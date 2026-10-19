import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/tab_bar_like.dart';
import 'financials/financial_bills.dart';
import 'financials/financial_panels.dart';
import 'financials/financial_sources.dart';
import 'financials/financial_views.dart';

/// Financials — the Airtable replacement (Jose, 2026-10-14).
///
/// Bases hold tables; a table has typed fields, records and saved views
/// (grid, kanban, calendar, gallery, form). Filters, sorts, grouping and
/// hidden fields are saved on the view, so everybody opening it sees the same.
class FinancialBases extends StatefulWidget {
  const FinancialBases({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<FinancialBases> createState() => _FinancialsScreenState();
}

/// Financials — the Airtable replacement (Jose, 2026-10-14). Two tabs:
/// Bases (the work) and Settings (which Airtable table is which part of the
/// books). Each is its own grant.
class FinancialsScreen extends StatefulWidget {
  const FinancialsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<FinancialsScreen> createState() => _FinancialsHostState();
}

class _FinancialsHostState extends State<FinancialsScreen> {
  int _tab = 0;

  bool _may(String tab) =>
      widget.session.me?.permissions.contains('financials.$tab.view') ?? false;

  @override
  Widget build(BuildContext context) {
    final tabs = [
      if (_may('bases'))
        (
          'Bases',
          Icons.table_chart_outlined,
          FinancialBases(api: widget.api, session: widget.session),
        ),
      if (_may('bills'))
        (
          'Bills',
          Icons.receipt_long_outlined,
          FinancialBillsTab(api: widget.api, me: widget.session.me!),
        ),
      if (_may('settings'))
        (
          'Settings',
          Icons.tune,
          FinancialSourcesSettings(api: widget.api, me: widget.session.me!),
        ),
    ];
    if (tabs.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('No Financials tabs have been granted to your role.'),
        ),
      );
    }
    final selected = _tab < tabs.length ? _tab : 0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-financials-tabs'),
            labels: [for (final t in tabs) (t.$1, t.$2)],
            selected: selected,
            onSelected: (i) => setState(() => _tab = i),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: IndexedStack(
            index: selected,
            children: [for (final t in tabs) t.$3],
          ),
        ),
      ],
    );
  }
}

const _viewTypes = <String, (String, IconData)>{
  'grid': ('Grid', Icons.grid_on),
  'kanban': ('Kanban', Icons.view_kanban_outlined),
  'calendar': ('Calendar', Icons.calendar_month_outlined),
  'gallery': ('Gallery', Icons.photo_library_outlined),
  'form': ('Form', Icons.dynamic_form_outlined),
  'split': ('Split', Icons.call_split),
};

class _FinancialsScreenState extends State<FinancialBases> {
  List<Map> _bases = [];
  String? _baseId;
  String? _tableId;
  Map? _table;
  String? _viewId;
  String _search = '';
  String? _error;
  bool _loading = true;

  bool _can(String action) =>
      widget.session.me?.can('financials.$action') ?? false;

  Map? get _base => _bases.where((b) => b['id'] == _baseId).firstOrNull;
  List<Map> get _fields => [...?(_table?['fields'] as List?)?.cast<Map>()];
  List<Map> get _views => [...?(_table?['views'] as List?)?.cast<Map>()];
  List<Map> get _records => [...?(_table?['records'] as List?)?.cast<Map>()];
  Map? get _view =>
      _views.where((v) => v['id'] == _viewId).firstOrNull ?? _views.firstOrNull;

  @override
  void initState() {
    super.initState();
    _loadBases();
  }

  Future<void> _loadBases({String? select}) async {
    final r = await widget.api.get('/financials/bases');
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _error = r.message;
        _loading = false;
      });
      return;
    }
    final bases = (r.body['data'] as List).cast<Map>();
    setState(() {
      _bases = bases;
      _loading = false;
      _baseId =
          select ??
          (bases.any((b) => b['id'] == _baseId)
              ? _baseId
              : bases.firstOrNull?['id'] as String?);
    });
    final tables = (_base?['tables'] as List?)?.cast<Map>() ?? const [];
    final keep = tables.any((t) => t['id'] == _tableId);
    await _openTable(keep ? _tableId : tables.firstOrNull?['id'] as String?);
  }

  Future<void> _openTable(String? id) async {
    if (id == null) {
      setState(() {
        _tableId = null;
        _table = null;
      });
      return;
    }
    final r = await widget.api.get('/financials/tables/$id');
    if (!mounted) return;
    setState(() {
      if (id != _tableId) _viewId = null;
      _tableId = id;
      _table = r.ok ? r.body['data'] as Map : null;
      _error = r.ok ? null : r.message;
    });
  }

  Future<void> _reload() => _openTable(_tableId);

  void _say(String message) => ScaffoldMessenger.of(
    context,
  ).showSnackBar(SnackBar(content: Text(message)));

  // ── Actions ──────────────────────────────────────────────

  Future<void> _newBase() async {
    final created = await showSidePanel<String>(
      context,
      builder: (_) => _NewBasePanel(api: widget.api, me: widget.session.me!),
    );
    if (created != null) await _loadBases(select: created);
  }

  Future<void> _linkAirtable() async {
    final r = await widget.api.get('/financials/airtable-tables');
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    final tables = (r.body['data'] as List).cast<Map>();
    if (tables.isEmpty) {
      return _say(
        'No Airtable copy yet. Connect Airtable in Integrations; the copy refreshes every hour.',
      );
    }
    final picked = await showSearchPicker<Map>(
      context,
      title: 'Mirror an Airtable table',
      options: [
        for (final t in tables)
          SearchOption(
            value: t,
            label: '${t['table_name']} (${t['rows']} rows)',
          ),
      ],
    );
    if (picked == null || _baseId == null) return;
    final linked = await widget.api.post(
      '/financials/bases/$_baseId/airtable-links',
      {'base_id': picked['base_id'], 'table_id': picked['table_id']},
    );
    if (!mounted) return;
    if (!linked.ok) return _say(linked.message);
    _tableId = (linked.body['data'] as Map)['id'] as String;
    await _loadBases();
  }

  Future<void> _refreshMirror() async {
    final r = await widget.api.post('/financials/tables/$_tableId/refresh', {});
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    final done = (r.body['data'] as Map)['refreshed'] as Map;
    _say(
      'Up to date: ${done['added']} added, ${done['changed']} changed, ${done['removed']} removed.',
    );
    await _reload();
  }

  Future<void> _newTable() async {
    final name = await showSidePanel<String>(
      context,
      builder: (_) => const _NamePanel(title: 'New table', label: 'Table name'),
    );
    if (name == null || _baseId == null) return;
    final r = await widget.api.post('/financials/bases/$_baseId/tables', {
      'name': name,
    });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    _tableId = (r.body['data'] as Map)['id'] as String;
    await _loadBases();
  }

  Future<void> _renameTable() async {
    final name = await showSidePanel<String>(
      context,
      builder: (_) => _NamePanel(
        title: 'Rename table',
        label: 'Table name',
        initial: '${_table?['name'] ?? ''}',
        onDelete: _can('delete') ? 'Delete table' : null,
      ),
    );
    if (name == null) return;
    final r = name == _NamePanel.delete
        ? await widget.api.delete('/financials/tables/$_tableId')
        : await widget.api.patch('/financials/tables/$_tableId', {
            'name': name,
          });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    await _loadBases();
  }

  Future<void> _renameBase() async {
    final name = await showSidePanel<String>(
      context,
      builder: (_) => _NamePanel(
        title: 'Rename base',
        label: 'Base name',
        initial: '${_base?['name'] ?? ''}',
        onDelete: _can('delete') ? 'Delete base and everything in it' : null,
      ),
    );
    if (name == null) return;
    final r = name == _NamePanel.delete
        ? await widget.api.delete('/financials/bases/$_baseId')
        : await widget.api.patch('/financials/bases/$_baseId', {'name': name});
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    if (name == _NamePanel.delete) _baseId = null;
    await _loadBases();
  }

  Future<void> _newView() async {
    final type = await showSearchPicker<String>(
      context,
      title: 'New view',
      options: [
        for (final e in _viewTypes.entries)
          SearchOption(value: e.key, label: e.value.$1),
      ],
    );
    if (type == null) return;
    final firstSelect = _fields
        .where((f) => f['type'] == 'single_select')
        .firstOrNull;
    final firstDate = _fields.where((f) => f['type'] == 'date').firstOrNull;
    final r = await widget.api.post('/financials/tables/$_tableId/views', {
      'name': '${_viewTypes[type]!.$1} view',
      'type': type,
      'config': {
        if (type == 'kanban' && firstSelect != null)
          'group_field': firstSelect['id'],
        if (type == 'calendar' && firstDate != null)
          'date_field': firstDate['id'],
      },
    });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    _viewId = (r.body['data'] as Map)['id'] as String;
    await _reload();
  }

  Future<void> _viewSettings() async {
    final view = _view;
    if (view == null) return;
    final config = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => ViewSettingsPanel(view: view, fields: _fields),
    );
    if (config == null) return;
    final r = await widget.api.patch('/financials/views/${view['id']}', {
      'config': config,
    });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    await _reload();
  }

  Future<void> _deleteView() async {
    final r = await widget.api.delete('/financials/views/${_view!['id']}');
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    _viewId = null;
    await _reload();
  }

  void _openRecord(Map? record, {Map initial = const {}}) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => RecordPanel(
        api: widget.api,
        tableId: _tableId!,
        fields: _fields,
        record: record,
        initial: initial,
        canEdit: _can('edit') && _table?['mirror'] == null,
        canDelete: _can('delete') && _table?['mirror'] == null,
      ),
    );
    if (changed == true) await _reload();
  }

  Future<void> _patch(Map record, String fieldId, dynamic value) async {
    final r = await widget.api.patch('/financials/records/${record['id']}', {
      'values': {fieldId: value},
    });
    if (!mounted) return;
    if (!r.ok) _say(r.message);
    await _reload();
  }

  void _editField(Map? field) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => FieldPanel(
        api: widget.api,
        tableId: _tableId!,
        field: field,
        canDelete: _can('delete'),
      ),
    );
    if (changed == true) await _reload();
  }

  Future<void> _import() async {
    final file = await Files.pickText();
    if (file == null) return;
    final r = await widget.api.post('/financials/tables/$_tableId/import', {
      'csv': file.text,
    });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    _say(
      'Imported ${(r.body['data'] as Map)['imported']} records from ${file.name}.',
    );
    await _reload();
  }

  Future<void> _export() async {
    final bytes = await widget.api.getBytes(
      '/financials/tables/$_tableId/export',
    );
    if (!mounted) return;
    if (bytes == null) {
      return _say('Could not export this table. Nothing was changed.');
    }
    Files.download('${_table?['name'] ?? 'table'}.csv', bytes);
  }

  // ── Layout ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_bases.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.table_chart_outlined, size: 48),
              const SizedBox(height: 12),
              Text(
                _error ??
                    'No bases yet. A base is a spreadsheet you can build on: bookkeeping, a budget, invoices.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 16),
              if (_can('create'))
                FilledButton.icon(
                  key: const ValueKey('financials-new-base'),
                  icon: const Icon(Icons.add),
                  label: const Text('New base'),
                  onPressed: _newBase,
                ),
            ],
          ),
        ),
      );
    }

    final tables = (_base?['tables'] as List?)?.cast<Map>() ?? const [];
    final view = _view;
    final config = (view?['config'] as Map?) ?? const {};
    final hidden = {
      ...(config['hidden'] as List? ?? const []).map((e) => '$e'),
    };
    final shown = _fields.where((f) => !hidden.contains(f['id'])).toList();
    final rows = applyView(_records, _fields, config, _search);
    final canEdit = _can('edit');
    // A mirrored table takes views and settings, never cells.
    final canWrite = canEdit && _table?['mirror'] == null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 240,
                child: SearchPickerField<String>(
                  label: 'Base',
                  isDense: true,
                  value: _baseId,
                  options: [
                    for (final b in _bases)
                      SearchOption(
                        value: '${b['id']}',
                        label: b['place'] == null
                            ? '${b['name']}'
                            : '${b['name']} · ${b['place']}',
                      ),
                  ],
                  onChanged: (id) {
                    if (id == null) return;
                    _tableId = null;
                    _loadBases(select: id);
                  },
                ),
              ),
              if (canEdit)
                IconButton(
                  tooltip: 'Rename base',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: _renameBase,
                ),
              if (_can('create'))
                OutlinedButton.icon(
                  icon: const Icon(Icons.add),
                  label: const Text('New base'),
                  onPressed: _newBase,
                ),
            ],
          ),
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: Row(
            children: [
              for (final t in tables)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    label: Text('${t['name']}'),
                    selected: t['id'] == _tableId,
                    onSelected: (_) => t['id'] == _tableId && canEdit
                        ? _renameTable()
                        : _openTable('${t['id']}'),
                  ),
                ),
              if (canEdit)
                TextButton.icon(
                  icon: const Icon(Icons.add),
                  label: const Text('Add table'),
                  onPressed: _newTable,
                ),
              if (canEdit)
                TextButton.icon(
                  icon: const Icon(Icons.sync_alt),
                  label: const Text('Mirror Airtable'),
                  onPressed: _linkAirtable,
                ),
            ],
          ),
        ),
        if (_table?['mirror'] case final Map mirror)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
            child: Row(
              children: [
                const Icon(Icons.sync, size: 16),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    'Mirrors Airtable, read-only here. Refreshed '
                    '${_ago(mirror['synced_at'] as String?)}; change it in Airtable.',
                  ),
                ),
                if (canEdit)
                  TextButton(
                    onPressed: _refreshMirror,
                    child: const Text('Refresh now'),
                  ),
              ],
            ),
          ),
        if (_table != null) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            child: Wrap(
              key: const ValueKey('tour-financials-views'),
              spacing: 6,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                for (final v in _views)
                  ChoiceChip(
                    avatar: Icon(_viewTypes[v['type']]?.$2, size: 16),
                    showCheckmark: false,
                    label: Text('${v['name']}'),
                    selected: v['id'] == view?['id'],
                    onSelected: (_) => setState(() => _viewId = '${v['id']}'),
                  ),
                if (canEdit)
                  IconButton(
                    tooltip: 'New view',
                    icon: const Icon(Icons.add),
                    onPressed: _newView,
                  ),
                const SizedBox(width: 12),
                SizedBox(
                  width: 200,
                  child: TextField(
                    decoration: const InputDecoration(
                      isDense: true,
                      prefixIcon: Icon(Icons.search),
                      hintText: 'Search',
                    ),
                    onChanged: (t) => setState(() => _search = t.trim()),
                  ),
                ),
                OutlinedButton.icon(
                  icon: const Icon(Icons.tune),
                  label: Text(_settingsLabel(config)),
                  onPressed: canEdit ? _viewSettings : null,
                ),
                if (canEdit && _views.length > 1)
                  IconButton(
                    tooltip: 'Delete this view',
                    icon: const Icon(Icons.delete_outline),
                    onPressed: _deleteView,
                  ),
                if (canWrite)
                  IconButton(
                    tooltip: 'Import CSV',
                    icon: const Icon(Icons.upload_file_outlined),
                    onPressed: _import,
                  ),
                IconButton(
                  tooltip: 'Export CSV',
                  icon: const Icon(Icons.download_outlined),
                  onPressed: _export,
                ),
                if (canWrite)
                  FilledButton.icon(
                    icon: const Icon(Icons.add),
                    label: const Text('Add record'),
                    onPressed: () => _openRecord(null),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: switch (view?['type']) {
              'kanban' => KanbanView(
                fields: shown,
                allFields: _fields,
                records: rows,
                config: config,
                onOpen: _openRecord,
                onPatch: _patch,
                canEdit: canWrite,
              ),
              'calendar' => CalendarView(
                fields: shown,
                allFields: _fields,
                records: rows,
                config: config,
                onOpen: _openRecord,
              ),
              'split' => SplitView(api: widget.api, view: view!),
              'gallery' => GalleryView(
                fields: shown,
                allFields: _fields,
                records: rows,
                onOpen: _openRecord,
              ),
              'form' => FormView(
                fields: shown,
                allFields: _fields,
                canEdit: canWrite,
                onSubmit: (values) async {
                  final r = await widget.api.post(
                    '/financials/tables/$_tableId/records',
                    {'values': values},
                  );
                  if (r.ok) await _reload();
                  return r.ok ? null : r.message;
                },
              ),
              _ => GridView2(
                fields: shown,
                allFields: _fields,
                records: rows,
                config: config,
                onOpen: _openRecord,
                onPatch: _patch,
                onField: _editField,
                canEdit: canWrite,
              ),
            },
          ),
        ] else
          const Expanded(
            child: Center(child: Text('This base has no tables yet.')),
          ),
      ],
    );
  }

  String _settingsLabel(Map config) {
    final n =
        (config['filters'] as List? ?? const []).length +
        (config['sorts'] as List? ?? const []).length +
        (config['hidden'] as List? ?? const []).length;
    return n == 0 ? 'Filter, sort, fields' : 'Filter, sort, fields ($n)';
  }
}

class _NamePanel extends StatefulWidget {
  const _NamePanel({
    required this.title,
    required this.label,
    this.initial = '',
    this.onDelete,
  });

  static const delete = '\u0000delete';

  final String title;
  final String label;
  final String initial;
  final String? onDelete;

  @override
  State<_NamePanel> createState() => _NamePanelState();
}

class _NamePanelState extends State<_NamePanel> {
  late final _name = TextEditingController(text: widget.initial);
  bool _confirming = false;

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.title,
      actions: [
        if (widget.onDelete != null)
          TextButton(
            onPressed: () => _confirming
                ? Navigator.of(context).pop(_NamePanel.delete)
                : setState(() => _confirming = true),
            child: Text(
              _confirming ? 'Press again to delete' : widget.onDelete!,
            ),
          ),
        FilledButton(
          onPressed: () {
            final t = _name.text.trim();
            if (t.isNotEmpty) Navigator.of(context).pop(t);
          },
          child: const Text('Save'),
        ),
      ],
      child: TextField(
        controller: _name,
        autofocus: true,
        decoration: InputDecoration(labelText: widget.label),
      ),
    );
  }
}

/// A base is filed where the view stands, like every other record (AGENTS.md).
class _NewBasePanel extends StatefulWidget {
  const _NewBasePanel({required this.api, required this.me});

  final ApiClient api;
  final Me me;

  @override
  State<_NewBasePanel> createState() => _NewBasePanelState();
}

class _NewBasePanelState extends State<_NewBasePanel> {
  final _name = TextEditingController();
  String _template = 'bookkeeping';
  String? _error;
  bool _busy = false;

  static const _templates = {
    'bookkeeping': (
      'Bookkeeping',
      'Transactions with type, category, amount and paid.',
    ),
    'budget': ('Budget', 'Budgeted against actual, with a Remaining formula.'),
    'invoices': (
      'Invoice tracker',
      'Customers, due dates, amounts and status.',
    ),
    'blank': ('Blank', 'One table with a Name and Notes column.'),
  };

  Future<void> _save() async {
    setState(() => _busy = true);
    final r = await widget.api.post('/financials/bases', {
      'name': _name.text.trim().isEmpty
          ? _templates[_template]!.$1
          : _name.text.trim(),
      'template': _template,
      'company_id': ?widget.me.filingCompanyId,
      'franchise_id': ?widget.me.filingFranchiseId,
    });
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok
        ? Navigator.of(context).pop((r.body['data'] as Map)['id'] as String)
        : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'New base',
      actions: [
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Create base'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          TextField(
            controller: _name,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'Base name'),
          ),
          const SizedBox(height: 12),
          for (final e in _templates.entries)
            ListTile(
              contentPadding: EdgeInsets.zero,
              selected: e.key == _template,
              leading: Icon(
                e.key == _template
                    ? Icons.radio_button_checked
                    : Icons.radio_button_unchecked,
              ),
              onTap: () => setState(() => _template = e.key),
              title: Text(e.value.$1),
              subtitle: Text(e.value.$2),
            ),
        ],
      ),
    );
  }
}

String _ago(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return 'never';
  final minutes = DateTime.now().difference(at).inMinutes;
  if (minutes < 1) return 'just now';
  if (minutes < 60) return '$minutes min ago';
  return '${minutes ~/ 60} h ago';
}
