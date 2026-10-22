import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/monitoring/ownership_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Monitoring › General: the search bar filters the ownership chart's rows.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> person(String id, String name, String role) => {
    'user': {'id': id, 'name': name},
    'role': role,
    'desk': null,
    'office': 'Main Office',
    'online': true,
    'assignment': 'Boston',
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Viewer',
              'email': 'v@e.test',
              'permissions': ['monitoring.view', 'monitoring.general.view'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Everdoors',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/monitoring/ownership')) {
          return json({
            'data': [
              person('u-1', 'Marco Reyes', 'Dispatcher'),
              person('u-2', 'Toula Elwell', 'Technician'),
            ],
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await session.adopt('tok');

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: OwnershipTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching person and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Marco Reyes'), findsOneWidget);
    expect(find.text('Toula Elwell'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('ownership-search')), 'techn');
    await tester.pump();

    expect(find.text('Toula Elwell'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsNothing);
  });

  testWidgets('a term matching nobody says so in one sentence', (tester) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('ownership-search')), 'zzz');
    await tester.pump();

    expect(find.text('No people match "zzz".'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsNothing);
    expect(find.text('Toula Elwell'), findsNothing);
  });
}
