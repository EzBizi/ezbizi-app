import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_table.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets(
    'groups rows, keeps task identity pinned, and collapses a group',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: TaskTable(
              board: _board,
              rows: const [_row],
              groupCounts: const [
                TaskGroupCount(groupId: 'group-1', value: 'group-1', count: 12),
              ],
              selectedTaskId: null,
              onSelected: (_) {},
              onTitleChanged: (_, _) {},
            ),
          ),
        ),
      );

      expect(find.text('To do · 12'), findsOneWidget);
      expect(find.text('Measure opening'), findsOneWidget);
      expect(find.text('Task'), findsOneWidget);
      await tester.tap(find.byTooltip('Collapse To do · 12'));
      await tester.pump();
      expect(find.text('Measure opening'), findsNothing);
    },
  );

  testWidgets(
    'keeps task identity outside the scrolling columns and supports keyboard selection',
    (tester) async {
      TaskRow? selected;
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: TaskTable(
              board: _board,
              rows: const [_row],
              selectedTaskId: null,
              onSelected: (row) => selected = row,
              onTitleChanged: (_, _) {},
            ),
          ),
        ),
      );

      expect(find.byKey(const Key('pinned-task-column')), findsOneWidget);
      await tester.tap(find.byKey(const Key('task-row-task-1')));
      await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      expect(selected?.id, 'task-1');
    },
  );

  testWidgets('bulk lock preserves selection and ignores table shortcuts', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    var interactionLocked = false;
    var selectionCount = 0;
    var selectionCleared = false;
    final selectedTaskIds = <String>[];
    late StateSetter rebuild;

    await tester.pumpWidget(
      StatefulBuilder(
        builder: (context, setState) {
          rebuild = setState;
          return MaterialApp(
            theme: EzBiziTheme.light(),
            home: Scaffold(
              body: TaskTable(
                board: _board,
                rows: const [_row, _secondRow],
                selectedTaskId: _row.id,
                onSelected: (row) => selectedTaskIds.add(row.id),
                onTitleChanged: (_, _) {},
                onSelectionChanged: (count) => selectionCount = count,
                onSelectionCleared: () => selectionCleared = true,
                interactionLocked: interactionLocked,
              ),
            ),
          );
        },
      ),
    );

    await tester.tap(find.byType(Checkbox).first);
    await tester.pump();
    expect(selectionCount, 1);
    expect(find.text('1 selected'), findsOneWidget);

    rebuild(() => interactionLocked = true);
    await tester.pump();
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.pump();

    expect(selectionCount, 1);
    expect(selectionCleared, isFalse);
    expect(selectedTaskIds, isEmpty);
    expect(find.text('1 selected'), findsOneWidget);

    rebuild(() => interactionLocked = false);
    await tester.pump();
    expect(selectionCount, 1);
    expect(find.text('1 selected'), findsOneWidget);
  });

  testWidgets('a row reorder keeps the dirty title with its task identity', (
    tester,
  ) async {
    var rows = const <TaskRow>[_row, _secondRow];
    late StateSetter rebuild;
    await tester.pumpWidget(
      StatefulBuilder(
        builder: (context, setState) {
          rebuild = setState;
          return MaterialApp(
            theme: EzBiziTheme.light(),
            home: Scaffold(
              body: TaskTable(
                board: _board,
                rows: rows,
                selectedTaskId: null,
                onSelected: (_) {},
                onTitleChanged: (_, _) {},
              ),
            ),
          );
        },
      ),
    );

    Finder title(String taskId) => find.descendant(
      of: find.byKey(Key('task-row-$taskId')),
      matching: find.byType(TextField),
    );
    await tester.enterText(title(_row.id), 'Draft for task one');
    rebuild(() => rows = const <TaskRow>[_secondRow, _row]);
    await tester.pump();

    expect(
      tester.widget<TextField>(title(_row.id)).controller?.text,
      'Draft for task one',
    );
    expect(
      tester.widget<TextField>(title(_secondRow.id)).controller?.text,
      _secondRow.title,
    );
  });

  testWidgets(
    'a column reorder keeps the dirty controller with its task and column',
    (tester) async {
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var configuration = TaskViewConfiguration.empty.copyWith(
        hiddenColumns: const <String>[
          'owner',
          'status',
          'priority',
          'start_on',
          'due_on',
        ],
        columnOrder: const <String>['custom:alpha', 'custom:beta'],
      );
      late StateSetter rebuild;
      await tester.pumpWidget(
        StatefulBuilder(
          builder: (context, setState) {
            rebuild = setState;
            return MaterialApp(
              theme: EzBiziTheme.light(),
              home: Scaffold(
                body: TaskTable(
                  board: _customBoard,
                  rows: const <TaskRow>[_customRow],
                  selectedTaskId: null,
                  configuration: configuration,
                  onSelected: (_) {},
                  onTitleChanged: (_, _) {},
                  onCustomChanged: (_, _, _) async {},
                ),
              ),
            );
          },
        ),
      );

      Finder field(String label) => find.byWidgetPredicate(
        (widget) =>
            widget is TextField && widget.decoration?.labelText == label,
      );
      await tester.enterText(field('Alpha'), 'Draft alpha');
      rebuild(
        () => configuration = configuration.copyWith(
          columnOrder: const <String>['custom:beta', 'custom:alpha'],
        ),
      );
      await tester.pump();

      expect(
        tester.widget<TextField>(field('Alpha')).controller?.text,
        'Draft alpha',
      );
      expect(
        tester.widget<TextField>(field('Beta')).controller?.text,
        'Beta note',
      );
    },
  );
}

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
  groups: [
    TaskGroup(
      id: 'group-1',
      name: 'To do',
      color: null,
      position: 1,
      orderVersion: 1,
      archived: false,
    ),
  ],
  statuses: [
    TaskStatusOption(
      id: 'status-1',
      name: 'Working',
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

const _row = TaskRow(
  id: 'task-1',
  boardId: 'board-1',
  groupId: 'group-1',
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
  version: 1,
  detailVersion: 1,
  values: {},
  groupByValue: 'group-1',
  subtasks: [],
);

const _secondRow = TaskRow(
  id: 'task-2',
  boardId: 'board-1',
  groupId: 'group-1',
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
  groupByValue: 'group-1',
  subtasks: [],
);

const _alphaColumn = TaskColumnDefinition(
  id: 'alpha',
  name: 'Alpha',
  kind: TaskColumnKind.text,
  rawType: 'text',
  numberSuffix: null,
  position: 1,
  archived: false,
  options: [],
);

const _betaColumn = TaskColumnDefinition(
  id: 'beta',
  name: 'Beta',
  kind: TaskColumnKind.text,
  rawType: 'text',
  numberSuffix: null,
  position: 2,
  archived: false,
  options: [],
);

final _customBoard = TaskBoard(
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
  groups: _board.groups,
  statuses: _board.statuses,
  priorities: [],
  coreColumns: [],
  customColumns: [_alphaColumn, _betaColumn],
  views: [],
);

const _customRow = TaskRow(
  id: 'task-1',
  boardId: 'board-1',
  groupId: 'group-1',
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
  version: 1,
  detailVersion: 1,
  values: {
    'alpha': TaskCellValue(
      kind: TaskColumnKind.text,
      value: 'Alpha note',
      rawValue: 'Alpha note',
      isReadOnly: false,
    ),
    'beta': TaskCellValue(
      kind: TaskColumnKind.text,
      value: 'Beta note',
      rawValue: 'Beta note',
      isReadOnly: false,
    ),
  },
  groupByValue: 'group-1',
  subtasks: [],
);
