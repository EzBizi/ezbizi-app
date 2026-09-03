import 'package:flutter_test/flutter_test.dart';
import 'package:ezbizi_client/core/phone_numbers.dart';

/// The interesting half of this is everything it REFUSES.
///
/// Formatting a North American number is arithmetic. Knowing that a London
/// number must come back untouched is the part that stops the app printing
/// `+4 (420) 718-3875`, which is not a phone number anywhere and looks
/// confident about it.
void main() {
  group('formats North American numbers', () {
    test('the ordinary case', () {
      expect(formatPhoneNumber('+12035550142'), '+1 (203) 555 - 0142');
    });

    test('a number whose digits repeat is not mangled by the grouping', () {
      expect(formatPhoneNumber('+18888888888'), '+1 (888) 888 - 8888');
    });

    test('surrounding whitespace does not stop it', () {
      expect(formatPhoneNumber('  +12035550142 '), '+1 (203) 555 - 0142');
    });
  });

  group('returns everything else exactly as it arrived', () {
    test('an international number is NOT bracketed', () {
      // The failure this test exists for. Three-digit grouping applied blindly
      // turns a London number into something that is not a number at all.
      expect(formatPhoneNumber('+442071838750'), '+442071838750');
    });

    test('a short internal extension', () {
      expect(formatPhoneNumber('101'), '101');
    });

    test('too few digits after +1 — do not guess the missing one', () {
      expect(formatPhoneNumber('+1203555014'), '+1203555014');
    });

    test('too many digits after +1', () {
      expect(formatPhoneNumber('+120355501421'), '+120355501421');
    });

    test('already formatted input is not re-parsed into a different number', () {
      // Being lenient here — stripping punctuation and re-reading — would take
      // "(203) 555-0142" as ten digits with no country code and could emit a
      // number that is not the one passed in.
      expect(formatPhoneNumber('(203) 555-0142'), '(203) 555-0142');
    });

    test('letters in the digits', () {
      expect(formatPhoneNumber('+1203555O142'), '+1203555O142');
    });

    test('an area code starting with 1 is not NANP', () {
      expect(formatPhoneNumber('+11035550142'), '+11035550142');
    });

    test('an exchange starting with 0 is not NANP', () {
      expect(formatPhoneNumber('+12030550142'), '+12030550142');
    });

    test('empty and null', () {
      expect(formatPhoneNumber(''), '');
      expect(formatPhoneNumber(null), '');
      expect(formatPhoneNumber('   '), '');
    });
  });

  test('formatting is display-only: the E.164 form is never derivable back', () {
    // Not a round-trip test, because there is deliberately no inverse. This
    // asserts the property that matters at the call sites: what comes out is
    // NOT what should be sent to Twilio or used as a key, so the two must never
    // be confused. If somebody later "helpfully" makes the formatter idempotent
    // over its own output, that is the moment a formatted string starts looking
    // safe to store.
    const stored = '+12035550142';
    final shown = formatPhoneNumber(stored);

    expect(shown, isNot(stored));
    expect(formatPhoneNumber(shown), shown,
        reason: 'Formatting its own output must not produce a third form.');
  });
}
