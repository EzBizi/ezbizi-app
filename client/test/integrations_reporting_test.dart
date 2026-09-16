import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/api_key_connect_form.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The shared connect form behind Settings › Integrations › Reporting.
///
/// Jose, 2026-09-16: "make the connection in Integrations", for Airtable and
/// Workiz both. The form is shared rather than written per provider, because
/// Workiz can also be connected from Reports › Experimental and two forms
/// posting to one endpoint would be two sets of field names to keep in step.
void main() {
  testWidgets('it posts the field names the API asks for', (tester) async {
    Map<String, dynamic>? sent;
    String? path;

    await _pump(tester, (request) {
      path = request.url.path;
      sent = jsonDecode(request.body) as Map<String, dynamic>;
      return http.Response(jsonEncode({'data': {}}), 201);
    });

    await tester.enterText(find.byType(TextField).at(0), '  pat_value  ');
    await tester.enterText(find.byType(TextField).at(1), 'appWhbwht0xgB5Yut');
    await tester.tap(find.text('Connect base'));
    await tester.pumpAndSettle();

    expect(path, endsWith('/social/airtable'));

    // Trimmed, because a pasted secret carries whitespace far more often than
    // anybody expects and a leading space is invisible in the box.
    expect(sent, {'api_token': 'pat_value', 'base_id': 'appWhbwht0xgB5Yut'});
  });

  testWidgets('a refusal lands on the FIELD the server named', (tester) async {
    // The whole reason the server carries the field: an unreachable base and a
    // rejected token are different fixes, and the sentence for the first
    // mentions the word "token". A screen that guessed would send somebody to
    // regenerate a credential that was fine.
    await _pump(
      tester,
      (request) => http.Response(
        jsonEncode({
          'message': 'The given data was invalid.',
          'errors': {
            'base_id': ['That token cannot reach that base.'],
          },
        }),
        422,
      ),
    );

    await tester.enterText(find.byType(TextField).at(0), 'pat_fine');
    await tester.enterText(find.byType(TextField).at(1), 'appWhbwht0xgB5Yut');
    await tester.tap(find.text('Connect base'));
    await tester.pumpAndSettle();

    expect(find.text('That token cannot reach that base.'), findsOneWidget);
  });

  testWidgets('a failure with no field is shown apart from the boxes', (
    tester,
  ) async {
    // A server outage under the token box reads as "your token is wrong",
    // which is a different fix and a wasted afternoon.
    await _pump(
      tester,
      (request) => http.Response(jsonEncode({'message': 'Server error.'}), 500),
    );

    await tester.enterText(find.byType(TextField).at(0), 'pat_fine');
    await tester.enterText(find.byType(TextField).at(1), 'appWhbwht0xgB5Yut');
    await tester.tap(find.text('Connect base'));
    await tester.pumpAndSettle();

    final fields = tester.widgetList<TextField>(find.byType(TextField));
    for (final field in fields) {
      expect(field.decoration?.errorText, isNull);
    }
  });

  testWidgets('a successful connect clears the secrets from the boxes', (
    tester,
  ) async {
    // They are not obscured, on purpose — somebody has to see whether a paste
    // landed. Leaving a token sitting in a visible box after it is stored is
    // the cost of that, so it is cleared.
    var reloaded = false;

    await _pump(
      tester,
      (request) => http.Response(jsonEncode({'data': {}}), 201),
      onConnected: () => reloaded = true,
    );

    await tester.enterText(find.byType(TextField).at(0), 'pat_secret');
    await tester.tap(find.text('Connect base'));
    await tester.pumpAndSettle();

    expect(reloaded, isTrue);
    expect(find.text('pat_secret'), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester,
  http.Response Function(http.Request request) handler, {
  VoidCallback? onConnected,
}) async {
  final api = ApiClient(client: MockClient((request) async => handler(request)));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ApiKeyConnectForm(
          api: api,
          path: '/social/airtable',
          submitLabel: 'Connect base',
          fields: const [
            ('api_token', 'Personal access token', 'pat…'),
            ('base_id', 'Base id', 'app…'),
          ],
          onConnected: onConnected ?? () {},
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
