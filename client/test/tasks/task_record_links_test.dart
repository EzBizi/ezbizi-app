import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_record_links.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets(
    'searches the selected server type and links with the task version',
    (tester) async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.method == 'GET' &&
                request.url.path.endsWith('/types')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{'key': 'customer', 'label': 'Customer'},
                ],
              });
            }
            if (request.method == 'GET' &&
                request.url.path.endsWith('/search')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[_candidate()],
              });
            }
            if (request.method == 'GET' &&
                request.url.path.endsWith('/tasks/task-1')) {
              return _json(200, <String, Object?>{
                'data': _detailJson(version: 8, links: <Object?>[_link()]),
              });
            }
            return _json(201, <String, Object?>{'data': _link()});
          }),
        ),
      );
      var snapshot = _snapshot();

      await tester.pumpWidget(
        _host(
          api: api,
          snapshot: snapshot,
          onChanged: (next) => snapshot = next,
        ),
      );

      await tester.tap(find.text('Link record'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'Allowed');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Allowed customer'));
      await tester.pumpAndSettle();

      expect(requests[1].url.queryParameters, <String, String>{
        'type': 'customer',
        'q': 'Allowed',
      });
      expect(jsonDecode(requests[2].body), <String, Object?>{
        'expected_task_version': 7,
        'record_type': 'customer',
        'record_id': 'customer-1',
      });
      expect(snapshot.recordLinks.single.label, 'Allowed customer');
    },
  );

  testWidgets('uses only the server-provided deep link', (tester) async {
    String? opened;
    await tester.pumpWidget(
      _host(
        api: _unusedApi(),
        snapshot: _snapshot(links: <TaskRecordLink>[_linkModel()]),
        onOpenRoute: (route) => opened = route,
      ),
    );

    await tester.tap(find.text('Allowed customer'));

    expect(opened, '/customers/customer-1');
  });

  testWidgets(
    'keeps unavailable links generic and replaces stale target data',
    (tester) async {
      var snapshot = _snapshot(links: <TaskRecordLink>[_linkModel()]);
      late StateSetter setHostState;
      await tester.pumpWidget(
        StatefulBuilder(
          builder: (context, setState) {
            setHostState = setState;
            return _host(api: _unusedApi(), snapshot: snapshot);
          },
        ),
      );
      expect(find.text('Allowed customer'), findsOneWidget);
      expect(find.text('C-100'), findsOneWidget);

      setHostState(
        () => snapshot = _snapshot(
          links: <TaskRecordLink>[
            const TaskRecordLink(
              linkId: 'link-1',
              available: false,
              typeLabel: 'Customer',
              recordId: null,
              label: null,
              subtitle: null,
              route: null,
            ),
          ],
        ),
      );
      await tester.pump();

      expect(find.text('Customer unavailable'), findsOneWidget);
      expect(find.text('Allowed customer'), findsNothing);
      expect(find.text('C-100'), findsNothing);
      expect(find.byTooltip('Open Customer'), findsNothing);
    },
  );

  testWidgets(
    'shows the server conflict sentence without changing the link list',
    (tester) async {
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            if (request.method == 'GET' &&
                request.url.path.endsWith('/types')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{'key': 'customer', 'label': 'Customer'},
                ],
              });
            }
            if (request.method == 'GET') {
              return _json(200, <String, Object?>{
                'data': <Object?>[_candidate()],
              });
            }
            return _json(409, <String, Object?>{
              'message': 'That task changed while you were linking a record.',
              'meta': <String, Object?>{'current_task_version': 8},
            });
          }),
        ),
      );
      var snapshot = _snapshot();

      await tester.pumpWidget(
        _host(
          api: api,
          snapshot: snapshot,
          onChanged: (next) => snapshot = next,
        ),
      );
      await tester.tap(find.text('Link record'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'Allowed');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Allowed customer'));
      await tester.pumpAndSettle();

      expect(
        find.text('That task changed while you were linking a record.'),
        findsOneWidget,
      );
      expect(snapshot.recordLinks, isEmpty);
    },
  );

  testWidgets('uses the current task version for unlink conflicts', (
    tester,
  ) async {
    http.Request? mutation;
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          mutation = request;
          return _json(409, <String, Object?>{
            'message': 'That task changed while you were unlinking a record.',
            'meta': <String, Object?>{'current_task_version': 8},
          });
        }),
      ),
    );
    var snapshot = _snapshot(links: <TaskRecordLink>[_linkModel()]);

    await tester.pumpWidget(
      _host(api: api, snapshot: snapshot, onChanged: (next) => snapshot = next),
    );
    await tester.tap(find.byTooltip('Unlink customer'));
    await tester.pumpAndSettle();

    expect(mutation?.method, 'DELETE');
    expect(jsonDecode(mutation!.body), <String, Object?>{
      'expected_task_version': 7,
    });
    expect(
      find.text('That task changed while you were unlinking a record.'),
      findsOneWidget,
    );
    expect(snapshot.recordLinks.single.recordId, 'customer-1');
  });
}

Widget _host({
  required TaskApi api,
  required TaskDetailSnapshot snapshot,
  ValueChanged<TaskDetailSnapshot>? onChanged,
  ValueChanged<String>? onOpenRoute,
}) => MaterialApp(
  home: Scaffold(
    body: TaskRecordLinks(
      api: api,
      snapshot: snapshot,
      onChanged: onChanged ?? (_) {},
      onOpenRoute: onOpenRoute ?? (_) {},
    ),
  ),
);

TaskApi _unusedApi() => TaskApi(
  ApiClient(
    client: MockClient((_) async => throw StateError('Unused API call')),
  ),
);

TaskDetailSnapshot _snapshot({
  List<TaskRecordLink> links = const <TaskRecordLink>[],
}) => TaskDetailSnapshot(
  task: TaskRow.fromJson(_detailJson(version: 7, links: const <Object?>[])),
  etag: '"detail-1"',
  recordLinks: links,
);

Map<String, Object?> _detailJson({
  required int version,
  required List<Object?> links,
}) => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': 'group-1',
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Prepare visit',
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
  'record_links': links,
};

Map<String, Object?> _candidate() => <String, Object?>{
  'id': 'customer-1',
  'type': 'customer',
  'label': 'Allowed customer',
  'subtitle': 'C-100',
  'route': '/customers/customer-1',
};

Map<String, Object?> _link() => <String, Object?>{
  'link_id': 'link-1',
  'available': true,
  ..._candidate(),
};

TaskRecordLink _linkModel() => const TaskRecordLink(
  linkId: 'link-1',
  available: true,
  typeLabel: 'customer',
  recordId: 'customer-1',
  label: 'Allowed customer',
  subtitle: 'C-100',
  route: '/customers/customer-1',
);

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);
