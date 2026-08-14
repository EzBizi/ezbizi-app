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

/// The three kinds of thing in the tree, spelled once.
///
/// A sub company is a `company` — a sub company IS a company row, and the model
/// says so with `parent_company_id` rather than with a type. A fourth kind here
/// would be this file inventing a distinction the business does not make, and
/// it would be the first thing to disagree with the canvas, which stores the
/// same three words in the saved arrangement.
const String structureOrganizationKind = 'organization';
const String structureCompanyKind = 'company';
const String structureFranchiseKind = 'franchise';

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
    required this.depth,
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

  /// 0 organization, 1 company, 2 a company's places and its sub companies,
  /// 3 a sub company's places. §3 fixes the depth, so this is bounded at 3.
  final int depth;
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
  bool get isSubCompany => kind == structureCompanyKind && ancestry.isNotEmpty;

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
      structureOrganizationKind => 'The whole business',
      structureFranchiseKind => '$franchiseLabel$where',
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
    required int depth,
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
      depth: depth,
      // Absent means on. Only a company or a place that says `false` is
      // switched off; the organization has no such flag and is never drawn
      // as off.
      isActive: raw['is_active'] != false,
      ancestry: ancestry,
      raw: raw,
    );
  }

  void addFranchises(
    Map<String, dynamic> company, {
    required int depth,
    required List<String> ancestry,
  }) {
    for (final franchise in _mapsIn(company['franchises'])) {
      rows.add(
        row(
          franchise,
          kind: structureFranchiseKind,
          depth: depth,
          ancestry: ancestry,
        ),
      );
    }
  }

  final organization = tree['organization'];

  if (organization is Map) {
    rows.add(
      row(
        organization.cast<String, dynamic>(),
        kind: structureOrganizationKind,
        depth: 0,
        ancestry: const [],
      ),
    );
  }

  for (final company in _mapsIn(tree['companies'])) {
    final companyRow = row(
      company,
      kind: structureCompanyKind,
      depth: 1,
      ancestry: const [],
    );

    rows.add(companyRow);

    // A company's own places first, then its sub companies with theirs. Same
    // order the List tab draws — the card holds its place chips and the sub
    // companies hang underneath it — so somebody switching views is reading the
    // same sequence rather than relearning it.
    addFranchises(company, depth: 2, ancestry: [companyRow.name]);

    for (final sub in _mapsIn(company['sub_companies'])) {
      final subRow = row(
        sub,
        kind: structureCompanyKind,
        depth: 2,
        ancestry: [companyRow.name],
      );

      rows.add(subRow);

      // §3 fixes the depth: a sub company cannot hold a sub company, so this
      // does not recurse. A general tree walk here would be code encoding a
      // shape the model forbids, with nothing to call it.
      addFranchises(sub, depth: 3, ancestry: [companyRow.name, subRow.name]);
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
      return _Note(
        'The structure has not loaded. Try again from the top of the page.',
        padding: widget.padding,
      );
    }

    return Scrollbar(
      child: SingleChildScrollView(
        padding: widget.padding,
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
            for (var i = 0; i < _rows.length; i++)
              _OutlineTile(
                row: _rows[i],
                franchiseLabel: widget.franchiseLabel,
                node: _nodeFor(_rows[i]),
                isCursor: _rows[i].key == _cursor,
                onKey: (event) => _onKey(i, event),
                onFocused: () {
                  // Tab or a click landed here rather than an arrow key. The
                  // cursor follows, so the next arrow press continues from
                  // where the person actually is.
                  if (_cursor != _rows[i].key) {
                    setState(() => _cursor = _rows[i].key);
                  }
                },
                trailing: widget.trailing?.call(context, _rows[i]),
              ),
            if (!_rows.any((row) => row.kind == structureCompanyKind))
              const _Note(
                'No companies yet. The List view is where one is added.',
                padding: EdgeInsets.only(top: 12, left: 4),
              ),
          ],
        ),
      ),
    );
  }
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
                depth: row.depth,
                // Theme-derived, never a hex. An organization defines its own
                // theme (§12), and a guide line pinned to one palette's grey is
                // a line that disappears in somebody else's.
                colour: scheme.outlineVariant,
              ),
              child: Padding(
                padding: EdgeInsets.only(left: row.depth * _step),
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
            structureOrganizationKind => Icons.hub_outlined,
            structureFranchiseKind => Icons.storefront_outlined,
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
/// One guide per ancestor level, drawn on every row deeper than that level, so
/// each guide starts under its parent and stops on its own when the block ends.
class _IndentRailPainter extends CustomPainter {
  const _IndentRailPainter({required this.depth, required this.colour});

  final int depth;
  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    if (depth == 0) return;

    final brush = Paint()
      ..color = colour
      ..strokeWidth = 1;

    for (var level = 0; level < depth; level++) {
      final x = level * _step + _step / 2;

      canvas.drawLine(Offset(x, 0), Offset(x, size.height), brush);
    }
  }

  @override
  bool shouldRepaint(_IndentRailPainter old) =>
      old.depth != depth || old.colour != colour;
}
