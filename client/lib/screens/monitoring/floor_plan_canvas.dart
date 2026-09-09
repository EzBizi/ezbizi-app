import 'dart:math' as math;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/gestures.dart' show DragStartBehavior;
import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// The Monitoring floor-plan canvas: a grid, the walls drawn on it, and one
/// tile per computer coloured by its assignee's live status
/// (spec docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
///
/// Task 6 of the plan. Read-only and PURE: everything it draws comes from
/// [layout] and [status], which [GeneralTab] fetches and polls. It never
/// fetches, holds no state, and the only thing it can do to the world is call
/// [onSelectNode] — which is what the Task 8 sidebar listens to.
///
/// ── Coordinates ────────────────────────────────────────────────────
///
/// `layout` speaks in GRID CELLS, never pixels — `{grid:{cols,rows}}`, walls as
/// `{x1,y1,x2,y2}` cell corners, nodes at `{x,y}` cells. A layout is therefore
/// resolution-independent: the same document reads the same on a 1440px desktop
/// and on a 375px phone, which is the only reason fit-to-canvas is enough and
/// pan/zoom could be deferred (spec "Deferred").
///
/// Fit-to-canvas means exactly one thing here: the canvas takes ALL the width
/// it is offered and derives its height from the grid's aspect ratio, so a cell
/// is square and `cell = availableWidth / cols`. Nothing scrolls sideways and
/// nothing is clipped, at any width.
///
/// ── Why walls are a painter and tiles are widgets ──────────────────
///
/// Two layers, split by whether the thing is interactive. The grid and the
/// walls are geometry nobody taps, so they are one [CustomPaint] — one repaint
/// boundary for a 40x28 grid instead of a thousand widgets. The tiles ARE
/// tappable and carry text, so they are real widgets in a [Stack]: hit-testing,
/// semantics and text layout are things Flutter already does correctly and a
/// painter would have to reimplement badly.
///
/// A wall is any two points, not a run of cells, so diagonals draw as
/// themselves — `drawLine` does not care about the grid.
///
/// ── Wall mode (Task 7b) ────────────────────────────────────────────
///
/// [wallMode] turns the whole canvas into a drawing surface: every tap on it
/// reports a grid INTERSECTION through [onWallPoint], and the tiles are put
/// behind an [IgnorePointer] so one tap can never both start a wall and pick up
/// a computer. That is the whole of the mode conflict story — the two gestures
/// are not arbitrated at run time, one of them simply does not exist while the
/// other is on.
///
/// The exception is the wall HANDLES, one small target at each existing wall's
/// midpoint, drawn only in wall mode. They sit above the background detector
/// and so win the tap (the deepest recognizer in the arena does), which is why
/// selecting a wall to delete cannot be confused with placing a point: the
/// point lands wherever the handle is not. Selecting is all a handle does — the
/// removal itself is a separate press on the toolbar's Delete wall, so a
/// mis-aimed tap while drawing cannot destroy anything.
///
/// ── The three colours, and the honesty rule ────────────────────────
///
/// See [tileColor]. Green and grey are the SAME tokens the PC Specs status dot
/// uses (`EzBiziTheme.clockIn` / `colorScheme.outlineVariant`), deliberately:
/// two Monitoring tabs telling you the same person is online must not do it in
/// two different greens. Red is `colorScheme.error`. No literals — the theme
/// ratchet enforces that, and this file adds none.
class FloorPlanCanvas extends StatelessWidget {
  const FloorPlanCanvas({
    super.key,
    required this.layout,
    required this.status,
    required this.selectedNodeId,
    required this.onSelectNode,
    this.editable = false,
    this.onNodeMoved,
    this.wallMode = false,
    this.onWallPoint,
    this.pendingWall,
    this.selectedWallIndex,
    this.onSelectWall,
  });

  /// `{grid:{cols,rows}, walls:[{x1,y1,x2,y2}], nodes:[{id,x,y,label,user_id}]}`
  /// — exactly the document the `/floor-plans/{id}` endpoint returns.
  final Map<String, dynamic> layout;

  /// `{<nodeId>: {assigned:bool, online:bool, name:?string}}`. A node with no
  /// entry is treated as unassigned: the server resolves status for everyone
  /// the viewer may see, so a missing entry is an absence of permission or of
  /// an assignee, never an absence of information we could have had.
  final Map<String, dynamic> status;

  final String? selectedNodeId;

  /// Called with a node's id when its tile is tapped. The tab owns the
  /// selection; this widget only reports the tap.
  final ValueChanged<String> onSelectNode;

  /// Whether tiles may be dragged. View mode is [editable] false and is
  /// exactly the Task 6 behaviour: no drag recognizer is even built, so there
  /// is nothing to start by accident while reading a plan.
  final bool editable;

  /// Called with `(nodeId, col, row)` when a drag is released — CELLS, already
  /// snapped and already clamped inside the grid, never pixels.
  ///
  /// The pixel-to-cell conversion lives HERE and only here, in [_dropAt],
  /// because this widget is the only thing that knows the cell size: `cell`
  /// falls out of the fit-to-canvas rule (`availableWidth / cols`) inside a
  /// LayoutBuilder the tab cannot see into. A caller doing its own conversion
  /// would be guessing at a width, and would silently disagree the moment the
  /// canvas is laid out at any other size — so the tab is handed the answer,
  /// not the arithmetic.
  final void Function(String nodeId, int col, int row)? onNodeMoved;

  /// Whether the canvas is drawing walls rather than arranging computers.
  ///
  /// One flag, two consequences, and both of them have to be here rather than
  /// in the tab: taps on the canvas become wall points, and the tiles stop
  /// taking pointers at all. See the class doc comment.
  final bool wallMode;

  /// Called with a grid INTERSECTION `(col, row)` — clamped to `0..cols` /
  /// `0..rows`, which is one more than the cell count because a wall runs along
  /// cell corners, not through cell centres.
  ///
  /// Pixels never leave this file. The tab is handed cells for the same reason
  /// [onNodeMoved] hands it cells: the cell size falls out of the fit-to-canvas
  /// rule inside a LayoutBuilder nothing outside can see into, so a caller doing
  /// its own arithmetic would be guessing at a width.
  final void Function(int col, int row)? onWallPoint;

  /// The first point of a wall that has been started but not finished, drawn as
  /// a marker so a half-drawn wall is visible rather than a mode you have to
  /// remember you are in.
  final FloorPlanCell? pendingWall;

  /// Which wall (its index in `layout['walls']`) is selected for deletion.
  final int? selectedWallIndex;

  /// Called with a wall's index when its handle is tapped. Selection only —
  /// removing it is the toolbar's job, not a tap's.
  final ValueChanged<int>? onSelectWall;

  /// Used when a layout omits its grid. The spec's own example is 40x28.
  static const int defaultCols = 40;
  static const int defaultRows = 28;

  /// A tile covers this many cells on a side. One cell is too small to read a
  /// label in at any realistic grid size (40 columns of a 1200px canvas is a
  /// 30px cell); two is a desk-sized marker that still lands on the grid, which
  /// is what a drag snaps to.
  static const int tileSpan = 2;

  /// The colour a tile draws in, for one node's [status] entry.
  ///
  /// ASSIGNED IS ASKED FIRST, and that is the honesty rule (spec §"Honesty"),
  /// not an ordering accident: `online` on a node nobody is assigned to is a
  /// fact about no one. A tile with no assignee is grey whatever else the map
  /// says, so the strongest claim the canvas can make — "that machine's person
  /// is at it right now", in green — is only ever made about a person.
  static Color tileColor(BuildContext context, Map<String, dynamic>? entry) {
    final scheme = Theme.of(context).colorScheme;
    if (entry == null || entry['assigned'] != true) {
      return scheme.outlineVariant;
    }
    return entry['online'] == true
        ? EzBiziTheme.clockIn(context)
        : scheme.error;
  }

  static int _asInt(Object? value, int fallback) =>
      value is num ? value.toInt() : fallback;

  static double _asDouble(Object? value) =>
      value is num ? value.toDouble() : 0.0;

  List<Map<String, dynamic>> get _nodes {
    final raw = layout['nodes'];
    if (raw is! List) return const [];
    return [
      for (final node in raw)
        if (node is Map<String, dynamic> && node['id'] is String) node,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final grid = layout['grid'];
    final gridMap = grid is Map ? grid : const {};
    final cols = math.max(1, _asInt(gridMap['cols'], defaultCols));
    final rows = math.max(1, _asInt(gridMap['rows'], defaultRows));
    final walls = FloorPlanWall.parseAll(layout['walls']);
    final nodes = _nodes;

    return LayoutBuilder(
      builder: (context, constraints) {
        // An unbounded width has no fit-to-canvas answer. Draw nothing rather
        // than guess a size the caller did not choose.
        if (!constraints.hasBoundedWidth || constraints.maxWidth <= 0) {
          return const SizedBox.shrink();
        }

        final width = constraints.maxWidth;
        final cell = width / cols;
        final height = cell * rows;
        final tile = math.min(cell * tileSpan, math.min(width, height));

        final drawing = wallMode && onWallPoint != null;

        final board = SizedBox(
          width: width,
          height: height,
          child: Stack(
            children: [
              Positioned.fill(
                child: CustomPaint(
                  painter: FloorPlanPainter(
                    cols: cols,
                    rows: rows,
                    walls: walls,
                    // The grid says "this is a plan" without asking to be read;
                    // `edge` is the token for exactly that weight of line.
                    gridColor: EzBiziTheme.edge(context),
                    wallColor: theme.colorScheme.outline,
                    pending: wallMode ? pendingWall : null,
                    selectedWallIndex: wallMode ? selectedWallIndex : null,
                    accentColor: theme.colorScheme.primary,
                  ),
                ),
              ),
              for (final node in nodes)
                _positionedTile(
                  context,
                  node: node,
                  cell: cell,
                  tile: tile,
                  cols: cols,
                  rows: rows,
                  width: width,
                  height: height,
                ),
              if (wallMode && onSelectWall != null)
                for (var index = 0; index < walls.length; index++)
                  _wallHandle(
                    context,
                    index: index,
                    wall: walls[index],
                    cell: cell,
                    width: width,
                    height: height,
                  ),
            ],
          ),
        );

        if (!drawing) return board;

        return GestureDetector(
          key: const Key('floor-plan-wall-surface'),
          behavior: HitTestBehavior.opaque,
          onTapUp: (details) => _wallPointAt(
            details.localPosition,
            cell: cell,
            cols: cols,
            rows: rows,
          ),
          child: board,
        );
      },
    );
  }

  Widget _positionedTile(
    BuildContext context, {
    required Map<String, dynamic> node,
    required double cell,
    required double tile,
    required int cols,
    required int rows,
    required double width,
    required double height,
  }) {
    final id = node['id'] as String;
    final label = (node['label'] as String?)?.trim();
    final entry = status[id] as Map<String, dynamic>?;

    // Clamped so a node placed on the last column still draws inside the
    // canvas instead of half off the edge — a drag snaps within bounds, but a
    // layout written by an older client must not overflow the box.
    final left = (_asDouble(node['x']) * cell)
        .clamp(0.0, math.max(0.0, width - tile))
        .toDouble();
    final top = (_asDouble(node['y']) * cell)
        .clamp(0.0, math.max(0.0, height - tile))
        .toDouble();

    return Positioned(
      key: ValueKey('floor-plan-node-$id'),
      left: left,
      top: top,
      width: tile,
      height: tile,
      // Wall mode takes the tiles out of the hit test entirely, so one tap can
      // never both place a wall point and select or drag a computer. Not a
      // z-order race: while walls are being drawn the tile has no recognizer to
      // win the arena with. The IgnorePointer is INSIDE the Positioned because
      // a Stack only positions its direct children.
      child: IgnorePointer(
        ignoring: wallMode,
        child: _NodeTile(
          label: label == null || label.isEmpty ? 'Computer' : label,
          entry: entry,
          selected: id == selectedNodeId,
          onTap: () => onSelectNode(id),
          // A drag is wired up only when there is both a mode that wants it and
          // somewhere to report it; either one missing and the tile is Task 6's.
          onDragged: editable && onNodeMoved != null
              ? (delta) => _dropAt(
                  id: id,
                  left: left,
                  top: top,
                  delta: delta,
                  cell: cell,
                  cols: cols,
                  rows: rows,
                )
              : null,
        ),
      ),
    );
  }

  /// A small target at a wall's midpoint, drawn only in wall mode.
  ///
  /// Selecting a wall needs somewhere unambiguous to tap: the wall itself is a
  /// one-pixel-thick line drawn by a painter, which has no hit test at all, and
  /// widening it into a tappable strip would put an invisible ribbon across the
  /// grid that swallows the points you are trying to draw with. A handle is
  /// small, sits where the wall is, exists only while walls are being edited,
  /// and blocks exactly one intersection while it does.
  Widget _wallHandle(
    BuildContext context, {
    required int index,
    required FloorPlanWall wall,
    required double cell,
    required double width,
    required double height,
  }) {
    final theme = Theme.of(context);
    final selected = index == selectedWallIndex;
    final size = math.max(16.0, cell * 1.2);

    final midX = (wall.x1 + wall.x2) / 2 * cell;
    final midY = (wall.y1 + wall.y2) / 2 * cell;

    return Positioned(
      key: ValueKey('floor-plan-wall-$index'),
      left: (midX - size / 2).clamp(0.0, math.max(0.0, width - size)),
      top: (midY - size / 2).clamp(0.0, math.max(0.0, height - size)),
      width: size,
      height: size,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () => onSelectWall!(index),
        child: Semantics(
          button: true,
          selected: selected,
          label: 'Wall ${index + 1}',
          child: Container(
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: theme.colorScheme.surface,
              border: Border.all(
                color: selected
                    ? theme.colorScheme.primary
                    : theme.colorScheme.outline,
                width: selected ? 3 : 1,
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// Turn a tap on the canvas into the nearest grid INTERSECTION, and report it.
  ///
  /// Rounds rather than floors, and clamps to `0..cols` inclusive: a wall runs
  /// along cell corners, so the last column's right-hand edge is a legal end
  /// point and flooring would make it unreachable.
  void _wallPointAt(
    Offset local, {
    required double cell,
    required int cols,
    required int rows,
  }) {
    if (cell <= 0) return;

    onWallPoint!(
      (local.dx / cell).round().clamp(0, cols),
      (local.dy / cell).round().clamp(0, rows),
    );
  }

  /// Turn a released drag into a grid cell, and report it.
  ///
  /// The tile hands back the pixels it was dragged by; this adds them to where
  /// it was drawn, divides by the cell size and ROUNDS — so a tile let go
  /// anywhere inside a cell lands on that cell, which is what "snaps to the
  /// nearest cell" means. The result is then clamped to `0 .. cols - tileSpan`
  /// (rows likewise), so a tile dragged off the right edge lands on the last
  /// column that fits it WHOLE rather than half outside the plan. A grid
  /// narrower than one tile clamps to 0 rather than to a negative column.
  void _dropAt({
    required String id,
    required double left,
    required double top,
    required Offset delta,
    required double cell,
    required int cols,
    required int rows,
  }) {
    if (cell <= 0) return;

    final maxCol = math.max(0, cols - tileSpan);
    final maxRow = math.max(0, rows - tileSpan);

    final col = ((left + delta.dx) / cell).round().clamp(0, maxCol);
    final row = ((top + delta.dy) / cell).round().clamp(0, maxRow);

    onNodeMoved!(id, col, row);
  }
}

/// One computer on the plan.
///
/// The visible text is the node's own LABEL — the desk, never the person. A
/// tile with no assignee has no name to show, and a tile that does have one
/// still shows the label, so the map reads the same whoever is sitting there;
/// the name belongs to the sidebar (Task 8), which has room to say "online,
/// seen 2 minutes ago" instead of implying it in six pixels. The name does
/// reach the screen reader, where the status colour cannot.
///
/// It is stateful only for the drag: while a pointer is down the tile has to
/// follow it, and where it has been dragged TO is not a fact about the layout
/// until it is let go. So the in-flight offset lives here and dies here, and
/// the only thing that leaves is [_NodeTile.onDragged] with the total pixel
/// delta — the canvas converts that into a cell, because the canvas is what
/// knows how big a cell is.
class _NodeTile extends StatefulWidget {
  const _NodeTile({
    required this.label,
    required this.entry,
    required this.selected,
    required this.onTap,
    this.onDragged,
  });

  final String label;
  final Map<String, dynamic>? entry;
  final bool selected;
  final VoidCallback onTap;

  /// Null in view mode — no recognizer is built at all, so nothing about
  /// reading a plan changes.
  final void Function(Offset delta)? onDragged;

  @override
  State<_NodeTile> createState() => _NodeTileState();
}

class _NodeTileState extends State<_NodeTile> {
  /// How far this tile has been dragged since the gesture started, in pixels.
  /// Zero whenever nothing is being dragged.
  Offset _drag = Offset.zero;

  String get _statusWord {
    final entry = widget.entry;
    if (entry == null || entry['assigned'] != true) return 'unassigned';
    final name = entry['name'] as String?;
    final state = entry['online'] == true ? 'online' : 'offline';
    return name == null || name.isEmpty ? state : '$name, $state';
  }

  void _onPanUpdate(DragUpdateDetails details) {
    setState(() => _drag += details.delta);
  }

  void _onPanEnd(DragEndDetails details) {
    final moved = _drag;
    // Cleared BEFORE it is reported: the canvas answers a move by rebuilding
    // this tile at its new left/top, and an offset still sitting here would be
    // added to that new position and double the drag.
    setState(() => _drag = Offset.zero);
    widget.onDragged?.call(moved);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fill = FloorPlanCanvas.tileColor(context, widget.entry);
    final draggable = widget.onDragged != null;

    // A token pair rather than a picked one: whichever of the three fills is
    // under the label, the text is the surface colour on a dark fill and the
    // on-surface colour on a light one. `clockIn` is dark in the light theme
    // and bright in the dark theme, so a fixed choice would fail one of them.
    final onFill = ThemeData.estimateBrightnessForColor(fill) == Brightness.dark
        ? theme.colorScheme.surface
        : theme.colorScheme.onSurface;

    return Semantics(
      button: true,
      selected: widget.selected,
      label: '${widget.label}, $_statusWord',
      child: Transform.translate(
        offset: _drag,
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          // `down`, not the default `start`: the tile has to end up where the
          // pointer left it, and `start` measures from where the recognizer won
          // the arena instead of from where the pointer went down — so every
          // drag would land the tile a touch-slop short of the finger, and a
          // short drag would snap back to the cell it came from.
          dragStartBehavior: DragStartBehavior.down,
          onTap: widget.onTap,
          onPanUpdate: draggable ? _onPanUpdate : null,
          onPanEnd: draggable ? _onPanEnd : null,
          child: Container(
            padding: const EdgeInsets.all(2),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: fill,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(
                // Selection is a border, not a different fill: the fill is the
                // status, and a selected tile that changed colour would be
                // saying something about the person instead of about the cursor.
                color: widget.selected
                    ? theme.colorScheme.onSurface
                    : theme.colorScheme.outlineVariant,
                width: widget.selected ? 3 : 1,
              ),
            ),
            // Scales the label down rather than wrapping or ellipsing it: the
            // tile is as small as the grid makes it, and a FittedBox cannot
            // overflow its box, which is what keeps 375px clean.
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                widget.label,
                maxLines: 1,
                softWrap: false,
                style: theme.textTheme.labelSmall?.copyWith(color: onFill),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One grid INTERSECTION — a corner, not a cell. What a wall's two ends are
/// made of, and what [FloorPlanCanvas.onWallPoint] reports.
typedef FloorPlanCell = ({int col, int row});

/// One wall segment, in grid-cell coordinates. Two arbitrary points, so a
/// diagonal is not a special case.
@immutable
class FloorPlanWall {
  const FloorPlanWall({
    required this.x1,
    required this.y1,
    required this.x2,
    required this.y2,
  });

  final double x1;
  final double y1;
  final double x2;
  final double y2;

  /// Skips anything that is not a `{x1,y1,x2,y2}` map. A layout is a JSON
  /// document written by another client; one malformed entry must cost that
  /// entry, not the whole map.
  static List<FloorPlanWall> parseAll(Object? raw) {
    if (raw is! List) return const [];
    return [
      for (final wall in raw)
        if (wall is Map)
          FloorPlanWall(
            x1: FloorPlanCanvas._asDouble(wall['x1']),
            y1: FloorPlanCanvas._asDouble(wall['y1']),
            x2: FloorPlanCanvas._asDouble(wall['x2']),
            y2: FloorPlanCanvas._asDouble(wall['y2']),
          ),
    ];
  }

  @override
  bool operator ==(Object other) =>
      other is FloorPlanWall &&
      other.x1 == x1 &&
      other.y1 == y1 &&
      other.x2 == x2 &&
      other.y2 == y2;

  @override
  int get hashCode => Object.hash(x1, y1, x2, y2);

  @override
  String toString() => 'FloorPlanWall($x1,$y1 -> $x2,$y2)';
}

/// The non-interactive layer: faint grid lines, then the walls over them.
///
/// Its fields are public so a test can read what it was handed instead of
/// matching pixels — a golden of a grid tells you a picture changed, not
/// whether a wall arrived.
class FloorPlanPainter extends CustomPainter {
  const FloorPlanPainter({
    required this.cols,
    required this.rows,
    required this.walls,
    required this.gridColor,
    required this.wallColor,
    this.pending,
    this.selectedWallIndex,
    this.accentColor,
  });

  final int cols;
  final int rows;
  final List<FloorPlanWall> walls;
  final Color gridColor;
  final Color wallColor;

  /// The first point of a half-drawn wall, or null. Drawn as a ring so the
  /// mode is visible on the map itself, not only in the toolbar's words.
  final FloorPlanCell? pending;

  /// Which wall is selected for removal — drawn in [accentColor] so the thing
  /// about to be deleted is the thing you can see.
  final int? selectedWallIndex;

  /// The token both of the above draw in. Null when neither is in play.
  final Color? accentColor;

  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0) return;

    final cellW = size.width / cols;
    final cellH = size.height / rows;

    final gridPaint = Paint()
      ..color = gridColor
      ..strokeWidth = 1
      ..style = PaintingStyle.stroke;

    for (var c = 0; c <= cols; c++) {
      final x = c * cellW;
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), gridPaint);
    }
    for (var r = 0; r <= rows; r++) {
      final y = r * cellH;
      canvas.drawLine(Offset(0, y), Offset(size.width, y), gridPaint);
    }

    // Scaled with the cell so a wall reads as a wall at 375px and at 1440px,
    // with a floor so it never thins to invisibility.
    final wallPaint = Paint()
      ..color = wallColor
      ..strokeWidth = math.max(2, cellW * 0.3)
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    final accent = accentColor;

    final selectedPaint = accent == null
        ? null
        : (Paint()
          ..color = accent
          ..strokeWidth = math.max(3, cellW * 0.45)
          ..strokeCap = StrokeCap.round
          ..style = PaintingStyle.stroke);

    for (var index = 0; index < walls.length; index++) {
      final wall = walls[index];
      canvas.drawLine(
        Offset(wall.x1 * cellW, wall.y1 * cellH),
        Offset(wall.x2 * cellW, wall.y2 * cellH),
        index == selectedWallIndex && selectedPaint != null
            ? selectedPaint
            : wallPaint,
      );
    }

    // The half-drawn wall's first point: a filled dot with a ring, so it reads
    // at 375px where the dot alone would be a few pixels of nothing.
    final start = pending;
    if (start != null && accent != null) {
      final centre = Offset(start.col * cellW, start.row * cellH);
      final radius = math.max(4.0, cellW * 0.5);

      canvas.drawCircle(centre, radius, Paint()..color = accent);
      canvas.drawCircle(
        centre,
        radius * 2,
        Paint()
          ..color = accent
          ..strokeWidth = 2
          ..style = PaintingStyle.stroke,
      );
    }
  }

  @override
  bool shouldRepaint(FloorPlanPainter oldDelegate) =>
      oldDelegate.cols != cols ||
      oldDelegate.rows != rows ||
      oldDelegate.gridColor != gridColor ||
      oldDelegate.wallColor != wallColor ||
      oldDelegate.pending != pending ||
      oldDelegate.selectedWallIndex != selectedWallIndex ||
      oldDelegate.accentColor != accentColor ||
      !listEquals(oldDelegate.walls, walls);
}
