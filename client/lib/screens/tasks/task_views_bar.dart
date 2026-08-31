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

  TaskViewDefinition? get _active {
    for (final view in views) {
      if (view.id == activeViewId) return view;
    }
    return null;
  }

  /// Same breakpoint as `TaskPresentationBar`, the bar directly below this
  /// one — the two are meant to break together, not at different widths.
  /// Read from `MediaQuery`, not this bar's own local constraint: see
  /// `TaskPresentationBar._narrowBreakpoint` for why (a sibling detail panel
  /// can squeeze this bar's own width well below 700 on a screen nowhere
  /// near narrow, and wrapping would cost height the table below cannot
  /// spare).
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
    final narrow = MediaQuery.sizeOf(context).width < _narrowBreakpoint;
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
