import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/memberships_dashboard.dart';
import 'package:ezbizi_client/screens/customers/memberships_members.dart';
import 'package:ezbizi_client/screens/customers/memberships_settings.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customers › Memberships, UI queue 52 (Jose 2026-10-05: "go with ur
/// recommended options as usual" — desktop 1, phone 4).
///
/// Every date is counted from today, so "renews in the next 30 days" means the
/// same thing on every day this runs.
void main() {
  group('Members', () {
    testWidgets("Jose's columns, in his order, with when the year renews", (
      tester,
    ) async {
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
      );

      const order = [
        'CUSTOMER',
        'ENROLLMENT',
        'PLAN',
        'AMOUNT DUE',
        'STATE',
        'ZIP',
        'EST. GROSS',
        'PAID SO FAR',
        'RENEWS',
      ];
      final lefts = [
        for (final h in order)
          tester
              .getTopLeft(
                find.descendant(
                  of: find.byType(ListTable),
                  matching: find.text(h),
                ),
              )
              .dx,
      ];
      expect(
        lefts,
        [...lefts]..sort(),
        reason: 'The columns are not in his order.',
      );
      expect(find.text('Riley Park'), findsOneWidget);
      expect(find.text(r'$54'), findsOneWidget);
    });

    testWidgets('without financials no amount is drawn, and the rest is', (
      tester,
    ) async {
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
        permissions: _noMoney,
        amounts: false,
      );

      // Control: the member and the money-free columns are there.
      expect(find.text('Riley Park'), findsOneWidget);
      expect(find.text('ZIP'), findsOneWidget);

      expect(find.text('AMOUNT DUE'), findsNothing);
      expect(find.text('PAID SO FAR'), findsNothing);
      expect(find.byKey(const Key('members-tile-due')), findsNothing);
      expect(
        find.text('—'),
        findsNothing,
        reason: 'Dashes in columns nobody can read.',
      );
    });

    testWidgets('a tile is a filter, and so is the search', (tester) async {
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
      );

      expect(find.byKey(const ValueKey('member:m-3')), findsOneWidget);

      await tester.tap(find.byKey(const Key('members-tile-lapsed')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('member:m-3')), findsOneWidget);
      expect(find.byKey(const ValueKey('member:m-1')), findsNothing);

      await tester.tap(find.byKey(const Key('members-tile-lapsed')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('members-search')), '34102');
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('member:m-2')), findsOneWidget);
      expect(find.byKey(const ValueKey('member:m-1')), findsNothing);
    });

    testWidgets('renewing soon is the next 30 days, not the rest of the year', (
      tester,
    ) async {
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
      );

      await tester.tap(find.byKey(const Key('members-tile-soon')));
      await tester.pumpAndSettle();

      // Riley's year ends in 10 days; Morgan's in 200; Quinn's ended.
      expect(find.byKey(const ValueKey('member:m-2')), findsOneWidget);
      expect(find.byKey(const ValueKey('member:m-1')), findsNothing);
      expect(find.byKey(const ValueKey('member:m-3')), findsNothing);
    });

    testWidgets('a member opens in place, a payment is typed in dollars, and '
        'back returns to the list', (tester) async {
      final server = _Server();
      final pushes = <Route<dynamic>>[];
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
        server: server,
        pushes: pushes,
      );
      final before = pushes.length;

      await tester.tap(find.byKey(const ValueKey('member:m-2')));
      await tester.pumpAndSettle();

      expect(find.byTooltip('Back to members'), findsOneWidget);
      expect(
        pushes.length,
        before,
        reason: 'Containment: a member is not a new screen.',
      );

      await tester.tap(find.byKey(const Key('member-paid-change')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('member-paid')), '1,162.50');
      await tester.tap(find.byKey(const Key('member-paid-save')));
      await tester.pumpAndSettle();

      expect(server.patches.single.$1, '/api/v1/memberships/m-2');
      expect(server.patches.single.$2, {'paid_minor': 116250});

      // Every earlier year, from the customer's own rows.
      expect(find.text('Every year'), findsOneWidget);

      await tester.tap(find.byTooltip('Back to members'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('member:m-1')), findsOneWidget);
    });

    testWidgets('cancelling asks once, in the row, and then cancels', (
      tester,
    ) async {
      final server = _Server();
      await _pump(
        tester,
        (api, session) => MembershipsMembers(api: api, session: session),
        server: server,
      );

      await tester.tap(find.byKey(const ValueKey('member:m-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('member-cancel')));
      await tester.pumpAndSettle();

      expect(server.patches, isEmpty, reason: 'One tap ended a membership.');

      await tester.tap(find.byKey(const Key('member-cancel-yes')));
      await tester.pumpAndSettle();

      expect(server.patches.single.$1, '/api/v1/memberships/m-1');
      expect(server.patches.single.$2, {'cancelled': true});
    });

    testWidgets(
      'a member is added from a side panel, and the list reads again',
      (tester) async {
        final server = _Server();
        await _pump(
          tester,
          (api, session) => MembershipsMembers(api: api, session: session),
          server: server,
        );
        final reads = server.reads;

        await tester.tap(find.byKey(const Key('members-add')));
        await tester.pumpAndSettle();

        await tester.enterText(
          find.byKey(const Key('member-add-customer')),
          'Ta',
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Taylor Lin').last);
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('member-add-save')));
        await tester.pumpAndSettle();

        expect(server.posts.single.$1, '/api/v1/memberships');
        expect(server.posts.single.$2['customer_id'], 'c-9');
        expect(server.posts.single.$2['membership_plan_id'], 'p-1');
        expect(
          find.byKey(const Key('member-add-save')),
          findsNothing,
          reason: 'The panel stayed open.',
        );
        expect(server.reads, greaterThan(reads));
      },
    );
  });

  testWidgets('on a phone a member is a row that names its values', (
    tester,
  ) async {
    await _pump(
      tester,
      (api, session) => MembershipsMembers(api: api, session: session),
      size: const Size(390, 1400),
    );

    expect(
      find.byType(ListTable),
      findsNothing,
      reason: 'Stacked, it is bare values.',
    );
    expect(find.text('Chimney care · renewal · FL 34102'), findsOneWidget);
    final riley = find.byKey(const ValueKey('member:m-2'));
    expect(
      find.descendant(of: riley, matching: find.text(r'$54 due')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: riley, matching: find.text(r'paid $162')),
      findsOneWidget,
    );
    expect(find.textContaining('renews '), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  group('Dashboard', () {
    testWidgets('the figures are counted from the members, money only with '
        'financials', (tester) async {
      await _pump(
        tester,
        (api, session) => MembershipsDashboard(api: api, session: session),
      );

      expect(find.text('ACTIVE'), findsOneWidget);
      expect(find.text('2'), findsOneWidget, reason: 'Two active members.');
      expect(find.text('1 renewal · 1 new'), findsOneWidget);
      expect(find.text('PAID THIS YEAR'), findsOneWidget);

      // Riley renews within 30 days and is listed by name; Morgan is not.
      expect(find.text('Riley Park'), findsOneWidget);
      expect(find.text('Morgan Ellis'), findsNothing);

      await _pump(
        tester,
        (api, session) => MembershipsDashboard(api: api, session: session),
        permissions: _noMoney,
        amounts: false,
      );
      expect(find.text('ACTIVE'), findsOneWidget);
      expect(find.text('PAID THIS YEAR'), findsNothing);
      expect(find.text('EST. GROSS'), findsNothing);
    });
  });

  group('Settings', () {
    testWidgets(
      'a plan is added and changed in place, prices typed in dollars',
      (tester) async {
        final server = _Server();
        await _pump(
          tester,
          (api, session) => MembershipsSettings(api: api, session: session),
          server: server,
        );

        expect(find.text('Yearly sweep'), findsOneWidget);

        await tester.tap(find.byKey(const Key('plans-add')));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byKey(const Key('plan-name')),
          'Gutter care',
        );
        await tester.enterText(find.byKey(const Key('plan-price')), '19.99');
        await tester.tap(find.byKey(const Key('plan-save')));
        await tester.pumpAndSettle();

        expect(server.posts.single.$1, '/api/v1/memberships/plans');
        expect(server.posts.single.$2, {
          'name': 'Gutter care',
          'price_minor': 1999,
          'billing': 'yearly',
          'is_active': true,
        });

        await tester.tap(find.byKey(const ValueKey('plan:p-1')));
        await tester.pumpAndSettle();
        await tester.enterText(find.byKey(const Key('plan-price')), '209');
        await tester.tap(find.byKey(const Key('plan-save')));
        await tester.pumpAndSettle();

        expect(server.patches.single.$1, '/api/v1/memberships/plans/p-1');
        expect(server.patches.single.$2, {
          'name': 'Yearly sweep',
          'price_minor': 20900,
          'billing': 'yearly',
        });
      },
    );

    testWidgets('without memberships.edit the plans are read only', (
      tester,
    ) async {
      await _pump(
        tester,
        (api, session) => MembershipsSettings(api: api, session: session),
        permissions: const [
          'memberships.view',
          'memberships.settings.view',
          'jobs.financials',
        ],
      );

      expect(find.text('Yearly sweep'), findsOneWidget);
      expect(find.byKey(const Key('plans-add')), findsNothing);
      expect(
        tester
            .widget<Switch>(find.byKey(const ValueKey('plan-active:p-1')))
            .onChanged,
        isNull,
      );
      expect(
        find.text('You can see the plans, but not change them.'),
        findsOneWidget,
      );
    });
  });
}

// ── the server ─────────────────────────────────────────────────────

const _all = [
  'memberships.view',
  'memberships.create',
  'memberships.edit',
  'memberships.dashboard.view',
  'memberships.members.view',
  'memberships.settings.view',
  'jobs.financials',
];
const _noMoney = [
  'memberships.view',
  'memberships.create',
  'memberships.edit',
  'memberships.members.view',
  'memberships.dashboard.view',
];

String _day(int fromToday) {
  final d = DateUtils.dateOnly(DateTime.now()).add(Duration(days: fromToday));
  return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}

class _Server {
  bool amounts = true;
  final patches = <(String, Map<String, dynamic>)>[];
  final posts = <(String, Map<String, dynamic>)>[];
  int reads = 0;

  Map<String, dynamic> _row(
    String id,
    String customer,
    String customerId, {
    required String status,
    required int endsIn,
    String enrollment = 'renewal',
    String plan = 'p-1',
    String zip = '33901',
    int due = 0,
    int paid = 19900,
  }) => {
    'id': id,
    'customer_id': customerId,
    'customer_name': customer,
    'state': 'FL',
    'zip': zip,
    'enrollment': enrollment,
    'plan_id': plan,
    'plan_name': plan == 'p-1' ? 'Yearly sweep' : 'Chimney care',
    'billing': plan == 'p-1' ? 'yearly' : 'monthly',
    'starts_on': _day(endsIn - 364),
    'ends_on': _day(endsIn),
    'status': status,
    'reminded_at': null,
    'currency': 'USD',
    'price_minor': amounts ? 19900 : null,
    'paid_minor': amounts ? paid : null,
    'due_minor': amounts ? due : null,
    'estimated_gross_minor': amounts ? 48900 : null,
  };

  List<Map<String, dynamic>> get rows => [
    _row(
      'm-1',
      'Morgan Ellis',
      'c-1',
      status: 'active',
      endsIn: 200,
      enrollment: 'new',
    ),
    _row(
      'm-2',
      'Riley Park',
      'c-2',
      status: 'active',
      endsIn: 10,
      plan: 'p-2',
      zip: '34102',
      due: 5400,
      paid: 16200,
    ),
    _row(
      'm-3',
      'Quinn Avery',
      'c-3',
      status: 'expired',
      endsIn: -5,
      zip: '30303',
    ),
  ];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});
    if (request.method == 'PATCH') {
      patches.add((path, jsonDecode(request.body) as Map<String, dynamic>));
      return _json({'data': rows[1]});
    }
    if (request.method == 'POST') {
      posts.add((path, jsonDecode(request.body) as Map<String, dynamic>));
      return _json({'data': rows.first}, 201);
    }
    if (path.endsWith('/memberships/plans')) {
      return _json({
        'data': [
          {
            'id': 'p-1',
            'name': 'Yearly sweep',
            'price_minor': 19900,
            'currency': 'USD',
            'billing': 'yearly',
            'is_active': true,
          },
          {
            'id': 'p-2',
            'name': 'Chimney care',
            'price_minor': 1800,
            'currency': 'USD',
            'billing': 'monthly',
            'is_active': true,
          },
        ],
      });
    }
    if (path.endsWith('/memberships')) {
      reads++;
      final customer = request.url.queryParameters['customer_id'];
      if (customer == 'c-2') {
        return _json({
          'data': [
            rows[1],
            {
              ..._row(
                'm-0',
                'Riley Park',
                'c-2',
                status: 'expired',
                endsIn: -355,
                enrollment: 'new',
              ),
            },
          ],
        });
      }
      return _json({'data': rows});
    }
    if (path.endsWith('/customers')) {
      return _json({
        'data': [
          {'id': 'c-9', 'name': 'Taylor Lin'},
        ],
      });
    }

    return _json({'data': const []});
  }

  Map<String, Object?> _me = const {};
}

Future<void> _pump(
  WidgetTester tester,
  Widget Function(ApiClient, Session) screen, {
  _Server? server,
  List<String> permissions = _all,
  bool amounts = true,
  List<Route<dynamic>>? pushes,
  Size size = const Size(1400, 1600),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final serving = server ?? _Server();
  serving.amounts = amounts;
  serving._me = {
    'id': 'u-1',
    'name': 'Dana Reyes',
    'email': 'dana@example.com',
    'has_avatar': false,
    'role': {'id': 'role-1', 'name': 'Administrator'},
    'permissions': permissions,
    'modules': <Map<String, Object?>>[],
    'preferences': <String, dynamic>{},
    'organization': {
      'id': 'org-1',
      'name': 'Harbor Group',
      'franchise_label': 'Franchise',
      'is_read_only': false,
    },
    'scope': {
      'type': 'organization',
      'id': 'org-1',
      'label': 'Harbor Group',
      'path': ['Harbor Group'],
    },
    'companies': <Map<String, Object?>>[],
    'franchises': <Map<String, Object?>>[],
  };

  final api = ApiClient(client: MockClient(serving.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      navigatorObservers: [if (pushes != null) _Pushes(pushes)],
      home: Scaffold(
        body: KeyedSubtree(key: UniqueKey(), child: screen(api, session)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

class _Pushes extends NavigatorObserver {
  _Pushes(this.routes);

  final List<Route<dynamic>> routes;

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      routes.add(route);
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
