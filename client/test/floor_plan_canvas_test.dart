import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/floor_plan_canvas.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The Monitoring floor-plan canvas (Task 6): grid + walls + status-coloured
/// computer tiles, read-only.
///
/// The load-bearing test is the first one. The whole point of this widget is
/// that three machine states are TOLD APART at a glance, so all three are on
/// screen in ONE pump and each is read by its own key: a test that pumped one
/// state at a time and checked "it is green" would pass just as happily if
/// every tile were green. The expected colours are pulled out of the live
/// theme through a BuildContext from the rendered tree — never a hex — so the
/// assertion tracks the tokens rather than restating them, and the three are
/// asserted to differ from each other before any of them is matched, because
/// three equal tokens would satisfy three equality checks and prove nothing.
void main() {
  testWidgets(
    'online, offline and unassigned tiles are three different colours',
    (tester) async {
      final context = await _pumpCanvas(
        tester,
        layout: _layout(nodes: const [
          {'id': 'n-on', 'x': 2, 'y': 2, 'label': 'Front desk'},
          {'id': 'n-off', 'x': 6, 'y': 2, 'label': 'Dispatch'},
          {'id': 'n-free', 'x': 10, 'y': 2, 'label': 'Spare'},
        ]),
        status: const {
          'n-on': {'assigned': true, 'online': true, 'name': 'Ada'},
          'n-off': {'assigned': true, 'online': false, 'name': 'Grace'},
          'n-free': {'assigned': false, 'online': false, 'name': null},
        },
      );

      final scheme = Theme.of(context).colorScheme;
      final online = EzBiziTheme.clockIn(context);
      final offline = scheme.error;
      final unassigned = scheme.outlineVariant;

      // The distinction is only meaningful if the tokens themselves differ.
      expect({online, offline, unassigned}, hasLength(3),
          reason: 'Two of the three status tokens resolve to the same colour, '
              'so the map cannot tell those two states apart no matter what '
              'this widget does.');

      expect(_fillOf(tester, 'n-on'), online);
      expect(_fillOf(tester, 'n-off'), offline);
      expect(_fillOf(tester, 'n-free'), unassigned);
    },
  );

  testWidgets(
    'an unassigned tile shows its label and never a name',
    (tester) async {
      await _pumpCanvas(
        tester,
        layout: _layout(nodes: const [
          {'id': 'n-free', 'x': 3, 'y': 3, 'label': 'Spare'},
        ]),
        // A name beside `assigned:false` is the shape a half-resolved server
        // answer would have; the tile must trust `assigned`, not the leftovers.
        status: const {
          'n-free': {'assigned': false, 'online': true, 'name': 'Ada'},
        },
      );

      expect(find.text('Spare'), findsOneWidget);
      expect(
        find.text('Ada'),
        findsNothing,
        reason: 'Nobody is assigned to that machine, so naming a person on it '
            'claims more than the status says.',
      );

      final context = tester.element(find.byType(FloorPlanCanvas));
      expect(
        _fillOf(tester, 'n-free'),
        Theme.of(context).colorScheme.outlineVariant,
        reason: 'online:true on an unassigned node is a fact about no one — it '
            'must not paint the tile green.',
      );
    },
  );

  testWidgets(
    'tapping a tile reports that node, and not another',
    (tester) async {
      final taps = <String>[];

      await _pumpCanvas(
        tester,
        layout: _layout(nodes: const [
          {'id': 'n-on', 'x': 2, 'y': 2, 'label': 'Front desk'},
          {'id': 'n-off', 'x': 20, 'y': 14, 'label': 'Dispatch'},
        ]),
        status: const {
          'n-on': {'assigned': true, 'online': true, 'name': 'Ada'},
          'n-off': {'assigned': true, 'online': false, 'name': 'Grace'},
        },
        onSelectNode: taps.add,
      );

      await tester.tap(find.byKey(const ValueKey('floor-plan-node-n-off')));
      await tester.pump();

      expect(taps, ['n-off']);

      await tester.tap(find.byKey(const ValueKey('floor-plan-node-n-on')));
      await tester.pump();

      expect(taps, ['n-off', 'n-on'],
          reason: 'Each tile must report its OWN id; two tiles reporting the '
              'same one would look identical here unless both are tapped.');
    },
  );

  testWidgets('the selected tile draws a heavier border', (tester) async {
    await _pumpCanvas(
      tester,
      layout: _layout(nodes: const [
        {'id': 'n-on', 'x': 2, 'y': 2, 'label': 'Front desk'},
        {'id': 'n-off', 'x': 6, 'y': 2, 'label': 'Dispatch'},
      ]),
      status: const {
        'n-on': {'assigned': true, 'online': true, 'name': 'Ada'},
        'n-off': {'assigned': true, 'online': false, 'name': 'Grace'},
      },
      selectedNodeId: 'n-on',
    );

    final selected = _decorationOf(tester, 'n-on').border! as Border;
    final other = _decorationOf(tester, 'n-off').border! as Border;

    expect(selected.top.width, greaterThan(other.top.width));
    expect(
      _fillOf(tester, 'n-on'),
      isNot(_fillOf(tester, 'n-off')),
      reason: 'Selection is drawn in the border precisely so the fill is free '
          'to keep meaning status; if selecting recoloured the fill, an online '
          'machine would stop reading as online the moment you clicked it.',
    );
  });

  testWidgets('every wall segment reaches the painter, diagonals included', (
    tester,
  ) async {
    await _pumpCanvas(
      tester,
      layout: _layout(
        nodes: const [],
        walls: const [
          {'x1': 2, 'y1': 2, 'x2': 12, 'y2': 2},
          {'x1': 3, 'y1': 4, 'x2': 9, 'y2': 11},
        ],
      ),
      status: const {},
    );

    final walls = _painter(tester).walls;

    expect(walls, hasLength(2));
    expect(walls.first, const FloorPlanWall(x1: 2, y1: 2, x2: 12, y2: 2));
    expect(
      walls.last,
      const FloorPlanWall(x1: 3, y1: 4, x2: 9, y2: 11),
      reason: 'A wall is two arbitrary points, so a diagonal must survive '
          'parsing unchanged rather than being snapped to an axis.',
    );

    // The control: the same widget with no walls must hand the painter none.
    // Without it, a painter that fabricated two segments from anywhere would
    // satisfy every assertion above.
    await _pumpCanvas(
      tester,
      layout: _layout(nodes: const [], walls: const []),
      status: const {},
    );

    expect(_painter(tester).walls, isEmpty);
  });

  testWidgets('a 40x28 grid lays out at 375px without overflowing', (
    tester,
  ) async {
    await _pumpCanvas(
      tester,
      size: const Size(375, 800),
      layout: _layout(
        nodes: const [
          // Deliberately on the far edge: a tile positioned at the last column
          // is the one that would hang off the canvas.
          {'id': 'n-edge', 'x': 39, 'y': 27, 'label': 'Corner'},
          {'id': 'n-on', 'x': 0, 'y': 0, 'label': 'Front desk'},
        ],
        walls: const [
          {'x1': 0, 'y1': 0, 'x2': 40, 'y2': 28},
        ],
      ),
      status: const {
        'n-on': {'assigned': true, 'online': true, 'name': 'Ada'},
      },
    );

    expect(tester.takeException(), isNull);

    // Fit-to-canvas: the full width, and the grid's own aspect ratio.
    final size = tester.getSize(find.byType(FloorPlanCanvas));
    expect(size.width, 375);
    expect(size.height, closeTo(375 * 28 / 40, 0.01));

    final canvas = tester.getRect(find.byType(FloorPlanCanvas));
    final edge = tester.getRect(
      find.byKey(const ValueKey('floor-plan-node-n-edge')),
    );
    expect(edge.right, lessThanOrEqualTo(canvas.right + 0.01));
    expect(edge.bottom, lessThanOrEqualTo(canvas.bottom + 0.01));
  });
}

Map<String, dynamic> _layout({
  required List<Map<String, dynamic>> nodes,
  List<Map<String, dynamic>> walls = const [],
  int cols = 40,
  int rows = 28,
}) => {
  'grid': {'cols': cols, 'rows': rows},
  'walls': walls,
  'nodes': nodes,
};

/// The canvas under test, on its own, with the app's real light theme — the
/// colours only mean anything read through it.
Future<BuildContext> _pumpCanvas(
  WidgetTester tester, {
  required Map<String, dynamic> layout,
  required Map<String, dynamic> status,
  String? selectedNodeId,
  void Function(String nodeId)? onSelectNode,
  Size size = const Size(1000, 800),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: FloorPlanCanvas(
            layout: layout,
            status: status,
            selectedNodeId: selectedNodeId,
            onSelectNode: onSelectNode ?? (_) {},
          ),
        ),
      ),
    ),
  );
  await tester.pump();

  return tester.element(find.byType(FloorPlanCanvas));
}

BoxDecoration _decorationOf(WidgetTester tester, String nodeId) {
  final container = tester.widget<Container>(
    find.descendant(
      of: find.byKey(ValueKey('floor-plan-node-$nodeId')),
      matching: find.byType(Container),
    ),
  );
  return container.decoration! as BoxDecoration;
}

Color _fillOf(WidgetTester tester, String nodeId) =>
    _decorationOf(tester, nodeId).color!;

FloorPlanPainter _painter(WidgetTester tester) => tester
    .widget<CustomPaint>(
      find.byWidgetPredicate(
        (widget) => widget is CustomPaint && widget.painter is FloorPlanPainter,
      ),
    )
    .painter! as FloorPlanPainter;
