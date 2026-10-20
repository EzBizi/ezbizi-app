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

    // BOTH CARDS ARE DRAWN — the card is inventory, the form is the act.
    // Somebody who may not connect a Workiz account may still see that one is
    // not connected, which is what this screen is for.
    expect(find.byKey(const ValueKey('provider:airtable')), findsOneWidget);
    expect(find.byKey(const ValueKey('provider:workiz')), findsOneWidget);

    await _open(tester, 'airtable');
    expect(find.text('Find bases'), findsOneWidget);

    await _back(tester);
    await _open(tester, 'workiz');
    expect(find.text('Connect account'), findsNothing);
  });

  // UI queue 120: the Workiz web login is its own provider key, drawn on
  // Workiz's page rather than as a second Workiz tile.
  testWidgets('the Workiz web login is listed on the one Workiz page', (
    tester,
  ) async {
    await _pump(
      tester,
      permissions: const ['reports.build', 'reports.experimental.view'],
      connections: const [
        {
          'id': 'w-1',
          'provider': 'workiz_web',
          'provider_label': 'Workiz web login',
          'display_name': 'Workiz web login',
          'scope_type': 'organization',
          'scope_id': 'org-1',
          'status': 'expired',
          'status_reason': 'Workiz signed this session out.',
        },
      ],
      moreProviders: const [
        {'value': 'workiz_web', 'label': 'Workiz web login'},
      ],
    );

    expect(find.byKey(const ValueKey('provider:workiz_web')), findsNothing);
    await _open(tester, 'workiz');
    expect(find.byKey(const ValueKey('connection:w-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('edit:w-1')), findsNothing);
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

    await _open(tester, 'workiz');
    expect(find.text('Connect account'), findsOneWidget);
    // The write key comes in with the token: a branch A1 Tools never held
    // (MA, 2026-10-05) has nowhere else to get one.
    // On v1, since UI queue 109 asks which API first (v2 has one key).
    await tester.tap(find.text('API v1'));
    await tester.pumpAndSettle();
    expect(find.text('API secret (optional)'), findsOneWidget);

    await _back(tester);
    await _open(tester, 'airtable');
    expect(find.text('Find bases'), findsNothing);
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

    await _open(tester, 'workiz');
    expect(find.text('Connect account'), findsNothing);
  });

  testWidgets('RingCentral is a Reporting card with its own JWT form', (
    tester,
  ) async {
    // Jose, 2026-10-15: "Shouldn't ring central also show here on
    // Integrations?" Read by the call report, so gated like Workiz.
    await _pump(
      tester,
      permissions: const ['reports.build', 'reports.experimental.view'],
    );

    expect(find.byKey(const ValueKey('provider:ringcentral')), findsOneWidget);
    expect(find.textContaining('Reporting ·'), findsOneWidget);

    await _open(tester, 'ringcentral');
    expect(find.text('Connect account'), findsOneWidget);
    expect(find.text('App ID'), findsOneWidget);
    expect(find.text('App secret'), findsOneWidget);
    expect(find.text('Sign-in key'), findsOneWidget);
  });

  testWidgets('the RingCentral form is not offered without the report', (
    tester,
  ) async {
    await _pump(tester, permissions: const ['reports.build']);

    await _open(tester, 'ringcentral');
    expect(find.text('Connect account'), findsNothing);
  });

  testWidgets('somebody with neither is offered no reporting form at all', (
    tester,
  ) async {
    await _pump(tester, permissions: const ['marketing.edit']);

    await _open(tester, 'airtable');
    expect(find.text('Find bases'), findsNothing);

    await _back(tester);
    await _open(tester, 'workiz');
    expect(find.text('Connect account'), findsNothing);
  });
}

/// Open a provider's card, which is where the forms live since 2026-09-22.
///
/// The form used to be under the family heading on the grid itself. Jose asked
/// for "cards instead each with their own screen for connections", so the
/// affordance moved one tap deeper — and these tests navigate rather than
/// asserting on a screen that no longer draws it. A suite that had been
/// loosened to `findsNothing` everywhere would have passed against a screen
/// with no forms at all.
Future<void> _open(WidgetTester tester, String provider) async {
  final card = find.byKey(ValueKey('provider:$provider'));

  expect(
    card,
    findsOneWidget,
    reason: 'No $provider card to open, so nothing below this proves anything.',
  );

  await tester.tap(card);
  await tester.pumpAndSettle();
}

/// Back to the grid — a BUTTON IN THE BODY, not a route pop.
///
/// `tester.pageBack()` was right while the pane was a pushed route, and Jose
/// named that as the defect on 2026-09-23: "every single Integration Card
/// violates the containment rule by going full screen when clicked". There is
/// no route to pop now, so a test still calling pageBack would fail for the
/// right reason and for the wrong one at once.
Future<void> _back(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('integration-back')));
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>> connections = const [],
  List<Map<String, dynamic>> moreProviders = const [],
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
        return _json({'data': connections});
      }

      // The catalogue is what draws a card for a provider with NOTHING
      // connected, and both reporting sources are that case here. Without it
      // there is no card to open and every assertion below would pass against
      // a screen that simply has no reporting section.
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': [
            const {'value': 'airtable', 'label': 'Airtable'},
            const {'value': 'workiz', 'label': 'Workiz'},
            const {'value': 'ringcentral', 'label': 'RingCentral'},
            ...moreProviders,
          ],
        });
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
