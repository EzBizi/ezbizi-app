import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/organization_screen.dart';
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
      expect(
        find.text('Organization'),
        findsOneWidget,
        reason:
            'One heading above both views. Two would read as two screens '
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

  group('the saved arrangement', () {
    testWidgets('is where the boxes are when the tab opens', (tester) async {
      // The control, first and in the same test. Without it this passes
      // against a canvas that never reads the preference at all and happens to
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
          preferences: {
            'organization.canvas': [
              {'kind': 'company', 'id': _sun, 'x': 0, 'y': 0},
              {'kind': 'organization', 'id': _org, 'x': 600, 'y': 300},
            ],
          },
        ),
      );
      await _openStructure(tester);

      expect(
        _delta(tester),
        const Offset(600, 300),
        reason:
            'The arrangement rides in on /me with the theme. Nothing here '
            'waits on a second request before the boxes can be drawn where '
            'they belong.',
      );
    });

    testWidgets('a box it has never heard of is still drawn', (tester) async {
      await _pump(
        tester,
        _server(
          preferences: {
            'organization.canvas': [
              {'kind': 'organization', 'id': _org, 'x': 0, 'y': 0},
            ],
          },
        ),
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
    testWidgets('dragging one box does not make the other five new', (
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
        find.textContaining('5 boxes have not been placed by you'),
        findsOneWidget,
        reason:
            'The honest half stays, because it is what is actually on screen: '
            'five boxes really are where the shape put them rather than where '
            'this person did, which is why one may be sitting on another, and '
            'there is one button that tidies the lot.',
      );
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
      // Measured against a box nobody has touched rather than against the
      // window, because the note above the canvas appears the moment there is a
      // saved arrangement and shifts the whole picture down with it — which is
      // a change of layout and not of arrangement.
      Offset apart() =>
          _rectOf(tester, 'franchise:$_north').topLeft -
          _rectOf(tester, 'organization:$_org').topLeft;

      final settled = apart();

      await tester.drag(
        find.byKey(structureBoxKey('franchise:$_north')),
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
        second.firstWhere((box) => box['id'] == _north),
        {
          'kind': 'franchise',
          'id': _north,
          'x': (from['franchise:$_north']!.dx + 80).round(),
          'y': (from['franchise:$_north']!.dy + 90).round(),
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
        {'boxes'},
        reason:
            'The only wire this tab owns is a list of coordinates. A second '
            'field on this request is the drag having started to move the '
            'business.',
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
        _server(
          saves: saves,
          preferences: {
            'organization.canvas': [
              {'kind': 'company', 'id': _away, 'x': 40, 'y': 80},
            ],
          },
        ),
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

    testWidgets('a successful save lands in the session’s own preferences', (
      tester,
    ) async {
      final session = await _pump(tester, _server());
      await _openStructure(tester);

      expect(
        session.me!.preferences['organization.canvas'],
        isNull,
        reason: 'The premise: nothing was stored before the drag.',
      );

      await tester.drag(
        find.byKey(structureBoxKey('company:$_sun')),
        const Offset(110, 45),
      );
      await _settle(tester);

      final stored = session.me!.preferences['organization.canvas'];

      expect(
        stored,
        isA<List<dynamic>>(),
        reason:
            'The session is holding its own copy of the preferences, and '
            'leaving this tab and coming back reads that copy. Without this, a '
            'save that succeeded is undone on screen by a stale local blob.',
      );
      expect(
        (stored as List).any((b) => (b as Map)['id'] == _sun),
        isTrue,
      );
    });

    testWidgets('a drag is not lost by leaving before it settles', (
      tester,
    ) async {
      final saves = <Map<String, dynamic>>[];

      final session = await _pump(tester, _server(saves: saves));
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

      // And the session was told, which is the half that decides whether the
      // flush was worth making. Nothing in this app refetches /me on
      // navigation, so the session's own copy of the preferences IS what the
      // tab reads next time it is opened: left stale, the boxes go back to
      // their automatic places on a save that succeeded, and the next save —
      // built from that empty copy — replaces the arrangement the server is
      // holding. The drag survived the trip and was destroyed by the drag
      // after it.
      final stored = session.me!.preferences['organization.canvas'];

      expect(stored, isA<List<dynamic>>());
      expect((stored as List).any((box) => (box as Map)['id'] == _sun), isTrue);
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
      await tester.dragFrom(
        const Offset(60, 900),
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

Map<String, dynamic> _tree() => {
  'organization': {
    'id': _org,
    'name': 'Everdoors Group',
    'may_add_company': true,
    'may_add_company_reason': null,
    'has_logo': false,
    'may_edit': false,
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
};

Map<String, dynamic> _company(
  String id,
  String name, {
  String? parent,
  List<Map<String, dynamic>> franchises = const [],
  List<Map<String, dynamic>> subs = const [],
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
  List<Map<String, dynamic>>? saves,
  List<String>? calls,
  int saveStatus = 200,
  String saveError = 'No.',
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
        return _json({
          'message': 'The given data was invalid.',
          'errors': {
            'boxes': [saveError],
          },
        }, saveStatus);
      }

      // Echoes what was STORED, the way the endpoint does — the client applies
      // this answer rather than the value it sent, so a clamped or deduplicated
      // coordinate would show up on screen rather than being corrected behind
      // its back.
      return _json({'boxes': body['boxes']});
    }

    if (request.url.path.endsWith('/companies')) {
      return _json(_tree());
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

Future<Session> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

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

Future<void> _openStructure(WidgetTester tester) async {
  await tester.tap(find.text('Structure'));
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

/// Where the organization box sits relative to a company box.
///
/// Relative rather than absolute, because the canvas offsets everything by its
/// own viewport — which is where you happen to be looking rather than part of
/// the arrangement, and is deliberately never stored.
Offset _delta(WidgetTester tester) =>
    _rectOf(tester, 'organization:$_org').topLeft -
    _rectOf(tester, 'company:$_sun').topLeft;
