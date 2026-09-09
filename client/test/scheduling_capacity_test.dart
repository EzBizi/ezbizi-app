import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/capacity_settings.dart';
import 'package:ezbizi_client/widgets/error_note.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Capacity settings — design docs/previews/scheduling-layouts.html #7.
///
/// The check worth having is that CLOSING A DAY SENDS NO ROWS FOR IT. A close
/// that wrote `max_jobs: 0` would satisfy every other test here — the day would
/// still look closed on screen — while collapsing "we do not work Sundays" into
/// "we work Sundays and are full" in storage, with nothing erroring. So this
/// asserts on the REQUEST BODY, which is the only place the difference shows.
void main() {
  testWidgets('the rail pins Overview above the areas and marks one that differs', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Overview'), findsOneWidget);
    expect(find.text('Dallas'), findsOneWidget);

    // Twice on purpose: once in the rail, once in the Overview's "areas that
    // differ" list. Both routes to the exception are the design — the list so
    // it cannot be forgotten, the rail so somebody who never opens the list
    // still sees it.
    expect(find.text('Houston'), findsNWidgets(2));

    expect(find.textContaining('differs'), findsOneWidget);
  });

  testWidgets('the overview names how many areas follow the pattern', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.textContaining('followed by 1 of 2 areas'), findsOneWidget);
  });

  /// The defect this screen shipped with: it listed areas and had no way to
  /// make one. With zero areas that is a dead end.
  testWidgets('an empty screen offers a way to create the first area', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      areas: const [],
      pattern: null,
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/scheduling/areas')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail}, 201);
        }
        return null;
      },
    );

    expect(find.text('Add service area'), findsOneWidget);

    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    // A PANE, not a dialog. A modal over a split screen hides the rail you
    // were reading to decide what to type.
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const Key('scheduling-area-name')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('scheduling-area-name')),
      'Dallas',
    );
    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull, reason: 'Creating an area should POST it.');
    expect(posted!['name'], 'Dallas');
    expect(
      posted!['franchise_id'],
      'fr-1',
      reason: 'One franchise in view is filled in without asking.',
    );
  });

  /// The bug reported from the app: at company level, with two franchises in
  /// view, the screen REFUSED to open the form and said to go and change the
  /// switcher. The one control that could have answered "which franchise" was
  /// the control being withheld.
  testWidgets('at company level the form asks which franchise, not refuses', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      areas: const [],
      pattern: null,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
        ],
      },
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/scheduling/areas')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail}, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    // It opened, and it is asking.
    expect(find.byKey(const Key('scheduling-area-franchise')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('scheduling-area-name')),
      'Dallas',
    );

    // Submitting without choosing says so IN THE PANE and does not blank the
    // screen. Sending this to the load error produced an empty page with one
    // red box on it.
    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNull, reason: 'It must not guess a franchise.');
    expect(find.byType(ErrorNote), findsOneWidget);
    expect(
      find.byKey(const Key('scheduling-area-name')),
      findsOneWidget,
      reason: 'The form survives its own error.',
    );

    await tester.tap(find.byKey(const Key('scheduling-area-franchise')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Georgia').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['franchise_id'], 'fr-2');
  });

  /// Items, not a comma-separated string. The old field took the whole set as
  /// one string on one Save, so a single zip held by another area refused
  /// every edit at once and named one of them.
  testWidgets('a zip is added as an item, with its city, and saves on its own', (
    tester,
  ) async {
    final saves = <Map<String, dynamic>>[];

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/zips')) {
          saves.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('scheduling-zip-entry')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('scheduling-zip-entry')),
      '75202',
    );
    await tester.enterText(
      find.byKey(const Key('scheduling-city-entry')),
      'Plano',
    );
    await tester.tap(find.byTooltip('Add zip code'));
    await tester.pumpAndSettle();

    expect(saves, hasLength(1), reason: 'Adding one zip saves immediately.');

    final zips = (saves.first['zips'] as List).cast<Map<String, dynamic>>();

    expect(zips.map((z) => z['zip']), containsAll(['75201', '75202']));

    final added = zips.firstWhere((z) => z['zip'] == '75202');
    expect(added['city'], 'Plano');
  });

  testWidgets('removing a zip sends the set without it', (tester) async {
    final saves = <Map<String, dynamic>>[];

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/zips')) {
          saves.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const ValueKey('zip-75201')));
    await tester.pumpAndSettle();

    // The chip's own delete affordance, so one zip goes without retyping the
    // other forty-nine.
    await tester.tap(find.byTooltip('Remove 75201'));
    await tester.pumpAndSettle();

    expect(saves, hasLength(1));
    expect(saves.first['zips'] as List, isEmpty);
  });

  /// Delete asks for the area's own name, in the pane, and refuses anything
  /// else. Without the wrong-name half this test would pass on a confirmation
  /// that accepted any keystroke, which is a ritual rather than a check.
  testWidgets('delete needs the area name typed, and no dialog opens', (
    tester,
  ) async {
    var deleted = false;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = true;
          return _json({}, 204);
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    await _reveal(tester, find.text('Delete area'));
    await tester.tap(find.text('Delete area'));
    await tester.pumpAndSettle();

    // In the pane, not over it.
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Delete Dallas?'), findsOneWidget);

    // Reveal before EVERY tap. A tap that misses an off-screen button also
    // leaves `deleted` false, so without this the two negative assertions
    // below would pass on a button nobody pressed.
    await _reveal(tester, find.text('Delete Dallas'));

    // Nothing typed: the button is there and does nothing.
    await tester.tap(find.text('Delete Dallas'));
    await tester.pumpAndSettle();
    expect(deleted, isFalse, reason: 'An empty box must not delete.');

    // The WRONG name must not delete either.
    await tester.enterText(
      find.byKey(const Key('scheduling-delete-confirm')),
      'Houston',
    );
    await tester.pumpAndSettle();
    await _reveal(tester, find.text('Delete Dallas'));
    await tester.tap(find.text('Delete Dallas'));
    await tester.pumpAndSettle();
    expect(deleted, isFalse, reason: 'Another area name must not delete this one.');

    // The right name, spaced and in the wrong case, does.
    await tester.enterText(
      find.byKey(const Key('scheduling-delete-confirm')),
      '  dallas ',
    );
    await tester.pumpAndSettle();
    await _reveal(tester, find.text('Delete Dallas'));
    await tester.tap(find.text('Delete Dallas'));
    await tester.pumpAndSettle();
    expect(deleted, isTrue, reason: 'The area name should confirm it.');
  });

  /// A typed confirmation must not survive a change of area, or it would
  /// already match the one you moved to nothing about.
  testWidgets('switching area drops a half-typed confirmation', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    await _reveal(tester, find.text('Delete area'));
    await tester.tap(find.text('Delete area'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('scheduling-delete-confirm')),
      'Dallas',
    );
    await tester.pumpAndSettle();

    // Move to another area. The confirmation should be gone entirely.
    await tester.tap(find.text('Houston').first);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('scheduling-delete-confirm')), findsNothing);
  });

  testWidgets('closing a day sends no rows for it, never a zero', (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/slots')) {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    // Dallas is open on Monday. Close it.
    expect(find.text('Close'), findsWidgets);
    await tester.tap(find.text('Close').first);
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Save Dallas'));
    await tester.pumpAndSettle();

    expect(saved, isNotNull, reason: 'The grid should have been saved.');

    final slots = (saved!['slots'] as List).cast<Map<String, dynamic>>();

    // The closed weekday is simply absent, and nothing carries a zero.
    expect(
      slots.where((s) => s['weekday'] == 1),
      isEmpty,
      reason: 'A closed day must send no rows at all.',
    );
    expect(
      slots.where((s) => s['max_jobs'] == 0),
      isEmpty,
      reason: 'Zero capacity would make "closed" and "full" the same state.',
    );
  });
  /// Twenty-six areas across several franchises came out as one flat list with
  /// nothing saying which franchise owned which — and the same area name can
  /// legitimately sit under two franchises, so those were indistinguishable.
  testWidgets('the rail groups areas under the franchise that owns them', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
        ],
      },
      areas: const [
        {
          'id': 'a-1',
          'name': 'East',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
        },
        {
          'id': 'a-2',
          'name': 'Atlanta North',
          'franchise_id': 'fr-2',
          'zip_count': 4,
          'follows_pattern': true,
        },
      ],
      pattern: null,
    );

    // One header per franchise, so a row is never orphaned from its owner.
    expect(find.text('CONNECTICUT'), findsOneWidget);
    expect(find.text('GEORGIA'), findsOneWidget);
    expect(find.text('East'), findsOneWidget);
    expect(find.text('Atlanta North'), findsOneWidget);
  });

  /// One franchise means nothing to disambiguate, so a header would be a
  /// label on the whole screen.
  testWidgets('a single franchise gets no group header', (tester) async {
    await _pump(tester);

    expect(find.text('CONNECTICUT'), findsNothing);
    expect(find.text('Dallas'), findsOneWidget);
  });

  /// Two companies on screen means the bare franchise name is ambiguous —
  /// four Connecticuts under different companies is real data here — so the
  /// header carries the company too. Same rule FranchiseField uses.
  testWidgets('two companies on screen put the company in the header', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'companies': [
          {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
          {'id': 'co-2', 'name': 'Premier', 'parent_company_id': null},
        ],
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Connecticut', 'company_id': 'co-2'},
        ],
      },
      areas: const [
        {
          'id': 'a-1',
          'name': 'East',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
        },
        {
          'id': 'a-2',
          'name': 'West',
          'franchise_id': 'fr-2',
          'zip_count': 4,
          'follows_pattern': true,
        },
      ],
      pattern: null,
    );

    expect(find.text('EVERDOORS › CONNECTICUT'), findsOneWidget);
    expect(find.text('PREMIER › CONNECTICUT'), findsOneWidget);
  });
}

/// Scrolls the AREA pane (not the rail) until [what] is on screen.
///
/// The pane is a lazy ListView, so anything below the fold is not merely
/// off-screen — it has not been built, and ensureVisible throws "No element"
/// rather than scrolling to it.
Future<void> _reveal(WidgetTester tester, Finder what) async {
  await tester.scrollUntilVisible(
    what,
    300,
    // `.first` is the ListView's own Scrollable. Every TextField in the pane
    // carries an internal one too, so the unqualified finder matches several
    // and scrollUntilVisible asks for exactly one.
    scrollable: find
        .descendant(
          of: find.byKey(const Key('scheduling-area-pane')),
          matching: find.byType(Scrollable),
        )
        .first,
  );
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>>? areas,
  Map<String, dynamic>? pattern = _defaultPattern,
  Map<String, dynamic>? me,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(onRequest: onRequest, areas: areas, pattern: pattern, me: me),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CapacitySettings(api: api, session: session, visible: true),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>>? areas,
  Map<String, dynamic>? pattern,
  Map<String, dynamic>? me,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          ...?me,
          'permissions': const [
            'scheduling.view',
            'scheduling.create',
            'scheduling.edit',
            'scheduling.delete',
          ],
        },
      });
    }

    if (path.endsWith('/scheduling/areas')) {
      return _json({'data': areas ?? _defaultAreas, 'pattern': pattern});
    }

    if (RegExp(r'/scheduling/areas/[^/]+$').hasMatch(path)) {
      return _json({'data': _dallasDetail});
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

const _defaultAreas = [
  {
    'id': 'a-dallas',
    'name': 'Dallas',
    'franchise_id': 'fr-1',
    'zip_count': 18,
    'follows_pattern': true,
  },
  {
    'id': 'a-houston',
    'name': 'Houston',
    'franchise_id': 'fr-1',
    'zip_count': 24,
    'follows_pattern': false,
  },
];

const _defaultPattern = {
  'slots': [
    {'weekday': 1, 'start_time': '07:00', 'end_time': '11:00', 'max_jobs': 2},
  ],
  'followed_by': 1,
  'of': 2,
};

const _dallasDetail = {
  'id': 'a-dallas',
  'name': 'Dallas',
  'franchise_id': 'fr-1',
  'zips': [
    {'zip': '75201', 'city': 'Dallas'},
  ],
  'slots': [
    {
      'id': 's-1',
      'weekday': 1,
      'start_time': '07:00',
      'end_time': '11:00',
      'max_jobs': 2,
    },
  ],
};

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
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
