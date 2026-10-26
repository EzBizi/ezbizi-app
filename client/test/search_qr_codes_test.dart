import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/marketing/qr_codes_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Marketing › QR Codes: the search bar keeps the codes whose words match.
void main() {
  Map<String, Object?> row(String id, String name, String destination) => {
    'id': id,
    'name': name,
    'code': '$id-7KQ2',
    'url': 'https://ezbizi.test/q/$id-7KQ2',
    'destination_url': destination,
    'scans': 0,
    'last_scanned_at': null,
    'active': true,
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/marketing/qr-codes')) {
          return http.Response(
            jsonEncode({
              'data': [
                row(
                  'q-1',
                  'Van 1, side door',
                  'https://everdoors.example/spring',
                ),
                row('q-2', 'Yard sign', 'https://everdoors.example/fall'),
              ],
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('{}', 200);
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: QrCodesTab(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching code and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Van 1, side door'), findsOneWidget);
    expect(find.text('Yard sign'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('qr-codes-search')), 'yard');
    await tester.pumpAndSettle();

    expect(find.text('Yard sign'), findsOneWidget);
    expect(find.text('Van 1, side door'), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('qr-codes-search')), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No QR codes match "zebra".'), findsOneWidget);
    expect(find.text('Van 1, side door'), findsNothing);
    expect(find.text('Yard sign'), findsNothing);
  });
}
