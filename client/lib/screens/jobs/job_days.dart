import '../../core/timestamps.dart';
import '../../widgets/grouped_rows.dart';

/// The day headings over a list of jobs already in order.
///
/// Jose's *"time divisions"* (queue 24, option 7). Grouped by the WALL date of
/// `scheduled_for` — the digits the franchise booked, not the reader's clock —
/// because that is what a job is (FranchiseWallTime: "9am Tuesday for a
/// technician would not" move). A Texas job at 11pm is Tuesday's job for the
/// person going to it, whatever time it is in New Jersey.
///
/// Nothing is sorted here. The server orders the list, and a group keeps the
/// place of its first row, so the headings follow the order they were given.
///
/// [counts] is the server's per-day total, keyed `YYYY-MM-DD` and `none`. A day
/// with no count gets no count — the rows loaded so far are not the day's
/// total when the page ended halfway through it.
List<RowGroup<Map<String, dynamic>>> groupJobsByDay(
  List<Map<String, dynamic>> jobs, {
  required DateTime today,
  Map<String, int> counts = const {},
}) {
  final days = <String, List<Map<String, dynamic>>>{};

  for (final job in jobs) {
    final wall = parseWallDateTime(job['scheduled_for'] as String?);
    (days[wall == null ? 'none' : formatCalendarDay(wall.day)] ??= []).add(job);
  }

  final anchor = DateTime.utc(today.year, today.month, today.day);

  return [
    for (final MapEntry(:key, :value) in days.entries)
      _group(key, value, anchor, counts[key]),
  ];
}

RowGroup<Map<String, dynamic>> _group(
  String key,
  List<Map<String, dynamic>> rows,
  DateTime today,
  int? count,
) {
  final total = count == null ? null : '$count ${count == 1 ? 'job' : 'jobs'}';
  final day = parseCalendarDay(key);

  if (day == null) {
    return RowGroup(heading: 'Not scheduled', count: total, rows: rows);
  }

  final at = DateTime.utc(day.year, day.month, day.day);
  final offset = at.difference(today).inDays;
  final weekday = longWeekdays[at.weekday - 1];
  final date = [
    '${at.day} ${longMonths[at.month - 1]}',
    if (at.year != today.year) '${at.year}',
  ].join(' ');

  final (heading, detail) = switch (offset) {
    0 => ('Today', '$weekday $date'),
    1 => ('Tomorrow', '$weekday $date'),
    -1 => ('Yesterday', '$weekday $date'),
    _ => (weekday, date),
  };

  return RowGroup(heading: heading, detail: detail, count: total, rows: rows);
}
