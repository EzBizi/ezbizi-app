import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/zip_reports.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Nelly Escobar, suggestion 01a20e5c (Jose 11-24): report a wrong ZIP, an
// admin moves it from Settings > Wrong ZIPs.
void main() {
  for (final size in const [Size(1400, 900), Size(390, 844)]) {
    testWidgets('report -> listed -> moved at ${size.width.toInt()} wide', (
      tester,
    ) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final reports = <Map<String, Object?>>[];
      final sent = <String, Object?>{};
      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;
          if (path.endsWith('/me')) return _json({'data': _me});
          if (path.endsWith('/scheduling/areas')) {
            return _json({
              'data': [
                {'id': 'a-ny', 'name': 'New York'},
                {'id': 'a-ma', 'name': 'Massachusetts'},
              ],
            });
          }
          if (path.endsWith('/scheduling/zip-reports') &&
              request.method == 'POST') {
            final body = jsonDecode(request.body) as Map<String, dynamic>;
            sent.addAll(body);
            reports.add({
              'id': 'r-1',
              'zip': body['zip'],
              'reported_area': {'id': 'a-ny', 'name': 'New York'},
              'suggested_area': {'id': 'a-ma', 'name': 'Massachusetts'},
              'note': body['note'],
              'reported_by': 'Nelly Escobar',
            });
            return _json({
              'data': {'id': 'r-1'},
            }, 201);
          }
          if (path.endsWith('/scheduling/zip-reports')) {
            return _json({
              'data': reports,
              'meta': {'open': reports.length},
            });
          }
          if (path.endsWith('/zip-reports/r-1/move')) {
            sent['moved_to'] = (jsonDecode(request.body) as Map)['area_id'];
            reports.clear();
            return _json({'data': {}});
          }
          return _json({'data': <Object>[]});
        }),
      );
      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: Column(
              children: [
                ReportWrongZipButton(
                  api: api,
                  zip: '01851',
                  areaId: 'a-ny',
                  areaName: 'New York',
                ),
                Expanded(
                  child: ZipReportsSettings(
                    api: api,
                    session: session,
                    visible: true,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('0 open reports'), findsOneWidget);

      await tester.tap(find.byKey(const Key('report-wrong-zip-01851')));
      await tester.pumpAndSettle();
      expect(find.text('It shows up in New York.'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('report-zip-note')),
        'Lowell is in MA',
      );
      await tester.tap(find.byKey(const Key('report-zip-send')));
      await tester.pumpAndSettle();
      expect(sent['zip'], '01851');
      expect(sent['reported_area_id'], 'a-ny');
      expect(sent['note'], 'Lowell is in MA');

      // The admin's list, reloaded when shown.
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: ZipReportsSettings(api: api, session: session, visible: true),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('1 open report'), findsOneWidget);
      expect(find.textContaining('should be Massachusetts'), findsOneWidget);
      await tester.tap(find.byKey(const Key('zip-report-move-r-1')));
      await tester.pumpAndSettle();
      expect(sent['moved_to'], 'a-ma');
      expect(find.text('0 open reports'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}

const _me = {
  'id': 'u-1',
  'name': 'Sam Rivera',
  'email': 'sam@example.com',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'permissions': [
    'scheduling.locations.view',
    'scheduling.availability.view',
    'scheduling.edit',
  ],
  'modules': <Object>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Harbor Group',
    'path': ['Harbor Group'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
