import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Reports library: the search narrows the saved reports.
void main() {
  Map<String, dynamic> report(String id, String name, String description) => {
    'id': id,
    'name': name,
    'description': description,
    'company_id': 'co-1',
    'franchise_id': null,
    'definition': {
      'root': 'jobs',
      'columns': [
        {'resource': 'jobs', 'column': 'title'},
      ],
      'filters': <Map<String, dynamic>>[],
    },
  };

  Future<void> pump(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    http.Response json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
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
              'permissions': [
                'reports.view',
                'reports.build',
                'reports.library.view',
                'reports.builder.view',
              ],
              'modules': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/reports')) {
          return json({
            'data': [
              report('rep-1', 'Open jobs', 'Everything still open.'),
              report('rep-2', 'Closed sweeps', 'Done this month.'),
            ],
          });
        }
        return json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportsScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  for (final width in [1280.0, 390.0]) {
    testWidgets('the search narrows the library at $width', (tester) async {
      await pump(tester, width);
      expect(find.text('Open jobs'), findsOneWidget);
      expect(find.text('Closed sweeps'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('reports-library-search')),
        'SWEEP',
      );
      await tester.pump();
      expect(find.text('Closed sweeps'), findsOneWidget);
      expect(find.text('Open jobs'), findsNothing);

      await tester.enterText(
        find.byKey(const Key('reports-library-search')),
        'zzz',
      );
      await tester.pump();
      expect(find.text('No reports match "zzz".'), findsOneWidget);
      expect(find.text('Closed sweeps'), findsNothing);
    });
  }
}
