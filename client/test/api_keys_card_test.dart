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
/// owner says it is copied, Viewer is what a key gets unless somebody
/// chooses otherwise, the role decides which kinds can be chosen, and a key is
/// revoked only after being asked in place.
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
      'Full access',
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
      'Viewer',
    );
    expect(
      find.descendant(
        of: script,
        matching: find.text('Last used 2 h ago · made Oct 2'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a new key is Viewer unless chosen otherwise, and shown once', (
    tester,
  ) async {
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

    expect(server.made.single, {'name': 'Reports bot', 'level': 'viewer'});
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
  });

  testWidgets('Editor and Full access are choices somebody makes, each time', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    Future<void> make(String? level) async {
      await tester.tap(find.byKey(const Key('api-key-new')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('api-key-name')), 'Claude');
      if (level != null) {
        await tester.tap(find.byKey(ValueKey('api-key-level-$level')));
      }
      await tester.pump();
      await tester.tap(find.byKey(const Key('api-key-make')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('api-key-done')));
      await tester.pumpAndSettle();
    }

    await make('editor');
    expect(
      tester
          .widget<StatusPill>(
            find.descendant(
              of: find.byKey(const ValueKey('api-key-9')),
              matching: find.byType(StatusPill),
            ),
          )
          .label,
      'Editor',
    );
    // A key just made can do what it was made to.
    expect(find.byKey(const ValueKey('api-key-narrowed-9')), findsNothing);
    await make('full');
    // The next key is Viewer again unless somebody chooses otherwise...
    await make(null);
    // ...and so is the form after a choice was cancelled.
    await tester.tap(find.byKey(const Key('api-key-new')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('api-key-level-full')));
    await tester.pump();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    await make(null);

    expect(
      [for (final made in server.made) made['level']],
      ['editor', 'full', 'viewer', 'viewer'],
    );
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

  testWidgets('a kind the role does not allow is shown, but cannot be chosen', (
    tester,
  ) async {
    final server = _Server(allowed: ['viewer', 'editor']);
    await _pump(tester, server);

    await tester.tap(find.byKey(const Key('api-key-new')));
    await tester.pumpAndSettle();
    final full = find.byKey(const ValueKey('api-key-level-full'));
    expect(
      find.descendant(
        of: full,
        matching: find.text('Your role does not allow this kind'),
      ),
      findsOneWidget,
    );
    expect(find.text('Your role does not allow this kind'), findsOneWidget);

    await tester.tap(full);
    await tester.pump();
    await tester.enterText(find.byKey(const Key('api-key-name')), 'Claude');
    await tester.pump();
    await tester.tap(find.byKey(const Key('api-key-make')));
    await tester.pumpAndSettle();

    expect(server.made.single['level'], 'viewer');
  });

  testWidgets('a role that allows no keys says so, and offers none', (
    tester,
  ) async {
    await _pump(tester, _Server(allowed: [], effective: {7: null, 3: null}));

    expect(find.byKey(const Key('api-key-new')), findsNothing);
    expect(
      find.text(
        'Your role does not allow API keys. An administrator can allow them '
        'in Roles & permissions.',
      ),
      findsOneWidget,
    );
    for (final id in [7, 3]) {
      expect(
        find.descendant(
          of: find.byKey(ValueKey('api-key-$id')),
          matching: find.text(
            'Your role no longer allows API keys, so this key is refused '
            'everywhere.',
          ),
        ),
        findsOneWidget,
      );
      // A key that can do nothing can still be revoked.
      expect(find.byKey(ValueKey('api-key-revoke-$id')), findsOneWidget);
    }
  });

  testWidgets('a key the role has since narrowed says what it can do now', (
    tester,
  ) async {
    await _pump(tester, _Server(allowed: ['viewer'], effective: {7: 'viewer'}));

    final laptop = find.byKey(const ValueKey('api-key-7'));
    expect(
      find.descendant(
        of: laptop,
        matching: find.text('Your role now limits this key to Viewer.'),
      ),
      findsOneWidget,
    );
    // The pill still says what it was made as.
    expect(
      tester
          .widget<StatusPill>(
            find.descendant(of: laptop, matching: find.byType(StatusPill)),
          )
          .label,
      'Full access',
    );
    expect(find.byKey(const ValueKey('api-key-narrowed-3')), findsNothing);
  });
}

const _key = '9|ExampleKeyForTestsOnly0000000000000000000';

class _Server {
  _Server({
    this.refuse = false,
    this.allowed = const ['viewer', 'editor', 'full'],
    this.effective = const {},
  });

  final bool refuse;

  /// The kinds of key the role allows.
  final List<String> allowed;

  /// What a key can do today, by id, where the role has narrowed it since.
  final Map<int, String?> effective;
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
            'level': 'full',
            'effective_level': effective.containsKey(7) ? effective[7] : 'full',
            'last_used_at': null,
            'created_at': '2026-10-06T13:00:00Z',
          },
          {
            'id': 3,
            'name': 'Weekly numbers script',
            'level': 'viewer',
            'effective_level': effective.containsKey(3)
                ? effective[3]
                : 'viewer',
            'last_used_at': '2026-10-06T17:00:00Z',
            'created_at': '2026-10-02T15:00:00Z',
          },
        ],
        'allowed_levels': allowed,
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
        'id': 8 + made.length,
        'name': body['name'],
        'level': body['level'],
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
