import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'task_column_cells.dart';
import 'task_models.dart';

class TaskTable extends StatefulWidget {
  const TaskTable({
    super.key,
    required this.board,
    required this.rows,
    required this.selectedTaskId,
    required this.onSelected,
    required this.onTitleChanged,
    this.onCoreChanged,
    this.onCustomChanged,
    this.onBulkChanged,
    this.owners = const [],
    this.people = const [],
    this.onSelectionChanged,
    this.onSelectionCleared,
    this.bulkBusy = false,
    this.interactionLocked = false,
    this.configuration = TaskViewConfiguration.empty,
    this.groupCounts = const <TaskGroupCount>[],
    this.onDraftChanged,
    this.onAddTask,
  });
  final TaskBoard board;
  final List<TaskRow> rows;
  final String? selectedTaskId;
  final ValueChanged<TaskRow> onSelected;
  final void Function(TaskRow, String) onTitleChanged;
  final Future<void> Function(TaskRow, String, Object?)? onCoreChanged;
  final Future<void> Function(TaskRow, TaskColumnDefinition, Object?)?
  onCustomChanged;
  final Future<void> Function(List<TaskRow>, Map<String, Object?>)?
  onBulkChanged;
  final List<TaskParticipant> owners;
  final List<TaskParticipant> people;
  final ValueChanged<int>? onSelectionChanged;
  final VoidCallback? onSelectionCleared;
  final bool bulkBusy;
  final bool interactionLocked;
  final TaskViewConfiguration configuration;
  final List<TaskGroupCount> groupCounts;
  final TaskDraftChanged? onDraftChanged;
  final ValueChanged<String?>? onAddTask;
  @override
  State<TaskTable> createState() => _TaskTableState();
}

class _TaskTableState extends State<TaskTable> {
  final _selection = TaskSelection();
  final _pinned = ScrollController();
  final _columns = ScrollController();
  final _collapsed = <Object?>{};
  bool _syncing = false;
  @override
  void initState() {
    super.initState();
    _pinned.addListener(() => _sync(_pinned, _columns));
    _columns.addListener(() => _sync(_columns, _pinned));
  }

  void _sync(ScrollController from, ScrollController to) {
    if (_syncing || !to.hasClients) return;
    _syncing = true;
    to.jumpTo(
      from.offset.clamp(
        to.position.minScrollExtent,
        to.position.maxScrollExtent,
      ),
    );
    _syncing = false;
  }

  @override
  void dispose() {
    _pinned.dispose();
    _columns.dispose();
    super.dispose();
  }

  void _toggle(TaskRow task) => setState(() {
    if (widget.interactionLocked) return;
    _selection.toggle(task);
    widget.onSelectionChanged?.call(_selection.count);
  });
  void _clear() => setState(() {
    _selection.clear();
    widget.onSelectionChanged?.call(0);
  });
  Future<void> _core(TaskRow task, String field, Object? value) async {
    if (widget.interactionLocked) return;
    if (field == 'title' && widget.onCoreChanged == null) {
      widget.onTitleChanged(task, value as String);
    }
    await widget.onCoreChanged?.call(task, field, value);
  }

  Future<void> _custom(
    TaskRow task,
    TaskColumnDefinition column,
    Object? value,
  ) async {
    if (widget.interactionLocked) return;
    await widget.onCustomChanged?.call(task, column, value);
  }

  Future<void> _bulk(Map<String, Object?> changes) async {
    final rows = widget.rows
        .where((row) => _selection.contains(row.id))
        .toList();
    if (rows.isNotEmpty) await widget.onBulkChanged?.call(rows, changes);
  }

  @override
  Widget build(BuildContext context) {
    final groups = <Object?, List<TaskRow>>{};
    for (final row in widget.rows) {
      final key = widget.configuration.groupBy == null
          ? row.groupId
          : row.groupByValue;
      (groups[key] ??= []).add(row);
    }
    final ids = <Object?>{
      ...widget.groupCounts.map((count) => count.value),
      if (widget.configuration.groupBy == null)
        ...widget.board.groups.where((g) => !g.archived).map((g) => g.id),
      ...groups.keys,
    }.toList(growable: false);
    final columns = _visibleColumns();
    const taskWidth = 300.0;
    return FocusableActionDetector(
      shortcuts: const {
        SingleActivator(LogicalKeyboardKey.escape): _ClearIntent(),
        SingleActivator(LogicalKeyboardKey.arrowDown): _MoveIntent(1),
        SingleActivator(LogicalKeyboardKey.arrowUp): _MoveIntent(-1),
        SingleActivator(LogicalKeyboardKey.enter): _ActivateIntent(),
      },
      actions: {
        _ClearIntent: CallbackAction<_ClearIntent>(
          onInvoke: (_) {
            if (widget.interactionLocked) return null;
            _clear();
            widget.onSelectionCleared?.call();
            return null;
          },
        ),
        _MoveIntent: CallbackAction<_MoveIntent>(
          onInvoke: (intent) {
            if (widget.interactionLocked) return null;
            if (widget.rows.isNotEmpty) {
              final at = widget.rows.indexWhere(
                (row) => row.id == widget.selectedTaskId,
              );
              widget.onSelected(
                widget.rows[(at + intent.delta).clamp(
                  0,
                  widget.rows.length - 1,
                )],
              );
            }
            return null;
          },
        ),
        _ActivateIntent: CallbackAction<_ActivateIntent>(
          onInvoke: (_) {
            if (widget.interactionLocked) return null;
            final found = widget.rows.where(
              (row) => row.id == widget.selectedTaskId,
            );
            if (found.isNotEmpty) widget.onSelected(found.first);
            return null;
          },
        ),
      },
      autofocus: true,
      child: IgnorePointer(
        ignoring: widget.interactionLocked,
        child: Column(
          children: [
            if (_selection.count > 0)
              _BulkBar(
                count: _selection.count,
                board: widget.board,
                onClear: _clear,
                onBulk: _bulk,
                busy: widget.bulkBusy,
              ),
            Expanded(
              child: Row(
                children: [
                  SizedBox(
                    key: const Key('pinned-task-column'),
                    width: taskWidth,
                    child: ListView(
                      controller: _pinned,
                      children: [
                        _header(context, 'Task'),
                        for (final id in ids) ...[
                          _group(
                            context,
                            _groupLabel(id, groups[id] ?? const <TaskRow>[]),
                            _collapsed.contains(id),
                            () => setState(
                              () => _collapsed.contains(id)
                                  ? _collapsed.remove(id)
                                  : _collapsed.add(id),
                            ),
                            widget.configuration.groupBy == null
                                ? () => widget.onAddTask?.call(id as String?)
                                : null,
                          ),
                          if (!_collapsed.contains(id))
                            for (final row in groups[id] ?? const <TaskRow>[])
                              _PinnedRow(
                                key: ValueKey<String>('task:${row.id}:pinned'),
                                task: row,
                                selected: row.id == widget.selectedTaskId,
                                checked: _selection.contains(row.id),
                                onSelect: () => widget.onSelected(row),
                                onCheck: () => _toggle(row),
                                onTitle: (title) => _core(row, 'title', title),
                                onDirtyChanged: (dirty) => widget.onDraftChanged
                                    ?.call('task:${row.id}:title', dirty),
                              ),
                        ],
                      ],
                    ),
                  ),
                  Expanded(
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: SizedBox(
                        width: columns.fold<double>(
                          0,
                          (total, column) => total + column.width,
                        ),
                        child: ListView(
                          controller: _columns,
                          children: [
                            _OperationalHeader(columns: columns),
                            for (final id in ids) ...[
                              _groupSpacer(context),
                              if (!_collapsed.contains(id))
                                for (final row
                                    in groups[id] ?? const <TaskRow>[])
                                  _OperationalRow(
                                    key: ValueKey<String>(
                                      'task:${row.id}:operational',
                                    ),
                                    task: row,
                                    board: widget.board,
                                    columns: columns,
                                    owners: widget.owners,
                                    people: widget.people,
                                    onCore: (field, value) =>
                                        _core(row, field, value),
                                    onCustom: (column, value) =>
                                        _custom(row, column, value),
                                    onDraftChanged: widget.onDraftChanged,
                                  ),
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<_TableColumn> _visibleColumns() {
    final definitions = <String, _TableColumn>{
      'owner': _TableColumn(
        key: 'owner',
        label: _coreLabel('owner', 'Person'),
        width: _width('owner', 150),
      ),
      'status': _TableColumn(
        key: 'status',
        label: _coreLabel('status', 'Status'),
        width: _width('status', 150),
      ),
      'priority': _TableColumn(
        key: 'priority',
        label: _coreLabel('priority', 'Priority'),
        width: _width('priority', 150),
      ),
      'start_on': _TableColumn(
        key: 'start_on',
        label: _coreLabel('start_on', 'Start date'),
        width: _width('start_on', 160),
      ),
      'due_on': _TableColumn(
        key: 'due_on',
        label: _coreLabel('due_on', 'Due date'),
        width: _width('due_on', 160),
      ),
      for (final custom in widget.board.customColumns.where((c) => !c.archived))
        'custom:${custom.id}': _TableColumn(
          key: 'custom:${custom.id}',
          label: custom.name,
          width: _width('custom:${custom.id}', 180),
          custom: custom,
        ),
    };
    final ordered = <_TableColumn>[];
    for (final key in widget.configuration.columnOrder) {
      final column = definitions.remove(key);
      if (column != null && !widget.configuration.hiddenColumns.contains(key)) {
        ordered.add(column);
      }
    }
    for (final entry in definitions.entries) {
      if (!widget.configuration.hiddenColumns.contains(entry.key)) {
        ordered.add(entry.value);
      }
    }
    return ordered;
  }

  double _width(String key, double fallback) =>
      (widget.configuration.columnWidths[key] ?? fallback.round()).toDouble();

  String _coreLabel(String key, String fallback) =>
      widget.board.coreColumns
          .where((column) => column.key == key)
          .map((column) => column.displayLabel)
          .firstOrNull ??
      fallback;

  String _groupName(Object? value) {
    if (widget.configuration.groupBy == null) {
      return widget.board.groups
              .where((group) => group.id == value)
              .map((group) => group.name)
              .firstOrNull ??
          'Ungrouped';
    }
    return switch (widget.configuration.groupBy) {
      'status' =>
        widget.board.statuses
                .where((status) => status.id == value)
                .map((status) => status.name)
                .firstOrNull ??
            'No status',
      'priority' =>
        widget.board.priorities
                .where((priority) => priority.id == value)
                .map((priority) => priority.name)
                .firstOrNull ??
            'No priority',
      'owner' =>
        widget.owners
                .where((person) => person.id == value)
                .map((person) => person.name)
                .firstOrNull ??
            'Unassigned',
      _ => value?.toString() ?? 'No value',
    };
  }

  String _groupLabel(Object? value, List<TaskRow> rows) {
    final matches = widget.groupCounts.where((count) => count.value == value);
    final count = matches.isEmpty ? null : matches.first.count;
    return count == null ? _groupName(value) : '${_groupName(value)} · $count';
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}

Widget _header(BuildContext context, String text) => Container(
  height: 44,
  color: Theme.of(context).colorScheme.surfaceContainerHighest,
  padding: const EdgeInsets.symmetric(horizontal: 16),
  alignment: Alignment.centerLeft,
  child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700)),
);
Widget _group(
  BuildContext context,
  String name,
  bool collapsed,
  VoidCallback toggle,
  VoidCallback? addTask,
) => Container(
  height: 44,
  decoration: BoxDecoration(
    border: Border(
      bottom: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
    ),
  ),
  child: Row(
    children: [
      Container(
        width: 4,
        height: double.infinity,
        color: Theme.of(context).colorScheme.primary,
      ),
      IconButton(
        tooltip: '${collapsed ? 'Expand' : 'Collapse'} $name',
        icon: Icon(collapsed ? Icons.chevron_right : Icons.expand_more),
        onPressed: toggle,
      ),
      Expanded(
        child: Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
      ),
      if (addTask != null)
        IconButton(
          tooltip: 'Add task to $name',
          icon: const Icon(Icons.add, size: 19),
          onPressed: addTask,
        ),
    ],
  ),
);
Widget _groupSpacer(BuildContext context) => Container(
  height: 44,
  decoration: BoxDecoration(
    border: Border(
      bottom: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
    ),
  ),
);

class _BulkBar extends StatelessWidget {
  const _BulkBar({
    required this.count,
    required this.board,
    required this.onClear,
    required this.onBulk,
    required this.busy,
  });
  final int count;
  final TaskBoard board;
  final VoidCallback onClear;
  final Future<void> Function(Map<String, Object?>) onBulk;
  final bool busy;
  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.secondaryContainer,
    child: SizedBox(
      height: 52,
      child: Row(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text('$count selected'),
          ),
          DropdownButton<String>(
            hint: const Text('Set status'),
            items: board.statuses
                .where((s) => !s.archived)
                .map((s) => DropdownMenuItem(value: s.id, child: Text(s.name)))
                .toList(),
            onChanged: busy
                ? null
                : (value) {
                    if (value != null) onBulk({'status_option_id': value});
                  },
          ),
          const SizedBox(width: 8),
          DropdownButton<String>(
            hint: const Text('Set priority'),
            items: board.priorities
                .where((p) => !p.archived)
                .map((p) => DropdownMenuItem(value: p.id, child: Text(p.name)))
                .toList(),
            onChanged: busy
                ? null
                : (value) {
                    if (value != null) onBulk({'priority_option_id': value});
                  },
          ),
          TextButton(
            onPressed: busy ? null : () => onBulk({'archived': true}),
            child: const Text('Archive'),
          ),
          const Spacer(),
          TextButton(
            onPressed: busy ? null : onClear,
            child: Text(busy ? 'Saving…' : 'Clear selection'),
          ),
        ],
      ),
    ),
  );
}

class _PinnedRow extends StatefulWidget {
  const _PinnedRow({
    super.key,
    required this.task,
    required this.selected,
    required this.checked,
    required this.onSelect,
    required this.onCheck,
    required this.onTitle,
    required this.onDirtyChanged,
  });
  final TaskRow task;
  final bool selected;
  final bool checked;
  final VoidCallback onSelect;
  final VoidCallback onCheck;
  final Future<void> Function(String) onTitle;
  final ValueChanged<bool> onDirtyChanged;
  @override
  State<_PinnedRow> createState() => _PinnedRowState();
}

class _PinnedRowState extends State<_PinnedRow> {
  late final _title = TextEditingController(text: widget.task.title);
  late final _focus = FocusNode();
  bool _dirty = false;
  @override
  void didUpdateWidget(covariant _PinnedRow old) {
    super.didUpdateWidget(old);
    if (old.task.title == widget.task.title) return;
    if (_title.text == widget.task.title) {
      _dirty = false;
      widget.onDirtyChanged(false);
    } else if (!_dirty) {
      _title.text = widget.task.title;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDirtyChanged(false);
    _title.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: widget.selected,
    label: 'Task ${widget.task.number}: ${widget.task.title}',
    child: InkWell(
      key: Key('task-row-${widget.task.id}'),
      onTap: widget.onSelect,
      child: Container(
        height: 52,
        decoration: BoxDecoration(
          color: widget.selected
              ? Theme.of(context).colorScheme.primaryContainer
              : null,
          border: Border(
            bottom: BorderSide(
              color: Theme.of(context).colorScheme.outlineVariant,
            ),
          ),
        ),
        child: Row(
          children: [
            Checkbox(value: widget.checked, onChanged: (_) => widget.onCheck()),
            Expanded(
              child: TextField(
                controller: _title,
                focusNode: _focus,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  isDense: true,
                ),
                onTap: widget.onSelect,
                onChanged: (value) {
                  _dirty = value != widget.task.title;
                  widget.onDirtyChanged(_dirty);
                },
                onSubmitted: (value) async {
                  await widget.onTitle(value);
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _TableColumn {
  const _TableColumn({
    required this.key,
    required this.label,
    required this.width,
    this.custom,
  });

  final String key;
  final String label;
  final double width;
  final TaskColumnDefinition? custom;
}

class _OperationalHeader extends StatelessWidget {
  const _OperationalHeader({required this.columns});
  final List<_TableColumn> columns;
  @override
  Widget build(BuildContext context) => Container(
    height: 44,
    color: Theme.of(context).colorScheme.surfaceContainerHighest,
    child: Row(
      children: [
        for (final column in columns)
          SizedBox(
            width: column.width,
            child: Text(
              column.label,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
      ],
    ),
  );
}

class _OperationalRow extends StatelessWidget {
  const _OperationalRow({
    super.key,
    required this.task,
    required this.board,
    required this.columns,
    required this.owners,
    required this.people,
    required this.onCore,
    required this.onCustom,
    this.onDraftChanged,
  });
  final TaskRow task;
  final TaskBoard board;
  final List<_TableColumn> columns;
  final List<TaskParticipant> owners;
  final List<TaskParticipant> people;
  final Future<void> Function(String, Object?) onCore;
  final Future<void> Function(TaskColumnDefinition, Object?) onCustom;
  final TaskDraftChanged? onDraftChanged;
  @override
  Widget build(BuildContext context) => Semantics(
    container: true,
    label: 'Task ${task.number} operational fields',
    child: Container(
      height: 52,
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(
            color: Theme.of(context).colorScheme.outlineVariant,
          ),
        ),
      ),
      child: Row(
        children: [
          for (final column in columns)
            SizedBox(
              key: ValueKey<String>('task:${task.id}:column:${column.key}'),
              width: column.width,
              child: _cell(column),
            ),
        ],
      ),
    ),
  );

  Widget _cell(_TableColumn column) {
    if (column.custom case final custom?) {
      return TaskColumnCell(
        task: task,
        definition: custom,
        value:
            task.values[custom.id] ??
            TaskCellValue(
              kind: custom.kind,
              value: null,
              rawValue: null,
              isReadOnly: custom.isReadOnly,
            ),
        participants: people,
        onChanged: onCustom,
        onDraftChanged: onDraftChanged,
      );
    }
    return switch (column.key) {
      'status' => Semantics(
        label: 'Task ${task.number} status',
        child: _ArchivedOrOption(
          value: task.statusOptionId,
          hint: 'No status',
          options: board.statuses
              .map(
                (status) =>
                    _OptionEntry(status.id, status.name, status.archived),
              )
              .toList(),
          changed: (value) => onCore('status_option_id', value),
        ),
      ),
      'owner' => Semantics(
        label: 'Task ${task.number} owner',
        child: _Option(
          value: task.ownerUserId,
          hint: 'Unassigned',
          options: owners.map((person) => (person.id, person.name)).toList(),
          changed: (value) => onCore('owner_user_id', value),
        ),
      ),
      'priority' => Semantics(
        label: 'Task ${task.number} priority',
        child: _ArchivedOrOption(
          value: task.priorityOptionId,
          hint: 'No priority',
          options: board.priorities
              .map(
                (priority) =>
                    _OptionEntry(priority.id, priority.name, priority.archived),
              )
              .toList(),
          changed: (value) => onCore('priority_option_id', value),
        ),
      ),
      'start_on' => Semantics(
        label: 'Task ${task.number} start date',
        child: _Date(
          value: task.startOn,
          changed: (value) => onCore('start_on', value),
          draftId: 'task:${task.id}:core:start_on',
          onDraftChanged: onDraftChanged,
        ),
      ),
      'due_on' => Semantics(
        label: 'Task ${task.number} due date',
        child: _Date(
          value: task.dueOn,
          changed: (value) => onCore('due_on', value),
          draftId: 'task:${task.id}:core:due_on',
          onDraftChanged: onDraftChanged,
        ),
      ),
      _ => const SizedBox.shrink(),
    };
  }
}

class _Option extends StatelessWidget {
  const _Option({
    required this.value,
    required this.hint,
    required this.options,
    required this.changed,
  });
  final String? value;
  final String hint;
  final List<(String, String)> options;
  final ValueChanged<String?> changed;
  @override
  Widget build(BuildContext context) => DropdownButton<String>(
    isExpanded: true,
    value: options.any((o) => o.$1 == value) ? value : null,
    hint: Text(hint),
    items: options
        .map((o) => DropdownMenuItem(value: o.$1, child: Text(o.$2)))
        .toList(),
    onChanged: changed,
  );
}

class _OptionEntry {
  const _OptionEntry(this.id, this.name, this.archived);
  final String id;
  final String name;
  final bool archived;
}

class _ArchivedOrOption extends StatelessWidget {
  const _ArchivedOrOption({
    required this.value,
    required this.hint,
    required this.options,
    required this.changed,
  });
  final String? value;
  final String hint;
  final List<_OptionEntry> options;
  final ValueChanged<String?> changed;
  @override
  Widget build(BuildContext context) {
    final current = options.where((option) => option.id == value).firstOrNull;
    if (current?.archived ?? false) {
      return Semantics(
        readOnly: true,
        label: '$hint ${current!.name}. Archived.',
        child: Text(current.name),
      );
    }
    return _Option(
      value: value,
      hint: hint,
      options: options
          .where((option) => !option.archived)
          .map((option) => (option.id, option.name))
          .toList(),
      changed: changed,
    );
  }
}

class _Date extends StatefulWidget {
  const _Date({
    required this.value,
    required this.changed,
    required this.draftId,
    this.onDraftChanged,
  });
  final DateTime? value;
  final Future<void> Function(String?) changed;
  final String draftId;
  final TaskDraftChanged? onDraftChanged;
  @override
  State<_Date> createState() => _DateState();
}

class _DateState extends State<_Date> {
  late final _controller = TextEditingController(
    text: widget.value == null
        ? ''
        : '${widget.value!.year.toString().padLeft(4, '0')}-${widget.value!.month.toString().padLeft(2, '0')}-${widget.value!.day.toString().padLeft(2, '0')}',
  );
  bool _dirty = false;

  String get _initialText => widget.value == null
      ? ''
      : '${widget.value!.year.toString().padLeft(4, '0')}-${widget.value!.month.toString().padLeft(2, '0')}-${widget.value!.day.toString().padLeft(2, '0')}';

  @override
  void didUpdateWidget(covariant _Date oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.value == widget.value) return;
    if (_controller.text == _initialText) {
      _dirty = false;
      widget.onDraftChanged?.call(widget.draftId, false);
    } else if (!_dirty) {
      _controller.text = _initialText;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(widget.draftId, false);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    decoration: const InputDecoration(isDense: true, hintText: 'YYYY-MM-DD'),
    onChanged: (value) {
      _dirty = value != _initialText;
      widget.onDraftChanged?.call(widget.draftId, _dirty);
    },
    onSubmitted: (value) async {
      await widget.changed(value.trim().isEmpty ? null : value.trim());
    },
  );
}

class _ClearIntent extends Intent {
  const _ClearIntent();
}

class _MoveIntent extends Intent {
  const _MoveIntent(this.delta);
  final int delta;
}

class _ActivateIntent extends Intent {
  const _ActivateIntent();
}
