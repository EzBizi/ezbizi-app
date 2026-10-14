import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/routing_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Routing (UI queue 90, desktop 1 and phone 4), against
/// Scheduling's `GET /scheduling/routes` (a95f30f6, 623cabda). Every name and
/// address here is invented.
void main() {
  Map<String, dynamic> stop(
    String number,
    String name,
    String time, {
    bool approximate = false,
    double lat = 41.9,
  }) => {
    'number': number,
    'service': 'Chimney sweep',
    'time': time,
    'end_time': null,
    'customer': {'name': name, 'address': '$number Larkspur Ln'},
    'latitude': lat,
    'longitude': -87.6,
    'approximate': approximate,
  };

  final plan = {
    'area_id': 'a-mg',
    'area_name': 'Maple Grove',
    'date': '2026-10-15',
    'capacity': 6,
    'technicians': 1,
    'routes': [
      {
        'technician': 1,
        'stops': [
          stop('4127', 'Ana Ruiz', '08:00'),
          stop('4102', 'Marta Silva', '10:00', approximate: true, lat: 41.95),
        ],
        'miles': 3.4,
        'extra': false,
        'maps_url': 'https://www.google.com/maps/dir/?api=1',
      },
      {
        'technician': 2,
        'stops': [stop('4171', 'Zoe Marr', '12:00', lat: 42.0)],
        'miles': 0,
        'extra': true,
        'maps_url': null,
      },
    ],
    'unplaced': [stop('4158', 'Omar Haddad', '14:00')],
  };

  late List<Map<String, String>> asked;

  Future<void> pump(
    WidgetTester tester, {
    double width = 1280,
    List<Map<String, dynamic>> areas = const [
      {'id': 'a-mg', 'name': 'Maple Grove'},
      {'id': 'a-cf', 'name': 'Cedar Falls'},
    ],
  }) async {
    tester.view.physicalSize = Size(width, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    asked = [];

    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/scheduling/areas')) return json({'data': areas});
        if (path.endsWith('/scheduling/routes')) {
          asked.add(request.url.queryParameters);
          return json({'data': plan});
        }
        return json({'data': null});
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: RoutingPane(api: api, visible: true)),
      ),
    );
    await tester.pumpAndSettle();
  }

  String ymd(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  testWidgets('a card per technician beside the map, the extra one marked, '
      'and the jobs with no location listed', (tester) async {
    await pump(tester);

    final today = DateUtils.dateOnly(DateTime.now());
    expect(asked.single, {'area_id': 'a-mg', 'date': ymd(today)});
    expect(find.byKey(const ValueKey('routing-map')), findsOneWidget);
    expect(find.text('Technician 1'), findsOneWidget);
    expect(find.text('2 stops · 3.4 mi'), findsOneWidget);
    expect(find.text('Needs another technician'), findsOneWidget);
    expect(find.byKey(const ValueKey('routing-maps-0')), findsOneWidget);
    // No Google Maps link from the server, no button.
    expect(find.byKey(const ValueKey('routing-maps-1')), findsNothing);
    expect(find.text('No location · 1'), findsOneWidget);

    // A card opens onto its stops, in order, the approximate one said so.
    await tester.tap(find.text('Technician 1'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('routing-stop-0-1')), findsOneWidget);
    expect(
      find.text('Approximate location: the centre of its zip code.'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('routing-day-next')));
    await tester.pumpAndSettle();
    expect(asked.last['date'], ymd(today.add(const Duration(days: 1))));
  });

  testWidgets('on a phone a route opens in the tab, with a way back', (
    tester,
  ) async {
    await pump(tester, width: 400);

    await tester.tap(find.text('Technician 1'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('routing-route-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('routing-stop-0-0')), findsOneWidget);
    expect(find.byKey(const ValueKey('routing-maps-0')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('routing-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('routing-route-page')), findsNothing);
  });

  testWidgets('with no service area it says where to add one', (tester) async {
    await pump(tester, areas: const []);

    expect(asked, isEmpty);
    expect(find.textContaining('No service areas yet'), findsOneWidget);
  });
}
