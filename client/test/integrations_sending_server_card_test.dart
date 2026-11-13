import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Sending server card on Integrations (Jose, 2026-10-22, v0.0.0472, on
/// Triplex Group: no Sending server card at all).
///
/// The fake answers `/mail/sending-server` the way
/// SendingServersController::show does: `scope_type` and `scope_id` are
/// REQUIRED, and a request without them is a 422. The older fake answered
/// any request, which is how a screen that never sent the scope passed.
void main() {
  testWidgets('the screen asks for the sending server AT this scope', (
    tester,
  ) async {
    final seen = <Map<String, String>>[];
    await _pump(tester, inEffect: null, seen: seen);

    expect(seen, isNotEmpty);
    expect(seen.first['scope_type'], 'organization');
    expect(seen.first['scope_id'], 'org-1');
  });

  testWidgets('nothing in effect: the card is offered, not connected', (
    tester,
  ) async {
    await _pump(tester, inEffect: null);

    expect(
      find.byKey(const ValueKey('provider:sending_server')),
      findsOneWidget,
    );
    expect(find.text('Sending server'), findsOneWidget);
    expect(find.textContaining('your own mail server'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('connect-card:sending_server')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integrations-category:email')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integrations-section:email')),
      findsOneWidget,
    );
  });

  testWidgets('an empty in_effect is also "not connected", and still drawn', (
    tester,
  ) async {
    await _pump(tester, inEffect: <String, dynamic>{});

    expect(
      find.byKey(const ValueKey('provider:sending_server')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('connect-card:sending_server')),
      findsOneWidget,
    );
  });

  testWidgets('refused at this scope: the card stays, with the reason', (
    tester,
  ) async {
    await _pump(
      tester,
      inEffect: null,
      refusal: 'Set per company: open a company.',
    );

    expect(
      find.byKey(const ValueKey('provider:sending_server')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('refused:sending_server')),
      findsOneWidget,
    );
    expect(find.text('Set per company: open a company.'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('connect-card:sending_server')),
      findsNothing,
    );
  });

  testWidgets('connected: the card names the from address and the host', (
    tester,
  ) async {
    await _pump(
      tester,
      inEffect: {
        'host': 'mail.triplex.example',
        'from_address': 'office@triplex.example',
      },
    );

    expect(
      find.byKey(const ValueKey('provider:sending_server')),
      findsOneWidget,
    );
    expect(
      find.textContaining('Connected · office@triplex.example'),
      findsOneWidget,
    );
    expect(find.textContaining('mail.triplex.example'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('connect-card:sending_server')),
      findsNothing,
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic>? inEffect,
  String? refusal,
  List<Map<String, String>>? seen,
}) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/social/connections')) return _json({'data': []});
      if (path.endsWith('/social/providers')) return _json({'data': []});
      if (path.endsWith('/mail/sending-server')) {
        final query = request.url.queryParameters;
        seen?.add(query);

        // SendingServersController::resolveScope — both are required.
        if ((query['scope_type'] ?? '').isEmpty ||
            (query['scope_id'] ?? '').isEmpty) {
          return _json({
            'message': 'The scope type field is required.',
            'errors': {
              'scope_type': ['The scope type field is required.'],
            },
          }, 422);
        }
        if (refusal != null) return _json({'message': refusal}, 403);

        return _json({
          'data': {
            'connected_here': inEffect,
            'in_effect': inEffect,
            'may_edit': true,
            'may_edit_reason': null,
            'ports': [587, 465],
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

final Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Demasi',
  'email': 'jose@triplex.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view'],
  'modules': <Map<String, dynamic>>[],
  'companies': [
    {'id': 'co-t', 'name': 'Triplex Services'},
  ],
  'franchises': <Map<String, dynamic>>[],
  'organization': {
    'id': 'org-1',
    'name': 'Triplex Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Triplex Group',
    'path': ['Triplex Group'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
