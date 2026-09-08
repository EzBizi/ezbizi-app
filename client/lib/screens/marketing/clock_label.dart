/// Which clock a schedule was filed against, in words a person can act on.
///
/// The server already names the zone on every scheduled newsletter and post
/// (`scheduled_timezone`), and until now nothing in this client read it. That
/// left the composer promising "the time you pick is the time it goes out
/// there" while naming the PLACE — and above franchise scope the clock is the
/// platform default `Etc/GMT+5`, a fixed offset that does not move with
/// daylight saving the way the place does. Same wall time, an hour apart from
/// March to November, with nothing on screen saying so.
///
/// This does not fix that. Choosing between real zones and fixed offsets is a
/// platform decision on Jose's list, and quietly resolving it inside Marketing
/// is how two modules come to disagree about what time it is. What this does
/// is stop the app from hiding which of the two you got.
library;

final RegExp _fixed = RegExp(r'^Etc/GMT([+-])(\d{1,2})$');

/// `America/New_York` -> `New York`; `Etc/GMT+5` -> `UTC−5, no daylight saving`.
///
/// The sign is inverted on purpose. POSIX runs `Etc/GMT+5` WEST of Greenwich,
/// so it is UTC−5 and not UTC+5 — printing it through unchanged would put the
/// warning on screen and still name the wrong clock.
String clockLabel(String? zone) {
  final z = zone?.trim() ?? '';
  if (z.isEmpty) return '';

  final fixed = _fixed.firstMatch(z);
  if (fixed != null) {
    final sign = fixed.group(1) == '+' ? '−' : '+';
    return 'UTC$sign${fixed.group(2)}, no daylight saving';
  }

  return z.split('/').last.replaceAll('_', ' ');
}

/// The same, ready to sit after a wall time: ` (New York)`.
///
/// Empty rather than ` ()` when the server named no clock, so a row that has
/// no zone reads exactly as it did before this existed.
String clockSuffix(String? zone) {
  final label = clockLabel(zone);
  return label.isEmpty ? '' : ' ($label)';
}
