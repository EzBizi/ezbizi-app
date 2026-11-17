import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board › People and New board (UI queue 89, desktop 1 and phone 4).
/// Written to the shapes the Task Board session's controller returns:
/// members GET {members: [{id, user_id, name, role}], candidates: [{id, name,
/// role_name}]}, a board's `kind`, `visibility` (main|private), `my_role`,
/// `members_count` and `can_manage`.
void main() {
  // The board last opened is remembered on disk: keep it out of the real one.
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tb');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  late List<(String, String, Object?)> log;

  ApiClient fakeServer({
    required bool manage,
    bool? owner,
    String kind = 'custom',
    bool automations = false,
    String? franchiseId,
    List<Map<String, dynamic>> more = const [],
  }) {
    log = [];
    var boards = <Map<String, dynamic>>[
      {
        'id': 'b-9',
        'kind': kind,
        'name': 'Spring push',
        'visibility': 'private',
        'company_id': null,
        'company_name': null,
        'open_for_me': 0,
        'can_edit': true,
        if (more.isNotEmpty) 'section': 'mine',
      },
      ...more,
    ];
    http.Response json(Object body, [int code = 200]) => http.Response(
      jsonEncode(body),
      code,
      headers: const {'content-type': 'application/json'},
    );
    Map<String, dynamic> board(Map<String, dynamic> b) => {
      ...b,
      'my_role': owner ?? manage ? 'owner' : 'member',
      'franchise_id': franchiseId,
      'can_manage': manage,
      'can_rename': (owner ?? manage) && kind == 'custom',
      'members_count': 2,
      'automations_count': 1,
      'statuses': [
        {
          'id': 's-stuck',
          'name': 'Stuck',
          'colour': '#C53B3B',
          'is_done': false,
          'position': 0,
        },
      ],
      'columns': <dynamic>[],
      'groups': [
        {'id': 'g1', 'name': 'This week', 'colour': '#3A7BD5', 'position': 0},
      ],
      'assignable': [
        {'id': 'u-2', 'name': 'Tobin Marsh'},
      ],
      'tasks': <dynamic>[],
    };

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
              'permissions': [
                'tasks.view',
                'tasks.edit',
                'tasks.create_boards',
                if (automations) ...['automations.view', 'automations.create'],
              ],
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
        if (path == '/task-boards' && request.method == 'POST') {
          final made = {
            ...boards.first,
            'id': 'b-10',
            'name': (body as Map)['name'],
            'visibility': body['visibility'],
          };
          boards = [...boards, made];
          return json({'data': board(made)}, 201);
        }
        if (path == '/task-boards') return json({'data': boards});
        if (path == '/automations' && request.method == 'GET') {
          // An owner without the permission and no rules yet (2798c466).
          if (!automations) return json({'message': 'Forbidden'}, 403);
          return json({
            'data': [
              {'id': 'r-1', 'trigger_key': 'task.status_changed'},
              {'id': 'r-2', 'trigger_key': 'task.created'},
              {'id': 'r-3', 'trigger_key': 'lead.created'},
            ],
          });
        }
        if (path == '/automations/r-3') throw StateError('read a lead rule');
        if (path == '/automations/r-1' || path == '/automations/r-2') {
          final mine = path.endsWith('r-1');
          return json({
            'data': {
              'id': path.split('/').last,
              'name': 'whatever it was called',
              'trigger_key': mine ? 'task.status_changed' : 'task.created',
              'is_active': true,
              'conditions': {
                'all': [
                  {
                    'field': 'board_id',
                    'op': 'eq',
                    'value': mine ? 'b-9' : 'b-other',
                  },
                  if (mine)
                    {'field': 'status_id', 'op': 'eq', 'value': 's-stuck'},
                ],
              },
              'actions': [
                {'type': 'notify', 'message': 'x', 'to_assignee': true},
              ],
            },
          });
        }
        if (path == '/automations' && request.method == 'POST') {
          return json({
            'data': {'id': 'r-9'},
          }, 201);
        }
        if (path == '/task-boards/b-9/members' && request.method == 'GET') {
          return json({
            'data': {
              'members': [
                {
                  'id': 'm-1',
                  'user_id': 'u-1',
                  'name': 'Omar Feld',
                  'role': 'owner',
                },
                {
                  'id': 'm-2',
                  'user_id': 'u-2',
                  'name': 'Tobin Marsh',
                  'role': 'member',
                },
              ],
              'candidates': manage
                  ? [
                      {
                        'id': 'u-3',
                        'name': 'Rhea Quist',
                        'role_name': 'Technician',
                      },
                    ]
                  : <dynamic>[],
            },
          });
        }
        if (request.method != 'GET') return json({'data': <String, dynamic>{}});
        for (final b in boards) {
          if (path == '/task-boards/${b['id']}') {
            return json({'data': board(b)});
          }
        }
        return json({'message': 'Not found'}, 404);
      }),
    );
  }

  Future<void> pump(
    WidgetTester tester, {
    bool manage = true,
    bool? owner,
    String kind = 'custom',
    double width = 1400,
    bool automations = false,
    String? franchiseId,
    List<Map<String, dynamic>> more = const [],
  }) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(
      manage: manage,
      owner: owner,
      kind: kind,
      automations: automations,
      franchiseId: franchiseId,
      more: more,
    );
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

  testWidgets('the owner adds people, changes a role, takes one off and opens '
      'the board', (tester) async {
    await pump(tester);

    expect(find.text('Invite · 2'), findsOneWidget);
    expect(find.text('Private'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tasks-people')));
    await tester.pumpAndSettle();
    expect(find.text('Tobin Marsh'), findsOneWidget);

    await tester.tap(find.byKey(const Key('board-people-add')));
    await tester.pumpAndSettle();
    expect(find.text('Technician'), findsOneWidget);
    await tester.tap(find.text('Rhea Quist'));
    await tester.pumpAndSettle();
    sent(log.last, 'POST', '/task-boards/b-9/members', {
      'user_id': 'u-3',
      'role': 'member',
    });

    await tester.tap(find.byKey(const ValueKey('board-member-role-m-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Viewer').last);
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/task-boards/b-9/members/m-2', {'role': 'viewer'});

    await tester.tap(find.byKey(const ValueKey('board-member-remove-m-2')));
    await tester.pumpAndSettle();
    sent(log.last, 'DELETE', '/task-boards/b-9/members/m-2', null);

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/task-boards/b-9', {'visibility': 'main'});
  });

  testWidgets('a member reads who is on it and changes nothing', (
    tester,
  ) async {
    await pump(tester, manage: false);

    await tester.tap(find.byKey(const Key('tasks-people')));
    await tester.pumpAndSettle();
    expect(find.text('Member'), findsOneWidget);
    expect(find.byKey(const Key('board-people-add')), findsNothing);
    expect(find.byKey(const ValueKey('board-member-remove-m-2')), findsNothing);

    await tester.tap(find.text('Open'), warnIfMissed: false);
    await tester.pumpAndSettle();
    expect(log, isEmpty);
  });

  testWidgets('New board at the foot of the picker creates one and opens it', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.byKey(const Key('tasks-new-board')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('new-board-name')), 'Gutters');
    await tester.tap(find.byKey(const Key('new-board-create')));
    await tester.pumpAndSettle();

    // Organization view: filed nowhere narrower, Private unless chosen.
    sent(log.single, 'POST', '/task-boards', {
      'name': 'Gutters',
      'visibility': 'private',
    });
    expect(find.text('Gutters'), findsOneWidget);
  });

  /// Jose could not find People (UI queue 95, 7): on a phone it is a chip
  /// under the picker, not inside the menu.
  testWidgets('on a phone People is in the toolbar ⋯, off the one line', (
    tester,
  ) async {
    await pump(tester, width: 400);

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-more-people')));
    await tester.pumpAndSettle();
    expect(find.text('Tobin Marsh'), findsOneWidget);
  });

  testWidgets('a company or branch board has People too, and no Open or '
      'Private', (tester) async {
    await pump(tester, kind: 'franchise');

    expect(find.byKey(const Key('tasks-people')), findsOneWidget);
    expect(find.text('Private'), findsNothing);
  });

  testWidgets('on My board, adding someone is said to share it', (
    tester,
  ) async {
    await pump(tester, kind: 'user');

    await tester.tap(find.byKey(const Key('tasks-people')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('board-people-shares')), findsOneWidget);
    expect(find.byKey(const Key('board-visibility')), findsNothing);
  });

  /// UI queue 95, 1: grouped by why each board is here, everyone's by person.
  testWidgets("the picker groups my boards, shared ones and everyone's by "
      'person', (tester) async {
    await pump(
      tester,
      more: [
        {
          'id': 'b-20',
          'kind': 'custom',
          'name': 'Office move',
          'section': 'shared',
          'owner': {'id': 'u-5', 'name': 'Dana Ruiz'},
          'open_for_me': 1,
        },
        {
          'id': 'b-21',
          'kind': 'user',
          'name': 'Ben',
          'section': 'everyone',
          'owner': {'id': 'u-6', 'name': 'Ben Okafor'},
          'open_for_me': 0,
        },
      ],
    );

    for (final header in ['MY BOARDS', 'SHARED WITH ME', "EVERYONE'S BOARDS"]) {
      expect(find.text(header), findsOneWidget);
    }
    expect(find.text('Office move · from Dana Ruiz'), findsOneWidget);
    expect(find.text('Ben Okafor · 1 board'), findsOneWidget);
    expect(find.text("Ben Okafor's board"), findsOneWidget);
  });

  testWidgets("Automate lists this board's rules as sentences and saves a "
      'recipe as a rule limited to the board, switched on', (tester) async {
    await pump(tester, automations: true, kind: 'franchise');

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-automate')));
    await tester.pumpAndSettle();
    expect(
      find.text('When the status changes to Stuck, notify the person assigned'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('board-rule-r-2')), findsNothing);

    await tester.tap(find.byKey(const Key('board-rule-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-then')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('assign it to').last);
    await tester.pumpAndSettle();
    final save = find.byKey(const Key('board-rule-save'));
    expect(tester.widget<FilledButton>(save).onPressed, isNull);
    await tester.tap(find.byKey(const Key('board-rule-person')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tobin Marsh').last);
    await tester.pumpAndSettle();
    await tester.tap(save);
    await tester.pumpAndSettle();

    sent(log[0], 'POST', '/automations', {
      'name': 'Spring push: When a task is created, assign it to Tobin Marsh',
      'trigger_key': 'task.created',
      'conditions': {
        'all': [
          {'field': 'board_id', 'op': 'eq', 'value': 'b-9'},
        ],
      },
      'actions': [
        {'type': 'task.assign', 'user_field': 'user:u-2'},
      ],
      'scope_type': null,
      'scope_id': null,
    });
    sent(log[1], 'PATCH', '/automations/r-9', {'is_active': true});

    await tester.tap(find.byKey(const ValueKey('board-rule-on-r-1')));
    await tester.pumpAndSettle();
    sent(log.last, 'PATCH', '/automations/r-1', {'is_active': false});
  });

  testWidgets('a status recipe stores the status by name', (tester) async {
    await pump(tester, automations: true, kind: 'franchise');

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-automate')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-when')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('a task is overdue').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-then')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('set the status to').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-to-status')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Stuck').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-save')));
    await tester.pumpAndSettle();

    final body = log.first.$3 as Map;
    expect(
      body['name'],
      'Spring push: When a task is overdue, set the status to Stuck',
    );
    expect(body['trigger_key'], 'task.overdue');
    expect(body['actions'], [
      {'type': 'change_status', 'value': 'Stuck'},
    ]);
  });

  testWidgets("a board's owner automates it without the Automations "
      "permissions, scoped to the board's own place", (tester) async {
    await pump(tester, franchiseId: 'f-1');

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-automate')));
    await tester.pumpAndSettle();
    expect(find.text('No automations on this board yet.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('board-rule-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('board-rule-save')));
    await tester.pumpAndSettle();

    final body = log.first.$3 as Map;
    expect(body['scope_type'], 'franchise');
    expect(body['scope_id'], 'f-1');
    expect(body['actions'], [
      {
        'type': 'notify',
        'message': '{{title}} was added (Spring push)',
        'to_assignee': true,
      },
    ]);
  });

  testWidgets('Add people to any board adds people, and neither makes the '
      'board Open nor automates it', (tester) async {
    // tasks.manage_members: can_manage, but not the owner, so no can_rename.
    await pump(tester, manage: true, owner: false);

    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tasks-automate')), findsNothing);
    await tester.tapAt(Offset.zero);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-people')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('board-people-add')), findsOneWidget);

    await tester.tap(find.text('Open'), warnIfMissed: false);
    await tester.pumpAndSettle();
    expect(log.where((c) => c.$1 == 'PATCH'), isEmpty);
  });

  testWidgets('without Automations, on a board they do not manage, there is '
      'no Automate', (tester) async {
    await pump(tester, kind: 'franchise', manage: false);

    expect(find.byKey(const Key('tasks-automate')), findsNothing);
  });

  testWidgets('the top bar counts rules and people, offers Integrate, and '
      'Import adds one task per pasted line', (tester) async {
    await pump(tester, automations: true, kind: 'franchise');

    expect(find.text('Invite · 2'), findsOneWidget);
    await tester.tap(find.byKey(const Key('board-menu')));
    await tester.pumpAndSettle();
    expect(find.text('Automate · 1'), findsOneWidget);
    expect(find.byKey(const Key('tasks-integrate')), findsOneWidget);
    await tester.tapAt(Offset.zero);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('tasks-new-more')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tasks-import')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('tasks-import-text')),
      'Sweep the flue\tMonday\n\nOrder a cap,12\n',
    );
    await tester.tap(find.byKey(const Key('tasks-import-go')));
    await tester.pumpAndSettle();
    final posts = [
      for (final (m, p, b) in log)
        if (m == 'POST' && p == '/tasks') (b as Map)['title'],
    ];
    expect(posts, ['Sweep the flue', 'Order a cap']);
  });
}
