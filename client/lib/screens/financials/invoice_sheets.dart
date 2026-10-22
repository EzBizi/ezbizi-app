import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'invoice_common.dart';

/// Financials › Invoices › Charge sheets (design 128, option 1).
///
/// What each branch owes for a period: a row per branch, Jobs, a column per
/// charge line and a total, with a totals row. Cells are typed in and saved
/// a moment after the last keystroke. A row already invoiced is read-only and
/// carries a chip to its invoice. "Make invoices" drafts one per branch with
/// money on it and no invoice yet.
class ChargeSheetsTab extends StatefulWidget {
  const ChargeSheetsTab({
    super.key,
    required this.api,
    required this.me,
    required this.onMade,
    required this.onOpenInvoice,
    this.saveDelay = const Duration(milliseconds: 700),
  });

  final ApiClient api;
  final Me me;

  /// After Make invoices: the host switches to the Invoices tab.
  final VoidCallback onMade;

  /// The chip on an invoiced row.
  final ValueChanged<String> onOpenInvoice;

  /// How long after the last keystroke a cell is saved.
  final Duration saveDelay;

  @override
  State<ChargeSheetsTab> createState() => _ChargeSheetsTabState();
}

class _ChargeSheetsTabState extends State<ChargeSheetsTab> {
  List<Map>? _sheets;
  String? _sheetId;
  Map? _sheet;
  String? _error;
  String? _note;
  bool _saving = false;
  bool _making = false;

  /// Rows as typed here; the server's answer only replaces them on a reload.
  List<Map<String, dynamic>> _rows = [];
  final _controllers = <String, TextEditingController>{};
  final _pending = <String, Map<String, dynamic>>{};
  Timer? _debounce;

  /// The row a phone is editing, drawn in place of the list.
  String? _editingRowId;

  /// The search box over the sheets, sent as `q`, debounced.
  final _search = TextEditingController();
  Timer? _searchDebounce;
  String _q = '';

  DisplayFormats get _formats => widget.me.display;
  bool get _canEdit => widget.me.can('invoices.edit');
  bool get _canCreate => widget.me.can('invoices.create');

  @override
  void initState() {
    super.initState();
    _loadSheets();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchDebounce?.cancel();
    _search.dispose();
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _searched(String text) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _loadSheets();
    });
  }

  /// InvoicesController@sheets does not read `q` yet (it returns the newest
  /// 200), so the loaded sheets are also narrowed here, by title, period and
  /// status. Once the server honours `q` this is a no-op.
  List<Map> _matching(List<Map> sheets) {
    final q = _q.toLowerCase();
    if (q.isEmpty) return sheets;
    return [
      for (final s in sheets)
        if ([
          s['title'],
          s['status'],
          _sheetName(s),
        ].whereType<Object>().join(' ').toLowerCase().contains(q))
          s,
    ];
  }

  Future<void> _loadSheets({String? select}) async {
    final r = await widget.api.get(
      '/invoices/sheets',
      _q.isEmpty ? null : {'q': _q},
    );
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _sheets = const [];
        _error = r.reason;
      });
      return;
    }
    final sheets = _matching((r.body['data'] as List).cast<Map>());
    setState(() {
      _sheets = sheets;
      _error = null;
    });
    // A search that leaves the open sheet out opens the first that matches.
    final current = sheets.any((s) => '${s['id']}' == _sheetId)
        ? _sheetId
        : null;
    final id =
        select ?? current ?? (sheets.isEmpty ? null : '${sheets.first['id']}');
    if (id != null) {
      await _loadSheet(id);
    } else if (_q.isNotEmpty) {
      setState(() {
        _sheet = null;
        _sheetId = null;
        _rows = [];
      });
    }
  }

  Future<void> _loadSheet(String id) async {
    _debounce?.cancel();
    _pending.clear();
    final r = await widget.api.get('/invoices/sheets/$id');
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    _adopt(r.body['data'] as Map);
  }

  void _adopt(Map sheet) {
    for (final c in _controllers.values) {
      c.dispose();
    }
    _controllers.clear();
    setState(() {
      _sheetId = '${sheet['id']}';
      _sheet = sheet;
      _rows = [
        for (final row in (sheet['rows'] as List).cast<Map>())
          {
            ...row.cast<String, dynamic>(),
            'cells': Map<String, dynamic>.from(
              row['cells'] as Map? ?? const {},
            ),
          },
      ];
      _error = null;
    });
  }

  List<Map> get _columns =>
      ((_sheet?['columns'] as List?) ?? const []).cast<Map>();

  int _rowTotal(Map row) => (row['cells'] as Map).values.fold<int>(
    0,
    (s, v) => s + ((v as num?)?.toInt() ?? 0),
  );

  int get _toInvoice =>
      _rows.where((r) => r['invoice_id'] == null && _rowTotal(r) > 0).length;

  TextEditingController _controller(String key, String initial) =>
      _controllers.putIfAbsent(key, () => TextEditingController(text: initial));

  static String _plain(int cents) => (cents / 100).toStringAsFixed(2);

  // ── Saving ───────────────────────────────────────────────

  void _typedCell(Map<String, dynamic> row, String columnId, String text) {
    final cents = parseMoneyCents(text);
    if (cents == null) {
      setState(() => _note = '"$text" is not an amount.');
      return;
    }
    setState(() {
      (row['cells'] as Map)[columnId] = cents;
      _note = null;
    });
    final change = _pending.putIfAbsent(
      '${row['id']}',
      () => {'id': row['id']},
    );
    ((change['cells'] ??= <String, dynamic>{}) as Map)[columnId] = cents;
    _schedule();
  }

  void _typedJobs(Map<String, dynamic> row, String text) {
    final jobs = text.trim().isEmpty ? 0 : int.tryParse(text.trim());
    if (jobs == null || jobs < 0) {
      setState(() => _note = '"$text" is not a number of jobs.');
      return;
    }
    setState(() {
      row['jobs'] = jobs;
      _note = null;
    });
    _pending.putIfAbsent('${row['id']}', () => {'id': row['id']})['jobs'] =
        jobs;
    _schedule();
  }

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(widget.saveDelay, _flush);
  }

  Future<void> _flush() async {
    if (_pending.isEmpty || _sheetId == null) return;
    final rows = _pending.values.toList();
    _pending.clear();
    setState(() => _saving = true);
    final r = await widget.api.patch('/invoices/sheets/$_sheetId/rows', {
      'rows': rows,
    });
    if (!mounted) return;
    setState(() => _saving = false);
    if (!r.ok) {
      // All or nothing: nothing was kept, so the sheet goes back to what it is.
      final why = r.reason;
      await _loadSheet(_sheetId!);
      if (mounted) setState(() => _note = why);
      return;
    }
    final saved = r.body['data'] as Map;
    setState(() => _sheet = {...?_sheet, 'saved_at': saved['saved_at']});
  }

  Future<String?> _saveRow(Map<String, dynamic> change) async {
    final r = await widget.api.patch('/invoices/sheets/$_sheetId/rows', {
      'rows': [change],
    });
    if (!mounted) return null;
    if (!r.ok) return r.reason;
    _adopt(r.body['data'] as Map);
    return null;
  }

  // ── Actions ──────────────────────────────────────────────

  Future<void> _newSheet() async {
    final made = await showSidePanel<Map>(
      context,
      builder: (_) => _NewSheetPanel(api: widget.api, me: widget.me),
    );
    if (made != null && mounted) await _loadSheets(select: '${made['id']}');
  }

  Future<void> _make() async {
    _debounce?.cancel();
    await _flush();
    if (!mounted || _sheetId == null) return;
    setState(() => _making = true);
    final r = await widget.api.post('/invoices/sheets/$_sheetId/invoice', {});
    if (!mounted) return;
    setState(() => _making = false);
    if (!r.ok) {
      setState(() => _note = r.reason);
      return;
    }
    final data = r.body['data'] as Map;
    final made = (data['made'] as List? ?? const []).length;
    final skipped = (data['skipped'] as List? ?? const []).cast<Map>();
    final said = [
      'Made $made invoice${made == 1 ? '' : 's'}.',
      for (final s in skipped) '${s['label']}: ${s['reason']}.',
    ].join(' ');
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(said)));
    widget.onMade();
  }

  // ── Layout ───────────────────────────────────────────────

  String _sheetName(Map s) {
    final title = s['title'] as String?;
    final period =
        '${invoiceDay(s['period_from'], _formats)} – ${invoiceDay(s['period_to'], _formats)}';
    return title == null || title.isEmpty ? period : '$title · $period';
  }

  @override
  Widget build(BuildContext context) {
    final sheets = _sheets;
    if (sheets == null) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < invoicesPhoneWidth;
        final editing = _rows
            .where((r) => '${r['id']}' == _editingRowId)
            .firstOrNull;
        if (phone && editing != null) {
          return _RowEditor(
            key: ValueKey('sheet-row-editor-${editing['id']}'),
            row: editing,
            columns: _columns,
            canEdit: _canEdit && editing['invoice_id'] == null,
            onSave: _saveRow,
            onBack: () => setState(() => _editingRowId = null),
          );
        }

        return ListView(
          padding: EdgeInsets.all(phone ? 12 : 16),
          children: [
            _header(context, sheets, phone: phone),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, key: const ValueKey('sheet-error')),
              ),
            if (_note != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _note!,
                  key: const ValueKey('sheet-note'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (sheets.isEmpty && _error == null)
              Padding(
                padding: const EdgeInsets.only(top: 24),
                child: Text(
                  _q.isNotEmpty
                      ? 'No charge sheet matches "$_q".'
                      : 'No charge sheets yet. A sheet is what each branch owes for a period.',
                ),
              ),
            if (_sheet != null) ...[
              const SizedBox(height: 12),
              if (phone) _phoneList(context) else _grid(context),
            ],
          ],
        );
      },
    );
  }

  Widget _header(
    BuildContext context,
    List<Map> sheets, {
    required bool phone,
  }) {
    final saved = _saving
        ? 'Saving…'
        : _sheet?['saved_at'] == null
        ? null
        : 'Saved ${formatStamp(_sheet!['saved_at'], '', formats: _formats)}';
    final n = _toInvoice;

    return Wrap(
      spacing: 12,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: phone ? double.infinity : 240,
          child: TextField(
            key: const Key('charge-sheets-search'),
            controller: _search,
            onChanged: _searched,
            // bodyMedium for the text and the hint, as the dense pickers draw:
            // 53 points tall, as they are; the theme's 16-point hint makes 56.
            style: Theme.of(context).textTheme.bodyMedium,
            decoration: InputDecoration(
              isDense: true,
              prefixIcon: const Icon(Icons.search, size: 18),
              hintText: 'Sheet title or period',
              hintStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: Theme.of(context).inputDecorationTheme.hintStyle?.color,
              ),
            ),
          ),
        ),
        if (sheets.isNotEmpty)
          SizedBox(
            width: phone ? double.infinity : 320,
            child: SearchPickerField<String>(
              key: const ValueKey('tour-invoices-sheet-picker'),
              label: 'Sheet',
              isDense: true,
              value: _sheetId,
              options: [
                for (final s in sheets)
                  SearchOption(
                    value: '${s['id']}',
                    label: _sheetName(s),
                    detail: '${s['rows_count']} branches',
                  ),
              ],
              onChanged: (id) {
                if (id != null) _loadSheet(id);
              },
            ),
          ),
        if (_canCreate)
          OutlinedButton.icon(
            key: const ValueKey('invoice-sheet-new'),
            onPressed: _newSheet,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New sheet'),
          ),
        if (saved != null)
          Text(
            saved,
            key: const ValueKey('sheet-saved'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        if (_canCreate && _sheet != null)
          FilledButton(
            key: const ValueKey('tour-invoices-make'),
            onPressed: n == 0 || _making ? null : _make,
            child: Text('Make invoices · $n ${n == 1 ? 'branch' : 'branches'}'),
          ),
      ],
    );
  }

  static const _branchWidth = 220.0;
  static const _jobsWidth = 90.0;
  static const _cellWidth = 130.0;
  static const _totalWidth = 150.0;

  Widget _grid(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final columns = _columns;
    final head = theme.textTheme.labelMedium;

    Widget box(Widget child, double width, {bool right = false}) => SizedBox(
      width: width,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        child: Align(
          alignment: right ? Alignment.centerRight : Alignment.centerLeft,
          child: child,
        ),
      ),
    );

    Widget line(
      List<Widget> cells, {
      bool header = false,
      bool footer = false,
    }) => Container(
      decoration: BoxDecoration(
        color: header || footer ? scheme.surfaceContainerHighest : null,
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      constraints: const BoxConstraints(minHeight: 44),
      child: Row(children: cells),
    );

    final totals = <String, int>{
      for (final c in columns)
        '${c['id']}': _rows.fold<int>(
          0,
          (s, r) =>
              s + (((r['cells'] as Map)['${c['id']}'] as num?)?.toInt() ?? 0),
        ),
    };
    final jobs = _rows.fold<int>(
      0,
      (s, r) => s + ((r['jobs'] as num?)?.toInt() ?? 0),
    );
    final grand = _rows.fold<int>(0, (s, r) => s + _rowTotal(r));

    return Container(
      key: const ValueKey('tour-invoices-sheet-grid'),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      clipBehavior: Clip.antiAlias,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: FocusTraversalGroup(
          policy: ReadingOrderTraversalPolicy(),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              line(header: true, [
                box(Text('BRANCH', style: head), _branchWidth),
                box(Text('JOBS', style: head), _jobsWidth, right: true),
                for (final c in columns)
                  box(
                    Text('${c['name']}'.toUpperCase(), style: head),
                    _cellWidth,
                    right: true,
                  ),
                box(Text('TOTAL', style: head), _totalWidth, right: true),
              ]),
              for (final row in _rows) line(_rowCells(context, row, box)),
              line(footer: true, [
                box(
                  Text('Total', style: theme.textTheme.titleSmall),
                  _branchWidth,
                ),
                box(
                  Text('$jobs', key: const ValueKey('sheet-total-jobs')),
                  _jobsWidth,
                  right: true,
                ),
                for (final c in columns)
                  box(
                    Text(
                      invoiceMoney(totals['${c['id']}']!),
                      key: ValueKey('sheet-total-${c['id']}'),
                    ),
                    _cellWidth,
                    right: true,
                  ),
                box(
                  Text(
                    invoiceMoney(grand),
                    key: const ValueKey('sheet-total'),
                    style: theme.textTheme.titleSmall,
                  ),
                  _totalWidth,
                  right: true,
                ),
              ]),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _rowCells(
    BuildContext context,
    Map<String, dynamic> row,
    Widget Function(Widget, double, {bool right}) box,
  ) {
    final id = '${row['id']}';
    final invoiced = row['invoice_id'] != null;
    final editable = _canEdit && !invoiced;
    final total = _rowTotal(row);

    Widget field(String key, String initial, ValueChanged<String> onChanged) =>
        TextField(
          key: ValueKey(key),
          controller: _controller(key, initial),
          textAlign: TextAlign.right,
          textInputAction: TextInputAction.next,
          keyboardType: const TextInputType.numberWithOptions(
            decimal: true,
            signed: true,
          ),
          decoration: const InputDecoration(
            isDense: true,
            border: OutlineInputBorder(),
          ),
          onChanged: onChanged,
          onSubmitted: (_) => FocusScope.of(context).nextFocus(),
        );

    return [
      box(
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${row['label']}', overflow: TextOverflow.ellipsis),
            if (invoiced) _invoiceChip(row),
          ],
        ),
        _branchWidth,
      ),
      box(
        editable
            ? field(
                'cell-$id-jobs',
                '${row['jobs'] ?? 0}',
                (t) => _typedJobs(row, t),
              )
            : Text('${row['jobs'] ?? 0}'),
        _jobsWidth,
        right: true,
      ),
      for (final c in _columns)
        box(
          editable
              ? field(
                  'cell-$id-${c['id']}',
                  _plain(
                    (((row['cells'] as Map)['${c['id']}']) as num?)?.toInt() ??
                        0,
                  ),
                  (t) => _typedCell(row, '${c['id']}', t),
                )
              : Text(
                  invoiceMoney(
                    (((row['cells'] as Map)['${c['id']}']) as num?)?.toInt() ??
                        0,
                  ),
                ),
          _cellWidth,
          right: true,
        ),
      box(
        Text(
          total == 0 && !invoiced ? r'$0 · no invoice' : invoiceMoney(total),
          key: ValueKey('sheet-row-total-$id'),
        ),
        _totalWidth,
        right: true,
      ),
    ];
  }

  Widget _invoiceChip(Map row) {
    final status = invoiceStatus({'status': row['invoice_status']});
    return ActionChip(
      key: ValueKey('sheet-invoice-${row['id']}'),
      visualDensity: VisualDensity.compact,
      label: Text('${row['invoice_number'] ?? 'Invoice'} · ${status.$1}'),
      onPressed: () => widget.onOpenInvoice('${row['invoice_id']}'),
    );
  }

  Widget _phoneList(BuildContext context) {
    final theme = Theme.of(context);
    final grand = _rows.fold<int>(0, (s, r) => s + _rowTotal(r));
    return Column(
      key: const ValueKey('tour-invoices-sheet-grid'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final row in _rows)
          ListTile(
            key: ValueKey('sheet-row-${row['id']}'),
            contentPadding: EdgeInsets.zero,
            title: Text('${row['label']}', overflow: TextOverflow.ellipsis),
            subtitle: row['invoice_id'] != null
                ? Align(
                    alignment: Alignment.centerLeft,
                    child: _invoiceChip(row),
                  )
                : Text('${row['jobs'] ?? 0} jobs'),
            trailing: Text(invoiceMoney(_rowTotal(row))),
            onTap: () => setState(() => _editingRowId = '${row['id']}'),
          ),
        const Divider(),
        Row(
          children: [
            Expanded(child: Text('Total', style: theme.textTheme.titleSmall)),
            Text(
              invoiceMoney(grand),
              key: const ValueKey('sheet-total'),
              style: theme.textTheme.titleSmall,
            ),
          ],
        ),
      ],
    );
  }
}

/// A phone's editor for one branch's row, drawn in place of the list.
class _RowEditor extends StatefulWidget {
  const _RowEditor({
    super.key,
    required this.row,
    required this.columns,
    required this.canEdit,
    required this.onSave,
    required this.onBack,
  });

  final Map<String, dynamic> row;
  final List<Map> columns;
  final bool canEdit;
  final Future<String?> Function(Map<String, dynamic> change) onSave;
  final VoidCallback onBack;

  @override
  State<_RowEditor> createState() => _RowEditorState();
}

class _RowEditorState extends State<_RowEditor> {
  late final _jobs = TextEditingController(text: '${widget.row['jobs'] ?? 0}');
  late final _cells = {
    for (final c in widget.columns)
      '${c['id']}': TextEditingController(
        text:
            (((((widget.row['cells'] as Map)['${c['id']}']) as num?)?.toInt() ??
                        0) /
                    100)
                .toStringAsFixed(2),
      ),
  };
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _jobs.dispose();
    for (final c in _cells.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final jobs = int.tryParse(
      _jobs.text.trim().isEmpty ? '0' : _jobs.text.trim(),
    );
    final cells = <String, int>{};
    for (final MapEntry(:key, :value) in _cells.entries) {
      final cents = parseMoneyCents(value.text);
      if (cents == null) {
        setState(() => _error = '"${value.text}" is not an amount.');
        return;
      }
      cells[key] = cents;
    }
    if (jobs == null || jobs < 0) {
      setState(() => _error = '"${_jobs.text}" is not a number of jobs.');
      return;
    }
    setState(() => _busy = true);
    final error = await widget.onSave({
      'id': widget.row['id'],
      'jobs': jobs,
      'cells': cells,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = error;
    });
    if (error == null) widget.onBack();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: '${widget.row['label']}',
      subtitle: widget.canEdit
          ? null
          : 'Invoiced. Void its invoice to change it.',
      docked: true,
      onClose: widget.onBack,
      onBack: widget.onBack,
      actions: [
        if (widget.canEdit)
          FilledButton(
            key: const ValueKey('sheet-row-save'),
            onPressed: _busy ? null : _save,
            child: const Text('Save'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('sheet-row-jobs'),
            controller: _jobs,
            enabled: widget.canEdit,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Jobs'),
          ),
          for (final c in widget.columns) ...[
            const SizedBox(height: 12),
            TextField(
              key: ValueKey('sheet-row-cell-${c['id']}'),
              controller: _cells['${c['id']}'],
              enabled: widget.canEdit,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
                signed: true,
              ),
              decoration: InputDecoration(
                labelText: '${c['name']}',
                prefixText: r'$',
              ),
            ),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}

/// New sheet: the period, and a title if wanted.
class _NewSheetPanel extends StatefulWidget {
  const _NewSheetPanel({required this.api, required this.me});

  final ApiClient api;
  final Me me;

  @override
  State<_NewSheetPanel> createState() => _NewSheetPanelState();
}

class _NewSheetPanelState extends State<_NewSheetPanel> {
  static DateTime get _now => DateTime.now();
  final _from = TextEditingController(
    text: isoDay(DateTime(_now.year, _now.month - 1, 1)),
  );
  final _to = TextEditingController(
    text: isoDay(DateTime(_now.year, _now.month, 0)),
  );
  final _title = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    _title.dispose();
    super.dispose();
  }

  Future<void> _create() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final company = widget.me.filingCompanyId;
    final r = await widget.api.post('/invoices/sheets', {
      'period_from': _from.text,
      'period_to': _to.text,
      if (_title.text.trim().isNotEmpty) 'title': _title.text.trim(),
      'company_id': ?company,
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _busy = false;
        _error = r.reason;
      });
      return;
    }
    Navigator.of(context).pop(r.body['data'] as Map);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'New sheet',
      subtitle:
          'A row per branch in view, filled in from your books where it can be.',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('invoice-sheet-create'),
          onPressed: _busy ? null : _create,
          child: const Text('Make the sheet'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          CalendarDayField(
            controller: _from,
            formats: widget.me.display,
            label: 'From',
          ),
          const SizedBox(height: 12),
          CalendarDayField(
            controller: _to,
            formats: widget.me.display,
            label: 'To',
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('invoice-sheet-title'),
            controller: _title,
            decoration: const InputDecoration(labelText: 'Title (optional)'),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                _error!,
                key: const ValueKey('invoice-sheet-error'),
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}
