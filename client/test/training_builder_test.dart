import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/guide_builder.dart';
import 'package:ezbizi_client/screens/training/test_builder.dart';
import 'package:ezbizi_client/screens/training_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings — layout 8 of `docs/previews/training-layouts.html` (Jose's
/// approved pick, by number): a storyboard, the module's one novel pattern.
/// [GuideBuilder] draws the strip and the screen editor; [TestBuilder] draws
/// the four options Jose named and the question editor, standalone or
/// embedded beside a guide's own strip.
void main() {
  // ── The four options, editable ──────────────────────────────────────

  testWidgets('the four test options are all editable in the builder', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.text('Pass mark'), findsOneWidget);
    expect(find.text('Retakes allowed'), findsOneWidget);
    expect(find.text('A pass runs out after'), findsOneWidget);

    // Each is a real, live control — not just a label — bound to the
    // field it edits.
    expect(find.byKey(const Key('training.option-requires-guide')), findsOneWidget);
    expect(find.byKey(const Key('training.option-pass-mark')), findsOneWidget);
    expect(find.byKey(const Key('training.option-max-attempts')), findsOneWidget);
    expect(find.byKey(const Key('training.option-valid-for-days')), findsOneWidget);
  });

  testWidgets('editing all four options and saving sends exactly what was set', (tester) async {
    Map<String, dynamic>? sentBody;

    await _pumpTestBuilder(
      tester,
      test: _test(id: 't1', title: 'Ladder Safety Quiz', passMark: 80),
      onRequest: (request) {
        if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
          sentBody = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _test(id: 't1', title: 'Ladder Safety Quiz')});
        }
        return null;
      },
    );

    await tester.tap(find.byKey(const Key('training.option-requires-guide')));
    await tester.enterText(find.byKey(const Key('training.option-pass-mark')), '70');
    await tester.enterText(find.byKey(const Key('training.option-max-attempts')), '3');
    await tester.enterText(find.byKey(const Key('training.option-valid-for-days')), '365');
    await tester.tap(find.byKey(const Key('training.save-test')));
    await tester.pumpAndSettle();

    expect(sentBody, isNotNull);
    expect(sentBody!['requires_guide'], isTrue);
    expect(sentBody!['pass_mark'], 70);
    expect(sentBody!['max_attempts'], 3);
    expect(sentBody!['valid_for_days'], 365);
  });

  // ── No modals, anywhere ───────────────────────────────────────────────

  testWidgets('adding a screen adds a card to the strip and never opens a dialog', (tester) async {
    await _pumpGuideBuilder(tester, guide: _guide(id: 'g1', title: 'Ladder Safety'));

    expect(find.byKey(const ValueKey('training.screen-card-0')), findsNothing);

    await tester.tap(find.text('Add screen'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byKey(const ValueKey('training.screen-card-0')), findsOneWidget);
  });

  testWidgets('adding a question never opens a dialog either', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    await tester.tap(find.byKey(const Key('training.add-question')));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const ValueKey('training.question-0')), findsOneWidget);
  });

  // ── The server's own sentence, shown verbatim ─────────────────────────

  testWidgets(
    'the server refusal sentence is shown verbatim when a question has no right answer',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(
          id: 't1',
          title: 'Ladder Safety Quiz',
          questions: [
            {
              'prompt': 'How far out does the base go?',
              'choices': [
                {'text': 'Two feet', 'correct': false},
                {'text': 'Four feet', 'correct': false},
              ],
            },
          ],
        ),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            return _json({
              'message': 'The given data was invalid.',
              'errors': {
                'questions': [
                  'Every question needs exactly one right answer. Question 1 has none.',
                ],
              },
            }, 422);
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      // The server's own sentence (`TestsController::assertExactlyOneCorrectPerQuestion`),
      // shown character for character — never a client-invented paraphrase.
      expect(
        find.text('Every question needs exactly one right answer. Question 1 has none.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'the server refusal sentence is shown verbatim when the guide gate has no target',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(id: 't1', title: 'Ladder Safety Quiz'),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            return _json({
              'message': 'The given data was invalid.',
              'errors': {
                'requires_guide': [
                  'This test requires a guide, but none is attached. Pick a guide, '
                      'or turn off requiring one.',
                ],
              },
            }, 422);
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const Key('training.option-requires-guide')));
      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      // `TestsController::assertGuideGateHasATarget`'s exact sentence.
      expect(
        find.text(
          'This test requires a guide, but none is attached. Pick a guide, or turn off '
          'requiring one.',
        ),
        findsOneWidget,
      );
    },
  );

  // ── 375px: the strip scrolls inside itself; the page never scrolls sideways ──

  testWidgets(
    'at 375px the strip scrolls inside its own container while the page never scrolls sideways',
    (tester) async {
      await _pumpGuideBuilder(
        tester,
        guide: _guide(
          id: 'g1',
          title: 'Ladder Safety',
          screens: [
            _screen('Why this guide exists'),
            _screen('Checking a ladder', kind: 'image', url: 'https://imgur.com/a'),
            _screen('The four-to-one rule', kind: 'video', url: 'https://youtube.com/watch?v=1'),
            _screen('Tying off at the top'),
            _screen('Ground conditions', kind: 'image', url: 'https://imgur.com/b'),
          ],
        ),
        size: const Size(375, 900),
      );

      // No overflow anywhere on the page at 375px.
      expect(tester.takeException(), isNull);

      // Exactly one horizontally-scrolling thing on the whole screen: the
      // strip. The screen editor and "Its test" column stack vertically
      // instead (the brief's own "at 375px … the editors stack").
      final horizontal = find.byWidgetPredicate(
        (w) => w is ScrollView && w.scrollDirection == Axis.horizontal,
      );
      expect(horizontal, findsOneWidget);
      expect(tester.widget(horizontal), isA<ListView>());

      // And it is the strip specifically.
      final stripBox = tester.widget<SizedBox>(find.byKey(const Key('training.screen-strip')));
      expect(stripBox.child, isA<ListView>());
    },
  );

  // RED/GREEN, recorded manually in the task-15 report: swapping the
  // strip's `ListView(scrollDirection: Axis.horizontal)` for a bare `Row`
  // (guide_builder.dart, `_ScreenStrip.build`) turns the assertion above
  // red with a `RenderFlex overflowed` exception, because a `Row` never
  // scrolls and never clips its children — restoring the `ListView` turns
  // it green again. Not re-asserted here as a permanent test (there is
  // nothing else to assert against a temporary edit); see the report for
  // what was observed.

  // ── The permission gate ────────────────────────────────────────────────

  testWidgets('a viewer without training.manage does not reach the Settings tab', (tester) async {
    await _pumpScreen(tester, permissions: const ['training.view']);

    expect(find.text('Learn'), findsOneWidget);
    expect(find.text('Settings'), findsNothing);
  });

  testWidgets('a viewer with training.manage reaches Settings, and it is not a placeholder', (
    tester,
  ) async {
    await _pumpScreen(tester, permissions: const ['training.view', 'training.manage']);

    expect(find.text('Settings'), findsOneWidget);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    expect(find.text('TODO'), findsNothing);
    expect(find.textContaining('Coming next'), findsNothing);
    expect(find.text('Guides'), findsOneWidget);
    expect(find.text('Tests'), findsOneWidget);
  });

  // ── Through the real route, never constructed directly ────────────────
  //
  // Task 13's postmortem: a test that constructs the destination widget
  // straight inside its own borrowed `Scaffold` can pass while the app's
  // REAL route to that widget is broken, because the harness's own wrapper
  // supplies whatever ancestor (`Material`, in that case) the real route
  // forgot. These two reach both builders the way `TrainingSettingsTab`
  // itself does — a tap, then a real `Navigator.push` — so a future regression
  // in that wiring shows up here rather than only in the field.

  testWidgets('tapping a guide in Settings opens its real builder, through the real route', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [_guide(id: 'g1', title: 'Ladder Safety', screens: [_screen('Intro')])],
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Ladder Safety'));
    await tester.pumpAndSettle();

    // Reached GuideBuilder for real — its screen editor is on screen, and
    // nothing about the trip there was a dialog.
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('tapping a test in Settings opens its real builder, through the real route', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      tests: [_test(id: 't1', title: 'Carbon Monoxide Awareness')],
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Carbon Monoxide Awareness'));
    await tester.pumpAndSettle();

    // Reached TestBuilder for real — the four options are on screen.
    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.text('Pass mark'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  // ── Editors keyed by a stable id, never by list position (review fix) ──
  //
  // `TextFormField.initialValue` seeds a field's internal editing state only
  // ONCE — a later rebuild at the same list INDEX with different data does
  // not reset it, because the field's own `Key` did not change. Deleting a
  // MIDDLE item shifts every later item's index, so before this fix the
  // field that should now show the next item kept showing the deleted
  // item's own stale text — and a save at that point overwrote the next
  // item's real data with whatever the author typed while looking at the
  // wrong field. All three sites below reproduce that with three
  // distinguishably-named items and delete the MIDDLE one.

  testWidgets(
    'deleting a middle question leaves each remaining editor showing its OWN prompt, never a stale one',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(
          id: 't1',
          title: 'Ladder Safety Quiz',
          questions: [
            {
              'prompt': 'QUESTION-ALPHA',
              'choices': [
                {'text': 'A1', 'correct': true},
                {'text': 'A2', 'correct': false},
              ],
            },
            {
              'prompt': 'QUESTION-BRAVO',
              'choices': [
                {'text': 'B1', 'correct': true},
                {'text': 'B2', 'correct': false},
              ],
            },
            {
              'prompt': 'QUESTION-CHARLIE',
              'choices': [
                {'text': 'C1', 'correct': true},
                {'text': 'C2', 'correct': false},
              ],
            },
          ],
        ),
      );

      expect(find.text('QUESTION-ALPHA'), findsOneWidget);
      expect(find.text('QUESTION-BRAVO'), findsOneWidget);
      expect(find.text('QUESTION-CHARLIE'), findsOneWidget);

      // Remove the MIDDLE question (BRAVO) — CHARLIE slides into its slot.
      await tester.tap(find.byKey(const ValueKey('training.remove-question-1')));
      await tester.pumpAndSettle();

      // BRAVO's text must be gone entirely, and CHARLIE's own prompt — not
      // BRAVO's stale one — must be what the second remaining editor shows.
      expect(find.text('QUESTION-BRAVO'), findsNothing);
      expect(find.text('QUESTION-ALPHA'), findsOneWidget);
      expect(find.text('QUESTION-CHARLIE'), findsOneWidget);
    },
  );

  testWidgets(
    'editing the field that now shows CHARLIE and saving sends CHARLIE\'s edit — never overwrites it with BRAVO\'s',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpTestBuilder(
        tester,
        test: _test(
          id: 't1',
          title: 'Ladder Safety Quiz',
          questions: [
            {
              'prompt': 'QUESTION-ALPHA',
              'choices': [
                {'text': 'A1', 'correct': true},
                {'text': 'A2', 'correct': false},
              ],
            },
            {
              'prompt': 'QUESTION-BRAVO',
              'choices': [
                {'text': 'B1', 'correct': true},
                {'text': 'B2', 'correct': false},
              ],
            },
            {
              'prompt': 'QUESTION-CHARLIE',
              'choices': [
                {'text': 'C1', 'correct': true},
                {'text': 'C2', 'correct': false},
              ],
            },
          ],
        ),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _test(id: 't1', title: 'Ladder Safety Quiz')});
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const ValueKey('training.remove-question-1')));
      await tester.pumpAndSettle();

      // The author, believing they are fixing what is now on screen,
      // appends to the field that should be CHARLIE's.
      await tester.enterText(find.text('QUESTION-CHARLIE'), 'QUESTION-CHARLIE fixed');
      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      expect(sentBody, isNotNull);
      final questions = sentBody!['questions'] as List;
      expect(questions.length, 2);
      expect((questions[0] as Map)['prompt'], 'QUESTION-ALPHA');
      // The stable-id fix: this is CHARLIE's own prompt, edited — never
      // BRAVO's stale text with the same edit tacked on.
      expect((questions[1] as Map)['prompt'], 'QUESTION-CHARLIE fixed');
    },
  );

  testWidgets(
    'deleting a middle CHOICE leaves each remaining choice showing its OWN text, never a stale one',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(
          id: 't1',
          title: 'Ladder Safety Quiz',
          questions: [
            {
              'prompt': 'How far out does the base go?',
              'choices': [
                {'text': 'CHOICE-ALPHA', 'correct': true},
                {'text': 'CHOICE-BRAVO', 'correct': false},
                {'text': 'CHOICE-CHARLIE', 'correct': false},
              ],
            },
          ],
        ),
      );

      expect(find.text('CHOICE-ALPHA'), findsOneWidget);
      expect(find.text('CHOICE-BRAVO'), findsOneWidget);
      expect(find.text('CHOICE-CHARLIE'), findsOneWidget);

      // Remove the MIDDLE choice (BRAVO) — CHARLIE slides into its slot.
      await tester.tap(find.byKey(const ValueKey('training.remove-choice-0-1')));
      await tester.pumpAndSettle();

      expect(find.text('CHOICE-BRAVO'), findsNothing);
      expect(find.text('CHOICE-ALPHA'), findsOneWidget);
      expect(find.text('CHOICE-CHARLIE'), findsOneWidget);
    },
  );

  testWidgets(
    'deleting the SELECTED middle screen keeps the editor showing the screen that slid into its slot, never the deleted one\'s stale body',
    (tester) async {
      await _pumpGuideBuilder(
        tester,
        guide: _guide(
          id: 'g1',
          title: 'Ladder Safety',
          screens: [
            _screen('SCREEN-ALPHA'),
            _screen('SCREEN-BRAVO'),
            _screen('SCREEN-CHARLIE'),
          ],
        ),
      );

      // Select the middle screen (BRAVO) — its own body shows in the editor.
      await tester.tap(find.byKey(const ValueKey('training.screen-card-1')));
      await tester.pumpAndSettle();
      expect(find.text('Body for SCREEN-BRAVO.'), findsOneWidget);

      // Delete it from the screen editor's own delete button. `_removeScreen`
      // deliberately leaves `_selected` at index 1 — CHARLIE slides into
      // that slot, and the editor must show ITS body, never BRAVO's.
      await tester.tap(find.byTooltip('Delete screen'));
      await tester.pumpAndSettle();

      expect(find.text('Body for SCREEN-BRAVO.'), findsNothing);
      expect(find.text('Body for SCREEN-CHARLIE.'), findsOneWidget);
    },
  );

  // ── A parse failure must never pass for a legitimate blank (review fix) ──
  //
  // Blank is a real, meaningful value for all three of these fields
  // (unlimited retakes, a pass that never expires, "keep the stored pass
  // mark") — so `int.tryParse` returning null for a typo must never be
  // read as that same blank case. The fix keeps the ambiguity from ever
  // existing: a digits-only `inputFormatter` drops a non-digit the instant
  // it is typed, so the box can never hold anything `_parseInt` would need
  // to guess about.

  testWidgets('a letter typed into Pass mark never reaches the box — only digits do', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz', passMark: 80));

    await tester.enterText(find.byKey(const Key('training.option-pass-mark')), 'ab7c');
    await tester.pump();

    expect(find.text('ab7c'), findsNothing);
    expect(find.text('7'), findsOneWidget);
  });

  testWidgets('a letter typed into Retakes allowed never reaches the box either', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    await tester.enterText(find.byKey(const Key('training.option-max-attempts')), '3o');
    await tester.pump();

    expect(find.text('3o'), findsNothing);
    expect(find.text('3'), findsOneWidget);
  });

  testWidgets('a letter typed into "A pass runs out after" never reaches the box either', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    await tester.enterText(find.byKey(const Key('training.option-valid-for-days')), '36x5');
    await tester.pump();

    expect(find.text('36x5'), findsNothing);
    expect(find.text('365'), findsOneWidget);
  });

  testWidgets(
    'typing "3o" into Retakes allowed and saving sends 3 — never silently unlimited',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpTestBuilder(
        tester,
        test: _test(id: 't1', title: 'Ladder Safety Quiz'),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _test(id: 't1', title: 'Ladder Safety Quiz')});
          }
          return null;
        },
      );

      await tester.enterText(find.byKey(const Key('training.option-max-attempts')), '3o');
      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      expect(sentBody, isNotNull);
      expect(sentBody!['max_attempts'], 3);
    },
  );

  // ── The only creation path in the module (review finding) ─────────────

  testWidgets('tapping "New guide" creates one and opens its real builder', (tester) async {
    var created = false;

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      onRequest: (request) {
        if (request.method == 'POST' && request.url.path.endsWith('/training/guides')) {
          created = true;
          return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide')});
        }
        if (request.method == 'GET' && request.url.path.endsWith('/training/guides/new-g1')) {
          return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide')});
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New guide'));
    await tester.pumpAndSettle();

    expect(created, isTrue);
    // Reached GuideBuilder for real, through the same push Settings' own
    // rows use — never a dialog.
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('tapping "New test" creates one and opens its real builder', (tester) async {
    var created = false;

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      onRequest: (request) {
        if (request.method == 'POST' && request.url.path.endsWith('/training/tests')) {
          created = true;
          return _json({'data': _test(id: 'new-t1', title: 'Untitled test')});
        }
        if (request.method == 'GET' && request.url.path.endsWith('/training/tests/new-t1')) {
          return _json({'data': _test(id: 'new-t1', title: 'Untitled test')});
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New test'));
    await tester.pumpAndSettle();

    expect(created, isTrue);
    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  // ── Reorder: move-left/move-right, not drag (ruled finding) ────────────

  testWidgets(
    'the move-right control swaps a screen with its neighbour, and a save persists the new order',
    (tester) async {
      List<dynamic>? sentScreens;

      await _pumpGuideBuilder(
        tester,
        guide: _guide(
          id: 'g1',
          title: 'Ladder Safety',
          screens: [_screen('SCREEN-ALPHA'), _screen('SCREEN-BRAVO'), _screen('SCREEN-CHARLIE')],
        ),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/guides/g1')) {
            sentScreens = (jsonDecode(request.body) as Map)['screens'] as List;
            return _json({'data': _guide(id: 'g1', title: 'Ladder Safety')});
          }
          return null;
        },
      );

      expect(find.textContaining('1 · SCREEN-ALPHA'), findsOneWidget);
      expect(find.textContaining('2 · SCREEN-BRAVO'), findsOneWidget);

      // Move ALPHA (card 0) one step later — it swaps with BRAVO.
      await tester.tap(find.byKey(const ValueKey('training.screen-move-right-0')));
      await tester.pumpAndSettle();

      expect(find.textContaining('1 · SCREEN-BRAVO'), findsOneWidget);
      expect(find.textContaining('2 · SCREEN-ALPHA'), findsOneWidget);
      expect(find.textContaining('3 · SCREEN-CHARLIE'), findsOneWidget);

      await tester.tap(find.byKey(const Key('training.save-guide')));
      await tester.pumpAndSettle();

      expect(sentScreens, isNotNull);
      expect((sentScreens![0] as Map)['title'], 'SCREEN-BRAVO');
      expect((sentScreens![1] as Map)['title'], 'SCREEN-ALPHA');
      expect((sentScreens![2] as Map)['title'], 'SCREEN-CHARLIE');
    },
  );

  testWidgets('move-left is disabled on the first card, move-right on the last', (tester) async {
    await _pumpGuideBuilder(
      tester,
      guide: _guide(
        id: 'g1',
        title: 'Ladder Safety',
        screens: [_screen('SCREEN-ALPHA'), _screen('SCREEN-BRAVO')],
      ),
    );

    final firstLeft = tester.widget<IconButton>(
      find.descendant(
        of: find.byKey(const ValueKey('training.screen-move-left-0')),
        matching: find.byType(IconButton),
      ),
    );
    expect(firstLeft.onPressed, isNull);

    final lastRight = tester.widget<IconButton>(
      find.descendant(
        of: find.byKey(const ValueKey('training.screen-move-right-1')),
        matching: find.byType(IconButton),
      ),
    );
    expect(lastRight.onPressed, isNull);
  });

  // ── Minors ───────────────────────────────────────────────────────────

  testWidgets('the screens shelf carries the guide\'s own scope tag', (tester) async {
    await _pumpGuideBuilder(tester, guide: _guide(id: 'g1', title: 'Ladder Safety'));

    expect(find.text('Organization'), findsOneWidget);
  });

  testWidgets(
    'when the 200-test scan cannot find this guide\'s test, it says so honestly rather than claiming none exists',
    (tester) async {
      await _pumpGuideBuilder(
        tester,
        guide: _guide(id: 'g1', title: 'Ladder Safety'),
        onRequest: (request) {
          if (request.method == 'GET' && request.url.path.endsWith('/training/tests')) {
            return _json({
              'data': [_test(id: 't-other', title: 'Some other test')],
              'meta': {'total': 250},
            });
          }
          return null;
        },
      );

      expect(find.textContaining('Could not confirm'), findsOneWidget);
      expect(
        find.text(
          'No test is attached to this guide yet. Attach one from the Tests '
          'list by setting its guide there.',
        ),
        findsNothing,
      );
    },
  );
}

// ── Fixtures ────────────────────────────────────────────────────────────

Map<String, dynamic> _screen(String title, {String kind = 'none', String? url}) => {
  'title': title,
  'body': 'Body for $title.',
  'media_kind': kind,
  'media_url': url,
};

/// `GuidesController::present`'s own shape.
Map<String, dynamic> _guide({
  required String id,
  required String title,
  String status = 'draft',
  List<Map<String, dynamic>> screens = const [],
  String? companyId,
  String? franchiseId,
}) => {
  'id': id,
  'title': title,
  'summary': null,
  'status': status,
  'company_id': companyId,
  'franchise_id': franchiseId,
  'screen_count': screens.length,
  'screens': screens,
};

/// `TestsController::present`'s own shape.
Map<String, dynamic> _test({
  required String id,
  required String title,
  String status = 'draft',
  String? guideId,
  bool requiresGuide = false,
  int passMark = 80,
  int? maxAttempts,
  int? validForDays,
  List<Map<String, dynamic>> questions = const [],
  String? companyId,
  String? franchiseId,
}) => {
  'id': id,
  'title': title,
  'status': status,
  'company_id': companyId,
  'franchise_id': franchiseId,
  'guide_id': guideId,
  'requires_guide': requiresGuide,
  'pass_mark': passMark,
  'max_attempts': maxAttempts,
  'valid_for_days': validForDays,
  'question_count': questions.length,
  'questions': questions,
};

Future<void> _pumpTestBuilder(
  WidgetTester tester, {
  required Map<String, dynamic> test,
  http.Response? Function(http.Request request)? onRequest,
}) async {
  // Tall enough that every option and the Save button sit within the test
  // surface without needing a scroll gesture first — the panel's body is a
  // `SingleChildScrollView` (test_builder.dart), and the default 800×600
  // test window clips well above the questions/options/Save on a test with
  // even one question.
  tester.view.physicalSize = const Size(900, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;
      if (path.endsWith('/training/tests/${test['id']}')) return _json({'data': test});
      if (path.endsWith('/training/guides')) {
        return _json({
          'data': const [],
          'meta': {'total': 0},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TestBuilder(api: api, session: session, testId: test['id'] as String),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpGuideBuilder(
  WidgetTester tester, {
  required Map<String, dynamic> guide,
  Size size = const Size(1280, 1400),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;
      if (path.endsWith('/training/guides/${guide['id']}')) return _json({'data': guide});
      if (path.endsWith('/training/tests')) {
        return _json({
          'data': const [],
          'meta': {'total': 0},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: GuideBuilder(api: api, session: session, guideId: guide['id'] as String),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>> guides = const [],
  List<Map<String, dynamic>> tests = const [],
  http.Response? Function(http.Request request)? onRequest,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path.endsWith('/training/learn')) {
        return _json({'assigned': const [], 'library': const []});
      }
      if (path.endsWith('/training/guides') && request.method == 'GET') {
        return _json({
          'data': guides,
          'meta': {'total': guides.length},
        });
      }
      if (path.endsWith('/training/tests') && request.method == 'GET') {
        return _json({
          'data': tests,
          'meta': {'total': tests.length},
        });
      }
      for (final guide in guides) {
        if (path.endsWith('/training/guides/${guide['id']}')) return _json({'data': guide});
      }
      for (final test in tests) {
        if (path.endsWith('/training/tests/${test['id']}')) return _json({'data': test});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TrainingScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'training',
      'label': 'Training',
      'available': true,
      'reachable': true,
    },
  ],
};
