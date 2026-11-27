import '../screens/marketing/clock_label.dart';
import 'timestamps.dart';

/// A rule's schedule in words: "Sundays 8:00 PM (New York)",
/// "Every day 7:00 AM", "Mondays and Thursdays 9:00 AM", "The 1st of each
/// month 9:00 AM". Null when there is no schedule to describe: a rule that
/// runs on an event rather than a clock.
///
/// The shape is AutomationSchedule's: kind daily, weekly (days) or monthly
/// (day_of_month), a wall time HH:MM, and the zone it was typed in, which is
/// named because a wall time means nothing without its clock.
String? scheduleWords(Object? schedule, DisplayFormats formats) {
  if (schedule is! Map) return null;

  final time = RegExp(r'^(\d{2}):(\d{2})$').firstMatch('${schedule['time']}');
  if (time == null) return null;
  final clock =
      formats.formatTime(int.parse(time[1]!), int.parse(time[2]!)) +
      clockSuffix(schedule['timezone'] as String?);

  final when = switch (schedule['kind']) {
    'daily' => 'Every day',
    'weekly' => _days([
      for (final d in (schedule['days'] as List? ?? const [])) '$d',
    ]),
    'monthly' => 'The ${_ordinal(schedule['day_of_month'])} of each month',
    _ => null,
  };

  return when == null ? null : '$when $clock';
}

/// "Sundays", "Mondays and Thursdays", "Mon, Wed, Fri", "Every day".
String? _days(List<String> days) {
  final known = [
    for (final d in weekdayKeys)
      if (days.contains(d)) d,
  ];
  if (known.isEmpty) return null;
  if (known.length == 7) return 'Every day';
  if (known.length == 5 &&
      !known.contains('saturday') &&
      !known.contains('sunday')) {
    return 'Weekdays';
  }

  String name(String d) => '${d[0].toUpperCase()}${d.substring(1)}';
  if (known.length <= 2) return known.map((d) => '${name(d)}s').join(' and ');

  return known.map((d) => name(d).substring(0, 3)).join(', ');
}

String _ordinal(Object? raw) {
  final n = raw is num ? raw.toInt() : int.tryParse('$raw') ?? 1;
  final teen = n % 100 >= 11 && n % 100 <= 13;
  final suffix = teen
      ? 'th'
      : switch (n % 10) {
          1 => 'st',
          2 => 'nd',
          3 => 'rd',
          _ => 'th',
        };

  return '$n$suffix';
}
