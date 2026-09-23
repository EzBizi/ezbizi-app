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

    // Fit-to-canvas on a SQUARE field: the full width, and a height to match.
    // This used to assert `375 * 28 / 40` — the grid's own aspect ratio, which
    // is what the canvas drew before the field was squared.
    final size = tester.getSize(find.byType(FloorPlanCanvas));
    expect(size.width, 375);
    expect(size.height, closeTo(375, 0.01));

    final canvas = tester.getRect(find.byType(FloorPlanCanvas));
    final edge = tester.getRect(
      find.byKey(const ValueKey('floor-plan-node-n-edge')),
    );
    expect(edge.right, lessThanOrEqualTo(canvas.right + 0.01));
    expect(edge.bottom, lessThanOrEqualTo(canvas.bottom + 0.01));
  });

  testWidgets('the whole plan fits the space it is given, at default zoom',
      (tester) async {
    // The shape that broke: a grid TALLER than it is wide, in a pane wider
    // than it is tall. `cell = width / cols` fitted the width and let the
    // height fall out of the aspect ratio, so 30x40 in a 1000x800 pane drew a
    // board over 1300px tall and the lower rows were simply off screen — with
    // nothing on the map saying so. Every other test in this file passed
    // against that, because none of them asked how big the board came out.
    await _pumpCanvas(
      tester,
      layout: _layout(cols: 30, rows: 40, nodes: const []),
      status: const {},
      size: const Size(1000, 800),
    );

    final board = tester.getSize(_boardFinder());
    final allowed = 800 * FloorPlanCanvas.viewportFraction;

    expect(board.height, lessThanOrEqualTo(allowed + 1),
        reason: 'The plan is taller than the room it was given, so its lower '
            'rows are off screen. Fitting the width alone is what did this.');
    expect(board.width, lessThanOrEqualTo(1001));

    // Control: it did not collapse to nothing either — a zero-height board
    // satisfies the assertion above and shows no map at all.
    expect(board.height, greaterThan(50));
  });

  testWidgets('zooming in draws a bigger board than the fit', (tester) async {
    await _pumpCanvas(
      tester,
      layout: _layout(cols: 30, rows: 40, nodes: const []),
      status: const {},
      size: const Size(1000, 800),
    );
    final fitted = tester.getSize(_boardFinder());

    await _pumpCanvas(
      tester,
      layout: _layout(cols: 30, rows: 40, nodes: const []),
      status: const {},
      size: const Size(1000, 800),
      zoom: 2.0,
    );
    final zoomed = tester.getSize(_boardFinder());

    expect(zoomed.height, greaterThan(fitted.height),
        reason: 'Zoom must change the DRAWN size, or the buttons move a '
            'percentage and nothing else.');
    expect(zoomed.width, greaterThan(fitted.width));
  });

  testWidgets('the field is square whatever shape the grid is', (tester) async {
    // Jose, 2026-09-23: "the map canvas should be 1:1 square." Both shapes,
    // because squaring by padding the SHORT side is right and squaring by
    // cropping the long one would pass a test that only ever saw one of them.
    for (final grid in const [(30, 40), (40, 28)]) {
      await _pumpCanvas(
        tester,
        layout: _layout(cols: grid.$1, rows: grid.$2, nodes: const []),
        status: const {},
        size: const Size(1000, 800),
      );

      final board = tester.getSize(_boardFinder());

      expect(board.width, closeTo(board.height, 0.5),
          reason: 'A ${grid.$1}x${grid.$2} grid still draws on a square field.');
      expect(board.height, greaterThan(50), reason: 'Control: not collapsed.');
    }
  });

  testWidgets('a wall point lands on the cell under the finger once scrolled',
      (tester) async {
    // THE BUG Jose reported: "zooming in out messes up the drawing wall
    // system." The gesture surface wrapped the SCROLL FRAME, and
    // `localPosition` inside a scroll view is measured from the viewport and
    // carries no scroll offset — so once the board was zoomed and scrolled,
    // every tap reported the cell that happened to be at that spot on screen
    // BEFORE scrolling.
    //
    // Asserted as "the same screen point reports a different cell after
    // scrolling" rather than as an arithmetic expectation, because the second
    // form would just be the implementation typed twice.
    final points = <(int, int)>[];

    Future<void> pump() => _pumpCanvas(
          tester,
          layout: _layout(cols: 40, rows: 28, nodes: const []),
          status: const {},
          size: const Size(1000, 800),
          zoom: 3.0,
          wallMode: true,
          onWallPoint: (col, row) => points.add((col, row)),
        );

    await pump();

    final surface = find.byKey(const Key('floor-plan-wall-surface'));
    expect(surface, findsOneWidget,
        reason: 'Control: wall mode is actually on, so a tap has somewhere to '
            'land and the assertions below are about the coordinate.');

    // A point a little in from the frame's left edge, held FIXED on screen.
    final frame = tester.getTopLeft(find.byType(FloorPlanCanvas));
    final spot = frame + const Offset(40, 40);

    await tester.tapAt(spot);
    await tester.pump();

    final horizontal = find.byWidgetPredicate(
      (w) => w is SingleChildScrollView && w.scrollDirection == Axis.horizontal,
    );
    expect(horizontal, findsOneWidget,
        reason: 'Control: zoomed past the fit, the board really does scroll — '
            'without that there is no offset for this test to be about.');

    // Dragged in steps like a real pointer. One giant move with the slop
    // turned off never reached the scrollable, and the test then proved only
    // that a board which had not moved reported the cell it had not moved to.
    final pan = await tester.startGesture(tester.getCenter(horizontal));
    await pan.moveBy(const Offset(-40, 0));
    await tester.pump();
    await pan.moveBy(const Offset(-360, 0));
    await tester.pump();
    await pan.up();
    await tester.pumpAndSettle();

    await tester.tapAt(spot);
    await tester.pump();

    expect(points, hasLength(2));
    expect(points[1].$1, greaterThan(points[0].$1),
        reason: 'The board was scrolled left, so the same point on screen is '
            'now over a LATER column. Reporting the same column means the tap '
            'was measured against the viewport instead of the board.');
  });

  testWidgets('dragging a wall handle reports a whole-wall shift in cells',
      (tester) async {
    // Jose, 2026-09-23: "i want to be able to drag walls as well to
    // reposition them."
    final moves = <(int, int, int)>[];

    await _pumpWallCanvas(
      tester,
      walls: const [
        {'x1': 2, 'y1': 2, 'x2': 6, 'y2': 2},
      ],
      onWallMoved: (i, dCol, dRow) => moves.add((i, dCol, dRow)),
    );

    // 20 cells across a 400px board: one cell is 20px, so 60px is three.
    await tester.drag(
      find.byKey(const ValueKey('floor-plan-wall-0')),
      const Offset(60, 40),
      touchSlopX: 0,
      touchSlopY: 0,
      // The finder is the Positioned; the pointer lands on the Transform
      // inside it, which is the handle. Same widget, different render object.
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(moves, [(0, 3, 2)],
        reason: 'The shift is in CELLS, snapped — the tab applies it to both '
            'ends, so pixels must not reach it.');
  });

  testWidgets('a wall dragged past the edge keeps its length', (tester) async {
    // The reason the SHIFT is clamped rather than each end: clamping the ends
    // independently lets a wall dragged into a corner shorten or rotate, which
    // is not what dragging a wall means.
    final moves = <(int, int, int)>[];

    await _pumpWallCanvas(
      tester,
      walls: const [
        {'x1': 2, 'y1': 2, 'x2': 6, 'y2': 2},
      ],
      onWallMoved: (i, dCol, dRow) => moves.add((i, dCol, dRow)),
    );

    // Hard left: the wall's near end is on column 2, so it can move -2 and no
    // further, whatever the pointer did.
    await tester.drag(
      find.byKey(const ValueKey('floor-plan-wall-0')),
      const Offset(-400, 0),
      touchSlopX: 0,
      touchSlopY: 0,
      // The finder is the Positioned; the pointer lands on the Transform
      // inside it, which is the handle. Same widget, different render object.
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(moves, [(0, -2, 0)]);
  });
}

/// The canvas in wall mode on a board of a known size, so a drag in pixels has
/// an unambiguous answer in cells. A 20x20 grid in a bounded 400px box is a
/// 20px cell.
Future<void> _pumpWallCanvas(
  WidgetTester tester, {
  required List<Map<String, dynamic>> walls,
  required void Function(int index, int dCol, int dRow) onWallMoved,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Center(
          child: SizedBox(
            width: 400,
            height: 400,
            child: FloorPlanCanvas(
              layout: _layout(cols: 20, rows: 20, walls: walls, nodes: const []),
              status: const {},
              selectedNodeId: null,
              onSelectNode: (_) {},
              wallMode: true,
              onWallPoint: (_, _) {},
              onSelectWall: (_) {},
              onWallMoved: onWallMoved,
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
}

/// The board itself, found by its OWN painter.
///
/// `find.byType(CustomPaint).first` measured 800 — the whole window — because
/// Flutter's own widgets paint with CustomPaint too and one of them came
/// first in the tree. A size assertion against an ancestor is a healthy
/// instrument aimed one level beside its subject: it answers confidently and
/// about something else.
Finder _boardFinder() => find.byWidgetPredicate(
      (w) => w is CustomPaint && w.painter is FloorPlanPainter,
    );

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
  double zoom = 1.0,
  bool wallMode = false,
  void Function(int col, int row)? onWallPoint,
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
            zoom: zoom,
            selectedNodeId: selectedNodeId,
            onSelectNode: onSelectNode ?? (_) {},
            wallMode: wallMode,
            onWallPoint: onWallPoint,
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
