import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ezbizi_client/core/theme.dart';

/// Two house rules about colour that nothing used to check.
///
/// Both exist because a rule nothing checks is a preference. The no-pop-ups rule
/// was stated in StartUp.md in as many words and five `AlertDialog`s shipped
/// anyway, found weeks later by an audit; screens now assert `findsNothing`
/// for them one by one, which is the only reason that rule holds where it
/// holds. These are the colour equivalents.

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
  // Only the filled button had a shape, so every other kind kept Material's
  // pill: an outlined "Export" beside a boxed "Add job", and a box and two
  // ovals side by side on Scheduling. Jose: "app needs to be cohesive."
  for (final entry in {
    'light': EzBiziTheme.light(),
    'dark': EzBiziTheme.dark(),
  }.entries) {
    test('${entry.key}: every button kind and the chips share one shape', () {
      final t = entry.value;
      final shapes = <String, Object?>{
        'filled': t.filledButtonTheme.style?.shape?.resolve({}),
        'outlined': t.outlinedButtonTheme.style?.shape?.resolve({}),
        'text': t.textButtonTheme.style?.shape?.resolve({}),
        'elevated': t.elevatedButtonTheme.style?.shape?.resolve({}),
        'segmented': t.segmentedButtonTheme.style?.shape?.resolve({}),
        'chip': t.chipTheme.shape,
      };
      final expected = RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      );
      for (final shape in shapes.entries) {
        expect(shape.value, expected, reason: '${shape.key} button');
      }
    });
  }

  // The shape was one and the heights were not: only the filled kind carried
  // padding of its own, so "Done" stood taller than "Write another" beside it.
  // Measured as a phone and as a desktop sizes them: a phone pads every tap
  // target to 48, which hides a difference a desktop shows.
  for (final (name, density, target) in [
    ('phone', VisualDensity.standard, MaterialTapTargetSize.padded),
    ('desktop', VisualDensity.compact, MaterialTapTargetSize.shrinkWrap),
  ]) {
    testWidgets('every button kind is one height on a $name', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light().copyWith(
            visualDensity: density,
            materialTapTargetSize: target,
          ),
          home: Scaffold(
            body: Center(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  FilledButton(onPressed: () {}, child: const Text('Done')),
                  OutlinedButton(
                    onPressed: () {},
                    child: const Text('Write another'),
                  ),
                  TextButton(onPressed: () {}, child: const Text('Cancel')),
                  ElevatedButton(onPressed: () {}, child: const Text('Export')),
                ],
              ),
            ),
          ),
        ),
      );

      final heights = {
        for (final kind in [
          FilledButton,
          OutlinedButton,
          TextButton,
          ElevatedButton,
        ])
          tester.getSize(find.byType(kind)).height,
      };
      expect(heights, hasLength(1), reason: 'Heights: $heights');
    });
  }

  // An avatar's initials sit on its fill: 4.5:1 at least, in both
  // brightnesses, for every person colour.
  test('every person colour reads, and a person keeps theirs', () {
    for (final brightness in Brightness.values) {
      for (final pair in PersonColours.table(brightness)) {
        expect(
          contrast(pair.ink, pair.fill),
          greaterThanOrEqualTo(4.5),
          reason: '$brightness ${pair.ink} on ${pair.fill}',
        );
      }
    }

    expect(
      PersonColours.forSeed('u-42', Brightness.light),
      same(PersonColours.forSeed('u-42', Brightness.light)),
    );
    final spread = {
      for (var i = 0; i < 40; i++)
        PersonColours.forSeed('user-$i', Brightness.light),
    };
    expect(spread.length, greaterThan(5), reason: 'The seeds bunch up.');
  });

  // "No modal dialogs: screens or side panels" (DEVELOPMENT-RULES) had no
  // check, and the AI Library's Add answer shipped as an AlertDialog. These
  // files still open one, with the session that owns each; the list may only
  // shrink.
  test('no new screen opens a modal dialog', () {
    const stillOpenOne = {
      'lib/widgets/search_picker.dart': 'the picker is the settled chooser',
      // Stays. Jose's spec (2026-09-08) is a modal at shift end; unanswered,
      // the sweep clocks the person out and the lock screen follows.
      'lib/widgets/shift_reminder.dart': 'Clock In, must interrupt',
      'lib/screens/a1leads_gbp_screen.dart': 'Google API',
      'lib/screens/phone/call_flow_chart_pane.dart': 'Phone',
      'lib/screens/web/widgets_tab.dart': 'Web Management',
    };
    final opening = {
      for (final file in Directory('lib').listSync(recursive: true))
        if (file is File &&
            file.path.endsWith('.dart') &&
            RegExp(
              r'showDialog\s*[<(]|AlertDialog\(',
            ).hasMatch(file.readAsStringSync()))
          file.path.replaceAll(r'\', '/'),
    };
    expect(
      opening.difference(stillOpenOne.keys.toSet()),
      isEmpty,
      reason: 'A new modal dialog: use a SidePanel or a screen.',
    );
    expect(
      stillOpenOne.keys.toSet().difference(opening),
      isEmpty,
      reason: 'Converted: take it off the list.',
    );
  });

  // Nine create panes scrolled with Clip.none, so their fields slid under the
  // Cancel / Save bar below them, which has no background: on a phone "Save"
  // sat on top of the Body type field.
  test('no scroll view draws its content outside itself', () {
    final offenders = <String>[
      for (final file in Directory('lib').listSync(recursive: true))
        if (file is File &&
            file.path.endsWith('.dart') &&
            RegExp(
              r'ScrollView\(\s*clipBehavior:\s*Clip\.none',
            ).hasMatch(file.readAsStringSync()))
          file.path,
    ];
    expect(offenders, isEmpty);
  });

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

  /// Every heading the app asks for is one somebody actually defined.
  ///
  /// `theme.dart` listed four text styles; the app asks for six. The three
  /// missing ones did NOT fall back to Material — ThemeData does not merge its
  /// typography in behind that map. They came through as a TextStyle carrying
  /// a colour and nothing else, `fontSize: null, fontWeight: null`, so every
  /// call site rendered at the ambient body size and weight. 109 of them, and
  /// `titleSmall` was 93 on its own.
  ///
  /// The getter is never null, so absence cannot be read from it directly. The
  /// WEIGHT is what gives it away: an unfilled slot has none, and every slot we
  /// fill is 600 or heavier.
  /// A selectable chip with an icon must not also draw a checkmark.
  ///
  /// Material does not put the tick BESIDE the avatar. It paints it ON the
  /// avatar, and darkens the avatar first so the tick can be read against it —
  /// `_kSelectScrimColor = Color(0x60191919)`, filled through `avatarBorder`,
  /// which is a circle. So the selected chip's own icon disappears under a grey
  /// disc.
  ///
  /// Reported from the module tab rows, and the way it was reported is the
  /// clue: the disc appeared "regardless if I'm hovering over that tab or any
  /// tab". It follows the SELECTION, not the pointer — it reads as a stuck
  /// hover state and is not one.
  ///
  /// Enumerated rather than listed, because the two known cases are the two
  /// somebody happened to find. This is the same reason `titleSmall` was missed
  /// by a hand-written audit on the same day.
  /// One tab strip, so two screens cannot look like two products.
  ///
  /// Sixteen screens use `TabBarLike` — a row of chips. Leads used Material's
  /// `TabBar`: underlined, stretched across the full width, no icons. Side by
  /// side with Services or Communication it read as a different application,
  /// which is what got it reported.
  ///
  /// Enumerated rather than fixed where it was found. Leads was the only one
  /// left, and the only way to keep that true is to check all of them.
  group('every screen uses the same tab strip', () {
    test('no screen falls back to a Material TabBar', () {
      final banned = RegExp(r'\b(TabBar|TabBarView|DefaultTabController)\(');
      var scanned = 0;
      var usesShared = 0;
      final offenders = <String>[];

      for (final file in Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        scanned++;
        final rel = file.path.replaceAll(r'\', '/');
        final source = file.readAsStringSync();

        if (source.contains('TabBarLike(')) usesShared++;

        // The shared widget is allowed to be called TabBarLike without this
        // matching it, and it is the one place a raw strip could legitimately
        // live if it ever needed one.
        if (rel.endsWith('widgets/tab_bar_like.dart')) continue;

        for (final match in banned.allMatches(source)) {
          // `TabBarLike(` starts with TabBar but is the thing we want.
          if (source.startsWith('TabBarLike(', match.start)) continue;
          final line = '\n'.allMatches(source.substring(0, match.start)).length + 1;
          offenders.add('$rel:$line  ${match.group(1)}');
        }
      }

      // Floors before the rule, as everywhere else here.
      expect(scanned, greaterThan(100),
          reason: 'Only $scanned .dart files scanned under lib/.');
      expect(usesShared, greaterThan(10),
          reason: 'Found TabBarLike in only $usesShared files. It is in more '
              'than ten; the scan is broken and this test is reporting '
              'nothing rather than reporting clean.');

      expect(
        offenders,
        isEmpty,
        reason:
            'These use Material\'s tab strip instead of the app\'s:\n  '
            '${offenders.join('\n  ')}\n'
            'TabBarLike is a row of chips with icons; TabBar is underlined and '
            'full width. A screen with the wrong one looks like a different '
            'product from the page next to it.',
      );
    });
  });

  group('a chip does not cover its own icon', () {
    test('every selectable chip with an avatar sets showCheckmark', () {
      var scanned = 0;
      var withAvatar = 0;
      final offenders = <String>[];

      for (final file in Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        scanned++;
        final source = file.readAsStringSync();

        for (final kind in const ['ChoiceChip(', 'FilterChip(']) {
          var at = source.indexOf(kind);
          while (at >= 0) {
            final args = _argsOf(source, at + kind.length - 1);
            if (args != null && args.contains('avatar:')) {
              withAvatar++;
              if (!args.contains('showCheckmark:')) {
                final line = '\n'.allMatches(source.substring(0, at)).length + 1;
                offenders.add('${file.path.replaceAll(r'\', '/')}:$line');
              }
            }
            at = source.indexOf(kind, at + 1);
          }
        }
      }

      // Floors first. A paren matcher that returns null for everything finds no
      // offenders and passes forever.
      expect(scanned, greaterThan(100),
          reason: 'Only $scanned .dart files scanned under lib/.');
      expect(withAvatar, greaterThan(0),
          reason: 'No selectable chip with an avatar was found anywhere. That '
              'has never been true; the scan is broken and this test is '
              'reporting nothing rather than reporting clean.');

      expect(
        offenders,
        isEmpty,
        reason:
            'These draw a checkmark on top of their own icon:\n  '
            '${offenders.join('\n  ')}\n'
            'Material darkens the avatar with a 0x60191919 disc to make the '
            'tick readable, so the icon that identifies the option vanishes at '
            'the moment it is chosen. Pass showCheckmark: false — the filled '
            'background already says which one is selected.',
      );
    });
  });

  group('no heading is left unstyled', () {
    test('every heading style the app uses carries a weight of its own', () {
      final pattern = RegExp(r'textTheme\.([a-zA-Z]+)');
      final asked = <String>{};
      var scanned = 0;

      for (final file in Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        scanned++;
        for (final match in pattern.allMatches(file.readAsStringSync())) {
          asked.add(match.group(1)!);
        }
      }

      // EVERY slot the app asks for must have a SIZE. The weight rule below
      // only inspects display/headline/title, which is why this fault reached
      // its fourth instance: `bodySmall` sat unfilled with 469 call sites
      // across 113 files, rendering at the ambient 14 -- identical to
      // `bodyMedium`, so the app drew a distinction nobody could see.
      //
      // An unlisted slot arrives carrying a colour and nothing else. That is
      // invisible by eye precisely because it inherits something plausible,
      // which is what let three earlier ones ship.
      for (final theme in {
        'light': EzBiziTheme.light(),
        'dark': EzBiziTheme.dark(),
      }.entries) {
        for (final name in asked) {
          final style = _slot(theme.value.textTheme, name);
          if (style == null) continue;

          expect(
            style.fontSize,
            isNotNull,
            reason: 'textTheme.$name is used in lib/ but ${theme.key} leaves it '
                'unfilled, so every call site renders at whatever size the '
                'surrounding text happens to be. Define it in theme.dart, or '
                'stop asking for it.',
          );
        }
      }

      final headings = asked
          .where((n) =>
              n.startsWith('display') ||
              n.startsWith('headline') ||
              n.startsWith('title'))
          .toList()
        ..sort();

      // The floors, before the rule — the same reason the colour ratchet has
      // them. A regex that matches nothing passes silently.
      expect(scanned, greaterThan(100),
          reason: 'Only $scanned .dart files scanned under lib/. The scan is '
              'broken and this test says nothing until it is fixed.');
      expect(headings, isNotEmpty,
          reason: 'No heading styles found anywhere in lib/. That has never '
              'been true; the pattern is broken.');

      for (final theme in {
        'light': EzBiziTheme.light(),
        'dark': EzBiziTheme.dark(),
      }.entries) {
        for (final name in headings) {
          final style = _slot(theme.value.textTheme, name);
          if (style == null) continue;

          expect(
            style.fontWeight?.value ?? 400,
            greaterThanOrEqualTo(600),
            reason:
                'textTheme.$name is used in lib/ but ${theme.key} resolves it '
                'to weight ${style.fontWeight?.value ?? 'none'}. Nothing in '
                'this app is lighter than 600, and a slot with NO weight is '
                'one theme.dart never filled in — every call site is rendering '
                'at whatever size the surrounding text happens to be. Define '
                'it there, or stop asking for it.',
          );
        }
      }
    });
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
    // SUPERSEDED, and left here because the reasoning was wrong in a way worth
    // seeing. This used to say sanctioned literals were "inside the count
    // deliberately", on the grounds that excluding them needs a second pattern
    // that itself drifts. That was true about the mechanism and wrong about the
    // consequence: a count that mixes allowed colours with owed ones cannot be
    // acted on, because nobody can tell which is which — and the only way to add
    // an allowed one is to raise the ceiling, which is the exact move the ratchet
    // exists to prevent. They are excluded now. See `sanctioned` below.
    //
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
    // TO REPRODUCE THIS NUMBER OUTSIDE THE TEST, count MATCHES across ALL of
    // lib/ including its top level:
    //
    //   git grep -h -o -E "Color\(0x|Colors\.[a-z]" main     //     -- 'client/lib' ':(exclude)client/lib/core/theme.dart' | wc -l
    //
    // A glob of `client/lib/**/*.dart` gives 78, because `**/` requires at least
    // one directory segment and silently skips lib/main.dart, which holds two.
    // Two sessions hit that same artefact within an hour and one of them nearly
    // filed it as the ratchet having slack -- which would have invited somebody
    // to "correct" 80 down to 78 and turn the gate red for everybody. A wrong
    // number measured twice by the same broken method reads like corroboration.
    //
    // GIT PATHSPECS ARE NOT SHELL GLOBS, and they fail in BOTH directions here:
    //
    //   client/lib/**/*.dart   too narrow  -- `**/` needs a directory segment,
    //                                         so it skips lib/main.dart
    //   client/lib/*.dart      too wide    -- `*` matches slashes, so this is
    //                                         recursive, not top-level
    //
    // The second was used to measure the damage done by the first, and produced
    // "24 files were invisible" when the true answer was one. Two wrong numbers
    // in opposite directions, from the same wrong assumption about one tool.
    // SANCTIONED EXCEPTIONS are excluded from the count rather than absorbed
    // into the baseline. A literal inside the number is invisible: nobody can
    // tell an allowed one from a debt, and the only way to add another is to
    // raise the ceiling -- which is the move this ratchet exists to prevent.
    //
    // Excluding them instead makes the escape hatch an ARGUMENT rather than an
    // arithmetic edit. To add a colour literal legitimately you add your file
    // here with a reason somebody can disagree with, in a diff that shows what
    // you are claiming. Raising `baseline` says nothing and cannot be refused.
    const sanctioned = <String, String>{
      // Black ink and white paper are the pixels of a printed document. Theme
      // colours would rasterise a signature as near-white in dark mode, and it
      // would be invisible on paper. The reason lives at the constant too.
      'screens/inspection/signature_field.dart':
          'a signature is printed, so its colours are ink and paper, not theme',

      // The pre-login surface. auth_shell paints a fixed dark gradient
      // (#071018 -> #0A2233 -> #06384E) and everything on it is white. It is
      // deliberately OUTSIDE the theme: it is what you see before you are in
      // the app, and it looks the same in light and dark by design.
      //
      // Converting these would be an active regression, not a cleanup —
      // `Colors.white` here means "on my own dark background", so mapping it to
      // `onSurface` renders the login screen dark-on-dark in light mode.
      'widgets/auth_shell.dart':
          'a fixed dark pre-login surface; white here means on-my-own-background',
      'widgets/auth_preview.dart':
          'drawn on auth_shell dark gradient, same fixed surface',

      // A printed document and the desk it sits on. The file already documents
      // this itself — "Ink, and the paper it sits on. DocumentRenderer's
      // stylesheet, in Dart" — and says why they do not follow the theme.
      'screens/hr/contract_document_page.dart':
          'renders paper and ink for print; already reasoned about at the constants',

      // A picture of somebody else's screen: the SEO Helper draws Google's
      // own search result so a title can be judged as it will be seen. In the
      // theme's colours it would look like one of ours.
      'screens/web/seo_helper_pane.dart':
          "draws Google's search result, so its colours are Google's",

      // A colour picker for a CUSTOMER'S website (Jose, 2026-10-01): its
      // twelve swatches are colours on offer for somebody's site, and they
      // must stay the same in light and dark. In the theme's colours the
      // palette would change with EzBizi's look, which is not what is picked.
      'widgets/colour_field.dart':
          "a colour picker's swatches are the website's colours on offer, not EzBizi's theme",

      // A CATEGORICAL PALETTE IS NOT A BRAND COLOUR, and this ratchet was
      // pointed at the wrong end of the problem here.
      //
      // The chart drew series from `primary, secondary, tertiary` and their
      // containers — scheme roles, so it passed this gate cleanly while being
      // the one file in the tree rendering the banished violet. `theme.dart`
      // pins primary and secondary to the same deep cyan on light on purpose,
      // which is right for every widget that wants "something brand-ish" and
      // wrong for the one place that needs six things to look DIFFERENT:
      // Scheduled and In progress drew identically on every light jobs pie.
      //
      // So a scheme role passes every check a hex literal fails, and a colour
      // the app never chose can enter through a name the app did choose. That
      // is the finding worth carrying past this entry.
      //
      // These sixteen are a validated set, not choices: eight fixed hues per
      // mode, passing the lightness, chroma, CVD-separation, normal-vision and
      // contrast checks against this app's real surfaces in both modes. They
      // cannot come from the theme, because a theme may legitimately give two
      // roles the same colour and a series palette may not. Changing one value
      // means re-running the validator for both modes — a palette is a set,
      // not sixteen independent choices.
      'widgets/dashboard_chart.dart':
          'a validated categorical palette; series identity is not brand identity',
    };

    // MEASURED AFTER EXCLUDING THE ABOVE, and the drop is the finding rather
    // than an adjustment. The count was never that much DEBT: roughly half of it
    // was three surfaces that are deliberately outside the theme and say so in
    // their own code. A ratchet that counts documented, correct decisions as
    // debt reports a number nobody can act on, and manufactures pressure to
    // "clean up" things whose conversion would be a regression.
    //
    // What remains is the honest figure, and it is small enough to finish.
    const baseline = 31;

    test('no new hardcoded colours outside theme.dart', () {
      // `Colors.transparent` is excluded, and it is the one exception worth
      // making: it is the ABSENCE of paint, not a colour anybody chose, so
      // "read it from the theme instead" is advice with no answer. Counting it
      // sent a merge red over six `Colors.transparent` in a timeline
      // widget — a false positive that costs a real gate its credibility, which
      // is how gates end up being raised past instead of fixed.
      final pattern = RegExp(r'Color\(0x|Colors\.(?!transparent\b)[a-z]');
      var found = 0;
      var scanned = 0;
      final worst = <String, int>{};

      for (final file in Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        scanned++;
        final rel = file.path.replaceAll(r'\', '/');
        if (rel.endsWith('core/theme.dart')) continue;
        if (sanctioned.keys.any(rel.endsWith)) continue;
        final hits = pattern.allMatches(file.readAsStringSync()).length;
        if (hits > 0) {
          found += hits;
          worst[file.path] = hits;
        }
      }

      final ranked = worst.entries.toList()
        ..sort((a, b) => b.value.compareTo(a.value));

      // A RATCHET THAT ONLY CHECKS AN UPPER BOUND PASSES WHEN ITS INSTRUMENT
      // BREAKS. Break the regex, point `Directory` at the wrong cwd, or rename
      // lib/, and `found` is 0 — comfortably under the baseline, green forever,
      // and nobody is told the gate stopped looking.
      //
      // This is the failure the deploy desk hit an hour after this file was
      // written: a grep with wrong escaping matched nothing, and "no match" was
      // read as "no problem". It was caught only because the answer was too
      // convenient. A gate cannot rely on someone finding its output suspicious.
      //
      // So the floors are asserted first and separately from the ceiling. They
      // are not the rule — they are the proof that the rule was measured.
      expect(scanned, greaterThan(100),
          reason: 'Only $scanned .dart files scanned under lib/. The scan is '
              'broken or the working directory is wrong — this test says '
              'nothing about colour literals until that is fixed.');
      expect(found, greaterThan(0),
          reason: 'Zero colour literals matched anywhere outside theme.dart. '
              'That has never been true; the pattern is broken. A ratchet that '
              'matches nothing passes silently, which is worse than a red.');

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


/// One Material text slot by name, or null when the name is not a slot.
TextStyle? _slot(TextTheme t, String name) => switch (name) {
  'displayLarge' => t.displayLarge,
  'displayMedium' => t.displayMedium,
  'displaySmall' => t.displaySmall,
  'headlineLarge' => t.headlineLarge,
  'headlineMedium' => t.headlineMedium,
  'headlineSmall' => t.headlineSmall,
  'titleLarge' => t.titleLarge,
  'titleMedium' => t.titleMedium,
  'titleSmall' => t.titleSmall,
  // The body and label slots were missing from this switch, and THAT is why an
  // unfilled `bodySmall` survived three earlier rounds of this same fault: any
  // assertion written above resolved it to null and skipped it silently. The
  // guard was healthy and pointed one level away from the subject.
  'bodyLarge' => t.bodyLarge,
  'bodyMedium' => t.bodyMedium,
  'bodySmall' => t.bodySmall,
  'labelLarge' => t.labelLarge,
  'labelMedium' => t.labelMedium,
  'labelSmall' => t.labelSmall,
  _ => null,
};


/// The argument text of a call whose opening paren is at [open], or null when
/// the parens do not balance inside this file.
String? _argsOf(String source, int open) {
  var depth = 0;
  for (var i = open; i < source.length; i++) {
    final ch = source[i];
    if (ch == '(') depth++;
    if (ch == ')') {
      depth--;
      if (depth == 0) return source.substring(open + 1, i);
    }
  }
  return null;
}
