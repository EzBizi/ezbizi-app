import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/workiz_connect_form.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 109 (Jose, 2026-10-19): choose v1 or v2 when connecting, and edit
/// an existing connection.
void main() {
  testWidgets('v2 asks for one key; v1 adds the optional secret', (
    tester,
  ) async {
    final sent = <http.Request>[];
    await _pump(tester, sent);

    expect(find.byKey(const ValueKey('workiz-secret')), findsNothing);
    expect(find.textContaining('v2 tab'), findsOneWidget);

    await tester.tap(find.text('API v1'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('workiz-secret')), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('workiz-token')), 'tok');
    await tester.enterText(find.byKey(const ValueKey('workiz-secret')), 'sec');
    await tester.tap(find.byKey(const ValueKey('workiz-submit')));
    await tester.pumpAndSettle();

    expect(sent.single.method, 'POST');
    expect(sent.single.url.path, '/api/v1/social/workiz');
    expect(jsonDecode(sent.single.body), {
      'method': 'v1',
      'api_token': 'tok',
      'auth_secret': 'sec',
    });
  });

  testWidgets('the signed-in session is shown and cannot be picked', (
    tester,
  ) async {
    await _pump(tester, []);
    final seg = tester.widget<SegmentedButton<String>>(
      find.byKey(const ValueKey('workiz-method')),
    );
    expect(seg.segments.last.value, 'session');
    expect(seg.segments.last.enabled, isFalse);
  });

  testWidgets('editing replaces the credentials with a PUT', (tester) async {
    final sent = <http.Request>[];
    await _pump(tester, sent, id: 'c-7', method: 'v2');

    expect(find.text('Replace API key'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('workiz-token')), 'new');
    await tester.tap(find.byKey(const ValueKey('workiz-submit')));
    await tester.pumpAndSettle();

    expect(sent.single.method, 'PUT');
    expect(sent.single.url.path, '/api/v1/social/workiz/c-7');
  });

  testWidgets('a key for the other API says so under the box', (tester) async {
    await _pump(tester, [], status: 422);
    await tester.enterText(find.byKey(const ValueKey('workiz-token')), 'x');
    await tester.tap(find.byKey(const ValueKey('workiz-submit')));
    await tester.pumpAndSettle();
    expect(find.text('That key is for API v1.'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<http.Request> sent, {
  String? id,
  String? method,
  int status = 200,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      sent.add(request);
      return http.Response(
        jsonEncode(
          status == 422
              ? {
                  'message': 'Invalid.',
                  'errors': {
                    'api_token': ['That key is for API v1.'],
                  },
                }
              : {'data': <String, dynamic>{}},
        ),
        status,
        headers: const {'content-type': 'application/json'},
      );
    }),
  );
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: WorkizConnectForm(
            api: api,
            connectionId: id,
            method: method,
            onDone: () {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
