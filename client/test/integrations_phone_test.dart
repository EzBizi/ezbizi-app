import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:ezbizi_client/screens/settings/integration_provider_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Integrations on a phone (UI/UX, 2026-09-22 — Jose picked desktop 1 + phone 4).
///
/// ── Why this file exists ────────────────────────────────────────
///
/// The three existing integrations suites all pump at **1280 wide**. Every one
/// of them is about what the screen SAYS — whose the accounts are, which forms
/// are gated — and none about whether it fits. A card carrying a provider mark,
/// a status pill, a name and a two-line scope sentence is the widest thing on
/// this screen, and 375 is the width the rules require it to survive.
///
/// The redesign landed with no phone coverage at all, which is the ordinary
/// way a screen ships broken at one width: nobody chose to skip it, the
/// fixtures simply inherited a desktop number from the suite they were copied
/// from.
///
/// ── What an overflow looks like here ────────────────────────────
///
/// Flutter reports overflow as an exception during paint, so a test that only
/// asserts text is present passes over it — the words are in the tree either
/// way. `tester.takeException()` is what actually reads the verdict.
void main() {
  testWidgets('the cards fit a 375pt phone', (tester) async {
    await _pump(tester, width: 375);

    expect(
      find.byKey(const ValueKey('provider:google_ads')),
      findsOneWidget,
      reason: 'No Google Ads card, so an overflow check below proves nothing.',
    );

    expect(
      tester.takeException(),
      isNull,
      reason: 'The integrations cards overflowed at phone width.',
    );
  });

  /// Pumped DIRECTLY rather than navigated to, and that is the point.
  ///
  /// The first version of this tapped through from the cards and asserted
  /// `takeException()` was null. It reported the provider screen overflowing —
  /// and it was reading the CARD screen's exception, which is still mounted
  /// under a pushed route. One fix to `_providerCard` turned this test green
  /// without a line of the provider screen changing, which is how I found it.
  ///
  /// A screen under test has to be the only screen in the tree, or a shared
  /// verdict like "did anything throw" belongs to whichever screen threw.
  testWidgets('the provider screen fits a 375pt phone on its own', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((r) async => _json({'data': _me})),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        // A Scaffold around it, because the pane no longer carries one:
        // Jose named the full-screen push as a containment break on
        // 2026-09-23, so it is rendered in the app shell's body and takes its
        // Material from whatever already wraps it. Here that is this Scaffold.
        home: Scaffold(
          body: IntegrationProviderScreen(
            api: api,
            session: session,
            provider: 'google_ads',
            providerLabel: 'Google Ads',
            connections: _connections
                .map(IntegrationConnection.fromJson)
                .where((c) => c.provider == 'google_ads')
                .toList(),
            onBack: () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('A-1 Chimney'), findsWidgets);
    expect(
      tester.takeException(),
      isNull,
      reason: 'The per-provider screen overflowed at phone width.',
    );
  });

  /// Jose, 2026-10-19: the Workiz card said neither which API a key speaks
  /// nor whether its history came in. The server's `detail` line says both.
  testWidgets('a Workiz row says which API and how far its history got', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((r) async => _json({'data': _me})),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: IntegrationProviderScreen(
            api: api,
            session: session,
            provider: 'workiz',
            providerLabel: 'Workiz',
            connections: [
              IntegrationConnection.fromJson({
                'id': 'w1',
                'provider': 'workiz',
                'provider_label': 'Workiz',
                'display_name': 'Connecticut',
                'scope_type': 'franchise',
                'scope_id': 'f1',
                'status': 'connected',
                'detail': 'API v1 · importing history (300 jobs read)',
              }),
            ],
            onBack: () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.textContaining('API v1 · importing history (300 jobs read)'),
      findsOneWidget,
    );
  });

  /// The whole point of the redesign, asserted at the width it is least likely
  /// to survive: the company is NAMED rather than called "this company".
  testWidgets('a phone still names the company, not "this company"', (
    tester,
  ) async {
    await _pump(tester, width: 375);

    await tester.tap(find.byKey(const ValueKey('provider:google_ads')));
    await tester.pumpAndSettle();

    expect(find.textContaining('A-1 Chimney'), findsWidgets);
    expect(
      find.textContaining('this company'),
      findsNothing,
      reason:
          'The phrase the redesign exists to remove came back at phone width.',
    );
  });

  /// The wall of cards, and the one family that must keep them.
  ///
  /// EzBizi offers seventeen providers and a business connects three. Before
  /// this, a catalogue of fifteen drew FIFTEEN cards with THIRTEEN reading
  /// "Set up" — measured, not estimated — which is the same four-pages-of-
  /// scrolling Jose photographed, rebuilt in a nicer shape.
  ///
  /// The second assertion is the one that matters and is a positive control on
  /// the first: Reporting has no "connect it over there" pointer, so tapping
  /// an unconnected Airtable card IS the only route to its connect form.
  /// Tidying that family the same way would remove the only door, and nothing
  /// else in the suite would notice.
  /// Jose, 2026-10-13 (UI queue 82): "i should be able to see all the
  /// options so i can connected them all if i wanted." This reverses the
  /// rule it replaces, which drew only the providers a business uses.
  testWidgets('every provider is a card, connected or not', (tester) async {
    await _pumpCatalogue(tester);

    Finder cards() => find.byWidgetPredicate(
      (w) =>
          w is InkWell &&
          w.key is ValueKey &&
          (w.key as ValueKey).value.toString().startsWith('provider:'),
    );

    // The catalogue's fifteen, each once: the two connected ones among them
    // rather than beside them.
    expect(cards(), findsNWidgets(15));
    expect(
      find.byKey(const ValueKey('status-dot:Not connected')),
      findsNWidgets(13),
    );
    expect(find.byKey(const ValueKey('provider:linkedin')), findsOneWidget);
    expect(find.textContaining('Also available:'), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester, {required double width}) async {
  // Tall, so that a column which would scroll on a real phone is fully laid
  // out here: an overflow hidden below the fold is still an overflow, and a
  // short viewport would simply not build the widgets that carry it.
  tester.view.physicalSize = Size(width, 2400);
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
            {'value': 'google_business_profile', 'label': 'Business Profile'},
          ],
        });
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue, reason: 'the /me stub made no session');

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

/// The real catalogue, near enough: fifteen providers against two connections.
Future<void> _pumpCatalogue(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 4000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  const all = [
    'facebook_page',
    'instagram_professional',
    'threads',
    'linkedin',
    'tiktok',
    'pinterest',
    'youtube',
    'x',
    'bluesky',
    'google_business_profile',
    'google_ads',
    'thumbtack',
    'mailchimp',
    'workiz',
    'airtable',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/social/connections')) {
        return _json({'data': _connections});
      }
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': [
            for (final p in all) {'value': p, 'label': p},
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
}

Map<String, dynamic> _connection({
  required String id,
  required String provider,
  required String scopeType,
  required String scopeId,
  bool connected = true,
}) => {
  'id': id,
  'provider': provider,
  'provider_label': provider,
  'display_name': '$id-display',
  'label': null,
  'scope_type': scopeType,
  'scope_id': scopeId,
  'status': connected ? 'connected' : 'disconnected',
  'status_reason': connected ? null : 'Reauthorise — the token expired',
};

/// Several scopes and one broken row, because the status line that leads with
/// trouble is also the LONGEST sentence a card can draw — "1 of 4 need
/// attention at the organization, a company, a branch" — and the short happy
/// one would fit anywhere.
final _connections = [
  _connection(
    id: 'ga-1',
    provider: 'google_ads',
    scopeType: 'company',
    scopeId: 'co-a1',
    connected: false,
  ),
  _connection(
    id: 'ga-2',
    provider: 'google_ads',
    scopeType: 'company',
    scopeId: 'co-a1',
  ),
  _connection(
    id: 'ga-3',
    provider: 'google_ads',
    scopeType: 'organization',
    scopeId: 'org-1',
  ),
  _connection(
    id: 'ga-4',
    provider: 'google_ads',
    scopeType: 'franchise',
    scopeId: 'fr-ct',
  ),
  _connection(
    id: 'tt-1',
    provider: 'thumbtack',
    scopeType: 'company',
    scopeId: 'co-ev',
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
