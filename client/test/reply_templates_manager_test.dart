import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/reply_templates_manager.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Jose, 2026-09-30: a template manager inside Leads, one list per company.
  testWidgets('each company keeps its own list, and Save sends that list', (tester) async {
    Map<String, dynamic>? put;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'PUT') {
          put = jsonDecode(request.body) as Map<String, dynamic>;
          return http.Response(
            jsonEncode({
              'data': {
                'company': {'id': 'gd', 'name': 'A1 Garage Doors'},
                'templates': put!['templates'],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response(
          jsonEncode({
            'data': [
              {
                'company': {'id': 'ch', 'name': 'A-1 Chimney'},
                'templates': [
                  {'name': 'Inspection prices', 'text': 'Level 1 is \$99.'},
                ],
              },
              {
                'company': {'id': 'gd', 'name': 'A1 Garage Doors'},
                'templates': [
                  {'name': 'Free estimate', 'text': 'The visit is free.'},
                ],
              },
            ],
            'meta': {'may_edit': true},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(home: Scaffold(body: ReplyTemplatesManager(api: api))),
    );
    await tester.pumpAndSettle();

    expect(find.text('Inspection prices'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('templates-company-gd')));
    await tester.pumpAndSettle();
    expect(find.text('Inspection prices'), findsNothing);
    expect(find.text('Free estimate'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('templates-save')));
    await tester.pumpAndSettle();
    expect(put!['templates'], [
      {'name': 'Free estimate', 'text': 'The visit is free.'},
    ]);
  });
}
