import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/reports_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a job-shaped miss says Job not found', (tester) async {
    await _pump(
      tester,
      list: '{"data":[],"message":"Job not found"}',
      search: 'J-1042',
    );

    expect(find.text('Job not found'), findsOneWidget);
    expect(find.text('Client not found'), findsNothing);
  });

  testWidgets('archived reports stay hidden until the filter is on', (
    tester,
  ) async {
    var archived = false;
    await _pump(
      tester,
      onList: (uri) {
        archived = uri.queryParameters['archived'] == '1';
        if (archived) {
          return _hit(archived: true);
        }
        return '{"data":[]}';
      },
    );

    expect(find.text('Level 1'), findsNothing);

    await tester.tap(find.widgetWithText(FilterChip, 'Archived'));
    await tester.pumpAndSettle();

    expect(archived, isTrue);
    expect(find.text('Level 1'), findsWidgets);
  });

  testWidgets('the report is the stamped look, not a live template', (
    tester,
  ) async {
    await _pump(tester, list: _hit(), show: _show);

    expect(find.text('Level 1'), findsWidgets);
    expect(find.text('Marta Ruiz'), findsWidgets);
    expect(find.text('Crown'), findsWidgets);
    expect(find.text('Sound'), findsWidgets);
    expect(find.text('immutable'), findsNothing);
    expect(find.textContaining('InspectionReport'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
  });

  // Phone audit 2026-11-04, Section 3 #1: a report imported from A1 carries a
  // numeric id and photo answers as one string or a keyed object, and a bare
  // `as List?` crashed the whole tab to a grey box.
  testWidgets('an A1-shaped report opens instead of crashing', (tester) async {
    await _pump(
      tester,
      width: 390,
      settle: false,
      list: '{"data":[{"id":7,"definition_name":"Level 2","customer_name":"Marta Ruiz"}]}',
      show: '''
{"data":{"id":7,"definition_name":"Level 2","customer_name":"Marta Ruiz",
 "answers":{"crown":"Sound","front":"att-1","back":{"0":"att-2"}},
 "screens":[{"name":"Roof","fields":[
   {"key":"crown","label":"Crown","type":"text"},
   {"key":"front","label":"Front","type":"photo"},
   {"key":"back","label":"Back","type":"photo"}]}]}}
''',
    );

    expect(tester.takeException(), isNull);
    expect(find.text('Crown'), findsWidgets);
    expect(find.text('Sound'), findsWidgets);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  String? list,
  String? show,
  String? search,
  String Function(Uri uri)? onList,
  double width = 1400,
  bool settle = true,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/inspections/reports')) {
        final body = onList?.call(request.url) ?? list ?? '{"data":[]}';
        return http.Response(body, 200, headers: _json);
      }
      if (path.contains('/inspections/reports/')) {
        return http.Response(show ?? _show, 200, headers: _json);
      }
      return http.Response('{"message":"not stubbed"}', 500, headers: _json);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ReportsTab(api: api, session: Session(api)),
      ),
    ),
  );
  // A photo answer's spinner turns until its bytes arrive, which no stub sends.
  if (settle) {
    await tester.pumpAndSettle();
  } else {
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
  }

  if (search != null) {
    await tester.enterText(find.byType(TextField), search);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
  }
}

const _json = {'content-type': 'application/json'};

String _hit({bool archived = false}) => '''
{
  "data": [
    {
      "id": "rep-1",
      "definition_name": "Level 1",
      "layout_preset": "step_strip",
      "customer_name": "Marta Ruiz",
      "job_number": null,
      "submitted_by_name": "Jose Silva",
      "submitted_at": "2026-08-18T14:00:00Z",
      "archived": $archived
    }
  ]
}
''';

const _show = '''
{
  "data": {
    "id": "rep-1",
    "definition_name": "Level 1",
    "layout_preset": "step_strip",
    "customer_name": "Marta Ruiz",
    "job_number": null,
    "submitted_by_name": "Jose Silva",
    "submitted_at": "2026-08-18T14:00:00Z",
    "archived": false,
    "answers": {"crown": "Sound"},
    "screens": [
      {
        "name": "Roof",
        "fields": [{"key": "crown", "label": "Crown", "type": "text"}]
      }
    ]
  }
}
''';
