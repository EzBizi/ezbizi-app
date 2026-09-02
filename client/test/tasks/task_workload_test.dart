import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_workload_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('per-owner rows render counts per unit, labelled tasks and never hours', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [
        TaskWorkloadRow(
          ownerId: 'user-maya',
          ownerName: 'Maya Ortiz',
          units: [
            TaskWorkloadUnitCount(date: _d(2026, 9, 1), count: 3),
            TaskWorkloadUnitCount(date: _d(2026, 9, 2), count: 1),
          ],
        ),
      ],
    ));

    expect(find.byKey(const ValueKey('workload-row-user-maya')), findsOneWidget);
    expect(find.text('Maya Ortiz'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workload-cell-user-maya-2026-09-01')),
        matching: find.text('3'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workload-cell-user-maya-2026-09-02')),
        matching: find.text('1'),
      ),
      findsOneWidget,
    );
    // A day this owner has nothing due still renders as an explicit 0, not a
    // missing cell.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workload-cell-user-maya-2026-09-03')),
        matching: find.text('0'),
      ),
      findsOneWidget,
    );

    // THE DEFINING CONSTRAINT: tasks, never hours. The caption states this
    // out loud — "not hours" is the one place "hour" may legitimately
    // appear, as an explicit denial rather than a unit anyone could read a
    // count against. No capacity, threshold, or over-capacity concept
    // exists anywhere in this widget's tree.
    expect(find.textContaining('tasks'), findsWidgets);
    expect(
      find.text('Each number is a count of tasks due that day, not hours.'),
      findsOneWidget,
    );
    expect(find.textContaining('capacity'), findsNothing);
    expect(find.textContaining('over'), findsNothing);
    expect(find.textContaining('threshold'), findsNothing);
  });

  testWidgets('owner_id: null renders as an explicit Unassigned row, never dropped', (
    tester,
  ) async {
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [
        TaskWorkloadRow(
          ownerId: null,
          ownerName: null,
          units: [TaskWorkloadUnitCount(date: _d(2026, 9, 5), count: 2)],
        ),
        TaskWorkloadRow(
          ownerId: 'user-luis',
          ownerName: 'Luis Bell',
          units: [TaskWorkloadUnitCount(date: _d(2026, 9, 5), count: 1)],
        ),
      ],
    ));

    expect(find.byKey(const ValueKey('workload-row-unassigned')), findsOneWidget);
    expect(find.text('Unassigned'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workload-cell-unassigned-2026-09-05')),
        matching: find.text('2'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('at 375px the date columns scroll in their own container, never the page body', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 812);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: [
        TaskWorkloadRow(
          ownerId: 'user-maya',
          ownerName: 'Maya Ortiz',
          units: [TaskWorkloadUnitCount(date: _d(2026, 9, 1), count: 3)],
        ),
      ],
    ));

    expect(find.text('Maya Ortiz'), findsOneWidget);
    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is SingleChildScrollView &&
            widget.scrollDirection == Axis.horizontal,
      ),
      findsOneWidget,
    );
  });

  testWidgets('month navigation calls back with the new month', (tester) async {
    DateTime? changed;
    await tester.pumpWidget(_harness(
      month: DateTime.utc(2026, 9),
      rows: const [],
      onMonthChanged: (month) => changed = month,
    ));

    await tester.tap(find.byTooltip('Next month'));
    await tester.pump();

    expect(changed, DateTime.utc(2026, 10, 1));
  });

  testWidgets('no AlertDialog anywhere in this view', (tester) async {
    await tester.pumpWidget(_harness(month: DateTime.utc(2026, 9), rows: const []));
    expect(find.byType(AlertDialog), findsNothing);
  });
}

DateTime _d(int y, int m, int d) => DateTime.utc(y, m, d);

Widget _harness({
  required DateTime month,
  required List<TaskWorkloadRow> rows,
  ValueChanged<DateTime>? onMonthChanged,
}) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: TaskWorkloadView(
      month: month,
      window: TaskWorkloadWindow(
        from: DateTime.utc(month.year, month.month, 1),
        to: DateTime.utc(month.year, month.month + 1, 0),
        unit: TaskWorkloadUnit.day,
      ),
      rows: rows,
      onMonthChanged: onMonthChanged ?? (_) {},
    ),
  ),
);
