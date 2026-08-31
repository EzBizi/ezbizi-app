import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_calendar_view.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('a month grid renders tasks on their due dates', (tester) async {
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [_dueSep5, _dueSep20],
    ));

    // Each task appears under its own due-date cell...
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('calendar-day-2026-09-05')),
        matching: find.text('Measure opening'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('calendar-day-2026-09-20')),
        matching: find.text('Install the opening'),
      ),
      findsOneWidget,
    );
    // ...and nowhere else.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('calendar-day-2026-09-20')),
        matching: find.text('Measure opening'),
      ),
      findsNothing,
    );
  });

  testWidgets('the undated count is stated on screen, including at zero', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [_dueSep5],
      omitted: const TaskOmitted(undated: 4, outsideWindow: 0, unowned: 0),
    ));

    expect(find.text('4 tasks have no due date and are not shown here.'), findsOneWidget);

    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [_dueSep5],
    ));
    // Silence would read as "nothing is undated" — zero must still say so.
    expect(find.text('0 tasks have no due date and are not shown here.'), findsOneWidget);
  });

  testWidgets('dragging a card to another day issues the due-date write with its version', (
    tester,
  ) async {
    TaskRow? movedTask;
    DateTime? movedDay;

    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [_dueSep5],
      onMove: (task, day) async {
        movedTask = task;
        movedDay = day;
        return null;
      },
    ));

    await _drag(
      tester,
      from: find.byKey(const ValueKey('calendar-card-task-1')),
      to: find.byKey(const ValueKey('calendar-day-2026-09-20')),
    );

    expect(movedTask?.id, 'task-1');
    expect(movedTask?.version, 7);
    expect(movedDay, DateTime.utc(2026, 9, 20));
  });

  testWidgets(
    'a task due at 23:00 in the board place timezone renders on the correct day '
    'for a viewer in a different timezone',
    (tester) async {
      // The server already resolved "23:00 in the board's place timezone" to
      // this calendar day before truncating due_on to a date-only wire
      // value (task-11 brief); TaskRow.dueOn always arrives as UTC midnight
      // of that resolved day (task_models._parseDateOnly). If this widget
      // ever called `.toLocal()` before bucketing, a viewer running in a
      // timezone behind UTC — this suite's own host included — would see
      // the card roll back to August 31 instead. Constructed directly here
      // (not via JSON) to prove the bucketing itself, since real wire data
      // can never carry an hour on due_on in the first place.
      final dueAt2300PlaceTime = DateTime.utc(2026, 9, 1);
      final task = TaskRow(
        id: 'task-tz',
        boardId: 'board-1',
        groupId: null,
        parentTaskId: null,
        number: 'TASK-9',
        title: 'File the permit',
        ownerUserId: null,
        collaboratorUserIds: const [],
        statusOptionId: null,
        priorityOptionId: null,
        startOn: null,
        dueOn: dueAt2300PlaceTime,
        position: 1,
        version: 3,
        detailVersion: 1,
        values: const {},
        groupByValue: null,
        subtasks: const [],
      );

      await tester.pumpWidget(_harness(month: DateTime.utc(2026, 9), rows: [task]));

      expect(
        find.descendant(
          of: find.byKey(const ValueKey('calendar-day-2026-09-01')),
          matching: find.text('File the permit'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('calendar-day-2026-08-31')),
          matching: find.text('File the permit'),
        ),
        findsNothing,
      );
    },
  );

  testWidgets(
    'at 375px the month becomes a week list and the page body does not scroll sideways',
    (tester) async {
      tester.view.physicalSize = const Size(375, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(_harness(
        month: DateTime.utc(2026, 9),
        rows: [_dueSep5],
      ));

      expect(find.byKey(const ValueKey('calendar-week-list')), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('calendar-day-2026-09-05')),
          matching: find.text('Measure opening'),
        ),
        findsOneWidget,
      );
      // No horizontally-scrolling container anywhere — the page body stays
      // vertical-only at this width.
      expect(
        find.byWidgetPredicate(
          (widget) =>
              widget is SingleChildScrollView &&
              widget.scrollDirection == Axis.horizontal,
        ),
        findsNothing,
      );
    },
  );

  testWidgets('no AlertDialog and no showDialog appears anywhere in the flow', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [_dueSep5],
      onMove: (task, day) async => 'Refused.',
    ));
    expect(find.byType(AlertDialog), findsNothing);

    await _drag(
      tester,
      from: find.byKey(const ValueKey('calendar-card-task-1')),
      to: find.byKey(const ValueKey('calendar-day-2026-09-20')),
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
  required DateTime month,
  required List<TaskRow> rows,
  TaskOmitted omitted = TaskOmitted.zero,
  TaskCalendarMove? onMove,
}) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: TaskCalendarView(
      month: month,
      rows: rows,
      omitted: omitted,
      onMove: onMove ?? (_, _) async => null,
      onMonthChanged: (_) {},
    ),
  ),
);

// `DateTime.utc` is not a const constructor, so these carry real due dates
// and cannot be `const` themselves — unlike task_kanban_test.dart's fixtures,
// which never need a due date at all.
final _dueSep5 = TaskRow(
  id: 'task-1',
  boardId: 'board-1',
  groupId: null,
  parentTaskId: null,
  number: 'TASK-1',
  title: 'Measure opening',
  ownerUserId: null,
  collaboratorUserIds: const [],
  statusOptionId: null,
  priorityOptionId: null,
  startOn: null,
  dueOn: DateTime.utc(2026, 9, 5),
  position: 1,
  version: 7,
  detailVersion: 1,
  values: const {},
  groupByValue: null,
  subtasks: const [],
);

final _dueSep20 = TaskRow(
  id: 'task-2',
  boardId: 'board-1',
  groupId: null,
  parentTaskId: null,
  number: 'TASK-2',
  title: 'Install the opening',
  ownerUserId: null,
  collaboratorUserIds: const [],
  statusOptionId: null,
  priorityOptionId: null,
  startOn: null,
  dueOn: DateTime.utc(2026, 9, 20),
  position: 2,
  version: 2,
  detailVersion: 1,
  values: const {},
  groupByValue: null,
  subtasks: const [],
);
