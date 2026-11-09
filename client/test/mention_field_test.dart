import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/mention_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('typing @ lists who can see the record and a tap mentions them', (
    tester,
  ) async {
    final asked = <Uri>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url);
        return http.Response(
          jsonEncode({
            'data': [
              {'id': 'u-1', 'name': 'Ana Ruiz'},
              {'id': 'u-2', 'name': 'Bo Lee'},
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final text = TextEditingController();
    final mentions = MentionController();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MentionField(
            api: api,
            type: 'job',
            id: 'j-1',
            controller: text,
            mentions: mentions,
            child: TextField(key: const Key('box'), controller: text),
          ),
        ),
      ),
    );

    await tester.enterText(find.byKey(const Key('box')), 'Hi @an');
    await tester.pumpAndSettle();

    expect(asked.single.queryParameters, {'type': 'job', 'id': 'j-1'});
    expect(find.byKey(const ValueKey('mention-u-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('mention-u-2')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('mention-u-1')));
    await tester.pumpAndSettle();

    expect(text.text, 'Hi @Ana Ruiz ');
    expect(mentions.ids(text.text), ['u-1']);
    expect(find.byKey(const Key('mention-list')), findsNothing);

    // Deleting the name un-mentions them.
    expect(mentions.ids('Hi'), isEmpty);
  });

  testWidgets('the unread badge shows a count only above zero', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Column(
          children: [
            unreadNotesBadge(const Icon(Icons.note), 3),
            unreadNotesBadge(const Icon(Icons.note), 0),
          ],
        ),
      ),
    );

    expect(find.text('3'), findsOneWidget);
    expect(find.text('0'), findsNothing);
  });
}
