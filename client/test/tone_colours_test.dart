import 'dart:math' as math;

import 'package:ezbizi_client/core/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Every status pill is readable, in both brightnesses.
///
/// A pill's text IS its content — "Canceled", "Done pending approval" — so it
/// owes the ordinary 4.5:1 against its own fill, not the 3:1 a decoration gets
/// away with. Six tones, two themes, twelve measurements; the numbers are in
/// the failure message so a changed colour says how far it fell.
void main() {
  for (final brightness in Brightness.values) {
    group(brightness.name, () {
      for (final MapEntry(key: tone, value: colours)
          in ToneColours.table(brightness).entries) {
        test('${tone.name} text is readable on its fill', () {
          final ratio = _contrast(colours.ink, colours.fill);

          expect(
            ratio,
            greaterThanOrEqualTo(4.5),
            reason:
                '${tone.name} is ${ratio.toStringAsFixed(2)}:1 in '
                '${brightness.name}.',
          );
        });
      }

      /// Red for Canceled was Jose's word. A red that could be mistaken for
      /// the neutral grey would satisfy the contrast test above and still not
      /// be what he asked for.
      test('canceled is red, and the red is not the grey', () {
        final table = ToneColours.table(brightness);
        final bad = HSVColor.fromColor(table[Tone.bad]!.ink);

        expect(bad.hue < 15 || bad.hue > 345, isTrue, reason: 'hue ${bad.hue}');
        expect(bad.saturation, greaterThan(0.35));
        expect(table[Tone.bad]!.ink, isNot(table[Tone.neutral]!.ink));
      });
    });
  }
}

/// WCAG contrast. Every tone colour is opaque, so there is nothing to
/// composite first — the alpha trap in field_prompt_vs_value_test does not
/// apply, and this asserts it rather than assuming it.
double _contrast(Color a, Color b) {
  expect(a.a, 1.0);
  expect(b.a, 1.0);

  final x = a.computeLuminance();
  final y = b.computeLuminance();

  return (math.max(x, y) + 0.05) / (math.min(x, y) + 0.05);
}
