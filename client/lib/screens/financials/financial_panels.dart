import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'financial_cells.dart';

/// One input for one field, by type. Used by the record panel and the form view.
class CellEditor extends StatelessWidget {
  const CellEditor({
    super.key,
    required this.field,
    required this.value,
    required this.onChanged,
  });

  final Map field;
  final dynamic value;
  final ValueChanged<dynamic> onChanged;

  @override
  Widget build(BuildContext context) {
    final name = '${field['name']}';
    final type = '${field['type']}';
    switch (type) {
      case 'checkbox':
        return CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(name),
          value: value == true,
          onChanged: (v) => onChanged(v == true ? true : null),
        );
      case 'single_select':
        return SearchPickerField<String>(
          label: name,
          value: value as String?,
          allowClear: true,
          options: [
            for (final c in choicesOf(field)) SearchOption(value: c, label: c),
          ],
          onChanged: onChanged,
        );
      case 'multi_select':
        final picked = {...(value as List? ?? const []).map((e) => '$e')};
        return InputDecorator(
          decoration: InputDecoration(labelText: name),
          child: Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final c in choicesOf(field))
                FilterChip(
                  label: Text(c),
                  selected: picked.contains(c),
                  onSelected: (on) {
                    final next = {...picked};
                    on ? next.add(c) : next.remove(c);
                    onChanged(next.isEmpty ? null : next.toList());
                  },
                ),
            ],
          ),
        );
      case 'rating':
        final n = (value as num?)?.toInt() ?? 0;
        return InputDecorator(
          decoration: InputDecoration(labelText: name),
          child: Row(
            children: [
              for (var i = 1; i <= 5; i++)
                IconButton(
                  tooltip: '$i',
                  icon: Icon(i <= n ? Icons.star : Icons.star_border),
                  onPressed: () => onChanged(i == n ? null : i),
                ),
            ],
          ),
        );
      case 'date':
        return InkWell(
          onTap: () async {
            final now = DateTime.tryParse('${value ?? ''}') ?? DateTime.now();
            final picked = await showDatePicker(
              context: context,
              initialDate: now,
              firstDate: DateTime(1900),
              lastDate: DateTime(2200),
            );
            if (picked != null) {
              onChanged(picked.toIso8601String().substring(0, 10));
            }
          },
          child: InputDecorator(
            decoration: InputDecoration(
              labelText: name,
              suffixIcon: value == null
                  ? const Icon(Icons.event_outlined)
                  : IconButton(
                      tooltip: 'Clear',
                      icon: const Icon(Icons.close),
                      onPressed: () => onChanged(null),
                    ),
            ),
            child: Text(value == null ? '' : '$value'),
          ),
        );
      case 'formula':
        return InputDecorator(
          decoration: InputDecoration(labelText: '$name (formula)'),
          child: Text(formatCell(field, value)),
        );
      default:
        return TextFormField(
          key: ValueKey('${field['id']}'),
          initialValue: value == null ? '' : '$value',
          minLines: type == 'long_text' ? 3 : 1,
          maxLines: type == 'long_text' ? 8 : 1,
          keyboardType: isNumeric(type)
              ? const TextInputType.numberWithOptions(
                  decimal: true,
                  signed: true,
                )
              : TextInputType.text,
          decoration: InputDecoration(
            labelText: name,
            prefixText: type == 'currency'
                ? '${field['options']?['symbol'] ?? r'$'} '
                : null,
            suffixText: type == 'percent' ? '%' : null,
          ),
          onChanged: (t) => onChanged(t.trim().isEmpty ? null : t.trim()),
        );
    }
  }
}

/// Expand a record: every field, saved together.
class RecordPanel extends StatefulWidget {
  const RecordPanel({
    super.key,
    required this.api,
    required this.tableId,
    required this.fields,
    this.record,
    this.canEdit = true,
    this.canDelete = false,
    this.initial = const {},
  });

  final ApiClient api;
  final String tableId;
  final List<Map> fields;
  final Map? record;
  final bool canEdit;
  final bool canDelete;
  final Map initial;

  @override
  State<RecordPanel> createState() => _RecordPanelState();
}

class _RecordPanelState extends State<RecordPanel> {
  late final Map<String, dynamic> _values = {
    ...widget.initial.map((k, v) => MapEntry('$k', v)),
    ...?(widget.record?['values'] as Map?)?.map((k, v) => MapEntry('$k', v)),
  };
  final Map<String, dynamic> _changed = {};
  String? _error;
  bool _busy = false;

  Future<void> _save() async {
    setState(() => _busy = true);
    final r = widget.record == null
        ? await widget.api.post(
            '/financials/tables/${widget.tableId}/records',
            {'values': _values..removeWhere((k, v) => v == null)},
          )
        : await widget.api.patch(
            '/financials/records/${widget.record!['id']}',
            {'values': _changed},
          );
    if (!mounted) return;
    setState(() => _busy = false);
    if (r.ok) {
      Navigator.of(context).pop(true);
    } else {
      setState(() => _error = r.message);
    }
  }

  Future<void> _delete() async {
    setState(() => _busy = true);
    final r = await widget.api.delete(
      '/financials/records/${widget.record!['id']}',
    );
    if (!mounted) return;
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    final primary = widget.fields.firstWhere(
      (f) => f['is_primary'] == true,
      orElse: () => widget.fields.first,
    );
    final title = '${_values[primary['id']] ?? ''}';
    return SidePanel(
      title: widget.record == null
          ? 'New record'
          : (title.isEmpty ? 'Unnamed record' : title),
      actions: [
        if (widget.record != null && widget.canDelete)
          TextButton(
            style: TextButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: _busy ? null : _delete,
            child: const Text('Delete'),
          ),
        if (widget.canEdit)
          FilledButton(
            onPressed: _busy ? null : _save,
            child: Text(widget.record == null ? 'Add record' : 'Save'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          for (final f in widget.fields)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: IgnorePointer(
                ignoring: !widget.canEdit,
                child: CellEditor(
                  field: f,
                  value: f['type'] == 'formula'
                      ? cellValue(f, _values, widget.fields)
                      : _values[f['id']],
                  onChanged: (v) => setState(() {
                    _values['${f['id']}'] = v;
                    _changed['${f['id']}'] = v;
                  }),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Add or change a column.
class FieldPanel extends StatefulWidget {
  const FieldPanel({
    super.key,
    required this.api,
    required this.tableId,
    this.field,
    this.canDelete = false,
  });

  final ApiClient api;
  final String tableId;
  final Map? field;
  final bool canDelete;

  @override
  State<FieldPanel> createState() => _FieldPanelState();
}

class _FieldPanelState extends State<FieldPanel> {
  late final _name = TextEditingController(
    text: widget.field?['name'] as String?,
  );
  late final _choices = TextEditingController(
    text: widget.field == null ? '' : choicesOf(widget.field!).join('\n'),
  );
  late final _formula = TextEditingController(
    text: widget.field?['options']?['formula'] as String?,
  );
  late final _symbol = TextEditingController(
    text: (widget.field?['options']?['symbol'] as String?) ?? r'$',
  );
  late String _type = (widget.field?['type'] as String?) ?? 'text';
  String? _error;
  bool _busy = false;

  bool get _primary => widget.field?['is_primary'] == true;

  Future<void> _save() async {
    final options = <String, dynamic>{
      if (_type == 'single_select' || _type == 'multi_select')
        'choices': [
          for (final c in _choices.text.split('\n'))
            if (c.trim().isNotEmpty) {'name': c.trim()},
        ],
      if (_type == 'formula') 'formula': _formula.text.trim(),
      if (_type == 'currency') ...{
        'symbol': _symbol.text.trim(),
        'precision': 2,
      },
    };
    final body = {'name': _name.text.trim(), 'type': _type, 'options': options};
    setState(() => _busy = true);
    final r = widget.field == null
        ? await widget.api.post(
            '/financials/tables/${widget.tableId}/fields',
            body,
          )
        : await widget.api.patch(
            '/financials/fields/${widget.field!['id']}',
            body,
          );
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  Future<void> _delete() async {
    final r = await widget.api.delete(
      '/financials/fields/${widget.field!['id']}',
    );
    if (!mounted) return;
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.field == null ? 'New field' : 'Edit field',
      actions: [
        if (widget.field != null && widget.canDelete && !_primary)
          TextButton(
            onPressed: _busy ? null : _delete,
            child: const Text('Delete field'),
          ),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
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
            decoration: const InputDecoration(labelText: 'Field name'),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            label: 'Field type',
            value: _type,
            options: [
              for (final e in fieldTypeLabels.entries)
                if (!(_primary && e.key == 'formula'))
                  SearchOption(value: e.key, label: e.value),
            ],
            onChanged: (v) => setState(() => _type = v ?? _type),
          ),
          if (widget.field != null && widget.field!['type'] != _type)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(
                'Changing the type converts what it can and clears cells that do not fit.',
              ),
            ),
          const SizedBox(height: 12),
          if (_type == 'single_select' || _type == 'multi_select')
            TextField(
              controller: _choices,
              minLines: 3,
              maxLines: 12,
              decoration: const InputDecoration(
                labelText: 'Choices',
                helperText: 'One per line.',
              ),
            ),
          if (_type == 'formula')
            TextField(
              controller: _formula,
              decoration: const InputDecoration(
                labelText: 'Formula',
                helperText:
                    'For example {Budgeted} - {Actual}, or {First} & " " & {Last}.',
              ),
            ),
          if (_type == 'currency')
            TextField(
              controller: _symbol,
              decoration: const InputDecoration(labelText: 'Currency symbol'),
            ),
        ],
      ),
    );
  }
}

/// Filter, sort, group and hide — the settings a view saves.
class ViewSettingsPanel extends StatefulWidget {
  const ViewSettingsPanel({
    super.key,
    required this.view,
    required this.fields,
  });

  final Map view;
  final List<Map> fields;

  @override
  State<ViewSettingsPanel> createState() => _ViewSettingsPanelState();
}

const filterOps = <String, String>{
  'contains': 'contains',
  'is': 'is',
  'is_not': 'is not',
  'gt': '>',
  'lt': '<',
  'empty': 'is empty',
  'not_empty': 'is not empty',
};

class _ViewSettingsPanelState extends State<ViewSettingsPanel> {
  late final Map<String, dynamic> _config = {
    ...?(widget.view['config'] as Map?)?.map((k, v) => MapEntry('$k', v)),
  };

  List<Map> _list(String key) => [
    for (final e in (_config[key] as List? ?? const [])) Map.of(e as Map),
  ];

  List<SearchOption<String>> get _fieldOptions => [
    for (final f in widget.fields)
      SearchOption(value: '${f['id']}', label: '${f['name']}'),
  ];

  @override
  Widget build(BuildContext context) {
    final filters = _list('filters');
    final sorts = _list('sorts');
    final hidden = {
      ...(_config['hidden'] as List? ?? const []).map((e) => '$e'),
    };
    final type = widget.view['type'];
    final heading = Theme.of(context).textTheme.titleSmall;

    return SidePanel(
      title: 'View settings',
      subtitle: '${widget.view['name']}',
      actions: [
        FilledButton(
          onPressed: () => Navigator.of(context).pop(_config),
          child: const Text('Apply'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Filter', style: heading),
          for (var i = 0; i < filters.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(
                children: [
                  Expanded(
                    child: SearchPickerField<String>(
                      isDense: true,
                      value: filters[i]['field'] as String?,
                      options: _fieldOptions,
                      onChanged: (v) => setState(() {
                        filters[i]['field'] = v;
                        _config['filters'] = filters;
                      }),
                    ),
                  ),
                  const SizedBox(width: 6),
                  SizedBox(
                    width: 130,
                    child: SearchPickerField<String>(
                      isDense: true,
                      value: (filters[i]['op'] as String?) ?? 'contains',
                      options: [
                        for (final e in filterOps.entries)
                          SearchOption(value: e.key, label: e.value),
                      ],
                      onChanged: (v) => setState(() {
                        filters[i]['op'] = v;
                        _config['filters'] = filters;
                      }),
                    ),
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: TextFormField(
                      initialValue: '${filters[i]['value'] ?? ''}',
                      decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'Value',
                      ),
                      onChanged: (t) {
                        filters[i]['value'] = t;
                        _config['filters'] = filters;
                      },
                    ),
                  ),
                  IconButton(
                    tooltip: 'Remove filter',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() {
                      filters.removeAt(i);
                      _config['filters'] = filters;
                    }),
                  ),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add filter'),
              onPressed: () => setState(() {
                _config['filters'] = [
                  ...filters,
                  {
                    'field': widget.fields.first['id'],
                    'op': 'contains',
                    'value': '',
                  },
                ];
              }),
            ),
          ),
          const Divider(),
          Text('Sort', style: heading),
          for (var i = 0; i < sorts.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(
                children: [
                  Expanded(
                    child: SearchPickerField<String>(
                      isDense: true,
                      value: sorts[i]['field'] as String?,
                      options: _fieldOptions,
                      onChanged: (v) => setState(() {
                        sorts[i]['field'] = v;
                        _config['sorts'] = sorts;
                      }),
                    ),
                  ),
                  IconButton(
                    tooltip: sorts[i]['dir'] == 'desc'
                        ? 'Descending'
                        : 'Ascending',
                    icon: Icon(
                      sorts[i]['dir'] == 'desc'
                          ? Icons.arrow_downward
                          : Icons.arrow_upward,
                    ),
                    onPressed: () => setState(() {
                      sorts[i]['dir'] = sorts[i]['dir'] == 'desc'
                          ? 'asc'
                          : 'desc';
                      _config['sorts'] = sorts;
                    }),
                  ),
                  IconButton(
                    tooltip: 'Remove sort',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() {
                      sorts.removeAt(i);
                      _config['sorts'] = sorts;
                    }),
                  ),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add sort'),
              onPressed: () => setState(() {
                _config['sorts'] = [
                  ...sorts,
                  {'field': widget.fields.first['id'], 'dir': 'asc'},
                ];
              }),
            ),
          ),
          if (type == 'grid' || type == 'kanban') ...[
            const Divider(),
            Text(type == 'kanban' ? 'Stack by' : 'Group by', style: heading),
            SearchPickerField<String>(
              isDense: true,
              allowClear: type == 'grid',
              value: _config['group_field'] as String?,
              options: [
                for (final f in widget.fields)
                  if (type == 'grid' || f['type'] == 'single_select')
                    SearchOption(value: '${f['id']}', label: '${f['name']}'),
              ],
              onChanged: (v) => setState(() => _config['group_field'] = v),
            ),
          ],
          if (type == 'calendar') ...[
            const Divider(),
            Text('Date field', style: heading),
            SearchPickerField<String>(
              isDense: true,
              value: _config['date_field'] as String?,
              options: [
                for (final f in widget.fields)
                  if (f['type'] == 'date')
                    SearchOption(value: '${f['id']}', label: '${f['name']}'),
              ],
              onChanged: (v) => setState(() => _config['date_field'] = v),
            ),
          ],
          if (type == 'split') ...[
            const Divider(),
            Text('Split', style: heading),
            const Text(
              "Each group's cost is shared across its people by how many jobs each did, to the cent.",
            ),
            for (final (key, label, clear) in const [
              ('group_field', 'Group (for example Team)', false),
              ('person_field', 'Person (for example Technician)', false),
              ('kind_field', 'Row kind column (optional)', true),
              ('job_key_field', 'Job number, must not repeat (optional)', true),
              ('date_field', 'Date column for the period (optional)', true),
            ])
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: SearchPickerField<String>(
                  label: label,
                  isDense: true,
                  allowClear: clear,
                  value: _config[key] as String?,
                  options: _fieldOptions,
                  onChanged: (v) => setState(() => _config[key] = v),
                ),
              ),
            if (_config['kind_field'] != null)
              for (final (key, label, hint) in const [
                ('job_kind', 'Value that marks a job row', 'Job'),
                (
                  'cost_kind',
                  'Value that marks a cost row (blank: costs sit on job rows)',
                  'Weekly Summary',
                ),
              ])
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextFormField(
                    initialValue: _config[key] as String?,
                    decoration: InputDecoration(
                      labelText: label,
                      hintText: hint,
                      isDense: true,
                    ),
                    onChanged: (t) =>
                        _config[key] = t.trim().isEmpty ? null : t.trim(),
                  ),
                ),
            const SizedBox(height: 8),
            const Text('Cost columns added together'),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final f in widget.fields)
                  if (isNumeric('${f['type']}'))
                    FilterChip(
                      label: Text('${f['name']}'),
                      selected: (_config['cost_fields'] as List? ?? const [])
                          .contains(f['id']),
                      onSelected: (on) => setState(() {
                        final costs = {
                          ...(_config['cost_fields'] as List? ?? const []).map(
                            (e) => '$e',
                          ),
                        };
                        on ? costs.add('${f['id']}') : costs.remove(f['id']);
                        _config['cost_fields'] = costs.toList();
                      }),
                    ),
              ],
            ),
          ],
          const Divider(),
          Text('Fields shown', style: heading),
          for (final f in widget.fields)
            if (f['is_primary'] != true)
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text('${f['name']}'),
                secondary: Icon(fieldTypeIcons[f['type']], size: 18),
                value: !hidden.contains(f['id']),
                onChanged: (on) => setState(() {
                  on ? hidden.remove(f['id']) : hidden.add('${f['id']}');
                  _config['hidden'] = hidden.toList();
                }),
              ),
        ],
      ),
    );
  }
}

/// Applies a view's filters and sorts. Pure, so it is testable.
List<Map> applyView(
  List<Map> records,
  List<Map> fields,
  Map config,
  String search,
) {
  final byId = {for (final f in fields) '${f['id']}': f};
  String text(Map r, Map f) =>
      formatCell(f, cellValue(f, r['values'] as Map, fields)).toLowerCase();

  var out = records.where((r) {
    if (search.isNotEmpty &&
        !fields.any((f) => text(r, f).contains(search.toLowerCase()))) {
      return false;
    }
    for (final filter in (config['filters'] as List? ?? const [])) {
      final f = byId['${filter['field']}'];
      if (f == null) continue;
      final raw = cellValue(f, r['values'] as Map, fields);
      final cell = text(r, f);
      final want = '${filter['value'] ?? ''}'.toLowerCase();
      final n = raw is num ? raw : num.tryParse('$raw');
      final w = num.tryParse(want);
      final keep = switch (filter['op']) {
        'is' => cell == want || '$raw'.toLowerCase() == want,
        'is_not' => cell != want && '$raw'.toLowerCase() != want,
        'gt' => n != null && w != null ? n > w : cell.compareTo(want) > 0,
        'lt' => n != null && w != null ? n < w : cell.compareTo(want) < 0,
        'empty' => cell.isEmpty,
        'not_empty' => cell.isNotEmpty,
        _ => cell.contains(want),
      };
      if (!keep) return false;
    }
    return true;
  }).toList();

  final sorts = (config['sorts'] as List? ?? const []);
  if (sorts.isNotEmpty) {
    out.sort((a, b) {
      for (final s in sorts) {
        final f = byId['${s['field']}'];
        if (f == null) continue;
        final x = cellValue(f, a['values'] as Map, fields);
        final y = cellValue(f, b['values'] as Map, fields);
        int c;
        if (x == null && y == null) {
          c = 0;
        } else if (x == null) {
          c = 1;
        } else if (y == null) {
          c = -1;
        } else if (x is num && y is num) {
          c = x.compareTo(y);
        } else {
          c = '$x'.toLowerCase().compareTo('$y'.toLowerCase());
        }
        if (c != 0) return s['dir'] == 'desc' ? -c : c;
      }
      return 0;
    });
  }
  return out;
}
