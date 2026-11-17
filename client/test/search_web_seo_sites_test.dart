import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/seo_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management › SEO: the search bar over the site list keeps the
/// websites whose host or state matches. Every name invented.
void main() {
  Future<void> pump(WidgetTester tester) async {
    // A phone: the list is the screen, with no detail fetched beside it.
    tester.view.physicalSize = const Size(390, 844);
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
        if (path == '/api/v1/me') {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Dana Reyes',
              'email': 'dana@example.com',
              'has_avatar': false,
              'role': {'id': 'role-1', 'name': 'Administrator'},
              'permissions': [
                'web_management.view',
                'web_management.edit',
                'web_management.seo.view',
              ],
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
        if (path == '/api/v1/web/sites') {
          return json({
            'data': [
              {
                'id': 'site-1',
                'host': 'everdoors.example',
                'is_primary': true,
                'connected_at': '2026-10-01T10:00:00+00:00',
                'company': {'id': 'co-1'},
                'missing': 0,
              },
              {
                'id': 'site-2',
                'host': 'north.everdoors.example',
                'is_primary': false,
                'connected_at': null,
                'company': {'id': 'co-1'},
                'missing': 0,
              },
              {
                'id': 'site-3',
                'host': 'acefireplaceservicesandchimneysweeps.example',
                'is_primary': false,
                'connected_at': null,
                'company': {'id': 'co-1'},
                'missing': 0,
              },
            ],
            'sections': const [
              {'key': 'general', 'label': 'General'},
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
          body: SeoTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('web-seo-sites-search');
  const one = ValueKey('tile-site-1');
  const two = ValueKey('tile-site-2');

  testWidgets('typing keeps the matching website and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.byKey(one), findsOneWidget);
    expect(find.byKey(two), findsOneWidget);

    await tester.enterText(find.byKey(field), 'north');
    await tester.pumpAndSettle();

    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsOneWidget);
  });

  // UI pass 2026-11-17: a long host broke mid-word in the list column
  // ("acefireplaceservices.co / m"). It stays on one line.
  testWidgets('a long website name stays on one line', (tester) async {
    await pump(tester);
    final host = find.text('acefireplaceservicesandchimneysweeps.example');
    final short = find.text('everdoors.example');
    expect(host, findsOneWidget);
    expect(
      tester.getSize(host).height,
      lessThanOrEqualTo(tester.getSize(short).height),
    );
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No websites match "zebra".'), findsOneWidget);
    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsNothing);
  });
}
