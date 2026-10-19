import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/api_activity.dart';
import 'package:ezbizi_client/widgets/api_keys_card.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// API activity (UI queue 66, 1 and 4).
///
/// What these hold to: requests read as Read, Created, Changed or Deleted;
/// Changes only is on until somebody turns it off; a change opens to each
/// field before and after; a delete to what it held; the filters ask the
/// server, not just the page; and a key's own few changes sit under it in Your
/// account with the rest a click away.
void main() {
  // UI queue 111: the last 24 hours on top; Refused filters to itself.
  testWidgets('the summary strip counts the day and filters the table', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, const Size(1300, 900), everyone: true);

    expect(find.byKey(const ValueKey('api-summary-calls')), findsOneWidget);
    expect(find.text('9'), findsOneWidget);

    List<String> verbs() => [
      for (final pill in tester.widgetList<StatusPill>(find.byType(StatusPill)))
        pill.label,
    ];
    await tester.tap(find.byKey(const ValueKey('api-summary-refused')));
    await tester.pumpAndSettle();
    expect(verbs(), contains('Refused'));
    expect(verbs(), isNot(contains('OK')));

    await tester.tap(find.byKey(const ValueKey('api-summary-calls')));
    await tester.pumpAndSettle();
    expect(verbs(), contains('Read'));
  });

  testWidgets('changes only, in words, until reads are asked for', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, const Size(1300, 900), everyone: true);

    List<String> verbs() => [
      for (final pill in tester.widgetList<StatusPill>(find.byType(StatusPill)))
        pill.label,
    ];
    expect(verbs(), containsAllInOrder(['Changed', 'OK', 'Created', 'OK']));
    expect(verbs(), contains('Deleted'));
    expect(verbs(), contains('Refused'));
    expect(verbs(), isNot(contains('Read')));
    expect(verbs(), contains('Put back'));
    expect(find.text('Dana Reyes'), findsWidgets);

    await tester.tap(find.byKey(const Key('api-activity-changes')));
    await tester.pumpAndSettle();
    expect(verbs(), contains('Read'));
  });

  testWidgets('a change opens to each field before and after', (tester) async {
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
    await _pump(tester, _Server(), const Size(1300, 900), everyone: true);

    expect(find.byKey(const Key('api-activity-diff')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('api-activity-row-0')));
    await tester.pumpAndSettle();

    final diff = find.byKey(const Key('api-activity-diff'));
    for (final text in [
      'technician',
      'Marco Silva',
      'Priya Nair',
      'scheduled_for',
      '2026-10-08T09:00',
      '2026-10-09T14:00',
    ]) {
      expect(
        find.descendant(of: diff, matching: find.text(text)),
        findsOneWidget,
        reason: text,
      );
    }
    final was = tester.widget<Text>(
      find.descendant(of: diff, matching: find.text('Marco Silva')),
    );
    expect(was.style?.decoration, TextDecoration.lineThrough);

    await tester.tap(find.byKey(const Key('api-activity-copy')));
    await tester.pumpAndSettle();
    expect(jsonDecode(copied.single), {
      'technician': 'Priya Nair',
      'scheduled_for': '2026-10-09T14:00',
    });
  });

  testWidgets('a delete opens to what it held', (tester) async {
    await _pump(tester, _Server(), const Size(1300, 900), everyone: true);

    await tester.tap(find.byKey(const ValueKey('api-activity-row-2')));
    await tester.pumpAndSettle();
    final held = find.byKey(const Key('api-activity-held'));
    expect(
      find.descendant(of: held, matching: find.text('Gate code is 4410.')),
      findsOneWidget,
    );
  });

  testWidgets('the filters ask the server, and older rows come on request', (
    tester,
  ) async {
    final server = _Server(full: true);
    await _pump(tester, server, const Size(1300, 900), everyone: true);

    await tester.tap(find.byKey(const Key('api-activity-person')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Marco Silva').last);
    await tester.pumpAndSettle();
    expect(server.asked.last['user_id'], 'u-2');

    await tester.ensureVisible(find.byKey(const Key('api-activity-older')));
    await tester.tap(find.byKey(const Key('api-activity-older')));
    await tester.pumpAndSettle();
    expect(server.asked.last['before'], '2026-10-07T14:58:00Z');
    expect(server.asked.last['user_id'], 'u-2');
  });

  testWidgets('on a phone a change takes the list\'s place, with a way back', (
    tester,
  ) async {
    await _pump(tester, _Server(), const Size(390, 844), everyone: true);

    expect(find.byKey(const Key('api-activity-diff')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('api-activity-row-0')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('api-activity-detail')), findsOneWidget);
    expect(find.byKey(const Key('api-activity-diff')), findsOneWidget);
    expect(find.byKey(const ValueKey('api-activity-row-1')), findsNothing);

    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('api-activity-row-1')), findsOneWidget);
  });

  testWidgets('a key in Your account shows its last few changes, the rest in '
      'a panel', (tester) async {
    final server = _Server();
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
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('api-key-activity-list-7')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('api-key-activity-7')));
    await tester.pumpAndSettle();
    final list = find.byKey(const ValueKey('api-key-activity-list-7'));
    expect(list, findsOneWidget);
    expect(server.asked.last['key_id'], '7');
    expect(
      find.descendant(of: list, matching: find.byType(StatusPill)),
      findsNWidgets(3),
    );

    await tester.tap(find.byKey(const Key('api-activity-all')));
    await tester.pumpAndSettle();
    expect(find.text('Everything done with this key'), findsOneWidget);
    expect(server.asked.last['key_id'], '7');
  });

  // Jose, asked whether he wanted one-click revert: "Sure" (2026-10-07).
  testWidgets('Put it back only where the server can, answered in place', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, const Size(1300, 900), everyone: true);

    await tester.tap(find.byKey(const ValueKey('api-activity-row-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('api-activity-revert')));
    await tester.pumpAndSettle();

    expect(server.reverted, ['/api/v1/api-keys/activity/ev-1/revert']);
    expect(
      tester.widget<Text>(find.byKey(const Key('api-activity-said'))).data,
      'Put back.',
    );
    expect(find.byKey(const Key('api-activity-revert')), findsNothing);
    expect(find.textContaining('Put back '), findsOneWidget);

    // A delete is not one the server can put back.
    await tester.tap(find.byKey(const ValueKey('api-activity-row-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('api-activity-held')), findsOneWidget);
    expect(find.byKey(const Key('api-activity-revert')), findsNothing);
  });

  testWidgets('one not put back exactly says to check it', (tester) async {
    await _pump(
      tester,
      _Server(revert: 'inexact'),
      const Size(1300, 900),
      everyone: true,
    );

    await tester.tap(find.byKey(const ValueKey('api-activity-row-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('api-activity-revert')));
    await tester.pumpAndSettle();

    expect(
      tester.widget<Text>(find.byKey(const Key('api-activity-said'))).data,
      'Saved, but the record does not read exactly as it did before. Check '
      'it.',
    );
  });

  testWidgets('a refusal for good says why and takes the button away', (
    tester,
  ) async {
    await _pump(
      tester,
      _Server(revert: 'conflict'),
      const Size(1300, 900),
      everyone: true,
    );

    await tester.tap(find.byKey(const ValueKey('api-activity-row-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('api-activity-revert')));
    await tester.pumpAndSettle();

    expect(
      find.text('It has changed since, so nothing was put back.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('api-activity-revert')), findsNothing);
    expect(find.textContaining('Put back '), findsNothing);
  });

  testWidgets('one already put back says so, with no button', (tester) async {
    await _pump(tester, _Server(), const Size(1300, 900), everyone: true);

    await tester.tap(find.byKey(const ValueKey('api-activity-row-5')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Put back '), findsOneWidget);
    expect(find.byKey(const Key('api-activity-revert')), findsNothing);
  });
}

class _Server {
  _Server({this.full = false, this.revert = 'ok'});

  /// A full page of 200, so there may be older rows.
  final bool full;

  /// How a put-back answers: ok, inexact or conflict.
  final String revert;
  final List<Map<String, String>> asked = [];
  final List<String> reverted = [];

  static final _rows = [
    {
      'id': 'ev-1',
      'revertable': true,
      'reverted_at': null,
      'at': '2026-10-07T17:42:00Z',
      'action': 'api_key.request',
      'who': 'Dana Reyes',
      'user_id': 'u-1',
      'key_id': 7,
      'key_name': 'Claude on my laptop',
      'method': 'PATCH',
      'path': '/api/v1/jobs/JOB-10412',
      'status': 200,
      'via': 'mcp',
      'sent': {'technician': 'Priya Nair', 'scheduled_for': '2026-10-09T14:00'},
      // As the server keeps it: the GET's whole answer.
      'before': {
        'data': {
          'technician': 'Marco Silva',
          'scheduled_for': '2026-10-08T09:00',
          'notes': 'Side gate',
        },
      },
    },
    {
      'at': '2026-10-07T17:40:00Z',
      'action': 'api_key.request',
      'who': 'Dana Reyes',
      'user_id': 'u-1',
      'key_id': 7,
      'key_name': 'Claude on my laptop',
      'method': 'POST',
      'path': '/api/v1/customers',
      'status': 201,
      'via': 'mcp',
      'sent': {'name': 'Harbor Bakery'},
      'before': null,
    },
    {
      'at': '2026-10-07T15:05:00Z',
      'action': 'api_key.request',
      'who': 'Marco Silva',
      'user_id': 'u-2',
      'key_id': 3,
      'key_name': 'Weekly numbers script',
      'method': 'DELETE',
      'path': '/api/v1/jobs/JOB-10388/notes/77',
      'status': 204,
      'via': 'api',
      'sent': null,
      'before': {
        'data': {'id': 77, 'text': 'Gate code is 4410.'},
      },
    },
    {
      'at': '2026-10-07T14:58:00Z',
      'action': 'api_key.request',
      'who': 'Marco Silva',
      'user_id': 'u-2',
      'key_id': 3,
      'key_name': 'Weekly numbers script',
      'method': 'PUT',
      'path': '/api/v1/customers/C-2041',
      'status': 403,
      'via': 'api',
      'sent': {'name': 'Bayline Doors'},
      'before': null,
    },
    {
      'at': '2026-10-07T14:00:00Z',
      'action': 'api_key.request',
      'who': 'Dana Reyes',
      'user_id': 'u-1',
      'key_id': 7,
      'key_name': 'Claude on my laptop',
      'method': 'GET',
      'path': '/api/v1/jobs',
      'status': 200,
      'via': 'mcp',
      'sent': null,
      'before': null,
    },
    {
      'id': 'ev-6',
      'revertable': true,
      'reverted_at': '2026-10-06T21:00:00Z',
      'at': '2026-10-06T20:00:00Z',
      'action': 'api_key.request',
      'who': 'Dana Reyes',
      'user_id': 'u-1',
      'key_id': 7,
      'key_name': 'Claude on my laptop',
      'method': 'PATCH',
      'path': '/api/v1/jobs/JOB-10399',
      'status': 200,
      'via': 'mcp',
      'sent': {'status': 'done'},
      'before': {'status': 'scheduled'},
    },
    {
      'at': '2026-10-06T19:00:00Z',
      'action': 'api_key.request',
      'who': 'Dana Reyes',
      'user_id': 'u-1',
      'key_id': 7,
      'key_name': 'Claude on my laptop',
      'method': 'POST',
      'path': '/api/v1/jobs/JOB-10399/notes',
      'status': 201,
      'via': 'mcp',
      'sent': {'text': 'Chimney cap photographed.'},
      'before': null,
    },
    {
      'id': 'ev-8',
      'at': '2026-10-06T18:00:00Z',
      'action': 'api_key.reverted',
      'who': 'Marco Silva',
      'user_id': 'u-2',
      'key_id': 3,
      'key_name': 'Weekly numbers script',
      'method': 'PUT',
      'path': '/api/v1/customers/C-2040',
      'status': 200,
      'via': 'api',
      'sent': null,
      'before': null,
    },
  ];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'POST' && path.endsWith('/revert')) {
      reverted.add(path);
      return switch (revert) {
        'inexact' => _json({
          'reverted': false,
          'message':
              'Saved, but the record does not read exactly as it did before. '
              'Check it.',
        }),
        'conflict' => _json({
          'message': 'It has changed since, so nothing was put back.',
        }, 409),
        _ => _json({'message': 'Put back.', 'reverted': true}),
      };
    }
    if (path.endsWith('/api-keys/activity')) {
      asked.add(request.url.queryParameters);
      final key = request.url.queryParameters['key_id'];
      final person = request.url.queryParameters['user_id'];
      final rows = [
        for (final row in _rows)
          if ((key == null || '${row['key_id']}' == key) &&
              (person == null || row['user_id'] == person))
            row,
      ];
      return _json({
        'summary': {'keys': 2, 'calls': 9, 'changes': 4, 'refused': 1},
        'activity': full
            ? [for (var i = 0; i < 200; i++) rows[i % rows.length]]
            : rows,
      });
    }
    if (path.endsWith('/me/api-keys')) {
      return _json({
        'api_keys': [
          {
            'id': 7,
            'name': 'Claude on my laptop',
            'level': 'editor',
            'effective_level': 'editor',
            'last_used_at': null,
            'created_at': '2026-10-06T13:00:00Z',
          },
        ],
        'allowed_levels': ['viewer', 'editor', 'full'],
      });
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

Future<void> _pump(
  WidgetTester tester,
  _Server server,
  Size size, {
  bool everyone = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ApiActivity(
            api: ApiClient(client: MockClient(server.handle)),
            everyone: everyone,
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
