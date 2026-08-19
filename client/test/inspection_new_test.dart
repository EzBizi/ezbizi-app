import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/new_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a job-shaped miss says Job not found', (tester) async {
    await _pump(tester, searchBody: '{"data":[],"message":"Job not found"}');

    await tester.enterText(find.byType(TextField), 'J-1042');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(find.text('Job not found'), findsOneWidget);
    expect(find.text('Client not found'), findsNothing);
  });

  testWidgets('Next names Crown when it is required and empty', (tester) async {
    await _pump(tester, startBody: _run);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Submit'));
    await tester.pump();

    expect(
      find.text('Crown needs an answer before you can continue.'),
      findsOneWidget,
    );
  });

  testWidgets('resume vs start again is a panel, not a modal', (tester) async {
    await _pump(tester, startStatus: 409, startBody: _resume);

    await tester.tap(find.text('14 Maple'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Level 1'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('inspection-resume-panel')), findsOneWidget);
    expect(find.text('Resume'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  String? searchBody,
  String startBody = _run,
  int startStatus = 201,
}) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/inspections/search')) {
        return http.Response(
          searchBody ?? _hits,
          200,
          headers: _json,
        );
      }
      if (path.endsWith('/inspections/startable')) {
        return http.Response(_picker, 200, headers: _json);
      }
      if (path.endsWith('/inspections/runs') && request.method == 'POST') {
        return http.Response(startBody, startStatus, headers: _json);
      }
      return http.Response('{"message":"not stubbed"}', 500, headers: _json);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: NewInspectionTab(api: api, session: Session(api)),
      ),
    ),
  );
  await tester.pumpAndSettle();

  if (searchBody == null) {
    await tester.enterText(find.byType(TextField), 'Maple');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
  }
}

const _json = {'content-type': 'application/json'};

const _hits = '''
{"data":[{"type":"customer","id":"c-1","label":"14 Maple","customer_id":"c-1","job_id":null,"customer_name":"14 Maple"}]}
''';

const _picker = '''
{"data":[{"id":"def-1","name":"Level 1","draft_id":null}]}
''';

const _run = '''
{
  "data": {
    "id": "run-1",
    "definition_name": "Level 1",
    "customer_name": "14 Maple",
    "layout_preset": "step_strip",
    "answers": {},
    "screens": [
      {
        "name": "Roof",
        "fields": [
          {"key": "crown", "label": "Crown", "type": "text", "required": true}
        ]
      }
    ]
  }
}
''';

const _resume = '''
{
  "data": {
    "choice": "resume",
    "run": {
      "id": "run-1",
      "definition_name": "Level 1",
      "answers": {},
      "screens": []
    }
  }
}
''';
