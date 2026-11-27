import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/job_status.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:ezbizi_client/screens/jobs/job_days.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Jobs and Customers lists Jose chose (queue 24, option 7), 2026-09-24:
/// *"i like the table view, but it would be nice to also click and have it
/// change to sidebar and detailed view, and also have the time divisions"* —
/// and, a minute later, *"the canceled tab should be red, not gray"*.
///
/// jobs_screen_test and customers_screen_test are left as they were and still
/// pass: they are what this rewrite could have dropped without noticing. These
/// are the new behaviour.
void main() {
  group('day headings', () {
    final today = DateTime(2026, 9, 24); // a Thursday

    test('name today, tomorrow and yesterday, then the weekday', () {
      final groups = groupJobsByDay([
        _job('a', at: '2026-09-23T10:00:00-05:00'),
        _job('b', at: '2026-09-24T08:00:00-05:00'),
        _job('c', at: '2026-09-25T09:00:00-05:00'),
        _job('d', at: '2026-09-26T09:00:00-05:00'),
        _job('e', at: '2027-04-01T11:00:00-05:00'),
        _job('f'),
      ], today: today);

      expect(groups.map((g) => g.heading), [
        'Yesterday',
        'Today',
        'Tomorrow',
        'Saturday',
        'Thursday',
        'Not scheduled',
      ]);
      expect(groups[1].detail, 'Thursday 24 September');
      expect(groups[3].detail, '26 September');
      // Another year says so; this one does not need to.
      expect(groups[4].detail, '1 April 2027');
    });

    /// The WALL date: 11pm in the franchise is that day's job, whatever the
    /// offset says and wherever the reader is.
    test('group by the date the franchise booked, not the reader\'s', () {
      final groups = groupJobsByDay([
        _job('late', at: '2026-09-24T23:30:00-05:00'),
      ], today: today);

      expect(groups.single.heading, 'Today');
    });

    test('keep the server\'s order and only count what was counted', () {
      final groups = groupJobsByDay(
        [
          _job('b', at: '2026-09-24T08:00:00-05:00'),
          _job('a', at: '2026-09-24T09:00:00-05:00'),
        ],
        today: today,
        counts: const {'2026-09-24': 38},
      );

      expect(groups.single.rows.map((j) => j['id']), ['b', 'a']);
      expect(groups.single.count, '38 jobs');

      final uncounted = groupJobsByDay([
        _job('b', at: '2026-09-24T08:00:00-05:00'),
      ], today: today);
      // The two rows on this page are not the day's total.
      expect(uncounted.single.count, isNull);
    });
  });

  group('status colours', () {
    test('Canceled is red, in either spelling', () {
      expect(toneForJobStatus('Canceled'), Tone.bad);
      expect(toneForJobStatus('cancelled'), Tone.bad);
    });

    test('each status in real use has its own meaning', () {
      expect(toneForJobStatus('Done'), Tone.ok);
      expect(toneForJobStatus('completed'), Tone.ok);
      // Contains "done" and "pending", and is neither.
      expect(toneForJobStatus('done pending approval'), Tone.review);
      expect(toneForJobStatus('In progress'), Tone.info);
      expect(toneForJobStatus('in_progress'), Tone.info);
      expect(toneForJobStatus('Pending'), Tone.caution);
      expect(toneForJobStatus('Submitted'), Tone.neutral);
      // A word the mapping does not know is neutral, never a guess.
      expect(toneForJobStatus('Awaiting parts delivery'), Tone.neutral);
      expect(toneForJobStatus('Rebooked'), Tone.neutral);
    });
  });

  group('Jobs, wide', () {
    testWidgets('a table divided by day, with the columns lined up', (
      tester,
    ) async {
      await _pumpJobs(tester, jobs: _week());

      expect(find.text('Today'), findsOneWidget);
      expect(find.text('Tomorrow'), findsOneWidget);
      expect(find.text('Money'), findsOneWidget);
      expect(find.text('38 jobs'), findsOneWidget);
      // No lines, no price: a dash, not "USD 0.00".
      expect(find.text('USD 0.00'), findsNothing);
      expect(find.text('\$289.00'), findsOneWidget);
    });

    testWidgets('the canceled pill is red', (tester) async {
      await _pumpJobs(tester, jobs: _week());

      final pill = tester.widget<StatusPill>(
        find.widgetWithText(StatusPill, 'Canceled'),
      );
      expect(pill.tone, Tone.bad);
    });

    testWidgets('it opens on today, and says whose today it is', (
      tester,
    ) async {
      final asked = <Map<String, String>>[];
      await _pumpJobs(tester, jobs: _week(), onJobs: asked.add);

      // Jose, 2026-11-23: All by default, so a past client's job is found.
      expect(asked.first['when'], 'all');
      expect(asked.first['today'], formatCalendarDay(DateTime.now()));
    });

    testWidgets('clicking a row turns the table into the list beside it', (
      tester,
    ) async {
      await _pumpJobs(tester, jobs: _week());

      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();

      // The record, docked — not a panel over the page.
      expect(find.text('Scheduled for'), findsOneWidget);
      expect(tester.widget<SidePanel>(find.byType(SidePanel)).docked, isTrue);
      // The table's header is gone; its rows are still there, as the list.
      expect(find.text('Money'), findsNothing);
      expect(find.text('Crown Repair'), findsOneWidget);
      expect(find.byType(AlertDialog), findsNothing);

      await tester.tap(find.byTooltip('Close Chimney Sweep'));
      await tester.pumpAndSettle();

      expect(find.text('Money'), findsOneWidget);
      expect(find.byType(SidePanel), findsNothing);
    });

    testWidgets('another row cannot throw away an unsaved edit', (
      tester,
    ) async {
      await _pumpJobs(tester, jobs: _week());

      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Description'),
        'Gate code 4471',
      );
      await tester.pump();

      await tester.tap(find.text('Crown Repair'));
      await tester.pumpAndSettle();

      expect(
        find.text('Save or cancel the changes to this job first.'),
        findsOneWidget,
      );
      // Still the first job, with the edit still in it.
      expect(find.text('Gate code 4471'), findsOneWidget);
      expect(find.byTooltip('Close Chimney Sweep'), findsOneWidget);
    });

    testWidgets('an untouched record switches freely', (tester) async {
      await _pumpJobs(tester, jobs: _week());

      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Crown Repair'));
      await tester.pumpAndSettle();

      expect(find.byTooltip('Close Crown Repair'), findsOneWidget);
    });

    testWidgets('the chips count each status and filter by it', (tester) async {
      final asked = <Map<String, String>>[];
      await _pumpJobs(
        tester,
        jobs: _week(),
        onJobs: asked.add,
        statusCounts: const {'Submitted': 431, 'Canceled': 24},
      );

      final chip = find.byKey(const ValueKey('status-chip-Submitted'));
      expect(
        find.descendant(of: chip, matching: find.textContaining('431')),
        findsOneWidget,
      );
      // The All chip adds them up.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('status-chip-all')),
          matching: find.textContaining('455'),
        ),
        findsOneWidget,
      );

      await tester.tap(chip);
      await tester.pumpAndSettle();

      expect(asked.last['status'], 'Submitted');
    });

    testWidgets('Past asks for the past', (tester) async {
      final asked = <Map<String, String>>[];
      await _pumpJobs(tester, jobs: _week(), onJobs: asked.add);

      await tester.tap(
        find.descendant(
          of: find.byKey(const Key('jobs-window')),
          matching: find.textContaining('Past'),
        ),
      );
      await tester.pumpAndSettle();

      expect(asked.last['when'], 'past');
    });
  });

  group('Jobs, on a phone', () {
    testWidgets('two-line rows under the day, and a tap opens the job over '
        'the list', (tester) async {
      await _pumpJobs(tester, jobs: _week(), size: const Size(375, 800));

      expect(tester.takeException(), isNull);
      expect(find.text('Today'), findsOneWidget);
      expect(find.text('Money'), findsNothing);

      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();

      expect(tester.widget<SidePanel>(find.byType(SidePanel)).docked, isFalse);
      expect(tester.takeException(), isNull);
    });
  });

  group('Jobs, on a phone, scrolled', () {
    // The note, buttons, search and filters used to stand over the list and
    // take nearly half of a phone; only the rows below them scrolled.
    testWidgets('the header scrolls away with the list', (tester) async {
      await _pumpJobs(
        tester,
        jobs: [
          for (var i = 0; i < 30; i++)
            _job('j-$i', at: _wall(i ~/ 5, '08:00'), title: 'Sweep $i'),
        ],
        size: const Size(411, 800),
      );

      final search = find.byType(TextField);
      expect(search, findsWidgets);

      // Far enough for the money chips and totals over it too (queue 31).
      await tester.drag(find.text('Sweep 3'), const Offset(0, -560));
      await tester.pumpAndSettle();

      // Off screen: a finder skips what a viewport has scrolled out of view.
      expect(search, findsNothing);
      expect(find.text('Sweep 8'), findsOneWidget);
    });
  });

  group('Customers, wide', () {
    testWidgets('a table divided by letter, with the city', (tester) async {
      await _pumpCustomers(tester);

      expect(find.text('A'), findsOneWidget);
      expect(find.text('B'), findsOneWidget);
      expect(find.text('Dallas, TX'), findsOneWidget);
      expect(find.text('City'), findsOneWidget);
    });

    testWidgets('the chips count and filter the groups', (tester) async {
      final asked = <Map<String, String>>[];
      await _pumpCustomers(tester, onCustomers: asked.add);

      final repeat = find.byKey(const ValueKey('segment-chip-repeat'));
      expect(
        find.descendant(of: repeat, matching: find.textContaining('885')),
        findsOneWidget,
      );

      await tester.tap(repeat);
      await tester.pumpAndSettle();

      expect(asked.last['segment'], 'repeat');
    });

    testWidgets('clicking a customer docks their record, with their jobs', (
      tester,
    ) async {
      await _pumpCustomers(tester, withJobs: true);

      await tester.tap(find.text('Abby Lindqvist'));
      await tester.pumpAndSettle();

      expect(tester.widget<SidePanel>(find.byType(SidePanel)).docked, isTrue);
      expect(find.text('Street'), findsOneWidget);
      expect(find.text('Chimney Inspection'), findsOneWidget);
      // Three jobs on the customer, two of them readable here.
      expect(
        find.text('1 more, in a franchise you cannot open.'),
        findsOneWidget,
      );

      // State's helper line used to push ZIP lower than the box beside it.
      // The boxes are AddressFields' now (Jose, 2026-09-30), labelled ST.
      Finder box(String label) => find.ancestor(
        of: find.text(label),
        matching: find.byType(TextField),
      );
      expect(
        tester.getTopLeft(box('ZIP')).dy,
        tester.getTopLeft(box('ST')).dy,
      );
    });
  });

  group('Customers, on a phone', () {
    testWidgets('rows under their letter, and nothing overflows', (
      tester,
    ) async {
      await _pumpCustomers(tester, size: const Size(375, 800));

      expect(tester.takeException(), isNull);
      expect(find.text('A'), findsOneWidget);
      expect(find.text('City'), findsNothing);
    });
  });
}

// ── fixtures ──────────────────────────────────────────────────────

/// A job at a wall time, or undated.
Map<String, dynamic> _job(
  String id, {
  String? at,
  String title = 'Job',
  String status = 'Submitted',
  int minor = 0,
}) => {
  'id': id,
  'number': 'JOB-$id',
  'title': title,
  'status': status,
  'status_label': status,
  'scheduled_for': at,
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Maria Delgado',
    'number': 'C-1',
    'owned_by_this_franchise': true,
  },
  'amount': {
    'minor': minor,
    'currency': 'USD',
    'display': minor == 0 ? null : (minor / 100).toStringAsFixed(2),
  },
};

String _wall(int dayOffset, String clock) {
  final d = DateTime.now().add(Duration(days: dayOffset));
  return '${formatCalendarDay(d)}T$clock:00-05:00';
}

List<Map<String, dynamic>> _week() => [
  _job(
    'j-1',
    at: _wall(0, '08:00'),
    title: 'Chimney Sweep',
    status: 'In progress',
    minor: 28900,
  ),
  _job('j-2', at: _wall(0, '11:00'), title: 'Free Estimate'),
  _job(
    'j-3',
    at: _wall(1, '08:00'),
    title: 'Crown Repair',
    status: 'Pending',
    minor: 128000,
  ),
  _job(
    'j-4',
    at: _wall(1, '10:00'),
    title: 'Liner Install',
    status: 'Canceled',
  ),
];

Future<void> _pumpJobs(
  WidgetTester tester, {
  required List<Map<String, dynamic>> jobs,
  void Function(Map<String, String> query)? onJobs,
  Map<String, int> statusCounts = const {},
  Size size = const Size(1280, 900),
}) async {
  final byId = {for (final j in jobs) j['id']: j};
  final dayCounts = {formatCalendarDay(DateTime.now()): 38};

  await _pump(
    tester,
    size: size,
    // Sees money: the Money and Due columns (queue 31) are part of what this
    // layout test lines up.
    permissions: const [
      'jobs.view',
      'jobs.create',
      'jobs.edit',
      'jobs.delete',
      'jobs.financials',
    ],
    handler: (request) {
      final path = request.url.path;

      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': [
                'Pending',
                'Submitted',
                'In progress',
                'done pending approval',
                'Done',
                'Canceled',
              ],
            },
          ],
        });
      }

      for (final id in byId.keys) {
        if (path.endsWith('/jobs/$id')) {
          return _json({
            'data': {
              ...byId[id]!,
              'description': null,
              'duration_minutes': null,
              'allowed_statuses': ['Submitted', 'In progress', 'Canceled'],
              'lines': <dynamic>[],
            },
          });
        }
      }

      if (path.endsWith('/jobs')) {
        onJobs?.call(request.url.queryParameters);
        return _json({
          'data': jobs,
          'meta': {
            'total': jobs.length,
            'last_page': 1,
            'current_page': 1,
            'status_counts': statusCounts,
            // The statuses where the viewer looks come with the list (2026-10-27).
            'statuses': [
              'Pending',
              'Submitted',
              'In progress',
              'done pending approval',
              'Done',
              'Canceled',
            ],
            'when_counts': {'upcoming': jobs.length, 'past': 7522},
            'day_counts': dayCounts,
          },
        });
      }

      return null;
    },
    home: (api, session) => JobsScreen(api: api, session: session),
  );
}

const _abby = {
  'id': 'c-2',
  'name': 'Abby Lindqvist',
  'number': 'C-03390',
  'email': 'abby.l@example.com',
  'phone': '214-555-0187',
  'city': 'Dallas',
  'state': 'TX',
  'is_active': true,
  'jobs_count': 3,
  'owning_franchise': {
    'id': 'fr-1',
    'name': 'Texas',
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
  },
};

const _ben = {
  'id': 'c-3',
  'name': 'Ben Okafor',
  'number': 'C-04718',
  'email': 'ben.o@example.com',
  'phone': '312-555-0126',
  'city': 'Oak Park',
  'state': 'IL',
  'is_active': true,
  'jobs_count': 1,
  'owning_franchise': {
    'id': 'fr-1',
    'name': 'Illinois',
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
  },
};

Future<void> _pumpCustomers(
  WidgetTester tester, {
  void Function(Map<String, String> query)? onCustomers,
  bool withJobs = false,
  Size size = const Size(1280, 900),
}) async {
  await _pump(
    tester,
    size: size,
    permissions: [
      'customers.view',
      'customers.create',
      'customers.edit',
      'customers.delete',
      if (withJobs) 'jobs.view',
    ],
    handler: (request) {
      final path = request.url.path;

      if (path.endsWith('/customers/c-2')) {
        return _json({
          'data': {
            ..._abby,
            'notes': null,
            'address': {
              'line1': '4120 Maple Ave',
              'line2': null,
              'city': 'Dallas',
              'state': 'TX',
              'postal_code': '75219',
            },
          },
        });
      }

      if (path.endsWith('/customers')) {
        onCustomers?.call(request.url.queryParameters);
        return _json({
          'data': [_abby, _ben],
          'meta': {
            'total': 2,
            'last_page': 1,
            'current_page': 1,
            'segment_counts': {'all': 7675, 'repeat': 885, 'none': 502},
          },
        });
      }

      // The customer's history, read through the jobs list: two of their
      // three jobs are in a franchise this reader can open.
      if (path.endsWith('/jobs') &&
          request.url.queryParameters['customer_id'] == 'c-2') {
        return _json({
          'data': [
            _job('h-1', at: _wall(1, '10:00'), title: 'Chimney Inspection'),
            _job(
              'h-2',
              at: '2026-03-12T09:00:00-05:00',
              title: 'Sweep',
              status: 'Done',
            ),
          ],
          'meta': {'total': 2, 'last_page': 1, 'current_page': 1},
        });
      }

      return null;
    },
    home: (api, session) => CustomersScreen(api: api, session: session),
  );
}

Future<void> _pump(
  WidgetTester tester, {
  required Size size,
  required List<String> permissions,
  required http.Response? Function(http.Request request) handler,
  required Widget Function(ApiClient api, Session session) home,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'jobs',
              'label': 'Jobs',
              'columns': ['number'],
              'importable': false,
            },
            {
              'key': 'customers',
              'label': 'Customers',
              'columns': ['number'],
              'importable': true,
            },
          ],
          'organization': {'export_refusal': null, 'inventory_refusal': null},
        });
      }

      return handler(request) ??
          _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: home(api, session)),
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
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': <dynamic>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
