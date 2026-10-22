import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/project_management_logs.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management › Logs (UI queue 139, option 1 and phone 4). Every
/// name, rule and number invented.
void main() {
  final now = DateTime(2026, 10, 21, 12);

  tearDown(() => DeepLinks.pending.value = null);

  testWidgets('opens on Changes: no outcome sent, skipped rows hidden', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    expect(stub.logs.single.queryParameters, {
      'from': '2026-10-21',
      'to': '2026-10-21',
      'timezone': 'America/New_York',
    });
    for (final h in ['WHEN', 'JOB', 'CUSTOMER', 'WHAT CHANGED', 'BY']) {
      expect(find.text(h), findsOneWidget);
    }
    // The done, by-hand and failed rows; the skipped one is folded instead.
    expect(find.text('#7101'), findsOneWidget);
    expect(find.text('#7102'), findsOneWidget);
    expect(find.text('#7103'), findsOneWidget);
    expect(find.text('#7104'), findsNothing);
    expect(find.text('Status → Ready to invoice'), findsOneWidget);
    expect(find.text('Tag + Settled'), findsOneWidget);

    String chip(int i) => tester
        .widget<StatusPill>(find.byKey(ValueKey('pm-logs-outcome-$i')))
        .label;
    expect([chip(0), chip(1), chip(2)], ['Done', 'By hand', 'Failed']);
    expect(find.text('Wren Halvey'), findsOneWidget, reason: 'the person');
  });

  testWidgets('the segment counts each outcome, Changes is done + by hand', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    String label(String key) =>
        tester.widget<Text>(find.byKey(ValueKey('pm-logs-outcome-$key'))).data!;
    expect(label('changes'), 'Changes · 5');
    expect(label('failed'), 'Failed · 1');
    expect(label('skipped'), 'Skipped · 42');
    expect(label('all'), 'All');

    await tester.tap(find.byKey(const ValueKey('pm-logs-outcome-failed')));
    await tester.pumpAndSettle();
    expect(stub.logs.last.queryParameters['outcome'], 'failed');
    // Same counts whatever the outcome.
    expect(label('changes'), 'Changes · 5');

    await tester.tap(find.byKey(const ValueKey('pm-logs-outcome-all')));
    await tester.pumpAndSettle();
    expect(stub.logs.last.queryParameters.containsKey('outcome'), isFalse);
    expect(find.text('#7104'), findsOneWidget, reason: 'All shows skipped');
  });

  testWidgets('a failed row shows its reason beneath, in the error tone', (
    tester,
  ) async {
    await _pump(tester, _Stub(), now: now);

    final reason = tester.widget<Text>(
      find.byKey(const ValueKey('pm-logs-reason-2')),
    );
    expect(reason.data, 'Workiz refused the change: the job is locked.');
    final context = tester.element(find.byKey(const ValueKey('pm-logs')));
    expect(reason.style?.color, Theme.of(context).colorScheme.error);
    // The done row has none.
    expect(find.byKey(const ValueKey('pm-logs-reason-0')), findsNothing);
  });

  testWidgets('skipped runs fold into one line per rule; Why? lists reasons', (
    tester,
  ) async {
    await _pump(tester, _Stub(), now: now);

    expect(
      find.text('Deposit taken → mark settled: skipped 40 times ·'),
      findsOneWidget,
    );
    expect(find.text('Quote signed → book: skipped 2 times ·'), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-logs-reasons-r-1')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('pm-logs-why-r-1')));
    await tester.pumpAndSettle();
    final reasons = find.byKey(const ValueKey('pm-logs-reasons-r-1'));
    expect(
      find.descendant(
        of: reasons,
        matching: find.text('Status was Booked; the rule wants Invoiced. · 37'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: reasons,
        matching: find.text('Tags was empty; the rule wants Paid. · 3'),
      ),
      findsOneWidget,
    );
    // Inline, not over the page.
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const ValueKey('pm-logs-reasons-r-2')), findsNothing);
  });

  testWidgets('Skipped lists each skipped run with its reason', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.byKey(const ValueKey('pm-logs-outcome-skipped')));
    await tester.pumpAndSettle();
    expect(stub.logs.last.queryParameters['outcome'], 'skipped');
    expect(find.text('#7104'), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('pm-logs-reason-0'))).data,
      'Status was Booked; the rule wants Invoiced.',
    );
    expect(find.byKey(const ValueKey('pm-logs-fold-r-1')), findsNothing);
  });

  testWidgets('a rule opens in Automations; the job opens its timeline', (
    tester,
  ) async {
    final stub = _Stub();
    final opened = <String>[];
    await _pump(tester, stub, now: now, onOpen: opened.add);

    await tester.tap(find.byKey(const ValueKey('pm-logs-rule-0')));
    await tester.pumpAndSettle();
    expect(opened, ['automations']);
    final link = DeepLinks.pending.value!;
    expect(
      (link.module, link.type, link.id),
      ('automations', 'automation', 'r-1'),
    );

    await tester.tap(find.byKey(const ValueKey('pm-logs-job-0')));
    await tester.pumpAndSettle();
    expect(stub.timelines.single.queryParametersAll['ids[]'], ['j-1']);
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
    // The timeline's "EzBizi · <rule>" opens the rule too.
    DeepLinks.pending.value = null;
    await tester.tap(find.byKey(const ValueKey('pm-timeline-rule-r-1')).first);
    await tester.pumpAndSettle();
    expect(opened, ['automations', 'automations']);
    expect(DeepLinks.pending.value?.id, 'r-1');
  });

  testWidgets('the rule picker lists the rules seen and sends the rule', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.byKey(const ValueKey('pm-logs-rule')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Quote signed → book').last);
    await tester.pumpAndSettle();
    expect(stub.logs.last.queryParameters['automation_id'], 'r-2');
  });

  // Jose: every list gets a search, matched on the server (9967b050d).
  testWidgets('search sends q after 400 ms and shows what the server found', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.enterText(
      find.byKey(const ValueKey('pm-logs-search')),
      'corin',
    );
    await tester.pump(const Duration(milliseconds: 200));
    expect(stub.logs, hasLength(1), reason: 'still typing');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(stub.logs.last.queryParameters['q'], 'corin');
    expect(stub.logs.last.queryParameters['from'], '2026-10-21');

    expect(find.text('#7103'), findsOneWidget);
    expect(find.text('#7101'), findsNothing);
    expect(find.text('#7102'), findsNothing);
    // A rule's name matches too, and its folded line stays.
    await tester.enterText(
      find.byKey(const ValueKey('pm-logs-search')),
      'deposit',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(find.text('#7101'), findsOneWidget);
    expect(find.text('#7103'), findsNothing);
    expect(find.byKey(const ValueKey('pm-logs-fold-r-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-logs-fold-r-2')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('pm-logs-search')), 'zz');
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(find.text('Nothing here matches “zz”.'), findsOneWidget);

    // A phone number the row does not show: the server's match stands, as
    // the client filters nothing itself.
    await tester.enterText(
      find.byKey(const ValueKey('pm-logs-search')),
      '0199',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(find.text('#7103'), findsOneWidget);
  });

  testWidgets("a run's steps show under what changed, a failed one in red", (
    tester,
  ) async {
    await _pump(tester, _Stub(), now: now);

    final row = find.byKey(const ValueKey('pm-logs-row-2'));
    final failed = tester.widget<Text>(
      find.descendant(
        of: row,
        matching: find.text('Workiz: Status → Booked refused'),
      ),
    );
    final context = tester.element(row);
    expect(failed.style?.color, Theme.of(context).colorScheme.error);
    expect(
      find.descendant(
        of: row,
        matching: find.text('Notification: told the office'),
      ),
      findsOneWidget,
    );
    // In place of changes[].detail.
    expect(
      find.descendant(of: row, matching: find.text('Status → Booked')),
      findsNothing,
    );
  });

  testWidgets('the timeline draws each rule step with its outcome and why', (
    tester,
  ) async {
    await _pump(tester, _Stub(), now: now);
    await tester.tap(find.byKey(const ValueKey('pm-logs-job-0')));
    await tester.pumpAndSettle();

    final pane = find.byKey(const ValueKey('pm-timeline'));
    Finder inPane(String text) =>
        find.descendant(of: pane, matching: find.text(text));
    expect(inPane('Workiz: Status → Ready to invoice'), findsOneWidget);
    expect(inPane('Message to customer: not sent'), findsOneWidget);
    expect(inPane('Done'), findsOneWidget);
    expect(inPane('Failed'), findsOneWidget);
    expect(inPane('Skipped'), findsNWidgets(2), reason: 'its title and chip');
    final why = tester.widget<Text>(inPane('No mobile number.'));
    expect(why.style?.color, Theme.of(tester.element(pane)).colorScheme.error);
    expect(inPane('Tags was empty; the rule wants Paid.'), findsOneWidget);
    // The rule links on every step, and on the status change.
    expect(
      find.descendant(
        of: pane,
        matching: find.byKey(const ValueKey('pm-timeline-rule-r-1')),
      ),
      findsNWidgets(4),
    );
  });

  testWidgets('an empty window is one sentence', (tester) async {
    await _pump(tester, _Stub(empty: true), now: now);
    expect(find.text('Nothing happened in these dates.'), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-logs-fold-r-1')), findsNothing);
  });

  testWidgets('a phone at 390×844: cards, the folds, no overflow', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now, size: const Size(390, 844));

    expect(tester.takeException(), isNull);
    expect(find.text('WHEN'), findsNothing, reason: 'cards, not a table');
    expect(find.text('#7101'), findsOneWidget);
    expect(find.text('#7104'), findsNothing);
    expect(
      find.byKey(const ValueKey('pm-logs-reason-2')),
      findsOneWidget,
      reason: 'the failed card keeps its reason',
    );

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('pm-logs-why-r-1')),
      200,
      scrollable: _page,
    );
    await tester.ensureVisible(find.byKey(const ValueKey('pm-logs-why-r-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-logs-why-r-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-logs-reasons-r-1')), findsOneWidget);
    expect(tester.takeException(), isNull);

    // A job takes the screen, with a way back.
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('pm-logs-job-0')),
      -200,
      scrollable: _page,
    );
    await tester.tap(find.byKey(const ValueKey('pm-logs-job-0')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-logs-outcome')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('pm-timeline-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-logs-outcome')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

/// The page's own scroll view; the search field has one of its own.
final _page = find
    .descendant(
      of: find.byKey(const ValueKey('pm-logs')),
      matching: find.byType(Scrollable),
    )
    .first;

class _Stub {
  _Stub({this.empty = false});

  final bool empty;
  final logs = <Uri>[];
  final timelines = <Uri>[];

  static Map<String, dynamic> _job(String id, String number, String who) => {
    'id': id,
    'number': number,
    'title': 'Chimney sweep',
    'customer': who,
    'franchise': 'Northgate',
  };

  List<Map<String, dynamic>> get _all => [
    {
      'kind': 'automation',
      'at': DateTime(2026, 10, 21, 10, 42).toUtc().toIso8601String(),
      'job': _job('j-1', '#7101', 'Tamsin Okoro'),
      'rule': {'id': 'r-1', 'name': 'Deposit taken → mark settled'},
      'outcome': 'succeeded',
      'changes': [
        {
          'type': 'workiz.job_update',
          'state': 'succeeded',
          'detail': 'Status → Ready to invoice',
        },
        {
          'type': 'workiz.job_update',
          'state': 'succeeded',
          'detail': 'Tag + Settled',
        },
      ],
      'reason': null,
    },
    {
      'kind': 'manual',
      'at': DateTime(2026, 10, 21, 10, 15).toUtc().toIso8601String(),
      'job': _job('j-2', '#7102', 'Ilse Brandt'),
      'by': 'Wren Halvey',
      'outcome': 'manual',
      'changes': [
        {
          'type': 'job.updated',
          'state': 'succeeded',
          'detail': 'Sub status: Waiting on parts',
        },
      ],
    },
    {
      'kind': 'automation',
      'at': DateTime(2026, 10, 21, 9, 58).toUtc().toIso8601String(),
      'job': _job('j-3', '#7103', 'Corin Vale'),
      'rule': {'id': 'r-2', 'name': 'Quote signed → book'},
      'outcome': 'failed',
      'changes': [
        {
          'type': 'workiz.job_update',
          'state': 'failed',
          'detail': 'Status → Booked',
        },
      ],
      'lines': [
        {
          'outcome': 'failed',
          'type': 'workiz.job_update',
          'what': 'Workiz: Status → Booked refused',
          'reason': 'Workiz refused the change: the job is locked.',
        },
        {
          'outcome': 'succeeded',
          'type': 'notify',
          'what': 'Notification: told the office',
        },
      ],
      'reason': 'Workiz refused the change: the job is locked.',
    },
    {
      'kind': 'automation',
      'at': DateTime(2026, 10, 21, 9, 30).toUtc().toIso8601String(),
      'job': _job('j-4', '#7104', 'Pella Strand'),
      'rule': {'id': 'r-1', 'name': 'Deposit taken → mark settled'},
      'outcome': 'skipped',
      'changes': <Object>[],
      'reason': 'Status was Booked; the rule wants Invoiced.',
    },
  ];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/jobs/project-management/logs')) {
      logs.add(request.url);
      final outcome = request.url.queryParameters['outcome'];
      final q = request.url.queryParameters['q']?.toLowerCase();
      // As the server searches: the job, its customer and phone, the rule.
      bool hit(Object? v) => v != null && '$v'.toLowerCase().contains(q!);
      bool matches(Map<String, dynamic> r) {
        if (q == null) return true;
        final job = r['job'] as Map;
        return hit(job['number']) ||
            hit(job['customer']) ||
            hit(_phones[job['id']]) ||
            hit((r['rule'] as Map?)?['name']);
      }

      final rows = empty
          ? const <Map<String, dynamic>>[]
          : [
              for (final r in _all)
                if ((outcome == null || r['outcome'] == outcome) && matches(r))
                  r,
            ];
      return _json({
        'data': rows,
        'meta': {
          'counts': empty
              ? {'succeeded': 0, 'failed': 0, 'skipped': 0, 'manual': 0}
              : {'succeeded': 3, 'failed': 1, 'skipped': 42, 'manual': 2},
          'skipped_by_rule': empty
              ? const <Object>[]
              : [
                  for (final s in _folds)
                    if (q == null || hit((s['rule'] as Map)['name'])) s,
                ],
        },
      });
    }

    if (path.endsWith('/jobs/project-management')) {
      return _timeline(request);
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 404);
  }

  static const _phones = {'j-3': '555-0199'};

  static final _folds = <Map<String, dynamic>>[
    {
      'rule': {'id': 'r-1', 'name': 'Deposit taken → mark settled'},
      'count': 40,
      'reasons': [
        {'reason': 'Status was Booked; the rule wants Invoiced.', 'count': 37},
        {'reason': 'Tags was empty; the rule wants Paid.', 'count': 3},
      ],
    },
    {
      'rule': {'id': 'r-2', 'name': 'Quote signed → book'},
      'count': 2,
      'reasons': [
        {'reason': 'The rule did not match.', 'count': 2},
      ],
    },
  ];

  Future<http.Response> _timeline(http.Request request) async {
    {
      timelines.add(request.url);
      return _json({
        'data': [
          {
            'id': 'j-1',
            'number': '#7101',
            'title': 'Chimney sweep',
            'customer': {'id': 'c-1', 'name': 'Tamsin Okoro'},
            'franchise': {'id': 'fr-1', 'name': 'Northgate'},
            'stage': 'full',
            'waiting': {'timer': 'stopped', 'days_waiting': 4},
            'timeline': [
              {
                'kind': 'status',
                'at': DateTime(2026, 10, 21, 10, 44).toUtc().toIso8601String(),
                'from': 'booked',
                'to': 'ready',
                'by': 'EzBizi · Deposit taken → mark settled',
                'rule': {'id': 'r-1', 'name': 'Deposit taken → mark settled'},
              },
              // One line per step the rule took (2026-10-22).
              for (final (outcome, what, reason) in const [
                ('succeeded', 'Workiz: Status → Ready to invoice', null),
                (
                  'failed',
                  'Message to customer: not sent',
                  'No mobile number.',
                ),
                ('skipped', 'Skipped', 'Tags was empty; the rule wants Paid.'),
              ])
                {
                  'kind': 'action',
                  'at': DateTime(
                    2026,
                    10,
                    21,
                    10,
                    43,
                  ).toUtc().toIso8601String(),
                  'by': 'EzBizi · Deposit taken → mark settled',
                  'rule': {'id': 'r-1', 'name': 'Deposit taken → mark settled'},
                  'outcome': outcome,
                  'type': 'workiz.job_update',
                  'what': what,
                  'reason': ?reason,
                },
            ],
          },
        ],
        'meta': {'watched': <String>[], 'deadline_days': 30},
      });
    }
  }
}

Future<void> _pump(
  WidgetTester tester,
  _Stub stub, {
  required DateTime now,
  Size size = const Size(1280, 900),
  void Function(String)? onOpen,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(stub.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OpenDestination(
          open: onOpen ?? (_) {},
          child: ProjectManagementLogs(
            api: api,
            session: session,
            now: () => now,
            zone: () => 'America/New_York',
          ),
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
  'permissions': ['jobs.view', 'project_management.view', 'automations.view'],
  'modules': [
    {
      'key': 'project_management',
      'label': 'Project Management',
      'available': true,
      'reachable': true,
    },
    {
      'key': 'automations',
      'label': 'Automations',
      'available': true,
      'reachable': true,
    },
  ],
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
