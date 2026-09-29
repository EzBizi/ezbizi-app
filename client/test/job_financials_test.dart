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
  testWidgets('without Financials the list has no Amount column', (
    tester,
  ) async {
    await _open(tester, permissions: const ['jobs.view', 'jobs.edit']);

    expect(find.text('Amount'), findsNothing);
    expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');
  });

  testWidgets('with Financials the money columns are there', (tester) async {
    await _open(
      tester,
      permissions: const ['jobs.view', 'jobs.edit', 'jobs.financials'],
    );

    // Queue 31: Workiz's money is a state and what is still due.
    expect(find.text('Money'), findsOneWidget);
    expect(find.text('Due'), findsOneWidget);
  });

  // ── queue 31, Jose's 1 and 4: money on the list and on the job ──

  testWidgets('the money chips count each state, and one narrows the list', (
    tester,
  ) async {
    await _open(tester, permissions: _money, meta: _moneyMeta);

    expect(find.byKey(const ValueKey('money-chip-any')), findsOneWidget);
    for (final (key, count) in [
      ('unpaid', '198'),
      ('partial', '227'),
      ('overdue', '131'),
      ('paid', '2,593'),
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

    await tester.tap(find.byKey(const ValueKey('money-chip-overdue')));
    await tester.pumpAndSettle();
    expect(_lastList['money'], 'overdue');
    expect(_lastList['today'], isNotNull, reason: 'overdue needs a today');
  });

  testWidgets('the totals say billed, paid, due and overdue', (tester) async {
    await _open(tester, permissions: _money, meta: _moneyMeta);

    String total(String what) => tester
        .widget<Text>(find.byKey(ValueKey('money-total-$what-USD')))
        .textSpan!
        .toPlainText();
    expect(total('billed'), r'Billed $5,089,246');
    expect(total('paid'), r'Paid $4,370,740');
    expect(total('due'), r'Due $718,506');
    expect(total('overdue'), r'Overdue $212,380');
  });

  testWidgets('a row says its money, red once it is late', (tester) async {
    await _open(tester, permissions: _money, jobs: _moneyJobs, meta: _moneyMeta);

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

  testWidgets('the job opens with its money first: paid, due, and when', (
    tester,
  ) async {
    await _open(tester, permissions: _money, detailMoney: _partial);
    await tester.tap(find.text('Chimney Sweep'));
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

    // First after the status: above the job's own first field.
    expect(
      tester.getTopLeft(card).dy,
      lessThan(tester.getTopLeft(find.widgetWithText(TextField, 'What is the job')).dy),
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
    'money': {'total': 385000, 'due': 192500, 'state': 'partial', 'due_on': _day(5)},
  },
  {
    ..._jobs.first,
    'id': '2',
    'number': 'JOB-2',
    'title': 'Dryer vent cleaning',
    'money': {'total': 18900, 'due': 18900, 'state': 'unpaid', 'due_on': _day(-8)},
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
