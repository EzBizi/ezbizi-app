import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_manager_screen.dart';
import 'package:ezbizi_client/screens/ads_performance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Campaigns that turn on and off at set times (Jose, 2026-11-05; design 185).
void main() {
  setUp(forgetAdsMemory);

  late List<http.Request> requests;

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> me(List<String> permissions) => {
    'data': {
      'id': 'u-1',
      'name': 'Jose Silva',
      'email': 'jose@example.com',
      'has_avatar': false,
      'role': {'id': 'role-1', 'name': 'Administrator'},
      'permissions': permissions,
      'modules': [
        {
          'key': 'ads_performance',
          'label': 'Ads',
          'available': true,
          'reachable': true,
        },
      ],
      'preferences': <String, dynamic>{},
      'organization': {
        'id': 'org-1',
        'name': 'A1-Group',
        'franchise_label': 'Franchise',
        'is_read_only': false,
      },
      'scope': {
        'type': 'company',
        'id': 'co-1',
        'label': 'A-1 Chimney',
        'path': ['A1-Group', 'A-1 Chimney'],
      },
      'companies': <Map<String, Object?>>[],
      'franchises': <Map<String, Object?>>[],
    },
  };

  Map<String, dynamic> campaign(String id, String name) => {
    'level': 'campaign',
    'id': id,
    'name': name,
    'status': 'ACTIVE',
    'effective_status': 'ACTIVE',
    'daily_budget': 40,
    'lifetime_budget': null,
    'spend': 100,
    'impressions': 0,
    'clicks': 10,
    'leads': 2,
    'cost_per_lead': 50,
    'children': <dynamic>[],
  };

  Map<String, dynamic> scheduled({String? error}) => {
    'id': 'sch-1',
    'connection_id': 'm1',
    'object_id': '1',
    'object_name': 'Meta Leads · Milwaukee',
    'days': [1, 2, 3, 4, 5],
    'on_at': '08:00',
    'off_at': '18:00',
    'timezone': 'America/New_York',
    'summary': 'Mon–Fri 8am–6pm EST',
    'next_change': 'off at 6:00 PM',
    'last_change': 'Turned on at 8:00 AM',
    'wants': 'on',
    'last_error': error,
  };

  Future<void> openMeta(
    WidgetTester tester, {
    required List<String> permissions,
    List<Map<String, dynamic>> schedules = const [],
    double width = 1400,
  }) async {
    tester.view.physicalSize = Size(width, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    requests = [];

    final api = ApiClient(
      client: MockClient((request) async {
        requests.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) return json(me(permissions));
        if (path.endsWith('/meta-ads/accounts')) {
          return json({
            'data': [
              {
                'connection_id': 'm1',
                'account_id': 'act_1',
                'name': 'A1 Chimney',
              },
            ],
          });
        }
        if (path.endsWith('/meta-ads/tree')) {
          return json({
            'data': {
              'campaigns': [
                campaign('1', 'Meta Leads · Milwaukee'),
                campaign('2', 'Meta Leads · Columbus'),
              ],
            },
          });
        }
        if (path.endsWith('/ad-schedules') && request.method == 'GET') {
          return json({'data': schedules});
        }
        if (path.endsWith('/ad-schedules') && request.method == 'PUT') {
          return json({'data': scheduled()});
        }
        if (path.contains('/ad-schedules/') && request.method == 'DELETE') {
          return json({
            'data': {'id': 'sch-1'},
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: AdsManagerScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Meta'));
    await tester.pumpAndSettle();
  }

  // Meta's chips are Marketing › Ads (ads_manager); the Google table's is
  // the report in Reports › Google Ads (ads_performance).
  const manage = [
    'ads_manager.view',
    'ads_manager.manage',
    'ads_performance.view',
    'ads_performance.manage',
  ];

  testWidgets('a manager sets Mon–Fri 8 to 6 from the chip', (tester) async {
    await openMeta(tester, permissions: manage);

    final chip = find.byKey(const ValueKey('meta-schedule-1'));
    expect(
      find.descendant(of: chip, matching: find.text('＋ Schedule')),
      findsOneWidget,
    );
    await tester.tap(chip);
    await tester.pumpAndSettle();
    expect(find.text('Schedule · Meta Leads · Milwaukee'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('ad-schedule-save')));
    await tester.pumpAndSettle();

    final put = requests.singleWhere((r) => r.method == 'PUT');
    final body = jsonDecode(put.body) as Map;
    expect(body['object_id'], '1');
    expect(body['days'], [1, 2, 3, 4, 5]);
    expect('${body['on_at']}–${body['off_at']}', '08:00–18:00');
    expect(body.containsKey('timezone'), isFalse); // the organization's
  });

  testWidgets('a refused change turns the chip amber and says why', (
    tester,
  ) async {
    await openMeta(
      tester,
      permissions: manage,
      schedules: [
        scheduled(
          error: 'Meta refused: (#200) Requires ads_management permission',
        ),
      ],
    );

    expect(find.text("Couldn't turn on"), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('meta-schedule-1')));
    await tester.pumpAndSettle();
    expect(
      find.text('Meta refused: (#200) Requires ads_management permission'),
      findsOneWidget,
    );
  });

  testWidgets('reading alone shows a schedule and offers none', (tester) async {
    await openMeta(
      tester,
      permissions: const ['ads_manager.view'],
      schedules: [scheduled()],
    );

    expect(find.text('Mon–Fri 8am–6pm EST · off at 6:00 PM'), findsOneWidget);
    expect(find.text('＋ Schedule'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('meta-schedule-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('ad-schedule-save')), findsNothing);
    expect(find.byKey(const ValueKey('ad-schedule-remove')), findsNothing);
  });

  testWidgets(
    'removing is a two-step, and on a phone the chip sits under the name',
    (tester) async {
      await openMeta(
        tester,
        permissions: manage,
        schedules: [scheduled()],
        width: 390,
      );

      final name = tester.getTopLeft(find.text('Meta Leads · Milwaukee'));
      final chip = tester.getTopLeft(
        find.byKey(const ValueKey('meta-schedule-1')),
      );
      expect(chip.dy, greaterThan(name.dy));

      await tester.tap(find.byKey(const ValueKey('meta-schedule-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('ad-schedule-remove')));
      await tester.pump();
      expect(requests.where((r) => r.method == 'DELETE'), isEmpty);
      await tester.tap(find.byKey(const ValueKey('ad-schedule-remove-go')));
      await tester.pumpAndSettle();
      expect(
        requests.singleWhere((r) => r.method == 'DELETE').url.path,
        endsWith('/ad-schedules/sch-1'),
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('the Google table carries the same chip', (tester) async {
    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return json(me(manage));
        if (path.endsWith('/ads/accounts')) {
          return json({
            'data': [
              {
                'connection_id': 'c1',
                'customer_id': '4941850746',
                'name': 'A1 Chimney - Cleveland',
              },
            ],
          });
        }
        if (path.endsWith('/ads/performance')) {
          return json({
            'data': [
              {
                'connection_id': 'c1',
                'currency': 'USD',
                'account': {'id': '4941850746', 'name': 'Cleveland'},
                'series': <dynamic>[],
                'campaigns': [
                  {'id': '111', 'name': 'Search', 'status': 'ENABLED'},
                  {'id': '222', 'name': 'Display', 'status': 'PAUSED'},
                ],
              },
            ],
            'refused': <dynamic>[],
          });
        }
        if (path.endsWith('/ad-schedules')) {
          return json({
            'data': [
              {...scheduled(), 'connection_id': 'c1', 'object_id': '111'},
            ],
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: AdsPerformanceScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('ads-schedule-111')),
        matching: find.text('Mon–Fri 8am–6pm EST · off at 6:00 PM'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('ads-schedule-222')),
        matching: find.text('＋ Schedule'),
      ),
      findsOneWidget,
    );
  });
}
