import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/dashboard_tab.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/dashboard_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Human Resources dashboard chip (spec §12).
///
/// One box — the same HrGlance the main dashboard draws — not five widget
/// panels. The empty organization is still the case under test: most people
/// open this tab with one person on the books, and the sentences have to say
/// that rather than five titled zeros.
void main() {
  testWidgets('one card titled Human Resources, not five widget titles', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.byType(DashboardCard), findsOneWidget);
    expect(find.text('Human Resources'), findsOneWidget);

    expect(find.text('Headcount by place'), findsNothing);
    expect(find.text('Invitations that have stalled'), findsNothing);
    expect(find.text('Starting and leaving'), findsNothing);
    expect(find.text('Expiring certifications'), findsNothing);
  });

  testWidgets('the one-person organization is named in sentences', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('1 person on the books.'), findsOneWidget);
    expect(find.text('0 onboardings in flight.'), findsOneWidget);
    expect(find.text('0 invitations have stalled.'), findsOneWidget);
    expect(find.text('0 people joined this month.'), findsOneWidget);
    expect(find.text('0 people left this month.'), findsOneWidget);
    expect(find.textContaining('marked as an expiry'), findsOneWidget);
  });

  testWidgets('period chips sit in the header and write the dashboard period', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pump(tester, _handler(record: sent));

    expect(find.widgetWithText(ChoiceChip, 'Week'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Month'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Quarter'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Year'), findsOneWidget);
    expect(
      tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Month')).selected,
      isTrue,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Week'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single['period'], 'week');
    expect(
      [for (final g in sent.single['glances'] as List) g['key']],
      ['hr', 'jobs', 'customers'],
    );
    expect(
      (sent.single['glances'] as List).firstWhere((g) => g['key'] == 'hr')
          ['presentation'],
      'bar',
    );
  });

  /// /me often has no `dashboard.glances` yet. The first save GETs the
  /// dashboard and writes that list, so it does not name only `{hr}` and
  /// drop Jobs options the server would then append at defaults.
  testWidgets('a second layout save keeps the glances the first one stored', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pump(tester, _handler(record: sent));

    await tester.tap(find.widgetWithText(ChoiceChip, 'Week'));
    await tester.pumpAndSettle();

    expect(
      [for (final g in sent.single['glances'] as List) g['key']],
      ['hr', 'jobs', 'customers'],
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Quarter'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(2));
    expect(
      [for (final g in sent[1]['glances'] as List) g['key']],
      ['hr', 'jobs', 'customers'],
    );
    expect(sent[1]['period'], 'quarter');
  });

  testWidgets('the presentation switcher writes the same preference as the '
      'main dashboard', (tester) async {
    final sent = <Map<String, dynamic>>[];

    await _pump(tester, _handler(record: sent));

    await tester.tap(find.byTooltip('How to draw “Human Resources”'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Text'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single.containsKey('period'), isFalse);
    expect(
      [for (final g in sent.single['glances'] as List) g['key']],
      ['hr', 'jobs', 'customers'],
    );
    expect(
      (sent.single['glances'] as List).firstWhere((g) => g['key'] == 'hr')
          ['presentation'],
      'text',
    );
  });

  testWidgets('one card cannot be dragged', (tester) async {
    await _pump(tester, _handler());

    expect(find.byTooltip('Move “Human Resources” later'), findsNothing);
    expect(find.byTooltip('Move “Human Resources” earlier'), findsNothing);
    expect(
      find.byTooltip(
        'Drag “Human Resources” onto the card whose place it should take',
      ),
      findsNothing,
    );
  });

  testWidgets('tapping the card does not push another route', (tester) async {
    final nav = _PushRecorder();

    await _pump(tester, _handler(), observers: [nav]);

    expect(nav.pushes, 1, reason: 'Home is the first push.');
    expect(tester.state<NavigatorState>(find.byType(Navigator)).canPop(), isFalse);

    await tester.tap(find.text('Human Resources'));
    await tester.pumpAndSettle();

    expect(nav.pushes, 1, reason: 'The card called Navigator.push.');
    expect(tester.state<NavigatorState>(find.byType(Navigator)).canPop(), isFalse);
    expect(find.byType(DashboardTab), findsOneWidget);
  });

  testWidgets('empty is a sentence, not a chart', (tester) async {
    await _pump(tester, _handler(empty: true));

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('Nobody on the books yet.'), findsOneWidget);
  });

  /// Configured but nothing in it: real categories, a zero in every one. The
  /// deleted `_chartable` called that drawable and the painter did not, so a
  /// chart went into the tree and refused to paint. `canDraw` is the painter's
  /// own rule, so now nothing is built — and every sentence still renders,
  /// which is the half that carries the meaning for an organization of one.
  testWidgets('a bar with a zero in every slice draws nothing and keeps the '
      'sentences', (tester) async {
    await _pump(tester, _handler());

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('1 person on the books.'), findsOneWidget);
    expect(find.text('0 onboardings in flight.'), findsOneWidget);
    expect(find.text('0 invitations have stalled.'), findsOneWidget);
  });

  testWidgets('a bar is drawn when there is something to plot', (tester) async {
    await _pump(
      tester,
      _handler(
        inFlight: 2,
        stalled: 1,
        sentences: const [
          '3 people on the books.',
          '2 onboardings in flight.',
          '1 invitation has stalled.',
        ],
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
    expect(find.text('2 onboardings in flight.'), findsOneWidget);
  });

  testWidgets('text presentation draws sentences only', (tester) async {
    await _pump(
      tester,
      _handler(
        presentation: 'text',
        inFlight: 2,
        stalled: 1,
      ),
    );

    // Not built at all: the screen asks `DashboardChart.canDraw`, which is the
    // widget's own rule rather than a copy, so there is no chart in the tree
    // and `findsNothing` is the true claim rather than the convenient one.
    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('1 person on the books.'), findsOneWidget);
  });

  testWidgets('a failure is the server’s message and a way back, not a blank '
      'page', (tester) async {
    await _pump(tester, _handler(status: 500));

    expect(find.text('Human Resources is unavailable.'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
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
  tester.view.physicalSize = const Size(1400, 2200);
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
      home: Scaffold(body: DashboardTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  int status = 200,
  bool empty = false,
  String presentation = 'bar',
  int inFlight = 0,
  int stalled = 0,
  List<String>? sentences,
  List<Map<String, dynamic>>? record,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      record?.add(body);

      // Same append the server does: omitted reachable modules join at the
      // end. The second save must send this list back, not `{hr}` again.
      final sent = [
        for (final item in (body['glances'] as List? ?? const []))
          if (item is Map) Map<String, dynamic>.from(item),
      ];
      final keys = {for (final glance in sent) glance['key']};
      return _json({
        'period': body['period'] ?? 'month',
        'glances': [
          ...sent,
          if (!keys.contains('jobs'))
            {'key': 'jobs', 'presentation': 'pie'},
          if (!keys.contains('customers'))
            {'key': 'customers', 'presentation': 'line'},
        ],
      });
    }

    if (request.method == 'GET' &&
        path.endsWith('/dashboard') &&
        !path.endsWith('/hr/dashboard')) {
      return _json({
        'period': 'month',
        'glances': [
          {
            'key': 'hr',
            'presentation': presentation,
            'options': <String, dynamic>{},
          },
          {'key': 'jobs', 'presentation': 'pie', 'options': <String, dynamic>{}},
          {
            'key': 'customers',
            'presentation': 'line',
            'options': <String, dynamic>{},
          },
        ],
      });
    }

    if (path.endsWith('/hr/dashboard')) {
      if (status != 200) {
        return _json({'message': 'Human Resources is unavailable.'}, status);
      }

      return _json({
        'period': 'month',
        'period_starts_on': '2026-08-01T00:00:00-04:00',
        'period_ends_on': '2026-08-24T16:00:00-04:00',
        'glance': {
          'key': 'hr',
          'label': 'Human Resources',
          'presentation': presentation,
          'allowed_presentations': ['bar', 'pie', 'text'],
          'snapshot': true,
          'coming_soon': false,
          'options': <String, dynamic>{},
          'data': empty
              ? {
                  'empty': true,
                  'sentence': 'Nobody on the books yet.',
                }
              : {
                  'empty': false,
                  'slices': [
                    {'label': 'Active', 'total': inFlight},
                    {'label': 'Inactive', 'total': stalled},
                  ],
                  'sentences': sentences ??
                      [
                        '1 person on the books.',
                        '0 onboardings in flight.',
                        '0 invitations have stalled.',
                        '0 people joined this month.',
                        '0 people left this month.',
                        'None of the contract questions are marked as an expiry, so there is nothing to list yet. Mark a date field as an expiry on a contract template.',
                      ],
                },
        },
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
  'permissions': ['hr.view'],
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
