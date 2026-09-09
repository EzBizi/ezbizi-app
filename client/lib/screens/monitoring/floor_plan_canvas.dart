import 'dart:math' as math;

import 'package:flutter/foundation.dart' show listEquals;
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

  /// Used when a layout omits its grid. The spec's own example is 40x28.
  static const int defaultCols = 40;
  static const int defaultRows = 28;

  /// A tile covers this many cells on a side. One cell is too small to read a
  /// label in at any realistic grid size (40 columns of a 1200px canvas is a
  /// 30px cell); two is a desk-sized marker that still lands on the grid, which
  /// is what the editor will snap to in Task 7.
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

        return SizedBox(
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
                  ),
                ),
              ),
              for (final node in nodes)
                _positionedTile(
                  context,
                  node: node,
                  cell: cell,
                  tile: tile,
                  width: width,
                  height: height,
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _positionedTile(
    BuildContext context, {
    required Map<String, dynamic> node,
    required double cell,
    required double tile,
    required double width,
    required double height,
  }) {
    final id = node['id'] as String;
    final label = (node['label'] as String?)?.trim();
    final entry = status[id] as Map<String, dynamic>?;

    // Clamped so a node placed on the last column still draws inside the
    // canvas instead of half off the edge — the editor snaps within bounds,
    // but a layout written by an older client must not overflow the box.
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
      child: _NodeTile(
        label: label == null || label.isEmpty ? 'Computer' : label,
        entry: entry,
        selected: id == selectedNodeId,
        onTap: () => onSelectNode(id),
      ),
    );
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
class _NodeTile extends StatelessWidget {
  const _NodeTile({
    required this.label,
    required this.entry,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final Map<String, dynamic>? entry;
  final bool selected;
  final VoidCallback onTap;

  String get _statusWord {
    if (entry == null || entry!['assigned'] != true) return 'unassigned';
    final name = entry!['name'] as String?;
    final state = entry!['online'] == true ? 'online' : 'offline';
    return name == null || name.isEmpty ? state : '$name, $state';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fill = FloorPlanCanvas.tileColor(context, entry);

    // A token pair rather than a picked one: whichever of the three fills is
    // under the label, the text is the surface colour on a dark fill and the
    // on-surface colour on a light one. `clockIn` is dark in the light theme
    // and bright in the dark theme, so a fixed choice would fail one of them.
    final onFill = ThemeData.estimateBrightnessForColor(fill) == Brightness.dark
        ? theme.colorScheme.surface
        : theme.colorScheme.onSurface;

    return Semantics(
      button: true,
      selected: selected,
      label: '$label, $_statusWord',
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
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
              color: selected
                  ? theme.colorScheme.onSurface
                  : theme.colorScheme.outlineVariant,
              width: selected ? 3 : 1,
            ),
          ),
          // Scales the label down rather than wrapping or ellipsing it: the
          // tile is as small as the grid makes it, and a FittedBox cannot
          // overflow its box, which is what keeps 375px clean.
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              label,
              maxLines: 1,
              softWrap: false,
              style: theme.textTheme.labelSmall?.copyWith(color: onFill),
            ),
          ),
        ),
      ),
    );
  }
}

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
  });

  final int cols;
  final int rows;
  final List<FloorPlanWall> walls;
  final Color gridColor;
  final Color wallColor;

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

    for (final wall in walls) {
      canvas.drawLine(
        Offset(wall.x1 * cellW, wall.y1 * cellH),
        Offset(wall.x2 * cellW, wall.y2 * cellH),
        wallPaint,
      );
    }
  }

  @override
  bool shouldRepaint(FloorPlanPainter oldDelegate) =>
      oldDelegate.cols != cols ||
      oldDelegate.rows != rows ||
      oldDelegate.gridColor != gridColor ||
      oldDelegate.wallColor != wallColor ||
      !listEquals(oldDelegate.walls, walls);
}
