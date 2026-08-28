import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_duplicate_flow.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_transfer_flow.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
    'import discovery and preview preserve the authoritative reconciliation',
    () async {
      final requests = <http.Request>[];
      var call = 0;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            call++;
            return _json(200, <String, Object?>{
              'data': <String, Object?>{
                'format': 'csv',
                'headings': <Object?>['Task', 'Status', 'Legacy'],
                'sample': <Object?>[
                  <String, Object?>{
                    'Task': 'Inspect chimney',
                    'Status': 'Working',
                    'Legacy': 'Ignore me',
                  },
                ],
                'unsupported_fields': <Object?>[
                  <String, Object?>{
                    'heading': call == 1 ? 'Task' : 'Legacy',
                    'reason': 'not_mapped',
                  },
                ],
                'counts': <String, Object?>{
                  'rows': 1,
                  'ready': call == 1 ? 0 : 1,
                  'errors': 0,
                },
                'reconciliation': <Object?>[],
                'requires_mapping': call == 1,
                if (call == 2) 'session_token': 'a' * 64,
                if (call == 2) 'expires_at': '2026-08-28T13:00:00Z',
              },
            });
          }),
        ),
      );

      final discovery = await api.previewImport(
        'board-1',
        activeViewId: 'view-1',
        filename: 'monday.csv',
        bytes: const <int>[84, 97, 115, 107],
      );
      final preview = await api.previewImport(
        'board-1',
        activeViewId: 'view-1',
        filename: 'monday.csv',
        bytes: const <int>[84, 97, 115, 107],
        mapping: const TaskImportMapping(
          fields: <String, String>{'title': 'Task', 'status': 'Status'},
          decisions: <String, Object?>{},
        ),
      );

      expect(discovery.value?.requiresMapping, isTrue);
      expect(discovery.value?.sessionToken, isNull);
      expect(discovery.value?.headings, <String>['Task', 'Status', 'Legacy']);
      expect(discovery.value?.sample.single['Task'], 'Inspect chimney');
      expect(preview.value?.requiresMapping, isFalse);
      expect(preview.value?.sessionToken, 'a' * 64);
      expect(preview.value?.counts.ready, 1);
      expect(preview.value?.unsupportedFields.single.heading, 'Legacy');
      expect(requests, hasLength(2));
      expect(requests.every((request) => request.method == 'POST'), isTrue);
    },
  );

  test(
    'commit and export send the active view and explicit archive choice',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.method == 'POST') {
              return _json(201, <String, Object?>{
                'data': <String, Object?>{
                  'created': 2,
                  'task_ids': <Object?>['task-1', 'task-2'],
                },
              });
            }
            return http.Response.bytes(
              const <int>[1, 2, 3],
              200,
              headers: const <String, String>{
                'content-type':
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              },
            );
          }),
        ),
      );

      final commit = await api.commitImport(
        'board-1',
        activeViewId: 'view-1',
        sessionToken: 'b' * 64,
      );
      final export = await api.exportBoard(
        'board-1',
        format: TaskExportFormat.xlsx,
        activeViewId: 'view-1',
        includeArchived: true,
      );

      expect(commit.value?.created, 2);
      expect(export.value, <int>[1, 2, 3]);
      expect(jsonDecode(requests.first.body), <String, Object?>{
        'active_view_id': 'view-1',
        'session_token': 'b' * 64,
      });
      expect(
        requests.last.url.path,
        '/api/v1/tasks/boards/board-1/exports/xlsx',
      );
      expect(requests.last.url.queryParameters, <String, String>{
        'active_view_id': 'view-1',
        'include_archived': '1',
      });
    },
  );

  testWidgets(
    'duplicate flow offers task copies only when server authorizes it',
    (tester) async {
      http.Request? sent;
      TaskBoard? duplicated;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            sent = request;
            return _json(201, <String, Object?>{
              'data': _board(name: 'Operations copy'),
              'meta': <String, Object?>{'structure_version': 1},
            });
          }),
        ),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TaskDuplicateFlow(
              api: api,
              board: TaskBoard.fromJson(
                _board(actions: const <Object?>['duplicate']),
              ),
              destinationLabel: 'A-1 Chimney › Connecticut',
              onDuplicated: (board) => duplicated = board,
              onUnavailable: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('Copy tasks too'), findsNothing);
      expect(find.text('A-1 Chimney › Connecticut'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Duplicate board'));
      await tester.pumpAndSettle();

      expect(duplicated?.name, 'Operations copy');
      expect(jsonDecode(sent!.body), <String, Object?>{
        'expected_structure_version': 4,
        'name': 'Operations copy',
        'include_tasks': false,
      });
    },
  );

  testWidgets('transfer flow blocks commit on authoritative reconciliation', (
    tester,
  ) async {
    var previewCall = 0;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          previewCall++;
          return _json(200, <String, Object?>{
            'data': <String, Object?>{
              'format': 'csv',
              'headings': <Object?>['Task', 'Owner Email'],
              'sample': <Object?>[
                <String, Object?>{
                  'Task': 'Inspect chimney',
                  'Owner Email': 'unknown@example.com',
                },
              ],
              'unsupported_fields': <Object?>[],
              'counts': <String, Object?>{
                'rows': 1,
                'ready': previewCall == 1 ? 0 : 0,
                'errors': previewCall == 1 ? 0 : 1,
              },
              'reconciliation': previewCall == 1
                  ? <Object?>[]
                  : <Object?>[
                      <String, Object?>{
                        'row': 2,
                        'field': 'owner_email',
                        'code': 'ineligible_owner',
                        'message': 'That owner is not eligible for this board.',
                      },
                    ],
              'requires_mapping': previewCall == 1,
              if (previewCall == 2) 'session_token': 'c' * 64,
              if (previewCall == 2) 'expires_at': '2026-08-28T13:00:00Z',
            },
          });
        }),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskTransferFlow(
            api: api,
            board: TaskBoard.fromJson(
              _board(actions: const <Object?>['import']),
            ),
            activeViewId: 'view-1',
            pickFile: () async =>
                const PickedBytes('monday.csv', <int>[84, 97, 115, 107]),
            onUnavailable: (_) {},
          ),
        ),
      ),
    );

    await tester.tap(find.widgetWithText(OutlinedButton, 'Choose CSV or XLSX'));
    await tester.pumpAndSettle();
    expect(find.text('Task'), findsWidgets);
    expect(find.text('Owner Email'), findsWidgets);
    await tester.tap(find.widgetWithText(FilledButton, 'Preview import'));
    await tester.pumpAndSettle();

    expect(
      find.text('That owner is not eligible for this board.'),
      findsOneWidget,
    );
    final commit = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Import 0 tasks'),
    );
    expect(commit.onPressed, isNull);
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
