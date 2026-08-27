import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('shows a real loading state before the board directory arrives', (
    tester,
  ) async {
    final pending = Completer<http.Response>();
    await _pump(tester, (_) => pending.future, settle: false);

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    pending.complete(_json({'data': <Object>[]}));
  });

  testWidgets('an empty directory invites the first board', (tester) async {
    await _pump(tester, (_) async => _json({'data': <Object>[]}));

    expect(find.text('No task boards yet'), findsOneWidget);
    expect(find.text('New board'), findsOneWidget);
  });

  testWidgets('a refusal stays a refusal rather than looking empty', (
    tester,
  ) async {
    await _pump(
      tester,
      (_) async =>
          _json({'message': 'Tasks are not available for this view.'}, 403),
    );

    expect(find.text('This task board is unavailable.'), findsOneWidget);
    expect(find.text('Tasks are not available for this view.'), findsNothing);
    expect(find.text('No task boards yet'), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester,
  Future<http.Response> Function(http.Request) handler, {
  bool settle = true,
}) async {
  tester.view.physicalSize = const Size(1200, 800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TasksScreen(api: api, session: Session(api)),
      ),
    ),
  );
  settle ? await tester.pumpAndSettle() : await tester.pump();
}

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );
