import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/api_keys_card.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Your account › API keys (UI queue 62).
///
/// What these hold to: the key is shown once and stays on the page until its
/// owner says it is copied, Read only is what a key gets unless somebody
/// chooses otherwise, and a key is revoked only after being asked in place.
void main() {
  testWidgets('each key says what it may do and when it was last used', (
    tester,
  ) async {
    await _pump(tester, _Server());

    final laptop = find.byKey(const ValueKey('api-key-7'));
    expect(
      tester
          .widget<StatusPill>(
            find.descendant(of: laptop, matching: find.byType(StatusPill)),
          )
          .label,
      'Can make changes',
    );
    expect(
      find.descendant(
        of: laptop,
        matching: find.text('Never used · made today'),
      ),
      findsOneWidget,
    );

    final script = find.byKey(const ValueKey('api-key-3'));
    expect(
      tester
          .widget<StatusPill>(
            find.descendant(of: script, matching: find.byType(StatusPill)),
          )
          .label,
      'Read only',
    );
    expect(
      find.descendant(
        of: script,
        matching: find.text('Last used 2 h ago · made Oct 2'),
      ),
      findsOneWidget,
    );
  });

  testWidgets(
    'a new key is read only unless chosen otherwise, and shown once',
    (tester) async {
      final server = _Server();
      await _pump(tester, server);

      await tester.tap(find.byKey(const Key('api-key-new')));
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<FilledButton>(find.byKey(const Key('api-key-make')))
            .onPressed,
        isNull,
        reason: 'a key needs a name',
      );

      await tester.enterText(
        find.byKey(const Key('api-key-name')),
        'Reports bot',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('api-key-make')));
      await tester.pumpAndSettle();

      expect(server.made.single, {'name': 'Reports bot', 'access': 'read'});
      final shown = find.byKey(const Key('api-key-shown'));
      expect(
        find.descendant(of: shown, matching: find.text(_key)),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: shown,
          matching: find.textContaining('Authorization: Bearer $_key'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: shown,
          matching: find.textContaining('claude mcp add'),
        ),
        findsOneWidget,
      );
      // The new key heads the list.
      expect(find.byKey(const ValueKey('api-key-9')), findsOneWidget);

      // Gone once its owner says it is copied, and not to be had again.
      await tester.tap(find.byKey(const Key('api-key-done')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('api-key-shown')), findsNothing);
      expect(find.text(_key), findsNothing);
    },
  );

  testWidgets('Can make changes is a choice somebody makes', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.byKey(const Key('api-key-new')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('api-key-name')), 'Claude');
    await tester.tap(find.byKey(const ValueKey('api-key-access-write')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('api-key-make')));
    await tester.pumpAndSettle();

    expect(server.made.single['access'], 'write');
  });

  testWidgets('Copy key puts the key on the clipboard', (tester) async {
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

    await _pump(tester, _Server());
    await tester.tap(find.byKey(const Key('api-key-new')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('api-key-name')),
      'Reports bot',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('api-key-make')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('api-key-copy')));
    await tester.pumpAndSettle();
    expect(copied, [_key]);
  });

  testWidgets('the server refusing says why, and keeps the form', (
    tester,
  ) async {
    await _pump(tester, _Server(refuse: true));

    await tester.tap(find.byKey(const Key('api-key-new')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('api-key-name')),
      'Reports bot',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('api-key-make')));
    await tester.pumpAndSettle();

    expect(
      find.text('The name may not be greater than 60 characters.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('api-key-form')), findsOneWidget);
    expect(find.byKey(const Key('api-key-shown')), findsNothing);
  });

  testWidgets('a key is revoked only after being asked, in place', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.byKey(const ValueKey('api-key-revoke-3')));
    await tester.pumpAndSettle();
    expect(server.revoked, isEmpty, reason: 'asked first');
    expect(find.byKey(const ValueKey('api-key-confirm-3')), findsOneWidget);

    await tester.tap(find.text('Keep'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('api-key-3')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('api-key-revoke-3')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('api-key-revoke-yes-3')));
    await tester.pumpAndSettle();
    expect(server.revoked, ['3']);
    expect(find.byKey(const ValueKey('api-key-3')), findsNothing);
  });
}

const _key = '9|ExampleKeyForTestsOnly0000000000000000000';

class _Server {
  _Server({this.refuse = false});

  final bool refuse;
  final List<Map<String, dynamic>> made = [];
  final List<String> revoked = [];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'GET' && path.endsWith('/me/api-keys')) {
      return _json({
        'api_keys': [
          {
            'id': 7,
            'name': 'Claude on my laptop',
            'access': 'write',
            'last_used_at': null,
            'created_at': '2026-10-06T13:00:00Z',
          },
          {
            'id': 3,
            'name': 'Weekly numbers script',
            'access': 'read',
            'last_used_at': '2026-10-06T17:00:00Z',
            'created_at': '2026-10-02T15:00:00Z',
          },
        ],
      });
    }
    if (request.method == 'POST' && path.endsWith('/me/api-keys')) {
      if (refuse) {
        return _json({
          'message': 'The name may not be greater than 60 characters.',
          'errors': {
            'name': ['The name may not be greater than 60 characters.'],
          },
        }, 422);
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      made.add(body);
      return _json({
        'id': 9,
        'name': body['name'],
        'access': body['access'],
        'key': _key,
      }, 201);
    }
    if (request.method == 'DELETE') {
      revoked.add(path.split('/').last);
      return http.Response('', 204);
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(1000, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: ApiKeysCard(
            api: ApiClient(client: MockClient(server.handle)),
            now: () => DateTime.utc(2026, 10, 6, 19).toLocal(),
          ),
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
