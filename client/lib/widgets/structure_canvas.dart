/// The organization's structure as boxes on a free canvas, arranged by hand.
///
/// Organization → Company → Sub Company → Franchise (§3), drawn rather than
/// listed, with the boxes placed wherever the person looking at them wants them.
/// The list on the other tab is the view that CHANGES the business; this one is
/// the view that shows its shape, and the two draw the same nodes from the same
/// payload so they cannot disagree about what exists.
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
///      read as "it went inside".
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
library;

import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter/services.dart';

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

/// The three kinds a box can be, and they are the SERVER's three.
///
/// A sub company is `company` — a sub company IS a company row, and the client
/// inventing a fourth kind would be describing a distinction the model does not
/// make. Which of the two it is is [StructureNode.isSub], derived from whether
/// its parent is a company, which is the same question the server answers with
/// `parent_company_id`.
const String structureOrganization = 'organization';
const String structureCompany = 'company';
const String structureFranchise = 'franchise';

/// The widget key a box is drawn with.
///
/// Exposed rather than spelled out at each call site because the screen's tests
/// and this file's tests have to name the same box, and a key format copied
/// into two test files is two things that have to be kept in step.
Key structureBoxKey(String key) => ValueKey('structure-box:$key');

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

/// One box: what it is, what it is called, and what it hangs off.
class StructureNode {
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
  final String kind;
  final String id;
  final String name;

  /// The key of the box this one hangs off — `company:<uuid>` — or null for the
  /// organization, which hangs off nothing.
  final String? parentKey;

  /// Carried so the box can say "under Everdoors" out loud. The connector says
  /// the same thing to somebody who can see it; this says it to somebody who
  /// cannot, and both are drawn from the tree rather than from the positions.
  final String? parentName;

  final bool isActive;

  /// A company whose parent is a company. Not a fourth [kind] — see the note on
  /// the kind constants.
  final bool isSub;

  /// The server's own map for this node, untouched.
  ///
  /// Carried whole rather than having its flags copied out, so a caller that
  /// draws an action menu reads `may_edit`, `may_add_franchise` and their
  /// reasons from the payload at the point of use. §4 has one home, and
  /// `organization_screen.dart` records what happened the last time a client
  /// approximated one of those answers for itself.
  final Map<String, dynamic> raw;

  /// How the layout names this box. The kind travels with the id because it is
  /// what makes a stored arrangement readable by a person who opens the column,
  /// and what lets a stale company id be told from a stale franchise id.
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

/// A place for every box, laid out as an org chart.
///
/// ── What "sensible" means here, precisely ────────────────────────
///
/// A row is where a node actually HANGS, not a fixed table of four. The
/// organization is row 0 and top-level companies row 1; a company's own
/// franchises AND its sub companies share row 2, because a franchise hangs off
/// whichever of company-or-sub-company owns it; a sub company's franchises are
/// row 3. A fixed four-row grid would put every franchise on one line and leave
/// a top-level company's places floating a row away from the company they
/// belong to.
///
/// Horizontally it is one tidy pass: a subtree is as wide as its children laid
/// side by side, or one box, whichever is more; a parent is centred over the
/// span its children occupy; siblings run left to right in tree order.
///
/// Deliberately NOT a general tree-layout algorithm. §3 fixes the depth at four,
/// so a general one would be code with no second caller, encoding a depth the
/// model forbids.
///
/// **Deterministic.** The same tree in gives an identical map out, every time.
/// That is what makes "Arrange for me" pressed twice a no-op instead of a
/// slowly drifting picture, and it is what lets a test assert exact numbers
/// rather than "roughly tidy".
///
/// [boxHeight] is taken rather than read off [kStructureBoxHeight] because a box
/// is as tall as the reader's text size makes it (see [structureBoxHeight]), and
/// rows laid out against a different height than the boxes are drawn at is the
/// automatic layout stacking them on top of each other for anybody who has
/// turned their font up. It defaults to the height at the default text size, so
/// a caller with no opinion — every test that only cares about the shape — gets
/// the same answer it always did.
Map<String, Offset> arrangeStructure(
  List<StructureNode> nodes, {
  double boxHeight = kStructureBoxHeight,
}) {
  if (nodes.isEmpty) return const {};

  final byKey = {for (final node in nodes) node.key: node};
  final children = <String, List<StructureNode>>{};
  final roots = <StructureNode>[];

  for (final node in nodes) {
    final parent = node.parentKey;

    // A node whose parent is not in the list is laid out as a root rather than
    // dropped. It should not happen — the payload is nested — but a box nobody
    // can find is worse than a box in an odd place.
    if (parent == null || !byKey.containsKey(parent)) {
      roots.add(node);
    } else {
      (children[parent] ??= <StructureNode>[]).add(node);
    }
  }

  final widths = <String, double>{};

  double measure(StructureNode node) {
    final kids = children[node.key] ?? const <StructureNode>[];

    if (kids.isEmpty) return widths[node.key] = kStructureBoxWidth;

    var total = -kStructureGapX;

    for (final kid in kids) {
      total += measure(kid) + kStructureGapX;
    }

    return widths[node.key] = math.max(kStructureBoxWidth, total);
  }

  final places = <String, Offset>{};

  void place(StructureNode node, double left, int row) {
    final span = widths[node.key]!;
    final kids = children[node.key] ?? const <StructureNode>[];

    places[node.key] = Offset(
      left + (span - kStructureBoxWidth) / 2,
      row * (boxHeight + kStructureGapY),
    );

    if (kids.isEmpty) return;

    var run = -kStructureGapX;

    for (final kid in kids) {
      run += widths[kid.key]! + kStructureGapX;
    }

    // Centred under the parent when the children take up less room than it
    // does, which happens whenever a company holds a single place.
    var x = left + (span - run) / 2;

    for (final kid in kids) {
      place(kid, x, row + 1);
      x += widths[kid.key]! + kStructureGapX;
    }
  }

  var cursor = 0.0;

  for (final root in roots) {
    measure(root);
    place(root, cursor, 0);
    cursor += widths[root.key]! + kStructureGapX;
  }

  // Shifted so the whole picture starts at the margin. The origin is the top
  // left of the viewport and nothing scrolls above or left of it, so a box at a
  // negative coordinate is a box nobody can reach.
  var minX = double.infinity;
  var minY = double.infinity;

  for (final at in places.values) {
    minX = math.min(minX, at.dx);
    minY = math.min(minY, at.dy);
  }

  final shift = Offset(kStructureMargin - minX, kStructureMargin - minY);

  return {
    for (final entry in places.entries)
      entry.key: Offset(
        math.max(0, entry.value.dx + shift.dx),
        math.max(0, entry.value.dy + shift.dy),
      ),
  };
}

/// The canvas.
class StructureCanvas extends StatefulWidget {
  const StructureCanvas({
    super.key,
    required this.tree,
    required this.franchiseLabel,
    this.layout = const {},
    this.onMoved,
    this.onArranged,
    this.leading,
    this.actions,
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
  final Map<String, Offset> layout;

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

  /// What goes in the box after its name — the action menu, typically. Kept a
  /// builder because §4's answers live on the server's own payload, which the
  /// caller reads off [StructureNode.raw] at the point of use.
  final Widget? Function(BuildContext context, StructureNode node)? actions;

  /// The sentence above the canvas. Null when the screen around it already says
  /// this in its own header, so the two cannot end up saying it twice — or,
  /// worse, saying two different things.
  final String? note;

  /// Said in words as well as in behaviour, because the behaviour is an absence
  /// and an absence is hard to notice. The same sentence the list tab uses, so
  /// the two views cannot drift apart on the one rule that matters most.
  static const String defaultNote =
      'Drag the boxes to arrange them. This changes the picture only — nothing '
      'here can be moved to a different parent once it exists.';

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

  /// Whether the first layout has happened, so the viewport is aimed once.
  bool _placed = false;

  /// Where the viewport is looking, which is NOT part of the arrangement and is
  /// never reported anywhere. It is where you happened to be looking, not how
  /// you arranged things, and persisting it would open the tab somewhere nobody
  /// chose and put a second drifting thing in the stored blob.
  Offset _view = Offset.zero;

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

  Offset _viewAnchor = Offset.zero;
  Offset _viewPointerAnchor = Offset.zero;

  /// The box currently being moved with the arrow keys, if any.
  String? _nudging;

  /// Which box has focus, so its border can say so. Kept as a key rather than
  /// read from the nodes because focus survives the tree being refetched.
  String? _focused;

  final Map<String, FocusNode> _focusNodes = {};

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
    _nodes = flattenStructure(widget.tree);
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

    final height = structureBoxHeight(MediaQuery.textScalerOf(context));

    if (_placed && height == _boxHeight) return;

    _boxHeight = height;
    _auto = arrangeStructure(_nodes, boxHeight: height);

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
    _auto = arrangeStructure(_nodes, boxHeight: _boxHeight);
  }

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
  Offset _homeView() {
    var minX = double.infinity;
    var minY = double.infinity;

    for (final node in _nodes) {
      final at = _positionOf(node.key);
      minX = math.min(minX, at.dx);
      minY = math.min(minY, at.dy);
    }

    if (!minX.isFinite || !minY.isFinite) return Offset.zero;

    return Offset(kStructureMargin - minX, kStructureMargin - minY);
  }

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

    final moved = _boxAnchor + (details.globalPosition - _pointerAnchor);

    setState(() {
      _dragTo = Offset(math.max(0, moved.dx), math.max(0, moved.dy));
    });

    _report(key, _dragTo);
  }

  void _panEnd() {
    // Reported on every update rather than only here, because the caller is the
    // one that debounces. A canvas that only spoke on release would make a
    // dropped pointer — a browser tab losing focus mid-drag — a move that never
    // happened at all.
    setState(() => _dragKey = null);
  }

  // ── moving a box without a mouse ─────────────────────────────

  /// 16 a press, 64 with Shift held. Not a pixel: a canvas nudged one pixel at
  /// a time is a canvas a keyboard user gives up on, and both figures are
  /// factors of the gap between rows so a nudged box still lines up with one
  /// that was arranged automatically.
  static const double _nudgeStep = 16;
  static const double _nudgeStride = 64;

  void _startNudge(StructureNode node) {
    setState(() => _nudging = node.key);
    _focusNode(node.key).requestFocus();

    _say(
      'Moving ${node.name}. Arrow keys move it. Press Escape when you are done.',
    );
  }

  void _endNudge(StructureNode node) {
    if (_nudging != node.key) return;

    setState(() => _nudging = null);
    _say('${node.name} placed.');
  }

  /// ── Why nothing here ever says a number ──────────────────────
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
    final placed = arrangeStructure(_nodes, boxHeight: _boxHeight);

    // Read BEFORE anything is told about the new arrangement, because this is
    // where every box currently IS — including one that is part-way through an
    // arrangement somebody pressed a moment ago, which is why it is read through
    // [_positionOf] rather than off the layout.
    _leaving = {for (final node in _nodes) node.key: _positionOf(node.key)};

    setState(() {
      _nudging = null;
      // Arranged and then looked at. Leaving the viewport where it was would
      // tidy the whole picture somewhere off screen, which reads as a button
      // that did nothing.
      _view = Offset(kStructureMargin, kStructureMargin) -
          (placed.values.isEmpty ? Offset.zero : _topLeftOf(placed));
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
  void _panZoomStart(PointerPanZoomStartEvent event) {
    _viewAnchor = _view;
  }

  void _panZoomUpdate(PointerPanZoomUpdateEvent event) {
    setState(() => _view = _viewAnchor + event.pan);
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _toolbar(theme),
        const Divider(height: 1),
        Expanded(
          child: LayoutBuilder(
            builder: (context, constraints) => SizedBox(
              // A canvas needs a bounded height to be a canvas at all. A caller
              // that puts one inside a scroll view gets a workable slab rather
              // than an assertion, because a screen that throws on layout is a
              // screen nobody can get back out of.
              height: constraints.maxHeight.isFinite
                  ? constraints.maxHeight
                  : 520,
              child: _surface(theme),
            ),
          ),
        ),
      ],
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
          Wrap(spacing: 8, runSpacing: 8, children: controls),
          if (note != null) ...[
            const SizedBox(height: 10),
            Text(note, style: theme.textTheme.bodyMedium),
          ],
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
    final rects = <String, Rect>{
      for (final node in _nodes)
        node.key: (_positionOf(node.key) + _view) &
            Size(kStructureBoxWidth, _boxHeight),
    };

    final links = <(Rect, Rect)>[
      for (final node in _nodes)
        if (node.parentKey != null && rects.containsKey(node.parentKey))
          (rects[node.parentKey]!, rects[node.key]!),
    ];

    return ClipRect(
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
          onPointerSignal: (signal) {
            if (signal is! PointerScrollEvent) return;

            setState(() => _view -= signal.scrollDelta);
          },
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
                    onScaleStart: (details) => setState(() {
                      _viewAnchor = _view;
                      _viewPointerAnchor = details.focalPoint;
                    }),
                    onScaleUpdate: (details) => setState(() {
                      _view = _viewAnchor +
                          (details.focalPoint - _viewPointerAnchor);
                    }),
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
                for (final node in _nodes) _box(theme, node, rects[node.key]!),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _box(ThemeData theme, StructureNode node, Rect at) {
    final movable = widget.onMoved != null;

    return Positioned(
      key: ValueKey('slot:${node.key}'),
      left: at.left,
      top: at.top,
      width: kStructureBoxWidth,
      height: _boxHeight,
      // Plain, not AnimatedPositioned. [at] already carries the one easing this
      // widget does, worked out in [_positionOf] where the connector painter can
      // read the same numbers — see the note on [_arranging].
      child: Semantics(
        container: true,
        label: _sentenceFor(node),
        child: Focus(
          key: structureBoxKey(node.key),
          focusNode: _focusNode(node.key),
          onKeyEvent: (_, event) => _onKey(node, event),
          onFocusChange: (has) {
            // Focus is handed back as a widget is being taken down, which is
            // after this State can be asked to rebuild.
            if (!mounted) return;

            setState(() {
              if (has) {
                _focused = node.key;
              } else {
                if (_focused == node.key) _focused = null;
                // The mode is per box and ends on blur, which is what keeps
                // Tab working: leaving a box always hands the arrow keys back
                // to whatever wants them next.
                if (_nudging == node.key) _nudging = null;
              }
            });
          },
          child: MouseRegion(
            // `move`, not `grab`. See the note at the top of the file: one of
            // these says "carry this somewhere", and it is not this one.
            cursor: movable
                ? SystemMouseCursors.move
                : SystemMouseCursors.basic,
            // The box is opaque to hit testing, so a two-finger trackpad gesture
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
                onPanEnd: movable ? (_) => _panEnd() : null,
                child: _face(theme, node),
              ),
            ),
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
  /// reader, none of which a fill or a border does. The glyph and the border
  /// weight are there so the levels can be told apart at a glance without
  /// reading, and the corner radius separates the two ends — a place is round
  /// like the chip it is drawn as on the list tab, the whole business is the
  /// squarest and heaviest thing on the canvas.
  ///
  /// A fifth channel of shape for company-versus-sub-company was considered and
  /// dropped: four distinct box shapes in a 260-point slot is a visual language
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

  double _radiusFor(StructureNode node) => switch (node.kind) {
    structureOrganization => 16,
    structureFranchise => 26,
    _ => 12,
  };

  double _borderFor(StructureNode node) => switch (node.kind) {
    structureOrganization => 2,
    structureFranchise => 1,
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
  const StructureConnectorPainter({required this.links, required this.color});

  /// Parent box and child box, both already in viewport coordinates.
  final List<(Rect, Rect)> links;

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
      final route = _route(parent, child);
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

  /// An orthogonal run out of one box and into the other.
  ///
  /// Not a bezier. With free positions a curve routes straight through whatever
  /// boxes happen to lie between, whereas an out-across-in run stays readable
  /// in every arrangement — including upside down, which free positioning
  /// allows and which an org chart drawn by hand will eventually contain.
  ///
  /// Which edges it leaves from is decided by where the boxes actually ARE, not
  /// by which is the parent, so a child dragged above or beside its parent gets
  /// a line that still reads as a connection rather than one that doubles back
  /// through both boxes.
  static List<Offset> _route(Rect from, Rect to) {
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
        Offset(from.right, from.center.dy),
        Offset(mid, from.center.dy),
        Offset(mid, to.center.dy),
        Offset(to.left, to.center.dy),
      ];
    }

    if (to.right <= from.left) {
      final mid = (to.right + from.left) / 2;

      return [
        Offset(from.left, from.center.dy),
        Offset(mid, from.center.dy),
        Offset(mid, to.center.dy),
        Offset(to.right, to.center.dy),
      ];
    }

    // The boxes are on top of each other, which is what dropping one on another
    // does and is allowed to do. A straight line between the centres is the
    // only honest thing left to draw, and drawing SOMETHING is the point: the
    // connector surviving the overlap is how the picture says the tree did not
    // change.
    return [from.center, to.center];
  }

  @override
  bool shouldRepaint(StructureConnectorPainter old) =>
      old.color != color ||
      old.links.length != links.length ||
      _differs(old.links, links);

  static bool _differs(List<(Rect, Rect)> a, List<(Rect, Rect)> b) {
    for (var i = 0; i < a.length; i++) {
      if (a[i].$1 != b[i].$1 || a[i].$2 != b[i].$2) return true;
    }

    return false;
  }
}
