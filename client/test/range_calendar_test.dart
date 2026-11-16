import 'package:ezbizi_client/widgets/range_calendar.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('two clicks pick a range; days are one line each', (
    tester,
  ) async {
    final picks = <(String?, String?)>[];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Center(
            child: RangeCalendar(
              from: '2026-11-10',
              to: '2026-11-10',
              onChanged: (a, b) => picks.add((a, b)),
            ),
          ),
        ),
      ),
    );
    expect(find.text('November 2026'), findsOneWidget);
    // "16" fits on one line (it read "1 / 6" before).
    expect(tester.getSize(find.text('16')).height, lessThan(24));

    await tester.tap(find.byKey(const ValueKey('range-day-2026-11-12')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('range-day-2026-11-20')));
    await tester.pump();
    expect(picks.last, ('2026-11-12', '2026-11-20'));

    await tester.tap(find.byKey(const ValueKey('range-clear')));
    expect(picks.last, (null, null));
  });
}
