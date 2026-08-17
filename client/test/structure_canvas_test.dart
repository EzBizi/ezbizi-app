import 'dart:math' as math;

import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/structure_canvas.dart';
import 'package:ezbizi_client/widgets/structure_menu.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
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

      // Control first. If every box drew the same glyph this test would still
      // find four boxes, and the assertion below would be about nothing.
      final glyphs = {
        for (final key in _allKeys)
          key: _iconIn(tester, key),
      };

      expect(
        glyphs.values.toSet(),
        hasLength(4),
        reason: 'The four levels share glyphs, so the icon tells nobody '
            'anything.',
      );

      final frames = {
        for (final key in _allKeys) key: _decorationOf(tester, key),
      };

      // The whole business is the heaviest and squarest thing on the canvas; a
      // place is round like the chip it is drawn as on the list tab.
      expect(_radiusOf(frames['organization:org-1']!), 16);
      expect(_radiusOf(frames['company:c-ever']!), 12);
      expect(_radiusOf(frames['franchise:f-north']!), 26);

      expect(_borderOf(frames['organization:org-1']!).width, 2);
      expect(_borderOf(frames['company:c-ever']!).width, 1.5);
      expect(_borderOf(frames['company:c-sun']!).width, 1);
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

      final from = tester.getCenter(find.byKey(structureBoxKey('franchise:f-north')));
      final onto = tester.getCenter(find.byKey(structureBoxKey('company:c-sun')));

      await tester.drag(
        find.byKey(structureBoxKey('franchise:f-north')),
        onto - from,
      );
      await tester.pumpAndSettle();

      // Everything the canvas said, said about one box's position and nothing
      // else. There is no second callback and no payload with a parent in it,
      // because there is nowhere for one to go.
      expect(state.moved.map((move) => move.$1).toSet(), {'franchise:f-north'});

      // And the picture still says the same thing it did before: the place is
      // under Everdoors, and the line drawn to it comes from Everdoors.
      expect(
        _semanticsOf(tester, 'franchise:f-north'),
        'North Shore — $_franchiseLabel, under Everdoors',
      );

      final links = _painter(tester).links;

      expect(links, hasLength(_allKeys.length - 1), reason: 'A connector was '
          'dropped when two boxes overlapped.');

      // The connector for the dragged place now runs from Everdoors' box to a
      // rectangle sitting on top of Sunward's — which is the point. It did not
      // detach, and it did not re-aim.
      final everdoors = _paintedRectOf(tester, 'company:c-ever');
      final moved = _paintedRectOf(tester, 'franchise:f-north');

      expect(
        links.any((link) => link.$1 == everdoors && link.$2 == moved),
        isTrue,
        reason: 'The line stopped pointing at the parent it belongs to.',
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
        tester.getCenter(find.byKey(structureBoxKey('franchise:f-north'))),
      );

      await finger.moveTo(
        tester.getCenter(find.byKey(structureBoxKey('company:c-sun'))),
      );
      await tester.pump();

      // The box in hand may show that it is in hand. Nothing it is passing OVER
      // may show anything at all.
      expect(
        _highlighted(tester, scheme.primary)
            .where((key) => key != 'franchise:f-north'),
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
        find.byKey(structureBoxKey('franchise:f-north')),
      );
      final onto = tester.getCenter(
        find.byKey(structureBoxKey('company:c-sun')),
      );

      await tester.drag(
        find.byKey(structureBoxKey('franchise:f-north')),
        onto - from,
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('“North Shore” has not been moved into “Sunward”'),
        findsOneWidget,
        reason:
            'Both names, so it is about the two boxes on screen rather than a '
            'general notice somebody has to work out the relevance of.',
      );
      expect(
        find.textContaining(
          'moving it now would make the past look like it happened somewhere '
          'else',
        ),
        findsOneWidget,
        reason:
            'The actual reason, in a business owner\'s words. "You cannot '
            're-parent an entity" is the model\'s vocabulary and answers '
            'nothing.',
      );

      expect(find.byType(Dialog), findsNothing);
      expect(find.byType(AlertDialog), findsNothing);
      expect(
        find.byType(SnackBar),
        findsNothing,
        reason:
            'A note above the picture, never a pop-up and never something that '
            'slides away while it is being read. It interrupts nothing and it '
            'covers neither of the two boxes it is about.',
      );

      // And not one thing about the picture is different because of it.
      expect(
        _semanticsOf(tester, 'franchise:f-north'),
        'North Shore — $_franchiseLabel, under Everdoors',
        reason: 'The box changed what it says it hangs off.',
      );
      expect(state.moved.map((move) => move.$1).toSet(), {'franchise:f-north'});
      expect(
        _painter(tester).links,
        hasLength(_allKeys.length - 1),
        reason: 'A connector was dropped, or a second one invented.',
      );

      await tester.tap(find.text('Got it'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('has not been moved into'),
        findsNothing,
        reason:
            'Clearable, because it is about a gesture that has finished. A '
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

      // Starting just clear of Sunward: overlapping by a third of its own
      // height, which is deliberately UNDER the half this raises at. So the
      // note below is about the press rather than about where the box began.
      final state = _State(
        layout: {
          'franchise:f-north': _auto['company:c-sun']! - const Offset(0, 48),
        },
      );

      await _pump(tester, state: state);

      expect(find.textContaining('has not been moved into'), findsNothing);

      await tester.tap(find.byTooltip('Move “North Shore” with the arrow keys'));
      await tester.pumpAndSettle();

      // One press of 16 takes the overlap past half.
      await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
      await tester.pumpAndSettle();

      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await tester.pumpAndSettle();

      expect(
        find.textContaining('“North Shore” has not been moved into “Sunward”'),
        findsOneWidget,
      );
      expect(
        said.last,
        contains('has not been moved into'),
        reason:
            'And said out loud. A sentence that only exists as a strip on a '
            'canvas is an explanation for people who can see the canvas, which '
            'is not who most needs one.',
      );
    });

    testWidgets('the box says move, not grab', (tester) async {
      await _pump(tester);

      final region = tester.widget<MouseRegion>(
        find.descendant(
          of: find.byKey(structureBoxKey('company:c-ever')),
          matching: find.byType(MouseRegion),
        ).first,
      );

      // "grab" is pick this up and carry it somewhere. "move" is reposition it.
      expect(region.cursor, SystemMouseCursors.move);
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
      expect(find.byTooltip('Move “Everdoors” with the arrow keys'), findsNothing);
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

    /// A company with no sub companies has its places two lines below it, and
    /// the line between them has to read as a line rather than as a fault.
    ///
    /// Tidewater in the fixture is that company: one place, no sub companies.
    /// The painter needs no case for it — the run is out of the bottom, down to
    /// the midpoint, across, and into the top, which is the same route it draws
    /// for every other pair — and this exists so that nobody adds one.
    testWidgets('a connector across a skipped line still joins the two boxes', (
      tester,
    ) async {
      await _pump(tester);

      final company = _paintedRectOf(tester, 'company:c-tide');
      final place = _paintedRectOf(tester, 'franchise:f-harbour');

      expect(
        _painter(tester).links.any(
          (link) => link.$1 == company && link.$2 == place,
        ),
        isTrue,
        reason: 'The place two lines below its company has no line to it, so '
            'the gap reads as something that failed to draw.',
      );

      // Two pitches apart rather than one, measured edge to edge: the run is a
      // whole empty line longer than the ones beside it, and that length is the
      // picture saying this branch has no sub companies.
      expect(
        place.top - company.bottom,
        moreOrLessEquals(2 * _pitch - kStructureBoxHeight, epsilon: 0.5),
      );

      // Control, and it is the half that makes the number above mean something:
      // a place hanging off a SUB company is one pitch below it, so the
      // measurement is about the skipped line rather than about the geometry.
      expect(
        _paintedRectOf(tester, 'franchise:f-deep').top -
            _paintedRectOf(tester, 'company:c-sun').bottom,
        moreOrLessEquals(_pitch - kStructureBoxHeight, epsilon: 0.5),
      );
    });

    testWidgets('a switched-off company keeps its connector', (tester) async {
      await _pump(tester);

      final links = _painter(tester).links;

      // One per node except the organization, which hangs off nothing. Sunward
      // is switched off in the fixture and its line is still counted.
      expect(links, hasLength(_allKeys.length - 1));

      final everdoors = _paintedRectOf(tester, 'company:c-ever');
      final sunward = _paintedRectOf(tester, 'company:c-sun');
      final deepwater = _paintedRectOf(tester, 'franchise:f-deep');

      // The line INTO the switched-off company, at full strength...
      expect(
        links.any((link) => link.$1 == everdoors && link.$2 == sunward),
        isTrue,
        reason: 'The relationship is not switched off; the company is.',
      );

      // ...and the line out of it to the place it still owns. Greying or
      // dropping either would say the structure changed when a switch was
      // flipped, and it did not.
      expect(
        links.any((link) => link.$1 == sunward && link.$2 == deepwater),
        isTrue,
      );
    });
  });

  group('arrange for me', () {
    testWidgets('places every box, with none on top of another', (
      tester,
    ) async {
      final state = _State(
        // Everything piled at the origin, which is what a layout looks like
        // after a bad write and what the button exists to rescue.
        layout: {for (final key in _allKeys) key: Offset.zero},
      );

      await _pump(tester, state: state);

      // Control: they really are piled up to begin with, otherwise the
      // assertion after the press is about a canvas that was already tidy.
      expect(_rectOf(tester, 'company:c-ever'), _rectOf(tester, 'company:c-sun'));

      await tester.tap(find.text('Arrange for me'));
      await tester.pumpAndSettle();

      expect(state.arranged, hasLength(1));
      expect(state.arranged.single.keys.toSet(), _allKeys.toSet());

      final rects = [for (final key in _allKeys) _rectOf(tester, key)];

      for (var i = 0; i < rects.length; i++) {
        for (var j = i + 1; j < rects.length; j++) {
          expect(
            rects[i].overlaps(rects[j]),
            isFalse,
            reason: '${_allKeys[i]} and ${_allKeys[j]} were laid on top of '
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
      // The trap `panelsInSavedOrder` names, in canvas form: make the saved
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
      expect(_allKeys.map((key) => find.byKey(structureBoxKey(key))),
          everyElement(findsOneWidget));
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
        layout: const {'franchise:f-deep': Offset(0, 0)},
      );

      await _pump(tester, state: state);

      final walked = <String>[];

      for (var press = 0; press < 24 && walked.length < _allKeys.length; press++) {
        await tester.sendKeyEvent(LogicalKeyboardKey.tab);
        await tester.pump();

        final focused = _focusedBox(tester);

        if (focused != null && (walked.isEmpty || walked.last != focused)) {
          walked.add(focused);
        }
      }

      expect(walked, _allKeys);
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
        _painter(tester).links.any(
          (link) => link.$1 == _paintedRectOf(tester, 'organization:org-1'),
        ),
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
        state: _State(layout: {for (final key in _allKeys) key: Offset.zero}),
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();

      final travelling = _rectOf(tester, 'franchise:f-deep');

      await tester.pumpAndSettle();

      final arrived = _rectOf(tester, 'franchise:f-deep');

      expect(travelling, isNot(arrived));

      await _pump(
        tester,
        state: _State(layout: {for (final key in _allKeys) key: Offset.zero}),
        disableAnimations: true,
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();

      expect(
        _rectOf(tester, 'franchise:f-deep'),
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

      await pad.panZoomStart(tester.getCenter(
        find.byKey(structureBoxKey('company:c-ever')),
      ));
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
      expect(
        _painter(tester).links.any((link) => link.$2 == before),
        isTrue,
      );

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
        _painter(tester).links.any((link) => link.$2 == after),
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
        state: _State(layout: {for (final key in _allKeys) key: Offset.zero}),
      );

      await tester.tap(find.text('Arrange for me'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));

      final travelling = _paintedRectOf(tester, 'franchise:f-deep');
      final arrived = () {
        // Where it ends up, so the frame above can be shown to be a frame in
        // the middle of the journey rather than the end of it.
        return _auto['franchise:f-deep']!;
      }();

      expect(
        travelling.topLeft,
        isNot(offsetMoreOrLessEquals(arrived, epsilon: 1)),
        reason: 'The premise: 60ms in, this box is still on its way.',
      );
      expect(
        _painter(tester).links.any((link) => link.$2 == travelling),
        isTrue,
        reason: 'The line to this box was drawn where the box is going rather '
            'than where it is, so for a fifth of a second it ends in mid air.',
      );

      await tester.pumpAndSettle();
    });

    /// A box is two lines of text, and text follows the reader's font size.
    /// 150% is an ordinary accessibility setting on the tablets this view is
    /// served to, and the box used to stay 72 points tall through all of it.
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
    test('a malformed payload is read for what it has rather than thrown on',
        () {
      expect(flattenStructure(const {}), isEmpty);
      expect(flattenStructure(const {'organization': 'nonsense'}), isEmpty);
      expect(flattenStructure(const {'companies': 'nonsense'}), isEmpty);

      final partial = flattenStructure(const {
        'organization': {'id': 'o', 'name': 'O'},
        'companies': [
          'nonsense',
          {'id': 'c', 'name': 'C', 'franchises': [
            {'id': 'f', 'name': 'F'},
            {'id': 7, 'name': 'skipped'},
            'nonsense',
          ]},
          {'name': 'no id at all'},
        ],
      });

      expect([for (final node in partial) node.id], ['o', 'c', 'f']);
    });

    /// A node with no id would otherwise be dropped while its children were
    /// promoted to the top of the canvas — drawing a sub company as a sibling
    /// of its own parent, which is the exact bug the nested payload prevents.
    test('a company that cannot be read takes its children with it', () {
      final orphaned = flattenStructure(const {
        'companies': [
          {'name': 'no id', 'franchises': [
            {'id': 'f', 'name': 'F'},
          ]},
        ],
      });

      expect(orphaned, isEmpty);
    });
  });

  group('arranging it automatically', () {
    /// This assertion used to say the opposite, and the opposite was the bug.
    ///
    /// A row was passed down the recursion, so a company's own place came out
    /// on the same line as a sub company of the same company — the picture
    /// saying the two are peers. They are not: a sub company holds places of
    /// its own. A row is what a box IS.
    test('a row is what a box IS, not how deep the walk went', () {
      final nodes = flattenStructure(_tree());
      final places = arrangeStructure(nodes);

      double rowOf(String key) => places[key]!.dy;

      expect(
        rowOf('franchise:f-north'),
        greaterThan(rowOf('company:c-sun')),
        reason: 'A place of the company is level with a sub company of the '
            'same company, which draws them as the same size of thing.',
      );

      // Every place on one line, whichever kind of company owns it. That is the
      // owner's fourth line, and it is the half a walk-derived row cannot say.
      expect(rowOf('franchise:f-north'), rowOf('franchise:f-deep'));
      expect(rowOf('franchise:f-north'), rowOf('franchise:f-harbour'));

      // And the three lines above it, in order.
      expect(rowOf('organization:org-1'), lessThan(rowOf('company:c-ever')));
      expect(rowOf('company:c-ever'), lessThan(rowOf('company:c-sun')));
      expect(rowOf('company:c-sun'), lessThan(rowOf('franchise:f-deep')));
    });

    /// The real customer, at the shape that exposed it: a company with places
    /// of its OWN and sub companies with places of theirs, all hanging off one
    /// company. Everything before this passed on a fixture where no company had
    /// both.
    test('Everdoors: a place of the company and a place of a sub company share '
        'the fourth line, and a sub company sits on the line above both', () {
      final places = arrangeStructure(flattenStructure(_everdoors()));

      final companyPlace = places['franchise:f-everdoors-north']!.dy;
      final subPlace = places['franchise:f-sunward-east']!.dy;
      final sub = places['company:c-sunward']!.dy;
      final company = places['company:c-everdoors']!.dy;

      expect(companyPlace, subPlace);
      expect(sub, lessThan(companyPlace));
      expect(company, lessThan(sub));

      // Exactly one pitch between each, so "above" is a LINE rather than a
      // nudge — and so a picture that merely sorted the boxes vertically
      // without putting them on lines cannot pass this.
      expect(sub - company, _pitch);
      expect(companyPlace - sub, _pitch);

      // The second sub company's place lands on the same line as the first's,
      // which is what makes the claim about the level rather than about one
      // branch of the tree.
      expect(places['franchise:f-premier-west']!.dy, companyPlace);
    });

    /// The consequence, and it is intended rather than tolerated. A-1 Chimney
    /// has nine places and no sub companies at all, so the sub-company line is
    /// empty beneath its company and every place is on one line — which is
    /// exactly what somebody reading an org chart expects, and why the canvas's
    /// standing note names the empty line in words.
    test('A-1: nine places on one line, with the sub-company line left empty',
        () {
      final places = arrangeStructure(flattenStructure(_aOneChimney()));

      final lines = {
        for (final entry in places.entries)
          if (entry.key.startsWith('franchise:')) entry.value.dy,
      };

      expect(lines, hasLength(1), reason: 'Nine places on more than one line.');

      // The FOURTH line, counted from the top of the picture — which is the
      // half that says the third was skipped rather than simply unused. The
      // margin is where the whole picture starts; three pitches below it is
      // where a place belongs.
      expect(lines.single, kStructureMargin + 3 * _pitch);

      // And nothing at all is drawn on the line above it.
      expect(
        places.values.where((at) => at.dy == kStructureMargin + 2 * _pitch),
        isEmpty,
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
      expect(arrangeStructure(nodes), arrangeStructure(flattenStructure(_tree())));
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

/// One line to the next: a box and the gap under it.
///
/// Built from the widget's own two constants rather than typed as 156, because
/// what these assertions are about is boxes sitting exactly ONE line apart —
/// not about the line being any particular number of points. Somebody who
/// changes the gap should not have to edit a test that has no opinion about it.
const double _pitch = kStructureBoxHeight + kStructureGapY;

/// Every box the fixture draws, in tree order — which is also the order Tab has
/// to walk them in.
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

/// Every widget whose type is named [name], whatever it was parameterised with.
Finder _byTypeName(String name) => find.byWidgetPredicate((widget) {
  final type = widget.runtimeType.toString();

  return type == name || type.startsWith('$name<');
});

Rect _rectOf(WidgetTester tester, String key) =>
    tester.getRect(find.byKey(structureBoxKey(key)));

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
Offset _emptyPatch(WidgetTester tester) {
  final boxes = [for (final key in _allKeys) _rectOf(tester, key)];
  final lowest = boxes.map((box) => box.bottom).reduce(math.max);
  final patch = Offset(60, lowest + 40);

  expect(
    boxes.where((box) => box.contains(patch)),
    isEmpty,
    reason: 'The patch this test pans from has a box on it, so it is about to '
        'measure a box drag and call it a viewport pan.',
  );

  return patch;
}

/// A box's rectangle in the canvas's own coordinates rather than the window's.
///
/// The painter is handed rectangles relative to the drawing surface, which
/// starts below the toolbar. Comparing a window rectangle against one of those
/// is the kind of test that fails by a constant and sends somebody looking for
/// a bug in the geometry.
Rect _paintedRectOf(WidgetTester tester, String key) =>
    _rectOf(tester, key).shift(-_canvasOrigin(tester));

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

BoxDecoration _decorationOf(WidgetTester tester, String key) => tester
    .widget<Container>(
      find
          .descendant(
            of: find.byKey(structureBoxKey(key)),
            matching: find.byType(Container),
          )
          .first,
    )
    .decoration! as BoxDecoration;

double _radiusOf(BoxDecoration decoration) =>
    ((decoration.borderRadius! as BorderRadius).topLeft).x;

BorderSide _borderOf(BoxDecoration decoration) =>
    (decoration.border! as Border).top;

/// The keys of every box currently drawing its frame in [colour].
List<String> _highlighted(WidgetTester tester, Color colour) => [
  for (final key in _allKeys)
    if (_borderOf(_decorationOf(tester, key)).color == colour) key,
];

String? _semanticsOf(WidgetTester tester, String key) => tester
    .getSemantics(find.byKey(structureBoxKey(key)))
    .label;

/// The box that currently holds focus, by its node key.
///
/// Read off the Focus widget rather than a debug label, because the key is the
/// thing the canvas actually promises and a debug label is not.
String? _focusedBox(WidgetTester tester) {
  for (final key in _allKeys) {
    final focus = tester.widget<Focus>(find.byKey(structureBoxKey(key)));

    if (focus.focusNode?.hasPrimaryFocus == true) return key;
  }

  return null;
}

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

  tester.binding.defaultBinaryMessenger
      .setMockDecodedMessageHandler<Object?>(SystemChannels.accessibility, (
    message,
  ) async {
    if (message is Map && message['type'] == 'announce') {
      said.add((message['data'] as Map)['message'] as String);
    }

    return null;
  });

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
  _State({Map<String, Offset> layout = const {}})
      : layout = {...layout};

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
}) =>
    _pumpRaw(
      tester,
      _Harness(state: state ?? _State(), withActions: withActions),
      theme: theme,
      size: size,
      disableAnimations: disableAnimations,
      textScaler: textScaler,
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
  const _Harness({required this.state, this.withActions = false});

  final _State state;

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
        ),
        structureFranchise => FranchiseActionsMenu(
          franchise: node.raw,
          onRename: () {},
          onToggle: () {},
        ),
        _ => null,
      };
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
