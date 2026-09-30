import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field/map_overlays.dart';
import 'package:ezbizi_client/screens/field/map_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Field › Map. The first case is Jose's crash (2026-09-28): he tapped his own
/// pin, and his trail was one spot repeated, because he was standing still.
void main() {
  testWidgets('tapping a pin whose trail is one spot repeated does not crash', (
    tester,
  ) async {
    await _pump(
      tester,
      trail: [
        for (var i = 0; i < 5; i++)
          {'lat': 26.0, 'lng': -80.14, 'at': '2026-09-28T13:0$i:00Z'},
      ],
    );

    await tester.tap(find.text('Jose Luis Demasi').last);
    await tester.pumpAndSettle();
    // Let the map's own timers run too (the copyright line asks 600 ms after
    // the camera moves, reading the zoom), then draw again.
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    // The row in the list, not the legend's line of the same name.
    expect(
      find.descendant(
        of: find.byType(FieldMapPanel),
        matching: find.textContaining('Route today'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the floating list says since when, how far and how fast', (
    tester,
  ) async {
    await _pump(tester, trail: const []);

    expect(find.text('Tracked'), findsOneWidget);
    expect(find.text('1 of 1 reporting now'), findsOneWidget);
    expect(find.textContaining('Since '), findsOneWidget);
    expect(find.textContaining('30 mph · 6.9 mi'), findsOneWidget);
  });

  testWidgets('a dark app asks for the dark map', (tester) async {
    final themes = <String?>[];
    await _pump(tester, trail: const [], dark: true, onTiles: themes.add);

    expect(themes, ['dark']);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, Object?>> trail,
  bool dark = false,
  void Function(String?)? onTiles,
}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final seen = DateTime.now().toUtc().toIso8601String();
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/field/map/tiles')) {
        onTiles?.call(request.url.queryParameters['theme']);
        // A real template, so the tile layer runs: it is the layer that
        // breaks at infinite zoom. Tile images never load in a test.
        return _json({
          'data': {'url': 'https://tiles.invalid/{z}/{x}/{y}.png'},
        });
      }
      if (path.endsWith('/field/map') &&
          request.url.queryParameters.containsKey('user_id')) {
        return _json({
          'data': {
            'user': {'id': 'u-1', 'name': 'Jose Luis Demasi'},
            'points': trail,
          },
        });
      }
      if (path.endsWith('/field/map')) {
        return _json({
          'data': [
            {
              'user': {'id': 'u-1', 'name': 'Jose Luis Demasi'},
              'lat': 26.0,
              'lng': -80.14,
              'last_seen_at': seen,
              'fresh': true,
              'stopped': false,
              'silent': false,
              'at_job': null,
              'since': seen,
              'distance_mi': 6.9,
              'speed_mph': 30,
            },
          ],
        });
      }
      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: dark ? EzBiziTheme.dark() : EzBiziTheme.light(),
      home: Scaffold(body: FieldMapTab(api: api)),
    ),
  );
  await tester.pumpAndSettle();
  // The 30-second refresh must not outlive the test.
  addTearDown(() => tester.pumpWidget(const SizedBox()));
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );
