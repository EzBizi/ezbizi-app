import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/camera_monitoring_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Monitoring › Camera Monitoring: the search bar filters the tile grid.
///
/// RTSP tiles only: they render the copy-URL card and open no connection, so
/// no fake MJPEG stream is needed here.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> camera(String id, String label) => {
    'id': id,
    'label': label,
    'protocol': 'rtsp',
    'url': 'rtsp://10.0.0.20/$id',
    'enabled': true,
    'created_at': '2026-08-01T12:00:00Z',
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Jose',
              'email': 'jose@example.com',
              'has_avatar': false,
              'permissions': ['monitoring.view', 'monitoring.cameras.view'],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'A-1 Chimney',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
              'modules': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/monitoring/cameras')) {
          return json({
            'data': [
              camera('cam-1', 'Shop floor'),
              camera('cam-2', 'Back lot'),
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
          body: CameraMonitoringTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching camera and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Shop floor'), findsOneWidget);
    expect(find.text('Back lot'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('cameras-search')), 'back');
    await tester.pump();

    expect(find.text('Back lot'), findsOneWidget);
    expect(find.text('Shop floor'), findsNothing);
  });

  testWidgets('a term matching no camera says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('cameras-search')), 'zzz');
    await tester.pump();

    expect(find.text('No cameras match "zzz".'), findsOneWidget);
    expect(find.text('Back lot'), findsNothing);
    expect(find.text('Shop floor'), findsNothing);
  });
}
