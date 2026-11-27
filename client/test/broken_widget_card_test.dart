import 'package:ezbizi_client/widgets/broken_widget_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Phone review 2026-11-13, item 1: one widget that threw while building
/// (Reports › Scoring) blanked the whole app. With the builder installed it
/// draws a card in its place and the rest of the screen stays.
void main() {
  testWidgets('a widget that throws draws a card, the rest still draws', (
    tester,
  ) async {
    final previous = ErrorWidget.builder;
    BrokenWidgetCard.install();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              const Text('Still here'),
              Builder(builder: (_) => throw RangeError.index(3, const [])),
            ],
          ),
        ),
      ),
    );
    expect(tester.takeException(), isA<RangeError>());
    expect(find.byKey(const Key('broken-widget-card')), findsOneWidget);
    expect(find.text('Still here'), findsOneWidget);
    ErrorWidget.builder = previous;
  });
}
