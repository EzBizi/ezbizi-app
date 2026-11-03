import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// Scheduling › Settings › Technicians: EzBizi's own roster (Jose, 2026-10-27:
/// "EzBizi is the master"). Who can be put on a job, whether they work in the
/// field, the service areas they cover and the EzBizi login they are. It
/// replaces Settings › Schedule, which linked Workiz technicians to users; a
/// roster row seeded from Workiz carries that link as its `user` and says
/// "In Workiz". See SchedulingRosterController.
class TechnicianRoster extends StatefulWidget {
  const TechnicianRoster({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<TechnicianRoster> createState() => _TechnicianRosterState();
}

class _TechnicianRosterState extends State<TechnicianRoster> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  String? _branch;
  bool _showInactive = false;
  List<Map<String, dynamic>> _rows = const [];
  List<Map<String, dynamic>> _users = const [];
  List<Map<String, dynamic>> _areas = const [];

  /// On a phone the editor takes the page: the row open (or `{}` for a new
  /// one), null for the list.
  Map<String, dynamic>? _editing;

  bool get _canEdit => widget.session.me?.can('scheduling.edit') ?? false;

  List<BranchOption> get _branches => widget.session.me == null
      ? const []
      : branchOptionsInView(widget.session.me!, activeOnly: false);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant TechnicianRoster old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/scheduling/roster', {
        'franchise_id': ?_branch,
        if (_showInactive) 'all': '1',
      }),
      if (_canEdit && _areas.isEmpty) widget.api.get('/scheduling/areas'),
    ]);

    if (!mounted) return;

    final roster = results.first;
    setState(() {
      _loading = false;
      if (!roster.ok) {
        _error = roster.message;
        return;
      }
      _rows = _list(roster.body['data']);
      // The people a technician can be: the organization's active logins,
      // sent with the roster (1fc243bcf).
      _users = _list(roster.body['users']);
      if (results.length > 1 && results[1].ok) {
        _areas = _list(results[1].body['data']);
      }
    });
  }

  static List<Map<String, dynamic>> _list(Object? v) =>
      (v as List? ?? const []).cast<Map<String, dynamic>>();

  String _branchLabel(String? id) =>
      _branches.where((b) => b.id == id).firstOrNull?.label ?? '';

  List<Map<String, dynamic>> get _shown {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return _rows;

    return [
      for (final r in _rows)
        if ([
          r['name'],
          r['email'],
          r['phone'],
          _branchLabel(r['franchise_id'] as String?),
          (r['user'] as Map?)?['name'],
          for (final a in _list(r['areas'])) a['name'],
        ].whereType<String>().join(' ').toLowerCase().contains(q))
          r,
    ];
  }

  bool get _phone => MediaQuery.sizeOf(context).width < 600;

  Future<void> _open(Map<String, dynamic>? row) async {
    if (!_canEdit) return;
    if (_phone) {
      setState(() => _editing = row ?? {});
      return;
    }
    final saved = await showSidePanel<bool>(
      context,
      builder: (context) => _editor(row, null),
    );
    if (saved == true) _load();
  }

  Widget _editor(Map<String, dynamic>? row, VoidCallback? onBack) =>
      RosterEditor(
        api: widget.api,
        row: row,
        branches: _branches,
        users: _users,
        areas: _areas,
        defaultBranch: _branch,
        onBack: onBack,
        onSaved: () {
          if (onBack != null) {
            onBack();
            _load();
          } else {
            Navigator.of(context).pop(true);
          }
        },
      );

  @override
  Widget build(BuildContext context) {
    final editing = _editing;
    if (editing != null && _phone) {
      return _editor(
        editing.isEmpty ? null : editing,
        () => setState(() => _editing = null),
      );
    }

    final theme = Theme.of(context);
    final shown = _shown;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        key: const ValueKey('tour-scheduling-technicians'),
        padding: EdgeInsets.all(_phone ? 16 : 24),
        children: [
          Row(
            children: [
              Expanded(
                child: Text('Technicians', style: theme.textTheme.titleMedium),
              ),
              if (_canEdit)
                FilledButton.icon(
                  key: const ValueKey('roster-add'),
                  onPressed: () => _open(null),
                  icon: const Icon(Icons.add),
                  label: const Text('Add technician'),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            key: const ValueKey('tour-scheduling-technicians-find'),
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 280,
                child: ListSearch(
                  key: const ValueKey('roster-search'),
                  controller: _search,
                  hint: 'Search technicians',
                  onChanged: (_) => setState(() {}),
                ),
              ),
              SizedBox(
                width: 280,
                child: SearchPickerField<String>(
                  key: const ValueKey('roster-branch'),
                  value: _branch,
                  hint: 'All branches',
                  allowClear: true,
                  isDense: true,
                  options: [
                    for (final b in _branches)
                      SearchOption(value: b.id, label: b.label),
                  ],
                  onChanged: (id) {
                    setState(() => _branch = id);
                    _load();
                  },
                ),
              ),
              FilterChip(
                key: const ValueKey('roster-inactive'),
                label: const Text('Show inactive'),
                selected: _showInactive,
                onSelected: (v) {
                  setState(() => _showInactive = v);
                  _load();
                },
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                _rows.isEmpty
                    ? 'No technicians yet.${_canEdit ? ' Add one with + Add technician.' : ''}'
                    : 'Nobody matches.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            )
          else
            LayoutBuilder(
              builder: (context, box) => box.maxWidth < ListTable.stackBelow
                  ? _cards(theme, shown)
                  : _table(theme, shown),
            ),
        ],
      ),
    );
  }

  Widget _chips(Map<String, dynamic> r) => Wrap(
    runSpacing: 4,
    children: [
      if (r['is_field_tech'] == true) const Tag('Field tech'),
      r['is_active'] == true
          ? const Tag('Active', good: true)
          : const Tag('Inactive'),
      if (r['in_workiz'] == true) const Tag('In Workiz'),
    ],
  );

  Widget _areaChips(Map<String, dynamic> r) => Wrap(
    runSpacing: 4,
    children: [for (final a in _list(r['areas'])) Tag('${a['name']}')],
  );

  String _text(Object? v) => v is String && v.isNotEmpty ? v : '—';

  Widget _table(ThemeData theme, List<Map<String, dynamic>> rows) {
    return ListTable(
      rowKey: (i) => ValueKey('roster:${rows[i]['id']}'),
      onTap: _canEdit ? (i) => _open(rows[i]) : null,
      columns: const [
        ListColumn('Name', flex: 3),
        ListColumn('Branch', flex: 3),
        ListColumn('Phone', flex: 2),
        ListColumn('Email', flex: 3),
        ListColumn('Linked user', flex: 2),
        ListColumn('Areas', flex: 3),
        ListColumn('Status', flex: 3),
      ],
      rows: [
        for (final r in rows)
          [
            Text('${r['name']}', style: theme.textTheme.titleSmall),
            Text(_branchLabel(r['franchise_id'] as String?)),
            Text(_text(r['phone'])),
            Text(_text(r['email'])),
            Text(_text((r['user'] as Map?)?['name'])),
            _areaChips(r),
            _chips(r),
          ],
      ],
    );
  }

  Widget _cards(ThemeData theme, List<Map<String, dynamic>> rows) {
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Column(
      children: [
        for (final r in rows)
          Card(
            key: ValueKey('roster:${r['id']}'),
            margin: const EdgeInsets.only(bottom: 8),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: _canEdit ? () => _open(r) : null,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${r['name']}', style: theme.textTheme.titleSmall),
                    Text(
                      _branchLabel(r['franchise_id'] as String?),
                      style: muted,
                    ),
                    Text(
                      [r['phone'], r['email']]
                          .whereType<String>()
                          .where((s) => s.isNotEmpty)
                          .join(' · '),
                      style: muted,
                    ),
                    if (r['user'] != null)
                      Text(
                        'Linked to ${(r['user'] as Map)['name']}',
                        style: muted,
                      ),
                    const SizedBox(height: 6),
                    _areaChips(r),
                    const SizedBox(height: 4),
                    _chips(r),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// One technician, new ([row] null) or existing. A side panel beside the list;
/// on a phone, with [onBack], it is the page.
class RosterEditor extends StatefulWidget {
  const RosterEditor({
    super.key,
    required this.api,
    required this.row,
    required this.branches,
    required this.users,
    required this.areas,
    required this.onSaved,
    this.defaultBranch,
    this.onBack,
  });

  final ApiClient api;
  final Map<String, dynamic>? row;
  final List<BranchOption> branches;
  final List<Map<String, dynamic>> users;
  final List<Map<String, dynamic>> areas;
  final String? defaultBranch;
  final VoidCallback onSaved;
  final VoidCallback? onBack;

  @override
  State<RosterEditor> createState() => _RosterEditorState();
}

class _RosterEditorState extends State<RosterEditor> {
  late final _name = TextEditingController(
    text: widget.row?['name'] as String?,
  );
  late final _email = TextEditingController(
    text: widget.row?['email'] as String?,
  );
  late final _phone = TextEditingController(
    text: widget.row?['phone'] as String?,
  );
  late String? _branch =
      widget.row?['franchise_id'] as String? ??
      widget.defaultBranch ??
      (widget.branches.length == 1 ? widget.branches.first.id : null);
  late String? _user = (widget.row?['user'] as Map?)?['id'] as String?;
  late bool _fieldTech = widget.row?['is_field_tech'] as bool? ?? true;
  late bool _active = widget.row?['is_active'] as bool? ?? true;
  late final Set<String> _areaIds = {
    for (final a in (widget.row?['areas'] as List? ?? const []))
      '${(a as Map)['id']}',
  };

  bool _saving = false;
  String? _error;

  bool get _isNew => widget.row == null;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    super.dispose();
  }

  String? _orNull(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _branch == null) {
      setState(() => _error = 'A technician needs a name and a branch.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });

    final fields = <String, dynamic>{
      'name': _name.text.trim(),
      'email': _orNull(_email),
      'phone': _orNull(_phone),
      'user_id': _user,
      'is_field_tech': _fieldTech,
      'area_ids': _areaIds.toList(),
    };
    final response = _isNew
        ? await widget.api.post('/scheduling/roster', {
            'franchise_id': _branch,
            for (final e in fields.entries)
              if (e.value != null) e.key: e.value,
          })
        : await widget.api.patch('/scheduling/roster/${widget.row!['id']}', {
            ...fields,
            'is_active': _active,
          });

    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });
      return;
    }
    widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final areas = [
      for (final a in widget.areas)
        if (a['franchise_id'] == _branch) a,
    ];

    return SidePanel(
      title: _isNew ? 'Add technician' : '${widget.row!['name']}',
      docked: widget.onBack != null,
      onBack: widget.onBack,
      actions: [
        FilledButton(
          key: const ValueKey('roster-save'),
          onPressed: _saving ? null : _save,
          child: Text(_isNew ? 'Add' : 'Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          TextField(
            key: const ValueKey('roster-name'),
            controller: _name,
            autofocus: _isNew,
            decoration: const InputDecoration(labelText: 'Name'),
          ),
          const SizedBox(height: 12),
          if (_isNew) ...[
            SearchPickerField<String>(
              key: const ValueKey('roster-editor-branch'),
              label: 'Branch',
              value: _branch,
              options: [
                for (final b in widget.branches)
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (id) => setState(() {
                _branch = id;
                _areaIds.clear();
              }),
            ),
            const SizedBox(height: 12),
          ],
          TextField(
            key: const ValueKey('roster-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'Email'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('roster-phone'),
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'Phone'),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: const ValueKey('roster-user'),
            label: 'EzBizi user',
            hint: 'Not linked',
            value: _user,
            allowClear: true,
            options: [
              for (final u in widget.users)
                SearchOption(
                  value: u['id'] as String,
                  label: '${u['name']}',
                  detail: u['email'] as String?,
                ),
            ],
            onChanged: (id) => setState(() => _user = id),
          ),
          const SizedBox(height: 8),
          SwitchListTile(
            key: const ValueKey('roster-field-tech'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Field tech'),
            subtitle: const Text('Goes out to jobs'),
            value: _fieldTech,
            onChanged: (v) => setState(() => _fieldTech = v),
          ),
          const SizedBox(height: 8),
          Text('Service areas', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (areas.isEmpty)
            Text(
              _branch == null
                  ? 'Pick a branch to see its areas.'
                  : 'This branch has no service areas yet.',
              style: theme.textTheme.bodySmall,
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final a in areas)
                  FilterChip(
                    key: ValueKey('roster-area:${a['id']}'),
                    label: Text('${a['name']}'),
                    selected: _areaIds.contains(a['id']),
                    onSelected: (v) => setState(
                      () => v
                          ? _areaIds.add('${a['id']}')
                          : _areaIds.remove(a['id']),
                    ),
                  ),
              ],
            ),
          if (!_isNew) ...[
            const SizedBox(height: 8),
            SwitchListTile(
              key: const ValueKey('roster-active'),
              contentPadding: EdgeInsets.zero,
              title: const Text('Active'),
              subtitle: const Text('Off retires them: no new jobs'),
              value: _active,
              onChanged: (v) => setState(() => _active = v),
            ),
          ],
        ],
      ),
    );
  }
}
