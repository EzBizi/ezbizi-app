import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/general_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Monitoring "General" tab — Task 7b: drawing and removing WALLS, and
/// making, renaming and removing the PLANS themselves.
///
/// Every negative is paired with a positive control, because almost everything
/// here is a mode declining to do something: a tile that does not select, a
/// wall that is not added, a button that is not offered. Each of those reads
/// exactly like a tab that failed to build, so each is asserted beside the case
/// where the same tap does work.
///
/// Request bodies are decoded and read, never counted. A create that posts the
/// wrong scope, or a rename that posts an empty layout — which would erase the
/// plan it is renaming, because the PUT replaces both — are the failures that
/// matter, and neither is visible to a request count.
void main() {
  group('walls', () {
    testWidgets('two taps draw one wall, and without wall mode they draw none', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);

      await _enterWallMode(tester);

      // Deliberately a DIAGONAL: five columns across and three rows down. A
      // wall is two arbitrary points, and an implementation that quietly
      // squared it off would pass an "a wall was added" assertion.
      await _tapCell(tester, 10, 10);
      expect(
        find.text(GeneralTab.wallPendingHint),
        findsOneWidget,
        reason: 'A started wall says so — a half-drawn one is otherwise an '
            'invisible mode.',
      );

      await _tapCell(tester, 15, 13);

      await _save(tester);

      final walls = _savedWalls(fake);
      expect(walls, hasLength(_fixtureWalls.length + 1));
      expect(walls.last, {'x1': 10, 'y1': 10, 'x2': 15, 'y2': 13});
      expect(
        walls.last['x2'] == walls.last['x1'] ||
            walls.last['y2'] == walls.last['y1'],
        isFalse,
        reason: 'The saved wall is the diagonal that was drawn, not an '
            'axis-aligned one snapped out of it.',
      );

      // The control: the same two taps with wall mode OFF add nothing. The save
      // is provoked by an unrelated edit, so the PUT really does happen and the
      // absence is the taps, not a missing request.
      final control = await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);

      await _tapCell(tester, 10, 10);
      await _tapCell(tester, 15, 13);

      await tester.tap(find.byKey(const Key('general-add-computer')));
      await tester.pumpAndSettle();
      await _save(tester);

      expect(_savedWalls(control), hasLength(_fixtureWalls.length));
    });

    testWidgets('a dragged wall is saved with whole-number coordinates', (
      tester,
    ) async {
      // Audit 11-27: moving a wall made its ends doubles; a native build sends
      // 2.0 as "2.0" and the server refused the save.
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);
      await _enterWallMode(tester);

      final canvas = find.byKey(const Key('floor-plan-canvas'));
      final cell = tester.getSize(canvas).width / _gridCols;
      await tester.drag(
        find.byKey(const ValueKey('floor-plan-wall-0')),
        Offset(cell * 2, cell),
        touchSlopX: 0,
        touchSlopY: 0,
        warnIfMissed: false,
      );
      await tester.pumpAndSettle();
      await _save(tester);

      final moved = _savedWalls(fake).first;
      expect(moved, isNot(_fixtureWalls.first), reason: 'the wall did move');
      for (final v in moved.values) {
        expect(v, isA<int>(), reason: '$moved');
      }
    });

    testWidgets('a half-drawn wall is thrown away when wall mode is left', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);

      await _enterWallMode(tester);
      await _tapCell(tester, 10, 10);

      // Leaving with one point down. Nothing was added, and nothing is dirty —
      // a started-and-abandoned wall must not arm the unsaved-changes prompt.
      await tester.tap(find.byKey(const Key('general-draw-wall')));
      await tester.pumpAndSettle();

      final save = tester.widget<FilledButton>(
        find.byKey(const Key('general-save')),
      );
      expect(
        save.onPressed,
        isNull,
        reason: 'A wall that was never finished is not a change to save.',
      );

      // The control: back in, and a fresh pair of taps draws THAT wall — so the
      // abandoned point is gone rather than still waiting to be the start of
      // the next one.
      await _enterWallMode(tester);
      await _tapCell(tester, 4, 12);
      await _tapCell(tester, 9, 12);
      await _save(tester);

      final walls = _savedWalls(fake);
      expect(walls, hasLength(_fixtureWalls.length + 1));
      expect(walls.last, {'x1': 4, 'y1': 12, 'x2': 9, 'y2': 12});
    });

    testWidgets('removing a wall takes that one and leaves the other', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);
      await _enterWallMode(tester);

      // Nothing is selected yet, so there is nothing to delete — the button
      // says so rather than acting on whichever wall it likes.
      expect(
        tester
            .widget<OutlinedButton>(find.byKey(const Key('general-delete-wall')))
            .onPressed,
        isNull,
      );

      await tester.tap(find.byKey(const ValueKey('floor-plan-wall-0')));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<OutlinedButton>(find.byKey(const Key('general-delete-wall')))
            .onPressed,
        isNotNull,
      );

      await tester.tap(find.byKey(const Key('general-delete-wall')));
      await tester.pumpAndSettle();

      await _save(tester);

      final walls = _savedWalls(fake);
      expect(walls, hasLength(1));
      expect(
        walls.single,
        _fixtureWalls[1],
        reason: 'The control: the OTHER wall survives untouched. A delete that '
            'emptied the list would satisfy "the deleted one is gone".',
      );
    });

    testWidgets('wall mode takes the tiles out of reach, and gives them back', (
      tester,
    ) async {
      await _pumpTab(tester);
      await _selectPlan(tester);
      await _enterEdit(tester);
      await _enterWallMode(tester);

      // `warnIfMissed: false` because MISSING IS THE POINT: in wall mode the
      // tile is behind an IgnorePointer, so the tap goes through it to the
      // drawing surface. The warning would be the helper reporting the very
      // thing this test is asserting.
      await tester.tap(
        find.byKey(const ValueKey('floor-plan-node-n0')),
        warnIfMissed: false,
      );
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<OutlinedButton>(find.byKey(const Key('general-assign-node')))
            .onPressed,
        isNull,
        reason: 'A tap in wall mode is a wall point. If it had ALSO selected '
            'the tile, Assign person would have something to act on.',
      );

      // The control: the same tap with wall mode off does select it.
      await tester.tap(find.byKey(const Key('general-draw-wall')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('floor-plan-node-n0')));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<OutlinedButton>(find.byKey(const Key('general-assign-node')))
            .onPressed,
        isNotNull,
      );
    });

    testWidgets('the wall toolbar fits a 375px screen without overflowing', (
      tester,
    ) async {
      await _pumpTab(tester, size: const Size(375, 812));
      await _selectPlan(tester);
      await _enterEdit(tester);

      // Wall mode is the widest the toolbar ever gets: six controls at once.
      await _enterWallMode(tester);

      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('general-delete-wall')), findsOneWidget);
      expect(find.byKey(const Key('floor-plan-canvas')), findsOneWidget);
    });
  });

  group('the plans themselves', () {
    testWidgets('a new plan is named, scoped, posted and then opened', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);

      await tester.tap(find.byKey(const Key('general-add-plan')));
      await tester.pumpAndSettle();

      // The scopes are whatever `scope-options` answered — the server's own
      // reach, not a list assembled here from the session.
      expect(fake.scopeOptionFetches, 1);
      expect(find.byKey(const ValueKey('plan-scope-organization-org-1')),
          findsOneWidget);
      expect(
          find.byKey(const ValueKey('plan-scope-company-co-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('plan-scope-franchise-fr-1')),
          findsOneWidget);
      expect(find.textContaining('A-1 Chimney'), findsWidgets);
      expect(find.textContaining('North Shore'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('plan-name')),
        'Second floor',
      );
      await tester.pumpAndSettle();

      // The franchise, NOT the default — so the assertion below is about the
      // scope that was chosen rather than the one that was pre-selected.
      await tester.tap(find.byKey(const ValueKey('plan-scope-franchise-fr-1')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('plan-save')));
      await tester.pumpAndSettle();

      expect(fake.posts, hasLength(1));

      final body = fake.posts.single;
      expect(body['name'], 'Second floor');
      expect(body['scope'], {'type': 'franchise', 'id': 'fr-1'});
      expect(
        body['scope'],
        isNot({'type': 'organization', 'id': 'org-1'}),
        reason: 'The control: posting the first option would satisfy "a scope '
            'was sent" while filing the plan at the wrong level.',
      );

      final layout = body['layout'] as Map<String, dynamic>;
      expect(layout['grid'], {'cols': 40, 'rows': 28});
      expect(layout['walls'], isEmpty);
      expect(layout['nodes'], isEmpty);

      // The list is re-read and the new plan is the one now open.
      expect(fake.planListFetches, 2);
      expect(find.byKey(const ValueKey('floor-plan-fp-2')), findsOneWidget);
      expect(find.text('Second floor'), findsWidgets);
      expect(find.byKey(const Key('floor-plan-canvas')), findsOneWidget);
    });

    testWidgets('a refused create shows the sentence the server gave', (
      tester,
    ) async {
      final fake = await _pumpTab(tester, refuseCreate: true);

      await tester.tap(find.byKey(const Key('general-add-plan')));
      await tester.pumpAndSettle();

      await tester.enterText(find.byKey(const Key('plan-name')), 'Head office');
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('plan-save')));
      await tester.pumpAndSettle();

      // The request really was made — without this the sentence on screen could
      // as easily be a button that never fired.
      expect(fake.posts, hasLength(1));
      expect(find.text(_scopeRefusal), findsOneWidget);
      expect(
        fake.planListFetches,
        1,
        reason: 'A refused create does not re-read the list; there is nothing '
            'new in it.',
      );
    });

    testWidgets('the empty state offers a create that works', (tester) async {
      await _pumpTab(tester, plans: const []);

      expect(find.text(GeneralTab.noPlansNote), findsOneWidget);

      final button = tester.widget<FilledButton>(
        find.byKey(const Key('general-add-plan')),
      );
      expect(
        button.onPressed,
        isNotNull,
        reason: 'The empty state is where the FIRST plan is made; a disabled '
            'button there is the tab refusing its own only entry point.',
      );

      await tester.tap(find.byKey(const Key('general-add-plan')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('plan-name')), findsOneWidget);
    });

    testWidgets('a rename sends only the new name, never a layout it could overwrite', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);

      await tester.tap(find.byKey(const Key('general-rename-plan')));
      await tester.pumpAndSettle();

      // A rename does not move a plan, so it is not offered a scope.
      expect(find.byKey(const ValueKey('plan-scope-company-co-1')), findsNothing);

      await tester.enterText(find.byKey(const Key('plan-name')), 'Ground floor');
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('plan-save')));
      await tester.pumpAndSettle();

      expect(fake.puts, hasLength(1));

      final body = fake.puts.single;
      expect(body['name'], 'Ground floor');

      // The load-bearing half (audit 11-27). The server keeps the layout when
      // none is sent (FloorPlanWriteTest), and a layout sent from a screen
      // opened earlier overwrote one saved meanwhile.
      expect(body.containsKey('layout'), isFalse);
    });

    testWidgets('deleting asks first, and cancelling sends nothing', (
      tester,
    ) async {
      final fake = await _pumpTab(tester);
      await _selectPlan(tester);

      await tester.tap(find.byKey(const Key('general-delete-plan')));
      await tester.pumpAndSettle();

      // The panel names the plan rather than asking about "this item".
      expect(find.textContaining('Main office'), findsWidgets);

      await tester.tap(find.byKey(const Key('plan-delete-cancel')));
      await tester.pumpAndSettle();

      expect(
        fake.deletes,
        isEmpty,
        reason: 'The control: backing out of the confirmation must send no '
            'request at all.',
      );
      expect(find.byKey(const ValueKey('floor-plan-fp-1')), findsOneWidget);

      await tester.tap(find.byKey(const Key('general-delete-plan')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('plan-delete-confirm')));
      await tester.pumpAndSettle();

      expect(fake.deletes, ['fp-1']);
      expect(find.byKey(const ValueKey('floor-plan-fp-1')), findsNothing);
      expect(
        find.text(GeneralTab.noPlansNote),
        findsOneWidget,
        reason: 'The list is re-read and the selection cleared; the tab does '
            'not go on showing a plan that is gone.',
      );
    });

    testWidgets('managing plans is an editor grant, not a viewer one', (
      tester,
    ) async {
      await _pumpTab(tester, grantManage: false);
      await _selectPlan(tester);

      expect(find.byKey(const Key('general-add-plan')), findsNothing);
      expect(find.byKey(const Key('general-rename-plan')), findsNothing);
      expect(find.byKey(const Key('general-delete-plan')), findsNothing);

      // The control: the same tab with the grant offers all three, so the
      // absence above is the grant talking and not a tab that failed to build.
      await _pumpTab(tester, grantManage: true);
      await _selectPlan(tester);

      expect(find.byKey(const Key('general-add-plan')), findsOneWidget);
      expect(find.byKey(const Key('general-rename-plan')), findsOneWidget);
      expect(find.byKey(const Key('general-delete-plan')), findsOneWidget);
    });
  });
}

// ── the fixture ─────────────────────────────────────────────────────

const _gridCols = 40;
const _gridRows = 28;

const _fixtureWalls = [
  {'x1': 0, 'y1': 0, 'x2': 6, 'y2': 0},
  {'x1': 2, 'y1': 18, 'x2': 12, 'y2': 18},
];

/// What `GET /monitoring/floor-plans/scope-options` answers here — an
/// ORG-LEVEL actor, so all three levels are offered. The panel shows what this
/// list says and nothing else; the narrower case (no organization row) is
/// general_tab_test's.
const _scopeOptions = [
  {'type': 'organization', 'id': 'org-1', 'label': 'A-1 Chimney'},
  {'type': 'company', 'id': 'co-1', 'label': 'A-1 Chimney'},
  {'type': 'franchise', 'id': 'fr-1', 'label': 'North Shore'},
];

const _scopeRefusal =
    'You can only create a floor plan for your own level or below.';

const _plan = {
  'id': 'fp-1',
  'name': 'Main office',
  'scope': {'type': 'company', 'id': 'co-1', 'label': 'A-1 Chimney'},
  'updated_at': '2026-09-09T10:00:00Z',
};

const _createdPlan = {
  'id': 'fp-2',
  'name': 'Second floor',
  'scope': {'type': 'franchise', 'id': 'fr-1', 'label': 'North Shore'},
  'updated_at': '2026-09-09T11:00:00Z',
};

Map<String, dynamic> _planBody(String id) => {
  'id': id,
  'name': id == 'fp-2' ? 'Second floor' : 'Main office',
  'scope': id == 'fp-2' ? _createdPlan['scope'] : _plan['scope'],
  'layout': {
    'grid': {'cols': _gridCols, 'rows': _gridRows},
    'walls': [
      for (final wall in _fixtureWalls) {...wall},
    ],
    'nodes': [
      {'id': 'n0', 'x': 2, 'y': 3, 'label': 'Desk 0', 'user_id': null},
    ],
  },
  'status': {
    'n0': {'assigned': false, 'online': false, 'name': null},
  },
};

/// What the fake server saw. Held by the pump so a test can read the body a
/// press produced rather than trusting the screen about what was sent.
class _Fake {
  int planListFetches = 0;
  int scopeOptionFetches = 0;
  final List<Map<String, dynamic>> posts = [];
  final List<Map<String, dynamic>> puts = [];
  final List<String> deletes = [];
  final List<Map<String, dynamic>> plans = [];
}

List<Map<String, dynamic>> _savedWalls(_Fake fake) {
  final layout = fake.puts.last['layout'] as Map<String, dynamic>;
  return (layout['walls'] as List<dynamic>).cast<Map<String, dynamic>>();
}

/// Tap a grid INTERSECTION on the canvas.
///
/// The cell size is the canvas's own arithmetic (`width / cols`), so this reads
/// it off the laid-out canvas rather than restating it — the same reason the
/// Task 7a drag test does. A tap at exactly `col * cell` rounds to `col` at any
/// width, which is what makes these assertions size-independent.
Future<void> _tapCell(WidgetTester tester, int col, int row) async {
  final canvas = find.byKey(const Key('floor-plan-canvas'));
  final origin = tester.getTopLeft(canvas);
  final cell = tester.getSize(canvas).width / _gridCols;

  await tester.tapAt(origin + Offset(col * cell, row * cell));
  await tester.pumpAndSettle();
}

Future<void> _selectPlan(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('floor-plan-fp-1')));
  await tester.pumpAndSettle();
}

Future<void> _enterEdit(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('general-edit-toggle')));
  await tester.pumpAndSettle();
}

Future<void> _enterWallMode(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('general-draw-wall')));
  await tester.pumpAndSettle();
}

Future<void> _save(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('general-save')));
  await tester.pumpAndSettle();
}

Future<_Fake> _pumpTab(
  WidgetTester tester, {
  bool grantManage = true,
  bool refuseCreate = false,
  List<Map<String, dynamic>>? plans,
  // Tall on purpose: the canvas is `cols x rows` cells at the full width it is
  // given, so a short window puts the lower rows below the fold and a tap there
  // would land on nothing. Every cell these tests tap has to be on screen.
  Size size = const Size(1200, 1400),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final fake = _Fake();
  fake.plans.addAll(plans ?? const [_plan]);

  final permissions = <String>[
    'monitoring.view',
    if (grantManage) 'monitoring.map.manage',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final id = path.split('/').last;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (request.method == 'GET' &&
          path.endsWith('/monitoring/assignable-people')) {
        return _json({'data': <Map<String, dynamic>>[]});
      }

      if (request.method == 'GET' && path.endsWith('/monitoring/floor-plans')) {
        fake.planListFetches++;
        return _json({'data': fake.plans});
      }

      if (request.method == 'POST' &&
          path.endsWith('/monitoring/floor-plans')) {
        fake.posts.add(jsonDecode(request.body) as Map<String, dynamic>);

        if (refuseCreate) {
          return _json({
            'message': 'The given data was invalid.',
            'errors': {
              'scope': [_scopeRefusal],
            },
          }, 422);
        }

        fake.plans.add(_createdPlan);
        return _json({'data': _createdPlan}, 201);
      }

      // Before the {id} GET, exactly as the route is registered server-side.
      if (request.method == 'GET' &&
          path.endsWith('/monitoring/floor-plans/scope-options')) {
        fake.scopeOptionFetches++;
        return _json({'data': _scopeOptions});
      }

      if (request.method == 'GET' &&
          path.contains('/monitoring/floor-plans/')) {
        return _json({'data': _planBody(id)});
      }

      if (request.method == 'PUT' && path.contains('/monitoring/floor-plans/')) {
        fake.puts.add(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': _planBody(id)});
      }

      if (request.method == 'DELETE' &&
          path.contains('/monitoring/floor-plans/')) {
        fake.deletes.add(id);
        fake.plans.removeWhere((plan) => plan['id'] == id);
        return http.Response('', 204);
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  // A key per pump, and it is load-bearing rather than tidiness. A second
  // `pumpWidget` of the same widget types REUSES the element tree, so the tab's
  // State — `_editing`, the draft, the selected plan — would survive into what
  // a test is treating as a fresh screen, and a control pumped after an
  // exercise would start halfway through the exercise. Distinct keys force a
  // new State object, which is what "pump it again" is supposed to mean.
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: GeneralTab(key: ValueKey(fake), api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return fake;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'permissions': <String>['monitoring.view'],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'North Shore', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'monitoring',
      'label': 'Monitoring',
      'available': true,
      'reachable': true,
    },
  ],
};
