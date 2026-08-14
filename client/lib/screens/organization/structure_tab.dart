import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/structure_canvas.dart';
import '../../widgets/structure_outline.dart';
import 'structure_layout.dart';

/// The structure as a picture, and the one thing on this screen that remembers
/// where you put something.
///
/// Two widgets draw the structure and this decides which one, holds the
/// arrangement, and is the only place that writes it. The canvas and the outline
/// are both handed the tree the List tab already fetched — there is no second
/// read endpoint for the structure and there must not be one, because a second
/// one is a second answer to "what exists" and the day they disagreed the two
/// tabs would be describing different businesses.
///
/// ── Dragging moves the picture, not the business ─────────────────
///
/// `CompaniesController` omits the parent from every set of fields it accepts:
/// "entities are never moved between parents. Every setting, assignment and
/// roll-up below a company is expressed in terms of where it sits, so moving one
/// silently rewrites history." There is no endpoint that re-parents anything.
///
/// This tab is where that rule could be broken by accident, because it is the
/// only part of the feature that can send a request. It sends exactly one kind:
/// a list of `{kind, id, x, y}` to the canvas endpoint. It never PATCHes a
/// company or a franchise, it has no drop handler to wire one into, and the
/// canvas it drives has no `DragTarget` for a drop handler to hang off. The
/// widget's own docblock lists the six mechanisms that keep the PICTURE from
/// implying otherwise; this file's contribution is simply that the only wire it
/// owns cannot carry a parent.
///
/// ── Automations: this emits nothing, and here is why ─────────────
///
/// The whole argument is written out on `OrganizationCanvasController`, which is
/// where the write actually happens; it is summarised here because a feature
/// with no trigger is otherwise indistinguishable from one where somebody
/// forgot. In short: an arrangement is a §2.1 category 6 user preference, so a
/// `canvas_arranged` trigger would have to live under a subject nothing can
/// write to, `AutomationEngine::scopeOf` would answer `[null, null]` for it, and
/// one person nudging a box in their own browser would become tenant-wide queued
/// work once per debounce. When an event has no honest home, it is not an event.
///
/// The positive half matters more: every fact this view draws already raises its
/// own event. `company.created`, `company.updated`, `company.deactivated` and
/// the three franchise equivalents all fire from the controllers the List tab
/// writes through. So the drift this tab's "Arrange for me" exists for — new
/// companies appearing since somebody last tidied the picture — is already
/// something a tenant can build a rule on, against triggers this feature did not
/// need to add.
///
/// The absence worth tripping over: there is no `company.reparented` trigger,
/// because no code path can move one. That absence is §3 expressed in the event
/// catalogue, which is the same rule this canvas expresses in pixels. A future
/// task proposing such a trigger is the signal that the drag has started moving
/// the business.
class StructureTab extends StatefulWidget {
  const StructureTab({
    super.key,
    required this.api,
    required this.session,
    required this.tree,
    this.actions,
  });

  final ApiClient api;
  final Session session;

  /// The payload from `GET /companies`, exactly as the List tab received it.
  ///
  /// Its IDENTITY is load-bearing: it is replaced only when a fetch lands, so
  /// this tab and the canvas can both tell "the tree changed" from "the screen
  /// rebuilt" with a cheap `identical` rather than by walking it every frame —
  /// and a drag rebuilds this tree on every pointer move.
  final Map<String, dynamic> tree;

  /// The action menu for one node, given what it is and the server's own map.
  ///
  /// Deliberately not typed against either drawing widget's node class. The
  /// canvas and the outline each model a row their own way, and the screen above
  /// has no business knowing which one is on screen — what an action menu
  /// actually needs is the kind and the payload the §4 booleans live on, and
  /// those are the two things both models agree about.
  final Widget? Function(
    BuildContext context,
    String kind,
    Map<String, dynamic> node,
  )?
  actions;

  @override
  State<StructureTab> createState() => _StructureTabState();
}

class _StructureTabState extends State<StructureTab> {
  /// How long after the last change the arrangement is written.
  ///
  /// One PUT per drag-end is fine for one drag and wrong for tidying: five boxes
  /// nudged in three seconds is five writes to a JSON column that is read on
  /// every request returning `/me`. 700ms is long enough that a run of small
  /// corrections collapses into one write and short enough that leaving the tab
  /// straight after a drag lands inside [dispose]'s flush rather than needing it.
  static const Duration _settle = Duration(milliseconds: 700);

  /// Where every box goes — INCLUDING keys that name nothing on screen.
  ///
  /// This is the most important line in the file. The server keeps a position
  /// whose company it cannot resolve, on purpose: a company can be missing for
  /// the afternoon while somebody's role is edited or a module is switched off
  /// and back on, and forgetting where it sat because it happened to be absent
  /// when a different box was nudged would lose an arrangement nobody changed.
  ///
  /// Holding the whole blob here is what honours that rule from this side.
  /// Because [canvasBoxes] serialises this map rather than the boxes that were
  /// drawn, a save made while something is invisible still carries its position
  /// back. A client that serialised only what it drew would defeat the server's
  /// rule completely and silently, and the loss would be blamed on the server.
  late Map<String, Offset> _positions;

  /// The last arrangement the server confirmed, to put back if a save fails.
  ///
  /// There is nothing to reload from — no GET for this, by design; it rides in
  /// on `/me` — so a refused save has to be undone from memory or not at all.
  late Map<String, Offset> _lastSaved;

  /// Boxes moved while a save was on the wire, and therefore absent from the
  /// answer it is about to come back with.
  ///
  /// This is the difference between applying the server's answer and applying
  /// it OVER somebody. The response echoes what was stored, which is what was
  /// sent — so assigning it wholesale puts every box moved during the flight
  /// back where it stood before the request left, and the debounced save that
  /// follows then writes that revert into the column. On any connection slow
  /// enough for a second drag to finish before the first answer lands, a move
  /// would be undone by the save of the move before it: the box visibly jumps
  /// back a moment after being let go, and nothing on screen says why. The
  /// same window swallows an arrow-key nudge and a whole "Arrange for me".
  ///
  /// So the echo is reconciled per box rather than assigned. What the server
  /// corrected — a clamped coordinate, a deduplicated entry — still lands on
  /// every box nobody has touched since, and a box somebody HAS touched keeps
  /// the place they just gave it. Cleared when a save starts, because from that
  /// moment the request on the wire is what these are measured against.
  ///
  /// The monotonic [_saving] counter cannot do this job: it tells a superseded
  /// SAVE from the current one, and a move is not a save. Nothing between two
  /// saves ever bumps it.
  Map<String, Offset> _movedSinceSent = const {};

  /// The boxes the tree currently contains, cached rather than re-flattened.
  ///
  /// Only used to count what a saved arrangement has never heard of. Recomputing
  /// it per frame would mean walking the whole tree on every pointer move of a
  /// drag, to answer a question that can only change when the tree does.
  List<StructureNode> _nodes = const [];

  Timer? _debounce;

  /// Which save owns the arrangement. A late failure from a superseded save must
  /// not roll back a newer one — the same monotonic counter the screens use for
  /// their loads, and for the same reason.
  int _saving = 0;

  /// Set in [dispose], because `mounted` going false is a side effect of timing
  /// rather than a statement of intent. The flush below deliberately outlives
  /// this State, and every branch that would touch it checks this first.
  bool _gone = false;

  @override
  void initState() {
    super.initState();

    // Off the session, which already carries this user's preferences: `/me`
    // sends them so the app can paint the right theme on the first frame, and an
    // arrangement is the same kind of fact. No second request, and nothing here
    // waits on one before the boxes can be drawn where they belong.
    _positions = savedCanvasLayout(widget.session.me!.preferences);
    _lastSaved = _positions;
    _nodes = flattenStructure(widget.tree);
  }

  @override
  void didUpdateWidget(covariant StructureTab old) {
    super.didUpdateWidget(old);

    if (!identical(old.tree, widget.tree)) {
      _nodes = flattenStructure(widget.tree);
    }
  }

  @override
  void dispose() {
    _gone = true;

    // A drag two hundred milliseconds before somebody clicks Settings must not
    // be the one that is lost. The timer is cancelled and its work done now
    // instead: fired, not awaited, with the answer deliberately discarded —
    // there is no screen left to tell, and ApiClient never throws, so nothing
    // can be left hanging by ignoring it.
    final pending = _debounce?.isActive ?? false;

    _debounce?.cancel();
    _debounce = null;

    if (pending) unawaited(_save());

    super.dispose();
  }

  // ── what a person did ────────────────────────────────────────

  /// One box moved. Applied first, saved second.
  ///
  /// A box that stays put for as long as the network takes reads as a control
  /// that did not work, and invites a second drag. The dashboard's cards learned
  /// this the same way.
  void _moved(String key, Offset to) {
    setState(() {
      _positions = {..._positions, key: to};
      _movedSinceSent = {..._movedSinceSent, key: to};
    });
    _schedule();
  }

  /// "Arrange for me" — every box the canvas can see, in its automatic place.
  ///
  /// MERGED into what is held, never assigned over it. The canvas can only
  /// report the boxes it drew, so replacing the map would drop exactly the
  /// entries the server is keeping on purpose — the same rule as [_positions],
  /// arriving from the other direction.
  void _arranged(Map<String, Offset> placed) {
    setState(() {
      _positions = {..._positions, ...placed};
      _movedSinceSent = {..._movedSinceSent, ...placed};
    });
    _schedule();
  }

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(_settle, _save);
  }

  Future<void> _save() async {
    _debounce?.cancel();
    _debounce = null;

    final attempt = ++_saving;
    final undo = _lastSaved;
    final sent = _positions;

    // From here, anything moved is measured against what is on the wire.
    _movedSinceSent = const {};

    final response = await widget.api.put('/organization/canvas', {
      'boxes': canvasBoxes(sent),
    });

    // A save this one has already superseded says nothing about anything: its
    // answer is about an arrangement that is no longer the arrangement, and
    // acting on it would roll a newer one back.
    if (attempt != _saving) return;

    if (!response.ok) {
      // Put back, and say the server's own sentence. Boxes leaping back to
      // where they were is jarring and it is still the right answer: an
      // arrangement that looks saved and is not is the version of this that
      // costs somebody the same afternoon twice. §13 makes a lapsed tenant
      // read-only, and this is deliberately not special-cased — the 402 carries
      // a sentence about the subscription and it belongs on screen verbatim,
      // like every other refusal on this page.
      //
      // Back to what the server confirmed — but not over the top of a box
      // somebody has moved since this request left, which was never part of the
      // arrangement that was refused.
      if (_gone || !mounted) return;

      setState(() => _positions = {...undo, ..._movedSinceSent});
      _snack(_firstError(response) ?? response.message);
      return;
    }

    final echoed = response.body['boxes'];

    // What was STORED, not what was sent, and read back through the same
    // defensive reader the preference blob goes through — so a clamped
    // coordinate or a deduplicated entry shows where the box will actually be
    // rather than being corrected behind the screen's back.
    //
    // Guarded, because an answer without `boxes` is an answer this client does
    // not understand, and treating it as an empty arrangement would wipe the
    // one that was just saved successfully.
    if (echoed is! List) return;

    // ── Told to the session BEFORE this State is asked about ─────
    //
    // The session is holding its own copy of the preferences, and leaving this
    // tab and coming back reads that copy — so without this the boxes would go
    // back to where they were before the drag, on a save that succeeded.
    //
    // It comes before the `mounted` check rather than after it, and that order
    // is the whole point. The flush in [dispose] fires PRECISELY because this
    // screen is going away, so by the time its answer lands `_gone` is true and
    // `mounted` is false — and behind that guard the one update that matters
    // most was the one being dropped. Nothing else in the app refetches `/me` on
    // navigation, so the session's copy stayed empty, the tab drew every box at
    // its automatic place next visit, and the next save — built from that empty
    // copy — replaced the arrangement the server had actually stored. A drag
    // survived the trip to the server and was destroyed by the drag after it.
    //
    // Safe outside the State's own lifetime because it touches no State: the
    // session outlives this screen by design, and telling a ChangeNotifier with
    // no listeners left is a no-op rather than an error.
    widget.session.rememberPreference(organizationCanvasPreference, echoed);

    if (_gone || !mounted) return;

    final stored = savedCanvasLayout({organizationCanvasPreference: echoed});

    setState(() {
      // Reconciled, not replaced — see [_movedSinceSent].
      _positions = {...stored, ..._movedSinceSent};
      _lastSaved = stored;
    });
  }

  String? _firstError(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;

    if (errors == null || errors.isEmpty) return null;

    final first = errors.values.first;

    return first is List && first.isNotEmpty ? first.first as String : null;
  }

  void _snack(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: Theme.of(context).colorScheme.error,
        behavior: SnackBarBehavior.floating,
        width: 420,
      ),
    );
  }

  // ── drawing it ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    // LayoutBuilder, never MediaQuery. This screen sits inside a shell whose
    // sidebar is as wide as the user asked for (`me.navigationWidth`, 180-420),
    // so the window's width and the width this tab actually gets are two
    // different numbers, and the second one is the one a canvas has to fit in.
    //
    // BOTH dimensions, and the height is not an afterthought. Width alone left a
    // phone held sideways — 812 by 375 — on the canvas, where the drawing
    // surface came out shorter than a single box: a blank grey slab with a live
    // "Arrange for me" over it, and pressing that on a device that can draw
    // nothing is how somebody flattens the arrangement they made at a desk. The
    // outline exists for exactly that device and there was no way to reach it.
    return LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth < kStructureOutlineBelow) {
          return _outline(kStructureOutlineNarrowNote);
        }

        if (constraints.maxHeight < kStructureOutlineShorterThan) {
          return _outline(kStructureOutlineShortNote);
        }

        return _canvas();
      },
    );
  }

  Widget _canvas() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_unplaced > 0) _StatusNote(_driftNote),
        Expanded(
          child: StructureCanvas(
            tree: widget.tree,
            // §8: the organization's own word for a place, the same one the
            // outline is handed below. "Franchise" is the one noun this product
            // lets a customer replace, and a box that said "Place" while the
            // outline three hundred pixels narrower said "Territory" was one
            // tab disagreeing with itself about what its own boxes are.
            franchiseLabel: widget.session.me!.organization.franchiseLabel,
            layout: _positions,
            onMoved: _moved,
            onArranged: _arranged,
            actions: (context, node) =>
                widget.actions?.call(context, node.kind, node.raw),
            // The header above the tab strip already carries the sentence about
            // dragging changing the picture only. Said twice it is noise, and
            // the day somebody edits one of the two copies it is a screen
            // contradicting itself about the rule that matters most here.
            note: null,
            // No logo badge in the box, deliberately. A LogoBadge is an EDITING
            // control — it opens a file picker — and putting one inside the
            // thing you drag makes the tap-versus-pan arbitration decide
            // whether somebody moved a box or started uploading an image. The
            // canvas falls back to a glyph per level, which is one of the four
            // ways it tells the levels apart, and a logo is changed on the list
            // where nothing is being dragged.
          ),
        ),
      ],
    );
  }

  Widget _outline(String note) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Says what happened instead of leaving somebody who was told the boxes
        // can be dragged looking at a list that cannot be. "Arrange for me" is
        // ABSENT here rather than disabled, so without this sentence there would
        // be nothing on screen to explain itself.
        //
        // Which sentence depends on which dimension ran out, because the
        // remedies are different: the sidebar is width and nothing else.
        _StatusNote(note),
        Expanded(
          child: StructureOutline(
            tree: widget.tree,
            // §8: the organization's own word for a place. "Franchise" is the
            // one noun this product lets a customer replace, and a view that
            // used the default would be this screen calling it something the
            // rest of the app does not.
            franchiseLabel: widget.session.me!.organization.franchiseLabel,
            trailing: (context, row) =>
                widget.actions?.call(context, row.kind, row.raw),
          ),
        ),
        // Nothing below writes. A visit that lands on the outline — too narrow
        // or too short — reads the arrangement and never sends one: no
        // automatic layout is run and no save is scheduled, so
        // somebody opening this on a phone cannot flatten the arrangement they
        // spent an afternoon on at a desk. That is a real bug rather than a
        // hypothetical one: the tempting shape here is "lay it out and save it
        // so it is ready", and that shape overwrites a desktop arrangement from
        // a device that could never have produced one.
      ],
    );
  }

  /// Boxes on screen that the saved arrangement says nothing about.
  ///
  /// Counted against [_lastSaved] rather than [_positions], because [_positions]
  /// includes moves that have not been written yet — and those ARE where
  /// somebody put them, which is the opposite of what is being counted.
  ///
  /// Zero when nothing has ever been saved. Then every box is at its automatic
  /// place, which is a coherent picture rather than a mixed one, and there is
  /// nothing to explain.
  int get _unplaced {
    if (_lastSaved.isEmpty) return 0;

    return _nodes.where((node) => !_lastSaved.containsKey(node.key)).length;
  }

  /// The honest state, in words, beside the button that fixes it.
  ///
  /// ── Why this no longer says "new since you last arranged" ────
  ///
  /// Because it was false, and falsest in the most ordinary case. A box is
  /// missing from the saved arrangement for two reasons this tab cannot tell
  /// apart: it was created after somebody last tidied the picture, or it was
  /// simply never dragged. Dragging ONE box writes an arrangement of one box —
  /// the client sends what it holds and the server stores what it is sent — so
  /// the second reason is what happens the first time anybody touches this
  /// screen. On the fixture in this tab's own tests that produced "5 boxes are
  /// new since you last arranged this" said to somebody who had moved one box
  /// and changed nothing about the business, on every visit, until they pressed
  /// the escape hatch — which meant the only way to silence a false sentence was
  /// to let the automatic layout overwrite the placement they had just made.
  ///
  /// Telling the two apart honestly would need a record of whether the
  /// arrangement was ever COMPLETE, and there is nowhere truthful to keep one.
  /// The stored blob is a list of boxes and it survives a reload; a flag held
  /// only in memory would make the same sentence true before a refresh and false
  /// after it, which is a worse lie than the one being fixed.
  ///
  /// So it says the thing that is true either way and is the thing that
  /// actually matters on screen: these boxes are where the SHAPE put them rather
  /// than where you did, which is why one may be sitting on top of another, and
  /// there is one button that tidies the lot.
  ///
  /// A parking row for unplaced boxes was the alternative and it is worse: it is
  /// a second layout nobody designed, sitting in a corner, and it still has to
  /// drift somewhere.
  String get _driftNote {
    final count = _unplaced;

    return count == 1
        ? 'One box has not been placed by you, so it is drawn where it belongs '
              'in the shape rather than where you would have put it. '
              '“Arrange for me” tidies the whole picture.'
        : '$count boxes have not been placed by you, so they are drawn where '
              'they belong in the shape rather than where you would have put '
              'them. “Arrange for me” tidies the whole picture.';
  }
}

/// A sentence about the state of this tab, above the thing it is about.
///
/// Not a snackbar: these are conditions rather than events, and both of them are
/// true for as long as they are true. A message that slides away after four
/// seconds cannot say "this is a narrow screen".
class _StatusNote extends StatelessWidget {
  const _StatusNote(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
      color: theme.colorScheme.surfaceContainerHighest,
      child: Text(text, style: theme.textTheme.bodySmall),
    );
  }
}
