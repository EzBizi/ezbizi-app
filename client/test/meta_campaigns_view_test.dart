import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_performance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Meta campaigns on the Ads performance screen (design 180, Jose 2026-11-04).
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

  Map<String, dynamic> node(
    String level,
    String id,
    String name, {
    String status = 'ACTIVE',
    String? effective,
    num? daily,
    num spend = 0,
    int leads = 0,
    List<Map<String, dynamic>> children = const [],
  }) => {
    'level': level,
    'id': id,
    'name': name,
    'status': status,
    'effective_status': effective ?? status,
    'daily_budget': daily,
    'lifetime_budget': null,
    'spend': spend,
    'impressions': 0,
    'clicks': 10,
    'leads': leads,
    'cost_per_lead': leads > 0 ? spend / leads : null,
    'children': children,
  };

  List<Map<String, dynamic>> tree() => [
    node(
      'campaign',
      '1',
      'Meta Leads · Springfield',
      daily: 40,
      spend: 1180,
      leads: 61,
      children: [
        node(
          'adset',
          '11',
          'Homeowners 35–65',
          spend: 820,
          leads: 44,
          children: [
            node('ad', '111', 'Chimney sweep · video', spend: 610, leads: 35),
          ],
        ),
      ],
    ),
    node(
      'campaign',
      '2',
      'Engagement · Riverton',
      status: 'PAUSED',
      spend: 214,
      leads: 3,
      children: [
        node(
          'adset',
          '21',
          'Local reach',
          effective: 'CAMPAIGN_PAUSED',
          daily: 12,
          spend: 214,
          leads: 3,
        ),
      ],
    ),
  ];

  Future<void> open(
    WidgetTester tester, {
    required List<String> permissions,
    List<Map<String, dynamic>>? accounts,
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
            'data':
                accounts ??
                [
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
              'from': '2026-10-06',
              'to': '2026-11-04',
              'campaigns': tree(),
            },
          });
        }
        if (path.endsWith('/meta-ads/status')) {
          return json({'data': jsonDecode(request.body)});
        }
        if (path.endsWith('/meta-ads/budget')) {
          return json({
            'message': 'This one has a lifetime budget, not a daily one.',
          }, 422);
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
    await tester.tap(find.text('Meta'));
    await tester.pumpAndSettle();
  }

  const manage = ['ads_performance.view', 'ads_performance.manage'];

  testWidgets('a switch pauses at once, and Undo posts the reverse', (
    tester,
  ) async {
    await open(tester, permissions: manage);

    await tester.tap(find.byKey(const ValueKey('meta-on-1')));
    await tester.pumpAndSettle();
    expect(find.text('Meta Leads · Springfield paused'), findsOneWidget);

    await tester.tap(find.text('UNDO'));
    await tester.pumpAndSettle();

    final posted = requests
        .where((r) => r.url.path.endsWith('/meta-ads/status'))
        .map((r) => jsonDecode(r.body) as Map)
        .toList();
    expect(posted.map((b) => '${b['object_id']}:${b['status']}'), [
      '1:PAUSED',
      '1:ACTIVE',
    ]);
  });

  testWidgets('the budget is a link only on the row that owns it', (
    tester,
  ) async {
    await open(tester, permissions: manage);
    await tester.tap(find.byKey(const ValueKey('meta-expand-1')));
    await tester.tap(find.byKey(const ValueKey('meta-expand-2')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('meta-budget-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('meta-budget-11')), findsNothing);
    expect(find.text('Campaign budget'), findsOneWidget);
    expect(find.byKey(const ValueKey('meta-budget-2')), findsNothing);
    expect(find.text('Ad set budgets'), findsOneWidget);
    expect(find.byKey(const ValueKey('meta-budget-21')), findsOneWidget);
    expect(find.text('Paused by campaign'), findsOneWidget);

    // Meta's refusal shows in the panel, as it said it.
    await tester.tap(find.byKey(const ValueKey('meta-budget-21')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('meta-budget-save')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('meta-budget-error')), findsOneWidget);
    expect(
      find.text('This one has a lifetime budget, not a daily one.'),
      findsOneWidget,
    );
  });

  testWidgets('reading spend alone shows no switches and no budget links', (
    tester,
  ) async {
    await open(tester, permissions: const ['ads_performance.view']);

    expect(find.byType(Switch), findsNothing);
    expect(find.byKey(const ValueKey('meta-budget-1')), findsNothing);
    expect(find.text(r'$40.00'), findsOneWidget);
    expect(find.text('Paused'), findsOneWidget);
  });

  testWidgets('no ad account connected says so and points to Integrations', (
    tester,
  ) async {
    await open(tester, permissions: manage, accounts: const []);

    expect(find.byKey(const ValueKey('meta-ads-empty')), findsOneWidget);
    expect(find.text('No Meta ad account connected'), findsOneWidget);
    expect(
      requests.where((r) => r.url.path.endsWith('/meta-ads/tree')),
      isEmpty,
    );
  });

  testWidgets('a phone shows cards, children indented under a tap', (
    tester,
  ) async {
    await open(tester, permissions: manage, width: 390);

    expect(find.byKey(const ValueKey('meta-ads-cards')), findsOneWidget);
    expect(find.byKey(const ValueKey('meta-ads-table')), findsNothing);
    expect(find.byKey(const ValueKey('meta-card-11')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('meta-card-1')));
    await tester.pumpAndSettle();
    final parent = tester.getTopLeft(find.byKey(const ValueKey('meta-card-1')));
    final child = tester.getTopLeft(find.byKey(const ValueKey('meta-card-11')));
    expect(child.dx - parent.dx, 14);
    expect(tester.takeException(), isNull);
  });
}
