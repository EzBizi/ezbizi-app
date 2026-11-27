/// A flow chart: a graph, its layering, and the canvas that draws it.
///
/// Jose, 2026-09-17: *"this flow chart we did is amazing and i want it
/// implemented in other parts of the app specifically the Inspection
/// Builder"*. Built for Phone first; it lives here because a second caller
/// arrived, and two canvases would have drifted apart within a release.
///
/// -- What a module supplies, and what it does not ------------------
///
/// It supplies a [CallFlowGraph] and a [FlowLook]. It does NOT supply drawing:
/// the layering, the wires, the back-edge rule, the zoom and the phone ribbon
/// are the same everywhere, and the only thing that differs between a phone
/// line and an inspection form is **what a box is called and what is happening
/// inside it right now** -- which is exactly [FlowNodeLook].
///
/// `dead` and `skipped` are NOT part of the seam. They are the graph's own
/// words and they are drawn identically for every caller, because "a caller
/// reaches this and nothing happens" and "a technician passes this and nothing
/// is asked" are the same fault wearing two nouns, and the whole reason either
/// chart earns its screen.
library;

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/theme.dart';

/// One box. `state` is the server's, and the two that are not `ok` are the
/// reason the screen exists rather than decoration on it.
class FlowNode {
  const FlowNode({
    required this.id,
    required this.kind,
    required this.title,
    required this.detail,
    required this.state,
    this.ref = '',
    this.edit,
  });

  final String id;
  final String kind;
  final String title;
  final String detail;

  /// What this node IS elsewhere — for a queue node, its ring group id.
  ///
  /// The live-counts endpoint keys by ring group rather than by node, and
  /// Phone's reason is a good one: node ids depend on the menu
  /// (`queue-answer` versus `queue-key-3`), so producing them would mean
  /// rebuilding the whole graph on every ten-second poll to learn a number
  /// that depends on none of it. This is the key that makes the join one
  /// lookup instead.
  final String ref;

  /// Where this box is changed, or null when it is not a thing you change.
  ///
  /// Jose, 2026-09-16: *"every settings needs to be synced and manageable from
  /// here as well"*. **Synced is the word that decided the design** — he named
  /// a property rather than a layout, and a bespoke editor per node kind is
  /// precisely the thing that goes out of sync, silently, the first time
  /// somebody adds a field in Settings.
  final NodeEdit? edit;

  /// `ok` | `dead` | `skipped`.
  ///
  /// `dead` — a caller CAN reach this and nothing useful happens: an option
  /// pointing at a number nobody set, a ring group with nobody in it.
  ///
  /// `skipped` — the caller passes through this step and it does nothing.
  ///
  /// NOT unreachable. Phone measured their own renderer after describing it
  /// the other way: a skipped menu is linked in the ordinary chain,
  /// `arrival -> menu -> answer`, so a caller DOES traverse that point and
  /// simply hears nothing there. Nobody complains, because the caller does not
  /// know they missed a menu and the owner does not know theirs is silent.
  final String state;

  bool get isDead => state == 'dead';
  bool get isSkipped => state == 'skipped';

  static FlowNode fromJson(Map<String, dynamic> json) => FlowNode(
    id: '${json['id']}',
    kind: '${json['kind'] ?? ''}',
    title: '${json['title'] ?? ''}',
    detail: '${json['detail'] ?? ''}',
    state: '${json['state'] ?? 'ok'}',
    ref: json['ref'] == null ? '' : '${json['ref']}',
    edit: NodeEdit.fromJson(json['edit']),
  );
}

/// One wire. [label] is the branch's own word — "Press 1", "No key pressed",
/// "Everyone busy" — and is what makes a menu readable without opening a box.
class FlowEdge {
  const FlowEdge({required this.from, required this.to, this.label = ''});

  final String from;
  final String to;
  final String label;

  static FlowEdge fromJson(Map<String, dynamic> json) => FlowEdge(
    from: '${json['from']}',
    to: '${json['to']}',
    label: '${json['label'] ?? ''}',
  );
}

/// A graph, with its nodes indexed and its counts ready.
class CallFlowGraph {
  CallFlowGraph({required this.nodes, required this.edges, required this.entry})
    : _byId = {for (final n in nodes) n.id: n};

  final List<FlowNode> nodes;
  final List<FlowEdge> edges;
  final Map<String, FlowNode> _byId;

  /// Where the walk begins, which is not the same word in every module.
  ///
  /// Phone's is `caller` and Inspection's is `start`. **Required, with no
  /// default**, and that is Phone's own argument: a default of `caller` works
  /// for whoever did not pass one, so renaming Phone's entry node would keep
  /// inspections drawing correctly and silently stop working for Phone — the
  /// module that owns the word would be the one it broke.
  ///
  /// The ONE thing the layout does with this is measure distance from it, so
  /// an entry that names no node places every box in column zero: a perfectly
  /// tidy picture of nothing, with no error anywhere.
  ///
  /// It is also why `caller` does not appear in this file any more. A shared
  /// canvas holding one caller's vocabulary is the leak the seam exists to
  /// prevent.
  final String entry;

  FlowNode? node(String id) => _byId[id];

  /// The two counts the screen leads with.
  ///
  /// Led with rather than left to the drawing, because a chart that draws a
  /// dead branch identically to a live one is prettier than the list and less
  /// useful — and on the phone layout, where nothing is visible until you have
  /// walked to it, this is the ONLY thing that answers "is any of this broken?"
  int get deadCount => nodes.where((n) => n.isDead).length;

  int get skippedCount => nodes.where((n) => n.isSkipped).length;

  List<FlowEdge> out(String id) => [
    for (final e in edges)
      if (e.from == id) e,
  ];

  /// [entry] is the id of the node the walk begins at, which the caller knows
  /// and this cannot see: the payload does not name it.
  static CallFlowGraph? fromJson(
    Map<String, dynamic>? json, {
    required String entry,
  }) {
    if (json == null) return null;

    final rawNodes = json['nodes'];
    final rawEdges = json['edges'];
    if (rawNodes is! List || rawEdges is! List) return null;

    final nodes = [
      for (final n in rawNodes)
        if (n is Map<String, dynamic>) FlowNode.fromJson(n),
    ];
    if (nodes.isEmpty) return null;

    return CallFlowGraph(
      nodes: nodes,
      edges: [
        for (final e in rawEdges)
          if (e is Map<String, dynamic>) FlowEdge.fromJson(e),
      ],
      entry: entry,
    );
  }
}

/// A node placed in the picture: which column, which row within it.
class PlacedNode {
  const PlacedNode({
    required this.node,
    required this.column,
    required this.row,
  });

  final FlowNode node;
  final int column;
  final int row;
}

/// The laid-out graph: columns of nodes, and the edges sorted into the ones
/// that go forward and the ones that come back.
class FlowLayout {
  const FlowLayout({
    required this.placed,
    required this.forward,
    required this.back,
    required this.columns,
  });

  final List<PlacedNode> placed;

  /// Edges between different columns, drawn as ordinary wires.
  final List<FlowEdge> forward;

  /// Edges returning to a column at or before their own.
  ///
  /// **Drawn, never followed.** `queue -> answer -> queue` is real and is on
  /// eleven of the thirteen production lines, so this is the ordinary
  /// appearance rather than an edge case. Laying a back-edge out as another
  /// column would grow the chart by a column per retry, forever.
  final List<FlowEdge> back;

  final int columns;

  PlacedNode? find(String id) {
    for (final p in placed) {
      if (p.node.id == id) return p;
    }

    return null;
  }
}

/// Place every node in a column by its distance from the caller.
///
/// Breadth-first from [CallFlowGraph.entry], so a node's column is the
/// fewest keypresses a caller could be from it — which is the order somebody
/// reads the chart in.
///
/// **The cycle is why this is not a walk.** A depth-first descent would follow
/// `queue -> answer -> queue` round forever. Breadth-first with a seen-set
/// terminates, and an edge arriving at a node that already has a column is
/// classified rather than followed: if that column is not further right, the
/// edge comes BACK, and it is drawn as a return rather than as progress.
FlowLayout layOutFlow(CallFlowGraph graph) {
  final column = <String, int>{};
  final order = <String>[];

  if (graph.node(graph.entry) != null) {
    column[graph.entry] = 0;
    order.add(graph.entry);
  }

  final queue = <String>[...order];
  while (queue.isNotEmpty) {
    final id = queue.removeAt(0);
    for (final edge in graph.out(id)) {
      if (column.containsKey(edge.to)) continue;
      if (graph.node(edge.to) == null) continue;
      column[edge.to] = column[id]! + 1;
      order.add(edge.to);
      queue.add(edge.to);
    }
  }

  // Anything the search did not reach, placed one column past the end.
  //
  // This is NOT the `skipped` path, though it was written believing it was. A
  // skipped node is linked into the ordinary chain and BFS gives it a normal
  // column — measured, `arrival -> menu(skipped) -> answer` puts it at column
  // 2, in the middle, which is the right drawing: a reader sees callers
  // passing through a step that does nothing.
  //
  // What this loop actually guards is a node the server's contract says cannot
  // exist — unreachable, or hung off a dangling edge. Kept rather than
  // deleted, because without it such a node is dropped from `placed` and
  // vanishes from a chart that is the only place anybody would notice it.
  final deepest = column.values.isEmpty
      ? 0
      : column.values.reduce((a, b) => math.max(a, b));
  for (final n in graph.nodes) {
    if (!column.containsKey(n.id)) {
      column[n.id] = deepest + 1;
      order.add(n.id);
    }
  }

  final rows = <int, int>{};
  final placed = <PlacedNode>[];
  for (final id in order) {
    final node = graph.node(id);
    if (node == null) continue;
    final c = column[id]!;
    final r = rows[c] ?? 0;
    rows[c] = r + 1;
    placed.add(PlacedNode(node: node, column: c, row: r));
  }

  final forward = <FlowEdge>[];
  final back = <FlowEdge>[];
  for (final edge in graph.edges) {
    final from = column[edge.from];
    final to = column[edge.to];
    if (from == null || to == null) continue;
    (to > from ? forward : back).add(edge);
  }

  return FlowLayout(
    placed: placed,
    forward: forward,
    back: back,
    columns: column.values.isEmpty
        ? 0
        : column.values.reduce((a, b) => math.max(a, b)) + 1,
  );
}

/// One wire, and every key that travels it.
class MergedEdge {
  const MergedEdge({required this.edge, required this.label});

  /// The first of the parallel edges; its `from` and `to` are the wire.
  final FlowEdge edge;

  /// Every non-empty label on that wire, joined.
  final String label;
}

/// Edges sharing a source AND a target are ONE wire carrying both keys.
///
/// Jose, reading the live chart on 2026-09-15: *"the everyone busy line seems
/// to be coming out of last 5th button but in reality it applies to the entire
/// group"*. He was reading the drawing correctly and the drawing was wrong.
/// Phone re-measured and now emits **one node per group rather than per key**,
/// so a second key reaching the same team is a second EDGE into one box.
///
/// Two edges with identical endpoints draw as two wires on exactly the same
/// curve with two labels at the same point — the label collision this chart was
/// just fixed for, arriving again through another door. Merging them is also
/// the truer sentence: those keys do reach the same team.
///
/// Order is the order the server sent, so the keys read in menu order.
List<MergedEdge> mergeParallel(List<FlowEdge> edges) {
  final byPair = <String, List<FlowEdge>>{};
  for (final e in edges) {
    (byPair['${e.from}->${e.to}'] ??= []).add(e);
  }

  return [
    for (final group in byPair.values)
      MergedEdge(
        edge: group.first,
        label: group.map((e) => e.label).where((l) => l.isNotEmpty).join(' · '),
      ),
  ];
}

/// Which Settings pane edits a node, and which record in it.
///
/// [pane] is a section id out of the phone settings tab's own list —
/// `ring-groups`, `voicemail`, `hours`, `flows`… — and it is **given** rather
/// than derived.
///
/// Phone's warning, with a test pinning it: a voicemail BOX and a voicemail
/// OPTION on a menu are both kind `voicemail` and are edited in two different
/// panes, so deriving the pane from [FlowNode.kind] is wrong on a real line
/// rather than in theory. A ring group node and its queue node deliberately
/// share one target, because queueing-versus-voicemail is the group's own
/// `overflow` setting.
class NodeEdit {
  const NodeEdit({required this.pane, required this.id});

  final String pane;
  final String id;

  static NodeEdit? fromJson(Object? json) {
    if (json is! Map) return null;

    final pane = json['pane'];
    if (pane == null || '$pane'.isEmpty) return null;

    return NodeEdit(pane: '$pane', id: '${json['id'] ?? ''}');
  }
}

/// What one box is called and what is happening in it -- the only thing a
/// module has to answer for the chart to draw its graph.
///
/// One callback rather than four, because a node's icon, its kind word and its
/// live reading are one decision made in one place. Phone folds the queue
/// counts in here; Inspection folds in how many questions a screen asks.
class FlowNodeLook {
  const FlowNodeLook({
    required this.icon,
    this.caption = '',
    this.note = '',
    this.alert = false,
  });

  final IconData icon;

  /// The quiet word under the name on the canvas -- "Keypad", "Questions".
  ///
  /// **Canvas only, and that is not an oversight.** A canvas tile is an icon
  /// and a name, so it needs the hint; a ribbon row already carries the node's
  /// detail line underneath, where the same word would be a third way of
  /// saying one thing.
  final String caption;

  /// What is happening here NOW -- "4 waiting", "asked of nobody".
  ///
  /// Drawn on both surfaces, and it displaces [caption] where they compete: on
  /// a queue tile, "Queue" is the least interesting true thing on the screen
  /// once somebody is actually holding.
  final String note;

  /// Ring the tile. Reserved for something live, not for something wrong --
  /// wrong is the graph's own `dead`, and it is drawn without asking.
  final bool alert;
}

/// How a module answers for one node.
typedef FlowLook = FlowNodeLook Function(FlowNode);

/// A node nobody can describe -- an edge pointing at a box off the graph.
const IconData flowUnknownIcon = Icons.circle_outlined;

// ── Desktop: the canvas (Jose: desktop 2, 2026-09-15) ──────────────
//
// His words: "have it begin in the middle left, so you can expand up and down
// when nodes branch", and "more modern, with icons, arrows, and even a energy
// flow animation on the lines". He sent an n8n canvas as the reference: dark
// ground, an icon tile for the node, the name underneath it.
//
// The tile is why the detail text is not drawn here. It was: the menu node
// rendered the whole IVR prompt and stood five times taller than every other
// node, which is most of why the chart did not fit on his screen. The prompt is
// worth reading once, so it moved to a tap.

const double _tile = 56;
const double _nodeWidth = 128;
const double _nodeHeight = 92;
const double _columnGap = 104;
const double _rowGap = 18;

// The canvas palette lives in `theme.dart` as [FlowCanvas]: fixed rather than
// theme-derived, because a graph canvas is a drawing rather than a page — but
// declared with the rest of the colours, because ten literals accumulating in
// one screen is exactly what `theme_conventions_test` exists to stop, and it
// caught this.

/// Where every node sits, computed once and shared by the boxes and the wires.
///
/// It used to be computed twice — the `Positioned` children from column/row,
/// and the painter from column/row again. Two expressions of one fact, and the
/// kind that agree until somebody edits one of them.
class _Geometry {
  _Geometry(this.layout) {
    final perColumn = <int, int>{};
    for (final p in layout.placed) {
      perColumn[p.column] = (perColumn[p.column] ?? 0) + 1;
    }
    final tallest = perColumn.values.isEmpty
        ? 1
        : perColumn.values.reduce(math.max);

    size = Size(
      layout.columns * (_nodeWidth + _columnGap),
      tallest * (_nodeHeight + _rowGap) + 72,
    );

    // THE CHANGE HE ASKED FOR. Each column is centred on the canvas midline,
    // so the caller sits middle-left and a branch grows symmetrically up AND
    // down rather than hanging off the bottom.
    final centreY = size.height / 2;
    for (final p in layout.placed) {
      final n = perColumn[p.column]!;
      final stack = n * _nodeHeight + (n - 1) * _rowGap;
      topLeft[p.node.id] = Offset(
        p.column * (_nodeWidth + _columnGap) + 12,
        centreY - stack / 2 + p.row * (_nodeHeight + _rowGap),
      );
    }
  }

  final FlowLayout layout;
  final Map<String, Offset> topLeft = {};
  late final Size size;

  /// Wires meet the TILE, not the block: the name sits under the tile, and a
  /// wire arriving at the middle of the block would run through the text.
  Offset rightOf(String id) =>
      topLeft[id]! + const Offset(_nodeWidth, _tile / 2);
  Offset leftOf(String id) => topLeft[id]! + const Offset(0, _tile / 2);
  Offset underOf(String id) =>
      topLeft[id]! + const Offset(_nodeWidth / 2, _tile);
}

class FlowChartCanvas extends StatefulWidget {
  const FlowChartCanvas({
    super.key,
    required this.graph,
    required this.look,
    required this.onOpen,
  });

  final CallFlowGraph graph;

  /// What each box is called and what is happening in it.
  final FlowLook look;

  final void Function(FlowNode) onOpen;

  @override
  State<FlowChartCanvas> createState() => _FlowChartCanvasState();
}

class _FlowChartCanvasState extends State<FlowChartCanvas>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );

  /// Jose: "we need to be able to zoom in - out".
  ///
  /// `InteractiveViewer` rather than a scale factor of my own: it already
  /// does pinch, drag and trackpad, and `constrained: false` makes it the
  /// panner too, which is what the two nested scroll views were for. Buttons
  /// as well as gestures, because a canvas that only zooms by pinch does not
  /// look like it zooms at all on a desktop with a mouse.
  final TransformationController _view = TransformationController();

  bool _running = false;

  @override
  void dispose() {
    _pulse.dispose();
    _view.dispose();
    super.dispose();
  }

  static const double _minZoom = 0.4;
  static const double _maxZoom = 2.5;

  double get _zoom => _view.value.getMaxScaleOnAxis();

  void _zoomBy(double factor) {
    final current = _zoom;
    final next = (current * factor).clamp(_minZoom, _maxZoom);
    final applied = next / current;

    // The factor actually applied is derived from the CLAMPED target rather
    // than used raw, so repeated taps cannot accumulate past the ends -- and
    // the existing matrix is kept rather than replaced, so zooming does not
    // throw away a pan somebody just made.
    if ((applied - 1).abs() < 0.0001) return;

    _view.value = _view.value.clone()
      ..scaleByDouble(applied, applied, applied, 1);
  }

  /// Reduce-motion turns the flow off, and that is not only an accessibility
  /// courtesy: a repeating controller never lets `pumpAndSettle` finish, so a
  /// harness that sets the flag also gets a chart that settles.
  void _sync(bool wanted) {
    if (wanted == _running) return;
    _running = wanted;
    if (wanted) {
      _pulse.repeat();
    } else {
      _pulse.stop();
      _pulse.value = 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    final layout = layOutFlow(widget.graph);
    final geometry = _Geometry(layout);
    final paper = FlowCanvas.of(context);

    _sync(!MediaQuery.of(context).disableAnimations);

    return ColoredBox(
      color: paper.ink,
      child: Stack(
        children: [
          Positioned.fill(
            // THE WHEEL WORKS AND IT IS InteractiveViewer THAT DOES IT.
            // Phone flagged that a plain wheel event often pans rather than
            // scales, so I wrote the test, saw it red, and added a Listener of
            // my own -- then neutered that Listener and the test still passed.
            // The wheel was never the defect: the READOUT was, because it was
            // built from setState and the view also moves by wheel, pinch and
            // drag. The Listener came out; a decoy that looks load-bearing is
            // worse than no code at all.
            child: InteractiveViewer(
              transformationController: _view,
              constrained: false,
              minScale: _minZoom,
              maxScale: _maxZoom,
              boundaryMargin: const EdgeInsets.all(120),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
                child: SizedBox(
                  width: geometry.size.width,
                  height: geometry.size.height,
                  child: Stack(
                    children: [
                      // Still: dots, wires, arrows, labels, painted once.
                      Positioned.fill(
                        child: RepaintBoundary(
                          child: CustomPaint(
                            painter: FlowWirePainter(
                              layout: layout,
                              colour: paper.wire,
                              labelColour: paper.muted,
                              paper: paper,
                            ),
                          ),
                        ),
                      ),
                      // Moving: only the dashes, every frame, on their own layer.
                      Positioned.fill(
                        child: RepaintBoundary(
                          child: AnimatedBuilder(
                            animation: _pulse,
                            builder: (context, _) => CustomPaint(
                              painter: FlowWirePainter(
                                layout: layout,
                                colour: paper.wire,
                                labelColour: paper.muted,
                                paper: paper,
                                phase: _pulse.value,
                                flowOnly: true,
                              ),
                            ),
                          ),
                        ),
                      ),
                      for (final p in layout.placed)
                        Positioned(
                          left: geometry.topLeft[p.node.id]!.dx,
                          top: geometry.topLeft[p.node.id]!.dy,
                          width: _nodeWidth,
                          child: _NodeTile(
                            node: p.node,
                            look: widget.look(p.node),
                            isEntry: p.node.id == widget.graph.entry,
                            onTap: () => widget.onOpen(p.node),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          Positioned(
            right: 12,
            bottom: 12,
            // Rebuilt from the CONTROLLER rather than from setState, because
            // the view also moves by pinch, drag and wheel -- none of which go
            // through the buttons. Without this the readout says 100% while
            // the canvas is at 160%, which is worse than having no readout.
            child: AnimatedBuilder(
              animation: _view,
              builder: (context, _) => _ZoomBar(
                paper: paper,
                zoom: _zoom,
                onIn: () => _zoomBy(1.25),
                onOut: () => _zoomBy(0.8),
                onReset: () => _view.value = Matrix4.identity(),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Zoom, and the number, because a canvas that has been zoomed and cannot say
/// so leaves somebody wondering whether the chart is wrong or the view is.
class _ZoomBar extends StatelessWidget {
  const _ZoomBar({
    required this.paper,
    required this.zoom,
    required this.onIn,
    required this.onOut,
    required this.onReset,
  });

  final FlowCanvas paper;
  final double zoom;
  final VoidCallback onIn;
  final VoidCallback onOut;
  final VoidCallback onReset;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: paper.tile,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: paper.tileLine),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            onPressed: onOut,
            icon: const Icon(Icons.remove),
            iconSize: 16,
            color: paper.muted,
            tooltip: 'Zoom out',
          ),
          GestureDetector(
            onTap: onReset,
            child: Tooltip(
              message: 'Reset the view',
              child: Text(
                '${(zoom * 100).round()}%',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: paper.text,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
          IconButton(
            onPressed: onIn,
            icon: const Icon(Icons.add),
            iconSize: 16,
            color: paper.muted,
            tooltip: 'Zoom in',
          ),
        ],
      ),
    );
  }
}

/// One node: an icon tile with its name under it, the way his reference draws
/// them.
class _NodeTile extends StatelessWidget {
  const _NodeTile({
    required this.node,
    required this.look,
    required this.isEntry,
    this.onTap,
  });

  final FlowNode node;

  /// Given, not derived. `id` and `kind` are different namespaces that happen
  /// to coincide on exactly one Phone node, so a kind comparison works today
  /// and goes silent the moment either side is renamed. Found by Phone.
  final bool isEntry;
  final VoidCallback? onTap;
  final FlowNodeLook look;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final paper = FlowCanvas.of(context);

    // Something is happening HERE, right now. A quiet reading is a real answer
    // and is drawn as one, but it does not earn a ring.
    final holding = look.alert;

    final edge = node.isDead
        ? scheme.error
        : node.isSkipped
        ? paper.amber
        : isEntry
        ? paper.accent
        : paper.tileLine;

    return Tooltip(
      message: node.detail,
      child: InkWell(
        // Opens the settings for this box rather than a dialog of its own
        // text. The detail is still readable -- the drawer leads with it --
        // and a tap that only showed you what you could already see was the
        // weakest thing on the canvas.
        onTap: onTap,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: _tile,
              height: _tile,
              decoration: BoxDecoration(
                color: node.isDead
                    ? scheme.error.withValues(alpha: 0.16)
                    : paper.tile,
                borderRadius: BorderRadius.circular(15),
                border: Border.all(
                  color: holding ? paper.accent : edge,
                  width: isEntry || node.isDead || holding ? 2 : 1,
                ),
              ),
              child: Icon(look.icon, size: 22, color: edge),
            ),
            const SizedBox(height: 7),
            Text(
              node.title,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.labelSmall?.copyWith(
                color: paper.text,
                fontWeight: FontWeight.w700,
              ),
            ),
            if (node.isDead || node.isSkipped)
              Text(
                node.isDead ? 'Dead end' : 'Does nothing',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: edge,
                  fontWeight: FontWeight.w800,
                  fontSize: 9,
                ),
              )
            // Jose: "see Live Calls flowing through the Node as well". It
            // replaces the kind word rather than joining it, because on a
            // queue tile "Queue" is the least interesting true thing on the
            // screen once somebody is actually holding.
            else if (look.note.isNotEmpty)
              Text(
                look.note,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: look.alert ? paper.accent : paper.muted,
                  fontWeight: FontWeight.w800,
                  fontSize: 9,
                ),
              )
            else if (look.caption.isNotEmpty)
              Text(
                look.caption,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: paper.muted,
                  fontSize: 9,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// The wires: an arrowhead on every one, and the energy flow he asked for.
///
/// Public so a test can reach it — nothing else in a widget test can see a
/// painter, and the wires are the half of this screen no `find.text` reaches.
class FlowWirePainter extends CustomPainter {
  FlowWirePainter({
    required this.layout,
    required this.colour,
    required this.labelColour,
    this.paper = FlowCanvas.light,
    this.phase = 0,
    this.flowOnly = false,
  }) : _geometry = _Geometry(layout);

  /// Only the travelling dashes: the layer that repaints every frame, over
  /// a still one with everything else (audit 2026-11-27: the whole chart,
  /// dots, wires and labels, repainted at 60 fps while on screen).
  final bool flowOnly;

  final FlowLayout layout;
  final Color colour;
  final Color labelColour;

  /// The canvas palette, so the label plates match the ground they sit on.
  final FlowCanvas paper;

  /// 0..1, driven by the pulse controller. 0 when reduce-motion is on.
  final double phase;

  final _Geometry _geometry;

  /// WHERE A LABEL GOES, and it is two rules rather than one.
  ///
  /// This painter used to put every label at the midpoint of its own wire,
  /// with no idea another label was already there — so wherever wires
  /// converge, which is every line with a queue, they collided. Jose's own
  /// screenshot reads "Ееrybody bese": "Everyone busy" and "Waited too long"
  /// painted at the same point.
  ///
  /// The first fix was also wrong. Labelling near the START assumes a fan
  /// separates early, and a fan-OUT has barely left its source there. Measured
  /// on the gallery: 13px of pitch for 11px-tall text. Labelling a fan-out
  /// near its DESTINATION gives 67px.
  double _labelAt(FlowEdge edge) {
    var out = 0;
    var into = 0;
    for (final e in [...layout.forward, ...layout.back]) {
      if (e.from == edge.from) out++;
      if (e.to == edge.to) into++;
    }
    if (out > 1 && into <= 1) return 0.78;
    if (into > 1 && out <= 1) return 0.22;

    return 0.5;
  }

  @override
  void paint(Canvas canvas, Size size) {
    if (!flowOnly) _dots(canvas, size);

    final pen = Paint()
      ..color = colour
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke;

    // Parallel edges are one wire carrying both keys; the rule and the reason
    // live in `mergeParallel`, beside the layout, where they can be tested
    // without pumping a widget.
    for (final wire in mergeParallel(layout.forward)) {
      final edge = wire.edge;
      if (!_geometry.topLeft.containsKey(edge.from)) continue;
      if (!_geometry.topLeft.containsKey(edge.to)) continue;
      final from = _geometry.rightOf(edge.from);
      final to = _geometry.leftOf(edge.to);
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(
          from.dx + _columnGap * 0.55,
          from.dy,
          to.dx - _columnGap * 0.55,
          to.dy,
          to.dx,
          to.dy,
        );
      _flow(canvas, path, paper.accent, reverse: false);
      if (flowOnly) continue;
      canvas.drawPath(path, pen);
      _arrow(canvas, path, colour);
      _label(canvas, wire.label, path, _labelAt(edge));
    }

    // Back-edges: drawn, never followed. They loop UNDER their source rather
    // than crossing the chart, so a queue returning to the group it came from
    // reads as a short return rather than a wire going the wrong way. The flow
    // on them runs backwards, so a return reads as one without its label.
    for (final edge in layout.back) {
      if (!_geometry.topLeft.containsKey(edge.from)) continue;
      if (!_geometry.topLeft.containsKey(edge.to)) continue;
      final from = _geometry.underOf(edge.from);
      final to = _geometry.underOf(edge.to);
      final dip = math.max(from.dy, to.dy) + 46;
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(from.dx, dip, to.dx, dip, to.dx, to.dy);
      _flow(canvas, path, paper.amber, reverse: true);
      if (flowOnly) continue;
      canvas.drawPath(_dashes(path, dash: 5, gap: 4), pen);
      _arrow(canvas, path, colour);
      _label(canvas, edge.label, path, 0.5);
    }
  }

  void _dots(Canvas canvas, Size size) {
    final dot = Paint()..color = paper.grid;
    for (var x = 0.0; x < size.width; x += 17) {
      for (var y = 0.0; y < size.height; y += 17) {
        canvas.drawCircle(Offset(x, y), 1, dot);
      }
    }
  }

  /// The energy flow: a short bright dash travelling the wire.
  void _flow(Canvas canvas, Path path, Color colour, {required bool reverse}) {
    if (phase == 0) return;
    final travel = reverse ? 1 - phase : phase;
    final pen = Paint()
      ..color = colour
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    for (final metric in path.computeMetrics()) {
      final at = metric.length * travel;
      final start = math.max(0.0, at - 13);
      if (at <= start) continue;
      canvas.drawPath(metric.extractPath(start, at), pen);
    }
  }

  void _arrow(Canvas canvas, Path path, Color colour) {
    for (final metric in path.computeMetrics()) {
      final tip = metric.getTangentForOffset(metric.length);
      if (tip == null) continue;
      final angle = tip.angle;
      final head = Path()
        ..moveTo(tip.position.dx, tip.position.dy)
        ..lineTo(
          tip.position.dx - 7 * math.cos(angle - 0.4),
          tip.position.dy - 7 * math.sin(angle - 0.4),
        )
        ..lineTo(
          tip.position.dx - 7 * math.cos(angle + 0.4),
          tip.position.dy - 7 * math.sin(angle + 0.4),
        )
        ..close();
      canvas.drawPath(head, Paint()..color = colour);
    }
  }

  void _label(Canvas canvas, String text, Path path, double at) {
    if (text.isEmpty) return;

    for (final metric in path.computeMetrics()) {
      final spot = metric.getTangentForOffset(metric.length * at);
      if (spot == null) continue;

      final painter = TextPainter(
        text: TextSpan(
          text: text,
          style: TextStyle(
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: labelColour,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();

      final at0 = spot.position - Offset(painter.width / 2, painter.height + 3);

      // A plate behind the text, so no wire runs through a label.
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(
            at0.dx - 4,
            at0.dy - 1,
            painter.width + 8,
            painter.height + 2,
          ),
          const Radius.circular(4),
        ),
        Paint()..color = paper.ink,
      );
      painter.paint(canvas, at0);

      return;
    }
  }

  Path _dashes(Path source, {required double dash, required double gap}) {
    final out = Path();
    for (final metric in source.computeMetrics()) {
      var at = 0.0;
      while (at < metric.length) {
        final end = (at + dash).clamp(0.0, metric.length);
        if (end > at) out.addPath(metric.extractPath(at, end), Offset.zero);
        at += dash + gap;
      }
    }

    return out;
  }

  @override
  bool shouldRepaint(FlowWirePainter old) =>
      old.layout != layout ||
      old.colour != colour ||
      old.labelColour != labelColour ||
      old.paper != paper ||
      old.phase != phase ||
      old.flowOnly != flowOnly;
}

// ── Phone: the whole flow, top to bottom (Jose: mobile 4) ──────────

/// Built for a phone rather than shrunk onto one.
///
/// The flow runs top to bottom because that is the direction a phone scrolls,
/// so his "expand up and down" becomes "the branches indent". Everything is on
/// one page, which is the complaint about the walk this replaces: you never saw
/// the whole shape, and a fault you had not walked to was invisible.
class FlowChartRibbon extends StatelessWidget {
  const FlowChartRibbon({
    super.key,
    required this.graph,
    required this.look,
    required this.onOpen,
  });

  final CallFlowGraph graph;
  final FlowLook look;
  final void Function(FlowNode) onOpen;

  @override
  Widget build(BuildContext context) {
    final layout = layOutFlow(graph);
    final steps = layout.placed;

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      itemCount: steps.length,
      itemBuilder: (context, i) => _Step(
        node: steps[i].node,
        branches: graph.out(steps[i].node.id),
        graph: graph,
        look: look(steps[i].node),
        lookOf: look,
        last: i == steps.length - 1,
        onOpen: () => onOpen(steps[i].node),
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
    required this.node,
    required this.branches,
    required this.graph,
    required this.last,
    required this.onOpen,
    required this.look,
    required this.lookOf,
  });

  final FlowNode node;
  final List<FlowEdge> branches;
  final CallFlowGraph graph;
  final bool last;
  final VoidCallback onOpen;

  /// This node's own look.
  final FlowNodeLook look;

  /// And the delegate again, for the boxes this one branches TO -- a branch
  /// row wears the icon of its destination, which is a different node.
  final FlowLook lookOf;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final tint = node.isDead
        ? scheme.error
        : node.isSkipped
        ? FlowCanvas.light.amber
        : scheme.primary;

    // Only a branching step lists where it goes. A single next step is implied
    // by the rail, and listing it would say the same thing twice.
    final fan = branches.length > 1 ? branches : const <FlowEdge>[];

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: tint.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(look.icon, size: 14, color: tint),
              ),
              if (!last)
                Expanded(
                  child: Container(
                    width: 2,
                    margin: const EdgeInsets.symmetric(vertical: 4),
                    color: scheme.outlineVariant,
                  ),
                ),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: InkWell(
                          onTap: node.edit == null ? null : onOpen,
                          child: Text(
                            node.title,
                            style: theme.textTheme.titleSmall?.copyWith(
                              decoration: node.edit == null
                                  ? null
                                  : TextDecoration.underline,
                            ),
                          ),
                        ),
                      ),
                      if (node.isDead || node.isSkipped) ...[
                        const SizedBox(width: 8),
                        Text(
                          node.isDead ? 'Dead end' : 'Does nothing',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: tint,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ] else if (look.note.isNotEmpty) ...[
                        const SizedBox(width: 8),
                        Text(
                          look.note,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: look.alert
                                ? scheme.primary
                                : scheme.onSurfaceVariant,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ],
                    ],
                  ),
                  if (node.detail.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        node.detail,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ),
                  if (fan.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    DecoratedBox(
                      decoration: BoxDecoration(
                        border: Border.all(color: scheme.outlineVariant),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Column(
                        children: [
                          for (final e in fan)
                            _BranchRow(
                              edge: e,
                              to: graph.node(e.to),
                              lastRow: e == fan.last,
                              lookOf: lookOf,
                            ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _BranchRow extends StatelessWidget {
  const _BranchRow({
    required this.edge,
    required this.to,
    required this.lastRow,
    required this.lookOf,
  });

  final FlowEdge edge;
  final FlowNode? to;
  final bool lastRow;
  final FlowLook lookOf;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final bad = to?.isDead ?? false;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
      decoration: BoxDecoration(
        border: lastRow
            ? null
            : Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          if (edge.label.isNotEmpty) ...[
            DecoratedBox(
              decoration: BoxDecoration(
                color: bad
                    ? scheme.error.withValues(alpha: 0.1)
                    : scheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(5),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                child: Text(
                  edge.label,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: bad ? scheme.error : scheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 9),
          ],
          // The icon of what it REACHES: at a glance the menu reads as "three
          // groups and a voicemail" without a word being read.
          Icon(
            to == null ? flowUnknownIcon : lookOf(to!).icon,
            size: 14,
            color: bad ? scheme.error : scheme.onSurfaceVariant,
          ),
          const SizedBox(width: 7),
          Expanded(
            child: Text(
              to?.title ?? edge.to,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── the payload ────────────────────────────────────────────────────
