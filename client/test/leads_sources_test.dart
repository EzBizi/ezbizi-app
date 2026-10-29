import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_accounts.dart';
import 'package:ezbizi_client/widgets/leads_sources.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Leads › Manage › Connections, every source (design 156, options 1 + 4),
/// against `GET /leads/sources` (server 6aff39145).
final _sent = <({String method, String path, Object? body})>[];
var _sourceReads = 0;

Future<void> _openSite(WidgetTester tester, String id) async {
  await tester.tap(find.byKey(const ValueKey('provider-web')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(ValueKey('site-$id')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the source list holds every source with its count and a dot '
      'where something needs attention', (tester) async {
    await _pump(tester);

    for (final key in [
      'google_ads',
      'thumbtack',
      'web',
      'whatsapp',
      'facebook',
      'instagram',
      'google_business_profile',
    ]) {
      expect(
        find.byKey(ValueKey('provider-$key')),
        findsOneWidget,
        reason: key,
      );
    }
    expect(find.text('2 sites'), findsOneWidget);
    expect(find.text('1 number'), findsOneWidget);
    expect(find.byKey(const ValueKey('attention-web')), findsOneWidget);
    expect(find.byKey(const ValueKey('attention-whatsapp')), findsNothing);
  });

  testWidgets('a source the server leaves out is not listed', (tester) async {
    await _pump(tester, data: {'web': _web});

    expect(find.byKey(const ValueKey('provider-web')), findsOneWidget);
    expect(find.byKey(const ValueKey('provider-instagram')), findsNothing);
    expect(find.byKey(const ValueKey('provider-google_ads')), findsOneWidget);
  });

  testWidgets('the website table lists each site with its columns, and an '
      'outdated plugin wears the update chip', (tester) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('provider-web')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('website-search')), findsOneWidget);
    expect(find.text('brightside.example'), findsOneWidget);
    expect(find.text('riverton.brightside.example'), findsOneWidget);
    expect(find.text('Primary'), findsOneWidget);
    expect(find.text('Brightside · Springfield'), findsOneWidget);
    expect(find.text('Booking unknown'), findsOneWidget);
    expect(find.text('38'), findsOneWidget);
    expect(find.byKey(const ValueKey('site-update-s-1')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('site-update-s-2')),
      findsNothing,
      reason: 'a current plugin has no update chip',
    );

    await tester.enterText(
      find.byKey(const ValueKey('website-search')),
      'riverton',
    );
    await tester.pumpAndSettle();
    expect(find.text('brightside.example'), findsNothing);
    expect(find.text('riverton.brightside.example'), findsOneWidget);
  });

  testWidgets('each flag draws its control', (tester) async {
    await _pump(tester);
    await _openSite(tester, 's-2');

    expect(find.byKey(const ValueKey('website-panel')), findsOneWidget);
    expect(find.text('Leads go to'), findsOneWidget);
    expect(find.text('Company · Branch'), findsOneWidget);
    expect(find.byKey(const ValueKey('site-chat')), findsOneWidget);
    expect(find.byKey(const ValueKey('site-make-primary')), findsOneWidget);
    expect(find.textContaining('Last seen'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('website-panel')),
        matching: find.text('Booking unknown'),
      ),
      findsOneWidget,
    );
    expect(
      find.text('Booking is a block on the site\'s pages.'),
      findsOneWidget,
    );
    expect(find.text('Changes come with the next update'), findsNothing);
  });

  testWidgets('without its flags the panel shows values and no controls', (
    tester,
  ) async {
    await _pump(tester, flags: false);
    await _openSite(tester, 's-2');

    expect(find.byKey(const ValueKey('site-chat')), findsNothing);
    expect(find.text('Chat on'), findsOneWidget);
    expect(find.byType(SearchPickerField<String>), findsNothing);
    expect(find.text('Brightside · Riverton'), findsWidgets);
    expect(find.byKey(const ValueKey('site-make-primary')), findsNothing);
  });

  testWidgets('the Chat switch sends the whole config back with only chat '
      'flipped, then reloads', (tester) async {
    await _pump(tester);
    await _openSite(tester, 's-2');
    final reads = _sourceReads;

    await tester.tap(find.byKey(const ValueKey('site-chat')));
    await tester.pumpAndSettle();

    final put = _sent.singleWhere((r) => r.method == 'PUT');
    expect(put.path, '/api/v1/web/sites/s-2/widgets');
    expect(put.body, {
      'config': {
        'chat': {'enabled': false, 'greeting': 'Hi there'},
        'booking': {'enabled': true, 'slots': 3},
        'theme': 'light',
      },
    });
    expect(_sourceReads, reads + 1);
  });

  testWidgets('the branch picker patches franchise_id', (tester) async {
    await _pump(tester);
    await _openSite(tester, 's-2');

    await tester.tap(find.byType(SearchPickerField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Brightside · Springfield').last);
    await tester.pumpAndSettle();

    final patch = _sent.singleWhere((r) => r.method == 'PATCH');
    expect(patch.path, '/api/v1/web/sites/s-2');
    expect(patch.body, {'franchise_id': 'fr-1'});
  });

  testWidgets('Make primary posts, then reloads', (tester) async {
    await _pump(tester);
    await _openSite(tester, 's-2');
    final reads = _sourceReads;

    await tester.tap(find.byKey(const ValueKey('site-make-primary')));
    await tester.pumpAndSettle();

    expect(
      _sent.where((r) => r.method == 'POST').single.path,
      '/api/v1/web/sites/s-2/primary',
    );
    expect(_sourceReads, reads + 1);
  });

  testWidgets('a refusal is shown in the server\'s words', (tester) async {
    await _pump(tester, refusal: 'Only an owner can change the primary site.');
    await _openSite(tester, 's-2');
    final reads = _sourceReads;

    await tester.tap(find.byKey(const ValueKey('site-make-primary')));
    await tester.pumpAndSettle();

    expect(
      find.text('Only an owner can change the primary site.'),
      findsOneWidget,
    );
    expect(_sourceReads, reads, reason: 'a refused save reloads nothing');
  });

  testWidgets('Google Business Profile lists its listings and says why no '
      'leads come from them', (tester) async {
    await _pump(tester);
    await tester.tap(
      find.byKey(const ValueKey('provider-google_business_profile')),
    );
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Google ended Business Profile chat in 2024, so listings bring no '
        'leads here.',
      ),
      findsOneWidget,
    );
    expect(find.text('Brightside Springfield'), findsOneWidget);
    expect(find.text('Verified'), findsOneWidget);
    expect(find.byKey(const ValueKey('source-search')), findsOneWidget);
  });

  testWidgets('WhatsApp lists its numbers', (tester) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('provider-whatsapp')));
    await tester.pumpAndSettle();

    expect(find.text('Brightside main line'), findsOneWidget);
    expect(find.textContaining('+1 555 0100'), findsOneWidget);
  });

  testWidgets('a phone steps from sources to sites to one site', (
    tester,
  ) async {
    await _pump(tester, size: const Size(400, 800));
    await tester.tap(find.byKey(const ValueKey('provider-web')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('website-search')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('site-s-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('website-panel')), findsOneWidget);
    expect(find.byKey(const ValueKey('website-search')), findsNothing);
  });

  testWidgets('without the endpoint the page keeps today\'s three providers', (
    tester,
  ) async {
    await _pump(tester, data: null);

    expect(find.byKey(const ValueKey('provider-web')), findsOneWidget);
    expect(find.byKey(const ValueKey('provider-whatsapp')), findsNothing);
  });

  test('the mapper reads the server\'s shape', () {
    final sources = parseLeadSources({'data': _all});
    expect(sources.map((s) => s.key), [
      'website',
      'whatsapp',
      'facebook',
      'instagram',
      'google_business_profile',
    ]);
    final site = sources.first.items.first;
    expect(site.title, 'brightside.example');
    expect(site.isPrimary, isTrue);
    expect(site.pluginBehind, isTrue);
    expect(site.leads, 38);
    expect(site.place, 'Brightside · Springfield');
    expect(sources.first.items.last.bookingEnabled, isNull);
    expect(sources.last.items.single.leads, isNull);
    expect(parseLeadSources({'data': <String, dynamic>{}}), isEmpty);
  });
}

const _web = {
  'latest_plugin_version': '1.5.0',
  'sites': [
    {
      'id': 's-1',
      'host': 'brightside.example',
      'label': null,
      'is_primary': true,
      'company': {'id': 'co-1', 'name': 'Brightside'},
      'franchise': {'id': 'fr-1', 'name': 'Springfield'},
      'plugin_version': '1.4.2',
      'outdated': true,
      'last_seen_at': '2026-10-29T13:00:00Z',
      'chat_on': true,
      'booking_on': true,
      'leads': 38,
      'last_lead_at': '2026-10-29T12:00:00Z',
    },
    {
      'id': 's-2',
      'host': 'riverton.brightside.example',
      'label': null,
      'is_primary': false,
      'company': {'id': 'co-1', 'name': 'Brightside'},
      'franchise': {'id': 'fr-2', 'name': 'Riverton'},
      'plugin_version': '1.5.0',
      'outdated': false,
      'last_seen_at': '2026-10-28T13:00:00Z',
      'chat_on': true,
      'booking_on': null,
      'leads': 7,
      'last_lead_at': null,
    },
  ],
};

const _all = <String, dynamic>{
  'web': _web,
  'whatsapp': [
    {
      'id': 'wa-1',
      'number': '+1 555 0100',
      'name': 'Brightside main line',
      'leads': 4,
      'last_lead_at': null,
    },
  ],
  'facebook': [
    {
      'id': 'fb-1',
      'name': 'Brightside Home',
      'handle': 'brightsidehome',
      'status': 'connected',
      'leads': 2,
      'last_lead_at': null,
    },
  ],
  'instagram': [
    {
      'id': 'ig-1',
      'name': 'Brightside',
      'handle': '@brightside',
      'status': 'connected',
      'leads': 1,
      'last_lead_at': null,
    },
  ],
  'google_business_profile': [
    {
      'id': 'gbp-1',
      'name': 'Brightside Springfield',
      'handle': 'locations/1',
      'status': 'verified',
      'locations': 1,
      'mapped': true,
    },
  ],
};

Future<void> _pump(
  WidgetTester tester, {
  Object? data = _all,
  bool canEdit = true,
  bool flags = true,
  String? refusal,
  Size size = const Size(1400, 900),
}) async {
  _sent.clear();
  _sourceReads = 0;
  final served = data == null
      ? null
      : (jsonDecode(jsonEncode(data)) as Map).cast<String, dynamic>();
  for (final site in (served?['web']?['sites'] as List? ?? const [])) {
    (site as Map)
      ..['may_edit'] = flags
      ..['may_edit_chat'] = flags
      ..['may_set_primary'] = flags;
  }
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': const ['leads.view', 'leads.edit'],
          },
        });
      }
      final path = request.url.path;
      if (path.endsWith('/leads/sources') && request.method == 'GET') {
        _sourceReads++;
        return served == null
            ? _json({'message': 'Not found.'}, 404)
            : _json({'data': served});
      }
      if (path.endsWith('/widgets') && request.method == 'GET') {
        return _json({
          'data': {
            'config': {
              'chat': {'enabled': true, 'greeting': 'Hi there'},
              'booking': {'enabled': true, 'slots': 3},
              'theme': 'light',
            },
          },
        });
      }
      if (request.method != 'GET') {
        _sent.add((
          method: request.method,
          path: path,
          body: request.body.isEmpty ? null : jsonDecode(request.body),
        ));
        return refusal == null
            ? _json({'data': <String, dynamic>{}})
            : _json({'message': refusal}, 422);
      }
      return _json({'message': 'Nothing stubbed.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadsConnections(
          api: api,
          session: session,
          connections: const [],
          canEdit: canEdit,
          onChanged: () async {},
          onBack: () {},
        ),
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
  'name': 'Pat',
  'email': 'pat@brightside.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
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
  'companies': [
    {'id': 'co-1', 'name': 'Brightside'},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Springfield', 'company_id': 'co-1'},
    {'id': 'fr-2', 'name': 'Riverton', 'company_id': 'co-1'},
  ],
};
