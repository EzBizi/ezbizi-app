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

  /// The state that produces no complaint.
  ///
  /// A `skipped` node is reachable from nothing a caller walks, so the search
  /// never reaches it. Dropping it would be the screen hiding the one thing
  /// nobody else reports — the caller does not know they missed a menu and the
  /// owner does not know theirs is skipped.
  test('a node no caller can reach is still placed, past the end', () {
    final layout = layOutFlow(graph(
      [
        ['caller'], ['arrival'], ['greeting', 'skipped'],
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
