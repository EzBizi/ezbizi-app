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

import 'support/fake_recording_player.dart';

/// Pressing Play, and what happens when it cannot.
///
/// ── The two bugs these exist for ─────────────────────────────────
///
/// First: on Windows the recording player was a stub whose `play()` was an
/// empty method, and this pane threw every exception away. The button was
/// drawn, enabled, pressed, and silent while the server returned the audio
/// perfectly well. The only description the app made possible was "recordings
/// are not playing", which is what got reported.
///
/// Then the fix was worse than the bug: it wrote the voicemail into %TEMP% and
/// handed it to whatever opens an MP3, and the button read "Play in your audio
/// player". An app that carries live call audio does not need another
/// application to play a recording, and a customer's voicemail does not belong
/// loose in a temp directory.
///
/// So these assert the transport IN the app: play, pause, scrub, and say so
/// out loud when it cannot.
void main() {
  testWidgets('a recording that will not play says why', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(refuses: 'This recording would not play.'),
    );

    await _tap(tester, find.byKey(const Key('recording-play')));

    expect(
      find.textContaining('would not play'),
      findsOneWidget,
      reason: 'the failure was swallowed, exactly as it used to be',
    );
  });

  /// Nothing on this screen sends anybody to another application.
  testWidgets('the audio plays here, and nothing offers to open it elsewhere', (
    tester,
  ) async {
    await _pump(tester, player: FakeRecordingPlayer());

    expect(find.textContaining('audio player'), findsNothing);
    expect(find.textContaining('opens in'), findsNothing);
    expect(find.byKey(const Key('recording-play')), findsWidgets);
  });

  /// A transport, not a Play button.
  ///
  /// The job is listening to a voicemail, and what people do is go back four
  /// seconds because somebody said a number too fast.
  testWidgets('playing gives a real transport: pause and a scrubber', (
    tester,
  ) async {
    final player = FakeRecordingPlayer();
    await _pump(tester, player: player);

    await _tap(tester, find.byKey(const Key('recording-play')).first);

    expect(player.played, hasLength(1), reason: 'nothing was handed to the player');
    expect(find.byTooltip('Pause'), findsOneWidget);

    final scrub = tester.widget<Slider>(
      find.byKey(const Key('recording-scrub')).first,
    );
    expect(
      scrub.onChanged,
      isNotNull,
      reason: 'the scrubber is dead, so there is no way back four seconds',
    );

    await _tap(tester, find.byTooltip('Pause'));
    expect(player.pauses, 1);
  });

  /// A recording the server will not hand over is not a silent button either.
  testWidgets('a recording that cannot be fetched says that instead', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingStatus: 502,
    );

    await _tap(tester, find.byKey(const Key('recording-play')).first);

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
