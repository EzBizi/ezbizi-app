import '../core/phone_numbers.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/lead_thread.dart';
import '../widgets/leads_accounts.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/social_network_mark.dart';
import '../widgets/tab_bar_like.dart';

/// Leads — enquiries from connected advertising (EZ Leads).
///
/// One list, because that is what the work is: somebody scans what came in and
/// decides what each one is. Filters and search sit above it rather than behind
/// a menu, since both are used while a customer is on the phone.
///
/// ── The empty state is the screen's real job ─────────────────────
///
/// "No leads" and "nothing is connected" render identically as an empty list
/// and mean opposite things — a quiet week, or nothing working at all. The API
/// sends `meta.connections` for exactly this, and the two cases say different
/// sentences and offer different buttons. Getting that wrong is the failure the
/// whole module exists to avoid: a screen that quietly shows nothing while an
/// enquiry sits unanswered.
class LeadsScreen extends StatefulWidget {
  const LeadsScreen({
    super.key,
    required this.api,
    required this.session,
    this.initialSource,
  });

  final ApiClient api;
  final Session session;

  /// Which source strip entry to open on, for arriving from a notification.
  ///
  /// `initialSource` and NOT `initialTab`, which is what it was asked for as.
  /// The strip is drawn with `TabBarLike` and looks like tabs, but it is a
  /// FILTER: there is no TabController and no tab views, only `_source`
  /// narrowing one list. A parameter called `initialTab` invites an index and
  /// tab semantics, and the value that actually belongs here is the source as
  /// the API spells it -- `website_chat`, the same string `_sourceChips` and
  /// the `source` column use.
  ///
  /// Null keeps the default, which is All and is the right default for
  /// somebody opening Leads to look at their leads. Nothing was tried and
  /// removed here; the screen simply had no reason to start narrowed until a
  /// notification wanted to say "this enquiry, from this source".
  ///
  /// A source the strip does not know is ignored rather than drawn: an unknown
  /// value would otherwise select nothing and read as an empty inbox.
  final String? initialSource;

  @override
  State<LeadsScreen> createState() => _LeadsScreenState();
}

class _LeadsScreenState extends State<LeadsScreen> {
  final _search = TextEditingController();

  /// Search runs as you type, a third of a second after the last keystroke.
  /// Enter still works; it just no longer waits for it (Jose, 2026-09-09).
  Timer? _searchDebounce;

  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _leads = [];
  List<Map<String, dynamic>> _connections = [];

  /// When Google last refused the scheduled fetch on quota, or null.
  ///
  /// The list refreshes itself every twenty seconds now, so a stalled sync
  /// looks exactly like a quiet afternoon - the same rows, re-read, over and
  /// over. On 2026-09-16 that quiet afternoon was four hours of enquiries not
  /// arriving, found only because somebody opened the Ads report for an
  /// unrelated reason.
  String? _delayedSince;

  /// The open Connected sources panel, when there is one. A panel is its own
  /// route: the screen's setState does not reach it, so a row disconnected
  /// from inside it stayed on screen until the panel was closed and opened
  /// again (Jose, 2026-09-09). Every reload pokes it.
  StateSetter? _redrawSources;
  int _total = 0;
  String _status = '';
  int _request = 0;

  /// "Who is waiting on me": the enquirer spoke last. The one filter with
  /// data behind it. Unread and Starred are not drawn: a filter that is
  /// present and always empty teaches people the list is broken.
  bool _unreplied = false;

  /// Approved triage layout (18012c74): three filters that NARROW TOGETHER.
  /// Unread + Starred means both, because "starred things I have not read"
  /// is the combination somebody actually wants and "either" cannot say it.
  bool _unread = false;
  bool _starred = false;

  /// The source chip, as the API spells it. Null is every source.
  String? _source;

  static const _sourceChips = <(String, String)>[
    ('local_services', 'Google'),
    ('thumbtack', 'Thumbtack'),
    ('website_chat', 'Chat'),
    ('website_form', 'Forms'),
  ];

  /// The strip, in `_sourceChips` order with All in front. Icons only exist
  /// here: nothing else in the app draws a per-source mark, so these are the
  /// definition rather than a copy of one. Keep them in step if a source is
  /// added -- the strip is built from `_sourceChips`, so a source added there
  /// and not here would land with the wrong icon rather than none.
  static const _sourceIcons = <IconData>[
    Icons.campaign, // Google Local Services: an ad placement
    Icons.push_pin, // Thumbtack, which is what a thumbtack is
    Icons.chat_bubble_outline,
    Icons.description,
  ];

  /// The sources this person was granted, with their icons - Jose,
  /// 2026-09-17: *"i should be able to grant access by sub tab as well"*.
  ///
  /// Built by index from the two const lists above so the icon cannot drift
  /// from the label, and read fresh on every build rather than cached: `/me`
  /// arrives after the first frame, and a list computed in initState would draw
  /// an empty strip for the life of the screen.
  ///
  /// Absent `/me` answers NO, the same way hr_screen's `_mayOpen` does. A strip
  /// with nothing in it is the honest drawing of "we do not know yet"; the
  /// alternative shows four tabs and takes some away a moment later.
  ///
  /// This is a drawing, not the enforcement. The rows are filtered by
  /// LeadsController on every request including All, because one list behind
  /// one endpoint cannot be gated by hiding a chip.
  List<(String, String, IconData)> get _visibleSources => [
    for (var i = 0; i < _sourceChips.length; i++)
      if (_maySee(_sourceChips[i].$1))
        (_sourceChips[i].$1, _sourceChips[i].$2, _sourceIcons[i]),
  ];

  bool _maySee(String source) =>
      widget.session.me?.can('leads.$source.view') ?? false;

  void _setSource(String? source) {
    if (source == _source) return;
    setState(() => _source = source);
    _load();
  }

  /// All: every filter off, including the source, in one reload.
  void _clearFilters() {
    if (!_unread && !_unreplied && !_starred && _source == null) return;
    setState(() {
      _unread = false;
      _unreplied = false;
      _starred = false;
      _source = null;
    });
    _load();
  }

  /// Desktop layout 1: the lead whose thread is open beside the list. Null
  /// means the first one, so the pane is never blank while there are leads.
  String? _selectedId;

  /// The list's share of the two panes, dragged on the divider. Bounded in
  /// PIXELS so neither pane can be squeezed below what it lays out (Jose,
  /// 2026-09-09: "with limits of course to not break overflow"): the list
  /// keeps its row, the thread keeps its header with the star, Book and the
  /// status control on one line. A share alone let a wide window push the
  /// thread to 336px, and the header overflowed by 46.
  double _listShare = 0.5;
  static const _minListPx = 320.0;
  static const _minThreadPx = 460.0;

  Map<String, dynamic>? get _selected {
    for (final l in _leads) {
      if (l['id'] == _selectedId) return l;
    }
    return _leads.isEmpty ? null : _leads.first;
  }

  /// Ours, not the source's. The wire values, and what a person calls them.
  static const _statuses = <String, String>{
    '': 'All',
    'new': 'New',
    'booked': 'Booked',
    'archived': 'Archived',
    'not_a_lead': 'Not a lead',
  };

  /// How often the list re-reads itself while somebody is looking at it.
  ///
  /// Jose, 2026-09-16: a website chat arrived while a dispatcher was sitting on
  /// this screen, and it did not appear until they left the tab and came back.
  /// A person waiting in a live chat had nobody answer for half an hour.
  ///
  /// Twenty seconds rather than a websocket because this list is read from
  /// OUR database, not from Google or Thumbtack: a poll costs one indexed
  /// query and no external quota, which is the whole reason it is an
  /// acceptable answer here and would not be on the Ads report next door.
  ///
  /// It is safe to fire mid-use. `_load()` only shows a spinner when the list
  /// is EMPTY, the open thread is keyed on the lead id so its State and a
  /// half-typed reply survive the rebuild, and `_request` discards any answer
  /// that arrives out of order.
  static const _pollEvery = Duration(seconds: 20);

  Timer? _poll;

  @override
  void initState() {
    super.initState();

    // Set BEFORE the first _load(), not through _setSource() after it. That
    // would fire a second request and show the unfiltered list first, so
    // arriving from a notification would flash every source's leads and then
    // narrow -- which reads as the screen changing its mind.
    //
    // Checked against the strip rather than trusted: a source it does not know
    // would select nothing and draw as an empty inbox, which is the one thing
    // this screen exists to avoid.
    final wanted = widget.initialSource;

    if (wanted != null && _visibleSources.any((c) => c.$1 == wanted)) {
      _source = wanted;
    }

    _load();
    _poll = Timer.periodic(_pollEvery, (_) => _load());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _searchDebounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // "Nothing on screen yet", not "a request is in flight" — a refresh after
      // a status change keeps the list rendered rather than tearing it down.
      _loading = _leads.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/leads', {
      if (_status.isNotEmpty) 'status': _status,
      if (_unreplied) 'unreplied': '1',
      if (_unread) 'unread': '1',
      if (_starred) 'starred': '1',
      'source': ?_source,
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      // `data` is already the rows; `body` is the whole envelope. Reading
      // `response.data['data']` indexes a List with a String and throws.
      _leads = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _delayedSince = meta['sync_delayed_since'] as String?;
      _total = (meta['total'] as num?)?.toInt() ?? _leads.length;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
    });
    _redrawSources?.call(() {});
  }

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/leads/sync', const {});

    if (!mounted) return;

    setState(() => _syncing = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final created = (data['created'] as num?)?.toInt() ?? 0;
    final fetched = (data['fetched'] as num?)?.toInt() ?? 0;

    setState(() {
      // Says what happened, including when the honest answer is "nothing new".
      // A silent refresh leaves somebody wondering whether it ran.
      _notice = created > 0
          ? '$created new ${created == 1 ? 'lead' : 'leads'}.'
          : fetched > 0
          ? 'Nothing new — $fetched already here.'
          : 'Nothing new.';
    });

    await _load();
  }

  Future<void> _setStatus(Map<String, dynamic> lead, String status) async {
    final response = await widget.api.patch('/leads/${lead['id']}', {
      'status': status,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    await _load();
  }

  bool get _anyGoogleAds =>
      _connections.any((c) => c['provider'] == 'google_ads');

  /// EVERY Google Ads account, not the first one the query happened to return.
  ///
  /// The helper this replaced answered with the first match, which was right
  /// when a connect made one row and wrong the moment it made twenty-two in the
  /// same second: twenty-one were invisible and the one shown was arbitrary.
  /// It is gone now — Thumbtack was its last caller, for the same reason.
  List<Map<String, dynamic>> get _googleAdsAccounts => [
    for (final c in _connections)
      if (c['provider'] == 'google_ads') c,
  ];

  /// Every connected Thumbtack business, for the same reason as Google's.
  ///
  /// This was `_connectionFor('thumbtack')` — one row — until 2026-09-08, when
  /// one connect produced TWELVE businesses in the same second. Eleven were
  /// invisible, and the failure was worse than hiding them: disconnecting the
  /// one on screen reloaded and drew the next, so Jose reported the button as
  /// broken. It was working. He had removed eight before we understood why.
  ///
  /// Twelve is not the ceiling and the ceiling is not knowable — it is however
  /// many Thumbtack businesses the login administers.
  List<Map<String, dynamic>> get _thumbtackAccounts => [
    for (final c in _connections)
      if (c['provider'] == 'thumbtack') c,
  ];

  /// Connecting is module-level, NOT per source - decided 2026-09-17.
  ///
  /// The sources panel sits above the list rather than inside a source tab, so
  /// the provider sections are drawn for anybody holding `leads.edit` even if
  /// they were not granted that source's chip. An account connected by somebody
  /// who does not watch the Google tab still delivers every enquiry to the
  /// people who do; the reasoning, and the case where the opposite is right, is
  /// in SocialProvider::connectPermission.
  bool get _canConnect => widget.session.me?.can('leads.edit') ?? false;

  /// One tab per source, because the two are not one list yet.
  ///
  /// Google Ads leads are fetched, stored and worked here. Thumbtack is
  /// connected here and does not yet deliver leads — and those are different
  /// enough that merging them into one list would put an empty Thumbtack behind
  /// the same "no enquiries yet" sentence Google gets for a quiet week. A tab
  /// each lets the Thumbtack one say what is actually true about it.
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // One list across every source, under a source strip: UI/UX sources
    // layouts 3 and 6, the hybrid, chosen by Jose on 2026-09-09. It reverses
    // the 1 / 4 shipped in his absence the night before -- the source moved
    // out of the filter row and into a strip of its own with All in front.
    //
    // What the strip does NOT change: the list is still one list, and the
    // triage marks still answer "who is waiting on me" once rather than once
    // per source. All is the strip's first tab AND the filter row's first
    // chip, and both mean the unfiltered list -- clearing the filters returns
    // the strip to All visibly, rather than leaving a tab selected that no
    // longer describes what is on screen.
    return _googleTab(theme);
  }

  Widget _googleTab(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          // One row on a desktop; on a phone the search takes the width and
          // the two controls sit under it. Side by side they overflowed 375
          // by 76 pixels, which the phone-width test found the day it existed.
          child: LayoutBuilder(
            builder: (context, constraints) {
              final search = TextField(
                controller: _search,
                onSubmitted: (_) {
                  _searchDebounce?.cancel();
                  _load();
                },
                onChanged: (_) {
                  _searchDebounce?.cancel();
                  _searchDebounce = Timer(
                    const Duration(milliseconds: 350),
                    _load,
                  );
                },
                decoration: InputDecoration(
                  isDense: true,
                  prefixIcon: const Icon(Icons.search, size: 20),
                  // Both, because somebody has one or the other in front of
                  // them and should not have to know which the box wants.
                  hintText: 'Search a name or phone number',
                  border: const OutlineInputBorder(),
                  suffixIcon: _search.text.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.close, size: 18),
                          onPressed: () {
                            _search.clear();
                            _load();
                          },
                        ),
                ),
              );
              // `expanded` used to mean the dropdown's own isExpanded. It
              // now means "somebody else is bounding my width": the picker is
              // an InputDecorator, and an InputDecorator in a Row with no
              // width constraint ASSERTS rather than shrinking to fit, which
              // a DropdownButton never did.
              Widget status({required bool expanded}) => SizedBox(
                width: expanded ? null : 180,
                child: SearchPickerField<String>(
                    value: _status,
                    border: InputBorder.none,
                    isDense: true,
                    options: [
                      for (final entry in _statuses.entries)
                        SearchOption(value: entry.key, label: entry.value),
                    ],
                    onChanged: (value) {
                      setState(() => _status = value ?? '');
                      _load();
                    },
                  ),
              );
              final fetch = FilledButton.tonalIcon(
                onPressed: _syncing ? null : _sync,
                icon: _syncing
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.sync, size: 18),
                label: Text(_syncing ? 'Fetching…' : 'Fetch new'),
              );

              if (constraints.maxWidth < 600) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    search,
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(child: status(expanded: true)),
                        const SizedBox(width: 12),
                        fetch,
                      ],
                    ),
                  ],
                );
              }

              return Row(
                children: [
                  Expanded(child: search),
                  const SizedBox(width: 12),
                  status(expanded: false),
                  const SizedBox(width: 12),
                  fetch,
                ],
              );
            },
          ),
        ),
        // The source strip. All first, then one tab per source, in the order
        // the filter row used to list them.
        //
        // TabBarLike scrolls horizontally, which is what makes this work on a
        // phone: the drawn mobile option had Thumbtack pushed off the end
        // because the HTML mock could not scroll, and losing a live source
        // would have been a real gap rather than a drawing's compromise.
        // Every source is reachable at 352px; the strip scrolls to them.
        TabBarLike(
          key: const ValueKey('source-tabs'),
          labels: [
            ('All', Icons.all_inbox),
            for (final source in _visibleSources) (source.$2, source.$3),
          ],
          // indexWhere answers -1 for a source that is no longer drawn, and
          // -1 + 1 is All -- which is the right tab to be on when the chip you
          // were reading has been taken away, and is why this is left as
          // arithmetic rather than guarded.
          selected: _source == null
              ? 0
              : _visibleSources.indexWhere((c) => c.$1 == _source) + 1,
          onSelected: (i) =>
              _setSource(i == 0 ? null : _visibleSources[i - 1].$1),
        ),
        // One "Connected sources" row whatever the number of providers: the
        // band-to-panel shape Google's accounts shipped with, costing one row
        // at one provider or four. The panel holds every provider's section.
        LayoutBuilder(
          builder: (context, constraints) => constraints.maxWidth < 600
              ? _sourcesTile(theme)
              : _sourcesBand(theme),
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(_notice!, style: theme.textTheme.bodySmall),
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () => setState(() => _notice = null),
                ),
              ],
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Row(
            children: [
              Expanded(
                child: Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    ChoiceChip(
                      key: const ValueKey('filter-all'),
                      label: const Text('All'),
                      selected:
                          !_unreplied &&
                          !_unread &&
                          !_starred &&
                          _source == null,
                      onSelected: (_) => _clearFilters(),
                    ),
                    FilterChip(
                      key: const ValueKey('filter-unread'),
                      label: const Text('Unread'),
                      selected: _unread,
                      onSelected: (on) => _setFilters(unread: on),
                    ),
                    FilterChip(
                      key: const ValueKey('filter-unreplied'),
                      label: const Text('Unreplied'),
                      selected: _unreplied,
                      onSelected: (on) => _setFilters(unreplied: on),
                    ),
                    FilterChip(
                      key: const ValueKey('filter-starred'),
                      label: const Text('Starred'),
                      selected: _starred,
                      onSelected: (on) => _setFilters(starred: on),
                    ),
                  ],
                ),
              ),
              // Where a1tools puts it: the top of the list.
              TextButton(
                key: const ValueKey('mark-all-read'),
                onPressed: _leads.isEmpty ? null : _readAll,
                child: const Text('Mark all read'),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            // Desktop layout 1 above 900: the list left, the whole thread
            // beside it, so reading a conversation never costs anybody their
            // place in the list. Mobile layout 4 below: the row states its
            // value and tapping opens the thread full screen with a back bar.
            child: _leads.isEmpty
                ? _empty(theme)
                : LayoutBuilder(
                    builder: (context, constraints) =>
                        constraints.maxWidth >= 900
                        ? _twoPane(theme)
                        : _list(theme, onTap: _openThread),
                  ),
          ),
        ),
      ],
    );
  }

  void _setFilters({bool? unread, bool? unreplied, bool? starred}) {
    final next = (
      unread ?? _unread,
      unreplied ?? _unreplied,
      starred ?? _starred,
    );
    if (next == (_unread, _unreplied, _starred)) return;
    setState(() {
      _unread = next.$1;
      _unreplied = next.$2;
      _starred = next.$3;
    });
    _load();
  }

  /// This person has seen this lead. The row un-bolds at once; the server
  /// keeps the mark. Nobody else's row changes.
  Future<void> _markRead(Map<String, dynamic> lead) async {
    if (lead['is_unread'] != true) return;
    _replace({...lead, 'is_unread': false});
    await widget.api.post('/leads/${lead['id']}/read', const {});
  }

  Future<void> _readAll() async {
    final response = await widget.api.post('/leads/read-all', const {});
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    setState(() {
      _leads = [
        for (final l in _leads) {...l, 'is_unread': false},
      ];
    });
  }

  /// Star and note are marks, shared by everybody who can see the lead.
  /// Returns the lead as the server now holds it, so the thread can show the
  /// author and time the server stamped on the note.
  Future<Map<String, dynamic>?> _mark(
    Map<String, dynamic> lead, {
    bool? starred,
    String? note,
    bool clearNote = false,
  }) async {
    final response = await widget.api.patch('/leads/${lead['id']}', {
      'starred': ?starred,
      if (note != null || clearNote) 'note': note,
    });
    if (!mounted) return null;
    if (!response.ok) {
      setState(() => _notice = response.message);
      return null;
    }
    // The list row carried the reader's mark; the PATCH answer does not.
    final fresh = {
      ...(response.data as Map<String, dynamic>? ?? const {}),
      'is_unread': lead['is_unread'],
    };
    _replace(fresh);
    return fresh;
  }

  /// The lead came back booked and pointing at its job. The row updates at
  /// once; a status filter that no longer matches sorts itself out on the
  /// next load, which is a smaller surprise than a row vanishing mid-look.
  Future<Map<String, dynamic>?> _booked(Map<String, dynamic> result) async {
    final lead = result['lead'] as Map<String, dynamic>?;
    if (lead == null) return null;
    final current = _leads.firstWhere(
      (l) => l['id'] == lead['id'],
      orElse: () => const {},
    );
    final fresh = {...lead, 'is_unread': current['is_unread'] ?? false};
    _replace(fresh);
    final job = result['job'] as Map<String, dynamic>?;
    setState(() {
      _notice = job == null ? 'Booked.' : 'Booked as job ${job['number']}.';
    });
    return fresh;
  }

  void _replace(Map<String, dynamic> lead) {
    setState(() {
      _leads = [
        for (final l in _leads)
          if (l['id'] == lead['id']) lead else l,
      ];
    });
  }

  Widget _twoPane(ThemeData theme) {
    final selected = _selected;

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final maxList = (width - _minThreadPx).clamp(_minListPx, width);
        final listWidth = (width * _listShare).clamp(_minListPx, maxList);

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: listWidth,
              child: _list(
                theme,
                selectedId: selected?['id'] as String?,
                onTap: (lead) =>
                    setState(() => _selectedId = lead['id'] as String?),
              ),
            ),
            // The divider is the handle: an 8px hit target drawn as a 1px
            // line, dragged left or right to resize both panes at once.
            MouseRegion(
              cursor: SystemMouseCursors.resizeLeftRight,
              child: GestureDetector(
                key: const ValueKey('pane-divider'),
                behavior: HitTestBehavior.opaque,
                onHorizontalDragUpdate: (d) => setState(() {
                  _listShare =
                      (listWidth + d.delta.dx).clamp(_minListPx, maxList) /
                      width;
                }),
                child: const SizedBox(
                  width: 8,
                  child: Center(child: VerticalDivider(width: 1)),
                ),
              ),
            ),
            Expanded(
              child: selected == null
                  ? const SizedBox.shrink()
                  : LeadThread(
                      // Keyed on the lead so picking another one fetches its
                      // thread rather than keeping the last one's State.
                      key: ValueKey('thread-${selected['id']}'),
                      api: widget.api,
                      lead: selected,
                      onStatusChanged: (status) => _setStatus(selected, status),
                      onRead: () => _markRead(selected),
                      onReplied: _load,
                      onStar: (on) => _mark(selected, starred: on),
                      onNote: (text) =>
                          _mark(selected, note: text, clearNote: text == null),
                      session: widget.session,
                      onBooked: _booked,
                    ),
            ),
          ],
        );
      },
    );
  }

  Future<void> _openThread(Map<String, dynamic> lead) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (routeContext) => Scaffold(
          appBar: AppBar(title: Text(leadTitle(lead))),
          body: LeadThread(
            api: widget.api,
            lead: lead,
            // Deciding what a lead is ends the visit: back to the list, which
            // reloads with the new status, rather than a stale dropdown on a
            // screen holding the row as it was.
            onStatusChanged: (status) async {
              await _setStatus(lead, status);
              if (routeContext.mounted) Navigator.of(routeContext).pop();
            },
            onRead: () => _markRead(lead),
            onReplied: _load,
            onStar: (on) => _mark(lead, starred: on),
            onNote: (text) => _mark(lead, note: text, clearNote: text == null),
            session: widget.session,
            onBooked: _booked,
          ),
        ),
      ),
    );
  }

  /// Thumbtack: connected here, not yet delivering.
  ///
  /// ── Why this tab does not show an empty list ─────────────────
  ///
  /// Because an empty list is a lie shaped like a fact. EzBizi can connect a
  /// Thumbtack business and keep its associate phone numbers in step — which is
  /// what makes caller ID work on Thumbtack's side — and it cannot yet read
  /// enquiries. A list rendering nothing would say "no leads this week" to
  /// somebody whose leads are arriving perfectly well on Thumbtack's own app.
  ///
  /// So the tab says the two things separately: the account is connected, and
  /// enquiries are not being fetched yet. When ingestion lands this becomes the
  /// same list as Google's and this paragraph goes with it.
  /// Thumbtack's section of the Connected sources panel: the businesses
  /// when connected (every one of them, the list that told twelve twins
  /// apart), the door to connect when not.
  Widget _thumbtackSection(ThemeData theme) {
    final accounts = _thumbtackAccounts;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (accounts.isNotEmpty) ...[
          LeadsAccountsList(
            api: widget.api,
            session: widget.session,
            connections: accounts,
            onChanged: _load,
          ),
          const SizedBox(height: 12),
          Text(
            'Connected — enquiries not fetched yet',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'EzBizi keeps this business\'s associate phone numbers in step '
            'with Thumbtack, so calls from your team are recognised as '
            'coming from the business. Reading enquiries into this list is '
            'not switched on yet — until it is, your Thumbtack leads are '
            'on Thumbtack, not missing.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ] else ...[
          Text(
            'No Thumbtack account connected',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'Connecting lets EzBizi keep your team\'s phone numbers in step '
            'with Thumbtack, so a technician calling a customer is shown as '
            'the business rather than an unknown number.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          // Same rule as Google's half: a control that is offered must be
          // a control that works, so somebody holding only `leads.view`
          // is told who can do it rather than given a button that 403s.
          if (_canConnect)
            ConnectAccount(
              api: widget.api,
              label: 'Thumbtack',
              startPath: '/social/thumbtack/start',
              onConnected: _load,
            )
          else
            Text(
              'Somebody who can manage Leads needs to connect the account.',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ],
    );
  }

  /// Google's section: every account and where its leads go, or the door in.
  Widget _googleSection(ThemeData theme) {
    if (_googleAdsAccounts.isNotEmpty) {
      return LeadsAccountsList(
        api: widget.api,
        session: widget.session,
        connections: _googleAdsAccounts,
        onChanged: _load,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          'No Google Ads account connected',
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 12),
        if (_canConnect)
          ConnectAccount(
            api: widget.api,
            label: 'Google Ads',
            startPath: '/social/google-ads/start',
            onConnected: _load,
          )
        else
          Text(
            'Somebody who can manage Leads needs to connect the account.',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  /// "Google Ads · 22 accounts · Thumbtack · 1 · Website · chat and forms".
  String _sourcesSummary() {
    final google = _googleAdsAccounts.length;
    final thumbtack = _thumbtackAccounts.length;

    return [
      if (google > 0)
        'Google Ads · $google ${google == 1 ? 'account' : 'accounts'}',
      if (thumbtack > 0) 'Thumbtack · $thumbtack',
      'Website · chat and forms',
    ].join(' · ');
  }

  Widget _sourcesBand(ThemeData theme) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
    child: Row(
      children: [
        Icon(Icons.link, size: 20, color: theme.colorScheme.onSurfaceVariant),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Connected sources', style: theme.textTheme.bodyMedium),
              // Said HERE rather than as a banner: this is the line a person
              // already reads to learn where enquiries come from, so it is
              // where "they are not coming right now" belongs. A dismissible
              // banner would be dismissed once and never seen again on the
              // day it mattered.
              if (_delayedSince != null)
                Text(
                  'Google is refusing our requests, so new enquiries are '
                  'delayed. Nothing is lost — they arrive when it clears.',
                  key: const ValueKey('sync-delayed'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              Text(
                _sourcesSummary(),
                key: const ValueKey('sources-summary'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
        TextButton(
          key: const ValueKey('sources-manage'),
          onPressed: () => _openSources(pushed: false),
          child: const Text('Manage'),
        ),
      ],
    ),
  );

  Widget _sourcesTile(ThemeData theme) => ListTile(
    key: const ValueKey('sources-manage'),
    leading: Icon(
      Icons.link,
      size: 20,
      color: theme.colorScheme.onSurfaceVariant,
    ),
    title: Text('Connected sources', style: theme.textTheme.bodyMedium),
    subtitle: Text(_sourcesSummary(), style: theme.textTheme.bodySmall),
    trailing: const Icon(Icons.chevron_right, size: 18),
    onTap: () => _openSources(pushed: true),
  );

  /// Every provider's section on one surface: a side panel on a desktop, a
  /// pushed screen on a phone. Web Management's website section arrives as
  /// a widget of theirs; until then one line says where those leads come from.
  Future<void> _openSources({required bool pushed}) async {
    final theme = Theme.of(context);

    Widget section(String title, String network, Widget child) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            SocialNetworkMark(network: network, size: 18),
            const SizedBox(width: 8),
            Text(title, style: theme.textTheme.titleMedium),
          ],
        ),
        const SizedBox(height: 10),
        child,
        const SizedBox(height: 24),
      ],
    );

    // Built on every redraw, not once at open: the sections read the
    // screen's connections, and a disconnect changes them.
    Widget body() => StatefulBuilder(
      builder: (context, redraw) {
        _redrawSources = redraw;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            section('Google Ads', 'google_ads', _googleSection(theme)),
            section('Thumbtack', 'thumbtack', _thumbtackSection(theme)),
            section(
              'Website',
              'web',
              Text(
                'Chat and form enquiries arrive through the EzBizi WordPress '
                'plugin on your site, into this list.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          ],
        );
      },
    );

    if (pushed) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            appBar: AppBar(title: const Text('Connected sources')),
            body: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: body(),
            ),
          ),
        ),
      );
      _redrawSources = null;
      return;
    }

    await showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Connected sources',
        subtitle:
            'Where enquiries come from, and where each account\'s leads go.',
        child: body(),
      ),
    );
    _redrawSources = null;
  }

  /// Two different nothings, and they must not read the same.
  Widget _empty(ThemeData theme) {
    final connected = _anyGoogleAds;
    final canConnect = _canConnect;

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              connected ? Icons.inbox_outlined : Icons.link_off,
              size: 40,
              color: theme.colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              connected
                  ? 'No enquiries yet'
                  : 'No advertising account connected',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              connected
                  ? 'Nothing has come in through the connected account for the '
                        'filters above. Fetch new checks again straight away.'
                  : 'Leads arrive from a Google Ads account this organization '
                        'connects itself. Until one is connected there is '
                        'nothing to show here — this is not a quiet week.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            // §4: a control that is offered must be a control that works.
            // Drawing Connect for somebody holding only `leads.view` offered a
            // button whose entire outcome was a 403 — the permission check
            // existed server-side and the screen simply did not ask.
            if (!connected && canConnect) ...[
              const SizedBox(height: 18),
              // The button lives HERE rather than in Marketing: this is the
              // module that reads the account, and `leads.edit` is what gates
              // it. Somebody looking at an empty Leads tab should not have to
              // learn which other tab owns the connection.
              ConnectAccount(
                api: widget.api,
                label: 'Google Ads',
                startPath: '/social/google-ads/start',
                onConnected: _load,
              ),
            ] else if (!connected) ...[
              const SizedBox(height: 16),
              // Absent button, present explanation. Silence would read as "this
              // tab is broken" to the person least able to tell the difference.
              Text(
                'Somebody who can manage Leads needs to connect the account.',
                style: theme.textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _list(
    ThemeData theme, {
    String? selectedId,
    required void Function(Map<String, dynamic> lead) onTap,
  }) {
    return ListView.separated(
      itemCount: _leads.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _leads.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              '$_total ${_total == 1 ? 'lead' : 'leads'}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          );
        }

        final lead = _leads[index];

        return _row(
          theme,
          lead,
          selected: selectedId != null && lead['id'] == selectedId,
          onTap: () => onTap(lead),
        );
      },
    );
  }

  /// Name · Call/Text badge · the last line said · a dot when they spoke last.
  ///
  /// The dot is on the LEFT, where an eye or a thumb scanning a column passes
  /// first, not on the right with the timestamp. When the business sent last
  /// the preview is prefixed "You:". Before anything has been said the second
  /// line is the number and the service, never the category (see
  /// [leadHeaderLine] for why).
  Widget _row(
    ThemeData theme,
    Map<String, dynamic> lead, {
    required bool selected,
    required VoidCallback onTap,
  }) {
    final id = lead['id'];
    final from = lead['last_contact_from'] as String?;

    // ADVERTISER means the business spoke last, which is what "replied" means.
    // Anything else - CONSUMER, or null because the conversation read has not
    // landed yet - is still awaiting one. `unreplied` as a separate local is
    // gone: the dot no longer asks that question, and the badge asks it the
    // other way round.
    final mine = from == 'ADVERTISER';
    final channel = lead['last_contact_channel'] as String?;
    final source = lead['source'] as String?;
    final preview = lead['last_contact_preview'] as String?;
    /*
     * THE DOT IS UNREAD. Jose's ruling, 2026-09-15.
     *
     * It used to be unreplied, and that was the confusion: the strongest mark
     * on the row answered a question most rows cannot be asked. A Local
     * Services CALL has no reply box, so a dot meaning "unreplied" was lit on
     * enquiries nobody could ever reply to - and the text enquiries that really
     * were waiting looked exactly the same.
     *
     * So: the dot and the bold weight both say UNREAD, which is the fact that
     * applies to every row whatever its source. Replied-or-not moved to a badge
     * that is drawn ONLY when `can_reply` says a reply is possible at all.
     *
     * Bold stays alongside the dot rather than being replaced by it (approved
     * triage layout 1/4, 18012c74): the dot is a scan target, the weight is
     * what survives the selected row's fill.
     */
    final unread = lead['is_unread'] == true;
    final starred = lead['starred'] == true;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: unread
          ? theme.colorScheme.onSurface
          : theme.colorScheme.onSurfaceVariant,
      fontWeight: unread ? FontWeight.w700 : null,
    );

    return InkWell(
      onTap: onTap,
      child: Container(
        // Approved colour layout 3 (c31b50ad): a primaryContainer fill,
        // unmistakable at a glance where a 3px rule is not. No leading-edge
        // accent bar anywhere, on Jose's ruling of 2026-09-08.
        color: selected ? theme.colorScheme.primaryContainer : null,
        padding: const EdgeInsets.fromLTRB(14, 11, 14, 11),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: SizedBox(
                width: 8,
                height: 8,
                child: unread
                    ? DecoratedBox(
                        key: ValueKey('unread-$id'),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.primary,
                          shape: BoxShape.circle,
                        ),
                      )
                    : null,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: Text(
                          leadTitle(lead),
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: unread ? FontWeight.w700 : null,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      // A specimen, not a customer. First on the row because
                      // it changes how everything after it should be read.
                      if (lead['is_test'] == true) ...[
                        const SizedBox(width: 8),
                        _Badge('Test', key: ValueKey('test-badge-$id')),
                      ],
                      // The channel badge is drawn only when it says something
                      // the source badge does not. "Call · Google" is two
                      // facts; a chat row read "Chat · Chat" and a form row
                      // "Form · Form", one fact twice (UI/UX, 2026-09-08). The
                      // rule needs no list of sources: a fifth source with
                      // one channel gets the right answer for free.
                      if (channel != null &&
                          channelWord(channel) != sourceBadgeWord(source)) ...[
                        const SizedBox(width: 8),
                        _Badge(
                          channelWord(channel),
                          call: channel == 'PHONE_CALL',
                        ),
                      ],
                      // Replied or not, and ONLY where a reply is possible.
                      //
                      // `can_reply` is the channel's own answer, not a guess
                      // from lead_type here: a call cannot be replied to, a
                      // Thumbtack specimen cannot, and a lead whose account was
                      // disconnected cannot. On all of those the badge is
                      // absent entirely rather than reading "Replied", because
                      // "no reply is owed" and "a reply was sent" are different
                      // things and one must not be dressed as the other.
                      if (lead['can_reply'] == true) ...[
                        const SizedBox(width: 8),
                        _Badge(
                          mine ? 'Replied' : 'Awaiting reply',
                          key: ValueKey('reply-badge-$id'),
                        ),
                      ],
                      // The source, the last mark on the row, in the neutral
                      // container so it never competes with Call and Text.
                      const SizedBox(width: 6),
                      _SourceBadge(
                        sourceBadgeWord(source),
                        key: ValueKey('source-badge-$id'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  if (preview != null && preview.isNotEmpty)
                    Text.rich(
                      TextSpan(
                        children: [
                          if (mine)
                            TextSpan(
                              text: 'You: ',
                              style: TextStyle(
                                fontWeight: FontWeight.w600,
                                color: theme.colorScheme.onSurface,
                              ),
                            ),
                          TextSpan(text: preview),
                        ],
                      ),
                      style: muted,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    )
                  else
                    Text(
                      [
                        if ((lead['phone'] as String?)?.isNotEmpty == true &&
                            (lead['contact_name'] as String?)
                                    ?.trim()
                                    .isNotEmpty ==
                                true)
                          formatPhoneNumber(lead['phone'] as String?),
                        ?humaniseService(lead['service'] as String?),
                      ].join(' · '),
                      style: muted,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                ],
              ),
            ),
            const SizedBox(width: 6),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  leadWhen(
                    (lead['last_contact_at'] ?? lead['received_at']) as String?,
                  ),
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                // Hollow until set; 44px of target for a 16px glyph.
                IconButton(
                  key: ValueKey('star-$id'),
                  tooltip: starred ? 'Unstar' : 'Star',
                  iconSize: 16,
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(
                    minWidth: 44,
                    minHeight: 44,
                  ),
                  onPressed: () => _mark(lead, starred: !starred),
                  icon: Icon(
                    starred ? Icons.star : Icons.star_border,
                    color: starred
                        ? theme.colorScheme.primary
                        : theme.colorScheme.outline,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Google / Thumbtack / Chat / Form: the short word for the row.
String sourceBadgeWord(String? source) => switch (source) {
  'thumbtack' => 'Thumbtack',
  'website_chat' => 'Chat',
  'website_form' => 'Form',
  _ => 'Google',
};

/// The source, in the neutral container: a fourth mark that never competes
/// with Call and Text for the eye.
class _SourceBadge extends StatelessWidget {
  const _SourceBadge(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// "Call" or "Text". Never PHONE_CALL, never "LS Ads".
class _Badge extends StatelessWidget {
  const _Badge(this.text, {super.key, this.call = false});

  final String text;
  final bool call;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        // Call is the primary family, Text the secondary. tertiaryContainer
        // is off limits: it would be the first purple in the app.
        color: call ? scheme.primaryContainer : scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: call ? scheme.onPrimaryContainer : scheme.onSecondaryContainer,
        ),
      ),
    );
  }
}
