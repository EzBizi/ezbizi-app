import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_run_view.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a wide screen draws the rows as a table', (tester) async {
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.byType(ListTable), findsOneWidget);
    expect(find.text('Sweep'), findsOneWidget);
    expect(find.text('TITLE'), findsOneWidget);
  });

  testWidgets('a narrow screen leads with the count, then cards', (
    tester,
  ) async {
    await _pumpRun(tester, width: 375, run: _oneRow);

    expect(find.byType(ListTable), findsNothing);
    expect(find.text('1 row'), findsOneWidget);
    expect(find.text('Sweep'), findsOneWidget);
  });

  testWidgets('the count is plural when it should be', (tester) async {
    await _pumpRun(tester, width: 375, run: _twoRows);

    expect(find.text('2 rows'), findsOneWidget);
  });

  testWidgets('a dotted column key is read as a key, not as a path', (
    tester,
  ) async {
    // Rows are {'jobs.title': 'Sweep'} — flat, with dots IN the key. A reader
    // that splits on the dot finds nothing and draws a blank table.
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.text('Sweep'), findsOneWidget);
  });

  testWidgets('a run that matched nothing does not read as a fault', (
    tester,
  ) async {
    await _pumpRun(tester, width: 1200, run: _noRows);

    expect(find.text('This report found nothing.'), findsOneWidget);
    expect(
      find.text(
        'No rows matched, for the records you can see. Somebody with a wider '
        'reach may get results from the same report.',
      ),
      findsOneWidget,
    );
    // The opposite fact, which belongs to the LIBRARY and not to a run.
    expect(find.textContaining('No reports built yet'), findsNothing);
  });

  testWidgets('the empty-results sentence is shown on a phone too', (
    tester,
  ) async {
    await _pumpRun(tester, width: 375, run: _noRows);

    expect(find.text('This report found nothing.'), findsOneWidget);
  });

  testWidgets('the cap line is absent when the cap did not bite', (
    tester,
  ) async {
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.textContaining('5,000'), findsNothing);
  });

  testWidgets('the cap line appears when the cap did bite', (tester) async {
    await _pumpRun(tester, width: 1200, run: _capped);

    expect(
      find.text('Showing the first 5,000 rows. This report matched more.'),
      findsOneWidget,
    );
  });

  testWidgets('the cap line is muted, never the error colour', (tester) async {
    await _pumpRun(tester, width: 1200, run: _capped);

    final context = tester.element(find.byType(ReportRunView));
    final scheme = Theme.of(context).colorScheme;
    final line = tester.widget<Text>(
      find.text('Showing the first 5,000 rows. This report matched more.'),
    );

    // Nothing failed: the reader did nothing wrong and the report worked.
    // Dressing an advisory as a failure spends the credibility of the screens
    // where red does mean stop.
    expect(line.style?.color, isNot(scheme.error));
  });

  testWidgets('an empty run shows no cap line even when capped is true', (
    tester,
  ) async {
    // "The first 5,000" of nothing is not a truthful sentence, and a line
    // under an empty table reads as a fault.
    await _pumpRun(tester, width: 1200, run: _cappedButEmpty);

    expect(find.textContaining('5,000'), findsNothing);
  });

  testWidgets('a refusal is the server sentence, not an empty result', (
    tester,
  ) async {
    await _pumpRun(
      tester,
      width: 1200,
      status: 403,
      body: {'message': 'You do not have permission to see this report.'},
    );

    expect(find.textContaining('do not have permission'), findsOneWidget);
    expect(find.text('This report found nothing.'), findsNothing);
  });
}

Future<void> _pumpRun(
  WidgetTester tester, {
  required double width,
  Map<String, dynamic>? run,
  int status = 200,
  Map<String, dynamic>? body,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.contains('/reports/') &&
          request.url.path.endsWith('/run')) {
        return _json(body ?? {'data': run}, status);
      }
      return _json({'message': 'Nothing stubbed.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ReportRunView(api: api, reportId: 'rep-1', name: 'Open jobs'),
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

const _columns = [
  {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
];

const _oneRow = {
  'columns': _columns,
  'rows': [
    {'jobs.title': 'Sweep'},
  ],
  'capped': false,
  'count': 1,
  'cap': 5000,
};

const _twoRows = {
  'columns': _columns,
  'rows': [
    {'jobs.title': 'Sweep'},
    {'jobs.title': 'Cap install'},
  ],
  'capped': false,
  'count': 2,
  'cap': 5000,
};

const _noRows = {
  'columns': _columns,
  'rows': <Map<String, dynamic>>[],
  'capped': false,
  'count': 0,
  'cap': 5000,
};

const _capped = {
  'columns': _columns,
  'rows': [
    {'jobs.title': 'Sweep'},
  ],
  'capped': true,
  'count': 5000,
  'cap': 5000,
};

const _cappedButEmpty = {
  'columns': _columns,
  'rows': <Map<String, dynamic>>[],
  'capped': true,
  'count': 0,
  'cap': 5000,
};
