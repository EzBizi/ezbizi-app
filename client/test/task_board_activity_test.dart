import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_activity.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('gap review P1: the board Activity Log lists changes and opens '
      'the task of a row', (tester) async {
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url.queryParameters);
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'a1',
                'at': '2026-11-24T10:00:00Z',
                'who': 'Shoval Ohayon',
                'task_id': 't1',
                'task': 'Liner job',
                'label': 'Status',
                'from': 'Working on it',
                'to': 'Done',
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    String? opened;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: BoardActivityPanel(
            api: api,
            board: const {
              'id': 'b-1',
              'name': 'Ohio PM',
              'groups': [],
              'columns': [],
            },
            formats: DisplayFormats.fallback,
            people: const {'u-2': 'Shoval Ohayon'},
            onOpenTask: (id) => opened = id,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked.first['days'], '7', reason: 'opens on the last week');
    expect(find.textContaining('Status: Working on it → Done'), findsOneWidget);
    expect(find.textContaining('Liner job'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('board-activity-a1')));
    expect(opened, 't1');
  });
}
