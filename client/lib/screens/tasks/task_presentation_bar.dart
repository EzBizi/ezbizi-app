import 'package:flutter/material.dart';

import 'task_kanban_view.dart';
import 'task_models.dart';

/// The presentation switcher Task 10 is required to build so Tasks 11-13
/// (Calendar, Timeline, Workload) have a bar to plug their tab into instead
/// of each inventing their own (Ruling 1, progress.md). Table, Kanban,
/// Calendar, and (Task 12) Timeline are wired to real screens; Workload is
/// shown but disabled — there is nothing yet for it to switch to, and an
/// enabled tab that does nothing would be a worse experience than an
/// honestly-disabled one.
///
/// Inline only — no popup menu, no dialog. When Kanban is the active
/// presentation, an inline bucket-field selector appears alongside the
/// presentation tabs rather than as a second control elsewhere.
class TaskPresentationBar extends StatelessWidget {
  const TaskPresentationBar({
    super.key,
    required this.presentation,
    required this.kanbanField,
    required this.onPresentationChanged,
    required this.onKanbanFieldChanged,
  });

  final TaskPresentation presentation;
  final TaskKanbanField kanbanField;
  final ValueChanged<TaskPresentation> onPresentationChanged;
  final ValueChanged<TaskKanbanField> onKanbanFieldChanged;

  static const _tabs = [
    (TaskPresentation.table, 'Table', Icons.table_rows_outlined, true),
    (TaskPresentation.kanban, 'Kanban', Icons.view_kanban_outlined, true),
    (TaskPresentation.calendar, 'Calendar', Icons.calendar_month_outlined, true),
    (TaskPresentation.timeline, 'Timeline', Icons.view_timeline_outlined, true),
    (TaskPresentation.workload, 'Workload', Icons.bar_chart_outlined, false),
  ];

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surface,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Row(
            children: [
              for (final (value, label, icon, available) in _tabs) ...[
                ChoiceChip(
                  key: ValueKey('presentation-tab-${value.wire}'),
                  avatar: Icon(icon, size: 16),
                  label: Text(label),
                  selected: presentation == value,
                  onSelected: available
                      ? (_) => onPresentationChanged(value)
                      : null,
                  tooltip: available ? null : '$label is not available yet.',
                ),
                const SizedBox(width: 8),
              ],
              if (presentation == TaskPresentation.kanban) ...[
                const SizedBox(width: 4),
                Text('Group by', style: Theme.of(context).textTheme.labelSmall),
                const SizedBox(width: 8),
                DropdownButton<TaskKanbanField>(
                  key: const ValueKey('kanban-bucket-field-picker'),
                  value: kanbanField,
                  underline: const SizedBox.shrink(),
                  items: [
                    for (final field in TaskKanbanField.values)
                      DropdownMenuItem(value: field, child: Text(field.label)),
                  ],
                  onChanged: (value) {
                    if (value != null) onKanbanFieldChanged(value);
                  },
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
