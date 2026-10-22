import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Suggestions: the search bar filters the rail.
///
/// Scoped to the rail: the first suggestion is also open in the detail pane.
void main() {
  Finder inRail(String text) => find.descendant(
    of: find.byKey(const Key('suggestions-rail')),
    matching: find.text(text),
  );

  Map<String, dynamic> row(String id, String status, String body) => {
    'id': id,
    'body': body,
    'status': status,
    'decision_note': null,
    'decided_at': null,
    'created_at': '2026-09-02T10:00:00+00:00',
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1600, 2200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final rows = [
      row('a', 'new', 'Let the board remember its filter.'),
      row('b', 'reviewing', 'Print a route sheet.'),
    ];

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SuggestionsScreen(
            api: ApiClient(
              client: MockClient((request) async {
                if (request.url.path.endsWith('/suggestions')) {
                  return http.Response(
                    jsonEncode({'data': rows}),
                    200,
                    headers: const {'content-type': 'application/json'},
                  );
                }
                return http.Response('{"message":"unstubbed"}', 404);
              }),
            ),
            formats: const DisplayFormats(
              dateFormat: 'M/D/YYYY',
              timeFormat: '12h',
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching suggestion and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(inRail('Let the board remember its filter.'), findsOneWidget);
    expect(inRail('Print a route sheet.'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('suggestions-search')),
      'route',
    );
    await tester.pump();

    expect(inRail('Print a route sheet.'), findsOneWidget);
    expect(inRail('Let the board remember its filter.'), findsNothing);
  });

  testWidgets('a term matching no suggestion says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('suggestions-search')), 'zzz');
    await tester.pump();

    expect(inRail('No suggestions match "zzz".'), findsOneWidget);
    expect(inRail('Print a route sheet.'), findsNothing);
    expect(inRail('Let the board remember its filter.'), findsNothing);
  });
}
