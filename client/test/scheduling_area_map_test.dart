import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/area_map_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Settings › Service areas (UI queue 125, desktop 1 and phone
/// 4), against Scheduling's map server (efbd6e645, d69673ebf). No tile URL is
/// served, so no tile is fetched. Every name, ZIP and point is invented.
void main() {
  late List<http.Request> requests;

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  const preview = {
    'add': ['90101', '90102'],
    'conflicts': [
      {
        'zip': '90111',
        'area_id': 'a-east',
        'area_name': 'Eastfield',
        'may_take': true,
      },
      {
        'zip': '90112',
        'area_id': 'a-north',
        'area_name': 'Northgate',
        'may_take': false,
      },
    ],
    'remove': ['90190'],
    'outside': ['90199'],
  };

  Future<void> pump(
    WidgetTester tester, {
    double width = 1400,
    List<String> franchises = const ['fr-1'],
    List<String> permissions = const [
      'scheduling.view',
      'scheduling.settings.view',
      'scheduling.edit',
      'scheduling.delete',
    ],
    http.Response? Function(http.Request request)? onRequest,
  }) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    requests = [];

    final api = ApiClient(
      client: MockClient((request) async {
        requests.add(request);
        final override = onRequest?.call(request);
        if (override != null) return override;
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Pat Doe',
              'email': 'pat@example.com',
              'role': {'name': 'Manager'},
              'has_avatar': false,
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Brightside',
                'franchise_label': 'Branch',
                'is_read_only': false,
              },
              'scope': {
                'type': 'organization',
                'id': 'org-1',
                'label': 'Brightside',
                'path': ['Brightside'],
              },
              'companies': [
                {'id': 'co-1', 'name': 'Brightside', 'parent_company_id': null},
              ],
              'franchises': [
                for (final f in franchises)
                  {'id': f, 'name': 'Branch $f', 'company_id': 'co-1'},
              ],
              'permissions': permissions,
            },
          });
        }
        if (path.endsWith('/scheduling/areas-map')) {
          return json({
            'data': [
              {
                'area_id': 'a-down',
                'name': 'Downtown',
                'franchise_id': 'fr-1',
                'franchise_name': 'Branch fr-1',
                'zip_count': 18,
                'polygons': [
                  [
                    [41.0, -88.0],
                    [41.0, -87.9],
                    [41.1, -87.9],
                  ],
                ],
              },
              {
                'area_id': 'a-east',
                'name': 'Eastfield',
                'franchise_id': 'fr-1',
                'franchise_name': 'Branch fr-1',
                'zip_count': 31,
                'polygons': [],
              },
            ],
          });
        }
        if (path.endsWith('/scheduling/areas/a-down/map')) {
          return json({
            'data': {
              'area_id': 'a-down',
              'polygons': [],
              'zips': [
                {
                  'zip': '90101',
                  'city': 'Springvale',
                  'source': 'typed',
                  'latitude': 41.05,
                  'longitude': -87.95,
                },
                // No Census centre: listed nowhere on the map.
                {
                  'zip': '90150',
                  'city': null,
                  'source': 'typed',
                  'latitude': null,
                  'longitude': null,
                },
              ],
            },
          });
        }
        if (path.endsWith('/polygons/preview')) return json({'data': preview});
        if (path.endsWith('/polygons') && request.method == 'PUT') {
          return json({
            'data': {
              'added': ['90101'],
              'moved': [],
              'removed': [],
              'left_with_owner': [],
            },
          });
        }
        return json({'data': null});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: AreaMapPane(api: api, session: session, visible: true),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Iterable<http.Request> sent(String method, String suffix) =>
      requests.where((r) => r.method == method && r.url.path.endsWith(suffix));

  Future<void> openDowntown(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('area-map-row-a-down')));
    await tester.pumpAndSettle();
  }

  /// Edit drawing, three taps on the map, Close shape, then the debounce.
  Future<void> draw(WidgetTester tester) async {
    await openDowntown(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-edit')));
    await tester.pumpAndSettle();
    final map = tester.getRect(find.byKey(const ValueKey('area-map')));
    for (final at in [
      map.center + const Offset(-80, -60),
      map.center + const Offset(90, -50),
      map.center + const Offset(10, 80),
    ]) {
      await tester.tapAt(at);
      await tester.pump(const Duration(milliseconds: 400));
    }
    await tester.tap(find.byKey(const ValueKey('area-map-close-shape')));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
  }

  testWidgets('one branch in view: the map loads that branch, with counts', (
    tester,
  ) async {
    await pump(tester);

    final asked = sent('GET', '/scheduling/areas-map').single;
    expect(asked.url.queryParameters, {'franchise_id': 'fr-1'});
    expect(find.text('Downtown'), findsOneWidget);
    expect(find.text('18 ZIPs'), findsOneWidget);
    expect(find.text('31 ZIPs'), findsOneWidget);
    // One branch is no choice, so no picker.
    expect(find.byKey(const ValueKey('area-map-branch')), findsNothing);

    // Picking an area draws its ZIPs that have a Census centre.
    await openDowntown(tester);
    expect(find.byKey(const ValueKey('area-map-zip-90101')), findsOneWidget);
    expect(find.byKey(const ValueKey('area-map-zip-90150')), findsNothing);
  });

  testWidgets('two branches: the company view, then a branch from the picker', (
    tester,
  ) async {
    await pump(tester, franchises: const ['fr-1', 'fr-2']);

    expect(
      sent('GET', '/scheduling/areas-map').single.url.queryParameters,
      isEmpty,
    );

    await tester.tap(find.byKey(const ValueKey('area-map-branch')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Branch fr-2 · Brightside').last);
    await tester.pumpAndSettle();

    expect(sent('GET', '/scheduling/areas-map').last.url.queryParameters, {
      'franchise_id': 'fr-2',
    });
  });

  testWidgets('drawing a shape asks the preview with its corners', (
    tester,
  ) async {
    await pump(tester);
    await draw(tester);

    final body =
        jsonDecode(sent('POST', '/areas/a-down/polygons/preview').last.body)
            as Map<String, dynamic>;
    final polygons = body['polygons'] as List;
    expect(polygons, hasLength(1));
    final corners = polygons.single as List;
    expect(corners, hasLength(3));
    for (final c in corners.cast<List>()) {
      expect(c, hasLength(2));
      expect((c[0] as num).abs(), lessThanOrEqualTo(90));
      expect((c[1] as num).abs(), lessThanOrEqualTo(180));
    }
  });

  testWidgets('the review lists adds, conflicts, drops and typed-outside; '
      'Take it only where may_take', (tester) async {
    await pump(tester);
    await draw(tester);

    expect(find.text('Adds · 2'), findsOneWidget);
    expect(find.text('Belong to another area · 2'), findsOneWidget);
    expect(find.text('Would drop out · 1'), findsOneWidget);
    expect(find.text('Typed outside the drawing, kept · 1'), findsOneWidget);
    for (final zip in ['90101', '90102', '90111', '90112', '90190', '90199']) {
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('area-review')),
          matching: find.text(zip),
        ),
        findsOneWidget,
        reason: '$zip should be in the review.',
      );
    }

    expect(
      find.byKey(const ValueKey('area-review-take-90111')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('area-review-take-90112')), findsNothing);
    expect(
      find.text("Belongs to Northgate, which you can't change"),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('area-review-take-90111')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();

    final put =
        jsonDecode(sent('PUT', '/areas/a-down/polygons').single.body)
            as Map<String, dynamic>;
    expect(put['take'], ['90111']);
    expect((put['polygons'] as List).single, hasLength(3));
    expect(find.text('Saved: 1 added, 0 moved, 0 removed'), findsOneWidget);
    // Saved: the tools and the review are gone.
    expect(find.byKey(const ValueKey('area-review')), findsNothing);
  });

  testWidgets('Save without a tick sends an empty take', (tester) async {
    await pump(tester);
    await draw(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();

    final put =
        jsonDecode(sent('PUT', '/areas/a-down/polygons').single.body)
            as Map<String, dynamic>;
    expect(put['take'], isEmpty);
  });

  testWidgets("a 403 shows the server's sentence and keeps the drawing", (
    tester,
  ) async {
    await pump(
      tester,
      onRequest: (r) => r.method == 'PUT' && r.url.path.endsWith('/polygons')
          ? json({
              'message': '90111 is in Eastfield, which you cannot change.',
            }, 403)
          : null,
    );
    await draw(tester);
    await tester.tap(find.byKey(const ValueKey('area-review-take-90111')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();

    expect(
      find.text('90111 is in Eastfield, which you cannot change.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('area-review')), findsOneWidget);
  });

  testWidgets('Clear drawing sends no shapes', (tester) async {
    await pump(tester);
    await draw(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-clear')));
    await tester.pumpAndSettle();

    final put =
        jsonDecode(sent('PUT', '/areas/a-down/polygons').single.body)
            as Map<String, dynamic>;
    expect(put['polygons'], isEmpty);
    expect(put.containsKey('take'), isFalse);
  });

  testWidgets('Cancel puts the saved drawing back and saves nothing', (
    tester,
  ) async {
    await pump(tester);
    await draw(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-cancel')));
    await tester.pumpAndSettle();

    expect(sent('PUT', '/polygons'), isEmpty);
    expect(find.byKey(const ValueKey('area-review')), findsNothing);
    expect(find.byKey(const ValueKey('area-map-edit')), findsOneWidget);
  });

  testWidgets('without scheduling.edit there are no drawing tools', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['scheduling.view', 'scheduling.settings.view'],
    );
    await openDowntown(tester);

    expect(find.byKey(const ValueKey('area-map-edit')), findsNothing);
    expect(find.byKey(const ValueKey('area-map-delete')), findsNothing);
  });

  testWidgets('delete from the map asks where the ZIPs go', (tester) async {
    Uri? deleted;
    await pump(
      tester,
      onRequest: (r) {
        if (r.method != 'DELETE') return null;
        deleted = r.url;
        return json({
          'data': {
            'moved': 18,
            'unassigned': 0,
            'moved_to': {'id': 'a-east', 'name': 'Eastfield'},
          },
        });
      },
    );
    await openDowntown(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-delete')));
    await tester.pumpAndSettle();

    expect(
      find.text('Downtown has 18 ZIPs. Where should they go?'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('area-delete-move')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-delete-to')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Eastfield').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-delete-go')));
    await tester.pumpAndSettle();

    expect(deleted?.path, endsWith('/scheduling/areas/a-down'));
    expect(deleted?.queryParameters['move_zips_to'], 'a-east');
    expect(find.text('18 moved to Eastfield'), findsOneWidget);
  });

  testWidgets('a phone gets the map to look at, and the list, no tools', (
    tester,
  ) async {
    await pump(tester, width: 400);

    expect(find.byKey(const ValueKey('area-map-phone')), findsOneWidget);
    expect(find.text('Drawing is on a computer or tablet.'), findsOneWidget);
    expect(find.text('Downtown'), findsOneWidget);

    await openDowntown(tester);
    expect(find.byKey(const ValueKey('area-map-edit')), findsNothing);
    expect(find.byKey(const ValueKey('area-map-tool-draw')), findsNothing);
  });
}
