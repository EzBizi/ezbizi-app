import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_transfer_flow.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _desktop = Size(1440, 900);
const _mobile = Size(375, 812);
const _goldenRoot = ValueKey('tasks-golden-root');

void main() {
  testWidgets('directory desktop', (tester) async {
    await _pumpTasks(tester, size: _desktop);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/directory_desktop.png'),
    );
  });

  testWidgets('directory mobile', (tester) async {
    await _pumpTasks(tester, size: _mobile);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/directory_mobile.png'),
    );
  });

  testWidgets('populated board desktop', (tester) async {
    await _pumpTasks(tester, size: _desktop, openBoard: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/board_desktop.png'),
    );
  });

  testWidgets('populated board mobile', (tester) async {
    await _pumpTasks(tester, size: _mobile, openBoard: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/board_mobile.png'),
    );
  });

  testWidgets('task detail desktop', (tester) async {
    await _pumpTasks(tester, size: _desktop, openBoard: true, openDetail: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/detail_desktop.png'),
    );
  });

  testWidgets('task detail mobile', (tester) async {
    await _pumpTasks(tester, size: _mobile, openBoard: true, openDetail: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/detail_mobile.png'),
    );
  });

  testWidgets('board settings desktop', (tester) async {
    await _pumpTasks(tester, size: _desktop, openBoard: true, openAdmin: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/settings_desktop.png'),
    );
  });

  testWidgets('import mapping desktop', (tester) async {
    await _pumpImport(tester);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/import_desktop.png'),
    );
  });

  testWidgets('concurrent edit conflict desktop', (tester) async {
    await _pumpTasks(
      tester,
      size: _desktop,
      openBoard: true,
      conflictOnRename: true,
    );
    final title = find.descendant(
      of: find.byKey(const Key('task-row-task-1')),
      matching: find.byType(TextField),
    );
    await tester.enterText(title, 'Confirm final liner measurements');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/conflict_desktop.png'),
    );
  });

  testWidgets('empty directory desktop', (tester) async {
    await _pumpDirectoryState(tester, size: _desktop, empty: true);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/empty_desktop.png'),
    );
  });

  testWidgets('access refusal mobile', (tester) async {
    await _pumpDirectoryState(tester, size: _mobile, empty: false);

    await expectLater(
      find.byKey(_goldenRoot),
      matchesGoldenFile('../goldens/tasks/refusal_mobile.png'),
    );
  });
}

Future<void> _pumpTasks(
  WidgetTester tester, {
  required Size size,
  bool openBoard = false,
  bool openDetail = false,
  bool openAdmin = false,
  bool conflictOnRename = false,
}) async {
  tester.view
    ..physicalSize = size
    ..devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final api = ApiClient(
    client: MockClient((request) async {
      switch (request.url.path) {
        case '/api/v1/tasks/boards':
          return _json(<String, Object?>{
            'data': <Object?>[
              _board(
                id: 'board-1',
                name: 'August Operations',
                favorite: true,
                folderId: 'folder-1',
                visibility: 'private',
              ),
              _board(id: 'board-2', name: 'Installations'),
              _board(id: 'board-3', name: 'Maintenance follow-up'),
            ],
            'meta': <String, Object?>{
              'capabilities': <String, Object?>{
                'actions': <Object?>[
                  'create_board',
                  'create_folder',
                  'view_archived',
                  'recover_private_boards',
                ],
              },
            },
          });
        case '/api/v1/tasks/folders':
          return _json(<String, Object?>{
            'data': <Object?>[
              <String, Object?>{
                'id': 'folder-1',
                'name': 'Field operations',
                'company_id': 'company-1',
                'franchise_id': null,
                'position': 1,
                'archived': false,
                'actions': <Object?>['update', 'archive'],
              },
            ],
          });
        case '/api/v1/tasks/boards/board-1/rows':
          return _json(<String, Object?>{
            'data': _tasks,
            'meta': <String, Object?>{
              'total': _tasks.length,
              'page': 1,
              'per_page': 50,
              'last_page': 1,
              'group_counts': <Object?>[
                <String, Object?>{
                  'group_id': 'group-todo',
                  'group_by_value': 'group-todo',
                  'count': 4,
                },
                <String, Object?>{
                  'group_id': 'group-done',
                  'group_by_value': 'group-done',
                  'count': 1,
                },
              ],
              'status_summaries': <Object?>[
                <String, Object?>{
                  'status_option_id': 'status-ready',
                  'count': 2,
                },
                <String, Object?>{
                  'status_option_id': 'status-working',
                  'count': 2,
                },
                <String, Object?>{
                  'status_option_id': 'status-done',
                  'count': 1,
                },
              ],
              'view_id': 'view-main',
              'view_version': 3,
              'configuration': _configuration,
            },
          });
        case '/api/v1/tasks/boards/board-1/preference':
          return _json(<String, Object?>{
            'data': <String, Object?>{
              'board_id': 'board-1',
              'is_favorite': true,
              'last_opened_at': '2026-08-28T12:00:00Z',
            },
          });
        case '/api/v1/tasks/boards/board-1/views':
          return _json(<String, Object?>{
            'data': <Object?>[_mainView],
            'meta': <String, Object?>{
              'capabilities': <String, Object?>{
                'create_personal': true,
                'create_shared': true,
              },
            },
          });
        case '/api/v1/tasks/boards/board-1/participants':
          return _json(<String, Object?>{
            'data': <Object?>[
              <String, Object?>{
                'id': 'user-maya',
                'name': 'Maya Ortiz',
                'email': 'maya@a1.test',
              },
              <String, Object?>{
                'id': 'user-luis',
                'name': 'Luis Bell',
                'email': 'luis@a1.test',
              },
            ],
          });
        case '/api/v1/tasks/boards/board-1/tasks/task-1':
          return _json(<String, Object?>{'data': _detailTask});
        case '/api/v1/tasks/task-1':
          if (conflictOnRename) {
            return _json(<String, Object?>{
              'message': 'That task changed while you were editing it.',
              'data': _detailTask,
              'meta': <String, Object?>{
                'current_version': 7,
                'conflicting_fields': <Object?>['title'],
              },
            }, 409);
          }
      }
      return _json(<String, Object?>{
        'message': 'Unexpected golden request.',
      }, 500);
    }),
  );

  await tester.pumpWidget(
    RepaintBoundary(
      key: _goldenRoot,
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TasksScreen(api: api, session: Session(api)),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  if (openBoard) {
    await tester.tap(find.text('August Operations').last);
    await tester.pumpAndSettle();
  }
  if (openDetail) {
    await tester.tap(find.text('Confirm liner measurements').last);
    await tester.pumpAndSettle();
  }
  if (openAdmin) {
    final admin = find.widgetWithText(OutlinedButton, 'Admin');
    await tester.ensureVisible(admin);
    await tester.pump();
    await tester.tap(admin);
    await tester.pumpAndSettle();
  }
}

Future<void> _pumpDirectoryState(
  WidgetTester tester, {
  required Size size,
  required bool empty,
}) async {
  tester.view
    ..physicalSize = size
    ..devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path == '/api/v1/tasks/boards') {
        if (!empty) {
          return _json(<String, Object?>{
            'message': 'Tasks are not available for this view.',
          }, 403);
        }
        return _json(<String, Object?>{
          'data': <Object?>[],
          'meta': <String, Object?>{
            'capabilities': <String, Object?>{
              'actions': <Object?>[
                'create_board',
                'create_folder',
                'view_archived',
              ],
            },
          },
        });
      }
      if (request.url.path == '/api/v1/tasks/folders') {
        return _json(<String, Object?>{'data': <Object?>[]});
      }
      return _json(<String, Object?>{
        'message': 'Unexpected golden request.',
      }, 500);
    }),
  );
  await tester.pumpWidget(
    RepaintBoundary(
      key: _goldenRoot,
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TasksScreen(api: api, session: Session(api)),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpImport(WidgetTester tester) async {
  tester.view
    ..physicalSize = _desktop
    ..devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final api = TaskApi(
    ApiClient(
      client: MockClient((request) async {
        if (request.url.path ==
            '/api/v1/tasks/boards/board-1/imports/preview') {
          return _json(<String, Object?>{'data': _importPreview});
        }
        return _json(<String, Object?>{
          'message': 'Unexpected golden request.',
        }, 500);
      }),
    ),
  );
  final board = TaskBoard.fromJson(
    _board(
      id: 'board-1',
      name: 'August Operations',
      favorite: true,
      folderId: 'folder-1',
      visibility: 'private',
    ),
  );

  await tester.pumpWidget(
    RepaintBoundary(
      key: _goldenRoot,
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: EzBiziTheme.light(),
        home: Scaffold(
          backgroundColor: const Color(0xFFE9EDF1),
          body: SidePanel(
            title: 'Import and export',
            width: 620,
            scrollable: false,
            child: TaskTransferFlow(
              api: api,
              board: board,
              activeViewId: 'view-main',
              pickFile: () async => const PickedBytes(
                'monday-august.csv',
                <int>[84, 97, 115, 107],
              ),
              onUnavailable: (_) {},
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.widgetWithText(OutlinedButton, 'Choose CSV or XLSX'));
  await tester.pumpAndSettle();
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: const <String, String>{'content-type': 'application/json'},
    );

Map<String, Object?> _board({
  required String id,
  required String name,
  bool favorite = false,
  String? folderId,
  String visibility = 'scope',
}) => <String, Object?>{
  'id': id,
  'name': name,
  'description': 'Daily field work and customer commitments.',
  'visibility': visibility,
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': folderId,
  'structure_version': 7,
  'archived': false,
  'is_favorite': favorite,
  'last_opened_at': '2026-08-28T12:00:00Z',
  'actions': id == 'board-1'
      ? <Object?>[
          'create_task',
          'update',
          'archive',
          'manage_structure',
          'manage_members',
          'duplicate',
          'import',
          'export',
          'archive_manifest',
        ]
      : <Object?>[],
  'groups': <Object?>[
    <String, Object?>{
      'id': 'group-todo',
      'name': 'To-Do',
      'color': '#00AEEF',
      'position': 1,
      'order_version': 4,
      'archived': false,
      'actions': <Object?>['update', 'archive'],
    },
    <String, Object?>{
      'id': 'group-done',
      'name': 'Completed',
      'color': '#0B8F5B',
      'position': 2,
      'order_version': 2,
      'archived': false,
      'actions': <Object?>['update', 'archive'],
    },
  ],
  'statuses': <Object?>[
    _status('status-ready', 'Ready', 'not_started', '#5B6672', 1),
    _status('status-working', 'Working on it', 'active', '#E09F3E', 2),
    _status('status-done', 'Done', 'done', '#0B8F5B', 3),
  ],
  'priorities': <Object?>[
    _priority('priority-normal', 'Normal', 'normal', '#41627A', 1),
    _priority('priority-high', 'High', 'high', '#E09F3E', 2),
    _priority('priority-urgent', 'Urgent', 'urgent', '#C62828', 3),
  ],
  'core_columns': <Object?>[
    <String, Object?>{'core_key': 'owner', 'display_label': 'Owner'},
    <String, Object?>{'core_key': 'status', 'display_label': 'Status'},
    <String, Object?>{'core_key': 'priority', 'display_label': 'Priority'},
    <String, Object?>{'core_key': 'due_on', 'display_label': 'Due date'},
  ],
  'custom_columns': <Object?>[],
  'views': <Object?>[_mainView],
};

Map<String, Object?> _status(
  String id,
  String name,
  String category,
  String color,
  int position,
) => <String, Object?>{
  'id': id,
  'name': name,
  'category': category,
  'color': color,
  'position': position,
  'archived': false,
};

Map<String, Object?> _priority(
  String id,
  String name,
  String rank,
  String color,
  int position,
) => <String, Object?>{
  'id': id,
  'name': name,
  'rank': rank,
  'color': color,
  'position': position,
  'archived': false,
};

const Map<String, Object?> _configuration = <String, Object?>{
  'schema': 1,
  'presentation': 'table',
  'filters': <Object?>[],
  'sorts': <Object?>[],
  'hidden_columns': <Object?>[],
  'column_order': <Object?>['owner', 'status', 'priority', 'due_on'],
  'column_widths': <String, Object?>{
    'owner': 150,
    'status': 150,
    'priority': 120,
    'due_on': 130,
  },
  'group_by': null,
  'search': '',
};

const Map<String, Object?> _mainView = <String, Object?>{
  'id': 'view-main',
  'board_id': 'board-1',
  'owner_user_id': null,
  'visibility': 'shared',
  'name': 'Main table',
  'is_locked': false,
  'is_permanent_main': true,
  'configuration': _configuration,
  'version': 3,
  'position': 1,
  'preference': null,
  'actions': <Object?>['update', 'duplicate'],
};

final List<Object?> _tasks = <Object?>[
  _task(
    'task-1',
    'OPS-101',
    'Confirm liner measurements',
    'group-todo',
    owner: 'user-maya',
    status: 'status-working',
    priority: 'priority-urgent',
    due: '2026-08-24',
  ),
  _task(
    'task-2',
    'OPS-102',
    'Call municipal office',
    'group-todo',
    owner: 'user-luis',
    status: 'status-ready',
    priority: 'priority-high',
    due: '2026-08-26',
  ),
  _task(
    'task-3',
    'OPS-103',
    'Order stainless fasteners',
    'group-todo',
    status: 'status-ready',
    priority: 'priority-normal',
    due: '2026-08-28',
  ),
  _task(
    'task-4',
    'OPS-104',
    'Schedule return inspection',
    'group-todo',
    owner: 'user-maya',
    status: 'status-working',
    priority: 'priority-high',
    due: '2026-08-30',
  ),
  _task(
    'task-5',
    'OPS-098',
    'Upload signed inspection',
    'group-done',
    owner: 'user-luis',
    status: 'status-done',
    priority: 'priority-normal',
    due: '2026-08-23',
  ),
];

Map<String, Object?> _task(
  String id,
  String number,
  String title,
  String groupId, {
  String? owner,
  required String status,
  required String priority,
  required String due,
}) => <String, Object?>{
  'id': id,
  'board_id': 'board-1',
  'group_id': groupId,
  'parent_task_id': null,
  'number': number,
  'title': title,
  'description':
      '<p>Use the signed inspection report before releasing the order.</p>',
  'description_plain':
      'Use the signed inspection report before releasing the order.',
  'owner_user_id': owner,
  'collaborator_user_ids': <Object?>[],
  'status_option_id': status,
  'priority_option_id': priority,
  'start_on': '2026-08-22',
  'due_on': due,
  'position': int.parse(id.split('-').last),
  'version': 6,
  'detail_version': 3,
  'custom_values': <String, Object?>{},
  'group_by_value': groupId,
  'subtasks': <Object?>[],
};

final Map<String, Object?> _detailTask =
    _task(
      'task-1',
      'OPS-101',
      'Confirm liner measurements',
      'group-todo',
      owner: 'user-maya',
      status: 'status-working',
      priority: 'priority-urgent',
      due: '2026-08-24',
    )..addAll(<String, Object?>{
      'record_links': <Object?>[
        <String, Object?>{
          'link_id': 'link-1',
          'available': true,
          'type': 'Customer',
          'id': 'customer-1',
          'label': 'Avery Thompson',
          'subtitle': 'Inspection C-1048',
          'route': '/customers/customer-1',
        },
      ],
      'updates': <Object?>[
        <String, Object?>{
          'id': 'update-1',
          'author_user_id': 'user-luis',
          'body': '<p>Tuesday measurements are attached to the inspection.</p>',
          'body_plain': 'Tuesday measurements are attached to the inspection.',
          'version': 2,
          'mention_user_ids': <Object?>[],
          'attachments': <Object?>[],
          'versions': <Object?>[],
          'actions': <Object?>['update', 'archive'],
          'created_at': '2026-08-27T14:30:00Z',
          'updated_at': '2026-08-27T14:30:00Z',
        },
      ],
      'watch': <String, Object?>{
        'preference': null,
        'mandatory': false,
        'watched': false,
      },
      'activities': <Object?>[
        <String, Object?>{
          'id': 'activity-1',
          'actor_user_id': 'user-maya',
          'kind': 'task.updated',
          'summary': 'Maya updated the due date.',
          'data': <String, Object?>{},
          'created_at': '2026-08-27T15:00:00Z',
        },
      ],
      'actions': <Object?>['create_update', 'watch', 'manage_collaborators'],
    });

const Map<String, Object?> _importPreview = <String, Object?>{
  'format': 'csv',
  'headings': <Object?>[
    'Task',
    'Owner email',
    'Status',
    'Group',
    'Due date',
    'Monday mirror',
  ],
  'sample': <Object?>[
    <String, Object?>{
      'Task': 'Confirm liner measurements',
      'Owner email': 'maya@a1.test',
      'Status': 'Working on it',
      'Group': 'To-Do',
      'Due date': '2026-08-24',
      'Monday mirror': 'legacy-column',
    },
  ],
  'unsupported_fields': <Object?>[
    <String, Object?>{
      'heading': 'Monday mirror',
      'reason': 'Mirror columns are not imported.',
    },
  ],
  'counts': <String, Object?>{'rows': 31, 'ready': 29, 'errors': 2},
  'reconciliation': <Object?>[
    <String, Object?>{
      'row': 18,
      'field': 'owner_email',
      'code': 'unknown_owner',
      'source_value': 'newhire@a1.test',
      'message': 'Choose an eligible owner for newhire@a1.test.',
    },
    <String, Object?>{
      'row': 31,
      'field': 'parent_source_row',
      'code': 'missing_parent',
      'source_value': '14',
      'message': 'Parent source row 14 is missing.',
    },
  ],
  'requires_mapping': false,
  'session_token': null,
  'expires_at': null,
};
