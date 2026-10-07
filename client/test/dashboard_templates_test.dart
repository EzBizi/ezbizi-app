import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Dashboard templates (UI queue 53; Jose, 2026-10-07: "so users can click on
/// them and it changes the layout of their dashboard that being order and size
/// of widgets and modes").
///
/// What these hold to: a template is saved exactly as the server built it, it
/// is applied at once and undone in one tap, and the standard layout is an
/// empty save.
void main() {
  testWidgets('Customize offers the templates, each saying what it is for', (
    tester,
  ) async {
    await _pump(tester, _Server());
    await _openTemplates(tester);

    final office = find.byKey(const ValueKey('dashboard-template-office'));
    expect(
      find.descendant(of: office, matching: find.text('Office and dispatch')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: office,
        matching: find.text(
          'Today\'s jobs, the schedule, calls and new leads.',
        ),
      ),
      findsOneWidget,
    );
    // The miniature draws the boxes the template shows, not the hidden ones.
    expect(
      find.descendant(
        of: office,
        matching: find.byKey(const ValueKey('miniature-jobs')),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: office,
        matching: find.byKey(const ValueKey('miniature-hr')),
      ),
      findsNothing,
    );
  });

  testWidgets('a template is saved as sent, says what it hid, and Undo puts '
      'the old layout back', (tester) async {
    final server = _Server();
    await _pump(tester, server);
    await _openTemplates(tester);

    await tester.tap(
      find.byKey(const ValueKey('dashboard-template-use-office')),
    );
    await tester.pumpAndSettle();

    expect(server.saved.single, {'glances': _office});
    expect(
      find.descendant(
        of: find.byKey(const Key('dashboard-template-applied')),
        matching: find.text(
          'Using Office and dispatch. 1 box is hidden; Add a box brings any '
          'of them back.',
        ),
      ),
      findsOneWidget,
    );
    expect(server.loads, 2, reason: 'read again, to show it as saved');

    await tester.tap(find.byKey(const Key('dashboard-template-undo')));
    await tester.pumpAndSettle();

    // Every box as it was: order, size, mode, options and hidden.
    expect(server.saved.last, {
      'glances': [
        for (final glance in _layout)
          {
            'key': glance['key'],
            'presentation': glance['presentation'],
            'options': glance['options'],
            'size': glance['size'],
            'hidden': glance['hidden'],
          },
      ],
    });
    expect(find.byKey(const Key('dashboard-template-applied')), findsNothing);
  });

  // Dashboard, reviewing: an entry that leaves out size, hidden or
  // presentation KEEPS the stored value, so an Undo that said less would leave
  // the template's values in place.
  testWidgets('Undo brings back a box the template hid, at its old size and '
      'mode', (tester) async {
    final server = _Server();
    await _pump(tester, server);
    await _openTemplates(tester);

    // People hides Jobs, which is showing, Medium, as a pie.
    await tester.tap(
      find.byKey(const ValueKey('dashboard-template-use-people')),
    );
    await tester.pumpAndSettle();
    expect(server.box('jobs')['hidden'], isTrue);
    expect(find.text('Jobs'), findsNothing);

    await tester.tap(find.byKey(const Key('dashboard-template-undo')));
    await tester.pumpAndSettle();

    expect(server.box('jobs'), containsPair('hidden', false));
    expect(server.box('jobs'), containsPair('size', 'medium'));
    expect(server.box('jobs'), containsPair('presentation', 'pie'));
    expect(find.text('Jobs'), findsOneWidget);
  });

  testWidgets('the standard layout is an empty save, with Undo', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);
    await _openTemplates(tester);

    await tester.tap(find.byKey(const Key('dashboard-template-standard')));
    await tester.pumpAndSettle();

    expect(server.saved.single, {'glances': <dynamic>[]});
    expect(
      find.descendant(
        of: find.byKey(const Key('dashboard-template-applied')),
        matching: find.text('Back to the standard layout.'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('dashboard-template-undo')), findsOneWidget);
  });

  testWidgets('a refused save says why and claims nothing', (tester) async {
    await _pump(tester, _Server(refuse: true));
    await _openTemplates(tester);

    await tester.tap(
      find.byKey(const ValueKey('dashboard-template-use-office')),
    );
    await tester.pumpAndSettle();

    expect(find.text('That layout could not be saved.'), findsOneWidget);
    expect(find.byKey(const Key('dashboard-template-applied')), findsNothing);
  });
}

Future<void> _openTemplates(WidgetTester tester) async {
  await tester.tap(find.text('Customize'));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const Key('dashboard-templates')));
  await tester.pumpAndSettle();
}

/// The layout before any template: three boxes, the last hidden.
final _layout = <Map<String, dynamic>>[
  {
    'key': 'jobs',
    'label': 'Jobs',
    'presentation': 'pie',
    'allowed_presentations': ['pie', 'bar', 'text'],
    'options': <String, dynamic>{},
    'size': 'medium',
    'hidden': false,
    'snapshot': true,
    'coming_soon': false,
    'data': {'empty': true, 'sentence': 'No jobs today.'},
  },
  {
    'key': 'scheduling',
    'label': 'Scheduling',
    'presentation': 'text',
    'allowed_presentations': ['bar', 'text'],
    'options': <String, dynamic>{},
    'size': 'small',
    'hidden': false,
    'snapshot': true,
    'coming_soon': false,
    'data': {'empty': true, 'sentence': 'Nothing booked.'},
  },
  {
    'key': 'hr',
    'label': 'Human Resources',
    'presentation': 'text',
    'allowed_presentations': ['bar', 'text'],
    'options': <String, dynamic>{},
    'size': 'small',
    'hidden': true,
    'snapshot': true,
    'coming_soon': false,
    'data': {'empty': true, 'sentence': 'Nobody yet.'},
  },
];

/// As the server builds it: named boxes sized, a mode only where the box
/// offers one and is big enough to draw it, then the rest hidden.
const _office = [
  {'key': 'jobs', 'size': 'large', 'presentation': 'bar', 'hidden': false},
  {'key': 'scheduling', 'size': 'small', 'hidden': false},
  {'key': 'hr', 'hidden': true},
];

class _Server {
  _Server({this.refuse = false});

  final bool refuse;
  final List<Map<String, dynamic>> saved = [];
  int loads = 0;

  /// The stored layout, kept the way the server keeps it: an entry changes
  /// only what it names, and an empty save is the standard layout again.
  List<Map<String, dynamic>> layout = _fresh();

  static List<Map<String, dynamic>> _fresh() => [
    for (final glance in _layout) Map<String, dynamic>.from(glance),
  ];

  Map<String, dynamic> box(String key) =>
      layout.firstWhere((glance) => glance['key'] == key);

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      if (refuse) {
        return _json({'message': 'That layout could not be saved.'}, 422);
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      saved.add(body);
      final entries = [
        for (final entry in body['glances'] as List)
          Map<String, dynamic>.from(entry as Map),
      ];
      if (entries.isEmpty) {
        layout = _fresh();
      } else {
        layout = [
          for (final entry in entries)
            {
              ...box(entry['key'] as String),
              for (final field in const [
                'size',
                'presentation',
                'hidden',
                'options',
              ])
                if (entry.containsKey(field)) field: entry[field],
            },
        ];
      }
      return _json({'ok': true});
    }

    if (path.endsWith('/dashboard/catalogue')) {
      return _json({
        'templates': [
          {
            'key': 'office',
            'label': 'Office and dispatch',
            'description': 'Today\'s jobs, the schedule, calls and new leads.',
            'glances': _office,
          },
          {
            'key': 'people',
            'label': 'People',
            'description':
                'Who is on the books, on the clock, due for training.',
            'glances': [
              {
                'key': 'hr',
                'size': 'large',
                'presentation': 'bar',
                'hidden': false,
              },
              {
                'key': 'scheduling',
                'size': 'medium',
                'presentation': 'bar',
                'hidden': false,
              },
              {'key': 'jobs', 'hidden': true},
            ],
          },
        ],
        'glances': <dynamic>[],
      });
    }

    if (path.endsWith('/dashboard')) {
      loads++;
      return _json({
        'period': 'day',
        'period_starts_on': '2026-10-07T00:00:00-04:00',
        'period_ends_on': '2026-10-07T16:00:00-04:00',
        'glances': layout,
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  }
}

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(1400, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(server.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: DashboardScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['jobs.view', 'hr.view'],
  'modules': <dynamic>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Branch',
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
