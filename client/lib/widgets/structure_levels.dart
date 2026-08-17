/// What level a thing is on, which is a fact about WHAT IT IS.
///
/// ── The rule, in the owner's words ───────────────────────────────
///
///     level 1 - organization
///     level 2 - company
///     level 3 - sub company
///     level 4 - company franchises AND franchises of sub companies
///
/// Zero-based here, because both views multiply it by something — the canvas by
/// a row pitch and the outline by an indent step — and a one-based number would
/// have each of them subtract one before it could be used.
///
/// ── Why a node's level is not how deep the walk went ─────────────
///
/// Because §3 fixes the depth of the business at four, so the kind IS the
/// level. Both views used to derive it from the parent chain instead, and both
/// were wrong in the same way and wrong on a real customer: Everdoors has
/// franchises of its own AND sub companies, and a franchise hanging off the
/// company came out on the same line as a sub company hanging off the same
/// company. The picture then said the two were peers, when a sub company is
/// plainly the bigger thing — it holds franchises of its own.
///
/// ── A place is still level 4, and is no longer DRAWN on a line ───
///
/// The fourth line of the rule is untouched — a place is a place whichever
/// company owns it — but nothing puts a place on a line any more, because a
/// place is not a box.
///
/// A franchise has no children and never can (§3), so it became a row in a
/// compact list attached to the company or sub company that owns it. The
/// customer this was built for has thirteen of them: nine under A-1 Chimney,
/// two under Everdoors, one under each of Everdoors' two sub companies.
/// Thirteen boxes side by side is wider than any window, and the owner's own
/// screenshots showed the tab opening on a fragment of the chart with nothing
/// saying so. Thirteen boxes became four attached lists.
///
/// So [structureLevelOf] still answers 3 for a place, and that answer is now
/// about what a place IS rather than about where it is drawn. Two things read
/// it differently and both are right: [structureHasBox] says a place gets no
/// position at all, so [arrangeStructureFlow] never gives it one; and
/// [structureIndentOf] draws it one step past the company it belongs to, which
/// on a top-level company's place is level 2 rather than level 3.
///
/// That gap between the two used to be the visible one. A-1 Chimney has no sub
/// companies, so its places sat on level 3 with level 2 empty above them — a
/// blank line the canvas had to name in its own note so it did not read as
/// something failing to draw. Nothing sits below a sub company now, so there is
/// no blank line to explain: a branch with no sub companies simply stops.
///
/// ── Why one file rather than a constant in each view ─────────────
///
/// Because the canvas and the outline are two pictures of one business and
/// there is exactly one hierarchy. They had a copy each — the canvas passed a
/// row down its recursion, the outline typed a depth at four call sites — and
/// both copies carried the same bug and had to be found twice. One function is
/// what makes "the outline agrees with the canvas" true by construction rather
/// than by somebody remembering.
///
/// ── Why the automatic layout is here too ────────────────────────
///
/// Because it is the same fact, applied. Where a box goes is entirely decided by
/// what it is and what it hangs off, and both of those are answered above; a
/// layout living somewhere else would be a second reading of the hierarchy, and
/// the whole reason this file exists is that two readings drift.
///
/// It moved here from `structure_canvas.dart` when the chart learned to flow
/// left to right, and the move is what makes that direction TESTABLE: the old
/// one was a closure inside a widget's library, so an assertion about where nine
/// places end up had to pump a frame to get one. [arrangeStructureFlow] takes a
/// list and some measurements and returns a map of points, and it can be asked
/// the same question at any text size without a screen existing at all.
///
/// It takes the measurements rather than knowing them. `kStructureBoxWidth` and
/// its neighbours stay in the widget that draws a box, because that is the thing
/// they are about — and a second copy of them here is exactly how a connector
/// starts landing three pixels off the box it points at.
///
/// ── What "no Flutter" now means, exactly ────────────────────────
///
/// It imports `dart:ui` for [Offset], and nothing else. That is the engine's
/// geometry type — a pair of doubles — rather than a widget: there is still no
/// `material.dart` here, still nothing to pump, and both drawing widgets can
/// still import this without either importing the other, which is what a shared
/// constant in one of them would have forced. Returning a pair of doubles of its
/// own instead would have meant every caller converting at the door, and a
/// second point type is a worse dependency than the one being avoided.
library;

import 'dart:math' as math;
import 'dart:ui' show Offset;

/// The three kinds a box or a row can be, and they are the SERVER's three.
///
/// A sub company is a `company` — a sub company IS a company row, and the model
/// says so with `parent_company_id` rather than with a type. A fourth kind here
/// would be the client inventing a distinction the business does not make, and
/// it would be the first thing to disagree with the arrangement the canvas
/// stores, which is written in these same three words.
///
/// They were spelled twice before this file existed — `structureOrganization`
/// in the canvas and `structureOrganizationKind` in the outline, same string,
/// two names — and two spellings of one vocabulary is the duplication this file
/// exists to end. Both widgets re-export these, so nothing that imported either
/// of them had to change.
const String structureOrganization = 'organization';
const String structureCompany = 'company';
const String structureFranchise = 'franchise';

/// The three, as a closed list.
///
/// The same list as `OrganizationCanvasController::KINDS`, and it is the same
/// question: which words may appear in a stored arrangement. A reader that
/// spelled the check out as two comparisons instead — which is what the
/// arrangement reader did — is a reader that quietly answers a different
/// question every time a kind is added or removed.
///
/// It is NOT the list of things that get a box. That is [structureHasBox], and
/// the two are as different here as `KINDS` and `POSITIONED_KINDS` are on the
/// server: one says what a payload may name, the other what may hold a position.
const List<String> kStructureKinds = [
  structureOrganization,
  structureCompany,
  structureFranchise,
];

/// The four lines, zero-based, in the order the owner wrote them.
const int kStructureLevelOrganization = 0;
const int kStructureLevelCompany = 1;
const int kStructureLevelSubCompany = 2;
const int kStructureLevelFranchise = 3;

/// How many levels there are.
///
/// §3 fixes it and nothing here is configurable, which is the one thing on this
/// screen a customer cannot change: the depth of the hierarchy is what every
/// setting, assignment and roll-up in the product is expressed in terms of.
const int kStructureLevels = 4;

/// The level of one thing.
///
/// [isSub] is only consulted for a company, and it is the same question the
/// server answers with `parent_company_id`. A franchise is level 3 whether it
/// hangs off a company or off a sub company — that is the whole of the owner's
/// fourth line, and it is why this cannot be derived from the walk.
///
/// An unrecognised kind is read as a company rather than thrown on. This is a
/// value that arrives from the network by way of the payload, and a level
/// function that threw would take the whole structure screen down over one word
/// nobody can correct from inside the app; the middle of the chart is the least
/// wrong place to draw something whose kind is unreadable.
int structureLevelOf({required String kind, required bool isSub}) =>
    switch (kind) {
      structureOrganization => kStructureLevelOrganization,
      structureFranchise => kStructureLevelFranchise,
      _ => isSub ? kStructureLevelSubCompany : kStructureLevelCompany,
    };

/// Whether a thing of this kind is a BOX with a position of its own.
///
/// Everything except a place. A franchise has no children and never can, so it
/// is drawn as a row in a list attached to the company above it, and a row in a
/// list has no coordinate — which is why [arrangeStructureFlow] returns nothing
/// for one and why `OrganizationCanvasController::POSITIONED_KINDS` drops a
/// stored `{kind: franchise, x, y}` in both directions.
///
/// Asked of the KIND rather than of a whole node, so the three places that need
/// it can ask without holding one: the layout holds nodes, the tab holds a
/// stored key it has split apart, and the reader holds a word off the wire.
///
/// Written as "not a place" rather than as a list of the two kinds that do get a
/// box, because that is the actual rule — a thing gets a box unless it can never
/// have anything under it — and a list would have to be edited by anybody adding
/// a kind, silently, in the direction of drawing it wrong.
bool structureHasBox(String kind) => kind != structureFranchise;

/// How far in a row is drawn in the OUTLINE, which is not always its level.
///
/// [ancestors] is how many companies stand above the row — 0 for the
/// organization and for a top-level company, 1 for a sub company and for a
/// top-level company's place, 2 for a sub company's place. It is the length of
/// the path the outline already carries on every row to tell four places called
/// Connecticut apart, so nothing new has to be worked out to ask this.
///
/// ── Why a place is not indented by its level ─────────────────────
///
/// Because it is no longer drawn on a line; it is attached to something. On the
/// canvas a place is a row in a list under the company that owns it, one step in
/// from that company and no further. An outline that indented A-1 Chimney's
/// places by their LEVEL would step them in twice — leaving an empty guide rail
/// where a sub company would have been — and the two views would then be saying
/// different things about the same nine places: attached to the company here,
/// two levels below it there.
///
/// That empty step used to be correct, and it is worth saying why it stopped
/// being. The canvas drew places on a line of their own, so a company with no
/// sub companies genuinely left the third line empty and its places genuinely
/// sat on the fourth; the rail was marking levels rather than rows, and the
/// canvas said the same thing by leaving a line blank. Neither view has that
/// line any more.
int structureIndentOf({
  required String kind,
  required bool isSub,
  required int ancestors,
}) => kind == structureFranchise
    ? ancestors + 1
    : structureLevelOf(kind: kind, isSub: isSub);

// ── which way the picture flows ────────────────────────────────────

/// Depth rightward, siblings stacked downward.
///
/// The default, and the reason is the real data rather than a taste: one
/// customer has nine places under a single company and four more under three
/// others. Down is the direction a screen scrolls, so stacking siblings downward
/// costs nothing at all, while thirteen of anything across is wider than any
/// window that exists.
const String structureLeftToRight = 'left_to_right';

/// Depth downward, siblings side by side — the chart the tab shipped with.
///
/// Kept because the owner asked for both. A business four companies wide reads
/// perfectly well this way, and it is the shape most people picture when they
/// hear "org chart", so removing it would be taking away a view somebody was
/// already using to answer a question.
const String structureTopToBottom = 'top_to_bottom';

/// The two, as a closed list.
///
/// Closed because this word is stored in one shared setting and validated
/// against the same closed list by `OrganizationCanvasController::ORIENTATIONS`.
/// The two lists are two spellings of one rule, which is a duplication worth
/// naming: the server is the one that enforces it, and this one exists so a
/// stored word nobody recognises draws SOMETHING rather than nothing.
const List<String> kStructureOrientations = [
  structureLeftToRight,
  structureTopToBottom,
];

/// Which way a chart flows when nobody has said, or when what they said is not
/// a direction this build knows about.
const String kStructureDefaultOrientation = structureLeftToRight;

/// One orientation, read off whatever arrived.
///
/// Anything unrecognised — a null, a number, a word from a build that is not
/// this one — reads as the default rather than throwing. This value comes off
/// the network by way of a JSON column that can have been hand-edited or
/// restored from a backup, and an orientation that threw would be a structure
/// screen nobody could open over one word nobody can correct from inside the
/// app. The server cleans the same value the same way on the way out, so this
/// is the second net rather than the only one.
String structureOrientationFrom(Object? stored) =>
    stored is String && kStructureOrientations.contains(stored)
    ? stored
    : kStructureDefaultOrientation;

// ── the measurements a place list adds ─────────────────────────────

/// One row of a box's attached list of places, at the reader's default text
/// size.
///
/// Smaller than a box's 72 and not much smaller, and the reason is the CONTROL
/// rather than the words. A place row says less than a box does — a glyph, the
/// name and the organization's word for a place, against a box's two lines plus
/// a move handle plus the caller's menu — but it still carries the same action
/// menu the list tab and the outline give a place, because a picture of the
/// business that cannot rename a branch is a picture people leave to go and find
/// the list.
///
/// That menu is a tap target, and Flutter's own interactive minimum is 48. This
/// number was 26 while nothing had drawn a row yet, and 26 is the height of the
/// TEXT: a `PopupMenuButton` in a 26-point row does not shrink to fit, it lays
/// out at its minimum and overflows the row it is in, which is a striped bar
/// across the chart in debug and a clipped control in release. Shrinking the
/// button instead was the other way out and is the wrong one for the same reason
/// it is wrong inside a box (see `_moveButton`): a control small enough to fit
/// is a control a finger misses.
///
/// Nine of these under a company is 438 points, which is about six boxes tall
/// and fits on a laptop beside everything else in the real business; nine BOXES
/// side by side was 2,628 points wide, which is three screens. The change this
/// number is part of is still doing what it was for.
///
/// Here rather than in the widget that draws it, unlike the box measurements
/// next door, and the difference is which way the dependency runs: the box
/// numbers already live in the canvas and this file is careful not to copy them,
/// while a place row is a thing this file invented and the canvas asks for. It
/// is passed to [arrangeStructureFlow] as a default rather than read by it, so
/// the canvas can scale it with the reader's text size exactly as it scales a
/// box's height.
const double kStructurePlaceRowHeight = 48;

/// Between the bottom of a box and the first place hanging off it.
const double kStructurePlacesGap = 6;

/// How much room a box's attached places take underneath it.
///
/// Zero for a company with none, which is not the same as "one row of nothing":
/// most companies in a real structure have places and some have none, and a gap
/// reserved for an absent list would put every box in that branch out of line
/// with its siblings for no reason a reader could see.
///
/// DOWNWARD in both orientations, which is why this takes no orientation. A
/// list of names reads down the page whichever way the chart flows, and a
/// vertical list beside a left-to-right chart is the same list as a vertical
/// list beneath a top-down one. It is the branches that turn, not the words.
double structurePlacesHeight(
  int places, {
  double rowHeight = kStructurePlaceRowHeight,
  double gap = kStructurePlacesGap,
}) => places <= 0 ? 0 : gap + places * rowHeight;

// ── the automatic layout ───────────────────────────────────────────

/// What the automatic layout needs to know about one thing in the business.
///
/// An interface rather than a class, and that is the whole point of it. The node
/// model lives in `structure_canvas.dart` — `StructureNode`, built by
/// `flattenStructure` from the payload — and there must not be a second one for
/// it to disagree with. `StructureNode` already has all four of these members;
/// declaring that it implements this is what lets the layout be a pure function
/// in a file with no widgets in it, with no conversion at the call site and no
/// import running from this file back to a widget.
///
/// Four members, and no more, because the layout is not allowed to care about
/// anything else. It cannot see a name, so it cannot size a box to one; it
/// cannot see `is_active`, so it cannot decide a switched-off company deserves
/// less room. Both of those are how an arrangement starts changing under people
/// after a rename or a toggle.
abstract interface class StructureItem {
  /// `kind:id`, the way a stored arrangement names a box.
  String get key;

  /// One of [structureOrganization], [structureCompany], [structureFranchise].
  String get kind;

  /// The key of the thing this hangs off, or null for the organization.
  String? get parentKey;

  /// A company whose parent is a company. Not a fourth [kind].
  bool get isSub;
}

/// A place for every BOX, laid out as an org chart that flows one way or the
/// other.
///
/// ── What changed, and what did not ───────────────────────────────
///
/// Two things changed and they are the same change twice. The chart flows LEFT
/// TO RIGHT by default — depth rightward, siblings stacked downward — and a
/// place is no longer a box, so it takes no position and adds its height to the
/// company it hangs off instead. Both exist because thirteen places on one line
/// is wider than any window, and both were picked by the owner from mockups.
///
/// What did not change is the tidy pass. A subtree takes as much room along the
/// stacking axis as its children laid end to end, or as much as the thing itself
/// needs, whichever is more; a parent is centred over the span its children
/// occupy; siblings run in tree order. That is the same walk that was in the
/// canvas, with the axes swapped by one flag rather than written out twice —
/// two copies of a tree layout is two places for "centred over its children" to
/// be subtly different.
///
/// ── Why the two orientations are not symmetrical ─────────────────
///
/// Because places always stack downward. In [structureLeftToRight] a box's
/// attached list makes it TALLER, which is the axis siblings are spread along,
/// so the list is part of the space the tidy pass reserves. In
/// [structureTopToBottom] the list makes it taller too — the same list, the same
/// direction — but there the tall axis is DEPTH, so it is the distance to the
/// next level down that has to grow instead, by the tallest box on the level
/// above. That is why levels are spaced by measurement in one orientation and by
/// a fixed pitch in the other, and it is not an inconsistency to be tidied away.
///
/// ── Deliberately NOT a general tree-layout algorithm ─────────────
///
/// §3 fixes the depth at four, so a general one would be code with no second
/// caller encoding a depth the model forbids. It is also why an item's depth
/// comes from [structureLevelOf] rather than from how far the walk has got:
/// a company's own place and a sub company of the same company are not peers,
/// and the walk cannot tell them apart.
///
/// **Deterministic.** The same list in gives an identical map out, every time,
/// which is what makes "Arrange for me" pressed twice a no-op instead of a
/// slowly drifting picture — and what lets a test assert exact numbers rather
/// than "roughly tidy".
///
/// The measurements are all handed in. [boxWidth] and [boxHeight] belong to the
/// widget that draws a box and stay there, and [boxHeight] is a box's height AT
/// THE READER'S TEXT SIZE rather than the constant, because rows laid out
/// against a different height than the boxes are drawn at is an automatic layout
/// that stacks them on top of each other for anybody who turned their font up.
/// [placeRowHeight] is the same story for a place row.
Map<String, Offset> arrangeStructureFlow(
  List<StructureItem> items, {
  String orientation = kStructureDefaultOrientation,
  required double boxWidth,
  required double boxHeight,
  required double gapBetweenSiblings,
  required double gapBetweenLevels,
  required double margin,
  double placeRowHeight = kStructurePlaceRowHeight,
  double placesGap = kStructurePlacesGap,
}) {
  if (items.isEmpty) return const {};

  // Anything that is not the other one is the default, rather than an assert or
  // a throw. This word can arrive from a stored blob, and a chart that refuses
  // to draw is worse than a chart drawn the usual way round.
  final downward = orientation == structureTopToBottom;

  final byKey = {for (final item in items) item.key: item};

  /// How many places hang off each box, which is the only thing a place
  /// contributes to the geometry.
  final attached = <String, int>{};
  final children = <String, List<StructureItem>>{};
  final roots = <StructureItem>[];

  for (final item in items) {
    final parentKey = item.parentKey;
    final parent = parentKey == null ? null : byKey[parentKey];

    if (!structureHasBox(item.kind)) {
      // A place is counted against the box it hangs off and given no position
      // of its own. A place whose owner is not in the list is counted nowhere
      // and drawn nowhere — `flattenStructure` cannot produce one, because a
      // company it could not read takes its franchises with it, and the outline
      // lists every row whatever the canvas can draw.
      if (parent != null && structureHasBox(parent.kind)) {
        attached[parent.key] = (attached[parent.key] ?? 0) + 1;
      }

      continue;
    }

    // A box whose parent is not in the list, or whose parent is a place, is laid
    // out as a root: it gets a band of the stacking axis to itself rather than
    // being dropped. It should not happen — the payload is nested — but a box
    // nobody can find is worse than a box in an odd place. Its DEPTH is still
    // its kind, so a stray sub company appears in the sub-company column rather
    // than beside the organization.
    if (parent == null || !structureHasBox(parent.kind)) {
      roots.add(item);
    } else {
      (children[parent.key] ??= <StructureItem>[]).add(item);
    }
  }

  /// A box and everything attached to it, top to bottom.
  double heightOf(StructureItem item) =>
      boxHeight +
      structurePlacesHeight(
        attached[item.key] ?? 0,
        rowHeight: placeRowHeight,
        gap: placesGap,
      );

  /// How much room the thing takes along the axis siblings are spread along.
  double stackExtent(StructureItem item) =>
      downward ? boxWidth : heightOf(item);

  /// And along the axis depth runs down.
  double depthExtent(StructureItem item) =>
      downward ? heightOf(item) : boxWidth;

  // Where each level starts along the depth axis.
  //
  // Measured rather than multiplied, because in top-to-bottom a level is as deep
  // as its tallest box WITH ITS PLACES — A-1 Chimney's nine of them are 240
  // points on their own — and a fixed pitch would run the sub companies straight
  // through the middle of that list. Left to right needs no such care, since
  // every box is the same width, but the same loop answers both.
  //
  // A level with nothing on it takes no room at all, which is the change from
  // the layout this replaces. That one held the sub-company band open even when
  // a branch had none, so that every place in the business lined up on the line
  // below; there is no line below any more, so an empty band would be a gap with
  // nothing under it and nothing to explain it.
  final depths = <int, double>{};
  final onLevel = <int, List<StructureItem>>{};

  for (final item in items) {
    if (!structureHasBox(item.kind)) continue;

    (onLevel[structureLevelOf(kind: item.kind, isSub: item.isSub)] ??=
            <StructureItem>[])
        .add(item);
  }

  var depth = 0.0;

  for (var level = 0; level < kStructureLevels; level++) {
    depths[level] = depth;

    final here = onLevel[level];

    if (here == null) continue;

    depth +=
        here.map(depthExtent).reduce(math.max) + gapBetweenLevels;
  }

  // How much of the stacking axis a subtree needs: its children end to end, or
  // the thing itself, whichever is more.
  final spans = <String, double>{};

  double measure(StructureItem item) {
    final kids = children[item.key] ?? const <StructureItem>[];

    if (kids.isEmpty) return spans[item.key] = stackExtent(item);

    var total = -gapBetweenSiblings;

    for (final kid in kids) {
      total += measure(kid) + gapBetweenSiblings;
    }

    return spans[item.key] = math.max(stackExtent(item), total);
  }

  final placed = <String, Offset>{};

  void place(StructureItem item, double start) {
    final span = spans[item.key]!;
    final kids = children[item.key] ?? const <StructureItem>[];

    // Centred in its own span, so a company with one place sits opposite that
    // place rather than at the top of the room its nine-place sibling needed.
    final along = start + (span - stackExtent(item)) / 2;
    final across = depths[structureLevelOf(kind: item.kind, isSub: item.isSub)]!;

    placed[item.key] = downward
        ? Offset(along, across)
        : Offset(across, along);

    if (kids.isEmpty) return;

    var run = -gapBetweenSiblings;

    for (final kid in kids) {
      run += spans[kid.key]! + gapBetweenSiblings;
    }

    // Centred against the parent when the children need less room than it does,
    // which happens whenever a company holds more places than sub companies.
    var next = start + (span - run) / 2;

    for (final kid in kids) {
      place(kid, next);
      next += spans[kid.key]! + gapBetweenSiblings;
    }
  }

  var cursor = 0.0;

  for (final root in roots) {
    measure(root);
    place(root, cursor);
    cursor += spans[root.key]! + gapBetweenSiblings;
  }

  // Shifted so the whole picture starts at the margin. The origin is the top
  // left of the viewport and nothing scrolls above or left of it, so a box at a
  // negative coordinate is a box nobody can reach — and centring a parent
  // against a taller child produces negative numbers routinely.
  var minX = double.infinity;
  var minY = double.infinity;

  for (final at in placed.values) {
    minX = math.min(minX, at.dx);
    minY = math.min(minY, at.dy);
  }

  final shift = Offset(margin - minX, margin - minY);

  return {
    for (final entry in placed.entries)
      entry.key: Offset(
        math.max(0, entry.value.dx + shift.dx),
        math.max(0, entry.value.dy + shift.dy),
      ),
  };
}
