import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_column_cells.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('keeps unknown and malformed custom values visible but read-only', () {
    final unknown = TaskCellValue.fromJson(<String, Object?>{'future': true});
    final malformed = TaskCellValue.fromJson(
      'not-a-number',
      definition: const TaskColumnDefinition(
        id: 'number',
        name: 'Estimate',
        kind: TaskColumnKind.number,
        rawType: 'number',
        numberSuffix: 'h',
        position: 1,
        archived: false,
        options: [],
      ),
    );

    expect(unknown.isReadOnly, isTrue);
    expect(unknown.rawValue, <String, Object?>{'future': true});
    expect(malformed.isReadOnly, isTrue);
    expect(malformed.rawValue, 'not-a-number');
  });

  test(
    'accepts the canonical decimal-string Number response without losing raw wire value',
    () {
      final value = TaskCellValue.fromJson(
        '3.500000',
        definition: _column('Decimal', TaskColumnKind.number),
      );
      expect(value.value, 3.5);
      expect(value.rawValue, '3.500000');
    },
  );

  testWidgets('renders each declared custom type and keeps File unavailable', (
    tester,
  ) async {
    final saved = <Object?>[];
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Column(
            children: [
              for (final entry in _cells.entries)
                TaskColumnCell(
                  task: _task,
                  definition: entry.key,
                  value: entry.value,
                  participants: const [
                    TaskParticipant(
                      id: 'user-1',
                      name: 'Taylor',
                      email: 'taylor@example.com',
                    ),
                  ],
                  onChanged: (_, value) async => saved.add(value),
                ),
            ],
          ),
        ),
      ),
    );

    expect(find.text('A note'), findsOneWidget);
    expect(find.bySemanticsLabel('Number'), findsOneWidget);
    expect(find.byType(Checkbox), findsOneWidget);
    expect(find.bySemanticsLabel('Date'), findsOneWidget);
    expect(find.text('Taylor'), findsOneWidget);
    expect(find.text('Customer'), findsOneWidget);
    expect(find.bySemanticsLabel('Link'), findsOneWidget);
    expect(
      find.text('Files are unavailable until the restore gate passes.'),
      findsOneWidget,
    );
    await tester.tap(find.byTooltip('Toggle Ready'));
    await tester.pump();
    expect(saved, <Object?>[false]);
  });

  test('multi-value columns cannot be sorted', () {
    expect(TaskColumnCell.isSortable(TaskColumnKind.people), isFalse);
    expect(TaskColumnCell.isSortable(TaskColumnKind.label), isFalse);
    expect(TaskColumnCell.isSortable(TaskColumnKind.file), isFalse);
    expect(TaskColumnCell.isSortable(TaskColumnKind.number), isTrue);
  });

  testWidgets('custom text editor reports its stable draft identity', (
    tester,
  ) async {
    final drafts = <String, bool>{};
    final definition = _column('Text', TaskColumnKind.text);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskColumnCell(
            task: _task,
            definition: definition,
            value: const TaskCellValue(
              kind: TaskColumnKind.text,
              value: 'A note',
              rawValue: 'A note',
              isReadOnly: false,
            ),
            onChanged: (_, _) async {},
            onDraftChanged: (id, dirty) => drafts[id] = dirty,
          ),
        ),
      ),
    );

    await tester.enterText(find.byType(TextField), 'Draft note');
    expect(drafts['task:task-1:custom:${definition.id}'], isTrue);
    await tester.enterText(find.byType(TextField), 'A note');
    expect(drafts['task:task-1:custom:${definition.id}'], isFalse);
  });

  test(
    'selection carries the exact current versions for atomic bulk changes',
    () {
      final selection = TaskSelection()
        ..toggle(_task)
        ..toggle(_otherTask);

      expect(selection.count, 2);
      expect(
        selection.bulkPayload(const <String, Object?>{
          'status_option_id': 'done',
        }),
        <String, Object?>{
          'tasks': <Object?>[
            <String, Object?>{'id': 'task-1', 'expected_version': 4},
            <String, Object?>{'id': 'task-2', 'expected_version': 8},
          ],
          'changes': <String, Object?>{'status_option_id': 'done'},
        },
      );
    },
  );

  test(
    'scalar mutation is optimistic then rolls back with the server sentence',
    () async {
      final controller = TaskCellEditController(_task);
      final outcome = await controller.saveScalar(
        field: 'title',
        value: 'Changed',
        send: (_, _) async => const TaskCellSaveFailure(
          'This title is already used in this board.',
        ),
      );

      expect(controller.valueFor('title'), 'Measure opening');
      expect(outcome.message, 'This title is already used in this board.');
    },
  );

  test('structural mutation waits for the server response', () async {
    final controller = TaskCellEditController(_task);
    var senderStarted = false;
    final pending = controller.saveStructural(
      field: 'group_id',
      value: 'group-2',
      send: (_, _) async {
        senderStarted = true;
        return const TaskCellSaveSuccess(<String, Object?>{
          'group_id': 'group-2',
        });
      },
    );

    expect(senderStarted, isFalse);
    expect(controller.valueFor('group_id'), 'group-1');
    await pending;
    expect(senderStarted, isTrue);
    expect(controller.valueFor('group_id'), 'group-2');
  });
}

const _task = TaskRow(
  id: 'task-1',
  boardId: 'board-1',
  groupId: 'group-1',
  parentTaskId: null,
  number: 'TASK-1',
  title: 'Measure opening',
  ownerUserId: null,
  collaboratorUserIds: [],
  statusOptionId: null,
  priorityOptionId: null,
  startOn: null,
  dueOn: null,
  position: 1,
  version: 4,
  detailVersion: 1,
  values: {},
  groupByValue: null,
  subtasks: [],
);

const _otherTask = TaskRow(
  id: 'task-2',
  boardId: 'board-1',
  groupId: 'group-1',
  parentTaskId: null,
  number: 'TASK-2',
  title: 'Order glass',
  ownerUserId: null,
  collaboratorUserIds: [],
  statusOptionId: null,
  priorityOptionId: null,
  startOn: null,
  dueOn: null,
  position: 2,
  version: 8,
  detailVersion: 1,
  values: {},
  groupByValue: null,
  subtasks: [],
);

final _cells = <TaskColumnDefinition, TaskCellValue>{
  _column('Text', TaskColumnKind.text): const TaskCellValue(
    kind: TaskColumnKind.text,
    value: 'A note',
    rawValue: 'A note',
    isReadOnly: false,
  ),
  _column('Number', TaskColumnKind.number, suffix: 'h'): const TaskCellValue(
    kind: TaskColumnKind.number,
    value: 3.5,
    rawValue: 3.5,
    isReadOnly: false,
  ),
  _column('Ready', TaskColumnKind.checkbox): const TaskCellValue(
    kind: TaskColumnKind.checkbox,
    value: true,
    rawValue: true,
    isReadOnly: false,
  ),
  _column('Date', TaskColumnKind.date): TaskCellValue(
    kind: TaskColumnKind.date,
    value: DateTime.utc(2026, 8, 31),
    rawValue: '2026-08-31',
    isReadOnly: false,
  ),
  _column('People', TaskColumnKind.people): const TaskCellValue(
    kind: TaskColumnKind.people,
    value: <String>['user-1'],
    rawValue: <String>['user-1'],
    isReadOnly: false,
  ),
  _column(
    'Labels',
    TaskColumnKind.label,
    options: [
      TaskColumnOption(
        id: 'label-1',
        name: 'Customer',
        color: null,
        position: 1,
        archived: false,
      ),
    ],
  ): const TaskCellValue(
    kind: TaskColumnKind.label,
    value: <String>['label-1'],
    rawValue: <String>['label-1'],
    isReadOnly: false,
  ),
  _column('Link', TaskColumnKind.link): const TaskCellValue(
    kind: TaskColumnKind.link,
    value: TaskLinkValue(url: 'https://example.com', label: 'Read more'),
    rawValue: <String, Object?>{
      'url': 'https://example.com',
      'label': 'Read more',
    },
    isReadOnly: false,
  ),
  _column('Files', TaskColumnKind.file): const TaskCellValue(
    kind: TaskColumnKind.file,
    value: <String>['file-1'],
    rawValue: <String>['file-1'],
    isReadOnly: false,
  ),
};

TaskColumnDefinition _column(
  String name,
  TaskColumnKind kind, {
  String? suffix,
  List<TaskColumnOption> options = const [],
}) => TaskColumnDefinition(
  id: name.toLowerCase(),
  name: name,
  kind: kind,
  rawType: name.toLowerCase(),
  numberSuffix: suffix,
  position: 1,
  archived: false,
  options: options,
);
