import 'package:flutter/material.dart';

import 'task_models.dart';

class TaskViewsBar extends StatelessWidget {
  const TaskViewsBar({
    super.key,
    required this.views,
    required this.activeViewId,
    required this.canCreatePersonal,
    required this.dirty,
    required this.onSelected,
    required this.onCreate,
    required this.onSave,
    required this.onDuplicate,
    required this.onDelete,
    this.width,
  });

  final List<TaskViewDefinition> views;
  final String? activeViewId;
  final bool canCreatePersonal;
  final bool dirty;
  final ValueChanged<TaskViewDefinition> onSelected;
  final VoidCallback onCreate;
  final ValueChanged<TaskViewDefinition> onSave;
  final ValueChanged<TaskViewDefinition> onDuplicate;
  final ValueChanged<TaskViewDefinition> onDelete;

  /// The actual width THIS bar has to work with — see
  /// `TaskPresentationBar.width` for the full account of why this is a
  /// caller-supplied measurement now, not `MediaQuery`. `null` (any caller
  /// with no sibling pane squeezing it) falls back to `MediaQuery`.
  final double? width;

  TaskViewDefinition? get _active {
    for (final view in views) {
      if (view.id == activeViewId) return view;
    }
    return null;
  }

  /// Same breakpoint as `TaskPresentationBar`, the bar directly below this
  /// one — the two are meant to break together, not at different widths,
  /// and now against the same caller-supplied `width` too (Task 15
  /// correction — see `TaskPresentationBar._narrowBreakpoint`).
  static const _narrowBreakpoint = 700.0;

  List<Widget> _viewChips() => [
    for (final view in views)
      ChoiceChip(
        selected: view.id == activeViewId,
        label: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(view.name),
            if (view.isLocked) ...[
              const SizedBox(width: 4),
              const Tooltip(
                message: 'Locked shared view',
                child: Icon(Icons.lock_outline, size: 15),
              ),
            ],
            if (dirty && view.id == activeViewId)
              const Padding(
                padding: EdgeInsets.only(left: 4),
                child: Text('•'),
              ),
          ],
        ),
        onSelected: (_) => onSelected(view),
      ),
  ];

  List<Widget> _trailingActions(TaskViewDefinition? active) => [
    if (canCreatePersonal)
      IconButton(
        tooltip: 'New saved view',
        onPressed: onCreate,
        icon: const Icon(Icons.add),
      ),
    if (active != null)
      PopupMenuButton<String>(
        tooltip: 'Saved view actions',
        onSelected: (action) {
          switch (action) {
            case 'save':
              onSave(active);
            case 'duplicate':
              onDuplicate(active);
            case 'delete':
              onDelete(active);
          }
        },
        itemBuilder: (_) => [
          if (active.can('update'))
            PopupMenuItem(
              value: 'save',
              enabled: dirty,
              child: const Text('Save changes'),
            ),
          if (active.can('duplicate'))
            const PopupMenuItem(
              value: 'duplicate',
              child: Text('Duplicate view'),
            ),
          if (active.can('delete'))
            const PopupMenuItem(value: 'delete', child: Text('Delete view')),
        ],
      ),
  ];

  @override
  Widget build(BuildContext context) {
    final active = _active;
    final narrow = (width ?? MediaQuery.sizeOf(context).width) < _narrowBreakpoint;
    return Material(
      color: Theme.of(context).colorScheme.surface,
      child: narrow ? _wrapped(active) : _scrolled(active),
    );
  }

  // Narrow: the saved-view chips and the trailing actions wrap instead of
  // scrolling sideways in a fixed-height strip — the same chrome-not-data
  // rule `TaskPresentationBar` follows just below this bar.
  Widget _wrapped(TaskViewDefinition? active) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
    child: Wrap(
      spacing: 6,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [..._viewChips(), ..._trailingActions(active)],
    ),
  );

  Widget _scrolled(TaskViewDefinition? active) => SizedBox(
    height: 48,
    child: Row(
      children: [
        Expanded(
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            children: [
              for (final chip in _viewChips())
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 2,
                    vertical: 6,
                  ),
                  child: chip,
                ),
            ],
          ),
        ),
        ..._trailingActions(active),
      ],
    ),
  );
}
