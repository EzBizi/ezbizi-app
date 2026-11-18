import 'package:ezbizi_client/widgets/mail_text.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  // The shape of the lead email Jose flagged on 2026-11-13.
  const rose = 'Hi, it is the blower and our\n'
      'address is 1 Main Road. Friday works because he will\n'
      'be home.\n'
      'Mondays 2-5,\n'
      'Thursdays 11:30-5.\n'
      'Thanks,\n'
      'Rose\n'
      '\n'
      '\n'
      'Sent from Yahoo Mail for iPad <https://mail.onelink.me/107872968?pid=nativeplacement&c=Global_Acquisition&af_android_url=https%3A%2F%2Fplay.google.com>';

  test('wrapping joins, real breaks stay, the mail app becomes a footer', () {
    final mail = MailText.of(rose);

    expect(mail.body, 'Hi, it is the blower and our address is 1 Main Road. Friday works because he will be home.\n'
        'Mondays 2-5,\n'
        'Thursdays 11:30-5.\n'
        'Thanks,\n'
        'Rose');
    expect(mail.footer, 'Sent from Yahoo Mail for iPad');
  });

  test('a URL shows as its domain, a quoted reply keeps its signature', () {
    final mail = MailText.of('See https://example.com/a?b=c\n\nSent from my iPhone\n> On Monday you wrote');

    expect(mail.footer, isNull);
    final shown = mail.spans(null).map((s) => (s as TextSpan).text).join();
    expect(shown, contains('example.com ↗'));
    expect(shown, isNot(contains('?b=c')));
  });
}
