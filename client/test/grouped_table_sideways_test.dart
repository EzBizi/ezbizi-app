import 'package:ezbizi_client/widgets/grouped_rows.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Desktop review 2026-11-13: the Jobs table cut Title, Customer, Where and
/// Booked by to a few letters while fixed columns kept their room. A flex
/// column now has a floor, and a window narrower than the floors scrolls the
/// table sideways instead of crushing them.
void main() {
  Future<void> pump(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 700);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: GroupedTable<String>(
            idOf: (r) => r,
            onOpen: (_) {},
            groups: const [
              RowGroup(heading: 'Today', rows: ['a']),
            ],
            columns: [
              TableColumn('Job', width: 220, cell: (_, _) => const Text('1')),
              TableColumn(
                'Title',
                flex: 3,
                minWidth: 200,
                cell: (_, _) => const Text('Title'),
              ),
              TableColumn(
                'Customer',
                flex: 3,
                minWidth: 160,
                cell: (_, _) => const Text('Customer'),
              ),
              TableColumn(
                'Where',
                flex: 3,
                minWidth: 180,
                cell: (_, _) => const Text('Where'),
              ),
              TableColumn(
                'Booked by',
                flex: 2,
                minWidth: 130,
                cell: (_, _) => const Text('Booked by'),
              ),
              TableColumn('Time', width: 88, cell: (_, _) => const Text('9')),
              TableColumn(
                'Status',
                width: 180,
                cell: (_, _) => const Text('S'),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  double cellWidth(WidgetTester tester, String heading) => tester
      .getSize(
        find
            .ancestor(
              of: find.text(heading).first,
              matching: find.byType(Expanded),
            )
            .first,
      )
      .width;

  testWidgets('a narrow window scrolls the table sideways and keeps each '
      'flex column at its floor', (tester) async {
    await pump(tester, 900);
    expect(find.byKey(const Key('grouped-table-sideways')), findsOneWidget);
    // Each cell's width includes its 12 px gap.
    expect(cellWidth(tester, 'Title'), greaterThanOrEqualTo(200));
    expect(cellWidth(tester, 'Customer'), greaterThanOrEqualTo(160));
    expect(cellWidth(tester, 'Where'), greaterThanOrEqualTo(180));
    expect(cellWidth(tester, 'Booked by'), greaterThanOrEqualTo(130));
  });

  testWidgets('a wide window fits without scrolling', (tester) async {
    await pump(tester, 1800);
    expect(find.byKey(const Key('grouped-table-sideways')), findsNothing);
  });
}
