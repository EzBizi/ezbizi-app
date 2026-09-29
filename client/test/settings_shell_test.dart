import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings_screen.dart';
import 'package:ezbizi_client/screens/settings_shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Each face of Settings is its own grant (deploy desk audit, 2026-09-29).
void main() {
  testWidgets('a face the role does not hold is not drawn, and Settings still '
      'opens on Settings', (tester) async {
    // No Organization, so Integrations slides into position 1 — where an
    // index-based landing face would open it instead of Settings.
    await _pump(tester, [
      'organizations.settings.view',
      'organizations.integrations.view',
      'marketing.view',
    ]);

    expect(find.text('Organization'), findsNothing);
    expect(find.text('Integrations'), findsOneWidget);
    expect(find.byType(SettingsScreen), findsOneWidget);
  });

  testWidgets('marketing.view alone no longer opens Integrations', (
    tester,
  ) async {
    await _pump(tester, ['organizations.settings.view', 'marketing.view']);

    expect(find.text('Integrations'), findsNothing);
  });

  testWidgets('a role that opens none of it is told so', (tester) async {
    await _pump(tester, const []);

    expect(
      find.text('Your role does not open any part of Settings.'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(WidgetTester tester, List<String> permissions) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@example.com',
            'role': {'name': 'Dispatcher'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'permissions': permissions,
            'modules': <dynamic>[],
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
          },
        });
      }

      return _json({'data': const <dynamic>[]});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SettingsShell(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
