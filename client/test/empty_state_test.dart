import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/empty_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// UI queue 70: the next step is a button, full width on a phone, and only
/// for someone who may take it.
void main() {
  Future<void> pump(
    WidgetTester tester,
    double width, {
    bool may = true,
  }) async {
    tester.view.physicalSize = Size(width, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: EmptyState(
            icon: Icons.phone_outlined,
            title: 'No phone lines yet',
            message: 'Calls and texts come in on a line.',
            actionLabel: 'Add a phone line',
            onAction: may ? () {} : null,
            otherwise: 'An owner can add one.',
          ),
        ),
      ),
    );
  }

  testWidgets('on a phone the button spans the width', (tester) async {
    await pump(tester, 375);
    final button = tester.getSize(find.byType(FilledButton)).width;
    expect(button, 375 - 48); // The width, less the padding.
    expect(tester.takeException(), isNull);
  });

  testWidgets('on a desk the button is its own size', (tester) async {
    await pump(tester, 1200);
    expect(
      tester.getSize(find.byType(FilledButton)).width,
      lessThan(440),
    ); // Not the column.
  });

  testWidgets('no button for someone who may not, and it says who can', (
    tester,
  ) async {
    await pump(tester, 1200, may: false);
    expect(find.byType(FilledButton), findsNothing);
    expect(find.text('An owner can add one.'), findsOneWidget);
  });

  // Mobile review 11-24: inside HeaderAboveList's NestedScrollView on iOS an
  // empty state that took the page's scroll controller overflowed the stack
  // ("could not be drawn", Inventory > Orders on an iPhone Duo). It scrolls on
  // its own controller.
  testWidgets('its scroll view never takes the page scroller', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: EmptyState(icon: Icons.inbox, title: 'None', message: 'Yet.'),
        ),
      ),
    );
    final scroll = tester.widget<SingleChildScrollView>(
      find.descendant(
        of: find.byType(EmptyState),
        matching: find.byType(SingleChildScrollView),
      ),
    );
    expect(scroll.primary, isFalse);
  });
}
