import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-11-03: a card must never say "Connected" on a dead token, and
/// each card says when the daily token check last had a verdict.
void main() {
  testWidgets('a checked card shows the line and says Connected', (
    tester,
  ) async {
    final checked = DateTime.now().toUtc().subtract(const Duration(hours: 3));
    await _pump(tester, [
      _row('pin-1', 'pinterest', 'connected', checked.toIso8601String()),
    ]);

    final card = find.byKey(const ValueKey('provider:pinterest'));
    expect(
      find.descendant(of: card, matching: find.text('Last checked 3 h ago')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: card,
        matching: find.byKey(const ValueKey('status-dot:Connected')),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a broken card needs reconnecting and never says Connected', (
    tester,
  ) async {
    await _pump(tester, [
      _row(
        'pin-1',
        'pinterest',
        'broken',
        DateTime.now().toUtc().toIso8601String(),
      ),
    ]);

    final card = find.byKey(const ValueKey('provider:pinterest'));
    expect(
      find.descendant(
        of: card,
        matching: find.byKey(const ValueKey('status-dot:Needs attention')),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: card,
        matching: find.byKey(const ValueKey('status-dot:Connected')),
      ),
      findsNothing,
    );
    expect(
      find.descendant(of: card, matching: find.text('Last checked just now')),
      findsOneWidget,
    );

    // And the row behind it carries the sentence, not a green state.
    await tester.tap(card);
    await tester.pumpAndSettle();
    expect(find.textContaining('needs connecting again'), findsOneWidget);
  });

  testWidgets('a never-checked card says so', (tester) async {
    await _pump(tester, [_row('pin-1', 'pinterest', 'connected', null)]);

    expect(
      find.byKey(const ValueKey('last-checked:pinterest')),
      findsOneWidget,
    );
    expect(find.text('Not checked yet'), findsOneWidget);
  });
}

Map<String, dynamic> _row(
  String id,
  String provider,
  String status,
  String? checkedAt,
) => {
  'id': id,
  'provider': provider,
  'provider_label': 'Pinterest',
  'display_name': 'A-1 Chimney',
  'scope_type': 'organization',
  'scope_id': 'org-1',
  'status': status,
  'status_reason': status == 'broken'
      ? 'Pinterest no longer accepts this connection. It needs connecting again.'
      : null,
  'last_checked_at': checkedAt,
  'last_reached_at': checkedAt,
};

Future<void> _pump(WidgetTester tester, List<Map<String, dynamic>> rows) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/social/connections')) return _json({'data': rows});
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': const [
            {
              'value': 'pinterest',
              'label': 'Pinterest',
              'connects_by': 'sign_in',
              'start_path': '/social/pinterest/start',
              'permission': 'marketing.edit',
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
