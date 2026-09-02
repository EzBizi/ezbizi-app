import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a new suggestion offers edit and withdraw', (tester) async {
    await _pump(tester, _listing([_row(status: 'new')]));

    expect(find.text('Let the board remember its filter.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsOneWidget);
    expect(find.byIcon(Icons.delete_outline), findsOneWidget);
  });

  /// The client half of the server rule: a control the server will refuse must
  /// not be offered. See SuggestionsController::frozen.
  testWidgets('a decided suggestion shows the note and no controls', (
    tester,
  ) async {
    await _pump(tester, _listing([
      _row(status: 'denied', note: 'It is in Settings already.'),
    ]));

    expect(find.text('It is in Settings already.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsNothing);
    expect(find.byIcon(Icons.delete_outline), findsNothing);
  });
}

Map<String, dynamic> _row({required String status, String? note}) => {
  'id': 'a',
  'body': 'Let the board remember its filter.',
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-02T11:00:00+00:00',
  'created_at': '2026-09-02T10:00:00+00:00',
};

MockClientHandler _listing(List<Map<String, dynamic>> rows) {
  return (http.Request request) async {
    if (request.url.path.endsWith('/suggestions')) {
      return http.Response(
        jsonEncode({'data': rows}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.Response('{}', 404, headers: {'content-type': 'application/json'});
  };
}

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SuggestionsScreen(api: ApiClient(client: MockClient(handler))),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
