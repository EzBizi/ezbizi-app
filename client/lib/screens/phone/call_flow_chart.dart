/// The call flow as a graph, and the layering that turns it into a picture.
///
/// Split out of the pane so the part with actual logic can be tested without
/// pumping a widget: parsing, layering, and the back-edge rule are decisions,
/// and the rest of that file is arrangement.
///
/// The server builds the graph (`TwimlRenderer::graph()`); nothing here infers
/// structure. Two facts it guarantees, measured and pinned on that side, which
/// this file is allowed to rely on:
///
///   * exactly one entry node, always `caller` — which is what makes Jose's
///     "Caller ---- (what happens)" literal;
///   * every node reachable from it and no edge dangling, so there is no
///     defensive drawing for orphans anywhere below.
library;

import 'dart:math' as math;

/// One box. `state` is the server's, and the two that are not `ok` are the
/// reason the screen exists rather than decoration on it.
class FlowNode {
  const FlowNode({
    required this.id,
    required this.kind,
    required this.title,
    required this.detail,
    required this.state,
  });

  final String id;
  final String kind;
  final String title;
  final String detail;

  /// `ok` | `dead` | `skipped`.
  ///
  /// `dead` — a caller CAN reach this and nothing useful happens: an option
  /// pointing at a number nobody set, a ring group with nobody in it.
  ///
  /// `skipped` — configured and never reached at all. Nobody complains about
  /// one of these, because the caller does not know they missed a menu and the
  /// owner does not know theirs is skipped.
  final String state;

  bool get isDead => state == 'dead';
  bool get isSkipped => state == 'skipped';

  static FlowNode fromJson(Map<String, dynamic> json) => FlowNode(
    id: '${json['id']}',
    kind: '${json['kind'] ?? ''}',
    title: '${json['title'] ?? ''}',
    detail: '${json['detail'] ?? ''}',
    state: '${json['state'] ?? 'ok'}',
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
  CallFlowGraph({required this.nodes, required this.edges})
    : _byId = {for (final n in nodes) n.id: n};

  final List<FlowNode> nodes;
  final List<FlowEdge> edges;
  final Map<String, FlowNode> _byId;

  static const String entryId = 'caller';

  FlowNode? node(String id) => _byId[id];

  /// The two counts the screen leads with.
  ///
  /// Led with rather than left to the drawing, because a chart that draws a
  /// dead branch identically to a live one is prettier than the list and less
  /// useful — and on the phone layout, where nothing is visible until you have
  /// walked to it, this is the ONLY thing that answers "is any of this broken?"
  int get deadCount => nodes.where((n) => n.isDead).length;

  int get skippedCount => nodes.where((n) => n.isSkipped).length;

  List<FlowEdge> out(String id) => [for (final e in edges) if (e.from == id) e];

  static CallFlowGraph? fromJson(Map<String, dynamic>? json) {
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
    );
  }
}

/// A node placed in the picture: which column, which row within it.
class PlacedNode {
  const PlacedNode({required this.node, required this.column, required this.row});

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
/// Breadth-first from [CallFlowGraph.entryId], so a node's column is the
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

  if (graph.node(CallFlowGraph.entryId) != null) {
    column[CallFlowGraph.entryId] = 0;
    order.add(CallFlowGraph.entryId);
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

  // A `skipped` node is reachable from nothing a caller walks, so the search
  // above never assigns it a column. It is not dropped: it goes one column
  // past the end, where it reads as what it is rather than floating in the
  // middle of the picture looking like a mistake in the drawing.
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
