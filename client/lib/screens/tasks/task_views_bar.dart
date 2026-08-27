import 'package:flutter/material.dart';

import 'task_models.dart';

class TaskViewsBar extends StatelessWidget {
  const TaskViewsBar({
    super.key,
    required this.views,
    required this.activeViewId,
    required this.capabilities,
    required this.dirty,
    required this.onSelected,
    required this.onCreate,
    required this.onSave,
    required this.onDuplicate,
    required this.onDelete,
  });

  final List<TaskViewDefinition> views;
  final String? activeViewId;
  final TaskViewCapabilities capabilities;
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

  bool _mayEdit(TaskViewDefinition view) {
    if (view.visibility == TaskViewVisibility.personal) {
      return capabilities.personalViews;
    }
    return view.isLocked
        ? capabilities.sharedLockedViews
        : capabilities.sharedUnlockedViews;
  }

  @override
  Widget build(BuildContext context) {
    final active = _active;
    return Material(
      color: Theme.of(context).colorScheme.surface,
      child: SizedBox(
        height: 48,
        child: Row(
          children: [
            Expanded(
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 8),
                children: [
                  for (final view in views)
                    Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 2,
                        vertical: 6,
                      ),
                      child: ChoiceChip(
                        selected: view.id == activeViewId,
                        label: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(view.name),
                            if (view.isLocked) ...[
                              const SizedBox(width: 4),
                              Tooltip(
                                message: 'Locked shared view',
                                child: const Icon(Icons.lock_outline, size: 15),
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
                    ),
                ],
              ),
            ),
            if (capabilities.personalViews)
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
                  if (_mayEdit(active))
                    PopupMenuItem(
                      value: 'save',
                      enabled: dirty,
                      child: const Text('Save changes'),
                    ),
                  if (capabilities.personalViews)
                    const PopupMenuItem(
                      value: 'duplicate',
                      child: Text('Duplicate view'),
                    ),
                  if (!active.isPermanentMain &&
                      ((active.visibility == TaskViewVisibility.personal &&
                              capabilities.personalViews) ||
                          capabilities.sharedLockedViews))
                    const PopupMenuItem(
                      value: 'delete',
                      child: Text('Delete view'),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
