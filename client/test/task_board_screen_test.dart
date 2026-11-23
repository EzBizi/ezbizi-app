import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board (UI queue 80): the boards as tabs, a board's groups as a table
/// or kanban, a task in the side panel, and cards on a phone.
void main() {
  // The board last opened is remembered on disk: keep it out of the real one.
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tb');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  const statuses = [
    {'key': 'not_started', 'label': 'Not started'},
    {'key': 'working_on_it', 'label': 'Working on it'},
    {'key': 'stuck', 'label': 'Stuck'},
    {'key': 'done', 'label': 'Done'},
  ];

  late List<Map<String, dynamic>> tasks;
  late List<(String, String, Object?)> log;

  ApiClient fakeServer() {
    tasks = [
      {
        'id': 't1',
        'board_id': 'b-me',
        'group_id': 'g1',
        'title': 'Call back Dana Whitfield about the cap quote',
        'status': 'working_on_it',
        'owner': {'id': 'me', 'name': 'Maya Lopez'},
        'due_on': '2099-01-05',
        'about': {'type': 'lead', 'id': 'l1', 'label': 'Dana Whitfield'},
        'updates_count': 2,
        'created_by': {'type': 'user', 'label': 'Maya Lopez'},
      },
      {
        'id': 't2',
        'board_id': 'b-me',
        'group_id': 'g1',
        'title': 'Order liner parts for the Oak Park job',
        'status': 'stuck',
        'owner': {'id': 'u2', 'name': 'Omar Feld'},
        'due_on': '2026-01-01',
        'about': {'type': 'job', 'id': 'j1', 'label': 'Job #1042'},
        'updates_count': 0,
        'created_by': {'type': 'automation', 'label': 'Liner job booked'},
      },
      {
        'id': 't3',
        'board_id': 'b-me',
        'group_id': 'g2',
        'title': 'Renew the van insurance',
        'status': 'done',
        'owner': null,
        'due_on': null,
        'about': null,
        'updates_count': 0,
        'created_by': null,
      },
    ];
    log = [];
    var next = 10;

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
                'name': 'Northside Chimney',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/activity')) {
          return json({
            'data': [
              {
                'id': 'a1',
                'at': '2026-11-09T15:00:00Z',
                'who': 'Omar Feld',
                'label': 'Status',
                'from': 'Working on it',
                'to': 'Stuck',
              },
            ],
          });
        }
        if (path == '/task-boards') {
          return json({
            'data': [
              {
                'id': 'b-me',
                'kind': 'user',
                'name': 'Maya Lopez',
                'open_for_me': 2,
                'can_edit': true,
              },
              {
                'id': 'b-fr',
                'kind': 'franchise',
                'name': 'Lakeview franchise',
                'company_id': 'c-1',
                'company_name': 'Northside Chimney',
                'open_for_me': 1,
                'can_edit': false,
              },
              {
                'id': 'b-m1',
                'kind': 'franchise',
                'name': 'Main',
                'company_id': 'c-1',
                'company_name': 'Northside Chimney',
                'open_for_me': 0,
                'can_edit': true,
              },
              {
                'id': 'b-m2',
                'kind': 'franchise',
                'name': 'Main',
                'company_id': 'c-2',
                'company_name': 'Everdoors Garage',
                'open_for_me': 0,
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b-me') {
          return json({
            'data': {
              'id': 'b-me',
              'kind': 'user',
              'name': 'Maya Lopez',
              'can_edit': true,
              'can_set_edit_mode': true,
              'can_delete': true,
              'statuses': statuses,
              // A personal board is given only to its owner.
              'assignable': [
                {'id': 'me', 'name': 'Maya Lopez'},
              ],
              'groups': [
                {
                  'id': 'g2',
                  'name': 'Done',
                  'colour': '#00C875',
                  'position': 2,
                },
                {
                  'id': 'g1',
                  'name': 'This week',
                  'colour': '#579BFC',
                  'position': 0,
                },
              ],
              'tasks': tasks,
            },
          });
        }
        if (path == '/task-boards/b-fr') {
          return json({
            'data': {
              'id': 'b-fr',
              'kind': 'franchise',
              'name': 'Lakeview franchise',
              'can_edit': false,
              'statuses': statuses,
              'groups': [
                {
                  'id': 'g9',
                  'name': 'This week',
                  'colour': '#579BFC',
                  'position': 0,
                },
              ],
              'tasks': [
                {
                  'id': 't9',
                  'board_id': 'b-fr',
                  'group_id': 'g9',
                  'title': 'Confirm Thursday crew',
                  'status': 'not_started',
                  'owner': null,
                  'due_on': null,
                  'about': null,
                  'updates_count': 0,
                  'created_by': null,
                },
              ],
            },
          });
        }
        if (path == '/tasks' && request.method == 'POST') {
          final m = body as Map<String, dynamic>;
          final task = {
            'id': 't${next++}',
            'board_id': m['board_id'],
            'group_id': m['group_id'],
            'title': m['title'],
            'status': 'not_started',
            'owner': m['assignee_user_id'] == 'me'
                ? {'id': 'me', 'name': 'Maya Lopez'}
                : null,
            'due_on': null,
            'about': null,
            'updates_count': 0,
            'created_by': {'type': 'user', 'label': 'Maya Lopez'},
          };
          tasks.add(task);
          return json({'data': task}, 201);
        }
        final one = RegExp(r'^/tasks/(t\d+)(/updates)?$').firstMatch(path);
        if (one != null) {
          final task = tasks.firstWhere((t) => t['id'] == one.group(1));
          if (one.group(2) != null) {
            return json({
              'data': {
                'id': 'u1',
                'body': (body as Map)['body'],
                'author': {'id': 'me', 'name': 'Maya Lopez'},
                'created_at': '2026-10-13T14:10:00Z',
              },
            }, 201);
          }
          switch (request.method) {
            case 'PATCH':
              task.addAll((body as Map).cast<String, dynamic>());
              return json({'data': task});
            case 'DELETE':
              tasks.remove(task);
              return http.Response('', 204);
            default:
              return json({
                'data': {...task, 'updates': <dynamic>[]},
              });
          }
        }
        return json({'message': 'Not found.'}, 404);
      }),
    );
  }

  /// Records hold maps by identity, so each part is compared on its own.
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

  testWidgets('a board is its groups in order, an all-done group folded', (
    tester,
  ) async {
    await pumpBoard(tester);

    // It opens on My board: the button is lit (the picker is for others).
    final quick = tester.widget<SegmentedButton<int>>(
      find.byKey(const Key('tasks-quick-boards')),
    );
    expect(quick.selected, hasLength(1));
    expect(
      find.descendant(
        of: find.byKey(const Key('tasks-quick-boards')),
        matching: find.text('Personal Task Board'),
      ),
      findsOneWidget,
    );

    final week = find.byKey(const ValueKey('task-group-g1'));
    final done = find.byKey(const ValueKey('task-group-g2'));
    expect(tester.getTopLeft(week).dy, lessThan(tester.getTopLeft(done).dy));

    expect(find.byKey(const ValueKey('task-t1')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-t2')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-t3')), findsNothing);

    // Late and not done reads in the error colour.
    final late = tester.widget<Text>(find.text('1/1/2026'));
    expect(late.style?.color, Theme.of(tester.element(week)).colorScheme.error);

    await tester.tap(done);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-t3')), findsOneWidget);
  });

  testWidgets('the status cell changes the status without opening the task', (
    tester,
  ) async {
    await pumpBoard(tester);

    await tester.tap(find.byKey(const ValueKey('task-status-t1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('task-status-t1-done')));
    await tester.pumpAndSettle();

    sent(log.single, 'PATCH', '/tasks/t1', {'status': 'done'});
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('task-status-t1')),
        matching: find.text('Done'),
      ),
      findsOneWidget,
    );
    expect(find.byType(SidePanel), findsNothing);
  });

  testWidgets('Add task at the foot of a group makes one there, mine on my '
      'own board', (tester) async {
    await pumpBoard(tester);

    await tester.tap(find.byKey(const ValueKey('task-add-g1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('task-new-title')),
      'Order the ladder',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(log.single.$1, 'POST');
    expect(log.single.$3, {
      'board_id': 'b-me',
      'title': 'Order the ladder',
      'group_id': 'g1',
      'assignee_user_id': 'me',
    });
    expect(find.text('Order the ladder'), findsOneWidget);
    // Still open for the next one.
    expect(find.byKey(const Key('task-new-title')), findsOneWidget);
  });

  testWidgets('a task opens in the side panel: status, an update, and a '
      'two-step delete', (tester) async {
    await pumpBoard(tester);

    // The name opens the task; Owner and About edit in place (Jose, 2026-11-13).
    await tester.tapAt(
      tester.getTopLeft(find.byKey(const ValueKey('task-t2'))) +
          const Offset(160, 19),
    );
    await tester.pumpAndSettle();
    expect(find.text('EzBizi · automation "Liner job booked"'), findsOneWidget);
    expect(find.text('Late'), findsOneWidget);

    // Activity: who changed what, from and to (Jose, 2026-11-09).
    await tester.ensureVisible(find.byKey(const Key('task-activity')));
    await tester.tap(find.byKey(const Key('task-activity')));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Status: Working on it → Stuck'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('task-activity')));
    await tester.pumpAndSettle();

    // One status chip opens the statuses (sidebar option A, 11-12).
    await tester.tap(find.byKey(const Key('task-panel-status')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('task-panel-status-working_on_it')),
    );
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/tasks/t2', {'status': 'working_on_it'});

    await tester.enterText(
      find.byKey(const Key('task-update-body')),
      'Elgin has them.',
    );
    await tester.ensureVisible(find.byKey(const Key('task-update-post')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-update-post')));
    await tester.pumpAndSettle();
    sent(log.last, 'POST', '/tasks/t2/updates', {'body': 'Elgin has them.'});
    expect(find.text('Elgin has them.'), findsOneWidget);
    expect(find.text('Updates · 1'), findsOneWidget);

    await tester.tap(find.byKey(const Key('task-delete')));
    await tester.pumpAndSettle();
    expect(log.where((e) => e.$1 == 'DELETE'), isEmpty);
    await tester.tap(find.byKey(const Key('task-delete-confirm')));
    await tester.pumpAndSettle();
    sent(log.last, 'DELETE', '/tasks/t2', null);
    expect(find.byKey(const ValueKey('task-t2')), findsNothing);
  });

  testWidgets('the board menu archives, and deletes only once the name is '
      'typed back', (tester) async {
    await pumpBoard(tester);

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-archive')));
    await tester.pumpAndSettle();
    expect(log.last.$1, 'POST');
    expect(log.last.$2, '/task-boards/b-me/archive');

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-delete')));
    await tester.pumpAndSettle();
    final confirm = find.byKey(const Key('board-delete-confirm'));
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull);
    await tester.enterText(
      find.byKey(const Key('board-delete-name')),
      'Maya Lopez',
    );
    await tester.pump();
    await tester.tap(confirm);
    await tester.pumpAndSettle();
    // Jose 11-19: and a fresh authenticator code.
    expect(log.last.$1, isNot('DELETE'));
    await tester.enterText(find.byKey(const Key('delete-code')), '123456');
    await tester.tap(find.byKey(const Key('delete-code-go')));
    await tester.pumpAndSettle();
    expect(log.last.$1, 'DELETE');
    expect(log.last.$2, '/task-boards/b-me');
  });

  testWidgets("the Owner picker offers the board's assignable people, not "
      'everybody who owns a task on it', (tester) async {
    await pumpBoard(tester);

    // The name opens the task; Owner and About edit in place (Jose, 2026-11-13).
    await tester.tapAt(
      tester.getTopLeft(find.byKey(const ValueKey('task-t1'))) +
          const Offset(160, 19),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-panel-owner')));
    await tester.pumpAndSettle();

    expect(find.text('Nobody'), findsWidgets);
    // Omar owns t2 on this board but is not assignable here.
    expect(find.text('Omar Feld'), findsNothing);
  });

  // UI queue 84: in the organization view the tabs wrapped into four rows,
  // twenty of them "Main · 0".
  testWidgets('the picker groups boards under their company, so two Mains '
      'are told apart, and no open count', (tester) async {
    await pumpBoard(tester);

    expect(
      find.byKey(const ValueKey('tasks-fold-company:Northside Chimney')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('tasks-fold-company:Everdoors Garage')),
      findsOneWidget,
    );
    expect(find.text('Main'), findsNWidgets(2));
    // No "N open" (Jose 11-20, design 200: Monday shows none).
    expect(find.text('2 open'), findsNothing);
    // Companies in alphabetical order, each Main under its own.
    final everdoors = tester
        .getTopLeft(
          find.byKey(const ValueKey('tasks-fold-company:Everdoors Garage')),
        )
        .dy;
    final northside = tester
        .getTopLeft(
          find.byKey(const ValueKey('tasks-fold-company:Northside Chimney')),
        )
        .dy;
    final mains = [
      for (final e in find.text('Main').evaluate())
        tester.getTopLeft(find.byWidget(e.widget)).dy,
    ]..sort();
    expect(everdoors, lessThan(northside));
    expect(mains.first, greaterThan(everdoors));
    expect(mains.first, lessThan(northside));
    expect(mains.last, greaterThan(northside));
  });

  testWidgets('a board I may only read says so and offers no add', (
    tester,
  ) async {
    await pumpBoard(tester);

    await tester.tap(find.text('Lakeview franchise'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('task-t9')), findsOneWidget);
    expect(find.byKey(const Key('tasks-view-only')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-add-g9')), findsNothing);
  });

  testWidgets('Kanban is a column per status, and search narrows every view', (
    tester,
  ) async {
    await pumpBoard(tester);

    await pickView(tester, 'Kanban');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('kanban-stuck')), findsOneWidget);
    expect(find.text('Working on it · 1'), findsOneWidget);
    expect(find.text('Done · 1'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('tasks-search')), 'omar');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-t2')), findsOneWidget);
    expect(find.byKey(const ValueKey('task-t1')), findsNothing);
  });

  testWidgets('Kanban columns keep a readable width and scroll sideways', (
    tester,
  ) async {
    // Desktop review 2026-11-13, Top 1: at 1440 eleven columns were squeezed
    // into the width, about 30 px each, one letter per line.
    await pumpBoard(tester);

    await pickView(tester, 'Kanban');
    await tester.pumpAndSettle();
    final column = find.byKey(const ValueKey('kanban-stuck'));
    expect(tester.getSize(column).width, 280);
    expect(
      find.ancestor(of: column, matching: find.byType(Scrollable)),
      findsWidgets,
    );
  });

  testWidgets('on a phone the view switcher is one dropdown', (tester) async {
    // Phone review 2026-11-13, item 7: the segments broke "Kanb/an".
    await pumpBoard(tester, size: const Size(390, 844));

    expect(find.byKey(const Key('tasks-view-picker')), findsOneWidget);
    expect(find.byType(SegmentedButton<String>), findsNothing);
    await tester.tap(find.byKey(const Key('tasks-view-picker')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kanban').last);
    await tester.pumpAndSettle();
    expect(
      find.byWidgetPredicate(
        (w) =>
            w.key is ValueKey<String> &&
            (w.key! as ValueKey<String>).value.startsWith('kanban-'),
      ),
      findsWidgets,
    );
  });

  testWidgets('on a phone each task is a card with its status in words', (
    tester,
  ) async {
    await pumpBoard(tester, size: const Size(390, 844));

    expect(find.text('OWNER'), findsNothing);
    expect(find.byKey(const ValueKey('task-t2')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('task-t2')),
        matching: find.text('Stuck'),
      ),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });
}

/// Switches the board's view by its segment, or by the picker where the
/// toolbar has no room for segments.
Future<void> pickView(WidgetTester tester, String name) async {
  final segments = find.byKey(const Key('tasks-view'));
  if (segments.evaluate().isNotEmpty) {
    await tester.tap(find.descendant(of: segments, matching: find.text(name)));
  } else {
    await tester.tap(find.byKey(const Key('tasks-view-picker')));
    await tester.pumpAndSettle();
    await tester.tap(find.text(name).last);
  }
  await tester.pumpAndSettle();
}
