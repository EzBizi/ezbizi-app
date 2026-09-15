import 'package:ezbizi_client/screens/phone/call_flow_chart.dart';
import 'package:flutter_test/flutter_test.dart';

/// Laying a call flow out as a picture (Jose's desktop 1, 2026-09-15).
///
/// ── The one that matters ─────────────────────────────────────────
///
/// The graph CYCLES. `queue → answer → queue` is real and Phone measured it on
/// **eleven of the thirteen production lines**, so it is the ordinary shape
/// rather than an edge case. A depth-first walk follows it round forever, and
/// a layering that treats the return as progress grows the chart by a column
/// per retry.
///
/// So `a return to an earlier column is a back-edge, not another column` is
/// the load-bearing test here. Everything else asserts arrangement.
void main() {
  CallFlowGraph graph(List<List<String>> nodes, List<List<String>> edges) =>
      CallFlowGraph(
        nodes: [
          for (final n in nodes)
            FlowNode(
              id: n[0],
              kind: n[0],
              title: n[0],
              detail: '',
              state: n.length > 1 ? n[1] : 'ok',
            ),
        ],
        edges: [
          for (final e in edges)
            FlowEdge(from: e[0], to: e[1], label: e.length > 2 ? e[2] : ''),
        ],
      );

  test('a return to an earlier column is a back-edge, not another column', () {
    // caller → arrives → group → queue, and the queue returns to the group.
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['group'], ['queue'],
      ],
      [
        ['caller', 'arrival'], ['arrival', 'group'],
        ['group', 'queue', 'Everyone busy'], ['queue', 'group', 'try again'],
      ],
    ));

    expect(
      layout.columns,
      4,
      reason:
          'Four nodes, four columns. A fifth would mean the return was laid '
          'out as progress, and the chart grows a column per retry forever.',
    );
    expect(layout.back.map((e) => '${e.from}->${e.to}'), ['queue->group']);
    expect(layout.forward.length, 3);
    expect(layout.find('queue')!.column, 3);
  });

  test('a column is the fewest keypresses from the caller', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['menu'], ['sales'], ['service'],
      ],
      [
        ['caller', 'menu'],
        ['menu', 'sales', 'Press 1'], ['menu', 'service', 'Press 2'],
      ],
    ));

    expect(layout.find('caller')!.column, 0);
    expect(layout.find('menu')!.column, 1);
    // Both menu destinations share a column and stack by row, which is what
    // makes a menu read as one fan rather than a staircase.
    expect(layout.find('sales')!.column, 2);
    expect(layout.find('service')!.column, 2);
    expect(layout.find('sales')!.row, 0);
    expect(layout.find('service')!.row, 1);
  });

  /// The state that produces no complaint — laid out where it actually goes.
  ///
  /// **This test's fixture used to be an orphan**, because the description I
  /// built to said a skipped node "is reachable from nothing a caller walks".
  /// Phone read their own renderer afterwards and it emits the opposite:
  /// `$add('menu', …, 'skipped')` is linked in with `arrival -> menu` and
  /// `menu -> answer`. A caller DOES pass through the point and hears nothing,
  /// which is the whole defect.
  ///
  /// So the real shape is an ordinary column in the middle of the chain, and
  /// that is the right drawing: a reader sees callers passing through a step
  /// that does nothing, rather than finding it parked off the end looking
  /// unrelated to the flow it is part of.
  test('a skipped step sits in the chain, not off the end', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['menu', 'skipped'], ['answer'],
        ['voicemail'],
      ],
      [
        ['caller', 'arrival'], ['arrival', 'menu'], ['menu', 'answer'],
        ['answer', 'voicemail'],
      ],
    ));

    expect(
      layout.find('menu')!.column,
      2,
      reason:
          'Between Arrival and Answer, where a caller passes through it. Past '
          'the end (column 5) would be the old wrong belief drawn.',
    );
    expect(layout.columns, 5);
    expect(layout.back, isEmpty);
  });

  /// A node the server's contract says cannot exist: unreachable, no edge in.
  ///
  /// Kept as a test of the GUARD rather than of `skipped`, which is what it
  /// was mistaken for. Without the guard such a node is dropped from `placed`
  /// and disappears from the one screen anybody would notice it on.
  test('a node the search cannot reach is placed anyway, not dropped', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['greeting'],
      ],
      [
        ['caller', 'arrival'],
      ],
    ));

    expect(layout.placed.length, 3, reason: 'Nothing may be dropped.');
    expect(
      layout.find('greeting')!.column,
      2,
      reason: 'Past the deepest reachable node, not in among them.',
    );
  });

  test('the counts are what the screen leads with', () {
    final g = graph(
      [
        ['caller'], ['menu'], ['sales'], ['service', 'dead'],
        ['forward', 'dead'], ['greeting', 'skipped'],
      ],
      [
        ['caller', 'menu'], ['menu', 'sales'], ['menu', 'service'],
        ['menu', 'forward'],
      ],
    );

    expect(g.deadCount, 2);
    expect(g.skippedCount, 1);
  });

  group('two keys reaching one team', () {
    test('are one wire carrying both keys, not two wires on one curve', () {
      final wires = mergeParallel(const [
        FlowEdge(from: 'menu', to: 'g1', label: 'Press 1'),
        FlowEdge(from: 'menu', to: 'g2', label: 'Press 2'),
        FlowEdge(from: 'menu', to: 'g2', label: 'No key pressed'),
      ]);

      expect(
        wires.length,
        2,
        reason: 'Three edges, two destinations. Two edges with identical '
            'endpoints draw on exactly the same curve, so drawing both is two '
            'labels at one point -- the collision this chart was just fixed '
            'for, arriving by another door.',
      );

      final joined = wires.firstWhere((w) => w.edge.to == 'g2');

      expect(joined.label, 'Press 2 \u00b7 No key pressed');
      expect(
        wires.firstWhere((w) => w.edge.to == 'g1').label,
        'Press 1',
        reason: 'A wire with one key must not gain a separator.',
      );
    });

    test('an unlabelled edge contributes nothing to the join', () {
      final wires = mergeParallel(const [
        FlowEdge(from: 'a', to: 'b', label: ''),
        FlowEdge(from: 'a', to: 'b', label: 'Press 9'),
      ]);

      expect(wires.single.label, 'Press 9');
    });
  });

  /// Live counts (Jose: *"see Live Calls flowing through the Node as well"*).
  ///
  /// Three rules that are easy to get individually right and collectively
  /// wrong, which is why they are one method rather than three call sites.
  group('what a node may say about right now', () {
    const queueNode = FlowNode(
      id: 'queue-g1',
      kind: 'queue',
      title: 'Hold for Sales',
      detail: '',
      state: 'ok',
      ref: 'g1',
    );
    const menuNode = FlowNode(
      id: 'menu',
      kind: 'menu',
      title: 'Menu',
      detail: '',
      state: 'ok',
    );

    test('a queue Twilio told us about says its number', () {
      const live = CallFlowLive(
        onLine: 2,
        queuesKnown: true,
        queues: {'g1': QueueLive(waiting: 4, averageWaitSeconds: 73)},
      );

      expect(live.forNode(queueNode)!.waiting, 4);
      expect(live.forNode(queueNode)!.averageWaitSeconds, 73);
    });

    /// A Twilio queue resource exists only once it has been used, so a group
    /// that has never held anybody has no resource. Phone measured it: that
    /// account has three queueing groups and one queue.
    test('a queue absent from a KNOWN answer is zero, not unknown', () {
      const live = CallFlowLive(onLine: 0, queuesKnown: true, queues: {});

      expect(live.forNode(queueNode)?.waiting, 0);
    });

    /// The defect Phone found in their own first version: it returned an
    /// empty map on failure, which is also what a quiet afternoon looks like
    /// -- a row of confident zeroes at the exact moment it has no idea.
    test('an answer we could not get is NOT zero', () {
      const live = CallFlowLive(onLine: 0, queuesKnown: false, queues: {});

      expect(
        live.forNode(queueNode),
        isNull,
        reason: 'Zero here would draw "nobody is waiting" when the truth is '
            '"we could not ask", and those are different facts.',
      );
    });

    test('a node that is not a queue never has a count', () {
      const live = CallFlowLive(
        onLine: 9,
        queuesKnown: true,
        queues: {'g1': QueueLive(waiting: 4, averageWaitSeconds: 73)},
      );

      expect(
        live.forNode(menuNode),
        isNull,
        reason: 'A call in the menu, ringing a group and holding are all '
            '"ringing" on the row. A number here would be invented.',
      );
    });

    test('the payload parses, keyed by ring group rather than node', () {
      final live = CallFlowLive.fromJson(const {
        'id': 'n-1',
        'on_line': 2,
        'queues': {
          'g1': {'waiting': 4, 'average_wait_seconds': 73},
        },
        'queues_known': true,
      })!;

      expect(live.onLine, 2);
      expect(
        live.forNode(queueNode)!.waiting,
        4,
        reason: 'The join is node.ref -> queues[key]. Keying by node id would '
            'mean rebuilding the whole graph on every ten-second poll.',
      );
    });

    test('a payload with no queues_known is treated as not known', () {
      final live = CallFlowLive.fromJson(const {'on_line': 1})!;

      expect(live.queuesKnown, isFalse);
      expect(live.forNode(queueNode), isNull);
    });
  });

  group('parsing', () {
    test('a row with no graph is not a graph', () {
      expect(CallFlowGraph.fromJson(null), isNull);
      expect(CallFlowGraph.fromJson({'nodes': 'nope'}), isNull);
      expect(CallFlowGraph.fromJson({'nodes': [], 'edges': []}), isNull);
    });

    test('a state this build has never heard of is not a fault', () {
      // A server newer than this client. It is drawn plainly rather than
      // coloured as a failure — inventing a verdict from an unknown word is
      // the confident-wrong-cause defect, and the counts must not move.
      final g = CallFlowGraph.fromJson({
        'nodes': [
          {'id': 'caller', 'kind': 'caller', 'title': 'Caller', 'state': 'ok'},
          {'id': 'x', 'kind': 'x', 'title': 'X', 'state': 'marvellous'},
        ],
        'edges': [
          {'from': 'caller', 'to': 'x'},
        ],
      })!;

      expect(g.node('x')!.isDead, isFalse);
      expect(g.node('x')!.isSkipped, isFalse);
      expect(g.deadCount, 0);
      expect(g.skippedCount, 0);
    });
  });

  /// THE SHAPE CHANGED UNDER ME, 2026-09-15 (`5c033dda`).
  ///
  /// Jose read the chart and asked why "everyone busy" appeared to come out of
  /// the fifth menu key when it applies to the whole group. He was reading the
  /// drawing correctly and the drawing was wrong, so Phone re-measured their own
  /// renderer against the live line and changed two things:
  ///
  ///   * **one node per GROUP, not per key** — a second key reaching the same
  ///     team is a second EDGE into one box, so a node can now have several
  ///     inbound edges carrying different labels;
  ///   * **every queueing group has its own queue**, `queue-<groupNodeId>` —
  ///     three queueing groups is three `group ⇄ queue` pairs.
  ///
  /// The back-edge rule was written and tuned when a line had exactly one
  /// queue. This is the test that says it still holds at three.
  test('three queueing groups are three back-edges, not three more columns', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['menu'],
        ['g1'], ['g2'], ['g3'],
        ['q1'], ['q2'], ['q3'],
      ],
      [
        ['caller', 'arrival'], ['arrival', 'menu'],
        ['menu', 'g1', 'Press 1'],
        ['menu', 'g2', 'Press 2'],
        ['menu', 'g3', 'Press 3'],
        // The second route into a box Jose asked about: same target, another
        // label, and it must not become a second node or another column.
        ['menu', 'g2', 'No key pressed'],
        ['g1', 'q1', 'Everyone busy'], ['q1', 'g1', 'try again'],
        ['g2', 'q2', 'Everyone busy'], ['q2', 'g2', 'try again'],
        ['g3', 'q3', 'Everyone busy'], ['q3', 'g3', 'try again'],
      ],
    ));

    expect(
      layout.back.map((e) => '${e.from}->${e.to}').toList(),
      ['q1->g1', 'q2->g2', 'q3->g3'],
      reason: 'One return per queue. Fewer means a queue was laid out as '
          'progress and the chart grows a column per retry.',
    );
    expect(
      layout.columns,
      5,
      reason: 'caller, arrival, menu, the groups, the queues. Three separate '
          'queues share a column because they are the same distance from the '
          'caller, not three columns in a row.',
    );
    expect(
      layout.placed.length,
      9,
      reason: 'Nine nodes in, nine placed. A second edge into g2 must not '
          'place it twice.',
    );
    expect(layout.find('g2')!.column, 3);
    expect(
      layout.find('q1')!.column,
      layout.find('q3')!.column,
      reason: 'Every queue is one step past its group, so they share a column.',
    );
  });

  /// The four-node line, which is two of the thirteen: a spare number with no
  /// ring group, so every caller goes straight to voicemail.
  test('the smallest real line lays out without a branch', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['nobody', 'dead'], ['voicemail'],
      ],
      [
        ['caller', 'arrival'], ['arrival', 'nobody'], ['nobody', 'voicemail'],
      ],
    ));

    expect(layout.columns, 4);
    expect(layout.back, isEmpty);
    expect(layout.placed.every((p) => p.row == 0), isTrue);
  });
}
