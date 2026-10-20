import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Business Profile — the listings the public sees.
///
/// The case worth a test is the one nobody would notice going wrong: a listing
/// Google has stopped showing looks exactly like a healthy one in a list of
/// names. If it is not marked, the screen is lying by omission, and the way
/// anybody finds out is a customer saying they could not find the business.
///
/// Every name here is invented.
void main() {
  const connected = [
    {
      'provider': 'google_business_profile',
      'display_name': 'Brightside',
      'status': 'connected',
    },
  ];

  final showing = <String, dynamic>{
    'id': 'loc-1',
    'title': 'Brightside Home Services',
    'label': 'Brightside Home Services · Houston, TX',
    'address': '1 Riverway Dr, Suite 1717, Houston, TX, 77056',
    'phone': '+12035550142',
    'website': 'https://brightside.example/springfield',
    'primary_category': 'Chimney services',
    'verification_state': 'VERIFIED',
    'is_published': true,
    'auto_reply': true,
    'is_live': true,
    'synced_at': null,
    'company': {'id': 'co-1', 'name': 'Brightside'},
    'franchise': {'id': 'fr-1', 'name': 'Springfield'},
  };

  final suspended = <String, dynamic>{
    'id': 'loc-2',
    'title': 'Brightside Home Services',
    'label': 'Brightside Home Services · Portland, OR',
    'address': '9 Harbor Way, Portland, OR, 97201',
    'phone': '+12035550164',
    'website': null,
    'primary_category': 'Chimney sweep',
    'verification_state': 'SUSPENDED',
    'is_published': true,
    'auto_reply': false,
    'is_live': false,
    'synced_at': null,
    'company': {'id': 'co-1', 'name': 'Brightside'},
    'franchise': {'id': 'fr-2', 'name': 'Northgate'},
  };

  final unfiled = <String, dynamic>{
    'id': 'loc-3',
    'title': 'Brightside Home Services',
    'label': 'Brightside Home Services · Dallas, TX',
    'address': '40 Elm St, Dallas, TX, 75201',
    'phone': '+12035550155',
    'primary_category': 'Chimney sweep',
    'verification_state': 'VERIFIED',
    'is_published': true,
    'auto_reply': false,
    'is_live': true,
    'company': {'id': 'co-1', 'name': 'Brightside'},
    'franchise': null,
  };

  testWidgets('a listing Google is not showing is marked, not just listed', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
    );

    // Google's own word, in the error tone — SUSPENDED and UNVERIFIED need
    // different fixes, so collapsing them to "problem" would help nobody.
    final pill = tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-row-loc-2')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(pill.label, 'Suspended');
    expect(pill.tone, Tone.bad);
    expect(find.text('Not showing · 1'), findsOneWidget);
    expect(find.text('Brightside · Springfield'), findsOneWidget);
  });

  testWidgets('a row is led by its branch, and an unfiled one says so', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, unfiled],
      notLive: 0,
      connections: connected,
    );

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-row-loc-1')),
        matching: find.text('Brightside · Springfield'),
      ),
      findsOneWidget,
    );
    // No branch: Google's own label, and the row admits it is unfiled.
    final row = find.byKey(const ValueKey('gbp-row-loc-3'));
    expect(
      find.descendant(
        of: row,
        matching: find.text('Brightside Home Services · Dallas, TX'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: row, matching: find.text('Not filed under a branch')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-row-loc-1')),
        matching: find.text('Not filed under a branch'),
      ),
      findsNothing,
    );
  });

  testWidgets('not showing sorts first, on an error-tinted row', (
    tester,
  ) async {
    // The server orders by title; the suspended one arrives last.
    await _pump(
      tester,
      locations: [showing, unfiled, suspended],
      notLive: 1,
      connections: connected,
    );

    final ys = {
      for (final id in ['loc-1', 'loc-2', 'loc-3'])
        id: tester.getTopLeft(find.byKey(ValueKey('gbp-row-$id'))).dy,
    };
    expect(ys['loc-2']!, lessThan(ys['loc-1']!));
    expect(ys['loc-2']!, lessThan(ys['loc-3']!));

    final context = tester.element(find.byKey(const ValueKey('gbp-row-loc-2')));
    final tint = ToneColours.of(context, Tone.bad).fill;
    expect(
      tester
          .widget<Material>(find.byKey(const ValueKey('gbp-row-loc-2')))
          .color,
      tint,
    );
    expect(
      tester
          .widget<Material>(find.byKey(const ValueKey('gbp-row-loc-1')))
          .color,
      isNot(tint),
    );
  });

  testWidgets('the counts and the segment filter the rows', (tester) async {
    await _pump(
      tester,
      locations: [showing, unfiled, suspended],
      notLive: 1,
      connections: connected,
    );

    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('gbp-counts')))
          .textSpan!
          .toPlainText(),
      '3 listings · 2 showing · 1 not showing',
    );
    expect(find.text('Showing · 2'), findsOneWidget);
    expect(find.text('Not showing · 1'), findsOneWidget);

    // All is the default.
    expect(find.byKey(const ValueKey('gbp-row-loc-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsOneWidget);

    await tester.tap(find.text('Not showing · 1'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-row-loc-1')), findsNothing);
    expect(find.byKey(const ValueKey('gbp-row-loc-3')), findsNothing);

    await tester.tap(find.text('Showing · 2'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsNothing);
    expect(find.byKey(const ValueKey('gbp-row-loc-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-row-loc-3')), findsOneWidget);
  });

  testWidgets('the search matches a city or an address', (tester) async {
    await _pump(
      tester,
      locations: [showing, unfiled, suspended],
      notLive: 1,
      connections: connected,
    );

    await tester.enterText(
      find.byKey(const ValueKey('gbp-search')),
      'portland',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-row-loc-1')), findsNothing);
    expect(find.byKey(const ValueKey('gbp-row-loc-3')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('gbp-search')), 'elm st');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-row-loc-3')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('gbp-search')), 'nowhere');
    await tester.pumpAndSettle();
    expect(find.text('No listings match'), findsOneWidget);
  });

  testWidgets('opening a row shows its details in a pane beside the table', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
    );

    expect(find.byKey(const ValueKey('gbp-pane')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();

    final pane = find.byKey(const ValueKey('gbp-pane'));
    expect(pane, findsOneWidget);
    // Beside, not instead: the table is still there.
    expect(find.byKey(const ValueKey('gbp-row-loc-2')), findsOneWidget);

    Finder inPane(String text) =>
        find.descendant(of: pane, matching: find.text(text));
    expect(
      inPane('1 Riverway Dr, Suite 1717, Houston, TX, 77056'),
      findsOneWidget,
    );
    expect(inPane('https://brightside.example/springfield'), findsOneWidget);
    expect(inPane('Chimney services · verified'), findsOneWidget);
    expect(inPane('Showing on Google'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-pane-branch')),
        matching: find.text('Brightside · Springfield'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-pane-auto-reply')),
        matching: find.text('On'),
      ),
      findsOneWidget,
    );
    // The list sends no Maps URL, so there is no link to invent.
    expect(inPane('Open in Google'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('gbp-pane-close')));
    await tester.pumpAndSettle();
    expect(pane, findsNothing);
  });

  testWidgets('on a phone a listing is a card that opens in place', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
      size: const Size(390, 844),
    );

    expect(find.byKey(const ValueKey('gbp-row-loc-1')), findsNothing);
    final card = find.byKey(const ValueKey('gbp-card-loc-2'));
    expect(card, findsOneWidget);
    expect(
      find.descendant(of: card, matching: find.text('Brightside · Northgate')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.text('Suspended')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: card,
        matching: find.text('Portland, OR · +1 (203) 555-0164'),
      ),
      findsOneWidget,
    );
    // Not showing first here too.
    expect(
      tester.getTopLeft(card).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const ValueKey('gbp-card-loc-1'))).dy,
      ),
    );

    await tester.tap(card);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-pane')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-card-loc-1')), findsNothing);
    expect(find.text('9 Harbor Way, Portland, OR, 97201'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('gbp-pane-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-pane')), findsNothing);
    expect(find.byKey(const ValueKey('gbp-card-loc-1')), findsOneWidget);
  });

  testWidgets('every tour anchor is on screen', (tester) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
    );

    for (final anchor in [
      'tour-business-profile-not-showing',
      'tour-customer_experience-check-google',
      'tour-business-profile-account',
      'tour-business-profile-listings',
    ]) {
      expect(find.byKey(ValueKey(anchor)), findsOneWidget, reason: anchor);
    }
  });

  testWidgets('with nothing connected the empty state offers to connect', (
    tester,
  ) async {
    await _pump(tester, locations: const [], notLive: 0, connections: const []);

    expect(find.text('No Business Profile connected'), findsOneWidget);
    expect(find.text('Connect Business Profile'), findsOneWidget);
  });

  testWidgets('connected with every listing showing does not read as a fault', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing],
      notLive: 0,
      connections: connected,
    );

    await tester.tap(find.text('Not showing · 0'));
    await tester.pumpAndSettle();

    expect(find.text('No Business Profile connected'), findsNothing);
    expect(find.text('Every listing is showing'), findsOneWidget);
  });

  testWidgets('connected with no listings yet says so', (tester) async {
    await _pump(
      tester,
      locations: const [],
      notLive: 0,
      connections: connected,
    );

    expect(find.text('No Business Profile connected'), findsNothing);
    expect(find.text('No listings yet'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> locations,
  required int notLive,
  required List<Map<String, dynamic>> connections,
  Size size = const Size(1200, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.contains('/business-profile/locations')) {
        return http.Response(
          jsonEncode({
            'data': locations,
            'meta': {
              'total': locations.length,
              'not_live': notLive,
              'connections': connections,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{}',
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BusinessProfileScreen(api: api, session: Session(api)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
