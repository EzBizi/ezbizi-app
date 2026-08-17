import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/structure_canvas.dart';
import '../../widgets/structure_outline.dart';
import 'structure_layout.dart';

/// The structure as a picture, and the one thing on this screen that remembers
/// where the boxes were put.
///
/// Two widgets draw the structure and this decides which one, holds the
/// arrangement, and is the only place that writes it. The canvas and the outline
/// are both handed the tree the List tab already fetched — there is no second
/// read endpoint for the structure and there must not be one, because a second
/// one is a second answer to "what exists" and the day they disagreed the two
/// tabs would be describing different businesses.
///
/// ── One arrangement, for everybody ───────────────────────────────
///
/// It used to be a per-user preference on `/me`. The owner's answer to "should
/// this be shared" was "should be shared obviously", so it is one
/// organization-scoped setting now: everybody in the business opens the same
/// picture. It arrives on `tree['canvas']` beside the structure it describes
/// and goes back through `PUT /organization/canvas`, and nothing caches it —
/// `GET /companies` is refetched every time this screen opens, which is what
/// makes a colleague's change arrive rather than wait for a sign-out.
///
/// Two consequences this file has to answer for, and both are below rather than
/// left to be discovered:
///
///   * **Saving is a permission now.** It changes what every colleague sees, so
///     it is gated on `organizations.edit` plus an assignment at the whole
///     organization — asked by the server, published on the tree as
///     [mayArrange] and [arrangeRefusal]. Somebody without it sees the same
///     picture, pans it, re-centres it and reads every box; what they do not
///     get is the two controls that would produce a save, and one sentence in
///     the server's own words saying why.
///   * **Two people can move the same box.** There is one row, so a save
///     replaces the picture rather than merging into it. What that is not
///     allowed to be is silent — see [_arrivedFromServer].
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
/// forgot.
///
/// The old summary said an arrangement was a per-user preference with no honest
/// subject to hang a trigger off. That reason died with the per-user store and
/// is deliberately not repeated: this writes an organization-scoped setting now,
/// and the subject would be a real Organization.
///
/// The reason it still adds nothing is simpler — a trigger that covers it
/// already exists. `organization.settings_changed` says "somebody saved a
/// different value for a setting, at whichever level they set it", and an
/// arrangement IS a setting saved at organization level, so that sentence is
/// already true of this. A second, narrower trigger beside it would be dead in
/// exactly the same way, because settings are saved without going through the
/// outbox. Whether that changes is a decision about every setting rather than
/// about a canvas.
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
/// catalogue, which is the same rule this canvas expresses in pixels — and now
/// also in the paragraph it raises when somebody drops one box on another. A
/// future task proposing such a trigger is the signal that the drag has started
/// moving the business.
class StructureTab extends StatefulWidget {
  const StructureTab({
    super.key,
    required this.api,
    required this.session,
    required this.tree,
    required this.mayArrange,
    required this.arrangeRefusal,
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

  /// Whether this person may save an arrangement everybody else will see.
  ///
  /// The server's answer, off `tree['organization']['may_arrange']`, and NOT
  /// something worked out here from the permission set on `/me`. The act needs
  /// `organizations.edit` AND an assignment at the whole organization, and a
  /// client that asked only the first half would hand a branch manager a live
  /// canvas and a 403 — which is the §4 mistake `may_add_franchise` on the same
  /// payload already exists to record.
  final bool mayArrange;

  /// Why not, in the server's own words, or null when they may.
  ///
  /// The SAME STRING `PUT /organization/canvas` would have aborted with,
  /// computed once on the server. A sentence written on this side is how a dead
  /// control and the refusal behind it come to explain the same rule two
  /// different ways, which is how somebody comes to believe they are two
  /// different problems.
  final String? arrangeRefusal;

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
  /// nudged in three seconds is five writes to a JSON column every colleague
  /// reads, and — now that the write is audited — five rows in the audit log for
  /// one person straightening a chart. 700ms is long enough that a run of small
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

  /// The arrangement the server is believed to be holding.
  ///
  /// Two jobs, and they are the same fact read two ways. It is what a refused
  /// save is put back to — the tree could be refetched instead, but that is a
  /// whole request to undo one drag, and the screen above would rebuild
  /// underneath somebody mid-gesture. And it is what an incoming arrangement is
  /// COMPARED against, which is the only way this tab can tell its own picture
  /// coming back to it from a colleague's picture arriving (see
  /// [_arrivedFromServer]).
  late Map<String, Offset> _lastSaved;

  /// The arrangement currently on the wire, or null when nothing is in flight.
  ///
  /// Only [_arrivedFromServer] reads it, and only to keep quiet: a refetch that
  /// lands between a save leaving and its answer coming back carries THIS
  /// arrangement, which is neither new nor somebody else's. Without it, saving
  /// and then glancing at the List tab would announce that a colleague had
  /// rearranged the structure, naming this person's own drag.
  Map<String, Offset>? _sent;

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

  /// Set when a fetch brought back an arrangement this tab did not write, and
  /// cleared when somebody has read the sentence about it.
  ///
  /// A `bool` rather than a count or a name. The server does not say who saved
  /// it and this tab must not guess — "Ana rearranged this" would be a claim
  /// with nothing behind it, and the person it would most often name wrongly is
  /// whoever is reading it.
  bool _rearrangedByAnother = false;

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

    // Off the tree, which is the payload this whole screen is drawn from. The
    // arrangement describes that tree, so it travels with it — one fetch, no
    // second request, and nothing here waits on one before the boxes can be
    // drawn where they belong.
    //
    // Deliberately NOT off the session. `/me` is answered once at launch and
    // cached, so an arrangement read from there would be whatever was true when
    // this person signed in — which was right when it was their own and is
    // wrong the moment it is everybody's.
    _positions = canvasLayoutFrom(widget.tree['canvas']);
    _lastSaved = _positions;
    _nodes = flattenStructure(widget.tree);
  }

  @override
  void didUpdateWidget(covariant StructureTab old) {
    super.didUpdateWidget(old);

    if (!identical(old.tree, widget.tree)) {
      _nodes = flattenStructure(widget.tree);
      _arrivedFromServer(canvasLayoutFrom(widget.tree['canvas']));
    }
  }

  /// A fetch has landed, carrying whatever arrangement the server now holds.
  ///
  /// ── Two people can move the same box, and one row holds the answer ──
  ///
  /// There is one arrangement for the organization, so a save replaces the
  /// picture rather than merging into it: the last person to press save is the
  /// one whose placement is kept. That is the honest consequence of "should be
  /// shared obviously", and there is no version stamp on the endpoint to make
  /// it anything else.
  ///
  /// What it must not be is INVISIBLE. Two failures were available here and
  /// both are refused:
  ///
  ///   * *Silently losing.* Ignoring what a refetch brought back and carrying
  ///     on drawing the arrangement in memory. Then a colleague's tidy-up is on
  ///     the server and on their screen and not on this one, and the next drag
  ///     from here writes the old picture back over it. Neither person is told,
  ///     and the one who tidied watches it undo itself.
  ///   * *Silently winning.* Taking the server's arrangement wholesale, which
  ///     yanks a box out from under somebody who moved it two seconds ago and
  ///     has not saved yet.
  ///
  /// So: everything nobody here has touched since takes the server's places,
  /// which is how the office converges on one picture. Boxes moved here and not
  /// yet written keep where they were put, because they are the only thing on
  /// screen this person is in the middle of. And it SAYS SO, once, in a
  /// sentence they can dismiss — [_sharedNote] states the rule as well as the
  /// event, because "somebody else moved these" is only half an explanation
  /// without "and only one arrangement is kept".
  ///
  /// The comparison is against what the server was believed to hold and against
  /// what is on the wire, not against what is on screen. Unsaved local moves
  /// make the drawn picture differ from the stored one constantly, and
  /// comparing against those would announce a colleague every time anybody
  /// dragged anything.
  void _arrivedFromServer(Map<String, Offset> theirs) {
    // Ours, come back to us — either the arrangement we already knew about or
    // the one currently in flight. Nothing happened.
    if (_sameArrangement(theirs, _lastSaved)) return;

    final sent = _sent;

    if (sent != null && _sameArrangement(theirs, sent)) {
      _lastSaved = theirs;

      return;
    }

    setState(() {
      // Theirs underneath, this person's unwritten moves on top — the same
      // reconciliation the response to a save does, and for the same reason.
      _positions = {...theirs, ..._movedSinceSent};
      _lastSaved = theirs;
      _rearrangedByAnother = true;
    });
  }

  /// Whether two arrangements say the same thing about the same boxes.
  ///
  /// Written out rather than reached for through a collection helper, because
  /// what is being compared is the ANSWER rather than the object: a round trip
  /// through the endpoint rounds every coordinate to a whole pixel and the
  /// reader on this side rebuilds it as a double, so two maps that describe one
  /// picture are equal here by construction. Anything unequal is a different
  /// picture, which is the only question this is asked.
  static bool _sameArrangement(Map<String, Offset> a, Map<String, Offset> b) {
    if (a.length != b.length) return false;

    for (final entry in a.entries) {
      if (b[entry.key] != entry.value) return false;
    }

    return true;
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
    _sent = sent;

    final response = await widget.api.put('/organization/canvas', {
      'boxes': canvasBoxes(sent),
    });

    // A save this one has already superseded says nothing about anything: its
    // answer is about an arrangement that is no longer the arrangement, and
    // acting on it would roll a newer one back. [_sent] is deliberately left
    // alone here: a newer save has already replaced it with its own, and
    // clearing it would be this one tidying up after the one that overtook it.
    if (attempt != _saving) return;

    _sent = null;

    if (!response.ok) {
      // Put back, and say the server's own sentence. Boxes leaping back to
      // where they were is jarring and it is still the right answer: an
      // arrangement that looks saved and is not is the version of this that
      // costs somebody the same afternoon twice. §13 makes a lapsed tenant
      // read-only, and this is deliberately not special-cased — the 402 carries
      // a sentence about the subscription and it belongs on screen verbatim,
      // like every other refusal on this page. The 403 this endpoint can now
      // answer with is the same shape: a role edited while somebody had the tab
      // open, and the server's own sentence is the one that explains it.
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
    // defensive reader the tree's own copy goes through — so a clamped
    // coordinate or a deduplicated entry shows where the box will actually be
    // rather than being corrected behind the screen's back.
    //
    // Guarded, because an answer without `boxes` is an answer this client does
    // not understand, and treating it as an empty arrangement would wipe the
    // one that was just saved successfully.
    if (echoed is! List) return;

    // ── Nothing is told to the session, and that is the change ───
    //
    // There used to be a `rememberPreference` here, and it had to be here: the
    // arrangement lived on `/me`, the session cached `/me`, and nothing in this
    // app refetches it on navigation — so a successful save that was not
    // written into that cache was undone on screen the next time somebody
    // opened the tab, and then written back out of the stale copy by the drag
    // after it.
    //
    // None of that is true of a shared setting. The arrangement rides on
    // `GET /companies`, which the screen above refetches every time it opens,
    // so the server is the only copy there is. Caching it here would put a
    // second one behind a screen whose whole point is that a colleague's change
    // arrives — and the stale one would win, because it is the one that gets
    // drawn first.
    if (_gone || !mounted) return;

    final stored = canvasLayoutFrom(echoed);

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
        // Not a disabled button and not a hidden tab. The picture is the same
        // picture everybody else is looking at, and it still pans, scrolls,
        // re-centres and reads out — none of that is written anywhere and none
        // of it needs a permission. What is refused is the SAVE, so the two
        // controls that would produce one are simply absent (the canvas draws
        // neither when it is handed no callback), and the server's own sentence
        // says why in the same words its 403 would have used.
        //
        // The fallback exists for a server older than this build, which sends
        // no reason with the flag. It is the same shape as the one beside "Add
        // company" on the header above, and for the same reason: a control that
        // does nothing and explains nothing is the worst of the three states
        // this can be in.
        if (!widget.mayArrange)
          _StatusNote(
            widget.arrangeRefusal ??
                'Rearranging the structure is the whole business’s to do.',
          ),
        if (_rearrangedByAnother)
          _StatusNote(
            _sharedNote,
            onDismiss: () => setState(() => _rearrangedByAnother = false),
          ),
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
            // Null, not a handler that refuses. The canvas draws no move handle
            // and no "Arrange for me" when it is handed neither, which is what
            // keeps a dead control off a screen somebody cannot use it on.
            onMoved: widget.mayArrange ? _moved : null,
            onArranged: widget.mayArrange ? _arranged : null,
            actions: (context, node) =>
                widget.actions?.call(context, node.kind, node.raw),
            // The canvas's own sentence, which it used to be passed `null` for.
            //
            // The two no longer say the same thing, which is why both are now
            // on screen. The header above the tab strip says what this tab IS
            // and states the rule — who owns what is settled when a thing is
            // created. This one says how to USE the surface and what its lines
            // mean, including the line that is empty whenever a company has no
            // sub companies. That last part cannot live in the header: it is
            // about the canvas specifically, and the outline three hundred
            // points narrower has no lines at all.
            note: StructureCanvas.defaultNote,
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

  /// Nothing here writes. A visit that lands on the outline — too narrow or too
  /// short — reads the arrangement and never sends one: no automatic layout is
  /// run and no save is scheduled, so somebody opening this on a phone cannot
  /// flatten the arrangement they spent an afternoon on at a desk. That is a
  /// real bug rather than a hypothetical one: the tempting shape here is "lay it
  /// out and save it so it is ready", and that shape overwrites a desktop
  /// arrangement from a device that could never have produced one. It matters
  /// more than it did — the arrangement it would overwrite is now everybody's.
  Widget _outline(String note) {
    return StructureOutline(
      tree: widget.tree,
      // §8: the organization's own word for a place. "Franchise" is the one
      // noun this product lets a customer replace, and a view that used the
      // default would be this screen calling it something the rest of the app
      // does not.
      franchiseLabel: widget.session.me!.organization.franchiseLabel,
      trailing: (context, row) =>
          widget.actions?.call(context, row.kind, row.raw),
      // ── Inside the scroll, not stacked above it ───────────────
      //
      // These used to be a Column of notes with the outline in an Expanded
      // underneath, and that shape overflowed on the one device this view
      // exists for: a phone held sideways leaves this widget about 108 points,
      // and two paragraphs is more than that before a single row is drawn. An
      // overflow is a striped bar in debug and silently clipped content in
      // release, and the clipped content would have been the structure.
      //
      // As a header they are read first and scrolled past, which is what they
      // are: context for the list rather than chrome around it.
      header: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Says what happened instead of leaving somebody who was told the
          // boxes can be dragged looking at a list that cannot be. "Arrange for
          // me" is ABSENT here rather than disabled, so without this sentence
          // there would be nothing on screen to explain itself.
          //
          // Which sentence depends on which dimension ran out, because the
          // remedies are different: the sidebar is width and nothing else.
          _StatusNote(note),
          // And the rule, which the canvas says in its own note and which this
          // view would otherwise never say at all. This is the ONLY view on a
          // phone: somebody whose whole experience of the structure is this
          // list is owed the one thing that matters most about it, in the same
          // words.
          //
          // Nothing here can be dragged, so there is no gesture to misread and
          // no moment to answer — the canvas's long explanation is raised by a
          // drop, and there are no drops here. A standing sentence is the whole
          // of what this view needs.
          const _StatusNote(kStructureOutlineOwnershipNote),
        ],
      ),
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

  /// What happened when somebody else's arrangement arrived, and what it means.
  ///
  /// ── Why it states the rule and not only the event ────────────
  ///
  /// "Somebody else has arranged these" on its own leaves the two questions
  /// anybody would ask next unanswered: what happened to what I was doing, and
  /// what happens if we both keep going. So it answers both. What this person
  /// moved and has not saved is still where they put it, which is what is
  /// actually on screen; and only one arrangement is kept, so the last person
  /// to move a box is the one who decides where it sits.
  ///
  /// That last sentence is the honest description of a single shared row with
  /// no version stamp on it. Softening it — "your changes have been merged" —
  /// would be a promise this endpoint does not make.
  ///
  /// It names nobody, because the server does not say who saved it and a guess
  /// here would be a claim with nothing behind it. It says "somebody else",
  /// which is the whole of what is known.
  static const String _sharedNote =
      'Somebody else has arranged these boxes since this tab was opened, and '
      'their arrangement is what you are looking at now. Anything you moved '
      'yourself is still where you put it. There is one arrangement for the '
      'whole business, so the last person to move a box is the one who decides '
      'where it sits.';

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
/// Not a snackbar and never a dialog. Most of these are CONDITIONS rather than
/// events — the screen is narrow, this permission is not held, these boxes are
/// where the shape put them — and each is true for as long as it is true. A
/// message that slides away after four seconds cannot say "this is a narrow
/// screen", and a modal would stop the very thing it is describing.
class _StatusNote extends StatelessWidget {
  const _StatusNote(this.text, {this.onDismiss});

  final String text;

  /// Given only by the one note that is about an EVENT rather than a condition
  /// — a colleague's arrangement having arrived. That one has to be clearable,
  /// because there is nothing anybody can do to make it stop being true and a
  /// sentence that cannot be got rid of becomes furniture.
  ///
  /// The others deliberately have no dismiss: their remedy is on screen (widen
  /// the window, press "Arrange for me", ask for the permission), and a
  /// dismissable explanation of a condition is a way to hide the reason a
  /// control is missing while the control stays missing.
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dismiss = onDismiss;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
      color: theme.colorScheme.surfaceContainerHighest,
      // A Column rather than a Row, because a paragraph and a button do not
      // share a line at 375 points and a Row would lay the sentence out a few
      // characters wide to make room.
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(text, style: theme.textTheme.bodySmall),
          if (dismiss != null)
            Align(
              alignment: AlignmentDirectional.centerEnd,
              // "Got it" rather than a bare ✕, which a screen reader announces
              // as "close" without saying what it closes.
              child: TextButton(onPressed: dismiss, child: const Text('Got it')),
            ),
        ],
      ),
    );
  }
}
