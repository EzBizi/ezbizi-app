import 'dart:math' as math;

import 'package:ezbizi_client/core/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

/// An empty field must not read like a filled one.
///
/// Jose, 2026-09-24, on a column of Web Management footer fields: *"there's
/// practically no difference on the field text for completed stuff vs
/// placeholders, u can't tell them apart."*
///
/// ── Why this is a theme test and not a Web Management one ───────
///
/// `inputDecorationTheme` styled borders, fill and padding and **nothing about
/// text** — no `labelStyle`, no `floatingLabelStyle`, no `hintStyle`. So every
/// form in EzBizi inherited Material's defaults, whose resting label is dark
/// enough to pass for content. The screen he photographed is simply the one
/// with the most empty fields next to full ones.
///
/// ── Why a ratio and not "they are different colours" ────────────
///
/// They always were: `#414849` against `#060808`. Two greys that differ by an
/// `==` and not by eye, which is exactly the complaint — so an inequality
/// assertion passes on the screen he sent and proves nothing.
///
/// **3:1 is borrowed, not invented.** It is WCAG's threshold for when two
/// things are reliably distinguishable. Measured by removing the styles and
/// running this file, rather than worked out by hand:
///
///     before   light 2.16:1   dark 1.70:1
///     after    light 3.43:1   dark 3.10:1
///
/// The dark theme was the worse of the two and nobody had reported it, which
/// is what an app-wide default does — it fails everywhere at once and gets
/// attributed to whichever screen somebody happened to be looking at.
void main() {
  for (final (name, theme) in [
    ('light', EzBiziTheme.light()),
    ('dark', EzBiziTheme.dark()),
  ]) {
    group(name, () {
      testWidgets('an empty prompt is distinguishable from a typed value', (
        tester,
      ) async {
        final styles = await _measure(tester, theme);

        expect(
          _ratio(styles.prompt, styles.value, over: styles.fill),
          greaterThanOrEqualTo(3.0),
          reason:
              'The prompt in an empty field and the text in a filled one are '
              '${_ratio(styles.prompt, styles.value, over: styles.fill).toStringAsFixed(2)}:1 '
              'apart. Under 3:1 a column of mixed fields reads as one block, '
              'which is what Jose photographed.',
        );
      });

      /// THE OTHER SIDE, and the reason this file has two tests.
      ///
      /// The cheap way to pass the one above is to make the prompt paler until
      /// it separates — which "fixes" the screen by making the thing somebody
      /// has to READ too faint. Every prompt is still text, so it owes the
      /// ordinary 4.5:1 against the field it sits in.
      testWidgets('and the prompt is still readable', (tester) async {
        final styles = await _measure(tester, theme);

        expect(
          _ratio(styles.prompt, styles.fill, over: styles.fill),
          greaterThanOrEqualTo(4.5),
          reason:
              'The prompt is only '
              '${_ratio(styles.prompt, styles.fill, over: styles.fill).toStringAsFixed(2)}:1 '
              'against the field behind it. Separating it from the value by '
              'making it unreadable is not the fix.',
        );
      });
    });
  }
}

typedef _Styles = ({Color prompt, Color value, Color fill});

Future<_Styles> _measure(WidgetTester tester, ThemeData theme) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: theme,
      home: Scaffold(
        body: Column(
          children: [
            TextFormField(
              initialValue: 'A1 Chimney',
              decoration: const InputDecoration(labelText: 'Company name'),
            ),
            const TextField(
              decoration: InputDecoration(labelText: 'Second phone number'),
            ),
          ],
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  // The RESTING label of the empty field — the thing that looked like content.
  final prompt = tester
      .renderObject<RenderParagraph>(find.text('Second phone number'))
      .text
      .style!
      .color!;

  // What somebody actually typed, read off the editable rather than the
  // decoration: the decoration is what was mis-styled, so reading the value
  // from it would compare the suspect against itself.
  final value = tester
      .widget<EditableText>(find.byType(EditableText).first)
      .style
      .color!;

  final fill = theme.inputDecorationTheme.fillColor!;

  return (prompt: prompt, value: value, fill: fill);
}

/// WCAG relative-luminance contrast, **composited over the field first**.
///
/// `computeLuminance()` IGNORES ALPHA. The dark theme's prompt was
/// `Colors.white60` — pure white at 60% — so an uncomposited reading called it
/// white and reported 1.00:1 against a white value, when what the eye gets is
/// a mid grey. My first version of this file did exactly that and produced a
/// number with no relationship to the screen.
///
/// So every translucent colour is blended onto the fill before it is measured,
/// which is what the eye is doing anyway.
double _ratio(Color a, Color b, {required Color over}) {
  final x = Color.alphaBlend(a, over).computeLuminance();
  final y = Color.alphaBlend(b, over).computeLuminance();

  return (math.max(x, y) + 0.05) / (math.min(x, y) + 0.05);
}
