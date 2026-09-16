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
        label: group
            .map((e) => e.label)
            .where((l) => l.isNotEmpty)
            .join(' · '),
      ),
  ];
}

/// How many callers are holding in one queue, and how long they have waited.
class QueueLive {
  const QueueLive({required this.waiting, required this.averageWaitSeconds});

  final int waiting;
  final int averageWaitSeconds;

  static QueueLive fromJson(Map<String, dynamic> json) => QueueLive(
    waiting: (json['waiting'] as num?)?.toInt() ?? 0,
    averageWaitSeconds: (json['average_wait_seconds'] as num?)?.toInt() ?? 0,
  );
}

/// What is happening on this line right now (Jose: *"see Live Calls flowing
/// through the Node as well"*).
///
/// Exact where callers actually pile up and silent everywhere else, which is
/// Phone's measurement rather than a design compromise: a call in the menu, a
/// call ringing a group and a call holding are all `status = ringing` on the
/// row, and they declined to add a per-step column on purpose — the existing
/// status already goes stale when a final callback never arrives. Their
/// sentence: *"a tile reading 2 calls here about a call that ended on Tuesday
/// is worse than a tile reading nothing."*
class CallFlowLive {
  const CallFlowLive({
    required this.onLine,
    required this.queues,
    required this.queuesKnown,
  });

  /// Ringing or connected on this line now, with a 30-minute ceiling on the
  /// server: a call whose final callback never arrives would otherwise sit at
  /// `ringing` for ever, and the one number on this screen that means "right
  /// now" would be the one that is never right.
  final int onLine;

  /// Keyed by RING GROUP id, which is what [FlowNode.ref] holds.
  final Map<String, QueueLive> queues;

  /// False when Twilio could not be asked.
  ///
  /// It is a flag rather than an empty map because **an empty map is also what
  /// a quiet afternoon looks like**. Phone found their first version returned
  /// empty on failure — a row of confident zeroes at the exact moment it has
  /// no idea.
  final bool queuesKnown;

  /// The count for a node, or null when there is nothing honest to draw.
  ///
  /// All three rules in one place, because they are easy to get individually
  /// right and collectively wrong:
  ///
  ///   * not a queue node — null; no other kind has a count at all;
  ///   * we could not ask — null, NOT zero;
  ///   * asked, and this queue is absent — **zero**. A Twilio queue resource
  ///     exists only once it has been used, so a group that has never held
  ///     anybody has no resource. Absent means empty, and the tile says 0
  ///     rather than dashes.
  QueueLive? forNode(FlowNode node) {
    if (!queuesKnown) return null;
    if (node.ref.isEmpty) return null;

    return queues[node.ref] ??
        const QueueLive(waiting: 0, averageWaitSeconds: 0);
  }

  static CallFlowLive? fromJson(Map<String, dynamic>? json) {
    if (json == null) return null;

    final raw = json['queues'];

    return CallFlowLive(
      onLine: (json['on_line'] as num?)?.toInt() ?? 0,
      queuesKnown: json['queues_known'] as bool? ?? false,
      queues: {
        if (raw is Map)
          for (final entry in raw.entries)
            if (entry.value is Map<String, dynamic>)
              '${entry.key}': QueueLive.fromJson(
                entry.value as Map<String, dynamic>,
              ),
      },
    );
  }
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
