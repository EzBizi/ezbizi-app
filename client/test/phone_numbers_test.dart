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
      expect(formatPhoneNumber('+12035550142'), '+1 (203) 555-0142');
    });

    test('a number whose digits repeat is not mangled by the grouping', () {
      expect(formatPhoneNumber('+18888888888'), '+1 (888) 888-8888');
    });

    test('surrounding whitespace does not stop it', () {
      expect(formatPhoneNumber('  +12035550142 '), '+1 (203) 555-0142');
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

  group('typing a number', () {
    TextEditingValue typed(String was, String now) =>
        const PhoneNumberInputFormatter().formatEditUpdate(
          TextEditingValue(text: was),
          TextEditingValue(text: now),
        );

    test('the tenth digit turns it into the readable form', () {
      expect(typed('305724219', '3055550107').text, '+1 (305) 555-0107');
    });

    test('nine digits are left alone — it is still being typed', () {
      expect(typed('30572421', '305724219').text, '305724219');
    });

    test('a leading 1 is the same number with its country code', () {
      expect(typed('130572421', '13055550107').text, '+1 (305) 555-0107');
    });

    test('deleting can escape the formatting', () {
      // Without this the mask reapplies on every backspace and the caret never
      // moves, so the field looks frozen.
      final back = typed('+1 (305) 555-0107', '+1 (305) 555-010');
      expect(back.text, '+1 (305) 555-010');
    });

    test('an international number is left exactly as typed', () {
      expect(typed('+4420718387', '+442071838750').text, '+442071838750');
    });

    test('an impossible area code is not bracketed', () {
      expect(typed('105724219', '1057242199').text, '1057242199');
    });
  });

  group('what gets stored', () {
    test('ten digits become E.164', () {
      expect(toE164('3055550107'), '+13055550107');
    });

    test('the formatted form round-trips back to what it came from', () {
      expect(toE164(formatPhoneNumber('+13055550107')), '+13055550107');
    });

    test('eleven digits starting with 1 are the same number', () {
      expect(toE164('1 305 555 0107'), '+13055550107');
    });

    test('an empty field stores null, not an empty string', () {
      expect(toE164(''), isNull);
      expect(toE164('   '), isNull);
    });

    test('an extension is not turned into a phone number', () {
      expect(toE164('101'), '101');
    });

    test('an international number survives', () {
      expect(toE164('+442071838750'), '+442071838750');
    });
  });
}
