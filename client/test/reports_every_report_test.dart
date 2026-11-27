import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Queue 33, Jose's 1 and 4 (2026-09-30). Jose, looking at the "Weekly work
/// hours report" rule: "shouldn't i be able to see that existing report on
/// reports?". The library lists every saved report, the Builder's and Work
/// Hours', with who each covers and what sends it; a Work Hours one opens
/// beside the list with its week, and is still edited with Work Hours' own
/// editor.
void main() {
  group('the library', () {
    testWidgets("lists Work Hours' reports beside the Builder's", (
      tester,
    ) async {
      await _pump(tester);

      expect(find.text('Open jobs by franchise'), findsOneWidget);
      expect(find.text('Weekly Time Report'), findsOneWidget);
      expect(find.text('Built here'), findsWidgets);
      expect(find.text('Work Hours'), findsWidgets);
      expect(
        find.text('Administrator, Dispatcher, + Mia Torres, − Ben Carter'),
        findsOneWidget,
      );
      expect(find.text('Jobs you can reach'), findsOneWidget);
      // Rule names for a reader of rules; the schedule in words.
      expect(find.text('Sundays 8:00 PM (New York)'), findsOneWidget);
      expect(find.text('by “Weekly work hours report”'), findsOneWidget);
      expect(find.text('Not sent by any automation'), findsOneWidget);

      for (final (key, count) in [
        ('all', '2'),
        ('built', '1'),
        ('hours', '1'),
      ]) {
        expect(
          find.descendant(
            of: find.byKey(ValueKey('library-kind-$key')),
            matching: find.textContaining(count),
          ),
          findsOneWidget,
          reason: '$key counts',
        );
      }

      await tester.tap(find.byKey(const ValueKey('library-kind-hours')));
      await tester.pumpAndSettle();
      expect(find.text('Open jobs by franchise'), findsNothing);
      expect(find.text('Weekly Time Report'), findsOneWidget);
    });

    testWidgets('without the Work Hours grant that list is never asked for', (
      tester,
    ) async {
      await _pump(tester, permissions: _reportsOnly);

      expect(_server.asked, isNot(contains('/api/v1/hr/time-clock/reports')));
      expect(find.text('Open jobs by franchise'), findsOneWidget);
      expect(find.text('Weekly Time Report'), findsNothing);
      expect(find.byKey(const ValueKey('library-kind-all')), findsNothing);
    });

    testWidgets('"No reports built yet" counts both kinds', (tester) async {
      await _pump(tester, built: const []);
      expect(find.text('No reports built yet.'), findsNothing);
      expect(find.text('Weekly Time Report'), findsOneWidget);

      // The control: neither kind has one.
      await _pump(tester, built: const [], hours: const []);
      expect(find.text('No reports built yet.'), findsOneWidget);
    });

    testWidgets('a refusal of one list leaves the other drawn', (tester) async {
      await _pump(tester, refuse: '/reports');
      expect(find.text('Weekly Time Report'), findsOneWidget);
      expect(find.text('No, not /reports.'), findsOneWidget);

      await _pump(tester, refuse: '/hr/time-clock/reports');
      expect(find.text('Open jobs by franchise'), findsOneWidget);
      expect(find.text('No, not /hr/time-clock/reports.'), findsOneWidget);
    });

    testWidgets('without automations.view it says only whether one sends it', (
      tester,
    ) async {
      await _pump(
        tester,
        hours: [
          {..._hoursReport, 'sent_by': null, 'sent_automatically': true},
        ],
      );

      expect(find.text('Sent automatically'), findsOneWidget);
      expect(find.textContaining('Weekly work hours report'), findsNothing);
    });

    testWidgets('a rule that is off says so', (tester) async {
      await _pump(
        tester,
        hours: [
          {
            ..._hoursReport,
            'sent_automatically': false,
            'sent_by': [
              {..._rule, 'is_active': false},
            ],
          },
        ],
      );

      expect(find.text('Off · Sundays 8:00 PM (New York)'), findsOneWidget);
    });

    testWidgets('a rule that is on is named before one that is off', (
      tester,
    ) async {
      await _pump(
        tester,
        hours: [
          {
            ..._hoursReport,
            'sent_by': [
              {..._rule, 'name': 'Old weekly', 'is_active': false},
              {
                ..._rule,
                'id': 'a-2',
                'name': 'Monday digest',
                'schedule': {
                  'kind': 'weekly',
                  'days': ['monday'],
                  'time': '07:00',
                  'timezone': 'America/New_York',
                },
              },
            ],
          },
        ],
      );

      expect(find.text('Mondays 7:00 AM (New York)'), findsOneWidget);
      expect(find.text('by “Monday digest”'), findsOneWidget);
    });
  });

  group('a Work Hours report', () {
    testWidgets('opens beside the list with its week, people and rule', (
      tester,
    ) async {
      await _pump(tester);
      await tester.tap(find.text('Weekly Time Report'));
      await tester.pumpAndSettle();

      expect(
        find.text('Work Hours · edited in Work Hours › Reports'),
        findsOneWidget,
      );
      expect(
        find.text('Open jobs by franchise'),
        findsOneWidget,
        reason: 'list stays',
      );
      expect(find.widgetWithText(Chip, 'Administrator'), findsOneWidget);
      expect(find.widgetWithText(Chip, 'Mia Torres'), findsOneWidget);
      expect(find.text('payroll@example.com, Jose'), findsOneWidget);
      expect(find.text('Ana Ruiz'), findsOneWidget);
      expect(find.text('41.5 h'), findsOneWidget);
      expect(find.text('Total (2 people)'), findsOneWidget);
      // The rule, when it next goes: its date in the organization's format.
      expect(
        find.text('Sundays 8:00 PM (New York) · next 10/4/2026'),
        findsOneWidget,
      );
      expect(_server.asked.last, contains('/preview'));
      expect(_server.lastQuery['week'], 'last');
    });

    testWidgets(
      'Edit who is in it is Work Hours\' editor, and the list re-reads',
      (tester) async {
        await _pump(tester);
        await tester.tap(find.text('Weekly Time Report'));
        await tester.pumpAndSettle();

        final before = _server.asked
            .where((p) => p.endsWith('/hr/time-clock/reports'))
            .length;
        await tester.tap(find.byKey(const ValueKey('hours-report-edit')));
        await tester.pumpAndSettle();
        expect(
          find.text('Edit report'),
          findsOneWidget,
          reason: "Work Hours' own",
        );

        await tester.tap(find.widgetWithText(FilterChip, 'Dispatcher'));
        await tester.pump();
        await tester.tap(find.text('Save'));
        await tester.pumpAndSettle();

        expect(_server.patched?['role_ids'], ['r-1']);
        expect(
          _server.asked
              .where((p) => p.endsWith('/hr/time-clock/reports'))
              .length,
          before + 1,
        );
      },
    );

    testWidgets('Send now sends the week on screen', (tester) async {
      await _pump(tester);
      await tester.tap(find.text('Weekly Time Report'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('hours-report-send')));
      await tester.pumpAndSettle();

      expect(_server.asked.last, '/api/v1/hr/time-clock/reports/w-1/send');
      expect(_server.lastQuery['week'], 'last');
      expect(find.text('Sent to 2 addresses.'), findsOneWidget);
    });

    testWidgets('a reader who cannot manage Work Hours only reads it', (
      tester,
    ) async {
      await _pump(
        tester,
        permissions: [..._permissions]..remove('work_hours.manage'),
      );
      await tester.tap(find.text('Weekly Time Report'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('hours-report-edit')), findsNothing);
      expect(find.byKey(const ValueKey('hours-report-send')), findsNothing);
      expect(find.text('Ana Ruiz'), findsOneWidget, reason: 'the control');
    });

    testWidgets('on a phone it opens in place, with a way back', (
      tester,
    ) async {
      await _pump(tester, width: 390);
      await tester.tap(find.text('Weekly Time Report'));
      await tester.pumpAndSettle();

      expect(
        find.text('Open jobs by franchise'),
        findsNothing,
        reason: 'in place',
      );
      expect(find.byType(AppBar), findsNothing, reason: 'containment');
      expect(find.text('Ana Ruiz'), findsOneWidget);

      await tester.tap(find.byTooltip('Back'));
      await tester.pumpAndSettle();
      expect(find.text('Open jobs by franchise'), findsOneWidget);
    });
  });
}

const _permissions = [
  'reports.view',
  'reports.build',
  'reports.library.view',
  'reports.builder.view',
  'work_hours.reports.view',
  'work_hours.manage',
  'automations.view',
];

const _reportsOnly = [
  'reports.view',
  'reports.build',
  'reports.library.view',
  'reports.builder.view',
];

const _rule = {
  'id': 'a-1',
  'name': 'Weekly work hours report',
  'schedule': {
    'kind': 'weekly',
    'days': ['sunday'],
    'time': '20:00',
    'timezone': 'America/New_York',
  },
  'next_run_at': '2026-10-04T12:00:00+00:00',
  'is_active': true,
  'disabled_at': null,
};

const _hoursReport = {
  'id': 'w-1',
  'name': 'Weekly Time Report',
  'role_ids': ['r-1', 'r-2'],
  'include_user_ids': ['u-7'],
  'exclude_user_ids': ['u-8'],
  'recipients': ['payroll@example.com', 'user:u-1'],
  'sent_automatically': true,
  'sent_by': [_rule],
};

const _builtReport = {
  'id': 'b-1',
  'name': 'Open jobs by franchise',
  'description': 'Jobs not done, per franchise',
  'definition': {'root': 'jobs'},
  'sent_automatically': false,
  'sent_by': <dynamic>[],
};

class _Server {
  final asked = <String>[];
  Map<String, String> lastQuery = const {};
  Map<String, dynamic>? patched;
}

late _Server _server;

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = _permissions,
  List<Map<String, dynamic>> built = const [_builtReport],
  List<Map<String, dynamic>> hours = const [_hoursReport],
  String? refuse,
  double width = 1280,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final server = _server = _Server();
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': permissions,
            'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
          },
        });
      }
      server.asked.add(path);
      server.lastQuery = request.url.queryParameters;

      if (refuse != null && path == '/api/v1$refuse') {
        return _json({'message': 'No, not $refuse.'}, 403);
      }
      // Every report sent (2026-11-25): none here.
      if (path.endsWith('/reports/sent')) return _json({'data': []});
      if (path.endsWith('/reports') && !path.contains('time-clock')) {
        return _json({'data': built});
      }
      if (path.endsWith('/hr/time-clock/reports')) {
        return _json({
          'data': hours,
          'roles': [
            {'id': 'r-1', 'name': 'Administrator'},
            {'id': 'r-2', 'name': 'Dispatcher'},
          ],
          'people': [
            {'id': 'u-1', 'name': 'Jose', 'email': 'jose@example.com'},
            {'id': 'u-7', 'name': 'Mia Torres', 'email': 'mia@example.com'},
            {'id': 'u-8', 'name': 'Ben Carter', 'email': 'ben@example.com'},
          ],
        });
      }
      if (path.endsWith('/preview')) {
        return _json({
          'data': {
            'range': 'Sep 21 – Sep 27',
            'rows': [
              {'name': 'Ana Ruiz', 'hours': '41.5 h'},
              {'name': 'Mia Torres', 'hours': '22.25 h'},
            ],
            'total': '63.75 h',
            'recipients': ['payroll@example.com', 'jose@example.com'],
          },
        });
      }
      if (path.endsWith('/send')) {
        return _json({
          'data': {
            'to': ['payroll@example.com', 'jose@example.com'],
          },
        });
      }
      if (request.method == 'PATCH') {
        server.patched = jsonDecode(request.body) as Map<String, dynamic>;
        return _json({'data': server.patched});
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ReportsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
