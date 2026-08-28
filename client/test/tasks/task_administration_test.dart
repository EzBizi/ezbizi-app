import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_board_settings.dart';
import 'package:ezbizi_client/screens/tasks/task_board_members.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_mobile_list.dart';
import 'package:ezbizi_client/screens/tasks/task_structure_editor.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
    'directory and membership choices come from server-authored actions',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.url.path.endsWith('/participants')) {
              return _json(200, <String, Object?>{'data': <Object?>[]});
            }
            return _json(200, <String, Object?>{
              'data': <Object?>[
                _board(actions: const <Object?>['update', 'manage_members']),
              ],
              'meta': <String, Object?>{
                'total': 1,
                'capabilities': <String, Object?>{
                  'actions': <Object?>['create_board'],
                },
              },
            });
          }),
        ),
      );

      final directory = await api.boardDirectorySnapshot();
      await api.participants(
        'board-1',
        purpose: TaskParticipantPurpose.membership,
      );

      expect(directory.value?.can('create_board'), isTrue);
      expect(directory.value?.boards.single.can('manage_members'), isTrue);
      expect(requests.last.url.queryParameters['purpose'], 'membership');
    },
  );

  test(
    'folder preferences membership and recovery use typed current-place contracts',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            final path = request.url.path;
            if (path.endsWith('/tasks/folders')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{
                    'id': 'folder-1',
                    'name': 'Field work',
                    'company_id': 'company-1',
                    'franchise_id': null,
                    'position': 2,
                    'archived': false,
                    'actions': <Object?>['update', 'archive'],
                  },
                ],
              });
            }
            if (path.endsWith('/preference')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'board_id': 'board-1',
                  'is_favorite': true,
                  'last_opened_at': '2026-08-28T12:00:00Z',
                },
              });
            }
            if (path.endsWith('/members') && request.method == 'GET') {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{
                    'id': 'member-1',
                    'user_id': 'user-1',
                    'name': 'Alex Rivera',
                    'email': 'alex@example.com',
                    'role': 'owner',
                    'active': true,
                    'actions': <Object?>['change_role'],
                  },
                ],
              });
            }
            if (path.endsWith('/recovery')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'board_id': 'board-1',
                  'owner_user_id': 'user-2',
                },
              });
            }
            return _json(500, <String, Object?>{
              'message': 'Unexpected request',
            });
          }),
        ),
      );

      final folders = await api.folders();
      final preference = await api.saveBoardPreference(
        'board-1',
        isFavorite: true,
        markOpened: true,
      );
      final members = await api.boardMembers('board-1');
      final recovery = await api.recoverBoardOwner('board-1', 'user-2');

      expect(folders.value?.single.can('archive'), isTrue);
      expect(preference.value?.isFavorite, isTrue);
      expect(members.value?.single.role, TaskBoardMemberRole.owner);
      expect(members.value?.single.can('change_role'), isTrue);
      expect(recovery.value?.ownerUserId, 'user-2');
      expect(jsonDecode(requests[1].body), <String, Object?>{
        'is_favorite': true,
        'mark_opened': true,
      });
      expect(jsonDecode(requests.last.body), <String, Object?>{
        'new_owner_user_id': 'user-2',
      });
    },
  );

  test('structure writes carry and return the authoritative version', () async {
    final requests = <http.Request>[];
    var version = 10;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          version++;
          final path = request.url.path;
          final data = path.endsWith('/groups')
              ? <String, Object?>{
                  'id': 'group-2',
                  'name': 'Scheduled',
                  'color': '#0B7285',
                  'position': 2,
                  'order_version': 1,
                  'archived': false,
                  'actions': <Object?>['update', 'archive'],
                }
              : path.endsWith('/statuses')
              ? <String, Object?>{
                  'id': 'status-2',
                  'name': 'Working',
                  'color': '#E59A24',
                  'category': 'active',
                  'position': 2,
                  'archived': false,
                  'actions': <Object?>['update', 'archive'],
                }
              : path.endsWith('/priorities')
              ? <String, Object?>{
                  'id': 'priority-2',
                  'name': 'Urgent',
                  'color': '#C92A2A',
                  'rank': 'urgent',
                  'position': 2,
                  'archived': false,
                  'actions': <Object?>['update', 'archive'],
                }
              : <String, Object?>{
                  'id': 'column-2',
                  'name': 'Estimate',
                  'type': 'number',
                  'number_suffix': 'h',
                  'position': 2,
                  'archived': false,
                  'actions': <Object?>['update', 'archive'],
                  'options': <Object?>[],
                };
          return _json(200, <String, Object?>{
            'data': data,
            'meta': <String, Object?>{'structure_version': version},
          });
        }),
      ),
    );

    final group = await api.createGroup(
      'board-1',
      expectedStructureVersion: 10,
      name: 'Scheduled',
      color: '#0B7285',
    );
    final status = await api.createStatus(
      'board-1',
      expectedStructureVersion: 11,
      name: 'Working',
      category: TaskStatusCategory.active,
      color: '#E59A24',
    );
    final priority = await api.createPriority(
      'board-1',
      expectedStructureVersion: 12,
      name: 'Urgent',
      rank: TaskPriorityRank.urgent,
      color: '#C92A2A',
    );
    final column = await api.createColumn(
      'board-1',
      expectedStructureVersion: 13,
      name: 'Estimate',
      type: TaskColumnKind.number,
      numberSuffix: 'h',
    );

    expect(group.value?.structureVersion, 11);
    expect(status.value?.structureVersion, 12);
    expect(priority.value?.structureVersion, 13);
    expect(column.value?.structureVersion, 14);
    expect(jsonDecode(requests[0].body), <String, Object?>{
      'expected_structure_version': 10,
      'name': 'Scheduled',
      'color': '#0B7285',
    });
    expect(jsonDecode(requests[3].body), <String, Object?>{
      'expected_structure_version': 13,
      'name': 'Estimate',
      'type': 'number',
      'number_suffix': 'h',
    });
  });

  test(
    'structure edit archive reorder and option routes stay versioned',
    () async {
      final requests = <http.Request>[];
      var version = 20;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            version++;
            final path = request.url.path;
            final Map<String, Object?> data;
            if (path.contains('/groups/')) {
              data = <String, Object?>{
                'id': 'group-1',
                'name': 'Ready',
                'color': null,
                'position': 1,
                'order_version': 2,
                'archived': false,
              };
            } else if (path.contains('/statuses/')) {
              data = <String, Object?>{
                'id': 'status-1',
                'name': 'Done',
                'color': '#16A34A',
                'category': 'completed',
                'position': 1,
                'archived': true,
              };
            } else if (path.endsWith('/priorities/reorder')) {
              data = <String, Object?>{
                'ordered_ids': <Object?>['priority-2', 'priority-1'],
              };
            } else if (path.contains('/columns/core/')) {
              data = <String, Object?>{
                'core_key': 'task',
                'display_label': 'Work item',
              };
            } else {
              data = <String, Object?>{
                'id': 'option-1',
                'name': 'Install',
                'color': '#0B7285',
                'position': 1,
                'archived': false,
              };
            }
            return _json(200, <String, Object?>{
              'data': data,
              'meta': <String, Object?>{'structure_version': version},
            });
          }),
        ),
      );

      await api.updateGroup(
        'board-1',
        'group-1',
        expectedStructureVersion: 20,
        name: 'Ready',
      );
      await api.setStatusArchived(
        'board-1',
        'status-1',
        expectedStructureVersion: 21,
        archived: true,
      );
      await api.reorderPriorities(
        'board-1',
        expectedStructureVersion: 22,
        orderedIds: const <String>['priority-2', 'priority-1'],
      );
      await api.updateCoreLabel(
        'board-1',
        'task',
        expectedStructureVersion: 23,
        displayLabel: 'Work item',
      );
      final option = await api.createColumnOption(
        'board-1',
        'column-1',
        expectedStructureVersion: 24,
        name: 'Install',
        color: '#0B7285',
      );

      expect(option.value?.structureVersion, 25);
      expect(
        requests.map((request) => '${request.method} ${request.url.path}'),
        <String>[
          'PATCH /api/v1/tasks/boards/board-1/groups/group-1',
          'POST /api/v1/tasks/boards/board-1/statuses/status-1/archive',
          'POST /api/v1/tasks/boards/board-1/priorities/reorder',
          'PATCH /api/v1/tasks/boards/board-1/columns/core/task',
          'POST /api/v1/tasks/boards/board-1/columns/column-1/options',
        ],
      );
    },
  );

  test(
    'administration mutations use their declared routes and actions',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            final path = request.url.path;
            if (path.contains('/folders/')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'id': 'folder-1',
                  'name': 'Field work',
                  'company_id': 'company-1',
                  'franchise_id': null,
                  'position': 1,
                  'archived': true,
                  'actions': <Object?>['restore'],
                },
              });
            }
            if (path.contains('/members/')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'id': 'member-1',
                  'user_id': 'user-1',
                  'name': 'Alex Rivera',
                  'email': 'alex@example.com',
                  'role': 'member',
                  'active': false,
                  'actions': <Object?>[],
                },
              });
            }
            if (path.endsWith('/duplicate') || path.endsWith('/archive')) {
              return _json(200, <String, Object?>{
                'data': _board(actions: const <Object?>['restore']),
                'meta': <String, Object?>{'structure_version': 15},
              });
            }
            if (path.endsWith('/groups/reorder')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'ordered_ids': <Object?>['group-2', 'group-1'],
                },
                'meta': <String, Object?>{'structure_version': 16},
              });
            }
            return _json(500, <String, Object?>{
              'message': 'Unexpected request',
            });
          }),
        ),
      );

      final folder = await api.setFolderArchived('folder-1', archived: true);
      final member = await api.revokeBoardMember('board-1', 'member-1');
      final board = await api.setBoardArchived(
        'board-1',
        expectedStructureVersion: 14,
        archived: true,
      );
      final duplicate = await api.duplicateBoard(
        'board-1',
        expectedStructureVersion: 14,
        name: 'Operations copy',
        includeTasks: false,
      );
      final reorder = await api.reorderGroups(
        'board-1',
        expectedStructureVersion: 15,
        orderedIds: const <String>['group-2', 'group-1'],
      );

      expect(folder.value?.archived, isTrue);
      expect(member.value?.active, isFalse);
      expect(board.value?.structureVersion, 15);
      expect(duplicate.value?.data.name, 'Operations');
      expect(reorder.value?.structureVersion, 16);
      expect(
        requests.map((request) => '${request.method} ${request.url.path}'),
        <String>[
          'POST /api/v1/tasks/folders/folder-1/archive',
          'DELETE /api/v1/tasks/boards/board-1/members/member-1',
          'POST /api/v1/tasks/boards/board-1/archive',
          'POST /api/v1/tasks/boards/board-1/duplicate',
          'POST /api/v1/tasks/boards/board-1/groups/reorder',
        ],
      );
    },
  );

  testWidgets('board settings save only server-authored actions', (
    tester,
  ) async {
    http.Request? saved;
    TaskBoard? changed;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          saved = request;
          return _json(200, <String, Object?>{
            'data': _board(
              name: 'Installation board',
              actions: const <Object?>['update', 'archive'],
            ),
          });
        }),
      ),
    );
    final board = TaskBoard.fromJson(
      _board(actions: const <Object?>['update', 'archive']),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskBoardSettings(
            api: api,
            board: board,
            folders: const <TaskFolder>[],
            onChanged: (value) => changed = value,
            onUnavailable: (_) {},
          ),
        ),
      ),
    );
    await tester.enterText(
      find.byKey(const ValueKey('task-board-name')),
      'Installation board',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Save changes'));
    await tester.pumpAndSettle();

    expect(changed?.name, 'Installation board');
    expect(saved?.url.path, '/api/v1/tasks/boards/board-1');
    expect(jsonDecode(saved!.body)['expected_structure_version'], 4);
    expect(find.text('Archive board'), findsOneWidget);
    expect(find.text('Restore board'), findsNothing);
  });

  testWidgets('members render and mutate only server-authored row actions', (
    tester,
  ) async {
    final requests = <http.Request>[];
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          if (request.url.path.endsWith('/participants')) {
            return _json(200, <String, Object?>{
              'data': <Object?>[
                <String, Object?>{
                  'id': 'user-3',
                  'name': 'Casey Morgan',
                  'email': 'casey@example.com',
                },
              ],
            });
          }
          if (request.method == 'DELETE') {
            return _json(200, <String, Object?>{
              'data': _member(active: false, actions: const <Object?>[]),
            });
          }
          return _json(200, <String, Object?>{
            'data': <Object?>[
              <String, Object?>{
                'id': 'owner-1',
                'user_id': 'user-1',
                'name': 'Alex Rivera',
                'email': 'alex@example.com',
                'role': 'owner',
                'active': true,
                'actions': <Object?>[],
              },
              _member(
                active: true,
                actions: const <Object?>['change_role', 'revoke'],
              ),
            ],
          });
        }),
      ),
    );
    final board = TaskBoard.fromJson(
      _board(actions: const <Object?>['manage_members']),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskBoardMembers(api: api, board: board, onUnavailable: (_) {}),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Alex Rivera'), findsOneWidget);
    expect(find.text('Riley Chen'), findsOneWidget);
    expect(find.byTooltip('Remove Alex Rivera'), findsNothing);
    expect(find.byTooltip('Remove Riley Chen'), findsOneWidget);

    await tester.tap(find.byTooltip('Remove Riley Chen'));
    await tester.pumpAndSettle();
    expect(
      requests.last.url.path,
      '/api/v1/tasks/boards/board-1/members/member-2',
    );
    expect(find.text('Riley Chen'), findsNothing);
  });

  testWidgets('recovery stays content blind and installs an eligible owner', (
    tester,
  ) async {
    http.Request? recoveryRequest;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          if (request.url.queryParameters['recoverable'] == '1') {
            return _json(200, <String, Object?>{
              'data': <Object?>[
                <String, Object?>{
                  'id': 'secret-board-id',
                  'company_id': 'company-1',
                  'franchise_id': null,
                  'actions': <Object?>['recover_owner'],
                },
              ],
              'meta': <String, Object?>{
                'capabilities': <String, Object?>{
                  'actions': <Object?>['recover_boards'],
                },
              },
            });
          }
          if (request.url.path.endsWith('/participants')) {
            return _json(200, <String, Object?>{
              'data': <Object?>[
                <String, Object?>{
                  'id': 'user-2',
                  'name': 'Eligible Owner',
                  'email': 'eligible@example.com',
                },
              ],
            });
          }
          recoveryRequest = request;
          return _json(200, <String, Object?>{
            'data': <String, Object?>{
              'board_id': 'secret-board-id',
              'owner_user_id': 'user-2',
            },
          });
        }),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskBoardRecoveryList(api: api, onUnavailable: (_) {}),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Private board needing an owner'), findsOneWidget);
    expect(find.text('secret-board-id'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('task-recovery-owner-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Eligible Owner · eligible@example.com').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Assign owner'));
    await tester.pumpAndSettle();

    expect(recoveryRequest?.method, 'POST');
    expect(
      recoveryRequest?.url.path,
      '/api/v1/tasks/boards/secret-board-id/recovery',
    );
    expect(jsonDecode(recoveryRequest!.body), <String, Object?>{
      'new_owner_user_id': 'user-2',
    });
    expect(find.text('Private board needing an owner'), findsNothing);
  });

  testWidgets('create-task action shows group add for a non-manager member', (
    tester,
  ) async {
    String? targetGroupId;
    final boardJson = _board(actions: const <Object?>['create_task'])
      ..['groups'] = <Object?>[
        <String, Object?>{
          'id': 'group-1',
          'name': 'To do',
          'color': null,
          'position': 1,
          'order_version': 1,
          'archived': false,
          'actions': <Object?>[],
        },
      ];
    final board = TaskBoard.fromJson(boardJson);
    expect(board.can('import'), isFalse);
    expect(board.can('manage_structure'), isFalse);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskMobileList(
            board: board,
            rows: const <TaskRow>[],
            onSelected: (_) {},
            onAddTask: board.can('create_task')
                ? (groupId) => targetGroupId = groupId
                : null,
          ),
        ),
      ),
    );

    await tester.tap(find.byTooltip('Add task to To do'));
    expect(targetGroupId, 'group-1');
  });

  testWidgets('structure editor uses row actions and advances server version', (
    tester,
  ) async {
    int? authoritativeVersion;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          return _json(200, <String, Object?>{
            'data': <String, Object?>{
              'id': 'group-1',
              'name': 'To do',
              'color': '#0B7285',
              'position': 1,
              'order_version': 2,
              'archived': true,
              'actions': <Object?>['restore'],
            },
            'meta': <String, Object?>{'structure_version': 5},
          });
        }),
      ),
    );
    final boardJson = _board(actions: const <Object?>['manage_structure'])
      ..['groups'] = <Object?>[
        <String, Object?>{
          'id': 'group-1',
          'name': 'To do',
          'color': '#0B7285',
          'position': 1,
          'order_version': 1,
          'archived': false,
          'actions': <Object?>['update', 'archive'],
        },
        <String, Object?>{
          'id': 'group-2',
          'name': 'Locked',
          'color': null,
          'position': 2,
          'order_version': 1,
          'archived': false,
          'actions': <Object?>[],
        },
      ];

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskStructureEditor(
            api: api,
            board: TaskBoard.fromJson(boardJson),
            onStructureChanged: (value) => authoritativeVersion = value,
            onUnavailable: (_) {},
          ),
        ),
      ),
    );

    expect(find.text('Groups'), findsOneWidget);
    expect(find.text('Statuses'), findsOneWidget);
    expect(find.text('Priorities'), findsOneWidget);
    expect(find.text('Core columns'), findsOneWidget);
    expect(find.text('Custom columns'), findsOneWidget);
    expect(find.byTooltip('Archive To do'), findsOneWidget);
    expect(find.byTooltip('Archive Locked'), findsNothing);

    await tester.tap(find.byTooltip('Archive To do'));
    await tester.pumpAndSettle();
    expect(authoritativeVersion, 5);
    expect(find.byTooltip('Restore To do'), findsOneWidget);
  });
}

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);

Map<String, Object?> _board({
  String name = 'Operations',
  List<Object?> actions = const <Object?>[],
}) => <String, Object?>{
  'id': 'board-1',
  'name': name,
  'description': null,
  'visibility': 'scope',
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': null,
  'structure_version': 4,
  'archived': false,
  'is_favorite': false,
  'last_opened_at': null,
  'actions': actions,
  'groups': <Object?>[],
  'statuses': <Object?>[],
  'priorities': <Object?>[],
  'core_columns': <Object?>[],
  'custom_columns': <Object?>[],
  'views': <Object?>[],
};

Map<String, Object?> _member({
  required bool active,
  required List<Object?> actions,
}) => <String, Object?>{
  'id': 'member-2',
  'user_id': 'user-2',
  'name': 'Riley Chen',
  'email': 'riley@example.com',
  'role': 'member',
  'active': active,
  'actions': actions,
};
