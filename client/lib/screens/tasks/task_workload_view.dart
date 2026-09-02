import 'package:flutter/material.dart';

import 'task_models.dart';

/// Re-windows Workload to a new month — the same shape [TaskWorkloadView]'s
/// sibling views (`TaskCalendarMove`'s neighbours) use.
typedef TaskWorkloadMonthChanged = ValueChanged<DateTime>;

/// The Workload presentation (task-13 brief): one row per owner, one column
/// per day in the current window, each cell a plain count of tasks whose
/// span covers that day (`TaskWorkloadQuery::rows`, spec §5).
///
/// THE DEFINING CONSTRAINT, stated here because it is the easiest thing in
/// this file to get wrong without meaning to: EzBizi has no effort field and
/// no capacity number for anyone. This widget shows exactly what the server
/// counted — tasks — and invents nothing on top of it. There is no capacity
/// line, no threshold, and no over-capacity colour anywhere below; every
/// cell reads as a plain count, in the same ink, whether it says 0 or 12.
/// A change that adds any of those is inventing a fact the product does not
/// have.
///
/// `ownerId: null` — the explicit Unassigned row `TaskWorkloadRow` always
/// carries when the window has any unowned, dated task — renders exactly
/// like any other owner row, labelled "Unassigned". It is never dropped.
///
/// The date columns are DATA, not chrome, so they scroll horizontally in
/// their own container at every width, including 375px — unlike
/// `TaskCalendarView`'s month grid, which reflows to a week list instead.
/// The owner column stays put outside that scroller so a long row of counts
/// never separates a name from its own numbers.
class TaskWorkloadView extends StatelessWidget {
  const TaskWorkloadView({
    super.key,
    required this.month,
    required this.window,
    required this.rows,
    required this.onMonthChanged,
  });

  /// The displayed month; only `.year`/`.month` matter — mirrors
  /// `TaskCalendarView.month` / `TaskTimelineView.month`.
  final DateTime month;

  /// The window [rows] answers to. Always a whole number of days apart;
  /// this view only ever asks for `TaskWorkloadUnit.day` (see
  /// `TasksScreen._applyWorkloadWindow`), so every day between `from` and
  /// `to` inclusive is exactly one column.
  final TaskWorkloadWindow window;
  final List<TaskWorkloadRow> rows;
  final TaskWorkloadMonthChanged onMonthChanged;

  static const _monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  static const _rowHeight = 40.0;
  static const _ownerColumnWidth = 168.0;
  static const _unitColumnWidth = 56.0;

  List<DateTime> get _days {
    final days = <DateTime>[];
    var cursor = window.from;
    while (!cursor.isAfter(window.to)) {
      days.add(cursor);
      cursor = cursor.add(const Duration(days: 1));
    }
    return days;
  }

  void _changeMonth(int delta) =>
      onMonthChanged(DateTime.utc(month.year, month.month + delta, 1));

  @override
  Widget build(BuildContext context) {
    final days = _days;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(context),
        _caption(context),
        Expanded(
          child: rows.isEmpty
              ? const Center(
                  key: ValueKey('workload-empty'),
                  child: Text('No dated tasks fall in this window.'),
                )
              : _grid(context, days),
        ),
      ],
    );
  }

  Widget _header(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
    child: Row(
      children: [
        IconButton(
          tooltip: 'Previous month',
          icon: const Icon(Icons.chevron_left),
          onPressed: () => _changeMonth(-1),
        ),
        Expanded(
          child: Text(
            '${_monthNames[month.month - 1]} ${month.year}',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
        IconButton(
          tooltip: 'Next month',
          icon: const Icon(Icons.chevron_right),
          onPressed: () => _changeMonth(1),
        ),
      ],
    ),
  );

  /// States, on screen, the one fact this view must never let a reader
  /// assume otherwise: every number below is a count of TASKS, never hours.
  /// There is nothing this app knows about anyone's capacity to compare
  /// that count against, so no such comparison is drawn here either.
  Widget _caption(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
    child: Text(
      key: const ValueKey('workload-caption'),
      'Each number is a count of tasks due that day, not hours.',
      style: TextStyle(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
        fontSize: 12,
      ),
    ),
  );

  Widget _grid(BuildContext context, List<DateTime> days) {
    final border = Theme.of(context).dividerColor;
    return SingleChildScrollView(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _ownerColumn(context, border),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: _unitGrid(context, days, border),
            ),
          ),
        ],
      ),
    );
  }

  Widget _ownerColumn(BuildContext context, Color border) => SizedBox(
    width: _ownerColumnWidth,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          height: _rowHeight,
          alignment: Alignment.centerLeft,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(border: Border(bottom: BorderSide(color: border))),
          child: Text('Owner', style: Theme.of(context).textTheme.labelSmall),
        ),
        for (final row in rows)
          Container(
            key: ValueKey('workload-row-${row.ownerId ?? 'unassigned'}'),
            height: _rowHeight,
            alignment: Alignment.centerLeft,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(border: Border(bottom: BorderSide(color: border))),
            child: Text(
              row.ownerName ?? 'Unassigned',
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
      ],
    ),
  );

  Widget _unitGrid(BuildContext context, List<DateTime> days, Color border) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final day in days)
            Container(
              key: ValueKey('workload-column-${_dateKey(day)}'),
              width: _unitColumnWidth,
              height: _rowHeight,
              alignment: Alignment.center,
              decoration: BoxDecoration(border: Border(bottom: BorderSide(color: border))),
              child: Text(
                '${day.month}/${day.day}',
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
        ],
      ),
      for (final row in rows) _unitRow(context, row, days, border),
    ],
  );

  Widget _unitRow(
    BuildContext context,
    TaskWorkloadRow row,
    List<DateTime> days,
    Color border,
  ) {
    final counts = <String, int>{
      for (final unit in row.units) _dateKey(unit.date): unit.count,
    };
    final scheme = Theme.of(context).colorScheme;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final day in days)
          Container(
            key: ValueKey(
              'workload-cell-${row.ownerId ?? 'unassigned'}-${_dateKey(day)}',
            ),
            width: _unitColumnWidth,
            height: _rowHeight,
            alignment: Alignment.center,
            decoration: BoxDecoration(border: Border(bottom: BorderSide(color: border))),
            child: Text(
              '${counts[_dateKey(day)] ?? 0}',
              style: TextStyle(
                color: (counts[_dateKey(day)] ?? 0) == 0
                    ? scheme.onSurfaceVariant
                    : scheme.onSurface,
              ),
            ),
          ),
      ],
    );
  }

  String _dateKey(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}
