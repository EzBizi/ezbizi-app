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
    expect(
      find.text('Does nothing'),
      findsOneWidget,
      reason:
          'Not "Never reached" — the caller passes through a skipped step. '
          'The old label sent a reader hunting for a branch nobody can get '
          'to, which is a different fault in a different place.',
    );
  });

  /// The entry node is outlined in the brand colour so the eye starts where
  /// the caller does — Jose's "begins with Caller".
  ///
  /// Pinned because the styling was keyed off `kind` rather than `id` until
  /// Phone read it: two namespaces that coincide on exactly one node, so a
  /// rename on either side would have removed the outline and nothing would
  /// have gone red. A missing outline reads as a design choice.
  testWidgets('the caller is outlined differently from every other node', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    // The tile carries the treatment now, and the icon carries the tile's
    // colour -- the title sits beneath the tile rather than inside it, so
    // there is no Container ancestor of the text to read a border from.
    // .first because the fixture has three ring groups; they share one
    // treatment, so any of them answers the question.
    Color? tintOf(IconData icon) =>
        tester.widget<Icon>(find.byIcon(icon).first).color;

    final caller = tintOf(Icons.call_outlined);

    expect(caller, isNotNull);
    expect(
      tintOf(Icons.groups_outlined),
      isNot(caller),
      reason: 'An ordinary node must not borrow the entry treatment.',
    );
  });

  /// Phone's measurement of their own contract: the SMALLEST graph is two
  /// nodes, not four. A number that was imported and never pointed at EzBizi
  /// returns `caller -> arrival` and stops. Neither the gallery nor the first
  /// round of these tests had it.
  testWidgets('a line pointed nowhere is two nodes and still draws', (
    tester,
  ) async {
    await _pump(tester, width: 1100, graph: {
      'nodes': [
        {'id': 'caller', 'kind': 'caller', 'title': 'Caller',
          'detail': 'Somebody dials.', 'state': 'ok'},
        {'id': 'arrival', 'kind': 'arrival', 'title': 'Arrival',
          'detail': 'Not pointed at EzBizi.', 'state': 'dead'},
      ],
      'edges': [
        {'from': 'caller', 'to': 'arrival', 'label': ''},
      ],
    });

    expect(tester.takeException(), isNull);

    // Named because they are the only assertions here that DISTINGUISH this
    // graph from the default fixture: 'Caller' and '1 dead end' are both true
    // of the six-node one, so a test built on those alone would stay green if
    // the graph argument were ignored and prove nothing.
    expect(find.text('Arrival'), findsOneWidget);
    expect(find.text('Menu'), findsNothing);
    expect(find.text('1 dead end'), findsOneWidget);
  });

  /// Phone's renderer links a skipped step INTO the walk — `arrival -> menu
  /// -> answer` — so it draws between two live nodes rather than off the end.
  /// Nothing on my side covered that shape until they measured their own
  /// output and told me the description I had built to was wrong.
  testWidgets('a skipped step draws in the chain, labelled for what it is', (
    tester,
  ) async {
    await _pump(tester, width: 1100, graph: {
      'nodes': [
        {'id': 'caller', 'kind': 'caller', 'title': 'Caller',
          'detail': 'Somebody dials.', 'state': 'ok'},
        {'id': 'arrival', 'kind': 'arrival', 'title': 'Arrival',
          'detail': 'The line picks up.', 'state': 'ok'},
        {'id': 'menu', 'kind': 'menu', 'title': 'Menu',
          'detail': 'Plays nothing.', 'state': 'skipped'},
        {'id': 'answer', 'kind': 'ring_group', 'title': 'Sales team',
          'detail': 'Rings 3 people.', 'state': 'ok'},
      ],
      'edges': [
        {'from': 'caller', 'to': 'arrival', 'label': ''},
        {'from': 'arrival', 'to': 'menu', 'label': ''},
        {'from': 'menu', 'to': 'answer', 'label': ''},
      ],
    });

    expect(find.text('Menu'), findsOneWidget);
    expect(find.text('Does nothing'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
    expect(
      find.text('1 dead end'),
      findsNothing,
      reason:
          'Distinguishes this fixture from the default six-node one, which '
          'has a dead end; without it the test would pass on either.',
    );
  });

  testWidgets('the counts are on screen before anything is read', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
  });

  /// The one that matters on a phone.
  ///
  /// The counts sit above the flow rather than in it, which is the whole
  /// argument for having them: a `skipped` step is the one state a picture
  /// cannot show by colouring a node, because the node works -- it is the flow
  /// THROUGH it that is empty. Phone's sentence, and the reason this assertion
  /// is at phone width rather than only where it is easy to see.
  testWidgets('narrow: the counts are on screen above the flow', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
  });

  /// Jose picked mobile 4 over the walk that shipped, and this is the whole
  /// difference between them: the walk showed one node at a time, so anything
  /// you had not walked to was invisible.
  testWidgets('narrow: the whole flow is on one page, not one node at a time', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('Caller'), findsOneWidget);
    expect(find.text('Menu'), findsWidgets);
    expect(
      find.text('Sales team'),
      findsWidgets,
      reason:
          'The walk this replaced put Sales team two taps away. If it is '
          'absent here the ribbon is rendering a path rather than the flow.',
    );
  });

  testWidgets('narrow: a branching step lists where each key goes', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('Press 1'), findsOneWidget);
    expect(find.text('Press 2'), findsOneWidget);
    expect(
      find.text('Service team'),
      findsWidgets,
      reason: 'A dead branch is named where the choice is made.',
    );
  });

  /// Jose, 2026-09-15, looking at the built chart: "have it begin in the
  /// middle left, so you can expand up and down when nodes branch".
  ///
  /// The old layout stacked every column from the TOP, so a five-way menu hung
  /// downwards and the spine he actually reads drifted off the bottom. Each
  /// column is now centred on the canvas midline, which is the whole of what
  /// he asked for -- so it is asserted rather than left to look right.
  testWidgets('a branch expands above AND below the caller, not downwards', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    final caller = tester.getCenter(find.byIcon(Icons.call_outlined)).dy;
    final branches = tester
        .widgetList<Icon>(find.byIcon(Icons.groups_outlined))
        .toList();

    expect(branches.length, greaterThan(1), reason: 'Needs a fan to judge.');

    final ys = find.byIcon(Icons.groups_outlined).evaluate().map((e) {
      return tester.getCenter(find.byWidget(e.widget as Icon)).dy;
    }).toList();

    expect(
      ys.any((y) => y < caller),
      isTrue,
      reason: 'Nothing sits above the caller, so the fan still hangs down.',
    );
    expect(
      ys.any((y) => y > caller),
      isTrue,
      reason: 'Nothing sits below the caller either.',
    );
  });

  _pickerGroup();

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

/// The number picker (Jose: picker 8, 2026-09-15 — "we also want a search bar
/// for the number picker").
///
/// The search field is the ask; the GROUPING is what makes it worth building
/// rather than bolting a field onto the dropdown. Thirteen lines and the
/// question is usually "which one is broken", so a line with a fault is the
/// first row before anything is typed.
///
/// These counts need no server work, which I got wrong when I first put this
/// option to Jose: I told him it was blocked on Phone sending a per-line
/// summary. `/phone/call-flow` already answers with every line and its whole
/// graph. I had read the brief rather than the payload.
void _pickerGroup() {
  List<Map<String, dynamic>> twoLines() => [
    {
      'id': 'ok-1',
      'e164': '+18605550100',
      'label': 'Accounts',
      'graph': {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'vm',
            'kind': 'voicemail',
            'title': 'Voicemail',
            'detail': 'Takes a message.',
            'state': 'ok',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'vm', 'label': ''},
        ],
      },
    },
    {
      'id': 'bad-1',
      'e164': '+18605550199',
      'label': 'Service',
      'graph': {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'nobody',
            'kind': 'ring_group',
            'title': 'Service team',
            'detail': 'Nobody is in this group.',
            'state': 'dead',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'nobody', 'label': ''},
        ],
      },
    },
  ];

  testWidgets('the picker leads with the line that has a fault', (
    tester,
  ) async {
    await _pump(tester, width: 1100, lines: twoLines());

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    expect(find.text('NEEDS ATTENTION — 1'), findsOneWidget);
    expect(find.text('1 dead end'), findsWidgets);

    // The order is the feature, not the grouping header. Accounts is healthy
    // and must sit below Service however the list is built.
    // Scoped to the dialog: the button BEHIND it also shows a line name, so
    // an unscoped finder reads the header and answers about the wrong widget.
    Finder inDialog(String text) => find.descendant(
      of: find.byType(AlertDialog),
      matching: find.textContaining(text),
    );

    final faulty = tester.getTopLeft(inDialog('Service').first).dy;
    final healthy = tester.getTopLeft(inDialog('Accounts').first).dy;

    expect(
      faulty,
      lessThan(healthy),
      reason:
          'A healthy line above a broken one is the dropdown this replaced.',
    );
  });

  testWidgets('the picker searches digits, punctuation and all', (
    tester,
  ) async {
    await _pump(tester, width: 1100, lines: twoLines());

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    // Nobody types the punctuation, and half the time you know the number
    // rather than the name.
    await tester.enterText(find.byType(TextField), '0199');
    await tester.pumpAndSettle();

    Finder inDialog(String text) => find.descendant(
      of: find.byType(AlertDialog),
      matching: find.textContaining(text),
    );

    expect(inDialog('Service'), findsWidgets);
    expect(
      inDialog('Accounts'),
      findsNothing,
      reason: 'A filter that keeps the non-matching row is not a filter.',
    );
  });

  testWidgets('a line with no faults is not given a needs-attention group', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1100,
      lines: [twoLines().first],
    );

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    expect(
      find.textContaining('NEEDS ATTENTION'),
      findsNothing,
      reason:
          'The control that distinguishes: if the group were unconditional '
          'the test above would pass on a build that always draws it.',
    );
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

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  Map<String, dynamic>? graph,
  List<Map<String, dynamic>>? lines,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/phone/call-flow')) {
        return http.Response(
          jsonEncode({
            'data':
                lines ??
                [
                  {
                    'id': 'n-1',
                    'e164': '+18605550142',
                    'label': 'Main line',
                    'graph': graph ?? _graph(),
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
      // REDUCE MOTION, and not only for tidiness. The energy flow Jose asked
      // for is an AnimationController on repeat, and a repeating controller
      // schedules frames forever -- `pumpAndSettle` never returns and every
      // test here times out rather than failing on anything real. The chart
      // honours the flag because somebody with vestibular trouble should not
      // be given a pulsing canvas either; the harness gets determinism as a
      // side effect of the accessibility behaviour rather than a switch of
      // its own.
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: true),
        child: child!,
      ),
      home: Scaffold(body: CallFlowChartPane(api: api)),
    ),
  );
  await tester.pumpAndSettle();
}
