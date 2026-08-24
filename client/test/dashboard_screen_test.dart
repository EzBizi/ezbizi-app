import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/tag.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The main dashboard: one box per reachable module, one period, no picker.
void main() {
  testWidgets('HR sentences from the glance payload appear', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('1 person on the books.'), findsOneWidget);
    expect(find.text('0 onboardings in flight.'), findsOneWidget);
    expect(find.text('0 invitations have stalled.'), findsOneWidget);
    expect(find.text('0 people joined this month.'), findsOneWidget);

    // The old widget-row window is gone; this screen draws sentences.
    expect(find.text('Joined in the last 30 days'), findsNothing);
  });

  testWidgets('period chips sit in the header and Choose panels does not', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.widgetWithText(ChoiceChip, 'Week'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Month'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Quarter'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Year'), findsOneWidget);

    expect(
      tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Month')).selected,
      isTrue,
    );
    expect(
      tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Week')).selected,
      isFalse,
    );

    expect(find.text('Choose panels'), findsNothing);
    expect(find.byTooltip('Choose panels'), findsNothing);
    expect(find.byIcon(Icons.tune), findsNothing);
  });

  testWidgets('an empty glance is the sentence, coming soon is tagged', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('No new customers this month.'), findsOneWidget);
    expect(find.text('Coming soon'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(DashboardCard),
        matching: find.byType(Tag),
      ),
      findsOneWidget,
    );
    expect(
      find.textContaining('The register is not here yet'),
      findsOneWidget,
    );
  });

  testWidgets('tapping a card does not push another route', (tester) async {
    final nav = _PushRecorder();

    await _pump(tester, _handler(), observers: [nav]);

    expect(nav.pushes, 1, reason: 'Home is the first push.');
    expect(tester.state<NavigatorState>(find.byType(Navigator)).canPop(), isFalse);

    await tester.tap(find.text('Human Resources'));
    await tester.pumpAndSettle();

    expect(nav.pushes, 1, reason: 'The card called Navigator.push.');
    expect(tester.state<NavigatorState>(find.byType(Navigator)).canPop(), isFalse);
    expect(find.byType(DashboardScreen), findsOneWidget);
  });

  testWidgets('choosing a period writes it with the current glances', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pump(tester, _handler(record: sent));

    await tester.tap(find.widgetWithText(ChoiceChip, 'Week'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single['period'], 'week');
    expect(
      [for (final g in sent.single['glances'] as List) g['key']],
      ['hr', 'customers', 'inventory'],
    );
    expect(
      (sent.single['glances'] as List)[0]['presentation'],
      'bar',
    );
  });

  /// AsyncBody: a retry after failure has nothing to keep. Treating a failed
  /// first fetch as loaded cleared the error, skipped the spinner, and flashed
  /// empty period chips until the next answer landed.
  testWidgets('Try again after a failed first fetch is a spinner, not an '
      'empty dashboard', (tester) async {
    var dashboardGets = 0;
    final retry = Completer<void>();

    await _pump(tester, (request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/dashboard')) {
        dashboardGets++;
        if (dashboardGets == 1) {
          return _json({'message': 'Nothing doing.'}, 500);
        }
        await retry.future;
        return _json({
          'period': 'month',
          'period_starts_on': '2026-08-01T00:00:00-04:00',
          'period_ends_on': '2026-08-24T16:00:00-04:00',
          'glances': [
            {
              'key': 'hr',
              'label': 'Human Resources',
              'presentation': 'bar',
              'allowed_presentations': ['bar', 'pie', 'text'],
              'snapshot': true,
              'coming_soon': false,
              'options': <String, dynamic>{},
              'data': {
                'empty': false,
                'sentences': ['1 person on the books.'],
              },
            },
          ],
        });
      }

      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    });

    expect(find.text('Nothing doing.'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Month'), findsNothing);

    await tester.tap(find.text('Try again'));
    await tester.pump();

    expect(
      find.byType(CircularProgressIndicator),
      findsOneWidget,
      reason: 'A retry after a failed first fetch has nothing to keep.',
    );
    expect(
      find.widgetWithText(ChoiceChip, 'Month'),
      findsNothing,
      reason: 'Empty chips flashed because the failed fetch was treated as loaded.',
    );

    retry.complete();
    await tester.pumpAndSettle();

    expect(find.text('1 person on the books.'), findsOneWidget);
  });

  testWidgets('stock_value of 500 USD is drawn as USD 5.00', (tester) async {
    await _pump(tester, _handler(stockValue: [
      {'currency': 'USD', 'amount_minor': 500},
    ]));

    expect(find.text('USD 5.00'), findsOneWidget);
    expect(find.text('No new customers this month.'), findsOneWidget);
  });

  testWidgets('a JSON number for amount_minor still draws', (tester) async {
    await _pump(tester, _handler(stockValue: [
      {'currency': 'USD', 'amount_minor': 500.0},
    ]));

    expect(find.text('USD 5.00'), findsOneWidget);
  });
}

class _PushRecorder extends NavigatorObserver {
  int pushes = 0;

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    pushes++;
  }
}

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  List<NavigatorObserver> observers = const [],
}) async {
  tester.view.physicalSize = const Size(1400, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      navigatorObservers: observers,
      home: Scaffold(body: DashboardScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<Map<String, dynamic>>? record,
  List<Map<String, dynamic>>? stockValue,
}) {
  final glances = [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'presentation': 'bar',
      'allowed_presentations': ['bar', 'pie', 'text'],
      'snapshot': true,
      'coming_soon': false,
      'options': <String, dynamic>{},
      'data': {
        'empty': false,
        'slices': [
          {'label': 'Active', 'total': 0},
          {'label': 'Inactive', 'total': 0},
        ],
        'sentences': [
          '1 person on the books.',
          '0 onboardings in flight.',
          '0 invitations have stalled.',
          '0 people joined this month.',
          '0 people left this month.',
        ],
      },
    },
    {
      'key': 'customers',
      'label': 'Customers',
      'presentation': 'line',
      'allowed_presentations': ['line', 'bar', 'text'],
      'snapshot': false,
      'coming_soon': false,
      'options': <String, dynamic>{},
      'data': {
        'empty': true,
        'sentence': 'No new customers this month.',
      },
    },
    {
      'key': 'inventory',
      'label': 'Inventory',
      'presentation': 'text',
      'allowed_presentations': <String>[],
      'snapshot': true,
      'coming_soon': stockValue == null,
      'options': <String, dynamic>{},
      'data': stockValue == null
          ? {
              'empty': true,
              'sentence':
                  'Inventory is where the company will keep what it has on the '
                  'shelf — parts, materials, and the stock that jobs draw '
                  'from. The register is not here yet.',
            }
          : {
              'empty': false,
              'sentences': ['1 item in reach.'],
              'stock_value': stockValue,
            },
    },
  ];

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      record?.add(jsonDecode(request.body) as Map<String, dynamic>);

      return _json({'ok': true});
    }

    if (path.endsWith('/dashboard')) {
      return _json({
        'period': 'month',
        'period_starts_on': '2026-08-01T00:00:00-04:00',
        'period_ends_on': '2026-08-24T16:00:00-04:00',
        'glances': glances,
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view', 'jobs.view'],
  'modules': [
    {'key': 'hr', 'label': 'Human Resources', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
