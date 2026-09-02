import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/capacity_settings.dart';
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
  /// make one. With zero areas that is a dead end — the pattern card, the
  /// grid and the zips all sit behind having an area, so nothing on the
  /// screen could be reached from a cold start.
  testWidgets('an empty screen offers a way to create the first area', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      areas: const [],
      pattern: null,
      onRequest: (request) {
        if (request.method == 'POST' && request.url.path.endsWith('/scheduling/areas')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail}, 201);
        }
        return null;
      },
    );

    expect(find.text('Add service area'), findsOneWidget);

    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).last, 'Dallas');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull, reason: 'Creating an area should POST it.');
    expect(posted!['name'], 'Dallas');
    expect(
      posted!['franchise_id'],
      'fr-1',
      reason: 'A new area is filed under the franchise in view.',
    );
  });

  testWidgets('zip codes can be edited and are sent as a list', (tester) async {
    Map<String, dynamic>? saved;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/zips')) {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas'));
    await tester.pumpAndSettle();

    // The zips card sits below the day grid, so it has to be scrolled to
    // before it can be typed into or tapped.
    await tester.ensureVisible(find.byKey(const Key('scheduling-zips')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('scheduling-zips')),
      '75201, 75202 75203',
    );

    await tester.ensureVisible(find.text('Save zip codes'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save zip codes'));
    await tester.pumpAndSettle();

    expect(saved, isNotNull, reason: 'Saving zips should PUT them.');

    // Commas and spaces both separate, because people type both.
    expect(saved!['zips'], ['75201', '75202', '75203']);
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
}

Future<void> _pump(
  WidgetTester tester, {
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>>? areas,
  Map<String, dynamic>? pattern = _defaultPattern,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(onRequest: onRequest, areas: areas, pattern: pattern),
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
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
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
  'zips': ['75201'],
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
