import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_dependency_panel.dart';
import 'package:ezbizi_client/screens/tasks/task_detail_panel.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_timeline_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('render states', () {
    testWidgets('both dates render a bar, distinguishable from a marker', (
      tester,
    ) async {
      await tester.pumpWidget(
        _harness(month: DateTime.utc(2026, 9), rows: [_bothDates]),
      );

      expect(find.byType(TaskTimelineBar), findsOneWidget);
      expect(find.byKey(const ValueKey('timeline-bar-task-1')), findsOneWidget);
      expect(find.byType(TaskTimelineMarker), findsNothing);
    });

    testWidgets('due only renders a marker, not a bar', (tester) async {
      await tester.pumpWidget(
        _harness(month: DateTime.utc(2026, 9), rows: [_dueOnly]),
      );

      expect(find.byType(TaskTimelineMarker), findsOneWidget);
      expect(find.byKey(const ValueKey('timeline-marker-task-1')), findsOneWidget);
      expect(find.byType(TaskTimelineBar), findsNothing);
    });

    testWidgets('start only renders a marker, not a bar', (tester) async {
      await tester.pumpWidget(
        _harness(month: DateTime.utc(2026, 9), rows: [_startOnly]),
      );

      expect(find.byType(TaskTimelineMarker), findsOneWidget);
      expect(find.byKey(const ValueKey('timeline-marker-task-1')), findsOneWidget);
      expect(find.byType(TaskTimelineBar), findsNothing);
    });

    testWidgets(
      'a task with neither date is not drawn, and counted in the omitted total',
      (tester) async {
        await tester.pumpWidget(
          _harness(
            month: DateTime.utc(2026, 9),
            rows: [_neitherDate, _bothDates],
            omitted: const TaskOmitted(undated: 1, outsideWindow: 0, unowned: 0),
          ),
        );

        // Not drawn at all — no bar, no marker, no label row for it.
        expect(find.byKey(const ValueKey('timeline-label-task-none')), findsNothing);
        expect(find.byType(TaskTimelineBar), findsOneWidget); // only _bothDates
        expect(find.byType(TaskTimelineMarker), findsNothing);
        // Counted, including the exact wording (mirrors TaskCalendarView).
        expect(
          find.text('1 task has no start or due date and is not shown here.'),
          findsOneWidget,
        );
      },
    );

    testWidgets('the undated count is stated on screen, including at zero', (
      tester,
    ) async {
      await tester.pumpWidget(
        _harness(month: DateTime.utc(2026, 9), rows: [_bothDates]),
      );

      expect(
        find.text('0 tasks have no start or due date and are not shown here.'),
        findsOneWidget,
      );
    });
  });

  group('dragging', () {
    testWidgets('dragging an edge writes that one date', (tester) async {
      TaskRow? movedTask;
      DateTime? movedStart;
      DateTime? movedDue;
      var calls = 0;

      await tester.pumpWidget(
        _harness(
          month: DateTime.utc(2026, 9),
          rows: [_bothDates],
          onMove: (task, {startOn, dueOn}) async {
            calls++;
            movedTask = task;
            movedStart = startOn;
            movedDue = dueOn;
            return null;
          },
        ),
      );

      await _drag(
        tester,
        from: find.byKey(const ValueKey('timeline-bar-due-handle-task-1')),
        to: find.byKey(const ValueKey('timeline-day-task-1-2026-09-15')),
      );

      expect(calls, 1);
      expect(movedTask?.id, 'task-1');
      expect(movedTask?.version, 7);
      // Only the dragged field is sent — the other stays untouched (null).
      expect(movedStart, isNull);
      expect(movedDue, DateTime.utc(2026, 9, 15));
    });

    testWidgets(
      'dragging the whole bar writes both dates in one command at one version',
      (tester) async {
        // Wide enough that the Sep 20 drop target sits inside the grid's
        // own horizontal scroller without needing to scroll it first — a
        // synthetic drag does not auto-scroll a target into view.
        tester.view.physicalSize = const Size(1400, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        TaskRow? movedTask;
        DateTime? movedStart;
        DateTime? movedDue;
        var calls = 0;

        await tester.pumpWidget(
          _harness(
            month: DateTime.utc(2026, 9),
            rows: [_bothDates], // start Sep 5, due Sep 10 — 5-day span
            onMove: (task, {startOn, dueOn}) async {
              calls++;
              movedTask = task;
              movedStart = startOn;
              movedDue = dueOn;
              return null;
            },
          ),
        );

        await _drag(
          tester,
          from: find.byKey(const ValueKey('timeline-bar-body-task-1')),
          to: find.byKey(const ValueKey('timeline-day-task-1-2026-09-20')),
        );

        // One call, one version, both fields present together.
        expect(calls, 1);
        expect(movedTask?.version, 7);
        expect(movedStart, DateTime.utc(2026, 9, 20));
        // The 5-day span is preserved.
        expect(movedDue, DateTime.utc(2026, 9, 25));
      },
    );

    testWidgets(
      "start_on after due_on is refused with the server's sentence and not silently swapped",
      (tester) async {
        tester.view.physicalSize = const Size(1400, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        DateTime? sentStart;
        DateTime? sentDue;

        await tester.pumpWidget(
          _harness(
            month: DateTime.utc(2026, 9),
            rows: [_bothDates], // start Sep 5, due Sep 10
            onMove: (task, {startOn, dueOn}) async {
              sentStart = startOn;
              sentDue = dueOn;
              return 'The start date cannot be after the due date.';
            },
          ),
        );

        // Drag the start handle past the current due date (Sep 10) —
        // asking to set start_on = Sep 20, which the server refuses.
        await _drag(
          tester,
          from: find.byKey(const ValueKey('timeline-bar-start-handle-task-1')),
          to: find.byKey(const ValueKey('timeline-day-task-1-2026-09-20')),
        );

        // Exactly the server's sentence, shown once.
        expect(
          find.text('The start date cannot be after the due date.'),
          findsOneWidget,
        );
        // The client sent exactly what was dragged — it never reordered or
        // swapped the two fields to make them valid on its own.
        expect(sentStart, DateTime.utc(2026, 9, 20));
        expect(sentDue, isNull);
      },
    );
  });

  group('dependencies', () {
    testWidgets('dependency lines are drawn between bars', (tester) async {
      await tester.pumpWidget(
        _harness(
          month: DateTime.utc(2026, 9),
          rows: [_blocker, _blocked],
        ),
      );

      expect(find.byType(TaskTimelineDependencyLine), findsOneWidget);
      expect(
        find.byKey(const ValueKey('timeline-dependency-task-blocker-task-blocked')),
        findsOneWidget,
      );
    });

    testWidgets('a Blocked by chip appears on the task detail', (tester) async {
      final api = _permissiveApi();
      final snapshot = _detailSnapshot(
        blockedBy: const [
          TaskBlockedByRef(
            id: 'dep-1',
            taskId: 'task-blocker',
            number: 'TASK-9',
            title: 'Prep the site',
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: TaskDetailPanel(
              task: snapshot,
              taskApi: api,
              onTaskChanged: (_) {},
              onOpenRecordRoute: (_) {},
              onClose: () {},
            ),
          ),
        ),
      );

      expect(find.byKey(const ValueKey('dependency-chip-dep-1')), findsOneWidget);
      expect(find.textContaining('TASK-9'), findsOneWidget);
      expect(find.textContaining('Prep the site'), findsOneWidget);
      expect(find.byType(AlertDialog), findsNothing);
    });

    testWidgets('adding a dependency happens in a panel, never a dialog', (
      tester,
    ) async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.method == 'GET' && request.url.path.endsWith('/search')) {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{
                    'board_id': 'board-1',
                    'board_name': 'Operations',
                    'task_id': 'task-blocker',
                    'number': 'TASK-9',
                    'title': 'Prep the site',
                    'deep_link': '/tasks/task-blocker',
                  },
                ],
              });
            }
            if (request.method == 'POST' &&
                request.url.path.endsWith('/dependencies')) {
              return _json(201, <String, Object?>{
                'data': <String, Object?>{
                  'id': 'dep-1',
                  'board_id': 'board-1',
                  'blocker_task_id': 'task-blocker',
                  'blocked_task_id': 'task-blocked',
                },
              });
            }
            if (request.method == 'GET' && request.url.path.contains('/tasks/')) {
              return _json(200, <String, Object?>{
                'data': _detailJson(
                  blockedBy: const [
                    <String, Object?>{
                      'id': 'dep-1',
                      'task_id': 'task-blocker',
                      'number': 'TASK-9',
                      'title': 'Prep the site',
                    },
                  ],
                ),
              });
            }
            throw StateError('Unexpected request: ${request.method} ${request.url}');
          }),
        ),
      );

      TaskDetailSnapshot snapshot = _detailSnapshot();
      await tester.pumpWidget(
        StatefulBuilder(
          builder: (context, setState) => MaterialApp(
            theme: EzBiziTheme.light(),
            home: Scaffold(
              body: TaskDependencyPanel(
                api: api,
                snapshot: snapshot,
                onChanged: (next) => setState(() => snapshot = next),
              ),
            ),
          ),
        ),
      );

      expect(find.byType(AlertDialog), findsNothing);
      expect(find.text('Not blocked by anything.'), findsOneWidget);

      await tester.tap(find.text('Add dependency'));
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsNothing);
      expect(find.byKey(const ValueKey('dependency-search-field')), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('dependency-search-field')),
        'Prep',
      );
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsNothing);
      expect(find.text('Prep the site'), findsOneWidget);

      await tester.tap(find.text('Prep the site'));
      await tester.pumpAndSettle();

      expect(find.byType(AlertDialog), findsNothing);
      expect(find.byKey(const ValueKey('dependency-chip-dep-1')), findsOneWidget);
      expect(
        requests.any(
          (r) => r.method == 'POST' && r.url.path.endsWith('/dependencies'),
        ),
        isTrue,
      );
    });
  });

  testWidgets(
    'at 375px the timeline scrolls horizontally inside its own container, not the page body',
    (tester) async {
      tester.view.physicalSize = const Size(375, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(
        _harness(month: DateTime.utc(2026, 9), rows: [_bothDates]),
      );
      await tester.pumpAndSettle();

      // No overflow at this width — the grid's own scroller absorbs the day
      // columns; the page body never does.
      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('timeline-bar-task-1')), findsOneWidget);

      // Exactly one horizontally-scrolling container: the grid's own.
      expect(
        find.byWidgetPredicate(
          (widget) =>
              widget is SingleChildScrollView &&
              widget.scrollDirection == Axis.horizontal,
        ),
        findsOneWidget,
      );
      // The page body scrolls only vertically.
      expect(
        find.byWidgetPredicate(
          (widget) =>
              widget is SingleChildScrollView &&
              widget.scrollDirection == Axis.vertical,
        ),
        findsOneWidget,
      );
    },
  );
}

Future<void> _drag(
  WidgetTester tester, {
  required Finder from,
  required Finder to,
}) async {
  final gesture = await tester.startGesture(tester.getCenter(from));
  await tester.pump(const Duration(milliseconds: 50));
  await gesture.moveTo(tester.getCenter(to));
  await tester.pump(const Duration(milliseconds: 50));
  await gesture.up();
  await tester.pumpAndSettle();
}

Widget _harness({
  required DateTime month,
  required List<TaskRow> rows,
  TaskOmitted omitted = TaskOmitted.zero,
  TaskTimelineMove? onMove,
}) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: TaskTimelineView(
      month: month,
      rows: rows,
      omitted: omitted,
      onMove: onMove ?? (_, {startOn, dueOn}) async => null,
      onMonthChanged: (_) {},
    ),
  ),
);

TaskRow _row({
  required String id,
  required String number,
  required String title,
  DateTime? startOn,
  DateTime? dueOn,
  int version = 1,
  List<TaskBlockedByRef>? blockedBy,
}) => TaskRow(
  id: id,
  boardId: 'board-1',
  groupId: null,
  parentTaskId: null,
  number: number,
  title: title,
  ownerUserId: null,
  collaboratorUserIds: const [],
  statusOptionId: null,
  priorityOptionId: null,
  startOn: startOn,
  dueOn: dueOn,
  position: 1,
  version: version,
  detailVersion: 1,
  values: const {},
  groupByValue: null,
  subtasks: const [],
  blockedBy: blockedBy,
);

final _bothDates = _row(
  id: 'task-1',
  number: 'TASK-1',
  title: 'Measure opening',
  startOn: DateTime.utc(2026, 9, 5),
  dueOn: DateTime.utc(2026, 9, 10),
  version: 7,
);

final _dueOnly = _row(
  id: 'task-1',
  number: 'TASK-1',
  title: 'Order the unit',
  dueOn: DateTime.utc(2026, 9, 12),
  version: 3,
);

final _startOnly = _row(
  id: 'task-1',
  number: 'TASK-1',
  title: 'Begin demolition',
  startOn: DateTime.utc(2026, 9, 8),
  version: 4,
);

final _neitherDate = _row(id: 'task-none', number: 'TASK-9', title: 'Someday task');

final _blocker = _row(
  id: 'task-blocker',
  number: 'TASK-1',
  title: 'Prep the site',
  startOn: DateTime.utc(2026, 9, 1),
  dueOn: DateTime.utc(2026, 9, 5),
);

final _blocked = _row(
  id: 'task-blocked',
  number: 'TASK-2',
  title: 'Install the unit',
  startOn: DateTime.utc(2026, 9, 10),
  dueOn: DateTime.utc(2026, 9, 15),
  blockedBy: const [
    TaskBlockedByRef(
      id: 'dep-1',
      taskId: 'task-blocker',
      number: 'TASK-1',
      title: 'Prep the site',
    ),
  ],
);

TaskApi _permissiveApi() => TaskApi(
  ApiClient(
    client: MockClient(
      (_) async => _json(200, const <String, Object?>{'data': <Object?>[]}),
    ),
  ),
);

TaskDetailSnapshot _detailSnapshot({List<TaskBlockedByRef> blockedBy = const []}) =>
    TaskDetailSnapshot(
      task: TaskRow.fromJson(
        _detailJson(
          blockedBy: blockedBy
              .map(
                (ref) => <String, Object?>{
                  'id': ref.id,
                  'task_id': ref.taskId,
                  'number': ref.number,
                  'title': ref.title,
                },
              )
              .toList(growable: false),
        ),
      ),
      etag: '"detail-1"',
    );

Map<String, Object?> _detailJson({List<Object?> blockedBy = const []}) =>
    <String, Object?>{
      'id': 'task-blocked',
      'board_id': 'board-1',
      'group_id': null,
      'parent_task_id': null,
      'number': 'TASK-2',
      'title': 'Install the unit',
      'owner_user_id': null,
      'collaborator_user_ids': <Object?>[],
      'status_option_id': null,
      'priority_option_id': null,
      'start_on': null,
      'due_on': null,
      'position': 1,
      'version': 7,
      'detail_version': 2,
      'custom_values': <String, Object?>{},
      'group_by_value': null,
      'subtasks': <Object?>[],
      'record_links': <Object?>[],
      'blocked_by': blockedBy,
    };

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);
