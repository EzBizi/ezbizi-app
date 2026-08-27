import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'task_models.dart';

/// Dense desktop representation. Its horizontal scroll belongs to the columns,
/// never the page; task identity stays at the left edge of each row.
class TaskTable extends StatefulWidget {
  const TaskTable({
    super.key,
    required this.board,
    required this.rows,
    required this.selectedTaskId,
    required this.onSelected,
    required this.onTitleChanged,
    this.onSelectionCleared,
  });

  final TaskBoard board;
  final List<TaskRow> rows;
  final String? selectedTaskId;
  final ValueChanged<TaskRow> onSelected;
  final void Function(TaskRow task, String title) onTitleChanged;
  final VoidCallback? onSelectionCleared;

  @override
  State<TaskTable> createState() => _TaskTableState();
}

class _TaskTableState extends State<TaskTable> {
  final Set<String> _collapsedGroups = <String>{};
  final ScrollController _pinnedScroll = ScrollController();
  final ScrollController _operationalScroll = ScrollController();
  bool _syncingScroll = false;
  @override
  void initState() {
    super.initState();
    _pinnedScroll.addListener(() => _sync(_pinnedScroll, _operationalScroll));
    _operationalScroll.addListener(
      () => _sync(_operationalScroll, _pinnedScroll),
    );
  }

  void _sync(ScrollController source, ScrollController target) {
    if (_syncingScroll || !target.hasClients) return;
    _syncingScroll = true;
    target.jumpTo(
      source.offset.clamp(
        target.position.minScrollExtent,
        target.position.maxScrollExtent,
      ),
    );
    _syncingScroll = false;
  }

  @override
  void dispose() {
    _pinnedScroll.dispose();
    _operationalScroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String?, List<TaskRow>>{};
    for (final row in widget.rows) {
      (groups[row.groupId] ??= <TaskRow>[]).add(row);
    }
    final ordered = <String?>[
      ...widget.board.groups
          .where((group) => !group.archived)
          .map((group) => group.id),
      ...groups.keys.where(
        (key) => !widget.board.groups.any((group) => group.id == key),
      ),
    ];
    final theme = Theme.of(context);
    return FocusableActionDetector(
      shortcuts: <ShortcutActivator, Intent>{
        SingleActivator(LogicalKeyboardKey.escape): _ClearTaskSelectionIntent(),
        SingleActivator(LogicalKeyboardKey.arrowDown): _MoveTaskIntent(1),
        SingleActivator(LogicalKeyboardKey.arrowUp): _MoveTaskIntent(-1),
        SingleActivator(LogicalKeyboardKey.enter): _ActivateTaskIntent(),
      },
      actions: <Type, Action<Intent>>{
        _ClearTaskSelectionIntent: CallbackAction<_ClearTaskSelectionIntent>(
          onInvoke: (_) {
            FocusManager.instance.primaryFocus?.unfocus();
            widget.onSelectionCleared?.call();
            return null;
          },
        ),
        _MoveTaskIntent: CallbackAction<_MoveTaskIntent>(
          onInvoke: (intent) {
            final rows = widget.rows;
            if (rows.isEmpty) return null;
            final at = rows.indexWhere(
              (row) => row.id == widget.selectedTaskId,
            );
            widget.onSelected(
              rows[(at + intent.delta).clamp(0, rows.length - 1)],
            );
            return null;
          },
        ),
        _ActivateTaskIntent: CallbackAction<_ActivateTaskIntent>(
          onInvoke: (_) {
            final selected = widget.rows
                .where((row) => row.id == widget.selectedTaskId)
                .firstOrNull;
            if (selected != null) widget.onSelected(selected);
            return null;
          },
        ),
      },
      autofocus: true,
      child: Row(
        children: [
          SizedBox(
            key: const Key('pinned-task-column'),
            width: 300,
            child: ListView(
              controller: _pinnedScroll,
              children: [
                _PinnedHeader(color: theme.colorScheme),
                for (final groupId in ordered)
                  if (groups[groupId] case final rows?) ...[
                    _GroupHeader(
                      name:
                          widget.board.groups
                              .where((group) => group.id == groupId)
                              .map((group) => group.name)
                              .firstOrNull ??
                          'Ungrouped',
                      collapsed: _collapsedGroups.contains(groupId),
                      onToggle: () => setState(
                        () => _collapsedGroups.contains(groupId)
                            ? _collapsedGroups.remove(groupId)
                            : _collapsedGroups.add(groupId ?? ''),
                      ),
                    ),
                    if (!_collapsedGroups.contains(groupId))
                      for (final task in rows)
                        _PinnedTaskRow(
                          task: task,
                          selected: task.id == widget.selectedTaskId,
                          onSelected: () => widget.onSelected(task),
                          onTitleChanged: (title) =>
                              widget.onTitleChanged(task, title),
                        ),
                  ],
              ],
            ),
          ),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: SizedBox(
                width: 460,
                child: ListView(
                  controller: _operationalScroll,
                  children: [
                    _OperationalHeader(color: theme.colorScheme),
                    for (final groupId in ordered)
                      if (groups[groupId] case final rows?) ...[
                        const _OperationalGroupHeader(),
                        if (!_collapsedGroups.contains(groupId))
                          for (final task in rows)
                            _OperationalTaskRow(
                              task: task,
                              status: _status(task.statusOptionId),
                            ),
                      ],
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  TaskStatusOption? _status(String? id) => widget.board.statuses
      .where((status) => status.id == id)
      .cast<TaskStatusOption?>()
      .firstOrNull;
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}

class _PinnedHeader extends StatelessWidget {
  const _PinnedHeader({required this.color});
  final ColorScheme color;
  @override
  Widget build(BuildContext context) => Container(
    height: 44,
    color: color.surfaceContainerHighest,
    child: const Padding(
      padding: EdgeInsets.symmetric(horizontal: 16),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Text('Task', style: TextStyle(fontWeight: FontWeight.w700)),
      ),
    ),
  );
}

class _OperationalHeader extends StatelessWidget {
  const _OperationalHeader({required this.color});
  final ColorScheme color;
  @override
  Widget build(BuildContext context) => Container(
    height: 44,
    color: color.surfaceContainerHighest,
    child: const Row(
      children: [
        SizedBox(
          width: 150,
          child: Text('Status', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        SizedBox(
          width: 150,
          child: Text('Owner', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        SizedBox(
          width: 140,
          child: Text(
            'Due date',
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
      ],
    ),
  );
}

class _OperationalGroupHeader extends StatelessWidget {
  const _OperationalGroupHeader();
  @override
  Widget build(BuildContext context) => Container(
    height: 44,
    decoration: BoxDecoration(
      border: Border(
        bottom: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
      ),
    ),
  );
}

class _GroupHeader extends StatelessWidget {
  const _GroupHeader({
    required this.name,
    required this.collapsed,
    required this.onToggle,
  });
  final String name;
  final bool collapsed;
  final VoidCallback onToggle;
  @override
  Widget build(BuildContext context) => Container(
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
          onPressed: onToggle,
        ),
        Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
      ],
    ),
  );
}

class _PinnedTaskRow extends StatefulWidget {
  const _PinnedTaskRow({
    required this.task,
    required this.selected,
    required this.onSelected,
    required this.onTitleChanged,
  });
  final TaskRow task;
  final bool selected;
  final VoidCallback onSelected;
  final ValueChanged<String> onTitleChanged;
  @override
  State<_PinnedTaskRow> createState() => _PinnedTaskRowState();
}

class _PinnedTaskRowState extends State<_PinnedTaskRow> {
  late final TextEditingController _title = TextEditingController(
    text: widget.task.title,
  );
  late final FocusNode _focus = FocusNode();
  @override
  void didUpdateWidget(covariant _PinnedTaskRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_focus.hasFocus && oldWidget.task.title != widget.task.title)
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
      onTap: widget.onSelected,
      child: Container(
        height: 52,
        color: widget.selected
            ? Theme.of(context).colorScheme.primaryContainer
            : null,
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(
              color: Theme.of(context).colorScheme.outlineVariant,
            ),
          ),
        ),
        child: Row(
          children: [
            const SizedBox(width: 16),
            const Icon(Icons.radio_button_unchecked, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _title,
                focusNode: _focus,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  isDense: true,
                ),
                style: const TextStyle(fontWeight: FontWeight.w600),
                onTap: widget.onSelected,
                onSubmitted: widget.onTitleChanged,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _OperationalTaskRow extends StatelessWidget {
  const _OperationalTaskRow({required this.task, required this.status});
  final TaskRow task;
  final TaskStatusOption? status;
  @override
  Widget build(BuildContext context) => Container(
    height: 52,
    decoration: BoxDecoration(
      border: Border(
        bottom: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
      ),
    ),
    child: Row(
      children: [
        SizedBox(width: 150, child: Text(status?.name ?? 'No status')),
        SizedBox(
          width: 150,
          child: Text(task.ownerUserId == null ? 'Unassigned' : 'Assigned'),
        ),
        SizedBox(
          width: 140,
          child: Text(
            task.dueOn == null
                ? 'No due date'
                : '${task.dueOn!.month}/${task.dueOn!.day}/${task.dueOn!.year}',
          ),
        ),
      ],
    ),
  );
}

class _TaskGridRow extends StatefulWidget {
  const _TaskGridRow({
    required this.task,
    required this.selected,
    required this.status,
    required this.onSelected,
    required this.onTitleChanged,
  });
  final TaskRow task;
  final bool selected;
  final TaskStatusOption? status;
  final VoidCallback onSelected;
  final ValueChanged<String> onTitleChanged;
  @override
  State<_TaskGridRow> createState() => _TaskGridRowState();
}

class _TaskGridRowState extends State<_TaskGridRow> {
  late final TextEditingController _title = TextEditingController(
    text: widget.task.title,
  );
  late final FocusNode _focus = FocusNode();
  @override
  void didUpdateWidget(covariant _TaskGridRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_focus.hasFocus && oldWidget.task.title != widget.task.title) {
      _title.text = widget.task.title;
    }
  }

  @override
  void dispose() {
    _title.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Semantics(
      button: true,
      selected: widget.selected,
      label: 'Task ${widget.task.number}: ${widget.task.title}',
      child: InkWell(
        onTap: widget.onSelected,
        child: Container(
          height: 52,
          color: widget.selected ? colors.primaryContainer : null,
          decoration: BoxDecoration(
            border: Border(bottom: BorderSide(color: colors.outlineVariant)),
          ),
          child: Row(
            children: [
              SizedBox(
                width: 300,
                child: Row(
                  children: [
                    const SizedBox(width: 16),
                    Icon(
                      widget.status?.category == TaskStatusCategory.completed
                          ? Icons.check_circle_outline
                          : Icons.radio_button_unchecked,
                      size: 18,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: _title,
                        focusNode: _focus,
                        key: ValueKey('task-title-${widget.task.id}'),
                        decoration: const InputDecoration(
                          border: InputBorder.none,
                          isDense: true,
                        ),
                        style: const TextStyle(fontWeight: FontWeight.w600),
                        onTap: widget.onSelected,
                        onSubmitted: widget.onTitleChanged,
                      ),
                    ),
                  ],
                ),
              ),
              SizedBox(
                width: 150,
                child: Text(widget.status?.name ?? 'No status'),
              ),
              SizedBox(
                width: 150,
                child: Text(
                  widget.task.ownerUserId == null ? 'Unassigned' : 'Assigned',
                ),
              ),
              SizedBox(
                width: 140,
                child: Text(
                  widget.task.dueOn == null
                      ? 'No due date'
                      : '${widget.task.dueOn!.month}/${widget.task.dueOn!.day}/${widget.task.dueOn!.year}',
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ClearTaskSelectionIntent extends Intent {
  const _ClearTaskSelectionIntent();
}

class _MoveTaskIntent extends Intent {
  const _MoveTaskIntent(this.delta);
  final int delta;
}

class _ActivateTaskIntent extends Intent {
  const _ActivateTaskIntent();
}
