import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/message_list.dart';
import 'package:ezbizi_client/screens/email/sender_brand.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('email.apple.com is apple.com for the icon', () {
    expect(
      SenderBrand.registrableDomain('hello@email.apple.com'),
      'apple.com',
    );
    expect(
      SenderBrand.faviconUrl('hello@email.apple.com'),
      'https://www.google.com/s2/favicons?sz=64&domain=apple.com',
    );
  });

  test('a co.uk address keeps the compound tld', () {
    expect(
      SenderBrand.registrableDomain('x@mail.shop.co.uk'),
      'shop.co.uk',
    );
  });

  // Audit 11-27: a brand in the From line is what a spoof always has, so
  // only a DMARC pass earns the check; a silent provider does not.
  test('apple is a verified brand only when dmarc passed', () {
    expect(SenderBrand.verified('hello@email.apple.com'), isFalse);
    expect(
      SenderBrand.verified('hello@email.apple.com', authenticated: true),
      isTrue,
    );
    expect(
      SenderBrand.verified('hello@email.apple.com', authenticated: false),
      isFalse,
    );
    expect(SenderBrand.verified('ana@example.com'), isFalse);
  });

  testWidgets('apple mail draws the verified mark', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(
          body: SenderAvatar(
            name: 'Apple Business',
            email: 'hello@email.apple.com',
            authenticated: true,
          ),
        ),
      ),
    );

    expect(find.text('A'), findsOneWidget);
    expect(find.byTooltip('Verified sender'), findsOneWidget);
  });

  testWidgets('a failed check is not decorated even for apple', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(
          body: SenderAvatar(
            name: 'Apple Business',
            email: 'hello@email.apple.com',
            authenticated: false,
          ),
        ),
      ),
    );

    expect(find.byTooltip('Verified sender'), findsNothing);
  });
}
