import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/general_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Monitoring "General" tab in EDIT mode — Task 7a: add a computer, move
/// one, delete one, assign a person to one, and save the layout.
///
/// Every negative here is paired with a positive control, because most of what
/// this feature does is *not* offering something. "No edit toggle" and "the
/// roster was never fetched" both read exactly like a tab that failed to build,
/// so each is asserted beside the same case with the grant, where the toggle is
/// there and the fetch does happen.
///
/// The PUT body is decoded and read, not merely counted: a save that posts the
/// wrong person's id, or the pre-drag cell, is the failure that matters, and a
/// request-count assertion cannot see either.
void main() {
  testWidgets(
    'the edit toggle is an editor grant, and a viewer never fetches the roster',
    (tester) async {
      final viewer = await _pumpTab(tester, grantManage: false);
      await _selectPlan(tester);

      expect(find.byKey(const Key('general-edit-toggle')), findsNothing);
      expect(find.byKey(const Key('general-add-computer')), findsNothing);
      expect(
        viewer.peopleFetches,
        0,
        reason: 'Without monitoring.map.manage nobody can be assigned, so the '
            'roster endpoint (which refuses this caller anyway) is never asked.',
      );

      // The control: the same tab WITH the grant offers the toggle — so the
      // absence above is the grant talking, not a tab that failed to build.
      final editor = await _pumpTab(tester, grantManage: true);
      await _selectPlan(tester);

      expect(find.byKey(const Key('general-edit-toggle')), findsOneWidget);
      expect(
        editor.peopleFetches,
        0,
        reason: 'Holding the grant is not entering the editor; the roster is '
            'fetched when edit mode opens, not when the tab loads.',
      );

      // ... and it is fetched exactly once when edit mode opens.
      await _enterEdit(tester);
      expect(editor.peopleFetches, 1);
      expect(find.byKey(const Key('general-add-computer')), findsOneWidget);
    },
  );

  testWidgets('adding a computer puts a tile up; deleting takes it away', (
    tester,
  ) async {
    await _pumpTab(tester);
    await _selectPlan(tester);
    await _enterEdit(tester);

    expect(find.byKey(const ValueKey('floor-plan-node-n0')), findsOneWidget);
    expect(_tileCount(tester), 1);
    expect(find.textContaining('1 computers'), findsOneWidget);

    await tester.tap(find.byKey(const Key('general-add-computer')));
    await tester.pumpAndSettle();

    expect(_tileCount(tester), 2);
    expect(find.textContaining('2 computers'), findsOneWidget);
    expect(
      find.text('Computer 2'),
      findsOneWidget,
      reason: 'The new tile carries its own default label.',
    );

    // The new tile is selected by the add, so Delete acts on it.
    await tester.tap(find.byKey(const Key('general-delete-node')));
    await tester.pumpAndSettle();

    expect(_tileCount(tester), 1);
    expect(find.text('Computer 2'), findsNothing);
    expect(
      find.byKey(const ValueKey('floor-plan-node-n0')),
      findsOneWidget,
      reason: 'Delete removes the SELECTED tile, not whichever one it likes.',
    );
  });

  testWidgets('a dragged tile lands on the nearest cell, and that cell saves', (
    tester,
  ) async {
    final fake = await _pumpTab(tester);
    await _selectPlan(tester);
    await _enterEdit(tester);

    // The cell size is the canvas's own arithmetic (width / cols), so the test
    // reads it off the laid-out canvas rather than restating it — a drag of
    // five cells is five cells at any width.
    final cell =
        tester.getSize(find.byKey(const Key('floor-plan-canvas'))).width /
            _gridCols;

    await _dragTile(
      tester,
      find.byKey(const ValueKey('floor-plan-node-n0')),
      Offset(cell * 5, cell * 4),
    );

    await tester.tap(find.byKey(const Key('general-save')));
    await tester.pumpAndSettle();

    final node = _savedNodes(fake).single;
    expect(node['id'], 'n0');
    expect(
      node['x'],
      _startCol + 5,
      reason: 'A drop is snapped to the nearest cell, not left on a pixel.',
    );
    expect(node['y'], _startRow + 4);
    expect(
      node['x'],
      isNot(_startCol),
      reason: 'The control: a move that stored the ORIGINAL cell would satisfy '
          'a shape-only assertion.',
    );
  });

  testWidgets('a tile is assigned to the person chosen, and to no other', (
    tester,
  ) async {
    final fake = await _pumpTab(tester);
    await _selectPlan(tester);
    await _enterEdit(tester);

    await tester.tap(find.byKey(const ValueKey('floor-plan-node-n0')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('general-assign-node')));
    await tester.pumpAndSettle();

    // The panel lists what the roster endpoint returned — both people.
    expect(find.text('Ada Lovelace'), findsOneWidget);
    expect(find.text('Grace Hopper'), findsOneWidget);

    // The search narrows it to one, and the other really does go.
    await tester.enterText(find.byKey(const Key('assign-search')), 'grace');
    await tester.pumpAndSettle();
    expect(find.text('Ada Lovelace'), findsNothing);
    expect(find.text('Grace Hopper'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assign-person-u-3')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('general-save')));
    await tester.pumpAndSettle();

    final node = _savedNodes(fake).single;
    expect(node['user_id'], 'u-3');
    expect(
      node['user_id'],
      isNot('u-2'),
      reason: 'The control: sending the first person in the list would pass an '
          '"a user_id was sent" assertion while assigning the wrong desk.',
    );

    // Unassign clears it again, and the clearing is what gets saved. The tile
    // is still the selected one — tapping it again would DEselect it, which is
    // what a tap on the selection means.
    await tester.tap(find.byKey(const Key('general-assign-node')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assign-unassign')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('general-save')));
    await tester.pumpAndSettle();

    expect(_savedNodes(fake).single['user_id'], isNull);
  });

  testWidgets('a refused save shows the reason and keeps every edit', (
    tester,
  ) async {
    final fake = await _pumpTab(tester, refuseSave: true);
    await _selectPlan(tester);
    await _enterEdit(tester);

    await tester.tap(find.byKey(const Key('general-add-computer')));
    await tester.pumpAndSettle();
    expect(_tileCount(tester), 2);

    await tester.tap(find.byKey(const Key('general-save')));
    await tester.pumpAndSettle();

    // The save really was attempted — without this the sentence on screen
    // could as easily be a button that never fired.
    expect(fake.puts, hasLength(1));

    // The server's own field-level sentence, not the generic 422 wrapper.
    expect(find.text(_refusal), findsOneWidget);

    // The edits survive the refusal — the tile is still there ...
    expect(_tileCount(tester), 2);
    expect(find.text('Computer 2'), findsOneWidget);

    // ... and the plan is still dirty, so Save can be pressed again. (Enabled
    // is the load-bearing half: a Save that greyed out here would strand the
    // edits on screen with no way to retry them.)
    final save = tester.widget<FilledButton>(
      find.byKey(const Key('general-save')),
    );
    expect(save.onPressed, isNotNull);
  });

  testWidgets('leaving edit mode with unsaved edits asks before discarding', (
    tester,
  ) async {
    await _pumpTab(tester);
    await _selectPlan(tester);
    await _enterEdit(tester);

    await tester.tap(find.byKey(const Key('general-add-computer')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('general-edit-toggle')));
    await tester.pumpAndSettle();

    expect(find.text(GeneralTab.unsavedNote), findsOneWidget);

    // Keeping: still editing, still two tiles, nothing lost.
    await tester.tap(find.byKey(const Key('general-keep-editing')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('general-add-computer')), findsOneWidget);
    expect(_tileCount(tester), 2);

    // Discarding: an explicit answer, and only then does the draft go.
    await tester.tap(find.byKey(const Key('general-edit-toggle')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('general-discard')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('general-add-computer')), findsNothing);
    expect(_tileCount(tester), 1);
  });

  testWidgets('edit mode fits a 375px screen without overflowing', (
    tester,
  ) async {
    await _pumpTab(tester, size: const Size(375, 812));
    await _selectPlan(tester);
    await _enterEdit(tester);

    await tester.tap(find.byKey(const Key('general-add-computer')));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('floor-plan-canvas')), findsOneWidget);
  });
}

// ── the fixture ─────────────────────────────────────────────────────

const _gridCols = 40;
const _gridRows = 28;
const _startCol = 2;
const _startRow = 3;

const _refusal = 'You can only assign people you can see.';

const _plan = {
  'id': 'fp-1',
  'name': 'Main office',
  'scope': {'type': 'company', 'id': 'co-1', 'label': 'A-1 Chimney'},
  'updated_at': '2026-09-09T10:00:00Z',
};

Map<String, dynamic> _planBody() => {
  'id': 'fp-1',
  'name': 'Main office',
  'scope': _plan['scope'],
  'layout': {
    'grid': {'cols': _gridCols, 'rows': _gridRows},
    'walls': [
      {'x1': 0, 'y1': 0, 'x2': 6, 'y2': 0},
    ],
    'nodes': [
      {
        'id': 'n0',
        'x': _startCol,
        'y': _startRow,
        'label': 'Desk 0',
        'user_id': null,
      },
    ],
  },
  'status': {
    'n0': {'assigned': false, 'online': false, 'name': null},
  },
};

/// What the fake server saw. Held by the pump so a test can read the PUT body
/// it produced rather than trusting the screen about what was sent.
class _Fake {
  int peopleFetches = 0;
  final List<Map<String, dynamic>> puts = [];
}

int _tileCount(WidgetTester tester) => tester
    .widgetList(
      find.byWidgetPredicate(
        (w) => w.key is ValueKey<String> &&
            (w.key! as ValueKey<String>).value.startsWith('floor-plan-node-'),
      ),
    )
    .length;

/// The nodes of the last saved layout.
List<Map<String, dynamic>> _savedNodes(_Fake fake) {
  final layout = fake.puts.last['layout'] as Map<String, dynamic>;
  return (layout['nodes'] as List<dynamic>).cast<Map<String, dynamic>>();
}

/// Drag a tile and let go.
///
/// A pointer stream with a frame between the move and the release, rather than
/// `tester.drag`: the tile's pan recognizer shares the arena with the page's
/// vertical scroll, and the convenience helper's un-pumped burst of moves lets
/// the arena reach the pointer-up undecided — which is a property of that
/// helper, not of the widget. Every real pointer arrives across frames, which
/// is what this reproduces.
Future<void> _dragTile(WidgetTester tester, Finder tile, Offset by) async {
  final gesture = await tester.startGesture(tester.getCenter(tile));
  await gesture.moveBy(by);
  await tester.pump();
  await gesture.up();
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

Future<_Fake> _pumpTab(
  WidgetTester tester, {
  bool grantManage = true,
  bool refuseSave = false,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final fake = _Fake();

  final permissions = <String>[
    'monitoring.view',
    if (grantManage) 'monitoring.map.manage',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (request.method == 'GET' &&
          path.endsWith('/monitoring/assignable-people')) {
        fake.peopleFetches++;
        return _json({
          'data': [
            {'id': 'u-2', 'name': 'Ada Lovelace'},
            {'id': 'u-3', 'name': 'Grace Hopper'},
          ],
        });
      }

      if (request.method == 'GET' && path.endsWith('/monitoring/floor-plans')) {
        return _json({
          'data': [_plan],
        });
      }

      if (request.method == 'GET' &&
          path.contains('/monitoring/floor-plans/')) {
        return _json({'data': _planBody()});
      }

      if (request.method == 'PUT' &&
          path.contains('/monitoring/floor-plans/')) {
        fake.puts.add(jsonDecode(request.body) as Map<String, dynamic>);

        if (refuseSave) {
          return _json({
            'message': 'The given data was invalid.',
            'errors': {
              'layout': [_refusal],
            },
          }, 422);
        }

        return _json({'data': _planBody()});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: GeneralTab(api: api, session: session)),
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
  'franchises': <Map<String, Object?>>[],
  'modules': [
    {
      'key': 'monitoring',
      'label': 'Monitoring',
      'available': true,
      'reachable': true,
    },
  ],
};
