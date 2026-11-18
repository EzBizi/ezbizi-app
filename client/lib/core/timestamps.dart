/// How this organization writes a date and a clock, and the one place that
/// applies it (plan §6 `date_format` / `time_format`).
///
/// ── Why the browser's locale is not allowed a vote ───────────────
///
/// The owner changed Time format from 12h to 24h and nothing happened, because
/// nothing anywhere read the setting: `/me` carried identity and scope but not
/// a single resolved setting, so no screen had one to read even if it had
/// wanted to. Everything below exists to close that.
///
/// The obvious Flutter answer — MaterialLocalizations, or
/// MediaQuery.alwaysUse24HourFormatOf — is the wrong one here, and quietly so.
/// Both format by LOCALE, which is a property of the reader's machine. An
/// organization setting that loses to the browser is a setting that appears to
/// work for whoever configured it and does nothing for everybody else, which is
/// the same bug wearing a disguise. There is no i18n in this app yet (§8,
/// TODO #19); when there is, the rule stays: the organization decides the
/// FORMAT, a locale may still decide the language around it.
library;

/// The display settings in force, as `/me` resolved them for the current scope.
///
/// Immutable and cheap, so it is passed down explicitly rather than read from
/// an inherited widget — the same shape as `session` and `api` everywhere else
/// in this client, and it keeps [formatStamp] a pure function that a test can
/// call without a widget tree.
class DisplayFormats {
  const DisplayFormats({required this.dateFormat, required this.timeFormat});

  /// The raw strings the server sent, unrecognised values included.
  ///
  /// Kept verbatim rather than parsed into an enum on the way in, so the
  /// settings screen can name the value it cannot draw instead of reporting
  /// that something unspecified went wrong.
  final String dateFormat;
  final String timeFormat;

  /// What THIS BUILD can draw, per registry key.
  ///
  /// The server validates against SettingRegistry, so a value outside these
  /// lists is not bad data — it is a registry that has gained an option since
  /// this bundle was compiled. That is a real state (one deploy lands before
  /// the other) and it has to degrade to something legible, not to a crash and
  /// not to a silent wrong answer.
  static const Map<String, List<String>> known = {
    'date_format': ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'],
    'time_format': ['12h', '24h'],
  };

  /// Where an unrecognised value lands, and where the control plane starts.
  ///
  /// ISO-8601 and a 24-hour clock, chosen because they are the only pair that
  /// cannot be misread: `03/04` is two different days depending on who is
  /// looking, `2026-03-04` is one, and `17:00` needs no AM/PM to be right.
  /// Falling back to the registry's own default (`M/D/YYYY`, `12h`) would look
  /// deliberate and hide the mismatch; this looks like what it is.
  static const DisplayFormats fallback = DisplayFormats(
    dateFormat: 'YYYY-MM-DD',
    timeFormat: '24h',
  );

  /// For screens that sit outside every organization — the staff control plane
  /// (§4.2), which spans tenants and so has no organization setting to honour.
  ///
  /// Deliberately the same values as [fallback] and deliberately a separate
  /// name: "there is no setting here" and "the setting is one I cannot read"
  /// are different facts, and a call site should say which one it means.
  static const DisplayFormats platform = fallback;

  /// Built from `/me`'s `display` block.
  ///
  /// A missing block means a server older than this build, which can only
  /// happen mid-deploy. It takes [fallback] rather than the registry defaults
  /// so the app shows ISO dates for those few seconds instead of confidently
  /// showing 12-hour times to an organization that set 24.
  factory DisplayFormats.fromJson(Object? json) {
    if (json is! Map) return fallback;

    return DisplayFormats(
      dateFormat: json['date_format'] as String? ?? fallback.dateFormat,
      timeFormat: json['time_format'] as String? ?? fallback.timeFormat,
    );
  }

  /// A sentence for the settings screen when a value cannot be drawn, or null
  /// when it can.
  ///
  /// This is what makes an unrecognised value VISIBLE rather than merely
  /// survivable. Falling back quietly would reproduce the original complaint
  /// exactly — a setting changed, saved, and apparently ignored — so the screen
  /// where somebody changed it is the screen that says why nothing moved.
  /// Static, because it answers about a settings ROW at whatever scope that
  /// screen is editing, not about the formats currently in force.
  static String? unsupportedNote(String key, Object? value) {
    final options = known[key];

    if (options == null || options.contains(value)) return null;

    final shown = key == 'time_format' ? fallback.timeFormat : fallback.dateFormat;

    return "This version of the app doesn't recognise '$value' yet, so it is "
        'showing $shown instead. Reload once the update has landed.';
  }

  /// `2026-08-10`, `10/08/2026` or `8/10/2026`.
  ///
  /// The enum strings say how wide each part is and are followed literally:
  /// `M/D/YYYY` writes a single digit for August, `YYYY-MM-DD` pads it. Padding
  /// the slashed forms would make them a fourth format nobody asked for.
  String formatDate(DateTime at) => switch (dateFormat) {
    'M/D/YYYY' => '${at.month}/${at.day}/${at.year}',
    'D/M/YYYY' => '${at.day}/${at.month}/${at.year}',
    // 'YYYY-MM-DD', and anything this build does not know.
    _ => '${at.year}-${_two(at.month)}-${_two(at.day)}',
  };

  /// `5:07 PM` or `17:07`.
  String formatTime(int hour, int minute) {
    if (timeFormat != '12h') {
      // '24h', and anything this build does not know.
      return '${_two(hour)}:${_two(minute)}';
    }

    // Midnight and noon are the two the modulo gets wrong on its own: hour 0
    // is 12 AM and hour 12 is 12 PM, not 0 of either.
    final twelve = hour % 12 == 0 ? 12 : hour % 12;

    return '$twelve:${_two(minute)} ${hour < 12 ? 'AM' : 'PM'}';
  }

  /// A wall clock with no date behind it — the `HH:MM` the server stores for
  /// opening times and quiet hours, shown the way this organization reads it.
  ///
  /// Anything unparseable is handed back untouched. A stored time is the
  /// server's to validate, and a screen that swallowed a malformed one into
  /// `00:00` would be inventing an opening time.
  String formatWallClock(String hhmm) {
    final parts = hhmm.split(':');

    if (parts.length < 2) return hhmm;

    final hour = int.tryParse(parts[0]);
    final minute = int.tryParse(parts[1]);

    if (hour == null || minute == null) return hhmm;

    return formatTime(hour, minute);
  }

  /// Month, day and year in the order this organization types a date.
  ///
  /// The three boxes on a date field follow this, not the browser locale and
  /// not a hardcoded month-first row. Labels on each box keep 3/8 from being
  /// two different days; the order is still the setting, or the setting is
  /// decoration.
  List<DatePart> get dateParts => switch (dateFormat) {
    'D/M/YYYY' => const [DatePart.day, DatePart.month, DatePart.year],
    'YYYY-MM-DD' => const [DatePart.year, DatePart.month, DatePart.day],
    // 'M/D/YYYY', and anything this build does not know.
    _ => const [DatePart.month, DatePart.day, DatePart.year],
  };

  @override
  bool operator ==(Object other) =>
      other is DisplayFormats &&
      other.dateFormat == dateFormat &&
      other.timeFormat == timeFormat;

  @override
  int get hashCode => Object.hash(dateFormat, timeFormat);
}

/// One piece of a calendar day, named so a field can label the box rather than
/// hoping the reader infers the order from position.
enum DatePart { month, day, year }

String _two(int n) => n.toString().padLeft(2, '0');

/// Month and weekday abbreviations, read as `[month - 1]` and
/// `[weekday - 1]`. English whatever the locale, for the reason above.
const shortMonths = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];
const shortWeekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const longMonths = [
  'January', 'February', 'March', 'April', 'May', 'June', //
  'July', 'August', 'September', 'October', 'November', 'December',
];
const longWeekdays = [
  'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', //
  'Sunday',
];

/// A calendar day as `YYYY-MM-DD`. Null when the string is empty or not a day.
///
/// Rejects overflow (`2026-02-31`) rather than letting [DateTime] roll it into
/// March: a field that accepted a day the calendar does not have would store a
/// different day than the one typed.
DateTime? parseCalendarDay(String? raw) {
  if (raw == null) return null;

  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(raw.trim());
  if (match == null) return null;

  return _civilDay(
    int.parse(match[1]!),
    int.parse(match[2]!),
    int.parse(match[3]!),
  );
}

/// Wall clock `YYYY-MM-DD HH:MM` as jobs store it, plus the date-only form.
///
/// The optional time is what `_wallClock` leaves after stripping a zone suffix.
/// Hour and minute default to midnight when the string is a day and nothing
/// else, so a date field and a date-and-time field can share the parser.
({DateTime day, int hour, int minute})? parseWallDateTime(String? raw) {
  if (raw == null) return null;

  final match = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2}))?',
  ).firstMatch(raw.trim());
  if (match == null) return null;

  final day = _civilDay(
    int.parse(match[1]!),
    int.parse(match[2]!),
    int.parse(match[3]!),
  );
  if (day == null) return null;

  final hour = match[4] == null ? 0 : int.parse(match[4]!);
  final minute = match[5] == null ? 0 : int.parse(match[5]!);
  if (hour > 23 || minute > 59) return null;

  return (day: day, hour: hour, minute: minute);
}

/// How long ago [at] was: "just now", "5 min ago", "3 h ago", "2 d ago".
///
/// A time ahead of [now] (a server clock ahead of this one) reads "just now":
/// [Duration.inMinutes] truncates towards zero, so it is never a negative
/// count.
String formatAgo(DateTime at, {DateTime? now}) {
  final ago = (now ?? DateTime.now()).difference(at.toLocal());
  if (ago.inMinutes < 1) return 'just now';
  if (ago.inHours < 1) return '${ago.inMinutes} min ago';
  if (ago.inDays < 1) return '${ago.inHours} h ago';
  return '${ago.inDays} d ago';
}

/// A playing time as `m:ss`; the minutes keep counting past an hour (`75:04`).
String formatClock(Duration d) => '${d.inMinutes}:${_two(d.inSeconds % 60)}';

String formatCalendarDay(DateTime at) =>
    '${at.year}-${_two(at.month)}-${_two(at.day)}';

String formatWallDateTime(DateTime day, int hour, int minute) =>
    '${formatCalendarDay(day)} ${_two(hour)}:${_two(minute)}';

DateTime? _civilDay(int year, int month, int day) {
  if (month < 1 || month > 12 || day < 1) return null;

  final at = DateTime(year, month, day);
  if (at.year != year || at.month != month || at.day != day) return null;

  return at;
}

/// ISO-8601 from the server, shown in the reader's own zone and in the
/// organization's own format.
///
/// Formatting is deliberately plain — a wrong "2 hours ago" is worse than an
/// exact stamp.
///
/// [fallback] is what belongs on screen when there is no usable time, and it is
/// never the same word twice: a job with no date reads "Not scheduled", an
/// account that has never signed in reads "never". The parsing is the shared
/// part; the wording is the caller's.
///
/// [formats] is required rather than defaulted. A default would be the one line
/// that quietly reintroduces the bug: a new screen written next month would
/// compile, look right to whoever wrote it, and ignore the organization's
/// setting. Being made to type `session.me!.display` is the point — and staff
/// screens, which have no organization behind them, say
/// [DisplayFormats.platform] so that absence is stated rather than defaulted.
String formatStamp(
  Object? iso,
  String fallback, {
  required DisplayFormats formats,
}) {
  final at = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

  if (at == null) return fallback;

  return '${formats.formatDate(at)} ${formats.formatTime(at.hour, at.minute)}';
}

/// A stamp in a LIST, where the day is usually today and the column is narrow.
///
/// [formatStamp] always writes both halves, which is right for a detail field
/// somebody is reading once. A list of calls is the other case: forty rows, the
/// same date on most of them, and a column about eleven characters wide. Every
/// phone anybody owns solves it the same way — the clock for today, the
/// calendar for anything older — so this does too.
///
/// [now] is injectable because "today" is the whole behaviour and a test that
/// cannot move the day can only assert the branch it happens to be in.
String formatListStamp(
  Object? iso,
  String fallback, {
  required DisplayFormats formats,
  DateTime? now,
}) {
  final at = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

  if (at == null) return fallback;

  final today = (now ?? DateTime.now()).toLocal();
  final sameDay =
      at.year == today.year && at.month == today.month && at.day == today.day;

  return sameDay
      ? formats.formatTime(at.hour, at.minute)
      : formats.formatDate(at);
}
