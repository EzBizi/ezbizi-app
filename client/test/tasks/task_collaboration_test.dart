import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_participants.dart';
import 'package:ezbizi_client/screens/tasks/task_updates.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets(
    'composer keeps a transient draft and erases it when access is revoked',
    (tester) async {
      final draftEvents = <(String, bool)>[];

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TaskUpdateComposer(
              taskId: 'task-1',
              onDraftChanged: (id, dirty) => draftEvents.add((id, dirty)),
              onSubmit: (_) async => const TaskComposerTransientFailure(
                'The update could not be saved. Try again.',
              ),
            ),
          ),
        ),
      );

      await tester.enterText(find.byType(TextField), 'Keep this update');
      expect(draftEvents.last, ('task:task-1:update-composer', true));
      await tester.tap(find.text('Post update'));
      await tester.pump();
      expect(find.text('Keep this update'), findsOneWidget);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TaskUpdateComposer(
              taskId: 'task-1',
              accessRevoked: true,
              onDraftChanged: (id, dirty) => draftEvents.add((id, dirty)),
              onSubmit: (_) async => const TaskComposerSaved(),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Keep this update'), findsNothing);
      expect(draftEvents, contains(('task:task-1:update-composer', false)));
    },
  );

  test(
    'detail collaboration is parsed from the server response without names being invented',
    () {
      final snapshot = TaskDetailSnapshot.fromJson(
        _detailJson(),
        etag: '"detail-3"',
      );

      expect(
        snapshot.task.description,
        '<p>Durable <strong>brief</strong></p>',
      );
      expect(snapshot.task.descriptionPlain, 'Durable brief');
      expect(
        snapshot.updates.single.attachments.single.originalName,
        'proof.pdf',
      );
      expect(snapshot.updates.single.versions.single.editedBy, 'user-2');
      expect(snapshot.watch.mandatory, isFalse);
      expect(snapshot.watch.watched, isTrue);
      expect(snapshot.activities.single.summary, 'A collaborator was added.');
    },
  );

  test(
    'collaboration API sends exact update, collaborator, and watch contracts',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.url.path.endsWith('/updates')) {
              return _json(201, <String, Object?>{
                'data': _detailJson()['updates'] is List
                    ? (_detailJson()['updates'] as List).single
                    : null,
              });
            }
            if (request.url.path.endsWith('/collaborators')) {
              return _json(200, <String, Object?>{'data': _detailJson()});
            }
            return _json(200, <String, Object?>{
              'data': <String, Object?>{
                'task_id': 'task-1',
                'preference': 'muted',
              },
            });
          }),
        ),
      );

      await api.createTaskUpdate(
        'task-1',
        body: '<p>Hello</p>',
        mentionUserIds: const ['user-2'],
        attachmentCandidateIds: const ['file-1'],
        idempotencyKey: 'draft-1',
      );
      await api.updateCollaborators(
        'task-1',
        expectedTaskVersion: 4,
        userIds: const ['user-2'],
      );
      final watch = await api.setWatchPreference(
        'task-1',
        TaskWatchChoice.muted,
      );

      expect(jsonDecode(requests[0].body), <String, Object?>{
        'body': '<p>Hello</p>',
        'mention_user_ids': <Object?>['user-2'],
        'attachment_candidate_ids': <Object?>['file-1'],
        'idempotency_key': 'draft-1',
      });
      expect(jsonDecode(requests[1].body), <String, Object?>{
        'expected_version': 4,
        'user_ids': <Object?>['user-2'],
      });
      expect(jsonDecode(requests[2].body), <String, Object?>{
        'preference': 'muted',
      });
      expect(watch.value?.preference, TaskWatchChoice.muted);
    },
  );

  test('only absolute HTTPS update links are launchable', () {
    expect(
      safeTaskExternalUri('https://example.com/path')?.host,
      'example.com',
    );
    expect(safeTaskExternalUri('http://example.com'), isNull);
    expect(safeTaskExternalUri('javascript:alert(1)'), isNull);
    expect(safeTaskExternalUri('/relative'), isNull);
    expect(safeTaskExternalUri('https://user:pass@example.com'), isNull);
  });

  test(
    'attachment download keeps access loss distinct from a transient failure',
    () async {
      final api = TaskApi(
        ApiClient(
          client: MockClient(
            (_) async => _json(404, <String, Object?>{
              'message': 'That attachment is unavailable.',
            }),
          ),
        ),
      );

      final result = await api.downloadAttachment('file-1');

      expect(result, isA<TaskApiUnavailable<List<int>>>());
      expect(result.message, 'That attachment is unavailable.');
    },
  );

  testWidgets(
    'participant search renders only server-returned candidates and refusal text',
    (tester) async {
      var searches = <String>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TaskParticipantPicker(
              selectedIds: const <String>[],
              onChanged: (_) {},
              search: (query) async {
                searches = [...searches, query];
                if (query == 'blocked') {
                  return const TaskApiFailure<List<TaskParticipant>>(
                    statusCode: 422,
                    message:
                        'Only eligible people can collaborate on this board.',
                  );
                }
                return const TaskApiSuccess<List<TaskParticipant>>([
                  TaskParticipant(
                    id: 'user-2',
                    name: 'Mia Chen',
                    email: 'mia@example.com',
                  ),
                ]);
              },
            ),
          ),
        ),
      );
      await tester.pump();
      expect(find.text('Mia Chen'), findsOneWidget);

      await tester.enterText(find.byType(TextField), 'blocked');
      await tester.pump(const Duration(milliseconds: 350));
      await tester.pump();

      expect(searches, contains('blocked'));
      expect(
        find.text('Only eligible people can collaborate on this board.'),
        findsOneWidget,
      );
      expect(find.text('Mia Chen'), findsNothing);
    },
  );

  testWidgets(
    'detail collaboration renders durable content, neutral authors, files, history, and mandatory watch',
    (tester) async {
      final detail = _detailJson();
      detail['watch'] = <String, Object?>{
        'preference': null,
        'mandatory': true,
        'watched': true,
      };
      final api = TaskApi(
        ApiClient(
          client: MockClient(
            (_) async =>
                throw StateError('No request expected while rendering.'),
          ),
        ),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: TaskCollaborationPanel(
                snapshot: TaskDetailSnapshot.fromJson(detail, etag: null),
                api: api,
                currentUserId: 'user-1',
                canComment: false,
                canEdit: false,
                privacyEpoch: 2,
                onChanged: (_) {},
                onUnavailable: (_) {},
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Durable brief'), findsOneWidget);
      expect(find.text('A teammate'), findsOneWidget);
      expect(find.text('proof.pdf'), findsOneWidget);
      expect(find.text('Edit history (1)'), findsOneWidget);
      expect(find.text('Owner · always watching'), findsOneWidget);
      expect(find.text('Watch'), findsNothing);
      expect(find.text('Mute'), findsNothing);
    },
  );
}

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);

Map<String, Object?> _detailJson() => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': 'group-1',
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Inspect site',
  'description': '<p>Durable <strong>brief</strong></p>',
  'description_plain': 'Durable brief',
  'owner_user_id': 'user-1',
  'collaborator_user_ids': <Object?>['user-2'],
  'status_option_id': null,
  'priority_option_id': null,
  'start_on': null,
  'due_on': null,
  'position': 1,
  'version': 4,
  'detail_version': 3,
  'custom_values': <String, Object?>{},
  'subtasks': <Object?>[],
  'record_links': <Object?>[],
  'updates': <Object?>[
    <String, Object?>{
      'id': 'update-1',
      'author_user_id': 'user-2',
      'body': '<p>Uploaded <a href="https://example.com/proof">proof</a>.</p>',
      'body_plain': 'Uploaded proof.',
      'version': 2,
      'mention_user_ids': <Object?>['user-1'],
      'attachments': <Object?>[
        <String, Object?>{
          'id': 'file-1',
          'attachable': <String, Object?>{
            'type': 'task_updates',
            'id': 'update-1',
          },
          'original_name': 'proof.pdf',
          'mime': 'application/pdf',
          'size_bytes': 1200,
          'checksum': 'abc123',
          'uploaded_by': <String, Object?>{'id': 'user-2', 'name': null},
          'uploaded_at': '2026-08-27T12:00:00Z',
        },
      ],
      'versions': <Object?>[
        <String, Object?>{
          'version': 1,
          'body': '<p>Uploaded proof.</p>',
          'body_plain': 'Uploaded proof.',
          'edited_by': 'user-2',
          'created_at': '2026-08-27T12:01:00Z',
        },
      ],
      'created_at': '2026-08-27T12:00:00Z',
      'updated_at': '2026-08-27T12:01:00Z',
    },
  ],
  'watch': <String, Object?>{
    'preference': 'watched',
    'mandatory': false,
    'watched': true,
  },
  'activities': <Object?>[
    <String, Object?>{
      'id': 'activity-1',
      'actor_user_id': 'user-1',
      'kind': 'task.collaborator_added',
      'summary': 'A collaborator was added.',
      'data': <String, Object?>{'user_id': 'user-2'},
      'created_at': '2026-08-27T12:02:00Z',
    },
  ],
};
