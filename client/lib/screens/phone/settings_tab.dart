import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import 'call_flow_pane.dart';
import 'extensions_pane.dart';
import 'flows_pane.dart';
import 'general_pane.dart';
import 'hours_pane.dart';
import 'numbers_pane.dart';
import 'porting_pane.dart';
import 'ring_groups_pane.dart';
import 'tags_pane.dart';
import 'trust_pane.dart';
import 'usage_pane.dart';
import 'voicemail_pane.dart';
import 'service_request_pane.dart';

/// Phone › Settings — layout 6: a rail of sections, and the form beside it.
///
/// Connection is the pane that works this task. The others say they are not
/// built yet rather than inventing a list. Below 600px the rail and the form
/// are one pane at a time, with Back, the same split Fleet uses.
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
    // Getting calls to arrive at all.
    (
      id: 'general',
      label: 'General',
      icon: Icons.tune_outlined,
      group: 'Connect',
    ),
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
      id: 'extensions',
      label: 'Extensions',
      icon: Icons.pin_outlined,
      group: 'Answering',
    ),
    (
      id: 'voicemail',
      label: 'Voicemail',
      icon: Icons.voicemail_outlined,
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
  ];

  /// Connection, not Call flow.
  ///
  /// Call flow leads the rail because it is the screen that says whether any
  /// of the others are doing anything — but it has nothing to say until a
  /// phone account exists, and landing a brand new organization on "no lines
  /// yet" would be answering a question they have not asked. Connection is
  /// where somebody with nothing set up actually needs to start.
  int _section = 2;

  bool _paneOpen = false;

  bool _loading = true;
  String? _error;
  List<_Connection> _connections = const [];

  bool _saving = false;
  String? _noticeScope;
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

    final response = await widget.api.get('/phone/connections');
    if (!mounted) return;

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
      _noticeScope = '${place.type}:${place.id}';
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
    return switch (_sections[_section].id) {
      // General leads and Call flow follows it. They answer the same question
      // — one as the form you fill in, one as the report of what that did —
      // and the form comes first because that is the order somebody works in.
      'general' => GeneralPane(api: widget.api, session: widget.session),
      'call-flow' => CallFlowPane(api: widget.api),
      'connection' => _connectionBody(),
      'numbers' => NumbersPane(api: widget.api, session: widget.session),
      'porting' => PortingPane(api: widget.api, session: widget.session),
      'flows' => FlowsPane(api: widget.api, session: widget.session),
      'hours' => HoursPane(api: widget.api, session: widget.session),
      'ring-groups' => RingGroupsPane(api: widget.api, session: widget.session),
      'extensions' => ExtensionsPane(api: widget.api, session: widget.session),
      'voicemail' => VoicemailPane(api: widget.api, session: widget.session),
      'trust' => TrustPane(api: widget.api, session: widget.session),
      'tags' => TagsPane(api: widget.api, session: widget.session),
      'cost' => UsagePane(
        api: widget.api,
        session: widget.session,
        scopeType: _places.first.type,
        scopeId: _places.first.id,
      ),
      _ => _unbuilt('Messaging campaigns will be set here.'),
    };
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
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
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

          // Companies only. Phone service is bought and switched on per
          // company — each gets its own Twilio subaccount, because A2P brand
          // registration and caller-ID trust are per business, not per group.
          for (final place in _places.where((p) => p.type == 'company')) ...[
            _connectionCard(place),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }

  Widget _connectionCard(_Place place) {
    final theme = Theme.of(context);
    final row = _connectionFor(place);
    final noticeHere = _noticeScope == '${place.type}:${place.id}';

    return Material(
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(place.name, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              _blurb(place, row),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            if (row != null) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  Tag(
                    row.status == 'connected' ? 'Connected' : 'Not working',
                    warn: row.status != 'connected',
                  ),
                  Tag(
                    row.kind == 'byo' ? 'Your Twilio' : 'EzBizi phone account',
                  ),
                ],
              ),
              if (row.sidTail != null) ...[
                const SizedBox(height: 8),
                Text(
                  'Account SID ends …${row.sidTail}. The key is never shown back.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
              if (row.statusReason != null && row.statusReason!.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(row.statusReason!, style: theme.textTheme.bodyMedium),
              ],
            ],
            if (noticeHere && _notice != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_notice!),
            ],
            if (_canModifyPlace(place) && row == null) ...[
              const SizedBox(height: 12),

              // One control, and only once EzBizi has said yes to this company.
              // Bringing your own Twilio is not on offer: phone service is
              // something EzBizi runs on its own account, so a customer never
              // sees a credential field and never has an unapproved way in.
              if (_approved.contains(place.id))
                Refused(
                  reason: _canEdit ? null : _editRefusal,
                  child: FilledButton(
                    onPressed: _canEdit && !_saving
                        ? () => _createSubaccount(place)
                        : null,
                    child: const Text('Activate phone service'),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  String _blurb(_Place place, _Connection? row) {
    if (place.type == 'organization') {
      if (!_canModifyPlace(place)) {
        return row == null
            ? 'Organization line. Switch to the group to connect or change it. Company numbers use this unless this company has its own.'
            : 'Organization line. Switch to the group to change it. Company numbers use this unless this company has its own.';
      }
      return row == null
          ? 'No connection. Company numbers use this unless that company has its own.'
          : 'Numbers without their own company connection use this.';
    }
    return row == null
        ? 'No connection. Numbers here use the organization until this exists.'
        : 'This company has its own Twilio.';
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
