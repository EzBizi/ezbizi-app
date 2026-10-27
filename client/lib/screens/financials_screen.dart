import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import 'financials/financial_adjustments.dart';
import 'financials/financial_bills.dart';
import 'financials/financial_grid.dart';
import 'financials/financial_home.dart';
import 'financials/financial_panels.dart';
import 'financials/financial_results.dart';
import 'financials/financial_sources.dart';
import 'financials/financial_views.dart';
import 'financials/invoices_tab.dart';

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
          'Home',
          Icons.table_chart_outlined,
          FinancialBases(api: widget.api, session: widget.session),
        ),
      if (_may('bills'))
        (
          'Bills',
          Icons.receipt_long_outlined,
          FinancialBillsTab(api: widget.api, me: widget.session.me!),
        ),
      // A child module, drawn as a tab here (design 128).
      if (widget.session.me?.reachableModules.any((m) => m.key == 'invoices') ??
          false)
        (
          'Invoices',
          Icons.request_quote_outlined,
          InvoicesTab(api: widget.api, session: widget.session),
        ),
      // A child module, drawn as a tab here, as Invoices is (ACE, option A).
      if (widget.session.me?.reachableModules.any(
            (m) => m.key == 'financial_results',
          ) ??
          false)
        (
          'Lead source results',
          Icons.campaign_outlined,
          FinancialResultsTab(api: widget.api, me: widget.session.me!),
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

  /// What GET /financials/home answered; null while it answers 404, and the
  /// home is then built from /financials/bases alone.
  Map? _home;

  /// The base open in place of the home, or null.
  String? _baseId;

  /// The area open in place of the home, or null, and its table on show.
  FinancialCard? _area;
  String? _areaTable;
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

  bool get _onHome => _baseId == null && _area == null;

  List<FinancialCard> get _areaCards => [
    for (final a in (_home?['areas'] as List? ?? const []).cast<Map>())
      FinancialCard.area(a),
  ];

  /// The home's own base cards when it answered; otherwise one per base the
  /// Bases tab already reads, whose only real number is how many tables it has.
  List<FinancialCard> get _baseCards {
    if (_home?['bases'] case final List bases) {
      return [for (final b in bases.cast<Map>()) FinancialCard.base(b)];
    }
    return [
      for (final b in _bases)
        FinancialCard(
          id: '${b['id']}',
          name: '${b['name']}',
          colourHint: b['colour'] as String?,
          figure: switch ((b['tables'] as List?)?.length ?? 0) {
            1 => '1 table',
            final n => '$n tables',
          },
        ),
    ];
  }

  bool get _mayCreateBase =>
      _home == null ? _can('create') : _home!['may_create_base'] == true;

  /// The open base's tables: (id, name, record count or null).
  List<(String, String, int?)> get _tables => [
    for (final t in (_base?['tables'] as List? ?? const []).cast<Map>())
      ('${t['id']}', '${t['name']}', null),
  ];

  @override
  void initState() {
    super.initState();
    _loadHome();
    _loadBases();
  }

  Future<void> _loadHome() async {
    final r = await widget.api.get('/financials/home');
    if (!mounted) return;
    // 404: this server has no home yet; the bases alone draw it.
    if (r.ok) setState(() => _home = r.body['data'] as Map?);
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
      if (select != null) {
        _area = null;
        _baseId = select;
      } else if (!bases.any((b) => b['id'] == _baseId)) {
        _baseId = null;
      }
    });
    if (_baseId == null) return;
    final keep = _tables.any((t) => t.$1 == _tableId);
    await _openTable(keep ? _tableId : _tables.firstOrNull?.$1);
  }

  void _openBase(FinancialCard card) {
    setState(() {
      _area = null;
      _baseId = card.id;
      _tableId = null;
      _search = '';
    });
    _openTable(_tables.firstOrNull?.$1);
  }

  void _openArea(FinancialCard card) {
    _openAreaState(card);
    final first = card.tables.where((t) => _closedBecause(t) == null);
    if (first.isNotEmpty) _selectAreaTable(first.first);
  }

  void _openAreaState(FinancialCard card) => setState(() {
    _baseId = null;
    _area = card;
    _areaTable = null;
    _tableId = null;
    _table = null;
  });

  void _backHome() {
    setState(() {
      _baseId = null;
      _area = null;
      _areaTable = null;
      _tableId = null;
      _table = null;
      _viewId = null;
      _search = '';
    });
    _loadHome();
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

  bool _confirmCut = false;

  /// Cut a mirrored table over to EzBizi (or back). The first press asks.
  Future<void> _cutOver({required bool undo}) async {
    if (!undo && !_confirmCut) {
      setState(() => _confirmCut = true);
      return;
    }
    _confirmCut = false;
    final r = await widget.api.post('/financials/tables/$_tableId/cut-over', {
      if (undo) 'undo': true,
    });
    if (!mounted) return;
    if (!r.ok) return _say(r.message);
    await _reload();
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
        canEdit: _can('edit'),
        canDelete: _can('delete'),
      ),
    );
    if (changed == true) await _reload();
  }

  /// A batch of cell edits from the grid: a paste, a fill-down, a delete, an undo.
  Future<String?> _changeCells(List<CellChange> changes) async {
    final byRecord = <String, Map<String, dynamic>>{};
    for (final c in changes) {
      (byRecord['${c.record['id']}'] ??= {})[c.fieldId] = c.value;
    }
    final r = await widget.api.patch('/financials/tables/$_tableId/records', {
      'changes': [
        for (final e in byRecord.entries) {'id': e.key, 'values': e.value},
      ],
    });
    if (!mounted) return null;
    if (r.ok) {
      final conflicts = (r.body['conflicts'] as List?) ?? const [];
      if (conflicts.isNotEmpty) {
        _say(
          '${conflicts.length} ${conflicts.length == 1 ? 'cell was' : 'cells were'} changed in Airtable since you '
          "opened this, so Airtable's value was kept: "
          '${conflicts.take(3).map((c) => '${c['field']} is now "${c['theirs'] ?? ''}"').join('; ')}. '
          'Type yours again to replace it.',
        );
      }
      final updated = {
        for (final rec in (r.body['data'] as List).cast<Map>())
          '${rec['id']}': rec,
      };
      setState(() {
        final records = (_table!['records'] as List);
        for (var i = 0; i < records.length; i++) {
          final fresh = updated['${records[i]['id']}'];
          if (fresh != null) records[i] = fresh;
        }
      });
      return null;
    }
    return r.message;
  }

  List<Map> _grouped(List<Map> rows, Map config) {
    final field = _fields
        .where((f) => f['id'] == config['group_field'])
        .firstOrNull;
    if (field == null) return rows;
    final sorted = [...rows];
    sorted.sort(
      (a, b) => '${(a['values'] as Map)[field['id']] ?? ''}'.compareTo(
        '${(b['values'] as Map)[field['id']] ?? ''}',
      ),
    );
    return sorted;
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
    final areaCards = _areaCards;
    final baseCards = _baseCards;
    if (areaCards.isEmpty && baseCards.isEmpty) {
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
              if (_mayCreateBase)
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

    if (_onHome) {
      return FinancialHome(
        areas: areaCards,
        bases: baseCards,
        onArea: _openArea,
        onBase: _openBase,
        onNewBase: _mayCreateBase ? _newBase : null,
      );
    }

    if (_area case final area?) return _areaPage(area);

    final tables = _tables;
    final card = baseCards.where((b) => b.id == _baseId).firstOrNull;
    final badge = sourceLabel(card?.source);
    final refreshed = refreshedLabel(card?.refreshedAt);
    final selectedTable = tables.indexWhere((t) => t.$1 == _tableId);
    final canEdit = _can('edit');

    return _pinBody([
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            IconButton(
              key: const ValueKey('financials-back'),
              tooltip: 'Back to Financials home',
              icon: const Icon(Icons.arrow_back),
              onPressed: _backHome,
            ),
            Text(
              (_base?['place'] == null
                  ? '${_base?['name'] ?? ''}'
                  : '${_base?['name']} · ${_base?['place']}'),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            if (badge != null) StatusPill(badge),
            if (refreshed != null)
              Text(
                refreshed,
                style: TextStyle(
                  fontSize: 12,
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            if (canEdit)
              IconButton(
                tooltip: 'Rename base',
                icon: const Icon(Icons.edit_outlined),
                onPressed: _renameBase,
              ),
          ],
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
        child: Wrap(
          spacing: 6,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (tables.isNotEmpty)
              TabBarLike(
                key: const ValueKey('financials-table-chips'),
                level: TabLevel.secondary,
                labels: [
                  for (final t in tables)
                    (
                      t.$3 == null ? t.$2 : '${t.$2} · ${t.$3}',
                      Icons.table_rows_outlined,
                    ),
                ],
                selected: selectedTable < 0 ? 0 : selectedTable,
                onSelected: (i) => _openTable(tables[i].$1),
              ),
            if (canEdit && _table != null)
              IconButton(
                tooltip: 'Rename table',
                icon: const Icon(Icons.drive_file_rename_outline),
                onPressed: _renameTable,
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
      ..._tableSection(),
    ]);
  }

  /// A page whose last child is its table (or list): everything above it
  /// scrolls within at most half the height, so the table always keeps the
  /// rest. On a phone the base's name, its tables, the mirror line and the
  /// view's buttons wrap to more lines than the screen is tall, and the
  /// table under them was laid out with no height at all.
  Widget _pinBody(List<Widget> children) {
    if (children case [...final above, Expanded(:final child)]) {
      return LayoutBuilder(
        builder: (context, box) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ConstrainedBox(
              constraints: BoxConstraints(maxHeight: box.maxHeight / 2),
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: above,
                ),
              ),
            ),
            Expanded(child: child),
          ],
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: children,
    );
  }

  /// The open table: its mirror line, views, and the view itself. Shared by
  /// a base and by an area whose table mirrors into Financials.
  List<Widget> _tableSection() {
    final view = _view;
    final config = (view?['config'] as Map?) ?? const {};
    final hidden = {
      ...(config['hidden'] as List? ?? const []).map((e) => '$e'),
    };
    final shown = _fields.where((f) => !hidden.contains(f['id'])).toList();
    final rows = applyView(_records, _fields, config, _search);
    final canEdit = _can('edit');
    // Cells edit everywhere (a mirrored table writes through to Airtable);
    // a mirrored table's columns and CSV import stay Airtable's.
    final canWrite = canEdit;
    final canShape = canEdit && _table?['mirror'] == null;

    return [
      if (_table?['mirror'] case final Map mirror)
        _TableNote(
          icon: Icons.sync,
          text:
              'Kept in step with Airtable: edits here are saved there too. '
              'Refreshed ${_ago(mirror['synced_at'] as String?)}.',
          actions: [
            if (canEdit)
              TextButton(
                onPressed: _refreshMirror,
                child: const Text('Refresh now'),
              ),
            if (canEdit && widget.session.me?.scope.type == 'organization')
              TextButton(
                key: const ValueKey('cut-over'),
                onPressed: () => _cutOver(undo: false),
                child: Text(
                  _confirmCut
                      ? 'Press again: Airtable stops being used for this table'
                      : 'Cut over to EzBizi',
                ),
              ),
          ],
        ),
      if (_table?['cut_over'] case final Map cut)
        _TableNote(
          icon: Icons.check_circle_outline,
          text:
              'Kept in EzBizi since ${_ago(cut['at'] as String?)}; Airtable is no longer read or written for it.',
          actions: [
            if (canEdit && widget.session.me?.scope.type == 'organization')
              TextButton(
                onPressed: () => _cutOver(undo: true),
                child: const Text('Go back to Airtable'),
              ),
          ],
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
                  key: const Key('financials-grid-search'),
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
              if (canShape)
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
            _ => FinancialGrid(
              key: ValueKey('grid:$_tableId'),
              tableId: '$_tableId',
              fields: shown,
              allFields: _fields,
              // ponytail: grouping sorts rows by the group column; headers per group come back if missed.
              records: _grouped(rows, config),
              config: config,
              onOpen: _openRecord,
              onChange: _changeCells,
              onField: canShape ? _editField : (_) {},
              canEdit: canWrite,
              // Unset, the server sends [] (an empty PHP array), not {}.
              widths: switch (widget
                  .session
                  .me
                  ?.preferences['financials.column_widths']) {
                final Map m => Map<String, dynamic>.from(m),
                _ => const {},
              },
              onWidths: (w) =>
                  widget.session.setPreference('financials.column_widths', w),
            ),
          },
        ),
      ] else
        const Expanded(child: Center(child: Text('No tables here yet.'))),
    ];
  }

  /// Why an area's table cannot be opened here, or null when it can. The
  /// server's own refusal wins, except "not mirrored yet", which a person
  /// who may link Airtable resolves here with Mirror it.
  String? _closedBecause(AreaTable t) {
    if (t.tableId != null && t.mayOpen != false) return null;
    if (t.mayOpen == false && t.refused != AreaTable.notMirrored) {
      return t.refused ?? 'Your role cannot open this';
    }
    return switch (t.key) {
      // A server that does not say: the Bills tab's own grant.
      'bills'
          when t.mayOpen == null &&
              !(widget.session.me?.permissions.contains(
                    'financials.bills.view',
                  ) ??
                  false) =>
        'Your role cannot see Bills',
      'bills' || 'adjustments' || 'repair_moves' => null,
      // An Airtable source no base mirrors: the server's may_mirror alone
      // says whether Mirror it is offered.
      _ when t.mayMirror == true && t.airtable != null => null,
      _ => t.refused ?? AreaTable.notMirrored,
    };
  }

  void _selectAreaTable(AreaTable t) {
    setState(() {
      _areaTable = t.key;
      if (t.tableId == null) {
        _tableId = null;
        _table = null;
      }
    });
    if (t.tableId != null) _openTable(t.tableId);
  }

  bool _mirroring = false;

  /// Mirrors an area's Airtable source (the server picks or makes the base),
  /// then reloads the home, whose chip now carries the new table's id.
  Future<void> _mirror(AreaTable t) async {
    final (baseId, tableId) = t.airtable!;
    setState(() => _mirroring = true);
    final r = await widget.api.post('/financials/airtable-links', {
      'base_id': baseId,
      'table_id': tableId,
    });
    if (!mounted) return;
    setState(() => _mirroring = false);
    if (!r.ok) return _say(r.message);
    await _loadHome();
    await _loadBases();
    if (!mounted) return;
    final key = _area?.id;
    final fresh = _areaCards.where((a) => a.id == key).firstOrNull;
    if (fresh == null) return;
    setState(() => _area = fresh);
    final again = fresh.tables.where((x) => x.key == t.key).firstOrNull;
    if (again != null) _selectAreaTable(again);
  }

  /// An area of the books, in place of the home: its tables as chips, and
  /// the one on show below. A table that cannot open here is a disabled chip
  /// that says why.
  Widget _areaPage(FinancialCard area) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final badge = sourceLabel(area.source);
    final refreshed = refreshedLabel(area.refreshedAt);
    final shown = area.tables.where((t) => t.key == _areaTable).firstOrNull;

    return _pinBody([
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            IconButton(
              key: const ValueKey('financials-back'),
              tooltip: 'Back to Financials home',
              icon: const Icon(Icons.arrow_back),
              onPressed: _backHome,
            ),
            Text(area.name, style: Theme.of(context).textTheme.titleMedium),
            if (badge != null) StatusPill(badge),
            if (refreshed != null)
              Text(refreshed, style: TextStyle(fontSize: 12, color: muted)),
          ],
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Wrap(
          key: const ValueKey('financials-area-tables'),
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final t in area.tables)
              ChoiceChip(
                key: ValueKey('financials-area-table-${t.key}'),
                label: Text(
                  [
                    t.name,
                    if (t.count != null) '${t.count}',
                    ?_closedBecause(t),
                  ].join(' · '),
                ),
                selected: t.key == _areaTable,
                onSelected: _closedBecause(t) == null
                    ? (_) => _selectAreaTable(t)
                    : null,
              ),
          ],
        ),
      ),
      const Divider(height: 1),
      if (shown == null)
        Expanded(
          child: Center(
            child: Text(
              'Nothing here opens for your role yet.',
              style: TextStyle(color: muted),
            ),
          ),
        )
      else if (shown.tableId != null)
        ..._tableSection()
      else
        Expanded(
          child: switch (shown.key) {
            'bills' => FinancialBillsTab(
              api: widget.api,
              me: widget.session.me!,
            ),
            'adjustments' => FinancialAdjustmentsList(
              key: const ValueKey('financials-adjustments'),
              api: widget.api,
              exclude: const {'repair_move'},
              canEdit: _can('edit'),
              canDelete: _can('delete'),
            ),
            'repair_moves' => FinancialAdjustmentsList(
              key: const ValueKey('financials-repair-moves'),
              api: widget.api,
              kind: 'repair_move',
              canEdit: _can('edit'),
              canDelete: _can('delete'),
            ),
            _ => Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      '${shown.name} is in the Airtable copy but not '
                      'mirrored into a base yet.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    FilledButton.icon(
                      key: const ValueKey('financials-mirror-it'),
                      icon: const Icon(Icons.sync_alt),
                      label: const Text('Mirror it'),
                      onPressed: _mirroring ? null : () => _mirror(shown),
                    ),
                  ],
                ),
              ),
            ),
          },
        ),
    ]);
  }

  String _settingsLabel(Map config) {
    final n =
        (config['filters'] as List? ?? const []).length +
        (config['sorts'] as List? ?? const []).length +
        (config['hidden'] as List? ?? const []).length;
    return n == 0 ? 'Filter, sort, fields' : 'Filter, sort, fields ($n)';
  }
}

/// A line above a table (its mirror, its cut-over) and its buttons. The
/// buttons wrap under the sentence: beside it on a phone they took the whole
/// width, the sentence was laid one letter wide, and the table under it got
/// no height at all.
class _TableNote extends StatelessWidget {
  const _TableNote({
    required this.icon,
    required this.text,
    required this.actions,
  });

  final IconData icon;
  final String text;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
    child: Wrap(
      spacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 16),
            const SizedBox(width: 6),
            Flexible(child: Text(text)),
          ],
        ),
        ...actions,
      ],
    ),
  );
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
