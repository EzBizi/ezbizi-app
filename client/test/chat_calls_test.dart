import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/call_alerts.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_calls.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Chat calls (Twilio Video): the ring, answering, the call, hanging up.
void main() {
  late List<String> log;
  late List<Map<String, Object?>> ringing;
  late _Alerts alerts;
  void Function(Map<String, dynamic>)? pageEvents;
  String? pageUrl;

  setUp(() {
    log = [];
    ringing = [];
    alerts = _Alerts();
    pageEvents = null;
    pageUrl = null;
    debugCallView = ({Key? key, required url, required onEvent}) {
      pageUrl = url;
      pageEvents = onEvent;
      return const ColoredBox(
        key: ValueKey('fake-call-page'),
        color: Color(0x00000000),
      );
    };
  });
  setUp(() => ChatCalls.devicesAllowed = (_) async => true);
  tearDown(() => debugCallView = null);

  Future<ChatCalls> pump(WidgetTester tester, {bool mayChat = true}) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        log.add('${request.method} ${path.split('/api/v1').last}');
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': ['phone.view', if (mayChat) 'phone.chat.view'],
            },
          });
        }
        if (path.endsWith('/chat/calls/ringing')) {
          return _json({'data': ringing});
        }
        if (path.endsWith('/join') || path.endsWith('/calls')) {
          return _json({
            'data': {
              'call_id': 'call-1',
              'chat_id': 'c-1',
              'room': 'chat-call-1',
              'video': true,
              'group': false,
              'identity': 'u-1',
              'token': 'jwt.secret.sig',
              'names': {'u-1': 'Jose Silva', 'u-2': 'Ana Ruiz'},
              'ring_seconds': 45,
            },
          });
        }
        if (path.endsWith('/decline') || path.endsWith('/leave')) {
          return _json({'ok': true});
        }
        if (path.contains('/avatar')) return http.Response('', 404);
        return _json({'message': 'unexpected'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    final calls = ChatCalls(alerts: alerts);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ChatCallLayer(api: api, session: session, calls: calls),
        ),
      ),
    );
    await tester.pump();
    return calls;
  }

  testWidgets('a ringing video call shows, rings, and Answer opens the call', (
    tester,
  ) async {
    ringing = [
      {
        'call_id': 'call-1',
        'chat_id': 'c-1',
        'chat_name': null,
        'from_id': 'u-2',
        'from_name': 'Ana Ruiz',
        'video': true,
        'group': false,
      },
    ];
    final calls = await pump(tester);
    await tester.runAsync(calls.poll);
    await tester.pump();

    expect(find.byKey(const ValueKey('chat-call-ring')), findsOneWidget);
    expect(find.text('Ana Ruiz'), findsOneWidget);
    expect(find.text('Incoming video call'), findsOneWidget);
    expect(alerts.rang, ['Ana Ruiz · video call']);

    await tester.tap(find.byKey(const ValueKey('chat-call-answer')));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 150)),
    );
    await tester.pump();

    expect(log, contains('POST /chat/calls/call-1/join'));
    expect(find.byKey(const ValueKey('chat-call-active')), findsOneWidget);
    expect(find.byKey(const ValueKey('chat-call-ring')), findsNothing);
    expect(alerts.stopped, isTrue);
    // The token rides in the fragment, which no browser sends to a server.
    expect(pageUrl, contains('/api/v1/chat/call#'));
    final fragment = Uri.splitQueryString(pageUrl!.split('#').last);
    expect(fragment['token'], 'jwt.secret.sig');
    expect(fragment['room'], 'chat-call-1');
    expect(fragment['video'], '1');

    // The page hangs up: the server is told whether anyone is left.
    pageEvents!({'event': 'ended', 'alone': true});
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 150)),
    );
    await tester.pump();
    expect(log, contains('POST /chat/calls/call-1/leave'));
    expect(find.byKey(const ValueKey('chat-call-active')), findsNothing);
  });

  testWidgets('Decline stops the ring and tells the server', (tester) async {
    ringing = [
      {
        'call_id': 'call-1',
        'chat_id': 'c-1',
        'chat_name': 'NJ crew',
        'from_id': 'u-2',
        'from_name': 'Ana Ruiz',
        'video': false,
        'group': true,
      },
    ];
    final calls = await pump(tester);
    await tester.runAsync(calls.poll);
    await tester.pump();
    expect(find.text('NJ crew'), findsOneWidget);
    expect(find.text('Ana Ruiz · Incoming group voice call'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('chat-call-decline')));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 150)),
    );
    await tester.pump();
    expect(log, contains('POST /chat/calls/call-1/decline'));
    expect(find.byKey(const ValueKey('chat-call-ring')), findsNothing);
    expect(alerts.stopped, isTrue);
  });

  testWidgets('without Chat the layer never polls for calls', (tester) async {
    await pump(tester, mayChat: false);
    expect(log.where((l) => l.contains('/chat/calls')), isEmpty);
  });
}

class _Alerts implements CallAlerts {
  final rang = <String>[];
  bool stopped = false;

  @override
  void incoming(String from) => rang.add(from);

  @override
  void stop() => stopped = true;

  @override
  void dispose() {}
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Staff'},
  'modules': [
    {
      'key': 'phone',
      'label': 'Communication',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
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
  'companies': <Object>[],
};
