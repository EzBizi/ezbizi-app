import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/activity_screen.dart';
import 'package:ezbizi_client/screens/settings_shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Activity (2026-10-16).
void main() {
  testWidgets('lists who did what, and a source chip asks the server', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, ['audit.view'], asked, shell: false);

    expect(find.text('Dana · lead.created · Ann Smith'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('source:system')));
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters['source'], 'system');
    expect(asked.last.queryParameters['window'], '7d');
  });

  testWidgets('the tab is drawn only for audit.view', (tester) async {
    await _pump(tester, ['organizations.settings.view'], [], shell: true);
    expect(find.text('Activity'), findsNothing);

    await _pump(tester, [
      'organizations.settings.view',
      'audit.view',
    ], [], shell: true);
    expect(find.text('Activity'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<String> permissions,
  List<Uri> asked, {
  required bool shell,
}) async {
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
            'role': {'name': 'Auditor'},
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
      if (request.url.path.endsWith('/audit-events')) {
        asked.add(request.url);
        return _json({
          'data': [
            {
              'id': 'e-1',
              'occurred_at': '2026-10-16T14:00:00Z',
              'source': 'user',
              'actor': 'Dana',
              'action': 'lead.created',
              'target': 'Ann Smith',
            },
          ],
          'meta': {'more': false},
        });
      }
      return _json({'data': const <dynamic>[]});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: shell
            ? SettingsShell(api: api, session: session)
            : ActivityScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
