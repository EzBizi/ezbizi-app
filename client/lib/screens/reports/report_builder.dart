import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../widgets/list_table.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/phone_numbers.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Composing a report, watching it as it is built (UI queue 72, Jose
/// 2026-10-09: "i also want a preview view, as you select the items and be
/// able to order them too"; no answer in ten minutes, so 1 and 4).
///
/// Wide: the fields on the left; on the right the name and Save, the columns
/// as chips that drag into order, a row of Filter, Sort, Group by and Totals,
/// and the preview filling the rest. A phone stacks the same pieces with the
/// preview on top and the columns as a list that drags.
///
/// ── The tree walks declared edges, and says which one it walked ──
///
/// `GET /reports/fields` returns per resource: a label, its columns, and the
/// resources it can reach. A relation's NAME is its key in that payload —
/// `relations: {"customer": {"to": "customers"}}` — and there is no human
/// label, deliberately: one relation exists in the product today, and
/// `ReportMapTest::test_two_relations_to_the_same_target_would_need_labels`
/// goes red the day two edges from one resource reach the same place and the
/// target stops identifying which was taken. So a hop is drawn as
/// `› customer › Customers` rather than as the target alone.
///
/// ── There is no client-side validator, on purpose ────────────────
///
/// The server validates a definition by RUNNING it, so its complaint is the
/// real one - and the preview asks it on every change, so the complaint shows
/// while building rather than at Save. A second copy of those rules here is
/// the thing that drifts. The only check made here is that there is at least
/// one column, because that costs a round trip to learn.
class ReportBuilder extends StatefulWidget {
  const ReportBuilder({
    super.key,
    required this.api,
    required this.onSaved,
    this.editing,
  });

  final ApiClient api;
  final void Function(String id) onSaved;
  final String? editing;

  /// The fields beside the report at this width and wider; narrower, stacked.
  static const double sideBySide = 900;

  @override
  State<ReportBuilder> createState() => _ReportBuilderState();
}

class _ReportBuilderState extends State<ReportBuilder> {
  /// The words for each operator, and which column types offer it. The server
  /// owns what they mean (Reports, 59d8a2dd).
  static const Map<String, String> _operators = {
    'eq': 'is',
    'ne': 'is not',
    'contains': 'contains',
    'in': 'is one of',
    'not_in': 'is not one of',
    'lt': 'less than / before',
    'lte': 'at most / on or before',
    'gt': 'more than / after',
    'gte': 'at least / on or after',
    'between': 'between',
    'period': 'in',
    'empty': 'is empty',
    'not_empty': 'is not empty',
  };

  static const _byType = {
    'string': ['eq', 'ne', 'contains', 'in', 'not_in', 'empty', 'not_empty'],
    'integer': ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'empty'],
    'money': ['eq', 'lt', 'lte', 'gt', 'gte', 'between', 'empty'],
    'date': ['period', 'between', 'lt', 'gt', 'empty', 'not_empty'],
    'datetime': ['period', 'between', 'lt', 'gt', 'empty', 'not_empty'],
    'boolean': ['eq'],
  };

  static const Map<String, String> _periods = {
    'today': 'Today',
    'yesterday': 'Yesterday',
    'this_week': 'This week',
    'last_week': 'Last week',
    'this_month': 'This month',
    'last_month': 'Last month',
    'last_7_days': 'Last 7 days',
    'last_30_days': 'Last 30 days',
    'last_90_days': 'Last 90 days',
    'this_year': 'This year',
    'last_year': 'Last year',
  };

  static const Map<String, String> _functions = {
    'count': 'Count',
    'sum': 'Sum of',
    'avg': 'Average of',
    'min': 'Smallest',
    'max': 'Largest',
  };

  final TextEditingController _name = TextEditingController();
  final TextEditingController _search = TextEditingController();
  final List<_Chosen> _columns = [];
  final List<_Filter> _filters = [];
  final List<_Field> _group = [];
  final List<_Total> _totals = [];
  final Map<String, String> _buckets = {};
  final Set<String> _expanded = {};

  String _match = 'all';
  _Field? _sort;
  bool _descending = false;

  /// Which editor is open under the option row: 'filter:2', 'sort', 'group',
  /// 'totals', 'rename:1', or null. One at a time, in place - no dialogs.
  String? _editor;

  /// Fields shown in place on a phone, under "Add field".
  bool _adding = false;

  Map<String, dynamic>? _fields;
  String? _loadError;
  String? _saveError;
  bool _loading = true;
  bool _saving = false;

  Map<String, dynamic>? _preview;
  String? _previewError;
  bool _previewing = false;
  Timer? _debounce;
  int _asked = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _name.dispose();
    _search.dispose();
    super.dispose();
  }

  /// The first column chosen decides the root, and every later column has to
  /// be reachable from it — which the SERVER decides, not this screen.
  String? get _root => _columns.isEmpty ? null : _columns.first.resource;

  Map<String, dynamic> get _definition => {
    'root': _root,
    'columns': [
      for (final column in _columns)
        {
          'resource': column.resource,
          'column': column.column,
          if (column.label != null) 'label': column.label,
        },
    ],
    'filters': [
      for (final filter in _filters)
        if (filter.ready)
          {
            'resource': filter.field.resource,
            'column': filter.field.column,
            'op': filter.op,
            'value': filter.value,
          },
    ],
    if (_filters.length > 1) 'match': _match,
    if (_sort case final sort?)
      'sort': [
        {
          'resource': sort.resource,
          'column': sort.column,
          'dir': _descending ? 'desc' : 'asc',
        },
      ],
    if (_group.isNotEmpty)
      'group': [
        for (final field in _group)
          {
            'resource': field.resource,
            'column': field.column,
            // A date groups by month until another bucket is picked.
            if (field.type == 'date' || field.type == 'datetime')
              'bucket': _buckets[field.key] ?? 'month',
          },
      ],
    if (_totals.isNotEmpty)
      'totals': [
        for (final total in _totals)
          if (total.fn == 'count')
            {'fn': 'count'}
          else if (total.field case final field?)
            {
              'resource': field.resource,
              'column': field.column,
              'fn': total.fn,
            },
      ],
  };

  Future<void> _load() async {
    final response = await widget.api.get('/reports/fields');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _fields = (response.body['data'] as Map).cast<String, dynamic>();
      } else {
        _loadError = response.message;
      }
    });
  }

  /// Something about the report changed: draw it now, and ask for a fresh
  /// preview once the changes stop for a moment.
  void _changed(VoidCallback change) {
    setState(() {
      change();
      _saveError = null;
    });
    _debounce?.cancel();
    if (_columns.isEmpty) {
      setState(() {
        _preview = null;
        _previewError = null;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 350), _askPreview);
  }

  Future<void> _askPreview() async {
    final asked = ++_asked;
    setState(() => _previewing = true);

    final response = await widget.api.post('/reports/preview', {
      'definition': _definition,
    });

    // A later change has asked again; this answer is for a report that is
    // no longer on the screen.
    if (!mounted || asked != _asked) return;

    setState(() {
      _previewing = false;
      if (response.ok) {
        _preview = (response.body['data'] as Map?)?.cast<String, dynamic>();
        _previewError = null;
      } else {
        _previewError = _definitionComplaint(response) ?? response.message;
      }
    });
  }

  Future<void> _save() async {
    if (_columns.isEmpty) {
      setState(() => _saveError = 'Choose at least one column first.');
      return;
    }

    setState(() {
      _saving = true;
      _saveError = null;
    });

    final response = await widget.api.post('/reports', {
      'name': _name.text.trim(),
      'definition': _definition,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // The server's own sentence. It validated by running the definition,
        // so it knows what is actually wrong; "invalid" would throw that away.
        _saveError = _definitionComplaint(response) ?? response.message;
      });
      return;
    }

    setState(() => _saving = false);
    widget.onSaved(response.body['data']?['id'] as String? ?? '');
  }

  String? _definitionComplaint(ApiResponse response) {
    final errors = response.body['errors'];
    if (errors is! Map) return null;
    final definition = errors['definition'];
    if (definition is List && definition.isNotEmpty) {
      return definition.first.toString();
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_loadError != null) {
      return Align(
        alignment: Alignment.topCenter,
        child: ErrorNote(_loadError!),
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth >= ReportBuilder.sideBySide) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 290, child: _fieldList(context)),
              const SizedBox(width: 16),
              Expanded(child: _wide(context)),
            ],
          );
        }

        return _stacked(context);
      },
    );
  }

  // ── the two layouts ───────────────────────────────────────────

  Widget _wide(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Row(
        children: [
          Expanded(child: _nameField()),
          const SizedBox(width: 12),
          _saveButton(),
        ],
      ),
      if (_saveError != null) ...[
        const SizedBox(height: 8),
        ErrorNote(_saveError!),
      ],
      const SizedBox(height: 12),
      _columnBar(context),
      const SizedBox(height: 10),
      _options(context),
      if (_editor != null) ...[
        const SizedBox(height: 10),
        _editorCard(context),
      ],
      const SizedBox(height: 12),
      Expanded(child: _previewCard(context, fill: true)),
    ],
  );

  /// A phone: the preview first, then the columns as a list that drags,
  /// then what narrows and sums it, then Save.
  Widget _stacked(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      children: [
        _nameField(),
        const SizedBox(height: 12),
        _previewCard(context, fill: false),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Text(
                      'Columns · ${_columns.length}',
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const Spacer(),
                    TextButton.icon(
                      key: const Key('builder-add-field'),
                      onPressed: () => setState(() => _adding = !_adding),
                      icon: Icon(_adding ? Icons.close : Icons.add),
                      label: Text(_adding ? 'Done' : 'Add field'),
                    ),
                  ],
                ),
                if (_adding) SizedBox(height: 360, child: _fieldList(context)),
                _columnList(context),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        _options(context),
        if (_editor != null) ...[
          const SizedBox(height: 10),
          _editorCard(context),
        ],
        const SizedBox(height: 12),
        if (_saveError != null) ...[
          ErrorNote(_saveError!),
          const SizedBox(height: 8),
        ],
        _saveButton(),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _nameField() => TextField(
    controller: _name,
    decoration: const InputDecoration(
      labelText: 'Name this report',
      border: OutlineInputBorder(),
    ),
  );

  Widget _saveButton() => FilledButton(
    onPressed: _saving ? null : _save,
    child: const Text('Save report'),
  );

  // ── the fields ────────────────────────────────────────────────

  Widget _fieldList(BuildContext context) {
    final theme = Theme.of(context);
    final fields = _fields ?? const {};

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ListSearch(
              key: const Key('builder-field-search'),
              controller: _search,
              hint: 'Find a field',
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: ListView(
                children: [
                  // MODULES, not "fields". Jose's rule: the tree should match
                  // the sidebar so it is obvious which tab a column comes from.
                  Text('MODULES', style: theme.textTheme.labelSmall),
                  const SizedBox(height: 4),
                  for (final entry in fields.entries)
                    ..._resourceNode(context, entry.key, '', 0),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// `path` is the hops taken to get here, so the same resource reached two
  /// ways is two nodes rather than one shared one.
  List<Widget> _resourceNode(
    BuildContext context,
    String key,
    String path,
    int depth,
  ) {
    final theme = Theme.of(context);
    final resource = (_fields?[key] as Map?)?.cast<String, dynamic>();
    if (resource == null || depth > 3) return const [];

    final words = _search.text.trim().toLowerCase();
    final nodeId = '$path/$key';
    // Searching opens every module, so a match is never folded away.
    final open = words.isNotEmpty || _expanded.contains(nodeId);
    final columns = _asMap(resource['columns']).entries.where(
      (column) =>
          words.isEmpty ||
          '${(column.value as Map)['label'] ?? column.key}'
              .toLowerCase()
              .contains(words),
    );
    final relations = _asMap(resource['relations']);

    return [
      Padding(
        padding: EdgeInsets.only(left: depth * 14),
        child: InkWell(
          onTap: () => setState(() {
            _expanded.contains(nodeId)
                ? _expanded.remove(nodeId)
                : _expanded.add(nodeId);
          }),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(
              children: [
                Icon(open ? Icons.expand_more : Icons.chevron_right, size: 18),
                const SizedBox(width: 4),
                Text(
                  resource['label'] as String? ?? key,
                  style: theme.textTheme.titleSmall,
                ),
              ],
            ),
          ),
        ),
      ),
      if (open) ...[
        for (final column in columns)
          Padding(
            padding: EdgeInsets.only(left: (depth + 1) * 14),
            child: CheckboxListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _columns.any(
                (c) => c.resource == key && c.column == column.key,
              ),
              onChanged: (_) => _toggle(key, column.key),
              title: Text(
                (column.value as Map)['label'] as String? ?? column.key,
                style: theme.textTheme.bodyMedium,
              ),
              // What kind of value it is: a date, money, text.
              secondary: Text(
                _typeWord((column.value as Map)['type'] as String?),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ),
          ),
        for (final relation in relations.entries) ...[
          Padding(
            padding: EdgeInsets.only(left: (depth + 1) * 14, top: 8),
            child: Text(
              // The relation's own name, because the target alone stops
              // identifying the edge as soon as there are two of them.
              '› ${relation.key} ›',
              style: theme.textTheme.labelSmall,
            ),
          ),
          ..._resourceNode(
            context,
            (relation.value as Map)['to'] as String,
            nodeId,
            depth + 1,
          ),
        ],
      ],
    ];
  }

  static String _typeWord(String? type) => switch (type) {
    'date' || 'datetime' => 'date',
    'money' => 'money',
    'integer' => 'number',
    'boolean' => 'yes/no',
    _ => 'text',
  };

  /// A JSON object, tolerating the empty-array form.
  ///
  /// The server casts both maps to objects now, so this should always receive
  /// one. It stays because the failure it guards against is silent: PHP
  /// serializes an empty array as `[]`, a cast to Map throws on a List, and a
  /// throw inside build() is a blank grey panel in a release build with
  /// nothing in any log. A shipped client cannot be fixed by a server deploy,
  /// so the client tolerates the shape it may already have been given.
  Map<String, dynamic> _asMap(Object? value) {
    if (value is Map) return value.cast<String, dynamic>();
    return <String, dynamic>{};
  }

  void _toggle(String resource, String column) => _changed(() {
    final at = _columns.indexWhere(
      (c) => c.resource == resource && c.column == column,
    );
    if (at >= 0) {
      _columns.removeAt(at);
      if (_columns.isEmpty) _editor = null;
    } else {
      _columns.add(_Chosen(resource, column));
    }
  });

  /// One column's own words: the name it was given, else its field's label.
  String _columnLabel(_Chosen column) =>
      column.label ?? _fieldLabel(column.resource, column.column);

  String _fieldLabel(String resource, String column) {
    final resourceMap = (_fields?[resource] as Map?)?.cast<String, dynamic>();
    final columnMap = (_asMap(resourceMap?['columns'])[column] as Map?);
    return columnMap?['label'] as String? ?? column;
  }

  /// The fields a filter, a group or a total can use: the first column's
  /// module and the modules one step from it, as the tree draws them.
  List<_Field> _reachable() {
    final root = _root;
    if (root == null) return const [];
    final out = <_Field>[];

    void add(String resource, String prefix) {
      final map = (_fields?[resource] as Map?)?.cast<String, dynamic>();
      for (final column in _asMap(map?['columns']).entries) {
        out.add(
          _Field(
            resource,
            column.key,
            '$prefix${(column.value as Map)['label'] ?? column.key}',
            (column.value as Map)['type'] as String? ?? 'string',
          ),
        );
      }
    }

    add(root, '');
    final rootMap = (_fields?[root] as Map?)?.cast<String, dynamic>();
    for (final relation in _asMap(rootMap?['relations']).entries) {
      final to = (relation.value as Map)['to'] as String;
      final label = (_fields?[to] as Map?)?['label'] ?? to;
      add(to, '$label › ');
    }
    return out;
  }

  // ── the columns ───────────────────────────────────────────────

  /// The chosen columns as chips that drag into order (wide).
  Widget _columnBar(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Row(
          children: [
            Text(
              'Columns',
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _columns.isEmpty
                  ? Text(
                      'Tick fields on the left. They appear here and in the '
                      'preview, and drag into order.',
                      style: theme.textTheme.bodySmall,
                    )
                  : SizedBox(
                      height: 40,
                      child: ReorderableListView(
                        key: const Key('builder-columns'),
                        scrollDirection: Axis.horizontal,
                        buildDefaultDragHandles: false,
                        onReorder: _reorder,
                        children: [
                          for (var i = 0; i < _columns.length; i++)
                            Padding(
                              key: ValueKey(_columns[i].key),
                              padding: const EdgeInsets.only(right: 6),
                              child: _columnChip(context, i),
                            ),
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  /// A chip with its handle beside it: a chip sends every tap on its avatar
  /// to its label, so a handle inside it could never start a drag.
  Widget _columnChip(BuildContext context, int i) {
    final theme = Theme.of(context);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        ReorderableDragStartListener(
          index: i,
          child: MouseRegion(
            cursor: SystemMouseCursors.grab,
            child: Icon(
              Icons.drag_indicator,
              size: 18,
              color: theme.colorScheme.outline,
            ),
          ),
        ),
        InputChip(
          key: ValueKey('builder-column-${_columns[i].key}'),
          label: Text(_columnLabel(_columns[i])),
          tooltip: 'Rename this column',
          onPressed: () => setState(() => _editor = 'rename:$i'),
          onDeleted: () => _toggle(_columns[i].resource, _columns[i].column),
          deleteButtonTooltipMessage: 'Remove this column',
        ),
      ],
    );
  }

  /// The chosen columns as rows that drag into order (a phone).
  Widget _columnList(BuildContext context) {
    final theme = Theme.of(context);
    if (_columns.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(
          'Add a field and it joins the preview above.',
          style: theme.textTheme.bodySmall,
        ),
      );
    }

    return ReorderableListView(
      key: const Key('builder-columns'),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      buildDefaultDragHandles: false,
      onReorder: _reorder,
      children: [
        for (var i = 0; i < _columns.length; i++)
          ListTile(
            key: ValueKey(_columns[i].key),
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: ReorderableDragStartListener(
              index: i,
              child: const Icon(Icons.drag_indicator),
            ),
            title: Text(_columnLabel(_columns[i])),
            onTap: () => setState(() => _editor = 'rename:$i'),
            trailing: IconButton(
              tooltip: 'Remove this column',
              icon: const Icon(Icons.close),
              onPressed: () =>
                  _toggle(_columns[i].resource, _columns[i].column),
            ),
          ),
      ],
    );
  }

  void _reorder(int from, int to) => _changed(() {
    final column = _columns.removeAt(from);
    _columns.insert(to > from ? to - 1 : to, column);
    _editor = null;
  });

  // ── filter, sort, group, totals ───────────────────────────────

  Widget _options(BuildContext context) {
    final theme = Theme.of(context);
    final none = _columns.isEmpty;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (_filters.length > 1)
          SegmentedButton<String>(
            key: const Key('builder-match'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 'all', label: Text('Match all')),
              ButtonSegment(value: 'any', label: Text('Match any')),
            ],
            selected: {_match},
            onSelectionChanged: (picked) =>
                _changed(() => _match = picked.first),
          ),
        for (var i = 0; i < _filters.length; i++)
          InputChip(
            key: ValueKey('builder-filter-$i'),
            avatar: const Icon(Icons.filter_alt_outlined, size: 18),
            label: Text(_filterWords(_filters[i])),
            selected: _editor == 'filter:$i',
            showCheckmark: false,
            onPressed: () => setState(() => _editor = 'filter:$i'),
            onDeleted: () => _changed(() {
              _filters.removeAt(i);
              _editor = null;
            }),
          ),
        ActionChip(
          key: const Key('builder-add-filter'),
          avatar: const Icon(Icons.add, size: 18),
          label: const Text('Add filter'),
          onPressed: none
              ? null
              : () => setState(() {
                  final first = _reachable().first;
                  _filters.add(_Filter(first, _opsFor(first).first, null));
                  _editor = 'filter:${_filters.length - 1}';
                }),
        ),
        _optionChip(
          'sort',
          Icons.swap_vert,
          _sort == null
              ? 'Sort'
              : 'Sort: ${_sort!.label}, ${_descending ? 'high to low' : 'low to high'}',
          none,
        ),
        _optionChip(
          'group',
          Icons.view_agenda_outlined,
          _group.isEmpty
              ? 'Group by'
              : 'Group by: ${_group.map((g) => g.label).join(', then ')}',
          none,
        ),
        _optionChip(
          'totals',
          Icons.functions,
          _totals.isEmpty
              ? 'Totals'
              : 'Totals: ${_totals.map(_totalWords).join(', ')}',
          none,
        ),
        // A disabled control says why (UI review 2026-10-08).
        if (none)
          Text(
            'Choose a column first, then filter, sort, group or total it.',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  Widget _optionChip(String key, IconData icon, String label, bool off) =>
      ActionChip(
        key: ValueKey('builder-$key'),
        avatar: Icon(icon, size: 18),
        label: Text(label),
        onPressed: off
            ? null
            : () => setState(() => _editor = _editor == key ? null : key),
      );

  List<String> _opsFor(_Field field) =>
      _byType[field.type] ?? _byType['string']!;

  String _filterWords(_Filter filter) {
    final value = switch (filter.value) {
      null => '',
      final List<dynamic> list when filter.op == 'between' =>
        ' ${list.first} and ${list.last}',
      final List<dynamic> list => ' ${list.join(', ')}',
      final String period when filter.op == 'period' =>
        ' ${_periods[period] ?? period}',
      final Object other => ' $other',
    };
    return '${filter.field.label} ${_operators[filter.op] ?? filter.op}$value';
  }

  String _totalWords(_Total total) => total.fn == 'count'
      ? 'Count'
      : '${_functions[total.fn]} ${total.field?.label ?? '…'}';

  Widget _editorCard(BuildContext context) {
    final editor = _editor!;
    final body = switch (editor) {
      'sort' => _sortEditor(context),
      'group' => _groupEditor(context),
      'totals' => _totalsEditor(context),
      _ when editor.startsWith('filter:') => _filterEditor(
        context,
        int.parse(editor.substring(7)),
      ),
      _ when editor.startsWith('rename:') => _renameEditor(
        context,
        int.parse(editor.substring(7)),
      ),
      _ => const SizedBox.shrink(),
    };

    return Card(
      key: const Key('builder-editor'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: body),
            IconButton(
              tooltip: 'Done',
              onPressed: () => setState(() => _editor = null),
              icon: const Icon(Icons.check),
            ),
          ],
        ),
      ),
    );
  }

  Widget _fieldPicker(
    List<_Field> fields,
    _Field? value,
    ValueChanged<_Field> onChanged, {
    String label = 'Field',
  }) => SizedBox(
    width: 240,
    child: SearchPickerField<String>(
      label: label,
      value: value?.key,
      isDense: true,
      options: [
        for (final field in fields)
          SearchOption(value: field.key, label: field.label),
      ],
      onChanged: (key) {
        final picked = fields.where((f) => f.key == key).firstOrNull;
        if (picked != null) onChanged(picked);
      },
    ),
  );

  Widget _filterEditor(BuildContext context, int i) {
    if (i >= _filters.length) return const SizedBox.shrink();
    final filter = _filters[i];
    final ops = _opsFor(filter.field);

    void set(_Filter next) => _changed(() => _filters[i] = next);

    final Widget value = switch (filter.op) {
      'empty' || 'not_empty' => const SizedBox.shrink(),
      'period' => SizedBox(
        width: 200,
        child: SearchPickerField<String>(
          label: 'Period',
          value: filter.value as String?,
          isDense: true,
          options: [
            for (final entry in _periods.entries)
              SearchOption(value: entry.key, label: entry.value),
          ],
          onChanged: (period) => set(filter.withValue(period)),
        ),
      ),
      'between' => Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          _valueBox(
            key: 'builder-filter-from',
            hint: _hint(filter.field),
            initial: _shown(filter.field, _at(filter.value, 0)),
            onChanged: (from) => set(
              filter.withValue([
                _typed(filter.field, from),
                _at(filter.value, 1),
              ]),
            ),
          ),
          const Text('and'),
          _valueBox(
            key: 'builder-filter-to',
            hint: _hint(filter.field),
            initial: _shown(filter.field, _at(filter.value, 1)),
            onChanged: (to) => set(
              filter.withValue([
                _at(filter.value, 0),
                _typed(filter.field, to),
              ]),
            ),
          ),
        ],
      ),
      'in' || 'not_in' => _valueBox(
        key: 'builder-filter-value',
        hint: 'Separate with commas',
        width: 260,
        initial: [
          for (final item in (filter.value as List?) ?? const [])
            _shown(filter.field, item),
        ].join(', '),
        onChanged: (text) => set(
          filter.withValue([
            for (final part in text.split(','))
              if (part.trim().isNotEmpty) _typed(filter.field, part),
          ]),
        ),
      ),
      _ when filter.field.type == 'boolean' => SegmentedButton<bool>(
        showSelectedIcon: false,
        segments: const [
          ButtonSegment(value: true, label: Text('Yes')),
          ButtonSegment(value: false, label: Text('No')),
        ],
        emptySelectionAllowed: true,
        selected: {if (filter.value case final bool on) on},
        onSelectionChanged: (picked) =>
            set(filter.withValue(picked.isEmpty ? null : picked.first)),
      ),
      _ => _valueBox(
        key: 'builder-filter-value',
        hint: _hint(filter.field),
        initial: _shown(filter.field, filter.value),
        onChanged: (text) => set(filter.withValue(_typed(filter.field, text))),
      ),
    };

    return Wrap(
      spacing: 10,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _fieldPicker(
          _reachable(),
          filter.field,
          (field) => set(_Filter(field, _opsFor(field).first, null)),
        ),
        SizedBox(
          width: 210,
          child: SearchPickerField<String>(
            label: 'Condition',
            value: filter.op,
            isDense: true,
            options: [
              for (final op in ops)
                SearchOption(value: op, label: _operators[op] ?? op),
            ],
            onChanged: (op) =>
                set(_Filter(filter.field, op ?? ops.first, null)),
          ),
        ),
        value,
      ],
    );
  }

  /// Money is typed in dollars and sent in cents, as money columns are kept.
  Object? _typed(_Field field, String text) {
    final t = text.trim();
    if (t.isEmpty) return null;
    return switch (field.type) {
      'integer' => int.tryParse(t) ?? t,
      'money' => switch (double.tryParse(t.replaceAll(RegExp(r'[$,]'), ''))) {
        final dollars? => (dollars * 100).round(),
        null => t,
      },
      _ => t,
    };
  }

  /// One end of a `between`, already in the server's units: re-reading it
  /// through [_typed] would turn cents into cents again.
  Object? _at(Object? value, int i) =>
      value is List && value.length > i ? value[i] : null;

  /// A stored value back in the words it was typed in: money in dollars.
  String _shown(_Field field, Object? value) {
    if (value == null) return '';
    if (field.type == 'money' && value is num) {
      final dollars = value / 100;
      return dollars == dollars.roundToDouble()
          ? '${dollars.round()}'
          : dollars.toStringAsFixed(2);
    }
    return '$value';
  }

  String _hint(_Field field) => switch (field.type) {
    'date' || 'datetime' => 'YYYY-MM-DD',
    'money' => 'Dollars',
    'integer' => 'A number',
    _ => 'Value',
  };

  Widget _valueBox({
    required String key,
    required String hint,
    required String initial,
    required ValueChanged<String> onChanged,
    double width = 160,
  }) => SizedBox(
    width: width,
    child: TextFormField(
      key: ValueKey(key),
      initialValue: initial,
      decoration: InputDecoration(isDense: true, hintText: hint),
      onChanged: onChanged,
    ),
  );

  Widget _sortEditor(BuildContext context) {
    final shown = [
      for (final column in _columns)
        _reachable().firstWhere(
          (f) => f.resource == column.resource && f.column == column.column,
          orElse: () => _Field(
            column.resource,
            column.column,
            _columnLabel(column),
            'string',
          ),
        ),
    ];

    return Wrap(
      spacing: 10,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _fieldPicker(
          shown,
          _sort,
          (field) => _changed(() => _sort = field),
          label: 'Sort by',
        ),
        SegmentedButton<bool>(
          showSelectedIcon: false,
          segments: const [
            ButtonSegment(value: false, label: Text('Low to high')),
            ButtonSegment(value: true, label: Text('High to low')),
          ],
          selected: {_descending},
          onSelectionChanged: (picked) =>
              _changed(() => _descending = picked.first),
        ),
        if (_sort != null)
          TextButton(
            onPressed: () => _changed(() => _sort = null),
            child: const Text('No sort'),
          ),
      ],
    );
  }

  Widget _groupEditor(BuildContext context) {
    final theme = Theme.of(context);
    final fields = _reachable();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Grouped, each row is one group with its totals. Add a total too, or '
          'each group just counts its rows.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        for (var i = 0; i < _group.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Wrap(
              spacing: 10,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                _fieldPicker(
                  fields,
                  _group[i],
                  (field) => _changed(() => _group[i] = field),
                  label: i == 0 ? 'Group by' : 'Then by',
                ),
                if (_group[i].type == 'date' || _group[i].type == 'datetime')
                  SegmentedButton<String>(
                    showSelectedIcon: false,
                    segments: const [
                      ButtonSegment(value: 'day', label: Text('Day')),
                      ButtonSegment(value: 'week', label: Text('Week')),
                      ButtonSegment(value: 'month', label: Text('Month')),
                    ],
                    selected: {_buckets[_group[i].key] ?? 'month'},
                    onSelectionChanged: (picked) =>
                        _changed(() => _buckets[_group[i].key] = picked.first),
                  ),
                IconButton(
                  tooltip: 'Stop grouping by this',
                  onPressed: () => _changed(() => _group.removeAt(i)),
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
          ),
        // At most two, as the server takes.
        if (_group.length < 2)
          TextButton.icon(
            key: const Key('builder-add-group'),
            onPressed: () => _changed(() {
              final field = fields.first;
              _group.add(field);
              if (field.type == 'date' || field.type == 'datetime') {
                _buckets[field.key] = 'month';
              }
              if (_totals.isEmpty) _totals.add(const _Total('count', null));
            }),
            icon: const Icon(Icons.add),
            label: Text(
              _group.isEmpty ? 'Group by a field' : 'Then by another',
            ),
          ),
      ],
    );
  }

  Widget _totalsEditor(BuildContext context) {
    final numbers = _reachable()
        .where((f) => f.type == 'integer' || f.type == 'money')
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < _totals.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Wrap(
              spacing: 10,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SizedBox(
                  width: 170,
                  child: SearchPickerField<String>(
                    label: 'Total',
                    value: _totals[i].fn,
                    isDense: true,
                    options: [
                      for (final entry in _functions.entries)
                        if (entry.key == 'count' || numbers.isNotEmpty)
                          SearchOption(value: entry.key, label: entry.value),
                    ],
                    onChanged: (fn) => _changed(
                      () => _totals[i] = _Total(
                        fn ?? 'count',
                        fn == 'count'
                            ? null
                            : (_totals[i].field ?? numbers.firstOrNull),
                      ),
                    ),
                  ),
                ),
                if (_totals[i].fn != 'count')
                  _fieldPicker(
                    numbers,
                    _totals[i].field,
                    (field) => _changed(
                      () => _totals[i] = _Total(_totals[i].fn, field),
                    ),
                    label: 'Of',
                  ),
                IconButton(
                  tooltip: 'Remove this total',
                  onPressed: () => _changed(() => _totals.removeAt(i)),
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
          ),
        TextButton.icon(
          key: const Key('builder-add-total'),
          onPressed: () => _changed(
            () => _totals.add(
              numbers.isEmpty
                  ? const _Total('count', null)
                  : _Total('sum', numbers.first),
            ),
          ),
          icon: const Icon(Icons.add),
          label: const Text('Add a total'),
        ),
      ],
    );
  }

  Widget _renameEditor(BuildContext context, int i) {
    if (i >= _columns.length) return const SizedBox.shrink();
    final column = _columns[i];

    return Wrap(
      spacing: 10,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 280,
          child: TextFormField(
            key: ValueKey('builder-rename-${column.key}'),
            initialValue: _columnLabel(column),
            decoration: InputDecoration(
              isDense: true,
              labelText: 'Column name',
              helperText: 'Was ${_fieldLabel(column.resource, column.column)}',
            ),
            onChanged: (text) => _changed(
              () => _columns[i] = column.named(
                text.trim().isEmpty ? null : text.trim(),
              ),
            ),
          ),
        ),
      ],
    );
  }

  // ── the preview ───────────────────────────────────────────────

  Widget _previewCard(BuildContext context, {required bool fill}) {
    final theme = Theme.of(context);
    final preview = _preview;
    final columns = [
      for (final column in (preview?['columns'] as List? ?? const []))
        (column as Map).cast<String, dynamic>(),
    ];
    final rows = [
      for (final row in (preview?['rows'] as List? ?? const []))
        (row as Map).cast<String, dynamic>(),
    ];
    final total = (preview?['total'] as Map?)?.cast<String, dynamic>();
    final count = preview?['count'];
    final grouped = _group.isNotEmpty || _totals.isNotEmpty;

    final said = switch ((preview, grouped)) {
      (null, _) => '',
      (_, true) => '${rows.length} ${rows.length == 1 ? 'group' : 'groups'}',
      _ when preview?['capped'] == true =>
        'first ${rows.length} of more than $count rows',
      _ => 'first ${rows.length} of $count ${count == 1 ? 'row' : 'rows'}',
    };

    final Widget body;
    if (_columns.isEmpty) {
      body = Padding(
        padding: const EdgeInsets.all(16),
        child: Text(
          'Tick a field and its first rows appear here, filling in as you '
          'build.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    } else if (_previewError != null) {
      body = Padding(
        padding: const EdgeInsets.all(12),
        child: ErrorNote(_previewError!),
      );
    } else if (preview == null) {
      body = const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    } else {
      body = ListTable(
        key: const Key('builder-preview'),
        columns: [
          for (final column in columns)
            ListColumn(
              '${column['label'] ?? column['key']}',
              end: _isNumber(column['type']),
            ),
        ],
        rows: [
          for (final row in rows)
            [
              for (final column in columns)
                Text(_cell(row[column['key']], column['type'], column['key'])),
            ],
          if (total != null && columns.isNotEmpty)
            [
              for (var i = 0; i < columns.length; i++)
                Text(
                  total.containsKey(columns[i]['key'])
                      ? _cell(total[columns[i]['key']], columns[i]['type'])
                      : (i == 0 ? 'Total' : ''),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
            ],
        ],
      );
    }

    final content = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 6),
          child: Row(
            children: [
              Text(
                'Preview',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  said,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ),
              if (_previewing)
                const SizedBox.square(
                  dimension: 14,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        if (fill) Expanded(child: SingleChildScrollView(child: body)) else body,
      ],
    );

    return Card(margin: EdgeInsets.zero, child: content);
  }

  static bool _isNumber(Object? type) => type == 'integer' || type == 'money';

  String _cell(Object? value, Object? type, [Object? key]) {
    if (value == null) return '—';
    // A phone written as a number (Jose, 2026-11-16); export keeps the digits.
    if ('${key ?? ''}'.endsWith('phone')) {
      return formatPhoneNumber(toE164('$value') ?? '$value');
    }
    return switch (type) {
      'money' when value is num => formatMoney(value.round()),
      'boolean' => value == true ? 'Yes' : 'No',
      'datetime' || 'date' => '$value'.split('T').first,
      _ => '$value',
    };
  }
}

class _Chosen {
  const _Chosen(this.resource, this.column, [this.label]);

  final String resource;
  final String column;

  /// The report's own name for this column, or null for the field's.
  final String? label;

  String get key => '$resource.$column';

  _Chosen named(String? label) => _Chosen(resource, column, label);
}

/// A field a filter, group or total points at.
class _Field {
  const _Field(this.resource, this.column, this.label, this.type);

  final String resource;
  final String column;
  final String label;
  final String type;

  String get key => '$resource.$column';
}

class _Filter {
  const _Filter(this.field, this.op, this.value);

  final _Field field;
  final String op;
  final Object? value;

  /// Sent once it says something: an empty value would only be refused.
  bool get ready => switch (op) {
    'empty' || 'not_empty' => true,
    'between' => value is List && (value as List).every((v) => v != null),
    'in' || 'not_in' => value is List && (value as List).isNotEmpty,
    _ => value != null,
  };

  _Filter withValue(Object? value) => _Filter(field, op, value);
}

class _Total {
  const _Total(this.fn, this.field);

  final String fn;
  final _Field? field;
}
