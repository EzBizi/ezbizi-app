import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_feed.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('gap review P1: the updates feed lists every board, filters '
      'mentions and unread, marks all read, likes and opens a task', (
    tester,
  ) async {
    final asked = <(String, String, Map<String, String>)>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        asked.add((request.method, path, request.url.queryParameters));
        final body = switch (path) {
          '/task-updates' => {
            'data': [
              {
                'id': 'u1',
                'task_id': 't1',
                'task': 'Liner job',
                'board': 'Ohio PM',
                'body': 'Parts came',
                'author': {'name': 'Shoval Ohayon'},
                'created_at': '2026-11-24T10:00:00Z',
                'likes': 0,
                'liked': false,
              },
            ],
            'meta': {
              'boards': [
                {'id': 'b1', 'name': 'Ohio PM', 'count': 1},
                {'id': 'b2', 'name': 'DMV', 'count': 3},
              ],
            },
          },
          _ when path.endsWith('/bookmark') => {
            'data': {'bookmarked': true},
          },
          _ when path.endsWith('/like') => {
            'data': {'likes': 1, 'liked': true},
          },
          _ => {
            'data': {'read': 4},
          },
        };
        return http.Response(
          jsonEncode(body),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    String? opened;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: UpdatesFeedPanel(
            api: api,
            formats: DisplayFormats.fallback,
            onOpenTask: (id) => opened = id,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Liner job · Ohio PM'), findsOneWidget);
    expect(find.text('DMV · 3'), findsOneWidget);

    await tester.tap(find.byKey(const Key('feed-mentions')));
    await tester.pumpAndSettle();
    expect(asked.last.$3['filter'], 'mentions');
    await tester.tap(find.byKey(const Key('feed-unread')));
    await tester.pumpAndSettle();
    expect(asked.last.$3['unread'], '1');

    await tester.tap(find.byKey(const Key('feed-read-all')));
    await tester.pumpAndSettle();
    expect(
      asked.map((a) => '${a.$1} ${a.$2}'),
      contains('POST /task-updates/read-all'),
    );

    await tester.tap(find.byKey(const ValueKey('feed-like-u1')));
    await tester.pumpAndSettle();
    expect(find.text('Like · 1'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('feed-bookmark-u1')));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.bookmark), findsOneWidget);
    for (final tab in ['bookmarked', 'scheduled']) {
      await tester.tap(find.byKey(ValueKey('feed-$tab')));
      await tester.pumpAndSettle();
      expect(asked.last.$3['filter'], tab);
    }
    await tester.tap(find.byKey(const ValueKey('feed-open-u1')));
    expect(opened, 't1');
  });
}
