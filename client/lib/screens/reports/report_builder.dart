import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Composing a report (spec 2026-09-10, Jose's option 1).
///
/// A field tree on the left, the report on the right. Desktop only — the
/// caller decides that; see `ReportsScreen.buildAbove`.
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
/// real one. A second copy of those rules here is the thing that drifts: the
/// day somebody adds a filter operator, one list gets it and the other refuses
/// a report the runner would happily execute. The only check made here is that
/// there is at least one column, because that costs a round trip to learn.
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

  @override
  State<ReportBuilder> createState() => _ReportBuilderState();
}

class _ReportBuilderState extends State<ReportBuilder> {
  static const Map<String, String> _operators = {
    'eq': 'is',
    'ne': 'is not',
    'contains': 'contains',
    'lt': 'before',
    'lte': 'on or before',
    'gt': 'after',
    'gte': 'on or after',
  };

  final TextEditingController _name = TextEditingController();
  final List<_Chosen> _columns = [];
  final List<_Filter> _filters = [];
  final Set<String> _expanded = {};

  Map<String, dynamic>? _fields;
  String? _loadError;
  String? _saveError;
  bool _loading = true;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  /// The first column chosen decides the root, and every later column has to
  /// be reachable from it — which the SERVER decides, not this screen.
  String? get _root => _columns.isEmpty ? null : _columns.first.resource;

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
      'definition': {
        'root': _root,
        'columns': [
          for (final column in _columns)
            {'resource': column.resource, 'column': column.column},
        ],
        'filters': [
          for (final filter in _filters)
            {
              'resource': filter.resource,
              'column': filter.column,
              'op': filter.op,
              'value': filter.value,
            },
        ],
      },
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
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_loadError!));
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 320, child: _tree(context)),
        const SizedBox(width: 16),
        Expanded(child: _report(context)),
      ],
    );
  }

  // ── the tree ──────────────────────────────────────────────────

  Widget _tree(BuildContext context) {
    final theme = Theme.of(context);
    final fields = _fields ?? const {};

    return ListView(
      children: [
        // MODULES, not "fields". Jose's rule: the tree should match the
        // sidebar so it is obvious which tab a column comes from.
        Text('MODULES', style: theme.textTheme.labelSmall),
        const SizedBox(height: 12),
        for (final entry in fields.entries)
          ..._resourceNode(context, entry.key, '', 0),
      ],
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
    if (resource == null) return const [];

    final nodeId = '$path/$key';
    final open = _expanded.contains(nodeId);
    final columns = _asMap(resource['columns']);
    final relations = _asMap(resource['relations']);

    return [
      Padding(
        padding: EdgeInsets.only(left: depth * 16),
        child: InkWell(
          onTap: () => setState(() {
            open ? _expanded.remove(nodeId) : _expanded.add(nodeId);
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
        for (final column in columns.entries)
          Padding(
            padding: EdgeInsets.only(left: (depth + 1) * 16),
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
            ),
          ),
        for (final relation in relations.entries) ...[
          Padding(
            padding: EdgeInsets.only(left: (depth + 1) * 16, top: 8),
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

  void _toggle(String resource, String column) {
    setState(() {
      final at = _columns.indexWhere(
        (c) => c.resource == resource && c.column == column,
      );
      if (at >= 0) {
        _columns.removeAt(at);
      } else {
        _columns.add(_Chosen(resource, column));
      }
      _saveError = null;
    });
  }

  // ── the report ────────────────────────────────────────────────

  Widget _report(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      children: [
        TextField(
          controller: _name,
          decoration: const InputDecoration(
            labelText: 'Name this report',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 16),
        Text('COLUMNS', style: theme.textTheme.labelSmall),
        const SizedBox(height: 12),
        if (_columns.isEmpty)
          Text(
            'Pick fields on the left and they appear here, in order.',
            style: theme.textTheme.bodyMedium,
          ),
        for (final column in _columns)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text('${column.resource} · ${column.column}'),
            trailing: IconButton(
              icon: const Icon(Icons.close),
              onPressed: () => _toggle(column.resource, column.column),
            ),
          ),
        const SizedBox(height: 16),
        Text('FILTERS', style: theme.textTheme.labelSmall),
        const SizedBox(height: 12),
        for (var i = 0; i < _filters.length; i++) _filterRow(context, i),
        TextButton.icon(
          onPressed: _columns.isEmpty ? null : _addFilter,
          icon: const Icon(Icons.add),
          label: const Text('Add filter'),
        ),
        const SizedBox(height: 16),
        if (_saveError != null) ...[
          ErrorNote(_saveError!),
          const SizedBox(height: 12),
        ],
        FilledButton(
          onPressed: _saving ? null : _save,
          child: const Text('Save report'),
        ),
      ],
    );
  }

  void _addFilter() {
    setState(() {
      final first = _columns.first;
      _filters.add(_Filter(first.resource, first.column, 'eq', ''));
      _saveError = null;
    });
  }

  Widget _filterRow(BuildContext context, int i) {
    final filter = _filters[i];

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Expanded(
            child: Text('${filter.resource} · ${filter.column}'),
          ),
          const SizedBox(width: 12),
          SizedBox(
            width: 170,
            child: SearchPickerField<String>(
              value: filter.op,
              border: InputBorder.none,
              isDense: true,
              options: [
                for (final entry in _operators.entries)
                  SearchOption(value: entry.key, label: entry.value),
              ],
              onChanged: (value) =>
                  setState(() => _filters[i] = filter.withOp(value ?? 'eq')),
            ),
          ),
          const SizedBox(width: 12),
          SizedBox(
            width: 160,
            child: TextField(
              decoration: const InputDecoration(labelText: 'Value'),
              onChanged: (value) =>
                  setState(() => _filters[i] = filter.withValue(value)),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.close),
            onPressed: () => setState(() => _filters.removeAt(i)),
          ),
        ],
      ),
    );
  }
}

class _Chosen {
  const _Chosen(this.resource, this.column);

  final String resource;
  final String column;
}

class _Filter {
  const _Filter(this.resource, this.column, this.op, this.value);

  final String resource;
  final String column;
  final String op;
  final String value;

  _Filter withOp(String op) => _Filter(resource, column, op, value);
  _Filter withValue(String value) => _Filter(resource, column, op, value);
}
