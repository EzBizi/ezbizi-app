import '../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/connected_account.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import 'business_profile/listing_copy.dart';
import 'business_profile/listing_media.dart';
import 'business_profile/listing_services.dart';
import 'business_profile/performance_view.dart';
import 'business_profile/phone_check_view.dart';
import 'business_profile/whatsapp_links.dart';
import 'business_profile/whatsapp_row.dart';
import 'monitoring/device_display.dart' show relativeAge;

/// Business Profile — the listings the public sees on Maps.
///
/// ── What is NOT showing leads the screen ─────────────────────
///
/// A suspended or unverified listing looks perfectly healthy in a database and
/// is invisible on Google. Nobody discovers that from a list of names — they
/// discover it when a customer says they could not find the business. So the
/// count of listings Google is not showing sits at the top, and those rows
/// sort first and are tinted rather than left for somebody to notice.
///
/// ── The branch leads the row ─────────────────────────────────
///
/// Every listing of one business carries the same name on Google, so a list
/// of titles reads "A Business" twenty times and the street is the only way
/// to tell them apart (UI queue 116, Jose picked layout 1 + phone 4). The row
/// is led by the company and branch EzBizi files it under instead.
class BusinessProfileScreen extends StatefulWidget {
  const BusinessProfileScreen({
    super.key,
    required this.api,
    required this.session,
    this.performance = false,
  });

  final ApiClient api;
  final Session session;

  /// Which of the two top-level chips this is (Jose, 2026-10-20: "Reviews -
  /// GBP Listings - GBP Performance - Review Links"): the listings, or how
  /// they perform on Google.
  final bool performance;

  @override
  State<BusinessProfileScreen> createState() => _BusinessProfileScreenState();
}

/// Which listings the segment shows.
enum _Show { showing, notShowing, all }

/// The branch picker's value for listings filed under no branch. Not a uuid,
/// so it cannot collide with a franchise id.
const _unfiled = '-';

/// Below this tab width the table becomes cards (phone layout 4).
const double _cardsBelow = 760;

/// Below this the details pane takes the tab's place rather than sitting
/// beside the table, which would squeeze seven columns into a sliver.
const double _paneBesideFrom = 1100;

class _BusinessProfileScreenState extends State<BusinessProfileScreen> {
  bool _loading = true;
  bool _syncing = false;

  /// A just-connected account still reading its listings on the server.
  bool _serverSyncing = false;
  Timer? _syncPoll;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _connections = [];
  int _total = 0;
  int _notLive = 0;
  int _request = 0;

  // Filtered here rather than with `?live=no`: the segment shows a count on
  // every choice, which needs the whole list either way.
  _Show _show = _Show.all;
  String? _branch;
  String _search = '';
  final _searchController = TextEditingController();

  /// The listing whose details are open.
  String? _openId;

  /// Which of the open listing's tabs shows: Info, Media or Services
  /// (design 154 #1, #4, #16).
  int _paneTab = 0;

  /// Phone check in the tab's place (design 154 #10).
  bool _phoneCheck = false;

  /// Each listing's WhatsApp link, inline in the table (UI queue 164).
  late final _wa = WhatsAppLinks(widget.api)..addListener(_waChanged);

  void _waChanged() {
    if (mounted) setState(() {});
  }

  /// Every listing but [id], as "Company · Branch", for copying to.
  List<ListingTarget> _othersThan(String id) => [
    for (final l in _locations)
      if (l['id'] != id) (id: '${l['id']}', label: _rowName(l)),
  ];

  bool get _performance => widget.performance;

  @override
  void initState() {
    super.initState();
    _load();
    if (!_performance) _wa.load();
  }

  @override
  void dispose() {
    _wa.dispose();
    _syncPoll?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = _locations.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/business-profile/locations');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _locations = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _total = (meta['total'] as num?)?.toInt() ?? _locations.length;
      _notLive =
          (meta['not_live'] as num?)?.toInt() ??
          _locations.where((l) => !_live(l)).length;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      // Look again until the connect-time sync has read the listings.
      _serverSyncing = meta['syncing'] == true;
      // A read that failed says so on the card instead of looking idle (2026-11-12).
      final failed = [
        for (final e
            in (meta['sync_errors'] as List? ?? const []).whereType<Map>())
          '${e['connection']}: ${e['message']}',
      ];
      if (failed.isNotEmpty) _notice = failed.join(' · ');
      _syncPoll?.cancel();
      _syncPoll = _serverSyncing
          ? Timer(const Duration(seconds: 10), _load)
          : null;

      if (_openId != null && !_locations.any((l) => l['id'] == _openId)) {
        _openId = null;
      }
    });
  }

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/business-profile/sync', const {});

    if (!mounted) return;
    setState(() => _syncing = false);

    if (!response.ok) {
      // Carries the server's own sentence — including "Google has not granted
      // permission yet", which is a wait rather than a fault and must not read
      // like one.
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final fetched = (data['fetched'] as num?)?.toInt() ?? 0;

    setState(
      () => _notice = fetched > 0
          ? 'Checked $fetched listing${fetched == 1 ? '' : 's'}.'
          : 'Nothing to check.',
    );

    await _load();
  }

  bool get _connected => _connections.any((c) => c['status'] == 'connected');

  /// The connected profile itself, so the tab can name it and remove it.
  Map<String, dynamic>? get _profile =>
      _connections.isEmpty ? null : _connections.first;

  int get _showingCount => _locations.where(_live).length;
  int get _notShowingCount => _locations.length - _showingCount;

  /// Not showing first, then the server's order (by title) within each half.
  List<Map<String, dynamic>> get _visible {
    final query = _search.trim().toLowerCase();

    bool keep(Map<String, dynamic> l) {
      if (_show == _Show.showing && !_live(l)) return false;
      if (_show == _Show.notShowing && _live(l)) return false;
      if (_branch != null && (_franchiseId(l) ?? _unfiled) != _branch) {
        return false;
      }
      if (query.isEmpty) return true;

      return [
        _city(l),
        l['address'],
        l['title'],
      ].any((v) => v is String && v.toLowerCase().contains(query));
    }

    final kept = _locations.where(keep).toList();

    return [...kept.where((l) => !_live(l)), ...kept.where(_live)];
  }

  // ── Changing a listing ───────────────────────────────────────

  /// BusinessLocationPolicy::update: `business_profile.edit`, then reach.
  bool get _canEdit => widget.session.me?.can('business_profile.edit') ?? false;

  /// The auto-reply switch takes both halves of the act
  /// (BusinessProfileAutoReplyController): the listing's update policy and
  /// Reviews' publish grant.
  bool get _canAutoReply =>
      _canEdit && (widget.session.me?.can('reviews.publish') ?? false);

  /// Listings with a write in flight, so a second tap cannot race the first.
  final Set<String> _saving = {};

  void _replace(
    String id,
    Map<String, dynamic> Function(Map<String, dynamic>) change,
  ) {
    final i = _locations.indexWhere((l) => l['id'] == id);
    if (i >= 0) _locations[i] = change(_locations[i]);
  }

  /// PATCH /business-profile/locations/{id} {franchise_id} — null unfiles it.
  Future<void> _setBranch(Map<String, dynamic> l, String? franchiseId) async {
    final id = l['id'] as String;
    if (_franchiseId(l) == franchiseId) return;

    setState(() => _saving.add(id));
    final response = await widget.api.patch('/business-profile/locations/$id', {
      'franchise_id': franchiseId,
    });
    if (!mounted) return;

    setState(() {
      _saving.remove(id);
      if (!response.ok) {
        _notice = response.message;
        return;
      }
      final data = response.data;
      if (data is Map<String, dynamic>) _replace(id, (_) => data);
    });
  }

  /// PUT /business-profile/locations/{id}/auto-reply {on}.
  Future<void> _setAutoReply(Map<String, dynamic> l, bool on) async {
    final id = l['id'] as String;

    setState(() => _saving.add(id));
    final response = await widget.api.put(
      '/business-profile/locations/$id/auto-reply',
      {'on': on},
    );
    if (!mounted) return;

    setState(() {
      _saving.remove(id);
      if (!response.ok) {
        _notice = response.message;
        return;
      }
      final data = response.data as Map<String, dynamic>? ?? const {};
      _replace(id, (row) => {...row, 'auto_reply': data['auto_reply'] == true});
    });
  }

  /// A switch for somebody who may change it; On/Off text for anybody else.
  Widget _autoReply(
    ThemeData theme,
    Map<String, dynamic> l, {
    required String where,
  }) {
    final on = l['auto_reply'] == true;
    if (!_canAutoReply) {
      return Text(
        on ? 'On' : 'Off',
        key: ValueKey('gbp-$where-auto-reply-text-${l['id']}'),
        style: theme.textTheme.bodySmall,
      );
    }

    return Switch(
      key: ValueKey('gbp-$where-auto-reply-${l['id']}'),
      value: on,
      materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
      onChanged: _saving.contains(l['id']) ? null : (v) => _setAutoReply(l, v),
    );
  }

  /// The branch picker in the pane, greyed with the reason without the grant.
  Widget _branchPicker(Map<String, dynamic> l) {
    final me = widget.session.me;
    final current = _franchiseId(l);
    final options = <SearchOption<String>>[
      const SearchOption(value: null, label: 'Not filed under a branch'),
      if (me != null)
        for (final b in branchOptionsInView(me))
          SearchOption(value: b.id, label: b.label),
    ];
    // The branch it is on now, even when it is outside this view.
    if (current != null && !options.any((o) => o.value == current)) {
      options.add(SearchOption(value: current, label: _branchName(l)!));
    }

    return SearchPickerField<String>(
      key: const ValueKey('gbp-pane-branch-picker'),
      value: current,
      label: 'Filed under',
      isDense: true,
      options: options,
      helperText: _canEdit
          ? null
          : 'Your role cannot change which branch a listing is filed under.',
      helperMaxLines: 2,
      onChanged: !_canEdit || _saving.contains(l['id'])
          ? null
          : (v) => _setBranch(l, v),
    );
  }

  Map<String, dynamic>? get _open {
    for (final l in _locations) {
      if (l['id'] == _openId) return l;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Its own chip above, so no switch here.
    if (_performance) {
      return BusinessProfilePerformance(
        api: widget.api,
        session: widget.session,
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        final cards = box.maxWidth < _cardsBelow;
        final open = _open;
        final paneBeside = box.maxWidth >= _paneBesideFrom;

        if (_phoneCheck) {
          return PhoneCheckView(
            api: widget.api,
            labelOf: (id) {
              for (final l in _locations) {
                if (l['id'] == id) return _rowName(l);
              }
              return null;
            },
            onBack: () => setState(() => _phoneCheck = false),
          );
        }

        // Narrow, or too narrow for a pane beside the table: the details
        // take the tab's place, with a way back.
        if (open != null && !paneBeside) {
          return _details(theme, open, inPlace: true);
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(theme, cards),
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
            if (_locations.isNotEmpty) _filters(theme, cards),
            WhatsAppBar(links: _wa),
            const Divider(height: 1),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _locations.isEmpty
                    ? _empty(theme)
                    : Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            child: cards ? _cards(theme) : _table(theme),
                          ),
                          if (open != null) ...[
                            VerticalDivider(
                              width: 1,
                              color: EzBiziTheme.edge(context),
                            ),
                            SizedBox(
                              width: 380,
                              child: _details(theme, open, inPlace: false),
                            ),
                          ],
                        ],
                      ),
              ),
            ),
          ],
        );
      },
    );
  }

  // ── The top: counts, profile, Check Google ───────────────────

  Widget _header(ThemeData theme, bool narrow) {
    final showing = _total - _notLive;
    final counts = Text.rich(
      key: const ValueKey('gbp-counts'),
      TextSpan(
        children: [
          TextSpan(
            text: '$_total listing${_total == 1 ? '' : 's'}',
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          TextSpan(text: ' · $showing showing · '),
          TextSpan(
            text: '$_notLive not showing',
            style: _notLive > 0
                ? TextStyle(
                    color: theme.colorScheme.error,
                    fontWeight: FontWeight.w600,
                  )
                : null,
          ),
        ],
      ),
      style: theme.textTheme.bodyMedium,
      maxLines: narrow ? 2 : 1,
      overflow: TextOverflow.ellipsis,
    );

    final check = FilledButton.tonalIcon(
      key: const ValueKey('tour-customer_experience-check-google'),
      onPressed: _syncing || _serverSyncing ? null : _sync,
      icon: _syncing || _serverSyncing
          ? const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.sync, size: 18),
      label: Text(
        _serverSyncing
            ? 'Syncing…'
            : _syncing
            ? 'Checking…'
            : 'Check Google',
      ),
    );

    final profile = _profile;
    // Design 164: the connection is a chip; who it is and how to change it
    // are one tap away under the more menu, with Phone check.
    final google = profile == null
        ? null
        : Tooltip(
            message: '${profile['display_name'] ?? profile['name'] ?? ''}',
            child: StatusPill(
              key: const ValueKey('tour-business-profile-account'),
              profile['status'] == 'connected' ? 'Google ✓' : 'Google',
              tone: profile['status'] == 'connected' ? Tone.ok : Tone.caution,
            ),
          );
    final more = PopupMenuButton<String>(
      key: const ValueKey('gbp-more'),
      tooltip: 'More',
      icon: const Icon(Icons.more_horiz),
      onSelected: (v) {
        if (v == 'phone') setState(() => _phoneCheck = true);
        if (v == 'google' && profile != null) {
          showSidePanel<void>(
            context,
            builder: (context) => SidePanel(
              title: 'Google connection',
              child: ConnectedAccount(connection: profile),
            ),
          );
        }
      },
      itemBuilder: (context) => [
        const PopupMenuItem(
          key: ValueKey('cx-open-phone-check'),
          value: 'phone',
          child: ListTile(
            leading: Icon(Icons.phone_in_talk_outlined),
            title: Text('Phone check'),
          ),
        ),
        if (profile != null)
          const PopupMenuItem(
            key: ValueKey('gbp-google-connection'),
            value: 'google',
            child: ListTile(
              leading: Icon(Icons.settings_outlined),
              title: Text('Google connection…'),
            ),
          ),
      ],
    );

    // Mobile review 2026-11-13: beside the actions the counts wrapped to
    // "3 not sho…". On a phone they get the line to themselves, and the
    // chip, more and Check Google go under them.
    if (narrow) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            counts,
            const SizedBox(height: 6),
            // A Wrap, so a long label or a large text size puts the chip on
            // its own line rather than past the edge.
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: 4,
              children: [
                ?google,
                Row(mainAxisSize: MainAxisSize.min, children: [more, check]),
              ],
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
      child: Row(
        children: [
          Expanded(child: counts),
          if (google != null) ...[google, const SizedBox(width: 4)],
          more,
          const SizedBox(width: 4),
          check,
        ],
      ),
    );
  }

  // ── The filters: segment, branch, search ─────────────────────

  Widget _filters(ThemeData theme, bool narrow) {
    // One line per segment on a phone: "Not showing · 3" wrapped to two
    // (mobile review 2026-11-13). Scaled down rather than cut, so the count
    // is never lost.
    Widget fit(Widget label) =>
        narrow ? FittedBox(fit: BoxFit.scaleDown, child: label) : label;
    final segment = SegmentedButton<_Show>(
      key: const ValueKey('tour-business-profile-not-showing'),
      segments: [
        ButtonSegment(
          value: _Show.showing,
          label: fit(
            Text(
              'Showing · $_showingCount',
              maxLines: 1,
              softWrap: false,
              key: const ValueKey('gbp-show-showing'),
            ),
          ),
        ),
        ButtonSegment(
          value: _Show.notShowing,
          label: fit(
            Text(
              'Not showing · $_notShowingCount',
              maxLines: 1,
              softWrap: false,
              key: const ValueKey('gbp-show-not-showing'),
            ),
          ),
        ),
        const ButtonSegment(
          value: _Show.all,
          label: Text('All', key: ValueKey('gbp-show-all')),
        ),
      ],
      selected: {_show},
      showSelectedIcon: false,
      onSelectionChanged: (v) => setState(() {
        _show = v.first;
        _openId = null;
      }),
    );

    final branch = SizedBox(
      width: narrow ? double.infinity : 240,
      child: SearchPickerField<String>(
        key: const ValueKey('gbp-branch'),
        value: _branch,
        hint: 'All branches',
        isDense: true,
        options: _branchOptions(),
        onChanged: (v) => setState(() {
          _branch = v;
          _openId = null;
        }),
      ),
    );

    final search = SizedBox(
      width: narrow ? double.infinity : 240,
      child: ListSearch(
        key: const ValueKey('gbp-search'),
        controller: _searchController,
        hint: 'City or address',
        onChanged: (v) => setState(() => _search = v),
      ),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      child: narrow
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                segment,
                const SizedBox(height: 8),
                branch,
                const SizedBox(height: 8),
                search,
                if (whatsAppPickChips(_wa).isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Wrap(spacing: 6, children: whatsAppPickChips(_wa)),
                ],
              ],
            )
          : Wrap(
              spacing: 10,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [segment, branch, search, ...whatsAppPickChips(_wa)],
            ),
    );
  }

  /// Every branch a listing is filed under, plus "none" when one is not.
  List<SearchOption<String>> _branchOptions() {
    final seen = <String, String>{};
    final sortBy = <String, String>{};
    var unfiled = false;

    for (final l in _locations) {
      final id = _franchiseId(l);
      if (id == null) {
        unfiled = true;
      } else {
        seen.putIfAbsent(id, () => _branchName(l)!);
        // By company, then branch: a company's branches sit together.
        sortBy.putIfAbsent(
          id,
          () => '${_name(l['company']) ?? ''}\u0000${_name(l['franchise'])}'
              .toLowerCase(),
        );
      }
    }

    final named = seen.entries.toList()
      ..sort((a, b) => sortBy[a.key]!.compareTo(sortBy[b.key]!));

    return [
      const SearchOption(value: null, label: 'All branches'),
      for (final e in named) SearchOption(value: e.key, label: e.value),
      if (unfiled)
        const SearchOption(value: _unfiled, label: 'Not filed under a branch'),
    ];
  }

  // ── Nothing at all ───────────────────────────────────────────

  Widget _empty(ThemeData theme) {
    return Center(
      key: const ValueKey('tour-business-profile-empty'),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              _connected ? Icons.storefront_outlined : Icons.link_off,
              size: 40,
              color: theme.colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              _connected ? 'No listings yet' : 'No Business Profile connected',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              _connected
                  ? 'The profile is connected but no listings have come back '
                        'yet. Check Google fetches them.'
                  : 'This is where the listings the public sees on Google '
                        'Maps appear — name, address, hours, and whether '
                        'Google is showing them at all. Connect the profile '
                        'this organization already owns.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            if (!_connected) ...[
              const SizedBox(height: 18),
              ConnectAccount(
                api: widget.api,
                label: 'Business Profile',
                startPath: '/social/business-profile/start',
                onConnected: _load,
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Listings exist, but the filters leave none.
  Widget _noneMatch(ThemeData theme) {
    final allShowing =
        _show == _Show.notShowing &&
        _notShowingCount == 0 &&
        _branch == null &&
        _search.trim().isEmpty;

    return Padding(
      key: const ValueKey('tour-business-profile-empty'),
      padding: const EdgeInsets.all(32),
      child: Column(
        children: [
          Text(
            allShowing ? 'Every listing is showing' : 'No listings match',
            style: theme.textTheme.titleMedium,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 8),
          Text(
            allShowing
                ? 'Google is showing all of them. Nothing needs attention '
                      'here.'
                : 'Nothing fits these filters.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  // ── Wide: the table ──────────────────────────────────────────

  static const _columns = [
    ('Branch', 4),
    ('City', 3),
    ('Phone', 3),
    ('Google', 2),
    ('WhatsApp', 3),
    ('Auto-reply', 2),
    ('Checked', 2),
  ];

  Widget _table(ThemeData theme) {
    final rows = _visible;
    final edge = EzBiziTheme.edge(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget cells(List<Widget> children) => Row(
      children: [
        for (var i = 0; i < children.length; i++)
          Expanded(
            flex: _columns[i].$2,
            child: Padding(
              padding: const EdgeInsets.only(right: 10),
              child: Align(alignment: Alignment.centerLeft, child: children[i]),
            ),
          ),
      ],
    );

    Widget text(String value, {TextStyle? style}) => Text(
      value.isEmpty ? '—' : value,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: style ?? theme.textTheme.bodySmall,
    );

    return ListView(
      key: const ValueKey('tour-business-profile-listings'),
      padding: const EdgeInsets.only(bottom: 16),
      children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          decoration: BoxDecoration(
            border: Border(bottom: BorderSide(color: edge)),
          ),
          child: cells([
            for (final c in _columns)
              Text(
                c.$1.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  letterSpacing: 0.8,
                  fontWeight: FontWeight.w700,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
          ]),
        ),
        if (rows.isEmpty) _noneMatch(theme),
        for (final l in rows)
          Material(
            key: ValueKey('gbp-row-${l['id']}'),
            // Neutral: the red pill names the problem; a whole red row read
            // as an alarm (desktop review 2026-11-13, Top 7).
            color: l['id'] == _openId
                ? theme.colorScheme.primaryContainer
                : Colors.transparent,
            child: InkWell(
              onTap: () => setState(
                () => _openId = l['id'] == _openId ? null : l['id'] as String?,
              ),
              child: Container(
                constraints: const BoxConstraints(minHeight: 52),
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  border: Border(bottom: BorderSide(color: edge)),
                ),
                child: cells([
                  _branchCell(theme, l),
                  text(_city(l)),
                  text(formatPhoneNumber(l['phone'] as String?)),
                  _pill(l),
                  WhatsAppCell(links: _wa, id: '${l['id']}'),
                  _autoReply(theme, l, where: 'row'),
                  text(_checked(l), style: muted),
                ]),
              ),
            ),
          ),
      ],
    );
  }

  Widget _branchCell(ThemeData theme, Map<String, dynamic> l) {
    final unfiled = _franchiseId(l) == null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          _rowName(l),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
        if (unfiled)
          Text(
            'Not filed under a branch',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              fontStyle: FontStyle.italic,
            ),
          ),
      ],
    );
  }

  // ── Narrow: the cards ────────────────────────────────────────

  Widget _cards(ThemeData theme) {
    final rows = _visible;

    return ListView(
      key: const ValueKey('tour-business-profile-listings'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      children: [
        if (rows.isEmpty) _noneMatch(theme),
        for (final l in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Material(
              key: ValueKey('gbp-card-${l['id']}'),
              color: theme.colorScheme.surface,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
                side: BorderSide(color: EzBiziTheme.edge(context)),
              ),
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                onTap: () => setState(() => _openId = l['id'] as String?),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(child: _branchCell(theme, l)),
                          const SizedBox(width: 8),
                          _pill(l),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        [
                          _city(l),
                          formatPhoneNumber(l['phone'] as String?),
                        ].where((p) => p.isNotEmpty).join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      if (_wa.rows.isNotEmpty)
                        WhatsAppCell(links: _wa, id: '${l['id']}'),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  // ── One listing ──────────────────────────────────────────────

  /// The branch and the auto-reply are the two fields here that are EzBizi's
  /// rather than Google's, so they are the two that can change. No "Open in
  /// Google" — the list sends no Maps or listing URL, and a guessed one would
  /// be a claim about Google this screen cannot back.
  Widget _details(
    ThemeData theme,
    Map<String, dynamic> l, {
    required bool inPlace,
  }) {
    final live = _live(l);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget fact(String label, String value, {Key? key}) => Padding(
      key: key,
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label.toUpperCase(), style: theme.textTheme.labelSmall),
          const SizedBox(height: 2),
          SelectableText(
            value.isEmpty ? '—' : value,
            style: theme.textTheme.bodyMedium,
          ),
        ],
      ),
    );

    return ListView(
      key: const ValueKey('gbp-pane'),
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      children: [
        Row(
          children: [
            if (inPlace)
              IconButton(
                key: const ValueKey('gbp-pane-back'),
                tooltip: 'Back',
                icon: const Icon(Icons.arrow_back),
                onPressed: () => setState(() => _openId = null),
              ),
            Expanded(
              child: Text(
                _rowName(l),
                style: theme.textTheme.titleMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (!inPlace)
              IconButton(
                key: const ValueKey('gbp-pane-close'),
                tooltip: 'Close',
                icon: const Icon(Icons.close, size: 18),
                onPressed: () => setState(() => _openId = null),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Align(
          alignment: Alignment.centerLeft,
          child: live
              ? const StatusPill('Showing on Google', tone: Tone.ok)
              : StatusPill(_googleWord(l), tone: Tone.bad),
        ),
        const SizedBox(height: 8),
        TabBarLike(
          key: const ValueKey('gbp-pane-tabs'),
          level: TabLevel.secondary,
          labels: const [
            ('Info', Icons.info_outline),
            ('Media', Icons.photo_library_outlined),
            ('Services', Icons.handyman_outlined),
          ],
          selected: _paneTab,
          onSelected: (i) => setState(() => _paneTab = i),
        ),
        const SizedBox(height: 12),
        if (_paneTab == 1)
          ListingMedia(
            api: widget.api,
            locationId: '${l['id']}',
            others: _othersThan('${l['id']}'),
          )
        else if (_paneTab == 2)
          ListingServices(
            api: widget.api,
            locationId: '${l['id']}',
            others: _othersThan('${l['id']}'),
          )
        else
          ..._info(theme, l, fact, muted),
      ],
    );
  }

  List<Widget> _info(
    ThemeData theme,
    Map<String, dynamic> l,
    Widget Function(String, String, {Key? key}) fact,
    TextStyle? muted,
  ) {
    final verification = (l['verification_state'] as String?) ?? '';
    final category = l['primary_category'] as String? ?? '';
    final whatsapp = l['whatsapp_link'] as String?;
    return [
      fact('Address', l['address'] as String? ?? ''),
      fact('Phone', formatPhoneNumber(l['phone'] as String?)),
      fact('Website', l['website'] as String? ?? ''),
      if (whatsapp != null && whatsapp.isNotEmpty)
        WhatsAppRow(
          link: whatsapp,
          onSet: _wa.tickable('${l['id']}')
              ? () => _wa.pickOnly('${l['id']}')
              : null,
        ),
      fact(
        'Category',
        [
          category,
          if (verification.isNotEmpty) _humanise(verification).toLowerCase(),
        ].where((p) => p.isNotEmpty).join(' · '),
      ),
      Padding(
        key: const ValueKey('gbp-pane-branch'),
        padding: const EdgeInsets.only(bottom: 12),
        child: _branchPicker(l),
      ),
      Padding(
        key: const ValueKey('gbp-pane-auto-reply'),
        padding: const EdgeInsets.only(bottom: 12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'Auto-reply to reviews',
                style: theme.textTheme.bodyMedium,
              ),
            ),
            _autoReply(theme, l, where: 'pane'),
          ],
        ),
      ),
      Text('Checked ${_checked(l).toLowerCase()}', style: muted),
    ];
  }

  Widget _pill(Map<String, dynamic> l) => _live(l)
      ? const StatusPill('Showing', tone: Tone.ok)
      : StatusPill(_googleWord(l), tone: Tone.bad);
}

// ── Reading a listing ──────────────────────────────────────────

bool _live(Map<String, dynamic> l) => l['is_live'] == true;

String? _franchiseId(Map<String, dynamic> l) {
  final franchise = l['franchise'];
  return franchise is Map ? franchise['id'] as String? : null;
}

String? _name(Object? record) {
  if (record is! Map) return null;
  final name = (record['name'] as String?)?.trim() ?? '';
  return name.isEmpty ? null : name;
}

/// "Company · Branch" for a listing filed under a branch, the order every
/// branch picker uses (Jose, 2026-10-21); null when it is not filed.
String? _branchName(Map<String, dynamic> l) {
  final branch = _name(l['franchise']);
  if (branch == null) return null;
  final company = _name(l['company']);
  return company == null ? branch : '$company · $branch';
}

/// What the row is called: its branch, or Google's own name when it has none.
String _rowName(Map<String, dynamic> l) {
  final branch = _branchName(l);
  if (branch != null) return branch;

  for (final key in ['label', 'title']) {
    final value = (l[key] as String?)?.trim() ?? '';
    if (value.isNotEmpty) return value;
  }

  return 'Untitled listing';
}

/// The list sends no city of its own: `label` is "title · city, state"
/// (BusinessLocation::label), so the city is what follows the title.
String _city(Map<String, dynamic> l) {
  final title = (l['title'] as String?) ?? '';
  final label = (l['label'] as String?) ?? '';
  final prefix = '$title · ';
  return label.startsWith(prefix) ? label.substring(prefix.length) : '';
}

/// Google's own word when it is not simply showing — SUSPENDED and UNVERIFIED
/// mean different things and the fix differs, so collapsing them to "problem"
/// would help nobody. A verified listing that is not published says so.
String _googleWord(Map<String, dynamic> l) {
  final state = (l['verification_state'] as String?)?.trim() ?? '';
  if (state.isNotEmpty && state.toUpperCase() != 'VERIFIED') {
    return _humanise(state);
  }
  if (l['is_published'] == false) return 'Not published';
  return 'Not showing';
}

/// SUSPENDED → Suspended, VERIFICATION_REQUESTED → Verification requested.
String _humanise(String word) {
  final spaced = word.replaceAll('_', ' ').toLowerCase();
  return spaced.isEmpty
      ? spaced
      : spaced[0].toUpperCase() + spaced.substring(1);
}

String _checked(Map<String, dynamic> l) {
  final at = DateTime.tryParse(l['synced_at'] as String? ?? '');
  return at == null ? 'Never' : _capitalise(relativeAge(at));
}

String _capitalise(String s) =>
    s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
