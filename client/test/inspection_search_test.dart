import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/new_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-26, on New Inspection: "as simple as possible, sort of like
/// the scheduling one where you have the search bar in the middle, and it
/// autofills / suggests as you type".
void main() {
  testWidgets('the bar is centred at 1600x1000 with its one-line hint', (
    tester,
  ) async {
    await _pump(tester, const Size(1600, 1000));

    final bar = tester.getRect(find.byKey(const Key('tour-inspection-search')));
    expect(bar.center.dx, closeTo(800, 2));
    expect(bar.center.dy, closeTo(500, 4));
    expect(bar.width, closeTo(560, 1));
    expect(
      find.text("Type a client's name, a phone or a job number"),
      findsOneWidget,
    );
  });

  testWidgets('typing suggests clients and jobs, kind, address and branch', (
    tester,
  ) async {
    final harness = await _pump(tester, const Size(1600, 1000));

    await tester.enterText(find.byType(TextField), 'Mar');
    await tester.pump(const Duration(milliseconds: 100));
    expect(harness.queries, isEmpty, reason: 'debounced, not per keystroke');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();

    expect(harness.queries, ['Mar']);
    expect(find.text('Client'), findsOneWidget);
    expect(find.text('Job'), findsOneWidget);
    expect(find.text('Marta Lopez'), findsNWidgets(2));
    expect(
      find.text('14 Maple St, Trumbull CT  ·  A-1 Chimney · Connecticut'),
      findsOneWidget,
    );
    expect(
      find.text(
        'J-1042  ·  14 Maple St, Trumbull CT  ·  A-1 Chimney · Connecticut',
      ),
      findsOneWidget,
    );
  });

  testWidgets('Enter picks the first and starts the same way as before', (
    tester,
  ) async {
    final harness = await _pump(tester, const Size(1600, 1000));

    await tester.enterText(find.byType(TextField), 'Mar');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(harness.startable, [
      {'customer_id': 'c-1'},
    ]);
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();
    expect(harness.started, [
      {
        'customer_id': 'c-1',
        'job_id': null,
        'inspection_definition_id': 'def-1',
      },
    ]);
  });

  testWidgets('down then Enter picks the job; Esc closes the list', (
    tester,
  ) async {
    final harness = await _pump(tester, const Size(1600, 1000));

    await tester.enterText(find.byType(TextField), 'Mar');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pump();
    expect(find.text('Job'), findsNothing);

    await tester.enterText(find.byType(TextField), 'Mart');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
    await tester.pump();
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(harness.startable, [
      {'customer_id': 'c-1', 'job_id': 'j-1'},
    ]);
  });

  testWidgets('on a phone the bar sits in the top third, no overflow', (
    tester,
  ) async {
    await _pump(tester, const Size(390, 844));

    final bar = tester.getRect(find.byKey(const Key('tour-inspection-search')));
    expect(bar.center.dy, closeTo(844 / 3, 4));
    expect(bar.left, greaterThanOrEqualTo(16));

    await tester.enterText(find.byType(TextField), 'Mar');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(find.text('Job'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

class _Harness {
  final queries = <String>[];
  final startable = <Map<String, String>>[];
  final started = <Map<String, dynamic>>[];
}

Future<_Harness> _pump(WidgetTester tester, Size size) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final harness = _Harness();

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return http.Response(jsonEncode(_me), 200, headers: _json);
      }
      if (path.endsWith('/inspections/search')) {
        harness.queries.add(request.url.queryParameters['q'] ?? '');
        return http.Response(_hits, 200, headers: _json);
      }
      if (path.endsWith('/inspections/startable')) {
        harness.startable.add(request.url.queryParameters);
        return http.Response(_picker, 200, headers: _json);
      }
      if (path.endsWith('/inspections/runs') && request.method == 'POST') {
        harness.started.add(jsonDecode(request.body) as Map<String, dynamic>);
        return http.Response(_run, 201, headers: _json);
      }
      return http.Response('{"message":"not stubbed"}', 500, headers: _json);
    }),
  );
  final session = Session(api);
  await session.refresh();

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: NewInspectionTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return harness;
}

const _json = {'content-type': 'application/json'};

const _me = {
  'data': {
    'id': 'u-1',
    'name': 'Jose',
    'email': 'jose@example.com',
    'organization': {
      'id': 'org-1',
      'name': 'EzBizi',
      'franchise_label': 'Franchise',
      'is_read_only': false,
    },
    'scope': {'type': 'organization', 'id': null},
    'companies': [
      {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
    ],
    'franchises': [
      {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
    ],
  },
};

const _hits = '''
{"data":[
 {"type":"customer","id":"c-1","label":"Marta Lopez","customer_id":"c-1","job_id":null,"customer_name":"Marta Lopez","job_number":null,"address":"14 Maple St, Trumbull CT","franchise":{"id":"fr-a1","name":"Main"}},
 {"type":"job","id":"j-1","label":"J-1042 · Marta Lopez","customer_id":"c-1","job_id":"j-1","customer_name":"Marta Lopez","job_number":"J-1042","address":"14 Maple St, Trumbull CT","franchise_id":"fr-a1"}
]}
''';

const _picker = '''
{"data":[{"id":"def-1","name":"Level 1","draft_id":null}]}
''';

const _run = '''
{"data":{"id":"run-1","answers":{},"screens":[{"title":"One","fields":[]}]}}
''';
