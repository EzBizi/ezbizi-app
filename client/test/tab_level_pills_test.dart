import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13, P4: inner pills were 32 px tall (too small for a
/// thumb), and a record's tabs drew underlined unless the caller remembered
/// `level:` (the customer record did not; the job record did).
void main() {
  Widget host(Widget child) => MaterialApp(
    home: MediaQuery(
      data: const MediaQueryData(size: Size(375, 800)),
      child: Scaffold(body: child),
    ),
  );

  TabBarLike bar({TabLevel? level}) => TabBarLike(
    labels: const [
      ('Availability', Icons.event),
      ('Overbooking', Icons.warning),
    ],
    selected: 0,
    onSelected: (_) {},
    level: level,
  );

  testWidgets('an inner pill takes taps over at least 40 px', (tester) async {
    await tester.pumpWidget(host(bar(level: TabLevel.secondary)));
    final pill = find.byKey(const ValueKey('tab-pill-hit-1'));
    expect(tester.getSize(pill).height, greaterThanOrEqualTo(40));
  });

  testWidgets('a row inside a SidePanel draws pills without being told', (
    tester,
  ) async {
    await tester.pumpWidget(host(SidePanel(title: 'Customer', child: bar())));
    final chip = tester.widget<ChoiceChip>(
      find.ancestor(
        of: find.text('Availability'),
        matching: find.byType(ChoiceChip),
      ),
    );
    expect(chip.shape, isA<StadiumBorder>());
  });

  testWidgets('a module row outside a panel stays underlined', (tester) async {
    await tester.pumpWidget(host(bar()));
    final chip = tester.widget<ChoiceChip>(
      find.ancestor(
        of: find.text('Availability'),
        matching: find.byType(ChoiceChip),
      ),
    );
    expect(chip.shape, isNot(isA<StadiumBorder>()));
  });
}
