import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/email/consent_window.dart';
import 'package:ezbizi_client/widgets/connect_account.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _Window implements ConsentWindow {
  String? went;
  @override
  void goTo(String url) => went = url;
  @override
  bool get closed => false;
  @override
  void close() {}
}

void main() {
  // Jose, 2026-09-30: connecting Thumbtack from the app said "Waiting for
  // Google…" and the browser never opened. The label names no provider, and
  // the link can be copied while waiting.
  testWidgets('while waiting, it names no provider and offers the link', (tester) async {
    final window = _Window();
    final api = ApiClient(
      client: MockClient((request) async {
        final body = request.url.path.endsWith('/start')
            ? {'data': {'authorization_url': 'https://auth.thumbtack.com/oauth2/auth?x=1', 'flow': 'f-1'}}
            : {'data': {'state': 'pending'}};
        return http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json'});
      }),
    );

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ConnectAccount(
          api: api,
          label: 'Thumbtack',
          startPath: '/social/thumbtack/start',
          onConnected: () async {},
          openConsent: () => window,
        ),
      ),
    ));

    await tester.tap(find.text('Connect Thumbtack'));
    await tester.pump();
    await tester.pump();

    expect(window.went, 'https://auth.thumbtack.com/oauth2/auth?x=1');
    expect(find.textContaining('Google'), findsNothing);
    expect(find.text('Waiting for the sign-in…'), findsOneWidget);
    expect(find.byKey(const ValueKey('copy-sign-in-link')), findsOneWidget);

    await tester.pumpWidget(const SizedBox()); // cancels the poll timer
  });
}
