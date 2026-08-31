import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_recurrence_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('with no recurrence declared the panel offers to add one', (
    tester,
  ) async {
    await tester.pumpWidget(_host(api: _unusedApi(), snapshot: _snapshot()));

    expect(find.text('This task does not repeat.'), findsOneWidget);
    expect(find.text('Add recurrence'), findsOneWidget);
    // No hidden hours concept anywhere in this widget's tree.
    expect(find.textContaining('hour'), findsNothing);
  });

  testWidgets('opening the editor is inline, never a dialog, and offers both modes in plain wording', (
    tester,
  ) async {
    await tester.pumpWidget(_host(api: _unusedApi(), snapshot: _snapshot()));

    await tester.tap(find.text('Add recurrence'));
    await tester.pump();

    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('when it is completed'), findsOneWidget);
    expect(find.text('on a fixed schedule'), findsOneWidget);
  });

  testWidgets('saving sends the task version as expected_version and the encoded recurrence', (
    tester,
  ) async {
    final requests = <http.Request>[];
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          if (request.method == 'PUT') {
            return _json(200, <String, Object?>{'data': _taskJson(version: 8)});
          }
          return _json(200, <String, Object?>{
            'data': _detailJson(version: 8, recurrence: _recurrenceJson()),
          });
        }),
      ),
    );
    TaskDetailSnapshot? updated;
    await tester.pumpWidget(
      _host(
        api: api,
        snapshot: _snapshot(),
        onChanged: (next) => updated = next,
      ),
    );

    await tester.tap(find.text('Add recurrence'));
    await tester.pump();
    await tester.tap(find.text('on a fixed schedule'));
    await tester.enterText(find.byKey(const ValueKey('recurrence-every-field')), '2');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final put = requests.firstWhere((r) => r.method == 'PUT');
    expect(put.url.path, '/api/v1/tasks/task-1/recurrence');
    final body = jsonDecode(put.body) as Map<String, Object?>;
    expect(body['expected_version'], 7);
    expect(body['recurrence'], <String, Object?>{
      'mode': 'on_schedule',
      'every': 2,
      'unit': 'day',
      'until': null,
      'count': null,
    });
    expect(updated?.recurrence?.mode, TaskRecurrenceMode.onSchedule);
  });

  testWidgets('a subtask refusal from the server is shown verbatim, never a client-invented sentence', (
    tester,
  ) async {
    // Copied verbatim from Refusal::recurrenceOnSubtask() (fix round 1,
    // reviewer note) -- a truncated first-sentence-only fixture could not
    // catch the client silently truncating or reformatting the server's
    // sentence before display, because before and after would look
    // identical. This is the REAL, complete refusal.
    const refusal =
        'A subtask cannot recur on its own — it is a checklist item under '
        'its parent task rather than independent work. If this needs to '
        'repeat on its own schedule, make it a task of its own instead of '
        'a subtask.';
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async => _json(403, <String, Object?>{
          'message': refusal,
        })),
      ),
    );

    await tester.pumpWidget(_host(api: api, snapshot: _snapshot()));
    await tester.tap(find.text('Add recurrence'));
    await tester.pump();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(find.text(refusal), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
  });

  testWidgets('editing an existing declaration pre-fills the editor and can be removed', (
    tester,
  ) async {
    final requests = <http.Request>[];
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          if (request.method == 'PUT') {
            return _json(200, <String, Object?>{'data': _taskJson(version: 8)});
          }
          return _json(200, <String, Object?>{
            'data': _detailJson(version: 8, recurrence: null),
          });
        }),
      ),
    );
    final snapshot = _snapshot(
      recurrence: const TaskRecurrence(
        mode: TaskRecurrenceMode.afterCompletion,
        every: 3,
        unit: TaskRecurrenceUnit.week,
        until: null,
        count: 5,
      ),
    );
    await tester.pumpWidget(_host(api: api, snapshot: snapshot));

    expect(
      find.text('Repeats every 3 weeks, when it is completed, for 5 times.'),
      findsOneWidget,
    );

    await tester.tap(find.text('Edit'));
    await tester.pump();
    expect(
      find.widgetWithText(TextField, '3'),
      findsOneWidget,
    );

    await tester.tap(find.text('Remove recurrence'));
    await tester.pumpAndSettle();

    final put = requests.firstWhere((r) => r.method == 'PUT');
    final body = jsonDecode(put.body) as Map<String, Object?>;
    expect(body['recurrence'], isNull);
  });

  testWidgets('at 375px the editor stays usable with no horizontal page scroll', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 812);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(_host(api: _unusedApi(), snapshot: _snapshot()));
    await tester.tap(find.text('Add recurrence'));
    await tester.pumpAndSettle();

    expect(find.text('when it is completed'), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
  });
}

Widget _host({
  required TaskApi api,
  required TaskDetailSnapshot snapshot,
  ValueChanged<TaskDetailSnapshot>? onChanged,
}) => MaterialApp(
  home: Scaffold(
    body: TaskRecurrencePanel(
      api: api,
      snapshot: snapshot,
      onChanged: onChanged ?? (_) {},
    ),
  ),
);

TaskApi _unusedApi() => TaskApi(
  ApiClient(
    client: MockClient((_) async => throw StateError('Unused API call')),
  ),
);

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);

TaskDetailSnapshot _snapshot({TaskRecurrence? recurrence}) => TaskDetailSnapshot(
  task: TaskRow.fromJson(_taskJson(version: 7)),
  etag: '"detail-1"',
  recurrence: recurrence,
);

Map<String, Object?> _taskJson({required int version}) => <String, Object?>{
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
};

Map<String, Object?> _detailJson({
  required int version,
  Map<String, Object?>? recurrence,
}) => <String, Object?>{
  ...(_taskJson(version: version)),
  'record_links': <Object?>[],
  'recurrence': recurrence,
};

Map<String, Object?> _recurrenceJson() => <String, Object?>{
  'mode': 'on_schedule',
  'every': 2,
  'unit': 'day',
  'until': null,
  'count': null,
};
