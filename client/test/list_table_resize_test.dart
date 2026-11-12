import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

// Dragged columns wider than the window (Jose, 2026-11-12): a narrowed
// column's text wraps and its row grows, and a visible bar scrolls sideways.
void main() {
  testWidgets('a narrowed column grows its row; wide tables show a scrollbar', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: 1000,
            child: SingleChildScrollView(
              child: ListTable(
                widths: const {0: 900, 1: 80},
                onResize: (_, _) {},
                rowKey: (_) => const Key('row'),
                columns: const [ListColumn('Name'), ListColumn('Required')],
                rows: const [
                  [
                    Text('Ann'),
                    Text('Level 2 inspection, crown repair, liner install'),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );

    expect(
      tester.getSize(find.byKey(const Key('row'))).height,
      greaterThan(ListTable.rowHeight),
    );
    expect(find.byType(Scrollbar), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  testWidgets('undragged columns wider than the window scroll to the last', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: 1366,
            child: SingleChildScrollView(
              child: ListTable(
                columns: [
                  for (var i = 0; i < 16; i++) ListColumn('C$i', flex: 2),
                  const ListColumn('', flex: 4),
                ],
                rows: [
                  [
                    for (var i = 0; i < 16; i++) Text('v$i'),
                    FilledButton(
                      key: const Key('last'),
                      onPressed: () {},
                      child: const Text('Ready to schedule'),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );

    expect(find.byType(Scrollbar), findsOneWidget);
    await tester.drag(
      find.byType(SingleChildScrollView).last,
      const Offset(-3000, 0),
    );
    await tester.pumpAndSettle();
    final last = tester.getRect(find.byKey(const Key('last')));
    expect(last.right, lessThanOrEqualTo(1366));
    expect(last.width, greaterThan(150));
    expect(tester.takeException(), isNull);
  });
}
