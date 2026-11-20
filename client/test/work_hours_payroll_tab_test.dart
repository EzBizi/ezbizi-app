import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/work_hours_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Work Hours › Payroll (UI queue 198, options 1 and 4). Every name and
/// figure invented.
void main() {
  tearDown(() => Files.debugOnDownload = null);

  testWidgets('the week, its figures, and the week stepper', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub);

    expect(
      stub.gets.first.queryParameters,
      isEmpty,
      reason: 'the server\'s week',
    );
    expect(find.text('Nov 9 – Nov 15, 2026'), findsOneWidget);
    String count(String key) =>
        tester.widget<Text>(find.byKey(ValueKey('payroll-count-$key'))).data!;
    expect(count('people'), '3');
    expect(count('pay'), r'$1,892.40');
    expect(count('waiting'), '2');
    expect(count('with_warnings'), '1');

    // Pat: $24/h, 44.5 h with 4.5 OT not paid, the server's pay.
    expect(find.text(r'$24.00/h'), findsOneWidget);
    expect(find.text('+4.5 OT, not paid'), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('payroll-pay-u-1'))).data,
      r'$1,096.40',
    );
    // Rae: no rate, no pay, the warning in words.
    expect(find.text('No rate'), findsOneWidget);
    expect(find.text('No pay rate'), findsOneWidget);
    expect(find.textContaining('Approved by Dana Reyes'), findsOneWidget);
    expect(find.byKey(const ValueKey('payroll-total')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('payroll-previous-week')));
    await tester.pumpAndSettle();
    expect(stub.gets.last.queryParameters, {'week': '2026-11-02'});
  });

  testWidgets('bonus saves when the field is left; note, approve, approve '
      'all and the download', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub);

    final bonus = find.descendant(
      of: find.byKey(const ValueKey('payroll-bonus-u-1')),
      matching: find.byType(TextField),
    );
    await tester.enterText(bonus, '75');
    // Nothing is sent while typing.
    expect(stub.writes, isEmpty);
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(stub.writes.single.$1, 'PUT /api/v1/hr/time-clock/payroll/u-1');
    expect(stub.writes.single.$2, {'week': '2026-11-09', 'bonus': 75.0});

    // The same value again sends nothing.
    await tester.tap(bonus);
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(stub.writes, hasLength(1));

    await tester.tap(find.byKey(const ValueKey('payroll-note-toggle-u-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byKey(const ValueKey('payroll-note-u-1')),
        matching: find.byType(TextField),
      ),
      'Holiday shift',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(stub.writes.last.$2, {
      'week': '2026-11-09',
      'note': 'Holiday shift',
    });

    await tester.tap(find.byKey(const ValueKey('payroll-approve-u-1')));
    await tester.pumpAndSettle();
    expect(stub.writes.last.$1, 'POST /api/v1/hr/time-clock/payroll/approve');
    expect(stub.writes.last.$2, {'user_id': 'u-1', 'week': '2026-11-09'});

    await tester.tap(find.text('Approve all 2 waiting'));
    await tester.pumpAndSettle();
    expect(
      stub.writes.last.$1,
      'POST /api/v1/hr/time-clock/payroll/approve-all',
    );

    String? saved;
    Files.debugOnDownload = (name, _) => saved = name;
    await tester.tap(find.byKey(const ValueKey('payroll-download')));
    await tester.pumpAndSettle();
    expect(saved, 'payroll-2026-11-09.xlsx');
    expect(stub.gets.last.path, '/api/v1/hr/time-clock/payroll/export');
  });

  testWidgets('tech expenses open to their lines', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub);

    expect(
      find.byKey(const ValueKey('payroll-expense-lines-u-1')),
      findsNothing,
    );
    await tester.tap(find.byKey(const ValueKey('payroll-expenses-u-1')));
    await tester.pumpAndSettle();
    expect(
      find.textContaining(r'JOB-60906 · Chimney cap · × 1 · $62.40'),
      findsOneWidget,
    );
  });

  testWidgets('search narrows by name', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub);

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('work-hours-payroll-search')),
        matching: find.byType(TextField),
      ),
      'rae',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('payroll-row-u-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('payroll-row-u-1')), findsNothing);
  });

  testWidgets('on a 390 phone: cards, nothing overflows, Approve all at the '
      'bottom', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, size: const Size(390, 844));

    expect(tester.takeException(), isNull);
    expect(find.byType(Card), findsWidgets);
    final all = find.byKey(const ValueKey('payroll-approve-all'));
    expect(all, findsOneWidget);
    expect(tester.getBottomLeft(all).dy, greaterThan(700));
  });

  testWidgets('without work_hours.payroll there is no Payroll tab', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, payroll: false);

    expect(find.text('Payroll'), findsNothing);
    expect(stub.gets, isEmpty);
  });
}

class _Stub {
  final gets = <Uri>[];
  final writes = <(String, Map<String, dynamic>)>[];
  num bonus = 50.0;

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/hr/time-clock/payroll/export')) {
      gets.add(request.url);
      return http.Response.bytes([1, 2, 3], 200);
    }
    if (path.endsWith('/hr/time-clock/payroll') && request.method == 'GET') {
      gets.add(request.url);
      return _json({'data': _week(request.url.queryParameters['week'])});
    }
    if (path.contains('/hr/time-clock/payroll')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      writes.add(('${request.method} $path', body));
      bonus = (body['bonus'] as num?) ?? bonus;
      return _json({'data': _week('2026-11-09', bonus: bonus)});
    }
    return _json({'data': <Object>[]});
  }
}

Map<String, dynamic> _week(String? week, {num bonus = 50.0}) {
  final w = week == null || week.startsWith('2026-11-1') ? '2026-11-09' : week;
  return {
    'week_start': w,
    'label': w == '2026-11-09' ? 'Nov 9 – Nov 15, 2026' : 'Nov 2 – Nov 8, 2026',
    'totals': {
      'people': 3,
      'hours': 120.7,
      'pay': 1892.4,
      'waiting': 2,
      'with_warnings': 1,
    },
    'warning_labels': {
      'no_rate': 'No pay rate',
      'open_shift': 'Still clocked in',
    },
    'rows': [
      {
        'person': {
          'id': 'u-1',
          'name': 'Pat Lin',
          'role': 'Technician',
          'branch': 'Columbus',
        },
        'basis': 'hourly',
        'rate': 24.0,
        'period': 'hour',
        'hours': 44.5,
        'overtime_hours': 4.5,
        'bonus': bonus,
        'deductions': 0.0,
        'note': null,
        'tech_expenses': 86.4,
        'expense_lines': [
          {
            'job_number': 'JOB-60906',
            'item': 'Chimney cap',
            'quantity': 1,
            'amount': 62.4,
            'paid_at': '2026-11-11T15:00:00Z',
          },
          {
            'job_number': 'JOB-60877',
            'item': 'Mortar mix',
            'quantity': 2,
            'amount': 24.0,
            'paid_at': '2026-11-12T15:00:00Z',
          },
        ],
        'pay': 1096.4,
        'warnings': <String>[],
        'status': 'waiting',
        'approved_by': null,
      },
      {
        'person': {
          'id': 'u-2',
          'name': 'Rae Moss',
          'role': 'Technician',
          'branch': 'Akron',
        },
        'basis': null,
        'rate': null,
        'period': null,
        'hours': 36.2,
        'overtime_hours': 0,
        'bonus': 0.0,
        'deductions': 0.0,
        'note': null,
        'tech_expenses': 0.0,
        'expense_lines': <Object>[],
        'pay': null,
        'warnings': ['no_rate'],
        'status': 'waiting',
        'approved_by': null,
      },
      {
        'person': {
          'id': 'u-3',
          'name': 'Morgan Kay',
          'role': 'Office',
          'branch': 'Columbus',
        },
        'basis': 'salary',
        'rate': 41600.0,
        'period': 'year',
        'hours': 40.0,
        'overtime_hours': 0,
        'bonus': 0.0,
        'deductions': 0.0,
        'note': null,
        'tech_expenses': 0.0,
        'expense_lines': <Object>[],
        'pay': 796.0,
        'warnings': <String>[],
        'status': 'approved',
        'approved_by': {'id': 'u-9', 'name': 'Dana Reyes'},
      },
    ],
  };
}

Future<void> _pump(
  WidgetTester tester,
  _Stub stub, {
  bool payroll = true,
  Size size = const Size(1400, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      (request) => stub.handle(request).then((r) {
        if (request.url.path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': [
                'work_hours.view',
                'work_hours.approval.view',
                if (payroll) 'work_hours.payroll',
              ],
            },
          });
        }
        return r;
      }),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: WorkHoursScreen(key: UniqueKey(), api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  if (payroll) {
    await tester.tap(find.text('Payroll'));
    await tester.pumpAndSettle();
  }
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-9',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'permissions': <String>[],
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
  'franchises': <Object>[],
};
