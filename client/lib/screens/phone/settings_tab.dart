import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import 'ring_groups_pane.dart';
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
  static const _sections = <(String, IconData)>[
    ('Connection', Icons.link_outlined),
    ('Numbers', Icons.dialpad_outlined),
    ('Call flows', Icons.account_tree_outlined),
    ('Hours', Icons.schedule_outlined),
    ('Ring groups', Icons.groups_outlined),
    ('Trust', Icons.verified_outlined),
    ('Messaging', Icons.sms_outlined),
  ];

  int _section = 0;
  bool _paneOpen = false;

  bool _loading = true;
  String? _error;
  List<_Connection> _connections = const [];

  String? _formScopeType;
  String? _formScopeId;
  final _accountSid = TextEditingController();
  final _apiKeySid = TextEditingController();
  final _apiSecret = TextEditingController();
  bool _saving = false;
  String? _formError;
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
    _accountSid.dispose();
    _apiKeySid.dispose();
    _apiSecret.dispose();
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

  void _openForm(_Place place) {
    setState(() {
      _formScopeType = place.type;
      _formScopeId = place.id;
      _formError = null;
      _notice = null;
      _noticeScope = null;
      _accountSid.clear();
      _apiKeySid.clear();
      _apiSecret.clear();
    });
  }

  void _closeForm() {
    setState(() {
      _formScopeType = null;
      _formScopeId = null;
      _formError = null;
      _accountSid.clear();
      _apiKeySid.clear();
      _apiSecret.clear();
    });
  }

  bool _formOpenFor(_Place place) =>
      _formScopeType == place.type && _formScopeId == place.id;

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
      _formError = null;
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

    _closeForm();
    await _load();
  }

  Future<void> _saveByo(_Place place) async {
    if (!_canEdit || _saving) return;

    setState(() {
      _saving = true;
      _formError = null;
      _notice = null;
    });

    // POST only. Do not DELETE first — that would drop numbers, history and
    // routing. Store returns 409 when a row already exists; show that sentence.
    final response = await widget.api.post('/phone/connections', {
      'scope_type': place.type,
      'scope_id': place.id,
      'kind': 'byo',
      'account_sid': _accountSid.text.trim(),
      'api_key_sid': _apiKeySid.text.trim(),
      'api_secret': _apiSecret.text,
    });

    if (!mounted) return;

    setState(() => _saving = false);

    if (!response.ok) {
      setState(() => _formError = response.message);
      return;
    }

    _closeForm();
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
        for (var i = 0; i < _sections.length; i++)
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
                          _sections[i].$2,
                          size: 18,
                          color: i == _section
                              ? theme.colorScheme.primary
                              : theme.colorScheme.onSurfaceVariant,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _sections[i].$1,
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
                    _sections[_section].$1,
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

  Widget _sectionBody() {
    return switch (_section) {
      0 => _connectionBody(),
      1 => _unbuilt(
        'Buy and grant numbers here. None are listed on this screen yet.',
      ),
      2 => _unbuilt('Call flows will be written here.'),
      3 => _unbuilt('Opening hours will be set here.'),
      4 => RingGroupsPane(api: widget.api, session: widget.session),
      5 => _unbuilt('Caller ID trust will be set here.'),
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
            Text(_sections[_section].$1, style: theme.textTheme.titleMedium),
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
    final open = _formOpenFor(place);
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
            if (open && _canModifyPlace(place)) ...[
              const SizedBox(height: 12),
              TextField(
                controller: _accountSid,
                decoration: const InputDecoration(labelText: 'Account SID'),
                enabled: !_saving,
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _apiKeySid,
                decoration: const InputDecoration(labelText: 'API key SID'),
                enabled: !_saving,
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _apiSecret,
                decoration: const InputDecoration(labelText: 'API secret'),
                obscureText: true,
                enabled: !_saving,
                onChanged: (_) => setState(() {}),
              ),
              if (_formError != null) ...[
                const SizedBox(height: 12),
                ErrorNote(_formError!),
              ],
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  Refused(
                    reason: _canEdit ? null : _editRefusal,
                    child: FilledButton(
                      onPressed: _canEdit && !_saving && _byoReady
                          ? () => _saveByo(place)
                          : null,
                      child: const Text('Save'),
                    ),
                  ),
                  TextButton(
                    onPressed: _saving ? null : _closeForm,
                    child: const Text('Cancel'),
                  ),
                ],
              ),
            ] else if (_canModifyPlace(place)) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (row == null) ...[
                    Refused(
                      reason: _canEdit ? null : _editRefusal,
                      child: FilledButton(
                        onPressed: _canEdit && !_saving
                            ? () => _openForm(place)
                            : null,
                        child: const Text('Connect your Twilio'),
                      ),
                    ),
                    if (_approved.contains(place.id))
                      Refused(
                        reason: _canEdit ? null : _editRefusal,
                        child: OutlinedButton(
                          onPressed: _canEdit && !_saving
                              ? () => _createSubaccount(place)
                              : null,
                          child: const Text('Activate phone service'),
                        ),
                      ),
                  ] else if (row.kind == 'byo')
                    Refused(
                      reason: _canEdit ? null : _editRefusal,
                      child: OutlinedButton(
                        onPressed: _canEdit && !_saving
                            ? () => _openForm(place)
                            : null,
                        child: const Text('Replace the key'),
                      ),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  bool get _byoReady =>
      _accountSid.text.trim().isNotEmpty &&
      _apiKeySid.text.trim().isNotEmpty &&
      _apiSecret.text.isNotEmpty;

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
