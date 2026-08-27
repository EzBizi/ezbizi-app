import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('loads a typed board directory from the Tasks endpoint', () async {
    var call = 0;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          expect(request.method, 'GET');
          call++;
          if (call == 1) {
            expect(request.url.path, '/api/v1/tasks/boards');
          } else if (call == 2) {
            expect(request.url.path, '/api/v1/tasks/boards/board-1');
          } else {
            expect(request.url.path, '/api/v1/tasks/boards/board-1/rows');
            expect(request.url.queryParameters['view_id'], 'view-1');
            return _json(200, <String, Object?>{
              'data': <Object?>[],
              'meta': <String, Object?>{
                'total': 0,
                'page': 1,
                'per_page': 50,
                'last_page': 1,
                'group_counts': <Object?>[],
                'status_summaries': <Object?>[],
                'view_id': 'view-1',
                'view_version': 1,
                'configuration': _emptyConfiguration(),
              },
            });
          }
          return _json(200, <String, Object?>{
            'data': call == 1 ? <Object?>[_minimalBoard()] : _minimalBoard(),
            'meta': <String, Object?>{'total': 1},
          });
        }),
      ),
    );

    final result = await api.boardDirectory();
    final board = await api.board('board-1');
    final rows = await api.boardRows('board-1', viewId: 'view-1');

    expect(result, isA<TaskApiSuccess<List<TaskBoard>>>());
    expect(result.value?.single.name, 'Operations');
    expect(board.value?.id, 'board-1');
    expect(rows.value?.total, 0);
  });

  test(
    'puts each concurrency token only on the mutation that owns it',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            return _json(200, <String, Object?>{'data': _minimalTask()});
          }),
        ),
      );

      await api.updateTask(
        'task-1',
        expectedTaskVersion: 7,
        changes: const <String, Object?>{'title': 'Changed'},
      );
      await api.updateTaskUpdate(
        'task-1',
        'update-1',
        expectedUpdateVersion: 3,
        body: '<p>Changed</p>',
        mentionUserIds: const <String>['user-1'],
      );
      await api.updateView(
        'board-1',
        'view-1',
        expectedViewVersion: 5,
        expectedStructureVersion: 12,
        changes: const <String, Object?>{
          'name': 'Mine',
          'visibility': 'shared',
        },
      );
      await api.updateBoard(
        'board-1',
        expectedStructureVersion: 11,
        changes: const <String, Object?>{'name': 'Renamed'},
      );
      await api.moveTask(
        const TaskMoveMutation(
          boardId: 'board-1',
          taskId: 'task-1',
          destinationGroupId: 'group-2',
          afterTaskId: 'task-2',
          expectedTaskVersion: 7,
          expectedSourceGroupOrderVersion: 2,
          expectedDestinationGroupOrderVersion: 6,
        ),
      );

      expect(jsonDecode(requests[0].body), <String, Object?>{
        'expected_version': 7,
        'title': 'Changed',
      });
      expect(jsonDecode(requests[1].body), <String, Object?>{
        'expected_update_version': 3,
        'body': '<p>Changed</p>',
        'mention_user_ids': <Object?>['user-1'],
      });
      expect(jsonDecode(requests[2].body), <String, Object?>{
        'expected_view_version': 5,
        'expected_structure_version': 12,
        'name': 'Mine',
        'visibility': 'shared',
      });
      expect(jsonDecode(requests[3].body), <String, Object?>{
        'expected_structure_version': 11,
        'name': 'Renamed',
      });
      expect(jsonDecode(requests[4].body), <String, Object?>{
        'task_id': 'task-1',
        'destination_group_id': 'group-2',
        'after_task_id': 'task-2',
        'expected_task_version': 7,
        'expected_source_order_version': 2,
        'expected_destination_order_version': 6,
      });
    },
  );

  test(
    'uses server-scoped participants and versioned column and atomic bulk mutations',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.url.path.endsWith('/participants')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{
                    'id': 'user-1',
                    'name': 'Taylor',
                    'email': 'taylor@example.com',
                  },
                ],
              });
            }
            if (request.url.path.endsWith('/bulk')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[_minimalTask()],
                'meta': <String, Object?>{'total': 1},
              });
            }
            return _json(200, <String, Object?>{
              'data': <String, Object?>{
                'task_id': 'task-1',
                'column_definition_id': 'column-1',
                'type': 'checkbox',
                'value': true,
                'version': 8,
                'detail_version': 3,
              },
            });
          }),
        ),
      );

      final people = await api.participants(
        'board-1',
        purpose: TaskParticipantPurpose.people,
        search: 'tay',
      );
      await api.updateColumnValue(
        'task-1',
        'column-1',
        expectedTaskVersion: 7,
        value: true,
      );
      await api.bulkUpdate(
        'board-1',
        targets: const [TaskBulkTarget(id: 'task-1', expectedVersion: 7)],
        changes: const <String, Object?>{'status_option_id': 'status-1'},
      );

      expect(people.value?.single.name, 'Taylor');
      expect(requests[0].url.queryParameters, <String, String>{
        'purpose': 'people',
        'search': 'tay',
      });
      expect(jsonDecode(requests[1].body), <String, Object?>{
        'expected_version': 7,
        'value': true,
      });
      expect(jsonDecode(requests[2].body), <String, Object?>{
        'tasks': <Object?>[
          <String, Object?>{'id': 'task-1', 'expected_version': 7},
        ],
        'changes': <String, Object?>{'status_option_id': 'status-1'},
      });
    },
  );

  test(
    'returns typed conflict and generic unavailable results and never retains unavailable data',
    () async {
      var call = 0;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            call++;
            if (call == 1) {
              return _json(409, <String, Object?>{
                'message': 'That task changed while you were editing it.',
                'data': _minimalTask(version: 9),
                'meta': <String, Object?>{
                  'current_version': 9,
                  'conflicting_fields': <Object?>['title'],
                },
              });
            }
            return _json(404, <String, Object?>{
              'message': 'That task is unavailable.',
              'data': _minimalTask(version: 99),
            });
          }),
        ),
      );

      final conflict = await api.updateTask(
        'task-1',
        expectedTaskVersion: 7,
        changes: const <String, Object?>{'title': 'Changed'},
      );
      final unavailable = await api.taskDetail('board-1', 'task-1');

      expect(conflict, isA<TaskApiConflictResult<TaskRow>>());
      expect(conflict.conflict?.currentVersion, 9);
      expect(
        conflict.conflict?.currentTask?.field('title')?.value,
        'Server title',
      );
      expect(unavailable, isA<TaskApiUnavailable<TaskDetailSnapshot>>());
      expect(unavailable.value, isNull);
      expect(unavailable.conflict, isNull);
      expect(unavailable.message, 'That task is unavailable.');
    },
  );

  test('keeps a detail ETag separate from the task core version', () {
    final snapshot = TaskDetailSnapshot(
      task: TaskRow.fromJson(_minimalTask(version: 4)),
      etag: '"detail-17"',
    );

    expect(snapshot.task.version, 4);
    expect(snapshot.etag, '"detail-17"');
  });

  test(
    'captures the detail ETag and parses compact subtasks and record links',
    () async {
      final detail = _minimalTask(version: 4)
        ..['subtasks'] = <Object?>[
          <String, Object?>{
            'id': 'subtask-1',
            'title': 'Measure opening',
            'status_option_id': 'status-1',
            'version': 2,
            'archived': false,
          },
        ]
        ..['record_links'] = <Object?>[
          <String, Object?>{
            'link_id': 'link-1',
            'available': false,
            'type': 'Customer',
          },
        ];
      final api = TaskApi(
        ApiClient(
          client: MockClient(
            (_) async => _json(
              200,
              <String, Object?>{'data': detail},
              headers: const <String, String>{'etag': '"detail-17"'},
            ),
          ),
        ),
      );

      final result = await api.taskDetail('board-1', 'task-1');

      expect(result, isA<TaskApiSuccess<TaskDetailSnapshot>>());
      expect(result.value?.etag, '"detail-17"');
      expect(result.value?.task.version, 4);
      expect(result.value?.subtasks.single.title, 'Measure opening');
      expect(result.value?.recordLinks.single.available, isFalse);
      expect(result.value?.recordLinks.single.recordId, isNull);
      expect(result.value?.recordLinks.single.route, isNull);
    },
  );
}

http.Response _json(
  int status,
  Map<String, Object?> body, {
  Map<String, String> headers = const <String, String>{},
}) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json', ...headers},
);

Map<String, Object?> _minimalBoard() => <String, Object?>{
  'id': 'board-1',
  'name': 'Operations',
  'description': null,
  'visibility': 'scope',
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': null,
  'structure_version': 1,
  'archived': false,
  'is_favorite': false,
  'last_opened_at': null,
  'groups': <Object?>[],
  'statuses': <Object?>[],
  'priorities': <Object?>[],
  'core_columns': <Object?>[],
  'custom_columns': <Object?>[],
  'views': <Object?>[],
};

Map<String, Object?> _minimalTask({int version = 1}) => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': 'group-1',
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Server title',
  'owner_user_id': null,
  'collaborator_user_ids': <Object?>[],
  'status_option_id': 'status-1',
  'priority_option_id': null,
  'start_on': null,
  'due_on': null,
  'position': 1,
  'version': version,
  'detail_version': 2,
  'custom_values': <String, Object?>{},
  'group_by_value': 'group-1',
  'subtasks': <Object?>[],
};

Map<String, Object?> _emptyConfiguration() => <String, Object?>{
  'schema': 1,
  'presentation': 'table',
  'filters': <Object?>[],
  'sorts': <Object?>[],
  'hidden_columns': <Object?>[],
  'column_order': <Object?>['task'],
  'column_widths': <String, Object?>{},
  'group_by': null,
  'search': '',
};
