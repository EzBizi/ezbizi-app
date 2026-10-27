import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_management_2_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management - 2's search (Jose, 2026-10-27: "search by customer
/// name, job id, or address"). The fake server ignores q, as
/// ProjectManagement2Controller does today, so the rows must narrow on the
/// device.
void main() {
  for (final (what, term) in [
    ('customer', 'omar'),
    ('job id', '4471'),
    ('address', 'elm st'),
  ]) {
    testWidgets('$what: q is sent and the rows narrow', (tester) async {
      final requests = <http.Request>[];
      final api = ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          if (request.url.path.endsWith('/me')) {
            return _json({
              'data': {
                'id': 'u-1',
                'name': 'Dana',
                'email': 'dana@brightside.example',
                'permissions': ['project_management_2.view', 'jobs.view'],
                'modules': <dynamic>[],
                'organization': {'id': 'org-1', 'name': 'Brightside'},
                'scope': {
                  'type': 'organization',
                  'id': null,
                  'label': 'Brightside',
                  'path': ['Brightside'],
                },
              },
            });
          }
          return _json({
            'data': [
              _job('j-1', '1002', 'Dana Whitfield', '9 Oak Ave, Dayton OH'),
              _job('j-2', '4471', 'Omar Castillo', '12 Elm St, Akron OH'),
            ],
          });
        }),
      );
      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));

      tester.view.physicalSize = const Size(1400, 1000);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: ProjectManagement2Screen(api: api, session: session),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Dana Whitfield'), findsOneWidget);
      expect(find.text('Omar Castillo'), findsOneWidget);

      await _search(tester, term);
      final sent = requests
          .lastWhere((r) => r.url.path.endsWith('/project-management-2/jobs'))
          .url
          .queryParameters;
      expect(sent['q'], term);
      expect(find.text('Dana Whitfield'), findsNothing);
      expect(find.text('Omar Castillo'), findsOneWidget);
      expect(find.text('Done pending approval · 1 jobs'), findsOneWidget);

      await _search(tester, 'nobody');
      expect(find.text('No job matches "nobody".'), findsOneWidget);
    });
  }
}

Future<void> _search(WidgetTester tester, String text) async {
  await tester.enterText(find.byKey(const Key('pm2-search')), text);
  await tester.pump(const Duration(milliseconds: 399));
  await tester.pump(const Duration(milliseconds: 2));
  await tester.pumpAndSettle();
}

Map<String, Object?> _job(String id, String number, String who, String at) => {
  'id': id,
  'job_number': number,
  'customer': who,
  'branch': 'Ohio',
  'company': 'Brightside',
  'status': 'Done pending approval',
  'tags': <String>[],
  'address': at,
  'created': '2026-10-01',
  'modified': '2026-10-02',
};

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
