import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 182: one card per sign-in. Any provider the catalogue marks
/// `signed_in_with` folds into its head's card, so Facebook and a Google
/// family the client has never heard of draw the same way.
void main() {
  for (final width in [1280.0, 390.0]) {
    testWidgets('families fold into one card each at $width px', (
      tester,
    ) async {
      final deletes = await _pump(tester, width);

      // No card of their own for products a sign-in brings.
      expect(find.byKey(const ValueKey('provider:facebook_ads')), findsNothing);
      expect(find.byKey(const ValueKey('provider:youtube')), findsNothing);
      expect(
        find.byKey(const ValueKey('family:facebook_page')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('family:google_business_profile')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('family-product:facebook_ads')),
        findsOneWidget,
      );
      // YouTube was not granted: Add access, not a status.
      expect(find.byKey(const ValueKey('add-access:youtube')), findsOneWidget);

      // Disconnect is a two-step: the first tap lists, nothing is sent.
      final go = find.byKey(const ValueKey('family-disconnect:facebook_page'));
      await tester.ensureVisible(go);
      await tester.tap(go);
      await tester.pump();
      expect(find.text('This removes:'), findsOneWidget);
      expect(find.textContaining('Facebook Ads'), findsWidgets);
      expect(deletes, isEmpty);

      // It lapses by itself.
      await tester.pump(const Duration(seconds: 6));
      expect(find.text('This removes:'), findsNothing);

      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('Confirm sends one family disconnect with the code', (
    tester,
  ) async {
    final deletes = await _pump(tester, 1280);

    await tester.tap(
      find.byKey(const ValueKey('family-disconnect:facebook_page')),
    );
    await tester.pump();
    await tester.tap(
      find.byKey(const ValueKey('family-confirm-go:facebook_page')),
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, '123456');
    await tester.pump();
    await tester.tap(find.widgetWithText(FilledButton, 'Disconnect').last);
    await tester.pumpAndSettle();

    expect(deletes, ['/api/v1/social/providers/facebook_page/connections']);
  });
}

Map<String, dynamic> _row(String id, String provider, String label) => {
  'id': id,
  'provider': provider,
  'provider_label': label,
  'display_name': 'Brightside $label',
  'scope_type': 'organization',
  'scope_id': 'org-1',
  'status': 'connected',
  'last_checked_at': DateTime.now().toUtc().toIso8601String(),
};

Map<String, dynamic> _cat(String value, String label, [String? head]) => {
  'value': value,
  'label': label,
  'connects_by': 'sign_in',
  'start_path': '/social/$value/start',
  'permission': 'marketing.edit',
  'signed_in_with': head,
};

Future<List<String>> _pump(WidgetTester tester, double width) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final deletes = <String>[];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (request.method == 'DELETE') {
        deletes.add(path);
        return _json({
          'data': {'disconnected': 2},
        });
      }
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/social/connections')) {
        return _json({
          'data': [
            _row('fb-1', 'facebook_page', 'Facebook'),
            _row('fa-1', 'facebook_ads', 'Facebook Ads'),
            _row('gb-1', 'google_business_profile', 'Business Profile'),
          ],
        });
      }
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': [
            _cat('facebook_page', 'Facebook'),
            _cat('facebook_ads', 'Facebook Ads', 'facebook_page'),
            _cat('google_business_profile', 'Google'),
            _cat('youtube', 'YouTube', 'google_business_profile'),
            _cat('pinterest', 'Pinterest'),
          ],
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
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
  return deletes;
}

const _me = {
  'id': 'u-1',
  'name': 'Dana',
  'email': 'dana@brightside.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view', 'marketing.edit'],
  'modules': <dynamic>[],
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Branch',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
