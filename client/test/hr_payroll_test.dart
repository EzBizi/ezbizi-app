import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/payroll_tab.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Human Resources › Payroll (design 135, options 1 and 4). Every name and
/// number here is invented.
void main() {
  testWidgets('the filters go to the server as query parameters', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(tester, onPayroll: asked.add);

    expect(asked.single, isEmpty);

    await tester.tap(find.text('W2 signed'));
    await tester.pumpAndSettle();
    expect(asked.last, {'w2': 'signed'});

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const Key('payroll-role-filter')),
        )
        .onChanged!('r-tech');
    await tester.pumpAndSettle();
    expect(asked.last, {'w2': 'signed', 'role_id': 'r-tech'});

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const Key('payroll-contract-filter')),
        )
        .onChanged!('c-sales');
    await tester.pumpAndSettle();
    expect(asked.last, {
      'w2': 'signed',
      'role_id': 'r-tech',
      'contract_id': 'c-sales',
    });

    await tester.tap(find.text('W2 not signed'));
    await tester.pumpAndSettle();
    expect(asked.last['w2'], 'unsigned');

    // The role picked stays offered after the rows were narrowed to it.
    final roles = tester
        .widget<SearchPickerField<String>>(
          find.byKey(const Key('payroll-role-filter')),
        )
        .options
        .map((o) => o.label);
    expect(roles, containsAll(['Any role', 'Technician', 'Office manager']));
  });

  testWidgets('a salary reads as amount, period and since', (tester) async {
    await _pump(tester);

    expect(find.text(r'$52,000 a year'), findsOneWidget);
    expect(find.text('since 2026-03-01'), findsOneWidget);
    expect(find.text(r'$24.50 an hour'), findsOneWidget);
    expect(find.text('✓ Signed'), findsNWidgets(2));
    expect(find.text('✕ Not signed'), findsOneWidget);
  });

  testWidgets('without hr.pay.view the salary is a dash that says why', (
    tester,
  ) async {
    await _pump(tester, canSee: false);

    expect(find.text(r'$52,000 a year'), findsNothing);
    final hidden = find.byKey(const Key('payroll-salary-hidden-u-dana'));
    expect(hidden, findsOneWidget);
    expect(
      tester.widget<Tooltip>(hidden).message,
      'Your role cannot see pay, so salaries are not shown.',
    );
    expect(find.byKey(const Key('payroll-salary-u-dana')), findsNothing);
  });

  testWidgets('the salary pane posts the new rate and lists the history', (
    tester,
  ) async {
    Map<String, dynamic>? posted;
    final asked = <Map<String, String>>[];
    await _pump(
      tester,
      onPayroll: asked.add,
      onPayRate: (body) => posted = body,
    );

    await tester.tap(find.byKey(const Key('payroll-salary-u-dana')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('payroll-salary-pane')), findsOneWidget);
    expect(find.text(r'2023-04-12 · $46,000 a year · start'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('payroll-rate-amount')),
      '55000',
    );
    await tester.enterText(
      find.byKey(const Key('payroll-rate-note')),
      'annual raise',
    );
    await tester.tap(find.byKey(const Key('payroll-rate-save')));
    await tester.pumpAndSettle();

    expect(posted, {
      'basis': 'salary',
      'amount': 55000,
      'period': 'year',
      'effective_on': _today(),
      'note': 'annual raise',
    });
    // Saved, and the table asked again.
    expect(find.byKey(const Key('payroll-rate-saved')), findsOneWidget);
    expect(asked.length, 2);
  });

  testWidgets('without hr.pay.edit the pane is read-only', (tester) async {
    await _pump(tester, canEdit: false);

    await tester.tap(find.byKey(const Key('payroll-salary-u-dana')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('payroll-rate-read-only')), findsOneWidget);
    expect(find.byKey(const Key('payroll-rate-save')), findsNothing);
    expect(find.byKey(const Key('payroll-rate-amount')), findsNothing);
    expect(find.byKey(const Key('payroll-history')), findsOneWidget);
  });

  testWidgets('the W2 and the contract download from their own endpoints', (
    tester,
  ) async {
    final fetched = <String>[];
    await _pump(tester, onBytes: fetched.add);

    // Pat has not signed, so there is nothing to download.
    expect(find.byKey(const Key('payroll-w2-download-u-pat')), findsNothing);
    // Pat's contract is still being filled in.
    expect(
      find.byKey(const Key('payroll-contract-download-u-pat')),
      findsNothing,
    );

    await tester.tap(find.byKey(const Key('payroll-w2-download-u-dana')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('payroll-contract-download-u-dana')));
    await tester.pumpAndSettle();

    expect(fetched, [
      '/api/v1/attachments/0a1b2c3d-0000-4000-8000-000000000001',
      '/api/v1/hr/onboardings/ob-dana/document/pdf',
    ]);
  });

  testWidgets('people who left sort last, dimmed, and can be hidden', (
    tester,
  ) async {
    await _pump(tester);

    final dana = tester.getTopLeft(find.byKey(const Key('payroll-row-u-dana')));
    final rae = tester.getTopLeft(find.byKey(const Key('payroll-row-u-rae')));
    final pat = tester.getTopLeft(find.byKey(const Key('payroll-row-u-pat')));
    // The server sends them by name: Dana, Pat, Rae. Rae left.
    expect(dana.dy, lessThan(pat.dy));
    expect(pat.dy, lessThan(rae.dy));
    expect(
      tester
          .widget<Opacity>(find.byKey(const Key('payroll-row-u-rae')))
          .opacity,
      lessThan(1),
    );
    expect(
      tester
          .widget<Opacity>(find.byKey(const Key('payroll-row-u-dana')))
          .opacity,
      1,
    );

    await tester.tap(find.byKey(const Key('payroll-show-left')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('payroll-row-u-rae')), findsNothing);
    expect(find.byKey(const Key('payroll-row-u-dana')), findsOneWidget);
  });

  testWidgets('search narrows the table by name', (tester) async {
    await _pump(tester);

    await tester.enterText(find.byKey(const Key('payroll-search')), 'pat');
    await tester.pump();

    expect(find.byKey(const Key('payroll-row-u-pat')), findsOneWidget);
    expect(find.byKey(const Key('payroll-row-u-dana')), findsNothing);
  });

  testWidgets('on a phone: chips and cards, and a card opens the person in '
      'place', (tester) async {
    final asked = <Map<String, String>>[];
    await _pump(tester, size: const Size(390, 844), onPayroll: asked.add);

    expect(find.byKey(const Key('payroll-card-u-dana')), findsOneWidget);
    expect(find.byKey(const Key('payroll-row-u-dana')), findsNothing);
    expect(
      find.text(r'$52,000 a year · Office manager · since 2023-04-12'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('payroll-w2-chip-unsigned')));
    await tester.pumpAndSettle();
    expect(asked.last, {'w2': 'unsigned'});
    await tester.tap(find.byKey(const Key('payroll-w2-chip-everyone')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('payroll-card-u-dana')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('payroll-phone-person')), findsOneWidget);
    expect(find.byKey(const Key('payroll-card-u-pat')), findsNothing);
    expect(find.byKey(const Key('payroll-rate-save')), findsOneWidget);
    expect(find.byKey(const Key('payroll-w2-download-u-dana')), findsOneWidget);

    await tester.scrollUntilVisible(
      find.byKey(const Key('payroll-history')),
      200,
      scrollable: find.byType(Scrollable).last,
    );
    expect(tester.takeException(), isNull);
  });
}

String _today() {
  final now = DateTime.now();
  String two(int n) => n.toString().padLeft(2, '0');

  return '${now.year}-${two(now.month)}-${two(now.day)}';
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1400, 1000),
  bool canSee = true,
  bool canEdit = true,
  void Function(Map<String, String> query)? onPayroll,
  void Function(Map<String, dynamic> body)? onPayRate,
  void Function(String path)? onBytes,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/hr/payroll')) {
        onPayroll?.call(request.url.queryParameters);
        return _json({
          'data': [
            for (final row in _rows) {...row, if (!canSee) 'salary': null},
          ],
          'meta': {'can_see_salary': canSee, 'can_edit_salary': canEdit},
        });
      }
      if (path.endsWith('/details/pay-rates') && request.method == 'POST') {
        onPayRate?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({
          'data': {'id': 'pr-new'},
        }, 201);
      }
      if (path.endsWith('/hr/people/u-dana')) {
        return _json({
          'data': {
            'id': 'u-dana',
            'pay_rates': [
              {
                'id': 'pr-2',
                'basis': 'salary',
                'amount': '52000',
                'period': 'year',
                'effective_on': '2026-03-01',
                'note': 'raise',
              },
              {
                'id': 'pr-1',
                'basis': 'salary',
                'amount': '46000',
                'period': 'year',
                'effective_on': '2023-04-12',
                'note': 'start',
              },
            ],
          },
        });
      }
      if (path.contains('/attachments/') || path.endsWith('/document/pdf')) {
        onBytes?.call(path);
        return http.Response.bytes(
          utf8.encode('%PDF-1.4 invented'),
          200,
          headers: {'content-type': 'application/pdf'},
        );
      }
      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: PayrollTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _rows = [
  {
    'id': 'u-dana',
    'name': 'Dana Reyes',
    'is_active': true,
    'salary': {
      'amount': '52000',
      'period': 'year',
      'basis': 'salary',
      'effective_on': '2026-03-01',
    },
    'w2': {
      'signed': true,
      'attachment_id': '0a1b2c3d-0000-4000-8000-000000000001',
    },
    'started_on': '2023-04-12',
    'terminated_on': null,
    'role': {'id': 'r-office', 'name': 'Office manager'},
    'contract': {
      'id': 'c-office',
      'name': 'Office staff 2026',
      'onboarding_id': 'ob-dana',
      'status': 'completed',
    },
  },
  {
    'id': 'u-pat',
    'name': 'Pat Lin',
    'is_active': true,
    'salary': {
      'amount': '24.50',
      'period': 'hour',
      'basis': 'hourly',
      'effective_on': '2026-01-09',
    },
    'w2': {'signed': false, 'attachment_id': null},
    'started_on': '2026-01-09',
    'terminated_on': null,
    'role': {'id': 'r-tech', 'name': 'Technician'},
    'contract': {
      'id': 'c-sales',
      'name': 'Technician',
      'onboarding_id': 'ob-pat',
      'status': 'in_progress',
    },
  },
  {
    'id': 'u-rae',
    'name': 'Rae Moss',
    'is_active': false,
    'salary': null,
    'w2': {'signed': true, 'attachment_id': null},
    'started_on': '2024-02-02',
    'terminated_on': '2026-08-30',
    'role': {'id': 'r-tech', 'name': 'Technician'},
    'contract': null,
  },
];

const _me = {
  'id': 'u-me',
  'name': 'Morgan Kay',
  'email': 'morgan@example.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Office'},
  'permissions': ['hr.view', 'hr.payroll.view'],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Home Services',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
