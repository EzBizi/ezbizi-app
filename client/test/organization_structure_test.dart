import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/organization/structure_tab.dart';
import 'package:ezbizi_client/screens/organization_screen.dart';
import 'package:ezbizi_client/widgets/async_body.dart' show PanelHeader;
import 'package:ezbizi_client/widgets/structure_canvas.dart';
import 'package:ezbizi_client/widgets/structure_outline.dart';
import 'package:flutter/gestures.dart' show PointerDeviceKind;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Organization tab's two views, and the one thing that writes.
///
/// The canvas and the outline have their own test files and are not retested
/// here. What this file is about is the WIRING: that the second view exists and
/// is reachable, that the right one is chosen for the width, that an arrangement
/// makes exactly one request and the right one, and that a refusal is neither
/// swallowed nor allowed to look like a success.
///
/// The last of those is the reason several assertions below state their premise
/// before their claim. A test that only checks "the box is at 600" passes
/// against a canvas that ignores the stored arrangement entirely and happens to
/// auto-place it near there, so the control — the same box, same tree, no stored
/// arrangement — is asserted first and in the same test, where it cannot drift
/// away from the claim it is protecting.
void main() {
  group('the two views', () {
    testWidgets('the Organization tab offers a list and a structure', (
      tester,
    ) async {
      await _pump(tester, _server());

      expect(find.text('List'), findsOneWidget);
      expect(find.text('Structure'), findsOneWidget);
      // Scoped to the header: the List view's right pane now carries an
      // "Organization" pill on the picked node, which is a label on that node
      // and not a heading for the page (UI queue 111).
      expect(
        find.descendant(
          of: find.byType(PanelHeader),
          matching: find.text('Organization'),
        ),
        findsNothing,
        reason:
            'No heading of its own: the Settings chip above names it (UI '
            'queue 110). Never one per view, which would read as two screens '
            'rather than as two pictures of one page.',
      );
    });

    testWidgets('the structure is not built until somebody opens it', (
      tester,
    ) async {
      final calls = <String>[];

      await _pump(tester, _server(calls: calls));

      // skipOffstage: false is what makes this assertion mean anything. The
      // unopened tab's slot is offstage, and the default finder ignores
      // offstage widgets — so a canvas that was eagerly BUILT and merely hidden
      // would satisfy the plain form of this check while doing all the work it
      // is supposed to be avoiding.
      expect(
        find.byType(StructureCanvas, skipOffstage: false),
        findsNothing,
        reason:
            'The canvas lays out every box in the business and reads a '
            'preference to do it. Somebody who only ever looks at the list '
            'must pay for none of that.',
      );

      final before = calls.length;

      await _openStructure(tester);

      expect(find.byType(StructureCanvas), findsOneWidget);
      expect(
        calls.length,
        before,
        reason:
            'The tree the list already fetched is handed straight to the '
            'canvas. A second read endpoint for the structure is a second '
            'answer to "what exists".',
      );
    });

    testWidgets('a wide window draws the canvas', (tester) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(find.byType(StructureCanvas), findsOneWidget);
      expect(find.byType(StructureOutline), findsNothing);
    });

    testWidgets('a narrow window draws the outline instead', (tester) async {
      await _pump(tester, _server(), size: const Size(375, 900));
      await _openStructure(tester);

      expect(
        find.byType(StructureOutline),
        findsOneWidget,
        reason:
            'A canvas earns its keep when a parent and two of its children fit '
            'on screen together. Below that the pan is the only way to see '
            'anything, and a picture you have to navigate is not a picture.',
      );
      expect(find.byType(StructureCanvas), findsNothing);
      expect(
        find.text('Arrange for me'),
        findsNothing,
        reason:
            'Absent, not disabled. A disabled control invites somebody to work '
            'out why it does nothing.',
      );
      expect(
        find.textContaining('too narrow'),
        findsOneWidget,
        reason:
            'Somebody told the boxes can be dragged, looking at a list that '
            'cannot be, is owed the reason and the remedy.',
      );
      expect(
        find.textContaining('sidebar'),
        findsOneWidget,
        reason:
            'And the remedy has to be one that works from where they are '
            'standing. The shell shows its sidebar at a window width of 900, '
            'so dragging a window from 899 to 900 takes 180-420 points off '
            'this tab: "open it in a wider window" is advice that removes the '
            'canvas, and stays wrong for the next 80 to 400 points of '
            'widening. The sidebar’s width is theirs and it collapses.',
      );
    });

    /// Width alone used to decide this, and a phone held sideways is 812 by
    /// 375: wide enough for the canvas and short enough that the drawing
    /// surface came out 29 points tall, so not one box fitted. A blank grey
    /// slab with a live "Arrange for me" over it — and pressing that, which is
    /// what somebody does when a screen looks broken, writes the automatic
    /// layout over the arrangement they made at a desk.
    testWidgets('a wide but short window draws the outline too', (tester) async {
      await _pump(tester, _server(), size: const Size(812, 375));
      await _openStructure(tester);

      expect(find.byType(StructureOutline), findsOneWidget);
      expect(find.byType(StructureCanvas), findsNothing);
      expect(
        find.text('Arrange for me'),
        findsNothing,
        reason:
            'The outline is refused this button so a phone cannot flatten a '
            'desktop arrangement. Rotating the phone was a way round it.',
      );
      expect(find.textContaining('too short'), findsOneWidget);
    });

    testWidgets('both views show the whole structure', (tester) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      for (final name in const [
        'Everdoors',
        'Sunward Overhead Doors',
        'Sunward Commercial',
        'Northside',
        'Quietside',
      ]) {
        expect(
          find.text(name),
          findsWidgets,
          reason:
              'The two tabs draw one payload. A node on the list and missing '
              'from the canvas means they have come to disagree about what '
              'exists.',
        );
      }
    });

    /// §8, wired. The organization in this fixture calls a place a Territory,
    /// and the tab has to hand that word to whichever view is drawing — the
    /// canvas used to write "Place" on every box while the outline one window
    /// size away wrote "Territory".
    testWidgets('a place is called the organization’s own word for one, in '
        'both views', (tester) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        find.descendant(
          of: find.byKey(structureBoxKey('franchise:$_north')),
          matching: find.text('Territory'),
        ),
        findsOneWidget,
      );
      expect(find.text('Place'), findsNothing);

      // The same nodes, drawn by the other view, saying the same word.
      await _pump(tester, _server(), size: const Size(375, 900));
      await _openStructure(tester);

      expect(find.textContaining('Territory in Everdoors'), findsWidgets);
    });

    /// `CompaniesController::index` puts the timezone and the currency on every
    /// franchise it sends, and all three views read that one untouched map —
    /// which is also where the §4 booleans are read from. The menu's own
    /// comment used to claim a canvas box carried no such payload.
    testWidgets('a place’s menu carries its facts on the canvas too', (
      tester,
    ) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        find.descendant(
          of: find.byKey(structureBoxKey('franchise:$_north')),
          matching: find.byTooltip('America/New_York · USD'),
        ),
        findsOneWidget,
      );
    });
  });

  group('moving a company', () {
    /// Jose, 2026-09-14: "the ability to convert a company into a sub company
    /// or viceversa", after creating Crestline Home as a company when it should
    /// have been a sub company.
    testWidgets('a company can be made a sub company of another', (
      tester,
    ) async {
      final moves = <Map<String, dynamic>>[];

      await _pump(tester, _server(moves: moves));

      // Sunward Overhead Doors is top-level in the fixture, so the only
      // destination offered is the other top-level company. Its own place is
      // deliberately not in the list: choosing where it already is would be the
      // one option that does nothing.
      // Picked in the tree, then its menu on its own page (UI queue 111).
      await _pickInTree(tester, 'company', _sun);
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('org-detail')),
          matching: find.byIcon(Icons.more_horiz),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('Change what it sits under'));
      await tester.pumpAndSettle();

      expect(
        find.text('Top level, under Everdoors Group'),
        findsNothing,
        reason:
            'It is already at the top level. Offering that is a choice that '
            'moves nothing and reports "Moved".',
      );
      expect(find.text('Currently at the top level.'), findsOneWidget);

      await tester.tap(find.text('Under Everdoors'));
      await tester.pumpAndSettle();

      expect(moves, hasLength(1));
      expect(moves.single['path'], endsWith('/companies/$_sun/parent'));
      expect(
        (moves.single['body'] as Map<String, dynamic>)['parent_company_id'],
        _ever,
        reason:
            'Its own route, not a key on the ordinary PATCH — the server '
            'refuses parent_company_id there outright.',
      );
    });

    /// Jose's second case, 2026-09-14: "chimney star which is a sub company of
    /// a1 chimney, to be reassigned as a sub company of everdoors". It is the
    /// same route and needs nothing new — which is exactly why it is worth a
    /// test, because nothing would notice if the picker quietly stopped
    /// offering the sideways move.
    testWidgets('a sub company is offered the top AND another parent', (
      tester,
    ) async {
      final moves = <Map<String, dynamic>>[];

      await _pump(tester, _server(moves: moves));

      // Picked in the tree, then its menu on its own page (UI queue 111).
      await _pickInTree(tester, 'company', _commercial);
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('org-detail')),
          matching: find.byIcon(Icons.more_horiz),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('Change what it sits under'));
      await tester.pumpAndSettle();

      expect(find.text('Top level, under Everdoors Group'), findsOneWidget);

      // Not its current parent, which is where it already is -- and the one
      // line that says so, because an absence is not a label.
      expect(find.text('Under Everdoors'), findsNothing);
      expect(find.text('Currently under Everdoors.'), findsOneWidget);

      // Sideways: from Everdoors to the other top-level company, without
      // passing through the top level first.
      await tester.tap(find.text('Under Sunward Overhead Doors'));
      await tester.pumpAndSettle();

      expect(moves, hasLength(1));
      expect(moves.single['path'], endsWith('/companies/$_commercial/parent'));
      expect(
        (moves.single['body'] as Map<String, dynamic>)['parent_company_id'],
        _sun,
      );
    });
  });

  group('the shared arrangement', () {
    testWidgets('is where the boxes are when the tab opens', (tester) async {
      // The control, first and in the same test. Without it this passes
      // against a canvas that never reads the arrangement at all and happens to
      // put these two boxes roughly here on its own.
      await _pump(tester, _server());
      await _openStructure(tester);

      final auto = _delta(tester);

      expect(
        auto,
        isNot(const Offset(600, 300)),
        reason:
            'The automatic layout must not already produce the offset the '
            'stored arrangement is about to ask for, or the claim below is '
            'about nothing.',
      );

      await _pump(
        tester,
        _server(
          canvas: [
            _box('company', _sun, 0, 0),
            _box('organization', _org, 600, 300),
          ],
        ),
      );
      await _openStructure(tester);

      expect(
        _delta(tester),
        const Offset(600, 300),
        reason:
            'The arrangement rides in on the tree payload, beside the '
            'structure it describes. Nothing here waits on a second request '
            'before the boxes can be drawn where they belong.',
      );
    });

    /// The half of change (1) that a test reading only the tree cannot see. The
    /// old store was `users.preferences['organization.canvas']`, cached in the
    /// session at launch — so a client that still reads it would draw one
    /// person's stale private picture and never show a colleague's at all, and
    /// would look perfectly correct until two people opened the tab.
    testWidgets('comes from the tree, and the old private copy is ignored', (
      tester,
    ) async {
      await _pump(
        tester,
        _server(
          // What the tree says: the organization's arrangement, everybody's.
          canvas: [
            _box('company', _sun, 0, 0),
            _box('organization', _org, 600, 300),
          ],
          // What this person's own account still happens to carry from before
          // the arrangement was shared. It names the same two boxes and puts
          // them somewhere else entirely, so a client reading it cannot pass
          // by coincidence.
          preferences: {
            'organization.canvas': [
              _box('company', _sun, 0, 0),
              _box('organization', _org, 120, 40),
            ],
          },
        ),
      );
      await _openStructure(tester);

      expect(
        _delta(tester),
        const Offset(600, 300),
        reason:
            'The arrangement is one organization\'s setting now, not one '
            'person\'s preference. /me is answered once at launch and cached, '
            'so a colleague\'s save read from there would not appear until the '
            'next sign-in.',
      );
    });

    testWidgets('a box it has never heard of is still drawn', (tester) async {
      await _pump(
        tester,
        _server(canvas: [_box('organization', _org, 0, 0)]),
      );
      await _openStructure(tester);

      // The trap the dashboard's saved order names: making the stored
      // arrangement the whole truth is what makes every company added after it
      // invisible to everybody who has ever rearranged.
      expect(find.byKey(structureBoxKey('company:$_sun')), findsOneWidget);
      expect(find.byKey(structureBoxKey('franchise:$_quiet')), findsOneWidget);
      expect(
        find.textContaining('have not been placed by you'),
        findsOneWidget,
        reason:
            'The drift is stated rather than left for somebody to notice. '
            'Honest states, no dead ends.',
      );
    });

    testWidgets('says nothing about drift on a first visit', (tester) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        find.textContaining('not been placed by you'),
        findsNothing,
        reason:
            'Nothing is stored, so every box is at its automatic place. That '
            'is a coherent picture rather than a mixed one, and there is '
            'nothing to explain.',
      );
    });

    /// The note used to say the untouched boxes were NEW. They are not: the
    /// client sends what it holds and the server stores what it is sent, so
    /// dragging one box writes an arrangement of one box — and on this fixture
    /// that produced "5 boxes are new since you last arranged this" said to
    /// somebody who had moved one box and changed nothing about the business.
    /// It said it on every visit, and the only way to silence it was to press
    /// the escape hatch, which overwrites the placement they had just made.
    testWidgets('dragging one box does not make the other three new', (
      tester,
    ) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(120, 60),
      );
      await _settle(tester);

      expect(
        find.textContaining('new since you last arranged'),
        findsNothing,
        reason:
            'Nothing appeared. One box was moved, and the tab told somebody '
            'the rest of their business had turned up since they last did '
            'something.',
      );
      expect(
        find.textContaining('3 boxes have not been placed by you'),
        findsOneWidget,
        reason:
            'The honest half stays, because it is what is actually on screen: '
            'three boxes really are where the shape put them rather than where '
            'this person did, which is why one may be sitting on another, and '
            'there is one button that tidies the lot.',
      );
    });

    /// Three rather than five, and the two that went are the two PLACES.
    ///
    /// A place has no position now — it is a row in the list attached to its
    /// company — so it can never be missing from an arrangement and can never be
    /// placed by pressing anything. Counting them would have told this
    /// organization that thirteen boxes were unplaced, on every visit, for ever,
    /// with an escape hatch that could not clear the sentence because pressing
    /// it would not place them either. That is the same failure the "new since
    /// you last arranged" wording was fixed for, arriving from the other side.
    testWidgets('and the places are not among the boxes it counts', (
      tester,
    ) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      // The premise: the fixture has six things in it and two of them are
      // places, so a count that included them would say five.
      expect(flattenStructure(_tree()), hasLength(6));
      expect(
        flattenStructure(_tree()).where((n) => !structureHasBox(n.kind)),
        hasLength(2),
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(120, 60),
      );
      await _settle(tester);

      expect(find.textContaining('5 boxes have not been placed'), findsNothing);
    });
  });

  group('saving it', () {
    testWidgets('a drag is written once, after it settles', (tester) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(tester, _server(saves: saves));
      await _openStructure(tester);

      final before = _rectOf(tester, 'company:$_sun');

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(120, 60),
      );
      await _land(tester);

      expect(
        _rectOf(tester, 'company:$_sun'),
        isNot(before),
        reason:
            'Moved first, saved second. A box that stays put for as long as '
            'the network takes reads as a control that did not work.',
      );
      expect(
        saves,
        isEmpty,
        reason:
            'The canvas reports every pointer move, not just the release. '
            'Saving each one is a write per frame to a column read on every '
            'request that returns /me.',
      );

      await _settle(tester);

      expect(saves, hasLength(1));

      final boxes = (saves.single['boxes'] as List).cast<Map<String, dynamic>>();
      final sun = boxes.firstWhere((b) => b['id'] == _sun);

      // The coordinate that was WRITTEN, against the one the box was dragged
      // to. This used to assert only that one of the two numbers was above
      // zero, which the automatic layout already guarantees — it shifts every
      // box to at least the margin — so it passed against a client that threw
      // the dragged position away and sent a constant, and nothing anywhere in
      // this feature checked what the wire actually carried.
      //
      // Measured against the automatic layout rather than a table of numbers,
      // because there is no stored arrangement here: that is where the box
      // starts, computed by the same function the canvas uses.
      final from = arrangeStructure(flattenStructure(_tree()))['company:$_sun']!;

      expect(sun['x'], (from.dx + 120).round());
      expect(sun['y'], (from.dy + 60).round());
    });

    /// The answer to a save echoes what was STORED, which is what was sent. A
    /// box moved while that request was in the air is therefore absent from it,
    /// and applying the echo wholesale put such a box back where it stood
    /// before the request left — visibly, a moment after being let go — and the
    /// debounced save that followed wrote the revert into the column.
    testWidgets('a box moved while a save is on the wire is not put back', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];
      final gate = Completer<void>();

      await _pump(tester, _server(saves: saves, held: gate.future));
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(120, 60),
      );
      await _settle(tester);

      expect(
        saves,
        hasLength(1),
        reason: 'The premise: the first save is on the wire and held there.',
      );

      // A second box, moved while the first answer has not come back yet.
      //
      // A COMPANY rather than a place: a place is a row in the list attached to
      // its company now, so it has no position of its own and nothing to move.
      //
      // Measured against a box nobody has touched rather than against the
      // window, because the note above the canvas appears the moment there is a
      // saved arrangement and shifts the whole picture down with it — which is
      // a change of layout and not of arrangement.
      Offset apart() =>
          _rectOf(tester, 'company:$_ever').topLeft -
          _rectOf(tester, 'organization:$_org').topLeft;

      final settled = apart();

      await tester.drag(
        find.byKey(structureBoxKey('company:$_ever')),
        const Offset(80, 90),
      );
      await _land(tester);

      final moved = apart();

      expect(moved, isNot(settled), reason: 'The premise: it moved.');

      gate.complete();
      await tester.pumpAndSettle();

      expect(
        apart(),
        moved,
        reason:
            'The answer to somebody else’s drag put this box back. On any '
            'connection slow enough for two gestures to overlap, a move is '
            'undone by the save of the move before it — and nothing on screen '
            'says why.',
      );

      await _settle(tester);

      expect(saves, hasLength(2));

      final second = (saves.last['boxes'] as List).cast<Map<String, dynamic>>();
      final from = arrangeStructure(flattenStructure(_tree()));

      expect(
        second.firstWhere((box) => box['id'] == _ever),
        {
          'kind': 'company',
          'id': _ever,
          'x': (from['company:$_ever']!.dx + 80).round(),
          'y': (from['company:$_ever']!.dy + 90).round(),
        },
        reason:
            'And it is written, rather than lost from the column as well as '
            'from the screen.',
      );
    });

    testWidgets('and it never asks the server to re-parent anything', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];
      final calls = <String>[];

      await _pump(tester, _server(saves: saves, calls: calls));
      await _openStructure(tester);

      // Dropped exactly on top of a company in a different branch, which is
      // the gesture that means "re-parent" on every other canvas anybody has
      // used. Here it means two overlapping boxes and nothing else.
      final onto = tester.getCenter(find.byKey(structureBoxKey('company:$_ever')));
      final from = tester.getCenter(
        find.byKey(structureBoxKey('franchise:$_quiet')),
      );

      await tester.drag(
        find.byKey(structureBoxKey('franchise:$_quiet')),
        onto - from,
      );
      await _settle(tester);

      expect(saves, hasLength(1));
      expect(
        saves.single.keys.toSet(),
        {'orientation', 'boxes'},
        reason:
            'The only wire this tab owns carries coordinates and which way the '
            'chart runs. A THIRD field on this request is the drag having '
            'started to move the business — there is no field here a parent '
            'could arrive in, which is what makes the rule structural rather '
            'than a promise.',
      );

      for (final box in (saves.single['boxes'] as List).cast<Map>()) {
        expect(box.keys.toSet(), {'kind', 'id', 'x', 'y'});
      }

      expect(
        calls.where((c) => !c.startsWith('GET') && !c.contains('canvas')),
        isEmpty,
        reason:
            'CompaniesController omits the parent from every set of fields it '
            'accepts. A PATCH to a company or a franchise from a DRAG would be '
            'this feature rewriting history behind a picture.',
      );

      expect(
        find.text('Quietside'),
        findsWidgets,
        reason: 'And the box is still on the canvas, under the same parent.',
      );
    });

    testWidgets('a position for something not on screen is sent back', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(
        tester,
        _server(saves: saves, canvas: [_box('company', _away, 40, 80)]),
      );
      await _openStructure(tester);

      expect(
        find.byKey(structureBoxKey('company:$_away')),
        findsNothing,
        reason: 'The premise: this company is not on screen.',
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(90, 30),
      );
      await _settle(tester);

      final ids = [
        for (final box in (saves.single['boxes'] as List).cast<Map>())
          box['id'],
      ];

      expect(
        ids,
        contains(_away),
        reason:
            'A company can be away for the afternoon while a role is edited or '
            'a module is switched off and back on. The server keeps its '
            'position on purpose, and a client that sent back only what it '
            'drew would defeat that rule from the other side — the arrangement '
            'lost by nudging an unrelated box.',
      );
    });

    testWidgets('a refused save puts the boxes back and says why', (
      tester,
    ) async {
      const sentence =
          'A canvas can hold 500 boxes. This structure has more than that.';

      await _pump(tester, _server(saveStatus: 422, saveError: sentence));
      await _openStructure(tester);

      final before = _rectOf(tester, 'company:$_sun');

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(140, 70),
      );
      await _land(tester);

      expect(
        _rectOf(tester, 'company:$_sun'),
        isNot(before),
        reason: 'The premise: it moved, so there is something to put back.',
      );

      await _settle(tester);

      expect(
        _rectOf(tester, 'company:$_sun'),
        before,
        reason:
            'There is nothing to reload from, so a refused save is undone from '
            'memory or not at all. An arrangement that looks saved and is not '
            'costs somebody the same afternoon twice.',
      );
      expect(
        find.text(sentence),
        findsOneWidget,
        reason:
            'The server’s own sentence. A generic "could not save" throws away '
            'the only part worth reading.',
      );
    });

    /// The mirror image of the test that used to be here.
    ///
    /// While the arrangement was one person's, a successful save HAD to be
    /// written into the session's cached copy of `/me` — nothing in this app
    /// refetches that on navigation, so without it the boxes went back to their
    /// automatic places the next time the tab opened, and the next save wrote
    /// that emptiness over the column.
    ///
    /// None of it survives the arrangement becoming the organization's. The
    /// tree is refetched every time this screen opens, so the server is the
    /// only copy there is — and a second copy cached here would be the one that
    /// gets drawn first, which is exactly how a colleague's arrangement would
    /// stop arriving.
    testWidgets('a successful save caches nothing on this side', (tester) async {
      final session = await _pump(tester, _server());
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(110, 45),
      );
      await _settle(tester);

      expect(
        session.me!.preferences['organization.canvas'],
        isNull,
        reason:
            'A copy kept here is a copy that can be stale, on a screen whose '
            'whole point is that everybody converges on one picture.',
      );
      expect(
        session.me!.preferences.keys.where(
          (key) => key.contains('canvas') || key.contains('structure'),
        ),
        isEmpty,
        reason: 'Nor under a new name for the same mistake.',
      );
    });

    testWidgets('a drag is not lost by leaving before it settles', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(tester, _server(saves: saves));
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(100, 40),
      );
      await _land(tester);

      expect(
        saves,
        isEmpty,
        reason: 'The premise: the debounce is still counting.',
      );

      // Somebody clicks another destination in the sidebar. The screen goes
      // away with a write still pending.
      await tester.pumpWidget(
        const MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      );
      await tester.pumpAndSettle();

      expect(
        saves,
        hasLength(1),
        reason:
            'A drag two hundred milliseconds before somebody clicks Settings '
            'must not be the one that is lost. Debouncing without flushing on '
            'the way out is a saving feature that quietly drops the last thing '
            'anybody did.',
      );

      // And what it carried is the drag, not an empty list. The flush fires
      // from `dispose`, after the State has been told it is going away, so the
      // one thing it must not do is build its payload from something it has
      // already torn down.
      final sun = (saves.single['boxes'] as List)
          .cast<Map<String, dynamic>>()
          .firstWhere((box) => box['id'] == _sun);
      final from = arrangeStructure(flattenStructure(_tree()))['company:$_sun']!;

      expect(sun['x'], (from.dx + 100).round());
      expect(sun['y'], (from.dy + 40).round());
    });

    testWidgets('a narrow visit never writes an arrangement', (tester) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(tester, _server(saves: saves), size: const Size(375, 900));
      await _openStructure(tester);
      await _settle(tester);

      expect(
        saves,
        isEmpty,
        reason:
            'The tempting shape is "lay it out and save it so it is ready", '
            'and that shape lets a phone flatten an arrangement somebody spent '
            'an afternoon on at a desk.',
      );
    });

    testWidgets('leaving the tab and coming back keeps where you were', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(tester, _server(saves: saves));
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(130, 55),
      );
      await _settle(tester);

      // Then look somewhere else, by panning an empty patch of canvas well
      // below the last row of boxes.
      //
      // The viewport is the honest test of whether this tab survived the
      // switch, and the arrangement is not: where you are looking is
      // deliberately stored NOWHERE, so a canvas rebuilt from scratch cannot
      // recover it, while an arrangement would simply be re-read from the
      // preference that was just saved and land in the same place either way.
      // Measured rather than typed in. This was `Offset(60, 900)`, which was
      // empty canvas until places moved down to the fourth line — a place is a
      // place wherever it hangs, so a company with no sub companies leaves the
      // third line clear. A hardcoded point that quietly lands ON a box does
      // not fail loudly: it starts dragging the box and the test goes on
      // measuring a "viewport pan" that is nothing of the kind.
      await tester.dragFrom(
        _emptyPatch(tester),
        const Offset(-140, -80),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      final looking = _rectOf(tester, 'company:$_sun');

      await tester.tap(find.text('List'));
      await tester.pumpAndSettle();
      await _openStructure(tester);

      expect(
        _rectOf(tester, 'company:$_sun'),
        looking,
        reason:
            'Offstage takes the hidden half out of layout, painting and '
            'semantics and leaves its State exactly where it was. A swapped '
            'child rebuilds the canvas, and glancing at the list would throw '
            'somebody back to the top-left of a picture they had scrolled '
            'across.',
      );
      expect(
        saves,
        hasLength(1),
        reason:
            'And neither panning nor glancing at the other tab is a write. '
            'Where you are looking is not how you arranged things.',
      );
    });
  });

  group('arranging it is a permission now', () {
    /// The whole of §4 for this feature, in one test: the picture is not
    /// hidden, the refusal is the SERVER's sentence rather than a guess made
    /// here, there is no dead control anywhere, and everything that was never
    /// written down still works.
    testWidgets('somebody who may not arrange sees the boxes, is told why, and '
        'can still pan', (tester) async {
      // Word for word what `Refusal::permission` produces for this act. A test
      // that asserted "some sentence appeared" would pass against a client that
      // wrote its own, which is the failure this whole pair of fields exists to
      // prevent.
      const refusal =
          'You do not have permission to rearrange the structure everyone '
          'sees. That is part of a role rather than of an account, so somebody '
          'who can edit roles can add it to yours.';

      final saves = <Map<String, dynamic>>[];

      await _pump(
        tester,
        _server(
          saves: saves,
          mayArrange: false,
          arrangeRefusal: refusal,
          canvas: [
            _box('company', _sun, 0, 0),
            _box('organization', _org, 600, 300),
          ],
        ),
      );
      await _openStructure(tester);

      expect(
        find.byKey(structureBoxKey('company:$_sun')),
        findsOneWidget,
        reason:
            'Not a hidden tab. The picture is the same picture everybody else '
            'is looking at, and reading it needs no permission at all.',
      );
      expect(
        _delta(tester),
        const Offset(600, 300),
        reason:
            'And it is the ARRANGEMENT everybody else is looking at, not the '
            'automatic layout. Somebody refused the save is not thereby '
            'refused the picture their colleagues made.',
      );

      expect(
        find.text(refusal),
        findsOneWidget,
        reason:
            'The server\'s own sentence, in the same words its 403 would have '
            'used. A client that worded its own is how a dead control and the '
            'refusal behind it come to explain one rule two different ways.',
      );

      expect(
        find.text('Arrange for me'),
        findsNothing,
        reason:
            'Absent, not disabled. A disabled button invites somebody to work '
            'out why it does nothing, and a live one answering 403 is worse.',
      );
      expect(
        find.byTooltip('Move “Sunward Overhead Doors” with the arrow keys'),
        findsNothing,
        reason: 'And the keyboard route to the same write is gone with it.',
      );

      // A box cannot be picked up either, which is the third way in.
      Offset apart() =>
          _rectOf(tester, 'organization:$_org').topLeft -
          _rectOf(tester, 'company:$_sun').topLeft;

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(120, 60),
      );
      await _land(tester);

      expect(apart(), const Offset(600, 300), reason: 'A box moved anyway.');

      // The viewport, though, is not part of the arrangement and never was.
      // It is stored nowhere, so there is nothing here to refuse.
      final looking = _rectOf(tester, 'company:$_sun');

      await tester.dragFrom(
        _emptyPatch(tester),
        const Offset(-140, -80),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      expect(
        _rectOf(tester, 'company:$_sun'),
        isNot(looking),
        reason:
            'Panning is how you get around a free canvas, and a canvas nobody '
            'can pan is a dead end made out of a permission.',
      );

      await _settle(tester);

      expect(
        saves,
        isEmpty,
        reason:
            'And none of it wrote anything. A gate that only hides controls is '
            'a gate the first stray callback walks through.',
      );
    });

    /// A role edited while somebody had the tab open. The gate above is the
    /// ordinary path; this is the one the server has to catch, and the client's
    /// job is to print what it says rather than to invent a story about it.
    testWidgets('a save refused after the fact says the server\'s own sentence '
        'and puts the boxes back', (tester) async {
      const sentence =
          'Only somebody assigned at the whole organization can rearrange the '
          'structure everyone sees — it affects the entire business rather '
          'than one part of it.';

      await _pump(
        tester,
        _server(saveStatus: 403, saveMessage: sentence),
      );
      await _openStructure(tester);

      final before = _rectOf(tester, 'company:$_sun');

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(140, 70),
      );
      await _land(tester);

      expect(
        _rectOf(tester, 'company:$_sun'),
        isNot(before),
        reason: 'The premise: it moved, so there is something to put back.',
      );

      await _settle(tester);

      expect(_rectOf(tester, 'company:$_sun'), before);
      expect(
        find.text(sentence),
        findsOneWidget,
        reason:
            'A 403 explains itself at the top level and carries no field '
            'errors, so a client that only ever reads `errors` would show '
            '"Something went wrong" for the one refusal that explains itself '
            'best.',
      );
    });
  });

  group('two people, one arrangement', () {
    testWidgets('a colleague\'s arrangement arrives on the next fetch', (
      tester,
    ) async {
      final (api, session) = await _signIn(tester, _server());

      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: _tree(
          canvas: [
            _box('organization', _org, 40, 40),
            _box('company', _sun, 640, 340),
          ],
        ),
      );

      expect(
        _delta(tester),
        const Offset(-600, -300),
        reason: 'The premise: this is where the arrangement put them.',
      );

      // The same State, handed the payload a refetch brought back.
      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: _tree(
          canvas: [
            _box('organization', _org, 40, 40),
            _box('company', _sun, 240, 140),
          ],
        ),
      );

      expect(
        _delta(tester),
        const Offset(-200, -100),
        reason:
            'There is one arrangement for the organization, so a fetch that '
            'brings back a different one is somebody else having moved a box. '
            'A tab that kept drawing its own copy would leave two people '
            'looking at two pictures of one business, and the next drag from '
            'here would write the old one back over theirs.',
      );
    });

    /// The honest half of a shared row: it is taken, it is SAID, and it does
    /// not reach over somebody mid-gesture.
    testWidgets('a colleague\'s arrangement is taken, said out loud, and does '
        'not undo an unsaved move', (tester) async {
      final saves = <Map<String, dynamic>>[];
      final (api, session) = await _signIn(tester, _server(saves: saves));

      Map<String, dynamic> tree(int everX, int everY, int sunX) => _tree(
        canvas: [
          _box('organization', _org, 40, 40),
          _box('company', _ever, everX, everY),
          _box('company', _sun, sunX, 340),
        ],
      );

      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: tree(340, 240, 640),
      );

      // Measured between boxes rather than against the window, because the
      // sentence about to appear is a strip above the canvas and it pushes the
      // whole picture down — which is a change of layout, not of arrangement.
      Offset apart(String key) =>
          _rectOf(tester, key).topLeft -
          _rectOf(tester, 'organization:$_org').topLeft;

      // This person moves a box, and has not saved it yet.
      await tester.drag(
        find.byKey(structureBoxKey('company:$_ever')),
        const Offset(60, 30),
      );
      await _land(tester);

      expect(saves, isEmpty, reason: 'The premise: the debounce is counting.');
      expect(apart('company:$_ever'), const Offset(360, 230));

      // Meanwhile somebody else saved a different picture, and a refetch
      // brings it back. It moves the box this person is holding, and one they
      // are not.
      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: tree(900, 700, 240),
      );

      expect(
        apart('company:$_sun'),
        const Offset(200, 300),
        reason:
            'A box nobody here has touched takes the colleague\'s place. That '
            'is how an office converges on one picture instead of two.',
      );
      expect(
        apart('company:$_ever'),
        const Offset(360, 230),
        reason:
            'And a box this person moved two seconds ago and has not saved '
            'stays where they put it. Taking the server\'s answer wholesale '
            'would yank it out from under a hand that is still on it.',
      );

      expect(
        find.textContaining('Somebody else has arranged these boxes'),
        findsOneWidget,
        reason:
            'Said, rather than done quietly. Only one arrangement is kept, so '
            'silently winning and silently losing are both available here and '
            'both are lies about what just happened.',
      );
      expect(
        find.textContaining('the last person to move a box'),
        findsOneWidget,
        reason:
            'And it states the RULE as well as the event, because "somebody '
            'else moved these" leaves the next question — what happens if we '
            'both keep going — unanswered.',
      );
      expect(
        find.byType(Dialog),
        findsNothing,
        reason: 'A note, never a pop-up. It interrupts nothing.',
      );

      // And the save that follows carries the merge that is on screen: their
      // place for the box nobody here touched, this person's for the one they
      // did. A client that sent only what it had before the fetch would put
      // the colleague's whole arrangement back the way it was.
      await _settle(tester);

      final boxes = (saves.single['boxes'] as List).cast<Map<String, dynamic>>();

      expect(
        boxes.firstWhere((box) => box['id'] == _sun),
        {'kind': 'company', 'id': _sun, 'x': 240, 'y': 340},
      );
      expect(
        boxes.firstWhere((box) => box['id'] == _ever),
        {'kind': 'company', 'id': _ever, 'x': 400, 'y': 270},
      );

      // The sentence is about an event rather than a condition, so it can be
      // got rid of. The refusal and drift notes deliberately cannot: their
      // remedy is on screen, and hiding those would hide why a control is
      // missing while the control stayed missing.
      await tester.tap(find.text('Got it'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Somebody else has arranged these boxes'),
        findsNothing,
      );
    });

    /// The window where this is easiest to get wrong, and it is not a rare
    /// one: somebody drags a box, and while that PUT is still in the air they
    /// rename a company on the List tab. The server has already stored the
    /// drag, so the refetch carries it back — an arrangement this tab has not
    /// been told about yet, because its own answer has not landed.
    ///
    /// Compared against what is ON THE WIRE as well as against what was last
    /// confirmed, for exactly this. Without it, a save followed by any other
    /// edit would announce a colleague and name this person's own drag, and the
    /// one sentence that matters here becomes the sentence everybody learns to
    /// ignore.
    testWidgets('this tab\'s own save coming back mid-flight is not somebody '
        'else', (tester) async {
      final saves = <Map<String, dynamic>>[];
      final gate = Completer<void>();

      final (api, session) = await _signIn(
        tester,
        _server(saves: saves, held: gate.future),
      );

      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: _tree(
          canvas: [
            _box('organization', _org, 40, 40),
            _box('company', _sun, 640, 340),
          ],
        ),
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(50, 20),
      );
      await _settle(tester);

      expect(
        saves,
        hasLength(1),
        reason: 'The premise: the save is on the wire and held there.',
      );

      // The refetch that follows an edit on the other tab, carrying exactly
      // what this tab has just sent and has not been answered about yet.
      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: _tree(
          canvas: (saves.single['boxes'] as List).cast<Map<String, dynamic>>(),
        ),
      );

      expect(
        find.textContaining('Somebody else has arranged these boxes'),
        findsNothing,
        reason:
            'This is the arrangement this tab is in the middle of writing. '
            'Announcing it as a colleague\'s would be the feature crying wolf '
            'about the person using it.',
      );

      gate.complete();
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Somebody else has arranged these boxes'),
        findsNothing,
        reason: 'And still not, once the answer to it lands.',
      );
    });
  });

  group('which way the chart flows', () {
    /// It is part of the SHARED arrangement, so it comes off the tree with the
    /// boxes rather than out of a preference or a constant. A tab that opened
    /// left to right whatever the organization had chosen would be one person's
    /// picture disagreeing with everybody else's about which way their own
    /// business runs.
    testWidgets('the tab opens the way the shared arrangement says', (
      tester,
    ) async {
      await _pump(tester, _server(orientation: 'top_to_bottom'));
      await _openStructure(tester);

      expect(_chosenFlow(tester), 'Top to bottom');
      expect(find.textContaining('The chart runs top to bottom'), findsOneWidget);

      // And the BOXES flow that way, which is the half the chip and the
      // sentence cannot say. A tab that read the stored word, drew the chip
      // selected, wrote the right sentence and never handed the word to the
      // canvas would pass both assertions above and show everybody the chart
      // running the other way.
      final apart = _rectOf(tester, 'company:$_ever').topLeft -
          _rectOf(tester, 'organization:$_org').topLeft;

      expect(
        apart.dy,
        greaterThan(0),
        reason: 'Depth runs downward when the arrangement says top to bottom.',
      );
      expect(apart.dy, greaterThan(apart.dx.abs()));
    });

    testWidgets('and left to right when nobody has chosen', (tester) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        _chosenFlow(tester),
        'Left to right',
        reason:
            'The owner picked it from the mockups, and the reason is the real '
            'data: nine places under one company is nothing stacked downwards '
            'and three screens across.',
      );
    });

    /// One request, carrying the direction AND the boxes — because they are one
    /// arrangement, and a save that sent half of one would drop whichever half
    /// it left out.
    testWidgets('turning it round is written once, with the boxes', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(
        tester,
        _server(saves: saves, canvas: [_box('company', _sun, 240, 140)]),
      );
      await _openStructure(tester);

      await tester.tap(find.text('Top to bottom'));
      await _settle(tester);

      expect(saves, hasLength(1));
      expect(saves.single['orientation'], 'top_to_bottom');
      expect(
        (saves.single['boxes'] as List).single,
        {'kind': 'company', 'id': _sun, 'x': 240, 'y': 140},
        reason:
            'The arrangement it had goes back with the direction. A body that '
            'carried the orientation alone would be a save that flattened '
            'everybody\'s boxes to nothing.',
      );
    });

    /// The chip already selected can still be pressed, and a save that changes
    /// nothing still takes an audit row and still announces itself to every
    /// colleague's next fetch as somebody having rearranged the chart.
    testWidgets('pressing the one already chosen writes nothing', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(tester, _server(saves: saves));
      await _openStructure(tester);

      await tester.tap(find.text('Left to right'));
      await _settle(tester);

      expect(saves, isEmpty);
    });

    /// A colleague's flip is a colleague's arrangement, and gets the same
    /// treatment: taken, and said out loud.
    testWidgets('a colleague turning it round arrives and is said out loud', (
      tester,
    ) async {
      final (api, session) = await _signIn(tester, _server());

      await _pumpTab(tester, api: api, session: session, tree: _tree());

      expect(_chosenFlow(tester), 'Left to right');

      // The same State, handed the payload a refetch brought back.
      await _pumpTab(
        tester,
        api: api,
        session: session,
        tree: _tree(orientation: 'top_to_bottom'),
      );

      expect(_chosenFlow(tester), 'Top to bottom');
      expect(
        find.textContaining('Somebody else has arranged these boxes'),
        findsOneWidget,
        reason:
            'Turning the whole chart round is the most visible thing anybody '
            'can do here. Doing it to somebody silently is the same failure as '
            'moving their boxes silently.',
      );
    });

    /// Saving is what the permission gates, and this is a save. Absent rather
    /// than disabled, like the other two controls that would produce one.
    testWidgets('somebody who may not arrange is not offered the control', (
      tester,
    ) async {
      await _pump(
        tester,
        _server(mayArrange: false, arrangeRefusal: 'You may not.'),
      );
      await _openStructure(tester);

      expect(find.byType(StructureFlowChoice), findsNothing);
      expect(find.text('Top to bottom'), findsNothing);
      expect(
        find.textContaining('You may not.'),
        findsOneWidget,
        reason:
            'And the server\'s own sentence is still on screen, so the missing '
            'control is explained rather than merely missing.',
      );
    });

    /// A server that has not been deployed yet answers with the bare list this
    /// payload used to be, and an arrangement is not lost over it.
    testWidgets('an arrangement from an older server is still read', (
      tester,
    ) async {
      await _pump(
        tester,
        _server(rawCanvas: [_box('company', _sun, 240, 140)]),
      );
      await _openStructure(tester);

      expect(
        _rectOf(tester, 'company:$_sun').topLeft -
            _rectOf(tester, 'organization:$_org').topLeft,
        isNot(Offset.zero),
        reason: 'The premise: the two boxes are not on top of each other.',
      );

      // The arrangement was read: this box is where the old-shaped payload put
      // it rather than where the automatic layout would have.
      expect(
        find.byKey(structureBoxKey('company:$_sun')),
        findsOneWidget,
      );
      expect(
        _chosenFlow(tester),
        'Left to right',
        reason:
            'A payload that says nothing about direction says the default, not '
            'nothing at all.',
      );
    });

    /// The client half of "a place has no position". The server drops these in
    /// both directions; a client that kept them would send them straight back
    /// on the next drag and they would live in the shared column forever.
    testWidgets('a stored place coordinate is discarded rather than sent back', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(
        tester,
        _server(
          saves: saves,
          canvas: [
            _box('company', _sun, 240, 140),
            _box('franchise', _quiet, 700, 700),
          ],
        ),
      );
      await _openStructure(tester);

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(40, 20),
      );
      await _settle(tester);

      expect(
        [for (final box in (saves.single['boxes'] as List).cast<Map>()) box['id']],
        isNot(contains(_quiet)),
        reason:
            'A place is a row in the list attached to its company now, so a '
            'coordinate for one describes something with nowhere to sit. It is '
            'not the same as a position for a company that is away for the '
            'afternoon, which this same request is careful to send back.',
      );
    });
  });

  group('the explanations', () {
    testWidgets('the canvas says which way it runs and where the places are', (
      tester,
    ) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        find.textContaining('Nothing you do here changes the business'),
        findsOneWidget,
        reason:
            'The rule, stated before anybody tries it. This note used to be '
            'passed null because the header above said something close enough '
            'to it, and close enough is how one screen comes to say two things '
            'about the rule that matters most here.',
      );
      expect(
        find.textContaining('The chart runs left to right'),
        findsOneWidget,
        reason:
            'Which way depth runs is now a choice, so the sentence explaining '
            'how to read the picture has to be about the picture actually on '
            'screen. A constant inside the canvas cannot say that: it is one '
            'string, and there are two pictures.',
      );
      expect(
        find.textContaining(
          'listed under the company or sub company that runs them',
        ),
        findsOneWidget,
        reason:
            'A place is not a box any more, and somebody looking for thirteen '
            'of them and finding four companies is owed the reason in words.',
      );
      expect(
        find.textContaining('A line with nothing on it'),
        findsNothing,
        reason:
            'The canvas\'s own default note still says this, and it is the '
            'wrong sentence here: there is no line for a place to be absent '
            'from any more, so a blank line is not a thing this chart has.',
      );
    });

    /// The other half of the same sentence: it follows the control.
    testWidgets('and it says the other thing when the chart is turned round', (
      tester,
    ) async {
      await _pump(tester, _server());
      await _openStructure(tester);

      await tester.tap(find.text('Top to bottom'));
      await tester.pumpAndSettle();

      expect(find.textContaining('The chart runs top to bottom'), findsOneWidget);
      expect(find.textContaining('The chart runs left to right'), findsNothing);
    });

    /// The rule above BOTH views, in one wording.
    ///
    /// It used to end "nothing here can be moved to a different parent once it
    /// exists", which is the model's vocabulary rather than anybody's —
    /// "parent" is a column name. And the two tabs said it differently enough
    /// that somebody switching between them could reasonably conclude there
    /// were two rules, this being the one they are most likely to test by
    /// trying it on the other tab.
    testWidgets('the header states the rule for both views, in the same words', (
      tester,
    ) async {
      await _pump(tester, _server());

      expect(
        find.textContaining(
          'who owns what is decided when something is created',
        ),
        findsOneWidget,
        reason: 'The list says it.',
      );

      await _openStructure(tester);

      expect(
        find.textContaining(
          'Moving a box moves the picture only — who owns what is decided when '
          'something is created and stays that way afterwards.',
        ),
        findsOneWidget,
        reason:
            'And the structure says the same thing, with the sentence about '
            'the gesture in front of it.',
      );
      expect(
        find.textContaining('a different parent'),
        findsNothing,
        reason:
            'In nobody\'s words but a developer\'s. R8: a wire name is not a '
            'thing to put on screen.',
      );
    });

    testWidgets('the outline says who owns what, because on a phone it is the '
        'only view there is', (tester) async {
      await _pump(tester, _server(), size: const Size(375, 900));
      await _openStructure(tester);

      expect(find.byType(StructureOutline), findsOneWidget);
      expect(
        find.textContaining(
          'nothing on this list can be moved under a different company',
        ),
        findsOneWidget,
        reason:
            'Nothing here can be dragged, so there is no gesture to misread — '
            'and somebody whose whole experience of the structure is this list '
            'is still owed the one rule that matters most about it.',
      );
    });

    testWidgets('and it fits the phone held sideways, which is where it is '
        'tightest', (tester) async {
      // 812 by 375 leaves this tab about a hundred points. Two paragraphs of
      // explanation is more than that before a single row is drawn, so stacked
      // above a scroll view they overflow it — a striped bar in debug and
      // silently clipped structure in release. They ride inside the scroll for
      // exactly this reason.
      await _pump(tester, _server(), size: const Size(812, 375));
      await _openStructure(tester);

      expect(find.textContaining('too short'), findsOneWidget);
      expect(
        find.textContaining(
          'nothing on this list can be moved under a different company',
        ),
        findsOneWidget,
      );
      expect(
        find.text('Everdoors'),
        findsWidgets,
        reason:
            'And the structure is still reachable behind them, which is the '
            'half an overflow takes away.',
      );
    });
  });
}

// ── the fixture ────────────────────────────────────────────────────
//
// Real uuids rather than readable ids, because the server validates every id
// with `uuid` and refuses the whole save over one that is not. A fixture that
// could not survive the endpoint it is testing against is a fixture that hides
// exactly that.

const _org = '00000000-0000-4000-8000-000000000001';
const _ever = '00000000-0000-4000-8000-000000000002';
const _sun = '00000000-0000-4000-8000-000000000003';
const _commercial = '00000000-0000-4000-8000-000000000004';
const _north = '00000000-0000-4000-8000-000000000005';
const _quiet = '00000000-0000-4000-8000-000000000006';

/// A company the caller cannot currently see. Never in the tree; only ever in
/// somebody's stored arrangement.
const _away = '00000000-0000-4000-8000-0000000000ff';

/// The payload `GET /companies` answers with — the structure AND the
/// arrangement, because one describes the other and they travel together.
///
/// [canvas] is the organization's own arrangement, shared by everybody in it.
/// It is on the tree rather than in anybody's preferences, which is the whole
/// of change (1) from this side: `/me` is answered once at launch and cached,
/// so a colleague's save read from there would not appear until the next
/// sign-in.
///
/// [mayArrange] and [arrangeRefusal] are the server's §4 answer, published as a
/// flag and as the sentence behind it — the same pair `may_add_company` beside
/// them already is.
///
/// [canvas] is the BOXES, and [orientation] the direction they flow in. They go
/// out as one value — `{orientation, boxes}` — because they are one arrangement:
/// which way the chart runs is a fact about the picture everybody opens, not
/// about where one person is looking, so it is stored and published with the
/// coordinates rather than beside them.
Map<String, dynamic> _tree({
  List<Map<String, dynamic>> canvas = const [],
  String orientation = 'left_to_right',
  bool mayArrange = true,
  String? arrangeRefusal,
}) => {
  'organization': {
    'id': _org,
    'name': 'Everdoors Group',
    'may_add_company': true,
    'may_add_company_reason': null,
    'has_logo': false,
    'may_edit': false,
    'may_arrange': mayArrange,
    'may_arrange_reason': arrangeRefusal,
  },
  'companies': [
    _company(
      _ever,
      'Everdoors',
      franchises: [_place(_north, 'Northside')],
      subs: [
        _company(
          _commercial,
          'Sunward Commercial',
          parent: _ever,
          franchises: [_place(_quiet, 'Quietside')],
        ),
      ],
    ),
    _company(_sun, 'Sunward Overhead Doors'),
  ],
  'canvas': {'orientation': orientation, 'boxes': canvas},
};

/// One box of an arrangement, in the shape the endpoint stores and echoes.
Map<String, dynamic> _box(String kind, String id, int x, int y) => {
  'kind': kind,
  'id': id,
  'x': x,
  'y': y,
};

Map<String, dynamic> _company(
  String id,
  String name, {
  String? parent,
  List<Map<String, dynamic>> franchises = const [],
  List<Map<String, dynamic>> subs = const [],
  bool mayReparent = true,
  String? reparentRefusal,
}) => {
  'id': id,
  'name': name,
  'is_active': true,
  'parent_company_id': parent,
  'may_edit': true,
  'may_add_franchise': true,
  'may_add_franchise_reason': null,
  'may_add_sub_company': parent == null,
  'may_add_sub_company_reason': null,
  'may_reparent': mayReparent,
  'may_reparent_reason': reparentRefusal,
  'has_logo': false,
  'franchises': franchises,
  'sub_companies': subs,
};

Map<String, dynamic> _place(String id, String name) => {
  'id': id,
  'name': name,
  'is_active': true,
  'timezone': 'America/New_York',
  'currency': 'USD',
  'may_edit': true,
};

// ── the server ─────────────────────────────────────────────────────

typedef MockClientHandler = Future<http.Response> Function(http.Request);

/// Every unstubbed call answers 500 loudly, so a request this file did not
/// think about fails a test rather than quietly returning an empty list.
MockClientHandler _server({
  Map<String, dynamic> preferences = const {},
  List<Map<String, dynamic>> canvas = const [],
  String orientation = 'left_to_right',
  Object? rawCanvas,
  bool mayArrange = true,
  String? arrangeRefusal,
  List<Map<String, dynamic>>? saves,
  List<Map<String, dynamic>>? moves,
  List<String>? calls,
  int saveStatus = 200,
  String saveError = 'No.',
  String? saveMessage,
  Future<void>? held,
}) {
  return (request) async {
    calls?.add('${request.method} ${request.url.path}');

    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Jose',
          'email': 'jose@example.com',
          'has_avatar': false,
          'permissions': const ['companies.view'],
          'preferences': preferences,
          'organization': {
            'id': _org,
            'name': 'Everdoors Group',
            'franchise_label': 'Territory',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': _org,
            'label': 'Everdoors Group',
            'path': const ['Everdoors Group'],
          },
          'companies': const <Map<String, dynamic>>[],
          'franchises': const <Map<String, dynamic>>[],
        },
      });
    }

    if (request.url.path.endsWith('/organization/canvas')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;

      saves?.add(body);

      // Recorded first, answered when the test says so. A real save takes as
      // long as the network takes, and the whole class of bug this exists for
      // is what happens to a box moved while one is still in the air — which no
      // test can reach at all against a server that answers instantly.
      if (held != null) await held;

      if (saveStatus != 200) {
        // A 403 explains itself at the top level and carries no `errors` — the
        // shape `abort_if` produces — and a 422 puts the sentence worth reading
        // under the field. Both are answered here, because the client has to
        // print whichever one it is given rather than the generic top line.
        return _json(
          saveMessage != null
              ? {'message': saveMessage}
              : {
                  'message': 'The given data was invalid.',
                  'errors': {
                    'boxes': [saveError],
                  },
                },
          saveStatus,
        );
      }

      // Echoes what was STORED, the way the endpoint does — the client applies
      // this answer rather than the value it sent, so a clamped or deduplicated
      // coordinate would show up on screen rather than being corrected behind
      // its back. The whole arrangement, in the same shape the tree carries
      // one, because that is what the endpoint answers with.
      return _json({
        'orientation': body['orientation'] ?? orientation,
        'boxes': body['boxes'],
      });
    }

    if (request.url.path.endsWith('/parent')) {
      moves?.add({
        'path': request.url.path,
        'body': jsonDecode(request.body) as Map<String, dynamic>,
      });

      return _json({'id': _sun, 'parent_company_id': null, 'is_sub_company': false});
    }

    if (request.url.path.endsWith('/companies')) {
      final tree = _tree(
        canvas: canvas,
        orientation: orientation,
        mayArrange: mayArrange,
        arrangeRefusal: arrangeRefusal,
      );

      // A server that has not been deployed yet answers with the bare list this
      // payload used to be. One test needs to hand the client exactly that, and
      // it is the only reason this hatch exists.
      return _json(rawCanvas == null ? tree : {...tree, 'canvas': rawCanvas});
    }

    // The logo badges on the list fetch bytes. Answering with JSON hands the
    // image decoder something it cannot read, and the failure is reported as a
    // test failure rather than as the missing logo it is.
    if (request.url.path.contains('/branding/')) {
      return _json({'message': 'No logo.'}, 404);
    }

    return _json({'message': 'Nothing stubbed ${request.url.path}.'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

// ── driving the screen ─────────────────────────────────────────────

/// Signs in against [handler], and hands back the two things every screen here
/// is built from.
///
/// Its own step because two harnesses need it: the whole Organization screen,
/// and the Structure tab on its own — which is the only way to hand the same
/// State a SECOND tree and watch what it does with it, and a refetch carrying
/// somebody else's arrangement is exactly that.
Future<(ApiClient, Session)> _signIn(
  WidgetTester tester,
  MockClientHandler handler,
) async {
  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  return (api, session);
}

/// The default is 1400 by 1000, which is back where it started.
///
/// It went up to 1100 for one afternoon, while the tab carried one more strip
/// above the canvas than it does now — the control that turns the chart round,
/// which had nowhere else to sit — and while the chart was four levels deep
/// because a place was a box. Both are gone: the control is in the canvas's own
/// toolbar and a place is a row attached to its company, so the chart is three
/// levels deep and the surface has its hundred points back.
///
/// Worth stating rather than silently lowering, because the canvas below is
/// CLIPPED rather than scrolled: a box past the bottom is out of reach rather
/// than out of sight, and a test that pumps a window too small for the fixture
/// fails somewhere that says nothing about what it was testing.
Future<Session> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final (api, session) = await _signIn(tester, handler);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      // A fresh key every time, so pumping the screen a second time is somebody
      // OPENING it again rather than the same State carrying on. A test that
      // reopens the tab to check what it read back from preferences otherwise
      // passes on the state it left behind — which is exactly the reading that
      // would hide the preference never being read at all.
      home: Scaffold(
        body: KeyedSubtree(
          key: UniqueKey(),
          child: OrganizationScreen(api: api, session: session),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return session;
}

/// Picks a node in the List view's tree, which opens its page beside it.
Future<void> _pickInTree(WidgetTester tester, String kind, String id) async {
  await tester.tap(find.byKey(ValueKey('org-tree-$kind-$id')));
  await tester.pumpAndSettle();
}

Future<void> _openStructure(WidgetTester tester) async {
  await tester.tap(find.text('Structure'));
  await tester.pumpAndSettle();
}

/// The Structure tab on its own, so a SECOND tree can be handed to the same
/// State.
///
/// The screen above refetches `GET /companies` and swaps the payload in, which
/// is how a colleague's arrangement reaches anybody — and driving that through
/// the whole screen would mean performing an unrelated write just to provoke
/// the reload. The tab is what reads the arrangement, so the tab is what is
/// handed the two trees.
///
/// Keyed, and the key never changes. That is the whole point: a fresh key would
/// build a new State, which would read the new tree in `initState` and pass a
/// test that says nothing about `didUpdateWidget` — the one place a refetch is
/// actually noticed.
Future<void> _pumpTab(
  WidgetTester tester, {
  required ApiClient api,
  required Session session,
  required Map<String, dynamic> tree,
  bool mayArrange = true,
  String? arrangeRefusal,
  Size size = const Size(1400, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: StructureTab(
          key: const ValueKey('the tab under test'),
          api: api,
          session: session,
          tree: tree,
          mayArrange: mayArrange,
          arrangeRefusal: arrangeRefusal,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// Let a released box finish arriving, without letting the save fire.
///
/// Two things make this its own helper rather than a `pump()`. A box eases into
/// place over 220ms once nothing is in hand — the animation exists for the jump
/// every box makes when "Arrange for me" is pressed — and no frame is built
/// during a synthesised drag at all, so a single `pump()` afterwards renders the
/// FIRST frame of that ease, which is still the old position. It would fail a
/// screen that is behaving perfectly.
///
/// Settled in 50ms steps, so the clock lands around 250ms: past the end of the
/// ease and well short of the 700ms debounce. That gap is what makes "it moved
/// and nothing has been written yet" an assertion about optimism rather than a
/// race against the timer.
Future<void> _land(WidgetTester tester) =>
    tester.pumpAndSettle(const Duration(milliseconds: 50));

/// Past the debounce, and then far enough for the request to land.
///
/// A plain `pumpAndSettle` will not do it: the save is behind a Timer, and a
/// timer schedules no frame, so settling has nothing to wait for and returns
/// immediately with the write still pending.
Future<void> _settle(WidgetTester tester) async {
  await tester.pump(const Duration(seconds: 1));
  await tester.pumpAndSettle();
}

Rect _rectOf(WidgetTester tester, String key) =>
    tester.getRect(find.byKey(structureBoxKey(key)));

/// Which way the chart is currently set to flow, read off the control itself.
///
/// The chip's own `selected` rather than a colour or a position: that is the
/// property a screen reader announces, so asserting on it is asserting on what
/// somebody who cannot see the chips is actually told.
///
/// Scoped to the control rather than to every chip on screen, because the tab
/// strip above this whole page is chips as well — List and Structure — and one
/// of those is always selected too.
String _chosenFlow(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(
      find.descendant(
        of: find.byType(StructureFlowChoice),
        matching: find.byType(ChoiceChip),
      ),
    )
    .where((chip) => chip.selected)
    .map((chip) => ((chip.label as Text).data)!)
    .single;

/// A point ON the drawing surface with no box under it.
///
/// Found rather than typed, and both halves of that matter.
///
/// It used to be `Offset(60, lowest + 40)` — below every box. That was right
/// until it was not: a box moves onto a fixed point the first time the layout
/// changes, and a background pan that has quietly become a box drag reports a
/// move, saves it, and leaves every assertion above it measuring the wrong
/// thing. Then "below every box" stopped being on screen at all. The picture
/// grew a line when a place became level four, and this tab grew a note strip
/// above the canvas whenever there is a permission or an arrangement to speak
/// about, and between them the lowest box's bottom is now past the window on a
/// thousand-point screen. A pointer put down there lands on nothing, the view
/// does not move, and the test fails with no clue as to why.
///
/// So it searches the surface itself, from the bottom up, and returns the first
/// point no box covers — which is inside the canvas by construction whatever
/// the levels and the notes do next.
Offset _emptyPatch(WidgetTester tester) {
  // The drawing surface, found by the painter only this canvas uses. A
  // MaterialApp is full of CustomPaints and one that happens to be right today
  // is a test that starts asserting things about an ink splash tomorrow.
  final surface = tester.getRect(
    find.byWidgetPredicate(
      (widget) =>
          widget is CustomPaint && widget.painter is StructureConnectorPainter,
    ),
  );

  final boxes = tester
      .widgetList<Positioned>(
        find.byWidgetPredicate(
          (widget) =>
              widget is Positioned && widget.key.toString().contains('slot:'),
        ),
      )
      .map((positioned) => tester.getRect(find.byKey(positioned.key!)))
      .toList();

  for (var y = surface.bottom - 24; y > surface.top + 24; y -= 24) {
    for (var x = surface.left + 24; x < surface.right - 24; x += 24) {
      final at = Offset(x, y);

      if (boxes.every((box) => !box.contains(at))) return at;
    }
  }

  fail(
    'Every point on the drawing surface has a box on it, so this test is '
    'about to measure a box drag and call it a viewport pan.',
  );
}

/// Where the organization box sits relative to a company box.
///
/// Relative rather than absolute, because the canvas offsets everything by its
/// own viewport — which is where you happen to be looking rather than part of
/// the arrangement, and is deliberately never stored.
Offset _delta(WidgetTester tester) =>
    _rectOf(tester, 'organization:$_org').topLeft -
    _rectOf(tester, 'company:$_sun').topLeft;
