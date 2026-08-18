/// The organization's structure as boxes on a free canvas, arranged by hand.
///
/// Organization → Company → Sub Company → Franchise (§3), drawn rather than
/// listed, with the boxes placed wherever the person looking at them wants them.
/// The list on the other tab is the view that CHANGES the business; this one is
/// the view that shows its shape, and the two draw the same nodes from the same
/// payload so they cannot disagree about what exists.
///
/// ── THREE OF THE FOUR ARE BOXES, AND WHICH WAY IT FLOWS IS A CHOICE ──
///
/// A franchise has no children and never can (§3), so it is not a box: it is a
/// row in the compact list attached to the company or sub company that owns it,
/// and it has no position of its own for anybody to store or drag. The business
/// this was built for has thirteen of them under four companies, and thirteen
/// boxes on one line is wider than any window — the owner's screenshots showed
/// the tab opening on a fragment of its own chart with nothing on screen saying
/// so. Thirteen boxes became four attached lists.
///
/// And the chart flows LEFT TO RIGHT by default — depth rightward, siblings
/// stacked downward, because down is the direction a screen scrolls — with top
/// to bottom kept as the second view the owner asked for. Which one is on screen
/// is [StructureCanvas.orientation], and unlike the zoom it belongs to the
/// arrangement everybody shares: two colleagues describing "the box on the left"
/// are describing the same box only if the chart runs the same way for both.
/// This widget is handed the word and does not store it, for the same reason it
/// is handed the layout and does not store that.
///
/// Both changes are one change twice, both were picked by the owner from
/// mockups, and the layout that produces them is [arrangeStructureFlow] in
/// `structure_levels.dart`, beside the definition of the hierarchy it applies.
///
/// ── DRAGGING MOVES THE PICTURE, NOT THE BUSINESS ─────────────────
///
/// This is the constraint the whole widget is shaped around, and it is not a
/// preference. `CompaniesController` omits the parent from every set of fields
/// it accepts — "entities are never moved between parents. Every setting,
/// assignment and roll-up below a company is expressed in terms of where it
/// sits, so moving one silently rewrites history." There is no endpoint that
/// re-parents anything, and there must never be a picture that implies there is.
///
/// A canvas of draggable boxes is EXACTLY the interface that normally means
/// re-parenting, so the refusal has to be built into the affordance rather than
/// written in a caption somebody may not read. Six mechanisms, all deliberate:
///
///   1. **There is no [DragTarget] in this file.** Not one. `DashboardGrid` is
///      a `Draggable`/`DragTarget` pair whose hover state is a 2px `primary`
///      border on the target, and that border is literally the sentence "drop
///      here to take this place". Nothing here listens for a drop, so nothing
///      can light up under a dragged box.
///   2. **Not [Draggable] either.** A plain [GestureDetector] pan. `Draggable`
///      exists to carry a payload to a target; using it where there is no
///      target would leave the SHAPE of a drop-and-adopt gesture in the code
///      for the next person to finish.
///   3. **The box itself moves under the pointer.** No floating `feedback` chip
///      hovering over a field of candidates — the thing being moved is the
///      thing being looked at, and it never lifts off the surface.
///   4. **Overlap is allowed and means nothing.** Dropping a box exactly on
///      another leaves two overlapping boxes and two unchanged connectors. No
///      snapping, no sibling reordering, no promotion in z-order that would
///      read as "it went inside". What it DOES do is raise a paragraph saying
///      so in plain words ([_noticeLanding]) — noticed, not obeyed: the gesture
///      is read as a question and answered in text, and not one thing about
///      what was stored or drawn is different because of it.
///   5. **The connector never detaches.** It is derived from the TREE, never
///      from the positions, so during and after a drag the line keeps pointing
///      at the same parent and simply follows the box. That is the picture
///      saying, in the only language it has, that the business did not move.
///   6. **The cursor says `move`, not `grab`.** `grab` is "pick this up and
///      carry it somewhere"; `move` is "reposition this". Small, and it is the
///      pointer saying the right one.
///
/// ── What this widget does NOT do ─────────────────────────────────
///
/// It does not talk to the API and it holds no opinion about where a layout is
/// stored. It is handed the tree and a layout, and it reports moves back. That
/// is what lets it be tested with real gestures and no fake client at all, and
/// it is why the persistence argument — which preference, which endpoint, how
/// often — lives entirely in the screen that owns it.
///
/// It is CONTROLLED, in the same sense a `TextField` with a controller is: it
/// draws [layout] and reports what a person did, and the caller is expected to
/// apply that report. A caller that ignores [onMoved] will see boxes spring
/// back on release, which is the honest reading of a move that was not
/// accepted — and it is the same thing the screen does deliberately when a save
/// is refused.
///
/// That "which preference" is now "which setting": the arrangement belongs to
/// the organization rather than to one person. This widget is untouched by
/// that, which is the point of it holding no opinion — but the sentence above
/// would otherwise name a store that no longer exists.
///
/// ── ZOOM IS WHERE SOMEBODY IS LOOKING, NOT HOW THEY ARRANGED THINGS ──
///
/// The business this was built for has thirteen places in it, and thirteen
/// boxes side by side is wider than any window — the owner's screenshots show
/// the tab opening on a fragment of its own chart with nothing on screen
/// saying so. So the surface scales: a cluster in the toolbar (out, a
/// percentage, in, and "Fit"), ctrl with the wheel, and a trackpad or
/// touchscreen pinch, all four driving one number.
///
/// None of it is ever reported, and that is the important half.
/// [StructureCanvas.onMoved] and [StructureCanvas.onArranged] are the only two
/// things this widget says out loud, and both carry CANVAS coordinates —
/// where a box sits in the arrangement, identical at every zoom. Nothing about
/// the scale can reach `PUT /organization/canvas`, whose payload is
/// `{orientation, boxes}` — a word from a closed list and a list of
/// `{kind, id, x, y}` — and has nowhere to put one.
///
/// [StructureCanvas.orientation] is on that wire and the zoom is not, which is
/// the same distinction drawn twice rather than an inconsistency. Which way the
/// chart flows is a fact about the picture everybody opens; how big one person
/// has drawn it on their own screen is a fact about their eyes. That is why this
/// widget is HANDED the orientation and merely holds the scale.
///
/// That is the same line the viewport offset already draws (see
/// [_StructureCanvasState._view]) and it matters more than it did, because the
/// arrangement is now ONE setting for the whole organization rather than a
/// preference of one person's. If zoom rode along with it, somebody leaning in
/// to read a name would move every colleague's picture, and the colleagues
/// would find out by having their own chart jump the next time anybody pressed
/// a button. What magnification a person's own eyes want is not a fact about
/// the business.
///
/// The alternative considered was keeping zoom per user, on `/me`, beside
/// nothing else. It was rejected twice over: it would add a second store for a
/// second kind of state to this feature, and what it would remember is a
/// number that was right for whatever window somebody last had open — a chart
/// that reopens at 30% on a laptop because it was fitted on a monitor is worse
/// than one that opens at 100% with a Fit button an inch away.
library;

import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter/services.dart';

import 'structure_levels.dart';

/// The vocabulary and the four levels, which used to be declared here.
///
/// They moved to a file with no Flutter in it so that [StructureOutline] can
/// read the same answer without importing a widget, and so that there is one
/// definition of the hierarchy rather than one per view — see
/// [structureLevelOf], whose docblock is where the reason lives.
///
/// Re-exported rather than left behind an import, so every existing importer of
/// this file — `structure_layout.dart`, `organization_screen.dart`, this file's
/// own tests — is untouched by the move. A shared fact changing address should
/// not be a diff in six places.
export 'structure_levels.dart';

/// The largest share of the tab the words above the picture may ever occupy.
///
/// A fraction rather than a pixel count, because what is being protected is the
/// picture's share of the screen — and a pixel figure would mean one thing on a
/// phone and something quite different on a desktop. Beyond this the toolbar,
/// the note and any landing sentence scroll inside their own strip instead of
/// pushing the canvas off the bottom, which is what they did at large text
/// sizes on a short viewport.
///
/// Nearer a half than a third on purpose: the sentences are worth reading, and
/// a canvas with a little over half the height is still a canvas.
const double _mostOfTheHeightTheWordsMayTake = 0.45;

/// One box's size, in one place.
///
/// The automatic layout and the connector painter both have to agree about
/// where a box's edges are; two copies of these numbers is how a connector
/// starts landing three pixels off the box it points at.
///
/// Fixed, and NOT sized to its CONTENTS. A box whose height followed the length
/// of its name would draw a different picture after a rename, because the edge
/// the connector attaches to would have moved while the stored position stayed
/// where it was. Names ellipsize instead, and the full name is in the box's
/// semantics label either way.
///
/// ── Why the width is 260 rather than the 200 it started at ───────
///
/// Because a box carries more than a name. In the configuration that actually
/// ships — `organization_screen` hands the tab an action menu, and the tab
/// always hands the canvas an [StructureCanvas.onMoved] — the row inside a box
/// is a glyph, the name, the move handle and the overflow menu. The two
/// controls are full-size tap targets on purpose (see [_moveButton]), so at 200
/// they and the padding and the glyph took 147 points and left the name 53:
/// "Everdoors Overhead Doors" rendered as about seven characters and an
/// ellipsis, on every box, on the real screen. The canvas's own tests never
/// passed an action menu, so nothing measured that.
///
/// Shrinking the tap targets was the other way out and it is the wrong one: a
/// control small enough to fit is a control a finger misses. 260 leaves the
/// name a little over a hundred points with both controls present, which is a
/// readable fragment rather than a stub, and it is still narrow enough that two
/// boxes and the gap between them fit inside the width at which this view
/// gives way to the outline (`kStructureOutlineBelow`).
const double kStructureBoxWidth = 260;

/// A box's height at the reader's DEFAULT text size — see [structureBoxHeight],
/// which is what everything actually measures against.
const double kStructureBoxHeight = 72;

/// A box's height for a given text scale.
///
/// The two lines inside a box scale with the reader's font size and the box did
/// not, so at 150% — an ordinary accessibility setting on the tablets this view
/// is served to — every box overflowed its own frame by a few points, and at
/// 200% by a couple of dozen. In debug that is a striped bar across the picture;
/// in release it is the level caption painting outside the rounded border it
/// belongs to, which degrades the one channel this widget argues hardest for
/// (see [_wordFor]) for exactly the readers most likely to have turned their
/// text up.
///
/// So the number is DERIVED rather than literal. The height still has to be one
/// number per frame — the automatic layout, the connector rects and the box
/// itself all read it, and three answers is how a connector lands three pixels
/// off — but nothing about "one number" required it to ignore the reader. It is
/// scaled by the same [TextScaler] the text inside it is, so the room the
/// content needs and the room the box gives it move together.
double structureBoxHeight(TextScaler scaler) =>
    scaler.scale(kStructureBoxHeight);

/// Between siblings, and between a row and the row below it.
const double kStructureGapX = 32;
const double kStructureGapY = 84;

/// How far the top-left box sits from the corner of the viewport, so nothing
/// is drawn flush against the edge it can be dragged past.
const double kStructureMargin = 32;

/// How far in and how far out the picture may be taken.
///
/// ── Why there is a floor at all, and why "Fit" is allowed to hit it ──
///
/// The floor is what keeps "Fit" honest. Thirteen places side by side is about
/// 3,800 points across, which is a little under a third of itself on an
/// ordinary laptop — comfortably inside this. What is outside it is the
/// window that is narrower still, and there Fit CLAMPS rather than shrinking
/// further, so the chart is as small as it is worth making and the person pans
/// the rest. That is a deliberate choice between two imperfect answers: a
/// picture scaled to 8% technically contains every box and is a grey smear
/// with no readable word anywhere in it, which is a screen that looks like it
/// worked while telling nobody anything. A quarter size still shows the shape,
/// the levels and which branch is which.
///
/// The ceiling is for the opposite reader — somebody at a large system text
/// size who is zooming in to be certain of a name. Past three times, a
/// 260-point box is wider than the window it is being read in, and a box you
/// have to pan across to read is not more legible than one you do not.
const double kStructureZoomMin = 0.25;
const double kStructureZoomMax = 3;

/// The stops the minus and plus buttons walk between.
///
/// A LADDER rather than a multiplier, because the number between the buttons
/// is meant to be read. Multiplying by a constant gives 100, 125, 156, 195,
/// 244 — every step is the same size to the eye, and not one of those is a
/// figure a person would say out loud or aim for. These are the ones every
/// other zoomable thing somebody uses shows them, and "back to normal size" is
/// one press from either side of 100%.
///
/// Both ends are the bounds above, so the buttons can never ask for a scale
/// the wheel and the pinch are not also allowed to reach — two different
/// answers to "how far out does this go" is how a control ends up with a state
/// only one of its inputs can produce.
///
/// Deliberately NOT something a customer configures, unlike almost everything
/// else on this screen. A zoom ladder is the shape of a control rather than a
/// fact about a business — an organization has no more use for its own than
/// for its own scrollbar — and a setting nobody would ever open is still a
/// setting somebody has to maintain.
const List<double> kStructureZoomStops = [
  0.25,
  0.33,
  0.5,
  0.67,
  0.75,
  1,
  1.25,
  1.5,
  2,
  2.5,
  3,
];

/// Close enough to a stop to count as standing on it.
///
/// Zoom is continuous — a pinch or a wheel leaves it at 1.0000000000000002 as
/// readily as at 1 — so "is this already the smallest?" cannot be `==`. Without
/// this the minus button at the floor is drawn live, does nothing when pressed,
/// and is the one control on the toolbar that lies about what it can do.
const double _zoomTouching = 0.001;

/// The widget key one NODE is drawn with — a box for the things that have one,
/// and the row in a company's attached list for a place.
///
/// Exposed rather than spelled out at each call site because the screen's tests
/// and this file's tests have to name the same thing, and a key format copied
/// into two test files is two things that have to be kept in step.
///
/// Kept for a place even though a place is no longer a box, and that is worth a
/// sentence rather than a rename. What this key answers is "where on screen is
/// the thing called `franchise:abc`", which is a question the tab's own tests
/// ask about a place — its menu, its name, the customer's word for it — and the
/// answer is a row now instead of a box. Renaming it would have been a diff
/// across four test files that changed nothing about what is being asked.
Key structureBoxKey(String key) => ValueKey('structure-box:$key');

/// The widget key the whole CARD is drawn with — a box and, under it, its
/// attached list of places.
///
/// Two keys because there are two rectangles and the difference matters. The
/// card is what occupies the canvas, what a pointer can pick up and what the
/// connectors leave from; the box inside it is what a name is written on and
/// what a connector points AT. A single key would make "how big is a box drawn"
/// — which is how every zoom assertion in this file measures the scale — answer
/// with a rectangle that grows as a company gains places.
Key structureCardKey(String key) => ValueKey('slot:$key');

/// The two halves of a `kind:id` layout key.
///
/// These exist for one specific job, and it is worth naming it: a stored layout
/// legitimately holds positions for boxes that are NOT on screen — a company
/// somebody's role cannot see this afternoon, a franchise that was switched off
/// and back on. The server keeps those entries on purpose, and a screen that
/// serialised only what it had drawn would defeat that from the other side and
/// quietly lose an arrangement nobody changed. Sending a stale entry back means
/// splitting a key nothing can resolve to a node, which is what these do.
///
/// A uuid contains no colon, so the first one is unambiguously the separator.
String structureKindOf(String key) {
  final at = key.indexOf(':');

  return at < 0 ? key : key.substring(0, at);
}

String structureIdOf(String key) {
  final at = key.indexOf(':');

  return at < 0 ? '' : key.substring(at + 1);
}

/// One thing in the business: what it is, what it is called, and what it hangs
/// off.
///
/// It implements [StructureItem], which is the whole of what
/// [arrangeStructureFlow] is allowed to see. The four members that interface
/// asks for — [key], [kind], [parentKey], [isSub] — were already here and are
/// unchanged; declaring the relationship is what lets the automatic layout live
/// in a file with no widgets in it, be asked where nine places end up without a
/// screen existing, and still be handed this exact object with no conversion at
/// the call site. A second node model for the layout to read is the thing that
/// would come to disagree with this one.
///
/// It is deliberately NOT called a box any more. A franchise is one of these and
/// never gets a box — see [structureHasBox] — it is drawn as a row in the
/// compact list attached to the company above it.
class StructureNode implements StructureItem {
  const StructureNode({
    required this.kind,
    required this.id,
    required this.name,
    required this.parentKey,
    required this.parentName,
    required this.isActive,
    required this.isSub,
    required this.raw,
  });

  /// One of [structureOrganization], [structureCompany], [structureFranchise].
  @override
  final String kind;
  final String id;
  final String name;

  /// The key of the thing this one hangs off — `company:<uuid>` — or null for
  /// the organization, which hangs off nothing.
  @override
  final String? parentKey;

  /// Carried so the box can say "under Everdoors" out loud. The connector says
  /// the same thing to somebody who can see it; this says it to somebody who
  /// cannot, and both are drawn from the tree rather than from the positions.
  final String? parentName;

  final bool isActive;

  /// A company whose parent is a company. Not a fourth [kind] — see the note on
  /// the kind constants.
  @override
  final bool isSub;

  /// The server's own map for this node, untouched.
  ///
  /// Carried whole rather than having its flags copied out, so a caller that
  /// draws an action menu reads `may_edit`, `may_add_franchise` and their
  /// reasons from the payload at the point of use. §4 has one home, and
  /// `organization_screen.dart` records what happened the last time a client
  /// approximated one of those answers for itself.
  final Map<String, dynamic> raw;

  /// How the layout and the arrangement name this thing. The kind travels with
  /// the id because it is what makes a stored arrangement readable by a person
  /// who opens the column, and what lets a stale company id be told from a stale
  /// franchise id.
  @override
  String get key => '$kind:$id';
}

/// The tree from `GET /companies`, flattened into boxes in tree order.
///
/// Tree order is load-bearing twice over: the boxes are built into the [Stack]
/// in this order so that keyboard traversal follows the structure rather than
/// wherever somebody happened to drag things, and the automatic layout lays
/// siblings out left to right in it. It is NOT re-sorted here —
/// `CompaniesController::index` already orders companies and franchises by
/// name, and a second sort would be a second opinion about order.
///
/// Read defensively at every level, and that is not belt-and-braces. This map
/// arrives from the network, and a screen that threw because one franchise came
/// back without a name would be a screen nobody could open and nobody could
/// fix. A malformed node is skipped along with everything under it — promoting
/// orphans to the top would draw a sub company as a sibling of its own parent,
/// which is the exact bug the nested payload exists to prevent.
List<StructureNode> flattenStructure(Map<String, dynamic> tree) {
  final nodes = <StructureNode>[];

  final organization = tree['organization'];
  String? organizationKey;
  String? organizationName;

  if (organization is Map) {
    final id = organization['id'];
    final name = organization['name'];

    if (id is String && name is String) {
      organizationKey = '$structureOrganization:$id';
      organizationName = name;

      nodes.add(
        StructureNode(
          kind: structureOrganization,
          id: id,
          name: name,
          parentKey: null,
          parentName: null,
          // The organization has no `is_active`, and that is the truth rather
          // than a default: there is no such thing as switching the business
          // off from inside itself.
          isActive: true,
          isSub: false,
          raw: organization.cast<String, dynamic>(),
        ),
      );
    }
  }

  final companies = tree['companies'];

  if (companies is! List) return nodes;

  for (final company in companies) {
    _addCompany(
      nodes,
      company,
      parentKey: organizationKey,
      parentName: organizationName,
      isSub: false,
    );
  }

  return nodes;
}

void _addCompany(
  List<StructureNode> nodes,
  Object? raw, {
  required String? parentKey,
  required String? parentName,
  required bool isSub,
}) {
  if (raw is! Map) return;

  final id = raw['id'];
  final name = raw['name'];

  if (id is! String || name is! String) return;

  final map = raw.cast<String, dynamic>();
  final key = '$structureCompany:$id';

  nodes.add(
    StructureNode(
      kind: structureCompany,
      id: id,
      name: name,
      parentKey: parentKey,
      parentName: parentName,
      isActive: map['is_active'] != false,
      isSub: isSub,
      raw: map,
    ),
  );

  // Places first, then sub companies. It is the reading the list tab already
  // gives: a company's franchises are drawn INSIDE its card and its sub
  // companies below it, so putting the places first keeps a company's own
  // ground beside it rather than pushed out past a branch of the business.
  _addFranchises(nodes, map['franchises'], parentKey: key, parentName: name);

  if (isSub) return;

  final subs = map['sub_companies'];

  if (subs is! List) return;

  for (final sub in subs) {
    _addCompany(nodes, sub, parentKey: key, parentName: name, isSub: true);
  }
}

void _addFranchises(
  List<StructureNode> nodes,
  Object? raw, {
  required String parentKey,
  required String parentName,
}) {
  if (raw is! List) return;

  for (final franchise in raw) {
    if (franchise is! Map) continue;

    final id = franchise['id'];
    final name = franchise['name'];

    if (id is! String || name is! String) continue;

    final map = franchise.cast<String, dynamic>();

    nodes.add(
      StructureNode(
        kind: structureFranchise,
        id: id,
        name: name,
        parentKey: parentKey,
        parentName: parentName,
        isActive: map['is_active'] != false,
        isSub: false,
        raw: map,
      ),
    );
  }
}

/// A place for every BOX, in the canvas's own measurements.
///
/// ── This is not a layout. It is where the measurements meet one ──
///
/// The layout is [arrangeStructureFlow], and it lives in `structure_levels.dart`
/// with the hierarchy it is an application of. What is left here is the binding:
/// the numbers a box is actually drawn with — [kStructureBoxWidth],
/// [kStructureGapX], [kStructureGapY], [kStructureMargin] — belong to the widget
/// that draws one and deliberately stay in this file, and something has to hand
/// them over.
///
/// It is a function rather than six arguments repeated at each call site because
/// there are five call sites — three in this widget and two in its tests — and
/// six numbers copied five times is five chances for a connector to start
/// landing three pixels off the box it points at. That is the same argument the
/// constants themselves are declared with, arriving one level up.
///
/// The walk that used to be here is gone rather than kept beside the new one.
/// Two tidy passes is two places for "centred over its children" to be subtly
/// different, and the old one could only draw the chart one way round and could
/// only draw a place as a box — both of which the owner replaced.
///
/// [boxHeight] is taken rather than read off [kStructureBoxHeight] because a box
/// is as tall as the reader's text size makes it (see [structureBoxHeight]), and
/// boxes laid out against a different height than they are drawn at is the
/// automatic layout stacking them on top of each other for anybody who has
/// turned their font up. [placeRowHeight] is the same story for a row in a
/// company's attached list of places. Both default to the height at the default
/// text size, so a caller with no opinion gets the shape without having to know
/// about either.
Map<String, Offset> arrangeStructure(
  List<StructureNode> nodes, {
  String orientation = kStructureDefaultOrientation,
  double boxHeight = kStructureBoxHeight,
  double placeRowHeight = kStructurePlaceRowHeight,
}) => arrangeStructureFlow(
  nodes,
  orientation: orientation,
  boxWidth: kStructureBoxWidth,
  boxHeight: boxHeight,
  gapBetweenSiblings: kStructureGapX,
  gapBetweenLevels: kStructureGapY,
  margin: kStructureMargin,
  placeRowHeight: placeRowHeight,
);

/// The canvas.
class StructureCanvas extends StatefulWidget {
  const StructureCanvas({
    super.key,
    required this.tree,
    required this.franchiseLabel,
    this.layout = const {},
    this.orientation = kStructureDefaultOrientation,
    this.onMoved,
    this.onArranged,
    this.leading,
    this.actions,
    this.controls,
    this.note = defaultNote,
  });

  /// The payload from `GET /companies`, exactly as the server sent it. There is
  /// no second read endpoint for the structure and there must not be one.
  final Map<String, dynamic> tree;

  /// The organization's own word for a place (§8) — `me.organization.franchiseLabel`.
  ///
  /// Required rather than defaulted, and that is the point of it. "Franchise" is
  /// the one noun this product lets a customer replace, and this widget used to
  /// write "Place" on every place box while [StructureOutline] — the same tab,
  /// the same payload, one window-width away — wrote the customer's word. One
  /// screen calling the same thing two things depending on how wide the window
  /// is, is worse than either word on its own. A default here is how that comes
  /// back: it would let a caller forget, silently, and look right in a test
  /// fixture that never set one.
  final String franchiseLabel;

  /// Where the boxes go, keyed `kind:id`.
  ///
  /// A box this map says nothing about is drawn at its automatic place, which
  /// may well overlap something. That is deliberate: a parking row for new
  /// arrivals would be a second layout nobody designed, and "Arrange for me" is
  /// already the named way out of exactly this drift.
  ///
  /// Entries naming nothing on screen are ignored here and MUST be kept by the
  /// caller — see [structureKindOf].
  ///
  /// A place is not in here and cannot be. It has no position at all now — see
  /// [structureHasBox] — and both the reader on the way in and
  /// `OrganizationCanvasController::clean` on the way out drop one.
  final Map<String, Offset> layout;

  /// Which way the chart flows — one of [kStructureOrientations].
  ///
  /// ── Held by the CALLER, unlike the zoom ──────────────────────────
  ///
  /// This is a fact about the picture everybody in the organization opens, so it
  /// is part of the shared arrangement and the screen that owns the saving owns
  /// it. The zoom is the opposite case and is held privately in this State,
  /// never reported and never stored — the section at the top of this file is
  /// where that line is drawn and why.
  ///
  /// Taken as a plain [String] rather than an enum for the reason the kinds are:
  /// this word is stored in a JSON column and validated against the same closed
  /// list on the server, and an enum here would mean a conversion at every door
  /// and a decision at each one about what to do with a word that is not in it.
  /// [arrangeStructureFlow] reads anything it does not recognise as the default,
  /// so a chart always draws.
  ///
  /// ── What turning it round does, and what it deliberately does not ──
  ///
  /// It changes where the AUTOMATIC layout puts a box. It does not move a box
  /// somebody has placed by hand, because [layout] is what those are and this
  /// widget is not allowed to invent a save. So an organization that has
  /// arranged its chart sees the direction change only for boxes nobody has
  /// placed, and "Arrange for me" is the one control that re-lays out the lot —
  /// which is the same escape hatch the drift note already points at. Quietly
  /// re-arranging everything on a flip was the alternative and it is worse: it
  /// would throw away every colleague's placement on a press of a control that
  /// looks like a view switch.
  final String orientation;

  /// One box's new place. Null means nothing on this canvas can be moved, and
  /// then no move affordance is drawn at all — not a disabled one, which
  /// invites somebody to work out why it does nothing.
  ///
  /// One box rather than the whole layout, and this is the difference that
  /// matters: a callback handing back "the arrangement" could only contain what
  /// this widget drew, so applying it wholesale would silently drop every
  /// position belonging to a box the caller is currently unable to see.
  final void Function(String key, Offset to)? onMoved;

  /// Every box this canvas can see, in the place the automatic layout gives it.
  ///
  /// **MERGE this, do not replace with it.** It names only what is on screen,
  /// for the reason above. Null means no "Arrange for me" control is drawn.
  final void Function(Map<String, Offset> placed)? onArranged;

  /// What goes in the box before its name — a logo, typically. Null falls back
  /// to the glyph for the node's level, which is one of the things that tells
  /// the four levels apart.
  final Widget? Function(BuildContext context, StructureNode node)? leading;

  /// What goes after a name — the action menu, typically. Kept a builder because
  /// §4's answers live on the server's own payload, which the caller reads off
  /// [StructureNode.raw] at the point of use.
  ///
  /// Asked for a PLACE as well as for a box, and it always was. A place is a row
  /// in a company's attached list now rather than a box of its own, and the row
  /// carries the same menu the box did — a picture of the business you cannot
  /// rename a branch from is a picture people leave in order to find the list.
  /// It is also why a place row is as tall as it is; see
  /// [kStructurePlaceRowHeight].
  final Widget? Function(BuildContext context, StructureNode node)? actions;

  /// The caller's own control, in the toolbar beside this widget's two.
  ///
  /// ── Why there is a slot rather than a strip above the canvas ─────
  ///
  /// Because the row that holds "Arrange for me", "Bring the boxes into view"
  /// and the zoom cluster is the row of controls that act on the PICTURE, and
  /// the one the screen above has — which way the chart flows — is exactly one
  /// of those. It sat in its own full-width strip directly above this widget
  /// while there was nowhere to put it, which is the right neighbourhood and the
  /// wrong row: it read as another one of the sentences about the arrangement
  /// rather than as a control, and it cost a strip of height on the view whose
  /// whole problem is that the picture is too big for the window.
  ///
  /// A slot rather than this widget building the control itself, because which
  /// orientations exist and what a press MEANS are the caller's — the same
  /// reason [actions] is a builder. This widget draws what it is handed and
  /// reports what somebody did; it does not know that flipping the chart is a
  /// save, or that saving is a permission.
  ///
  /// It goes in the same [Wrap] as the rest, so it wraps onto its own line at
  /// 375 points instead of overflowing.
  final Widget? controls;

  /// The sentence above the canvas. Null when the screen around it already says
  /// this in its own header, so the two cannot end up saying it twice — or,
  /// worse, saying two different things.
  final String? note;

  /// Said in words as well as in behaviour, because the behaviour is an absence
  /// and an absence is hard to notice.
  ///
  /// ── Four sentences, and each of them is doing a job ───────────
  ///
  /// The first says what the gesture is for. The second is the rule §3 makes
  /// absolute, written for somebody who runs a business rather than for
  /// somebody who reads code: not "you cannot re-parent an entity" but "nothing
  /// you do here changes the business". A canvas of draggable boxes is exactly
  /// the interface that normally means moving a company from one parent to
  /// another, so the refusal is stated before anybody tries it — and
  /// [_landedNote] says it again, at length, at the moment they do.
  ///
  /// The third says where the places went, because thirteen boxes becoming four
  /// attached lists is the change somebody is most likely to read as data having
  /// gone missing. It names them in the generic word rather than in the
  /// organization's own (§8), unlike the boxes: a sentence has to pluralise, and
  /// "Territory" is a noun a customer chose that a naive plural would damage.
  ///
  /// ── What it deliberately no longer says ──────────────────────────
  ///
  /// It used to say each kind of thing keeps its own LINE, and that a line with
  /// nothing on it means a branch with no sub companies. Both halves are dead.
  /// A place is not on a line at all — it is a row attached to the company that
  /// owns it — so there is no line for one to be absent from, and nothing sits
  /// below a sub company any more, so no branch leaves a gap to explain.
  ///
  /// It also says nothing about WHICH WAY the chart runs, and that is the limit
  /// of what a constant can honestly say: [orientation] is a choice, and there
  /// are two pictures. A caller that offers both — `StructureTab` does — passes
  /// its own [note] saying which one is on screen. This is the answer for a
  /// caller with no opinion, and it is true either way round.
  static const String defaultNote =
      'Drag the boxes to lay the picture out the way you like it. Nothing you '
      'do here changes the business — where a box sits on screen is just where '
      'you put it. The whole business, then its companies, then any sub '
      'companies; the places work happens in are listed under the company or '
      'sub company that runs them rather than drawn as boxes of their own.';

  @override
  State<StructureCanvas> createState() => _StructureCanvasState();
}

/// The pointers a BOX can be dragged with — every kind except a trackpad.
///
/// Spelled out rather than left null, because null means "all of them" and the
/// one this must exclude is the one that arrives as a pan-zoom pointer. The
/// reasoning is on [_StructureCanvasState._panZoomStart], where the excluded
/// gesture is handled instead.
///
/// [PointerDeviceKind.unknown] is IN. It is what an event with no kind reported
/// arrives as, and a box nobody can move on a device nobody identified is worse
/// than a box moved by a gesture nobody expected.
const Set<PointerDeviceKind> _dragPointers = {
  PointerDeviceKind.touch,
  PointerDeviceKind.mouse,
  PointerDeviceKind.stylus,
  PointerDeviceKind.invertedStylus,
  PointerDeviceKind.unknown,
};

class _StructureCanvasState extends State<StructureCanvas>
    with SingleTickerProviderStateMixin {
  List<StructureNode> _nodes = const [];

  /// The things that get a box, in tree order. Everything in [_nodes] except the
  /// places, which hang off one of these instead.
  ///
  /// Held rather than filtered at each use because six things ask it — the
  /// automatic layout, the extent, the rectangles, the connectors, the overlap
  /// notice and the Stack itself — and every one of them has to be looking at
  /// the same list on the same frame.
  List<StructureNode> _boxes = const [];

  /// The places hanging off each box, keyed by the box's key, in tree order.
  ///
  /// A place whose owner is not in the tree appears in neither of these and is
  /// therefore drawn nowhere, which cannot happen from a real payload:
  /// [flattenStructure] drops a company it cannot read along with its franchises
  /// on purpose. The outline is the view that lists everything whatever the
  /// canvas can draw.
  Map<String, List<StructureNode>> _places = const {};

  /// Where a box goes when [StructureCanvas.layout] has never heard of it.
  ///
  /// Which is also the whole first visit: somebody opening this tab for the
  /// first time has no stored arrangement, and an empty canvas would be a
  /// feature that looks broken until you find the button.
  Map<String, Offset> _auto = const {};

  /// How tall a box is right now, which depends on the reader's text size.
  ///
  /// Held rather than read from [MediaQuery] at each use, because the automatic
  /// layout, the connector rectangles and the boxes themselves all have to be
  /// measuring the same number on the same frame.
  double _boxHeight = kStructureBoxHeight;

  /// And one row of a box's attached list of places, for the same reason and
  /// scaled by the same [TextScaler] — but never DOWN.
  ///
  /// ── Why this one has a floor and a box's height does not ─────
  ///
  /// Because the row is the same height as the control inside it. A text scale
  /// below 1 is an ordinary setting — Android offers 0.85, and a reader who
  /// wants more on screen chooses it — and it would take this row to 41 points
  /// while the action menu it carries stays at the framework's interactive
  /// minimum of 48, which is a control laid out taller than the row it is in.
  /// In debug that is a striped bar across the chart and in release it is a
  /// clipped menu. A box is 72 and has the same 48-point control in it, so it
  /// has 24 points of slack and needs no floor; this row was sized to the
  /// control in the first place and has none.
  ///
  /// Growing is still allowed and still necessary: at 200% the words inside the
  /// row are twice the size and the row has to be too.
  ///
  /// The GAP between a box and its first place is deliberately not scaled at
  /// all. Six points of separation is a gap rather than a line of text, and
  /// growing it with somebody's font size would push the places away from the
  /// box they belong to exactly for the reader least able to see which box that
  /// is.
  double _placeRowHeight = kStructurePlaceRowHeight;

  /// Whether the first layout has happened, so the viewport is aimed once.
  bool _placed = false;

  /// Where the viewport is looking, which is NOT part of the arrangement and is
  /// never reported anywhere. It is where you happened to be looking, not how
  /// you arranged things, and persisting it would open the tab somewhere nobody
  /// chose and put a second drifting thing in the stored blob.
  Offset _view = Offset.zero;

  /// How big the picture is drawn, which is the same kind of thing as [_view]
  /// and is treated the same way — see the section on it at the top of this
  /// file, which is where the argument lives rather than here.
  ///
  /// ── The one equation everything below is written against ─────
  ///
  ///     screen = canvas × _zoom + _view
  ///
  /// [_view] is therefore in SCREEN points, not canvas points, and that is the
  /// choice worth stating because the other convention — `(canvas + view) ×
  /// zoom` — is equally defensible and quietly breaks two things. Under this
  /// one a pan is the same number of points at every scale, so the wheel
  /// handler and the pan gestures need no scale in them at all and cannot go
  /// wrong when one is forgotten. Under the other, every pan would have to
  /// divide, and a drag of the background would move the picture a different
  /// distance than the finger doing it.
  ///
  /// What DOES have to divide is a box drag, because a pointer delta is in
  /// screen points and a box's position is in canvas points — see
  /// [_panUpdate], which is the one place it happens.
  double _zoom = 1;

  /// The drawing surface's size, recorded as it is laid out.
  ///
  /// Held rather than looked up, because the two things that need it — "Fit"
  /// and the zoom buttons, which grow the picture about the middle of the
  /// window — are pressed in the TOOLBAR, which is built above the surface and
  /// has no constraints of its own to read it from. A render-object lookup at
  /// the moment of the press would answer the same question through three
  /// nullable hops.
  Size _viewport = Size.zero;

  /// The box in hand, and where it is right now.
  ///
  /// Held locally as well as reported, so the box tracks the pointer on its own
  /// frame rather than waiting for the caller to rebuild. Cleared on release,
  /// at which point what the caller applied takes over — so a move the caller
  /// refused springs back, which is the honest picture of a refusal.
  String? _dragKey;
  Offset _dragTo = Offset.zero;
  Offset _pointerAnchor = Offset.zero;
  Offset _boxAnchor = Offset.zero;

  /// Where the viewport stood, what it was scaled to, and where on the surface
  /// the fingers were, when the current two-finger gesture began.
  ///
  /// All three read at the START and never updated during the gesture, because
  /// a pinch is anchor-to-now rather than a running sum: the canvas point that
  /// was under the fingers when they landed is the point that must still be
  /// under them at every frame, and that is a statement about where the gesture
  /// began. Recomputing it from the previous frame accumulates the rounding of
  /// every frame of a long pinch, which shows up as the picture creeping away
  /// from the fingers holding it.
  Offset _viewAnchor = Offset.zero;
  Offset _viewPointerAnchor = Offset.zero;
  double _zoomAnchor = 1;

  /// The box currently being moved with the arrow keys, if any.
  String? _nudging;

  /// Where it stood when that started, so a nudge session nobody actually
  /// nudged does not read as a move — see [_noticeLanding].
  Offset _nudgeFrom = Offset.zero;

  /// The two names in the last move that finished squarely on top of another
  /// box, or null when there is nothing to explain.
  ///
  /// Names rather than keys, because the only thing this is ever used for is a
  /// sentence with two names in it, and a key would have to be resolved back to
  /// a node that may have been refetched away in the meantime.
  ({String moved, String onto})? _landedOn;

  /// Which box has focus, so its border can say so. Kept as a key rather than
  /// read from the nodes because focus survives the tree being refetched.
  String? _focused;

  final Map<String, FocusNode> _focusNodes = {};

  /// The drawing surface itself, so a pointer's GLOBAL position can be put back
  /// into the surface's own coordinates.
  ///
  /// Needed by exactly one thing: a trackpad pinch whose cursor is sitting over
  /// a BOX. That gesture is caught by a [Listener] around the box (see
  /// [_panZoomStart]), so its local coordinates are measured from the box's own
  /// corner — and a zoom has to be anchored on a point in the same frame as
  /// [_view], which is the surface's. Anchoring it on the box's frame instead
  /// puts the fixed point wherever that box happens to be, so the picture
  /// lurches by the box's offset the moment two fingers land on one.
  final GlobalKey _surfaceKey = GlobalKey();

  /// ── Why the picture is drawn by hand rather than by AnimatedPositioned ──
  ///
  /// Because the connectors have to be drawn from the SAME numbers as the boxes,
  /// on the same frame, and an implicit animation gives its widget a value
  /// nobody else can read. The boxes used to ease into place while
  /// [StructureConnectorPainter] was handed the target rectangles directly, so
  /// for the length of every animation each line was drawn to where its box was
  /// GOING rather than to where it was — the box in one place, the line that
  /// says what it hangs off ending in mid air beside it.
  ///
  /// That was worst where the animation had no business running at all. A wheel
  /// scroll and "Bring the boxes into view" both move the VIEWPORT, and an eased
  /// viewport lags the input by design; a continuous scroll restarted the tween
  /// on every tick, so the boxes never caught up with the lines at all.
  ///
  /// So there is exactly one animation and this is it: the jump every box makes
  /// when "Arrange for me" is pressed. It is driven from here, both the boxes
  /// and the connectors read it, and nothing else in this widget eases —
  /// dragging tracks the pointer, and every viewport change is instant.
  late final AnimationController _arranging = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 220),
  );

  /// Where each box stood when the current arrangement began, so the picture can
  /// be drawn part-way between the two.
  Map<String, Offset> _leaving = const {};

  @override
  void initState() {
    super.initState();
    _readTree();
    _arranging.addListener(_repaint);
  }

  /// The first layout, and any later one the reader's text size forces.
  ///
  /// Here rather than in [initState] because a box's height comes off the
  /// [MediaQuery], which is a dependency and not available yet up there. It also
  /// means somebody changing their system font size while this is on screen gets
  /// boxes that still fit rather than boxes that overflow until the tab is
  /// reopened.
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    final scaler = MediaQuery.textScalerOf(context);
    final height = structureBoxHeight(scaler);
    // Never below the constant, which is the height of the control the row
    // carries — see [_placeRowHeight], where the reason is written out.
    final row = math.max(
      kStructurePlaceRowHeight,
      scaler.scale(kStructurePlaceRowHeight),
    );

    if (_placed && height == _boxHeight && row == _placeRowHeight) return;

    _boxHeight = height;
    _placeRowHeight = row;
    _auto = _arrangement();

    // Aimed once, and never again. Where somebody is LOOKING is not part of the
    // arrangement, and moving it because they changed their font size would move
    // the picture out from under them — the same reason a refetch does not reset
    // it either.
    if (!_placed) {
      _placed = true;
      _view = _homeView();
    }
  }

  void _repaint() {
    if (mounted) setState(() {});
  }

  @override
  void didUpdateWidget(covariant StructureCanvas old) {
    super.didUpdateWidget(old);

    if (!identical(old.tree, widget.tree)) {
      _readTree();

      // The viewport is deliberately NOT reset here. A refetch happens after
      // every rename and every switch-off on the other tab, and snapping the
      // view home each time would move the picture out from under somebody who
      // had just scrolled to look at something.
      final present = {for (final node in _nodes) node.key};

      for (final key in _focusNodes.keys.toList()) {
        if (!present.contains(key)) _focusNodes.remove(key)!.dispose();
      }

      if (_nudging != null && !present.contains(_nudging)) _nudging = null;
    }

    // Turning the chart round moves every box nobody has placed by hand, and
    // the whole picture with them: left to right spends its depth on width,
    // top to bottom on height, so the ground the chart covers is a different
    // shape and generally somewhere else. The viewport IS re-aimed here, unlike
    // on a refetch, and the difference is who asked: a refetch is the network
    // arriving under somebody who is reading, whereas a flip is somebody
    // pressing a control and expecting the picture to change. Leaving the view
    // where it was would answer that press with an empty grey surface, which is
    // the same failure "Arrange for me" re-aims to avoid.
    if (old.orientation != widget.orientation) {
      _auto = _arrangement();
      _view = _homeView();
    }
  }

  @override
  void dispose() {
    _arranging
      ..removeListener(_repaint)
      ..dispose();

    for (final node in _focusNodes.values) {
      node.dispose();
    }

    super.dispose();
  }

  void _readTree() {
    _nodes = flattenStructure(widget.tree);
    _boxes = [
      for (final node in _nodes)
        if (structureHasBox(node.kind)) node,
    ];

    final places = <String, List<StructureNode>>{};
    final boxed = {for (final box in _boxes) box.key};

    for (final node in _nodes) {
      final parent = node.parentKey;

      if (structureHasBox(node.kind) || parent == null) continue;
      if (!boxed.contains(parent)) continue;

      (places[parent] ??= <StructureNode>[]).add(node);
    }

    _places = places;
    _auto = _arrangement();
  }

  /// Every box's automatic place, in this canvas's own measurements.
  ///
  /// One method rather than the call written out at each of the three places
  /// that needs it, because the two heights are read off the reader's text size
  /// and a call site that forgot one would lay the chart out against numbers the
  /// boxes are not drawn at — which is the automatic layout stacking them on top
  /// of each other for anybody who has turned their font up.
  Map<String, Offset> _arrangement() => arrangeStructure(
    _nodes,
    orientation: widget.orientation,
    boxHeight: _boxHeight,
    placeRowHeight: _placeRowHeight,
  );

  /// How much room a box's attached places take underneath it, right now.
  double _placesHeight(String key) => structurePlacesHeight(
    _places[key]?.length ?? 0,
    rowHeight: _placeRowHeight,
  );

  /// The whole CARD: the box, and its list of places under it.
  ///
  /// This and not [kStructureBoxWidth] by [_boxHeight] is what occupies the
  /// canvas, so it is what the extent is measured from, what a pointer picks up,
  /// what the connectors leave from, and what "landed on top of that one" is
  /// judged by. The box inside it is a fixed size whatever the company owns —
  /// see [_face] — which is what keeps a connector pointing at a name rather
  /// than at the middle of a list of nine branches.
  Size _cardSize(String key) =>
      Size(kStructureBoxWidth, _boxHeight + _placesHeight(key));

  FocusNode _focusNode(String key) =>
      _focusNodes.putIfAbsent(key, () => FocusNode(debugLabel: 'box $key'));

  Offset _positionOf(String key) {
    if (key == _dragKey) return _dragTo;

    final to = widget.layout[key] ?? _auto[key] ?? Offset.zero;

    if (!_arranging.isAnimating) return to;

    final from = _leaving[key];

    // A box that was not on the canvas when the arrangement began has nowhere
    // to travel from, so it simply appears where it belongs rather than flying
    // in from a place it was never at.
    if (from == null) return to;

    return Offset.lerp(from, to, Curves.easeOutCubic.transform(_arranging.value))!;
  }

  /// The viewport offset that puts the top-left-most box at the margin.
  ///
  /// Scaled, because the equation is `screen = canvas × zoom + view`: the
  /// top-left box is drawn at `minX × zoom + view`, so putting it at the margin
  /// means subtracting the SCALED coordinate rather than the raw one. Left
  /// unscaled this looks right at 100% and sends "Bring the boxes into view"
  /// somewhere increasingly wrong the further from it somebody is — which is
  /// the worst kind of wrong, because the control is only reached by people who
  /// are already lost.
  Offset _homeView() {
    final extent = _extent();

    if (extent == null) return Offset.zero;

    return Offset(
      kStructureMargin - extent.left * _zoom,
      kStructureMargin - extent.top * _zoom,
    );
  }

  /// Every box's ground, in CANVAS coordinates — the real extent of the chart.
  ///
  /// Unscaled on purpose, and this is the whole reason it is a function rather
  /// than something measured off the drawn rectangles. "Fit" has to work while
  /// somebody is already zoomed in, and the drawn rectangles at 300% describe a
  /// chart three times the size of the one that needs fitting: measuring those
  /// and dividing the window by them would fit the picture to a third of the
  /// window, then a third of that, converging on nothing every time the button
  /// was pressed. A box's canvas footprint does not move when the scale does,
  /// so this answers the same thing at every zoom.
  ///
  /// Read through [_positionOf] rather than off [StructureCanvas.layout], so a
  /// box part-way through an "Arrange for me" or currently in somebody's hand
  /// is measured where it IS. Fitting to where things are about to be would put
  /// the chart out of the window for the length of the animation.
  ///
  /// Null when there is nothing to measure, which the caller has to answer for:
  /// a fit of an empty chart is a division by zero.
  ///
  /// ── CARDS, and only the things that have one ─────────────────
  ///
  /// This used to measure every node as one box, [kStructureBoxWidth] by
  /// [_boxHeight], because every node was one. A place is a row in a list
  /// attached to its company now, and that is wrong in both directions at once:
  /// a place has no rectangle of its own to include — [_positionOf] would answer
  /// `Offset.zero` for one and drag the extent to the origin — and the company
  /// it hangs off is taller than [_boxHeight] by whatever its list adds. Left
  /// alone this would fit the window to a chart that is partly imaginary and
  /// partly short, and "Fit" would cut the bottom off every company with more
  /// than a place or two — which on the business this was built for is the one
  /// with nine.
  ///
  /// So it walks [_boxes] and measures [_cardSize], and that is the whole of
  /// what the zoom code had to learn about places. Nothing else here did: the
  /// scale arithmetic, the gestures and the drag are all written against
  /// rectangles rather than against what is inside them.
  Rect? _extent() {
    var minX = double.infinity;
    var minY = double.infinity;
    var maxX = double.negativeInfinity;
    var maxY = double.negativeInfinity;

    for (final node in _boxes) {
      final at = _positionOf(node.key);
      final size = _cardSize(node.key);

      minX = math.min(minX, at.dx);
      minY = math.min(minY, at.dy);
      maxX = math.max(maxX, at.dx + size.width);
      maxY = math.max(maxY, at.dy + size.height);
    }

    if (!minX.isFinite || !minY.isFinite) return null;

    return Rect.fromLTRB(minX, minY, maxX, maxY);
  }

  // ── how big the picture is drawn ─────────────────────────────

  /// The percentage as it is written on the toolbar and said out loud.
  int get _percent => (_zoom * 100).round();

  /// The middle of the window, which is what a BUTTON zooms about.
  ///
  /// A button press has no pointer to keep still, so the thing held fixed is
  /// the centre of what somebody is looking at. Anchoring on the top-left
  /// corner instead — which is what falls out of doing nothing — walks the
  /// picture off the bottom right of the window a little further with every
  /// press, and a control that has to be undone by panning is a control people
  /// stop touching.
  Offset get _windowCentre => _viewport.isEmpty
      ? Offset.zero
      : Offset(_viewport.width / 2, _viewport.height / 2);

  /// Scale to [to], keeping the canvas point under [around] exactly where it is.
  ///
  /// ── The arithmetic, because it is the part that is easy to get wrong ──
  ///
  /// A screen point `p` is showing the canvas point `(p − view) ÷ zoom`. For
  /// that same canvas point to still be under `p` at the new scale:
  ///
  ///     (p − view) ÷ zoom  =  (p − view′) ÷ zoom′
  ///     view′ = p − (p − view) × zoom′ ÷ zoom
  ///
  /// Which is what the line below says. Without it — scaling and leaving the
  /// viewport alone — the fixed point is the surface's top-left corner, so
  /// zooming in on a box in the middle of the chart flings it off the bottom
  /// right and somebody has to go and find it again. That is the single thing
  /// that decides whether a zoom control gets used.
  void _zoomTo(double to, Offset around) {
    final next = to.clamp(kStructureZoomMin, kStructureZoomMax);

    if (next == _zoom) return;

    setState(() {
      _view = around - (around - _view) * (next / _zoom);
      _zoom = next;
    });
  }

  /// The next stop up or down, from wherever a pinch happens to have left this.
  ///
  /// Searched rather than indexed, because the wheel and the pinch are
  /// continuous: at 137% there is no index to add one to, and the honest answer
  /// to "smaller" is the largest stop below where you are — 125% — rather than
  /// a jump to whichever stop an index happened to be nearest.
  double _stopBelow(double from) {
    var found = kStructureZoomMin;

    for (final stop in kStructureZoomStops) {
      if (stop < from - _zoomTouching) found = stop;
    }

    return found;
  }

  double _stopAbove(double from) {
    for (final stop in kStructureZoomStops) {
      if (stop > from + _zoomTouching) return stop;
    }

    return kStructureZoomMax;
  }

  void _zoomOut() {
    _zoomTo(_stopBelow(_zoom), _windowCentre);
    _sayZoom();
  }

  void _zoomIn() {
    _zoomTo(_stopAbove(_zoom), _windowCentre);
    _sayZoom();
  }

  /// The whole chart, in the window, at the largest scale that holds it.
  ///
  /// Computed from [_extent] — the real ground the boxes cover, wherever
  /// somebody has dragged them to — rather than from the automatic layout,
  /// which is a guess about a picture nobody has touched and is wrong the
  /// moment they have. The margin is subtracted from the room on BOTH sides so
  /// the outermost boxes are not drawn flush against the edge of the surface,
  /// where a box reads as cut off rather than as the end of the chart.
  ///
  /// Then centred, which is not the same as putting the corner at the margin.
  /// A chart that is wide and short fits on width and leaves a band of empty
  /// surface, and hanging that band entirely below the picture looks like the
  /// bottom of the chart failed to draw.
  ///
  /// The scale is clamped like every other route to a zoom, so a window too
  /// small for the chart gets the smallest scale worth reading rather than an
  /// unreadable one — see [kStructureZoomMin], where that trade is argued.
  void _fit() {
    final extent = _extent();

    // Nothing to fit. The empty canvas is handled far above this — there are no
    // boxes and no toolbar — but a chart of one box with no height would divide
    // by zero here, and a control that throws is worse than one that waits.
    if (extent == null ||
        _viewport.isEmpty ||
        extent.width <= 0 ||
        extent.height <= 0) {
      return;
    }

    final room = Size(
      math.max(1, _viewport.width - 2 * kStructureMargin),
      math.max(1, _viewport.height - 2 * kStructureMargin),
    );

    final scale = math
        .min(room.width / extent.width, room.height / extent.height)
        .clamp(kStructureZoomMin, kStructureZoomMax);

    setState(() {
      _zoom = scale;
      _view = Offset(
        (_viewport.width - extent.width * scale) / 2 - extent.left * scale,
        (_viewport.height - extent.height * scale) / 2 - extent.top * scale,
      );
    });

    _say('The whole chart is in view. Zoom $_percent percent.');
  }

  /// ── Why the zoom DOES say a number, when nothing else here does ──
  ///
  /// [_say]'s own docblock argues at length that a box's position is never read
  /// out, because a coordinate is a fact about a picture the listener cannot
  /// see and moving a box changes nothing about the business. Every word of
  /// that is still true and none of it applies here.
  ///
  /// A zoom percentage is not a fact about the business or about the picture —
  /// it is the setting of the instrument being used to look at it, it is
  /// written on the toolbar in figures, and it is the only feedback the two
  /// buttons give. A person who presses "Zoom in" and hears nothing has no way
  /// to know whether the control did anything, whether they are at the ceiling,
  /// or how far from normal size they have wandered.
  ///
  /// Said on the BUTTONS and on Fit, and deliberately not on the wheel or the
  /// pinch. Those are continuous and would produce a sentence every few
  /// milliseconds, which is not information — it is a screen reader nobody can
  /// hear anything else over. A discrete press is a moment where somebody asked
  /// a question and is owed an answer; a gesture is somebody already watching
  /// the thing change.
  ///
  /// The percentage is also on the readout's own semantics label, so it can be
  /// read at any time by somebody who has not just pressed anything. A
  /// `liveRegion` was the alternative to announcing here and was dropped: its
  /// support differs across the three platforms this ships to, and half of an
  /// announcement is worse than a whole one.
  void _sayZoom() => _say('Zoom $_percent percent.');

  // ── moving a box ─────────────────────────────────────────────

  void _report(String key, Offset to) {
    // Never above or left of the origin. The viewport's extent starts there, so
    // a box dragged past it is a box that can be lost.
    widget.onMoved?.call(
      key,
      Offset(math.max(0, to.dx), math.max(0, to.dy)),
    );
  }

  void _panStart(StructureNode node, DragStartDetails details) {
    // Read BEFORE the box is marked as being in hand, and that order is
    // load-bearing: [_positionOf] answers with [_dragTo] for whichever box
    // [_dragKey] names, so setting the key first would have this line read the
    // position back off itself and anchor every drag at wherever the last one
    // happened to end.
    final from = _positionOf(node.key);

    // Anchored on the pointer's GLOBAL position, and the arithmetic below is
    // always anchor-to-now rather than a running sum of deltas. Local
    // coordinates would be measured against the box, which is itself moving —
    // the box would chase the pointer at an accelerating rate — and summed
    // deltas accumulate the rounding of every frame of a long drag.
    setState(() {
      _dragKey = node.key;
      _dragTo = from;
      _pointerAnchor = details.globalPosition;
      _boxAnchor = from;
      // The explanation is about the move that just finished, so the next move
      // starting is the moment it stops being about anything.
      _landedOn = null;
    });

    // The finger holding the box is also covering it, so "it is in hand now"
    // cannot only be something to look at. Same reasoning as the dashboard's
    // grip, and the same restriction to the pointers that have that problem.
    if (details.kind == PointerDeviceKind.touch ||
        details.kind == PointerDeviceKind.stylus ||
        details.kind == PointerDeviceKind.invertedStylus) {
      HapticFeedback.selectionClick();
    }
  }

  void _panUpdate(DragUpdateDetails details) {
    final key = _dragKey;

    // A pan update with nothing in hand means the tree was refetched mid-drag
    // and the box went away underneath the pointer. Reporting a position for a
    // node that no longer exists would write a stale entry every frame.
    if (key == null) return;

    // ── DIVIDED BY THE SCALE, and this is the defect worth naming ──
    //
    // A pointer moves in SCREEN points and a box's position is in CANVAS
    // points, and the two are the same number only at 100%. Left undivided,
    // this is the classic broken zoomable canvas: at 200% the box travels twice
    // as far as the pointer and slides out from under it, at 50% it lags behind
    // by half, and either way the thing being dragged is not the thing under
    // the finger. It is worst exactly where zoom is most useful — somebody
    // zoomed out to see the whole business, trying to tidy one box.
    //
    // The reported coordinate is a canvas coordinate for the same reason it
    // always was: the arrangement is shared, and a position stored in the
    // points of one person's screen at one person's zoom would land every
    // colleague's box somewhere else.
    final moved =
        _boxAnchor + (details.globalPosition - _pointerAnchor) / _zoom;

    setState(() {
      _dragTo = Offset(math.max(0, moved.dx), math.max(0, moved.dy));
    });

    _report(key, _dragTo);
  }

  void _panEnd(StructureNode node) {
    // Read BEFORE the box is let go, because [_positionOf] answers with
    // [_dragTo] only while [_dragKey] names it, and the position it would
    // otherwise fall back to is the one the CALLER has applied — which on a
    // caller that refused the move is where the box came from.
    //
    // A drag that ended where it began is not a move and raises nothing. A tap
    // on a box that already happens to overlap another one would otherwise
    // explain a gesture nobody made.
    if (_dragKey == node.key && _dragTo != _boxAnchor) _noticeLanding(node);

    // Reported on every update rather than only here, because the caller is the
    // one that debounces. A canvas that only spoke on release would make a
    // dropped pointer — a browser tab losing focus mid-drag — a move that never
    // happened at all.
    setState(() => _dragKey = null);
  }

  /// ── Noticing the question, without answering it differently ──
  ///
  /// Mechanism 4 at the top of this file says overlap is allowed and MEANS
  /// NOTHING, and that is still exactly true: nothing is saved differently,
  /// nothing snaps, no connector moves, no order changes, and this box stays
  /// precisely where the pointer left it. What is read here is not a drop —
  /// there is nothing to drop onto, there is no [DragTarget] in this file and
  /// there must never be one. It is the one moment we can be confident somebody
  /// has just asked a question, because dropping one box squarely onto another
  /// is what a person does when they mean "put this inside that". So the
  /// picture answers them in words instead of leaving the gesture to read as a
  /// control that broke.
  ///
  /// Also called at the end of a keyboard nudge, so the explanation is not a
  /// mouse-only affordance — the same reason the move handle exists at all.
  ///
  /// A box landing on its own parent or on one of its own children raises
  /// nothing. Those two are the only pairs where "put this inside that" is
  /// already true, so the sentence would be correcting a belief nobody holds,
  /// and it is also the overlap somebody makes on purpose when they are
  /// tucking a branch up tight.
  ///
  /// More than half of the moved card's own area, rather than any overlap at
  /// all. Boxes brush each other constantly on a hand-arranged canvas — that is
  /// what the drift note and "Arrange for me" are about — and a paragraph that
  /// appeared every time two corners touched would be noise somebody learns to
  /// look past, which is the one thing an explanation cannot afford to become.
  ///
  /// Measured on the CARDS, so a company's list of places counts as part of it.
  /// The alternative — comparing the boxes and ignoring the lists — would stay
  /// silent while A-1 Chimney's nine branches were dropped squarely across
  /// Everdoors, which is the most emphatic way anybody can ask the question this
  /// answers.
  void _noticeLanding(StructureNode node) {
    final mine = _positionOf(node.key) & _cardSize(node.key);
    final half = mine.width * mine.height / 2;

    for (final other in _boxes) {
      if (other.key == node.key) continue;
      if (other.key == node.parentKey || other.parentKey == node.key) continue;

      final overlap = mine.intersect(
        _positionOf(other.key) & _cardSize(other.key),
      );

      if (overlap.width <= 0 || overlap.height <= 0) continue;
      if (overlap.width * overlap.height <= half) continue;

      setState(() => _landedOn = (moved: node.name, onto: other.name));

      // The same words, for somebody who cannot see the strip. This is the one
      // announcement in this widget that is longer than a clause, and it earns
      // it: it is the only thing said anywhere that explains why the gesture
      // did not do what it looks like it should have.
      _say(_landedNote!);

      return;
    }
  }

  /// The explanation, written for somebody who runs a business.
  ///
  /// No "re-parent", no "hierarchy", no "immutable", and no section number.
  /// What it says instead is the actual reason: the numbers already counted
  /// under a thing were counted there at the time, so moving it now would make
  /// the past look like it happened somewhere else. That is `CompaniesController`'s
  /// own argument — "a franchise's numbers would appear to have always belonged
  /// to its new parent" — in the words the owner of the business would use.
  ///
  /// It ends with the way out, because a rule with no remedy is a dead end: add
  /// the thing where it should be and switch the old one off. That is a real
  /// path through this product, it keeps the history where it was made, and it
  /// is the answer support would give anyway.
  String? get _landedNote {
    final landed = _landedOn;

    if (landed == null) return null;

    return '“${landed.moved}” has not been moved into “${landed.onto}”. It is '
        'still where it has always been — you have only moved its box on '
        'screen. Where something sits in the business is settled when it is '
        'created and does not change afterwards, because everything already '
        'counted under it — its work, its money, its settings — was counted '
        'there at the time, and moving it now would make the past look like it '
        'happened somewhere else. If something really needs to sit somewhere '
        'else, add it there and switch the old one off; the history stays where '
        'it was made.';
  }

  // ── moving a box without a mouse ─────────────────────────────

  /// 16 a press, 64 with Shift held. Not a pixel: a canvas nudged one pixel at
  /// a time is a canvas a keyboard user gives up on, and both figures are
  /// factors of the gap between rows so a nudged box still lines up with one
  /// that was arranged automatically.
  static const double _nudgeStep = 16;
  static const double _nudgeStride = 64;

  void _startNudge(StructureNode node) {
    _nudgeFrom = _positionOf(node.key);

    setState(() {
      _nudging = node.key;
      _landedOn = null;
    });
    _focusNode(node.key).requestFocus();

    _say(
      'Moving ${node.name}. Arrow keys move it. Press Escape when you are done.',
    );
  }

  void _endNudge(StructureNode node) {
    if (_nudging != node.key) return;

    final moved = _positionOf(node.key) != _nudgeFrom;

    setState(() => _nudging = null);
    _say('${node.name} placed.');

    // After the confirmation rather than instead of it: finishing the move and
    // what the move did or did not mean are two different facts, and somebody
    // listening is owed both.
    if (moved) _noticeLanding(node);
  }

  /// ── Why nothing about a BOX ever says a number ───────────────
  ///
  /// Every other announcement in this app says where a thing landed — the
  /// dashboard's is "moved to position 3 of 5". This one deliberately says
  /// nothing between the first sentence and the last, and never a coordinate.
  ///
  /// That is the feature's own constraint showing up in the audio: moving a box
  /// changes NOTHING a screen reader would report about the business. The
  /// sentence the box carries — its name, its level, what it is under — is
  /// identical before and after. Reading out coordinates would be inventing a
  /// fact about a picture nobody using a screen reader can see, and it would be
  /// the only place in this feature that implied the move meant something.
  ///
  /// The zoom percentage IS said, and the distinction is worth stating so the
  /// two rules are not read as one being broken: a coordinate describes the
  /// picture, and a percentage describes the instrument somebody is looking at
  /// it through. [_sayZoom] carries the rest of that argument.
  ///
  /// Sent to THIS view rather than the implicit one, for `DashboardGrid`'s
  /// reason: `SemanticsService.announce` asserts there is exactly one window,
  /// and this app is web today with desktop and mobile behind it.
  void _say(String sentence) => SemanticsService.sendAnnouncement(
    View.of(context),
    sentence,
    Directionality.of(context),
  );

  KeyEventResult _onKey(StructureNode node, KeyEvent event) {
    // Arrows are only ever taken while this box is explicitly in move mode, and
    // the mode ends when it loses focus. An always-on binding would trade a
    // keyboard user's ability to move a box for their ability to READ the
    // structure at all, which is a bad trade in a view whose whole job is to be
    // read.
    if (_nudging != node.key) return KeyEventResult.ignored;
    if (event is! KeyDownEvent && event is! KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }

    final pressed = event.logicalKey;

    if (pressed == LogicalKeyboardKey.escape ||
        pressed == LogicalKeyboardKey.enter) {
      _endNudge(node);

      return KeyEventResult.handled;
    }

    final step = HardwareKeyboard.instance.isShiftPressed
        ? _nudgeStride
        : _nudgeStep;

    final by = switch (pressed) {
      LogicalKeyboardKey.arrowLeft => Offset(-step, 0),
      LogicalKeyboardKey.arrowRight => Offset(step, 0),
      LogicalKeyboardKey.arrowUp => Offset(0, -step),
      LogicalKeyboardKey.arrowDown => Offset(0, step),
      _ => null,
    };

    if (by == null) return KeyEventResult.ignored;

    _report(node.key, _positionOf(node.key) + by);

    return KeyEventResult.handled;
  }

  // ── the two toolbar controls ─────────────────────────────────

  void _arrange() {
    final placed = _arrangement();

    // Read BEFORE anything is told about the new arrangement, because this is
    // where every box currently IS — including one that is part-way through an
    // arrangement somebody pressed a moment ago, which is why it is read through
    // [_positionOf] rather than off the layout.
    _leaving = {for (final node in _boxes) node.key: _positionOf(node.key)};

    setState(() {
      _nudging = null;
      // And the landing sentence with it. It names two boxes and the overlap
      // between them, and this button has just moved every box — so leaving it
      // up describes a picture that is no longer on screen, which is worse than
      // saying nothing at all.
      _landedOn = null;
      // Arranged and then looked at. Leaving the viewport where it was would
      // tidy the whole picture somewhere off screen, which reads as a button
      // that did nothing.
      //
      // Scaled, for [_homeView]'s reason: the corner of the tidied picture is
      // drawn at `topLeft × zoom + view`, so aiming at it means subtracting the
      // scaled coordinate. The zoom itself is deliberately LEFT ALONE — this
      // button rearranges the boxes, and quietly resetting the scale would take
      // away the magnification somebody chose in order to read what they were
      // tidying.
      _view = Offset(kStructureMargin, kStructureMargin) -
          (placed.values.isEmpty ? Offset.zero : _topLeftOf(placed)) * _zoom;
    });

    widget.onArranged?.call(placed);

    // Somebody who has asked their system to stop moving things is asking this
    // canvas too, and then the boxes simply arrive.
    if (MediaQuery.disableAnimationsOf(context)) {
      _arranging.value = 1;
    } else {
      _arranging.forward(from: 0);
    }

    _say('The boxes have been arranged.');
  }

  // ── moving the viewport ──────────────────────────────────────

  /// A two-finger trackpad gesture, wherever the cursor happens to be sitting.
  ///
  /// Flutter delivers a trackpad pan as a PAN-ZOOM pointer, and a
  /// [GestureDetector] accepts those by default — so a two-finger scroll with
  /// the cursor over a box used to pick the box up, move it, report the move and
  /// have it written to the arrangement, while the identical gesture two
  /// centimetres to the left correctly scrolled the view. Scrolling must not
  /// mean two different things depending on what is underneath, and the one it
  /// must never mean is "rearrange the picture of the business".
  ///
  /// So a box's own drag refuses trackpad pointers ([_dragPointers]) and a
  /// [Listener] around each box catches them here instead. A Listener rather
  /// than a gesture, for the reason the wheel handler gives: it never enters the
  /// arena, so it cannot take a drag off anything.
  ///
  /// The background surface is untouched and still panned by its scale
  /// recognizer, which reads a trackpad pan as focal-point movement — the same
  /// "the surface follows your fingers" this produces, so the gesture does the
  /// same thing over a box as it does beside one.
  /// A trackpad's own gesture also carries a PINCH, which is the same zoom.
  ///
  /// `PointerPanZoomUpdateEvent` reports `pan` and `scale` together, because on
  /// a trackpad they are one gesture: two fingers sliding is a pan, two fingers
  /// spreading is a zoom, and doing both at once is ordinary rather than
  /// exceptional. Reading only the pan — which is what this did — meant a
  /// trackpad user pinching over a box got nothing at all, and the whole point
  /// of putting zoom on the wheel and the pinch is that they never have to go
  /// looking for the buttons.
  ///
  /// The anchor is put back into the SURFACE's coordinates, because the
  /// [Listener] this comes through is wrapped around a box — see [_surfaceKey].
  void _panZoomStart(PointerPanZoomStartEvent event) {
    _viewAnchor = _view;
    _zoomAnchor = _zoom;
    _viewPointerAnchor = _onSurface(event.position) ?? Offset.zero;
  }

  void _panZoomUpdate(PointerPanZoomUpdateEvent event) {
    // The cursor does not move during a trackpad gesture; `pan` is how far the
    // fingers have travelled since it began, so where they are NOW is the
    // anchor plus the pan.
    _pinch(_viewPointerAnchor + event.pan, event.scale);
  }

  /// One gesture, both things: the surface follows the fingers and the picture
  /// grows about the point between them.
  ///
  /// ── Why a pan and a pinch are the same three lines ───────────
  ///
  /// [focus] is where the fingers are now and [_viewPointerAnchor] is where
  /// they were when the gesture started, both in surface coordinates. The
  /// canvas point that was under them at the start is `(anchor − viewAnchor) ÷
  /// zoomAnchor`, and keeping that point under the fingers at the new scale
  /// gives `view = focus − (anchor − viewAnchor) × scale`.
  ///
  /// At a scale of exactly 1 that reduces to `viewAnchor + (focus − anchor)`,
  /// which is the plain pan this used to be, so a one-finger drag and a
  /// two-finger pinch are not two code paths that have to be kept in step. The
  /// alternative — a pan handler and a separate zoom handler — is how the
  /// picture ends up sliding during a pinch, because each of them is anchored
  /// on its own idea of where the gesture began.
  void _pinch(Offset focus, double scale) {
    final next = (_zoomAnchor * scale).clamp(
      kStructureZoomMin,
      kStructureZoomMax,
    );

    setState(() {
      _view =
          focus - (_viewPointerAnchor - _viewAnchor) * (next / _zoomAnchor);
      _zoom = next;
    });
  }

  /// A global point in the drawing surface's own coordinates, or null before it
  /// has been laid out.
  Offset? _onSurface(Offset global) {
    final box = _surfaceKey.currentContext?.findRenderObject();

    if (box is! RenderBox || !box.hasSize) return null;

    return box.globalToLocal(global);
  }

  Offset _topLeftOf(Map<String, Offset> places) {
    var minX = double.infinity;
    var minY = double.infinity;

    for (final at in places.values) {
      minX = math.min(minX, at.dx);
      minY = math.min(minY, at.dy);
    }

    return Offset(minX.isFinite ? minX : 0, minY.isFinite ? minY : 0);
  }

  void _bringIntoView() {
    setState(() => _view = _homeView());
    _say('The boxes are back in view.');
  }

  /// A wheel, and the two things a wheel means.
  ///
  /// ── Plain wheel scrolls, ctrl with the wheel zooms ───────────
  ///
  /// Which is not a convention this feature invented — it is the one every
  /// browser, map and drawing tool has taught people, including the browser
  /// this runs inside. Choosing anything else would mean somebody's muscle
  /// memory did the wrong thing on the one gesture they make without looking.
  /// Meta is accepted alongside control because it is the same key on a Mac.
  ///
  /// Exponential rather than a fixed step per notch, so the gesture is
  /// symmetric: three notches in and three notches back arrive exactly where
  /// they started, which a `zoom ± 0.1` cannot do. It also makes a trackpad's
  /// stream of small deltas feel like the same control as a mouse's discrete
  /// notches rather than a wildly faster one.
  ///
  /// `PointerScaleEvent` is the same act again, from a third direction: a
  /// browser turns a trackpad pinch into ctrl-with-wheel, and the web engine
  /// hands that on as this. Handling it here rather than leaving it to fall
  /// through is what makes a pinch work for the web build, which is the build
  /// that ships today.
  ///
  /// Both anchor on the pointer, so the thing under the cursor is the thing
  /// that stays put — see [_zoomTo]. A [Listener] rather than a gesture, for
  /// the reason it always was: it never enters the arena, so it cannot take a
  /// drag off a box.
  void _onSignal(PointerSignalEvent signal) {
    // Local to this Listener, which is the drawing surface, which is the frame
    // [_view] is measured in. A global position would be out by the height of
    // everything above the canvas, so the picture would slide by the toolbar's
    // height on every notch.
    if (signal is PointerScaleEvent) {
      _zoomTo(_zoom * signal.scale, signal.localPosition);

      return;
    }

    if (signal is! PointerScrollEvent) return;

    final keys = HardwareKeyboard.instance;

    if (keys.isControlPressed || keys.isMetaPressed) {
      _zoomTo(
        _zoom * math.exp(-signal.scrollDelta.dy / _wheelZoomDamping),
        signal.localPosition,
      );

      return;
    }

    setState(() => _view -= signal.scrollDelta);
  }

  /// How much of a zoom one wheel notch is.
  ///
  /// A notch arrives as 120 logical pixels, and `exp(120 ÷ 860)` is about 1.15
  /// — roughly what a browser's own ctrl+wheel does, which is the step people
  /// already have a feel for. Larger and a single flick of a finger overshoots
  /// past anything readable; smaller and crossing the range means spinning a
  /// wheel for several seconds.
  static const double _wheelZoomDamping = 860;

  // ── drawing it ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_nodes.isEmpty) {
      // Not a blank canvas. A surface with nothing on it and no explanation is
      // indistinguishable from one that failed to load.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'There is nothing to draw yet. A company and its first place are '
            'created together on the list.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, box) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // ── the words are capped, the picture is not ──────────────
          //
          // Everything above the surface is explanation, and explanation must
          // never become the whole screen. These children have no flex, so in a
          // plain Column they take whatever height they ask for and the canvas
          // gets the remainder — which is fine until somebody reads at twice
          // the normal text size on a short viewport, where the toolbar, the
          // note and a landing sentence together ask for more than there is.
          // Then the surface is squeezed to nothing and the Column overflows:
          // an explanation of a picture, with no picture.
          //
          // So they are given a ceiling and their own scrollbar instead. The
          // canvas keeps the majority of the height whatever the text does, and
          // a sentence that will not fit becomes a short scroll rather than a
          // drawing surface nobody can use. Capped as a FRACTION rather than in
          // pixels because the thing being protected is the picture's share of
          // the screen, and a pixel figure would mean something different on a
          // phone than on a desktop.
          ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: box.maxHeight.isFinite
                  ? box.maxHeight * _mostOfTheHeightTheWordsMayTake
                  : double.infinity,
            ),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _toolbar(theme),
                  const Divider(height: 1),
                  // Above the surface rather than over it. A strip pushes the
                  // picture down by its own height and covers nothing; anything
                  // drawn on top of the canvas would hide the two boxes the
                  // sentence is about, which are the two things somebody is
                  // looking at while they read it.
                  //
                  // And emphatically not a dialog. This is an explanation of
                  // something that just happened, offered to somebody who is
                  // mid-task — the house rule is a screen or a panel, never a
                  // pop-up, and a modal here would stop the very gesture it is
                  // explaining.
                  if (_landedOn != null) _explanation(theme),
                ],
              ),
            ),
          ),
          Expanded(
            child: LayoutBuilder(
              builder: (context, constraints) {
                // A canvas needs a bounded height to be a canvas at all. A
                // caller that puts one inside a scroll view gets a workable
                // slab rather than an assertion, because a screen that throws
                // on layout is a screen nobody can get back out of.
                // Typed, because without it `520` is an int and the whole
                // expression comes out as `num` — which the two places it is
                // handed to both refuse, and which would otherwise have been
                // "fixed" by casting rather than by saying what it is.
                final double height = constraints.maxHeight.isFinite
                    ? constraints.maxHeight
                    : 520;

                // Written down as the surface is measured, which is the only
                // moment its size is known for certain, and read later by the
                // zoom buttons and by "Fit" — both of which live in the toolbar
                // above and have no constraints of their own. Assigned rather
                // than set through setState on purpose: nothing drawn this
                // frame depends on it, so asking for a rebuild here would be a
                // rebuild caused by a rebuild.
                _viewport = Size(
                  constraints.maxWidth.isFinite ? constraints.maxWidth : 0,
                  height,
                );

                return SizedBox(height: height, child: _surface(theme));
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _toolbar(ThemeData theme) {
    final controls = <Widget>[
      if (widget.onArranged != null)
        FilledButton.icon(
          onPressed: _arrange,
          icon: const Icon(Icons.schema_outlined, size: 18),
          label: const Text('Arrange for me'),
        ),
      // Panning is the only way to move around a free canvas, and a free canvas
      // is the only kind with no scrollbar to tell you how far you have gone.
      // Without this, dragging the background far enough leaves a person
      // looking at an empty surface with nothing to aim at — a dead end made
      // out of a feature.
      TextButton.icon(
        onPressed: _bringIntoView,
        icon: const Icon(Icons.filter_center_focus, size: 18),
        label: const Text('Bring the boxes into view'),
      ),
      // Drawn for everybody, including somebody who may not save. Reading a
      // chart thirteen places wide needs a scale as much as arranging it does,
      // and none of this is written anywhere — see the section at the top of
      // the file. Gating it on `organizations.edit` would take the only way of
      // seeing the whole business away from most of the business.
      _zoomCluster(theme),
      // Last, and after the zoom rather than before it, because it is the
      // caller's rather than this widget's — the row reads as "what this canvas
      // can do" and then "what the screen around it can do". See
      // [StructureCanvas.controls] for why it is a slot at all.
      ?widget.controls,
    ];

    final note = widget.note;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Wrapped rather than a Row, because two buttons and a 375-point
          // window do not fit on one line and a toolbar that overflows is a
          // control nobody can press.
          Wrap(
            spacing: 8,
            runSpacing: 8,
            // Middles rather than tops, because the things in this row are
            // different heights — a filled button, a framed stepper and a label
            // beside two chips — and a row of controls aligned by their tops
            // reads as several rows that failed to line up.
            crossAxisAlignment: WrapCrossAlignment.center,
            children: controls,
          ),
          if (note != null) ...[
            const SizedBox(height: 10),
            Text(note, style: theme.textTheme.bodyMedium),
          ],
        ],
      ),
    );
  }

  /// The width of the widest figure this control can show, in the style it is
  /// drawn in. `kStructureZoomMax` is 3, so that figure is "300%".
  double _zoomReadoutWidth(ThemeData theme, TextScaler scaler) {
    final painter = TextPainter(
      text: TextSpan(
        text: '${(kStructureZoomMax * 100).round()}%',
        style: theme.textTheme.labelLarge,
      ),
      maxLines: 1,
      textDirection: Directionality.of(context),
      textScaler: scaler,
    )..layout();
    final width = painter.width;
    painter.dispose();
    return width;
  }

  /// Out, the number, in — and "Fit" beside them.
  ///
  /// ── Why the three sit in a frame and Fit does not ────────────
  ///
  /// Because they are one control with three parts and Fit is a different act.
  /// Minus, the percentage and plus are a single quantity being adjusted, and
  /// the percentage is not a label for the buttons — it is the value they move,
  /// which is why it is BETWEEN them rather than beside them. Fit sets that
  /// value to whatever the window needs; grouping it with them would say it is
  /// a fourth step on the same scale.
  ///
  /// The frame is the outline token and nothing else — no fill, no elevation.
  /// It is grouping rather than emphasis, and a filled tile here would compete
  /// with "Arrange for me", which is the one thing on this toolbar that changes
  /// what everybody else sees.
  ///
  /// ── Both buttons go dead at the ends, deliberately ───────────
  ///
  /// This file argues elsewhere that a control should be ABSENT rather than
  /// disabled, and that is about permissions: a greyed-out button somebody can
  /// never use invites them to work out why. A numeric limit is the opposite
  /// case. "Zoom out" at 25% has a visible reason sitting a centimetre away in
  /// figures, the state is temporary and self-explanatory, and removing the
  /// button would make the cluster change shape as somebody scrolled — a
  /// control that moves under the pointer is worse than one that waits.
  ///
  /// ── Why the readout has a width of its own ───────────────────
  ///
  /// "25%" and "100%" are different widths, so without a fixed slot the plus
  /// button walks left and right as somebody steps through the ladder, and a
  /// button that moves while being pressed repeatedly gets missed. The slot is
  /// the width of the widest figure this control can show, measured in the
  /// style it is drawn in — a typed pixel count that looked right at one text
  /// size is the slot that wraps "100%" onto two lines at another.
  Widget _zoomCluster(ThemeData theme) {
    final scheme = theme.colorScheme;
    final scaler = MediaQuery.textScalerOf(context);

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            border: Border.all(color: scheme.outlineVariant),
            borderRadius: BorderRadius.circular(24),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                iconSize: 18,
                tooltip: 'Zoom out',
                icon: const Icon(Icons.remove),
                onPressed: _zoom <= kStructureZoomMin + _zoomTouching
                    ? null
                    : _zoomOut,
              ),
              SizedBox(
                width: _zoomReadoutWidth(theme, scaler),
                child: Semantics(
                  // The figures are what a sighted reader sees; this is the
                  // same fact in a sentence, so somebody landing here with a
                  // screen reader is told what the number IS rather than
                  // hearing "125 percent" with nothing to attach it to.
                  label: 'Zoom $_percent percent',
                  excludeSemantics: true,
                  child: Text(
                    '$_percent%',
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    softWrap: false,
                    style: theme.textTheme.labelLarge,
                  ),
                ),
              ),
              IconButton(
                iconSize: 18,
                tooltip: 'Zoom in',
                icon: const Icon(Icons.add),
                onPressed: _zoom >= kStructureZoomMax - _zoomTouching
                    ? null
                    : _zoomIn,
              ),
            ],
          ),
        ),
        TextButton.icon(
          onPressed: _fit,
          icon: const Icon(Icons.fit_screen_outlined, size: 18),
          // The word on the button and the sentence a pointer or a screen
          // reader gets are different lengths on purpose: "Fit" is what fits on
          // a toolbar at 375 points, and it does not say fit to WHAT.
          label: const Text('Fit'),
        ),
      ],
    );
  }

  /// The strip that answers "why did that not work", in the palette's own
  /// tokens.
  ///
  /// The same recipe the tab's own status notes use — `surfaceContainerHighest`
  /// and `bodySmall` — so a sentence about the structure looks the same
  /// wherever on this tab it appears. Never a literal colour and never an
  /// error colour: nothing went wrong here, and painting an explanation red
  /// would tell somebody they had broken something when they had not.
  ///
  /// "Got it" rather than a bare ✕, and it never times out. Somebody reading a
  /// paragraph must not be racing a timer, and a control whose only label is a
  /// glyph is a control a screen reader announces as "close" without saying
  /// what it closes.
  ///
  /// Wrapped, because the button and a paragraph do not share a line at 375
  /// points — the width this whole product is built to work at.
  Widget _explanation(ThemeData theme) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
      color: theme.colorScheme.surfaceContainerHighest,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(_landedNote!, style: theme.textTheme.bodySmall),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton(
              onPressed: () => setState(() => _landedOn = null),
              child: const Text('Got it'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _surface(ThemeData theme) {
    final scheme = theme.colorScheme;

    // One reading of where every box is, this frame, and everything below draws
    // from it: the boxes themselves and the lines between them. That is the
    // whole reason [_positionOf] interpolates rather than an implicit animation
    // doing it — an implicitly animated box knows where it is and the painter
    // beside it does not.
    // `screen = canvas × zoom + view`, in the one place the two frames meet.
    //
    // The scale is applied HERE rather than by wrapping the whole stack in a
    // Transform, and the reason is hit testing. A Transform changes what is
    // painted without changing what is laid out, so at 50% the boxes would be
    // drawn across the whole surface while the Stack behind them still measured
    // one window — and every box beyond that width would be visible and
    // undraggable, because a render box does not hit test outside its own size.
    // Scaling the rectangles instead means a box's drawn rectangle IS its hit
    // rectangle at every zoom, and the connector painter is handed the same
    // numbers on the same frame, which is the promise the whole widget is built
    // around.
    //
    // Only the things that HAVE a rectangle, which is now everything except a
    // place. A place is drawn inside its owner's card by [_placeRow], so it has
    // no entry here, is in no link, and is not a child of the Stack — which is
    // what makes "thirteen boxes became four" true of the widget tree and not
    // only of the picture.
    final rects = <String, Rect>{
      for (final node in _boxes)
        node.key: (_positionOf(node.key) * _zoom + _view) &
            (_cardSize(node.key) * _zoom),
    };

    // Card to card, and BOX to box within them — see the painter, which is
    // handed the height of the box at the top of every card so it can point a
    // line at a name rather than at the middle of a list of nine branches.
    // Every link is between two things that have a card, because a place hangs
    // off its company inside one rather than beside it.
    final links = <(Rect, Rect)>[
      for (final node in _boxes)
        if (node.parentKey != null && rects.containsKey(node.parentKey))
          (rects[node.parentKey]!, rects[node.key]!),
    ];

    return ClipRect(
      // Named so a pointer's global position can be put back into this
      // surface's own coordinates — see [_onSurface], and [_panZoomStart] for
      // the one gesture that needs it.
      key: _surfaceKey,
      child: DecoratedBox(
        decoration: BoxDecoration(color: scheme.surfaceContainerHigh),
        // A wheel scrolls the canvas, which is how a desktop expects to move
        // around one. A Listener rather than a gesture: it never enters the
        // arena, so it cannot take a drag off a box.
        //
        // A trackpad does NOT arrive here. Two fingers on a trackpad are a
        // pan-zoom pointer rather than a signal, and those are handled by the
        // pan surface below when they land on the background and by a Listener
        // around each box when they land on one — see [_panZoomStart], which is
        // where the reason both exist is written down.
        child: Listener(
          onPointerSignal: _onSignal,
          // Tree order, not screen order. Flutter's default policy reads a
          // Stack of Positioned children GEOMETRICALLY, so Tab order would
          // change every time somebody dragged a box — the structure would
          // become unreadable in exactly the proportion that the picture became
          // useful. The boxes are built in tree order below for this line.
          child: FocusTraversalGroup(
            policy: WidgetOrderTraversalPolicy(),
            child: Stack(
              children: [
                // FIRST, so it is bottom of the paint order and LAST in hit
                // testing. A Stack stops at the first child that takes the hit,
                // so a pointer landing on a box never reaches this and the
                // viewport pan cannot steal a box's drag.
                Positioned.fill(
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    // Scale rather than pan, following the logo cropper: a
                    // one-finger drag arrives as a scale of 1.0, and using it
                    // means a second finger landing mid-drag keeps panning
                    // instead of confusing a pan recognizer.
                    //
                    // That choice is what makes a touchscreen PINCH free here.
                    // The recognizer was already reporting `scale` and this was
                    // already throwing it away; reading it means two fingers
                    // spreading on the surface do the same thing as ctrl with a
                    // wheel and the same thing as the buttons, which is the
                    // whole point of having all three.
                    //
                    // The focal point is taken LOCAL rather than global,
                    // because a zoom has to be anchored in the frame [_view] is
                    // measured in. It made no difference while this only ever
                    // took a difference between two of them.
                    //
                    // What this cannot reach, said plainly rather than left to
                    // be found: a pinch whose two fingers both land on BOXES.
                    // Each box owns its own drag recognizer and Flutter's arena
                    // is per pointer, so those two fingers are two separate
                    // one-finger drags rather than one gesture. Fixing it
                    // properly would mean moving every box's drag up into a
                    // single surface-level recognizer that hit tests the boxes
                    // itself — which would re-open the trackpad-versus-box
                    // arbitration this file has just settled ([_panZoomStart]),
                    // to buy a gesture that already works on the surface
                    // between the boxes, over any box for a trackpad, and from
                    // three controls in the toolbar.
                    onScaleStart: (details) => setState(() {
                      _viewAnchor = _view;
                      _viewPointerAnchor = details.localFocalPoint;
                      _zoomAnchor = _zoom;
                    }),
                    onScaleUpdate: (details) =>
                        _pinch(details.localFocalPoint, details.scale),
                  ),
                ),
                Positioned.fill(
                  // A CustomPaint takes the hit by DEFAULT — `hitTestSelf`
                  // returns true unless the painter says otherwise — which
                  // would put the connectors above the pan surface and leave
                  // the background undraggable everywhere.
                  child: IgnorePointer(
                    child: CustomPaint(
                      painter: StructureConnectorPainter(
                        links: links,
                        // As drawn, which is what the rectangles beside it are.
                        // A box is the same height whatever its company owns —
                        // only the card under it grows — so one number answers
                        // for every link.
                        faceHeight: _boxHeight * _zoom,
                        // Never a literal, and deliberately not
                        // `EzBiziTheme.edge`: that token is 0xFFE9EDF1 against
                        // this canvas's own ground, which is about 1.05:1 —
                        // invisible. `outlineVariant` is derived from the
                        // scheme, so an organization's own theme moves it, and
                        // it reads on both grounds.
                        color: scheme.outlineVariant,
                      ),
                    ),
                  ),
                ),
                for (final node in _boxes) _card(theme, node, rects[node.key]!),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// One card: a box, and under it the compact list of places that hang off it.
  ///
  /// ── Why the two are one Positioned rather than two ───────────
  ///
  /// Because they are one thing on the canvas. A place has no position of its
  /// own — it is drawn where its company is, and it moves when its company moves
  /// — so the alternative is a second Positioned that has to be kept underneath
  /// the first on every frame of every drag, at every zoom, through the
  /// "Arrange for me" ease. One rectangle cannot come apart.
  ///
  /// It is also what makes the whole card draggable, which is the honest
  /// gesture: the list belongs to the box, so picking up any part of it picks up
  /// the box. The alternative — a drag that only starts on the box and leaves
  /// the list inert — would have a pointer landing on nine of A-1 Chimney's
  /// branches fall through to the background and pan the view instead, which is
  /// a card that is only draggable at the top and says nothing about where.
  Widget _card(ThemeData theme, StructureNode node, Rect at) {
    final movable = widget.onMoved != null;
    final places = _places[node.key] ?? const <StructureNode>[];

    return Positioned(
      key: structureCardKey(node.key),
      left: at.left,
      top: at.top,
      // The rectangle already carries the zoom, so the slot a card occupies on
      // screen and the area that answers a pointer are the same rectangle at
      // every scale. What is INSIDE is still built at the canvas's own sizes and
      // scaled to fill this — see the FittedBox below.
      width: at.width,
      height: at.height,
      // Plain, not AnimatedPositioned. [at] already carries the one easing this
      // widget does, worked out in [_positionOf] where the connector painter can
      // read the same numbers — see the note on [_arranging].
      child: MouseRegion(
        // `move`, not `grab`. See the note at the top of the file: one of
        // these says "carry this somewhere", and it is not this one.
        cursor: movable
            ? SystemMouseCursors.move
            : SystemMouseCursors.basic,
        // The card is opaque to hit testing, so a two-finger trackpad gesture
        // landing on it never reaches the pan surface underneath — which is
        // why this catches it here and moves the viewport rather than the
        // box. See [_panZoomStart].
        child: Listener(
          onPointerPanZoomStart: _panZoomStart,
          onPointerPanZoomUpdate: _panZoomUpdate,
          child: GestureDetector(
            behavior: HitTestBehavior.opaque,
            // Everything but a trackpad. A DragGestureRecognizer takes
            // trackpad pan-zoom pointers unless it is told not to, and a
            // two-finger scroll is not somebody moving this box.
            supportedDevices: _dragPointers,
            onPanStart: movable ? (d) => _panStart(node, d) : null,
            onPanUpdate: movable ? _panUpdate : null,
            onPanEnd: movable ? (_) => _panEnd(node) : null,
            // ── The card is built once, at its own size, and scaled ──
            //
            // Everything inside — the glyph, the two lines of text, the move
            // handle, the caller's menu, every place row — is laid out against
            // [kStructureBoxWidth], [_boxHeight] and [_placeRowHeight] exactly
            // as it would be at 100%, and this scales the finished thing. The
            // alternative was multiplying every size, padding and font by the
            // zoom, which is a dozen numbers that have to be kept in step, and
            // the first one anybody forgot would be a name ellipsizing at one
            // scale and not at another.
            //
            // Scaled rather than re-laid-out also means the picture at 50% is
            // the picture at 100%, smaller. A card that re-flowed its contents
            // as it shrank would be a different card, and somebody zooming out
            // to see the shape would find the shape had changed.
            //
            // The SizedBox is what makes it work at all: a FittedBox lays its
            // child out unbounded, and the Rows inside have Expandeds in them,
            // which assert under an unbounded width.
            child: FittedBox(
              fit: BoxFit.fill,
              child: SizedBox(
                width: kStructureBoxWidth,
                height: _boxHeight + _placesHeight(node.key),
                child: Column(
                  children: [
                    SizedBox(
                      height: _boxHeight,
                      // The focus, the key and the box's own sentence wrap the
                      // BOX rather than the card, and that is deliberate on all
                      // three counts. A place row inside carries a sentence of
                      // its own, so a container around both would read the
                      // company's name and then swallow its branches; the key
                      // has to answer "how big is a box drawn" for every zoom
                      // assertion, which a rectangle that grows with the list
                      // cannot; and the arrow keys belong to the box, because
                      // the box is the thing with a position.
                      child: Semantics(
                        container: true,
                        label: _sentenceFor(node),
                        child: Focus(
                          key: structureBoxKey(node.key),
                          focusNode: _focusNode(node.key),
                          onKeyEvent: (_, event) => _onKey(node, event),
                          onFocusChange: (has) {
                            // Focus is handed back as a widget is being taken
                            // down, which is after this State can be asked to
                            // rebuild.
                            if (!mounted) return;

                            setState(() {
                              if (has) {
                                _focused = node.key;
                              } else {
                                if (_focused == node.key) _focused = null;
                                // The mode is per box and ends on blur, which
                                // is what keeps Tab working: leaving a box
                                // always hands the arrow keys back to whatever
                                // wants them next.
                                if (_nudging == node.key) _nudging = null;
                              }
                            });
                          },
                          child: _face(theme, node),
                        ),
                      ),
                    ),
                    if (places.isNotEmpty)
                      SizedBox(
                        height: _placesHeight(node.key),
                        child: Column(
                          children: [
                            // The gap between the box and its first place,
                            // which is what [structurePlacesHeight] reserves.
                            // Spent as space rather than as padding on the
                            // rows, so the arithmetic here and the arithmetic
                            // the automatic layout does are the same shape and
                            // a reader can check one against the other.
                            const SizedBox(height: kStructurePlacesGap),
                            for (final place in places)
                              _placeRow(theme, place),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// One place, as a row in the list attached to the company that owns it.
  ///
  /// ── Why this is not a small box ──────────────────────────────
  ///
  /// No frame, no fill and no move handle, and each absence is the picture
  /// saying something true. A place has nowhere of its own to be — it cannot be
  /// dragged, because it has no position for a drag to change — and a rounded
  /// rectangle with a border is what everything else on this canvas that CAN be
  /// dragged looks like. Drawing one here would be an affordance for a gesture
  /// that does not exist, which is the same class of mistake as a drop target on
  /// a canvas that cannot re-parent.
  ///
  /// What it keeps is everything a place actually says: the glyph, the name, the
  /// organization's own word for one (§8), whether it is switched off, and the
  /// caller's action menu. All five are what the box carried this morning, and
  /// dropping any of them would have made "a place is not a box" mean "a place
  /// tells you less", which is not what the owner picked from the mockups.
  ///
  /// It carries [structureBoxKey] for the same reason: the question "where on
  /// screen is `franchise:abc`" is asked by this tab's tests and by nothing
  /// else, and the answer is a row now rather than a box.
  Widget _placeRow(ThemeData theme, StructureNode place) {
    final scheme = theme.colorScheme;
    final actions = widget.actions?.call(context, place);

    return SizedBox(
      height: _placeRowHeight,
      // The label OUTSIDE the keyed widget rather than inside it, which is not
      // cosmetic: `WidgetTester.getSemantics` walks UP from the element a finder
      // landed on, so a key beneath the label finds it and a key above it walks
      // past every widget in this card and answers with the page. The box does
      // the same thing for the same reason — its key is on the [Focus] inside
      // its own label.
      child: Semantics(
        container: true,
        label: _sentenceFor(place),
        child: Padding(
          key: structureBoxKey(place.key),
          // Indented from the left so the list reads as hanging off the box
          // above it rather than as a continuation of it, and level with the
          // box's own text so the two columns of names line up. Nothing on the
          // right, because the menu is a full-size target and its own padding
          // is what holds it off the edge.
          padding: const EdgeInsets.only(left: 22),
          child: Row(
            children: [
              // Excluded from semantics, both of them, for the same reason the
              // box's are: the row's own label already says the name, the word
              // and the state as one sentence, in the order a reader wants it.
              // The CALLER's menu is untouched — it is a control, and taking a
              // control out of the semantics tree is how a menu becomes
              // unreachable.
              ExcludeSemantics(
                child: Icon(
                  _glyphFor(place),
                  size: 16,
                  color: place.isActive ? scheme.onSurfaceVariant : scheme.outline,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: ExcludeSemantics(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        place.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontSize: 13,
                          color: place.isActive ? null : scheme.onSurfaceVariant,
                          // Being switched off carried by a line as well as by
                          // grey, so it survives a reader who cannot see the
                          // grey — the same three channels the box uses.
                          decoration:
                              place.isActive ? null : TextDecoration.lineThrough,
                        ),
                      ),
                      Text(
                        place.isActive
                            ? _wordFor(place)
                            : '${_wordFor(place)} · switched off',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: place.isActive ? null : scheme.error,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              ?actions,
            ],
          ),
        ),
      ),
    );
  }

  /// What a screen reader is told, and it is the whole fact about the node.
  ///
  /// "under Everdoors" and the connector on the screen are the same
  /// relationship said twice for two different readers, and both are read off
  /// the tree rather than off the positions — which is why dragging a box
  /// cannot change either of them.
  String _sentenceFor(StructureNode node) {
    final parent = node.parentName;

    return [
      '${node.name} — ${_saidFor(node)}',
      if (parent != null) 'under $parent',
      // The same clause the franchise chip already uses on the list tab.
      if (!node.isActive) 'switched off',
    ].join(', ');
  }

  /// The level as it is SAID, which is not always how it is written on the box.
  ///
  /// The three words this file owns are lower-cased, because they are being read
  /// out in the middle of a sentence. The organization's own word for a place is
  /// left exactly as they typed it, following `StructureOutlineRow.caption`: it
  /// is their word, and it may well be an acronym or a proper noun that a
  /// `toLowerCase()` would quietly damage.
  String _saidFor(StructureNode node) => node.kind == structureFranchise
      ? widget.franchiseLabel
      : _wordFor(node).toLowerCase();

  /// ── Telling four levels apart without relying on colour ──────
  ///
  /// The word is the decisive channel and it is deliberately the plainest one:
  /// it survives colour blindness, a greyscale screen, a printout and a screen
  /// reader, none of which a fill or a border does. The glyph is there so the
  /// levels can be told apart at a glance without reading, and on the three
  /// things that get a box the corner radius and the border weight separate the
  /// ends — the whole business is the squarest and heaviest thing on the canvas.
  ///
  /// A place is now told apart by something stronger than any of those: it is
  /// not a box at all. It is a row in the list under the company that owns it,
  /// which says both what it is and whose it is in one reading, and is why the
  /// two shape channels below no longer have a case for one.
  ///
  /// A fifth channel of shape for company-versus-sub-company was considered and
  /// dropped: three distinct box shapes in a 260-point slot is a visual language
  /// nobody has been taught, and "SUB COMPANY" written on the box is not.
  ///
  /// A place is called whatever the ORGANIZATION calls one (§8). This used to
  /// say "Place", which was the same tab contradicting itself: widen the window
  /// past `kStructureOutlineBelow` and a row that had read "Territory in
  /// Sunward" a moment earlier became a box reading "Place".
  String _wordFor(StructureNode node) => switch (node.kind) {
    structureOrganization => 'Organization',
    structureFranchise => widget.franchiseLabel,
    _ => node.isSub ? 'Sub company' : 'Company',
  };

  IconData _glyphFor(StructureNode node) => switch (node.kind) {
    // The same glyph the list tab already gives the organization.
    structureOrganization => Icons.hub_outlined,
    structureFranchise => Icons.storefront_outlined,
    _ => node.isSub ? Icons.account_tree_outlined : Icons.apartment_outlined,
  };

  /// The two below are asked only of things that HAVE a box, so neither has a
  /// case for a place any more. The `franchise` arms were removed rather than
  /// left to rot: a switch arm nothing can reach is a claim that a place is
  /// drawn as a 26-radius rounded rectangle, which is exactly the picture this
  /// change replaced.
  double _radiusFor(StructureNode node) =>
      node.kind == structureOrganization ? 16 : 12;

  double _borderFor(StructureNode node) => switch (node.kind) {
    structureOrganization => 2,
    _ => node.isSub ? 1 : 1.5,
  };

  Widget _face(ThemeData theme, StructureNode node) {
    final scheme = theme.colorScheme;
    final highlighted = _focused == node.key || _nudging == node.key;

    // The same 2px primary border the dashboard grid uses for a DROP TARGET.
    // The vocabulary is free here precisely because this canvas has nothing to
    // drop onto — and a later reader must not "restore" drop behaviour to match
    // a border they recognise.
    final border = highlighted
        ? BorderSide(color: scheme.primary, width: 2)
        : BorderSide(
            color: node.kind == structureOrganization
                ? scheme.outline
                : scheme.outlineVariant,
            width: _borderFor(node),
          );

    final leading = widget.leading?.call(context, node);
    final actions = widget.actions?.call(context, node);

    return Container(
      decoration: BoxDecoration(
        color: node.isActive ? scheme.surface : scheme.surfaceContainerLow,
        borderRadius: BorderRadius.circular(_radiusFor(node)),
        border: Border.fromBorderSide(border),
      ),
      padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
      child: Row(
        children: [
          // Excluded from semantics, both of them, because the box's own label
          // already says the name, the level and the state as one sentence.
          // Left in, a reader would hear "Sunward — sub company, under
          // Everdoors, switched off" and then "Sunward", "Sub company ·
          // switched off" again: the same three facts, twice, in a worse
          // order. The CALLER's leading and trailing widgets are untouched —
          // they are controls, and taking a control out of the semantics tree
          // is how a menu becomes unreachable.
          leading ??
              ExcludeSemantics(
                child: Icon(
                  _glyphFor(node),
                  size: node.kind == structureOrganization ? 22 : 20,
                  color: node.isActive
                      ? scheme.onSurfaceVariant
                      : scheme.outline,
                ),
              ),
          const SizedBox(width: 10),
          Expanded(
            child: ExcludeSemantics(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    node.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontSize: 14,
                      color: node.isActive ? null : scheme.onSurfaceVariant,
                      // Being switched off carried by a line as well as by
                      // grey, so it survives a reader who cannot see the grey.
                      // The semantics label says it in words for one who can
                      // see neither.
                      decoration:
                          node.isActive ? null : TextDecoration.lineThrough,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    node.isActive
                        ? _wordFor(node)
                        : '${_wordFor(node)} · switched off',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: node.isActive ? null : scheme.error,
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (widget.onMoved != null) _moveButton(node),
          ?actions,
        ],
      ),
    );
  }

  Widget _moveButton(StructureNode node) {
    final moving = _nudging == node.key;

    return IconButton(
      // An 18-point glyph in a full-size button, so the touch target stays at
      // the framework's interactive minimum inside the box. The box is 260
      // points wide so that this and the caller's menu can both be full-size
      // targets and still leave the name something to say.
      iconSize: 18,
      // What a screen reader reads as well as what a hovering pointer sees, so
      // this is not icon-only to anybody. A control a finder cannot reach is a
      // control a person cannot either.
      tooltip: moving
          ? 'Finish moving “${node.name}”'
          : 'Move “${node.name}” with the arrow keys',
      icon: Icon(moving ? Icons.check : Icons.open_with),
      onPressed: () => moving ? _endNudge(node) : _startNudge(node),
    );
  }
}

/// The lines between the boxes.
///
/// Drawn from the TREE and never from the positions, which is what makes a
/// connector incapable of saying anything except who a node's parent is. Under
/// the boxes, so a line never crosses a name.
///
/// Public, and nothing outside this file constructs one. It is named so that
/// the assertions about it can read [color] and [links] off the widget tree
/// with types — the alternative is a test reaching into a private class
/// dynamically, which fails at run time with a NoSuchMethodError the day a
/// field is renamed and says nothing useful about what actually broke.
class StructureConnectorPainter extends CustomPainter {
  const StructureConnectorPainter({
    required this.links,
    required this.faceHeight,
    required this.color,
  });

  /// Parent CARD and child CARD, both already in viewport coordinates.
  ///
  /// Cards rather than boxes, and that is what keeps a line out of a list of
  /// places. A card is a box with its places under it, so in a top-to-bottom
  /// chart the run has to leave the bottom of the whole card — leaving the
  /// bottom of the BOX would send the vertical segment straight down through
  /// the middle of the company's own nine branches, drawing a line through nine
  /// names.
  final List<(Rect, Rect)> links;

  /// How tall the box at the top of every card is, as drawn.
  ///
  /// ── Why a scalar rather than a third rectangle per link ──────
  ///
  /// Because a box is the same height on every card — only the list under it
  /// varies — so one number answers for all of them, and a per-link rectangle
  /// would be the same value repeated with an opportunity to disagree.
  ///
  /// It is what the SIDEWAYS runs anchor on. A left-to-right chart puts the
  /// child in the next column, so nothing is crossed and the honest place to
  /// leave from is the middle of the box: a line that left A-1 Chimney's card at
  /// its vertical centre would leave it beside the fifth of its nine branches,
  /// pointing at a place rather than at the company. Vertically the opposite is
  /// true, which is why the two directions read different edges — the geometry
  /// decides which, exactly as it always did.
  final double faceHeight;

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final pen = Paint()
      ..color = color
      ..strokeWidth = 1.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    for (final (parent, child) in links) {
      final route = _route(parent, child, faceHeight);
      final path = Path()..moveTo(route.first.dx, route.first.dy);

      for (final point in route.skip(1)) {
        path.lineTo(point.dx, point.dy);
      }

      // A switched-off box keeps its line at full strength on purpose. The
      // RELATIONSHIP is not switched off; the company is, and the box already
      // says so in three ways.
      canvas.drawPath(path, pen);
    }
  }

  /// An orthogonal run out of one card and into the other.
  ///
  /// Not a bezier. With free positions a curve routes straight through whatever
  /// boxes happen to lie between, whereas an out-across-in run stays readable
  /// in every arrangement — including upside down, which free positioning
  /// allows and which an org chart drawn by hand will eventually contain.
  ///
  /// Which edges it leaves from is decided by where the cards actually ARE, not
  /// by which is the parent, so a child dragged above or beside its parent gets
  /// a line that still reads as a connection rather than one that doubles back
  /// through both cards. That is also what makes ONE routine serve both
  /// orientations: left to right takes the third branch and top to bottom the
  /// first, without either being named anywhere in here.
  ///
  /// ── Cards vertically, boxes horizontally ─────────────────────
  ///
  /// [faceHeight] is where that shows up, and it is the one asymmetry in this
  /// routine. Going DOWN, the line must clear the parent's own list of places,
  /// so it leaves the card's bottom edge; going ACROSS, nothing is in the way
  /// and the line should point at the box, so both ends anchor half a box down
  /// from the top of their card. The alternative — the card's vertical centre —
  /// puts the line beside a company's fifth branch and reads as a connector to a
  /// place, which is the one thing on this canvas a connector may never appear
  /// to be.
  ///
  /// A run that spans a level nothing is on needs no case, and must not get one.
  /// It is out of the near edge, along to the midpoint between the two cards,
  /// across, and in — which is the same line the reader has already seen twenty
  /// of, drawn longer. The tempting "fix" for a gap that looks unusual is a
  /// special case that shortens it, and shortening it would put the jog
  /// somewhere other than between the two things it actually spans.
  static List<Offset> _route(Rect from, Rect to, double faceHeight) {
    // Half a box down from the top, which on a card with no places is the
    // centre and is what this always was.
    final fromFace = from.top + faceHeight / 2;
    final toFace = to.top + faceHeight / 2;

    if (to.top >= from.bottom) {
      final mid = (from.bottom + to.top) / 2;

      return [
        Offset(from.center.dx, from.bottom),
        Offset(from.center.dx, mid),
        Offset(to.center.dx, mid),
        Offset(to.center.dx, to.top),
      ];
    }

    if (to.bottom <= from.top) {
      final mid = (to.bottom + from.top) / 2;

      return [
        Offset(from.center.dx, from.top),
        Offset(from.center.dx, mid),
        Offset(to.center.dx, mid),
        Offset(to.center.dx, to.bottom),
      ];
    }

    if (to.left >= from.right) {
      final mid = (from.right + to.left) / 2;

      return [
        Offset(from.right, fromFace),
        Offset(mid, fromFace),
        Offset(mid, toFace),
        Offset(to.left, toFace),
      ];
    }

    if (to.right <= from.left) {
      final mid = (to.right + from.left) / 2;

      return [
        Offset(from.left, fromFace),
        Offset(mid, fromFace),
        Offset(mid, toFace),
        Offset(to.right, toFace),
      ];
    }

    // The cards are on top of each other, which is what dropping one on another
    // does and is allowed to do. A straight line between the two BOXES is the
    // only honest thing left to draw, and drawing SOMETHING is the point: the
    // connector surviving the overlap is how the picture says the tree did not
    // change.
    return [
      Offset(from.center.dx, fromFace),
      Offset(to.center.dx, toFace),
    ];
  }

  @override
  bool shouldRepaint(StructureConnectorPainter old) =>
      old.color != color ||
      old.faceHeight != faceHeight ||
      old.links.length != links.length ||
      _differs(old.links, links);

  static bool _differs(List<(Rect, Rect)> a, List<(Rect, Rect)> b) {
    for (var i = 0; i < a.length; i++) {
      if (a[i].$1 != b[i].$1 || a[i].$2 != b[i].$2) return true;
    }

    return false;
  }
}
