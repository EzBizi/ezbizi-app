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
              source: const TaskDuplicateSource(
                id: 'board-1',
                name: 'Operations',
                structureVersion: 4,
                actions: <String>['duplicate', 'duplicate_with_tasks'],
                samePlace: false,
              ),
              destinationLabel: 'A-1 Chimney › Connecticut',
              onDuplicated: (board) => duplicated = board,
              onUnavailable: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('Copy tasks too'), findsNothing);
      expect(
        find.textContaining('Change the top-bar view before opening'),
        findsNothing,
      );
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

  testWidgets('lost duplicate-source access clears opaque pending state', (
    tester,
  ) async {
    var invalidated = false;
    http.Request? verificationRequest;
    TaskDuplicateDraftStore.pending = TaskDuplicateDraft(
      sourceBoardId: 'secret-board-id',
      actorUserId: 'user-1',
    );
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          verificationRequest = request;
          return _json(404, <String, Object?>{
            'message': 'That source is unavailable.',
          });
        }),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskDuplicateContinuation(
            api: api,
            draft: TaskDuplicateDraftStore.pending!,
            destinationLabel: 'Company two',
            onDuplicated: (_) {},
            onInvalid: (_) {
              invalidated = true;
              TaskDuplicateDraftStore.pending = null;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(invalidated, isTrue);
    expect(verificationRequest?.method, 'GET');
    expect(
      verificationRequest?.url.path,
      '/api/v1/tasks/boards/secret-board-id/duplicate-source',
    );
    expect(TaskDuplicateDraftStore.pending, isNull);
    expect(find.textContaining('Secret board'), findsNothing);
    expect(find.text('Company two'), findsNothing);
  });

  testWidgets('reconciliation decision can make an import committable', (
    tester,
  ) async {
    var previewCall = 0;
    final requests = <http.Request>[];
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          previewCall++;
          return _json(200, <String, Object?>{
            'data': <String, Object?>{
              'format': 'csv',
              'headings': <Object?>['Task', 'Status'],
              'sample': <Object?>[
                <String, Object?>{
                  'Task': 'Inspect chimney',
                  'Status': 'Monday To do',
                },
              ],
              'unsupported_fields': <Object?>[],
              'counts': <String, Object?>{
                'rows': 1,
                'ready': previewCall == 3 ? 1 : 0,
                'errors': previewCall == 2 ? 1 : 0,
              },
              'reconciliation': previewCall == 2
                  ? <Object?>[
                      <String, Object?>{
                        'row': 2,
                        'field': 'status',
                        'code': 'decision_required',
                        'source_value': 'Monday To do',
                        'message':
                            "Choose an active status for 'Monday To do'.",
                      },
                    ]
                  : <Object?>[],
              'requires_mapping': previewCall == 1,
              if (previewCall == 3) 'session_token': 'c' * 64,
              if (previewCall == 3) 'expires_at': '2026-08-28T13:00:00Z',
            },
          });
        }),
      ),
    );

    final boardJson = _board(actions: const <Object?>['import'])
      ..['statuses'] = <Object?>[
        <String, Object?>{
          'id': 'status-1',
          'name': 'EzBizi To do',
          'color': '#0B7285',
          'category': 'not_started',
          'position': 1,
          'archived': false,
          'actions': <Object?>[],
        },
      ];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskTransferFlow(
            api: api,
            board: TaskBoard.fromJson(boardJson),
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
    expect(find.text('Status'), findsWidgets);
    await tester.tap(find.widgetWithText(FilledButton, 'Preview import'));
    await tester.pumpAndSettle();

    expect(
      find.text("Choose an active status for 'Monday To do'."),
      findsOneWidget,
    );
    await tester.tap(
      find.byKey(const ValueKey('task-import-decision-status-Monday To do')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('EzBizi To do').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Preview import'));
    await tester.pumpAndSettle();

    expect(
      find.text("Choose an active status for 'Monday To do'."),
      findsNothing,
    );
    final commit = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Import 1 tasks'),
    );
    expect(commit.onPressed, isNotNull);
    expect(requests.last.body, contains('status-1'));
    expect(requests.last.body, contains('Monday To do'));
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
