import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-09-30, on booking a lead with one box called Address: "I don't
/// want to see this issue anymore anywhere in the app where address is just
/// one field."
///
/// A screen that sends a street to the server - a customer's, a company's, a
/// person's, a booking's, an emergency address - finds it with the lookup and
/// keeps it in parts, which means it builds AddressFields or AddressLookup.
/// The booking panel sent `address_line1` from one box and nothing else.
///
/// The keys are the mechanism: every address the server stores arrives as
/// `address_line1` (customers, companies, people, bookings) or `street`
/// (Twilio's emergency address). A form that sends a whole address as a
/// single `address` string is not caught here - that key is also an email
/// address in Email and Staff - so a new one must use a street key.
void main() {
  test('every screen that sends a street finds it with the lookup', () {
    final sendsAStreet = RegExp(r'''['"](address_line1|street)['"]\s*:''');
    final offenders = <String>[];

    for (final file in Directory('lib').listSync(recursive: true)) {
      if (file is! File || !file.path.endsWith('.dart')) continue;
      final source = file.readAsStringSync();
      if (!sendsAStreet.hasMatch(source)) continue;
      if (source.contains('AddressFields(') ||
          source.contains('AddressLookup(')) {
        continue;
      }
      offenders.add(file.path);
    }

    expect(
      offenders,
      isEmpty,
      reason:
          'These send a street without the address lookup. Use AddressFields '
          '(the lookup and the boxes it fills) or AddressLookup beside your '
          'own boxes.',
    );
  });
}
