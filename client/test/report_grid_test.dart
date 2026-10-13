import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/report_grid.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The one Reports table (UI queue 87): the naming column stays put while the
/// figures scroll sideways, a line says when there is more to the right, and
/// a loss is drawn in the bad tone.
void main() {
  Future<void> pump(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportGrid(
            columns: [
              const GridColumn('Branch', pinned: true),
              for (final c in [
                'Clicks',
                'Cost',
                'Leads',
                'Jobs',
                'Gross',
                'Net',
              ])
                GridColumn(c, numeric: true),
            ],
            rows: [
              [
                const GridCell('Stamford'),
                const GridCell('620'),
                const GridCell(r'$9,479.00'),
                const GridCell('37'),
                const GridCell('1'),
                const GridCell(r'$519.00'),
                const GridCell('−\$8,960.00', tone: Tone.bad),
              ],
            ],
            total: const [
              GridCell('Total'),
              GridCell.none(),
              GridCell(r'$9,479.00'),
              GridCell('37'),
              GridCell('1'),
              GridCell(r'$519.00'),
              GridCell('−\$8,960.00', tone: Tone.bad),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('narrow: the naming column stays while the rest scroll', (
    tester,
  ) async {
    await pump(tester, 640);

    expect(find.byKey(const Key('report-grid-more')), findsOneWidget);
    final before = tester.getTopLeft(find.text('Stamford')).dx;
    final cost = tester.getTopLeft(find.text(r'$9,479.00').first).dx;
    await tester.drag(
      find.byKey(const Key('report-grid-scroll')),
      const Offset(-200, 0),
    );
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(find.text('Stamford')).dx, before);
    expect(tester.getTopLeft(find.text(r'$9,479.00').first).dx, lessThan(cost));
  });

  testWidgets('wide: every column shows and no line asks to scroll', (
    tester,
  ) async {
    await pump(tester, 1400);

    expect(find.byKey(const Key('report-grid-more')), findsNothing);
    expect(find.byKey(const Key('report-grid-scroll')), findsNothing);
    expect(find.text('Total'), findsOneWidget);
    final loss = tester.widget<Text>(find.text('−\$8,960.00').first);
    final bad = ToneColours.of(
      tester.element(find.text('Total')),
      Tone.bad,
    ).ink;
    expect(loss.style?.color, bad);
  });

  testWidgets('a phone: each row a card, the rest a tap away', (tester) async {
    await pump(tester, 390);

    expect(find.byKey(const Key('report-grid-scroll')), findsNothing);
    final card = find.byKey(const ValueKey('report-grid-card-0'));
    expect(card, findsOneWidget);
    // The first three figures show; the other three wait for a tap.
    expect(
      find.descendant(of: card, matching: find.text('Clicks')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.text('Gross')),
      findsNothing,
    );
    expect(find.text('3 more ▾'), findsOneWidget);
    await tester.tap(card);
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: card, matching: find.text('Gross')),
      findsOneWidget,
    );
    expect(find.text('Total'), findsOneWidget);
  });
}
