import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/voicemails_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/fake_recording_player.dart';

/// Voicemail, layout B (Mia, 2026-10-02): the cards as they were, with tiles
/// on top counted by the SERVER over the whole box — the list stops at 50.
void main() {
  testWidgets('the tiles show the server counts and filter the cards', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(tester, asked);

    String countOn(String key) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(Key('voicemail-tile-$key')),
            matching: find.byType(Text),
          ),
        )
        .elementAt(1)
        .data!;

    // 120 — more than the 50 the list asks for, which is why the client
    // cannot count them itself.
    expect(countOn('all'), '120');
    expect(countOn('todo'), '70');
    expect(countOn('dealt'), '50');
    expect(countOn('no_transcript'), '9');
    expect(find.text('Please ring me back.'), findsOneWidget);
    expect(find.text('Sorted, thanks.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('voicemail-tile-todo')));
    await tester.pumpAndSettle();

    expect(asked.last['dealt'], '0');
    expect(asked.last['voicemail_only'], '1');
    expect(find.text('Please ring me back.'), findsOneWidget);
    expect(find.text('Sorted, thanks.'), findsNothing);
    expect(countOn('all'), '120', reason: 'the tile filter changed the counts');

    await tester.tap(find.byKey(const Key('voicemail-tile-no_transcript')));
    await tester.pumpAndSettle();

    expect(asked.last['transcript'], '0');
    expect(asked.last.containsKey('dealt'), isFalse);
    expect(find.text('No messages in this state.'), findsOneWidget);
  });
}

Future<void> _pump(WidgetTester tester, List<Map<String, String>> asked) async {
  tester.view.physicalSize = const Size(1100, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  const waiting = {
    'id': 'c-1',
    'from_e164': '+13055550100',
    'voicemail': true,
    'dealt_at': null,
    'voicemail_transcript': 'Please ring me back.',
    'created_at': '2026-09-08T10:00:00+00:00',
  };
  const done = {
    'id': 'c-2',
    'from_e164': '+13055550101',
    'voicemail': true,
    'dealt_at': '2026-09-08T11:00:00+00:00',
    'voicemail_transcript': 'Sorted, thanks.',
    'created_at': '2026-09-08T09:00:00+00:00',
  };

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/phone/voicemail-boxes')) return _json({'data': []});

      if (path.endsWith('/phone/calls')) {
        final query = request.url.queryParameters;
        asked.add(query);

        final rows = switch ((query['dealt'], query['transcript'])) {
          ('0', _) => [waiting],
          ('1', _) => [done],
          (_, '0') => <Map<String, Object?>>[],
          _ => [waiting, done],
        };

        return _json({
          'data': rows,
          'meta': {
            'tiles': {'todo': 70, 'dealt': 50, 'no_transcript': 9, 'all': 120},
          },
        });
      }

      return _json({'data': []});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: VoicemailsPane(
          api: api,
          session: session,
          player: FakeRecordingPlayer(),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
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
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view'],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
