import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ezbizi_client/core/theme.dart';

/// Two house rules about colour that nothing used to check.
///
/// Both exist because a rule nothing checks is a preference. The no-pop-ups rule
/// was stated in StartUp.md in as many words and five `AlertDialog`s shipped
/// anyway, found weeks later by an audit; the Tasks module now asserts
/// `findsNothing` for them per screen, which is the only reason that rule holds
/// where it holds. These are the colour equivalents.

/// WCAG relative luminance. sRGB channel -> linear, then the standard weights.
double _luminance(Color c) {
  double channel(double v) =>
      v <= 0.03928 ? v / 12.92 : pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
}

double contrast(Color a, Color b) {
  final la = _luminance(a);
  final lb = _luminance(b);
  final hi = la > lb ? la : lb;
  final lo = la > lb ? lb : la;
  return (hi + 0.05) / (lo + 0.05);
}

void main() {
  group('theme colour contrast', () {
    // 4.5:1 is AA for normal text. Deliberately not 3:1 (AA-large): these
    // tokens carry body text and 12px captions, not just headings.
    const aa = 4.5;

    for (final entry in {
      'light': EzBiziTheme.light(),
      'dark': EzBiziTheme.dark(),
    }.entries) {
      final mode = entry.key;
      final scheme = entry.value.colorScheme;

      test('$mode: primary reads as text on surface', () {
        // The failure this was written for: `primary` was pinned to the brand
        // cyan #00AEEF in both brightnesses. On white that is 2.5:1 — under
        // half the threshold, on the brand colour, on every primary action.
        expect(
          contrast(scheme.primary, scheme.surface),
          greaterThanOrEqualTo(aa),
          reason: '$mode primary on surface must clear AA — it carries text, '
              'icons and the input focus ring',
        );
      });

      test('$mode: onPrimary reads on a primary fill', () {
        // The other direction, and the one that made it load-bearing: a Gantt
        // bar is a primary fill with the task title written on it, so this is
        // content legibility, not button polish.
        expect(
          contrast(scheme.onPrimary, scheme.primary),
          greaterThanOrEqualTo(aa),
          reason: '$mode onPrimary on primary must clear AA — filled elements '
              'carry content, not just labels',
        );
      });

      test('$mode: onSurface reads on surface', () {
        expect(
          contrast(scheme.onSurface, scheme.surface),
          greaterThanOrEqualTo(aa),
        );
      });
    }
  });

  group('colour literals stay in theme.dart', () {
    // A ratchet, not a clean-room assertion. There are already literals in the
    // tree and fixing all of them is its own piece of work — but the count must
    // not grow while that is pending. Lower the number as they are cleaned up;
    // never raise it. If this fails, move your colour into theme.dart rather
    // than bumping the baseline.
    //
    // ponytail: a count, not an allowlist of 22 paths. An allowlist needs
    // editing every time a file is renamed and says nothing about direction.
    //
    // Measured 2026-08-31 by this test. Note it counts MATCHES, where a `grep
    // -c` over the same expression counts matching LINES — so a line holding
    // two literals makes the two disagree. Trust this number; it is the one the
    // gate enforces.
    //
    // Roughly 11 of these are benign (`Colors.transparent`) and 2 are the
    // sanctioned exception in signature_field.dart, which names black ink and
    // white paper because they are the pixels of a printed document. They are
    // inside the count deliberately: excluding them needs a second pattern that
    // itself drifts, and the ratchet only cares about direction.
    // Corrected 79 -> 80 on 2026-08-31. NOT a new literal: the count is
    // IDENTICAL at d0ce75a (where 79 was written) and at main, so nothing was
    // added after the baseline was declared. 79 was measured in a worktree that
    // did not yet hold 6d587f9, another session's landed SEO screen, whose
    // `Colors.transparent` is one of the benign ones described above. The merge
    // made the true count visible; it did not raise it.
    //
    // This is the shared-tree measurement problem in miniature, and worth
    // remembering before anyone corrects a ratchet again: a number measured in
    // YOUR worktree is a claim about your worktree. Measure a baseline at the
    // tip you are committing to, not at the tip you branched from.
    //
    // The rule above still stands and this is not a precedent for bumping it.
    // If it fails again, check whether the count moved BETWEEN the baseline
    // commit and now -- if it did, somebody added a colour and the fix is in
    // their file.
    const baseline = 80;

    test('no new hardcoded colours outside theme.dart', () {
      final pattern = RegExp(r'Color\(0x|Colors\.[a-z]');
      var found = 0;
      final worst = <String, int>{};

      for (final file in Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        if (file.path.replaceAll(r'\', '/').endsWith('core/theme.dart')) {
          continue;
        }
        final hits = pattern.allMatches(file.readAsStringSync()).length;
        if (hits > 0) {
          found += hits;
          worst[file.path] = hits;
        }
      }

      final ranked = worst.entries.toList()
        ..sort((a, b) => b.value.compareTo(a.value));

      expect(
        found,
        lessThanOrEqualTo(baseline),
        reason: 'Colour literals outside theme.dart rose to $found (baseline '
            '$baseline). Read the colour from the theme instead. Worst files: '
            '${ranked.take(4).map((e) => '${e.key}=${e.value}').join(', ')}',
      );
    });
  });
}
