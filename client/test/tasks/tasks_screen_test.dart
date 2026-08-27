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
  testWidgets('opens an inline exact-view board pane instead of a modal', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode(<String, Object?>{'data': <Object?>[]}),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TasksScreen(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('New board'));
    await tester.pump();

    expect(find.text('New board'), findsOneWidget);
    expect(
      find.text('This board will be filed in the view you are looking at.'),
      findsOneWidget,
    );
    expect(find.byType(Dialog), findsNothing);
  });
}
