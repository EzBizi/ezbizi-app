import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials/financial_sources.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Financials → Settings → Airtable sources as a role × branch matrix (UI
// queue 124). Every name here is invented.

String _ago(Duration d) => DateTime.now().toUtc().subtract(d).toIso8601String();

Map<String, dynamic> _sources({bool neverCopied = false}) => {
  'data': [
    {
      'id': 's-ref',
      'kind': 'mirror',
      'last_copied_at': neverCopied ? null : _ago(const Duration(minutes: 4)),
      'rows': 412,
      'base_id': 'appNorth',
      'table_id': 'tblRef',
      'role': 'refunds',
      'company_id': null,
      'franchise_id': null,
      'place': null,
      'field_map': {'amount': 'Amount'},
      'markers': <String, dynamic>{},
      'valid_from': null,
      'valid_until': null,
    },
    {
      'id': 's-chk-sp',
      'kind': 'mirror',
      'last_copied_at': _ago(const Duration(days: 3)),
      'rows': 38,
      'base_id': 'appNorth',
      'table_id': 'tblChkSp',
      'role': 'check_returns',
      'company_id': 'co-north',
      'franchise_id': 'fr-sp',
      'place': 'Springfield',
      'field_map': <String, dynamic>{},
      'markers': <String, dynamic>{},
      'valid_from': '2026-01-01',
      'valid_until': null,
    },
    {
      'id': 's-chk-rv',
      'kind': 'native',
      'last_copied_at': null,
      'rows': 1,
      'base_id': 'appNorth',
      'table_id': 'tblChkRv',
      'role': 'check_returns',
      'company_id': 'co-north',
      'franchise_id': 'fr-rv',
      'place': 'Riverton',
      'field_map': <String, dynamic>{},
      'markers': <String, dynamic>{},
      'valid_from': null,
      'valid_until': null,
    },
  ],
  'roles': {
    'refunds': 'Refunds',
    'check_returns': 'Returned checks',
    'payroll': 'Technician payroll',
  },
  'field_keys': ['amount', 'date'],
  'marker_keys': ['summary', 'job', 'skip'],
  'airtable_tables': [
    {
      'base_id': 'appNorth',
      'table_id': 'tblRef',
      'table_name': 'Refund log',
      'rows': 412,
    },
    {
      'base_id': 'appNorth',
      'table_id': 'tblChkSp',
      'table_name': 'Bounced Springfield',
      'rows': 38,
    },
    {
      'base_id': 'appNorth',
      'table_id': 'tblChkRv',
      'table_name': 'Bounced Riverton',
      'rows': 1,
    },
  ],
};

Me _me({bool canEdit = true}) => Me.fromJson({
  'id': 'u-1',
  'name': 'Pat Example',
  'email': 'pat@example.com',
  'organization': {
    'id': 'org-1',
    'name': 'Northwind Home',
    'franchise_label': 'Branch',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Northwind Home',
    'path': ['Northwind Home'],
  },
  'companies': [
    {'id': 'co-north', 'name': 'Northwind', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-sp', 'name': 'Springfield', 'company_id': 'co-north'},
    {'id': 'fr-rv', 'name': 'Riverton', 'company_id': 'co-north'},
    {'id': 'fr-oa', 'name': 'Oakdale', 'company_id': 'co-north'},
  ],
  'permissions': ['financials.view', if (canEdit) 'financials.edit'],
});

Future<List<http.Request>> _pump(
  WidgetTester tester, {
  double width = 1400,
  bool canEdit = true,
  bool copyNowRefused = false,
  bool neverCopied = false,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final seen = <http.Request>[];
  http.Response json(Object? body, [int status = 200]) => http.Response(
    body == null ? '' : jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );
  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request);
      final path = request.url.path;
      if (path.endsWith('/financials/sources') && request.method == 'GET') {
        return json(_sources(neverCopied: neverCopied));
      }
      if (path.endsWith('/financials/sources/copy-now')) {
        return copyNowRefused
            ? json({
                'message': 'The given data was invalid.',
                'errors': {
                  'copy': [
                    'A copy started less than two minutes ago. Give it a moment.',
                  ],
                },
              }, 422)
            : json({
                'data': {'message': 'Copying from Airtable now. Refresh soon.'},
              }, 202);
      }
      if (path.contains('/financials/sources/') && request.method == 'DELETE') {
        return json(null, 204);
      }
      if (path.contains('/financials/sources')) {
        return json({'data': (_sources()['data']! as List)[1]});
      }
      return json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FinancialSourcesSettings(
          api: api,
          me: _me(canEdit: canEdit),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return seen;
}

Finder _cell(String role, String column) =>
    find.byKey(ValueKey('source-cell-$role-$column'));

void main() {
  testWidgets('rows are the roles in server order, columns the branches', (
    tester,
  ) async {
    await _pump(tester);
    final roles = [
      for (final label in ['Refunds', 'Returned checks', 'Technician payroll'])
        tester.getTopLeft(find.text(label)).dy,
    ];
    expect(roles, orderedEquals([...roles]..sort()));

    final headers = [
      for (final label in [
        'ALL BRANCHES',
        'NORTHWIND · OAKDALE',
        'NORTHWIND · RIVERTON',
        'NORTHWIND · SPRINGFIELD',
      ])
        tester.getTopLeft(find.text(label)).dx,
    ];
    expect(headers, orderedEquals([...headers]..sort()));

    expect(
      find.descendant(
        of: _cell('check_returns', 'fr-sp'),
        matching: find.text('Bounced Springfield'),
      ),
      findsOneWidget,
    );
    expect(
      find.text('38 rows · last copy 3 d ago · from 2026-01-01'),
      findsOneWidget,
    );
    expect(find.text('412 rows · last copy 4 min ago'), findsOneWidget);
    // A native table has no copy to date.
    expect(find.text('1 row'), findsOneWidget);
    expect(find.text('3 mapped · 3 branches · last copy 4 min ago'), findsOne);
    expect(find.byKey(const ValueKey('tour-financials-sources')), findsOne);
  });

  testWidgets('a branch with no table of its own reads "uses All"; an empty '
      'cell offers Add', (tester) async {
    await _pump(tester);
    for (final branch in ['fr-sp', 'fr-rv', 'fr-oa']) {
      expect(
        find.descendant(
          of: _cell('refunds', branch),
          matching: find.text('uses All'),
        ),
        findsOneWidget,
      );
    }
    // Returned checks: nothing for All or Oakdale; payroll: nothing anywhere.
    expect(find.text('— · Add'), findsNWidgets(6));
    expect(
      find.descendant(
        of: _cell('check_returns', 'fr-oa'),
        matching: find.text('— · Add'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('without Financials edit an empty cell offers nothing', (
    tester,
  ) async {
    await _pump(tester, canEdit: false);
    expect(find.text('— · Add'), findsNothing);
    expect(find.text('—'), findsNWidgets(6));
    expect(find.text('Add source'), findsNothing);
  });

  testWidgets('a cell opens its source in a side panel; Save sends the patch', (
    tester,
  ) async {
    final seen = await _pump(tester);
    await tester.tap(find.text('Bounced Springfield'));
    await tester.pumpAndSettle();
    expect(find.text('Edit source'), findsOneWidget);
    expect(find.text('Base appNorth · Table tblChkSp'), findsOneWidget);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    final patch = seen.singleWhere((r) => r.method == 'PATCH');
    expect(patch.url.path, endsWith('/financials/sources/s-chk-sp'));
    final body = jsonDecode(patch.body) as Map;
    expect(body['role'], 'check_returns');
    expect(body['franchise_id'], 'fr-sp');
    expect(body['company_id'], 'co-north');
    expect(body['base_id'], 'appNorth');
    expect(body['table_id'], 'tblChkSp');
    expect(body['valid_from'], '2026-01-01');
    expect(find.text('Edit source'), findsNothing);
  });

  testWidgets('Add from an empty cell starts on its role and branch', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(
      find.descendant(
        of: _cell('check_returns', 'fr-oa'),
        matching: find.text('— · Add'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Add source'), findsNWidgets(2));
    final pickers = tester
        .widgetList<SearchPickerField<String>>(
          find.byType(SearchPickerField<String>),
        )
        .toList();
    expect(
      pickers.map((p) => p.value),
      containsAll(['check_returns', 'fr-oa']),
    );
  });

  testWidgets('Remove asks once before it deletes', (tester) async {
    final seen = await _pump(tester);
    await tester.tap(find.text('Bounced Riverton'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove'));
    await tester.pumpAndSettle();
    expect(seen.where((r) => r.method == 'DELETE'), isEmpty);
    expect(find.textContaining('Remove this source?'), findsOneWidget);
    await tester.tap(find.text('Remove for good'));
    await tester.pumpAndSettle();
    final delete = seen.singleWhere((r) => r.method == 'DELETE');
    expect(delete.url.path, endsWith('/financials/sources/s-chk-rv'));
  });

  testWidgets('role, branch and search filters narrow the matrix', (
    tester,
  ) async {
    await _pump(tester);
    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('sources-role-filter')),
        )
        .onChanged!('check_returns');
    await tester.pumpAndSettle();
    expect(find.text('Refunds'), findsNothing);
    expect(find.text('Technician payroll'), findsNothing);
    expect(find.text('Returned checks'), findsWidgets);

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('sources-role-filter')),
        )
        .onChanged!(null);
    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('sources-branch-filter')),
        )
        .onChanged!('fr-rv');
    await tester.pumpAndSettle();
    expect(find.text('ALL BRANCHES'), findsOneWidget);
    expect(find.text('NORTHWIND · RIVERTON'), findsOneWidget);
    expect(find.text('NORTHWIND · SPRINGFIELD'), findsNothing);
    expect(find.text('Bounced Springfield'), findsNothing);

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('sources-branch-filter')),
        )
        .onChanged!(null);
    await tester.enterText(
      find.byKey(const ValueKey('sources-search')),
      'refund',
    );
    await tester.pumpAndSettle();
    expect(find.text('Refund log'), findsOneWidget);
    expect(find.text('Returned checks'), findsNothing);
    expect(find.text('Technician payroll'), findsNothing);
  });

  testWidgets('narrow: a branch picker on top and that branch as a list', (
    tester,
  ) async {
    await _pump(tester, width: 600);
    expect(find.text('ALL BRANCHES'), findsNothing);
    expect(find.byKey(const ValueKey('sources-role-filter')), findsNothing);
    // All branches first: its own tables, and Add where it has none.
    expect(find.text('Refund log'), findsOneWidget);
    expect(find.text('— · Add'), findsNWidgets(2));

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('sources-narrow-branch')),
        )
        .onChanged!('fr-sp');
    await tester.pumpAndSettle();
    expect(find.text('Refund log'), findsNothing);
    expect(find.text('uses All'), findsOneWidget);
    expect(find.text('Bounced Springfield'), findsOneWidget);
    expect(find.text('— · Add'), findsOneWidget);
    expect(find.text('Technician payroll'), findsOneWidget);
  });

  testWidgets('each source wears Mirror or Native', (tester) async {
    await _pump(tester);
    expect(
      find.descendant(
        of: _cell('check_returns', 'fr-rv'),
        matching: find.text('Native'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: _cell('refunds', 'all'),
        matching: find.text('Mirror'),
      ),
      findsOneWidget,
    );
    expect(find.text('Mirror'), findsNWidgets(2));
    expect(find.text('Native'), findsOneWidget);
  });

  testWidgets('a copy older than a day is red; a fresh one is not', (
    tester,
  ) async {
    await _pump(tester);
    final error = Theme.of(
      tester.element(find.byType(FinancialSourcesSettings)),
    ).colorScheme.error;
    Color? colour(String text) =>
        tester.widget<Text>(find.text(text)).style?.color;
    expect(colour('38 rows · last copy 3 d ago · from 2026-01-01'), error);
    expect(colour('412 rows · last copy 4 min ago'), isNot(error));
    expect(colour('1 row'), isNot(error));
  });

  testWidgets('a mirror never copied says so', (tester) async {
    await _pump(tester, neverCopied: true);
    expect(find.text('412 rows · never copied'), findsOneWidget);
  });

  testWidgets('Copy now shows the server sentence and reads the list again '
      'a minute later', (tester) async {
    final seen = await _pump(tester);
    int gets() => seen.where((r) => r.method == 'GET').length;
    final before = gets();
    await tester.tap(find.byKey(const ValueKey('sources-copy-now')));
    await tester.pump();
    await tester.pump();
    expect(
      seen.where((r) => r.url.path.endsWith('/financials/sources/copy-now')),
      hasLength(1),
    );
    expect(
      find.text('Copying from Airtable now. Refresh soon.'),
      findsOneWidget,
    );
    await tester.pump(const Duration(seconds: 59));
    expect(gets(), before);
    await tester.pump(const Duration(seconds: 2));
    await tester.pump();
    expect(gets(), before + 1);
  });

  testWidgets('a refused Copy now shows why, in place', (tester) async {
    await _pump(tester, copyNowRefused: true);
    await tester.tap(find.byKey(const ValueKey('sources-copy-now')));
    await tester.pumpAndSettle();
    final note = find.text(
      'A copy started less than two minutes ago. Give it a moment.',
    );
    expect(note, findsOneWidget);
    expect(
      tester.widget<Text>(note).style?.color,
      Theme.of(tester.element(note)).colorScheme.error,
    );
    // Nothing is read again after a refusal.
    await tester.pump(const Duration(seconds: 61));
  });

  testWidgets('Copy now in the side panel', (tester) async {
    final seen = await _pump(tester);
    await tester.tap(find.text('Bounced Springfield'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('source-panel-copy-now')));
    await tester.pump();
    await tester.pump();
    expect(
      seen.where((r) => r.url.path.endsWith('/financials/sources/copy-now')),
      hasLength(1),
    );
    expect(
      find.text('Copying from Airtable now. Refresh soon.'),
      findsNWidgets(2),
    );
    // Let the refetch timer fire so none is left pending.
    await tester.pump(const Duration(seconds: 61));
  });

  testWidgets('without Financials edit Copy now is greyed and says why', (
    tester,
  ) async {
    await _pump(tester, canEdit: false);
    final button = tester.widget<ButtonStyleButton>(
      find.byKey(const ValueKey('sources-copy-now')),
    );
    expect(button.onPressed, isNull);
    expect(find.text('Copy now needs Financials edit.'), findsOneWidget);
  });
}
