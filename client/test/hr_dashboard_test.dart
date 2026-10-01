import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/dashboard_tab.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/dashboard_chart.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
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
    expect(find.text('Nobody in this view.'), findsOneWidget);
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

  // ── UI queue 42 (Jose's 1 and 4): figures, charts, right now, lists ──

  testWidgets('the overview replaces the one card: six figures and the lists '
      'with names', (tester) async {
    await _pump(tester, _handler(overview: _overview));

    expect(find.byType(DashboardCard), findsNothing);
    for (final key in ['books', 'joined', 'left', 'onboarding', 'stalled', 'certifications']) {
      expect(find.byKey(Key('hr-figure-$key')), findsOneWidget);
    }
    expect(
      find.descendant(
        of: find.byKey(const Key('hr-figure-books')),
        matching: find.text('79 active · 3 inactive'),
      ),
      findsOneWidget,
    );
    // Waiting on someone: the invitation sent first comes first.
    final waiting = find.byKey(const Key('hr-list-waiting'));
    final sam = tester.getTopLeft(find.descendant(of: waiting, matching: find.text('Sam Keller')));
    final marco = tester.getTopLeft(find.descendant(of: waiting, matching: find.text('Marco Reyes')));
    expect(sam.dy, lessThan(marco.dy));
    expect(find.descendant(of: waiting, matching: find.text('Expired')), findsOneWidget);
    expect(
      find.descendant(of: find.byKey(const Key('hr-right-now')), matching: find.text('31')),
      findsOneWidget,
    );
  });

  testWidgets('a figure opens its whole list beside the page', (tester) async {
    await _pump(tester, _handler(overview: _overview));

    await tester.tap(find.byKey(const Key('hr-figure-onboarding')));
    await tester.pumpAndSettle();

    final panel = find.byType(SidePanel);
    expect(panel, findsOneWidget);
    expect(find.descendant(of: panel, matching: find.text('Marco Reyes')), findsOneWidget);
    expect(find.descendant(of: panel, matching: find.text('Sam Keller')), findsNothing);
  });

  testWidgets('on a phone a figure opens its list in place, with a way back', (tester) async {
    await _pump(tester, _handler(overview: _overview), size: const Size(390, 1600));

    await tester.tap(find.byKey(const Key('hr-figure-stalled')));
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsNothing);
    expect(find.text('Sam Keller'), findsOneWidget);
    expect(find.byKey(const Key('hr-figure-books')), findsNothing);

    await tester.tap(find.byKey(const Key('hr-list-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('hr-figure-books')), findsOneWidget);
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
  Size size = const Size(1400, 2200),
}) async {
  tester.view.physicalSize = size;
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
  Map<String, dynamic>? overview,
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

    if (path.endsWith('/hr/dashboard/overview')) {
      return overview == null
          ? _json({'message': 'Not found.'}, 404)
          : _json({'data': overview});
    }

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
                  'sentence': 'Nobody in this view.',
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

/// `GET /hr/dashboard/overview`, as the contract sent to HR describes it.
/// Invented people.
final Map<String, dynamic> _overview = {
  'period': 'year',
  'from': '2026-01-01',
  'to': '2026-10-01',
  'on_the_books': {'total': 82, 'active': 79, 'inactive': 3},
  'joined': {
    'count': 2,
    'people': [
      {'user_id': 'u-7', 'name': 'Ana Ruiz', 'role': 'Technician', 'place': 'Harbor Chimney', 'started_on': '2026-09-15'},
      {'user_id': 'u-8', 'name': 'Owen Price', 'role': 'Dispatcher', 'place': 'Harbor Garage Doors', 'started_on': '2026-08-25'},
    ],
  },
  'left': {'count': 1, 'since': '2026-08-18', 'people': [
    {'user_id': 'u-9', 'name': 'Lena Fox', 'role': 'Office', 'place': 'Harbor Group', 'left_on': '2026-09-02'},
  ]},
  'onboarding': {
    'count': 1,
    'filling_in': 1,
    'to_sign': 0,
    'items': [
      {'id': 'o-1', 'user_id': 'u-3', 'name': 'Marco Reyes', 'contract': 'Office agreement', 'state': 'filling_in', 'since': '2026-09-25'},
    ],
  },
  'invitations_stalled': {
    'count': 1,
    'expired': 1,
    'not_opened': 0,
    'items': [
      {'id': 'i-1', 'name': 'Sam Keller', 'state': 'expired', 'sent_at': '2026-09-18'},
    ],
  },
  'certifications': {'count': 0, 'lapsed': 0, 'expiring': 0, 'items': <Map<String, dynamic>>[]},
  'by_month': [
    for (var m = 1; m <= 10; m++)
      {'month': '2026-${m.toString().padLeft(2, '0')}', 'joined': m % 3, 'left': m == 9 ? 1 : 0},
  ],
  'places': [
    {'name': 'Harbor Chimney', 'count': 36},
    {'name': null, 'count': 2},
  ],
  'overlap': 1,
  'right_now': {
    'on_the_clock': {'count': 31, 'of_active': 79},
    'away_today': {'count': 3, 'vacation': 2, 'sick': 1},
    'due_for_retaking': null,
  },
};
