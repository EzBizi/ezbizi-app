import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/project_management_tab.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:ezbizi_client/screens/project_management_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management, its own module: the table, its countdown, its filters
/// and the timeline beside it (UI queue, option 1 and phone 4). Pumped as the
/// module's screen, so the status words in the timeline come from its own
/// /settings read. Every name invented.
void main() {
  // A fixed local noon: the countdown is all about "now".
  final now = DateTime(2026, 10, 21, 12);
  String at(Duration d) => now.add(d).toUtc().toIso8601String();

  group('the countdown', () {
    ({String text, Tone tone}) running(Duration d) =>
        pmCountdown({'timer': 'running', 'deadline_at': at(d)}, now);

    test('days, amber under 7 and red under 2', () {
      expect(running(const Duration(days: 10)), (
        text: 'cancels in 10 days',
        tone: Tone.neutral,
      ));
      expect(running(const Duration(days: 4)), (
        text: 'cancels in 4 days',
        tone: Tone.caution,
      ));
      expect(running(const Duration(days: 1, hours: 12)), (
        text: 'cancels in 1 day',
        tone: Tone.bad,
      ));
    });

    test('hours under a day, then Due today, then Overdue', () {
      expect(running(const Duration(hours: 3)), (
        text: 'cancels in 3 hours',
        tone: Tone.bad,
      ));
      // The deadline is the day's start: past it, on the same day.
      expect(running(const Duration(hours: -12)), (
        text: 'Due today',
        tone: Tone.bad,
      ));
      expect(running(const Duration(days: -1)), (
        text: 'Overdue',
        tone: Tone.bad,
      ));
    });

    test('stopped, closed, and no estimate', () {
      expect(pmCountdown({'timer': 'stopped'}, now), (
        text: 'Paid · stopped',
        tone: Tone.ok,
      ));
      expect(pmCountdown({'timer': 'closed'}, now), (
        text: 'Closed',
        tone: Tone.neutral,
      ));
      expect(pmCountdown(null, now).text, '—');
    });
  });

  List<Map<String, dynamic>> rows({bool money = true}) => [
    _row('j-1', 'JOB-501', 'Flue rebuild', 'Wren Calder', 'waiting', {
      'estimate_date': '2026-09-22',
      'days_waiting': 29,
      'deadline': '2026-10-22',
      'deadline_at': at(const Duration(hours: 3)),
      'timer': 'running',
    }, money: money),
    _row('j-2', 'JOB-502', 'Liner fit', 'Ola Brisk', 'waiting', {
      'estimate_date': '2026-09-25',
      'days_waiting': 26,
      'deadline': '2026-10-25',
      'deadline_at': at(const Duration(days: 4)),
      'timer': 'running',
    }, money: money),
    _row('j-3', 'JOB-503', 'Cap swap', 'Tam Fenwick', 'partial', {
      'estimate_date': '2026-10-01',
      'days_waiting': 20,
      'deadline': null,
      'deadline_at': null,
      'timer': 'stopped',
    }, money: money),
    _row('j-4', 'JOB-504', 'Crown seal', 'Ivo Marsh', 'closed', {
      'estimate_date': '2026-09-01',
      'days_waiting': 50,
      'deadline': null,
      'deadline_at': null,
      'timer': 'closed',
    }, money: money),
  ];

  testWidgets('rows keep the server order, with the countdown and its tone', (
    tester,
  ) async {
    await _pump(tester, rows: rows(), now: now);

    // Waiting is the segment's first choice.
    expect(find.byKey(const ValueKey('pm-row-j-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-row-j-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-row-j-3')), findsNothing);
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('pm-row-j-1'))).dy,
      lessThan(tester.getTopLeft(find.byKey(const ValueKey('pm-row-j-2'))).dy),
    );

    final first = tester.widget<StatusPill>(
      find.byKey(const ValueKey('pm-countdown-j-1')),
    );
    expect((first.label, first.tone), ('cancels in 3 hours', Tone.bad));
    final second = tester.widget<StatusPill>(
      find.byKey(const ValueKey('pm-countdown-j-2')),
    );
    expect((second.label, second.tone), ('cancels in 4 days', Tone.caution));

    for (final h in ['JOB', 'CUSTOMER', 'BRANCH', 'STAGE', 'WAITING']) {
      expect(find.text(h), findsOneWidget);
    }
  });

  testWidgets('the stage segment counts the rows and filters them', (
    tester,
  ) async {
    await _pump(tester, rows: rows(), now: now);

    expect(find.textContaining('Waiting · 2'), findsOneWidget);
    expect(find.textContaining('Partly paid · 1'), findsOneWidget);
    expect(find.textContaining('Paid in full · 0'), findsOneWidget);
    expect(find.textContaining('Closed · 1'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-stage-partial')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-row-j-1')), findsNothing);
    final stopped = tester.widget<StatusPill>(
      find.byKey(const ValueKey('pm-countdown-j-3')),
    );
    expect(stopped.label, 'Paid · stopped');

    await tester.tap(find.byKey(const ValueKey('pm-stage-all')));
    await tester.pumpAndSettle();
    for (final id in ['j-1', 'j-2', 'j-3', 'j-4']) {
      expect(find.byKey(ValueKey('pm-row-$id')), findsOneWidget);
    }

    await tester.tap(find.byKey(const ValueKey('pm-stage-full')));
    await tester.pumpAndSettle();
    expect(find.text('No jobs are paid in full.'), findsOneWidget);
  });

  testWidgets('the branch and days pickers send their filters', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, rows: rows(), now: now, seen: seen);
    expect(seen.single.queryParameters, isEmpty);

    await tester.tap(find.byKey(const ValueKey('pm-min-days')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('At least 14 days'));
    await tester.pumpAndSettle();
    expect(seen.last.queryParameters, {'min_days': '14'});

    await tester.tap(find.byKey(const ValueKey('pm-branch')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lakeside · Brightway'));
    await tester.pumpAndSettle();
    expect(seen.last.queryParameters, {
      'min_days': '14',
      'franchise_id': 'fr-2',
    });
  });

  // Jose, 2026-10-21: a search bar, matched on the server.
  testWidgets('search asks the server as you type', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, rows: rows(), now: now, seen: seen);

    await tester.enterText(find.byKey(const ValueKey('pm-search')), '4417');
    await tester.pump(const Duration(milliseconds: 200));
    expect(seen, hasLength(1), reason: 'still typing');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(seen.last.queryParameters, {'q': '4417'});
  });

  testWidgets('a row opens its timeline beside the table, with money', (
    tester,
  ) async {
    await _pump(tester, rows: rows(), now: now);

    await tester.tap(find.byKey(const ValueKey('pm-row-j-1')));
    await tester.pumpAndSettle();

    final pane = find.byKey(const ValueKey('pm-timeline'));
    expect(pane, findsOneWidget);
    Finder inPane(String text) =>
        find.descendant(of: pane, matching: find.textContaining(text));
    expect(inPane('Entered “Estimate sent”'), findsOneWidget);
    expect(inPane('Payment · partial'), findsOneWidget);
    expect(inPane('Status Estimate sent → Approved'), findsOneWidget);
    expect(inPane('Tags + Follow up'), findsOneWidget);
    expect(inPane('Automation'), findsOneWidget);
    expect(inPane(r'$4,800.00'), findsOneWidget);
    expect(inPane(r'$1,200.00'), findsOneWidget);
    expect(inPane('waiting 29 days'), findsOneWidget);
    expect(inPane('cancels on'), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-open-job')), findsOneWidget);
  });

  testWidgets('without the money fields the timeline shows no amounts', (
    tester,
  ) async {
    await _pump(tester, rows: rows(money: false), now: now);

    await tester.tap(find.byKey(const ValueKey('pm-row-j-1')));
    await tester.pumpAndSettle();

    final pane = find.byKey(const ValueKey('pm-timeline'));
    // The control: the timeline is there.
    expect(
      find.descendant(of: pane, matching: find.textContaining('Payment')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: pane, matching: find.textContaining(r'$')),
      findsNothing,
    );
  });

  testWidgets('nothing watched says so instead of an empty table', (
    tester,
  ) async {
    await _pump(tester, rows: const [], watched: const [], now: now);

    expect(find.text('Nothing is being watched yet.'), findsOneWidget);
    expect(find.text('CUSTOMER'), findsNothing);
  });

  testWidgets('narrow: cards with the countdown, tapped open in place', (
    tester,
  ) async {
    await _pump(tester, rows: rows(), now: now, size: const Size(400, 800));

    expect(find.text('CUSTOMER'), findsNothing);
    expect(find.byKey(const ValueKey('pm-card-j-1')), findsOneWidget);
    expect(
      tester
          .widget<StatusPill>(find.byKey(const ValueKey('pm-countdown-j-1')))
          .label,
      'cancels in 3 hours',
    );

    await tester.tap(find.byKey(const ValueKey('pm-card-j-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-card-j-2')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('pm-timeline-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-card-j-2')), findsOneWidget);
  });

  testWidgets('Jobs no longer draws it as a tab', (tester) async {
    // Even for somebody who could see Estimates beside Jobs, the strip has
    // no third entry: Project Management left it for the sidebar.
    await _pump(
      tester,
      rows: rows(),
      now: now,
      screen: true,
      permissions: const [
        'jobs.view',
        'jobs.financials',
        'estimates.view',
        'project_management.view',
      ],
    );
    final labels = [
      for (final l
          in tester.widget<TabBarLike>(find.byType(TabBarLike).first).labels)
        l.$1,
    ];
    expect(labels, ['Jobs', 'Estimates']);
    expect(find.text('Project Management'), findsNothing);
    expect(find.byKey(const ValueKey('tour-jobs-pm-stages')), findsNothing);
  });
}

Map<String, dynamic> _row(
  String id,
  String number,
  String title,
  String customer,
  String stage,
  Map<String, dynamic> waiting, {
  required bool money,
}) => {
  'id': id,
  'number': number,
  'title': title,
  'status': 'estimate_sent',
  'tags': const <String>[],
  'franchise': {'id': 'fr-1', 'name': 'Northgate'},
  'customer': {'id': 'c-$id', 'name': customer},
  'stage': stage,
  'waiting': waiting,
  'timeline': [
    {
      'kind': 'entered',
      'at': '2026-09-22T14:00:00Z',
      'status': 'estimate_sent',
      'by': 'Dara Quill',
      if (money) 'total_minor': 480000,
    },
    {
      'kind': 'tags',
      'at': '2026-09-29T14:00:00Z',
      'tags': {
        'added': ['Follow up'],
        'removed': <String>[],
      },
      'by': 'Automation',
    },
    {
      'kind': 'status',
      'at': '2026-10-05T14:00:00Z',
      'from': 'estimate_sent',
      'to': 'approved',
      'by': 'Workiz',
    },
    {
      'kind': 'payment',
      'at': '2026-10-08T14:00:00Z',
      'marks': 'partial',
      if (money) 'amount_minor': 120000,
    },
  ],
};

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> rows,
  required DateTime now,
  List<String> watched = const ['estimate_sent'],
  List<Uri>? seen,
  Size size = const Size(1280, 900),
  bool screen = false,
  List<String> permissions = const ['jobs.view', 'project_management.view'],
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
      if (path.endsWith('/jobs/project-management')) {
        seen?.add(request.url);
        return _json({
          'data': rows,
          'meta': {'watched': watched, 'deadline_days': 30},
        });
      }
      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': [
                {'value': 'estimate_sent', 'label': 'Estimate sent'},
                {'value': 'approved', 'label': 'Approved'},
              ],
            },
          ],
        });
      }
      if (path.endsWith('/jobs')) {
        return _json({
          'data': <Object>[],
          'meta': {'total': 0, 'last_page': 1},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 404);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: screen
            ? JobsScreen(api: api, session: session)
            : ProjectManagementScreen(
                api: api,
                session: session,
                now: () => now,
              ),
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
  'name': 'Rhea Tollan',
  'email': 'rhea@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightway',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightway',
    'path': ['Brightway'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Brightway', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Northgate', 'company_id': 'co-1'},
    {'id': 'fr-2', 'name': 'Lakeside', 'company_id': 'co-1'},
  ],
};
