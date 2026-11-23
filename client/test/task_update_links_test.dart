import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Row 38 (Merav, 11-23): "I can't open the links" on Monday's balance reports.
void main() {
  testWidgets('a [name](url) update shows the name as a live link', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (c) => linkedText(
              c,
              'See [MA 2026.xlsx](https://1drv.ms/x/b) and https://a.test/x',
            ),
          ),
        ),
      ),
    );
    final text = tester.widget<SelectableText>(find.byType(SelectableText));
    final spans = (text.textSpan!.children!).cast<TextSpan>();
    final links = spans.where((s) => s.recognizer is TapGestureRecognizer);
    expect(links.map((s) => s.text), ['MA 2026.xlsx', 'https://a.test/x']);
    expect(spans.map((s) => s.text).join(), isNot(contains('1drv.ms')));
  });
}
