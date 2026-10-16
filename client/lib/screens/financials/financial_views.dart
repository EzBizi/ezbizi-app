import 'package:flutter/material.dart';

import '../../core/api_client.dart';

import '../../core/theme.dart';
import '../../widgets/month_calendar.dart';
import 'financial_cells.dart';
import 'financial_panels.dart';

typedef OpenRecord = void Function(Map? record, {Map initial});
typedef PatchCell =
    Future<void> Function(Map record, String fieldId, dynamic value);

/// The spreadsheet: one row per record, a footer that totals the numbers,
/// optional grouping. Cells open the record; checkboxes toggle in place.
class GridView2 extends StatelessWidget {
  const GridView2({
    super.key,
    required this.fields,
    required this.allFields,
    required this.records,
    required this.config,
    required this.onOpen,
    required this.onPatch,
    required this.onField,
    required this.canEdit,
  });

  final List<Map> fields;
  final List<Map> allFields;
  final List<Map> records;
  final Map config;
  final OpenRecord onOpen;
  final PatchCell onPatch;
  final void Function(Map? field) onField;
  final bool canEdit;

  static const double _numberWidth = 56;

  double _width(Map f) => f['is_primary'] == true
      ? 220
      : switch (f['type']) {
          'checkbox' || 'rating' => 110,
          'long_text' || 'multi_select' => 240,
          _ => 160,
        };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edge = EzBiziTheme.edge(context);
    final groupField = allFields
        .where((f) => f['id'] == config['group_field'])
        .firstOrNull;

    Widget cell(Widget child, double width, {bool header = false}) => Container(
      width: width,
      height: header ? 40 : 36,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      alignment: Alignment.centerLeft,
      decoration: BoxDecoration(
        border: Border(
          right: BorderSide(color: edge),
          bottom: BorderSide(color: edge),
        ),
        color: header ? theme.colorScheme.surfaceContainerHighest : null,
      ),
      child: child,
    );

    Widget row(Map r, int i) => InkWell(
      onTap: () => onOpen(r),
      child: Row(
        children: [
          cell(
            Text('${i + 1}', style: theme.textTheme.bodySmall),
            _numberWidth,
          ),
          for (final f in fields)
            cell(
              f['type'] == 'checkbox' && canEdit
                  ? Checkbox(
                      value: (r['values'] as Map)[f['id']] == true,
                      onChanged: (v) =>
                          onPatch(r, '${f['id']}', v == true ? true : null),
                    )
                  : CellView(
                      field: f,
                      value: cellValue(f, r['values'] as Map, allFields),
                    ),
              _width(f),
            ),
        ],
      ),
    );

    Widget footer(List<Map> rows) => Row(
      children: [
        cell(
          Text('${rows.length}', style: theme.textTheme.labelSmall),
          _numberWidth,
        ),
        for (final f in fields)
          cell(
            Builder(
              builder: (_) {
                if (!(isNumeric('${f['type']}') || f['type'] == 'formula') ||
                    f['type'] == 'rating') {
                  return const SizedBox.shrink();
                }
                num sum = 0;
                var any = false;
                for (final r in rows) {
                  final v = cellValue(f, r['values'] as Map, allFields);
                  if (v is num) {
                    sum += v;
                    any = true;
                  }
                }
                return any
                    ? Text(
                        'Sum ${formatCell(f['type'] == 'formula' ? {'type': 'formula'} : f, f['type'] == 'formula' ? sum.toDouble() : sum)}',
                        style: theme.textTheme.labelSmall,
                        textAlign: TextAlign.right,
                      )
                    : const SizedBox.shrink();
              },
            ),
            _width(f),
          ),
      ],
    );

    final body = <Widget>[];
    if (groupField == null) {
      for (var i = 0; i < records.length; i++) {
        body.add(row(records[i], i));
      }
    } else {
      final groups = <String, List<Map>>{};
      for (final r in records) {
        final v = formatCell(
          groupField,
          cellValue(groupField, r['values'] as Map, allFields),
        );
        groups.putIfAbsent(v, () => []).add(r);
      }
      for (final g in groups.entries) {
        body.add(
          Container(
            padding: const EdgeInsets.fromLTRB(12, 14, 12, 6),
            child: Text(
              '${groupField['name']}: ${g.key.isEmpty ? '(empty)' : g.key}  ·  ${g.value.length}',
              style: theme.textTheme.titleSmall,
            ),
          ),
        );
        for (var i = 0; i < g.value.length; i++) {
          body.add(row(g.value[i], i));
        }
        body.add(footer(g.value));
      }
    }

    return Scrollbar(
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: SizedBox(
          width:
              _numberWidth +
              fields.fold<double>(0, (w, f) => w + _width(f)) +
              48,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  cell(const SizedBox.shrink(), _numberWidth, header: true),
                  for (final f in fields)
                    InkWell(
                      onTap: canEdit ? () => onField(f) : null,
                      child: cell(
                        Row(
                          children: [
                            Icon(fieldTypeIcons[f['type']], size: 16),
                            const SizedBox(width: 6),
                            Expanded(
                              child: Text(
                                '${f['name']}',
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.labelLarge,
                              ),
                            ),
                          ],
                        ),
                        _width(f),
                        header: true,
                      ),
                    ),
                  if (canEdit)
                    IconButton(
                      tooltip: 'Add field',
                      icon: const Icon(Icons.add),
                      onPressed: () => onField(null),
                    ),
                ],
              ),
              Expanded(
                child: ListView(
                  children: [
                    ...body,
                    if (canEdit)
                      InkWell(
                        onTap: () => onOpen(null),
                        child: cell(
                          const Row(
                            children: [
                              Icon(Icons.add, size: 16),
                              SizedBox(width: 6),
                              Text('Add record'),
                            ],
                          ),
                          _numberWidth +
                              fields.fold<double>(0, (w, f) => w + _width(f)),
                        ),
                      ),
                    if (groupField == null) footer(records),
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

/// Cards stacked by a single-select field; drag a card to change it.
class KanbanView extends StatelessWidget {
  const KanbanView({
    super.key,
    required this.fields,
    required this.allFields,
    required this.records,
    required this.config,
    required this.onOpen,
    required this.onPatch,
    required this.canEdit,
  });

  final List<Map> fields;
  final List<Map> allFields;
  final List<Map> records;
  final Map config;
  final OpenRecord onOpen;
  final PatchCell onPatch;
  final bool canEdit;

  @override
  Widget build(BuildContext context) {
    final stack = allFields
        .where(
          (f) =>
              f['id'] == config['group_field'] && f['type'] == 'single_select',
        )
        .firstOrNull;
    if (stack == null) {
      return const Center(
        child: Text(
          'Choose a single-select field to stack by in View settings.',
        ),
      );
    }
    final columns = ['', ...choicesOf(stack)];
    final theme = Theme.of(context);

    return ListView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.all(12),
      children: [
        for (final c in columns)
          DragTarget<Map>(
            onAcceptWithDetails: (d) =>
                onPatch(d.data, '${stack['id']}', c.isEmpty ? null : c),
            builder: (context, hovering, _) {
              final cards = records
                  .where(
                    (r) => '${(r['values'] as Map)[stack['id']] ?? ''}' == c,
                  )
                  .toList();
              return Container(
                width: 280,
                margin: const EdgeInsets.only(right: 12),
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: hovering.isNotEmpty
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(
                    EzBiziTheme.controlRadius,
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        c.isEmpty
                            ? Text(
                                'Uncategorized',
                                style: theme.textTheme.titleSmall,
                              )
                            : ChoicePill(field: stack, choice: c),
                        const SizedBox(width: 8),
                        Text(
                          '${cards.length}',
                          style: theme.textTheme.labelSmall,
                        ),
                        const Spacer(),
                        if (canEdit)
                          IconButton(
                            tooltip: 'Add a card here',
                            icon: const Icon(Icons.add, size: 18),
                            onPressed: () => onOpen(
                              null,
                              initial: {if (c.isNotEmpty) stack['id']: c},
                            ),
                          ),
                      ],
                    ),
                    Expanded(
                      child: ListView(
                        children: [
                          for (final r in cards)
                            canEdit
                                ? Draggable<Map>(
                                    data: r,
                                    feedback: SizedBox(
                                      width: 264,
                                      child: _RecordCard(
                                        record: r,
                                        fields: fields,
                                        allFields: allFields,
                                      ),
                                    ),
                                    childWhenDragging: Opacity(
                                      opacity: 0.3,
                                      child: _RecordCard(
                                        record: r,
                                        fields: fields,
                                        allFields: allFields,
                                      ),
                                    ),
                                    child: _RecordCard(
                                      record: r,
                                      fields: fields,
                                      allFields: allFields,
                                      onTap: () => onOpen(r),
                                    ),
                                  )
                                : _RecordCard(
                                    record: r,
                                    fields: fields,
                                    allFields: allFields,
                                    onTap: () => onOpen(r),
                                  ),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
      ],
    );
  }
}

class _RecordCard extends StatelessWidget {
  const _RecordCard({
    required this.record,
    required this.fields,
    required this.allFields,
    this.onTap,
  });

  final Map record;
  final List<Map> fields;
  final List<Map> allFields;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final values = record['values'] as Map;
    final primary = fields.firstWhere(
      (f) => f['is_primary'] == true,
      orElse: () => fields.first,
    );
    return Card(
      margin: const EdgeInsets.only(top: 8),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${values[primary['id']] ?? 'Unnamed record'}',
                style: theme.textTheme.titleSmall,
              ),
              for (final f in fields.take(6))
                if (f != primary && cellValue(f, values, allFields) != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 90,
                          child: Text(
                            '${f['name']}',
                            style: theme.textTheme.labelSmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        Expanded(
                          child: Align(
                            alignment: Alignment.centerLeft,
                            child: CellView(
                              field: f,
                              value: cellValue(f, values, allFields),
                            ),
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

class GalleryView extends StatelessWidget {
  const GalleryView({
    super.key,
    required this.fields,
    required this.allFields,
    required this.records,
    required this.onOpen,
  });

  final List<Map> fields;
  final List<Map> allFields;
  final List<Map> records;
  final OpenRecord onOpen;

  @override
  Widget build(BuildContext context) {
    return GridView.extent(
      maxCrossAxisExtent: 300,
      padding: const EdgeInsets.all(12),
      crossAxisSpacing: 12,
      childAspectRatio: 1.3,
      children: [
        for (final r in records)
          _RecordCard(
            record: r,
            fields: fields,
            allFields: allFields,
            onTap: () => onOpen(r),
          ),
      ],
    );
  }
}

class CalendarView extends StatelessWidget {
  const CalendarView({
    super.key,
    required this.fields,
    required this.allFields,
    required this.records,
    required this.config,
    required this.onOpen,
  });

  final List<Map> fields;
  final List<Map> allFields;
  final List<Map> records;
  final Map config;
  final OpenRecord onOpen;

  @override
  Widget build(BuildContext context) {
    final date = allFields
        .where((f) => f['id'] == config['date_field'] && f['type'] == 'date')
        .firstOrNull;
    if (date == null) {
      return const Center(child: Text('Choose a date field in View settings.'));
    }
    final primary = allFields.firstWhere(
      (f) => f['is_primary'] == true,
      orElse: () => allFields.first,
    );
    return SingleChildScrollView(
      padding: const EdgeInsets.all(12),
      child: MonthCalendar(
        title: '${date['name']}',
        emptyNote: 'Nothing dated this month.',
        entries: [
          for (final r in records)
            if (DateTime.tryParse('${(r['values'] as Map)[date['id']] ?? ''}')
                case final day?)
              CalendarEntry(
                day: day,
                label:
                    '${(r['values'] as Map)[primary['id']] ?? 'Unnamed record'}',
                onTap: () => onOpen(r),
              ),
        ],
      ),
    );
  }
}

/// A form that adds one record per submit — Airtable's form view, for staff.
class FormView extends StatefulWidget {
  const FormView({
    super.key,
    required this.fields,
    required this.allFields,
    required this.onSubmit,
    required this.canEdit,
  });

  final List<Map> fields;
  final List<Map> allFields;
  final Future<String?> Function(Map<String, dynamic> values) onSubmit;
  final bool canEdit;

  @override
  State<FormView> createState() => _FormViewState();
}

class _FormViewState extends State<FormView> {
  Map<String, dynamic> _values = {};
  int _round = 0;
  String? _note;

  @override
  Widget build(BuildContext context) {
    if (!widget.canEdit) {
      return const Center(
        child: Text('Your role can read this table but not add to it.'),
      );
    }
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560),
        child: ListView(
          key: ValueKey(_round),
          padding: const EdgeInsets.all(16),
          children: [
            for (final f in widget.fields)
              if (f['type'] != 'formula')
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: CellEditor(
                    field: f,
                    value: _values[f['id']],
                    onChanged: (v) => setState(() => _values['${f['id']}'] = v),
                  ),
                ),
            if (_note != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(_note!),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton(
                onPressed: () async {
                  final error = await widget.onSubmit(
                    {..._values}..removeWhere((k, v) => v == null),
                  );
                  if (!mounted) return;
                  setState(() {
                    _note =
                        error ?? 'Added. The form is ready for the next one.';
                    if (error == null) {
                      _values = {};
                      _round++;
                    }
                  });
                },
                child: const Text('Submit'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A split view: each group's cost shared across its people by job count,
/// worked out on the server to the cent.
class SplitView extends StatefulWidget {
  const SplitView({super.key, required this.api, required this.view});

  final ApiClient api;
  final Map view;

  @override
  State<SplitView> createState() => _SplitViewState();
}

class _SplitViewState extends State<SplitView> {
  String _range = 'this_week';
  List<Map>? _groups;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(SplitView old) {
    super.didUpdateWidget(old);
    if (old.view != widget.view) _load();
  }

  (String, String)? get _period {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    String d(DateTime t) => t.toIso8601String().substring(0, 10);
    final monday = today.subtract(Duration(days: today.weekday - 1));
    return switch (_range) {
      'this_week' => (d(monday), d(monday.add(const Duration(days: 6)))),
      'last_week' => (
        d(monday.subtract(const Duration(days: 7))),
        d(monday.subtract(const Duration(days: 1))),
      ),
      'this_month' => (
        d(DateTime(now.year, now.month)),
        d(DateTime(now.year, now.month + 1, 0)),
      ),
      'last_month' => (
        d(DateTime(now.year, now.month - 1)),
        d(DateTime(now.year, now.month, 0)),
      ),
      _ => null,
    };
  }

  Future<void> _load() async {
    final p = _period;
    final r = await widget.api.get(
      '/financials/views/${widget.view['id']}/split',
      {if (p != null) 'from': p.$1, if (p != null) 'to': p.$2},
    );
    if (!mounted) return;
    setState(() {
      _groups = r.ok ? (r.body['data'] as List).cast<Map>() : null;
      _error = r.ok ? null : r.message;
    });
  }

  String _money(int cents) =>
      formatCell(const {'type': 'currency', 'options': {}}, cents / 100);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    const ranges = {
      'this_week': 'This week',
      'last_week': 'Last week',
      'this_month': 'This month',
      'last_month': 'Last month',
      'all': 'All dates',
    };
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        Wrap(
          spacing: 6,
          children: [
            for (final e in ranges.entries)
              ChoiceChip(
                label: Text(e.value),
                selected: _range == e.key,
                onSelected: (_) {
                  setState(() => _range = e.key);
                  _load();
                },
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) Text(_error!),
        if (_groups case final groups? when groups.isEmpty)
          const Text('Nothing to split in this period.'),
        for (final g in _groups ?? const <Map>[])
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${g['group']}',
                          style: theme.textTheme.titleSmall,
                        ),
                      ),
                      Text(
                        g['status'] == 'unavailable'
                            ? 'Unavailable'
                            : '${g['jobs']} jobs · ${_money(g['total_cents'] as int)}',
                      ),
                    ],
                  ),
                  if (g['reason'] != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        '${g['reason']}',
                        style: TextStyle(color: theme.colorScheme.error),
                      ),
                    ),
                  for (final p in (g['people'] as List).cast<Map>())
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Row(
                        children: [
                          Expanded(child: Text('${p['person']}')),
                          SizedBox(width: 80, child: Text('${p['jobs']} jobs')),
                          SizedBox(
                            width: 110,
                            child: Text(
                              _money(p['cents'] as int),
                              textAlign: TextAlign.right,
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
