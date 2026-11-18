import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/mailbox_panels.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Google Workspace on Settings › Integrations (Jose, 2026-10-15: "On
/// Integrations we are also missing Google Workspace connections").
///
/// The rows come from `GET /email/workspace`, which answers for the current
/// view — GoogleWorkspaceTest::test_a_company_workspace_is_hidden_from_a_sibling_company
/// holds the server half. These hold that the screen lists exactly what that
/// endpoint lists, asks it only when the person may read it, and connects and
/// disconnects through Email's own endpoints.
void main() {
  testWidgets('a Workspace domain is a card under Email, named and scoped', (
    tester,
  ) async {
    final calls = <String>[];
    await _pump(tester, calls: calls);

    expect(
      find.byKey(const ValueKey('integrations-category:email')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integrations-section:email')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('provider:google_workspace')));
    await tester.pumpAndSettle();

    expect(find.text('a-1chimney.com'), findsOneWidget);
    expect(find.textContaining('Admin it@a-1chimney.com'), findsOneWidget);
    // Grouped under the company that owns it.
    expect(find.text('A-1 Chimney'), findsOneWidget);
    // Only what the server put in view: the sibling's domain is not invented.
    expect(find.textContaining('everdoors.com'), findsNothing);
  });

  testWidgets('disconnect calls the same endpoint Email does, for that scope', (
    tester,
  ) async {
    final calls = <String>[];
    await _pump(tester, calls: calls);

    await tester.tap(find.byKey(const ValueKey('provider:google_workspace')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('actions:google_workspace:company:co-a1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('disconnect:google_workspace:company:co-a1')),
    );
    await tester.pumpAndSettle();
    // Asked once more since UI queue 110.
    await tester.enterText(find.byKey(const Key('disconnect-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('disconnect-confirm')));
    await tester.pumpAndSettle();

    expect(calls, contains('DELETE /api/v1/email/workspace'));
  });

  testWidgets('connect opens Email\'s own Workspace panel', (tester) async {
    await _pump(tester, calls: [], canConnect: true, rows: const []);

    expect(
      find.byKey(const ValueKey('integrations-category:email')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('integrations-section:email')),
      findsOneWidget,
    );
    await tester.tap(
      find.byKey(const ValueKey('connect-card:google_workspace')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('connect:google_workspace')));
    await tester.pumpAndSettle();

    expect(find.byType(ConnectWorkspacePanel), findsOneWidget);
  });

  testWidgets('without the Email settings permissions there is no card and no '
      'request', (tester) async {
    final calls = <String>[];
    await _pump(tester, calls: calls, permissions: const ['marketing.view']);

    expect(
      find.byKey(const ValueKey('provider:google_workspace')),
      findsNothing,
    );
    expect(calls.where((c) => c.contains('/email/workspace')), isEmpty);
  });
}

const _row = {
  'domain': 'a-1chimney.com',
  'admin_email': 'it@a-1chimney.com',
  'status': 'connected',
  'status_reason': null,
  'scope_type': 'company',
  'scope_id': 'co-a1',
  'scope_label': 'A-1 Chimney',
  'is_current_view': true,
  'health': {'status': 'ok', 'summary': 'All set.'},
};

Future<void> _pump(
  WidgetTester tester, {
  required List<String> calls,
  List<String> permissions = const [
    'marketing.view',
    'email.settings.view',
    'email.edit',
  ],
  bool canConnect = false,
  List<Map<String, dynamic>> rows = const [_row],
}) async {
  tester.view.physicalSize = const Size(1280, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      calls.add(
        '${request.method} ${request.url.path}'
        '${request.url.hasQuery ? '?${request.url.query}' : ''}',
      );

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path.endsWith('/social/connections')) return _json({'data': []});
      if (path.endsWith('/social/providers')) return _json({'data': []});
      if (path.endsWith('/email/workspace')) {
        if (request.method == 'DELETE') {
          return _json({'message': 'a-1chimney.com is no longer connected.'});
        }
        return _json({
          'data': {
            'view': {'type': 'company', 'id': 'co-a1'},
            'can_connect': canConnect,
            'connections': rows,
          },
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
  'permissions': <String>[],
  'modules': <dynamic>[],
  'companies': [
    {'id': 'co-a1', 'name': 'A-1 Chimney'},
    {'id': 'co-ev', 'name': 'Everdoors Garage'},
  ],
  'franchises': <dynamic>[],
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'company',
    'id': 'co-a1',
    'label': 'A-1 Chimney',
    'path': ['Everdoors', 'A-1 Chimney'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
