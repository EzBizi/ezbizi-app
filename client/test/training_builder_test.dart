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
/// the five options (the original four, plus `count_attempts_after_pass`
/// from the fifth-option brief, 2026-09-09) and the question editor,
/// standalone or embedded beside a guide's own strip.
void main() {
  // ── The five options, editable ────────────────────────────────────────

  testWidgets('the five test options are all editable in the builder', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.text('Pass mark'), findsOneWidget);
    expect(find.text('Retakes allowed'), findsOneWidget);
    expect(find.text('A pass runs out after'), findsOneWidget);
    expect(find.text('Count every retake, even a pass'), findsOneWidget);

    // Each is a real, live control — not just a label — bound to the
    // field it edits.
    expect(find.byKey(const Key('training.option-requires-guide')), findsOneWidget);
    expect(find.byKey(const Key('training.option-pass-mark')), findsOneWidget);
    expect(find.byKey(const Key('training.option-max-attempts')), findsOneWidget);
    expect(find.byKey(const Key('training.option-valid-for-days')), findsOneWidget);
    expect(find.byKey(const Key('training.option-count-attempts-after-pass')), findsOneWidget);
  });

  testWidgets('editing all five options and saving sends exactly what was set', (tester) async {
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
    await tester.tap(find.byKey(const Key('training.option-count-attempts-after-pass')));
    await tester.tap(find.byKey(const Key('training.save-test')));
    await tester.pumpAndSettle();

    expect(sentBody, isNotNull);
    expect(sentBody!['requires_guide'], isTrue);
    expect(sentBody!['pass_mark'], 70);
    expect(sentBody!['max_attempts'], 3);
    expect(sentBody!['valid_for_days'], 365);
    expect(sentBody!['count_attempts_after_pass'], isTrue);
  });

  // Two tests, not one with two pumps: a second pumpWidget in the same test
  // reuses the TestBuilder element, so initState never runs again and the
  // switch keeps the first case's value. Measured — written as one test, the
  // stored-false case read `true` and the assertion was about state left over
  // from the case above it.
  //
  // Neither is a test of "what a NEW test defaults to". That default is the
  // server's (TestsController::store, ON since 2026-09-09) and this screen
  // never sees it: creating a test POSTs first and then opens the builder BY
  // ID, so the payload always carries the field.

  testWidgets('the retake switch is on when the test stores it on', (tester) async {
    await _pumpTestBuilder(
      tester,
      test: _test(id: 't1', title: 'Ladder Safety Quiz', countAttemptsAfterPass: true),
    );

    expect(
      tester
          .widget<Switch>(find.byKey(const Key('training.option-count-attempts-after-pass')))
          .value,
      isTrue,
    );
  });

  testWidgets('the retake switch stays off when the test stores it off', (tester) async {
    // The control, and the hazard it guards: the save path sends this switch
    // on every save, so a stored `false` read as "unset" would silently flip
    // an author's own choice the first time somebody opened the test.
    await _pumpTestBuilder(
      tester,
      test: _test(id: 't1', title: 'Ladder Safety Quiz', countAttemptsAfterPass: false),
    );

    expect(
      tester
          .widget<Switch>(find.byKey(const Key('training.option-count-attempts-after-pass')))
          .value,
      isFalse,
    );
  });

  testWidgets('the one lockout combination warns the author, and only that combination', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    final warning = find.byKey(const Key('training.option-lockout-warning'));

    // Controls first, so a warning that never renders at all cannot pass this
    // test: a cap and an expiry with the switch OFF is the DEFAULT shape of a
    // recurring certification, and it has no dead end - the cap then counts
    // failures since the learner's last pass.
    await tester.enterText(find.byKey(const Key('training.option-max-attempts')), '1');
    await tester.enterText(find.byKey(const Key('training.option-valid-for-days')), '90');
    await tester.pump();
    expect(warning, findsNothing);

    // All three: the pass itself spends the only slot, it runs out after 90
    // days, and no route deletes an attempt - so this learner is finished.
    await tester.tap(find.byKey(const Key('training.option-count-attempts-after-pass')));
    await tester.pump();
    expect(warning, findsOneWidget);

    // Counted retakes and a cap with NO expiry is not the dead end: the pass
    // never runs out, so the learner never needs a slot they no longer have.
    await tester.enterText(find.byKey(const Key('training.option-valid-for-days')), '');
    await tester.pump();
    expect(warning, findsNothing);
  });

  // ── The "Which guide" picker at 375px, with a real guide in it ────────
  //
  // Review finding: `_pumpTestBuilder` used to hardcode a 900-wide surface
  // AND an always-empty guides list, which is two independent reasons a
  // narrow-width overflow in the picker could never show up here — 900px
  // has room to spare regardless, and an empty list means the dropdown's
  // widest item is "None". This test picks neither default: 375 wide (the
  // module's own narrow-layout floor, same as the strip test above) with
  // one real, long-titled guide in the picker.

  testWidgets(
    'at 375px, the "Which guide" picker does not overflow its row',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(id: 't1', title: 'Ladder Safety Quiz'),
        viewSize: const Size(375, 2200),
        guides: [
          _guide(id: 'g1', title: 'Confined Space Entry and Rescue Procedures Certification'),
        ],
      );

      expect(find.text('Which guide'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'the same long guide title does not regress the wide "Which guide" layout',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(id: 't1', title: 'Ladder Safety Quiz'),
        viewSize: const Size(900, 2200),
        guides: [
          _guide(id: 'g1', title: 'Confined Space Entry and Rescue Procedures Certification'),
        ],
      );

      expect(find.text('Which guide'), findsOneWidget);
      expect(tester.takeException(), isNull);

      // Still the wide, side-by-side layout — the picker is still pinned to
      // its 200px `trailingWidth`, not stretched to fill the row the way
      // the narrow layout above lets it.
      final dropdownBox = tester.widget<SizedBox>(
        find.ancestor(
          of: find.byKey(const Key('training.option-guide-id')),
          matching: find.byType(SizedBox),
        ).first,
      );
      expect(dropdownBox.width, 200);
    },
  );

  // ── No modals, anywhere ───────────────────────────────────────────────

  // -- Images a guide holds itself (TrainingMedia) --
  //
  // A1 Tools' images are copied into private storage, so a screen carries a
  // `media_id` and is read with a signed link that EXPIRES. The save used to
  // rebuild each screen from four fields, which would have dropped the
  // reference on the first save and stored the expiring link instead.

  Map<String, dynamic> heldImageGuide() => {
    ..._guide(id: 'g-held', title: 'Chimneys'),
    'screens': [
      {
        'title': 'Level 2',
        'blocks': [
          {
            'type': 'image',
            'media_id': 'm-1',
            'url': 'https://app.example/api/v1/training/media/m-1?expires=9&signature=abc',
          },
          {'type': 'text', 'text': 'Look up the flue.'},
          {'type': 'text', 'text': 'Then the crown.'},
        ],
      },
    ],
    'screen_count': 1,
  };

  Future<Map<String, dynamic>?> saveAndCapture(
    WidgetTester tester,
    Future<void> Function() between,
  ) async {
    Map<String, dynamic>? sent;

    await _pumpGuideBuilder(
      tester,
      guide: heldImageGuide(),
      onRequest: (request) {
        if (request.method == 'PATCH') {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': heldImageGuide()});
        }
        return null;
      },
    );

    await tester.tap(find.byKey(const ValueKey('training.screen-card-0')));
    await tester.pumpAndSettle();

    await between();

    await tester.tap(find.byKey(const Key('training.save-guide')));
    await tester.pumpAndSettle();

    return sent;
  }

  testWidgets('saving a guide keeps an image it holds, and never sends the expiring link as its own', (tester) async {
    final sent = await saveAndCapture(tester, () async {});

    final screen = (sent!['screens'] as List).single as Map<String, dynamic>;
    expect(
      (screen['blocks'] as List).first,
      {'type': 'image', 'media_id': 'm-1'},
      reason: 'The id keeps the picture; the signed link beside it expires and is never sent back.',
    );
  });

  testWidgets('a held image reads as stored with the guide, not as an editable link', (tester) async {
    await _pumpGuideBuilder(tester, guide: heldImageGuide());

    await tester.tap(find.byKey(const ValueKey('training.screen-card-0')));
    await tester.pumpAndSettle();

    expect(find.text('Stored with this guide'), findsOneWidget);
    // The expiring link is never put in a field an author could copy it from.
    expect(find.textContaining('signature='), findsNothing);
  });

  // A1 Tools shipped this bug: blocks without their own keys, so after a
  // move Flutter kept each text field in its old SLOT and the next keystroke
  // wrote into a different block. Each block editor travels with its block.
  testWidgets('moving a block keeps each editor on its own text, and the save sends the new order', (tester) async {
    final sent = await saveAndCapture(tester, () async {
      await tester.tap(find.byTooltip('Move down').at(1));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Look up the flue.'), 'Look up the flue, first.');
      await tester.pumpAndSettle();
    });

    final blocks = ((sent!['screens'] as List).single as Map<String, dynamic>)['blocks'] as List;
    expect([for (final b in blocks) (b as Map)['text']], [null, 'Then the crown.', 'Look up the flue, first.']);
  });

  testWidgets('removing the held image drops it from the save', (tester) async {
    final sent = await saveAndCapture(tester, () async {
      await tester.tap(find.byTooltip('Remove block').first);
      await tester.pumpAndSettle();
    });

    final blocks = ((sent!['screens'] as List).single as Map<String, dynamic>)['blocks'] as List;
    expect([for (final b in blocks) (b as Map)['type']], ['text', 'text']);
    expect(find.text('Stored with this guide'), findsNothing);
  });

  testWidgets('adding a screen adds a card to the strip and never opens a dialog', (tester) async {
    await _pumpGuideBuilder(tester, guide: _guide(id: 'g1', title: 'Ladder Safety'));

    expect(find.byKey(const ValueKey('training.screen-card-0')), findsNothing);

    await tester.tap(find.text('Add topic'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byKey(const ValueKey('training.screen-card-0')), findsOneWidget);
  });

  testWidgets('a draft test can be published from its editor', (tester) async {
    Map<String, dynamic>? sent;
    await _pumpTestBuilder(
      tester,
      test: _test(id: 't1', title: 'Ladder Safety Quiz'),
      onRequest: (request) {
        if (request.method == 'PATCH') {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _test(id: 't1', title: 'Ladder Safety Quiz', status: 'published')});
        }
        return null;
      },
    );

    expect(find.text('Draft — nobody sees it yet'), findsOneWidget);
    await tester.tap(find.byKey(const Key('training.publish-test')));
    await tester.pumpAndSettle();

    expect(sent, {'status': 'published'});
    expect(find.text('Published'), findsOneWidget);
    expect(find.text('Unpublish'), findsOneWidget);
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

  // ── Through the real interaction, never constructed directly ──────────
  //
  // Task 13's postmortem: a test that constructs the destination widget
  // straight inside its own borrowed `Scaffold` can pass while the app's
  // REAL path to that widget is broken, because the harness's own wrapper
  // supplies whatever ancestor (`Material`, in that case) the real path
  // forgot. These two reach both builders the way `TrainingSettingsTab`
  // itself does — a tap, then an in-body `setState` swap (`build()` returns
  // [GuideBuilder]/[TestBuilder] in place of the list; no `Navigator.push`
  // anywhere under `lib/screens/training/` any more) — so a future
  // regression in that wiring shows up here rather than only in the field.
  // The direct proof that no route is pushed lives in the
  // `navigator.canPop()` assertions below ("Bug 2: the builder stays inside
  // the app — no route, chrome intact"); these two exist to prove the other
  // half of the guarantee — that tapping a row is what reaches the builder,
  // never a direct construction of it.

  // -- Deleting a guide or a test (Jose, 2026-09-23: "no way to delete
  //    guides or tests or drafts") --

  testWidgets('deleting asks first, and sends nothing until it is confirmed', (tester) async {
    final sent = <String>[];

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [_guide(id: 'g-1', title: 'Ladder Safety')],
      onRequest: (request) {
        if (request.method == 'DELETE') sent.add(request.url.path);
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('training.settings-delete-g-1')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Delete this guide?'), findsOneWidget);
    expect(sent, isEmpty, reason: 'Asking is not doing.');

    // And backing out leaves the row alone.
    await tester.tap(find.byKey(const Key('training.settings-cancel-delete-g-1')));
    await tester.pumpAndSettle();

    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(sent, isEmpty);
  });

  testWidgets('confirming sends the delete for that guide', (tester) async {
    final sent = <String>[];

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [_guide(id: 'g-1', title: 'Ladder Safety')],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          sent.add(request.url.path);
          return http.Response('', 204);
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('training.settings-delete-g-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('training.settings-confirm-delete-g-1')));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single, endsWith('/training/guides/g-1'));
  });

  /// THE CASE THE INLINE CONFIRMATION EXISTS FOR.
  ///
  /// "New guide" creates "Untitled guide" every time, so the screen that
  /// prompted this had three rows with identical titles. A confirmation that
  /// named the title could not say WHICH row it meant, and a reader picking
  /// the second would have no way to tell they had deleted the first.
  testWidgets('with three identically titled drafts, the second row deletes the second', (tester) async {
    final sent = <String>[];

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [
        _guide(id: 'g-1', title: 'Untitled guide'),
        _guide(id: 'g-2', title: 'Untitled guide'),
        _guide(id: 'g-3', title: 'Untitled guide'),
      ],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          sent.add(request.url.path);
          return http.Response('', 204);
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    // All three are drawn, and the titles cannot tell them apart.
    expect(find.text('Untitled guide'), findsNWidgets(3));

    await tester.tap(find.byKey(const Key('training.settings-delete-g-2')));
    await tester.pumpAndSettle();

    // Only the row that was asked about is asking — the other two are intact.
    expect(find.textContaining('Delete this guide?'), findsOneWidget);
    expect(find.text('Untitled guide'), findsNWidgets(2));

    await tester.tap(find.byKey(const Key('training.settings-confirm-delete-g-2')));
    await tester.pumpAndSettle();

    expect(sent.single, endsWith('/training/guides/g-2'));
  });

  testWidgets('a test row deletes the test, not the guide endpoint', (tester) async {
    final sent = <String>[];

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      tests: [_test(id: 't-1', title: 'Ladder Safety Quiz')],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          sent.add(request.url.path);
          return http.Response('', 204);
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('training.settings-delete-t-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('training.settings-confirm-delete-t-1')));
    await tester.pumpAndSettle();

    expect(sent.single, endsWith('/training/tests/t-1'));
  });

  testWidgets('a refused delete shows the server sentence verbatim', (tester) async {
    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [_guide(id: 'g-1', title: 'Ladder Safety')],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          return _json({'message': 'You may not delete this guide outside your own franchise.'}, 403);
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('training.settings-delete-g-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('training.settings-confirm-delete-g-1')));
    await tester.pumpAndSettle();

    expect(
      find.text('You may not delete this guide outside your own franchise.'),
      findsOneWidget,
      reason: 'A refusal names why; inventing a generic one here would lose it.',
    );
    expect(find.text('Ladder Safety'), findsOneWidget, reason: 'Refused means still there.');
  });

  testWidgets('tapping a guide in Settings opens its real builder, through the real interaction', (
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

  testWidgets('tapping a test in Settings opens its real builder, through the real interaction', (
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

    // Reached TestBuilder for real — the options are on screen.
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
      await tester.tap(find.byTooltip('Delete topic'));
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
    Map<String, dynamic>? sentBody;

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      onRequest: (request) {
        if (request.method == 'POST' && request.url.path.endsWith('/training/guides')) {
          created = true;
          sentBody = jsonDecode(request.body) as Map<String, dynamic>;
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
    // Reached GuideBuilder for real, through the same in-body swap
    // Settings' own rows use — never a dialog.
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    // The default fixture's `me` stands at the ORGANIZATION view, so this
    // create must carry neither place key — sending `company_id` here
    // (e.g. a `companies.first` fallback) would silently reintroduce bug 1
    // while every screen-level assertion above still passes.
    expect(sentBody, isNotNull);
    expect(sentBody!.containsKey('company_id'), isFalse);
    expect(sentBody!.containsKey('franchise_id'), isFalse);
  });

  testWidgets('tapping "New test" creates one and opens its real builder', (tester) async {
    var created = false;
    Map<String, dynamic>? sentBody;

    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      onRequest: (request) {
        if (request.method == 'POST' && request.url.path.endsWith('/training/tests')) {
          created = true;
          sentBody = jsonDecode(request.body) as Map<String, dynamic>;
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

    // Same organization-view guard as "New guide" above.
    expect(sentBody, isNotNull);
    expect(sentBody!.containsKey('company_id'), isFalse);
    expect(sentBody!.containsKey('franchise_id'), isFalse);
  });

  testWidgets(
    'at a franchise view, "New guide" files it on the franchise AND its parent company',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpScreen(
        tester,
        permissions: const ['training.view', 'training.manage'],
        me: _franchiseScope,
        onRequest: (request) {
          if (request.method == 'POST' && request.url.path.endsWith('/training/guides')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({
              'data': _guide(
                id: 'new-g1',
                title: 'Untitled guide',
                companyId: 'co-a1',
                franchiseId: 'fr-a1',
              ),
            });
          }
          if (request.method == 'GET' && request.url.path.endsWith('/training/guides/new-g1')) {
            return _json({
              'data': _guide(
                id: 'new-g1',
                title: 'Untitled guide',
                companyId: 'co-a1',
                franchiseId: 'fr-a1',
              ),
            });
          }
          return null;
        },
      );

      await tester.tap(find.text('Settings'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('New guide'));
      await tester.pumpAndSettle();

      expect(sentBody, isNotNull);
      // The only view that sends two fields that must agree:
      // `franchise_id` names the franchise itself, and `company_id` must be
      // that franchise's PARENT company — never the franchise id repeated,
      // never a different company.
      expect(sentBody!['franchise_id'], 'fr-a1');
      expect(sentBody!['company_id'], 'co-a1');
    },
  );

  // ── Bug 1: create files on the current VIEW, never the organization ───
  //
  // Jose, standing at a company (A-1 Chimney), tapped "New guide" and got
  // the server's own refusal — `PlaceInView::assert` correctly rejects an
  // organization-level filing (`company_id === null`) from a non-org view.
  // The server was right; `_newGuide`/`_newTest` (settings_tab.dart) were
  // wrong — they never sent a place at all, so every create was an
  // organization filing regardless of where the author was standing. These
  // assert on the REQUEST BODY, not the screen: the screen looks identical
  // whether or not `company_id` made it into the POST.

  testWidgets(
    'at a company view, "New guide" files it on that company — never the organization',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpScreen(
        tester,
        permissions: const ['training.view', 'training.manage'],
        me: _companyScope,
        onRequest: (request) {
          if (request.method == 'POST' && request.url.path.endsWith('/training/guides')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide', companyId: 'co-a1')});
          }
          if (request.method == 'GET' && request.url.path.endsWith('/training/guides/new-g1')) {
            return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide', companyId: 'co-a1')});
          }
          return null;
        },
      );

      await tester.tap(find.text('Settings'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('New guide'));
      await tester.pumpAndSettle();

      expect(sentBody, isNotNull);
      // Before the fix this key is simply absent — `sentBody!['company_id']`
      // reads `null`, same as filing on the group, which is exactly the
      // organization-level POST the server correctly refused for Jose.
      expect(sentBody!['company_id'], 'co-a1');
      // A company view never has a franchise in view — a stray
      // `franchise_id` here would 422 in the field even though this
      // assertion alone would not have caught it.
      expect(sentBody!.containsKey('franchise_id'), isFalse);
    },
  );

  testWidgets(
    'at a company view, "New test" files it on that company too',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpScreen(
        tester,
        permissions: const ['training.view', 'training.manage'],
        me: _companyScope,
        onRequest: (request) {
          if (request.method == 'POST' && request.url.path.endsWith('/training/tests')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _test(id: 'new-t1', title: 'Untitled test', companyId: 'co-a1')});
          }
          if (request.method == 'GET' && request.url.path.endsWith('/training/tests/new-t1')) {
            return _json({'data': _test(id: 'new-t1', title: 'Untitled test', companyId: 'co-a1')});
          }
          return null;
        },
      );

      await tester.tap(find.text('Settings'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('New test'));
      await tester.pumpAndSettle();

      expect(sentBody, isNotNull);
      expect(sentBody!['company_id'], 'co-a1');
      expect(sentBody!.containsKey('franchise_id'), isFalse);
    },
  );

  // ── Bug 2: the builder stays inside the app — no route, chrome intact ──
  //
  // Jose's words: opening a guide from Settings replaced the entire
  // window — sidebar, header, top bar, all gone, because `_openGuide`/
  // `_openTest` pushed a `MaterialPageRoute`. Learn already does this
  // correctly (`LearnShelves._selected`, an in-body swap via `setState`,
  // no route) — Settings must do the same. `navigator.canPop()` is the
  // direct proof nothing was pushed: a real push leaves something to pop.

  testWidgets(
    'opening an existing guide from Settings stays inside the app — no route is pushed',
    (tester) async {
      await _pumpScreen(
        tester,
        permissions: const ['training.view', 'training.manage'],
        guides: [_guide(id: 'g1', title: 'Ladder Safety', screens: [_screen('Intro')])],
      );

      await tester.tap(find.text('Settings'));
      await tester.pumpAndSettle();

      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      expect(navigator.canPop(), isFalse);

      await tester.tap(find.text('Ladder Safety'));
      await tester.pumpAndSettle();

      // Reached the real builder...
      expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
      // ...without a route push: still nothing to pop, still one Scaffold
      // (the harness's own — a pushed route wrapped in its own `Scaffold`
      // would make this two), and the module's own tab strip (never
      // redrawn by GuideBuilder) is still on screen beside it.
      expect(navigator.canPop(), isFalse);
      expect(find.byType(Scaffold), findsOneWidget);
      expect(find.text('Learn'), findsOneWidget);
      expect(find.text('Settings'), findsOneWidget);
    },
  );

  // ── Both bugs together: the empty state, which is how Jose hit this ───
  //
  // A test that stubs guide rows in cannot see a broken way to create the
  // FIRST one — Jose had zero guides at A-1 Chimney, which is exactly why
  // "New guide" (not an existing row) was his only door in.

  testWidgets(
    'a company view with no guides yet can still create the first one — filed on '
    'the company, in-body, with the tab strip still on screen',
    (tester) async {
      Map<String, dynamic>? sentBody;

      await _pumpScreen(
        tester,
        permissions: const ['training.view', 'training.manage'],
        me: _companyScope,
        guides: const [],
        onRequest: (request) {
          if (request.method == 'POST' && request.url.path.endsWith('/training/guides')) {
            sentBody = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide', companyId: 'co-a1')});
          }
          if (request.method == 'GET' && request.url.path.endsWith('/training/guides/new-g1')) {
            return _json({'data': _guide(id: 'new-g1', title: 'Untitled guide', companyId: 'co-a1')});
          }
          return null;
        },
      );

      await tester.tap(find.text('Settings'));
      await tester.pumpAndSettle();

      // The empty state itself — the entry point Jose actually used, not a
      // stubbed row.
      expect(find.text('No guides to show — "New guide" starts one.'), findsOneWidget);

      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      expect(navigator.canPop(), isFalse);

      await tester.tap(find.text('New guide'));
      await tester.pumpAndSettle();

      // Bug 1: filed on the view actually standing on it — company_id
      // present, franchise_id absent (this is a company view, not a
      // franchise one).
      expect(sentBody, isNotNull);
      expect(sentBody!['company_id'], 'co-a1');
      expect(sentBody!.containsKey('franchise_id'), isFalse);

      // Bug 2: still inside the app.
      expect(navigator.canPop(), isFalse);
      expect(find.byType(Scaffold), findsOneWidget);
      expect(find.text('Learn'), findsOneWidget);
      expect(find.text('Settings'), findsOneWidget);
      expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    },
  );

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
  'blocks': [
    {'type': 'text', 'text': 'Body for $title.'},
    if (url != null) {'type': kind, 'url': url},
  ],
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
  bool countAttemptsAfterPass = false,
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
  'count_attempts_after_pass': countAttemptsAfterPass,
  'question_count': questions.length,
  'questions': questions,
};

Future<void> _pumpTestBuilder(
  WidgetTester tester, {
  required Map<String, dynamic> test,
  http.Response? Function(http.Request request)? onRequest,
  // Tall enough (2200) that every option and the Save button sit within the
  // test surface without needing a scroll gesture first — the panel's body
  // is a `SingleChildScrollView` (test_builder.dart), and the default
  // 800×600 test window clips well above the questions/options/Save on a
  // test with even one question. 900 wide is deliberately roomy — most
  // callers are exercising something other than layout at narrow widths;
  // pass a narrower `viewSize` (e.g. `Size(375, 2200)`) to exercise that.
  Size viewSize = const Size(900, 2200),
  // Empty by default so most callers never see the "Which guide" picker's
  // real content. A caller exercising that picker's layout — where a long
  // guide title is exactly what can overflow it — must pass real guides in;
  // an always-empty default here is what let the picker's 375px overflow
  // (test-builder.dart) go uncaught for as long as it did.
  List<Map<String, dynamic>> guides = const [],
}) async {
  tester.view.physicalSize = viewSize;
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
          'data': guides,
          'meta': {'total': guides.length},
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
  Map<String, dynamic>? me,
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
          'data': {..._me, 'permissions': permissions, ...?me},
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

/// `me` override for a session standing at a COMPANY view — bug 1's own
/// repro shape (Jose at A-1 Chimney). `scope.type` is `'company'`, so
/// `Me.filingCompanyId` (session.dart) resolves to `co-a1`, never `null`
/// and never `companies.first` off some other company reachable but not in
/// view.
const _companyScope = {
  'organization': {
    'id': 'org-1',
    'name': 'EzBizi Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'company',
    'id': 'co-a1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
    {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
    {'id': 'fr-ever', 'name': 'Ohio', 'company_id': 'co-ever'},
  ],
};

/// `me` override for a session standing at a FRANCHISE view (A-1 Chimney's
/// Connecticut franchise). `scope.type` is `'franchise'`, so
/// `Me.filingFranchiseId` (session.dart) resolves to `fr-a1` and
/// `Me.filingCompanyId` resolves by looking `fr-a1` up in `franchises` and
/// taking ITS `company_id` — `co-a1`, the franchise's own parent, never a
/// different or unrelated company. This is the only view that sends both
/// fields, and the only one where they must agree with each other.
const _franchiseScope = {
  'organization': {
    'id': 'org-1',
    'name': 'EzBizi Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-a1',
    'label': 'Connecticut',
    'path': ['A-1 Chimney', 'Connecticut'],
  },
  'companies': [
    {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
    {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
    {'id': 'fr-ever', 'name': 'Ohio', 'company_id': 'co-ever'},
  ],
};
