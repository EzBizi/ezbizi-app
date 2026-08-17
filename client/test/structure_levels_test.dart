import 'dart:math' show min;
import 'dart:ui';

import 'package:ezbizi_client/widgets/structure_canvas.dart'
    show
        kStructureBoxHeight,
        kStructureBoxWidth,
        kStructureGapX,
        kStructureGapY,
        kStructureMargin;
import 'package:ezbizi_client/widgets/structure_levels.dart';
import 'package:flutter_test/flutter_test.dart';

/// One hierarchy, one answer.
///
/// The canvas and the outline both draw the structure and both used to work out
/// a level for themselves — the canvas by counting recursion, the outline by
/// typing a number at four call sites. Both copies carried the same bug, and it
/// took a customer to find it: a company's own place drew level with a sub
/// company of the same company, saying the two are the same size of thing.
///
/// So the level is a fact about WHAT A THING IS, and it lives in one function
/// with no widgets around it. These are the assertions about that function
/// alone. `structure_canvas_test.dart` asserts the canvas lays boxes out by it,
/// `structure_outline_test.dart` asserts the outline indents by it, and one
/// test in the outline file asserts the two agree on every node.
void main() {
  group('a level is what a thing is', () {
    test('the organization is on the first line', () {
      expect(
        structureLevelOf(kind: structureOrganization, isSub: false),
        kStructureLevelOrganization,
      );
    });

    test('a top-level company is on the second', () {
      expect(
        structureLevelOf(kind: structureCompany, isSub: false),
        kStructureLevelCompany,
      );
    });

    test('a sub company is on the third', () {
      expect(
        structureLevelOf(kind: structureCompany, isSub: true),
        kStructureLevelSubCompany,
      );
    });

    /// The owner's fourth line, verbatim: "company franchises AND franchises of
    /// sub companies". This is the assertion the old walk-derived row could not
    /// make, because it answered with how far it had got rather than with what
    /// it was looking at.
    test('a place is on the fourth whichever company owns it', () {
      expect(
        structureLevelOf(kind: structureFranchise, isSub: false),
        kStructureLevelFranchise,
      );
      expect(
        structureLevelOf(kind: structureFranchise, isSub: true),
        kStructureLevelFranchise,
      );
    });

    /// [isSub] is a question about a company and nothing else. A place is a
    /// place; asking it whether it hangs off a sub company is asking about its
    /// parent, and its parent is not what decides its level.
    test('being under a sub company changes nothing about a place', () {
      expect(
        structureLevelOf(kind: structureFranchise, isSub: true),
        structureLevelOf(kind: structureFranchise, isSub: false),
      );
    });

    test('the four lines are four distinct numbers, in the owner’s order', () {
      const lines = [
        kStructureLevelOrganization,
        kStructureLevelCompany,
        kStructureLevelSubCompany,
        kStructureLevelFranchise,
      ];

      expect(lines.toSet(), hasLength(kStructureLevels));

      for (var i = 1; i < lines.length; i++) {
        expect(
          lines[i],
          greaterThan(lines[i - 1]),
          reason: 'The levels are drawn top to bottom in this order, so a '
              'number out of sequence would draw the business upside down.',
        );
      }
    });
  });

  group('the vocabulary is the server’s', () {
    /// These three strings travel: they are what a stored arrangement is keyed
    /// by, and the save endpoint refuses a kind it does not recognise. A typo
    /// here would not be a cosmetic bug — it would be every future save
    /// refused, with nothing on screen a person could do about it.
    test('the three kinds are the three words the payload uses', () {
      expect(structureOrganization, 'organization');
      expect(structureCompany, 'company');
      expect(structureFranchise, 'franchise');
    });

    /// A sub company is a `company`. Not a fourth kind — the model says so with
    /// `parent_company_id` rather than with a type, and a fourth word here
    /// would be the client inventing a distinction the business does not make.
    test('there is no fourth kind for a sub company', () {
      expect(
        {structureOrganization, structureCompany, structureFranchise},
        hasLength(3),
      );
    });

    /// This is a value that arrives from the network. A level function that
    /// threw on a word it did not know would take the whole structure screen
    /// down over one entry nobody can correct from inside the app.
    test('a kind nobody recognises is drawn rather than thrown on', () {
      expect(
        structureLevelOf(kind: 'something else entirely', isSub: false),
        kStructureLevelCompany,
      );
    });

    /// The client's copy of `OrganizationCanvasController::KINDS`. A word
    /// missing from it is an entry the reader drops on the way in, so a stored
    /// arrangement would come back short with nothing saying why.
    test('the vocabulary list is those three and nothing else', () {
      expect(kStructureKinds, [
        structureOrganization,
        structureCompany,
        structureFranchise,
      ]);
    });
  });

  group('a place is no longer a box', () {
    /// The whole of change (2b) in one function. A franchise has no children and
    /// never can, so it is a row in a list attached to its company — and a row
    /// in a list has no coordinate for anybody to store.
    test('everything except a place has a position of its own', () {
      expect(structureHasBox(structureOrganization), isTrue);
      expect(structureHasBox(structureCompany), isTrue);
      expect(structureHasBox(structureFranchise), isFalse);
    });

    /// Asked of a word off the wire as well as of a node, so it must answer for
    /// one it has never seen. Drawing something unrecognised is the same call
    /// [structureLevelOf] makes: a box in the middle of the chart is recoverable,
    /// a screen that will not open is not.
    test('a kind nobody recognises still gets a box', () {
      expect(structureHasBox('something else entirely'), isTrue);
    });

    /// Zero rather than the height of an empty list. A gap reserved for places a
    /// company does not have would put every box in that branch out of line with
    /// its siblings for a reason no reader could see.
    test('a box with no places is no taller for it', () {
      expect(structurePlacesHeight(0), 0);
      expect(structurePlacesHeight(-1), 0);
    });

    test('and nine of them is nine rows and one gap', () {
      expect(
        structurePlacesHeight(9, rowHeight: 26, gap: 6),
        6 + 9 * 26,
      );
    });

    /// The measurements scale with the reader's text size, exactly as a box's
    /// height does. A layout worked out against 26 while the rows are drawn at
    /// 52 is nine places overflowing the space reserved for them, for the
    /// readers most likely to have turned their text up.
    test('a place row is as tall as the reader’s text makes it', () {
      expect(
        structurePlacesHeight(4, rowHeight: 52, gap: 12),
        greaterThan(structurePlacesHeight(4, rowHeight: 26, gap: 6)),
      );
    });
  });

  group('how far in the outline steps a row', () {
    /// The three that have boxes are indented by their level, which is what they
    /// always were.
    test('a box is indented by its level', () {
      expect(
        structureIndentOf(
          kind: structureOrganization,
          isSub: false,
          ancestors: 0,
        ),
        kStructureLevelOrganization,
      );
      expect(
        structureIndentOf(kind: structureCompany, isSub: false, ancestors: 0),
        kStructureLevelCompany,
      );
      expect(
        structureIndentOf(kind: structureCompany, isSub: true, ancestors: 1),
        kStructureLevelSubCompany,
      );
    });

    /// The change, and the one worth being careful about. A place used to be
    /// indented by its LEVEL, which put a top-level company's places two steps
    /// in with an empty guide rail between — right when the canvas drew every
    /// place on one line, and wrong now that a place hangs off the company that
    /// owns it in both views.
    test('a place is one step past the company it belongs to', () {
      expect(
        structureIndentOf(kind: structureFranchise, isSub: false, ancestors: 1),
        kStructureLevelCompany + 1,
      );
      expect(
        structureIndentOf(kind: structureFranchise, isSub: false, ancestors: 2),
        kStructureLevelSubCompany + 1,
      );
    });

    /// And the empty step is gone, which is the thing a reader actually sees.
    /// A rail with no row beside it reads as a sub company that failed to load.
    test('a top-level company’s place leaves no empty step above it', () {
      final company = structureIndentOf(
        kind: structureCompany,
        isSub: false,
        ancestors: 0,
      );
      final place = structureIndentOf(
        kind: structureFranchise,
        isSub: false,
        ancestors: 1,
      );

      expect(place - company, 1);
    });
  });

  group('which way the chart flows', () {
    /// A closed list, because this word lands in one shared JSON column and the
    /// server validates it against the same two. A third would be a value stored
    /// for every colleague that nothing draws and nothing clears.
    test('there are two orientations and left to right is the default', () {
      expect(kStructureOrientations, [
        structureLeftToRight,
        structureTopToBottom,
      ]);
      expect(kStructureDefaultOrientation, structureLeftToRight);
    });

    /// The words travel: they are what a stored arrangement holds and what the
    /// endpoint refuses when it does not recognise them. A typo here would be
    /// every save refused with nothing on screen anybody could do about it.
    test('the two words are the two the payload uses', () {
      expect(structureLeftToRight, 'left_to_right');
      expect(structureTopToBottom, 'top_to_bottom');
    });

    test('a stored orientation is read back as itself', () {
      expect(structureOrientationFrom('top_to_bottom'), structureTopToBottom);
      expect(structureOrientationFrom('left_to_right'), structureLeftToRight);
    });

    /// This value comes out of a JSON column that can have been hand-edited or
    /// restored from a backup taken before it existed. An orientation that threw
    /// would be a structure screen nobody in the organization could open, over
    /// one word nobody can correct from inside the app.
    test('anything else reads as the default rather than throwing', () {
      expect(structureOrientationFrom(null), kStructureDefaultOrientation);
      expect(structureOrientationFrom('diagonal'), kStructureDefaultOrientation);
      expect(structureOrientationFrom(4), kStructureDefaultOrientation);
      expect(
        structureOrientationFrom(const {'orientation': 'top_to_bottom'}),
        kStructureDefaultOrientation,
      );
    });
  });

  group('the automatic layout, on the business it was built for', () {
    test('every box gets a place and no place gets one', () {
      final placed = _arrange(structureLeftToRight);

      expect(
        placed.keys.toSet(),
        {_a1, _chimney, _everdoors, _premier, _speedy},
        reason:
            'Five boxes: the business, two companies, two sub companies. The '
            'thirteen places are rows in the lists attached to them, and a row '
            'in a list has nowhere to put a coordinate.',
      );
    });

    /// The premise of the whole change, asserted so it cannot quietly stop being
    /// true: thirteen boxes became four attached lists.
    test('the thirteen places would have been thirteen more boxes', () {
      expect(
        _a1Group().where((item) => item.kind == structureFranchise),
        hasLength(13),
      );
      expect(_arrange(structureLeftToRight), hasLength(5));
    });

    test('left to right runs depth rightward and stacks siblings downward', () {
      final placed = _arrange(structureLeftToRight);

      expect(
        placed[_chimney]!.dx,
        greaterThan(placed[_a1]!.dx),
        reason: 'A company is to the right of the business it belongs to.',
      );
      expect(
        placed[_premier]!.dx,
        greaterThan(placed[_chimney]!.dx),
        reason: 'A sub company is one step further right again.',
      );

      // Siblings share a column and are spread down it.
      expect(placed[_chimney]!.dx, placed[_everdoors]!.dx);
      expect(placed[_premier]!.dx, placed[_speedy]!.dx);
      expect(placed[_chimney]!.dy, isNot(placed[_everdoors]!.dy));
    });

    test('top to bottom runs depth downward and siblings side by side', () {
      final placed = _arrange(structureTopToBottom);

      expect(placed[_chimney]!.dy, greaterThan(placed[_a1]!.dy));
      expect(placed[_premier]!.dy, greaterThan(placed[_chimney]!.dy));

      expect(placed[_chimney]!.dy, placed[_everdoors]!.dy);
      expect(placed[_premier]!.dy, placed[_speedy]!.dy);
      expect(placed[_chimney]!.dx, isNot(placed[_everdoors]!.dx));
    });

    /// The one that would actually be noticed on screen, in both orientations.
    ///
    /// A box is not just a box any more: it is a box AND the list of places
    /// hanging off it, and A-1 Chimney's nine of them are taller than three
    /// boxes. A layout that reserved room for the box alone would draw the sub
    /// companies straight through the middle of that list — which is exactly
    /// what the layout this replaces would do, because it had never heard of an
    /// attached list.
    for (final orientation in kStructureOrientations) {
      test('no two boxes overlap, flowing $orientation', () {
        final placed = _arrange(orientation);
        final keys = placed.keys.toList();

        for (var i = 0; i < keys.length; i++) {
          for (var j = i + 1; j < keys.length; j++) {
            final a = _boxRect(keys[i], placed[keys[i]]!);
            final b = _boxRect(keys[j], placed[keys[j]]!);

            expect(
              a.overlaps(b),
              isFalse,
              reason:
                  '${keys[i]} at $a runs into ${keys[j]} at $b. Two boxes on '
                  'top of each other is a chart nobody can read, and the '
                  'automatic layout is the one thing that must never produce '
                  'one.',
            );
          }
        }
      });

      /// The same claim on the shape this business is one decision away from:
      /// A-1 Chimney with its nine places AND a sub company under it.
      ///
      /// It is its own test because the real data cannot catch the bug it is
      /// about. On the business as it stands, the company with nine places has
      /// no children and the company with children has two places, so a layout
      /// that spaced the levels by a box's height rather than by the tallest box
      /// WITH its list would draw a perfectly clean chart — the tall list is in
      /// one column and the sub companies are in another, and two columns cannot
      /// run into each other. Give the nine-place company one sub company and
      /// the sub company is drawn through the middle of the list.
      test('nor when a company holds nine places and a sub company, flowing '
          '$orientation', () {
        final items = [
          ..._a1Group(),
          const _Item(
            key: 'company:chimney-commercial',
            kind: structureCompany,
            parentKey: _chimney,
            isSub: true,
          ),
        ];

        final placed = _arrangeThese(items, orientation);
        final keys = placed.keys.toList();

        int placesIn(String key) => items
            .where((i) => i.kind == structureFranchise && i.parentKey == key)
            .length;

        Rect rect(String key) =>
            placed[key]! &
            Size(
              kStructureBoxWidth,
              kStructureBoxHeight + structurePlacesHeight(placesIn(key)),
            );

        for (var i = 0; i < keys.length; i++) {
          for (var j = i + 1; j < keys.length; j++) {
            expect(
              rect(keys[i]).overlaps(rect(keys[j])),
              isFalse,
              reason:
                  '${keys[i]} at ${rect(keys[i])} runs into ${keys[j]} at '
                  '${rect(keys[j])}.',
            );
          }
        }
      });

      test('nothing is drawn off the top or the left, flowing $orientation', () {
        // The origin is the top left of what can be scrolled to, so a negative
        // coordinate is a box nobody can reach. Centring a parent against a
        // taller child produces them routinely before the shift.
        for (final at in _arrange(orientation).values) {
          expect(at.dx, greaterThanOrEqualTo(0));
          expect(at.dy, greaterThanOrEqualTo(0));
        }

        expect(
          _arrange(orientation).values.map((at) => at.dx).reduce(min),
          kStructureMargin,
          reason: 'The picture starts at the margin rather than flush against '
              'the edge it can be dragged past.',
        );
      });

      test('the same business arranged twice is the same picture, flowing '
          '$orientation', () {
        // What makes "Arrange for me" pressed twice a no-op rather than a
        // slowly drifting chart.
        expect(_arrange(orientation), _arrange(orientation));
      });
    }

    /// Nine places make A-1 Chimney a tall thing to lay out beside, and the
    /// sibling below it has to start below all nine — not below the box.
    test('a company’s places are room its siblings leave for it', () {
      final placed = _arrange(structureLeftToRight);

      expect(
        placed[_everdoors]!.dy - placed[_chimney]!.dy,
        greaterThan(kStructureBoxHeight + structurePlacesHeight(9)),
        reason:
            'Everdoors starts above the bottom of A-1 Chimney’s nine places, '
            'so the two lists are drawn through each other.',
      );
    });

    /// The same fact turned ninety degrees: in top-to-bottom the list makes the
    /// LEVEL deeper rather than the sibling further down, because places stack
    /// downward whichever way the branches run.
    test('and in top to bottom they push the next level down instead', () {
      final placed = _arrange(structureTopToBottom);

      expect(
        placed[_premier]!.dy - placed[_chimney]!.dy,
        greaterThan(kStructureBoxHeight + structurePlacesHeight(9)),
        reason:
            'The sub companies are drawn through the middle of A-1 Chimney’s '
            'list of nine places.',
      );
    });

    /// An empty list of items is a business that failed to load, not a crash.
    test('nothing at all arranges to nothing', () {
      expect(_arrangeThese(const [], structureLeftToRight), isEmpty);
    });

    /// A box whose parent is not in the list gets a band to itself rather than
    /// being dropped, and its DEPTH is still its kind — a stray sub company
    /// belongs in the sub-company column, not beside the organization.
    test('a box with no parent in the list is still drawn, in its own column', () {
      final placed = _arrangeThese(
        const [
          _Item(key: _a1, kind: structureOrganization),
          _Item(key: 'company:stray', kind: structureCompany, isSub: true),
        ],
        structureLeftToRight,
      );

      expect(placed, hasLength(2));
      expect(
        placed['company:stray']!.dx,
        greaterThan(placed[_a1]!.dx),
        reason: 'Its column is what it IS, not how the walk reached it.',
      );
    });

    /// An orientation nobody recognises draws the default rather than nothing.
    /// This word can arrive from a stored blob, and a chart that refuses to draw
    /// is worse than one drawn the usual way round.
    test('an orientation nobody recognises is drawn left to right', () {
      expect(_arrange('sideways'), _arrange(structureLeftToRight));
    });
  });
}

// ── the real business, which is what makes this necessary ──────────
//
// A1-Group, as it actually stands: A-1 Chimney with nine places and no sub
// companies, Everdoors with two of its own and two sub companies holding one
// each. Thirteen places, and thirteen boxes side by side is wider than any
// window — which is the whole reason places stopped being boxes.

const _a1 = 'organization:a1-group';
const _chimney = 'company:a-1-chimney';
const _everdoors = 'company:everdoors';
const _premier = 'company:premier-garage-repair';
const _speedy = 'company:speedy-garage-doors';

/// Tree order, the way `flattenStructure` produces it: a company, then its own
/// places, then its sub companies with theirs.
List<StructureItem> _a1Group() => [
  const _Item(key: _a1, kind: structureOrganization),

  const _Item(key: _chimney, kind: structureCompany, parentKey: _a1),
  for (final name in [
    'connecticut',
    'dmv',
    'georgia',
    'illinois',
    'nationwide',
    'new-jersey',
    'ohio',
    'texas',
    'washington',
  ])
    _Item(
      key: 'franchise:chimney-$name',
      kind: structureFranchise,
      parentKey: _chimney,
    ),

  const _Item(key: _everdoors, kind: structureCompany, parentKey: _a1),
  for (final name in ['connecticut', 'dmv'])
    _Item(
      key: 'franchise:everdoors-$name',
      kind: structureFranchise,
      parentKey: _everdoors,
    ),

  const _Item(
    key: _premier,
    kind: structureCompany,
    parentKey: _everdoors,
    isSub: true,
  ),
  const _Item(
    key: 'franchise:premier-connecticut',
    kind: structureFranchise,
    parentKey: _premier,
  ),

  const _Item(
    key: _speedy,
    kind: structureCompany,
    parentKey: _everdoors,
    isSub: true,
  ),
  const _Item(
    key: 'franchise:speedy-connecticut',
    kind: structureFranchise,
    parentKey: _speedy,
  ),
];

/// The layout, given the canvas's own measurements.
///
/// Imported rather than typed out. A box's size belongs to the widget that draws
/// one, and a second copy of those numbers in a test is a test that keeps
/// passing while the picture goes wrong — which is the same argument the layout
/// makes for taking them as arguments instead of knowing them.
Map<String, Offset> _arrangeThese(
  List<StructureItem> items,
  String orientation,
) => arrangeStructureFlow(
  items,
  orientation: orientation,
  boxWidth: kStructureBoxWidth,
  boxHeight: kStructureBoxHeight,
  gapBetweenSiblings: kStructureGapX,
  gapBetweenLevels: kStructureGapY,
  margin: kStructureMargin,
);

Map<String, Offset> _arrange(String orientation) =>
    _arrangeThese(_a1Group(), orientation);

/// One box and everything hanging off it, which is what must not overlap
/// anything else. A box's own rectangle would be the easy assertion and the
/// wrong one: it is the nine place rows below A-1 Chimney that a sibling runs
/// into.
Rect _boxRect(String key, Offset at) =>
    at &
    Size(
      kStructureBoxWidth,
      kStructureBoxHeight + structurePlacesHeight(_placesUnder(key)),
    );

int _placesUnder(String key) => _a1Group()
    .where(
      (item) => item.kind == structureFranchise && item.parentKey == key,
    )
    .length;

/// A thing in the business, with only what the layout is allowed to know about
/// it.
///
/// `StructureNode` is the real one and implements this same interface; a test
/// double is used here so an assertion about geometry does not have to build a
/// payload, and so this file stays free of the widget library the node model
/// lives in.
class _Item implements StructureItem {
  const _Item({
    required this.key,
    required this.kind,
    this.parentKey,
    this.isSub = false,
  });

  @override
  final String key;

  @override
  final String kind;

  @override
  final String? parentKey;

  @override
  final bool isSub;
}
