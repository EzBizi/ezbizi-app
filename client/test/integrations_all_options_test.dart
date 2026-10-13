import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 82 (Jose, 2026-10-13): every provider is shown, and a card with
/// nothing connected offers Connect only where connecting can work.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Future<void> pump(WidgetTester tester, {double width = 1280}) async {
    tester.view.physicalSize = Size(width, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': ['marketing.view', 'marketing.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northside',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/social/connections')) {
          return json({'data': <dynamic>[]});
        }
        if (path.endsWith('/social/providers')) {
          return json({
            'data': [
              {
                'value': 'linkedin',
                'label': 'LinkedIn',
                'usable': true,
                'connects_by': 'sign_in',
                'start_path': '/social/linkedin/start',
                'permission': 'marketing.edit',
              },
              {
                'value': 'tiktok',
                'label': 'TikTok',
                'usable': false,
                'unusable_because': 'TikTok has not approved EzBizi yet.',
                'connects_by': 'sign_in',
                'start_path': '/social/tiktok/start',
                'permission': 'marketing.edit',
              },
              {
                'value': 'google_business_profile',
                'label': 'Google Business Profile',
                'usable': true,
                'connects_by': 'sign_in',
                'start_path': '/social/google/start',
                'permission': 'leads.edit',
              },
              {
                'value': 'workiz',
                'label': 'Workiz',
                'usable': true,
                'connects_by': 'api_key',
                'start_path': '/social/workiz',
                'permission': 'reports.build',
              },
            ],
          });
        }
        return json({'message': 'Nothing stubbed for $path.'});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: IntegrationsScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('Connect where it can work, and why not where it cannot', (
    tester,
  ) async {
    await pump(tester);

    expect(find.byKey(const ValueKey('connect-card:linkedin')), findsOneWidget);
    // The server says it cannot, so no button; its own words instead.
    expect(find.byKey(const ValueKey('connect-card:tiktok')), findsNothing);
    expect(find.text('TikTok has not approved EzBizi yet.'), findsOneWidget);
    // Without the permission, nobody is handed a button that answers 403.
    expect(
      find.byKey(const ValueKey('connect-card:google_business_profile')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('ask:google_business_profile')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('ask:workiz')), findsOneWidget);

    // Connect opens the provider's own page, which knows the way in.
    await tester.tap(find.byKey(const ValueKey('connect-card:linkedin')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('integration-back')), findsOneWidget);
  });

  testWidgets('Workiz wears its logo, not the grey square', (tester) async {
    await pump(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('provider:workiz')),
        matching: find.byType(SvgPicture),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a phone lists them with the action on the right', (
    tester,
  ) async {
    await pump(tester, width: 375);

    expect(find.byKey(const ValueKey('provider:linkedin')), findsOneWidget);
    expect(find.byKey(const ValueKey('connect-card:linkedin')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
