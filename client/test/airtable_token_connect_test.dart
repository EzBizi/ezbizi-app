import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/airtable_token_connect.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-20: one token, then tick the bases it reaches; nobody types
/// a base ID.
void main() {
  testWidgets('a token lists its bases, all ticked, and saves the ticked set', (
    tester,
  ) async {
    final sent = <http.Request>[];
    await _pump(tester, sent);

    await tester.enterText(
      find.byKey(const ValueKey('airtable-token')),
      'pat1',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('airtable-find')));
    await tester.pumpAndSettle();

    expect(find.text('Books'), findsOneWidget);
    expect(find.text('3 tables · Can edit'), findsOneWidget);
    expect(find.text('1 table · Read only'), findsOneWidget);
    expect(find.text('Use 2 bases'), findsOneWidget);
    expect(find.text('Look again'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('airtable-base-app2')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('airtable-save')));
    await tester.pumpAndSettle();

    final save = sent.last;
    expect(save.url.path, '/api/v1/social/airtable/connect');
    expect(jsonDecode(save.body), {
      'api_token': 'pat1',
      'base_ids': ['app1'],
    });
  });

  testWidgets('a token that reaches nothing says so', (tester) async {
    await _pump(tester, [], bases: const []);
    await tester.enterText(find.byKey(const ValueKey('airtable-token')), 'p');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('airtable-find')));
    await tester.pumpAndSettle();
    expect(find.textContaining('This token reaches no bases'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<http.Request> sent, {
  List<Map<String, Object?>> bases = const [
    {'id': 'app1', 'name': 'Books', 'permission': 'create', 'tables': 3},
    {'id': 'app2', 'name': 'Payroll', 'permission': 'read', 'tables': 1},
  ],
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      sent.add(request);
      final body = request.url.path.endsWith('/bases')
          ? {'data': bases}
          : {
              'data': {'message': 'Saved.'},
            };
      return http.Response(
        jsonEncode(body),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }),
  );
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: AirtableTokenConnect(api: api, onChanged: () {}),
        ),
      ),
    ),
  );
}
