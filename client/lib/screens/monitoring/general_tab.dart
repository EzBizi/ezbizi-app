import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import 'floor_plan_canvas.dart';
import 'floor_plan_summary.dart';
import 'note_card.dart';

/// Monitoring — General: an organization's machines laid out on a floor plan
/// (spec docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
///
/// The tab SHELL — the plan picker, the load of one plan's `layout` + resolved
/// `status`, the 30s status poll, the TILE EDITOR (Task 7a: add, move, delete,
/// assign, save) and, from Task 7b, WALLS and the plans themselves (create with
/// a scope, rename, delete) — mounting [FloorPlanCanvas] as the map area, still
/// keyed `floor-plan-canvas`. The summary sidebar is Task 8, and the selection
/// this tab holds ([_selectedNodeId]) is what it will read, which is why the
/// canvas reports taps here rather than keeping the selection to itself.
///
/// ── Two editing modes, never both ──────────────────────────────────
///
/// Inside edit mode there is a second switch: tiles or walls. Wall mode hands
/// the canvas `wallMode: true`, which takes the tiles out of the hit test
/// entirely, and drops the tile selection here so the toolbar's three tile
/// controls have nothing to act on either. So one tap is one thing, always, and
/// which thing is a mode you can see rather than a gesture you have to aim.
/// Leaving wall mode discards a half-drawn wall: it was never in the draft, so
/// there is nothing to undo, and the plan is not dirty because of it.
///
/// ── The two grants, and what each gates ────────────────────────────
///
///   * `monitoring.view` — the same grant that gates the whole module — lets
///     someone SEE the tab and its plans. Without it there is no fetch at all,
///     only [noPermissionNote]: the list endpoint would 403 anyway, and asking
///     the server only to show the same sentence a beat later teaches nobody
///     anything (the rule every other Monitoring tab keeps).
///   * `monitoring.map.manage` — edit — decides whether the edit toggle exists
///     at all. Without it nothing on this tab can be dragged, added, deleted or
///     assigned, and the assign picker's roster
///     (`GET /monitoring/assignable-people`, gated on the same grant) is never
///     even requested: a viewer has no use for a list of people they cannot
///     assign anybody from.
///
/// ── View mode and edit mode ────────────────────────────────────────
///
/// View mode is byte-for-byte what it was before the editor existed: the canvas
/// is handed `editable: false` and no move callback, so no drag recognizer is
/// built and there are no edit affordances on screen.
///
/// Edit mode works on a DRAFT — a deep copy of the loaded layout ([_draft]) —
/// and never on `_layout` itself. Two reasons, both load-bearing: leaving edit
/// mode has to be able to throw the edits away and still have the plan the
/// server sent, and a `status` map keyed by the server's nodes has to keep
/// meaning something while the draft grows nodes the server has never seen. A
/// draft node has no `status` entry and so draws grey — including one you have
/// just assigned somebody to, which is honest: whether that person is ONLINE is
/// the server's fact, and it does not become ours by typing their name.
///
/// The poll is CANCELLED while editing. A poll exists to keep a live status
/// fresh; while you are rearranging desks it would only re-answer a question
/// about a layout you are in the middle of changing, and the plan-switch
/// picker is disabled in edit mode for the same reason — a switch would have
/// nowhere honest to put the draft.
///
/// ── The poll and its guard ─────────────────────────────────────────
///
/// The selected plan's `{id}` endpoint is re-read every [pollInterval] to keep
/// `status` fresh (plus a manual refresh). Every fetch — the first load, a
/// plan-switch, a poll tick — bumps [_loadGeneration] before its first await
/// and re-checks it after; a response that lands after a newer fetch has
/// started is stale and applies nothing. This is the exact generation guard
/// desktop_monitoring_tab's `_loadShots` uses, for the exact same reason: two
/// GETs in flight have no ordering guarantee, so a late answer for a plan you
/// have since left must never paint under the plan you are now looking at. The
/// poll timer is cancelled on dispose and on every plan-switch.
class GeneralTab extends StatefulWidget {
  const GeneralTab({
    super.key,
    required this.api,
    required this.session,
    this.onOpenDesktopMonitoring,
  });

  final ApiClient api;
  final Session session;

  /// Switches the Monitoring screen to its Desktop Monitoring sub-tab.
  ///
  /// A callback rather than a Navigator push because the sub-tab is not a
  /// route: [MonitoringScreen] owns which chip is active, so the only thing
  /// that can switch it is the screen itself. Null when the viewer lacks
  /// `monitoring.screenshots.view` (the screen passes nothing then, and its
  /// chip is locked for the same reason) — the summary offers no jump at all
  /// in that case rather than one that lands on a refusal.
  final VoidCallback? onOpenDesktopMonitoring;

  /// How often the selected plan's status is re-read. Polling, not push —
  /// live push is deferred for the whole module until it earns its keep (spec
  /// "Deferred").
  static const Duration pollInterval = Duration(seconds: 30);

  /// Above this width the summary is a rail to the RIGHT of the map; at or
  /// below it, the summary drops underneath. The map is fit-to-canvas, so a
  /// rail always costs the picture its own width — at 900px the map would be
  /// down to ~580px with the rail beside it, which is where a 40-column grid
  /// stops being readable. Below that the map keeps the full width and the
  /// summary takes a turn underneath it.
  static const double summaryBeside = 900;

  static const String noPermissionNote =
      'Your role does not include monitoring.view, so floor plans are not '
      'shown here.';

  static const String noPlansNote = 'No floor plans yet.';

  /// Shown under [noPlansNote] only to someone who can edit — it says the one
  /// thing about creating a plan that is not obvious from the button.
  static const String addPlanHint =
      'A plan belongs to the organization, to one company or to one franchise. '
      'You choose which when you name it.';

  static const String selectPrompt = 'Select a floor plan to view it.';

  /// Said in edit mode while nothing is selected, so the two controls that need
  /// a tile are not disabled for a reason the screen never gives.
  static const String selectTileHint =
      'Tap a computer to assign or delete it. Drag one to move it.';

  /// Wall mode, with nothing started. Says both halves — how a wall is drawn
  /// and how the mode is left — because a mode with no visible way out is how
  /// somebody ends up dragging tiles that no longer answer.
  static const String wallHint =
      'Tap two points on the grid to draw a wall between them; any two, so a '
      'diagonal works. Tap Drawing walls again to stop. Computers cannot be '
      'moved while walls are being drawn.';

  /// Wall mode, one point down. The cancel is stated because it is the only
  /// affordance here that is not a button.
  static const String wallPendingHint =
      'Wall started. Tap the other end, or tap the same point again to cancel '
      'it.';

  /// How a wall is removed. Two steps on purpose: a tap on a wall's handle
  /// selects it, and Delete wall removes it — so no tap while drawing can take
  /// a wall away.
  static const String wallDeleteHint =
      'Tap the dot on a wall to select it, then Delete wall.';

  /// The confirmation for leaving edit mode with unsaved edits. Edits are never
  /// dropped silently: the answer is either "keep editing" (the draft survives,
  /// still dirty) or an explicit discard.
  static const String unsavedNote =
      'This floor plan has changes that have not been saved. Leaving edit mode '
      'now throws them away.';

  @override
  State<GeneralTab> createState() => _GeneralTabState();
}

class _GeneralTabState extends State<GeneralTab> {
  bool _loadingPlans = true;
  String? _plansError;
  List<Map<String, dynamic>> _plans = [];

  String? _selectedPlanId;
  bool _loadingPlan = false;
  String? _planError;
  Map<String, dynamic>? _layout;
  Map<String, dynamic>? _status;

  /// Which computer tile is selected, or null for none. Owned here, not by the
  /// canvas: the Task 8 sidebar answers a different question depending on it,
  /// so one tab-level answer keeps the two panes from disagreeing.
  String? _selectedNodeId;

  bool _editing = false;

  /// Wall mode. While it is on the canvas is a drawing surface and the tiles
  /// take no pointers at all — see [FloorPlanCanvas.wallMode]. Only ever true
  /// inside edit mode; leaving edit mode turns it off.
  bool _wallMode = false;

  /// The first point of a wall that has been started and not finished. It is
  /// deliberately NOT in the draft: a half-drawn wall is not part of the plan,
  /// so it neither marks the plan dirty nor survives leaving wall mode.
  FloorPlanCell? _pendingWall;

  /// Which wall (its index in the shown layout's `walls`) is selected for
  /// removal. Indices, not identity: a wall is four numbers with no id, and the
  /// list is only ever rebuilt whole.
  int? _selectedWallIndex;

  /// The layout being edited — a deep copy of [_layout], made on the first
  /// mutation and dropped on save or discard. Null means "not edited since the
  /// last load", which is also exactly the not-dirty condition.
  Map<String, dynamic>? _draft;

  bool _dirty = false;
  bool _saving = false;
  String? _saveError;

  /// A create / rename / delete in flight, and whatever the server said if one
  /// was refused. Separate from [_saveError] because they are refused for
  /// different reasons — a scope you may not use, versus a person you may not
  /// see — and one of them must not clear the other's sentence off the screen.
  bool _planBusy = false;
  String? _planActionError;

  /// The assign picker's roster, fetched once per edit session. Null means
  /// "never asked" — which is the state a viewer without `monitoring.map.manage`
  /// stays in forever, because they never enter edit mode.
  List<Map<String, dynamic>>? _people;
  bool _loadingPeople = false;
  String? _peopleError;

  /// The scopes this viewer may CREATE a plan at, straight from
  /// `GET /monitoring/floor-plans/scope-options` — the server's own answer, not
  /// a guess assembled from the session. Null means "never asked"; fetched once
  /// and reused, exactly like [_people], and for the same reason: it is the
  /// same grant, and it does not change while the tab is open.
  List<Map<String, dynamic>>? _scopeRows;
  bool _loadingScopes = false;

  /// What the server said if the scope list could not be fetched. Shown in the
  /// create panel INSTEAD of a scope list — a guessed list is how the panel
  /// came to offer a level the actor could not use.
  String? _scopesError;

  Timer? _pollTimer;

  /// Bumped every time a plan fetch STARTS (a select, a poll tick, a manual
  /// refresh). `_loadPlan` captures it before its await and re-checks it after;
  /// a response from an older generation is dropped. See the class doc comment.
  int _loadGeneration = 0;

  bool get _canView => widget.session.me?.can('monitoring.view') ?? false;

  bool get _canManage =>
      widget.session.me?.can('monitoring.map.manage') ?? false;

  /// Whether the Desktop Monitoring jump is offered at all — the same grant
  /// MonitoringScreen gates that chip on. Without it the tab it would switch
  /// to is locked and its endpoints 403.
  bool get _canViewScreenshots =>
      widget.session.me?.can('monitoring.screenshots.view') ?? false;

  /// The selected plan's scope, worded the way the picker's own group headings
  /// word it ("Company · A-1 Chimney"), so the summary names the plan's place
  /// in the same words you chose it by.
  String get _scopeLabel {
    final scope =
        (_selectedPlan?['scope'] as Map<String, dynamic>?) ?? const {};
    final type = scope['type'] as String? ?? '';
    final label = scope['label'] as String? ?? '';

    if (type.isEmpty && label.isEmpty) return '';
    if (label.isEmpty) return _typeTitle(type);

    return '${_typeTitle(type)} · $label';
  }

  /// What the canvas draws: the draft while one exists, the server's layout
  /// otherwise. One getter so no widget has to remember which is current.
  Map<String, dynamic>? get _shownLayout => _draft ?? _layout;

  Map<String, dynamic>? get _selectedPlan =>
      _plans.where((p) => p['id'] == _selectedPlanId).firstOrNull;

  @override
  void initState() {
    super.initState();
    if (_canView) _loadPlans();
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = _plans.isEmpty;
      _plansError = null;
    });

    final response = await widget.api.get('/monitoring/floor-plans');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingPlans = false;
        _plansError = response.message;
      });
      return;
    }

    final plans = (response.data as List<dynamic>).cast<Map<String, dynamic>>();

    setState(() {
      _loadingPlans = false;
      _plans = plans;
    });
  }

  void _selectPlan(String id) {
    if (id == _selectedPlanId) return;

    // Stop the old plan's poll before the id even changes, so a tick already
    // scheduled for it cannot fire against the new selection — the generation
    // guard would drop its response anyway, but cancelling is the honest thing.
    _pollTimer?.cancel();
    _pollTimer = null;

    setState(() {
      _selectedPlanId = id;
      _layout = null;
      _status = null;
      _planError = null;
      // Another plan's node ids mean nothing here.
      _selectedNodeId = null;
    });

    _loadPlan(id);
    _startPoll(id);
  }

  void _startPoll(String planId) {
    _pollTimer?.cancel();
    _pollTimer =
        Timer.periodic(GeneralTab.pollInterval, (_) => _loadPlan(planId));
  }

  void _refreshSelected() {
    final id = _selectedPlanId;
    if (id != null) _loadPlan(id);
  }

  /// Tapping the selected tile clears the selection, which is how you get the
  /// sidebar's whole-plan summary back without hunting for a close button.
  void _selectNode(String nodeId) {
    setState(() {
      _selectedNodeId = nodeId == _selectedNodeId ? null : nodeId;
    });
  }

  Future<void> _loadPlan(String planId) async {
    // Captured before any await; nothing between here and the increment can
    // race it, so every later check compares against whichever fetch is now
    // current.
    final generation = ++_loadGeneration;

    // A first load (or a switch, which clears `_layout`) has nothing on screen
    // yet, so it shows a spinner; a poll tick keeps the current map up and
    // swaps the data in when it lands.
    if (_layout == null) {
      setState(() {
        _loadingPlan = true;
        _planError = null;
      });
    }

    final response = await widget.api.get('/monitoring/floor-plans/$planId');

    if (!mounted || generation != _loadGeneration) return;

    if (!response.ok) {
      setState(() {
        _loadingPlan = false;
        _planError = response.message;
      });
      return;
    }

    final data = response.data as Map<String, dynamic>;

    final layout = (data['layout'] as Map<String, dynamic>?) ?? const {};

    setState(() {
      _loadingPlan = false;
      _planError = null;
      _layout = layout;
      _status = (data['status'] as Map<String, dynamic>?) ?? const {};
      // A poll can land on a layout somebody else has edited. A selection
      // pointing at a node that is no longer there would leave the sidebar
      // describing a deleted machine, so it is dropped rather than kept. The
      // draft is asked when there is one: a node you have just added is not in
      // the server's answer and must not deselect itself.
      final shown = _draft ?? layout;
      if (_selectedNodeId != null && !_layoutHasNode(shown, _selectedNodeId!)) {
        _selectedNodeId = null;
      }
    });
  }

  static bool _layoutHasNode(Map<String, dynamic> layout, String nodeId) {
    final nodes = layout['nodes'];
    if (nodes is! List) return false;
    return nodes.any((n) => n is Map && n['id'] == nodeId);
  }

  // ── edit mode ──────────────────────────────────────────────────────

  /// Enter edit mode, or try to leave it.
  ///
  /// Leaving with unsaved edits asks first, in a panel, and a "keep editing"
  /// answer leaves everything exactly as it was — the draft intact and still
  /// dirty. Nothing here can discard an edit without somebody having said so.
  Future<void> _toggleEdit() async {
    if (!_editing) {
      // The poll is stopped for the duration; see the class doc comment.
      _pollTimer?.cancel();
      _pollTimer = null;

      setState(() {
        _editing = true;
        _saveError = null;
      });

      await _loadPeople();
      return;
    }

    if (_dirty && !await _confirmDiscard()) return;

    if (!mounted) return;

    setState(() {
      _editing = false;
      _draft = null;
      _dirty = false;
      _saveError = null;
      _peopleError = null;
      // Wall mode is a mode INSIDE editing, so it cannot outlive it — and a
      // half-drawn wall goes with it, having never been part of the plan.
      _wallMode = false;
      _pendingWall = null;
      _selectedWallIndex = null;
    });

    final id = _selectedPlanId;
    if (id != null) {
      // Back to live: re-read now rather than waiting out a whole interval for
      // the first status this tab has not been watching.
      _loadPlan(id);
      _startPoll(id);
    }
  }

  Future<bool> _confirmDiscard() async {
    final discard = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Leave without saving?',
        actions: [
          TextButton(
            key: const Key('general-keep-editing'),
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep editing'),
          ),
          FilledButton(
            key: const Key('general-discard'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Discard changes'),
          ),
        ],
        child: const Text(GeneralTab.unsavedNote),
      ),
    );

    return discard == true;
  }

  /// The people this editor may assign — asked for once, on entering edit mode.
  ///
  /// NOT `hr/people`: that is gated on HR grants a map editor need not hold.
  /// `monitoring/assignable-people` is gated on `monitoring.map.manage` and
  /// filtered by the same reach predicate the save is validated against, so
  /// every name it offers is a name the PUT will accept.
  Future<void> _loadPeople() async {
    if (_people != null || _loadingPeople) return;

    setState(() {
      _loadingPeople = true;
      _peopleError = null;
    });

    final response = await widget.api.get('/monitoring/assignable-people');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingPeople = false;
        _peopleError = response.message;
      });
      return;
    }

    setState(() {
      _loadingPeople = false;
      _people = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    });
  }

  /// The draft, created on first use from the loaded layout.
  ///
  /// The copy is deep enough to matter: the grid map, every wall map and every
  /// node map are copied, so editing a node cannot reach back into `_layout`
  /// and make the discard path a lie. Walls are copied although Task 7a cannot
  /// draw one — a save PUTs the whole layout, and a draft that dropped the
  /// walls would delete them from the plan on the next Save.
  Map<String, dynamic> _workingLayout() {
    final existing = _draft;
    if (existing != null) return existing;

    final base = _layout ?? const <String, dynamic>{};
    final grid = base['grid'];
    final walls = base['walls'];
    final nodes = base['nodes'];

    final copy = <String, dynamic>{
      'grid': grid is Map
          ? Map<String, dynamic>.from(grid)
          : <String, dynamic>{
              'cols': FloorPlanCanvas.defaultCols,
              'rows': FloorPlanCanvas.defaultRows,
            },
      'walls': [
        for (final wall in walls is List ? walls : const [])
          if (wall is Map) Map<String, dynamic>.from(wall),
      ],
      'nodes': [
        for (final node in nodes is List ? nodes : const [])
          if (node is Map) Map<String, dynamic>.from(node),
      ],
    };

    _draft = copy;
    return copy;
  }

  List<dynamic> _draftNodes() => _workingLayout()['nodes'] as List<dynamic>;

  List<dynamic> _draftWalls() => _workingLayout()['walls'] as List<dynamic>;

  // ── walls ──────────────────────────────────────────────────────────

  /// Turn wall drawing on or off.
  ///
  /// Both directions clear the half-drawn wall and the wall selection, and
  /// turning it ON also drops the TILE selection. That last one is the mode
  /// conflict guard on this side of the boundary: the canvas stops giving tiles
  /// pointers, and the toolbar's tile controls lose the selection they act on,
  /// so there is no way to reach a computer from a mode that cannot show you
  /// which one you would be reaching.
  void _toggleWallMode() {
    setState(() {
      _wallMode = !_wallMode;
      _pendingWall = null;
      _selectedWallIndex = null;
      if (_wallMode) _selectedNodeId = null;
    });
  }

  /// A grid intersection was tapped while drawing.
  ///
  /// The cell arrives already rounded to an intersection and already clamped —
  /// [FloorPlanCanvas] owns every pixel of that, because it is the only thing
  /// that knows how wide a cell was laid out. This only decides which of the
  /// three things a tap means: start a wall, finish one, or cancel the one that
  /// was started (the same point twice, which would otherwise be a wall of zero
  /// length — geometry the server accepts and nobody can see).
  void _wallPoint(int col, int row) {
    final start = _pendingWall;

    if (start == null) {
      setState(() {
        _pendingWall = (col: col, row: row);
        _selectedWallIndex = null;
      });
      return;
    }

    if (start.col == col && start.row == row) {
      setState(() => _pendingWall = null);
      return;
    }

    // Only now is the draft touched: a wall that was started and abandoned must
    // not leave the plan dirty.
    final walls = _draftWalls();

    setState(() {
      walls.add(<String, dynamic>{
        'x1': start.col,
        'y1': start.row,
        'x2': col,
        'y2': row,
      });
      _pendingWall = null;
      _dirty = true;
    });
  }

  /// A wall's handle was tapped. Selection only — see [_deleteWall].
  void _selectWall(int index) {
    setState(() {
      _selectedWallIndex = index == _selectedWallIndex ? null : index;
      // Selecting a wall abandons a half-drawn one rather than leaving two
      // different things half-started at once.
      _pendingWall = null;
    });
  }

  /// A wall's handle was dragged. The canvas has already snapped and clamped
  /// the shift to whole cells, so this only has to apply it to both ends.
  void _moveWall(int index, int dCol, int dRow) {
    final walls = _draftWalls();
    if (index < 0 || index >= walls.length) return;

    final wall = walls[index];
    if (wall is! Map) return;

    double at(String key) {
      final value = wall[key];
      return value is num ? value.toDouble() : 0.0;
    }

    setState(() {
      walls[index] = <String, dynamic>{
        'x1': at('x1') + dCol,
        'y1': at('y1') + dRow,
        'x2': at('x2') + dCol,
        'y2': at('y2') + dRow,
      };
      // A dragged wall is the one you meant, so it stays selected — otherwise
      // repositioning a wall you were about to delete would silently disarm
      // the Delete button.
      _selectedWallIndex = index;
      _pendingWall = null;
      _dirty = true;
    });
  }

  void _deleteWall() {
    final index = _selectedWallIndex;
    if (index == null) return;

    final walls = _draftWalls();
    if (index < 0 || index >= walls.length) return;

    setState(() {
      walls.removeAt(index);
      _selectedWallIndex = null;
      _dirty = true;
    });
  }

  Map<String, dynamic>? _draftNode(String id) {
    for (final node in _draftNodes()) {
      if (node is Map<String, dynamic> && node['id'] == id) return node;
    }
    return null;
  }

  /// Add a computer at the first free cell, and select it.
  ///
  /// "Free" is checked on the TILE LATTICE — every [FloorPlanCanvas.tileSpan]
  /// cells, the same lattice a drag snaps to — so a new tile lands beside the
  /// others rather than on top of one, and the scan never has to reason about
  /// two tiles half-overlapping. A full plan puts it at the origin: refusing to
  /// add would be a button that stops working with no way to see why, and a
  /// tile on top of another can be dragged off it in one gesture.
  void _addComputer() {
    final layout = _workingLayout();
    final grid = layout['grid'];
    final gridMap = grid is Map ? grid : const {};
    final cols = gridMap['cols'] is num
        ? (gridMap['cols'] as num).toInt()
        : FloorPlanCanvas.defaultCols;
    final rows = gridMap['rows'] is num
        ? (gridMap['rows'] as num).toInt()
        : FloorPlanCanvas.defaultRows;

    final nodes = _draftNodes();
    final taken = <String>{
      for (final node in nodes)
        if (node is Map) '${node['x']},${node['y']}',
    };

    var col = 0;
    var row = 0;
    const span = FloorPlanCanvas.tileSpan;

    outer:
    for (var r = 0; r + span <= rows; r += span) {
      for (var c = 0; c + span <= cols; c += span) {
        if (!taken.contains('$c,$r')) {
          col = c;
          row = r;
          break outer;
        }
      }
    }

    // Unique without asking the server for an id: the layout is one JSON
    // document written whole, so an id only has to be unique inside it.
    final id = 'node-${DateTime.now().microsecondsSinceEpoch}-${nodes.length}';

    setState(() {
      nodes.add(<String, dynamic>{
        'id': id,
        'x': col,
        'y': row,
        'label': 'Computer ${nodes.length + 1}',
        'user_id': null,
      });
      _dirty = true;
      _selectedNodeId = id;
    });
  }

  void _deleteSelected() {
    final id = _selectedNodeId;
    if (id == null) return;

    final nodes = _draftNodes();

    setState(() {
      nodes.removeWhere((node) => node is Map && node['id'] == id);
      _dirty = true;
      _selectedNodeId = null;
    });
  }

  /// A tile was dropped on a cell. The cell arrives already snapped and already
  /// clamped — [FloorPlanCanvas] owns every pixel of that arithmetic, because
  /// it is the only thing that knows how wide a cell was laid out.
  void _moveNode(String id, int col, int row) {
    final node = _draftNode(id);
    if (node == null) return;

    // A drag that ends on the cell it started from is not an edit, and marking
    // the plan dirty for it would arm the unsaved-changes prompt over nothing.
    if (node['x'] == col && node['y'] == row) return;

    setState(() {
      node['x'] = col;
      node['y'] = row;
      _dirty = true;
    });
  }

  /// Assign (or unassign) the selected tile, in a panel — this app has no
  /// dialogs, and the map stays visible beside the list so you can see which
  /// desk you are naming somebody for.
  Future<void> _assignSelected() async {
    final id = _selectedNodeId;
    if (id == null) return;

    await _loadPeople();

    if (!mounted) return;

    final node = _draftNode(id);
    final label = (node?['label'] as String?) ?? 'this computer';

    final choice = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => _AssignPanel(
        tileLabel: label,
        people: _people ?? const [],
        error: _peopleError,
        assignedUserId: node?['user_id'] as String?,
      ),
    );

    if (choice == null || !mounted) return;

    final target = _draftNode(id);
    if (target == null) return;

    setState(() {
      target['user_id'] = choice['user_id'];
      _dirty = true;
    });
  }

  /// PUT the whole draft, then re-read the plan.
  ///
  /// The re-read is not belt and braces: `status` is resolved by the server
  /// from the assignees, so the tile you just gave to somebody only learns
  /// whether they are online once the save has round-tripped. A failure keeps
  /// the draft exactly as it is — the server's own sentence is shown, and the
  /// plan stays dirty so Save is still there to press. That matters most for
  /// the one refusal the editor can actually provoke: assigning somebody the
  /// server will not let you see, which comes back 422.
  Future<void> _save() async {
    final planId = _selectedPlanId;
    final layout = _draft;
    if (planId == null || layout == null || _saving) return;

    setState(() {
      _saving = true;
      _saveError = null;
    });

    // LAYOUT ONLY, and deliberately no `name`. `update` takes `name` as
    // `sometimes`, and the only name this screen holds is the one that came
    // with the plan LIST — which can be minutes old and is not re-read while
    // you edit. Sending it back would let a layout save quietly revert somebody
    // else's rename to a name nobody typed. The editor has no name field, so
    // the name was not edited here and there is nothing to send.
    final response = await widget.api
        .put('/monitoring/floor-plans/$planId', <String, dynamic>{
      'layout': layout,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // `reason`, not `message`: a 422 says "The given data was invalid."
        // at the top and the sentence you can act on — "You can only assign
        // people you can see." — under `errors.layout`. The top-level one
        // would hide the only part of the refusal that says what to change.
        _saveError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _saveError = null;
      _draft = null;
      _dirty = false;
    });

    await _loadPlan(planId);
  }

  // ── the plans themselves: create, rename, delete ───────────────────

  /// Fetch the levels this viewer may create a plan at, once.
  ///
  /// `GET /monitoring/floor-plans/scope-options` is gated on the same grant the
  /// POST is (`monitoring.map.manage`) and answers from `ScopeReach::optionsFor`,
  /// the object `store` is judged against — so every row it returns saves.
  Future<void> _loadScopes() async {
    if (_scopeRows != null || _loadingScopes) return;

    setState(() {
      _loadingScopes = true;
      _scopesError = null;
    });

    final response =
        await widget.api.get('/monitoring/floor-plans/scope-options');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingScopes = false;
        // The server's own sentence. NOT a fallback list: guessing is the bug
        // this endpoint exists to fix, and a guess that is wrong here is a
        // create that cannot succeed however carefully it is filled in.
        _scopesError = response.message;
      });
      return;
    }

    setState(() {
      _loadingScopes = false;
      _scopeRows = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    });
  }

  /// The scopes this viewer may put a plan at, in the order the server sent.
  ///
  /// From [_scopeRows] — the server's answer — and NOT from the session. It was
  /// built here once, off `me.organization`, `me.companies` and `me.franchises`;
  /// two of those three are reach, but `me.organization` is not. It is the org
  /// you are IN, which everybody has, so an "Organization" row was offered to
  /// every actor and preselected for them, while `store`'s
  /// `ScopeReach::assertCovers` needs `canActOnOrganization` — a company or
  /// franchise manager who took the offered row got a guaranteed 422, under a
  /// sentence promising they were only offered levels they could act on.
  ///
  /// Not `me.scope.type` either: that is the VIEW SWITCHER's position, which
  /// falls back to the organization, so it answers a different question again.
  ///
  /// The server sends each row's plain name; the wording is this side's, and
  /// the franchise row uses the organization's own word for a franchise (§8).
  List<_ScopeOption> _scopeOptions() {
    final rows = _scopeRows;
    if (rows == null) return const [];

    final franchiseWord =
        widget.session.me?.organization.franchiseLabel ?? 'Franchise';

    return [
      for (final row in rows)
        if (row['type'] is String && row['id'] is String)
          _ScopeOption(
            type: row['type'] as String,
            id: row['id'] as String,
            label: row['type'] == 'franchise'
                ? '$franchiseWord · ${row['label'] ?? ''}'
                : '${_typeTitle(row['type'] as String)} · ${row['label'] ?? ''}',
          ),
    ];
  }

  /// Name a new plan and choose its scope, then POST it.
  ///
  /// The layout is a starter grid rather than nothing: the endpoint would
  /// default one for us, but a plan whose grid the server chose and a plan whose
  /// grid this client chose would be two different sizes, and the first save
  /// from the editor would silently resize somebody's map.
  ///
  /// A refusal is SHOWN, not swallowed — `assertCovers` answers a scope above
  /// the actor's level with a 422 on `scope`, and that sentence is the only part
  /// of the refusal anybody can act on.
  Future<void> _createPlan() async {
    // The levels come from the server, so they are asked for before the panel
    // is up rather than assembled from the session while it draws.
    await _loadScopes();

    if (!mounted) return;

    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => _PlanFormPanel(
        title: 'New floor plan',
        submitLabel: 'Create plan',
        initialName: '',
        scopes: _scopeOptions(),
        scopesError: _scopesError,
      ),
    );

    if (result == null || !mounted) return;

    setState(() {
      _planBusy = true;
      _planActionError = null;
    });

    final response = await widget.api.post('/monitoring/floor-plans', {
      'name': result['name'],
      'scope': result['scope'],
      'layout': <String, dynamic>{
        'grid': <String, dynamic>{
          'cols': FloorPlanCanvas.defaultCols,
          'rows': FloorPlanCanvas.defaultRows,
        },
        'walls': <dynamic>[],
        'nodes': <dynamic>[],
      },
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _planBusy = false;
        _planActionError = response.reason;
      });
      return;
    }

    final created = response.data;
    final id = created is Map<String, dynamic> ? created['id'] as String? : null;

    setState(() => _planBusy = false);

    // The list is re-read rather than having the new row pushed onto it: the
    // server decides the scope LABEL the picker groups by, and a row assembled
    // here would group under a heading of our own invention.
    await _loadPlans();

    if (id != null && mounted) _selectPlan(id);
  }

  /// Rename the selected plan.
  ///
  /// The PUT carries the LAYOUT as well as the name, and that is not belt and
  /// braces: `update` replaces whatever `layout` it is given, so a rename that
  /// sent only a name would be fine, but one that sent an empty layout would
  /// erase the plan. Sending the loaded layout back makes the request say what
  /// it means — this plan, with this name.
  Future<void> _renamePlan() async {
    final id = _selectedPlanId;
    final layout = _layout;
    if (id == null || layout == null) return;

    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => _PlanFormPanel(
        title: 'Rename floor plan',
        submitLabel: 'Save name',
        initialName: _selectedPlan?['name'] as String? ?? '',
        // A plan's scope does not move: `update` does not accept one, so
        // offering the picker here would be a control with nowhere to go.
        scopes: const [],
        scopesError: null,
      ),
    );

    if (result == null || !mounted) return;

    setState(() {
      _planBusy = true;
      _planActionError = null;
    });

    final response = await widget.api.put('/monitoring/floor-plans/$id', {
      'name': result['name'],
      'layout': layout,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _planBusy = false;
        _planActionError = response.reason;
      });
      return;
    }

    setState(() => _planBusy = false);

    await _loadPlans();
  }

  /// Delete the selected plan, after saying which one out loud.
  Future<void> _deletePlan() async {
    final id = _selectedPlanId;
    if (id == null) return;

    final name = _selectedPlan?['name'] as String? ?? 'this floor plan';

    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete floor plan?',
        actions: [
          TextButton(
            key: const Key('plan-delete-cancel'),
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            key: const Key('plan-delete-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Delete plan'),
          ),
        ],
        child: Text(
          '$name, its walls and every computer on it are removed for everyone '
          'who can see it. This cannot be undone.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() {
      _planBusy = true;
      _planActionError = null;
    });

    final response = await widget.api.delete('/monitoring/floor-plans/$id');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _planBusy = false;
        _planActionError = response.reason;
      });
      return;
    }

    // Nothing is selected any more, and the poll for the plan that is gone stops
    // before the list is even re-read — a tick against a deleted id would 404.
    _pollTimer?.cancel();
    _pollTimer = null;

    // And the generation is bumped, so a fetch ALREADY IN FLIGHT for this plan
    // (a tick that fired a moment before the cancel, or the load from the
    // select) cannot land and apply against the plan that has just been
    // deleted. Every other path that leaves a plan does this; delete was the
    // one that did not.
    //
    // No test pins this, deliberately, and the reason is worth writing down:
    // today it changes NOTHING you can see. `_selectedArea` is gated on
    // `_selectedPlanId`, which the setState below clears, so a late answer only
    // writes `_layout` where nothing reads it — a widget test asserting "the
    // deleted plan does not repaint" passes with this line deleted, which makes
    // it a test of nothing. What the line buys is the invariant the rest of
    // this file keeps ("never apply an answer for a plan you have left"), so
    // the next thing that reads `_layout` without asking about the selection
    // does not quietly reintroduce the bug.
    _loadGeneration++;

    setState(() {
      _planBusy = false;
      _selectedPlanId = null;
      _layout = null;
      _status = null;
      _planError = null;
      _selectedNodeId = null;
    });

    await _loadPlans();
  }

  @override
  Widget build(BuildContext context) {
    if (!_canView) {
      return NoteCard(GeneralTab.noPermissionNote);
    }

    return AsyncBody(
      loading: _loadingPlans,
      error: _plansError,
      onRetry: _loadPlans,
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_plans.isEmpty) {
      return _EmptyState(
        canManage: _canManage,
        error: _planActionError,
        onAdd: _planBusy ? null : _createPlan,
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_canManage) ...[
            _PlanActions(
              // Every one of these replaces or removes the whole plan, so none
              // of them is offered while there is an unsaved draft to lose.
              onCreate: _editing || _planBusy ? null : _createPlan,
              onRename:
                  _editing || _planBusy || _selectedPlanId == null ||
                      _layout == null
                  ? null
                  : _renamePlan,
              onDelete: _editing || _planBusy || _selectedPlanId == null
                  ? null
                  : _deletePlan,
            ),
            if (_planActionError != null) ...[
              const SizedBox(height: 8),
              ErrorNote(_planActionError!),
            ],
            const SizedBox(height: 12),
          ],
          _PlanPicker(
            key: const Key('floor-plan-picker'),
            plans: _plans,
            selectedId: _selectedPlanId,
            // Switching plans mid-edit would have nowhere honest to put the
            // draft, so the picker waits until you are done.
            onSelect: _editing ? null : _selectPlan,
          ),
          const SizedBox(height: 16),
          _selectedArea(context),
        ],
      ),
    );
  }

  Widget _selectedArea(BuildContext context) {
    final id = _selectedPlanId;
    if (id == null) {
      return NoteCard(GeneralTab.selectPrompt);
    }

    final theme = Theme.of(context);
    final name = _selectedPlan?['name'] as String? ?? 'Floor plan';
    final layout = _shownLayout;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                name,
                style: theme.textTheme.titleMedium,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            IconButton(
              key: const Key('general-refresh'),
              tooltip: 'Refresh status',
              onPressed: _editing ? null : _refreshSelected,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (_loadingPlan)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_planError != null)
          ErrorNote(_planError!)
        else if (layout != null) ...[
          if (_canManage) ...[
            _EditToolbar(
              editing: _editing,
              dirty: _dirty,
              saving: _saving,
              hasSelection: _selectedNodeId != null,
              wallMode: _wallMode,
              wallStarted: _pendingWall != null,
              hasWallSelection: _selectedWallIndex != null,
              onToggleEdit: _toggleEdit,
              onAdd: _addComputer,
              onAssign: _assignSelected,
              onDelete: _deleteSelected,
              onSave: _save,
              onToggleWall: _toggleWallMode,
              onDeleteWall: _deleteWall,
            ),
            const SizedBox(height: 8),
          ],
          if (_saveError != null) ...[
            ErrorNote(_saveError!),
            const SizedBox(height: 8),
          ],
          Row(
            children: [
              Expanded(child: _PlanCounts(layout: layout)),
              _zoomControls(context),
            ],
          ),
          const SizedBox(height: 8),
          _mapAndSummary(context, name: name, layout: layout),
        ] else
          const SizedBox.shrink(),
      ],
    );
  }

  /// 1.0 is the whole plan on screen; the buttons step from there.
  ///
  /// Held by the tab rather than the canvas because the controls sit with the
  /// other map buttons, and because a zoom that reset on every status poll —
  /// this tab refreshes every 30 seconds — would snap the view back under
  /// somebody mid-look.
  double _zoom = 1.0;

  static const double _zoomStep = 0.25;
  static const double _zoomMin = 0.5;
  static const double _zoomMax = 3.0;

  void _setZoom(double value) {
    setState(() => _zoom = value.clamp(_zoomMin, _zoomMax));
  }

  /// Minus, the percentage, plus, and a way back to the whole plan.
  ///
  /// The reading is written out rather than implied by the buttons: somebody
  /// who has clicked plus four times and cannot see a tile needs to know
  /// whether they are at 200% or at the ceiling, and "Fit" is the one control
  /// that is always correct to press.
  Widget _zoomControls(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          key: const Key('floor-plan-zoom-out'),
          icon: const Icon(Icons.remove),
          tooltip: 'Zoom out',
          onPressed: _zoom <= _zoomMin ? null : () => _setZoom(_zoom - _zoomStep),
        ),
        Text('${(_zoom * 100).round()}%',
            style: Theme.of(context).textTheme.labelMedium),
        IconButton(
          key: const Key('floor-plan-zoom-in'),
          icon: const Icon(Icons.add),
          tooltip: 'Zoom in',
          onPressed: _zoom >= _zoomMax ? null : () => _setZoom(_zoom + _zoomStep),
        ),
        TextButton(
          key: const Key('floor-plan-zoom-fit'),
          onPressed: _zoom == 1.0 ? null : () => _setZoom(1.0),
          child: const Text('Fit'),
        ),
      ],
    );
  }

  /// The map and the summary, side by side or stacked.
  ///
  /// A [LayoutBuilder] rather than a MediaQuery read: what decides this is how
  /// much room the TAB has, which is not the window — the app's own nav rail
  /// takes a slice of it on desktop, and the same window is a different amount
  /// of room with it open. The canvas already sizes itself from the width it
  /// is handed, so it takes whatever the rail leaves without being told.
  ///
  /// The summary is mounted in BOTH arms with the same inputs; only where it
  /// sits changes. So nothing the pane says depends on the width, and a narrow
  /// window is never told less than a wide one.
  Widget _mapAndSummary(
    BuildContext context, {
    required String name,
    required Map<String, dynamic> layout,
  }) {
    final canvas = FloorPlanCanvas(
      key: const Key('floor-plan-canvas'),
      layout: layout,
      zoom: _zoom,
      status: _status ?? const {},
      selectedNodeId: _selectedNodeId,
      onSelectNode: _selectNode,
      editable: _editing,
      onNodeMoved: _editing ? _moveNode : null,
      wallMode: _editing && _wallMode,
      onWallPoint: _editing && _wallMode ? _wallPoint : null,
      pendingWall: _pendingWall,
      selectedWallIndex: _selectedWallIndex,
      onSelectWall: _editing && _wallMode ? _selectWall : null,
      onWallMoved: _editing && _wallMode ? _moveWall : null,
    );

    final summary = FloorPlanSummary(
      planName: name,
      scopeLabel: _scopeLabel,
      // The DRAFT while one exists, so the counts describe what is on screen.
      layout: layout,
      status: _status ?? const {},
      selectedNodeId: _selectedNodeId,
      onOpenDesktopMonitoring: _canViewScreenshots
          ? widget.onOpenDesktopMonitoring
          : null,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth <= GeneralTab.summaryBeside) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [canvas, const SizedBox(height: 12), summary],
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: canvas),
            const SizedBox(width: 16),
            SizedBox(width: FloorPlanSummary.railWidth, child: summary),
          ],
        );
      },
    );
  }
}

/// The editor's controls, above the map.
///
/// A [Wrap], not a [Row]: five buttons is more than 375px holds, and a Row
/// would throw there rather than take a second line. Assign and Delete are
/// disabled without a selected tile and Save without an unsaved change, each
/// with the hint below saying which — a disabled button whose condition is
/// never stated is a puzzle, not an affordance.
class _EditToolbar extends StatelessWidget {
  const _EditToolbar({
    required this.editing,
    required this.dirty,
    required this.saving,
    required this.hasSelection,
    required this.wallMode,
    required this.wallStarted,
    required this.hasWallSelection,
    required this.onToggleEdit,
    required this.onAdd,
    required this.onAssign,
    required this.onDelete,
    required this.onSave,
    required this.onToggleWall,
    required this.onDeleteWall,
  });

  final bool editing;
  final bool dirty;
  final bool saving;
  final bool hasSelection;

  /// Wall mode: the three tile controls go dead for the duration, because the
  /// canvas has stopped giving tiles pointers and a control that can act on a
  /// tile you cannot select is a control that cannot be aimed.
  final bool wallMode;
  final bool wallStarted;
  final bool hasWallSelection;

  final VoidCallback onToggleEdit;
  final VoidCallback onAdd;
  final VoidCallback onAssign;
  final VoidCallback onDelete;
  final VoidCallback onSave;
  final VoidCallback onToggleWall;
  final VoidCallback onDeleteWall;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            OutlinedButton.icon(
              key: const Key('general-edit-toggle'),
              onPressed: onToggleEdit,
              icon: Icon(editing ? Icons.done : Icons.edit_outlined),
              label: Text(editing ? 'Done' : 'Edit map'),
            ),
            if (editing) ...[
              FilledButton.tonalIcon(
                key: const Key('general-add-computer'),
                onPressed: wallMode ? null : onAdd,
                icon: const Icon(Icons.add),
                label: const Text('Add computer'),
              ),
              OutlinedButton(
                key: const Key('general-assign-node'),
                onPressed: hasSelection && !wallMode ? onAssign : null,
                child: const Text('Assign person'),
              ),
              OutlinedButton(
                key: const Key('general-delete-node'),
                onPressed: hasSelection && !wallMode ? onDelete : null,
                child: const Text('Delete computer'),
              ),
              OutlinedButton.icon(
                key: const Key('general-draw-wall'),
                onPressed: onToggleWall,
                icon: Icon(wallMode ? Icons.close : Icons.timeline),
                label: Text(wallMode ? 'Drawing walls' : 'Draw wall'),
              ),
              if (wallMode)
                OutlinedButton(
                  key: const Key('general-delete-wall'),
                  onPressed: hasWallSelection ? onDeleteWall : null,
                  child: const Text('Delete wall'),
                ),
              FilledButton(
                key: const Key('general-save'),
                onPressed: dirty && !saving ? onSave : null,
                child: Text(saving ? 'Saving…' : 'Save'),
              ),
            ],
          ],
        ),
        if (editing && _hint.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            key: const Key('general-edit-hint'),
            _hint,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
      ],
    );
  }

  /// One line under the toolbar, saying whichever thing is currently true.
  /// A disabled control whose condition is never stated is a puzzle, and wall
  /// mode disables four of them at once.
  String get _hint {
    if (wallMode) {
      if (wallStarted) return GeneralTab.wallPendingHint;
      return hasWallSelection
          ? GeneralTab.wallDeleteHint
          : '${GeneralTab.wallHint} ${GeneralTab.wallDeleteHint}';
    }
    return hasSelection ? '' : GeneralTab.selectTileHint;
  }
}

/// Making, renaming and removing the plans themselves — above the picker,
/// because they are about which plans there ARE rather than about what is on
/// one. Shown only with `monitoring.map.manage`; every button here is a request
/// the server refuses without it.
///
/// A [Wrap] for the same reason the edit toolbar is one: three buttons do not
/// fit 375px on a line. Rename and Delete are null without a loaded selection —
/// there is no plan for them to be about — and all three are null while an
/// unsaved draft exists, because each of them would replace or remove the plan
/// the draft belongs to.
class _PlanActions extends StatelessWidget {
  const _PlanActions({
    required this.onCreate,
    required this.onRename,
    required this.onDelete,
  });

  final VoidCallback? onCreate;
  final VoidCallback? onRename;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        FilledButton.tonalIcon(
          key: const Key('general-add-plan'),
          onPressed: onCreate,
          icon: const Icon(Icons.add),
          label: const Text('New floor plan'),
        ),
        OutlinedButton(
          key: const Key('general-rename-plan'),
          onPressed: onRename,
          child: const Text('Rename plan'),
        ),
        OutlinedButton(
          key: const Key('general-delete-plan'),
          onPressed: onDelete,
          child: const Text('Delete plan'),
        ),
      ],
    );
  }
}

/// One place a plan can belong to. [id] is the organization's, company's or
/// franchise's own id — what `scope.id` carries to `ScopeReach::assertCovers`.
@immutable
class _ScopeOption {
  const _ScopeOption({
    required this.type,
    required this.id,
    required this.label,
  });

  final String type;
  final String id;
  final String label;

  Key get key => ValueKey('plan-scope-$type-$id');
}

/// Name a plan — and, when it is being created, say where it belongs.
///
/// One panel for both, because they are the same form minus a field: a rename
/// is handed an empty [scopes] and simply has no scope section, rather than a
/// disabled one that implies the scope could be changed if you found the right
/// screen. It cannot: `update` does not accept a scope at all.
///
/// The scope list is radio-shaped without being a Material Radio — a row per
/// option with its own state icon. It reads the same and it keeps this file
/// clear of the group/value API, whose shape has moved twice.
///
/// Returns `{name, scope:{type,id}}` (scope omitted for a rename), or null for
/// a cancel.
class _PlanFormPanel extends StatefulWidget {
  const _PlanFormPanel({
    required this.title,
    required this.submitLabel,
    required this.initialName,
    required this.scopes,
    required this.scopesError,
  });

  final String title;
  final String submitLabel;
  final String initialName;

  /// The levels the SERVER said this actor may create at, already worded. An
  /// empty list means the panel is not choosing a scope at all (the rename),
  /// which is a different thing from a fetch that failed — see [scopesError].
  final List<_ScopeOption> scopes;

  /// The server's sentence, if the scope list could not be fetched. Shown
  /// instead of a list: this panel does not invent levels, because a level it
  /// invented is one the create would be refused for.
  final String? scopesError;

  @override
  State<_PlanFormPanel> createState() => _PlanFormPanelState();
}

class _PlanFormPanelState extends State<_PlanFormPanel> {
  late final TextEditingController _name =
      TextEditingController(text: widget.initialName);

  late _ScopeOption? _scope =
      widget.scopes.isEmpty ? null : widget.scopes.first;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  bool get _ready =>
      _name.text.trim().isNotEmpty &&
      widget.scopesError == null &&
      (widget.scopes.isEmpty || _scope != null);

  void _submit() {
    if (!_ready) return;

    final scope = _scope;

    Navigator.of(context).pop(<String, dynamic>{
      'name': _name.text.trim(),
      if (scope != null)
        'scope': <String, dynamic>{'type': scope.type, 'id': scope.id},
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SidePanel(
      title: widget.title,
      actions: [
        TextButton(
          key: const Key('plan-form-cancel'),
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('plan-save'),
          onPressed: _ready ? _submit : null,
          child: Text(widget.submitLabel),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const Key('plan-name'),
            controller: _name,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'Plan name',
              hintText: 'Main office',
            ),
            onChanged: (_) => setState(() {}),
            onSubmitted: (_) => _submit(),
          ),
          if (widget.scopesError != null) ...[
            const SizedBox(height: 16),
            ErrorNote(widget.scopesError!),
          ],
          if (widget.scopes.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Who this plan is for', style: theme.textTheme.titleSmall),
            const SizedBox(height: 4),
            Text(
              // True as of the scope-options endpoint: the rows below are
              // ScopeReach::optionsFor's answer, the same object the create is
              // judged against, so nothing offered here can be refused for
              // being above this actor's level.
              'Everyone at that level and below can see it. You are only '
              'offered the levels you can act on.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: 8),
            for (final option in widget.scopes)
              ListTile(
                key: option.key,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  option == _scope
                      ? Icons.radio_button_checked
                      : Icons.radio_button_unchecked,
                  color: option == _scope
                      ? theme.colorScheme.primary
                      : theme.colorScheme.onSurfaceVariant,
                ),
                title: Text(option.label),
                onTap: () => setState(() => _scope = option),
              ),
          ],
        ],
      ),
    );
  }
}

/// Who to put on the selected tile.
///
/// The roster comes from `monitoring/assignable-people`, which is filtered by
/// the same reach predicate the save is validated against — so everything in
/// this list can actually be saved. The search box filters what is already
/// held rather than asking the server again: the endpoint answers with the
/// whole (capped) list precisely so typing costs nothing.
///
/// Returns `{'user_id': <id>}` for a person, `{'user_id': null}` for Unassign,
/// and null for a cancel — a distinct third answer, so backing out cannot be
/// mistaken for clearing the assignee.
class _AssignPanel extends StatefulWidget {
  const _AssignPanel({
    required this.tileLabel,
    required this.people,
    required this.error,
    required this.assignedUserId,
  });

  final String tileLabel;
  final List<Map<String, dynamic>> people;
  final String? error;
  final String? assignedUserId;

  @override
  State<_AssignPanel> createState() => _AssignPanelState();
}

class _AssignPanelState extends State<_AssignPanel> {
  String _query = '';

  List<Map<String, dynamic>> get _matches {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return widget.people;
    return [
      for (final person in widget.people)
        if (((person['name'] as String?) ?? '').toLowerCase().contains(needle))
          person,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final matches = _matches;

    return SidePanel(
      title: 'Assign ${widget.tileLabel}',
      // The body owns its own height: a long roster stays a lazy list rather
      // than building every row inside a scroll view.
      scrollable: false,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        TextButton(
          key: const Key('assign-unassign'),
          onPressed: () =>
              Navigator.of(context).pop(<String, dynamic>{'user_id': null}),
          child: const Text('Unassign'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.error != null) ...[
            ErrorNote(widget.error!),
            const SizedBox(height: 12),
          ],
          TextField(
            key: const Key('assign-search'),
            decoration: const InputDecoration(
              labelText: 'Search people',
              prefixIcon: Icon(Icons.search),
            ),
            onChanged: (value) => setState(() => _query = value),
          ),
          const SizedBox(height: 12),
          Expanded(
            child: matches.isEmpty
                ? Text(
                    widget.people.isEmpty
                        ? 'There is nobody here you can assign.'
                        : 'Nobody matches that.',
                    style: theme.textTheme.bodyMedium,
                  )
                : ListView.builder(
                    itemCount: matches.length,
                    itemBuilder: (context, index) {
                      final person = matches[index];
                      final id = person['id'] as String? ?? '';
                      final name = person['name'] as String? ?? 'Unnamed';

                      return ListTile(
                        key: ValueKey('assign-person-$id'),
                        title: Text(name),
                        trailing: id == widget.assignedUserId
                            ? const Icon(Icons.check)
                            : null,
                        onTap: () => Navigator.of(context)
                            .pop(<String, dynamic>{'user_id': id}),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

/// A scope type as a heading word. Top-level, not private to the picker: the
/// summary pane names the selected plan's scope in the same words the picker's
/// group heading does, and two spellings of "Franchise" on one screen is the
/// kind of drift nobody reports and everybody notices.
String _typeTitle(String type) => switch (type) {
  'organization' => 'Organization',
  'company' => 'Company',
  'franchise' => 'Franchise',
  _ => type.isEmpty ? 'Other' : type,
};

/// The plan picker: the plans the viewer can see, grouped by scope so the same
/// office reads as one group. Org first, then each company, then each
/// franchise; the heading carries the scope's own label so two companies with
/// a plan each never merge. A `Wrap` of chips per group keeps it from
/// overflowing at 375px.
///
/// [onSelect] is null while the tab is in edit mode, which disables every chip:
/// there is no honest place to put a half-finished layout when you leave the
/// plan it belongs to.
class _PlanPicker extends StatelessWidget {
  const _PlanPicker({
    super.key,
    required this.plans,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> plans;
  final String? selectedId;
  final ValueChanged<String>? onSelect;

  static int _rank(String type) => switch (type) {
    'organization' => 0,
    'company' => 1,
    'franchise' => 2,
    _ => 3,
  };


  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Group by scope, preserving a stable order: rank by type, then the
    // scope label, so the group headings read top-down the way the org tree
    // does.
    final groups = <String, List<Map<String, dynamic>>>{};
    final groupOrder = <String>[];

    for (final plan in plans) {
      final scope = (plan['scope'] as Map<String, dynamic>?) ?? const {};
      final type = scope['type'] as String? ?? '';
      final label = scope['label'] as String? ?? '';
      final key = '${_rank(type)}|$type|$label';
      groups.putIfAbsent(key, () {
        groupOrder.add(key);
        return [];
      }).add(plan);
    }

    groupOrder.sort();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final key in groupOrder) ...[
          Builder(
            builder: (context) {
              final members = groups[key]!;
              final scope =
                  (members.first['scope'] as Map<String, dynamic>?) ??
                      const {};
              final type = scope['type'] as String? ?? '';
              final label = scope['label'] as String? ?? '';
              final heading =
                  label.isEmpty ? _typeTitle(type) : '${_typeTitle(type)} · $label';

              return Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(heading, style: theme.textTheme.titleSmall),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      children: [
                        for (final plan in members)
                          ChoiceChip(
                            key: ValueKey('floor-plan-${plan['id']}'),
                            label: Text(plan['name'] as String? ?? 'Untitled'),
                            selected: plan['id'] == selectedId,
                            onSelected: onSelect == null
                                ? null
                                : (_) => onSelect!(plan['id'] as String),
                          ),
                      ],
                    ),
                  ],
                ),
              );
            },
          ),
        ],
      ],
    );
  }
}

/// What the plan holds, in words, above the map: how many computer tiles and
/// how many wall segments. The map itself is the picture; this is the caption
/// that lets you tell at a glance that you are looking at the plan you chose
/// and that it loaded whole — the same two counts a stale answer would get
/// wrong, which is what the tab's late-answer test reads. In edit mode it
/// counts the DRAFT, so adding a computer says so immediately.
class _PlanCounts extends StatelessWidget {
  const _PlanCounts({required this.layout});

  final Map<String, dynamic> layout;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final nodes = (layout['nodes'] as List<dynamic>?) ?? const [];
    final walls = (layout['walls'] as List<dynamic>?) ?? const [];

    return Text(
      '${nodes.length} computers · ${walls.length} walls',
      style: theme.textTheme.bodySmall
          ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
    );
  }
}

/// No plans yet, and for an editor the way to make the first one.
///
/// It carries the SAME `general-add-plan` key the [_PlanActions] row does, and
/// the two are mutually exclusive — an empty roster or a populated one, never
/// both — so "the button that makes a plan" is one key wherever you meet it.
///
/// A refusal is shown here too. The create that failed is the one that would
/// have taken this state away, so the sentence explaining it has nowhere else
/// to be.
class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.canManage,
    required this.error,
    required this.onAdd,
  });

  final bool canManage;
  final String? error;
  final VoidCallback? onAdd;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      key: const ValueKey('tour-monitoring-map-empty'),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(GeneralTab.noPlansNote, style: theme.textTheme.bodyMedium),
            if (canManage) ...[
              const SizedBox(height: 12),
              FilledButton.icon(
                key: const Key('general-add-plan'),
                onPressed: onAdd,
                icon: const Icon(Icons.add),
                label: const Text('New floor plan'),
              ),
              const SizedBox(height: 8),
              Text(
                GeneralTab.addPlanHint,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                textAlign: TextAlign.center,
              ),
              if (error != null) ...[
                const SizedBox(height: 12),
                ErrorNote(error!),
              ],
            ],
          ],
        ),
      ),
    );
  }
}
