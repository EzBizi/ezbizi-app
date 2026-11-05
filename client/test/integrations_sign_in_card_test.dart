import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 182 (Jose, 2026-11-05): "1 facebook connections grabs social and
/// ada and whatever scopes we have etc. Same for disconnecting."
void main() {
  testWidgets('one Facebook card carries the Pages and the ad accounts', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    expect(find.byKey(const ValueKey('signin:facebook_page')), findsOneWidget);
    // Facebook Ads has no tile of its own any more.
    expect(find.byKey(const ValueKey('provider:facebook_ads')), findsNothing);
    expect(find.text('Pages'), findsOneWidget);
    expect(find.text('Ad accounts'), findsOneWidget);
    expect(find.text('EzBizi'), findsOneWidget);
    expect(find.text('Jose Demasi'), findsOneWidget);
  });

  testWidgets('at 390 px, Disconnect is a two-step that lapses by itself', (
    tester,
  ) async {
    await _pump(tester, width: 390);

    // At the card's foot, with its full text.
    await tester.tap(find.text('Disconnect Facebook'));
    await tester.pump();
    final box = find.byKey(const ValueKey('family-confirm:facebook_page'));
    expect(box, findsOneWidget);
    expect(
      find.descendant(of: box, matching: find.text('Pages: EzBizi')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: box, matching: find.text('Ad accounts: Jose Demasi')),
      findsOneWidget,
    );
    expect(_requests.where((r) => r.method == 'DELETE'), isEmpty);

    await tester.pump(const Duration(seconds: 6));
    expect(box, findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the code confirms one disconnect for the whole sign-in', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(
      find.byKey(const ValueKey('family-disconnect:facebook_page')),
    );
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('family-code:facebook_page')),
      '123456',
    );
    // A code being typed keeps it open past the lapse.
    await tester.pump(const Duration(seconds: 6));
    await tester.tap(
      find.byKey(const ValueKey('family-confirm-go:facebook_page')),
    );
    await tester.pumpAndSettle();

    final deletes = _requests.where((r) => r.method == 'DELETE').toList();
    expect(deletes, hasLength(1));
    expect(
      deletes.single.url.path,
      endsWith('/social/providers/facebook_page/connections'),
    );
    expect(jsonDecode(deletes.single.body)['code'], '123456');
    // Both went with the one sign-in: the reload has neither.
    expect(find.byKey(const ValueKey('signin:facebook_page')), findsNothing);
    expect(
      find.byKey(const ValueKey('provider:facebook_page')),
      findsOneWidget,
    );
  });
}

late List<http.Request> _requests;

Future<void> _pump(WidgetTester tester, {required double width}) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  _requests = [];
  var rows = <Map<String, dynamic>>[
    _row('p-1', 'facebook_page', 'Facebook', 'EzBizi'),
    _row('a-1', 'facebook_ads', 'Facebook Ads', 'Jose Demasi'),
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      _requests.add(request);
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (request.method == 'DELETE' &&
          path.endsWith('/social/providers/facebook_page/connections')) {
        rows = [];
        return _json({
          'data': {'disconnected': 2, 'problems': <String>[]},
        });
      }
      if (path.endsWith('/social/connections')) return _json({'data': rows});
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': const [
            {
              'value': 'facebook_page',
              'label': 'Facebook',
              'usable': true,
              'connects_by': 'sign_in',
              'start_path': '/social/facebook/start',
              'permission': 'marketing.edit',
              'signed_in_with': null,
            },
            {
              'value': 'facebook_ads',
              'label': 'Facebook Ads',
              'usable': true,
              'connects_by': 'sign_in',
              'start_path': '/social/facebook-ads/start',
              'permission': 'marketing.edit',
              'signed_in_with': 'facebook_page',
            },
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

Map<String, dynamic> _row(
  String id,
  String provider,
  String label,
  String name,
) => {
  'id': id,
  'provider': provider,
  'provider_label': label,
  'display_name': name,
  'scope_type': 'organization',
  'scope_id': 'org-1',
  'status': 'connected',
  'status_reason': null,
  'last_checked_at': null,
  'last_reached_at': null,
};

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view', 'marketing.edit'],
  'modules': <dynamic>[],
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
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
