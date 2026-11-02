import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile_screen.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
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
      ).first, // the Google column; WhatsApp's pill comes after it
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

  // UI queue 164: WhatsApp is a column with ticks and one bar, no screen.
  testWidgets('WhatsApp: tick a missing listing, pick a number, set it', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing],
      notLive: 0,
      connections: connected,
    );
    expect(find.byKey(const ValueKey('cx-open-wa-bulk')), findsNothing);
    expect(find.byKey(const ValueKey('gbp-wa-bar')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('gbp-wa-pick-missing')));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<Checkbox>(find.byKey(const ValueKey('gbp-wa-tick-loc-1')))
          .value,
      isTrue,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-wa-number-+15550100142')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('gbp-wa-apply')));
    await tester.pumpAndSettle();

    final apply = _sent.lastWhere((r) => r.url.path.endsWith('/apply'));
    expect(jsonDecode(apply.body), {
      'targets': ['loc-1'],
      'link': 'https://wa.me/15550100142',
    });
    expect(find.byKey(const ValueKey('gbp-wa-result-loc-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('gbp-wa-bar')), findsNothing);
  });

  testWidgets('Phone check and the Google connection sit under more', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing],
      notLive: 0,
      connections: connected,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-google-connection')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('cx-open-phone-check')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-more')), findsNothing);
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

  testWidgets('auto-reply is a switch that PUTs the new value', (tester) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
      permissions: const ['business_profile.edit', 'reviews.publish'],
    );

    final rowSwitch = find.byKey(const ValueKey('gbp-row-auto-reply-loc-2'));
    expect(tester.widget<Switch>(rowSwitch).value, isFalse);

    await tester.tap(rowSwitch);
    await tester.pumpAndSettle();

    final put = _sent.singleWhere((r) => r.method == 'PUT');
    expect(
      put.url.path,
      endsWith('/business-profile/locations/loc-2/auto-reply'),
    );
    expect(jsonDecode(put.body), {'on': true});
    expect(tester.widget<Switch>(rowSwitch).value, isTrue);
    // The switch is not a row tap: no pane opened.
    expect(find.byKey(const ValueKey('gbp-pane')), findsNothing);

    // The same switch in the pane.
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    final paneSwitch = find.byKey(const ValueKey('gbp-pane-auto-reply-loc-1'));
    expect(tester.widget<Switch>(paneSwitch).value, isTrue);
    await tester.tap(paneSwitch);
    await tester.pumpAndSettle();
    expect(jsonDecode(_sent.where((r) => r.method == 'PUT').last.body), {
      'on': false,
    });
    expect(tester.widget<Switch>(paneSwitch).value, isFalse);
  });

  testWidgets('the pane files a listing under another branch with a PATCH', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
      permissions: const ['business_profile.edit'],
    );

    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('gbp-pane-branch-picker')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(Dialog),
        matching: find.text('Brightside · Northgate'),
      ),
    );
    await tester.pumpAndSettle();

    final patch = _sent.singleWhere((r) => r.method == 'PATCH');
    expect(patch.url.path, endsWith('/business-profile/locations/loc-1'));
    expect(jsonDecode(patch.body), {'franchise_id': 'fr-2'});
    // The row now leads with the branch the server answered with.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('gbp-row-loc-1')),
        matching: find.text('Brightside · Northgate'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('without the grants both stay read-only and say why', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing, suspended],
      notLive: 1,
      connections: connected,
      permissions: const ['business_profile.view'],
    );

    expect(find.byType(Switch), findsNothing);
    expect(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('gbp-row-auto-reply-text-loc-1')),
          )
          .data,
      'On',
    );

    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    expect(find.byType(Switch), findsNothing);
    final picker = find.byKey(const ValueKey('gbp-pane-branch-picker'));
    expect(tester.widget<SearchPickerField<String>>(picker).onChanged, isNull);
    expect(
      find.text(
        'Your role cannot change which branch a listing is filed under.',
      ),
      findsOneWidget,
    );

    await tester.tap(picker);
    await tester.pumpAndSettle();
    expect(find.byType(Dialog), findsNothing);
    expect(_sent.where((r) => r.method != 'GET'), isEmpty);
  });

  testWidgets('editing without reviews.publish leaves auto-reply as text', (
    tester,
  ) async {
    await _pump(
      tester,
      locations: [showing],
      notLive: 0,
      connections: connected,
      permissions: const ['business_profile.edit'],
    );

    expect(find.byType(Switch), findsNothing);
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    expect(find.byType(Switch), findsNothing);
    expect(
      tester
          .widget<SearchPickerField<String>>(
            find.byKey(const ValueKey('gbp-pane-branch-picker')),
          )
          .onChanged,
      isNotNull,
    );
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

/// The requests the screen sent, for the tests that change a listing.
final _sent = <http.Request>[];

/// [permissions] null: nobody signed in, as the read-only tests have always
/// run. Otherwise `/me` answers with those grants and two branches.
Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> locations,
  required int notLive,
  required List<Map<String, dynamic>> connections,
  Size size = const Size(1200, 900),
  List<String>? permissions,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  _sent.clear();

  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: {'content-type': 'application/json'},
  );

  final api = ApiClient(
    client: MockClient((request) async {
      _sent.add(request);
      final path = request.url.path;
      final segments = request.url.pathSegments;

      if (path.endsWith('/me') && permissions != null) {
        return json({
          'data': {
            'id': 'u-1',
            'name': 'Robin Vale',
            'email': 'robin@brightside.example',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Manager'},
            'permissions': permissions,
            'modules': <dynamic>[],
            'preferences': <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'Brightside Group',
              'franchise_label': 'Branch',
              'is_read_only': false,
            },
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Brightside Group',
              'path': ['Brightside Group'],
            },
            'companies': [
              {'id': 'co-1', 'name': 'Brightside'},
            ],
            'franchises': [
              {'id': 'fr-1', 'name': 'Springfield', 'company_id': 'co-1'},
              {'id': 'fr-2', 'name': 'Northgate', 'company_id': 'co-1'},
            ],
          },
        });
      }

      if (path.endsWith('/business-profile/whatsapp-links')) {
        return json({
          'proposed': 'https://wa.me/15550100100',
          'numbers': [
            {'number': '+15550100100', 'link': 'https://wa.me/15550100100'},
            {'number': '+15550100142', 'link': 'https://wa.me/15550100142'},
          ],
          'data': [
            for (final l in locations)
              {'id': l['id'], 'current': null, 'state': 'missing'},
          ],
        });
      }
      if (path.endsWith('/business-profile/whatsapp-links/apply')) {
        final targets = (jsonDecode(request.body) as Map)['targets'] as List;
        return json({
          'data': [
            for (final t in targets) {'id': t, 'ok': true},
          ],
        });
      }

      // The server answers with the whole row, re-presented.
      if (request.method == 'PATCH') {
        final franchiseId =
            (jsonDecode(request.body) as Map)['franchise_id'] as String?;
        final row = locations.firstWhere((l) => l['id'] == segments.last);
        return json({
          'data': {
            ...row,
            'franchise': franchiseId == null
                ? null
                : {
                    'id': franchiseId,
                    'name': franchiseId == 'fr-2' ? 'Northgate' : 'Springfield',
                  },
          },
        });
      }

      if (request.method == 'PUT' && segments.last == 'auto-reply') {
        return json({
          'data': {
            'id': segments[segments.length - 2],
            'auto_reply': (jsonDecode(request.body) as Map)['on'],
          },
        });
      }

      if (path.contains('/business-profile/locations')) {
        return json({
          'data': locations,
          'meta': {
            'total': locations.length,
            'not_live': notLive,
            'connections': connections,
          },
        });
      }

      return json(<String, dynamic>{});
    }),
  );

  final session = Session(api);
  if (permissions != null) await session.refresh();

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BusinessProfileScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
