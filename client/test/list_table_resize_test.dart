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
}
