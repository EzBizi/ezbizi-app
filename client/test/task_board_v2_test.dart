import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_files.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:ezbizi_client/widgets/user_avatar.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board v2 (UI queue 84; Jose: "Love it go with ur recommendations"):
/// a board's own statuses, its own columns, and subtasks under their task.
/// Written to the names the Task Board session gave: statuses
/// {id, name, colour, is_done}, a task's `status_id`, `parent_task_id`,
/// columns {id, name, type, position, hidden, settings}, `values`.
void main() {
  // The board last opened is remembered on disk: keep it out of the real one.
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tb');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  late List<(String, String, Object?)> log;
  late List<Map<String, dynamic>> tasks;

  /// What the board says of its built-in columns: null leaves the field
  /// out, as a server from before it did.
  Map<String, dynamic>? builtins;
  var canEdit = true;
  var refuseBoardPatch = false;

  /// Adds a Files column, c-files.
  var withFiles = false;

  /// Top-level tasks added after the three every test has.
  var extra = <Map<String, dynamic>>[];

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
        'owners': [
          {'id': 'u-1', 'name': 'Omar Feld'},
          {'id': 'u-2', 'name': 'Priya Nandakumar'},
          {'id': 'u-3', 'name': 'Tomas Reyes'},
        ],
        'created_at': '2026-03-04T15:00:00+00:00',
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'subtasks_count': 2,
        'subtasks_done': 1,
        'values': {
          'c-type': 'o-masonry',
          'c-hours': 3.5,
          'c-ok': false,
          if (withFiles) 'c-files': ['a-0'],
        },
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
      ...extra,
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
        final multipart =
            request.headers['content-type']?.startsWith('multipart') ?? false;
        final body = request.body.isEmpty || multipart
            ? null
            : jsonDecode(request.body);
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
        if (path == '/task-boards/b-1' && request.method == 'PATCH') {
          if (refuseBoardPatch) {
            return json({
              'message': 'The given data was invalid.',
              'errors': {
                'builtin_columns.about.label': [
                  'That column name is not allowed here.',
                ],
              },
            }, 422);
          }
          final change = ((body as Map)['builtin_columns'] as Map)
              .cast<String, dynamic>();
          for (final e in change.entries) {
            builtins![e.key] = {
              ...(builtins![e.key] as Map),
              ...(e.value as Map),
            };
          }
          return json({
            'data': {'id': 'b-1', 'builtin_columns': builtins},
          });
        }
        if (path == '/task-boards/b-1') {
          return json({
            'data': {
              'id': 'b-1',
              'kind': 'franchise',
              'name': 'Lakeview',
              'can_edit': canEdit,
              'builtin_columns': ?builtins,
              'assignable': [
                {'id': 'u-1', 'name': 'Omar Feld'},
                {'id': 'u-2', 'name': 'Priya Nandakumar'},
                {'id': 'u-3', 'name': 'Tomas Reyes'},
                {'id': 'u-4', 'name': 'Lena Okafor'},
              ],
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
                if (withFiles)
                  {
                    'id': 'c-files',
                    'name': 'Files',
                    'type': 'files',
                    'position': 50,
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
        if (path == '/tasks/t1/updates' && multipart) {
          return json({
            'data': {
              'id': 'up-1',
              'attachments': [
                {'id': 'a-9', 'name': 'photo.jpg'},
              ],
            },
          }, 201);
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
            if (m['assignee_user_ids'] is List) {
              final names = {
                'u-1': 'Omar Feld',
                'u-2': 'Priya Nandakumar',
                'u-3': 'Tomas Reyes',
                'u-4': 'Lena Okafor',
              };
              task['owners'] = [
                for (final id in m['assignee_user_ids'] as List)
                  {'id': id, 'name': names[id]},
              ];
            }
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

  setUp(() {
    builtins = null;
    withFiles = false;
    canEdit = true;
    refuseBoardPatch = false;
    extra = [];
  });

  Future<void> pump(
    WidgetTester tester, {
    double width = 1700,
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

  // Jose 11-13: dragging left made the column grow rightwards in place,
  // because the stretchy Task column ate every change. The edge must follow
  // the pointer, as in Monday.
  for (final dx in [-20.0, 50.0]) {
    testWidgets('a column edge follows the pointer ($dx)', (tester) async {
      await pump(tester, width: 2400, columns: 3);
      final handles = find.byWidgetPredicate(
        (w) =>
            w.key is ValueKey &&
            '${(w.key as ValueKey).value}'.startsWith('column-resize-'),
      );
      // The status column and the last column on the row, in the first
      // group's headings.
      for (final handle in [
        find.byKey(const ValueKey('column-resize-status')).first,
        handles.last,
      ]) {
        final before = tester.getRect(handle);
        final g = await tester.startGesture(
          before.center,
          kind: PointerDeviceKind.mouse,
        );
        for (var i = 0; i < 10; i++) {
          await g.moveBy(Offset(dx / 10, 0));
          await tester.pump();
        }
        expect(
          tester.getRect(handle).center.dx,
          closeTo(before.center.dx + dx, 2),
        );
        await g.up();
        await tester.pumpAndSettle();
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('a column is edited in the task\'s panel by its type', (
    tester,
  ) async {
    await pump(tester);

    // The name opens the task; a cell edits in place (Jose, 2026-11-12).
    await tester.tap(find.text('Order liner parts for the Oak Park job').first);
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

  // -- Built-in columns (Jose, 2026-10-20) --

  Map<String, dynamic> customised() => {
    'owner': {'label': 'Owner', 'visible': true},
    'status': {'label': 'Status', 'visible': true},
    'due': {'label': 'Due', 'visible': true},
    'about': {'label': 'Summary', 'visible': true},
    'created': {'label': 'Created', 'visible': false},
    'updates': {'label': 'Updates', 'visible': true},
  };

  testWidgets("built-in headings wear the board's labels, and a hidden one "
      'is not drawn', (tester) async {
    builtins = customised();
    builtins!['updates'] = {'label': 'Updates', 'visible': false};
    await pump(tester);

    expect(find.text('SUMMARY'), findsWidgets);
    expect(find.text('ABOUT'), findsNothing);
    expect(find.text('UPDATES'), findsNothing);
    expect(find.text('CREATED'), findsNothing);
    expect(find.byKey(const ValueKey('task-created-t1')), findsNothing);
  });

  testWidgets("a board without builtin_columns shows today's headings", (
    tester,
  ) async {
    await pump(tester);

    for (final h in ['OWNER', 'STATUS', 'DUE', 'ABOUT', 'UPDATES']) {
      expect(find.text(h), findsWidgets, reason: h);
    }
    expect(find.text('CREATED'), findsNothing);
    expect(find.byKey(const Key('tasks-columns')), findsNothing);
  });

  testWidgets('Created, when shown, is the day the task was made', (
    tester,
  ) async {
    builtins = customised();
    builtins!['created'] = {'label': 'Opened', 'visible': true};
    await pump(tester);

    expect(find.text('OPENED'), findsWidgets);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('task-created-t1'))).data,
      '2026-03-04',
    );
  });

  testWidgets("Rename sends only that column's label", (tester) async {
    builtins = customised();
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('builtin-head-about')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('builtin-rename-about')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('builtin-name')), 'Notes');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    sent(log.single, 'PATCH', '/task-boards/b-1', {
      'builtin_columns': {
        'about': {'label': 'Notes'},
      },
    });
    expect(find.text('NOTES'), findsWidgets);
    expect(find.byKey(const Key('builtin-name')), findsNothing);
  });

  testWidgets('a refused rename says why under the box, which stays open', (
    tester,
  ) async {
    builtins = customised();
    refuseBoardPatch = true;
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('builtin-head-about')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('builtin-rename-about')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('builtin-name')), 'Notes');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(find.text('That column name is not allowed here.'), findsOneWidget);
    expect(find.byKey(const Key('builtin-name')), findsOneWidget);
    expect(find.text('SUMMARY'), findsWidgets);
  });

  testWidgets('Hide sends visible false, and Columns shows it again', (
    tester,
  ) async {
    builtins = customised();
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('builtin-head-due')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('builtin-hide-due')));
    await tester.pumpAndSettle();
    sent(log.single, 'PATCH', '/task-boards/b-1', {
      'builtin_columns': {
        'due': {'visible': false},
      },
    });
    expect(find.text('DUE'), findsNothing);

    await tester.tap(find.byKey(const Key('tasks-columns')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('builtin-show-created')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('builtin-show-due')));
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/task-boards/b-1', {
      'builtin_columns': {
        'due': {'visible': true},
      },
    });
    expect(find.text('DUE'), findsWidgets);
  });

  testWidgets("every heading has a ⋯ that opens its menu: Hide column sends "
      'hidden, and Delete asks once inside the menu', (tester) async {
    builtins = customised();
    await pump(tester);

    // Built-ins and board columns alike wear the ⋯.
    expect(find.byKey(const ValueKey('builtin-more-due')), findsWidgets);
    expect(find.byKey(const ValueKey('column-more-c-hours')), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('column-more-c-hours')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-menu-hide-c-hours')));
    await tester.pumpAndSettle();
    sent(log.single, 'PATCH', '/task-boards/b-1/columns/c-hours', {
      'hidden': true,
    });

    await tester.tap(find.byKey(const ValueKey('column-more-c-hours')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-menu-remove-c-hours')));
    await tester.pumpAndSettle();
    // Nothing sent yet: the menu stays open and asks.
    expect(log, hasLength(1));
    expect(find.byType(Dialog), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('column-menu-delete-cancel-c-hours')),
    );
    await tester.pumpAndSettle();
    expect(log, hasLength(1));
    expect(
      find.byKey(const ValueKey('column-menu-delete-confirm-c-hours')),
      findsNothing,
    );
    await tester.tap(find.byKey(const ValueKey('column-menu-remove-c-hours')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('column-menu-delete-confirm-c-hours')),
    );
    await tester.pumpAndSettle();
    sent(log.last, 'DELETE', '/task-boards/b-1/columns/c-hours', null);
  });

  testWidgets("a built-in heading's ⋯ offers Hide and never Delete", (
    tester,
  ) async {
    builtins = customised();
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('builtin-more-due')).first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('builtin-hide-due')), findsOneWidget);
    expect(find.textContaining('Delete'), findsNothing);
  });

  testWidgets('Columns · N hidden shows a hidden board column again', (
    tester,
  ) async {
    builtins = customised();
    await pump(tester);

    await tester.tap(find.byKey(const Key('tasks-columns')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-show-c-gone')));
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/task-boards/b-1/columns/c-gone', {
      'hidden': false,
    });
  });

  testWidgets('a board you cannot edit has no column menus and no Columns', (
    tester,
  ) async {
    builtins = customised();
    canEdit = false;
    await pump(tester);

    expect(find.text('SUMMARY'), findsWidgets);
    expect(find.byKey(const ValueKey('builtin-head-about')), findsNothing);
    expect(find.byKey(const Key('tasks-columns')), findsNothing);
  });

  testWidgets('several owners: the primary and +N, and the panel adds one '
      'with every owner sent', (tester) async {
    await pump(tester);

    // Faces only, Monday's way (Jose 11-16): three overlapping, no names
    // drawn, every name in the cell's semantics label.
    final faces = find.byKey(const ValueKey('task-owner-faces-t1'));
    expect(
      find.descendant(of: faces, matching: find.byType(UserAvatar)),
      findsNWidgets(3),
    );
    expect(
      find.descendant(of: faces, matching: find.text('Omar')),
      findsNothing,
    );
    expect(
      find.descendant(
        of: faces,
        matching: find.byWidgetPredicate(
          (w) =>
              w is Semantics &&
              w.properties.label == 'Omar Feld, Priya Nandakumar, Tomas Reyes',
        ),
      ),
      findsOneWidget,
    );

    // The name opens the task; a cell edits in place (Jose, 2026-11-12).
    await tester.tap(find.text('Order liner parts for the Oak Park job').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-panel-owner-u-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-panel-owner-u-3')), findsOneWidget);

    await tester.tap(find.byKey(const Key('task-panel-owner-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lena Okafor').last);
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/tasks/t1', {
      'assignee_user_ids': ['u-1', 'u-2', 'u-3', 'u-4'],
    });
    expect(find.byKey(const ValueKey('task-panel-owner-u-4')), findsOneWidget);
  });

  testWidgets('an owner cell is a face: no name drawn, the name on hover, '
      'and an empty one is a faint person-plus that assigns', (tester) async {
    extra = [
      {
        'id': 't8',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': null,
        'title': 'Nobody on it',
        'status_id': 's-new',
        'owner': null,
        'owners': <dynamic>[],
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'values': <String, dynamic>{},
      },
    ];
    await pump(tester);

    expect(find.text('Omar'), findsNothing);
    expect(find.text('Omar Feld'), findsNothing);
    final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
    await mouse.addPointer(location: Offset.zero);
    addTearDown(mouse.removePointer);
    await mouse.moveTo(
      tester.getCenter(find.byKey(const ValueKey('people-face-u-1')).first),
    );
    // This fake has no person card data: the card opens on its loader.
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byKey(const ValueKey('person-card-u-1')), findsOneWidget);
    await mouse.moveTo(Offset.zero);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byKey(const ValueKey('person-card-u-1')), findsNothing);

    final empty = find.descendant(
      of: find.byKey(const ValueKey('task-owner-faces-t8')),
      matching: find.byKey(const Key('people-empty')),
    );
    expect(empty, findsOneWidget);
    await tester.tap(empty);
    await tester.pumpAndSettle();
    expect(find.text('Lena Okafor'), findsWidgets);
  });

  testWidgets('Owner and People columns default to about 80 wide', (
    tester,
  ) async {
    await pump(tester);
    final head = find.byKey(const ValueKey('builtin-head-owner')).first;
    expect(tester.getSize(head).width, lessThanOrEqualTo(80));
  });

  testWidgets('a Kanban card shows its first four filled columns and '
      '+N more, not every column', (tester) async {
    extra = [
      {
        'id': 't7',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': null,
        'title': 'Full card',
        'status_id': 's-new',
        'owner': null,
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'values': {
          'c-hours': 2,
          'c-ok': true,
          for (var i = 3; i < 8; i++) 'c-x$i': 'v$i',
        },
      },
    ];
    await pump(tester, columns: 8);
    await tester.tap(find.text('Kanban').first);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('task-card-more-t7')), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('task-card-more-t7'))).data,
      '+3 more',
    );
    expect(find.text('v7'), findsNothing);
  });

  testWidgets('Created comes before Due in the header', (tester) async {
    builtins = customised();
    builtins!['created'] = {'label': 'Created', 'visible': true};
    builtins!['due'] = {'label': 'Due', 'visible': true};
    await pump(tester);

    final created = tester.getTopLeft(find.text('CREATED').first).dx;
    final due = tester.getTopLeft(find.text('DUE').first).dx;
    expect(created, lessThan(due));
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('task-created-t1'))).dx,
      lessThan(due + 1),
    );
  });

  testWidgets("a card a rule made with no owner is EzBizi's, the rule on "
      "hover; a person's card is unchanged", (tester) async {
    extra = [
      {
        'id': 't9',
        'board_id': 'b-1',
        'group_id': 'g1',
        'parent_task_id': null,
        'title': 'Call back about the cap',
        'status_id': 's-stuck',
        'owner': null,
        'owners': <dynamic>[],
        'created_by': {'type': 'automation', 'label': 'New lead follow-up'},
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'values': <String, dynamic>{},
      },
    ];
    await pump(tester);

    final mark = find.byKey(const ValueKey('task-owner-ezbizi-t9'));
    expect(mark, findsOneWidget);
    // The mark alone; its name and the rule's on hover.
    expect(
      find.descendant(of: mark, matching: find.text('EzBizi')),
      findsNothing,
    );
    expect(tester.widget<Tooltip>(mark).message, 'EzBizi · New lead follow-up');

    expect(find.byKey(const ValueKey('task-owner-ezbizi-t1')), findsNothing);
    expect(find.byKey(const ValueKey('task-owner-faces-t1')), findsOneWidget);
  });

  Map<String, dynamic> doneTask() => {
    'id': 't9',
    'board_id': 'b-1',
    'group_id': 'g1',
    'parent_task_id': null,
    'title': 'Sweep the Elm St chimney',
    'status_id': 's-done',
    'owner': null,
    'due_on': null,
    'about': null,
    'updates_count': 0,
    'values': <String, dynamic>{},
  };

  testWidgets("a heading's Filter… keeps only the value picked, and Clear "
      'filter brings the rest back', (tester) async {
    builtins = customised();
    extra = [doneTask()];
    await pump(tester);
    expect(find.text('Order liner parts for the Oak Park job'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('builtin-head-status')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('header-filter-status')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('header-filter-status-s-done')));
    await tester.pumpAndSettle();
    expect(find.text('Order liner parts for the Oak Park job'), findsNothing);
    expect(find.text('Sweep the Elm St chimney'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('builtin-head-status')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('header-filter-status')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('header-filter-clear-status')));
    await tester.pumpAndSettle();
    expect(find.text('Order liner parts for the Oak Park job'), findsOneWidget);
  });

  testWidgets('Group by Status draws one group per status, and Board groups '
      'goes back', (tester) async {
    extra = [doneTask()];
    await pump(tester);

    await tester.tap(find.byKey(const Key('tasks-group-by')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('group-by-status')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-group-by:s-stuck')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-group-by:s-done')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-group-g1')), findsNothing);
    // A Group by group takes no new tasks: it is not a group on the board.
    expect(find.byKey(const ValueKey('group-menu-by:s-done')), findsNothing);

    await tester.tap(find.byKey(const Key('tasks-group-by')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('group-by-none')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-group-g1')), findsOneWidget);
  });

  testWidgets('right-click a cell and Filter by this value keeps the tasks '
      'with it', (tester) async {
    extra = [doneTask()];
    await pump(tester);
    await tester.tap(
      find.byKey(const ValueKey('range-cell-c-hours-t1')),
      buttons: kSecondaryButton,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('cell-filter-value')));
    await tester.pumpAndSettle();
    expect(find.text('Order liner parts for the Oak Park job'), findsOneWidget);
    expect(find.text('Sweep the Elm St chimney'), findsNothing);
  });

  testWidgets('Ctrl-click picks cells in one column and Ctrl+V pastes over '
      'every one', (tester) async {
    extra = [doneTask()];
    await pump(tester);
    String? clip = '7';
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.getData') return {'text': clip};
        if (call.method == 'Clipboard.setData') {
          clip = (call.arguments as Map)['text'] as String?;
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );

    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    for (final id in ['t1', 't9']) {
      await tester.tap(find.byKey(ValueKey('range-cell-c-hours-$id')));
      await tester.pump();
    }
    await tester.sendKeyEvent(LogicalKeyboardKey.keyC);
    await tester.pump();
    expect(clip, '3.5');
    await tester.sendKeyEvent(LogicalKeyboardKey.keyV);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pumpAndSettle();
    final patches = [
      for (final (m, p, b) in log)
        if (m == 'PATCH') '$p ${jsonEncode(b)}',
    ];
    expect(patches, [
      '/tasks/t1 {"values":{"c-hours":3.5}}',
      '/tasks/t9 {"values":{"c-hours":3.5}}',
    ]);
  });

  testWidgets('a label cell has Filter by this value on right-click too', (
    tester,
  ) async {
    extra = [doneTask()];
    await pump(tester);
    await tester.tap(
      find.byKey(const ValueKey('range-cell-c-type-t1')),
      buttons: kSecondaryButton,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('cell-filter-value')));
    await tester.pumpAndSettle();
    expect(find.text('Sweep the Elm St chimney'), findsNothing);
  });

  Future<List<String>> dragT9OntoT1(
    WidgetTester tester, {
    required bool esc,
  }) async {
    extra = [doneTask()];
    await pump(tester);
    final from = tester.getCenter(find.byKey(const ValueKey('task-drag-t9')));
    final to = tester.getCenter(find.byKey(const ValueKey('task-t1')));
    final g = await tester.startGesture(from);
    await tester.pump(const Duration(milliseconds: 50));
    await g.moveTo(Offset(from.dx, (from.dy + to.dy) / 2));
    await tester.pump();
    if (esc) await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await g.moveTo(to);
    await tester.pump();
    await g.up();
    await tester.pumpAndSettle();
    return [
      for (final (m, p, _) in log)
        if (m != 'GET') '$m $p',
    ];
  }

  testWidgets('a row dropped on another moves it', (tester) async {
    expect(await dragT9OntoT1(tester, esc: false), isNotEmpty);
  });

  testWidgets('Esc during a row drag cancels it: the drop moves nothing', (
    tester,
  ) async {
    expect(await dragT9OntoT1(tester, esc: true), isEmpty);
  });

  testWidgets('a row dragged to the bottom edge scrolls the list', (
    tester,
  ) async {
    extra = [
      for (var i = 0; i < 40; i++)
        {...doneTask(), 'id': 'x$i', 'title': 'Filler task $i'},
    ];
    await pump(tester);
    final list = find.byType(CustomScrollView).first;
    final before = tester
        .state<ScrollableState>(
          find.descendant(of: list, matching: find.byType(Scrollable)).first,
        )
        .position
        .pixels;
    final from = tester.getCenter(find.byKey(const ValueKey('task-drag-x0')));
    final bottom = tester.getBottomLeft(list).dy - 10;
    final g = await tester.startGesture(from);
    await tester.pump(const Duration(milliseconds: 50));
    for (var i = 0; i < 10; i++) {
      await g.moveTo(Offset(from.dx, bottom - (i.isEven ? 0 : 1)));
      await tester.pump(const Duration(milliseconds: 16));
    }
    final after = tester
        .state<ScrollableState>(
          find.descendant(of: list, matching: find.byType(Scrollable)).first,
        )
        .position
        .pixels;
    await g.up();
    await tester.pumpAndSettle();
    expect(after, greaterThan(before));
  });

  testWidgets("a Files cell's + uploads the file to the task and adds it to "
      'the cell', (tester) async {
    withFiles = true;
    pickTaskUpdateFile = () async =>
        PickedBytes('photo.jpg', utf8.encode('jpeg'));
    addTearDown(() => pickTaskUpdateFile = () async => null);
    await pump(tester);
    await tester.tap(
      find.byKey(const ValueKey('column-file-add-c-files')).first,
    );
    await tester.pumpAndSettle();
    final writes = [
      for (final (m, p, b) in log)
        if (m != 'GET') '$m $p ${b == null ? '' : jsonEncode(b)}',
    ];
    expect(writes, [
      'POST /tasks/t1/updates ',
      'PATCH /tasks/t1 {"values":{"c-files":["a-0","a-9"]}}',
    ]);
  });

  testWidgets("the + menu's search finds a type from More, and hides the "
      'rest', (tester) async {
    await pump(tester);
    await tester.tap(find.byKey(const Key('column-add')).first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('column-add-rating')), findsNothing);
    await tester.enterText(find.byKey(const Key('column-add-search')), 'rat');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('column-add-number')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('column-add-rating')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('column-save')), findsOneWidget);
  });
}
