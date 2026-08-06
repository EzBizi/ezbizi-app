import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/staff/staff_login_screen.dart';
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

  testWidgets('the customer host offers no way into the control plane', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // Staff live at dev.ezbizi.com now. nginx refuses /api/v1/staff here too,
    // so a link would only lead somewhere that answers 404.
    expect(find.text('EzBizi staff'), findsNothing);
    expect(find.text('CONTROL PLANE'), findsNothing);
    expect(find.text('Authenticator code'), findsNothing);
  });

  testWidgets('the control-plane form asks for a second factor', (tester) async {
    // Pumped directly: the host picks this screen in the real app, and a widget
    // test cannot set Uri.base. Which hostname selects it is covered in
    // deployment_test.dart.
    await tester.pumpWidget(MaterialApp(
      home: StaffLoginScreen(staff: StaffSession(ApiClient())),
    ));
    await tester.pump();

    // TOTP is mandatory for staff, so the field is not optional and not a second
    // screen — the server takes both factors in one request.
    expect(find.text('CONTROL PLANE'), findsOneWidget);
    expect(find.text('Staff email'), findsOneWidget);
    expect(find.text('Authenticator code'), findsOneWidget);
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
