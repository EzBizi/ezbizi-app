import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/screens/phone/voicemails_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Pressing Play, and what happens when it cannot.
///
/// ── The bug these exist for ──────────────────────────────────────
///
/// On Windows the recording player was a stub whose `play()` was an empty
/// method, and this pane caught every exception and threw it away. So the
/// button was drawn, enabled, pressed, and silent — while the server returned
/// the audio perfectly well the whole time. The only description the app made
/// possible was "recordings are not playing", which is what got reported.
///
/// A player that cannot play must SAY so, and a player that plays somewhere
/// else must say where. Neither is about audio; both are about a control not
/// claiming more than it does.
void main() {
  testWidgets('a recording that will not play says why', (tester) async {
    await _pump(tester, player: _RefusingPlayer());

    await _tap(tester, find.textContaining('Play'));

    expect(
      find.textContaining('nothing set up to play'),
      findsOneWidget,
      reason: 'the failure was swallowed, exactly as it used to be',
    );
  });

  /// Where the sound will come from, before somebody presses it twice.
  testWidgets('a player that plays elsewhere says so on the button', (
    tester,
  ) async {
    await _pump(tester, player: _RefusingPlayer());

    expect(find.text('Play in your audio player'), findsOneWidget);
    expect(find.text('Play'), findsNothing);
  });

  /// And in the browser, where it really does play in the page, it is Play.
  testWidgets('an in-app player keeps the plain label', (tester) async {
    await _pump(tester, player: _SilentButHonestPlayer());

    expect(find.text('Play'), findsOneWidget);
  });

  /// A recording the server will not hand over is not a silent button either.
  testWidgets('a recording that cannot be fetched says that instead', (
    tester,
  ) async {
    await _pump(
      tester,
      player: _SilentButHonestPlayer(),
      recordingStatus: 502,
    );

    await _tap(tester, find.textContaining('Play'));

    expect(find.textContaining('could not be fetched'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required RecordingPlayer player,
  int recordingStatus = 200,
}) async {
  tester.view.physicalSize = const Size(1100, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/phone/voicemail-boxes')) return _json({'data': []});

      if (path.contains('/recording')) {
        return http.Response.bytes(
          recordingStatus == 200 ? [0, 1, 2, 3] : [],
          recordingStatus,
        );
      }

      if (path.endsWith('/phone/calls')) {
        return _json({
          'data': [
            {
              'id': 'c-1',
              'number_id': 'n-1',
              'direction': 'inbound',
              'from_e164': '+13055550100',
              'to_e164': '+12035550142',
              'voicemail': true,
              'recording_sid': 'REaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
              'created_at': '2026-09-08T10:00:00+00:00',
            },
          ],
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
        body: VoicemailsPane(api: api, session: session, player: player),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

/// Refuses, loudly — the shape the desktop player has when the machine has no
/// MP3 handler, and the shape the old stub should have had.
class _RefusingPlayer implements RecordingPlayer {
  @override
  bool get playsInApp => false;

  @override
  Future<void> play(List<int> bytes) async {
    throw const RecordingPlaybackFailure(
      'This computer has nothing set up to play an MP3.',
    );
  }

  @override
  void stop() {}

  @override
  void dispose() {}
}

/// Plays nothing, but does not claim to be somewhere else.
class _SilentButHonestPlayer implements RecordingPlayer {
  @override
  bool get playsInApp => true;

  @override
  Future<void> play(List<int> bytes) async {}

  @override
  void stop() {}

  @override
  void dispose() {}
}

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
