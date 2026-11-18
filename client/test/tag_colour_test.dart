import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/tag.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Desktop review 2026-11-13, D5: a Workiz tag's magenta never draws purple,
/// and its red never draws as a problem.
void main() {
  Future<Color?> fillOf(WidgetTester tester, Color colour) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: Tag('DEPOSIT RECEIVED', colour: colour)),
      ),
    );
    final box = tester.widget<Container>(
      find.ancestor(
        of: find.text('DEPOSIT RECEIVED'),
        matching: find.byType(Container),
      ),
    );
    return (box.decoration as BoxDecoration?)?.color;
  }

  testWidgets('magenta becomes the theme tertiary', (tester) async {
    final fill = await fillOf(tester, const Color(0xFFC026D3));
    expect(fill, EzBiziTheme.light().colorScheme.tertiary);
  });

  testWidgets('red becomes caution, not the problem red', (tester) async {
    final fill = await fillOf(tester, const Color(0xFFE11D48));
    expect(fill, isNot(const Color(0xFFE11D48)));
  });

  testWidgets('green passes through', (tester) async {
    expect(
      await fillOf(tester, const Color(0xFF16A34A)),
      const Color(0xFF16A34A),
    );
  });
}
