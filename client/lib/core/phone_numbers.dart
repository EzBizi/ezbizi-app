/// How a phone number is written on screen.
///
/// The stored form is E.164 — `+12035550142` — because that is what Twilio
/// takes, what the database keys on, and what two numbers are compared by. It
/// is also unreadable: nobody checks a number against a business card by
/// counting digits.
///
/// ── The one rule that matters ────────────────────────────────────
///
/// **This is for DISPLAY ONLY.** The E.164 string stays the value everywhere
/// else — payloads, `ValueKey`s, equality, anything sent to Twilio. Formatting
/// a number and then sending it would break dialling; formatting one and then
/// comparing it to a raw one would silently never match, which is worse,
/// because a lookup that finds nothing looks like an empty result rather than
/// like a bug.
///
/// ── Why it refuses most of the world ─────────────────────────────
///
/// `+1 (203) 555-0142` is a North American convention and only makes sense for
/// North American numbers. A naive "insert brackets after three digits" would
/// render a London number `+4 (420) 718-3875` — which is not a phone number in
/// any country, and looks confident about it.
///
/// So anything that is not `+1` followed by exactly ten digits comes back
/// **exactly as it arrived**. An unformatted international number is honest; a
/// wrongly formatted one is not. EzBizi is US-only today, and the day it is not
/// this file is where that decision already lives.
library;

/// The North American form: `+1 (203) 555-0142`.
///
/// Anything else is returned unchanged — international numbers, short internal
/// extensions, empty strings, and anything malformed. Callers can pass whatever
/// they hold without checking first.
String formatPhoneNumber(String? raw) {
  final value = raw?.trim() ?? '';
  if (value.isEmpty) return '';

  // Deliberately strict rather than lenient. Stripping punctuation first and
  // then guessing would let `(203) 555-0142` — already formatted, no country
  // code — through as if it were E.164, and it would come back as a different
  // number. If it is not exactly the stored form, it is not ours to rewrite.
  if (value.length != 12 || !value.startsWith('+1')) return value;

  final digits = value.substring(2);
  for (final unit in digits.codeUnits) {
    if (unit < 0x30 || unit > 0x39) return value;
  }

  // NANP forbids an area code or exchange starting with 0 or 1. A number that
  // breaks that is not a NANP number that we should be bracketing, whatever its
  // shape — most likely a test fixture or a mis-stored international number.
  if (digits[0] == '0' || digits[0] == '1') return value;
  if (digits[3] == '0' || digits[3] == '1') return value;

  return '+1 (${digits.substring(0, 3)}) '
      '${digits.substring(3, 6)}-${digits.substring(6)}';
}
