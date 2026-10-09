import 'dart:math' as math;

import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/structure_canvas.dart';
import 'package:ezbizi_client/widgets/structure_menu.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// The structure drawn as a picture somebody arranges for themselves.
///
/// ── What these assertions are actually protecting ────────────────
///
/// One rule above all the others. `CompaniesController` will not accept a
/// parent on any write, because "entities are never moved between parents.
/// Every setting, assignment and roll-up below a company is expressed in terms
/// of where it sits, so moving one silently rewrites history."
///
/// A canvas of draggable boxes is exactly the interface that normally means
/// re-parenting, so it is not enough for this one to refuse — it must not LOOK
/// like it would. That is two separate failures with two separate tests: the
/// behavioural one (a drop changes nothing) and the visual one (nothing lights
/// up under a dragged box, and there is no drop target in the tree to light
/// up). An implementation could pass the first while still teaching everybody
/// who uses it that boxes are dropped onto boxes to move a company.
///
/// The canvas takes no API client, so everything below drives the real widget
/// with real gestures against a small harness that behaves the way the screen
/// does: it applies what the canvas reports, and records it.
void main() {
  group('four levels, told apart without relying on colour', () {
    testWidgets('every box says in words what it is', (tester) async {
      await _pump(tester);

      // The decisive channel, and the plainest one: it survives colour
      // blindness, greyscale, a printout and a screen reader. Asserted per box
      // rather than by counting words on the canvas, so a level drawn with the
      // wrong word cannot be hidden by another box drawn with the right one.
      expect(_wordIn(tester, 'organization:org-1'), 'Organization');
      expect(_wordIn(tester, 'company:c-ever'), 'Company');
      expect(_wordIn(tester, 'company:c-tide'), 'Company');
      expect(_wordIn(tester, 'franchise:f-north'), _franchiseLabel);

      // A sub company is a `company` on the wire and has to say the difference
      // out loud, because nothing else on the box does.
      expect(_wordIn(tester, 'company:c-sun'), 'Sub company · switched off');
    });

    /// §8: "Franchise" is the one noun this product lets a customer replace, and
    /// the outline this canvas gives way to on a narrow window already honours
    /// it. A box that said "Place" was the same tab calling the same thing two
    /// things depending on how wide the window happened to be.
    testWidgets('a place is called whatever the organization calls one', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();

      await _pumpRaw(
        tester,
        StructureCanvas(tree: _tree(), franchiseLabel: 'Depot'),
      );

      expect(_wordIn(tester, 'franchise:f-north'), 'Depot');
      expect(find.text('Place'), findsNothing);

      // And said, not lower-cased on the way out. It is their word and it may
      // well be an acronym or a proper noun — the outline's caption makes the
      // same promise, and these two must not disagree either.
      expect(
        _semanticsOf(tester, 'franchise:f-north'),
        'North Shore — Depot, under Everdoors',
      );

      // The three words this file owns are still said in lower case, so the
      // assertion above is about the customer's word rather than about the
      // sentence having stopped lower-casing anything at all.
      expect(
        _semanticsOf(tester, 'company:c-tide'),
        'Tidewater — company, under Ravenhill Services',
      );

      semantics.dispose();
    });

    testWidgets('the four levels differ by glyph and by frame, not only by '
        'fill', (tester) async {
      await _pump(tester);

      // Control first. If every level drew the same glyph this test would still
      // find four things, and the assertion below would be about nothing.
      final glyphs = {for (final key in _allKeys) key: _iconIn(tester, key)};

      expect(
        glyphs.values.toSet(),
        hasLength(4),
        reason: 'The four levels share glyphs, so the icon tells nobody '
            'anything.',
      );

      final frames = {
        for (final key in _boxKeys) key: _decorationOf(tester, key),
      };

      // The whole business is the heaviest and squarest thing on the canvas.
      expect(_radiusOf(frames['organization:org-1']!), 16);
      expect(_radiusOf(frames['company:c-ever']!), 12);

      expect(_borderOf(frames['organization:org-1']!).width, 2);
      expect(_borderOf(frames['company:c-ever']!).width, 1.5);
      expect(_borderOf(frames['company:c-sun']!).width, 1);
    });

    /// The strongest of the four channels, and the one the change added: a
    /// place is not a box at all.
    ///
    /// A frame is what everything on this canvas that can be DRAGGED wears, and
    /// a place has no position for a drag to change. Drawing one round its rows
    /// would be an affordance for a gesture that does not exist, which is the
    /// same class of mistake as a drop target on a canvas that cannot re-parent.
    testWidgets('a place is drawn as a row in its company’s list, not as a box '
        'of its own', (tester) async {
      await _pump(tester);

      for (final key in _placeKeys) {
        // The premise, per key: the row is actually on screen. Without it the
        // two assertions below are both `findsNothing` against a finder that
        // resolves to nothing, which is the shape of an assertion that passes
        // hardest exactly when the thing it is about has been deleted.
        expect(find.byKey(structureBoxKey(key)), findsOneWidget);

        expect(
          find.descendant(
            of: find.byKey(structureBoxKey(key)),
            matching: find.byType(Container),
          ),
          findsNothing,
          reason: '$key is drawn with a frame of its own, so it reads as one '
              'more thing that can be picked up and moved.',
        );
        expect(
          find.byKey(structureCardKey(key)),
          findsNothing,
          reason: '$key occupies the canvas in its own right, so it has a '
              'position — and a place has nowhere to put one.',
        );
      }

      // And it is inside the card belonging to the company that owns it, which
      // is the whole of what "attached" means on screen.
      expect(
        _cardOf(
          tester,
          'company:c-ever',
        ).contains(_rectOf(tester, 'franchise:f-north').center),
        isTrue,
      );
      expect(
        _cardOf(
          tester,
          'company:c-sun',
        ).contains(_rectOf(tester, 'franchise:f-deep').center),
        isTrue,
      );
    });

    /// The sentence a caller with no opinion gets, which is still part of the
    /// product even though the tab that ships passes its own.
    ///
    /// It used to describe a line per kind and a blank line for a branch with no
    /// sub companies. Both halves died with the change: a place is not on a line
    /// at all, and nothing sits below a sub company, so a blank line is not
    /// something this chart has. A note that describes the previous picture is
    /// worse than no note, because it is read as an instruction for finding
    /// something that is not there.
    testWidgets('the note a caller gets by default describes the picture that '
        'is actually drawn', (tester) async {
      await _pumpRaw(
        tester,
        StructureCanvas(tree: _tree(), franchiseLabel: _franchiseLabel),
      );

      expect(
        find.textContaining('listed under the company or sub company that runs '
            'them'),
        findsOneWidget,
        reason: 'Somebody looking for four place boxes and finding four '
            'companies is owed the reason in words.',
      );
      expect(
        find.textContaining('Nothing you do here changes the business'),
        findsOneWidget,
        reason: 'The rule, stated before anybody tries it.',
      );
      expect(find.textContaining('keeps its own line'), findsNothing);
      expect(find.textContaining('A line with nothing on it'), findsNothing);
    });

    testWidgets('a switched-off place says so in words and with a line, not '
        'only in grey', (tester) async {
      await _pump(tester);

      expect(find.text('$_franchiseLabel · switched off'), findsOneWidget);

      final name = tester.widget<Text>(
        find.descendant(
          of: find.byKey(structureBoxKey('franchise:f-quiet')),
          matching: find.text('Quiet Bay'),
        ),
      );

      expect(name.style?.decoration, TextDecoration.lineThrough);
    });
  });

  group('dragging moves the picture, not the business', () {
    testWidgets('a box follows the pointer and reports where it landed', (
      tester,
    ) async {
      final state = _State();

      await _pump(tester, state: state);

      final before = _rectOf(tester, 'company:c-ever');

      await tester.drag(
        find.byKey(structureBoxKey('company:c-ever')),
        const Offset(120, 60),
      );
      await tester.pumpAndSettle();

      final after = _rectOf(tester, 'company:c-ever');

      expect(after.left - before.left, moreOrLessEquals(120, epsilon: 0.5));
      expect(after.top - before.top, moreOrLessEquals(60, epsilon: 0.5));

      // The canvas reports one box, not an arrangement. A callback handing back
      // "the layout" could only hold what this widget drew, so applying it
      // wholesale would drop every position belonging to a box the caller
      // cannot currently see — which is the rule the server keeps on purpose.
      expect(state.moved.map((move) => move.$1).toSet(), {'company:c-ever'});

      // And the coordinate reported is a CANVAS coordinate — where the box now
      // sits in the arrangement, not where it happens to be on the screen. The
      // two differ by the viewport, which is nobody's business but this
      // widget's, and anchoring a drag on the wrong one is how a saved
      // arrangement drifts a little further every time it is opened.
      expect(
        state.layout['company:c-ever'],
        offsetMoreOrLessEquals(
          _auto['company:c-ever']! + const Offset(120, 60),
          epsilon: 0.5,
        ),
      );
    });

    /// The hard constraint, asserted on behaviour.
    testWidgets('dropping one box exactly on another changes nothing about '
        'the tree', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      final from = tester.getCenter(
        find.byKey(structureBoxKey('company:c-tide')),
      );
      final onto = tester.getCenter(
        find.byKey(structureBoxKey('company:c-sun')),
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:c-tide')),
        onto - from,
      );
      await tester.pumpAndSettle();

      // Everything the canvas said, said about one box's position and nothing
      // else. There is no second callback and no payload with a parent in it,
      // because there is nowhere for one to go.
      expect(state.moved.map((move) => move.$1).toSet(), {'company:c-tide'});

      // And the picture still says the same thing it did before: the company is
      // under the whole business, and the line drawn to it comes from there.
      expect(
        _semanticsOf(tester, 'company:c-tide'),
        'Tidewater — company, under Ravenhill Services',
      );

      expect(
        _painter(tester).links,
        hasLength(_boxKeys.length - 1),
        reason: 'A connector was dropped when two boxes overlapped.',
      );

      // The connector for the dragged company still runs from the organization
      // to a card sitting on top of Sunward's — which is the point. It did not
      // detach, and it did not re-aim.
      expect(
        _isLinked(tester, 'organization:org-1', 'company:c-tide'),
        isTrue,
        reason: 'The line stopped pointing at the parent it belongs to.',
      );

      // And the place it owns went with it, because a place has no position of
      // its own — it is a row in this company's card and it moves when the card
      // does. A place left behind at the old coordinate is the picture claiming
      // Harbour Point stopped belonging to Tidewater.
      expect(
        _cardOf(
          tester,
          'company:c-tide',
        ).contains(_rectOf(tester, 'franchise:f-harbour').center),
        isTrue,
      );
    });

    /// The same constraint asserted on APPEARANCE, which is the half an
    /// implementation can fail while passing the one above. A canvas that
    /// refuses to re-parent but lights a box up under the pointer has still
    /// taught everybody who uses it that boxes are dropped onto boxes.
    testWidgets('nothing offers itself as a drop target, mid-drag or ever', (
      tester,
    ) async {
      await _pump(tester);

      // Not one, anywhere. `DashboardGrid` is a Draggable/DragTarget pair whose
      // hover state is a 2px primary border on the target, and that border is
      // literally "drop here to take this place".
      //
      // Matched on the type's NAME rather than with `find.byType`, which needs
      // the exact generic argument: `find.byType(DragTarget<Object>)` finds
      // nothing at all in a tree full of `DragTarget<String>`, so the
      // assertion would read as green while the thing it forbids was on
      // screen. An assertion that cannot fail is worse than no assertion.
      expect(_byTypeName('DragTarget'), findsNothing);
      expect(_byTypeName('Draggable'), findsNothing);
      expect(_byTypeName('LongPressDraggable'), findsNothing);

      final scheme = EzBiziTheme.light().colorScheme;

      // Control: no box is drawn in the highlight colour before the drag, so
      // the count after the drag is about the drag.
      expect(_highlighted(tester, scheme.primary), isEmpty);

      final finger = await tester.startGesture(
        tester.getCenter(find.byKey(structureBoxKey('company:c-tide'))),
      );

      await finger.moveTo(
        tester.getCenter(find.byKey(structureBoxKey('company:c-sun'))),
      );
      await tester.pump();

      // The box in hand may show that it is in hand. Nothing it is passing OVER
      // may show anything at all.
      expect(
        _highlighted(
          tester,
          scheme.primary,
        ).where((key) => key != 'company:c-tide'),
        isEmpty,
        reason: 'A box lit up underneath the one being dragged.',
      );

      await finger.up();
      await tester.pumpAndSettle();
    });

    /// The third failure available here, and the one the owner asked for: a
    /// gesture that is refused and says nothing reads as a control that broke.
    ///
    /// Dropping one box squarely onto another is what a person does when they
    /// mean "put this inside that", so it is the one moment we can be confident
    /// somebody has asked the question. The canvas answers in words — and
    /// nothing else about it changes, which is the half this test has to prove
    /// as hard as the words themselves. An explanation that arrived with a
    /// snap, a reorder or a re-aimed connector would have taught exactly the
    /// thing the whole widget is shaped to refuse.
    testWidgets('dropping a box on another explains itself, and is not a '
        'dialog', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      // Control: the canvas says nothing about it before anybody asks.
      expect(find.textContaining('has not been moved into'), findsNothing);

      final from = tester.getCenter(
        find.byKey(structureBoxKey('company:c-tide')),
      );
      final onto = tester.getCenter(
        find.byKey(structureBoxKey('company:c-sun')),
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:c-tide')),
        onto - from,
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('“Tidewater” has not been moved into “Sunward”'),
        findsOneWidget,
        reason: 'Both names, so it is about the two boxes on screen rather than a '
            'general notice somebody has to work out the relevance of.',
      );
      expect(
        find.textContaining(
          'moving it now would make the past look like it happened somewhere '
          'else',
        ),
        findsOneWidget,
        reason: 'The actual reason, in a business owner\'s words. "You cannot '
            're-parent an entity" is the model\'s vocabulary and answers '
            'nothing.',
      );

      expect(find.byType(Dialog), findsNothing);
      expect(find.byType(AlertDialog), findsNothing);
      expect(
        find.byType(SnackBar),
        findsNothing,
        reason: 'A note above the picture, never a pop-up and never something that '
            'slides away while it is being read. It interrupts nothing and it '
            'covers neither of the two boxes it is about.',
      );

      // And not one thing about the picture is different because of it.
      expect(
        _semanticsOf(tester, 'company:c-tide'),
        'Tidewater — company, under Ravenhill Services',
        reason: 'The box changed what it says it hangs off.',
      );
      expect(state.moved.map((move) => move.$1).toSet(), {'company:c-tide'});
      expect(
        _painter(tester).links,
        hasLength(_boxKeys.length - 1),
        reason: 'A connector was dropped, or a second one invented.',
      );

      await tester.tap(find.text('Got it'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('has not been moved into'),
        findsNothing,
        reason: 'Clearable, because it is about a gesture that has finished. A '
            'paragraph that cannot be got rid of becomes furniture, and then '
            'it is not read at all.',
      );
    });

    /// The same answer for somebody who never touches a pointer.
    ///
    /// The move handle exists so a box can be placed with the arrow keys, and
    /// an explanation raised only by a drag would be the one part of this
    /// feature a keyboard user simply does not get — while being the part that
    /// says why the thing they just did did not do what it looked like.
    testWidgets('the same explanation follows a keyboard nudge onto a box', (
      tester,
    ) async {
      final said = _recordAnnouncements(tester);

      // Starting just clear of Sunward: the two cards are the same height — a
      // box and one place each — and this leaves them overlapping by a little
      // under half, which is deliberately UNDER what this raises at. So the note
      // below is about the press rather than about where the box began.
      final state = _State(
        layout: {
          'company:c-tide': _auto['company:c-sun']! - const Offset(0, 70),
        },
      );

      await _pump(tester, state: state);

      expect(find.textContaining('has not been moved into'), findsNothing);

      await tester.tap(find.byTooltip('Move “Tidewater” with the arrow keys'));
      await tester.pumpAndSettle();

      // One press of 16 takes the overlap past half.
      await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
      await tester.pumpAndSettle();

      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await tester.pumpAndSettle();

      expect(
        find.textContaining('“Tidewater” has not been moved into “Sunward”'),
        findsOneWidget,
      );
      expect(
        said.last,
        contains('has not been moved into'),
        reason: 'And said out loud. A sentence that only exists as a strip on a '
            'canvas is an explanation for people who can see the canvas, which '
            'is not who most needs one.',
      );
    });

    testWidgets('the box says move, not grab', (tester) async {
      await _pump(tester);

      // Found on the CARD rather than inside the box, because the whole card is
      // what a pointer picks up — the list of places belongs to the box and
      // moves with it, so the cursor has to say so over the list too.
      final region = tester.widget<MouseRegion>(
        find
            .descendant(
              of: find.byKey(structureCardKey('company:c-ever')),
              matching: find.byType(MouseRegion),
            )
            .first,
      );

      // "grab" is pick this up and carry it somewhere. "move" is reposition it.
      expect(region.cursor, SystemMouseCursors.move);

      // And the list under it really is inside that card, so the assertion
      // above is about a rectangle that covers the places rather than about the
      // box alone.
      expect(
        _cardOf(
          tester,
          'company:c-ever',
        ).contains(_rectOf(tester, 'franchise:f-quiet').center),
        isTrue,
      );
    });

    testWidgets('a canvas nobody may rearrange draws no move controls at all', (
      tester,
    ) async {
      await _pumpRaw(
        tester,
        StructureCanvas(
          tree: _tree(),
          franchiseLabel: _franchiseLabel,
          layout: const {},
        ),
      );

      // Absent, not disabled. A disabled control invites somebody to work out
      // why it does nothing.
      expect(
        find.byTooltip('Move “Everdoors” with the arrow keys'),
        findsNothing,
      );
      expect(find.text('Arrange for me'), findsNothing);

      // And the boxes are still all there to be read.
      expect(find.text('Everdoors'), findsOneWidget);
    });
  });

  group('the lines between the boxes', () {
    testWidgets('a connector is drawn in the theme’s own token, in both '
        'themes', (tester) async {
      await _pump(tester);

      expect(
        _painter(tester).color,
        EzBiziTheme.light().colorScheme.outlineVariant,
      );

      // The same canvas under the dark theme. A connector pinned to a literal
      // passes the first assertion and vanishes here.
      await _pump(tester, theme: EzBiziTheme.dark());

      expect(
        _painter(tester).color,
        EzBiziTheme.dark().colorScheme.outlineVariant,
      );

      expect(
        EzBiziTheme.light().colorScheme.outlineVariant,
        isNot(EzBiziTheme.dark().colorScheme.outlineVariant),
        reason: 'The two themes share this token, so the test above cannot '
            'tell a theme-derived colour from a literal.',
      );
    });

    /// A place has no connector at all, because it is inside the card the line
    /// would have been drawn to.
    ///
    /// Thirteen lines went with the thirteen boxes, and that is the picture
    /// getting simpler rather than losing information: a row sitting in a
    /// company's own list says "these are that company's places" more plainly
    /// than a line to a box a screen away ever did.
    testWidgets('a place has no line drawn to it, because it is inside one', (
      tester,
    ) async {
      await _pump(tester);

      final links = _painter(tester).links;

      // Three: each box except the organization, which hangs off nothing. Four
      // places, four fewer lines.
      expect(links, hasLength(_boxKeys.length - 1));

      for (final key in _placeKeys) {
        final row = _rectOf(tester, key).shift(-_canvasOrigin(tester));

        expect(
          _touchesALink(tester, row),
          isFalse,
          reason: 'A connector was drawn to $key, which is a row inside its '
              'company’s own card — so the line starts and ends in the same '
              'rectangle.',
        );
      }
    });

    /// The line arrives at the BOX, not at the middle of the card.
    ///
    /// A card is a box plus however many places hang off it, so its vertical
    /// centre is somewhere in the list — on the real customer, beside the fifth
    /// of A-1 Chimney's nine branches. A sideways run anchored there would read
    /// as a connector to a place, which is the one thing a connector on this
    /// canvas may never appear to be.
    ///
    /// Measured off the PATH the painter actually draws rather than off any
    /// number it reports about itself, because "was handed the box's height" and
    /// "used it" are two different claims and only the second one is the
    /// picture.
    testWidgets('a sideways run leaves and arrives at the box, not the middle '
        'of the list', (tester) async {
      await _pumpA1(tester);

      expect(
        _painter(tester).links,
        hasLength(1),
        reason: 'premise: one company, one line',
      );

      final business = _paintedRectOf(tester, 'organization:org-a1');
      final chimney = _paintedRectOf(tester, 'company:c-a1');
      final box = _rectOf(tester, 'company:c-a1').shift(-_canvasOrigin(tester));

      // The premise, and the reason this test exists: with nine places attached
      // the card is far taller than the box, so the two anchors are nowhere
      // near each other and only one of them can be right.
      expect(
        chimney.height,
        greaterThan(box.height * 3),
        reason: 'The card is not taller than the box, so anchoring on either '
            'gives the same answer and this asserts nothing.',
      );

      final (leaves, arrives) = _linesDrawn(tester).single;

      expect(
        arrives.dx,
        moreOrLessEquals(chimney.left, epsilon: 0.5),
        reason: 'The run does not arrive at the card’s left edge at all, so it '
            'is not the sideways route this is about.',
      );
      expect(
        arrives.dy,
        moreOrLessEquals(box.center.dy, epsilon: 0.5),
        reason: 'The line arrives half a CARD down rather than half a box, '
            'which on A-1 Chimney is beside its fifth branch instead of at its '
            'name — a connector pointing at a place.',
      );

      // And it leaves the organization at ITS box for the same reason, which on
      // a card with no places is the same point either way and is asserted so
      // the rule is stated rather than half applied.
      expect(leaves.dy, moreOrLessEquals(business.center.dy, epsilon: 0.5));
    });

    testWidgets('a switched-off company keeps its connector', (tester) async {
      await _pump(tester);

      final links = _painter(tester).links;

      // One per box except the organization, which hangs off nothing. Sunward
      // is switched off in the fixture and its line is still counted.
      expect(links, hasLength(_boxKeys.length - 1));

      // The line INTO the switched-off company, at full strength...
      expect(
        _isLinked(tester, 'company:c-ever', 'company:c-sun'),
        isTrue,
        reason: 'The relationship is not switched off; the company is.',
      );

      // ...and the line into the company that owns it. Greying or dropping
      // either would say the structure changed when a switch was flipped, and
      // it did not.
      expect(_isLinked(tester, 'organization:org-1', 'company:c-ever'), isTrue);
    });
  });

  group('arrange for me', () {
    testWidgets('places every box, with none on top of another', (
      tester,
    ) async {
      final state = _State(
        // Everything piled at the origin, which is what a layout looks like
        // after a bad write and what the button exists to rescue.
        layout: {for (final key in _boxKeys) key: Offset.zero},
      );

      await _pump(tester, state: state);

      // Control: they really are piled up to begin with, otherwise the
      // assertion after the press is about a canvas that was already tidy.
      expect(
        _rectOf(tester, 'company:c-ever'),
        _rectOf(tester, 'company:c-sun'),
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      expect(state.arranged, hasLength(1));
      expect(
        state.arranged.single.keys.toSet(),
        _boxKeys.toSet(),
        reason: 'The arrangement named a place, which has nowhere to sit — and '
            'the tab would send that coordinate to an endpoint that drops it, '
            'on every press, for ever.',
      );

      // The CARDS, because a place row is part of the ground its company
      // occupies. Two boxes that clear each other while Everdoors’ places are
      // drawn through Sunward is a chart nobody can read passing a test that
      // says it is tidy.
      final cards = [for (final key in _boxKeys) _cardOf(tester, key)];

      for (var i = 0; i < cards.length; i++) {
        for (var j = i + 1; j < cards.length; j++) {
          expect(
            cards[i].overlaps(cards[j]),
            isFalse,
            reason: '${_boxKeys[i]} and ${_boxKeys[j]} were laid on top of '
                'each other.',
          );
        }
      }
    });

    testWidgets('pressing it twice is a no-op, not a drift', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      expect(state.arranged, hasLength(2));
      expect(
        state.arranged[1],
        state.arranged[0],
        reason: 'The layout has a moving part in it, so a picture somebody is '
            'happy with cannot be left alone.',
      );
    });

    testWidgets('nothing is left where nobody can scroll to it', (
      tester,
    ) async {
      final state = _State();

      await _pump(tester, state: state);

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      for (final at in state.arranged.single.values) {
        expect(at.dx, greaterThanOrEqualTo(0));
        expect(at.dy, greaterThanOrEqualTo(0));
      }
    });
  });

  /// ── What these assertions are protecting, and why they are separate ──
  ///
  /// A zoom control has two defects available to it, and both of them are the
  /// kind somebody works around rather than reports.
  ///
  /// The first is a zoom that flings the picture off screen. If the scale is
  /// applied and the viewport is left alone, the fixed point is the top-left
  /// corner of the surface, so zooming in on the box you are actually looking
  /// at throws it off the bottom right and you have to go and find it again.
  /// Two presses of that and people stop touching the control.
  ///
  /// The second is a drag that no longer tracks the pointer. A pointer moves in
  /// screen points and a box's position is in canvas points, and the two are
  /// the same number at exactly one scale. Left undivided, at 200% the box
  /// travels twice as far as the finger holding it. That is the defect that
  /// makes a zoomable canvas feel broken, and it is invisible in every test
  /// written at 100%.
  ///
  /// So neither is asserted through the zoom number this widget reports about
  /// itself. Both are measured off what is actually DRAWN — a box's rectangle
  /// on screen, divided by the width a box is meant to be — which is the only
  /// reading that cannot agree with a bug.
  group('zoom is where somebody is looking', () {
    testWidgets('the point under the pointer is the point that stays put', (
      tester,
    ) async {
      await _pump(tester);

      final before = _rectOf(tester, 'company:c-sun');

      // Deliberately NOT the middle of the box and not the middle of the
      // window: a zoom anchored on either of those would pass an assertion
      // written about that one point while being wrong everywhere else.
      final under = before.topLeft + const Offset(37, 11);

      await _ctrlWheel(tester, at: under, by: -240);

      final after = _rectOf(tester, 'company:c-sun');

      expect(
        after.width,
        greaterThan(before.width),
        reason: 'The premise: the wheel with control held did not zoom at all, '
            'so what follows is about a picture that never changed.',
      );

      // The same canvas point, expressed as an offset from the box's own corner
      // in the box's own units, before and after. If the pointer is still over
      // the same part of the same box then the picture grew about the pointer;
      // if it is not, the picture slid.
      expect(
        _canvasPointUnder(tester, under, 'company:c-sun'),
        offsetMoreOrLessEquals(
          (under - before.topLeft) / (before.width / kStructureBoxWidth),
          epsilon: 0.5,
        ),
        reason: 'The picture slid out from under the pointer while it was '
            'being zoomed, which is what happens when the scale changes and '
            'the viewport is left where it was.',
      );

      // And out again, because a fixed point that only holds in one direction
      // is an accident rather than the arithmetic being right.
      await _ctrlWheel(tester, at: under, by: 480);

      expect(_drawnScale(tester), lessThan(1), reason: 'premise');
      expect(
        _canvasPointUnder(tester, under, 'company:c-sun'),
        offsetMoreOrLessEquals(
          (under - before.topLeft) / (before.width / kStructureBoxWidth),
          epsilon: 0.5,
        ),
      );
    });

    /// A button has no pointer to keep still, so the thing it has to hold is
    /// the middle of what somebody is looking at. Anchoring on the corner —
    /// which is what falls out of doing nothing — walks the picture off the
    /// bottom right a little further with every press.
    testWidgets('the buttons grow the picture about the middle of the window', (
      tester,
    ) async {
      await _pump(tester);

      final middle = _surfaceRect(tester).center;
      final was = _canvasPointUnder(tester, middle, 'company:c-ever');

      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pump();
      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pump();

      expect(_drawnScale(tester), greaterThan(1), reason: 'premise');
      expect(
        _canvasPointUnder(tester, middle, 'company:c-ever'),
        offsetMoreOrLessEquals(was, epsilon: 0.5),
        reason: 'Two presses of the zoom button moved what was in the middle '
            'of the window, so somebody zooming in on the thing they are '
            'reading has to go looking for it afterwards.',
      );
    });

    /// The classic defect, asserted from both ends: the box has to keep up with
    /// the pointer on SCREEN, and the coordinate it reports has to be the
    /// canvas coordinate — which is the screen distance divided by the scale.
    ///
    /// Both halves are needed. Reporting the screen distance would look right
    /// on screen for as long as the drag lasted and then spring back on
    /// release; dividing when it should not would track wrong while reporting
    /// right. Only measuring the drawn box and the reported number together
    /// tells the two apart.
    ///
    /// Run in BOTH orientations, and not because the arithmetic differs — it
    /// cannot, being a pointer delta divided by one scale — but because the
    /// picture does. Left to right and top to bottom put a box in a different
    /// part of the surface and give the chart a different shape, and a drag that
    /// tracked in one and not in the other would be a hit-testing or clipping
    /// fault rather than an arithmetic one. Those are exactly the faults a
    /// single-orientation test cannot see.
    testWidgets('a box drag tracks the pointer exactly, at any zoom, either '
        'way round', (tester) async {
      for (final orientation in kStructureOrientations) {
        for (final wheel in const [-360.0, 420.0]) {
          final state = _State();

          await _pump(tester, state: state, orientation: orientation);

          // Zoomed about the box that is about to be dragged, which is both the
          // gesture somebody actually makes — you zoom in on the thing you are
          // looking at — and what keeps the box on screen at every scale. Zooming
          // about the middle of the WINDOW takes whatever is not near the middle
          // off the edge, and a box outside the surface is clipped out of the hit
          // test, so the drag below would land on nothing and this would fail
          // saying the delta was wrong when nothing had been dragged at all.
          await _ctrlWheel(
            tester,
            at: _rectOf(tester, 'company:c-ever').center,
            by: wheel,
          );

          final scale = _drawnScale(tester);

          expect(
            (scale - 1).abs(),
            greaterThan(0.1),
            reason: 'The premise: this drag is happening at about 100%, so it '
                'cannot tell a delta that is divided by the scale from one that '
                'is not.',
          );

          final before = _rectOf(tester, 'company:c-ever');
          final from = arrangeStructure(
            flattenStructure(_tree()),
            orientation: orientation,
          )['company:c-ever']!;

          const by = Offset(120, 60);

          await tester.drag(find.byKey(structureBoxKey('company:c-ever')), by);
          await tester.pumpAndSettle();

          expect(
            _rectOf(tester, 'company:c-ever').topLeft - before.topLeft,
            offsetMoreOrLessEquals(by, epsilon: 0.5),
            reason: 'Flowing $orientation at ${(scale * 100).round()}% the box '
                'did not go where the pointer went. That is the delta being '
                'applied in the wrong units, and it is worst exactly where zoom '
                'is most useful.',
          );

          expect(
            state.layout['company:c-ever']! - from,
            offsetMoreOrLessEquals(by / scale, epsilon: 0.5),
            reason: 'The arrangement was written in the points of one screen at '
                'one zoom, so every colleague opening it finds the box '
                'somewhere else.',
          );
        }
      }
    });

    /// The business this whole change exists for: nine places on one line is
    /// wider than any window, and the tab opens on a fragment of it.
    testWidgets('Fit puts the whole chart in the window, from any zoom', (
      tester,
    ) async {
      await _pumpA1(tester);

      // Control, and it is the reason the feature exists: this chart does not
      // fit. Without it the assertion after the press is about a picture that
      // was already in view.
      expect(_allInView(tester, _a1BoxKeys), isFalse);

      await tester.tap(find.text('Fit'));
      await tester.pump();

      expect(_allInView(tester, _a1BoxKeys), isTrue);

      // And again from a long way in, which is the half that fails when Fit
      // measures the boxes as DRAWN rather than the arrangement they are drawn
      // from: at 300% the drawn chart is three times the size of the one that
      // needs fitting, so the window is divided by the wrong number and the
      // answer converges on nothing.
      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: -1400);

      expect(_drawnScale(tester, 'company:c-a1'), greaterThan(2));
      expect(_allInView(tester, _a1BoxKeys), isFalse, reason: 'premise');

      await tester.tap(find.text('Fit'));
      await tester.pump();

      expect(_allInView(tester, _a1BoxKeys), isTrue);

      // And still filling the window, which is the assertion that actually
      // catches a Fit measuring the boxes as drawn. That mistake does not put
      // anything out of view — it computes a third of the right answer at 300%,
      // lands on the floor of the range and shows a chart that is in view and a
      // quarter of the size it should be, which reads as a button that works.
      expect(
        _roomLeftOver(tester, _a1BoxKeys),
        lessThan(1),
        reason: 'Fit measured the drawn boxes rather than the arrangement they '
            'are drawn from, so pressing it while zoomed in shrinks the chart '
            'far further than the window needs.',
      );
    });

    /// The whole of A1-Group, in both orientations — thirteen places under four
    /// companies, which is the business every part of this change was made for.
    ///
    /// ── Why the real one and not the fixture above ───────────────
    ///
    /// Because the fixture has four places and one sub company, and every
    /// arithmetic mistake this can make is proportional to how much the places
    /// add. On A1-Group one company's list is 438 points on its own — six times
    /// a box — so a Fit that measured boxes and forgot the lists would cut the
    /// bottom off the chart by more than the height of the window, in the one
    /// orientation where the lists stack against the siblings and in the other
    /// where they stack against the levels.
    for (final orientation in kStructureOrientations) {
      testWidgets('Fit holds the whole of the real business, flowing '
          '$orientation', (tester) async {
        await _pumpRaw(
          tester,
          StructureCanvas(
            tree: _a1Group(),
            franchiseLabel: _franchiseLabel,
            orientation: orientation,
          ),
          size: const Size(900, 700),
        );

        // Control, and the reason the feature exists: this chart does not fit
        // the window it is opened in.
        expect(_allInView(tester, _realBoxKeys), isFalse);

        await tester.tap(find.text('Fit'));
        await tester.pump();

        expect(
          _allInView(tester, _realBoxKeys),
          isTrue,
          reason: 'Part of the real business is outside the surface after Fit, '
              'which on this data is a company’s list of places hanging off '
              'the bottom of a chart the button said was in view.',
        );

        // Inside the window WITH the margin kept, which is the assertion that
        // actually bites. A chart fitted to an extent that forgot the place
        // lists is drawn about seven per cent too large — enough to eat the
        // margin and not quite enough to leave the surface, so "is it on the
        // surface" answers yes while the outermost card is pressed against the
        // edge and the bottom one is a place row short of being cut off.
        final surface = _surfaceRect(tester);
        final drawn = _unionOf(tester, _realBoxKeys);

        expect(
          drawn.width,
          lessThanOrEqualTo(surface.width - 2 * kStructureMargin + 0.5),
        );
        expect(
          drawn.height,
          lessThanOrEqualTo(surface.height - 2 * kStructureMargin + 0.5),
          reason: 'The chart is taller than the room Fit had for it, which is '
              'what measuring the boxes and forgetting their places does.',
        );

        expect(
          _roomLeftOver(tester, _realBoxKeys),
          lessThan(1),
          reason: 'The chart was shrunk further than the window required.',
        );

        // And the picture it fitted is a legible one: nothing is drawn through
        // anything else, at the scale it was fitted to.
        final cards = [for (final key in _realBoxKeys) _cardOf(tester, key)];

        for (var i = 0; i < cards.length; i++) {
          for (var j = i + 1; j < cards.length; j++) {
            expect(
              cards[i].overlaps(cards[j]),
              isFalse,
              reason: '${_realBoxKeys[i]} and ${_realBoxKeys[j]} are drawn '
                  'through each other.',
            );
          }
        }

        // Every one of the thirteen places is on screen too, inside the card of
        // the company that owns it — which is the half a test that only
        // measured boxes would miss entirely.
        for (final place in _realPlaceKeys) {
          expect(
            surface.contains(_rectOf(tester, place).center),
            isTrue,
            reason: '$place is outside the drawing surface after Fit.',
          );
        }
      });
    }

    /// "Scales the whole chart to the window" is two claims, and the second one
    /// is the one a guess passes: it has to be the LARGEST scale that holds the
    /// chart, not merely one that does. A Fit that halves whatever it is given
    /// puts everything in view and wastes most of the window doing it.
    testWidgets('Fit is the largest scale the chart fits at, and it is '
        'centred', (tester) async {
      await _pumpA1(tester);

      await tester.tap(find.text('Fit'));
      await tester.pump();

      final surface = _surfaceRect(tester);
      final drawn = _unionOf(tester, _a1BoxKeys);

      final room = Size(
        surface.width - 2 * kStructureMargin,
        surface.height - 2 * kStructureMargin,
      );

      // Inside the window, with the margin kept on every side so the outermost
      // boxes do not read as cut off.
      expect(drawn.width, lessThanOrEqualTo(room.width + 0.5));
      expect(drawn.height, lessThanOrEqualTo(room.height + 0.5));

      // And filling it in whichever direction ran out first. This is what a
      // guess cannot do: a scale picked from anything other than the chart's
      // real extent leaves a gap here.
      expect(
        _roomLeftOver(tester, _a1BoxKeys),
        lessThan(1),
        reason: 'The chart was shrunk further than the window required, so '
            'most of the surface is empty and every name is smaller than it '
            'needed to be.',
      );

      // Centred rather than parked in the corner. A wide, short chart fits on
      // width and leaves a band of empty surface, and hanging all of it below
      // the picture reads as the bottom having failed to draw.
      expect(drawn.center.dx, moreOrLessEquals(surface.center.dx, epsilon: 1));
      expect(drawn.center.dy, moreOrLessEquals(surface.center.dy, epsilon: 1));
    });

    /// The rule the whole feature is built on, asserted rather than asserted
    /// about. The arrangement is ONE setting for the organization now, so a
    /// colleague leaning in to read a name must not move everybody's picture —
    /// and the only way this widget could do that is by reporting something.
    testWidgets('nothing about the zoom is ever reported, by any route', (
      tester,
    ) async {
      final state = _State();

      await _pump(tester, state: state);

      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pump();
      await tester.tap(find.byTooltip('Zoom out'));
      await tester.pump();
      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: -300);
      await tester.tap(find.text('Fit'));
      await tester.pump();

      final pad = await tester.createGesture(kind: PointerDeviceKind.trackpad);
      final over = tester.getCenter(
        find.byKey(structureBoxKey('company:c-ever')),
      );

      await pad.panZoomStart(over);
      await pad.panZoomUpdate(over, scale: 1.4);
      await tester.pump();
      await pad.panZoomEnd();
      await tester.pumpAndSettle();

      // The premise: every one of those did something, so an empty report is
      // about the report rather than about a control that never fired.
      expect(_drawnScale(tester), isNot(moreOrLessEquals(1, epsilon: 0.01)));

      expect(
        state.moved,
        isEmpty,
        reason: 'A zoom was written into the shared arrangement, so one '
            'person\'s eyes moved every colleague\'s boxes.',
      );
      expect(state.arranged, isEmpty);
      expect(
        state.layout,
        isEmpty,
        reason: 'There is no coordinate in the arrangement that a zoom could '
            'have changed, and there must not be a place to put one.',
      );
    });

    /// A control a mouse can reach and a keyboard cannot is half a control, and
    /// this one is on the toolbar of a view whose whole job is to be read.
    testWidgets('the zoom cluster is reachable and announced without a mouse', (
      tester,
    ) async {
      final said = _recordAnnouncements(tester);

      await _pump(tester);

      expect(find.text('100%'), findsOneWidget);

      var reached = false;

      for (var press = 0; press < 24 && !reached; press++) {
        await tester.sendKeyEvent(LogicalKeyboardKey.tab);
        await tester.pump();

        reached = _focusIsInside(tester, find.byTooltip('Zoom in'));
      }

      expect(
        reached,
        isTrue,
        reason: 'Tab never arrives at the zoom control, so the only way to '
            'change the scale is with a pointer.',
      );

      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pumpAndSettle();

      expect(
        _drawnScale(tester),
        greaterThan(1),
        reason: 'The focused button did nothing when it was activated from the '
            'keyboard.',
      );

      // And said out loud, because the percentage is the only feedback the two
      // buttons give and somebody who cannot see it has none at all. A
      // coordinate is never read out on this canvas — that argument is about a
      // fact nobody listening can see, and the setting of the instrument they
      // are looking through is not one of those.
      expect(said.last, 'Zoom ${(_drawnScale(tester) * 100).round()} percent.');
    });

    /// The readout is not decoration: it is the one thing on screen that says
    /// how far from normal size somebody has wandered, and a figure that drifts
    /// from what is drawn is worse than no figure at all.
    testWidgets('the percentage says what the picture is actually drawn at', (
      tester,
    ) async {
      await _pump(tester);

      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: -260);

      final drawn = (_drawnScale(tester) * 100).round();

      expect(drawn, isNot(100), reason: 'premise');
      expect(
        find.text('$drawn%'),
        findsOneWidget,
        reason: 'The number beside the buttons and the size the boxes are drawn '
            'at are two different answers to one question.',
      );
    });

    /// The slot between minus and plus is there so the plus does not walk as
    /// the figure changes width. A slot narrower than the figure is worse than
    /// no slot: "100%" wraps, the digits sit on one line and the percent on
    /// the next, and the cluster reads as a broken control rather than a
    /// scale.
    testWidgets('the zoom percentage stays on one line', (tester) async {
      await _pump(tester);

      final paragraph = tester.renderObject<RenderParagraph>(
        find.text('100%'),
      );

      expect(
        paragraph.size.height,
        moreOrLessEquals(paragraph.preferredLineHeight, epsilon: 0.5),
        reason: 'The digits and the percent wrapped onto two lines, which is '
            'what a slot narrower than “100%” does.',
      );
    });

    /// The gesture a trackpad user makes without thinking, over the thing they
    /// happen to be pointing at — which on a chart of boxes is usually a box.
    testWidgets('a trackpad pinch over a box zooms about the fingers, and '
        'moves no box', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      final pad = await tester.createGesture(kind: PointerDeviceKind.trackpad);
      final at = tester.getCenter(
        find.byKey(structureBoxKey('company:c-ever')),
      );

      await pad.panZoomStart(at);
      await pad.panZoomUpdate(at, scale: 1.6);
      await tester.pump();
      await pad.panZoomEnd();
      await tester.pumpAndSettle();

      expect(_drawnScale(tester), moreOrLessEquals(1.6, epsilon: 0.01));

      // The fingers were on the middle of this box, so the middle of this box
      // is where they still are.
      expect(
        _rectOf(tester, 'company:c-ever').center,
        offsetMoreOrLessEquals(at, epsilon: 0.5),
        reason: 'The picture jumped by the box\'s own offset, which is what '
            'anchoring the zoom in the box\'s coordinates rather than the '
            'surface\'s does.',
      );

      expect(
        state.moved,
        isEmpty,
        reason: 'Two fingers on a trackpad rearranged the picture of the '
            'business.',
      );
    });

    /// The same act on the device the house rules care most about. This is not
    /// a mouse-only affordance and it must not become one.
    testWidgets('two fingers on the surface pinch it', (tester) async {
      await _pump(tester);

      final patch = _emptyPatch(tester);

      final left = await tester.startGesture(
        patch - const Offset(40, 0),
        kind: PointerDeviceKind.touch,
      );
      final right = await tester.startGesture(
        patch + const Offset(40, 0),
        kind: PointerDeviceKind.touch,
      );

      // Eighty points apart becomes a hundred and sixty, which is twice.
      await left.moveBy(const Offset(-40, 0));
      await right.moveBy(const Offset(40, 0));
      await tester.pump();

      await left.up();
      await right.up();
      await tester.pumpAndSettle();

      expect(_drawnScale(tester), moreOrLessEquals(2, epsilon: 0.05));
    });

    /// Both ends, and both of the two ways of reaching them. A wheel that can
    /// take the picture somewhere the buttons cannot is a state only one input
    /// can produce and only one input can get out of.
    testWidgets('the picture stops at both ends, and the control says so', (
      tester,
    ) async {
      await _pump(tester);

      for (var press = 0; press < 24; press++) {
        if (_zoomButton(tester, 'Zoom out').onPressed == null) break;

        await tester.tap(find.byTooltip('Zoom out'));
        await tester.pump();
      }

      expect(
        _drawnScale(tester),
        moreOrLessEquals(kStructureZoomMin, epsilon: 0.005),
      );
      expect(
        _zoomButton(tester, 'Zoom out').onPressed,
        isNull,
        reason: 'A live button at the floor is the one control on this toolbar '
            'that lies about what it can do.',
      );

      // And the wheel cannot go under it either.
      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: 4000);

      expect(
        _drawnScale(tester),
        moreOrLessEquals(kStructureZoomMin, epsilon: 0.005),
      );

      for (var press = 0; press < 24; press++) {
        if (_zoomButton(tester, 'Zoom in').onPressed == null) break;

        await tester.tap(find.byTooltip('Zoom in'));
        await tester.pump();
      }

      expect(
        _drawnScale(tester),
        moreOrLessEquals(kStructureZoomMax, epsilon: 0.005),
      );
      expect(_zoomButton(tester, 'Zoom in').onPressed, isNull);

      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: -4000);

      expect(
        _drawnScale(tester),
        moreOrLessEquals(kStructureZoomMax, epsilon: 0.005),
      );
    });

    /// A plain wheel still scrolls, which is the convention every browser and
    /// map has taught people. If holding control were not consulted, the one
    /// gesture somebody makes without looking would do the wrong thing.
    testWidgets('a wheel with nothing held still scrolls rather than zooming', (
      tester,
    ) async {
      await _pump(tester);

      final before = _rectOf(tester, 'company:c-ever');
      final wheel = TestPointer(4, PointerDeviceKind.mouse);
      final over = _surfaceRect(tester).center;

      await tester.sendEventToBinding(wheel.hover(over));
      await tester.sendEventToBinding(wheel.scroll(const Offset(0, 120)));
      await tester.pump();

      final after = _rectOf(tester, 'company:c-ever');

      expect(
        after.size,
        before.size,
        reason: 'A plain wheel changed the size '
            'of the picture, so scrolling and zooming are the same gesture.',
      );
      expect(after.topLeft, isNot(before.topLeft));
    });

    /// The arrangement controls leave the scale alone, and they have to: the
    /// magnification somebody chose in order to read what they were tidying is
    /// not something a tidy-up gets to take away.
    testWidgets('arranging and re-centring do not undo somebody’s zoom', (
      tester,
    ) async {
      await _pump(tester);

      await _ctrlWheel(tester, at: _surfaceRect(tester).center, by: -300);

      final scale = _drawnScale(tester);

      expect(scale, greaterThan(1), reason: 'premise');

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      expect(_drawnScale(tester), moreOrLessEquals(scale, epsilon: 0.005));

      // And the boxes were actually brought into view at that scale, rather
      // than tidied somewhere off screen — which is what aiming the viewport
      // with an unscaled coordinate does.
      expect(
        _surfaceRect(
          tester,
        ).contains(_rectOf(tester, 'organization:org-1').topLeft),
        isTrue,
      );

      await tester.dragFrom(
        _emptyPatch(tester),
        const Offset(-3000, -2000),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('Bring the boxes into view'));
      await tester.pump();

      expect(_drawnScale(tester), moreOrLessEquals(scale, epsilon: 0.005));

      // The whole picture back at the corner, measured on the picture rather
      // than on any one box: the organization is centred over its children, so
      // the box at the margin is whichever one is furthest left.
      expect(
        _unionOf(tester, _boxKeys).topLeft,
        offsetMoreOrLessEquals(
          _surfaceRect(tester).topLeft +
              const Offset(kStructureMargin, kStructureMargin),
          epsilon: 0.5,
        ),
        reason: '"Bring the boxes into view" aimed at an unscaled coordinate, '
            'so the control that exists to rescue somebody who is lost leaves '
            'them lost at every zoom but 100%.',
      );
    });
  });

  group('a layout that outlives what it names', () {
    testWidgets('a saved place is where the box is when the canvas opens', (
      tester,
    ) async {
      // Measured against another box rather than against the window, so the
      // assertion is about the arrangement and not about the height of the
      // toolbar above the canvas.
      Offset apart() =>
          _rectOf(tester, 'company:c-sun').topLeft -
          _rectOf(tester, 'organization:org-1').topLeft;

      // Control first, and it is the whole test: without a saved layout the box
      // is somewhere else. Otherwise this passes against a canvas that ignores
      // the layout entirely and happens to place it nearby.
      await _pump(tester);

      final automatic = apart();

      expect(
        automatic,
        offsetMoreOrLessEquals(
          _auto['company:c-sun']! - _auto['organization:org-1']!,
          epsilon: 0.5,
        ),
      );

      await _pump(
        tester,
        state: _State(layout: const {'company:c-sun': Offset(700, 364)}),
      );

      expect(
        apart(),
        offsetMoreOrLessEquals(
          const Offset(700, 364) - _auto['organization:org-1']!,
          epsilon: 0.5,
        ),
      );
      expect(apart(), isNot(automatic));
    });

    testWidgets('a box the saved layout has never heard of is still drawn', (
      tester,
    ) async {
      // The saved-order trap, in canvas form: make the saved
      // layout the whole truth and every company added after somebody first
      // arranged their canvas is invisible to them for ever.
      await _pump(
        tester,
        state: _State(layout: const {'company:c-ever': Offset(40, 40)}),
      );

      expect(find.byKey(structureBoxKey('company:c-sun')), findsOneWidget);
      expect(find.byKey(structureBoxKey('franchise:f-quiet')), findsOneWidget);
      expect(find.text('Sunward'), findsOneWidget);
    });

    testWidgets('a layout naming boxes that are not here draws the ones that '
        'are', (tester) async {
      await _pump(
        tester,
        state: _State(
          layout: const {
            'company:c-gone': Offset(10, 10),
            'franchise:f-vanished': Offset(20, 20),
            'company:c-ever': Offset(40, 40),
          },
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.byType(StructureCanvas), findsOneWidget);
      expect(
        _allKeys.map((key) => find.byKey(structureBoxKey(key))),
        everyElement(findsOneWidget),
      );
    });
  });

  group('readable and usable without a mouse', () {
    testWidgets('every box says what it is and what it is under', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();

      await _pump(tester);

      expect(
        _semanticsOf(tester, 'company:c-sun'),
        'Sunward — sub company, under Everdoors, switched off',
      );
      expect(
        _semanticsOf(tester, 'organization:org-1'),
        'Ravenhill Services — organization',
      );
      expect(
        _semanticsOf(tester, 'franchise:f-deep'),
        'Deepwater — $_franchiseLabel, under Sunward',
      );

      semantics.dispose();
    });

    /// Tab has to walk the STRUCTURE. Flutter's default policy reads a Stack of
    /// Positioned children geometrically, so without an explicit widget-order
    /// policy the reading order would change every time somebody dragged a box:
    /// the structure would become unreadable in exactly the proportion the
    /// picture became useful.
    testWidgets('Tab walks the boxes in tree order, not in the order they sit '
        'on screen', (tester) async {
      final state = _State(
        // The last box in tree order is dragged to the top left, so the two
        // orders disagree and only one of them can be the answer.
        layout: const {'company:c-tide': Offset(0, 0)},
      );

      await _pump(tester, state: state);

      final walked = <String>[];

      for (
        var press = 0;
        press < 24 && walked.length < _boxKeys.length;
        press++
      ) {
        await tester.sendKeyEvent(LogicalKeyboardKey.tab);
        await tester.pump();

        final focused = _focusedBox(tester);

        if (focused != null && (walked.isEmpty || walked.last != focused)) {
          walked.add(focused);
        }
      }

      expect(walked, _boxKeys);
    });

    testWidgets('a keyboard user can move a box', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      final before = _rectOf(tester, 'company:c-ever');

      await tester.tap(find.byTooltip('Move “Everdoors” with the arrow keys'));
      await tester.pumpAndSettle();

      for (var press = 0; press < 4; press++) {
        await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
        await tester.pumpAndSettle();
      }

      await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
      await tester.pumpAndSettle();

      final after = _rectOf(tester, 'company:c-ever');

      expect(after.left - before.left, moreOrLessEquals(64, epsilon: 0.5));
      expect(after.top - before.top, moreOrLessEquals(16, epsilon: 0.5));

      expect(state.moved, hasLength(5));
      expect(state.moved.every((move) => move.$1 == 'company:c-ever'), isTrue);

      // And the mode can be left, which is what keeps Tab working.
      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await tester.pumpAndSettle();

      await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
      await tester.pumpAndSettle();

      expect(
        state.moved,
        hasLength(5),
        reason: 'Escape did not end the move, so the arrow keys are still '
            'taken and the structure cannot be read.',
      );
    });

    /// Moving a box changes NOTHING a screen reader would report about the
    /// business — the sentence the box carries is identical before and after.
    /// Reading out coordinates would invent a fact about a picture the listener
    /// cannot see, and would be the one place in this feature that implied the
    /// move meant something.
    testWidgets('the move mode announces itself and never says a number', (
      tester,
    ) async {
      final said = _recordAnnouncements(tester);

      await _pump(tester);

      // Control: nothing has been announced yet, so what follows is about the
      // press and not about something the canvas said while it was building.
      expect(said, isEmpty);

      await tester.tap(find.byTooltip('Move “Everdoors” with the arrow keys'));
      await tester.pumpAndSettle();

      expect(said, [
        'Moving Everdoors. Arrow keys move it. Press Escape when you are done.',
      ]);

      for (var press = 0; press < 3; press++) {
        await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
        await tester.pumpAndSettle();
      }

      // Silent in between. Three presses and nothing new to say.
      expect(said, hasLength(1));

      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await tester.pumpAndSettle();

      expect(said.last, 'Everdoors placed.');
      expect(
        said.where((sentence) => RegExp(r'\d').hasMatch(sentence)),
        isEmpty,
        reason: 'A coordinate was read out for a picture nobody listening can '
            'see.',
      );
    });

    testWidgets('the move control is not icon-only to anybody', (tester) async {
      final semantics = tester.ensureSemantics();

      await _pump(tester);

      expect(
        tester
            .getSemantics(
              find.byTooltip('Move “Everdoors” with the arrow keys'),
            )
            .tooltip,
        'Move “Everdoors” with the arrow keys',
      );

      semantics.dispose();
    });
  });

  group('every platform (R7)', () {
    testWidgets('a 375-point window lays the canvas out without overflowing', (
      tester,
    ) async {
      await _pump(tester, size: const Size(375, 700));

      expect(tester.takeException(), isNull);

      // And the controls are still there to be used rather than dropped
      // because the window was small.
      expect(find.text('Arrange for me'), findsOneWidget);
      expect(find.text('Bring the boxes into view'), findsOneWidget);

      // Including the zoom cluster, which is the control a small window needs
      // most. Its parts wrap onto their own line rather than being cut down to
      // fit — a stepper that loses a button at 375 points is a stepper that
      // only works on a desk.
      expect(find.byTooltip('Zoom out'), findsOneWidget);
      expect(find.text('100%'), findsOneWidget);
      expect(find.byTooltip('Zoom in'), findsOneWidget);
      expect(find.text('Fit'), findsOneWidget);
    });

    /// The toolbar has to hold the CALLER's control too, and that is the one
    /// this widget cannot see the size of.
    ///
    /// The screen above hands it a label and two chips — "The chart flows: Left
    /// to right / Top to bottom" — which is wider than everything else on the
    /// row put together. A slot that laid its child out in a Row rather than the
    /// Wrap would overflow here, and an overflow in release is a control clipped
    /// off the side of the screen rather than a striped bar saying so.
    testWidgets('a caller’s own control fits beside the rest at 375 points', (
      tester,
    ) async {
      await _pumpRaw(
        tester,
        StructureCanvas(
          tree: _tree(),
          franchiseLabel: _franchiseLabel,
          onArranged: (_) {},
          controls: Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: const [
              Text('The chart flows'),
              ChoiceChip(label: Text('Left to right'), selected: true),
              ChoiceChip(label: Text('Top to bottom'), selected: false),
            ],
          ),
        ),
        size: const Size(375, 700),
      );

      expect(tester.takeException(), isNull);

      // Every one of them, still reachable: this widget's three and the
      // caller's.
      expect(find.text('Arrange for me'), findsOneWidget);
      expect(find.text('Fit'), findsOneWidget);
      expect(find.text('Left to right'), findsOneWidget);
      expect(find.text('Top to bottom'), findsOneWidget);

      // And nothing was drawn off the side of the window, which is what an
      // overflow silently does in a release build.
      for (final label in const [
        'Arrange for me',
        'Fit',
        'Left to right',
        'Top to bottom',
      ]) {
        expect(
          tester.getRect(find.text(label)).right,
          lessThanOrEqualTo(375),
          reason: '“$label” is drawn past the right edge of a 375-point window.',
        );
      }
    });

    testWidgets('panning the background never carries a box with it', (
      tester,
    ) async {
      final state = _State();

      // With `state:`, and that word is the whole assertion at the end of this
      // test. Without it the harness builds a _State of its own, the local one
      // is never handed to anything, and `state.moved` is an empty list nothing
      // can ever write to — green against a canvas reporting a move on every
      // frame of a viewport pan.
      await _pump(tester, state: state);

      final before = _rectOf(tester, 'company:c-ever');

      await tester.dragFrom(
        _emptyPatch(tester),
        const Offset(-90, -40),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      final after = _rectOf(tester, 'company:c-ever');

      // The whole picture moved together...
      expect(after.left - before.left, moreOrLessEquals(-90, epsilon: 0.5));

      // ...and nothing was reported, because where you are looking is not part
      // of how you arranged things.
      expect(state.moved, isEmpty);
    });

    testWidgets('panning away is not a dead end', (tester) async {
      await _pump(tester);

      final home = _rectOf(tester, 'organization:org-1');

      await tester.dragFrom(
        _emptyPatch(tester),
        const Offset(-4000, -3000),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      expect(_rectOf(tester, 'organization:org-1'), isNot(home));

      await tester.tap(find.text('Bring the boxes into view'));

      // ONE frame, not pumpAndSettle. Moving the viewport is not an animation:
      // an eased one lags whatever asked for it, and — because the connectors
      // are painted at the destination — leaves every line detached from its
      // box for the length of the ease. pumpAndSettle is exactly when the two
      // agree again, so it is the pump that cannot see this.
      await tester.pump();

      expect(_rectOf(tester, 'organization:org-1'), home);

      expect(
        _touchesALink(tester, _paintedRectOf(tester, 'organization:org-1')),
        isTrue,
      );
    });

    /// Somebody who has asked their system to stop moving things is asking this
    /// canvas too.
    testWidgets('reduced motion means the boxes arrive rather than travel', (
      tester,
    ) async {
      // Control: with animation allowed, one frame after the press the box has
      // NOT arrived — which is what makes the assertion below meaningful.
      await _pump(
        tester,
        state: _State(layout: {for (final key in _boxKeys) key: Offset.zero}),
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();

      final travelling = _rectOf(tester, 'company:c-sun');

      await tester.pumpAndSettle();

      final arrived = _rectOf(tester, 'company:c-sun');

      expect(travelling, isNot(arrived));

      await _pump(
        tester,
        state: _State(layout: {for (final key in _boxKeys) key: Offset.zero}),
        disableAnimations: true,
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();

      expect(
        _rectOf(tester, 'company:c-sun'),
        arrived,
        reason: 'The boxes animated across the canvas for somebody who asked '
            'their system not to.',
      );
    });

    /// The configuration that actually ships, which nothing here used to
    /// measure. `organization_screen` hands the tab an action menu and the tab
    /// always hands the canvas an `onMoved`, so every box on the real screen
    /// holds a glyph, a name, a move handle and an overflow menu — and at the
    /// 200-point width this started at, the name was left 53 points. Every box
    /// in the business showed about seven characters and an ellipsis.
    testWidgets('a name still has room once the box carries its controls', (
      tester,
    ) async {
      await _pump(tester, withActions: true);

      // The premise: this really is the loaded configuration, both controls
      // present inside the box rather than a bare face.
      final everdoors = find.byKey(structureBoxKey('company:c-ever'));

      expect(
        find.descendant(of: everdoors, matching: find.byType(IconButton)),
        findsWidgets,
        reason: 'The move handle is missing, so the measurement below is of a '
            'box nobody is looking at.',
      );
      expect(
        find.descendant(
          of: everdoors,
          matching: find.byTooltip('What to do with Everdoors'),
        ),
        findsOneWidget,
      );

      final name = tester.getRect(
        find.descendant(of: everdoors, matching: find.text('Everdoors')),
      );

      expect(
        name.width,
        greaterThanOrEqualTo(100),
        reason: 'Below about a hundred points a 14-point name is a fragment, '
            'and "Everdoors Overhead Doors" and "Everdoors Overhead Repairs" '
            'become the same string on screen. Shrinking the two controls to '
            'buy the room is the wrong trade: a control small enough to fit is '
            'a control a finger misses.',
      );
      expect(
        name.width,
        lessThan(kStructureBoxWidth),
        reason: 'The premise: the name is measured inside the box rather than '
            'having escaped it.',
      );
    });

    /// A trackpad arrives as a pan-zoom pointer, and a GestureDetector takes
    /// those unless it is told not to — so a two-finger scroll with the cursor
    /// over a box used to pick the box up, and the move was debounced into a
    /// PUT. Scrolling must not mean two different things depending on what is
    /// underneath the cursor.
    testWidgets('a two-finger trackpad scroll over a box moves the view, not '
        'the box', (tester) async {
      final state = _State();

      await _pump(tester, state: state);

      final held = _rectOf(tester, 'company:c-ever');
      final neighbour = _rectOf(tester, 'company:c-tide');

      final pad = await tester.createGesture(kind: PointerDeviceKind.trackpad);

      await pad.panZoomStart(
        tester.getCenter(find.byKey(structureBoxKey('company:c-ever'))),
      );
      await pad.panZoomUpdate(
        tester.getCenter(find.byKey(structureBoxKey('company:c-ever'))),
        pan: const Offset(0, -120),
      );
      await tester.pump();
      await pad.panZoomEnd();
      await tester.pumpAndSettle();

      expect(
        state.moved,
        isEmpty,
        reason: 'Two fingers on a trackpad rearranged the picture of the '
            'business, and the move would have been written to the column.',
      );

      // The premise, and the other half of the claim: it did something, and
      // what it did was move the whole picture together.
      expect(_rectOf(tester, 'company:c-ever'), isNot(held));
      expect(
        _rectOf(tester, 'company:c-ever').top - held.top,
        moreOrLessEquals(
          _rectOf(tester, 'company:c-tide').top - neighbour.top,
          epsilon: 0.5,
        ),
      );
    });

    /// The lines are drawn from the same numbers as the boxes, on the same
    /// frame. They used to be drawn from the numbers the boxes were HEADING
    /// for: a scroll moved the painter at once and eased the boxes over 220ms,
    /// so for a fifth of a second every connector ended in mid air — and a
    /// continuous scroll restarted the ease on every tick, so they never caught
    /// up at all.
    testWidgets('scrolling moves the boxes and their lines on the same frame', (
      tester,
    ) async {
      await _pump(tester);

      final before = _paintedRectOf(tester, 'company:c-ever');

      // Control: the two agree before the scroll, so what follows is about the
      // scroll rather than about the painter never having agreed.
      expect(_touchesALink(tester, before), isTrue);

      final wheel = TestPointer(1, PointerDeviceKind.mouse);
      final over = tester.getCenter(find.byType(StructureCanvas));

      await tester.sendEventToBinding(wheel.hover(over));
      await tester.sendEventToBinding(wheel.scroll(const Offset(0, 120)));
      await tester.pump();

      final after = _paintedRectOf(tester, 'company:c-ever');

      expect(
        after,
        isNot(before),
        reason: 'One frame after the wheel the box has not moved, so the '
            'viewport is being eased — which is the lag this test is about.',
      );
      expect(
        _touchesALink(tester, after),
        isTrue,
        reason: 'The line to this box is drawn somewhere the box is not.',
      );
    });

    /// The same promise during the one animation this widget does have. The
    /// boxes travel for 220ms when "Arrange for me" is pressed, and the lines
    /// have to travel with them rather than arriving first.
    testWidgets('the lines travel with the boxes while they are being '
        'arranged', (tester) async {
      await _pump(
        tester,
        state: _State(layout: {for (final key in _boxKeys) key: Offset.zero}),
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));

      final travelling = _paintedRectOf(tester, 'company:c-sun');
      final arrived = () {
        // Where it ends up, so the frame above can be shown to be a frame in
        // the middle of the journey rather than the end of it.
        return _auto['company:c-sun']!;
      }();

      expect(
        travelling.topLeft,
        isNot(offsetMoreOrLessEquals(arrived, epsilon: 1)),
        reason: 'The premise: 60ms in, this box is still on its way.',
      );
      expect(
        _touchesALink(tester, travelling),
        isTrue,
        reason: 'The line to this box was drawn where the box is going rather '
            'than where it is, so for a fifth of a second it ends in mid air.',
      );

      await tester.pumpAndSettle();
    });

    /// A box is two lines of text, and text follows the reader's font size.
    /// 150% is an ordinary accessibility setting on the tablets this view is
    /// served to, and the box used to stay 72 points tall through all of it.
    /// The other end of the same setting, and the one nothing measured.
    ///
    /// A text scale BELOW 1 is ordinary — Android offers 0.85, and a reader who
    /// wants more on screen picks it — and a place row is sized to the control
    /// it carries rather than to the words in it. Scaled down with the text, the
    /// row goes to 41 points while the action menu inside it stays at the
    /// framework's 48-point minimum, and a control laid out taller than its row
    /// is a striped bar in debug and a clipped menu in release.
    ///
    /// Pumped WITH the menus, because a row with no control in it cannot
    /// overflow and would pass this whatever the height was.
    testWidgets('a place row never shrinks below the control it carries', (
      tester,
    ) async {
      for (final scale in const [0.8, 0.9, 1.0]) {
        await _pump(
          tester,
          withActions: true,
          textScaler: TextScaler.linear(scale),
        );

        expect(
          tester.takeException(),
          isNull,
          reason: 'A place row overflowed at ${scale}x text.',
        );

        expect(
          _rectOf(tester, 'franchise:f-north').height,
          greaterThanOrEqualTo(kStructurePlaceRowHeight - 0.5),
          reason: 'At ${scale}x the row is shorter than the menu inside it.',
        );
      }
    });

    testWidgets('a box grows with the reader’s text size rather than '
        'overflowing', (tester) async {
      for (final scale in const [1.5, 2.0]) {
        await _pump(tester, textScaler: TextScaler.linear(scale));

        expect(
          tester.takeException(),
          isNull,
          reason: 'A box overflowed its own frame at ${scale}x text.',
        );

        final box = _rectOf(tester, 'company:c-ever');
        final caption = tester.getRect(
          find.descendant(
            of: find.byKey(structureBoxKey('company:c-ever')),
            matching: find.text('Company'),
          ),
        );

        expect(
          caption.bottom,
          lessThanOrEqualTo(box.bottom),
          reason: 'The level caption is painting outside the border it belongs '
              'to, and that word is how the four levels are told apart without '
              'colour.',
        );
      }
    });

    testWidgets('a finger picks a box up with something to feel', (
      tester,
    ) async {
      final felt = _recordHaptics(tester);

      await _pump(tester);

      // Control: nothing has been said to the hand yet.
      expect(felt, isEmpty);

      final finger = await tester.startGesture(
        tester.getCenter(find.byKey(structureBoxKey('company:c-ever'))),
        kind: PointerDeviceKind.touch,
      );

      await finger.moveBy(const Offset(60, 0));
      await tester.pump();

      // The finger holding the box is also covering it, so "it is in hand now"
      // cannot only be something to look at.
      expect(felt, ['HapticFeedbackType.selectionClick']);

      await finger.up();
      await tester.pumpAndSettle();
    });
  });

  // ── the shape and the layout, without pumping anything ───────────

  group('reading the tree', () {
    test('a sub company hangs off its company, never off the organization', () {
      final nodes = flattenStructure(_tree());
      final sub = nodes.firstWhere((node) => node.id == 'c-sun');

      expect(sub.isSub, isTrue);
      expect(sub.parentKey, 'company:c-ever');
      expect(sub.parentName, 'Everdoors');
    });

    test('a place hangs off whichever of company or sub company owns it', () {
      final nodes = flattenStructure(_tree());

      expect(
        nodes.firstWhere((node) => node.id == 'f-north').parentKey,
        'company:c-ever',
      );
      expect(
        nodes.firstWhere((node) => node.id == 'f-deep').parentKey,
        'company:c-sun',
      );
    });

    /// This is JSON off the network. A screen that threw because one franchise
    /// came back without a name would be a screen nobody could open and nobody
    /// could fix.
    test(
      'a malformed payload is read for what it has rather than thrown on',
      () {
        expect(flattenStructure(const {}), isEmpty);
        expect(flattenStructure(const {'organization': 'nonsense'}), isEmpty);
        expect(flattenStructure(const {'companies': 'nonsense'}), isEmpty);

        final partial = flattenStructure(const {
          'organization': {'id': 'o', 'name': 'O'},
          'companies': [
            'nonsense',
            {
              'id': 'c',
              'name': 'C',
              'franchises': [
                {'id': 'f', 'name': 'F'},
                {'id': 7, 'name': 'skipped'},
                'nonsense',
              ],
            },
            {'name': 'no id at all'},
          ],
        });

        expect([for (final node in partial) node.id], ['o', 'c', 'f']);
      },
    );

    /// A node with no id would otherwise be dropped while its children were
    /// promoted to the top of the canvas — drawing a sub company as a sibling
    /// of its own parent, which is the exact bug the nested payload prevents.
    test('a company that cannot be read takes its children with it', () {
      final orphaned = flattenStructure(const {
        'companies': [
          {
            'name': 'no id',
            'franchises': [
              {'id': 'f', 'name': 'F'},
            ],
          },
        ],
      });

      expect(orphaned, isEmpty);
    });
  });

  /// ── What these are, now that the walk lives somewhere else ───────
  ///
  /// `arrangeStructure` is no longer a layout: it is where this widget's own
  /// measurements — the width of a box, the gaps, the margin, the height a box
  /// is drawn at for the reader's text size — are handed to
  /// [arrangeStructureFlow], which does the walk and is asserted at length in
  /// `structure_levels_test.dart` against the real thirteen-place business, in
  /// both orientations.
  ///
  /// So what is left to assert here is the BINDING, and the way to catch a
  /// binding that hands over the wrong number is to look at what it produces
  /// with the real numbers in it rather than to compare it against a second
  /// call written the same way. A test that recomputed the expected map by
  /// calling `arrangeStructureFlow` with the constants would pass whatever this
  /// function passed, because both sides would be wrong together.
  group('arranging it automatically', () {
    test('a place is given no position at all, and every box is given one', () {
      final placed = arrangeStructure(flattenStructure(_tree()));

      expect(
        placed.keys.toSet(),
        _boxKeys.toSet(),
        reason: 'Four boxes: the business, two companies and a sub company. '
            'The four places are rows in the lists attached to them, and a row '
            'in a list has nowhere to put a coordinate.',
      );
    });

    /// A box is drawn taller for a reader who has turned their text up, and the
    /// layout has to be told — otherwise the boxes are laid out against 72 and
    /// drawn at 144, and every one of them overlaps the one below it.
    ///
    /// Asserted on the DRAWN cards rather than on the number handed over,
    /// because handing over a number and drawing something else is exactly the
    /// failure, and only the picture can tell the two apart.
    testWidgets('the picture is laid out at the size it is actually drawn', (
      tester,
    ) async {
      for (final scale in const [1.0, 2.0]) {
        await _pump(tester, textScaler: TextScaler.linear(scale));

        final cards = [for (final key in _boxKeys) _cardOf(tester, key)];

        for (var i = 0; i < cards.length; i++) {
          for (var j = i + 1; j < cards.length; j++) {
            expect(
              cards[i].overlaps(cards[j]),
              isFalse,
              reason: 'At ${scale}x text, ${_boxKeys[i]} and ${_boxKeys[j]} '
                  'are drawn on top of each other — the layout was given a box '
                  'height the boxes are not drawn at.',
            );
          }
        }
      }
    });

    /// The orientation reaches the layout, which is the other half of the
    /// binding and the one a caller can silently drop: a canvas that took the
    /// word and never passed it on would draw the same chart whichever chip
    /// somebody pressed, and look like a control that does nothing.
    testWidgets('the chart flows the way the canvas is told to flow it', (
      tester,
    ) async {
      await _pump(tester, orientation: structureLeftToRight);

      final sideways =
          _rectOf(tester, 'company:c-ever').topLeft -
          _rectOf(tester, 'organization:org-1').topLeft;

      expect(
        sideways.dx,
        greaterThan(0),
        reason: 'Depth runs rightward: a company is to the right of the '
            'business it belongs to.',
      );

      await _pump(tester, orientation: structureTopToBottom);

      final downward =
          _rectOf(tester, 'company:c-ever').topLeft -
          _rectOf(tester, 'organization:org-1').topLeft;

      expect(downward.dy, greaterThan(0), reason: 'Depth runs downward.');
      expect(
        downward.dx.abs(),
        lessThan(sideways.dx),
        reason: 'Both orientations drew the same chart, so the word never '
            'reached the layout.',
      );
    });

    /// The shape that exposed the level bug, drawn: ONE company holding two
    /// places of its own AND two sub companies holding one each.
    ///
    /// Everything else in this file passes on a fixture where no company has
    /// both, and both is what makes a company's attached list and its children
    /// compete for the same room. In either orientation the sub companies must
    /// clear the list rather than being drawn through it.
    for (final orientation in kStructureOrientations) {
      testWidgets('Everdoors: nothing is drawn through a company’s own list of '
          'places, flowing $orientation', (tester) async {
        await _pumpRaw(
          tester,
          StructureCanvas(
            tree: _everdoors(),
            franchiseLabel: _franchiseLabel,
            orientation: orientation,
          ),
          size: const Size(1600, 1200),
        );

        const keys = [
          'organization:org-ever',
          'company:c-everdoors',
          'company:c-sunward',
          'company:c-premier',
        ];

        final cards = [for (final key in keys) _cardOf(tester, key)];

        // The premise: Everdoors’ card really is taller than a bare box, so a
        // layout that reserved room for the box alone has something to run
        // into.
        expect(
          cards[1].height,
          greaterThan(_rectOf(tester, 'company:c-everdoors').height),
        );

        for (var i = 0; i < cards.length; i++) {
          for (var j = i + 1; j < cards.length; j++) {
            expect(
              cards[i].overlaps(cards[j]),
              isFalse,
              reason: '${keys[i]} and ${keys[j]} are drawn through each other.',
            );
          }
        }
      });
    }

    /// Turning it round on the canvas that is already on screen, which is what
    /// somebody pressing the control actually does.
    ///
    /// The two-pump test above builds a fresh canvas each time and so says
    /// nothing about the one path a person can reach: the same State being
    /// handed a new orientation. A canvas that read the word once and never
    /// again would pass everything above and be a control that does nothing.
    ///
    /// The viewport is re-aimed as well, and that is the half worth asserting
    /// separately. The two orientations put the chart in different places, so a
    /// canvas that moved the boxes and left the viewport alone would answer the
    /// press with an empty grey surface — which reads as the picture having been
    /// lost rather than turned.
    testWidgets('turning it round on the same canvas moves the boxes and keeps '
        'them in view', (tester) async {
      await _pumpRaw(tester, const _Flipper());

      final sideways = _rectOf(tester, 'company:c-ever').topLeft -
          _rectOf(tester, 'organization:org-1').topLeft;

      expect(sideways.dx, greaterThan(0), reason: 'premise: it starts sideways');

      await tester.tap(find.text('Turn it round'));
      await tester.pumpAndSettle();

      final downward = _rectOf(tester, 'company:c-ever').topLeft -
          _rectOf(tester, 'organization:org-1').topLeft;

      expect(
        downward.dy,
        greaterThan(0),
        reason: 'The canvas kept the orientation it was first built with, so '
            'the control does nothing to anybody who presses it.',
      );
      expect(downward.dx.abs(), lessThan(sideways.dx));

      // And the whole picture is still on the surface rather than wherever the
      // old shape happened to leave the viewport pointing.
      expect(_allInView(tester, _boxKeys), isTrue);
    });

    /// A word from a stored blob nobody recognises draws the default rather
    /// than nothing. A chart that refuses to draw over one unreadable word
    /// would be a structure screen nobody in the organization could open.
    testWidgets('an orientation nobody recognises is drawn the usual way '
        'round', (tester) async {
      await _pump(tester, orientation: 'diagonal');

      expect(
        _rectOf(tester, 'company:c-ever').left,
        greaterThan(_rectOf(tester, 'organization:org-1').left),
      );
    });

    test('no two boxes are given the same point', () {
      final places = arrangeStructure(flattenStructure(_tree()));

      expect(places.values.toSet(), hasLength(places.length));
    });

    test('the same tree gives the same answer twice', () {
      final nodes = flattenStructure(_tree());

      expect(arrangeStructure(nodes), arrangeStructure(nodes));

      // And from a second read of the same payload, which is what a refetch
      // after a rename actually produces.
      expect(
        arrangeStructure(nodes),
        arrangeStructure(flattenStructure(_tree())),
      );
    });

    test('nothing is placed where nobody can scroll to it', () {
      final places = arrangeStructure(flattenStructure(_tree()));

      for (final at in places.values) {
        expect(at.dx, greaterThanOrEqualTo(0));
        expect(at.dy, greaterThanOrEqualTo(0));
      }
    });

    test('an empty tree arranges nothing rather than dividing by zero', () {
      expect(arrangeStructure(const []), isEmpty);
    });
  });

  group('the two halves of a layout key', () {
    /// A stored layout legitimately holds positions for boxes that are not on
    /// screen, and the screen has to send those back untouched. Splitting a key
    /// nothing resolves to is the only way to do it.
    test('a key nothing on screen can resolve still splits', () {
      expect(structureKindOf('company:0198-abc'), 'company');
      expect(structureIdOf('company:0198-abc'), '0198-abc');
      expect(structureKindOf('organization:x'), 'organization');
      expect(structureIdOf('nonsense'), '');
    });
  });
}

// ── reading the canvas ─────────────────────────────────────────────

/// What this organization calls a place (§8).
///
/// Deliberately not "Place": the canvas used to write that word on every place
/// box no matter what the customer had chosen, and a fixture using the default
/// could not tell the difference between a lookup and a literal.
const String _franchiseLabel = 'Territory';

/// Everything the fixture draws, in tree order — which is also the order the
/// structure is read in.
///
/// Four of these get a BOX and four are drawn as rows in the lists attached to
/// them; see [_boxKeys], which is the half that has a position, can be dragged,
/// can hold focus and can be linked by a connector. The two lists are kept apart
/// on purpose rather than one being filtered out of the other at each use: an
/// assertion that named a place where it meant a box would otherwise read as an
/// ordinary one and fail somewhere unhelpful.
const List<String> _allKeys = [
  'organization:org-1',
  'company:c-ever',
  'franchise:f-north',
  'franchise:f-quiet',
  'company:c-sun',
  'franchise:f-deep',
  'company:c-tide',
  'franchise:f-harbour',
];

/// The four that get a box, in tree order — which is also the order Tab has to
/// walk them in.
const List<String> _boxKeys = [
  'organization:org-1',
  'company:c-ever',
  'company:c-sun',
  'company:c-tide',
];

/// And the four that are rows in a company's attached list.
const List<String> _placeKeys = [
  'franchise:f-north',
  'franchise:f-quiet',
  'franchise:f-deep',
  'franchise:f-harbour',
];

/// The boxes A-1 Chimney draws — the real customer, and the shape that made
/// zoom necessary.
///
/// Two boxes and nine places attached to one of them. Nine places was 2,600
/// points across when each was a box of its own and is a 438-point list now,
/// which is a chart that still does not fit a short window at 100% — the zoom
/// assertions are written against this rather than the fixture above, because
/// the fixture fits and a "Fit" tested on a chart that already fits proves
/// nothing.
const List<String> _a1BoxKeys = ['organization:org-a1', 'company:c-a1'];

/// The boxes and the places of the WHOLE real business — see [_a1Group].
///
/// Four companies and one organization get a box; the thirteen places are rows
/// in four attached lists. Both halves are named because a chart can be fitted
/// to its boxes and still hang nine branches off the bottom of the window.
const List<String> _realBoxKeys = [
  'organization:a1-group',
  'company:a-1-chimney',
  'company:everdoors',
  'company:premier',
  'company:speedy',
];

final List<String> _realPlaceKeys = [
  for (final name in _chimneyPlaces) 'franchise:chimney-$name',
  'franchise:everdoors-connecticut',
  'franchise:everdoors-dmv',
  'franchise:premier-connecticut',
  'franchise:speedy-connecticut',
];

/// Every widget whose type is named [name], whatever it was parameterised with.
Finder _byTypeName(String name) => find.byWidgetPredicate((widget) {
  final type = widget.runtimeType.toString();

  return type == name || type.startsWith('$name<');
});

/// One BOX, or one place ROW — the rectangle the name is written in.
///
/// Deliberately not the card. A box is [kStructureBoxWidth] by
/// [kStructureBoxHeight] whatever its company owns, which is what lets
/// [_drawnScale] read the zoom off it; the card around it grows with the list of
/// places, so measuring that instead would report A-1 Chimney as drawn at 700%.
Rect _rectOf(WidgetTester tester, String key) =>
    tester.getRect(find.byKey(structureBoxKey(key)));

/// One CARD — a box and, under it, the list of places attached to it.
///
/// This is what occupies the canvas: what the automatic layout is placing, what
/// a pointer picks up, what the connectors leave from and what must not overlap
/// anything else. Every assertion about the SHAPE of the chart goes through this
/// rather than through [_rectOf], because two boxes that do not touch while
/// A-1 Chimney's nine branches are drawn through Everdoors is a chart nobody can
/// read passing a test that says it is fine.
Rect _cardOf(WidgetTester tester, String key) =>
    tester.getRect(find.byKey(structureCardKey(key)));

// ── reading the zoom off what is actually drawn ────────────────────

/// How big the picture is drawn, measured rather than asked for.
///
/// Every assertion about zoom in this file goes through this, and the reason is
/// worth stating: a widget that reports its own scale can report one thing and
/// draw another, and the two defects a zoom control actually has — the picture
/// sliding out from under the pointer, and a drag in the wrong units — are both
/// disagreements between a number and a drawing. A box is [kStructureBoxWidth]
/// across by definition, so the width it is drawn at IS the scale, and there is
/// no second opinion for a bug to hide behind.
double _drawnScale(WidgetTester tester, [String key = 'company:c-ever']) =>
    _rectOf(tester, key).width / kStructureBoxWidth;

/// The canvas point currently under a screen point, in canvas units.
///
/// Measured against one box's corner rather than against the surface, because
/// the viewport offset is private and the box is the only thing on screen whose
/// canvas position is known. What the assertions actually compare is this
/// answer before a zoom against the same answer after it: if the pointer is
/// still over the same part of the same box, the picture grew about the
/// pointer.
Offset _canvasPointUnder(WidgetTester tester, Offset screen, String key) {
  final box = _rectOf(tester, key);

  return (screen - box.topLeft) / (box.width / kStructureBoxWidth);
}

/// The drawing surface itself — the ground the boxes are drawn on, below the
/// toolbar. Found through the connector painter, which fills it.
Rect _surfaceRect(WidgetTester tester) => tester.getRect(
  find.byWidgetPredicate(
    (widget) =>
        widget is CustomPaint && widget.painter is StructureConnectorPainter,
  ),
);

/// Whether every named box is drawn inside the surface.
///
/// Read off `getRect`, which answers where a box IS rather than where it is
/// visible — a box outside the surface is clipped away on screen, and a test
/// that looked for it there could not tell "off the edge" from "not drawn".
bool _allInView(WidgetTester tester, List<String> keys) {
  final surface = _surfaceRect(tester);

  return keys.every((key) {
    final card = _cardOf(tester, key);

    return card.left >= surface.left - 0.5 &&
        card.top >= surface.top - 0.5 &&
        card.right <= surface.right + 0.5 &&
        card.bottom <= surface.bottom + 0.5;
  });
}

Rect _unionOf(WidgetTester tester, List<String> keys) => keys
    .map((key) => _cardOf(tester, key))
    .reduce((a, b) => a.expandToInclude(b));

/// How much room is left in whichever direction the chart ran out of it first.
///
/// Near zero is what "the largest scale it fits at" means, and it is the one
/// reading that separates a scale computed from the chart's real extent from
/// any scale that merely happens to fit. A chart shrunk to half of what the
/// window allows leaves hundreds of points here in both directions.
double _roomLeftOver(WidgetTester tester, List<String> keys) {
  final surface = _surfaceRect(tester);
  final drawn = _unionOf(tester, keys);

  return math.min(
    surface.width - 2 * kStructureMargin - drawn.width,
    surface.height - 2 * kStructureMargin - drawn.height,
  );
}

/// The wheel with control held, which is what every browser and map has taught
/// people means "zoom".
///
/// The key is genuinely pressed and released rather than the handler being
/// called directly, because what is being asserted is that the widget consults
/// [HardwareKeyboard] — a test that reached past it would pass against a canvas
/// that zoomed on every scroll and made ordinary scrolling impossible.
Future<void> _ctrlWheel(
  WidgetTester tester, {
  required Offset at,
  required double by,
}) async {
  final wheel = TestPointer(3, PointerDeviceKind.mouse);

  await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
  await tester.sendEventToBinding(wheel.hover(at));
  await tester.sendEventToBinding(wheel.scroll(Offset(0, by)));
  await tester.pump();
  await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
}

/// One of the two stepper buttons, so its enabled state can be read.
///
/// [IconButton] builds the tooltip INSIDE itself, so the button is an ancestor
/// of what `byTooltip` finds rather than the other way round.
IconButton _zoomButton(WidgetTester tester, String tooltip) => tester.widget(
  find.ancestor(of: find.byTooltip(tooltip), matching: find.byType(IconButton)),
);

/// Whether what currently holds focus is inside [finder].
///
/// Walked up from the focused element rather than compared against a focus node
/// the test made itself, because these are the framework's own buttons and the
/// nodes they focus are private to them. What is being asserted is only that
/// Tab arrives somewhere inside the control, which is the whole of what a
/// keyboard user needs.
bool _focusIsInside(WidgetTester tester, Finder finder) {
  final focused = FocusManager.instance.primaryFocus?.context;
  final targets = finder.evaluate().toSet();

  if (focused == null || targets.isEmpty) return false;

  var inside = false;

  focused.visitAncestorElements((element) {
    if (!targets.contains(element)) return true;

    inside = true;

    return false;
  });

  return inside;
}

/// A point on the drawing surface with no box under it.
///
/// ── Why this is measured rather than typed in ────────────────────
///
/// It used to be `Offset(60, 660)`, which was empty canvas until a place moved
/// down to the fourth line and landed on it. A test that pans the BACKGROUND
/// and silently starts panning a box does not fail loudly — it fails by
/// asserting the opposite of what it says, which is how "panning never carries
/// a box with it" would have gone green while carrying one.
///
/// So it is found: below every box that is actually on screen, at an x no box
/// occupies, with the assertion below to say so. Somebody rearranging the
/// levels again gets a working test rather than a puzzle.
///
/// The CARDS, not the boxes. A point below a box can be squarely on the list of
/// places attached to it, and the whole card answers a pointer — so a patch
/// found against boxes alone would pick a place row and turn every viewport-pan
/// assertion below it into a box drag.
Offset _emptyPatch(WidgetTester tester) {
  final cards = [for (final key in _boxKeys) _cardOf(tester, key)];
  final lowest = cards.map((card) => card.bottom).reduce(math.max);
  final patch = Offset(60, lowest + 40);

  expect(
    cards.where((card) => card.contains(patch)),
    isEmpty,
    reason: 'The patch this test pans from has a card on it, so it is about to '
        'measure a box drag and call it a viewport pan.',
  );

  return patch;
}

/// A card's rectangle in the canvas's own coordinates rather than the window's.
///
/// The painter is handed rectangles relative to the drawing surface, which
/// starts below the toolbar. Comparing a window rectangle against one of those
/// is the kind of test that fails by a constant and sends somebody looking for
/// a bug in the geometry.
///
/// Cards, because that is what the painter is given — see the links it holds.
Rect _paintedRectOf(WidgetTester tester, String key) =>
    _cardOf(tester, key).shift(-_canvasOrigin(tester));

Offset _canvasOrigin(WidgetTester tester) => tester
    .getRect(
      find.byWidgetPredicate(
        (widget) =>
            widget is CustomPaint &&
            widget.painter is StructureConnectorPainter,
      ),
    )
    .topLeft;

/// Where every box lands when nobody has arranged anything, computed by the
/// same function the widget uses. Not a table of numbers copied into the test:
/// a copy would have to be re-derived by hand every time the geometry moved,
/// and the assertions that matter are about the RELATIONSHIP between a stored
/// coordinate and a drawn one rather than about the numbers themselves.
///
/// Flowing the default way, because that is what [_pump] draws unless a test
/// says otherwise. What the layout ITSELF answers in each orientation is
/// asserted in `structure_levels_test.dart`, against the real thirteen-place
/// business rather than against this fixture.
final Map<String, Offset> _auto = arrangeStructure(flattenStructure(_tree()));

/// The level a box says it is, read off the box itself.
String _wordIn(WidgetTester tester, String key) => tester
    .widget<Text>(
      find
          .descendant(
            of: find.byKey(structureBoxKey(key)),
            matching: find.byType(Text),
          )
          .at(1),
    )
    .data!;

IconData _iconIn(WidgetTester tester, String key) => tester
    .widget<Icon>(
      find
          .descendant(
            of: find.byKey(structureBoxKey(key)),
            matching: find.byType(Icon),
          )
          .first,
    )
    .icon!;

BoxDecoration _decorationOf(WidgetTester tester, String key) =>
    tester
            .widget<Container>(
              find
                  .descendant(
                    of: find.byKey(structureBoxKey(key)),
                    matching: find.byType(Container),
                  )
                  .first,
            )
            .decoration!
        as BoxDecoration;

double _radiusOf(BoxDecoration decoration) =>
    ((decoration.borderRadius! as BorderRadius).topLeft).x;

BorderSide _borderOf(BoxDecoration decoration) =>
    (decoration.border! as Border).top;

/// The keys of every box currently drawing its frame in [colour].
///
/// The boxes, because a place row has no frame at all — see the assertion that
/// says so, which is the other half of "nothing lights up under a dragged box".
List<String> _highlighted(WidgetTester tester, Color colour) => [
  for (final key in _boxKeys)
    if (_borderOf(_decorationOf(tester, key)).color == colour) key,
];

String? _semanticsOf(WidgetTester tester, String key) =>
    tester.getSemantics(find.byKey(structureBoxKey(key))).label;

/// The box that currently holds focus, by its node key.
///
/// Read off the Focus widget rather than a debug label, because the key is the
/// thing the canvas actually promises and a debug label is not.
///
/// The boxes only. A place row holds no focus and has no [Focus] to read — it
/// cannot be moved, so there is nothing for the arrow keys to do to it, and
/// putting every place in the tab order would be four more stops on this fixture
/// and thirteen more on the real business, each of which does nothing.
String? _focusedBox(WidgetTester tester) {
  for (final key in _boxKeys) {
    final focus = tester.widget<Focus>(find.byKey(structureBoxKey(key)));

    if (focus.focusNode?.hasPrimaryFocus == true) return key;
  }

  return null;
}

/// The runs the connector painter actually PAINTS, each as its two ends.
///
/// ── Why this drives the painter rather than reading its fields ────
///
/// Because the fields are what it was handed and the path is what it drew, and
/// the whole question about an anchor is which of the two it used. A painter
/// given the height of a box and then routing its lines through the middle of a
/// card would satisfy every assertion about [StructureConnectorPainter.links]
/// and [StructureConnectorPainter.faceHeight] while drawing a line that points
/// at a company's fifth branch.
///
/// The ends are read with [Path.computeMetrics], which is the only way to get a
/// point back out of a [Path]: the first and last positions of each run are
/// exactly where it leaves and where it arrives, which is what the assertions
/// are about. The corners in between are not read, because where the jog sits
/// is a matter of taste and the two ends are not.
List<(Offset, Offset)> _linesDrawn(WidgetTester tester) {
  final canvas = _PathRecorder();

  _painter(tester).paint(canvas, Size.zero);

  return [
    for (final path in canvas.paths)
      if (path.computeMetrics().isNotEmpty)
        (
          path.computeMetrics().first.getTangentForOffset(0)!.position,
          path
              .computeMetrics()
              .last
              .getTangentForOffset(path.computeMetrics().last.length)!
              .position,
        ),
  ];
}

/// A [Canvas] that keeps the paths it is asked to draw and does nothing else.
///
/// `implements` with a [noSuchMethod] rather than a real recording canvas,
/// because the only thing this needs is the argument to `drawPath` — a
/// `PictureRecorder` would give back a picture nothing can read the geometry out
/// of again, which is the opposite of what is wanted.
class _PathRecorder implements Canvas {
  final List<Path> paths = [];

  @override
  void drawPath(Path path, Paint paint) => paths.add(path);

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

/// Whether a connector is currently drawn from one card to the other.
///
/// ── Why the rectangles are compared with a tolerance ─────────────
///
/// Because they arrive by two different routes from the same numbers. The
/// painter is handed `position × zoom + view`; this test reads the same card
/// back out of the widget tree through `localToGlobal` and then subtracts the
/// surface's own origin. The two agree to about a ten-thousandth of a point and
/// differ in the last bit of a double, so `==` on a [Rect] passes or fails on
/// which order the arithmetic happened in — which is not a fact about
/// connectors, and is a test that goes red for somebody who changed nothing.
///
/// Half a point is far tighter than any real defect: a line drawn to the wrong
/// card is out by a whole card, and a line that failed to follow a moving box is
/// out by the distance it travelled.
bool _isLinked(WidgetTester tester, String parent, String child) {
  final from = _paintedRectOf(tester, parent);
  final to = _paintedRectOf(tester, child);

  return _painter(
    tester,
  ).links.any((link) => _sameRect(link.$1, from) && _sameRect(link.$2, to));
}

/// Whether any connector at all begins or ends at this rectangle.
bool _touchesALink(WidgetTester tester, Rect rect) => _painter(
  tester,
).links.any((link) => _sameRect(link.$1, rect) || _sameRect(link.$2, rect));

bool _sameRect(Rect a, Rect b) =>
    (a.left - b.left).abs() < 0.5 &&
    (a.top - b.top).abs() < 0.5 &&
    (a.right - b.right).abs() < 0.5 &&
    (a.bottom - b.bottom).abs() < 0.5;

/// The connector painter, so its colour and its endpoints can be read directly.
///
/// Found by the painter's own type rather than by taking the first CustomPaint
/// under the canvas — a Material app is full of them, and a finder that happens
/// to pick the right one today is a test that starts asserting things about an
/// ink splash the day the toolbar gains a control.
StructureConnectorPainter _painter(WidgetTester tester) =>
    tester
            .widget<CustomPaint>(
              find.byWidgetPredicate(
                (widget) =>
                    widget is CustomPaint &&
                    widget.painter is StructureConnectorPainter,
              ),
            )
            .painter!
        as StructureConnectorPainter;

/// Collects the sentences the app asks a screen reader to say, in order.
List<String> _recordAnnouncements(WidgetTester tester) {
  final said = <String>[];

  tester.binding.defaultBinaryMessenger.setMockDecodedMessageHandler<Object?>(
    SystemChannels.accessibility,
    (message) async {
      if (message is Map && message['type'] == 'announce') {
        said.add((message['data'] as Map)['message'] as String);
      }

      return null;
    },
  );

  addTearDown(
    () => tester.binding.defaultBinaryMessenger
        .setMockDecodedMessageHandler<Object?>(
          SystemChannels.accessibility,
          null,
        ),
  );

  return said;
}

/// Collects the haptics the app asks the device for, in order.
List<String> _recordHaptics(WidgetTester tester) {
  final felt = <String>[];

  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async {
      if (call.method == 'HapticFeedback.vibrate') {
        felt.add(call.arguments as String);
      }

      return null;
    },
  );

  addTearDown(
    () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    ),
  );

  return felt;
}

// ── driving it ─────────────────────────────────────────────────────

/// The screen's half of the contract, small enough to read.
///
/// The canvas is controlled: it draws what it is handed and reports what
/// somebody did. This applies the reports the way the real tab does — which is
/// also what makes a test that asserts the box moved a test of the canvas AND
/// of the contract, rather than of a widget quietly keeping its own state.
class _State {
  _State({Map<String, Offset> layout = const {}}) : layout = {...layout};

  final Map<String, Offset> layout;

  /// Every move reported, in order, as (key, where).
  final List<(String, Offset)> moved = [];

  /// Every arrangement reported, in order.
  final List<Map<String, Offset>> arranged = [];
}

Future<void> _pump(
  WidgetTester tester, {
  _State? state,
  ThemeData? theme,
  Size size = const Size(1200, 900),
  bool disableAnimations = false,
  TextScaler textScaler = TextScaler.noScaling,
  bool withActions = false,
  String orientation = kStructureDefaultOrientation,
}) => _pumpRaw(
  tester,
  _Harness(
    state: state ?? _State(),
    withActions: withActions,
    orientation: orientation,
  ),
  theme: theme,
  size: size,
  disableAnimations: disableAnimations,
  textScaler: textScaler,
);

/// A-1 Chimney on a canvas, with nothing that could save anything.
///
/// No `onMoved` and no `onArranged` on purpose: the zoom controls are drawn for
/// everybody, including somebody who may not rearrange the structure, and
/// pumping it this way is what would fail if they were ever gated on the same
/// permission the move handles are.
/// The window is deliberately small, and that is the premise of every Fit
/// assertion below. Nine places became a 438-point list rather than 2,600
/// points of boxes, which is the whole point of the change — so the chart now
/// fits a desktop window at 100% and a "Fit" tested on a chart that already
/// fits proves nothing. 760 by 560 is about a canvas on a laptop with the
/// sidebar open, and A-1 Chimney's card is taller than the surface it leaves.
Future<void> _pumpA1(
  WidgetTester tester, {
  String orientation = kStructureDefaultOrientation,
  Size size = const Size(760, 560),
}) => _pumpRaw(
  tester,
  StructureCanvas(
    tree: _aOneChimney(),
    franchiseLabel: _franchiseLabel,
    orientation: orientation,
  ),
  size: size,
);

Future<void> _pumpRaw(
  WidgetTester tester,
  Widget canvas, {
  ThemeData? theme,
  Size size = const Size(1200, 900),
  bool disableAnimations = false,
  TextScaler textScaler = TextScaler.noScaling,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: theme ?? EzBiziTheme.light(),
      home: Scaffold(
        // A fresh key every time, so pumping a second canvas is somebody
        // OPENING it again rather than the same State carrying on. A test that
        // reopens the canvas to check what it read out of a saved layout
        // otherwise passes on the state it left behind, which is exactly the
        // reading that would hide the layout never being read at all.
        body: KeyedSubtree(
          key: UniqueKey(),
          child: Builder(
            builder: (context) => MediaQuery(
              data: MediaQuery.of(context).copyWith(
                disableAnimations: disableAnimations,
                textScaler: textScaler,
              ),
              child: canvas,
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

class _Harness extends StatefulWidget {
  const _Harness({
    required this.state,
    this.withActions = false,
    this.orientation = kStructureDefaultOrientation,
  });

  final _State state;

  /// Which way the chart flows, which is the caller's to hold — see
  /// `StructureCanvas.orientation`. Passed through this harness rather than set
  /// on the canvas directly so a test can turn the chart round on the SAME
  /// State, which is what somebody pressing the control actually does.
  final String orientation;

  /// Whether the canvas is given the action menus the real screen gives it.
  ///
  /// Off by default because most of these tests are about the canvas rather
  /// than about what a caller hangs off it — and ON for the ones about the
  /// geometry of a box, because the configuration that actually ships has a
  /// move handle AND an overflow menu inside every box, and a test that
  /// measures a box with neither is measuring something nobody ever sees.
  final bool withActions;

  @override
  State<_Harness> createState() => _HarnessState();
}

class _HarnessState extends State<_Harness> {
  /// Built once and held, the way the screen holds the payload it fetched.
  ///
  /// This is not tidiness. `_tree()` returns a fresh map each call, so building
  /// it inside `build` would hand the canvas a payload with a new identity on
  /// every frame of every drag — which is a caller no real screen resembles,
  /// and which quietly turns the canvas's own "has the tree actually changed"
  /// guard into a no-op. A test whose harness is unrealistic in that direction
  /// stops being able to see anything the guard protects.
  final Map<String, dynamic> _held = _tree();

  @override
  Widget build(BuildContext context) {
    return StructureCanvas(
      tree: _held,
      // Not "Place". The organization's own word for one (§8) is the whole
      // point of the parameter, and a fixture that passed the default word back
      // could not tell a lookup from a hardcode.
      franchiseLabel: _franchiseLabel,
      layout: widget.state.layout,
      orientation: widget.orientation,
      actions: widget.withActions ? _actionsFor : null,
      onMoved: (key, to) => setState(() {
        widget.state.moved.add((key, to));
        widget.state.layout[key] = to;
      }),
      onArranged: (placed) => setState(() {
        widget.state.arranged.add(placed);
        // Merged, never assigned. The layout may hold positions for boxes the
        // canvas cannot see, and an arrangement only names the ones it can.
        widget.state.layout.addAll(placed);
      }),
    );
  }

  /// The menus `organization_screen` actually hangs off a box, built from the
  /// server's own map exactly the way it does.
  Widget? _actionsFor(BuildContext context, StructureNode node) =>
      switch (node.kind) {
        structureCompany => CompanyActionsMenu(
          company: node.raw,
          onEdit: () {},
          onToggleActive: () {},
          onAddFranchise: () {},
          onAddSubCompany: node.isSub ? null : () {},
          onMove: () {},
        ),
        structureFranchise => FranchiseActionsMenu(
          franchise: node.raw,
          onRename: () {},
          onToggle: () {},
        ),
        _ => null,
      };
}

/// One canvas that can be turned round without being rebuilt.
///
/// The whole point is the State: [_pumpRaw] keys every canvas with a fresh
/// [UniqueKey] so that pumping twice is somebody OPENING it again, which is what
/// most of these tests want and is exactly wrong for the one that is about a
/// canvas already on screen being handed a new orientation.
class _Flipper extends StatefulWidget {
  const _Flipper();

  @override
  State<_Flipper> createState() => _FlipperState();
}

class _FlipperState extends State<_Flipper> {
  String _orientation = structureLeftToRight;

  /// Held, for the reason `_HarnessState` holds its own: a fresh map every frame
  /// is a caller no real screen resembles, and it turns the canvas's own "has
  /// the tree actually changed" guard into a no-op.
  final Map<String, dynamic> _held = _tree();

  @override
  Widget build(BuildContext context) {
    return StructureCanvas(
      tree: _held,
      franchiseLabel: _franchiseLabel,
      orientation: _orientation,
      controls: TextButton(
        onPressed: () => setState(
          () => _orientation = _orientation == structureLeftToRight
              ? structureTopToBottom
              : structureLeftToRight,
        ),
        child: const Text('Turn it round'),
      ),
    );
  }
}

// ── the business ───────────────────────────────────────────────────

/// One organization, two top-level companies, one sub company and four places,
/// with one company and one place switched off — enough shape for the layout to
/// be wrong about, and enough state for the switched-off drawing to be tested.
///
/// The keys of the flags are the server's own, from `CompaniesController::index`.
Map<String, dynamic> _tree() => {
  'organization': {
    'id': 'org-1',
    'name': 'Ravenhill Services',
    'may_add_company': true,
    'may_add_company_reason': null,
    'has_logo': false,
    'logo_aspect': null,
    'logo_version': '',
    'may_edit': true,
  },
  'companies': [
    {
      'id': 'c-ever',
      'name': 'Everdoors',
      'is_active': true,
      'parent_company_id': null,
      'may_edit': true,
      'may_add_franchise': true,
      'may_add_franchise_reason': null,
      'may_add_sub_company': true,
      'may_add_sub_company_reason': null,
      'has_logo': false,
      'logo_aspect': null,
      'franchises': [
        {
          'id': 'f-north',
          'name': 'North Shore',
          'timezone': 'America/New_York',
          'currency': 'USD',
          'is_active': true,
          'may_edit': true,
          'may_edit_reason': null,
        },
        {
          'id': 'f-quiet',
          'name': 'Quiet Bay',
          'timezone': 'America/New_York',
          'currency': 'USD',
          'is_active': false,
          'may_edit': true,
          'may_edit_reason': null,
        },
      ],
      'sub_companies': [
        {
          'id': 'c-sun',
          'name': 'Sunward',
          'is_active': false,
          'parent_company_id': 'c-ever',
          'may_edit': true,
          'may_add_franchise': true,
          'may_add_franchise_reason': null,
          'may_add_sub_company': false,
          'may_add_sub_company_reason': null,
          'has_logo': false,
          'logo_aspect': null,
          'franchises': [
            {
              'id': 'f-deep',
              'name': 'Deepwater',
              'timezone': 'America/Chicago',
              'currency': 'USD',
              'is_active': true,
              'may_edit': true,
              'may_edit_reason': null,
            },
          ],
        },
      ],
    },
    {
      'id': 'c-tide',
      'name': 'Tidewater',
      'is_active': true,
      'parent_company_id': null,
      'may_edit': true,
      'may_add_franchise': true,
      'may_add_franchise_reason': null,
      'may_add_sub_company': true,
      'may_add_sub_company_reason': null,
      'has_logo': false,
      'logo_aspect': null,
      'franchises': [
        {
          'id': 'f-harbour',
          'name': 'Harbour Point',
          'timezone': 'America/New_York',
          'currency': 'USD',
          'is_active': true,
          'may_edit': true,
          'may_edit_reason': null,
        },
      ],
      'sub_companies': <Map<String, dynamic>>[],
    },
  ],
};

/// Everdoors, at the shape that exposed the bug.
///
/// One company holding TWO of its own places AND two sub companies that each
/// hold a place of their own. That combination is the whole point: the fixture
/// above has a company with places and a company with a sub company, and a walk
/// that derives a row from its own depth is only wrong when one company has
/// both. It shipped, and the owner found it: "the everdoors franchises and sub
/// company sit at the same level, when they should not".
///
/// Trimmed to what the layout reads — id, name and the two child lists. The §4
/// booleans the other fixture carries are for the action menus, and nothing
/// here draws one.
Map<String, dynamic> _everdoors() => {
  'organization': {'id': 'org-ever', 'name': 'Everdoors Group'},
  'companies': [
    {
      'id': 'c-everdoors',
      'name': 'Everdoors',
      'is_active': true,
      'franchises': [
        {'id': 'f-everdoors-north', 'name': 'Everdoors North'},
        {'id': 'f-everdoors-south', 'name': 'Everdoors South'},
      ],
      'sub_companies': [
        {
          'id': 'c-sunward',
          'name': 'Sunward Overhead Doors',
          'is_active': true,
          'franchises': [
            {'id': 'f-sunward-east', 'name': 'Sunward East'},
          ],
        },
        {
          'id': 'c-premier',
          'name': 'Premier Garage Repair',
          'is_active': true,
          'franchises': [
            {'id': 'f-premier-west', 'name': 'Premier West'},
          ],
        },
      ],
    },
  ],
};

/// A1-Group, exactly as the business stands.
///
/// ── The data the whole change was made for ───────────────────────
///
/// Thirteen places under four companies: nine under A-1 Chimney, two under
/// Everdoors, and one under each of Everdoors' two sub companies. Thirteen
/// boxes side by side is about 3,800 points, which is wider than any window
/// that exists, and the owner's screenshots showed the tab opening on a
/// fragment of its own chart with nothing on screen saying so. That is why a
/// place stopped being a box and why the chart flows left to right.
///
/// It is a fixture of its own rather than a bigger version of [_aOneChimney]
/// because it is the only shape with BOTH a company that is tall with places and
/// a company that is deep with sub companies. Every arithmetic mistake the
/// layout can make shows up where those two compete for room, and neither of the
/// other two fixtures has both.
Map<String, dynamic> _a1Group() => {
  'organization': {'id': 'a1-group', 'name': 'A1-Group'},
  'companies': [
    {
      'id': 'a-1-chimney',
      'name': 'A-1 Chimney',
      'is_active': true,
      'franchises': [
        for (final name in _chimneyPlaces)
          {'id': 'chimney-$name', 'name': name},
      ],
      'sub_companies': <Map<String, dynamic>>[],
    },
    {
      'id': 'everdoors',
      'name': 'Everdoors',
      'is_active': true,
      'franchises': [
        {'id': 'everdoors-connecticut', 'name': 'Connecticut'},
        {'id': 'everdoors-dmv', 'name': 'DMV'},
      ],
      'sub_companies': [
        {
          'id': 'premier',
          'name': 'Premier Garage Repair',
          'is_active': true,
          'franchises': [
            {'id': 'premier-connecticut', 'name': 'Connecticut'},
          ],
        },
        {
          'id': 'speedy',
          'name': 'Speedy Garage Doors',
          'is_active': true,
          'franchises': [
            {'id': 'speedy-connecticut', 'name': 'Connecticut'},
          ],
        },
      ],
    },
  ],
};

/// A-1 Chimney's nine, in the order the server sends them.
const List<String> _chimneyPlaces = [
  'connecticut',
  'dmv',
  'georgia',
  'illinois',
  'nationwide',
  'new-jersey',
  'ohio',
  'texas',
  'washington',
];

/// A-1 Chimney: nine places, no sub companies anywhere.
///
/// The other real customer, and the one the level rule has a visible
/// consequence for — its sub-company line is empty and all nine places sit on
/// the fourth. That is the intended picture rather than a rendering fault, and
/// it is worth a fixture of its own because nine of one thing is where a layout
/// that quietly wraps or collapses a level shows itself.
Map<String, dynamic> _aOneChimney() => {
  'organization': {'id': 'org-a1', 'name': 'A-1 Chimney'},
  'companies': [
    {
      'id': 'c-a1',
      'name': 'A-1 Chimney',
      'is_active': true,
      'franchises': [
        for (var i = 1; i <= 9; i++) {'id': 'f-a1-$i', 'name': 'Branch $i'},
      ],
      'sub_companies': <Map<String, dynamic>>[],
    },
  ],
};
