import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/widgets_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management › Widgets: the search bar over the site list keeps the
/// websites whose host or franchise matches. Every name invented.
void main() {
  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 1000);
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
              'name': 'Dana Reyes',
              'email': 'dana@example.com',
              'has_avatar': false,
              'role': {'id': 'role-1', 'name': 'Administrator'},
              'permissions': ['web_management.view'],
              'modules': <Object>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Everdoors',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'scope': {'type': 'organization', 'id': 'org-1'},
              'companies': <Object>[],
              'franchises': <Object>[],
            },
          });
        }
        if (path.endsWith('/web/sites')) {
          return json({
            'data': [
              {
                'id': 'site-1',
                'host': 'everdoors.example',
                'is_primary': true,
                'company': {'id': 'co-1'},
              },
              {
                'id': 'site-2',
                'host': 'north.everdoors.example',
                'is_primary': false,
                'franchise': {'id': 'fr-1', 'name': 'North Jersey'},
                'company': {'id': 'co-1'},
              },
            ],
          });
        }
        return json(<String, Object>{}, 404);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: WidgetsTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('web-widgets-sites-search');
  Finder row(String host) => find.widgetWithText(ListTile, host);

  testWidgets('typing keeps the matching website and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(row('everdoors.example'), findsOneWidget);
    expect(row('north.everdoors.example'), findsOneWidget);

    await tester.enterText(find.byKey(field), 'jersey');
    await tester.pumpAndSettle();

    expect(row('north.everdoors.example'), findsOneWidget);
    expect(row('everdoors.example'), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No websites match "zebra".'), findsOneWidget);
    expect(row('everdoors.example'), findsNothing);
    expect(row('north.everdoors.example'), findsNothing);
  });
}
