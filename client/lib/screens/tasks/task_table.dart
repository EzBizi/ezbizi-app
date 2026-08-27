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
  @override
  State<TaskTable> createState() => _TaskTableState();
}

class _TaskTableState extends State<TaskTable> {
  final _selection = TaskSelection();
  final _pinned = ScrollController();
  final _columns = ScrollController();
  final _collapsed = <String>{};
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
    _selection.toggle(task);
    widget.onSelectionChanged?.call(_selection.count);
  });
  void _clear() => setState(() {
    _selection.clear();
    widget.onSelectionChanged?.call(0);
  });
  Future<void> _core(TaskRow task, String field, Object? value) async {
    if (field == 'title' && widget.onCoreChanged == null)
      widget.onTitleChanged(task, value as String);
    await widget.onCoreChanged?.call(task, field, value);
  }

  Future<void> _custom(
    TaskRow task,
    TaskColumnDefinition column,
    Object? value,
  ) async => widget.onCustomChanged?.call(task, column, value);
  Future<void> _bulk(Map<String, Object?> changes) async {
    final rows = widget.rows
        .where((row) => _selection.contains(row.id))
        .toList();
    if (rows.isNotEmpty) await widget.onBulkChanged?.call(rows, changes);
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String?, List<TaskRow>>{};
    for (final row in widget.rows) {
      (groups[row.groupId] ??= []).add(row);
    }
    final ids = <String?>[
      ...widget.board.groups.where((g) => !g.archived).map((g) => g.id),
      ...groups.keys.where((id) => !widget.board.groups.any((g) => g.id == id)),
    ];
    final custom = widget.board.customColumns
        .where((c) => !c.archived)
        .toList();
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
            _clear();
            widget.onSelectionCleared?.call();
            return null;
          },
        ),
        _MoveIntent: CallbackAction<_MoveIntent>(
          onInvoke: (intent) {
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
            final found = widget.rows.where(
              (row) => row.id == widget.selectedTaskId,
            );
            if (found.isNotEmpty) widget.onSelected(found.first);
            return null;
          },
        ),
      },
      autofocus: true,
      child: Column(
        children: [
          if (_selection.count > 0)
            _BulkBar(
              count: _selection.count,
              board: widget.board,
              onClear: _clear,
              onBulk: _bulk,
            ),
          Expanded(
            child: Row(
              children: [
                SizedBox(
                  key: const Key('pinned-task-column'),
                  width: 300,
                  child: ListView(
                    controller: _pinned,
                    children: [
                      _header(context, 'Task'),
                      for (final id in ids)
                        if (groups[id] case final rows?) ...[
                          _group(
                            context,
                            widget.board.groups
                                    .where((g) => g.id == id)
                                    .map((g) => g.name)
                                    .firstOrNull ??
                                'Ungrouped',
                            _collapsed.contains(id),
                            () => setState(
                              () => _collapsed.contains(id)
                                  ? _collapsed.remove(id)
                                  : _collapsed.add(id ?? ''),
                            ),
                          ),
                          if (!_collapsed.contains(id))
                            for (final row in rows)
                              _PinnedRow(
                                task: row,
                                selected: row.id == widget.selectedTaskId,
                                checked: _selection.contains(row.id),
                                onSelect: () => widget.onSelected(row),
                                onCheck: () => _toggle(row),
                                onTitle: (title) => _core(row, 'title', title),
                              ),
                        ],
                    ],
                  ),
                ),
                Expanded(
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: SizedBox(
                      width: 610.0 + 180 * custom.length,
                      child: ListView(
                        controller: _columns,
                        children: [
                          _OperationalHeader(custom: custom),
                          for (final id in ids)
                            if (groups[id] case final rows?) ...[
                              _groupSpacer(context),
                              if (!_collapsed.contains(id))
                                for (final row in rows)
                                  _OperationalRow(
                                    task: row,
                                    board: widget.board,
                                    custom: custom,
                                    owners: widget.owners,
                                    people: widget.people,
                                    onCore: (field, value) =>
                                        _core(row, field, value),
                                    onCustom: (column, value) =>
                                        _custom(row, column, value),
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
    );
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
      Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
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
  });
  final int count;
  final TaskBoard board;
  final VoidCallback onClear;
  final Future<void> Function(Map<String, Object?>) onBulk;
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
            onChanged: (value) {
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
            onChanged: (value) {
              if (value != null) onBulk({'priority_option_id': value});
            },
          ),
          TextButton(
            onPressed: () => onBulk({'archived': true}),
            child: const Text('Archive'),
          ),
          const Spacer(),
          TextButton(onPressed: onClear, child: const Text('Clear selection')),
        ],
      ),
    ),
  );
}

class _PinnedRow extends StatefulWidget {
  const _PinnedRow({
    required this.task,
    required this.selected,
    required this.checked,
    required this.onSelect,
    required this.onCheck,
    required this.onTitle,
  });
  final TaskRow task;
  final bool selected;
  final bool checked;
  final VoidCallback onSelect;
  final VoidCallback onCheck;
  final ValueChanged<String> onTitle;
  @override
  State<_PinnedRow> createState() => _PinnedRowState();
}

class _PinnedRowState extends State<_PinnedRow> {
  late final _title = TextEditingController(text: widget.task.title);
  late final _focus = FocusNode();
  @override
  void didUpdateWidget(covariant _PinnedRow old) {
    super.didUpdateWidget(old);
    if (!_focus.hasFocus && old.task.title != widget.task.title)
      _title.text = widget.task.title;
  }

  @override
  void dispose() {
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
                onSubmitted: widget.onTitle,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _OperationalHeader extends StatelessWidget {
  const _OperationalHeader({required this.custom});
  final List<TaskColumnDefinition> custom;
  @override
  Widget build(BuildContext context) => Container(
    height: 44,
    color: Theme.of(context).colorScheme.surfaceContainerHighest,
    child: Row(
      children: [
        for (final label in ['Status', 'Owner', 'Priority'])
          SizedBox(
            width: 150,
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        const SizedBox(
          width: 160,
          child: Text(
            'Due date',
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        for (final c in custom)
          SizedBox(
            width: 180,
            child: Text(c.name, style: TextStyle(fontWeight: FontWeight.w700)),
          ),
      ],
    ),
  );
}

class _OperationalRow extends StatelessWidget {
  const _OperationalRow({
    required this.task,
    required this.board,
    required this.custom,
    required this.owners,
    required this.people,
    required this.onCore,
    required this.onCustom,
  });
  final TaskRow task;
  final TaskBoard board;
  final List<TaskColumnDefinition> custom;
  final List<TaskParticipant> owners;
  final List<TaskParticipant> people;
  final Future<void> Function(String, Object?) onCore;
  final Future<void> Function(TaskColumnDefinition, Object?) onCustom;
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
          SizedBox(
            width: 150,
            child: Semantics(
              label: 'Task ${task.number} status',
              child: _Option(
                value: task.statusOptionId,
                hint: 'No status',
                options: board.statuses
                    .where((s) => !s.archived)
                    .map((s) => (s.id, s.name))
                    .toList(),
                changed: (value) => onCore('status_option_id', value),
              ),
            ),
          ),
          SizedBox(
            width: 150,
            child: Semantics(
              label: 'Task ${task.number} owner',
              child: _Option(
                value: task.ownerUserId,
                hint: 'Unassigned',
                options: owners.map((p) => (p.id, p.name)).toList(),
                changed: (value) => onCore('owner_user_id', value),
              ),
            ),
          ),
          SizedBox(
            width: 150,
            child: Semantics(
              label: 'Task ${task.number} priority',
              child: _Option(
                value: task.priorityOptionId,
                hint: 'No priority',
                options: board.priorities
                    .where((p) => !p.archived)
                    .map((p) => (p.id, p.name))
                    .toList(),
                changed: (value) => onCore('priority_option_id', value),
              ),
            ),
          ),
          SizedBox(
            width: 160,
            child: Semantics(
              label: 'Task ${task.number} due date',
              child: _Date(
                value: task.dueOn,
                changed: (value) => onCore('due_on', value),
              ),
            ),
          ),
          for (final c in custom)
            SizedBox(
              width: 180,
              child: TaskColumnCell(
                task: task,
                definition: c,
                value:
                    task.values[c.id] ??
                    TaskCellValue(
                      kind: c.kind,
                      value: null,
                      rawValue: null,
                      isReadOnly: c.isReadOnly,
                    ),
                participants: people,
                onChanged: onCustom,
              ),
            ),
        ],
      ),
    ),
  );
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

class _Date extends StatefulWidget {
  const _Date({required this.value, required this.changed});
  final DateTime? value;
  final ValueChanged<String?> changed;
  @override
  State<_Date> createState() => _DateState();
}

class _DateState extends State<_Date> {
  late final _controller = TextEditingController(
    text: widget.value == null
        ? ''
        : '${widget.value!.year.toString().padLeft(4, '0')}-${widget.value!.month.toString().padLeft(2, '0')}-${widget.value!.day.toString().padLeft(2, '0')}',
  );
  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    decoration: const InputDecoration(isDense: true, hintText: 'YYYY-MM-DD'),
    onSubmitted: (value) =>
        widget.changed(value.trim().isEmpty ? null : value.trim()),
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
