import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The call flow chart pane (Jose: desktop 1, mobile 6).
///
/// The counts are the load-bearing assertion in BOTH layouts, and they matter
/// more on the phone: the walk shows one node at a time, so until somebody has
/// walked to a dead end nothing on screen says the line is broken. The count is
/// the only thing answering "is any of this broken?" at a glance, which is why
/// it is asserted at phone width rather than only where it is easy to see.
void main() {
  testWidgets('wide: the chart draws the nodes and the wires', (tester) async {
    await _pump(tester, width: 1100);

    expect(find.text('Caller'), findsOneWidget);
    expect(find.text('Menu'), findsOneWidget);
    expect(find.text('Sales team'), findsOneWidget);

    // The wires are a painter, and nothing else in a widget test can see one.
    expect(
      find.byWidgetPredicate(
        (w) => w is CustomPaint && w.painter is FlowWirePainter,
      ),
      findsOneWidget,
    );
  });

  testWidgets('wide: a dead end says so on the node itself', (tester) async {
    await _pump(tester, width: 1100);

    expect(find.text('Dead end'), findsOneWidget);
    expect(find.text('Never reached'), findsOneWidget);
  });

  testWidgets('the counts are on screen before anything is read', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 never reached'), findsOneWidget);
  });

  /// The one that matters on a phone.
  testWidgets('narrow: the counts are visible before a single step is taken', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    // The walk starts at the caller, so the dead end is two taps away and
    // invisible. Without this line the screen would say nothing is wrong.
    expect(find.text('Sales team'), findsNothing);
    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 never reached'), findsOneWidget);
  });

  testWidgets('narrow: walking follows the branch that was tapped', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('Caller'), findsOneWidget);
    expect(find.text('What can happen next'), findsOneWidget);

    await tester.tap(find.text('Menu'));
    await tester.pumpAndSettle();

    // The menu's own options, labelled with the key that reaches them.
    expect(find.text('Press 1'), findsOneWidget);
    expect(find.text('Press 2'), findsOneWidget);
    expect(
      find.text('Nobody is in this group'),
      findsOneWidget,
      reason:
          'A dead end is explained where the choice is made, not flagged and '
          'left for somebody to open.',
    );
  });

  testWidgets('narrow: the breadcrumb goes back', (tester) async {
    await _pump(tester, width: 400);

    await tester.tap(find.text('Menu'));
    await tester.pumpAndSettle();
    expect(find.text('Press 1'), findsOneWidget);

    await tester.tap(find.text('Caller').first);
    await tester.pumpAndSettle();

    expect(
      find.text('Press 1'),
      findsNothing,
      reason: 'Back to the start, so the menu keys are no longer on offer.',
    );
  });

  /// A painter is not repainted for free; a stale one draws yesterday's wires
  /// under today's boxes.
  group('the wires repaint when the flow changes', () {
    FlowWirePainter painter(CallFlowGraph g) => FlowWirePainter(
      layout: layOutFlow(g),
      colour: const Color(0xFF000000),
      labelColour: const Color(0xFF000000),
    );

    final one = CallFlowGraph(
      nodes: const [
        FlowNode(id: 'caller', kind: 'caller', title: 'C', detail: '',
            state: 'ok'),
        FlowNode(id: 'b', kind: 'b', title: 'B', detail: '', state: 'ok'),
      ],
      edges: const [FlowEdge(from: 'caller', to: 'b')],
    );

    test('a different flow repaints', () {
      final two = CallFlowGraph(
        nodes: [...one.nodes],
        edges: const [],
      );

      expect(painter(one).shouldRepaint(painter(two)), isTrue);
    });

    test('and the same painter does not', () {
      final p = painter(one);

      expect(p.shouldRepaint(p), isFalse);
    });
  });
}

// ── harness ────────────────────────────────────────────────────────

/// One line, shaped like `TwimlRenderer::graph()` output: a menu whose second
/// option is dead, a queue that returns to the group it came from, and a
/// greeting no caller reaches.
Map<String, dynamic> _graph() => {
  'nodes': [
    {'id': 'caller', 'kind': 'caller', 'title': 'Caller',
      'detail': 'Somebody dials +1 860 555 0142.', 'state': 'ok'},
    {'id': 'menu', 'kind': 'menu', 'title': 'Menu',
      'detail': 'Press 1 or 2.', 'state': 'ok'},
    {'id': 'sales', 'kind': 'ring_group', 'title': 'Sales team',
      'detail': 'Rings 3 people.', 'state': 'ok'},
    {'id': 'service', 'kind': 'ring_group', 'title': 'Service team',
      'detail': 'Nobody is in this group', 'state': 'dead'},
    {'id': 'queue', 'kind': 'queue', 'title': 'Queue',
      'detail': 'Holds and tries again.', 'state': 'ok'},
    {'id': 'greeting', 'kind': 'menu', 'title': 'Greeting',
      'detail': 'A recorded welcome.', 'state': 'skipped'},
  ],
  'edges': [
    {'from': 'caller', 'to': 'menu', 'label': ''},
    {'from': 'menu', 'to': 'sales', 'label': 'Press 1'},
    {'from': 'menu', 'to': 'service', 'label': 'Press 2'},
    {'from': 'sales', 'to': 'queue', 'label': 'Everyone busy'},
    {'from': 'queue', 'to': 'sales', 'label': 'try again'},
  ],
};

Future<void> _pump(WidgetTester tester, {required double width}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/phone/call-flow')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'n-1',
                'e164': '+18605550142',
                'label': 'Main line',
                'graph': _graph(),
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{}', 500,
          headers: {'content-type': 'application/json'});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: CallFlowChartPane(api: api)),
    ),
  );
  await tester.pumpAndSettle();
}
