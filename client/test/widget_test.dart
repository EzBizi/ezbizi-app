import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('an unauthenticated app shows the sign-in screen', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // The gate that matters: no session, no application shell.
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Sign in to your account'), findsOneWidget);
  });

  testWidgets('the staff form is reachable and asks for a second factor', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    await tester.tap(find.text('EzBizi staff'));
    await tester.pumpAndSettle();

    // TOTP is mandatory for staff, so the field is not optional and not a second
    // screen — the server takes both factors in one request.
    expect(find.text('CONTROL PLANE'), findsOneWidget);
    expect(find.text('Staff email'), findsOneWidget);
    expect(find.text('Authenticator code'), findsOneWidget);
  });

  testWidgets('leaving the staff form returns to the customer sign-in', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    await tester.tap(find.text('EzBizi staff'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Back to customer sign-in'));
    await tester.pumpAndSettle();

    expect(find.text('Sign in to your account'), findsOneWidget);
  });

  testWidgets('the wordmark is legible in dark mode', (tester) async {
    // "Bizi" was once fixed to the brand's ink and vanished on a dark
    // background. It follows the theme now, and this is what says so.
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    final wordmark = tester.widget<RichText>(
      find.descendant(of: find.byType(Card), matching: find.byType(RichText)).first,
    );

    final spans = (wordmark.text as TextSpan).children!;
    final bizi = spans.firstWhere((s) => (s as TextSpan).text == 'Bizi') as TextSpan;

    expect(bizi.style!.color, Colors.white);
  });

  test('the API base URL is a build flag, never a hardcoded host', () {
    // A1 Tools compiled its host into the binary, which is why moving that app
    // to a new domain is a migration rather than a config change. The default
    // here is same-origin, so a web build cannot drift from where it was served.
    expect(ApiClient.baseUrl, isEmpty,
        reason: 'Default must be same-origin; set API_BASE at build time to override.');
  });
}
