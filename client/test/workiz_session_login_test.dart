import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/workiz_connect_form.dart';
import 'package:ezbizi_client/widgets/workiz_session_login.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 120 (Jose picked option 1 and phone 4): the Workiz web login,
/// handed over through the EzBizi extension. Every name is invented.
void main() {
  const signedOut =
      'Workiz signed this session out. Sign in to Workiz and send the session '
      'to EzBizi again.';

  testWidgets('Get a link shows the link and Copy puts it on the clipboard', (
    tester,
  ) async {
    final server = _Server();
    final copied = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied.add((call.arguments as Map)['text'] as String);
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );

    await _pumpSession(tester, server);
    expect(find.text(WorkizSessionLogin.disclaimer), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();

    expect(server.sent.last.method, 'POST');
    expect(server.sent.last.url.path, '/api/v1/social/workiz-web/link');
    expect(find.text('https://app.example.test/s/workiz/ab12'), findsOneWidget);
    expect(find.textContaining('Good for 30 minutes'), findsOneWidget);
    expect(find.text('Get a new one'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('workiz-session-copy')));
    await tester.pump();
    expect(copied, ['https://app.example.test/s/workiz/ab12']);
    expect(find.text('Copied'), findsOneWidget);
  });

  testWidgets('polling turns the page Connected on its own', (tester) async {
    var done = 0;
    final server = _Server();
    await _pumpSession(tester, server, onDone: () => done++);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();
    expect(find.text('Waiting for it…'), findsOneWidget);

    // The extension sends it; the next poll (5 s) sees it.
    server.row = {'id': 'w-1', 'provider': 'workiz_web', 'status': 'connected'};
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();

    expect(find.text('Connected'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(find.text('Waiting for it…'), findsNothing);
    expect(done, 1);
  });

  testWidgets('a branch picked for an account is PUT', (tester) async {
    final server = _Server()
      ..row = {'id': 'w-1', 'provider': 'workiz_web', 'status': 'connected'};
    await _pumpSession(tester, server);

    expect(find.text('Lakeview Plumbing'), findsOneWidget);
    expect(find.text('Choose a branch'), findsOneWidget);

    await tester.tap(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Maple Branch').last);
    await tester.pumpAndSettle();

    final put = server.sent.lastWhere((r) => r.method == 'PUT');
    expect(put.url.path, '/api/v1/social/workiz-web/w-1/accounts');
    expect(jsonDecode(put.body), {'account': 'acct-1', 'franchise_id': 'f-1'});
    expect(find.text('Choose a branch'), findsNothing);
  });

  testWidgets('signed out says why and offers Send it again', (tester) async {
    final server = _Server()
      ..row = {
        'id': 'w-1',
        'provider': 'workiz_web',
        'status': 'expired',
        'status_reason': signedOut,
      };
    await _pumpSession(tester, server);

    expect(find.text('Signed out'), findsOneWidget);
    expect(find.text(signedOut), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);

    await tester.tap(find.text('Send it again'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('workiz-session-get')), findsOneWidget);
  });

  testWidgets('a server without the routes says so', (tester) async {
    final server = _Server()..routes = false;
    await _pumpSession(tester, server);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pumpAndSettle();

    expect(find.text('Not available on this server yet.'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
  });

  testWidgets('a phone shows the status and table, not the handoff', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final server = _Server();
    await _pumpSession(tester, server);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(
      find.text(
        'Sending the session needs the EzBizi extension on a computer.',
      ),
      findsOneWidget,
    );

    server.row = {'id': 'w-1', 'provider': 'workiz_web', 'status': 'connected'};
    await _pumpSession(tester, server, fresh: true);
    expect(find.text('Connected'), findsOneWidget);
    expect(find.text('Lakeview Plumbing'), findsOneWidget);
    expect(find.text('Choose a branch'), findsOneWidget);
  });
}

class _Server {
  final sent = <http.Request>[];
  Map<String, dynamic>? row;
  bool routes = true;

  late final client = MockClient((request) async {
    sent.add(request);
    final path = request.url.path;
    http.Response json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: const {'content-type': 'application/json'},
    );

    if (path == '/api/v1/social/connections') {
      return json({
        'data': [
          {'id': 'c-2', 'provider': 'workiz', 'status': 'connected'},
          ?row,
        ],
      });
    }
    if (!routes) return json({'message': 'Not found.'}, 404);
    if (path == '/api/v1/social/workiz-web/link') {
      return json({
        'data': {'id': 'w-1', 'link': 'https://app.example.test/s/workiz/ab12'},
      });
    }
    if (path.endsWith('/accounts') && request.method == 'GET') {
      return json({
        'data': [
          {
            'account': 'acct-1',
            'name': 'Lakeview Plumbing',
            'franchise_id': null,
          },
        ],
      });
    }
    return json({'data': <String, dynamic>{}});
  });
}

Future<void> _pumpSession(
  WidgetTester tester,
  _Server server, {
  VoidCallback? onDone,
  bool fresh = false,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: WorkizConnectForm(
            key: fresh ? UniqueKey() : null,
            api: ApiClient(client: server.client),
            branches: const [
              FranchiseSummary(id: 'f-1', name: 'Maple Branch'),
              FranchiseSummary(id: 'f-2', name: 'Cedar Branch'),
            ],
            onDone: onDone ?? () {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('Signed-in session'));
  await tester.pumpAndSettle();
}
