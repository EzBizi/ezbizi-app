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
    bool unfold = true,
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
              {
                'connection_id': 'g2',
                'customer_id': '8839813550',
                'name': 'A1 Chimney - Hamden',
                'is_manager': false,
              },
            ],
          });
        }
        if (path.endsWith('/ads-manager/google/campaigns') &&
            request.url.queryParameters['all'] == '1') {
          return json({
            'data': {
              'campaigns': [
                {
                  ...campaign('11', 'Cleveland Search'),
                  'connection_id': 'g1',
                  'account': 'A1 Chimney - Cleveland',
                },
                {
                  ...campaign('21', 'Hamden Search'),
                  'connection_id': 'g2',
                  'account': 'A1 Chimney - Hamden',
                },
              ],
              'refused': <Object>[],
            },
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
                  'LocalServicesCampaign:SystemGenerated:0006204bfbf2',
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
    // Paused rows fold under Inactive (Jose, 2026-11-16); these tests act on them.
    final fold = find.byKey(const ValueKey('google-inactive'));
    if (unfold && fold.evaluate().isNotEmpty) {
      await tester.tap(fold);
      await tester.pumpAndSettle();
    }
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
    expect(find.textContaining('Local Services'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('google-on-12')));
    await tester.pumpAndSettle();
    expect(find.text('Turn on Local Services · A1 Chimney - Cleveland?'), findsOneWidget);
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
    // Before and after, reviewed before anything is sent (Rodrigo, 01a1ef57).
    expect(find.textContaining(r'→ $65.00 / day'), findsOneWidget);
    expect(posted('budget'), isEmpty);
    await tester.tap(find.byKey(const ValueKey('ad-change-confirm')));
    await tester.pumpAndSettle();
    // Then what Google itself reads back.
    expect(find.byKey(const ValueKey('google-change-readback')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('google-target-11')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('google-amount')), '95');
    await tester.tap(find.byKey(const ValueKey('google-amount-save')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ad-change-confirm')));
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

  // UI/UX audit 2026-11-13 §9.11: no raw system id as a campaign's name.
  testWidgets("a Local Services campaign is named for its account, Google's name behind it", (tester) async {
    await open(tester, permissions: manage);

    final name = find.byKey(const ValueKey('google-name-12'));
    expect(tester.widget<Text>(name).data, 'Local Services · A1 Chimney - Cleveland');
    expect(find.textContaining('SystemGenerated'), findsNothing);
    expect(
      find.ancestor(of: name, matching: find.byWidgetPredicate((w) => w is Tooltip && w.message!.contains('SystemGenerated'))),
      findsOneWidget,
    );
    expect(tester.widget<Text>(find.byKey(const ValueKey('google-name-11'))).data, 'Cleveland Search');
  });

  testWidgets('the budget column names no period its cells contradict (D9)', (tester) async {
    await open(tester, permissions: manage);

    expect(find.textContaining('BUDGET / DAY'), findsNothing);
    expect(
      find.descendant(of: find.byKey(const ValueKey('google-budget-11')), matching: find.textContaining('/ day')),
      findsOneWidget,
    );
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
    expect(find.textContaining(r'→ $700.00 / week'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('ad-change-confirm')));
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

  // Jose, 2026-11-16: "paused ones are hidden by default".
  testWidgets('paused campaigns fold under Inactive with a count; search opens the fold', (tester) async {
    await open(tester, permissions: manage, unfold: false);

    expect(find.byKey(const ValueKey('google-name-11')), findsOneWidget);
    expect(find.byKey(const ValueKey('google-name-12')), findsNothing);
    expect(find.text('Inactive (1)'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('google-ads-search')), 'local');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('google-name-12')), findsOneWidget);
  });

  testWidgets('the range is Reports\' own picker, sent as from and to', (tester) async {
    await open(tester, permissions: manage);
    final read = requests.firstWhere((r) => r.url.path.endsWith('/ads-manager/google/campaigns'));
    final from = DateTime.parse(read.url.queryParameters['from']!);
    final to = DateTime.parse(read.url.queryParameters['to']!);
    expect(to.difference(from).inDays, 29, reason: 'Last 30 days, both ends included');
    expect(find.byKey(const ValueKey('google-ads-range')), findsOneWidget);
  });

  testWidgets('All accounts lists every account with its name, and a change goes to the row\'s account', (tester) async {
    await open(tester, permissions: manage);

    // Live figures say when they were read (Rodrigo, 01a1ef57).
    expect(find.byKey(const ValueKey('google-ads-read-at')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('google-ads-account')));
    await tester.pumpAndSettle();
    // Each entry says it is a campaign account, its id as Google writes it;
    // the manager is not offered (Rodrigo, 01a1ef57).
    expect(find.text('Campaign account · 494-185-0746'), findsWidgets);
    expect(find.text('Manager'), findsNothing);
    await tester.tap(find.text('All accounts').last);
    await tester.pumpAndSettle();

    final read = requests.lastWhere((r) => r.url.path.endsWith('/ads-manager/google/campaigns'));
    expect(read.url.queryParameters['all'], '1');
    expect(read.url.queryParameters.containsKey('connection_id'), isFalse);
    expect(find.text('ACCOUNT'), findsOneWidget);
    expect(find.text('A1 Chimney - Hamden'), findsOneWidget);
    expect(find.byKey(const ValueKey('google-kpi-Spend')), findsOneWidget);
    expect(tester.widget<Text>(find.byKey(const ValueKey('google-kpi-Spend'))).data, r'$1,800.00');

    await tester.tap(find.byKey(const ValueKey('google-on-21')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ad-change-confirm')));
    await tester.pumpAndSettle();
    expect(posted('status').single, {'connection_id': 'g2', 'campaign_id': '21', 'status': 'PAUSED'});
  });
}
