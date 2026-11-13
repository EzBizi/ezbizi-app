import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_manager_screen.dart';
import 'package:ezbizi_client/screens/google_campaigns_view.dart' show lsaWeekly;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Marketing › Ads › Google (Jose, 2026-11-09): the same controls as Meta,
/// every change confirmed before it is sent.
void main() {
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
      'modules': <Map<String, Object?>>[],
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

  Map<String, dynamic> campaign(
    String id,
    String name, {
    String status = 'ENABLED',
    String type = 'SEARCH',
    String bidding = 'MAXIMIZE_CONVERSIONS',
    String? kind = 'cpa',
    num? target = 120,
  }) => {
    'id': id,
    'name': name,
    'status': status,
    'primary_status': 'ELIGIBLE',
    'type': type,
    'bidding': bidding,
    'portfolio': false,
    'target_kind': kind,
    'target': target,
    'daily_budget': 50,
    'shared_budget': false,
    'spend': 900,
    'clicks': 300,
    'conversions': 9,
    'cost_per_conversion': 100,
  };

  Future<void> open(
    WidgetTester tester, {
    required List<String> permissions,
  }) async {
    tester.view.physicalSize = const Size(1500, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    requests = [];

    final api = ApiClient(
      client: MockClient((request) async {
        requests.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) return json(me(permissions));
        if (path.endsWith('/ads/accounts')) {
          return json({
            'data': [
              {
                'connection_id': 'mcc',
                'customer_id': '1',
                'name': 'Manager',
                'is_manager': true,
              },
              {
                'connection_id': 'g1',
                'customer_id': '4941850746',
                'name': 'A1 Chimney - Cleveland',
                'is_manager': false,
              },
            ],
          });
        }
        if (path.endsWith('/ads-manager/google/campaigns')) {
          return json({
            'data': {
              'currency': 'USD',
              'campaigns': [
                campaign('11', 'Cleveland Search'),
                campaign(
                  '12',
                  'Cleveland LSA',
                  type: 'LOCAL_SERVICES',
                  bidding: 'MANUAL_CPA',
                  kind: null,
                  target: null,
                  status: 'PAUSED',
                ),
              ],
            },
          });
        }
        if (path.contains('/ads-manager/google/')) {
          return json({'data': jsonDecode(request.body)});
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
  }

  List<Map> posted(String what) => [
    for (final r in requests)
      if (r.url.path.endsWith('/ads-manager/google/$what'))
        jsonDecode(r.body) as Map,
  ];

  const manage = ['ads_manager.view', 'ads_manager.manage'];

  testWidgets('a switch is sent only after Confirm', (tester) async {
    await open(tester, permissions: manage);
    final read = requests.firstWhere(
      (r) => r.url.path.endsWith('/ads-manager/google/campaigns'),
    );
    expect(
      read.url.queryParameters['connection_id'],
      'g1',
      reason: 'a manager account holds no campaigns',
    );
    expect(find.text('Local Services'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('google-on-12')));
    await tester.pumpAndSettle();
    expect(find.text('Turn on Cleveland LSA?'), findsOneWidget);
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(posted('status'), isEmpty);

    await tester.tap(find.byKey(const ValueKey('google-on-11')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ad-change-confirm')));
    await tester.pumpAndSettle();
    expect(posted('status'), [
      {'connection_id': 'g1', 'campaign_id': '11', 'status': 'PAUSED'},
    ]);
  });

  testWidgets('budget and bid target are saved from their panels', (
    tester,
  ) async {
    await open(tester, permissions: manage);

    await tester.tap(find.byKey(const ValueKey('google-budget-11')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('google-amount')), '65');
    await tester.tap(find.byKey(const ValueKey('google-amount-save')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('google-target-11')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('google-amount')), '95');
    await tester.tap(find.byKey(const ValueKey('google-amount-save')));
    await tester.pumpAndSettle();

    expect(posted('budget').single, {
      'connection_id': 'g1',
      'campaign_id': '11',
      'daily_budget': 65.0,
    });
    expect(posted('target').single, {
      'connection_id': 'g1',
      'campaign_id': '11',
      'target': 95.0,
    });
    expect(
      find.byKey(const ValueKey('google-target-12')),
      findsNothing,
      reason: 'Manual CPA has no target to set',
    );
  });

  // UI/UX audit 2026-11-13 §10.1: "$28,571.43/day" was Google's per-day
  // reading of a $200,000 weekly Local Services budget.
  test('a Local Services budget is its week, undoing Google\'s cents', () {
    expect(lsaWeekly(28571.43), 200000);
    expect(lsaWeekly(857.14), 6000);
    expect(lsaWeekly(29.93), 209.51);
  });

  testWidgets('a Local Services budget reads and saves per week', (tester) async {
    await open(tester, permissions: manage);

    final cell = find.byKey(const ValueKey('google-budget-12'));
    expect(find.descendant(of: cell, matching: find.textContaining('/ week')), findsOneWidget);
    await tester.tap(cell);
    await tester.pumpAndSettle();
    expect(find.text('Per week'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('google-amount')), '700');
    await tester.tap(find.byKey(const ValueKey('google-amount-save')));
    await tester.pumpAndSettle();

    expect(posted('budget').single['daily_budget'], 100.0, reason: 'Google takes a week as day x 7');
  });

  testWidgets('reading alone shows no switches and no edit links', (
    tester,
  ) async {
    await open(tester, permissions: const ['ads_manager.view']);
    expect(find.text('Cleveland Search'), findsOneWidget);
    expect(find.byType(Switch), findsNothing);
    expect(find.byKey(const ValueKey('google-budget-11')), findsNothing);
  });
}
