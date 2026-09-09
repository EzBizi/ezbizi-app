/// Which clock a schedule was filed against, in words a person can act on.
///
/// The server already names the zone on every scheduled newsletter and post
/// (`scheduled_timezone`), and until this existed nothing in this client read
/// it. That left the composer promising "the time you pick is the time it goes
/// out there" while naming the PLACE, when the clock the server had actually
/// filed against might be a fixed offset — which stands still while the place
/// it is named after moves an hour in March. Same wall time, an hour apart
/// from March to November, with nothing on screen saying so.
///
/// **This names the condition, not a value, deliberately.** An earlier version
/// of this docblock said "the platform default `Etc/GMT+5`", and it was false
/// within a day: Phone measured the setting, Jose chose Miami's clock, and the
/// default became `America/New_York` (SettingRegistry). A comment pinned to
/// somebody else's current value is a comment with an expiry date on it.
///
/// The hazard did NOT go away with the default, which is the reason to say it
/// this way. Fixed offsets are still selectable — twenty-seven of them, now
/// labelled "fixed, no daylight saving" — so a view can still resolve one,
/// deliberately or by inheritance. Whether that is the right offering is a
/// platform decision and not Marketing's. Naming which clock you actually got
/// is not a decision at all, and that is all this does.
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
