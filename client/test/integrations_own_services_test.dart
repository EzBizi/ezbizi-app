import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// EzBizi's own services on Integrations (UI queue 110): the phone numbers
/// and the sending server, each only for somebody whose server answers.
void main() {
  testWidgets('phone numbers show as a tile, grouped by where they belong', (
    tester,
  ) async {
    await _pump(tester, phone: true);

    expect(
      find.byKey(const ValueKey('provider:phone_numbers')),
      findsOneWidget,
    );
    expect(find.textContaining('Phone · 1 of 1 connected'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('provider:phone_numbers')));
    await tester.pumpAndSettle();

    expect(find.text('Brightside main'), findsOneWidget);
    expect(find.text('Springfield'), findsOneWidget);
    expect(
      find.textContaining('Communication › Phone › Settings'),
      findsOneWidget,
    );
  });

  testWidgets('without the phone module there is no phone tile', (
    tester,
  ) async {
    await _pump(tester, phone: false);

    expect(find.byKey(const ValueKey('provider:phone_numbers')), findsNothing);
  });

  testWidgets('the sending server opens its own panel in place', (
    tester,
  ) async {
    await _pump(tester, phone: false);

    expect(
      find.byKey(const ValueKey('provider:sending_server')),
      findsOneWidget,
    );
    expect(find.textContaining('Email · 1 of 1 connected'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('provider:sending_server')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('integration-back')), findsOneWidget);
    expect(find.textContaining('your own mail server'), findsOneWidget);
  });
}

Future<void> _pump(WidgetTester tester, {required bool phone}) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me(phone)});
      if (path.endsWith('/social/connections')) return _json({'data': []});
      if (path.endsWith('/social/providers')) return _json({'data': []});
      if (path.endsWith('/phone/numbers')) {
        return _json({
          'data': [
            {
              'id': 'n-1',
              'e164': '+15555550123',
              'label': 'Brightside main',
              'scope_type': 'franchise',
              'scope_id': 'fr-sp',
            },
          ],
        });
      }
      if (path.endsWith('/mail/sending-server')) {
        return _json({
          'data': {
            'connected_here': null,
            'in_effect': {
              'host': 'mail.brightside.example',
              'from_address': 'office@brightside.example',
            },
            'may_edit': false,
            'may_edit_reason': 'Not yours to change.',
            'ports': [587],
          },
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

Map<String, dynamic> _me(bool phone) => {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@brightside.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view'],
  'modules': [
    if (phone)
      {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'companies': [
    {'id': 'co-b', 'name': 'Brightside Home Services'},
  ],
  'franchises': [
    {'id': 'fr-sp', 'name': 'Springfield', 'company_id': 'co-b'},
  ],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
