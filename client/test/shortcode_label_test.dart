import 'package:ezbizi_client/widgets/shortcode_button.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Desktop review 2026-11-13, D9: users saw raw "[ezbizi_address_line1]".
void main() {
  testWidgets('a shortcode reads as words; the tooltip names the code', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: ShortcodeButton(code: '[ezbizi_address_line1]')),
      ),
    );
    expect(find.text('Address line 1'), findsOneWidget);
    expect(find.textContaining('[ezbizi_'), findsNothing);
    expect(find.byTooltip('Copies [ezbizi_address_line1]'), findsOneWidget);
  });

  test('presets and foreign codes', () {
    expect(
      shortcodeLabel('[ezbizi_social_links preset="footer"]'),
      'Social links · footer',
    );
    expect(
      shortcodeLabel('<script src="x"></script>'),
      '<script src="x"></script>',
    );
  });
}
