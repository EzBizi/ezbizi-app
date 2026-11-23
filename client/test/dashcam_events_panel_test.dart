import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/monitoring/dashcam_events_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  ApiClient api(List<Map<String, Object?>> events, List<String> calls) =>
      ApiClient(
        client: MockClient((r) async {
          calls.add(
            '${r.method} ${r.url.path.replaceFirst(RegExp(r'^.*/v1'), '')}',
          );
          if (r.url.path.endsWith('/clip-url')) {
            return json({
              'data': {'url': 'https://vendor.test/m1', 'expires_at': 'x'},
            });
          }
          return json({'data': events});
        }),
      );

  Widget wrap(ApiClient a) => MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(child: DashcamEventsPanel(api: a)),
    ),
  );

  testWidgets('no events: nothing on screen', (tester) async {
    await tester.pumpWidget(wrap(api(const [], [])));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('dashcam-events')), findsNothing);
  });

  testWidgets(
    'an event lists with its van and driver; Play asks for a fresh link',
    (tester) async {
      final calls = <String>[];
      await tester.pumpWidget(
        wrap(
          api([
            {
              'id': 'e1',
              'type': 'harsh_brake',
              'occurred_at': '2026-11-16T15:01:00Z',
              'vehicle': {'id': 'v1', 'name': '101 Ford Transit'},
              'driver': {'id': 'u1', 'name': 'Toula Elwell'},
              'has_clip': true,
            },
          ], calls),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.descendant(
          of: find.byKey(const Key('dashcam-event-e1')),
          matching: find.text('Harsh braking'),
        ),
        findsOneWidget,
      );
      expect(
        find.textContaining('101 Ford Transit · Toula Elwell'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('dashcam-play-e1')));
      await tester.pump();
      expect(calls, contains('POST /monitoring/dashcam-events/e1/clip-url'));
    },
  );
}
