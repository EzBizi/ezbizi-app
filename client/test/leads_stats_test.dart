import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_stats.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 107 (A1 takeover): Leads › Manage › Stats. Invented numbers.
void main() {
  testWidgets('the numbers, markets and heatmap come from /leads/stats', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final asked = <String>[];

    http.Response json(Object b, [int s = 200]) => http.Response(
      jsonEncode(b),
      s,
      headers: const {'content-type': 'application/json'},
    );
    final api = ApiClient(
      client: MockClient((r) async {
        asked.add(r.url.path);
        final p = r.url.path;
        if (p.endsWith('/leads/stats')) {
          return json({
            'data': {
              'total': 412,
              'booked': 255,
              'booking_rate': 0.62,
              'by_source': {'ls_phone_call': 210, 'website_chat': 57},
              'markets': [
                {'market': 'Springfield', 'total': 182, 'booked': 120},
              ],
              'loss': {
                'statuses': {'lost': 20, 'not_a_lead': 10, 'archived': 8},
                'reasons': [
                  {'reason': 'Too far', 'count': 14},
                ],
              },
              'services_booked': [
                {'service': 'Chimney sweep', 'count': 90},
              ],
              'time_slots': {
                'days': ['Mon', 'Tue'],
                'slots': ['<8am', '8-11'],
                'counts': {
                  'Mon': {'8-11': 3},
                },
              },
              'dispatchers': [
                {'dispatcher': 'Morgan', 'total': 140, 'booked': 92},
              ],
            },
          });
        }
        if (p.endsWith('/leads/stats/points')) return json({'data': []});
        if (p.endsWith('/field/coverage')) {
          return json({
            'data': {'offices': [], 'jobs': []},
          });
        }
        if (p.endsWith('/field/map/tiles')) {
          return json({'message': 'No.'}, 403);
        }
        return json({'message': 'not stubbed'}, 404);
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadsStats(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('412'), findsOneWidget);
    expect(find.text('62%'), findsOneWidget);
    expect(find.text('38'), findsOneWidget, reason: 'Lost adds the three.');
    expect(find.text('LS Call'), findsOneWidget);
    expect(find.textContaining('Springfield: 182'), findsOneWidget);
    expect(find.textContaining('Morgan: 140'), findsOneWidget);
    expect(find.byKey(const ValueKey('leads-stats-heatmap')), findsOneWidget);
    expect(find.text('The map needs access to Field’s map.'), findsOneWidget);
    expect(asked, contains(endsWith('/leads/stats')));
  });

  testWidgets('an office is sized and coloured from its own leads', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    http.Response json(Object b, [int s = 200]) => http.Response(
      jsonEncode(b),
      s,
      headers: const {'content-type': 'application/json'},
    );
    final api = ApiClient(
      client: MockClient((r) async {
        final p = r.url.path;
        if (p.endsWith('/leads/stats')) {
          return json({
            'data': {
              'total': 10,
              'booked': 5,
              'booking_rate': 0.5,
              'offices': [
                {'company_id': 'co-1', 'total': 10, 'booked': 9},
              ],
            },
          });
        }
        if (p.endsWith('/field/coverage')) {
          return json({
            'data': {
              'offices': [
                {
                  'company_id': 'co-1',
                  'name': 'Brightside Home',
                  'lat': 39.8,
                  'lng': -89.6,
                },
              ],
              'jobs': [],
            },
          });
        }
        if (p.endsWith('/field/map/tiles')) {
          return json({
            'data': {'url': 'https://tiles.example/{z}/{x}/{y}.png'},
          });
        }
        return json({'data': []});
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadsStats(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pump(const Duration(seconds: 1));

    expect(
      find.byKey(const ValueKey('leads-stats-office-co-1')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('map-attribution')), findsOneWidget);
  });
}
