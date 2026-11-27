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

import 'package:flutter/services.dart';

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

  // US convention, tight around the dash. One place decides it, so the whole
  // app changes together if that ever changes again.
  return '+1 (${digits.substring(0, 3)}) '
      '${digits.substring(3, 6)}-${digits.substring(6)}';
}

/// The stored form of whatever somebody typed, or null if it is not a number.
///
/// The counterpart to [formatPhoneNumber]: that one is for eyes, this one is
/// for the wire. A field using [PhoneNumberInputFormatter] holds the pretty
/// form, and every save path must run it back through here — the API, the
/// database and Twilio all key on E.164, and posting `+1 (305) 555-0107` would
/// be stored as a different number from the same digits typed anywhere else.
///
/// Ten digits become `+1XXXXXXXXXX`. Eleven starting with 1 are the same number
/// written with its country code. Anything else — an extension, an
/// international number, a half-typed field — comes back as it arrived, so a
/// caller can tell "not ready yet" from "definitely wrong".
String? toE164(String? typed) {
  final value = typed?.trim() ?? '';
  if (value.isEmpty) return null;

  final digits = value.replaceAll(RegExp(r'\D'), '');

  // A leading + on something that is not NANP is an international number
  // somebody typed deliberately. Keep it exactly, rather than mangling it into
  // a +1.
  if (value.startsWith('+') && !(digits.length == 11 && digits[0] == '1')) {
    return digits.length == 10 ? '+1$digits' : value;
  }

  if (digits.length == 10) return '+1$digits';
  if (digits.length == 11 && digits[0] == '1') return '+$digits';

  return value;
}

/// Formats a North American number while it is being typed.
///
/// `3055550107` becomes `+1 (305) 555-0107` as the tenth digit lands. Anything
/// that cannot be a NANP number — more than eleven digits, a leading 0 or 1 in
/// the area code, an international `+` — is left exactly as typed, because a
/// field that rewrites what somebody deliberately entered is worse than one
/// that leaves it alone.
class PhoneNumberInputFormatter extends TextInputFormatter {
  const PhoneNumberInputFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final text = newValue.text;

    // Deleting must be able to escape the formatting. Without this, backspacing
    // over the ")" reformats it straight back and the caret never moves — the
    // field appears frozen, which is the classic phone-mask bug.
    if (text.length < oldValue.text.length) return newValue;

    // An international number is the person's own business.
    if (text.startsWith('+') && !text.startsWith('+1')) return newValue;

    final digits = text.replaceAll(RegExp(r'\D'), '');
    final national = digits.length == 11 && digits.startsWith('1')
        ? digits.substring(1)
        : digits;

    if (national.length != 10) return newValue;
    if (national[0] == '0' || national[0] == '1') return newValue;
    if (national[3] == '0' || national[3] == '1') return newValue;

    final formatted =
        '+1 (${national.substring(0, 3)}) '
        '${national.substring(3, 6)}-${national.substring(6)}';

    return TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: formatted.length),
    );
  }
}
