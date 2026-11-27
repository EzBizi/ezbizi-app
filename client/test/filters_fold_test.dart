import 'package:ezbizi_client/widgets/filters_fold.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13, P10: on a phone the filters fold behind one
/// "Filters · n" button so the first record shows.
void main() {
  Widget host(double width) => MaterialApp(
    home: Scaffold(
      body: FiltersFold(
        count: 2,
        width: width,
        child: const Text('chips'),
      ),
    ),
  );

  testWidgets('a phone shows the button, not the chips, until tapped', (
    tester,
  ) async {
    await tester.pumpWidget(host(375));
    expect(find.text('Filters · 2'), findsOneWidget);
    expect(find.text('chips'), findsNothing);
    await tester.tap(find.text('Filters · 2'));
    await tester.pump();
    expect(find.text('chips'), findsOneWidget);
  });

  testWidgets('a wide list shows the chips with no button', (tester) async {
    await tester.pumpWidget(host(900));
    expect(find.text('chips'), findsOneWidget);
    expect(find.byKey(const Key('filters-fold')), findsNothing);
  });
}
