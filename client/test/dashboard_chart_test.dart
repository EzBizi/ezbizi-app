import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Charts sit on the glance when the server sent slices or series; empty and
/// coming-soon stay a sentence. The switcher is a header control, not a
/// dashboard-wide graph mode, and coming soon has none.
void main() {
  testWidgets('a pie is drawn for slices and not for coming soon', (tester) async {
    await _pumpDashboard(tester, _glances(presentation: 'pie', slices: [
      {'label': 'scheduled', 'total': 2},
      {'label': 'completed', 'total': 1},
    ]));
    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('coming soon has no chart and no presentation control', (tester) async {
    await _pumpDashboard(tester, _comingSoon());
    expect(find.byType(DashboardChart), findsNothing);
    expect(find.byTooltip('Choose panels'), findsNothing);
    expect(find.text('Coming soon'), findsOneWidget);
  });

  testWidgets('empty is a sentence, not a chart', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        empty: true,
        slices: [
          {'label': 'scheduled', 'total': 0},
        ],
        sentence: 'No jobs on the board.',
      ),
    );

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('No jobs on the board.'), findsOneWidget);
  });

  testWidgets('text presentation draws sentences only', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'text',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
      ),
    );

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('2 jobs on the board.'), findsOneWidget);
  });

  testWidgets('the chart sits above the sentences', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
        sentence: '3 jobs on the board.',
      ),
    );

    expect(
      tester.getTopLeft(find.byType(DashboardChart)).dy,
      lessThan(tester.getTopLeft(find.text('3 jobs on the board.')).dy),
    );
  });

  testWidgets('a bar is drawn for slices', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'bar',
        allowed: const ['bar', 'pie', 'text'],
        slices: [
          {'label': 'Onboardings in flight', 'total': 2},
          {'label': 'Invitations stalled', 'total': 1},
        ],
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('a year series of Y-m buckets still draws', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'line',
        allowed: const ['line', 'bar', 'text'],
        series: [
          {
            'label': 'Added',
            'points': [
              {'at': '2026-08', 'value': 2},
              {'at': '2026-07', 'value': 1},
            ],
          },
        ],
        sentence: '3 new customers this year.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('pie and bar name their slices', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
      ),
    );

    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('scheduled'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('completed'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('choosing an earned status writes it on the jobs glance', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
      ),
      record: sent,
    );

    await tester.tap(find.widgetWithText(FilterChip, 'completed'));
    await tester.pumpAndSettle();

    expect(sent, isNotEmpty);
    final jobs = (sent.last['glances'] as List).single;
    expect(jobs['key'], 'jobs');
    expect(jobs['options']['earned_statuses'], ['completed']);
  });

  testWidgets('a line is drawn for series', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'line',
        allowed: const ['line', 'bar', 'text'],
        series: [
          {
            'label': 'Added',
            'points': [
              {'at': '2026-08-01', 'value': 0},
              {'at': '2026-08-08', 'value': 2},
            ],
          },
        ],
        sentence: '2 new customers this month.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('a line names every currency series', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DashboardChart(
            presentation: 'line',
            series: [
              {
                'label': 'CAD',
                'points': [
                  {'at': '2026-08-01', 'value': 5000},
                  {'at': '2026-08-08', 'value': 7000},
                ],
              },
              {
                'label': 'USD',
                'points': [
                  {'at': '2026-08-01', 'value': 10000},
                  {'at': '2026-08-08', 'value': 20000},
                ],
              },
            ],
          ),
        ),
      ),
    );

    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('CAD'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('USD'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a timeline is drawn for series', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'timeline',
        allowed: const ['timeline', 'text'],
        series: [
          {
            'label': 'Posted',
            'points': [
              {'at': '2026-08-01T10:00:00-04:00', 'value': 1},
              {'at': '2026-08-12T14:00:00-04:00', 'value': 1},
            ],
          },
        ],
        sentence: '2 posts this month.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('coming soon has no how-to-draw menu', (tester) async {
    await _pumpDashboard(tester, _comingSoon());

    expect(find.byType(PopupMenuButton<String>), findsNothing);
    expect(find.byTooltip('How to draw “Inventory”'), findsNothing);
  });

  testWidgets('an empty allowed list hides the presentation menu', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'text',
        allowed: const [],
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    expect(find.byType(PopupMenuButton<String>), findsNothing);
  });

  testWidgets('text is offered even when the server omitted it', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        allowed: const ['pie', 'bar'],
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    await tester.tap(find.byTooltip('How to draw “Jobs”'));
    await tester.pumpAndSettle();

    expect(find.text('Pie'), findsOneWidget);
    expect(find.text('Bar'), findsOneWidget);
    expect(find.text('Text'), findsOneWidget);
  });

  testWidgets('choosing a presentation writes it with the current glances', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
      record: sent,
    );

    await tester.tap(find.byTooltip('How to draw “Jobs”'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bar'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single.containsKey('period'), isFalse);
    expect((sent.single['glances'] as List).single['key'], 'jobs');
    expect((sent.single['glances'] as List).single['presentation'], 'bar');
  });

  testWidgets('the presentation menu sits in the card header', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    final titleY = tester.getCenter(find.text('Jobs')).dy;
    final menuY = tester.getCenter(find.byTooltip('How to draw “Jobs”')).dy;

    expect((titleY - menuY).abs(), lessThan(24));
  });
}

Future<void> _pumpDashboard(
  WidgetTester tester,
  List<Map<String, dynamic>> glances, {
  List<Map<String, dynamic>>? record,
}) async {
  tester.view.physicalSize = const Size(1400, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient((request) async {
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
  }));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: DashboardScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

List<Map<String, dynamic>> _glances({
  required String presentation,
  List<Map<String, dynamic>>? slices,
  List<Map<String, dynamic>>? series,
  bool empty = false,
  List<String> allowed = const ['pie', 'bar', 'text'],
  String sentence = '2 jobs on the board.',
}) {
  return [
    {
      'key': 'jobs',
      'label': 'Jobs',
      'presentation': presentation,
      'allowed_presentations': allowed,
      'snapshot': true,
      'coming_soon': false,
      'options': <String, dynamic>{},
      'data': {
        'empty': empty,
        'slices': ?slices,
        'series': ?series,
        if (empty) 'sentence': sentence else 'sentences': [sentence],
      },
    },
  ];
}

List<Map<String, dynamic>> _comingSoon() => [
  {
    'key': 'inventory',
    'label': 'Inventory',
    'presentation': 'text',
    'allowed_presentations': <String>[],
    'snapshot': true,
    'coming_soon': true,
    'options': <String, dynamic>{},
    'data': {
      'empty': true,
      'sentence':
          'Inventory is where the company will keep what it has on the shelf '
          '— parts, materials, and the stock that jobs draw from. The '
          'register is not here yet.',
    },
  },
];

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
  'permissions': ['jobs.view'],
  'modules': [
    {'key': 'jobs', 'label': 'Jobs', 'available': true, 'reachable': true},
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
