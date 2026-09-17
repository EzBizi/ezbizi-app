import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Who is offered the reporting Connect forms.
///
/// ── The two are DIFFERENT permissions, and were not always ──────
///
/// Connecting an Airtable base follows `profit_loss.view` — the screen that
/// reads it — and a Workiz account follows `reports.build`, the Experimental
/// report. They agreed until Profit & Loss became a module of its own on
/// 2026-09-17, and showing both to everybody was survivable only while they
/// did.
///
/// Absent rather than disabled, and never shown-then-refused: a form that takes
/// somebody's personal access token and comes back with a permission error has
/// spent their time and told them the app is for other people.
void main() {
  testWidgets('Profit & Loss opens the Airtable form and not the Workiz one', (
    tester,
  ) async {
    await _pump(tester, permissions: const ['profit_loss.view']);

    expect(find.text('Airtable'), findsOneWidget);
    expect(find.text('Connect base'), findsOneWidget);

    expect(find.text('Workiz'), findsNothing);
    expect(find.text('Connect account'), findsNothing);
  });

  testWidgets('reports.build opens the Workiz form and not the Airtable one', (
    tester,
  ) async {
    // The case that distinguishes the change: this is the permission that
    // opened BOTH until today, so a gate that had not moved would still draw
    // the Airtable form here.
    //
    // The tab comes with it because the Workiz form needs BOTH — see the test
    // below for why `reports.build` on its own is no longer enough.
    await _pump(
      tester,
      permissions: const ['reports.build', 'reports.experimental.view'],
    );

    expect(find.text('Workiz'), findsOneWidget);
    expect(find.text('Connect account'), findsOneWidget);

    expect(find.text('Airtable'), findsNothing);
    expect(find.text('Connect base'), findsNothing);
  });

  testWidgets('the Workiz form needs the tab as well as the capability', (
    tester,
  ) async {
    // Measured 2026-09-17: a role with `reports.build` and no experimental tab
    // reaches the CONNECT endpoint and is refused the pane it would be
    // connecting for — connect answered 422, the report answered 403. Offering
    // the form there asks somebody for an API token for a screen they cannot
    // open, which is the exact thing SocialProvider's own rule forbids.
    //
    // The server permission is deliberately unchanged. `reports.build` is the
    // right authorisation floor, no single connect key can say "both of
    // these", and widening the controller so one key would do it would hand
    // the pane to everybody holding `reports.view`.
    await _pump(tester, permissions: const ['reports.build']);

    expect(find.text('Workiz'), findsNothing);
    expect(find.text('Connect account'), findsNothing);
  });

  testWidgets('somebody with neither is offered no reporting form at all', (
    tester,
  ) async {
    await _pump(tester, permissions: const ['marketing.edit']);

    expect(find.text('Connect base'), findsNothing);
    expect(find.text('Connect account'), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> permissions,
}) async {
  tester.view.physicalSize = const Size(1280, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions, 'modules': const []},
        });
      }

      if (path.endsWith('/social/connections')) {
        return _json({'data': const []});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

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

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
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
};
