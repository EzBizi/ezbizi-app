import '../core/phone_numbers.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/ai_mark.dart';
import '../widgets/lead_source_details.dart';
import '../widgets/lead_thread.dart';
import '../widgets/leads_accounts.dart';
import '../widgets/search_picker.dart';
import '../widgets/tab_help.dart';

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
    this.initialLeadId,
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

  /// The lead to open, for arriving from a notification about it. Jose,
  /// 2026-09-28: clicking one "always [opens] the newest lead, instead of the
  /// one it was spawned for". Selected beside the list on a wide screen,
  /// opened full screen on a phone, and fetched on its own when it is not on
  /// the first page of the list.
  final String? initialLeadId;

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

  int _total = 0;

  /// How many rows the list holds: the first 50, then ten more each time the
  /// bottom is reached (Jose, 2026-10-01: "i should be able to load more ...
  /// when scrolling to the bottom by itself"). The refresh asks for the same
  /// number, so it does not fold the list back to 50 every 20 seconds.
  static const _firstPage = 50;
  static const _morePage = 10;
  int _shown = _firstPage;
  bool _loadingMore = false;
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

  /// Only the red, overdue rows (Jose, 2026-10-08).
  bool _overdue = false;

  /// Asked the price or a day on a call, nothing booked - AI's read (Jose,
  /// 2026-10-12).
  bool _followUp = false;
  bool _starred = false;

  /// UI queue 103 (A1 takeover): charge, IVR source, dispatcher and dates.
  String? _charge;
  String? _ivr;
  String? _dispatcher;
  String? _dates;
  List<String> _dispatchers = const [];
  bool _moreFilters = false;

  /// The source chip, as the API spells it. Null is every source.
  String? _source;

  /// Unread leads per source key, when the list says (`unread_by_source`).
  /// Empty draws no counts rather than zeros.
  Map<String, int> _unreadBySource = const {};

  /// A phone's sources page is open in place of the list (design 30, 5).
  bool _choosingSource = false;

  /// Manage: the Connections page in place of the leads, with its own back
  /// arrow (UI queue 34, Jose's 1 + 4 of 2026-09-30).
  bool _managing = false;

  /// A phone's search box, opened from its icon.
  bool _searching = false;

  /// The lead a phone has open in place of the list, with its own back
  /// arrow: containment, never a page pushed over the app.
  String? _phoneLeadId;
  Map<String, dynamic>? _phoneLeadAsOpened;

  /// Sources left out of All. LS Call is out by default: a call is over by
  /// the time it is listed, so it is not something to act on (Jose,
  /// 2026-09-29: "by default it should have all of them active except for LS
  /// Call as those are not actionable").
  /// LS Booking too (Jose, 2026-10-01): Google took the booking itself, so
  /// there is nothing to answer.
  // Nothing to reply to (Jose, 2026-10-15: "like ls calls and LS booking").
  final Set<String> _allLeftOut = {
    'ls_call',
    'ls_booking',
    'ringcentral',
    'ez_calls',
  };

  /// What All asks for: every source this person sees, less those left out.
  /// Null when nothing is left out, so the request stays unnarrowed.
  String? get _allSources {
    if (_source != null) return null;
    // Job applicants are never in All (the server leaves them out), so they
    // are not one of All's toggles either.
    final sources = _visibleSources.where((s) => s.$1 != 'job_applicant');
    final every = [for (final s in sources) ..._keysOf(s.$1)];
    final shown = [
      for (final s in sources)
        if (!_allLeftOut.contains(s.$1)) ..._keysOf(s.$1),
    ];
    if (shown.length == every.length) return null;
    return shown.isEmpty ? '-' : shown.join(',');
  }

  /// "LS" spelled out, for the rail's tooltips (UI review 2026-10-08).
  static const _fullNames = {
    'ls_call': 'Google Local Services calls',
    'ls_text': 'Google Local Services texts',
    'ls_booking': 'Google Local Services bookings',
    'website_booking': 'Online bookings from the website',
  };

  static const _sourceChips = <(String, String)>[
    // Google, split the way A1 Leads splits it (Jose, 2026-09-28). The API
    // takes these as a source and narrows Local Services by lead type.
    ('ls_call', 'LS Call'),
    ('ls_text', 'LS Text'),
    ('ls_booking', 'LS Booking'),
    // Calls straight to our own lines, not through Google (Jose, 2026-10-15).
    ('ringcentral', 'RC Calls'),
    // EzBizi's own lines (Jose, 2026-10-16): calls owe no reply, texts do.
    ('ez_calls', 'EZ Calls'),
    ('ez_texts', 'EZ Texts'),
    ('thumbtack', 'Thumbtack'),
    ('website_chat', 'Chat'),
    ('website_form', 'Forms'),
    // The review link's sad faces, out of Forms (Jose, 2026-10-19).
    ('bad_rating', 'Bad Ratings'),
    ('website_booking', 'Online Booking'),
    // Facebook, Instagram and WhatsApp in one tab, each lead keeping its own
    // tag (Jose, 2026-11-02; design 166). Picked inside it by _metaSources.
    ('meta', 'Meta'),
    ('workiz', 'Workiz'),
    // Not a source: people asking for a job, by Grok or by hand (Jose,
    // 2026-10-09). Their own tab, out of All.
    ('job_applicant', 'Job applicants'),
  ];

  /// What the Meta tab holds, each its own permission tick and its own chip
  /// inside the tab (design 166).
  static const _metaSources = <(String, String)>[
    ('facebook', 'Facebook'),
    ('instagram', 'Instagram'),
    ('whatsapp', 'WhatsApp'),
  ];

  /// The one Meta source picked inside the tab; null is all three.
  String? _metaPick;

  List<(String, String)> get _visibleMeta => [
    for (final m in _metaSources)
      if (_maySee(m.$1)) m,
  ];

  /// What a source asks the server for: Meta is its three sources.
  Iterable<String> _keysOf(String source) =>
      source == 'meta' ? _visibleMeta.map((m) => m.$1) : [source];

  /// The strip, in `_sourceChips` order with All in front. Icons only exist
  /// here: nothing else in the app draws a per-source mark, so these are the
  /// definition rather than a copy of one. Keep them in step if a source is
  /// added -- the strip is built from `_sourceChips`, so a source added there
  /// and not here would land with the wrong icon rather than none.
  static const _sourceIcons = <IconData>[
    Icons.call_outlined, // Google Local Services calls
    Icons.sms_outlined, // Google Local Services texts
    Icons.event_available_outlined, // booked through Google directly
    Icons.phone_callback_outlined, // RingCentral: a call to our own line
    Icons.phone_in_talk_outlined, // EZ Calls: a call to an EzBizi line
    Icons.textsms_outlined, // EZ Texts: a text to an EzBizi line
    Icons.push_pin, // Thumbtack, which is what a thumbtack is
    Icons.chat_bubble_outline,
    Icons.description,
    Icons.sentiment_dissatisfied_outlined, // Bad Ratings
    Icons.event_available, // the website's booking widget (2026-10-08)
    // Messenger and Instagram have no Material glyph of their own, and a
    // wrong-brand icon reads worse than a generic one. These say "a message"
    // and "a photo account" rather than pretending to be the logos.
    Icons.forum_outlined, // Meta: Messenger, Instagram and WhatsApp
    // Workiz is a book of records rather than a channel somebody wrote in,
    // which is the difference this whole source turns on - no thread, no
    // reply, and absent from the Unreplied filter. A ledger, not a message.
    Icons.assignment_outlined,
    Icons.badge_outlined, // job applicants
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
      source == 'job_applicant' ||
      (source == 'meta' && _metaSources.any((m) => _maySee(m.$1))) ||
      (widget.session.me?.can(
            'leads.${source.startsWith('ls_') ? 'local_services' : source}.view',
          ) ??
          false);

  void _setSource(String? source) {
    if (source == _source) return;
    setState(() {
      _source = source;
      _metaPick = null;
    });
    _load();
  }

  /// Desktop layout 1: the lead whose thread is open beside the list. Null
  /// means the first one, so the pane is never blank while there are leads.
  String? _selectedId;

  /// The notification's lead, until the first load has put it on screen.
  String? _toOpen;

  /// That lead when it was fetched from outside the loaded page, kept at the
  /// top so the list's own refresh does not drop it and jump to the newest.
  Map<String, dynamic>? _pinned;

  /// The list's share of the two panes, dragged on the divider. Bounded in
  /// PIXELS so neither pane can be squeezed below what it lays out (Jose,
  /// 2026-09-09: "with limits of course to not break overflow"): the list
  /// keeps its row, the thread keeps its header with the star, Book and the
  /// status control on one line. A share alone let a wide window push the
  /// thread to 336px, and the header overflowed by 46.
  ///
  /// Where it was last dragged, kept as a preference: held here alone, it went
  /// back to the middle every time somebody left the tab (Jose, 2026-09-29:
  /// "it resets after u leave the tab, default should be 30 - 70").
  late double _listShare = widget.session.me?.leadsListShare ?? 0.3;
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
    '': 'Any status',
    'new': 'New',
    'booked': 'Booked',
    'archived': 'Archived',
    'not_a_lead': 'Not a lead',
    'completed': 'Completed',
    'lost': 'Lost',
    'job_applicant': 'Job applicant',
    'follow_up': 'Follow up',
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
    } else if (wanted != null && _visibleMeta.any((m) => m.$1 == wanted)) {
      _source = 'meta';
      _metaPick = wanted;
    }

    _selectedId = widget.initialLeadId;
    _toOpen = widget.initialLeadId;

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
      ..._filters,
      'per_page': '$_shown',
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
      final pinned = _pinned;
      if (pinned != null && !_leads.any((l) => l['id'] == pinned['id'])) {
        _leads = [pinned, ..._leads];
      }
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _delayedSince = meta['sync_delayed_since'] as String?;
      _total = (meta['total'] as num?)?.toInt() ?? _leads.length;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      _unreadBySource = {
        for (final e
            in (meta['unread_by_source'] as Map? ?? const {}).entries)
          '${e.key}': (e.value as num?)?.toInt() ?? 0,
      };
      _dispatchers = [
        for (final d in meta['dispatchers'] as List? ?? const []) '$d',
      ];
    });

    if (response.ok && _toOpen != null) await _openWanted(_toOpen!);
  }

  /// What the list is narrowed to - the same for the first rows and for more.
  Map<String, String> get _filters => {
    if (_status.isNotEmpty) 'status': _status,
    if (_unreplied) 'unreplied': '1',
    if (_unread) 'unread': '1',
    if (_overdue) 'overdue': '1',
    if (_followUp) 'follow_up': '1',
    if (_starred) 'starred': '1',
    'source': ?(_source == 'meta' ? null : _source),
    'sources': ?(_source == 'meta'
        ? (_metaPick ?? _keysOf('meta').join(','))
        : _allSources),
    if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    'charge': ?_charge,
    'ivr': ?_ivr,
    'dispatcher': ?_dispatcher,
    ..._dateRange,
  };

  /// The Dates preset as from / to days, on this device's calendar.
  Map<String, String> get _dateRange {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    String day(DateTime d) =>
        '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';
    final monday = today.subtract(Duration(days: today.weekday - 1));
    final (DateTime, DateTime)? range = switch (_dates) {
      'today' => (today, today),
      'this_week' => (monday, today),
      'last_week' => (
        monday.subtract(const Duration(days: 7)),
        monday.subtract(const Duration(days: 1)),
      ),
      '7' => (today.subtract(const Duration(days: 6)), today),
      'this_month' => (DateTime(today.year, today.month), today),
      'last_month' => (
        DateTime(today.year, today.month - 1),
        DateTime(today.year, today.month).subtract(const Duration(days: 1)),
      ),
      '30' => (today.subtract(const Duration(days: 29)), today),
      _ => null,
    };
    return range == null ? const {} : {'from': day(range.$1), 'to': day(range.$2)};
  }

  /// Charge, IVR source, dispatcher and dates, under the three filters.
  Widget _takeoverFilters() {
    Widget pick<T>(
      String key,
      double width,
      T value,
      List<SearchOption<T>> options,
      ValueChanged<T> set,
    ) => SizedBox(
      width: width,
      child: SearchPickerField<T>(
        key: ValueKey(key),
        value: value,
        isDense: true,
        options: options,
        onChanged: (v) {
          setState(() => set(v as T));
          _load();
        },
      ),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
      child: Wrap(
        key: const ValueKey('tour-leads-more-filters'),
        spacing: 6,
        runSpacing: 6,
        children: [
          pick<String?>('leads-charge', 130, _charge, const [
            SearchOption(value: null, label: 'Any charge'),
            SearchOption(value: 'CHARGED', label: 'Charged'),
            SearchOption(value: 'CREDITED', label: 'Credited'),
            SearchOption(value: 'IN_REVIEW', label: 'In review'),
            SearchOption(value: 'NOT_CHARGED', label: 'Not charged'),
          ], (v) => _charge = v),
          pick<String?>('leads-ivr', 150, _ivr, const [
            SearchOption(value: null, label: 'Any IVR source'),
            SearchOption(value: 'LS Ads', label: 'LS Ads'),
            SearchOption(value: 'GMB', label: 'GMB'),
            SearchOption(value: 'SEO', label: 'SEO'),
            SearchOption(value: 'PPC', label: 'PPC'),
            SearchOption(value: 'Existing Customer', label: 'Existing customer'),
          ], (v) => _ivr = v),
          if (_dispatchers.isNotEmpty || _dispatcher != null)
            pick<String?>('leads-dispatcher', 150, _dispatcher, [
              const SearchOption(value: null, label: 'Any dispatcher'),
              for (final d in _dispatchers) SearchOption(value: d, label: d),
            ], (v) => _dispatcher = v),
          pick<String?>('leads-dates', 140, _dates, const [
            SearchOption(value: null, label: 'All dates'),
            SearchOption(value: 'today', label: 'Today'),
            SearchOption(value: 'this_week', label: 'This week'),
            SearchOption(value: 'last_week', label: 'Last week'),
            SearchOption(value: '7', label: 'Last 7 days'),
            SearchOption(value: 'this_month', label: 'This month'),
            SearchOption(value: 'last_month', label: 'Last month'),
            SearchOption(value: '30', label: 'Last 30 days'),
          ], (v) => _dates = v),
        ],
      ),
    );
  }

  /// The next ten older leads, when the list is scrolled to its end. Pages
  /// of ten line up with the first 50 because 50 is a whole number of them.
  Future<void> _more() async {
    if (_loadingMore || _shown >= _total) return;
    final request = _request;
    setState(() => _loadingMore = true);

    final response = await widget.api.get('/leads', {
      ..._filters,
      'per_page': '$_morePage',
      'page': '${_shown ~/ _morePage + 1}',
    });

    if (!mounted) return;
    setState(() {
      _loadingMore = false;
      // A filter changed while this was on its way: its rows belong to a
      // list that is no longer on screen.
      if (!response.ok || request != _request) return;
      final known = {for (final l in _leads) l['id']};
      _leads = [
        ..._leads,
        for (final l in (response.data as List? ?? const []).cast<Map<String, dynamic>>())
          if (!known.contains(l['id'])) l,
      ];
      _shown += _morePage;
    });
  }

  /// Put the notification's lead on screen: fetched when it is not in the
  /// loaded page, then selected, or opened full screen on a phone.
  Future<void> _openWanted(String id) async {
    _toOpen = null;

    var lead = _leads.where((l) => l['id'] == id).firstOrNull;

    if (lead == null) {
      final one = await widget.api.get('/leads/$id');
      if (!mounted || !one.ok || one.data is! Map) return;
      lead = Map<String, dynamic>.from(one.data as Map);
      _pinned = lead;
      setState(() => _leads = [lead!, ..._leads]);
    }

    setState(() => _selectedId = id);

    if (mounted && MediaQuery.sizeOf(context).width < 900) {
      _openThread(lead);
    }
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
  bool get _canConnect => widget.session.me?.can('leads.manage') ?? false;

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
    if (_managing) {
      return LeadsConnections(
        api: widget.api,
        session: widget.session,
        connections: _connections,
        canEdit: _canConnect,
        onChanged: _load,
        onBack: () => setState(() => _managing = false),
      );
    }

    // Design 30, Jose's 3 and 5 (2026-09-29): "ezbizi is taking a lot of
    // space with tabs and filters from the chat". On a desktop the sources
    // go down the side and the list keeps its own search and filters, so the
    // conversation runs from the title to the bottom. On a phone one button
    // says which source is showing and opens a page of them.
    final body = LayoutBuilder(
      builder: (context, constraints) {
        final desk = constraints.maxWidth >= 900;
        return desk ? _desk(theme) : _phone(theme);
      },
    );
    // (?) runs the open source's own walkthrough, by the server's name for
    // its tab (Jose, 2026-10-14: walkthroughs for "all the sub tabs"). The
    // three Local Services chips are one tab, the module's own tour.
    final tab = const {
      'thumbtack': 'Thumbtack',
      'website_chat': 'Chat',
      'website_form': 'Forms',
      'website_booking': 'Online Booking',
      'meta': 'Messenger',
      'workiz': 'Workiz',
      'whatsapp': 'WhatsApp',
      'ringcentral': 'RC Calls',
      'ez_calls': 'EZ Calls',
      'ez_texts': 'EZ Texts',
      'bad_rating': 'Bad Ratings',
    }[_source];
    return tab == null ? body : OpenTabMark(tab, child: body);
  }

  Widget _desk(ThemeData theme) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _rail(theme),
      const VerticalDivider(width: 1),
      Expanded(
        child: _leads.isEmpty
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _listHeader(theme),
                  const Divider(height: 1),
                  Expanded(child: _listBody(theme, onTap: (_) {})),
                ],
              )
            : _twoPane(theme),
      ),
    ],
  );

  Widget _phone(ThemeData theme) {
    if (_choosingSource) return _sourcesPage(theme);

    final id = _phoneLeadId;
    if (id != null) {
      final lead =
          _leads.where((l) => l['id'] == id).firstOrNull ?? _phoneLeadAsOpened;
      if (lead != null) return _phoneThread(theme, lead);
    }

    // The header scrolls away with the leads on a phone: standing, the
    // search, strip, sources and filters took 340 of its 800 pixels.
    return HeaderAboveList(
      header: _phoneHeader(theme),
      list: _listBody(theme, onTap: _openThread),
    );
  }

  Widget _listBody(
    ThemeData theme, {
    String? selectedId,
    required void Function(Map<String, dynamic> lead) onTap,
  }) => AsyncBody(
    loading: _loading,
    error: _error,
    onRetry: _load,
    child: _leads.isEmpty
        ? _empty(theme)
        : _list(theme, selectedId: selectedId, onTap: onTap),
  );

  // ── the rail (desktop 3) ─────────────────────────────────────────

  /// The sources down the side: All first, then every source this person was
  /// granted, each with its unread count and a red mark when its accounts
  /// are not delivering; Manage and Fetch at the foot.
  Widget _rail(ThemeData theme) => SizedBox(
    key: const ValueKey('source-rail'),
    width: 88,
    child: Column(
      children: [
        Expanded(
          child: SingleChildScrollView(
            key: const ValueKey('source-tabs'),
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Column(
              children: [
                _railTab(theme, null, 'All', Icons.all_inbox),
                for (final s in _visibleSources)
                  _railTab(theme, s.$1, s.$2, s.$3),
              ],
            ),
          ),
        ),
        const Divider(height: 1),
        const SizedBox(height: 6),
        if (_canConnect)
          _railButton(
            theme,
            key: const ValueKey('sources-manage'),
            icon: Icons.link,
            label: 'Manage',
            tooltip: _sourcesSummary(),
            onPressed: _openSources,
          ),
        _railButton(
          theme,
          key: const ValueKey('leads-fetch'),
          icon: Icons.sync,
          label: _syncing ? 'Fetching' : 'Fetch',
          tooltip: 'Fetch new leads now',
          busy: _syncing,
          onPressed: _syncing ? null : _sync,
        ),
        const SizedBox(height: 6),
      ],
    ),
  );

  /// Unread leads behind a source; for All, behind every source it holds.
  int _unreadIn(String? key) => key == 'meta'
      ? _keysOf('meta').fold(0, (sum, k) => sum + (_unreadBySource[k] ?? 0))
      : key != null
      ? _unreadBySource[key] ?? 0
      : _visibleSources
            .where((s) => s.$1 != 'job_applicant' && !_allLeftOut.contains(s.$1))
            .fold(0, (sum, s) => sum + _unreadIn(s.$1));

  /// A source whose accounts are not delivering: Google refusing on quota,
  /// or a connection broken or revoked.
  bool _failing(String key) {
    final provider = switch (key) {
      'ls_call' || 'ls_text' => 'google_ads',
      'thumbtack' => 'thumbtack',
      _ => null,
    };
    if (provider == null) return false;
    if (provider == 'google_ads' && _delayedSince != null) return true;

    return _connections.any(
      (c) =>
          c['provider'] == provider &&
          (c['status'] == 'broken' || c['status'] == 'revoked'),
    );
  }

  /// A source's icon over its name, its unread count on the icon, a red mark
  /// when it is not delivering. A ChoiceChip standing up, so it selects the
  /// way every chip in the app selects.
  Widget _railTab(ThemeData theme, String? key, String label, IconData icon) {
    final unread = _unreadIn(key);
    final failing = key != null && _failing(key);

    return Padding(
      padding: const EdgeInsets.fromLTRB(6, 0, 6, 4),
      child: ChoiceChip(
        key: ValueKey('source-tab-${key ?? 'all'}'),
        showCheckmark: false,
        selected: _source == key,
        side: BorderSide.none,
        padding: const EdgeInsets.symmetric(vertical: 6),
        labelPadding: EdgeInsets.zero,
        tooltip: failing
            ? '$label is not delivering right now'
            : _fullNames[key],
        onSelected: (_) => _setSource(key),
        label: SizedBox(
          width: 70,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Badge(
                isLabelVisible: unread > 0,
                label: Text(unread > 99 ? '99+' : '$unread'),
                backgroundColor: theme.colorScheme.primary,
                textColor: theme.colorScheme.onPrimary,
                child: Badge(
                  key: failing ? ValueKey('source-failing-$key') : null,
                  isLabelVisible: failing,
                  smallSize: 8,
                  alignment: AlignmentDirectional.topStart,
                  backgroundColor: theme.colorScheme.error,
                  child: Icon(icon, size: 20),
                ),
              ),
              const SizedBox(height: 4),
              Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall?.copyWith(
                  fontSize: 10.5,
                  letterSpacing: 0,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _railButton(
    ThemeData theme, {
    required Key key,
    required IconData icon,
    required String label,
    required String tooltip,
    required VoidCallback? onPressed,
    bool busy = false,
  }) => Tooltip(
    message: tooltip,
    child: TextButton(
      key: key,
      onPressed: onPressed,
      style: TextButton.styleFrom(
        minimumSize: const Size(76, 52),
        padding: const EdgeInsets.symmetric(vertical: 6),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Icon(icon, size: 20),
          const SizedBox(height: 3),
          Text(label, style: theme.textTheme.labelSmall),
        ],
      ),
    ),
  );

  // ── the list's own controls ──────────────────────────────────────

  /// Search, status and Filters share one height (Jose, 2026-11-02: "these 3
  /// fields have different sizes"): the theme's 16 px padding, less 4.
  static const _barPadding = EdgeInsets.symmetric(horizontal: 12, vertical: 12);
  static const _barHeight = 44.0;

  Widget _searchField() => TextField(
    key: const ValueKey('tour-leads-search'),
    controller: _search,
    onSubmitted: (_) {
      _searchDebounce?.cancel();
      _load();
    },
    onChanged: (_) {
      _searchDebounce?.cancel();
      _searchDebounce = Timer(const Duration(milliseconds: 350), _load);
    },
    decoration: InputDecoration(
      isDense: true,
      contentPadding: _barPadding,
      prefixIcon: const Icon(Icons.search, size: 20),
      // Both, because somebody has one or the other in front of them and
      // should not have to know which the box wants.
      hintText: 'Search name, phone or message',
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

  // Bounded by its caller: an InputDecorator in a Row with no width
  // constraint asserts rather than shrinking to fit.
  Widget _statusPicker() => SearchPickerField<String>(
    key: const ValueKey('tour-leads-status'),
    value: _status,
    isDense: true,
    contentPadding: _barPadding,
    options: [
      for (final entry in _statuses.entries)
        SearchOption(value: entry.key, label: entry.value),
    ],
    onChanged: (value) {
      setState(() => _status = value ?? '');
      _load();
    },
  );

  /// Search and status, the three filters, Mark all read, what All holds,
  /// and anything the sources want said: over the list and nothing else.
  Widget _listHeader(ThemeData theme) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (_source == 'meta') _inMeta(theme),
      // Design 162 (option 1 + phone 4): over the list, never the title row.
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
        child: LayoutBuilder(
          builder: (context, box) => Row(
            children: [
              Expanded(
                child: SizedBox(height: _barHeight, child: _searchField()),
              ),
              const SizedBox(width: 8),
              SizedBox(
                width: 140,
                height: _barHeight,
                child: _statusPicker(),
              ),
              const SizedBox(width: 8),
              // Charge, IVR, dispatcher, dates and the sources in All, behind
              // one button with a count (UI queue 108, design 1); the icon
              // alone in a narrow list.
              SizedBox(
                height: _barHeight,
                child: _filtersButton(compact: box.maxWidth < 420),
              ),
            ],
          ),
        ),
      ),
      _filterRow(theme, withStatus: false),
      if (_moreFilters) ...[
        _takeoverFilters(),
        if (_source == null && _visibleSources.length > 1) _inAll(theme),
      ],
      ..._alerts(theme),
    ],
  );

  /// The number of the folded filters that are set.
  int get _foldedSet =>
      [_charge, _ivr, _dispatcher, _dates].where((v) => v != null).length;

  Widget _filtersButton({bool compact = false}) {
    final label = _foldedSet > 0 ? 'Filters · $_foldedSet' : 'Filters';
    final icon = Icon(_moreFilters ? Icons.expand_less : Icons.tune, size: 18);
    void toggle() => setState(() => _moreFilters = !_moreFilters);
    if (compact) {
      return IconButton(
        key: const ValueKey('leads-more-filters'),
        tooltip: label,
        onPressed: toggle,
        icon: Badge(
          isLabelVisible: _foldedSet > 0,
          label: Text('$_foldedSet'),
          child: icon,
        ),
      );
    }
    return OutlinedButton.icon(
      key: const ValueKey('leads-more-filters'),
      onPressed: toggle,
      icon: icon,
      label: Text(label),
    );
  }

  /// Approved triage layout (18012c74): the three narrow together. Mark all
  /// read follows them in the same run, beside what it reads (design 28:
  /// pinned to the far edge it read as "floating in the air").
  Widget _filterRow(ThemeData theme, {required bool withStatus}) {
    final chips = _quickFilters(theme, withStatus: withStatus);
    // Design 162: one row that scrolls sideways, desktop and phone.
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: SingleChildScrollView(
        key: const ValueKey('tour-leads-filters'),
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            for (final (i, c) in chips.indexed) ...[
              if (i > 0) const SizedBox(width: 6),
              c,
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _quickFilters(ThemeData theme, {required bool withStatus}) => [
        // No Unread chip (Jose, 2026-11-02: "remove the unread filter").
        FilterChip(
          key: const ValueKey('filter-unreplied'),
          avatar: Icon(
            Icons.reply,
            size: 16,
            color: theme.colorScheme.primary,
          ),
          label: const Text('Unreplied'),
          showCheckmark: false,
          selected: _unreplied,
          visualDensity: VisualDensity.compact,
          onSelected: (on) => _setFilters(unreplied: on),
        ),
        FilterChip(
          key: const ValueKey('filter-overdue'),
          avatar: Icon(
            Icons.warning_amber_rounded,
            size: 16,
            color: theme.colorScheme.error,
          ),
          label: const Text('Overdue'),
          showCheckmark: false,
          selected: _overdue,
          visualDensity: VisualDensity.compact,
          onSelected: (on) => _setFilters(overdue: on),
        ),
        FilterChip(
          key: const ValueKey('filter-follow-up'),
          // The AI mark's glyph (AiMark): the list is AI's read of the calls.
          avatar: Icon(
            Icons.auto_awesome,
            size: 16,
            color: theme.colorScheme.primary,
            semanticLabel: 'AI',
          ),
          tooltip:
              'AI read the call: they asked the price or a day and nothing was booked.',
          label: const Text('Follow up'),
          showCheckmark: false,
          selected: _followUp,
          visualDensity: VisualDensity.compact,
          onSelected: (on) => _setFilters(followUp: on),
        ),
        FilterChip(
          key: const ValueKey('filter-starred'),
          avatar: Icon(
            Icons.star,
            size: 16,
            color: theme.colorScheme.primary,
          ),
          label: const Text('Starred'),
          showCheckmark: false,
          selected: _starred,
          visualDensity: VisualDensity.compact,
          onSelected: (on) => _setFilters(starred: on),
        ),
        if (withStatus) SizedBox(width: 150, child: _statusPicker()),
        IconButton(
          key: const ValueKey('mark-all-read'),
          tooltip: 'Mark all read',
          onPressed: _leads.isEmpty ? null : _readAll,
          icon: const Icon(Icons.done_all, size: 20),
        ),
  ];

  /// Inside Meta: all three, or one, each with its unread count (design 166,
  /// worked like the In All row).
  Widget _inMeta(ThemeData theme) => Padding(
    key: const ValueKey('meta-sources'),
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
    child: Wrap(
      spacing: 4,
      runSpacing: 4,
      children: [
        for (final m in <(String?, String)>[(null, 'All Meta'), ..._visibleMeta])
          ChoiceChip(
            key: ValueKey('meta-source-${m.$1 ?? 'all'}'),
            showCheckmark: false,
            visualDensity: VisualDensity.compact,
            label: Text(
              '${m.$2} ${_unreadIn(m.$1 ?? 'meta')}',
              style: theme.textTheme.labelSmall,
            ),
            selected: _metaPick == m.$1,
            onSelected: (_) {
              setState(() => _metaPick = m.$1);
              _load();
            },
          ),
      ],
    ),
  );

  /// Under All: which sources it holds, one toggle each, by the same icons
  /// the sources wear (Jose, 2026-09-29: "on ALL we should have filters for
  /// which leads it's showing ... except for LS Call").
  Widget _inAll(ThemeData theme) => Padding(
    key: const ValueKey('all-sources'),
    padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
    child: Wrap(
      spacing: 4,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Padding(
          padding: const EdgeInsets.only(right: 4),
          child: Text(
            'In All',
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        for (final s in _visibleSources.where((s) => s.$1 != 'job_applicant'))
          FilterChip(
            key: ValueKey('all-source-${s.$1}'),
            tooltip: _allLeftOut.contains(s.$1)
                ? '${s.$2}: left out of All'
                : '${s.$2}: in All',
            showCheckmark: false,
            visualDensity: VisualDensity.compact,
            labelPadding: EdgeInsets.zero,
            padding: const EdgeInsets.all(4),
            // Named, not just drawn: eleven bare icons on a phone said
            // nothing (UI review 2026-10-08).
            label: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(s.$3, size: 16),
                const SizedBox(width: 4),
                Text(s.$2, style: theme.textTheme.labelSmall),
              ],
            ),
            selected: !_allLeftOut.contains(s.$1),
            onSelected: (on) {
              setState(
                () => on ? _allLeftOut.remove(s.$1) : _allLeftOut.add(s.$1),
              );
              _load();
            },
          ),
      ],
    ),
  );

  /// Enquiries delayed, and the answer to Fetch: said over the list, which
  /// is where somebody is looking.
  List<Widget> _alerts(ThemeData theme) => [
    if (_delayedSince != null)
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
        child: Text(
          'Google is refusing our requests, so new enquiries are delayed. '
          'Nothing is lost — they arrive when it clears.',
          key: const ValueKey('sync-delayed'),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.error,
          ),
        ),
      ),
    if (_notice != null)
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 4, 6),
        child: Row(
          children: [
            Expanded(child: Text(_notice!, style: theme.textTheme.bodySmall)),
            IconButton(
              icon: const Icon(Icons.close, size: 16),
              onPressed: () => setState(() => _notice = null),
            ),
          ],
        ),
      ),
  ];

  // ── the phone (mobile 5) ─────────────────────────────────────────

  Widget _phoneHeader(ThemeData theme) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 4, 6),
        child: Row(
          children: [
            Expanded(child: _sourceButton(theme)),
            IconButton(
              key: const ValueKey('search-open'),
              tooltip: _searching ? 'Close search' : 'Search',
              icon: Icon(_searching ? Icons.search_off : Icons.search),
              onPressed: () {
                if (_searching && _search.text.isNotEmpty) {
                  _search.clear();
                  _load();
                }
                setState(() => _searching = !_searching);
              },
            ),
            IconButton(
              key: const ValueKey('leads-fetch'),
              tooltip: 'Fetch new leads now',
              onPressed: _syncing ? null : _sync,
              icon: _syncing
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.sync),
            ),
          ],
        ),
      ),
      if (_source == 'meta') _inMeta(theme),
      if (_searching || _search.text.isNotEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          child: _searchField(),
        ),
      _filterRow(theme, withStatus: true),
      // Phone (UI queue 103, 4): the four takeover filters behind one button.
      Align(
        alignment: AlignmentDirectional.centerStart,
        child: Padding(
          padding: const EdgeInsets.only(left: 6),
          child: TextButton.icon(
            key: const ValueKey('leads-more-filters'),
            onPressed: () => setState(() => _moreFilters = !_moreFilters),
            icon: Icon(_moreFilters ? Icons.expand_less : Icons.tune, size: 18),
            label: Text(
              [_charge, _ivr, _dispatcher, _dates].any((v) => v != null)
                  ? 'More filters (${[_charge, _ivr, _dispatcher, _dates].where((v) => v != null).length})'
                  : 'More filters',
            ),
          ),
        ),
      ),
      if (_moreFilters) _takeoverFilters(),
      if (_source == null && _visibleSources.length > 1) _inAll(theme),
      ..._alerts(theme),
    ],
  );

  /// Which source is showing and how many are unread; it opens the sources.
  Widget _sourceButton(ThemeData theme) {
    final current = _visibleSources.where((s) => s.$1 == _source).firstOrNull;
    final unread = _unreadIn(_source);

    return OutlinedButton(
      key: const ValueKey('source-button'),
      onPressed: () => setState(() => _choosingSource = true),
      style: OutlinedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        alignment: AlignmentDirectional.centerStart,
      ),
      child: Row(
        children: [
          Icon(current?.$3 ?? Icons.all_inbox, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              current?.$2 ?? 'All sources',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (unread > 0) _count(theme, unread),
          const Icon(Icons.chevron_right, size: 18),
        ],
      ),
    );
  }

  Widget _count(ThemeData theme, int n) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 6),
    child: Badge(
      label: Text(formatCount(n)),
      backgroundColor: theme.colorScheme.primary,
      textColor: theme.colorScheme.onPrimary,
    ),
  );

  /// The badge word a source key wears on its rows, for its colour.
  static String _badgeWordFor(String key) => switch (key) {
    'ls_call' => 'LS Call',
    'ls_text' => 'LS Text',
    'website_form' => 'Form',
    'website_chat' => 'Chat',
    _ => sourceBadgeWord(key),
  };

  /// What stands behind a source, said under its name on the phone's page.
  String? _sourceState(String? key) {
    if (key == null) return 'Every source below';
    if (key == 'ls_call' || key == 'ls_text') {
      if (_delayedSince != null) return 'Delayed: Google is refusing requests';
      if (_failing(key)) return 'Not delivering: an account needs reconnecting';
      final n = _googleAdsAccounts.length;
      return n == 0
          ? 'No Google Ads account connected'
          : '$n Google Ads ${n == 1 ? 'account' : 'accounts'}';
    }
    if (key == 'thumbtack') {
      if (_failing(key)) return 'Not delivering: an account needs reconnecting';
      final n = _thumbtackAccounts.length;
      return n == 0
          ? 'No Thumbtack account connected'
          : '$n Thumbtack ${n == 1 ? 'business' : 'businesses'}';
    }
    if (key == 'website_chat' || key == 'website_form' || key == 'website_booking') {
      return 'From your websites';
    }
    return null;
  }

  /// The sources as a page of their own, in place of the list.
  Widget _sourcesPage(ThemeData theme) {
    void close() => setState(() => _choosingSource = false);

    Widget row(String? key, String label, IconData icon) {
      final colours = key == null
          ? ToneColours.of(context, Tone.info)
          : sourceColours(context, _badgeWordFor(key));
      final unread = _unreadIn(key);
      final state = _sourceState(key);
      final failing = key != null && _failing(key);

      return ListTile(
        key: ValueKey('source-row-${key ?? 'all'}'),
        selected: _source == key,
        leading: CircleAvatar(
          radius: 18,
          backgroundColor: colours.fill,
          foregroundColor: colours.ink,
          child: Icon(icon, size: 18),
        ),
        title: Text(label),
        subtitle: state == null
            ? null
            : Text(
                state,
                style: failing
                    ? TextStyle(color: theme.colorScheme.error)
                    : null,
              ),
        trailing: unread > 0 ? _count(theme, unread) : null,
        onTap: () {
          close();
          _setSource(key);
        },
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 12, 4),
          child: Row(
            children: [
              IconButton(
                key: const ValueKey('sources-back'),
                tooltip: 'Back to the leads',
                icon: const Icon(Icons.arrow_back),
                onPressed: close,
              ),
              Text('Sources', style: theme.textTheme.titleMedium),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: ListView(
            key: const ValueKey('sources-page'),
            children: [
              row(null, 'All sources', Icons.all_inbox),
              for (final s in _visibleSources) row(s.$1, s.$2, s.$3),
              if (_canConnect)
                Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton.icon(
                  key: const ValueKey('sources-manage'),
                  onPressed: _openSources,
                  icon: const Icon(Icons.link, size: 18),
                  label: const Text('Manage'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// A lead open on a phone, in place of the list, with a way back.
  Widget _phoneThread(ThemeData theme, Map<String, dynamic> lead) {
    void close() => setState(() => _phoneLeadId = null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 4, 0),
          child: Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              key: const ValueKey('thread-back'),
              onPressed: close,
              icon: const Icon(Icons.arrow_back),
              label: const Text('All leads'),
            ),
          ),
        ),
        Expanded(
          child: LeadThread(
            key: ValueKey('thread-${lead['id']}'),
            api: widget.api,
            lead: lead,
            // Deciding what a lead is ends the visit: back to the list, which
            // reloads with the new status, rather than a stale control on a
            // screen holding the row as it was.
            onStatusChanged: (status) async {
              await _setStatus(lead, status);
              if (mounted) close();
            },
            onRead: () => _markRead(lead),
            onReplied: _load,
            onStar: (on) => _mark(lead, starred: on),
            onNote: (text) => _mark(lead, note: text, clearNote: text == null),
            session: widget.session,
            onBooked: _booked,
            onHidden: () async {
              close();
              await _load();
            },
          ),
        ),
      ],
    );
  }

  void _setFilters({
    bool? unread,
    bool? unreplied,
    bool? starred,
    bool? overdue,
    bool? followUp,
  }) {
    final next = (
      unread ?? _unread,
      unreplied ?? _unreplied,
      starred ?? _starred,
      overdue ?? _overdue,
      followUp ?? _followUp,
    );
    if (next == (_unread, _unreplied, _starred, _overdue, _followUp)) return;
    setState(() {
      _unread = next.$1;
      _unreplied = next.$2;
      _starred = next.$3;
      _overdue = next.$4;
      _followUp = next.$5;
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
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _listHeader(theme),
                  const Divider(height: 1),
                  Expanded(
                    child: _listBody(
                      theme,
                      selectedId: selected?['id'] as String?,
                      onTap: (lead) =>
                          setState(() => _selectedId = lead['id'] as String?),
                    ),
                  ),
                ],
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
                onHorizontalDragEnd: (_) => widget.session.setPreference(
                  'leads.list_percent',
                  (_listShare * 100).round().clamp(15, 70),
                ),
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
                      onHidden: _load,
                    ),
            ),
          ],
        );
      },
    );
  }

  /// On a phone the thread replaces the list in place, with its own back
  /// arrow: never a page pushed over the app (the containment rule).
  void _openThread(Map<String, dynamic> lead) => setState(() {
    _phoneLeadId = lead['id'] as String?;
    _phoneLeadAsOpened = lead;
  });

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

  /// Manage opens the Connections page in place of the leads.
  void _openSources() => setState(() {
    _managing = true;
    _choosingSource = false;
  });

  String? get _sourceLabel => _source == null
      ? null
      : _sourceChips.where((c) => c.$1 == _source).firstOrNull?.$2;

  /// Why the list is empty, in words that point somewhere (UI review
  /// 2026-10-08): All can be empty while LS Call has leads, because LS Call
  /// is left out of All.
  String get _emptyWhy {
    final hidden = _source != null
        ? const <String>[]
        : [
            for (final s in _visibleSources)
              if (_allLeftOut.contains(s.$1) && _unreadIn(s.$1) > 0)
                s.$2,
          ];
    return [
      'Nothing here matches the filters above.',
      if (hidden.isNotEmpty)
        '${hidden.join(' and ')} ${hidden.length == 1 ? 'is' : 'are'} left out '
            'of All - open ${hidden.length == 1 ? 'its tab' : 'their tabs'} on the left.',
      'Fetch checks for new ones now.',
    ].join(' ');
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
                  ? (_sourceLabel == null
                        ? 'No enquiries yet'
                        : 'No $_sourceLabel enquiries yet')
                  : 'No advertising account connected',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              connected
                  ? _emptyWhy
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
      key: const ValueKey('leads-list'),
      itemCount: _leads.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        // Near the end: fetch the next ten before the bottom is reached.
        if (index >= _leads.length - 5 && _leads.length < _total) {
          WidgetsBinding.instance.addPostFrameCallback((_) => _more());
        }

        if (index == _leads.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              _loadingMore
                  ? 'Loading more…'
                  : '${_leads.length < _total ? '${_leads.length} of ' : ''}'
                        '$_total ${_total == 1 ? 'lead' : 'leads'}',
              key: const ValueKey('leads-count'),
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
    final preview = lead['last_contact_preview'] as String?;
    // Design 159: the avatar, the source chip, "job type · city".
    final src = LeadSourceFields.from(lead);
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
      child: OverdueGlow(
        key: ValueKey('overdue-glow-$id'),
        // Not under the selected fill: the open lead is being answered.
        since: lead['reply_overdue'] == true && !selected
            ? DateTime.tryParse('${lead['last_contact_at'] ?? ''}')
            : null,
        child: Container(
        // Approved colour layout 3 (c31b50ad): a primaryContainer fill,
        // unmistakable at a glance where a 3px rule is not. No leading-edge
        // accent bar anywhere, on Jose's ruling of 2026-09-08.
        color: selected ? theme.colorScheme.primaryContainer : null,
        // Design 161: tighter, with the picture centred on the row.
        padding: const EdgeInsets.fromLTRB(12, 5, 6, 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Padding(
              padding: EdgeInsets.zero,
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
            LeadAvatar(
              key: ValueKey('lead-avatar-$id'),
              name: leadTitle(lead),
              url: src.avatarUrl,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: LayoutBuilder(
                          builder: (context, box) => Row(
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
                            if (leadNameIsAi(lead)) ...[
                              const SizedBox(width: 4),
                              AiMark(
                                key: ValueKey('lead-ai-name-${lead['id']}'),
                                tooltip: aiNameTooltip,
                              ),
                            ],
                            // The badges wrap under each other rather than push the
                            // row past its edge: at half a desktop screen the list
                            // pane is ~300px and a Customer + Text + Awaiting reply +
                            // warning + Google row overflowed by 118px (Jose,
                            // 2026-09-28).
                            const SizedBox(width: 8),
                            // Design 161: one line. The chips never wrap;
                            // past the edge they are clipped and the name
                            // ellipsizes first.
                            // The chips take the room they need (up to 70%
                            // of the row) and the name gets the rest: a
                            // fixed 3:2 split clipped chips beside short
                            // names (Jose, 2026-10-30).
                            ConstrainedBox(
                              constraints: BoxConstraints(
                                maxWidth: box.maxWidth * 0.7,
                              ),
                              child: SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                physics: const NeverScrollableScrollPhysics(),
                                child: _spaced([
                                // Beside the name, in its own colour (design 159).
                                if (src.sourceKey != null)
                                  LeadSourceChip(
                                    src.sourceKey,
                                    key: ValueKey('source-badge-$id'),
                                  ),
                                // A specimen, not a customer. First on the row because
                                // it changes how everything after it should be read.
                                if (lead['is_test'] == true) ...[
                                  _Badge('Test', key: ValueKey('test-badge-$id')),
                                ],
                                // AI thinks Google should refund it, not yet asked.
                                if (lead['credit'] is Map &&
                                    (lead['credit'] as Map)['requested_at'] == null)
                                  _Badge('Credit?', key: ValueKey('credit-badge-$id')),
                                // An unanswered RC call: Possible spam, Missed
                                // call or Voicemail (Jose, 2026-10-16).
                                if ((lead['source'] == 'ringcentral' ||
                                        lead['source'] == 'ez_calls') &&
                                    lead['source_status'] is String)
                                  _Badge(
                                    rcCallTag(lead['source_status'] as String),
                                    key: ValueKey('rc-tag-$id'),
                                  ),
                                // Only when it is not New: 43,184 of 43,190 are,
                                // and a pill on every row would say nothing.
                                if ((lead['status'] ?? 'new') != 'new')
                                  leadStatusPill(
                                    lead['status'],
                                    key: ValueKey('status-pill-$id'),
                                  ),
                                // Somebody else has it open right now.
                                if ((lead['viewers'] as List?)?.isNotEmpty ?? false)
                                  Tooltip(
                                    message: '${(lead['viewers'] as List).join(', ')} '
                                        'viewing this lead',
                                    child: Row(
                                      key: ValueKey('viewers-$id'),
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Icon(
                                          Icons.visibility_outlined,
                                          size: 15,
                                          color: ToneColours.of(context, Tone.caution).ink,
                                        ),
                                        const SizedBox(width: 3),
                                        // Flexible, so a long name ellipsizes in a
                                        // narrow list rather than running past it.
                                        ConstrainedBox(
                                          constraints: const BoxConstraints(maxWidth: 90),
                                          child: Text(
                                            '${(lead['viewers'] as List).first}'
                                            '${(lead['viewers'] as List).length > 1 ? ' +${(lead['viewers'] as List).length - 1}' : ''}',
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: theme.textTheme.labelSmall?.copyWith(
                                              fontWeight: FontWeight.w700,
                                              color: ToneColours.of(context, Tone.caution).ink,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                // This number is already a customer.
                                if (lead['customer'] is Map) ...[
                                  _Badge(
                                    'Customer',
                                    key: ValueKey('customer-badge-$id'),
                                  ),
                                ],
                                // A dispatcher left a note: seen from the list, read on
                                // hover (Jose, 2026-10-13: "u can't tell a note is
                                // there unless u enter the lead").
                                if ((lead['note'] as String?)?.trim().isNotEmpty ?? false)
                                  Tooltip(
                                    message: [
                                      if ((lead['note_by'] as Map?)?['name'] != null)
                                        'Note · ${(lead['note_by'] as Map)['name']}',
                                      '${lead['note']}',
                                    ].join('\n'),
                                    child: Icon(
                                      Icons.sticky_note_2,
                                      key: ValueKey('note-mark-$id'),
                                      size: 17,
                                      color: ToneColours.of(context, Tone.caution).ink,
                                    ),
                                  ),
                                // Replied or not, and ONLY where a reply is possible.
                                //
                                // `can_reply` is the channel's own answer, not a guess
                                // from lead_type here: a call cannot be replied to, a
                                // Thumbtack specimen cannot, and a lead whose account was
                                // disconnected cannot. On all of those the badge is
                                // absent entirely rather than reading "Replied", because
                                // "no reply is owed" and "a reply was sent" are different
                                // things and one must not be dressed as the other.
                                // Not on a Completed lead: nobody owes them an
                                // answer, whoever spoke last (Jose, 2026-10-01).
                                // A form has no reply box, but its overdue
                                // mark means a call back is owed (Jose, 2026-10-14).
                                // Not a lead and Lost owe nothing either; the
                                // server says which (Jose, 2026-11-02).
                                if ((lead['can_reply'] == true ||
                                        lead['reply_overdue'] == true) &&
                                    lead['reply_owed'] != false &&
                                    lead['status'] != 'completed') ...[
                                  _Badge(
                                    mine ? 'Replied' : 'Awaiting reply',
                                    key: ValueKey('reply-badge-$id'),
                                    alert: !mine,
                                  ),
                                ],
                                // Waited too long: the customer spoke last over ten
                                // minutes ago and the lead is still New (Jose,
                                // 2026-09-28). The server decides; the row only shows it.
                                if (lead['reply_overdue'] == true) ...[
                                  Tooltip(
                                    message: 'Waiting more than 10 minutes for a reply',
                                    child: Icon(
                                      Icons.warning_rounded,
                                      key: ValueKey('overdue-$id'),
                                      size: 18,
                                      color: theme.colorScheme.error,
                                    ),
                                  ),
                                ],
                                ]),
                              ),
                            ),
                          ],
                        ),
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        leadWhen(
                          (lead['last_contact_at'] ?? lead['received_at'])
                              as String?,
                        ),
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(width: 6),
                    ],
                  ),
                  const SizedBox(height: 1),
                  LayoutBuilder(
                    builder: (context, box) => Row(
                    children: [
                      // Design 161: job and city lead the one muted line.
                      // Capped, not Flexible: a loose Flexible beside the
                      // Expanded preview left its unused half empty, so the
                      // star drifted row to row (Jose, 2026-10-30).
                      if (src.jobLine != null)
                        ConstrainedBox(
                          constraints: BoxConstraints(
                            maxWidth: box.maxWidth * 0.4,
                          ),
                          child: Padding(
                            padding: const EdgeInsets.only(right: 6),
                            child: Text(
                              src.jobLine!,
                              key: ValueKey('lead-job-line-$id'),
                              style: muted,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                      // No company tag here: it is on the lead's own header,
                      // and Jose wants it in one place only (2026-09-30, "not
                      // on both").
                      // The source first on the line, in its own colour.
                      if (src.sourceKey == null)
                        _SourceBadge(
                          leadSourceWord(lead),
                          key: ValueKey('source-badge-$id'),
                        ),
                      // The channel badge is drawn only when it says
                      // something the source badge does not. "Call · Google"
                      // is two facts; a chat row read "Chat · Chat", one fact
                      // twice (UI/UX, 2026-09-08). The rule needs no list of
                      // sources: a fifth source with one channel gets the
                      // right answer for free.
                      if (channel != null &&
                          !leadSourceWord(lead).endsWith(
                            channelWord(channel),
                          )) ...[
                        const SizedBox(width: 4),
                        _Badge(
                          channelWord(channel),
                          call: channel == 'PHONE_CALL',
                        ),
                      ],
                      const SizedBox(width: 6),
                      Expanded(
                        child: preview != null && preview.isNotEmpty
                            ? Text.rich(
                                TextSpan(
                                  children: [
                                    // Which website, first (Jose, 2026-10-19:
                                    // "Forms not showing which website it
                                    // came from").
                                    if ((lead['site'] as String?)?.isNotEmpty ==
                                        true)
                                      TextSpan(text: '${lead['site']} · '),
                                    if (mine)
                                      TextSpan(
                                        text: 'You: ',
                                        style: TextStyle(
                                          fontWeight: FontWeight.w600,
                                          color: theme.colorScheme.onSurface,
                                        ),
                                      ),
                                    if (src.adQuestion)
                                      const TextSpan(text: 'Ad question: '),
                                    TextSpan(text: preview),
                                  ],
                                ),
                                style: muted,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              )
                            : Text(
                                [
                                  if ((lead['phone'] as String?)?.isNotEmpty ==
                                          true &&
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
                      ),
                      // Hollow until set, at the end of the line it marks
                      // (design 30): stacked under the time it made every row
                      // as tall as a 44px target. A phone keeps 40 of target.
                      IconButton(
                        key: ValueKey('star-$id'),
                        tooltip: starred ? 'Unstar' : 'Star',
                        iconSize: 16,
                        padding: EdgeInsets.zero,
                        constraints: BoxConstraints.tight(
                          Size.square(
                            MediaQuery.sizeOf(context).width < 600 ? 40 : 30,
                          ),
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
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      ),
    );
  }
}

/// A row of chips, 6 apart (design 161: one line, never wrapped).
Widget _spaced(List<Widget> chips) => Row(
  mainAxisSize: MainAxisSize.min,
  children: [
    for (final (i, c) in chips.indexed) ...[
      if (i > 0) const SizedBox(width: 6),
      c,
    ],
  ],
);

/// Google / Thumbtack / Chat / Form: the short word for the row.
/// "POSSIBLE_SPAM" → "Possible spam".
String rcCallTag(String status) {
  final words = status.toLowerCase().replaceAll('_', ' ');
  return words.isEmpty ? words : words[0].toUpperCase() + words.substring(1);
}

String sourceBadgeWord(String? source) => switch (source) {
  'thumbtack' => 'Thumbtack',
  'facebook' => 'Facebook',
  'instagram' => 'Instagram',
  'website_chat' => 'Chat',
  'website_form' => 'Form',
  'website_booking' => 'Online Booking',
  'workiz' => 'Workiz',
  'whatsapp' => 'WhatsApp',
  'ringcentral' => 'RC Call',
  'ez_calls' => 'EZ Call',
  'ez_texts' => 'EZ Text',
  _ => 'Google',
};

/// The source, in the neutral container: a fourth mark that never competes
/// with Call and Text for the eye.
class _SourceBadge extends StatelessWidget {
  const _SourceBadge(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    final colours = sourceColours(context, text);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: colours.ink,
        ),
      ),
    );
  }
}

/// The source tag's word: Google says which kind, as A1 Leads does - LS Call,
/// LS Text or LS Booking (Jose, 2026-09-29).
String leadSourceWord(Map<String, dynamic> lead) {
  // The review link's faces form (Jose, 2026-10-09: "Bad Rating or Good
  // Rating depending on the outcome").
  if (lead['source'] == 'website_form') {
    switch (lead['lead_type']) {
      case 'BAD_RATING':
        return 'Bad Rating';
      case 'GOOD_RATING':
        return 'Good Rating';
    }
  }
  if (lead['source'] != 'local_services') {
    return sourceBadgeWord(lead['source'] as String?);
  }
  return switch (lead['lead_type']) {
    'PHONE_CALL' => 'LS Call',
    'MESSAGE' => 'LS Text',
    'BOOKING' => 'LS Booking',
    _ => 'Google',
  };
}

/// One colour per source, so the list can be read by colour (Jose,
/// 2026-09-29: "give a color for each"). From the theme's palettes, AA in
/// light and dark; no two sources share one.
ToneColours sourceColours(BuildContext context, String word) => switch (word) {
  'LS Call' => PersonColours.hue(context, 0), // blue
  'LS Text' => PersonColours.hue(context, 2), // green
  'LS Booking' => PersonColours.hue(context, 1), // teal
  'Thumbtack' => PersonColours.hue(context, 5), // orange
  'Chat' => PersonColours.hue(context, 4), // gold
  'Form' => PersonColours.hue(context, 7), // brown
  // Named and coloured by Jose, 2026-10-12: "Messenger is too broad"; blue.
  'Facebook' => PersonColours.hue(context, 10), // sky
  'Instagram' => PersonColours.hue(context, 8), // rose: theme.dart bans purple
  'Workiz' => PersonColours.hue(context, 3), // olive
  'RC Call' => PersonColours.hue(context, 11), // RingCentral's deep orange
  'EZ Call' => PersonColours.hue(context, 6), // coral
  'EZ Text' => PersonColours.hue(context, 12), // deep teal
  _ => PersonColours.hue(context, 9), // slate: Tone.info is LS Call's blue
};

/// "Call" or "Text". Never PHONE_CALL, never "LS Ads".
class _Badge extends StatelessWidget {
  const _Badge(this.text, {super.key, this.call = false, this.alert = false});

  final String text;
  final bool call;

  /// Solid red: somebody is owed an answer (Jose, 2026-10-13: Awaiting reply
  /// "should be a different color, maybe bright red").
  final bool alert;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        // Call is the primary family, Text the secondary. tertiaryContainer
        // is off limits: it would be the first purple in the app.
        color: alert
            ? scheme.error
            : call
            ? scheme.primaryContainer
            : scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: alert
              ? scheme.onError
              : call
              ? scheme.onPrimaryContainer
              : scheme.onSecondaryContainer,
        ),
      ),
    );
  }
}

/// A customer left waiting: the row turns redder the longer they wait and
/// pulses (Jose, 2026-10-01: the warning sign alone "is not enough"). From a
/// faint wash at 10 minutes to its strongest at an hour. Still, not pulsing,
/// where the platform asks for less motion.
class OverdueGlow extends StatefulWidget {
  const OverdueGlow({super.key, required this.since, required this.child});

  /// When the customer last wrote; null when nobody is waiting.
  final DateTime? since;
  final Widget child;

  /// Off in widget tests, whose pumpAndSettle never settles on a pulse.
  static bool pulse = true;

  @override
  State<OverdueGlow> createState() => _OverdueGlowState();
}

class _OverdueGlowState extends State<OverdueGlow>
    with SingleTickerProviderStateMixin {
  late final AnimationController _beat = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sync();
  }

  @override
  void didUpdateWidget(OverdueGlow old) {
    super.didUpdateWidget(old);
    _sync();
  }

  void _sync() {
    final moving = widget.since != null &&
        OverdueGlow.pulse &&
        !MediaQuery.disableAnimationsOf(context);
    if (moving && !_beat.isAnimating) {
      _beat.repeat(reverse: true);
    } else if (!moving && _beat.isAnimating) {
      _beat.stop();
    }
  }

  @override
  void dispose() {
    _beat.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final since = widget.since;
    if (since == null) return widget.child;

    final minutes = DateTime.now().difference(since).inMinutes.clamp(10, 60);
    final base = 0.06 + (minutes - 10) / 50 * 0.14; // 0.06 at 10 min, 0.20 at an hour
    final red = Theme.of(context).colorScheme.error;

    return AnimatedBuilder(
      animation: _beat,
      builder: (context, child) => DecoratedBox(
        decoration: BoxDecoration(
          color: red.withValues(alpha: base + 0.08 * _beat.value),
        ),
        child: child,
      ),
      child: widget.child,
    );
  }
}
