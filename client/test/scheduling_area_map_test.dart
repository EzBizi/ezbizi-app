import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/area_map_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';

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
    double height = 900,
    String? downColour,
    List<String> franchises = const ['fr-1'],
    List<String> permissions = const [
      'scheduling.view',
      'scheduling.settings.view',
      'scheduling.edit',
      'scheduling.delete',
    ],
    http.Response? Function(http.Request request)? onRequest,
  }) async {
    tester.view.physicalSize = Size(width, height);
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
                'color': downColour,
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
        if (path.endsWith('/scheduling/areas/a-down') &&
            request.method == 'GET') {
          return json({
            'data': {
              'id': 'a-down',
              'name': 'Downtown',
              'center_zip': '90177',
              'radius_miles': 5,
            },
          });
        }
        if (path.endsWith('/scheduling/areas/a-down/outline') &&
            request.url.queryParameters['mode'] == 'recommended') {
          return json({
            'data': {
              'polygon': [
                for (var i = 0; i < 32; i++)
                  [41.0 + i / 1000, -88.0 - i / 1000],
              ],
            },
          });
        }
        if (path.endsWith('/scheduling/areas/a-down/outline')) {
          return json({
            'data': {
              'polygon': [
                [41.2, -88.1],
                [41.2, -87.8],
                [41.0, -87.7],
                [40.9, -88.0],
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
    await tester.tap(find.text('Brightside · Branch fr-2').last);
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

  // ── Auto draw from ZIPs ─────────────────────────────────────────

  Future<void> startEditing(WidgetTester tester) async {
    await openDowntown(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-edit')));
    await tester.pumpAndSettle();
  }

  testWidgets('Auto draw loads the outline as editable corners, saves nothing '
      'until Save', (tester) async {
    await pump(tester);
    await startEditing(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-auto-draw')));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();

    expect(sent('GET', '/areas/a-down/outline'), hasLength(1));
    for (var i = 0; i < 4; i++) {
      expect(find.byKey(ValueKey('area-map-vertex-0-$i')), findsOneWidget);
    }
    // The usual preview ran with the loaded corners; nothing was written.
    final body =
        jsonDecode(sent('POST', '/areas/a-down/polygons/preview').last.body)
            as Map<String, dynamic>;
    expect((body['polygons'] as List).single, [
      [41.2, -88.1],
      [41.2, -87.8],
      [41.0, -87.7],
      [40.9, -88.0],
    ]);
    expect(requests.where((r) => r.method == 'PATCH'), isEmpty);
    expect(requests.where((r) => r.method == 'PUT'), isEmpty);

    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();
    final put =
        jsonDecode(sent('PUT', '/areas/a-down/polygons').single.body)
            as Map<String, dynamic>;
    expect((put['polygons'] as List).single, hasLength(4));
  });

  testWidgets('Auto draw replaces only the unsaved drawing; Cancel keeps the '
      'saved one', (tester) async {
    await pump(tester);
    await draw(tester);
    expect(find.byKey(const ValueKey('area-map-vertex-0-2')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('area-map-auto-draw')));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();

    // One shape of four corners, not the drawn triangle plus the outline.
    expect(find.byKey(const ValueKey('area-map-vertex-0-3')), findsOneWidget);
    expect(find.byKey(const ValueKey('area-map-vertex-1-0')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('area-map-cancel')));
    await tester.pumpAndSettle();
    expect(sent('PUT', '/polygons'), isEmpty);
  });

  testWidgets("Auto draw's 422 sentence is shown inline", (tester) async {
    const sentence =
        'This area needs at least 3 ZIP codes on the map to draw an '
        'outline; it has 2.';
    await pump(
      tester,
      onRequest: (r) => r.url.path.endsWith('/outline')
          ? json({'message': sentence}, 422)
          : null,
    );
    await startEditing(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-auto-draw')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('area-map-auto-error')),
        matching: find.text(sentence),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('area-map-vertex-0-0')), findsNothing);
    expect(sent('POST', '/polygons/preview'), isEmpty);
  });

  // ── Colour ──────────────────────────────────────────────────────

  Color dotColour(WidgetTester tester, String id) =>
      ((tester.widget<Container>(
                find.byKey(ValueKey('area-map-dot-$id')),
              )).decoration!
              as BoxDecoration)
          .color!;

  Polygon downShape(WidgetTester tester) => tester
      .widget<PolygonLayer>(find.byType(PolygonLayer))
      .polygons
      .firstWhere((p) => p.points.first == const LatLng(41.0, -88.0));

  testWidgets('a swatch PATCHes the colour and paints the shape and the dot', (
    tester,
  ) async {
    await pump(tester);
    await openDowntown(tester);
    await tester.tap(find.byKey(const ValueKey('area-colour-#E5484D')));
    await tester.pumpAndSettle();

    final patch = sent('PATCH', '/scheduling/areas/a-down').single;
    expect(jsonDecode(patch.body), {'color': '#E5484D'});
    const red = Color(0xFFE5484D);
    expect(dotColour(tester, 'a-down'), red);
    expect(downShape(tester).borderColor, red);
    expect(downShape(tester).color, red.withValues(alpha: 0.16));
  });

  testWidgets('a typed hex is sent; Default sends null and falls back', (
    tester,
  ) async {
    await pump(tester, downColour: '#3E63DD');
    await openDowntown(tester);
    const blue = Color(0xFF3E63DD);
    expect(dotColour(tester, 'a-down'), blue);
    expect(downShape(tester).borderColor, blue);

    await tester.enterText(
      find.byKey(const ValueKey('area-colour-hex')),
      '12a594',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(jsonDecode(sent('PATCH', '/areas/a-down').last.body), {
      'color': '#12A594',
    });

    await tester.tap(find.byKey(const ValueKey('area-colour-default')));
    await tester.pumpAndSettle();
    expect(jsonDecode(sent('PATCH', '/areas/a-down').last.body), {
      'color': null,
    });
    final context = tester.element(find.byType(AreaMapPane));
    final fallback = PersonColours.hue(context, 0).ink;
    expect(dotColour(tester, 'a-down'), fallback);
    expect(downShape(tester).borderColor, fallback);
  });

  testWidgets('a null colour keeps the branch-position colour', (tester) async {
    await pump(tester);
    final context = tester.element(find.byType(AreaMapPane));
    expect(dotColour(tester, 'a-down'), PersonColours.hue(context, 0).ink);
    expect(dotColour(tester, 'a-east'), PersonColours.hue(context, 1).ink);
    expect(downShape(tester).borderColor, PersonColours.hue(context, 0).ink);
  });

  testWidgets('phone 390x844: the colour picker fits, nothing overflows', (
    tester,
  ) async {
    await pump(tester, width: 390, height: 844, downColour: '#8E4EC6');
    await openDowntown(tester);
    expect(find.byKey(const ValueKey('area-colour')), findsOneWidget);
    expect(tester.takeException(), isNull);
    final picker = tester.getRect(find.byKey(const ValueKey('area-colour')));
    expect(picker.right, lessThanOrEqualTo(390));
  });

  // ── Recommended area: a circle round a centre ZIP ──────────────

  testWidgets('Recommended: stored values prefill, typed ones override the '
      'query, the circle loads, and Save PATCHes centre and radius', (
    tester,
  ) async {
    await pump(tester);
    await startEditing(tester);

    final zip = find.byKey(const ValueKey('area-recommended-zip'));
    final miles = find.byKey(const ValueKey('area-recommended-miles'));
    expect(tester.widget<TextField>(zip).controller!.text, '90177');
    expect(tester.widget<TextField>(miles).controller!.text, '5');

    await tester.enterText(zip, '90155');
    await tester.enterText(miles, '12');
    await tester.tap(find.byKey(const ValueKey('area-recommended-draw')));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();

    final asked = sent('GET', '/areas/a-down/outline').single;
    expect(asked.url.queryParameters, {
      'mode': 'recommended',
      'center_zip': '90155',
      'radius_miles': '12',
    });
    expect(find.byKey(const ValueKey('area-map-vertex-0-31')), findsOneWidget);
    expect(sent('POST', '/polygons/preview'), isNotEmpty);
    expect(requests.where((r) => r.method == 'PATCH'), isEmpty);

    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();
    final put =
        jsonDecode(sent('PUT', '/areas/a-down/polygons').single.body)
            as Map<String, dynamic>;
    expect((put['polygons'] as List).single, hasLength(32));
    expect(jsonDecode(sent('PATCH', '/scheduling/areas/a-down').single.body), {
      'center_zip': '90155',
      'radius_miles': 12,
    });
  });

  testWidgets("Recommended's 422 sentence is shown and nothing loads", (
    tester,
  ) async {
    const sentence = 'Pick a centre ZIP code we know and a radius in miles.';
    await pump(
      tester,
      onRequest: (r) => r.url.path.endsWith('/outline')
          ? json({'message': sentence}, 422)
          : null,
    );
    await startEditing(tester);
    await tester.tap(find.byKey(const ValueKey('area-recommended-draw')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('area-recommended-error')),
        matching: find.text(sentence),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('area-map-vertex-0-0')), findsNothing);
  });

  testWidgets('a hand drawing saves no centre or radius', (tester) async {
    await pump(tester);
    await draw(tester);
    await tester.tap(find.byKey(const ValueKey('area-map-save')));
    await tester.pumpAndSettle();
    expect(requests.where((r) => r.method == 'PATCH'), isEmpty);
  });

  testWidgets('desktop: the radius label is painted whole, not cut short', (
    tester,
  ) async {
    await pump(tester);
    await startEditing(tester);
    final label = tester.renderObject<RenderParagraph>(
      find.descendant(
        of: find.byKey(const ValueKey('area-recommended-miles')),
        matching: find.text('Radius (miles)'),
      ),
    );
    expect(label.didExceedMaxLines, isFalse);
    expect(
      label.getMaxIntrinsicWidth(double.infinity),
      lessThanOrEqualTo(label.size.width + 0.5),
    );
  });
}
