import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Integrations says WHOSE each connection is (Jose, 2026-09-22).
///
/// ── The screenshot that asked for this ──────────────────────────
///
/// He sent the live screen: fifty-three Google Ads rows and a column of
/// identical "A1 Chimney · Thumbtack · this company" entries, four pages of
/// scrolling. Every one of those rows was TRUE and none was useful, because
/// "this company" never said which company. His instruction closed with
/// *"Remember to take into account org - company - franchise ownership"*.
///
/// ── What these tests hold, and what they deliberately do not ────
///
/// Not that a card is drawn — `integrations_connect_gate_test` already opens
/// one. What is new is that the screen behind the card groups by SCOPE and
/// names the scope, so the load-bearing case is two connections of the SAME
/// provider at two DIFFERENT companies: the flat list drew those as two
/// identical rows, and anything that still does will fail here.
void main() {
  testWidgets('a card counts what is connected and where', (tester) async {
    await _pump(tester);

    expect(find.byKey(const ValueKey('provider:google_ads')), findsOneWidget);

    // Three at two companies and one at a branch. The count is the thing the
    // grid exists to answer in one glance, and it is what fifty-three rows of
    // scrolling used to answer.
    expect(find.textContaining('4 connected'), findsOneWidget);
  });

  testWidgets('the connections are grouped under the company that owns them', (
    tester,
  ) async {
    await _pump(tester);

    await _open(tester, 'google_ads');

    /*
     * THE CASE THE FLAT LIST COULD NOT DRAW.
     *
     * Two Google Ads accounts belong to A-1 Chimney and one to Everdoors
     * Garage. Before today both drew as "Google Ads · this company" and an
     * administrator had no way to tell which business either belonged to.
     */
    expect(find.text('A-1 Chimney'), findsOneWidget);
    expect(find.text('Everdoors Garage'), findsOneWidget);
    expect(find.text('Connecticut'), findsOneWidget);

    expect(find.text('Atlanta account'), findsOneWidget);
    expect(find.text('Austin account'), findsOneWidget);
    expect(find.text('Garage account'), findsOneWidget);
    expect(find.text('Branch account'), findsOneWidget);
  });

  testWidgets('an organization-wide connection is named for the organization', (
    tester,
  ) async {
    await _pump(tester);

    await _open(tester, 'mailchimp');

    // Not "the whole organization" — the organization has a NAME, and the
    // heading is the same word the switcher shows.
    expect(find.text('Everdoors'), findsOneWidget);
    expect(find.text('The list'), findsOneWidget);
  });

  testWidgets('a connection whose scope this view cannot name is still shown', (
    tester,
  ) async {
    /*
     * The failure worse than an unnamed heading, and the reason the grouping
     * ends with a catch-all rather than a filter.
     *
     * The SERVER decides what is in view; `/me` decides what this person's
     * switcher can name. They agree today. If one ever fell between them, a
     * screen whose entire purpose is inventory would silently stop listing
     * something that exists — so the sum of the groups always equals the count
     * on the card.
     */
    await _pump(tester);

    await _open(tester, 'thumbtack');

    expect(find.text('Elsewhere in this view'), findsOneWidget);
    expect(find.text('Orphan account'), findsOneWidget);
  });
}

Future<void> _open(WidgetTester tester, String provider) async {
  final card = find.byKey(ValueKey('provider:$provider'));

  expect(
    card,
    findsOneWidget,
    reason: 'No $provider card, so nothing below this proves anything.',
  );

  await tester.tap(card);
  await tester.pumpAndSettle();
}

Future<void> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/social/connections')) {
        return _json({'data': _connections});
      }

      if (path.endsWith('/social/providers')) {
        return _json({
          'data': const [
            {'value': 'google_ads', 'label': 'Google Ads'},
            {'value': 'mailchimp', 'label': 'Mailchimp'},
            {'value': 'thumbtack', 'label': 'Thumbtack'},
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
      home: Scaffold(body: IntegrationsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _connection({
  required String id,
  required String provider,
  required String label,
  required String scopeType,
  required String scopeId,
}) => {
  'id': id,
  'provider': provider,
  'provider_label': provider,
  'display_name': '$id-display',
  'label': label,
  'scope_type': scopeType,
  'scope_id': scopeId,
  'status': 'connected',
};

final _connections = [
  _connection(
    id: 'ga-1',
    provider: 'google_ads',
    label: 'Atlanta account',
    scopeType: 'company',
    scopeId: 'co-a1',
  ),
  _connection(
    id: 'ga-2',
    provider: 'google_ads',
    label: 'Austin account',
    scopeType: 'company',
    scopeId: 'co-a1',
  ),
  _connection(
    id: 'ga-3',
    provider: 'google_ads',
    label: 'Garage account',
    scopeType: 'company',
    scopeId: 'co-ev',
  ),
  _connection(
    id: 'ga-4',
    provider: 'google_ads',
    label: 'Branch account',
    scopeType: 'franchise',
    scopeId: 'fr-ct',
  ),
  _connection(
    id: 'mc-1',
    provider: 'mailchimp',
    label: 'The list',
    scopeType: 'organization',
    scopeId: 'org-1',
  ),
  // Deliberately points at a company `/me` does not carry.
  _connection(
    id: 'tt-1',
    provider: 'thumbtack',
    label: 'Orphan account',
    scopeType: 'company',
    scopeId: 'co-gone',
  ),
];

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view'],
  'modules': <dynamic>[],
  'companies': [
    {'id': 'co-a1', 'name': 'A-1 Chimney'},
    {'id': 'co-ev', 'name': 'Everdoors Garage'},
  ],
  'franchises': [
    {'id': 'fr-ct', 'name': 'Connecticut', 'company_id': 'co-a1'},
  ],
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

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
