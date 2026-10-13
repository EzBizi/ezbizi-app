import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board v2 (UI queue 84; Jose: "Love it go with ur recommendations"):
/// a board's own statuses, its own columns, and subtasks under their task.
/// Written to the names the Task Board session gave: statuses
/// {id, name, colour, is_done}, a task's `status_id`, `parent_task_id`,
/// columns {id, name, type, position, hidden, settings}, `values`.
void main() {
  late List<(String, String, Object?)> log;
  late List<Map<String, dynamic>> tasks;

  ApiClient fakeServer({int columns = 3}) {
    log = [];
    tasks = [
      {
        'id': 't1',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': null,
        'title': 'Order liner parts for the Oak Park job',
        'status_id': 's-stuck',
        'owner': {'id': 'u-1', 'name': 'Omar Feld'},
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'subtasks_count': 2,
        'subtasks_done': 1,
        'values': {'c-type': 'o-masonry', 'c-hours': 3.5, 'c-ok': false},
      },
      {
        'id': 't2',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': 't1',
        'title': 'Measure the flue',
        'status_id': 's-done',
        'owner': null,
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'values': <String, dynamic>{},
      },
      {
        'id': 't3',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': 't1',
        'title': 'Place the order',
        'status_id': 's-stuck',
        'owner': null,
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'values': <String, dynamic>{},
      },
    ];
    var next = 10;
    http.Response json(Object body, [int code = 200]) => http.Response(
      jsonEncode(body),
      code,
      headers: const {'content-type': 'application/json'},
    );

    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        final body = request.body.isEmpty ? null : jsonDecode(request.body);
        if (request.method != 'GET') log.add((request.method, path, body));
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Omar Feld',
              'email': 'omar@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': {
                'help.tours_seen': {'tasks': 99},
              },
              'organization': {
                'id': 'org-1',
                'name': 'Northside',
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
                'id': 'b-1',
                'kind': 'franchise',
                'name': 'Lakeview',
                'company_id': 'c-1',
                'company_name': 'Northside Chimney',
                'open_for_me': 1,
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b-1') {
          return json({
            'data': {
              'id': 'b-1',
              'kind': 'franchise',
              'name': 'Lakeview',
              'can_edit': true,
              'statuses': [
                {
                  'id': 's-new',
                  'name': 'Not started',
                  'colour': '#8E9BA3',
                  'is_done': false,
                  'position': 0,
                },
                {
                  'id': 's-wait',
                  'name': 'Waiting on client',
                  'colour': '#7A4FB5',
                  'is_done': false,
                  'position': 1,
                },
                {
                  'id': 's-stuck',
                  'name': 'Stuck',
                  'colour': '#C53B3B',
                  'is_done': false,
                  'position': 2,
                },
                {
                  'id': 's-done',
                  'name': 'Done',
                  'colour': '#2F9A4C',
                  'is_done': true,
                  'position': 3,
                },
              ],
              'columns': [
                {
                  'id': 'c-type',
                  'name': 'Chimney type',
                  'type': 'dropdown',
                  'position': 0,
                  'hidden': false,
                  'settings': {
                    'options': [
                      {
                        'id': 'o-masonry',
                        'label': 'Masonry',
                        'colour': '#7A4FB5',
                      },
                      {
                        'id': 'o-prefab',
                        'label': 'Prefab',
                        'colour': '#00708F',
                      },
                    ],
                  },
                },
                if (columns > 1)
                  {
                    'id': 'c-hours',
                    'name': 'Est. hours',
                    'type': 'number',
                    'position': 1,
                    'hidden': false,
                    'settings': {'unit': 'h'},
                  },
                if (columns > 2)
                  {
                    'id': 'c-ok',
                    'name': 'Permit',
                    'type': 'checkbox',
                    'position': 2,
                    'hidden': false,
                    'settings': <String, dynamic>{},
                  },
                for (var i = 3; i < columns; i++)
                  {
                    'id': 'c-x$i',
                    'name': 'Notes $i',
                    'type': 'text',
                    'position': i,
                    'hidden': false,
                    'settings': <String, dynamic>{},
                  },
                {
                  'id': 'c-gone',
                  'name': 'Hidden one',
                  'type': 'text',
                  'position': 99,
                  'hidden': true,
                  'settings': <String, dynamic>{},
                },
              ],
              'groups': [
                {
                  'id': 'g1',
                  'name': 'This week',
                  'colour': '#579BFC',
                  'position': 0,
                },
                {
                  'id': 'g2',
                  'name': 'Next week',
                  'colour': '#A25DDC',
                  'position': 1,
                },
              ],
              'tasks': tasks,
            },
          });
        }
        if (path.startsWith('/task-boards/b-1/columns') ||
            path.startsWith('/task-boards/b-1/statuses') ||
            path.startsWith('/task-boards/b-1/groups')) {
          return json({
            'data': {
              'id': 'new-1',
              ...?(body as Map?)?.cast<String, dynamic>(),
            },
          });
        }
        if (path == '/tasks' && request.method == 'POST') {
          final m = body as Map<String, dynamic>;
          final task = {
            'id': 't${next++}',
            ...m,
            'status_id': 's-new',
            'owner': null,
            'due_on': null,
            'about': null,
            'updates_count': 0,
            'values': <String, dynamic>{},
          };
          tasks.add(task);
          return json({'data': task}, 201);
        }
        final one = RegExp(r'^/tasks/(t\d+)$').firstMatch(path);
        if (one != null) {
          final task = tasks.firstWhere((t) => t['id'] == one.group(1));
          if (request.method == 'PATCH') {
            final m = (body as Map).cast<String, dynamic>();
            if (m['values'] is Map) {
              task['values'] = {
                ...(task['values'] as Map),
                ...(m['values'] as Map),
              };
            }
            if (m['status_id'] != null) task['status_id'] = m['status_id'];
            return json({'data': task});
          }
          return json({
            'data': {...task, 'updates': <dynamic>[]},
          });
        }
        return json({'message': 'Not found.'}, 404);
      }),
    );
  }

  Future<void> pump(
    WidgetTester tester, {
    double width = 1400,
    int columns = 3,
  }) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(columns: columns);
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

  void sent(
    (String, String, Object?) call,
    String method,
    String path,
    Object? body,
  ) {
    expect(call.$1, method);
    expect(call.$2, path);
    expect(call.$3, body);
  }

  testWidgets('a board\'s own status wears its own name and colour, and a '
      'change sends status_id', (tester) async {
    await pump(tester);

    final cell = find.byKey(const ValueKey('task-status-t1'));
    expect(
      find.descendant(of: cell, matching: find.text('Stuck')),
      findsOneWidget,
    );
    final fill = tester.widget<Material>(
      find.ancestor(of: cell, matching: find.byType(Material)).first,
    );
    expect(fill.color, const Color(0xFFC53B3B));

    await tester.tap(cell);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('task-status-t1-s-wait')));
    await tester.pumpAndSettle();
    sent(log.single, 'PATCH', '/tasks/t1', {'status_id': 's-wait'});
  });

  testWidgets('the board\'s columns are columns, hidden ones left out, and '
      'a checkbox changes in place', (tester) async {
    await pump(tester);

    // A heading row per group.
    expect(find.text('CHIMNEY TYPE'), findsWidgets);
    expect(find.text('EST. HOURS'), findsWidgets);
    expect(find.text('HIDDEN ONE'), findsNothing);
    expect(find.text('Masonry'), findsOneWidget);
    expect(find.text('3.5 h'), findsOneWidget);

    await tester.tap(find.byType(Checkbox).first);
    await tester.pumpAndSettle();
    sent(log.single, 'PATCH', '/tasks/t1', {
      'values': {'c-ok': true},
    });
  });

  testWidgets('subtasks open under their task, and one is added there', (
    tester,
  ) async {
    await pump(tester);

    // Folded at first: the count, not the rows.
    expect(find.text('1 / 2'), findsOneWidget);
    expect(find.text('Measure the flue'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('task-subtasks-t1')));
    await tester.pumpAndSettle();
    expect(find.text('Measure the flue'), findsOneWidget);
    expect(find.text('Place the order'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('task-add-sub-t1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('task-new-title')),
      'Book the install',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(log.single.$1, 'POST');
    expect((log.single.$3 as Map)['parent_task_id'], 't1');
    expect(find.text('Book the install'), findsOneWidget);
    expect(find.text('1 / 3'), findsOneWidget);
  });

  testWidgets('a board with many columns scrolls sideways, not squeezes', (
    tester,
  ) async {
    await pump(tester, width: 1000, columns: 7);

    expect(find.byKey(const Key('tasks-table-scroll')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a column is edited in the task\'s panel by its type', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('task-t1')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('column-editor-c-hours')),
      '4',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/tasks/t1', {
      'values': {'c-hours': 4},
    });
  });

  testWidgets('the + at the end of the header adds a column of its type', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.byKey(const Key('column-add')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-add-number')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('column-name')), 'Miles');
    await tester.enterText(find.byKey(const Key('column-unit')), 'mi');
    await tester.tap(find.byKey(const Key('column-save')));
    await tester.pumpAndSettle();

    sent(log.single, 'POST', '/task-boards/b-1/columns', {
      'name': 'Miles',
      'type': 'number',
      'settings': {'unit': 'mi'},
    });
  });

  testWidgets('Statuses… renames one, and deleting one asks where its tasks '
      'go first', (tester) async {
    await pump(tester);

    await tester.tap(find.byKey(const Key('tasks-statuses')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('status-name-s-wait')),
      'Waiting',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    sent(log.single, 'PATCH', '/task-boards/b-1/statuses/s-wait', {
      'name': 'Waiting',
    });

    await tester.tap(find.byKey(const ValueKey('status-delete-s-wait')));
    await tester.pumpAndSettle();
    final confirm = tester.widget<FilledButton>(
      find.byKey(const Key('status-delete-confirm')),
    );
    expect(confirm.onPressed, isNull);
  });

  testWidgets('a group is added at the foot of the board and renamed from '
      'its heading', (tester) async {
    await pump(tester);

    await tester.tap(find.byKey(const Key('group-add')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('group-name')), 'Later');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    sent(log.last, 'POST', '/task-boards/b-1/groups', {'name': 'Later'});

    await tester.tap(find.byKey(const ValueKey('group-menu-g2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('group-rename-g2')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('group-name')), 'Soon');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/task-boards/b-1/groups/g2', {'name': 'Soon'});
  });

  testWidgets('a group with tasks is deleted only by moving them first', (
    tester,
  ) async {
    await pump(tester);

    // Empty: deleted outright.
    await tester.tap(find.byKey(const ValueKey('group-menu-g2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('group-delete-g2')));
    await tester.pumpAndSettle();
    expect(log.last.$1, 'DELETE');
    expect(log.last.$2, '/task-boards/b-1/groups/g2');

    // With tasks: the choice is where they go.
    await tester.tap(find.byKey(const ValueKey('group-menu-g1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('group-delete-g1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('group-delete-g1-to-g2')));
    await tester.pumpAndSettle();
    expect(log.last.$2, '/task-boards/b-1/groups/g1');
  });
}
