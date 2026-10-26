import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/lighting_tab.dart';
import 'package:ezbizi_client/screens/monitoring/monitoring_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Monitoring › Lighting (design 150, 1 + 4).
void main() {
  testWidgets('the Lighting tab shows only with monitoring.lighting.manage', (
    tester,
  ) async {
    await _pump(tester, permissions: ['monitoring.view'], screen: true);
    expect(find.text('Lighting'), findsNothing);

    await _pump(
      tester,
      permissions: ['monitoring.view', 'monitoring.lighting.manage'],
      screen: true,
    );
    expect(find.text('Lighting'), findsOneWidget);
  });

  testWidgets('bulk Apply to all in view posts no device_ids', (tester) async {
    final log = await _pump(tester);
    await tester.tap(
      find.byKey(const ValueKey('lighting-bulk-swatch-#FF0000')),
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('lighting-bulk-apply')));
    await tester.pumpAndSettle();

    final post = log.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/monitoring/lighting'));
    final body = jsonDecode(post.body) as Map;
    expect(body.containsKey('device_ids'), isFalse);
    expect(body['mode'], 'static');
    expect(body['color'], '#FF0000');
    expect(body['brightness'], 100);
    expect(find.text('Set on 3 PCs'), findsOneWidget);
  });

  testWidgets('bulk Apply to ticked posts only the ticked ids', (tester) async {
    final log = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('lighting-tick-pc-ok')));
    await tester.pump();
    await tester.tap(find.text('Ticked · 1'));
    await tester.pump();
    await tester.tap(find.text('Rainbow').first);
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('lighting-bulk-apply')));
    await tester.pumpAndSettle();

    final body =
        jsonDecode(log.singleWhere((r) => r.method == 'POST').body) as Map;
    expect(body['device_ids'], ['pc-ok']);
    expect(body['mode'], 'rainbow');
    expect(body.containsKey('color'), isFalse);
  });

  testWidgets('the PC pane PUTs that PC and lists its lights', (tester) async {
    final log = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('lighting-row-pc-ok')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('tour-lighting-pane')), findsOneWidget);
    expect(find.text('ASRock B760M'), findsOneWidget);

    await tester.ensureVisible(find.text('Breathing').last);
    await tester.tap(find.text('Breathing').last);
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('lighting-pane-hex')),
      '00ff40',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('lighting-pane-apply')));
    await tester.pumpAndSettle();

    final put = log.singleWhere((r) => r.method == 'PUT');
    expect(put.url.path, endsWith('/monitoring/devices/pc-ok/lighting'));
    expect(jsonDecode(put.body), {
      'mode': 'breathing',
      'color': '#00FF40',
      // The pane starts from what this PC was set to.
      'brightness': 80,
    });
  });

  testWidgets('static needs a valid hex before Apply', (tester) async {
    final log = await _pump(tester);
    await tester.enterText(
      find.byKey(const ValueKey('lighting-bulk-hex')),
      'red',
    );
    await tester.pump();
    expect(find.text('Like #00AEEF'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('lighting-bulk-apply')));
    await tester.pump();
    expect(log.where((r) => r.method == 'POST'), isEmpty);
  });

  testWidgets('status sentences, and a non-Ready PC cannot be ticked', (
    tester,
  ) async {
    await _pump(tester);
    expect(find.text('Ready'), findsOneWidget);
    expect(find.text('Close Corsair iCUE first'), findsOneWidget);
    expect(find.text('Lighting engine not installed'), findsOneWidget);

    for (final (id, enabled) in [
      ('pc-ok', true),
      ('pc-icue', false),
      ('pc-none', false),
    ]) {
      final box = tester.widget<Checkbox>(
        find.byKey(ValueKey('lighting-tick-$id')),
      );
      expect(box.onChanged != null, enabled, reason: id);
    }

    expect(
      ['no_devices', 'engine_not_responding', 'needs_admin'].map(
        (s) => lightingStatus({
          'specs': {'rgb_status': s},
        }).label,
      ),
      [
        'No RGB lights found',
        'Lighting engine not responding',
        'Needs admin rights',
      ],
    );
  });

  testWidgets('the swatch paints the colour the PC was set to', (tester) async {
    await _pump(tester);
    final row = find.byKey(const ValueKey('lighting-row-pc-ok'));
    final dot = tester.widget<Container>(
      find.descendant(
        of: row,
        matching: find.byKey(const ValueKey('lighting-swatch')),
      ),
    );
    expect((dot.decoration! as BoxDecoration).color, const Color(0xFFFF8000));
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('lighting-row-pc-none')),
        matching: find.byKey(const ValueKey('lighting-swatch-off')),
      ),
      findsOneWidget,
    );
  });

  testWidgets('phone 390x844: cards, the pane full screen, no overflow', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('tour-lighting-bulk')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('lighting-row-pc-ok')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('tour-lighting-pane')), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-lighting-bulk')), findsNothing);
  });
}

Map<String, dynamic> _pc(
  String id,
  String person,
  String status, {
  Map<String, dynamic>? lighting,
  List<Map<String, dynamic>> rgb = const [],
}) => {
  'id': id,
  'user': {'id': 'u-$id', 'name': person},
  'label': 'PC $person',
  'platform': 'windows',
  'capabilities': ['activity', 'specs'],
  'last_seen_at': DateTime.now().toUtc().toIso8601String(),
  'specs': {'rgb_status': status, 'rgb_devices': rgb},
  'lighting': lighting,
};

final _devices = [
  _pc(
    'pc-ok',
    'Marco Reyes',
    'ok',
    lighting: {
      'mode': 'static',
      'color': '#FF8000',
      'brightness': 80,
      'version': DateTime.now().millisecondsSinceEpoch,
      'set_by': 'Jose',
    },
    rgb: [
      {
        'name': 'ASRock B760M',
        'vendor': 'ASRock',
        'type': 'Motherboard',
        'zones': 3,
        'leds': 12,
      },
    ],
  ),
  _pc('pc-icue', 'Priya Nair', 'brand_app_running:Corsair iCUE'),
  _pc('pc-none', 'Ana Silva', 'engine_missing'),
];

Future<List<http.Request>> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'monitoring.view',
    'monitoring.lighting.manage',
  ],
  Size size = const Size(1280, 900),
  bool screen = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final log = <http.Request>[];
  final api = ApiClient(
    client: MockClient((request) async {
      log.add(request);
      final path = request.url.path;
      Object body = {'data': <Object>[]};
      if (path.endsWith('/me')) {
        body = {
          'data': {
            'id': 'u-1',
            'name': 'Jose',
            'email': 'jose@example.com',
            'role': {'name': 'Manager'},
            'has_avatar': false,
            'permissions': permissions,
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
          },
        };
      } else if (path.endsWith('/monitoring/devices')) {
        body = {'data': _devices};
      } else if (path.endsWith('/monitoring/lighting')) {
        final sent = jsonDecode(request.body) as Map;
        final ids = sent['device_ids'] as List?;
        body = {
          'data': {'updated': ids?.length ?? 3, 'lighting': sent},
        };
      } else if (path.endsWith('/lighting')) {
        body = {'data': jsonDecode(request.body)};
      }
      return http.Response(
        jsonEncode(body),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: screen
            ? MonitoringScreen(api: api, session: session)
            : LightingTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  log.removeWhere((r) => r.method == 'GET');
  return log;
}
