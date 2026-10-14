import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/refused.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tag.dart';
import 'blocked_numbers_pane.dart';
import 'call_flow_pane.dart';
import 'extensions_pane.dart';
import 'flows_pane.dart';
import 'hours_pane.dart';
import 'numbers_pane.dart';
import 'porting_pane.dart';
import 'ring_groups_pane.dart';
import 'tags_pane.dart';
import 'trust_pane.dart';
import 'usage_pane.dart';
import 'voicemail_pane.dart';
import 'service_request_pane.dart';
import 'settings_pane.dart';

/// Phone › Settings — layout 6: a rail of sections, and the form beside it.
///
/// Connection is the pane that works this task. The others say they are not
/// built yet rather than inventing a list. Below 600px the rail and the form
/// are one pane at a time, with Back, the same split Fleet uses.
/// The Phone settings pane for an id, or null when api and session are not
/// enough to build it.
///
/// ── Why this is a function and not a method ──────────────────────
///
/// There are two doors onto these panes now: the settings rail, and the drawer
/// the call flow chart opens when somebody clicks a box. UI/UX built the
/// second one against a switch of their own and wrote the debt at the top of
/// the file: two switches over the same ids drift, and the day a pane is added
/// here the drawer goes on not knowing about it with nothing failing.
///
/// So the list lives once, here, where the rail's own sections already live,
/// and both doors call it.
///
/// ── Null, not a guess ────────────────────────────────────────────
///
/// Three panes need more than an api and a session — Connection and Cost read
/// the tab's loaded scopes, and Messaging is not built. They stay in
/// [_PhoneSettingsTabState._sectionBody], which is the only place that HAS
/// those. A caller that gets null shows a door to Settings, which is true of
/// every pane; a caller handed the wrong pane would think it worked.
Widget? phoneSettingsPane(
  String id, {
  required ApiClient api,
  required Session session,
}) => switch (id) {
  // No `onOpen` from the shared factory: navigating means SELECTING a
  // section, and only the tab that owns the section list can do that. The tab
  // passes its own below; every other door gets a pane without the links to
  // other sections rather than links that go nowhere.
  //
  // General and the chart are in here too since UI queue 51: the company's
  // answer is the top of Call flow, and a line's chart opens from its row.
  'call-flow' => CallFlowPane(api: api, session: session),
  'numbers' => NumbersPane(api: api, session: session),
  'porting' => PortingPane(api: api, session: session),
  'flows' => FlowsPane(api: api, session: session),
  'hours' => HoursPane(api: api, session: session),
  'ring-groups' => RingGroupsPane(api: api, session: session),
  'voicemail' => VoicemailPane(api: api, session: session),
  'blocked' => BlockedNumbersPane(api: api, session: session),
  'trust' => TrustPane(api: api, session: session),
  'tags' => TagsPane(api: api, session: session),
  'extensions' => ExtensionsPane(api: api, session: session),
  _ => null,
};

/// What the rail calls that pane, or null for an id it does not list.
///
/// The twin of [phoneSettingsPane], and it exists for the same reason. The
/// drawer the call flow chart opens shows a trail — "Settings › Phone › Ring
/// groups" — so somebody can go and find the section again. It kept its own
/// map of those words, which drifted the moment a section was added: four ids
/// on the rail were missing from it, and four more were spelled differently
/// from the words actually on screen.
///
/// So it reads the rail's own list. A label here and a label there is the same
/// duplication as a switch here and a switch there, one level down, and it
/// fails the same way: quietly, in a breadcrumb sending somebody to a section
/// that is not called that.
///
/// Null rather than the id. An internal id is not a place a person can go
/// looking, so the trail stops instead — see `phonePaneTrail`.
String? phoneSettingsPaneLabel(String id) {
  for (final section in _PhoneSettingsTabState._sections) {
    if (section.id == id) return section.label;
  }

  return null;
}

class PhoneSettingsTab extends StatefulWidget {
  const PhoneSettingsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  static const double splitAbove = 600;

  @override
  State<PhoneSettingsTab> createState() => _PhoneSettingsTabState();
}

class _PhoneSettingsTabState extends State<PhoneSettingsTab> {
  /// The rail: order, grouping and identity, declared once.
  ///
  /// ── Why `id` exists ─────────────────────────────────────────────
  ///
  /// The body used to switch on the INDEX. That was fine while the rail was a
  /// flat list nobody reordered, and it became a trap the moment grouping
  /// arrived: moving Trust up one place repoints every section below it, and
  /// nothing fails — you land on Tags and get Cost, which reads as a rendering
  /// bug rather than an off-by-one. So the index is a position now, and the id
  /// is what the body is keyed on.
  ///
  /// ── Why `group` is here and not in the rail widget ──────────────
  ///
  /// Because the order and the grouping have to agree, and two lists cannot be
  /// made to. A section listed under Answering and rendered between two
  /// Connect entries would be a heading that lies, which is worse than no
  /// headings at all — the reason for grouping thirteen items is to let
  /// somebody stop reading once they know which group they want.
  static const _sections =
      <({String id, String label, IconData icon, String group})>[
    // Getting calls to arrive at all. Call flow is also where the company's
    // answer is set: General folded into it (UI queue 51, Jose 2026-10-05).
    (
      id: 'call-flow',
      label: 'Call flow',
      icon: Icons.account_tree_outlined,
      group: 'Connect',
    ),
    (
      id: 'connection',
      label: 'Connection',
      icon: Icons.link_outlined,
      group: 'Connect',
    ),
    (
      id: 'numbers',
      label: 'Numbers',
      icon: Icons.dialpad_outlined,
      group: 'Connect',
    ),
    (
      id: 'porting',
      label: 'Porting',
      icon: Icons.swap_horiz_outlined,
      group: 'Connect',
    ),

    // What happens once one has.
    (
      id: 'flows',
      label: 'IVR menus',
      icon: Icons.touch_app_outlined,
      group: 'Answering',
    ),
    (
      id: 'hours',
      label: 'Hours',
      icon: Icons.schedule_outlined,
      group: 'Answering',
    ),
    (
      id: 'ring-groups',
      label: 'Ring groups',
      icon: Icons.groups_outlined,
      group: 'Answering',
    ),
    (
      id: 'voicemail',
      label: 'Voicemail',
      icon: Icons.voicemail_outlined,
      group: 'Answering',
    ),

    // INSIDE the Answering block rather than appended, and the rail is why:
    // it opens a heading whenever the group differs from the previous entry,
    // so a section added at the end carrying an earlier group opens a SECOND
    // "ANSWERING" heading at the bottom of the list.
    //
    // Safe to insert here, unlike the chart: `_section` is in-memory state on
    // this State, nothing stores it, no test pins an index, and the default of
    // 2 names `connection` either way because this sits after it.
    (
      id: 'extensions',
      label: 'Extensions',
      icon: Icons.tag_outlined,
      group: 'Answering',
    ),

    // Under Answering because it is the first decision made about an
    // incoming call: whether to answer it at all.
    (
      id: 'blocked',
      label: 'Blocked callers',
      icon: Icons.block_outlined,
      group: 'Answering',
    ),

    // How the business looks to a carrier, which is its own subject and the
    // only member of its group. A group of one is still worth drawing: it is
    // the answer to "why are my calls marked spam", and burying it among the
    // housekeeping is how it went unfound.
    (
      id: 'trust',
      label: 'Trust & caller identity',
      icon: Icons.verified_outlined,
      group: 'Identity',
    ),

    // Housekeeping, and the things you read rather than set.
    (
      id: 'tags',
      label: 'Tags',
      icon: Icons.sell_outlined,
      group: 'Running it',
    ),
    (
      id: 'cost',
      label: 'Cost',
      icon: Icons.receipt_long_outlined,
      group: 'Running it',
    ),
    (
      id: 'messaging',
      label: 'Messaging',
      icon: Icons.sms_outlined,
      group: 'Running it',
    ),
    // No "Call flow chart" entry any more: it hung under a second CONNECT
    // heading at the bottom of the rail, and a line's chart now opens from its
    // row on Call flow (UI queue 51).
  ];

  /// Connection, not Call flow.
  ///
  /// Call flow leads the rail because it is the screen that says whether any
  /// of the others are doing anything — but it has nothing to say until a
  /// phone account exists, and landing a brand new organization on "no lines
  /// yet" would be answering a question they have not asked. Connection is
  /// where somebody with nothing set up actually needs to start.
  ///
  /// Found by its id rather than written as a position, which was 2 and named
  /// Connection only while General sat above Call flow.
  int _section = _sections.indexWhere((section) => section.id == 'connection');

  bool _paneOpen = false;

  bool _loading = true;
  String? _error;
  List<_Connection> _connections = const [];

  /// Lines per phone account, counted from `/phone/numbers` by
  /// `connection_id`. Null when that list could not be read - the column
  /// then says nothing rather than 0.
  Map<String, int>? _lineCounts;

  /// The Connection tile picked (tiles over a table, Mia 2026-10-02): `ok`,
  /// `bad` or `none`; null is every row.
  String? _tile;

  bool _saving = false;
  String? _notice;

  /// Companies EzBizi has switched phone service on for (§4 gate 2). Reported
  /// by the request pane above; nothing here activates without it.
  List<String> _approved = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    super.dispose();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal =>
      _me.refusal('phone.edit', 'connect a phone account');

  List<_Place> get _places {
    final me = _me;
    // Organization card is always visible (numbers walk up to it). Connect
    // and Replace only when looking at the group — a company cannot modify
    // its parent. companiesInView, not companies, so A-1 Chimney does not
    // offer Everdoors.
    return [
      _Place(
        type: 'organization',
        id: me.organization.id,
        name: me.organization.name,
      ),
      for (final company in me.companiesInView)
        _Place(type: 'company', id: company.id, name: company.name),
    ];
  }

  /// Organization Twilio is only writable in an organization view.
  bool _canModifyPlace(_Place place) {
    if (place.type == 'organization') {
      return _me.scope.type == 'organization';
    }
    return true;
  }

  Future<void> _load() async {
    setState(() {
      _loading = _connections.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/numbers'),
    ]);
    if (!mounted) return;

    final response = results[0];
    final numbers = results[1];

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _connections = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Connection.fromJson(row),
      ];

      // Only for the Lines column, so a refusal here does not blank the pane.
      final lines = numbers.ok ? numbers.data : null;
      _lineCounts = lines is List
          ? {
              for (final row in lines.whereType<Map>())
                if (row['connection_id'] is String)
                  row['connection_id'] as String: 0,
            }
          : null;
      if (lines is List) {
        for (final row in lines.whereType<Map>()) {
          final id = row['connection_id'];
          if (id is String) _lineCounts![id] = _lineCounts![id]! + 1;
        }
      }
    });
  }



  _Connection? _connectionFor(_Place place) {
    for (final row in _connections) {
      if (row.scopeType == place.type && row.scopeId == place.id) return row;
    }
    return null;
  }

  Future<void> _createSubaccount(_Place place) async {
    if (!_canEdit || _saving) return;

    setState(() {
      _saving = true;
      _notice = null;
    });

    final response = await widget.api.post('/phone/connections', {
      'scope_type': place.type,
      'scope_id': place.id,
      'kind': 'subaccount',
    });

    if (!mounted) return;

    setState(() => _saving = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    await _load();
  }


  void _selectSection(int index) => setState(() {
    _section = index;
    _paneOpen = true;
  });

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= PhoneSettingsTab.splitAbove;
        final paneOnly = !wide && _paneOpen;

        if (paneOnly) return _pane(showBack: true);
        if (wide) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 200, child: _rail()),
              VerticalDivider(
                width: 1,
                thickness: 1,
                color: EzBiziTheme.edge(context),
              ),
              Expanded(child: _pane(showBack: false)),
            ],
          );
        }
        return _rail();
      },
    );
  }

  Widget _rail() {
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('tour-phone-settings-rail'),
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 8),
      children: [
        for (var i = 0; i < _sections.length; i++) ...[
          // A heading wherever the group changes, which is derived from the
          // order rather than listed separately — the two cannot disagree.
          if (i == 0 || _sections[i].group != _sections[i - 1].group)
            Padding(
              padding: EdgeInsets.fromLTRB(12, i == 0 ? 4 : 16, 12, 6),
              child: Text(
                _sections[i].group.toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  letterSpacing: 0.8,
                ),
              ),
            ),

          Padding(
            padding: const EdgeInsets.only(bottom: 2),
            child: Material(
              color: i == _section
                  ? theme.colorScheme.primaryContainer
                  : theme.colorScheme.surface,
              borderRadius: BorderRadius.circular(8),
              child: InkWell(
                borderRadius: BorderRadius.circular(8),
                onTap: () => _selectSection(i),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 44),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 10,
                    ),
                    child: Row(
                      children: [
                        Icon(
                          _sections[i].icon,
                          size: 18,
                          color: i == _section
                              ? theme.colorScheme.primary
                              : theme.colorScheme.onSurfaceVariant,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _sections[i].label,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: i == _section
                                  ? FontWeight.w600
                                  : FontWeight.w400,
                              color: i == _section
                                  ? theme.colorScheme.primary
                                  : null,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }

  Widget _pane({required bool showBack}) {
    return Column(
      key: const ValueKey('tour-phone-settings-pane'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showBack)
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 12, 20, 8),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: () => setState(() => _paneOpen = false),
                ),
                Expanded(
                  child: Text(
                    _sections[_section].label,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
              ],
            ),
          ),
        Expanded(child: _sectionBody()),
      ],
    );
  }

  /// A switch, and NOT an IndexedStack — which is load-bearing, not incidental.
  ///
  /// Building one pane at a time means leaving a section disposes it and coming
  /// back constructs it again, so every pane's `initState` reloads and shows
  /// what the server holds NOW. Several panes edit the same line: General and
  /// Numbers share five controls, and Ring groups writes `phone_numbers` too.
  ///
  /// An IndexedStack keeps every pane alive and would look like a straight
  /// performance win. It would also mean a pane you visited an hour ago is
  /// still showing what it fetched then — change the fallback number in
  /// General, open Numbers, save, and General's stale copy silently reverts it.
  ///
  /// PhoneScreenTest has a test that a pane refetches when it is opened again,
  /// so this is enforced rather than remembered.
  Widget _sectionBody() {
    // Keyed on the section's id, not its position. The rail is grouped and
    // therefore reorderable, and an index would quietly repoint every section
    // below a move.
    final id = _sections[_section].id;

    // The one pane the tab builds itself, because it needs a way back into
    // the tab. Jose, 2026-09-23: clicking a step on Call flow should "direct
    // me to the screen where that can be changed".
    if (id == 'call-flow') {
      return CallFlowPane(
        api: widget.api,
        session: widget.session,
        onOpen: _openSection,
      );
    }

    return phoneSettingsPane(id, api: widget.api, session: widget.session) ??
        // And the three the shared list cannot build, because they need
        // something only this tab has: the loaded scopes, and a section label.
        switch (id) {
          'connection' => _connectionBody(),
          'cost' => UsagePane(
            api: widget.api,
            session: widget.session,
            scopeType: _places.first.type,
            scopeId: _places.first.id,
          ),
          _ => _unbuilt('Messaging campaigns will be set here.'),
        };
  }

  /// Open the section with this id, if this build has one.
  ///
  /// Keyed on the id rather than an index for the same reason `_sectionBody`
  /// is: the rail is grouped and therefore reorderable, and an index would
  /// quietly point at a different section the day somebody moves one.
  ///
  /// Silently does nothing for an id the rail does not list. That is the right
  /// failure for a step whose pane has not been built yet — a step that took
  /// somebody to the wrong section would be worse than one that stays put.
  void _openSection(String id) {
    for (var i = 0; i < _sections.length; i++) {
      if (_sections[i].id != id) continue;

      _selectSection(i);

      return;
    }
  }

  Widget _unbuilt(String sentence) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(_sections[_section].label, style: theme.textTheme.titleMedium),
            const Tag('Not built yet', warn: true),
          ],
        ),
        const SizedBox(height: 8),
        Text(sentence, style: theme.textTheme.bodyMedium),
      ],
    );
  }

  Widget _connectionBody() {
    if (_loading) return const Center(child: CircularProgressIndicator());

    final companies = _places.where((p) => p.type == 'company').toList();
    final organization = _places.firstWhere((p) => p.type == 'organization');

    // THE ROOT IS A ROW ONLY IF IT EXISTS. Phone service is sold per company
    // (A2P registration and caller-ID trust are per business), so there is
    // deliberately no organization account to activate, and a test guards
    // that. But ConnectionResolver still walks company -> organization, so one
    // that exists is drawn: drawing it when none does would invent a parent
    // and point every company's sentence at nothing.
    final places = [
      if (_connectionFor(organization) != null) organization,
      ...companies,
    ];

    return SettingsPane(
      title: 'Connection',
      subtitle: "Which Twilio account each company's calls run on.",
      error: _error,

      // Its error is a failed LOAD, so a reload discards nothing.
      onRetry: _load,

      // No footer: nothing on this pane is saved. Connecting is one button on
      // one row, and a Save here would claim the pane as a whole is a form.
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          // Gate 2 first: until EzBizi has said yes to a company by name there
          // is nothing here to connect, and the only useful control is asking.
          PhoneServiceRequestPane(
            api: widget.api,
            session: widget.session,
            onApproved: (ids) {
              if (!mounted) return;
              setState(() => _approved = ids);
            },
          ),

          const SizedBox(height: 12),

          // ── One rule, drawn once ────────────────────────────────
          //
          // A company's numbers use the organization's connection unless that
          // company has its own. That was six sentences — each correct, each
          // chosen by which state you happened to be standing in, so the rule
          // read differently depending on where you were.
          //
          // Six because two different facts were braided into one sentence:
          // WHERE CALLS GO, which never changes, and WHETHER YOU MAY CHANGE
          // IT HERE, which depends on your view. Splitting them is what
          // collapses the six - the sentence under each account carries the
          // routing rule and the permission gets its own line only when it
          // bites. Tiles over a table since Mia, 2026-10-02.
          if (_notice != null) ...[
            ErrorNote(_notice!),
            const SizedBox(height: 12),
          ],
          _connectionTiles(places),
          const SizedBox(height: 12),
          _connectionTable(places),
        ],
      ),
    );
  }

  /// Which tile a row counts under: its own account working, its own account
  /// not working, or no account of its own (inheriting, or reaching nobody).
  String _stage(_Place place) {
    final row = _connectionFor(place);
    if (row == null) return 'none';

    return row.status == 'connected' ? 'ok' : 'bad';
  }

  Widget _connectionTiles(List<_Place> places) {
    int count(String key) => places.where((p) => _stage(p) == key).length;

    return FilterTiles(
      key: const ValueKey('tour-phone-connection-tiles'),
      prefix: 'connection',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        // "All" and not the mockup's "Accounts": a company with no account of
        // its own is a row here too, and calling it an account would be wrong.
        FilterTile(
          key: null,
          label: 'All',
          count: places.length,
          note: 'places listed',
        ),
        FilterTile(
          key: 'ok',
          label: 'Connected',
          count: count('ok'),
          note: 'working',
          tone: Tone.ok,
        ),
        FilterTile(
          key: 'bad',
          label: 'Not working',
          count: count('bad'),
          note: 'needs attention',
          tone: Tone.bad,
        ),
        FilterTile(
          key: 'none',
          label: 'No account',
          count: count('none'),
          note: 'none of its own',
        ),
      ],
    );
  }

  Widget _connectionTable(List<_Place> places) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final shown = _tile == null
        ? places
        : places.where((p) => _stage(p) == _tile).toList();

    if (shown.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Text('No accounts like that.', textAlign: TextAlign.center),
      );
    }

    return ListTable(
      key: const ValueKey('tour-phone-connection-table'),
      columns: const [
        ListColumn('Account', flex: 5),
        ListColumn('Kind', flex: 4),
        ListColumn('Status', flex: 3),
        ListColumn('Lines', flex: 1),
        ListColumn('', flex: 3, end: true),
      ],
      rowKey: (i) => ValueKey('connection-${shown[i].type}-${shown[i].id}'),
      rows: [
        for (final place in shown) _connectionRow(place, theme, small),
      ],
    );
  }

  List<Widget> _connectionRow(_Place place, ThemeData theme, TextStyle? small) {
    final row = _connectionFor(place);
    final lines = row == null ? null : _lineCounts?[row.id] ?? 0;

    return [
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            place.name,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          // Where this row's calls go. One sentence, same shape on every
          // row, and true regardless of who is looking at it.
          Text(_usesLine(place, row), style: small),
          // The permission, said separately and only where it applies.
          if (!_canModifyPlace(place))
            Text(
              'Switch to the whole organization to change this.',
              style: small,
            ),
        ],
      ),
      row == null
          ? Text(
              place.type == 'company' && _organizationConnected
                  ? "The organization's"
                  : '—',
              style: small,
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  row.kind == 'byo' ? 'Your Twilio' : 'EzBizi phone account',
                ),
                if (row.sidTail != null)
                  Text(
                    'Account SID ends …${row.sidTail}. The key is never shown '
                    'back.',
                    style: small,
                  ),
              ],
            ),
      // A company with no account of its own is not broken and must not read
      // as an empty slot - it is inheriting, which is the ordinary case.
      row == null
          ? (place.type != 'company'
                ? const StatusPill('No account', tone: Tone.caution)
                : _organizationConnected
                ? const StatusPill('Uses the organization')
                : const StatusPill('No account', tone: Tone.caution))
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                StatusPill(
                  row.status == 'connected' ? 'Connected' : 'Not working',
                  tone: row.status == 'connected' ? Tone.ok : Tone.bad,
                ),
                if (row.statusReason != null && row.statusReason!.isNotEmpty)
                  Text(row.statusReason!, style: small),
              ],
            ),
      Text(lines == null ? '—' : '$lines'),
      _connectionAction(place, row),
    ];
  }

  Widget _connectionAction(_Place place, _Connection? row) {
    // Open goes to Numbers, where this account's lines are set up.
    if (row != null) {
      return TextButton(
        onPressed: () => _openSection('numbers'),
        child: const Text('Open'),
      );
    }

    // One control, and only once EzBizi has said yes to this company.
    // Bringing your own Twilio is not on offer: phone service is something
    // EzBizi runs on its own account, so a customer never sees a credential
    // field and never has an unapproved way in.
    if (_canModifyPlace(place) && _approved.contains(place.id)) {
      return Refused(
        reason: _canEdit ? null : _editRefusal,
        child: FilledButton(
          onPressed: _canEdit && !_saving
              ? () => _createSubaccount(place)
              : null,
          child: const Text('Activate phone service'),
        ),
      );
    }

    return const SizedBox.shrink();
  }

  /// Where this row's calls go — the ONE rule, stated the same way everywhere.
  ///
  /// This was `_blurb()`: six returns across three branches, each correct and
  /// each different, because it also tried to say whether the reader was
  /// allowed to change what they were looking at. Two facts in one sentence
  /// needs a sentence per combination, and the rule ends up sounding different
  /// depending on where somebody happens to be standing.
  ///
  /// Permission is now its own line on the card. This says only where calls go.
  /// Whether the organization has an account for companies to inherit.
  bool get _organizationConnected =>
      _connectionFor(_places.firstWhere((p) => p.type == 'organization')) !=
      null;

  String _usesLine(_Place place, _Connection? row) {
    if (place.type == 'organization') {
      return 'Numbers held at the organization use this.';
    }

    if (row != null) return "This company's numbers use its own Twilio.";

    // Only claim inheritance when there is something to inherit FROM.
    // "uses the organization's connection above" with no organization
    // connection is a sentence pointing at nothing, and it would read as
    // working to somebody whose calls reach no account at all.
    return !_organizationConnected
        ? 'No phone account yet, so calls to this company reach nobody.'
        : "This company's numbers use the organization's connection above.";
  }
}

class _Place {
  const _Place({required this.type, required this.id, required this.name});

  final String type;
  final String id;
  final String name;
}

class _Connection {
  const _Connection({
    required this.id,
    required this.scopeType,
    required this.scopeId,
    required this.kind,
    required this.status,
    this.accountSid,
    this.statusReason,
  });

  final String id;
  final String scopeType;
  final String scopeId;
  final String kind;
  final String status;
  final String? accountSid;
  final String? statusReason;

  String? get sidTail {
    final sid = accountSid;
    if (sid == null || sid.isEmpty) return null;
    return sid.length <= 4 ? sid : sid.substring(sid.length - 4);
  }

  factory _Connection.fromJson(Map<String, dynamic> json) => _Connection(
    id: json['id'] as String? ?? '',
    scopeType: json['scope_type'] as String? ?? '',
    scopeId: json['scope_id'] as String? ?? '',
    kind: json['kind'] as String? ?? '',
    status: json['status'] as String? ?? '',
    accountSid: json['account_sid'] as String?,
    statusReason: json['status_reason'] as String?,
  );
}
