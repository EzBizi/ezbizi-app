import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/marketing/instagram_comments_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  final sent = <http.Request>[];

  Future<void> pump(WidgetTester tester, {required bool canAnswer}) async {
    sent.clear();
    final api = ApiClient(
      client: MockClient((request) async {
        sent.add(request);
        if (request.method == 'POST') {
          return http.Response(
            jsonEncode({
              'data': {'id': 'r-2'},
            }),
            200,
          );
        }
        return http.Response(
          jsonEncode({
            'data': {
              'account': {'id': 'ig-9', 'username': 'ezbizi'},
              'posts': [
                {
                  'id': 'm-1',
                  'caption': 'Chimney season',
                  'comments': [
                    {
                      'id': 'c-1',
                      'text': 'Do you serve Tampa?',
                      'username': 'homeowner',
                      'hidden': false,
                      'replies': <Object>[],
                    },
                  ],
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: InstagramCommentsPanel(
              api: api,
              connectionId: 'conn-1',
              canAnswer: canAnswer,
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('comments are listed, and a reply goes to that comment', (
    tester,
  ) async {
    await pump(tester, canAnswer: true);

    expect(find.text('Do you serve Tampa?'), findsOneWidget);

    await tester.tap(find.text('Reply'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Yes, all of Tampa Bay.');
    await tester.tap(find.text('Send'));
    await tester.pumpAndSettle();

    final post = sent.firstWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/instagram-comments/c-1/replies'));
    expect(jsonDecode(post.body)['message'], 'Yes, all of Tampa Bay.');
  });

  testWidgets('without marketing.edit the comments are read-only', (
    tester,
  ) async {
    await pump(tester, canAnswer: false);

    expect(find.text('Do you serve Tampa?'), findsOneWidget);
    expect(find.text('Reply'), findsNothing);
    expect(find.text('Hide'), findsNothing);
  });
}
