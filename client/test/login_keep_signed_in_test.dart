import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// "Keep me signed in" starts ticked. Jose, 2026-10-06: "make the keep me
/// logged in toggle when logging in activated by default instead of turned
/// off." Ticked means the server is asked for a remembered session, so the
/// request is asserted, not only the box.
void main() {
  Future<List<Map<String, dynamic>>> signIn(
    WidgetTester tester, {
    bool untick = false,
  }) async {
    final sent = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/auth/login')) {
          sent.add(jsonDecode(request.body) as Map<String, dynamic>);
        }
        return http.Response(
          '{"message":"These credentials do not match our records."}',
          422,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: LoginScreen(
          api: api,
          session: Session(api),
          onCreateAccount: () {},
        ),
      ),
    );
    await tester.pump();

    expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isTrue);
    if (untick) {
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
    }

    await tester.enterText(
      find.widgetWithText(TextField, 'Email address'),
      'dana@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Password'),
      'not-a-real-one',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    return sent;
  }

  testWidgets(
    'keep me signed in starts ticked and asks for a remembered session',
    (tester) async {
      final sent = await signIn(tester);
      expect(sent.single['remember'], isTrue);
    },
  );

  testWidgets('unticked, it asks for a session that ends with the tab', (
    tester,
  ) async {
    final sent = await signIn(tester, untick: true);
    expect(sent.single['remember'], isFalse);
  });
}
