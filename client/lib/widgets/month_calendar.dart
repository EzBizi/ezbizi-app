import 'package:flutter/material.dart';

import '../core/timestamps.dart';
import 'dashboard_card.dart';

/// One thing sitting on a day.
class CalendarEntry {
  const CalendarEntry({
    required this.day,
    required this.label,
    this.detail,
    this.onTap,
  });

  /// Local date. The time of day is ignored — a month grid has no room for it,
  /// and the detail line carries it when it matters.
  final DateTime day;

  final String label;
  final String? detail;
  final VoidCallback? onTap;
}

/// A month, with what is happening on each day.
///
/// ── Why this exists next to the calendar in Social ──────────────
///
/// Social Media Management has its own month grid, written before anything was
/// scheduled, so it takes no entries and draws an empty month. This one takes
/// entries because that is the whole point of putting a calendar on a
/// dashboard: a month with nothing on it answers no question anybody has.
///
/// ponytail: two month grids in one client, deliberately and temporarily. The
/// right end state is that Social adopts this one and passes its scheduled
/// posts, which would also fix its empty calendar — but that is a refactor of a
/// working screen with its own tests, and it is not what the dashboard was
/// asked for. Migrate when Social's calendar is next touched.
class MonthCalendar extends StatefulWidget {
  const MonthCalendar({
    super.key,
    required this.entries,
    this.title = 'Calendar',
    this.emptyNote,
  });

  final List<CalendarEntry> entries;
  final String title;

  /// Shown when the visible month holds nothing. A grid of empty squares looks
  /// like a screen that failed to load.
  final String? emptyNote;

  @override
  State<MonthCalendar> createState() => _MonthCalendarState();
}

class _MonthCalendarState extends State<MonthCalendar> {
  late DateTime _month;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _month = DateTime(now.year, now.month);
  }

  static DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);

  List<CalendarEntry> _on(DateTime day) => widget.entries
      .where((e) => _dateOnly(e.day) == _dateOnly(day))
      .toList();

  bool get _monthIsEmpty => !widget.entries.any(
    (e) => e.day.year == _month.year && e.day.month == _month.month,
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _dateOnly(DateTime.now());
    final first = DateTime(_month.year, _month.month, 1);
    final daysInMonth = DateTime(_month.year, _month.month + 1, 0).day;
    final leading = first.weekday - DateTime.monday;
    final rows = ((leading + daysInMonth) / 7).ceil();

    return DashboardCard(
      title: widget.title,
      icon: Icons.calendar_view_month_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              IconButton(
                tooltip: 'Previous month',
                onPressed: () => setState(
                  () => _month = DateTime(_month.year, _month.month - 1),
                ),
                icon: const Icon(Icons.chevron_left),
              ),
              Expanded(
                child: Text(
                  _title(_month),
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleSmall,
                ),
              ),
              IconButton(
                tooltip: 'Next month',
                onPressed: () => setState(
                  () => _month = DateTime(_month.year, _month.month + 1),
                ),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              for (final d in shortWeekdays)
                Expanded(
                  child: Center(
                    child: Text(d, style: theme.textTheme.labelSmall),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          for (var row = 0; row < rows; row++)
            Row(
              // NOT stretch. Every cell sets its own 62 points, and stretch
              // inside a Column hands them an infinite height to stretch into
              // — which is an assertion, not a layout.
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (var col = 0; col < 7; col++)
                  Expanded(
                    child: _cell(
                      context,
                      row * 7 + col - leading + 1,
                      daysInMonth,
                      today,
                    ),
                  ),
              ],
            ),
          if (_monthIsEmpty && widget.emptyNote != null) ...[
            const SizedBox(height: 10),
            DashboardNote(widget.emptyNote!),
          ],
        ],
      ),
    );
  }

  Widget _cell(BuildContext context, int dayNumber, int daysInMonth, DateTime today) {
    final theme = Theme.of(context);

    if (dayNumber < 1 || dayNumber > daysInMonth) {
      return const SizedBox(height: 62);
    }

    final day = DateTime(_month.year, _month.month, dayNumber);
    final entries = _on(day);
    final isToday = _dateOnly(day) == today;

    return Container(
      height: 62,
      margin: const EdgeInsets.all(1),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: isToday ? theme.colorScheme.surfaceContainerHighest : null,
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(4),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$dayNumber', style: theme.textTheme.labelSmall),
          const SizedBox(height: 2),
          // At most two, then a count. Three tiny labels in a 62-point box is
          // unreadable, and a cell that grows breaks the grid.
          for (final entry in entries.take(2))
            Expanded(
              child: InkWell(
                onTap: entry.onTap,
                child: Tooltip(
                  message: entry.detail ?? entry.label,
                  child: Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
                    margin: const EdgeInsets.only(bottom: 1),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primaryContainer,
                      borderRadius: BorderRadius.circular(3),
                    ),
                    child: Text(
                      entry.label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
                ),
              ),
            ),
          if (entries.length > 2)
            Text('+${entries.length - 2}', style: theme.textTheme.labelSmall),
        ],
      ),
    );
  }

  static String _title(DateTime month) {
    const names = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December',
    ];
    return '${names[month.month - 1]} ${month.year}';
  }
}
