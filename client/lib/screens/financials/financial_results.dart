import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'financial_cells.dart';

String _money(int? cents) =>
    formatCell(const {'type': 'currency', 'options': {}}, (cents ?? 0) / 100);

int? _cents(String t) {
  final v = double.tryParse(t.replaceAll(r'$', '').replaceAll(',', '').trim());
  return v == null ? null : (v * 100).round();
}

String _dollars(Object? cents) =>
    cents is int ? (cents / 100).toStringAsFixed(2) : '';

String _day(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

/// The date ranges offered, and the `from`/`to` each sends. `all` sends none.
const _ranges = [
  ('all', 'All dates'),
  ('this_month', 'This month'),
  ('last_month', 'Last month'),
  ('last_30', 'Last 30 days'),
  ('this_year', 'This year'),
];

Map<String, String> _rangeQuery(String key, DateTime now) {
  final (DateTime, DateTime)? span = switch (key) {
    'this_month' => (DateTime(now.year, now.month), now),
    'last_month' => (
      DateTime(now.year, now.month - 1),
      DateTime(now.year, now.month, 0),
    ),
    'last_30' => (DateTime(now.year, now.month, now.day - 29), now),
    'this_year' => (DateTime(now.year), now),
    _ => null,
  };
  return span == null ? {} : {'from': _day(span.$1), 'to': _day(span.$2)};
}

/// Financials → Lead source results (Jose, 2026-10-27, ACE option A): an
/// outside lead source's calls, jobs, cost, sales and profit per date and
/// area, typed in or read from a photo. A row counts once approved.
class FinancialResultsTab extends StatefulWidget {
  const FinancialResultsTab({
    super.key,
    required this.api,
    required this.me,
    this.pick,
    this.now,
  });

  final ApiClient api;
  final Me me;

  /// The photo picker; tests replace it. Defaults to [Files.pickBytes].
  final Future<PickedBytes?> Function()? pick;

  /// Today, for the date ranges; tests pin it.
  final DateTime? now;

  @override
  State<FinancialResultsTab> createState() => _FinancialResultsTabState();
}

class _FinancialResultsTabState extends State<FinancialResultsTab> {
  List<Map>? _rows;
  String? _error;
  bool _reading = false;
  final _search = TextEditingController();
  String _q = '';
  String _range = 'all';
  String? _status;
  String? _source;
  final _sources = <String>{};
  String? _confirmDelete;

  bool _can(String action) => widget.me.can('financial_results.$action');
  bool get _canApprove => _can('approve');
  bool _canEdit(Map r) =>
      _can('edit') && (r['status'] != 'approved' || _canApprove);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final query = {
      ..._rangeQuery(_range, widget.now ?? DateTime.now()),
      'status': ?_status,
      'source': ?_source,
    };
    final r = await widget.api.get(
      '/financials/results',
      query.isEmpty ? null : query,
    );
    if (!mounted) return;
    setState(() {
      _rows = r.ok ? (r.body['data'] as List).cast<Map>() : null;
      _error = r.ok ? null : r.message;
      for (final row in _rows ?? const <Map>[]) {
        if (row['source'] is String) _sources.add(row['source'] as String);
      }
    });
  }

  /// The server has no `q`; the search narrows the loaded rows.
  List<Map> get _matching {
    final q = _q.toLowerCase();
    final rows = _rows ?? const <Map>[];
    if (q.isEmpty) return rows;
    return [
      for (final r in rows)
        if ([
          r['place'],
          r['source'],
          r['area'],
          r['date'],
        ].whereType<Object>().join(' ').toLowerCase().contains(q))
          r,
    ];
  }

  Future<void> _read() async {
    final file =
        await (widget.pick ?? () => Files.pickBytes(accept: 'image/*'))();
    if (file == null || !mounted) return;
    setState(() => _reading = true);
    final r = await widget.api.postFile(
      '/financials/results/read',
      'image',
      file.name,
      file.bytes,
    );
    if (!mounted) return;
    setState(() => _reading = false);
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.message)));
      return;
    }
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _ReviewPanel(
        api: widget.api,
        me: widget.me,
        read: r.body['data'] as Map,
        image: file.bytes,
      ),
    );
    if (saved == true) await _load();
  }

  Future<void> _open([Map? row]) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => _ResultPanel(
        api: widget.api,
        me: widget.me,
        row: row,
        canEdit: row == null ? _can('create') : _canEdit(row),
        canApprove: _canApprove,
        canDelete: _can('delete'),
      ),
    );
    if (changed == true) await _load();
  }

  Future<void> _approve(Map row) async {
    final r = await widget.api.post(
      '/financials/results/${row['id']}/approve',
      const {},
    );
    if (!mounted) return;
    r.ok
        ? await _load()
        : ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(r.message)));
  }

  Future<void> _delete(Map row) async {
    if (_confirmDelete != row['id']) {
      return setState(() => _confirmDelete = row['id'] as String);
    }
    final r = await widget.api.delete('/financials/results/${row['id']}');
    if (!mounted) return;
    setState(() => _confirmDelete = null);
    r.ok
        ? await _load()
        : ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(r.message)));
  }

  Widget _filters(bool narrow) {
    final theme = Theme.of(context);
    Widget box(Widget child, double width) =>
        narrow ? child : SizedBox(width: width, child: child);
    final fields = <Widget>[
      box(
        TextField(
          key: const Key('financials-results-search'),
          controller: _search,
          onChanged: (t) => setState(() => _q = t.trim()),
          style: theme.textTheme.bodyMedium,
          decoration: InputDecoration(
            isDense: true,
            prefixIcon: const Icon(Icons.search, size: 18),
            hintText: 'Place, source, area or date',
            hintStyle: theme.textTheme.bodyMedium?.copyWith(
              color: theme.inputDecorationTheme.hintStyle?.color,
            ),
          ),
        ),
        240,
      ),
      box(
        SearchPickerField<String>(
          key: const ValueKey('results-range'),
          isDense: true,
          value: _range,
          options: [
            for (final (k, l) in _ranges) SearchOption(value: k, label: l),
          ],
          onChanged: (v) {
            setState(() => _range = v ?? 'all');
            _load();
          },
        ),
        170,
      ),
      box(
        SearchPickerField<String>(
          key: const ValueKey('results-status'),
          isDense: true,
          hint: 'Any status',
          allowClear: true,
          value: _status,
          options: const [
            SearchOption(value: 'pending', label: 'Pending'),
            SearchOption(value: 'approved', label: 'Approved'),
          ],
          onChanged: (v) {
            setState(() => _status = v);
            _load();
          },
        ),
        160,
      ),
      box(
        SearchPickerField<String>(
          key: const ValueKey('results-source'),
          isDense: true,
          hint: 'Any source',
          allowClear: true,
          value: _source,
          options: [
            for (final s in _sources.toList()..sort())
              SearchOption(value: s, label: s),
          ],
          onChanged: (v) {
            setState(() => _source = v);
            _load();
          },
        ),
        170,
      ),
    ];
    final buttons = <Widget>[
      if (_can('create')) ...[
        OutlinedButton.icon(
          key: const Key('results-add'),
          icon: const Icon(Icons.add),
          label: const Text('Add result'),
          onPressed: () => _open(),
        ),
        FilledButton.icon(
          key: const Key('results-read'),
          icon: _reading
              ? const SizedBox.square(
                  dimension: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.photo_camera_outlined),
          label: const Text('Read from photo'),
          onPressed: _reading ? null : _read,
        ),
      ],
    ];
    if (narrow) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final f in fields) ...[f, const SizedBox(height: 8)],
          Wrap(spacing: 8, runSpacing: 8, children: buttons),
        ],
      );
    }
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [...fields, ...buttons],
    );
  }

  List<Widget> _chips(Map r) => [
    StatusPill(
      r['channel'] == 'whatsapp' ? 'WhatsApp' : 'App',
      key: ValueKey('result-channel-${r['id']}'),
      tone: r['channel'] == 'whatsapp' ? Tone.info : Tone.neutral,
    ),
    StatusPill(
      r['status'] == 'approved' ? 'Approved' : 'Pending',
      key: ValueKey('result-status-${r['id']}'),
      tone: r['status'] == 'approved' ? Tone.ok : Tone.caution,
    ),
  ];

  List<Widget> _actions(Map r) => [
    if (r['status'] != 'approved' && _canApprove)
      TextButton(
        key: ValueKey('result-approve-${r['id']}'),
        onPressed: () => _approve(r),
        child: const Text('Approve'),
      ),
    if (_canEdit(r))
      IconButton(
        key: ValueKey('result-edit-${r['id']}'),
        tooltip: 'Edit',
        icon: const Icon(Icons.edit_outlined, size: 18),
        onPressed: () => _open(r),
      ),
    if (_can('delete'))
      _confirmDelete == r['id']
          ? TextButton(
              key: ValueKey('result-delete-confirm-${r['id']}'),
              onPressed: () => _delete(r),
              child: const Text('Delete?'),
            )
          : IconButton(
              key: ValueKey('result-delete-${r['id']}'),
              tooltip: 'Delete',
              icon: const Icon(Icons.delete_outline, size: 18),
              onPressed: () => _delete(r),
            ),
  ];

  Color? _tint(Map r) => r['status'] == 'approved'
      ? null
      : ToneColours.of(context, Tone.caution).fill.withValues(alpha: 0.45);

  static const _columns = [
    ('Date', 3),
    ('Company · Branch', 5),
    ('Source', 3),
    ('Area', 3),
    ('Calls', 2),
    ('Jobs', 2),
    ('Cost', 3),
    ('Sales', 3),
    ('Profit', 3),
    ('', 5),
    ('', 5),
  ];

  Widget _table(List<Map> rows) {
    final theme = Theme.of(context);
    Widget cell(int i, Widget child) => Expanded(
      flex: _columns[i].$2,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: child,
      ),
    );
    Text t(Object? v, {bool end = false}) => Text(
      '${v ?? ''}',
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      textAlign: end ? TextAlign.end : TextAlign.start,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 8),
          child: Row(
            children: [
              for (final (i, c) in _columns.indexed)
                cell(
                  i,
                  Text(
                    c.$1.toUpperCase(),
                    textAlign: i >= 4 && i <= 8
                        ? TextAlign.end
                        : TextAlign.start,
                    style: theme.textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.6,
                    ),
                  ),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        for (final r in rows) ...[
          Material(
            key: ValueKey('result-row-${r['id']}'),
            color: _tint(r) ?? Colors.transparent,
            child: InkWell(
              onTap: () => _open(r),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                child: Row(
                  children: [
                    cell(0, t(r['date'])),
                    cell(1, t(r['place'] ?? 'Whole organization')),
                    cell(2, t(r['source'])),
                    cell(3, t(r['area'])),
                    cell(4, t(r['calls'], end: true)),
                    cell(5, t(r['jobs'], end: true)),
                    cell(6, t(_money(r['cost_cents'] as int?), end: true)),
                    cell(7, t(_money(r['sales_cents'] as int?), end: true)),
                    cell(8, t(_money(r['profit_cents'] as int?), end: true)),
                    cell(
                      9,
                      Wrap(spacing: 4, runSpacing: 4, children: _chips(r)),
                    ),
                    cell(
                      10,
                      Wrap(
                        alignment: WrapAlignment.end,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: _actions(r),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const Divider(height: 1),
        ],
      ],
    );
  }

  Widget _card(Map r) {
    final theme = Theme.of(context);
    return Card(
      key: ValueKey('result-row-${r['id']}'),
      color: _tint(r),
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        onTap: () => _open(r),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${r['source']} · ${r['date']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  ..._chips(r).expand((c) => [const SizedBox(width: 4), c]),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                [
                  r['place'] ?? 'Whole organization',
                  r['area'],
                ].whereType<Object>().join(' · '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 4),
              Text(
                '${r['calls']} calls · ${r['jobs']} jobs',
                style: theme.textTheme.bodySmall,
              ),
              Text(
                'Cost ${_money(r['cost_cents'] as int?)} · Sales ${_money(r['sales_cents'] as int?)} · Profit ${_money(r['profit_cents'] as int?)}',
                style: theme.textTheme.bodySmall,
              ),
              Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                children: _actions(r),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rows = _matching;
    return LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < 900;
        return ListView(
          key: const ValueKey('tour-financials-results'),
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              'Lead source results',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            const Text(
              'Calls, jobs, cost and sales per date and area. A row counts in reports once it is approved.',
            ),
            const SizedBox(height: 12),
            KeyedSubtree(
              key: const ValueKey('tour-financials-results-toolbar'),
              child: _filters(narrow),
            ),
            const SizedBox(height: 12),
            if (_error != null) Text(_error!),
            if (_rows != null && rows.isEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 24),
                child: Text(
                  _q.isEmpty ? 'No results yet.' : 'No result matches "$_q".',
                ),
              )
            else if (narrow)
              for (final r in rows) _card(r)
            else
              _table(rows),
          ],
        );
      },
    );
  }
}

/// One row's figures, as text fields.
class _Figures {
  _Figures([Map row = const {}])
    : date = TextEditingController(text: row['date'] as String? ?? ''),
      area = TextEditingController(text: row['area'] as String? ?? ''),
      calls = TextEditingController(text: '${row['calls'] ?? ''}'),
      jobs = TextEditingController(text: '${row['jobs'] ?? ''}'),
      cost = TextEditingController(text: _dollars(row['cost_cents'])),
      sales = TextEditingController(text: _dollars(row['sales_cents'])),
      profit = TextEditingController(text: _dollars(row['profit_cents']));

  final TextEditingController date, area, calls, jobs, cost, sales, profit;

  Map<String, Object?> body() => {
    'date': date.text.trim(),
    'area': area.text.trim().isEmpty ? null : area.text.trim(),
    'calls': int.tryParse(calls.text.trim()) ?? 0,
    'jobs': int.tryParse(jobs.text.trim()) ?? 0,
    'cost_cents': _cents(cost.text) ?? 0,
    'sales_cents': _cents(sales.text) ?? 0,
    if (_cents(profit.text) != null) 'profit_cents': _cents(profit.text),
  };

  void dispose() {
    for (final c in [date, area, calls, jobs, cost, sales, profit]) {
      c.dispose();
    }
  }
}

Widget _field(
  TextEditingController c,
  String label, {
  Key? key,
  bool money = false,
  bool number = false,
  bool enabled = true,
}) => TextField(
  key: key,
  controller: c,
  enabled: enabled,
  keyboardType: money || number
      ? TextInputType.numberWithOptions(decimal: money)
      : null,
  decoration: InputDecoration(
    isDense: true,
    labelText: label,
    prefixText: money ? r'$ ' : null,
  ),
);

/// Company · Branch, as every place picker in the app offers it.
Widget _placePicker(Me me, String? value, ValueChanged<String?> onChanged) =>
    SearchPickerField<String>(
      key: const ValueKey('result-place'),
      label: 'Company · Branch',
      hint: 'Whole organization',
      allowClear: true,
      value: value,
      options: [
        for (final c in me.companiesInView)
          SearchOption(value: c.id, label: c.name),
        for (final b in branchOptionsInView(me))
          SearchOption(value: b.id, label: b.label),
      ],
      onChanged: onChanged,
    );

Map<String, Object?> _placeBody(Me me, String? place) {
  final franchise = me.franchisesInView.where((f) => f.id == place).firstOrNull;
  return {
    'franchise_id': franchise?.id,
    'company_id': franchise?.companyId ?? (franchise == null ? place : null),
  };
}

class _ResultPanel extends StatefulWidget {
  const _ResultPanel({
    required this.api,
    required this.me,
    required this.row,
    required this.canEdit,
    required this.canApprove,
    required this.canDelete,
  });

  final ApiClient api;
  final Me me;
  final Map? row;
  final bool canEdit, canApprove, canDelete;

  @override
  State<_ResultPanel> createState() => _ResultPanelState();
}

class _ResultPanelState extends State<_ResultPanel> {
  late final _f = _Figures(widget.row ?? {'date': _day(DateTime.now())});
  late final _source = TextEditingController(
    text: widget.row?['source'] as String? ?? '',
  );
  late String? _place = widget.row == null
      ? widget.me.filingFranchiseId ?? widget.me.filingCompanyId
      : widget.row!['franchise_id'] as String? ??
            widget.row!['company_id'] as String?;
  List<int>? _image;
  String? _error;
  bool _busy = false;
  bool _confirm = false;

  Map? get _row => widget.row;

  @override
  void initState() {
    super.initState();
    if (_row?['has_image'] == true) {
      widget.api.getBytes('/financials/results/${_row!['id']}/image').then((b) {
        if (mounted) setState(() => _image = b);
      });
    }
  }

  @override
  void dispose() {
    _f.dispose();
    _source.dispose();
    super.dispose();
  }

  Future<void> _done(ApiResponse r) async {
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  Future<void> _save() async {
    if (_source.text.trim().isEmpty) {
      return setState(() => _error = 'Type the lead source.');
    }
    setState(() => _busy = true);
    final body = {'source': _source.text.trim(), ..._f.body()};
    await _done(
      _row == null
          ? await widget.api.post('/financials/results', {
              ..._placeBody(widget.me, _place),
              ...body,
            })
          : await widget.api.patch('/financials/results/${_row!['id']}', body),
    );
  }

  Future<void> _approve() async {
    setState(() => _busy = true);
    await _done(
      await widget.api.post(
        '/financials/results/${_row!['id']}/approve',
        const {},
      ),
    );
  }

  Future<void> _delete() async {
    if (!_confirm) return setState(() => _confirm = true);
    setState(() => _busy = true);
    await _done(await widget.api.delete('/financials/results/${_row!['id']}'));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pending = _row != null && _row!['status'] != 'approved';
    final on = widget.canEdit;
    return SidePanel(
      title: _row == null ? 'New result' : '${_row!['source']}',
      subtitle: _row == null
          ? null
          : '${_row!['date']} · ${pending ? 'Pending' : 'Approved'}',
      actions: [
        if (_row != null && widget.canDelete)
          TextButton(
            key: const Key('result-panel-delete'),
            onPressed: _busy ? null : _delete,
            child: Text(_confirm ? 'Press again to delete' : 'Delete'),
          ),
        if (pending && widget.canApprove)
          OutlinedButton(
            key: const Key('result-panel-approve'),
            onPressed: _busy ? null : _approve,
            child: const Text('Approve'),
          ),
        if (on)
          FilledButton(
            key: const Key('result-panel-save'),
            onPressed: _busy ? null : _save,
            child: const Text('Save'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          if (_row == null)
            _placePicker(widget.me, _place, (v) => setState(() => _place = v))
          else
            Text('${_row!['place'] ?? 'Whole organization'}'),
          const SizedBox(height: 8),
          _field(
            _source,
            'Lead source',
            key: const Key('result-source'),
            enabled: on,
          ),
          const SizedBox(height: 8),
          _field(
            _f.date,
            'Date (YYYY-MM-DD)',
            key: const Key('result-date'),
            enabled: on,
          ),
          const SizedBox(height: 8),
          _field(_f.area, 'Area', key: const Key('result-area'), enabled: on),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _field(
                  _f.calls,
                  'Calls',
                  key: const Key('result-calls'),
                  number: true,
                  enabled: on,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _field(
                  _f.jobs,
                  'Jobs',
                  key: const Key('result-jobs'),
                  number: true,
                  enabled: on,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _field(
            _f.cost,
            'Cost',
            key: const Key('result-cost'),
            money: true,
            enabled: on,
          ),
          const SizedBox(height: 8),
          _field(
            _f.sales,
            'Sales',
            key: const Key('result-sales'),
            money: true,
            enabled: on,
          ),
          const SizedBox(height: 8),
          _field(
            _f.profit,
            'Profit (blank: sales less cost)',
            key: const Key('result-profit'),
            money: true,
            enabled: on,
          ),
          if (_image != null)
            Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Image.memory(
                Uint8List.fromList(_image!),
                key: const Key('result-image'),
              ),
            ),
        ],
      ),
    );
  }
}

/// What a photo was read into: one editable line per row, the photo beside
/// them. Each row is saved on its own with the photo's token.
class _ReviewPanel extends StatefulWidget {
  const _ReviewPanel({
    required this.api,
    required this.me,
    required this.read,
    required this.image,
  });

  final ApiClient api;
  final Me me;
  final Map read;
  final List<int> image;

  @override
  State<_ReviewPanel> createState() => _ReviewPanelState();
}

class _ReviewPanelState extends State<_ReviewPanel> {
  late final _source = TextEditingController(
    text: widget.read['source'] as String? ?? '',
  );
  late final List<_Figures> _rows = [
    for (final r in (widget.read['rows'] as List? ?? const []))
      _Figures(r as Map),
  ];
  late String? _place =
      widget.me.filingFranchiseId ?? widget.me.filingCompanyId;
  String? _error;
  bool _busy = false;
  bool _savedAny = false;

  @override
  void dispose() {
    _source.dispose();
    for (final r in _rows) {
      r.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_source.text.trim().isEmpty) {
      return setState(() => _error = 'Type the lead source.');
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    for (final row in [..._rows]) {
      final r = await widget.api.post('/financials/results', {
        ..._placeBody(widget.me, _place),
        'source': _source.text.trim(),
        ...row.body(),
        'image_token': widget.read['image_token'],
      });
      if (!mounted) return;
      if (!r.ok) {
        return setState(() {
          _busy = false;
          _error = r.message;
        });
      }
      // Saved rows leave the list, so a retry sends only what is left.
      setState(() {
        _rows.remove(row);
        _savedAny = true;
      });
      row.dispose();
    }
    if (mounted) Navigator.of(context).pop(true);
  }

  Widget _line(int i) {
    final f = _rows[i];
    Widget w(double width, Widget child) =>
        SizedBox(width: width, child: child);
    return Padding(
      key: ValueKey('review-row-$i'),
      padding: const EdgeInsets.only(bottom: 8),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          w(120, _field(f.date, 'Date', key: ValueKey('review-date-$i'))),
          w(120, _field(f.area, 'Area', key: ValueKey('review-area-$i'))),
          w(
            70,
            _field(
              f.calls,
              'Calls',
              key: ValueKey('review-calls-$i'),
              number: true,
            ),
          ),
          w(
            70,
            _field(
              f.jobs,
              'Jobs',
              key: ValueKey('review-jobs-$i'),
              number: true,
            ),
          ),
          w(
            110,
            _field(
              f.cost,
              'Cost',
              key: ValueKey('review-cost-$i'),
              money: true,
            ),
          ),
          w(
            110,
            _field(
              f.sales,
              'Sales',
              key: ValueKey('review-sales-$i'),
              money: true,
            ),
          ),
          w(
            110,
            _field(
              f.profit,
              'Profit',
              key: ValueKey('review-profit-$i'),
              money: true,
            ),
          ),
          IconButton(
            key: ValueKey('review-remove-$i'),
            tooltip: 'Remove row',
            icon: const Icon(Icons.close),
            onPressed: () => setState(() => _rows.removeAt(i).dispose()),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final image = Image.memory(
      Uint8List.fromList(widget.image),
      key: const Key('review-image'),
      fit: BoxFit.contain,
    );
    final form = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_error != null)
          Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
        _placePicker(widget.me, _place, (v) => setState(() => _place = v)),
        const SizedBox(height: 8),
        _field(_source, 'Lead source', key: const Key('review-source')),
        const SizedBox(height: 12),
        if (_rows.isEmpty) const Text('No rows left to save.'),
        for (var i = 0; i < _rows.length; i++) _line(i),
      ],
    );
    return SidePanel(
      title: 'Check the results',
      subtitle: 'Read from the photo. Fix or remove rows, then save them.',
      width: 1100,
      actions: [
        FilledButton(
          key: const Key('review-save'),
          onPressed: _busy || _rows.isEmpty ? null : _save,
          child: Text(
            _rows.length == 1 ? 'Save 1 row' : 'Save ${_rows.length} rows',
          ),
        ),
        if (_savedAny)
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Done'),
          ),
      ],
      child: LayoutBuilder(
        builder: (context, box) => box.maxWidth < 800
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [image, const SizedBox(height: 12), form],
              )
            : Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 3, child: form),
                  const SizedBox(width: 16),
                  Expanded(flex: 2, child: image),
                ],
              ),
      ),
    );
  }
}
