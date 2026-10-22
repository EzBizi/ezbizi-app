import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/camera_sites_settings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Monitoring › Camera sites: the search bar filters the sites.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> site(String id, String name, String pc) => {
    'id': id,
    'name': name,
    'online': true,
    'online_since': '2026-10-20T12:00:00Z',
    'last_reason': null,
    'last_checkin_at': '2026-10-22T12:00:00Z',
    'device': {'pc_name': pc, 'app_version': '0.0.0460'},
    'camera_count': 2,
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'has_avatar': false,
              'preferences': <String, dynamic>{},
              'permissions': [
                'organizations.settings.view',
                'monitoring.cameras.view',
              ],
              'modules': <dynamic>[],
              'organization': {
                'id': 'org-1',
                'name': 'Brightside',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/monitoring/camera-sites')) {
          return json({
            'data': [
              site('s-yard', 'Main yard', 'YARD-OFFICE'),
              site('s-north', 'North warehouse', 'WH-NORTH-PC'),
            ],
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: CameraSitesSettings(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching site and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Main yard'), findsOneWidget);
    expect(find.text('North warehouse'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('camera-sites-search')),
      'north',
    );
    await tester.pump();

    expect(find.text('North warehouse'), findsOneWidget);
    expect(find.text('Main yard'), findsNothing);
  });

  testWidgets('a term matching no site says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('camera-sites-search')), 'zzz');
    await tester.pump();

    expect(find.text('No sites match "zzz".'), findsOneWidget);
    expect(find.text('Main yard'), findsNothing);
    expect(find.text('North warehouse'), findsNothing);
  });
}
