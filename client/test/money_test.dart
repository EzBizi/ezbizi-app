import 'package:ezbizi_client/core/money.dart';
import 'package:flutter_test/flutter_test.dart';

/// What a typed price becomes on the wire (plan §7).
///
/// There were no money tests in this client at all, which is how a field
/// labelled "Price in cents" ended up sitting on top of a parser that read
/// dollars: both halves were plausible on their own and nothing compared them.
void main() {
  group('parseAmountMinor', () {
    test('reads dollars and returns whole cents', () {
      expect(parseAmountMinor('199.99'), 19999);
    });

    test('a thousands separator is punctuation, not a digit', () {
      // The original bug: deleting every non-digit read this as 125000 cents,
      // a hundredfold error that still looks like a plausible price.
      expect(parseAmountMinor('1,250.00'), 125000);
    });

    test('a whole number is dollars, which is what the label now says', () {
      // Under the old label somebody typing this meant $199.99 and got
      // $19,999.00. They now mean $19,999.00 and get it.
      expect(parseAmountMinor('19999'), 1999900);
    });

    test('empty means no price given, which is allowed', () {
      // A job can be booked before anyone has quoted it. The caller omits
      // amount_minor entirely rather than sending a zero.
      expect(parseAmountMinor(''), isNull);
      expect(parseAmountMinor('   '), isNull);
    });

    test('anything that is not a number is refused, never guessed', () {
      expect(() => parseAmountMinor('abc'), throwsFormatException);
      expect(() => parseAmountMinor('12.34.56'), throwsFormatException);
      expect(() => parseAmountMinor(r'$199.99'), throwsFormatException);
    });

    test('NaN and Infinity are refused rather than crashing the form', () {
      // double.tryParse accepts both. Rounding either throws out of the submit
      // handler, where nothing catches it and the user sees nothing at all.
      expect(() => parseAmountMinor('NaN'), throwsFormatException);
      expect(() => parseAmountMinor('Infinity'), throwsFormatException);
    });

    test('surrounding whitespace is not a typing error', () {
      expect(parseAmountMinor('  75.50  '), 7550);
    });

    test('a half cent rounds rather than being silently truncated', () {
      expect(parseAmountMinor('0.125'), 13);
    });
  });

  group('formatMinor', () {
    test('two-decimal currencies print the major amount and the code', () {
      expect(formatMinor(12500, 'USD'), 'USD 125.00');
    });

    test('five hundred minor units of USD is five dollars', () {
      // The glance draws this string; dividing on the server would have
      // printed 5 for JPY and 5.00 for USD from the same integer.
      expect(formatMinor(500, 'USD'), 'USD 5.00');
    });

    test('JPY is the integer and the code, never a hundredth', () {
      expect(formatMinor(125, 'JPY'), 'JPY 125');
    });
  });
}
