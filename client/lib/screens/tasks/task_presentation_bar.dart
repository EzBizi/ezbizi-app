import 'package:flutter/material.dart';

import 'task_kanban_view.dart';
import 'task_models.dart';

/// The presentation switcher Task 10 is required to build so Tasks 11-13
/// (Calendar, Timeline, Workload) have a bar to plug their tab into instead
/// of each inventing their own (Ruling 1, progress.md). All five tabs are
/// now wired to real screens — Workload (task-13 brief) was the last one
/// shown disabled with a "not available yet" tooltip; `_tabs`' fourth
/// element stays as an explicit per-tab availability flag rather than being
/// deleted now that every entry happens to be `true`, so the next
/// presentation this bar grows can ship disabled the same honest way this
/// one did, instead of the tab silently doing nothing.
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
    (TaskPresentation.workload, 'Workload', Icons.bar_chart_outlined, true),
  ];

  /// Below this the tabs never fit five-wide (measured: five chips clip mid-
  /// element at 375px with no scroll affordance). Matches the breakpoint
  /// `TaskKanbanView` already uses for its own narrow-width chip selector —
  /// and, deliberately, matches it against `MediaQuery`'s width rather than
  /// this bar's own local constraint. This bar can be squeezed well below
  /// 700px on a genuinely wide screen (the detail panel alone reserves
  /// ~390px), and wrapping five icon+label chips onto three lines there
  /// nearly triples this bar's height with nothing above it to absorb the
  /// difference — starving the table below of the room its own rows need.
  /// The screen's own `mobile` flag already uses this same width for the
  /// same reason (Ruling: chrome reacts to the SCREEN being narrow, not to
  /// a sibling panel eating its share of a screen that isn't).
  static const _narrowBreakpoint = 700.0;

  List<Widget> _tabChips() => [
    for (final (value, label, icon, available) in _tabs)
      ChoiceChip(
        key: ValueKey('presentation-tab-${value.wire}'),
        avatar: Icon(icon, size: 16),
        label: Text(label),
        selected: presentation == value,
        onSelected: available ? (_) => onPresentationChanged(value) : null,
        tooltip: available ? null : '$label is not available yet.',
      ),
  ];

  Widget? _kanbanFieldPicker(BuildContext context) =>
      presentation != TaskPresentation.kanban
      ? null
      : Row(
          mainAxisSize: MainAxisSize.min,
          children: [
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
        );

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final narrow = MediaQuery.sizeOf(context).width < _narrowBreakpoint;
    return Material(
      color: scheme.surface,
      child: narrow ? _wrapped(context) : _scrolled(context),
    );
  }

  // Narrow: the tabs wrap onto as many lines as they need instead of
  // scrolling sideways — the rule this bar is chrome, not data, so a
  // horizontal scroller here is an unaffordanced clip, not a feature.
  Widget _wrapped(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
    child: Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [..._tabChips(), ?_kanbanFieldPicker(context)],
    ),
  );

  Widget _scrolled(BuildContext context) => SingleChildScrollView(
    scrollDirection: Axis.horizontal,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        children: [
          for (final chip in _tabChips()) ...[chip, const SizedBox(width: 8)],
          if (_kanbanFieldPicker(context) case final picker?) ...[
            const SizedBox(width: 4),
            picker,
          ],
        ],
      ),
    ),
  );
}
