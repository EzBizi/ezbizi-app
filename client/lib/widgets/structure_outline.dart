/// The structure as an indented outline — what the free canvas becomes on a
/// phone, and what it becomes for anybody driving with a keyboard (plan §3).
///
/// ── Why the canvas cannot simply shrink ──────────────────────────
///
/// A free canvas is a picture you read by looking around it. That works when a
/// parent and a couple of its children are on screen together; below that the
/// pan gesture becomes the only way to see anything, and a picture you have to
/// navigate in order to read is not a picture of the structure — it is a
/// keyhole. Boxes cannot be made smaller than their names either, so there is
/// no version of the canvas that fits 375px and still says what it is showing.
///
/// So the same tree is drawn as an outline instead. Not a reduced canvas and
/// not a different set of facts: the organization, every company, every sub
/// company and every place, in the order the server sent them, each saying what
/// it is and where it sits. Switching between the two views changes how the
/// structure is DRAWN and never what can be seen in it.
///
/// ── The rule this file exists to keep ────────────────────────────
///
/// Nothing here can move anything. Dragging on the canvas moves the picture,
/// and there is no picture to move here, so there is no gesture on this widget
/// at all — no drag, no drop target, no reordering. `CompaniesController`
/// leaves the parent out of its accepted fields on purpose ("entities are never
/// moved between parents"), and an outline that let somebody drag a row under a
/// different heading would be promising exactly the thing the server refuses.
///
/// ── What it takes, and what it deliberately does not ─────────────
///
/// It takes the tree the way `GET /api/v1/companies` sent it, which is the same
/// value the canvas is built from. One source, read twice, so the two views
/// cannot disagree about a node — a shared flattened list would be a second
/// place for the truth to live and a second place for it to go stale.
///
/// It does NOT take an [ApiClient], and so it draws kind icons rather than the
/// logo badges the canvas boxes carry. That is a decision about width and about
/// requests: at 375px every pixel a 36px badge takes is a pixel off the name
/// beside it, and a badge per row is a fetch per row on the connection least
/// able to afford it. A logo is recognition, not information — the name and the
/// path above it are the information, and they are what this view is for.
library;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'structure_levels.dart';

/// The vocabulary and the four levels, shared with the canvas.
///
/// Re-exported for the same reason the canvas re-exports them: a caller that
/// imported this file to name a `kind` should not have to learn where the word
/// went. What it does NOT mean is a second definition — both exports point at
/// the one file, which is the whole reason it exists.
export 'structure_levels.dart';

// ── the shape of the outline ───────────────────────────────────────

/// Below this width the structure is an outline rather than a canvas.
///
/// Not a guess and not a shared breakpoint: it is the geometry. A canvas earns
/// its keep when a parent and two of its children are on screen together — two
/// 260px boxes plus the 32px gap between them plus a margin either side is 616
/// — and the connector's horizontal run has to be visible as well, or the line
/// explains nothing. Somewhere below 760 the pan becomes the only way to see
/// anything.
///
/// It lives here, beside the thing it selects, so the tab has a named constant
/// to compare against instead of a number typed into a `LayoutBuilder`. Compare
/// it against the constraints a `LayoutBuilder` hands you and never against
/// `MediaQuery`: this screen sits inside a shell whose sidebar is as wide as
/// the user asked for (`me.navigationWidth`, 180-420), so the window's width
/// and the width this widget actually gets are two different numbers.
const double kStructureOutlineBelow = 760;

/// Below this HEIGHT the structure is an outline too, and for the same reason
/// turned ninety degrees.
///
/// Width alone used to decide it, and width alone is wrong on a device that
/// really exists: a phone held sideways is 812 by 375, which sails past
/// [kStructureOutlineBelow] and leaves the canvas a drawing surface 29 points
/// tall. No box fits in that. The result was a blank grey slab with a live
/// "Arrange for me" over it — and pressing that button, which is the obvious
/// thing to do when a screen looks broken, would overwrite the arrangement
/// somebody spent an afternoon on at a desk. The outline is refused the button
/// precisely so a phone cannot do that, and rotating the phone was a way round
/// the protection.
///
/// The number is the same kind of arithmetic as the width: a canvas is worth
/// drawing when a parent and a child are on screen together with the line
/// between them visible — two 72px boxes, the 84px gap they hang apart by and
/// the 32px margin above the first is 260 — plus the toolbar that carries the
/// two controls, which is another 66 with its divider. 340 is that, rounded up
/// so the second row is not flush against the bottom edge.
///
/// Those are the box heights at the DEFAULT text size, and a box grows with the
/// reader's (`structureBoxHeight`), so at 200% two rows want more room than this
/// asks for. Deliberately not scaled: above this threshold the canvas is a
/// canvas — it pans — and a second row a little past the bottom is a picture you
/// scroll rather than a picture you cannot see. What this number exists to catch
/// is the case where not one box fits at all, and that is the same case at every
/// text size.
///
/// It is arithmetic about the TOP-DOWN orientation, which is no longer the only
/// one. Left to right spends its depth on width instead, so a viewport this
/// short holds a whole branch rather than one box — the number is generous
/// there rather than wrong, and one threshold that is right for the worse case
/// beats two that have to be kept in step with which way somebody last flipped
/// the chart.
///
/// The empty middle line it used to have to allow for is gone: a company's
/// places hang off the company now instead of sitting on a fourth line below an
/// empty third one, so a parent and its child are one pitch apart again in both
/// orientations. What this number exists to catch is unchanged either way — the
/// case where NOT ONE BOX FITS — and raising it would push a working device onto
/// the outline for a picture it could simply scroll.
const double kStructureOutlineShorterThan = 340;

/// What the header says when the outline is on screen because it is too NARROW.
///
/// A person who was told the boxes can be dragged and is looking at a list that
/// cannot be dragged is owed the reason, and "Arrange for me" being absent
/// rather than disabled leaves nothing on screen to explain itself.
///
/// ── Why the sidebar is named as well as the window ───────────────
///
/// Because "open it in a wider window" is advice that can be followed and still
/// do nothing. The shell only shows its sidebar at a window width of 900, so
/// dragging a window from 899 to 900 ADDS 180-420 points of sidebar and takes
/// them off this tab — the canvas is there at 899 and gone at 900, and stays
/// gone until the window is wide enough to pay for the sidebar as well. Telling
/// somebody in that band to widen their window is telling them to do the thing
/// that just took the canvas away.
///
/// The sidebar's width is the user's own (`me.navigationWidth`) and it
/// collapses, so it is a remedy they hold. Naming both is what makes this a
/// sentence somebody can act on wherever they are standing.
const String kStructureOutlineNarrowNote =
    'Arranged for you, because this view is too narrow to place the boxes by '
    'hand. A wider window gives it the room — so does narrowing or collapsing '
    'the sidebar, which is the other half of the width this tab gets.';

/// What the header says when the outline is on screen because it is too SHORT.
///
/// Its own sentence rather than a shared one that mentions both, because the
/// sidebar is a remedy for width and nothing else: offering it to somebody on a
/// phone held sideways would be advice that cannot work, which is the failure
/// the note above exists to avoid.
const String kStructureOutlineShortNote =
    'Arranged for you, because this view is too short to place the boxes by '
    'hand. A taller window gives it the room — turning a phone upright is the '
    'same thing.';

/// The rule about who owns what, for the view that has no boxes to drag.
///
/// ── Why the outline needs this and needs nothing else ────────────
///
/// Nothing here can be dragged, so there is no gesture to be misread and no
/// answer to raise at the moment somebody makes one — the canvas's own
/// explanation appears when a box is dropped squarely onto another, and
/// explaining a refusal to somebody who never attempted the thing would be
/// noise.
///
/// But this is the ONLY view on a phone, and the rule is the most important
/// thing about this whole tab: nothing in the business moves between parents,
/// ever. Somebody who only ever sees the outline is owed it in the same words
/// as somebody who sees the canvas, which is why it is a constant beside the
/// other two rather than a sentence typed into a widget.
///
/// Written for a business owner. No "re-parent", no "hierarchy": what it says
/// is that the answer is settled when a thing is created, and that nothing on
/// the list can be put under a different company.
const String kStructureOutlineOwnershipNote =
    'This is the shape of the business. Who owns what is decided when '
    'something is created and stays that way afterwards, so nothing on this '
    'list can be moved under a different company.';

/// One line of the outline.
///
/// Immutable and free of widgets on purpose: everything that decides what a row
/// SAYS is worked out here, where it can be tested without pumping a frame, and
/// the widget below only decides how it looks.
@immutable
class StructureOutlineRow {
  const StructureOutlineRow({
    required this.key,
    required this.kind,
    required this.id,
    required this.name,
    required this.isActive,
    required this.ancestry,
    required this.raw,
  });

  /// Unique within one outline, and stable across a refresh, so the keyboard
  /// cursor stays on the row it was on when the tree is fetched again.
  final String key;

  /// organization · company · franchise
  final String kind;
  final String id;
  final String name;

  final bool isActive;

  /// The companies above it, outermost first — ["Everdoors", "Sunward"].
  ///
  /// ── Why the row carries its ancestry instead of trusting the indent ──
  ///
  /// Because names repeat, and they repeat for a good reason. This
  /// organization operates four places called Connecticut and two called DMV,
  /// each under a different company, and each is a different place with
  /// different data and different settings behind it. `ViewScope::path` was
  /// written for the same reason after "a switcher reading Connecticut and a
  /// settings screen reading Settings for Connecticut were then describing one
  /// of two different places, and nothing on either screen said which".
  ///
  /// An indent alone cannot answer it. The indent says how DEEP a row is, and
  /// the parent that would resolve it has usually scrolled off the top by the
  /// time the fourth Connecticut is on screen — so four identical rows is
  /// exactly what an indent-only outline draws.
  ///
  /// The organization is deliberately absent, following `ViewScope::path`: it
  /// is the root of every path, so including it would add a word to every row
  /// and distinguish nothing.
  final List<String> ancestry;

  /// The server's own map for this node, untouched.
  ///
  /// Carried rather than copied out field by field so a caller's [trailing]
  /// control can read the §4 booleans — `may_edit`, `may_add_franchise`,
  /// `may_add_franchise_reason` — from the server's answer instead of from this
  /// file's approximation of it. The organization tree already has a comment
  /// about what happens when a client decides for itself which permission an
  /// act needs: the wrong people got the control and the right ones did not.
  final Map<String, dynamic> raw;

  /// A company with something above it. There is no separate kind for it (§3).
  bool get isSubCompany => kind == structureCompany && ancestry.isNotEmpty;

  /// How many steps in this row is drawn — 0 organization, 1 company, 2 sub
  /// company, and one step past its owner for a place.
  ///
  /// Derived rather than passed, from the same [structureIndentOf] the canvas
  /// hangs its attached lists by, so the two views cannot come to disagree about
  /// the shape of the business.
  ///
  /// It used to be typed in at four call sites in [outlineRows], and two of
  /// them said 2 — a top-level company's places, and a sub company. That is the
  /// same bug the canvas had, written a different way, and it is precisely why
  /// the answer now comes from one function instead of from whoever was writing
  /// the walk.
  ///
  /// ── Why this is no longer simply the LEVEL ───────────────────
  ///
  /// It was [structureLevelOf] until places stopped being boxes. A place is
  /// level 3 whichever company owns it — that is what a place IS — and it used
  /// to be drawn on the fourth line of the canvas with every other place, so a
  /// top-level company's place was genuinely two steps in from its company with
  /// an empty sub-company step between them.
  ///
  /// The canvas draws a place as a row in a list attached to its company now.
  /// An outline that kept indenting by level would step A-1 Chimney's nine
  /// places in twice and draw a guide rail down the empty step, which reads as a
  /// sub company that failed to load — while the same nine places sit directly
  /// under the same company on the canvas. One business, two pictures, and they
  /// would be saying different things about where nine places hang.
  int get indent => structureIndentOf(
    kind: kind,
    isSub: isSubCompany,
    // The path this row already carries to tell four places called Connecticut
    // apart is also the count of companies above it, so nothing new is worked
    // out to answer this.
    ancestors: ancestry.length,
  );

  /// What this row is, and which one of it — "Territory in Everdoors › Sunward".
  ///
  /// [franchiseLabel] is the organization's own word for a place (§8). It is
  /// passed in rather than written here because "Franchise" is the one noun
  /// this product lets a customer replace, and it is used verbatim rather than
  /// lower-cased: it is their word, and it may well be an acronym or a proper
  /// noun that a `toLowerCase()` would quietly damage.
  String caption(String franchiseLabel) {
    final where = ancestry.isEmpty ? '' : ' in ${ancestry.join(' › ')}';

    return switch (kind) {
      structureOrganization => 'The whole business',
      structureFranchise => '$franchiseLabel$where',
      _ => ancestry.isEmpty ? 'Company' : 'Sub company$where',
    };
  }

  /// The whole row as one sentence, for a reader who cannot see the indent.
  ///
  /// "Connecticut — Territory in Everdoors › Sunward, switched off".
  ///
  /// Built from the same [caption] the row shows, so what is on screen and what
  /// is read out are the same fact said once. Two hand-written strings is how a
  /// screen reader ends up describing a structure the eye is not looking at.
  String sentence(String franchiseLabel) {
    final said = '$name — ${caption(franchiseLabel)}';

    // Switched off is carried by grey and a line through the name, and neither
    // of those reaches a screen reader — the same gap the franchise chips on
    // the List tab were fixed for.
    return isActive ? said : '$said, switched off';
  }
}

/// The tree, flattened into the rows an outline draws.
///
/// ── Read defensively at every level ──────────────────────────────
///
/// This is JSON off the wire, and a screen that threw because one entry was a
/// string instead of a map would be a screen nobody could open and nobody could
/// fix from inside the app. So a list that is not a list yields nothing, an
/// entry that is not a map is skipped, and the rest of the tree still draws.
///
/// A node whose name is missing is drawn as "(unnamed)" rather than dropped.
/// Dropping it is the tempting shortcut and it is the wrong one: a company that
/// does not appear takes its sub companies and all of its places with it, so a
/// broken label would silently shrink the business on the one screen whose job
/// is to show all of it.
///
/// The order is the server's. `CompaniesController::index` already sorts
/// companies and franchises by name, and a second sort here would be a second
/// opinion about order that the canvas does not share.
List<StructureOutlineRow> outlineRows(Map<String, dynamic> tree) {
  final rows = <StructureOutlineRow>[];
  var position = 0;

  StructureOutlineRow row(
    Map<String, dynamic> raw, {
    required String kind,
    required List<String> ancestry,
  }) {
    final name = raw['name'];
    final id = raw['id'];

    return StructureOutlineRow(
      // The id is what keeps the keyboard cursor on the same row across a
      // refresh. A payload broken enough to be missing one still has to draw,
      // so the position stands in — it is unique within this build, which is
      // all a focus key has to be.
      key: '$kind:${id is String && id.isNotEmpty ? id : '#${position++}'}',
      kind: kind,
      id: id is String ? id : '',
      name: name is String && name.isNotEmpty ? name : '(unnamed)',
      // Absent means on. Only a company or a place that says `false` is
      // switched off; the organization has no such flag and is never drawn
      // as off.
      isActive: raw['is_active'] != false,
      ancestry: ancestry,
      raw: raw,
    );
  }

  // No depth argument, and that absence is the fix. This closure used to be
  // called with 2 for a top-level company's places and 3 for a sub company's,
  // which is the walk deciding what a place IS from how far it had got — and it
  // put a company's own places level with a sub company. A place is a place;
  // [StructureOutlineRow.depth] answers from the kind now.
  void addFranchises(
    Map<String, dynamic> company, {
    required List<String> ancestry,
  }) {
    for (final franchise in _mapsIn(company['franchises'])) {
      rows.add(
        row(franchise, kind: structureFranchise, ancestry: ancestry),
      );
    }
  }

  final organization = tree['organization'];

  if (organization is Map) {
    rows.add(
      row(
        organization.cast<String, dynamic>(),
        kind: structureOrganization,
        ancestry: const [],
      ),
    );
  }

  for (final company in _mapsIn(tree['companies'])) {
    final companyRow = row(
      company,
      kind: structureCompany,
      ancestry: const [],
    );

    rows.add(companyRow);

    // A company's own places first, then its sub companies with theirs. Same
    // order the List tab draws — the card holds its place chips and the sub
    // companies hang underneath it — so somebody switching views is reading the
    // same sequence rather than relearning it.
    //
    // ── A place and a sub company now share an indent ────────────
    //
    // They did not this morning: a company's places were indented one step
    // FURTHER than its sub companies, because a place was drawn on the canvas's
    // fourth line whichever company owned it, and the outline agreed with the
    // canvas about that. It was the fix for a real bug — the two used to be
    // typed in at the same depth, which said a place and a sub company are the
    // same size of thing.
    //
    // What changed is what "the same line" means. A place is not a box any more:
    // it is a row in a list attached to the company above it, and Everdoors' own
    // territories hang off Everdoors on the canvas exactly the way Premier's
    // hang off Premier. An outline that kept the old indent would put them two
    // steps from a company they sit directly under, with a guide rail down an
    // empty step that reads as a sub company which failed to load.
    //
    // So the indent says PARENTAGE here, which is what an indent says in every
    // other outline anybody has read, and the thing it used to say — what rank a
    // row holds — is carried by the two channels that already carried it and are
    // not positional: the glyph, and the caption naming the row in words
    // ("Territory in Everdoors" against "Sub company in Everdoors"). A screen
    // reader was always getting its answer from the caption rather than from the
    // indent, which is the honest test of whether the indent was the one saying
    // it.
    addFranchises(company, ancestry: [companyRow.name]);

    for (final sub in _mapsIn(company['sub_companies'])) {
      final subRow = row(
        sub,
        kind: structureCompany,
        // What makes this row a SUB company, and the only thing that does: it
        // is a `company` with something above it, which is the same question
        // the server answers with `parent_company_id`. Its level is read back
        // off this, so an empty ancestry here would draw it as a top-level
        // company rather than merely mis-caption it.
        ancestry: [companyRow.name],
      );

      rows.add(subRow);

      // §3 fixes the depth: a sub company cannot hold a sub company, so this
      // does not recurse. A general tree walk here would be code encoding a
      // shape the model forbids, with nothing to call it.
      addFranchises(sub, ancestry: [companyRow.name, subRow.name]);
    }
  }

  return rows;
}

List<Map<String, dynamic>> _mapsIn(Object? value) {
  if (value is! List) return const [];

  return [
    for (final entry in value)
      if (entry is Map) entry.cast<String, dynamic>(),
  ];
}

// ── the widget ─────────────────────────────────────────────────────

/// How far one level of nesting steps in, and where its guide line is drawn.
///
/// Small on purpose. Four levels at a comfortable desktop indent would spend
/// 100px of a 375px screen on emptiness, and the name is what that width is
/// for. The guide line down the middle of each step is what keeps the level
/// legible at 18px — the indent alone stops being obvious once the rows above
/// have scrolled away.
const double _step = 18;

class StructureOutline extends StatefulWidget {
  const StructureOutline({
    super.key,
    required this.tree,
    required this.franchiseLabel,
    this.trailing,
    this.header,
    this.padding = const EdgeInsets.fromLTRB(16, 8, 16, 24),
  });

  /// The tree exactly as `GET /companies` sent it.
  final Map<String, dynamic> tree;

  /// The organization's own word for a place (§8) — `me.organization.franchiseLabel`.
  final String franchiseLabel;

  /// The control at the end of a row, if the caller has one to offer.
  ///
  /// A builder rather than a widget so the caller can answer per row — the same
  /// menu the List tab and the canvas box carry, refused or absent according to
  /// the §4 booleans on [StructureOutlineRow.raw]. Returning null draws nothing
  /// at all, which is the right answer for a row whose every act is refused: a
  /// disabled control invites somebody to work out why it does nothing.
  final Widget? Function(BuildContext, StructureOutlineRow)? trailing;

  /// What goes above the first row, INSIDE the scroll rather than above it.
  ///
  /// ── Why a header slot rather than a Column around this widget ────
  ///
  /// Because the caller's notes and this list have to share one viewport, and
  /// on the device this view exists for there is barely one to share. A phone
  /// held sideways is 812 by 375, and by the time the page header and the tab
  /// strip have had their share this widget's column is about 108 points tall.
  /// Two paragraphs of explanation is more than that on their own — stacked
  /// ABOVE a scroll view they overflow it, which in a release build is content
  /// silently clipped, and the content being clipped is the structure.
  ///
  /// Inside the scroll, the same two paragraphs cost nothing they do not use:
  /// they are read first, scrolled past, and the whole list is reachable behind
  /// them. That is also the honest reading of what they are — context for the
  /// list rather than chrome around it.
  ///
  /// Drawn OUTSIDE [padding] so a caller's full-width strip stays full width,
  /// which is why the padding below moved from the scroll view onto the rows.
  /// A note inset by sixteen points on each side is a note that looks like a
  /// row and reads like one.
  final Widget? header;

  final EdgeInsets padding;

  @override
  State<StructureOutline> createState() => _StructureOutlineState();
}

class _StructureOutlineState extends State<StructureOutline> {
  late List<StructureOutlineRow> _rows;

  /// One focus node per row, kept by key so a refetch does not shuffle them.
  final Map<String, FocusNode> _nodes = {};

  /// ── Why one tab stop and not one per row ─────────────────────────
  ///
  /// A structure of any size is dozens of rows, and none of them DOES anything
  /// — they are text. Putting every one of them in the tab order would mean
  /// somebody reaching the control after this outline by pressing Tab sixty
  /// times, which is the standard complaint about lists that were made
  /// "keyboard accessible" by making everything focusable.
  ///
  /// So the outline is a single stop, like a tree control anywhere else: Tab
  /// enters it at the row the cursor is on, the arrow keys walk the rows, Home
  /// and End jump to the ends, and Tab leaves. Every other row is marked
  /// `skipTraversal`, and its contents with it, so the count of stops does not
  /// depend on how many companies a customer has.
  String? _cursor;

  @override
  void initState() {
    super.initState();
    _readTree();
  }

  @override
  void didUpdateWidget(StructureOutline oldWidget) {
    super.didUpdateWidget(oldWidget);

    // Identity, not equality: the tree is replaced wholesale by every refetch,
    // and a deep comparison of a structure this size on every rebuild would
    // cost more than the walk it is trying to avoid.
    if (!identical(oldWidget.tree, widget.tree)) {
      _readTree();
      _forgetRowsThatAreGone();
    }
  }

  @override
  void dispose() {
    for (final node in _nodes.values) {
      node.dispose();
    }

    super.dispose();
  }

  void _readTree() {
    _rows = outlineRows(widget.tree);

    // The cursor's row can be renamed away, switched off, or deleted by
    // somebody else between two loads. Falling back to the top is the only
    // answer that always exists.
    if (_cursor == null || !_rows.any((row) => row.key == _cursor)) {
      _cursor = _rows.isEmpty ? null : _rows.first.key;
    }
  }

  /// Dispose the nodes of rows the last refresh removed — after the frame.
  ///
  /// Not here and now: the widgets holding those nodes are still mounted at
  /// this point, and disposing a node a live [Focus] is still using trips an
  /// assertion. By the time the callback runs, the rebuild has taken them off
  /// the tree.
  void _forgetRowsThatAreGone() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;

      final live = {for (final row in _rows) row.key};

      for (final key in _nodes.keys.toList()) {
        if (live.contains(key)) continue;

        _nodes.remove(key)!.dispose();
      }
    });
  }

  FocusNode _nodeFor(StructureOutlineRow row) =>
      _nodes.putIfAbsent(row.key, () => FocusNode(debugLabel: row.key));

  KeyEventResult _onKey(int index, KeyEvent event) {
    // Held arrows repeat, which is how somebody gets to the bottom of a long
    // structure without sixty separate presses.
    if (event is! KeyDownEvent && event is! KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }

    final target = switch (event.logicalKey) {
      LogicalKeyboardKey.arrowDown => index + 1,
      LogicalKeyboardKey.arrowUp => index - 1,
      LogicalKeyboardKey.home => 0,
      LogicalKeyboardKey.end => _rows.length - 1,
      _ => null,
    };

    if (target == null) return KeyEventResult.ignored;

    final within = target.clamp(0, _rows.length - 1);

    // Swallowed even when it does not move. Letting the key through at the last
    // row hands it to the scroll view, which would scroll the focused row off
    // screen and leave the arrow keys doing two different things depending on
    // where you are.
    if (within != index) _moveTo(within);

    return KeyEventResult.handled;
  }

  void _moveTo(int index) {
    final row = _rows[index];
    final node = _nodeFor(row);

    setState(() => _cursor = row.key);
    node.requestFocus();

    final context = node.context;

    // Focus does not scroll anything by itself — the traversal policy is what
    // normally does it, and this move is not a traversal. Without this the
    // cursor walks straight off the bottom of the viewport and a keyboard user
    // is reading a structure they cannot see.
    if (context != null) {
      Scrollable.ensureVisible(
        context,
        alignment: 0.5,
        duration: const Duration(milliseconds: 120),
        curve: Curves.easeOut,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows.isEmpty) {
      // The header comes too, and it is not decoration here. It is what the
      // caller uses to say why this view is on screen at all, and a structure
      // that failed to load is exactly when somebody most needs the sentence
      // that explains what they are looking at.
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ?widget.header,
          _Note(
            'The structure has not loaded. Try again from the top of the page.',
            padding: widget.padding,
          ),
        ],
      );
    }

    return Scrollbar(
      child: SingleChildScrollView(
        // ── Why every row is built, and not a ListView ──────────────
        //
        // A `ListView` builds what is on screen and a little either side.
        // A row that has not been built is not in the focus tree, so the
        // arrow keys would stop at the bottom edge of the viewport and the
        // rest of the structure would be unreachable without a mouse — the
        // exact thing this view exists to prevent.
        //
        // The cost is bounded by the business's own shape: §3 fixes the depth
        // at four levels, and every row here is an icon and two lines of text.
        // A tenant big enough for that to matter is a tenant whose structure
        // this outline should probably be summarising rather than listing, and
        // that is a real conversation to have when somebody has one — not a
        // reason to break the keyboard for everybody who does not.
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Outside the padding, so a full-width strip stays full width.
            ?widget.header,
            Padding(
              padding: widget.padding,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: _tiles(context),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Every row, built. Lifted out of [build] only so the header and the padded
  /// rows could become two children of one Column without the list itself
  /// moving two levels of indentation to the right.
  List<Widget> _tiles(BuildContext context) => [
    for (var i = 0; i < _rows.length; i++)
      _OutlineTile(
        row: _rows[i],
        franchiseLabel: widget.franchiseLabel,
        node: _nodeFor(_rows[i]),
        isCursor: _rows[i].key == _cursor,
        onKey: (event) => _onKey(i, event),
        onFocused: () {
          // Tab or a click landed here rather than an arrow key. The cursor
          // follows, so the next arrow press continues from where the person
          // actually is.
          if (_cursor != _rows[i].key) {
            setState(() => _cursor = _rows[i].key);
          }
        },
        trailing: widget.trailing?.call(context, _rows[i]),
      ),
    if (!_rows.any((row) => row.kind == structureCompany))
      const _Note(
        'No companies yet. The List view is where one is added.',
        padding: EdgeInsets.only(top: 12, left: 4),
      ),
  ];
}

class _Note extends StatelessWidget {
  const _Note(this.text, {required this.padding});

  final String text;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
    );
  }
}

class _OutlineTile extends StatelessWidget {
  const _OutlineTile({
    required this.row,
    required this.franchiseLabel,
    required this.node,
    required this.isCursor,
    required this.onKey,
    required this.onFocused,
    required this.trailing,
  });

  final StructureOutlineRow row;
  final String franchiseLabel;
  final FocusNode node;
  final bool isCursor;
  final KeyEventResult Function(KeyEvent) onKey;
  final VoidCallback onFocused;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final text = Theme.of(context).textTheme;

    return Focus(
      focusNode: node,
      // The row's semantics are assembled below as one sentence. Left on, Focus
      // would add a second node carrying the focused flag and no words, and the
      // reader would land on the one that says nothing.
      includeSemantics: false,
      skipTraversal: !isCursor,
      descendantsAreTraversable: isCursor,
      onKeyEvent: (_, event) => onKey(event),
      onFocusChange: (has) {
        if (has) onFocused();
      },
      child: Builder(
        builder: (context) {
          final focused = Focus.of(context).hasFocus;

          return Semantics(
            container: true,
            focusable: true,
            focused: focused,
            label: row.sentence(franchiseLabel),
            child: CustomPaint(
              painter: _IndentRailPainter(
                indent: row.indent,
                // Theme-derived, never a hex. An organization defines its own
                // theme (§12), and a guide line pinned to one palette's grey is
                // a line that disappears in somebody else's.
                colour: scheme.outlineVariant,
              ),
              child: Padding(
                padding: EdgeInsets.only(left: row.indent * _step),
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      // Transparent is not a colour here, it is the absence of
                      // one: the border is always present so a row does not
                      // change size — and shove the rows below it — at the
                      // moment focus arrives.
                      color: focused ? scheme.primary : Colors.transparent,
                      width: 2,
                    ),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(6, 8, 4, 8),
                    child: Row(
                      children: [
                        Expanded(
                          // Excluded rather than merged. The sentence above
                          // already says all of this in the order a reader
                          // wants it; left in, the name, the caption and the
                          // words "switched off" would be read a second time
                          // as three loose fragments.
                          child: ExcludeSemantics(
                            child: _rowText(scheme, text),
                          ),
                        ),
                        if (trailing != null)
                          // Bounded, because the row's promise not to overflow
                          // at 375px cannot be at the mercy of what a caller
                          // hands it. One icon-sized control is what fits, and
                          // that is what the menus on the other two views are.
                          ConstrainedBox(
                            constraints: const BoxConstraints(
                              maxWidth: kMinInteractiveDimension,
                            ),
                            child: trailing,
                          ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _rowText(ColorScheme scheme, TextTheme text) {
    final muted = scheme.onSurfaceVariant;

    return Row(
      children: [
        Icon(
          // A sub company gets the company's own glyph. It IS a company (§3),
          // and its own icon would say there are four kinds of thing here when
          // there are three. What makes it a sub company is the indent and the
          // caption, which are also the two things a reader can hear.
          switch (row.kind) {
            structureOrganization => Icons.hub_outlined,
            structureFranchise => Icons.storefront_outlined,
            _ => Icons.apartment_outlined,
          },
          size: 18,
          color: muted,
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  // Both halves flex, so a long name and a long word for
                  // "switched off" shorten each other instead of overflowing
                  // when somebody turns their text size up. Three to two rather
                  // than evenly: the name is the row, and the state beside it is
                  // two short words at the smallest size on screen.
                  Flexible(
                    flex: 3,
                    child: Text(
                      row.name,
                      // Two lines and then an ellipsis. A name is what the row
                      // is CALLED, and the whole of it is in the sentence a
                      // screen reader gets, so cutting a pathological one is a
                      // cosmetic loss. The caption below is the opposite case
                      // and is treated as such.
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: text.titleMedium?.copyWith(
                        fontSize: 15,
                        color: row.isActive ? null : muted,
                        decoration: row.isActive
                            ? null
                            : TextDecoration.lineThrough,
                      ),
                    ),
                  ),
                  if (!row.isActive) ...[
                    const SizedBox(width: 8),
                    // In words as well as in grey and a strike-through, so it
                    // survives a reader who cannot see either.
                    Flexible(
                      flex: 2,
                      child: Text(
                        'switched off',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: text.bodySmall?.copyWith(color: scheme.error),
                      ),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 2),
              Text(
                // The line that tells four places called Connecticut apart —
                // and the one thing on this row that is never cut short.
                //
                // ── Why it has no line limit ────────────────────────
                //
                // Because the tail of the path is the half that answers the
                // question, and a limit takes that half first. At 375px, one
                // line turns "Sunward Overhead Doors › Sunward Commercial" and
                // "Sunward Overhead Doors" into the same string —
                // "Territory in Sunward Overhead…" — which is precisely the
                // four-identical-rows failure this caption exists to prevent.
                // Two lines only moves the width at which that happens, and a
                // limit picked here would be a limit picked against ONE font
                // at ONE text size, neither of which this widget chooses.
                //
                // So it wraps as far as it needs. A long path costs a taller
                // row, which is a cost paid by the rows that need it, on the
                // one screen whose whole job is saying which one this is.
                row.caption(franchiseLabel),
                style: text.bodySmall?.copyWith(color: muted),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// The vertical guide down each level of nesting.
///
/// Painted rather than laid out as a column of thin boxes: a painter takes the
/// size the row already worked out for itself, so the guides run the full
/// height of a row however tall the text inside it is. Boxes would need either
/// a fixed row height — which breaks the moment somebody turns their text size
/// up — or an `IntrinsicHeight` around every row to discover it.
///
/// One guide per step this row is indented, drawn so each guide starts under the
/// row it belongs to and stops on its own when that block ends.
class _IndentRailPainter extends CustomPainter {
  const _IndentRailPainter({required this.indent, required this.colour});

  final int indent;
  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    if (indent == 0) return;

    final brush = Paint()
      ..color = colour
      ..strokeWidth = 1;

    // One guide per step, and every step now has something on it.
    //
    // This used to draw a rail down an EMPTY step: a company with no sub
    // companies still had a sub-company level, and its places sat three steps in
    // with the second rail marking a level nothing in that branch used. It was
    // right at the time — the canvas said the same thing by leaving a line
    // blank, and the rail was marking levels rather than rows.
    //
    // Neither view has that empty step any more. A place is drawn one step past
    // the company that owns it, here and on the canvas, so a rail with no row
    // beside it would now be the outline claiming a sub company that is not
    // there.
    for (var step = 0; step < indent; step++) {
      final x = step * _step + _step / 2;

      canvas.drawLine(Offset(x, 0), Offset(x, size.height), brush);
    }
  }

  @override
  bool shouldRepaint(_IndentRailPainter old) =>
      old.indent != indent || old.colour != colour;
}
