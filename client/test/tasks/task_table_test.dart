import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_table.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:flutter/material.dart';
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
              selectedTaskId: null,
              onSelected: (_) {},
              onTitleChanged: (_, _) {},
            ),
          ),
        ),
      );

      expect(find.text('To do'), findsOneWidget);
      expect(find.text('Measure opening'), findsOneWidget);
      expect(find.text('Task'), findsOneWidget);
      await tester.tap(find.byTooltip('Collapse To do'));
      await tester.pump();
      expect(find.text('Measure opening'), findsNothing);
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
