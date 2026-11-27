import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The row's ⋯ menu, ticks and the bulk bar, delete with Undo, and a branch
/// task shown on its company board (Jose, 2026-10-28).
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbr');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  late List<Map<String, dynamic>> tasks;
  late List<(String, String, Object?)> log;

  Map<String, dynamic> task(String id, String title, {Map? from}) => {
    'id': id,
    'board_id': from == null ? 'b-co' : 'b-br',
    'group_id': from == null ? 'g1' : 'g-br',
    'title': title,
    'status': 'not_started',
    'owner': null,
    'due_on': null,
    'about': null,
    'updates_count': 0,
    'created_by': null,
    'rolled_up_from': ?from,
  };

  ApiClient fakeServer() {
    tasks = [
      task('t1', 'Sweep the Oak Park flue'),
      task('t2', 'Order a cap'),
      task(
        't3',
        'Bad review: call back',
        from: {
          'board_id': 'b-br',
          'board_name': 'DMV',
          'group_name': 'Open Tasks',
        },
      ),
    ];
    log = [];
    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        final body = request.body.isEmpty ? null : jsonDecode(request.body);
        if (request.method != 'GET') log.add((request.method, path, body));
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
              'organization': {
                'id': 'org-1',
                'name': 'A-1',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path == '/task-boards') {
          return json({
            'data': [
              {
                'id': 'b-co',
                'kind': 'company',
                'name': 'A-1 Chimney',
                'open_for_me': 0,
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b-co') {
          return json({
            'data': {
              'id': 'b-co',
              'kind': 'company',
              'name': 'A-1 Chimney',
              'can_edit': true,
              'statuses': const [
                {'key': 'not_started', 'label': 'Not started'},
                {'key': 'done', 'label': 'Done'},
              ],
              'groups': [
                {'id': 'g1', 'name': 'Open Tasks', 'colour': '#579BFC'},
              ],
              'tasks': tasks,
            },
          });
        }
        if (path == '/tasks/bulk') {
          final ids = ((body as Map)['ids'] as List).cast<String>();
          if (body['op'] == 'delete') {
            tasks.removeWhere((t) => ids.contains(t['id']));
          }
          return json({
            'data': {'done': ids, 'refused': <dynamic>[]},
          });
        }
        final del = RegExp(r'^/tasks/(t\d+)$').firstMatch(path);
        if (del != null && request.method == 'DELETE') {
          tasks.removeWhere((t) => t['id'] == del.group(1));
          return http.Response('', 204);
        }
        final restore = RegExp(r'^/tasks/(t\d+)/restore$').firstMatch(path);
        if (restore != null) {
          tasks.add(task(restore.group(1)!, 'Back'));
          return json({'data': tasks.last});
        }
        return json({'message': 'Not found.'}, 404);
      }),
    );
  }

  Future<void> pumpBoard(
    WidgetTester tester, {
    Size size = const Size(1400, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = fakeServer();
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('the row menu offers every action', (tester) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('task-menu-t1')));
    await tester.pumpAndSettle();
    for (final label in [
      'Open',
      'Move to…',
      'Copy to…',
      'Duplicate',
      'Link…',
      'Delete',
    ]) {
      expect(find.text(label), findsOneWidget, reason: label);
    }
  });

  testWidgets('a branch task sits in the group of the same name, labelled', (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(find.byKey(const ValueKey('task-t3')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-from-t3')), findsOneWidget);
    expect(find.text('Not in a group'), findsNothing);
  });

  testWidgets('ticking a row brings the bulk bar; bulk delete is undoable', (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(find.byKey(const Key('task-bulk-bar')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('task-pick-t1')));
    await tester.tap(find.byKey(const ValueKey('task-pick-t2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-bulk-bar')), findsOneWidget);
    expect(find.text('2 selected'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('task-bulk-delete')));
    await tester.pumpAndSettle();
    expect(log.last.$2, '/tasks/bulk');
    expect(find.text('2 tasks deleted'), findsOneWidget);
    expect(find.byKey(const ValueKey('task-t1')), findsNothing);

    // It times out on its own: an action makes a SnackBar persist by default
    // (Flutter 3.35+), and "Task deleted" stayed up for good (Jose 11-19).
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
    expect(find.text('2 tasks deleted'), findsNothing);
  });

  testWidgets('delete from the menu shows Undo, which restores', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('task-menu-t1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete'));
    await tester.pumpAndSettle();
    expect(log.last.$1, 'DELETE');
    expect(find.byKey(const ValueKey('task-t1')), findsNothing);

    await tester.tap(find.byKey(const Key('task-undo')));
    await tester.pumpAndSettle();
    expect(log.last.$2, '/tasks/t1/restore');
    expect(find.byKey(const ValueKey('task-t1')), findsOneWidget);
  });
  testWidgets('a phone card has the menu too', (tester) async {
    await pumpBoard(tester, size: const Size(390, 844));
    await tester.tap(find.byKey(const ValueKey('task-menu-t1')));
    await tester.pumpAndSettle();
    expect(find.text('Move to…'), findsOneWidget);
    expect(find.byKey(const Key('task-bulk-bar')), findsNothing);
  });

  testWidgets('right-click on a row opens the fast actions', (tester) async {
    await pumpBoard(tester);
    await tester.tap(
      find.text('Sweep the Oak Park flue'),
      buttons: kSecondaryButton,
    );
    await tester.pumpAndSettle();
    for (final k in ['open', 'copy-name', 'copy-link', 'below', 'subtask']) {
      expect(find.byKey(ValueKey('task-rc-$k')), findsOneWidget, reason: k);
    }
  });

  testWidgets('shift-click picks the rows between; right-click acts on all', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('task-pick-t1')));
    await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
    await tester.tap(find.byKey(const ValueKey('task-pick-t3')));
    await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
    await tester.pumpAndSettle();
    expect(find.text('3 selected'), findsOneWidget);

    await tester.tap(find.text('Order a cap'), buttons: kSecondaryButton);
    await tester.pumpAndSettle();
    expect(find.text('3 tasks selected'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('task-rc-delete')));
    await tester.pumpAndSettle();
    expect(log.last.$2, '/tasks/bulk');
    expect(((log.last.$3 as Map)['ids'] as List).length, 3);
  });
}
