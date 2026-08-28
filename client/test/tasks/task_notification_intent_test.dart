import 'dart:convert';

import 'package:ezbizi_client/core/address.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/notification_bell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets(
    'a foreground Task tap marks read, closes, refreshes, and emits a typed intent',
    (tester) async {
      var reads = 0;
      var listReads = 0;
      TaskAddressIntent? opened;
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.method == 'POST') {
            reads++;
            return _json(200, <String, Object?>{'data': null});
          }
          listReads++;
          return _notifications(
            unread: listReads < 3 ? 1 : 0,
            readAt: listReads < 3 ? null : '2026-08-28T10:00:00Z',
          );
        }),
      );

      await tester.pumpWidget(
        _app(
          NotificationBell(api: api, onOpenTask: (intent) => opened = intent),
        ),
      );
      await tester.pump();
      await tester.tap(find.byTooltip('1 unread'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Task changed'));
      await tester.pumpAndSettle();

      expect(reads, 1);
      expect(listReads, 4); // initial, panel, after mark-read, after close
      expect(
        opened,
        const TaskAddressIntent(boardId: 'board-1', taskId: 'task-1'),
      );
      expect(find.text('Notifications'), findsNothing);
      expect(find.byTooltip('Notifications'), findsOneWidget);
      expect(find.textContaining('board-1'), findsNothing);
      expect(find.textContaining('task-1'), findsNothing);
    },
  );

  testWidgets(
    'access lost between listing and tap evicts the title and emits no intent',
    (tester) async {
      var listReads = 0;
      TaskAddressIntent? opened;
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.method == 'POST') {
            return _json(404, <String, Object?>{'message': 'Not found.'});
          }
          listReads++;
          if (listReads >= 3) {
            return _json(200, <String, Object?>{
              'data': <Object?>[],
              'meta': <String, Object?>{'unread': 0},
            });
          }
          return _notifications(unread: 1, readAt: null);
        }),
      );

      await tester.pumpWidget(
        _app(
          NotificationBell(api: api, onOpenTask: (intent) => opened = intent),
        ),
      );
      await tester.pump();
      await tester.tap(find.byTooltip('1 unread'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Task changed'));
      await tester.pumpAndSettle();

      expect(opened, isNull);
      expect(find.text('Task changed'), findsNothing);
      expect(find.text('Nothing yet.'), findsOneWidget);
      expect(find.byTooltip('Notifications'), findsOneWidget);
    },
  );

  test(
    'cold and foreground intents share a URL but repeat taps remain refreshable',
    () {
      const canonical = '/tasks/boards/board-1/tasks/task-1';
      final address = Address(path: canonical);
      const cold = TaskAddressIntent(boardId: 'board-1', taskId: 'task-1');
      const foreground = TaskAddressIntent(
        boardId: 'board-1',
        taskId: 'task-1',
        navigationEpoch: 1,
      );

      expect(Address.taskIntent(address.path), cold);
      expect(Address.taskPath(foreground), canonical);
      expect(foreground, isNot(cold));

      address.popped('/tasks');

      expect(Address.taskIntent(address.path), isNull);
      expect(Address.idOf(address.path), 'tasks');
    },
  );
}

Widget _app(Widget child) => MaterialApp(home: Scaffold(body: child));

http.Response _notifications({required int unread, required String? readAt}) =>
    _json(200, <String, Object?>{
      'data': <Object?>[
        <String, Object?>{
          'id': 'notification-1',
          'title': 'Task changed',
          'body': null,
          'source': 'task',
          'read_at': readAt,
          'meta': <String, Object?>{
            'route': '/tasks/boards/board-1/tasks/task-1',
          },
        },
      ],
      'meta': <String, Object?>{'unread': unread},
    });

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);
