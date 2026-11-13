import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-09-29: money in Jobs is "decided on roles y permissions as a
/// financials toggle". Without `jobs.financials` the server leaves every
/// amount out, and the screens must draw nothing rather than a dash in every
/// row or a "Total $0.00" nobody earned.
void main() {
  testWidgets('without Financials the list has no money columns', (
    tester,
  ) async {
    await _open(tester, permissions: const ['jobs.view', 'jobs.edit']);

    // Queue 31 renamed Amount to Money and Due. Asserting only 'Amount' here
    // would pass for ever, whatever the list drew, so every name is checked.
    for (final heading in ['Amount', 'Money', 'Due']) {
      expect(find.text(heading), findsNothing, reason: heading);
    }
    expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');
  });

  testWidgets('with Financials the money columns are there', (tester) async {
    await _open(
      tester,
      permissions: const ['jobs.view', 'jobs.edit', 'jobs.financials'],
      jobs: _moneyJobs,
    );

    // Queue 31: Workiz's money is a state and what is still due.
    expect(find.text('Money'), findsOneWidget);
    expect(find.text('Due'), findsOneWidget);
  });

  // UI review, 2026-10-08: two empty columns squeezed Title and Where. As the
  // Workiz column does, they draw only when a loaded job has money.
  testWidgets('with Financials but no money on the page, no money columns', (
    tester,
  ) async {
    await _open(
      tester,
      permissions: const ['jobs.view', 'jobs.edit', 'jobs.financials'],
    );

    expect(find.text('Money'), findsNothing);
    expect(find.text('Due'), findsNothing);
    expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');
  });

  // ── queue 31, Jose's 1 and 4: money on the list and on the job ──

  testWidgets('the money chips count each state, and one narrows the list', (
    tester,
  ) async {
    await _open(tester, permissions: _money, meta: _moneyMeta);

    // Option A (2026-11-13): the tiles carry paid and overdue; the panel
    // the rest.
    expect(find.byKey(const ValueKey('money-chip-any')), findsOneWidget);
    for (final (key, count) in [('overdue', '131'), ('paid', '2,593')]) {
      expect(
        find.descendant(
          of: find.byKey(ValueKey('money-chip-$key')),
          matching: find.textContaining(count),
        ),
        findsOneWidget,
        reason: '$key says how many',
      );
    }
    await tester.tap(find.byKey(const Key('jobs-filters')));
    await tester.pumpAndSettle();
    for (final (key, count) in [
      ('unpaid', '198'),
      ('partial', '227'),
      ('credit', '102'),
    ]) {
      expect(
        find.descendant(
          of: find.byKey(ValueKey('money-chip-$key')),
          matching: find.textContaining(count),
        ),
        findsOneWidget,
        reason: '$key says how many',
      );
    }

    await tester.tap(find.byKey(const ValueKey('money-chip-unpaid')));
    await tester.pumpAndSettle();
    expect(_lastList['money'], 'unpaid');
    await tester.tap(find.byKey(const Key('jobs-filters-done')));
    await tester.pumpAndSettle();
    expect(find.text('Filters · 1'), findsOneWidget);
    expect(find.widgetWithText(InputChip, 'Unpaid'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('money-chip-overdue')));
    await tester.pumpAndSettle();
    expect(_lastList['money'], 'overdue');
    expect(_lastList['today'], isNotNull, reason: 'overdue needs a today');
  });

  testWidgets('the totals say billed, paid, due and overdue', (tester) async {
    await _open(tester, permissions: _money, meta: _moneyMeta);

    String total(String what) => tester
        .widget<Text>(find.byKey(ValueKey('money-total-$what-USD')))
        .data!;
    expect(total('billed'), r'$5,089,246');
    expect(total('paid'), r'$4,370,740');
    expect(total('due'), r'$718,506');
    expect(total('overdue'), r'$212,380');
  });

  testWidgets('a row says its money, red once it is late', (tester) async {
    await _open(
      tester,
      permissions: _money,
      jobs: _moneyJobs,
      meta: _moneyMeta,
    );

    expect(find.text('Partly paid'), findsOneWidget);
    expect(find.text(r'$1,925.00'), findsOneWidget);
    expect(find.text('Overdue'), findsWidgets);

    final context = tester.element(find.byType(JobsScreen));
    final late = tester.widget<Text>(find.text(r'$189.00'));
    expect(late.style?.color, ToneColours.of(context, Tone.bad).ink);
    // The control: money due on time is not red.
    final onTime = tester.widget<Text>(find.text(r'$1,925.00'));
    expect(onTime.style?.color, isNot(ToneColours.of(context, Tone.bad).ink));
    // A credit is the negative it is, in its own tone.
    final credit = tester.widget<Text>(find.text(r'−$45.00'));
    expect(credit.style?.color, ToneColours.of(context, Tone.info).ink);
  });

  testWidgets("a row's due is in its own money's currency", (tester) async {
    // 2b34fa36: a row's money says its currency. The row's own `amount`
    // says none here, so reading the currency from there would print dollars.
    await _open(
      tester,
      permissions: _money,
      jobs: [
        {
          ..._jobs.first,
          'money': {
            'total': 120000,
            'due': 120000,
            'state': 'unpaid',
            'due_on': _day(9),
            'currency': 'CAD',
          },
        },
      ],
      meta: _moneyMeta,
    );

    expect(find.text('CAD 1,200.00'), findsOneWidget);
    expect(find.text(r'$1,200.00'), findsNothing);
  });

  testWidgets('without Financials there are no money chips or totals', (
    tester,
  ) async {
    await _open(
      tester,
      permissions: const ['jobs.view', 'jobs.edit'],
      meta: _moneyMeta,
    );

    expect(find.byKey(const ValueKey('money-chip-any')), findsNothing);
    expect(find.byKey(const ValueKey('money-total-billed-USD')), findsNothing);
    expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');
  });

  // Was "the job opens with its money first". Queue 58 (Jose: "as
  // recommended") moved the money into the job's Money tab, which names the
  // total so it is still read before anything is opened.
  testWidgets('the Money tab holds the money: paid, due, and when', (
    tester,
  ) async {
    await _open(tester, permissions: _money, detailMoney: _partial);
    await tester.tap(find.text('Chimney Sweep'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('job-money')), findsNothing);
    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('job-money'));
    expect(card, findsOneWidget);
    expect(
      find.descendant(of: card, matching: find.text('Partly paid')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('job-money-due')),
        matching: find.text(r'$1,925.00'),
      ),
      findsOneWidget,
    );
    final bar = tester.widget<LinearProgressIndicator>(
      find.byKey(const Key('job-money-paid-bar')),
    );
    expect(bar.value, closeTo(0.5, 0.001));
    expect(
      tester.widget<Text>(find.byKey(const Key('job-money-when'))).data,
      startsWith('Due by'),
    );

    // The lines fold under it.
    expect(find.text('Stainless steel liner'), findsNothing);
    await tester.tap(find.byKey(const Key('job-money-lines')));
    await tester.pumpAndSettle();
    expect(find.text('Stainless steel liner'), findsOneWidget);
    expect(find.text(r'1 × $2,400.00'), findsOneWidget);
  });

  testWidgets('a credit is said as money owed back', (tester) async {
    await _open(
      tester,
      permissions: _money,
      detailMoney: {
        ..._partial,
        'due': -4500,
        'paid': 29500,
        'total': 25000,
        'credit': 4500,
        'state': 'credit',
        'lines': <dynamic>[],
      },
    );
    await tester.tap(find.text('Chimney Sweep'));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('job-money-credit')), findsOneWidget);
    expect(find.byKey(const ValueKey('job-money-due')), findsNothing);
    expect(
      tester.widget<Text>(find.byKey(const Key('job-money-when'))).data,
      contains('owed back'),
    );
  });

  testWidgets('a job Workiz does not have draws no money card', (tester) async {
    await _open(tester, permissions: _money);
    await tester.tap(find.text('Chimney Sweep'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('job-money')), findsNothing);
    expect(find.text('Work'), findsOneWidget, reason: 'the control');
  });

  testWidgets('without Financials the Work section shows no Total', (
    tester,
  ) async {
    await _open(tester, permissions: const ['jobs.view', 'jobs.edit']);
    await tester.tap(find.text('Chimney Sweep'));
    await tester.pumpAndSettle();

    // The role and the performer are not money, so they stay.
    expect(find.text('Work'), findsOneWidget);
    expect(find.textContaining('Performed by'), findsOneWidget);
    expect(find.text('Total'), findsNothing);
  });
}

String _wall(String clock) =>
    '${formatCalendarDay(DateTime.now())}T$clock:00-05:00';

// What the server sends a person WITHOUT the toggle: no `amount`, no `lines`.
final _jobs = [
  {
    'id': '1',
    'number': 'JOB-1',
    'title': 'Chimney Sweep',
    'status': 'Pending',
    'status_label': 'Pending',
    'scheduled_for': _wall('08:00'),
    'franchise': {'id': 'fr-il', 'name': 'Illinois'},
    'customer': {
      'id': 'c-1',
      'name': 'Maria Delgado',
      'number': 'C-1',
      'owned_by_this_franchise': true,
    },
    'service_area': null,
  },
];

const _money = ['jobs.view', 'jobs.edit', 'jobs.financials'];

// Production's counts and billed, paid and due, from the Jobs session
// (2026-09-29), in cents. The overdue sum is invented.
const _moneyMeta = {
  'money_counts': {
    'unpaid': 198,
    'partial': 227,
    'overdue': 131,
    'paid': 2593,
    'credit': 102,
  },
  'money_totals': {
    'USD': {
      'total': 508924600,
      'paid': 437074000,
      'due': 71850600,
      'overdue': 21238000,
    },
  },
};

String _day(int offset) =>
    formatCalendarDay(DateTime.now().add(Duration(days: offset)));

final _moneyJobs = [
  {
    ..._jobs.first,
    'money': {
      'total': 385000,
      'due': 192500,
      'state': 'partial',
      'due_on': _day(5),
    },
  },
  {
    ..._jobs.first,
    'id': '2',
    'number': 'JOB-2',
    'title': 'Dryer vent cleaning',
    'money': {
      'total': 18900,
      'due': 18900,
      'state': 'unpaid',
      'due_on': _day(-8),
    },
  },
  {
    ..._jobs.first,
    'id': '3',
    'number': 'JOB-3',
    'title': 'Masonry repoint',
    'money': {'total': 25000, 'due': -4500, 'state': 'credit', 'due_on': null},
  },
];

final _partial = {
  'source': 'workiz',
  'currency': 'USD',
  'subtotal': 370000,
  'total': 385000,
  'due': 192500,
  'paid': 192500,
  'credit': 0,
  'due_on': _day(4),
  'state': 'partial',
  'lines': [
    {
      'name': 'Stainless steel liner',
      'description': '25 ft, 6 in',
      'quantity': 1.0,
      'unit_price': 240000,
      'taxable': true,
    },
    {
      'name': 'Liner installation labor',
      'description': null,
      'quantity': 1.0,
      'unit_price': 130000,
      'taxable': false,
    },
  ],
};

Map<String, String> _lastList = const {};

Future<void> _open(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>>? jobs,
  Map<String, dynamic> meta = const {},
  Map<String, dynamic>? detailMoney,
}) async {
  final rows = jobs ?? _jobs;
  _lastList = const {};
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'franchises': const [
              {'id': 'fr-il', 'name': 'Illinois', 'company_id': 'co-1'},
            ],
            'permissions': permissions,
          },
        });
      }
      if (path.endsWith('/exports')) {
        return _json({
          'resources': <dynamic>[],
          'organization': {'export_refusal': null, 'inventory_refusal': null},
        });
      }
      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': ['Pending', 'Done'],
            },
          ],
        });
      }
      if (path.contains('/jobs/')) {
        return _json({
          'data': {
            ...rows.first,
            'money': detailMoney,
            'description': null,
            'duration_minutes': null,
            'allowed_statuses': ['Pending', 'Done'],
            'allowed_service_areas': <dynamic>[],
          },
        });
      }
      if (path.endsWith('/jobs')) {
        _lastList = request.url.queryParameters;
        return _json({
          'data': rows,
          'meta': {
            'total': rows.length,
            'last_page': 1,
            'current_page': 1,
            ...meta,
          },
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: JobsScreen(api: api, session: session),
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
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <dynamic>[],
};
