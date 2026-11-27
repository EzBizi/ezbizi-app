import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integration_provider_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 167 (Jose, 2026-11-02): Google connections grouped under the Google
/// login they were signed in with, the unknown login last.
void main() {
  final deletes = <(String, Map<String, dynamic>)>[];

  Future<void> pump(WidgetTester tester) async {
    deletes.clear();
    tester.view.physicalSize = const Size(1280, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((r) async {
        if (r.method == 'DELETE') {
          deletes.add((r.url.path, jsonDecode(r.body) as Map<String, dynamic>));
          return _json({
            'data': {'problems': <String>[]},
          });
        }
        return _json({'data': _me});
      }),
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
            provider: 'google_business_profile',
            providerLabel: 'Business Profile',
            connections: _rows.map(IntegrationConnection.fromJson).toList(),
            onDisconnect: (row, code) async =>
                const ApiResponse(statusCode: 200, body: {}),
            onBack: () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('one card per login, counted, the broken one open and the '
      'unknown last', (tester) async {
    await pump(tester);

    final order = [
      'login:a@x.com',
      'login:b@x.com',
      'login:unknown',
    ].map((k) => tester.getTopLeft(find.byKey(ValueKey(k))).dy).toList();
    expect(order, [...order]..sort(), reason: 'logins A to Z, unknown last');
    expect(find.text('Unknown login · reconnect to see'), findsOneWidget);
    expect(find.text('1 OK'), findsNWidgets(3));
    expect(find.text('1 need reconnect'), findsOneWidget);

    // a@x.com holds the broken row, so it starts open; b@x.com does not.
    expect(find.byKey(const ValueKey('connection:g1')), findsOneWidget);
    expect(find.byKey(const ValueKey('connection:g3')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('login-toggle:b@x.com')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('connection:g3')), findsOneWidget);

    // Each row still says where it is.
    expect(find.textContaining('A-1 Chimney'), findsWidgets);
  });

  testWidgets('the search narrows one login, and Disconnect all sends that '
      'login', (tester) async {
    await pump(tester);

    await tester.enterText(
      find.byKey(const ValueKey('login-search:a@x.com')),
      'houston',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('connection:g2')), findsOneWidget);
    expect(find.byKey(const ValueKey('connection:g1')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('disconnect-login:a@x.com')));
    await tester.pumpAndSettle();
    expect(deletes, isEmpty, reason: 'nothing goes before the confirmation');
    await tester.enterText(find.byKey(const Key('disconnect-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('disconnect-confirm')));
    await tester.pumpAndSettle();
    expect(
      deletes.single.$1,
      '/api/v1/social/providers/google_business_profile/connections',
    );
    expect(deletes.single.$2['account_email'], 'a@x.com');
  });
}

Map<String, dynamic> _row(
  String id,
  String name,
  String? email, {
  bool connected = true,
}) => {
  'id': id,
  'provider': 'google_business_profile',
  'provider_label': 'Business Profile',
  'display_name': name,
  'label': null,
  'account_email': email,
  'scope_type': 'company',
  'scope_id': 'co-a1',
  'status': connected ? 'connected' : 'broken',
  'status_reason': connected ? null : 'Google would not renew this connection.',
};

final _rows = [
  _row('g4', 'Unknown one', null),
  _row('g3', 'Roofirst', 'b@x.com'),
  _row('g1', 'A1 Chimney · Atlanta', 'a@x.com', connected: false),
  _row('g2', 'A1 Chimney · Houston', 'a@x.com'),
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
  ],
  'franchises': <dynamic>[],
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
