import 'package:flutter/material.dart';

import 'task_models.dart';

class TaskFiltersPanel extends StatefulWidget {
  const TaskFiltersPanel({
    super.key,
    required this.board,
    required this.configuration,
    required this.people,
    required this.onChanged,
    required this.onClose,
  });

  final TaskBoard board;
  final TaskViewConfiguration configuration;
  final List<TaskParticipant> people;
  final ValueChanged<TaskViewConfiguration> onChanged;
  final VoidCallback onClose;

  @override
  State<TaskFiltersPanel> createState() => _TaskFiltersPanelState();
}

class _TaskFiltersPanelState extends State<TaskFiltersPanel> {
  late TaskViewConfiguration _configuration = widget.configuration;
  late final TextEditingController _search = TextEditingController(
    text: _configuration.search,
  );

  @override
  void didUpdateWidget(covariant TaskFiltersPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.configuration != widget.configuration &&
        widget.configuration.toJson().toString() !=
            _configuration.toJson().toString()) {
      _configuration = widget.configuration;
      _search.text = _configuration.search;
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _change(TaskViewConfiguration next) {
    setState(() => _configuration = next);
    widget.onChanged(next);
  }

  List<(String, String)> get _columns {
    final labels = <String, String>{
      for (final column in widget.board.coreColumns)
        column.key: column.displayLabel,
      for (final column in widget.board.customColumns.where((c) => !c.archived))
        'custom:${column.id}': column.name,
    };
    const defaults = <String, String>{
      'task': 'Task',
      'owner': 'Person',
      'status': 'Status',
      'priority': 'Priority',
      'start_on': 'Start date',
      'due_on': 'Due date',
    };
    return _configuration.columnOrder
        .map((key) => (key, labels[key] ?? defaults[key] ?? key))
        .toList(growable: false);
  }

  String? get _personId {
    for (final filter in _configuration.filters) {
      if (filter.field == 'owner' && filter.value is List) {
        final values = filter.value as List;
        if (values.length == 1 && values.single is String) {
          return values.single as String;
        }
      }
    }
    return null;
  }

  void _person(String? id) {
    final filters = _configuration.filters
        .where((filter) => filter.field != 'owner')
        .toList();
    if (id != null) {
      filters.add(
        TaskFilter(field: 'owner', operator: 'in', value: <String>[id]),
      );
    }
    _change(_configuration.copyWith(filters: List.unmodifiable(filters)));
  }

  void _sortField(String? field) {
    if (field == null) {
      _change(_configuration.copyWith(sorts: const <TaskSort>[]));
      return;
    }
    final direction = _configuration.sorts.firstOrNull?.rawDirection ?? 'asc';
    _change(
      _configuration.copyWith(
        sorts: <TaskSort>[
          TaskSort.fromJson(<String, Object?>{
            'field': field,
            'direction': direction,
          }),
        ],
      ),
    );
  }

  void _toggleDirection() {
    final sort = _configuration.sorts.firstOrNull;
    if (sort == null) return;
    _change(
      _configuration.copyWith(
        sorts: <TaskSort>[
          TaskSort.fromJson(<String, Object?>{
            'field': sort.field,
            'direction': sort.direction == TaskSortDirection.asc
                ? 'desc'
                : 'asc',
          }),
        ],
      ),
    );
  }

  void _hide(String key, bool hidden) {
    final next = _configuration.hiddenColumns.toSet();
    hidden ? next.add(key) : next.remove(key);
    _change(_configuration.copyWith(hiddenColumns: List.unmodifiable(next)));
  }

  void _move(String key, int delta) {
    final order = _configuration.columnOrder.toList();
    final at = order.indexOf(key);
    if (at < 0) return;
    final target = (at + delta).clamp(0, order.length - 1);
    if (target == at) return;
    order.removeAt(at);
    order.insert(target, key);
    _change(_configuration.copyWith(columnOrder: List.unmodifiable(order)));
  }

  void _resize(String key, int delta) {
    final widths = Map<String, int>.from(_configuration.columnWidths);
    widths[key] = ((widths[key] ?? (key == 'task' ? 320 : 180)) + delta).clamp(
      96,
      640,
    );
    _change(_configuration.copyWith(columnWidths: Map.unmodifiable(widths)));
  }

  @override
  Widget build(BuildContext context) {
    final sort = _configuration.sorts.firstOrNull;
    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerLow,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
        children: [
          Row(
            children: [
              Text(
                'Filter and arrange',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const Spacer(),
              IconButton(
                tooltip: 'Close filters',
                onPressed: widget.onClose,
                icon: const Icon(Icons.close),
              ),
            ],
          ),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 260,
                child: Semantics(
                  textField: true,
                  label: 'Search this board',
                  child: TextField(
                    key: const Key('task-view-search'),
                    controller: _search,
                    decoration: const InputDecoration(
                      labelText: 'Search this board',
                      prefixIcon: Icon(Icons.search),
                    ),
                    onChanged: (value) =>
                        _change(_configuration.copyWith(search: value)),
                  ),
                ),
              ),
              SizedBox(
                width: 220,
                child: Semantics(
                  label: 'Filter by person',
                  child: DropdownButtonFormField<String>(
                    key: const Key('task-person-filter'),
                    isExpanded: true,
                    initialValue: _personId,
                    decoration: const InputDecoration(labelText: 'Person'),
                    items: [
                      const DropdownMenuItem<String>(child: Text('Anyone')),
                      for (final person in widget.people)
                        DropdownMenuItem(
                          value: person.id,
                          child: Text(person.name),
                        ),
                    ],
                    onChanged: _person,
                  ),
                ),
              ),
              SizedBox(
                width: 220,
                child: Semantics(
                  label: 'Sort tasks',
                  child: DropdownButtonFormField<String>(
                    key: const Key('task-sort-field'),
                    isExpanded: true,
                    initialValue: sort?.field,
                    decoration: const InputDecoration(labelText: 'Sort by'),
                    items: [
                      const DropdownMenuItem<String>(
                        child: Text('Board order'),
                      ),
                      for (final column in _columns)
                        DropdownMenuItem(
                          value: column.$1,
                          child: Text(column.$2),
                        ),
                    ],
                    onChanged: _sortField,
                  ),
                ),
              ),
              IconButton.outlined(
                key: const Key('task-sort-direction'),
                tooltip: sort?.direction == TaskSortDirection.desc
                    ? 'Sort ascending'
                    : 'Sort descending',
                onPressed: sort == null ? null : _toggleDirection,
                icon: Icon(
                  sort?.direction == TaskSortDirection.desc
                      ? Icons.arrow_downward
                      : Icons.arrow_upward,
                ),
              ),
              SizedBox(
                width: 220,
                child: Semantics(
                  label: 'Group tasks',
                  child: DropdownButtonFormField<String>(
                    key: const Key('task-group-by'),
                    isExpanded: true,
                    initialValue: _configuration.groupBy,
                    decoration: const InputDecoration(labelText: 'Group by'),
                    items: [
                      const DropdownMenuItem<String>(
                        child: Text('Board groups'),
                      ),
                      for (final column in _columns)
                        DropdownMenuItem(
                          value: column.$1,
                          child: Text(column.$2),
                        ),
                    ],
                    onChanged: (value) =>
                        _change(_configuration.copyWith(groupBy: value)),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text('Columns', style: Theme.of(context).textTheme.labelLarge),
          for (final column in _columns)
            Row(
              children: [
                Checkbox(
                  key: Key('task-hide-${column.$1}'),
                  value: !_configuration.hiddenColumns.contains(column.$1),
                  onChanged: (visible) => _hide(column.$1, !(visible ?? true)),
                ),
                Expanded(child: Text(column.$2)),
                IconButton(
                  key: Key('task-column-${column.$1}-up'),
                  tooltip: 'Move ${column.$2} left',
                  onPressed: () => _move(column.$1, -1),
                  icon: const Icon(Icons.arrow_upward),
                ),
                IconButton(
                  key: Key('task-column-${column.$1}-down'),
                  tooltip: 'Move ${column.$2} right',
                  onPressed: () => _move(column.$1, 1),
                  icon: const Icon(Icons.arrow_downward),
                ),
                IconButton(
                  key: Key('task-width-${column.$1}-decrease'),
                  tooltip: 'Narrow ${column.$2}',
                  onPressed: () => _resize(column.$1, -24),
                  icon: const Icon(Icons.remove),
                ),
                IconButton(
                  key: Key('task-width-${column.$1}-increase'),
                  tooltip: 'Widen ${column.$2}',
                  onPressed: () => _resize(column.$1, 24),
                  icon: const Icon(Icons.add),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
