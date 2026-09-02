import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_kanban_view.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('columns render for the chosen bucket field, including empty ones', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      buckets: _statusBuckets,
      rows: const [_working1],
      groupCounts: const [
        TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 1),
      ],
    ));

    // Working has a card; Review has none, and still renders its column.
    expect(find.byKey(const ValueKey('kanban-header-status-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('kanban-header-status-2')), findsOneWidget);
    expect(find.text('No tasks'), findsOneWidget);
    expect(find.byKey(const ValueKey('kanban-column-status-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('kanban-column-status-2')), findsOneWidget);
  });

  testWidgets('dragging a card issues the field\'s existing write with its version', (
    tester,
  ) async {
    TaskRow? movedTask;
    TaskKanbanField? movedField;
    String? movedBucketId;

    await tester.pumpWidget(_harness(
      buckets: _statusBuckets,
      rows: const [_working1],
      groupCounts: const [
        TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 1),
      ],
      onMove: (task, field, bucketId) async {
        movedTask = task;
        movedField = field;
        movedBucketId = bucketId;
        return null;
      },
    ));

    await _drag(
      tester,
      from: find.byKey(const ValueKey('kanban-card-task-1')),
      to: find.byKey(const ValueKey('kanban-column-status-2')),
    );

    expect(movedTask?.id, 'task-1');
    expect(movedTask?.version, 7);
    expect(movedField, TaskKanbanField.status);
    expect(movedBucketId, 'status-2');
  });

  testWidgets(
    'a read-only or archived bucket field renders columns but refuses the drag with the server\'s sentence',
    (tester) async {
      await tester.pumpWidget(_harness(
        buckets: _statusBuckets,
        rows: const [_working1],
        groupCounts: const [
          TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 1),
        ],
        onMove: (task, field, bucketId) async =>
            'This board no longer accepts status changes.',
      ));

      await _drag(
        tester,
        from: find.byKey(const ValueKey('kanban-card-task-1')),
        to: find.byKey(const ValueKey('kanban-column-status-2')),
      );

      // Columns are still there.
      expect(find.byKey(const ValueKey('kanban-header-status-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('kanban-header-status-2')), findsOneWidget);
      // The server's sentence appears exactly once — never a second,
      // invented copy of the same reason.
      expect(
        find.text('This board no longer accepts status changes.'),
        findsOneWidget,
      );
      expect(find.byType(AlertDialog), findsNothing);
    },
  );

  testWidgets('the header count is the complete accessible total, not the drawn count', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      buckets: _statusBuckets,
      // Only 2 cards are actually drawn for status-1...
      rows: const [_working1, _working2],
      // ...but the board holds 40 accessible matching tasks for it.
      groupCounts: const [
        TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 40),
      ],
    ));

    expect(find.text('40'), findsOneWidget);
    expect(find.text('2'), findsNothing);
  });

  testWidgets('at 375px the view becomes one column with a bucket selector, body does not scroll sideways', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(_harness(
      buckets: _statusBuckets,
      rows: const [_working1],
      groupCounts: const [
        TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 1),
      ],
    ));

    // Only one column body renders...
    expect(find.byKey(const ValueKey('kanban-column-status-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('kanban-column-status-2')), findsNothing);
    // ...but a selector still names the other (empty) bucket, chip-style
    // rather than as a second scrolling column.
    expect(find.widgetWithText(ChoiceChip, 'Review · 0'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Working · 1'), findsOneWidget);
    // No horizontally-scrolling container anywhere in the tree — the page
    // body stays vertical-only at this width.
    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is SingleChildScrollView &&
            widget.scrollDirection == Axis.horizontal,
      ),
      findsNothing,
    );

    // Switching the selector swaps which single column is shown.
    await tester.tap(find.widgetWithText(ChoiceChip, 'Review · 0'));
    await tester.pump();
    expect(find.byKey(const ValueKey('kanban-column-status-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('kanban-column-status-1')), findsNothing);
  });

  testWidgets('no AlertDialog and no showDialog appears anywhere in the flow', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      buckets: _statusBuckets,
      rows: const [_working1],
      groupCounts: const [
        TaskGroupCount(groupId: 'status-1', value: 'status-1', count: 1),
      ],
      onMove: (task, field, bucketId) async => 'Refused.',
    ));
    expect(find.byType(AlertDialog), findsNothing);

    await _drag(
      tester,
      from: find.byKey(const ValueKey('kanban-card-task-1')),
      to: find.byKey(const ValueKey('kanban-column-status-2')),
    );

    expect(find.byType(AlertDialog), findsNothing);
  });
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
  required List<TaskKanbanBucket> buckets,
  required List<TaskRow> rows,
  required List<TaskGroupCount> groupCounts,
  TaskKanbanMove? onMove,
}) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: TaskKanbanView(
      board: _board,
      field: TaskKanbanField.status,
      buckets: buckets,
      rows: rows,
      groupCounts: groupCounts,
      onMove: onMove ?? (_, _, _) async => null,
    ),
  ),
);

const _statusBuckets = [
  TaskKanbanBucket(id: 'status-1', label: 'Working'),
  TaskKanbanBucket(id: 'status-2', label: 'Review'),
];

const _board = TaskBoard(
  id: 'board-1',
  name: 'Operations',
  description: null,
  visibility: TaskBoardVisibility.scope,
  rawVisibility: 'scope',
  companyId: 'company-1',
  franchiseId: null,
  folderId: null,
  structureVersion: 1,
  archived: false,
  isFavorite: false,
  lastOpenedAt: null,
  groups: [],
  statuses: [
    TaskStatusOption(
      id: 'status-1',
      name: 'Working',
      color: null,
      category: TaskStatusCategory.active,
      rawCategory: 'active',
      archived: false,
    ),
    TaskStatusOption(
      id: 'status-2',
      name: 'Review',
      color: null,
      category: TaskStatusCategory.active,
      rawCategory: 'active',
      archived: false,
    ),
  ],
  priorities: [],
  coreColumns: [],
  customColumns: [],
  views: [],
);

const _working1 = TaskRow(
  id: 'task-1',
  boardId: 'board-1',
  groupId: null,
  parentTaskId: null,
  number: 'TASK-1',
  title: 'Measure opening',
  ownerUserId: null,
  collaboratorUserIds: [],
  statusOptionId: 'status-1',
  priorityOptionId: null,
  startOn: null,
  dueOn: null,
  position: 1,
  version: 7,
  detailVersion: 1,
  values: {},
  groupByValue: 'status-1',
  subtasks: [],
);

const _working2 = TaskRow(
  id: 'task-2',
  boardId: 'board-1',
  groupId: null,
  parentTaskId: null,
  number: 'TASK-2',
  title: 'Install the opening',
  ownerUserId: null,
  collaboratorUserIds: [],
  statusOptionId: 'status-1',
  priorityOptionId: null,
  startOn: null,
  dueOn: null,
  position: 2,
  version: 1,
  detailVersion: 1,
  values: {},
  groupByValue: 'status-1',
  subtasks: [],
);
