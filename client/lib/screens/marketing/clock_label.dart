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
/// **This names the condition, not a value, deliberately — and the reason is
/// embarrassing enough to be worth writing down.** The first version of this
/// docblock said "the platform default `Etc/GMT+5`". When Phone set out to
/// change that default I rewrote this to say it had become `America/New_York`.
/// It had not. That value existed only in the shared working tree; the change
/// was cut back when the full suite came up 55 red — seventeen test files
/// across Dashboard, TimeClock, Phone reports, Social posts and HR glances
/// inherit the offset as the value they expect. So the comment was pinned
/// first to a value that changed, and then to a value that never shipped.
///
/// What DID ship (`f7eb8b16`) is that real places are now selectable and the
/// help text says a place handles daylight saving for you. **The default is
/// still `Etc/GMT+5`**, so above franchise scope this client still mostly
/// renders a fixed offset — which is exactly the case this function exists to
/// make visible.
///
/// Do not re-pin this to whatever the default is when you read it. Fixed
/// offsets are selectable regardless, so a view can resolve one whatever the
/// default says, and naming which clock you actually got is correct under all
/// of them. Whether the offering is right is a platform decision, not
/// Marketing's.
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
