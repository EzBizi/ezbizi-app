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

  testWidgets(
    'a card shows which board it belongs to only when the caller supplies '
    'that map — a single board\'s own Calendar never does',
    (tester) async {
      await tester.pumpWidget(_harness(
        month: DateTime.utc(2026, 9),
        rows: [_dueSep5],
        boardNamesByTaskId: const {'task-1': 'Operations'},
      ));
      expect(find.text('Operations'), findsOneWidget);

      // Same task, same title, no map — the single-board shape this widget
      // has always had, unaffected by the cross-board caller's addition.
      await tester.pumpWidget(_harness(
        month: DateTime.utc(2026, 9),
        rows: [_dueSep5],
      ));
      expect(find.text('Operations'), findsNothing);
    },
  );

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
      // due_on is a pure date column — TasksController validates writes as
      // date_format:Y-m-d, so no time or timezone ever attaches to it — and
      // TaskRow.dueOn always arrives as DateTime.utc(y, m, d), already the
      // correct calendar day. This test proves the widget never runs that
      // value through `.toLocal()`, which on a date-only value can only ever
      // shift it BACKWARD (there is no time-of-day left to push it forward).
      //
      // Reach of this assertion: it fails a reintroduced `.toLocal()` call
      // on any host running behind UTC (this suite's own EDT host included).
      // It CANNOT detect the same bug on a host at or ahead of UTC —
      // `.toLocal()` on a UTC-midnight value is a no-op there, since a
      // date-only value has no time to lose in that direction. There is no
      // single instant that catches both directions, and this repo pins no
      // process timezone, so both adjacent days are asserted absent below to
      // cover whichever direction a shift can occur in on the host that
      // happens to run this suite.
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
        dueOn: DateTime.utc(2026, 9, 1),
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
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('calendar-day-2026-09-02')),
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
  bool readOnly = false,
  Map<String, String>? boardNamesByTaskId,
}) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: TaskCalendarView(
      month: month,
      rows: rows,
      omitted: omitted,
      onMove: onMove ?? (_, _) async => null,
      onMonthChanged: (_) {},
      readOnly: readOnly,
      boardNamesByTaskId: boardNamesByTaskId,
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
