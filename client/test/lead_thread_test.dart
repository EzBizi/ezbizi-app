import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The thread beside (or behind) a lead, and the one state that matters.
///
/// Play is fetch-then-play: the recording is streamed through EzBizi with the
/// account's token and handed to the player as bytes, so there is no sound
/// until the whole file has arrived. A play button that sits silent for three
/// seconds on a twelve-minute call reads as broken, and the longest recordings
/// are the ones people most want to hear. The fetching state is not decoration.
void main() {
  testWidgets('play says it is fetching until the bytes arrive, then hands '
      'them to the player', (tester) async {
    final gate = Completer<void>();
    final player = _FakePlayer();

    await _pump(tester, player: player, recordingGate: gate);

    expect(find.text('Call · 2:05'), findsOneWidget);
    expect(find.textContaining('They called'), findsOneWidget);
    expect(find.textContaining('PHONE_CALL'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('play-c-call')));
    await tester.pump();

    expect(find.text('Fetching the recording…'), findsOneWidget);
    expect(
      player.played,
      isEmpty,
      reason: 'nothing to play before the bytes arrive',
    );

    gate.complete();
    await tester.pumpAndSettle();

    expect(find.text('Fetching the recording…'), findsNothing);
    expect(find.text('Playing'), findsOneWidget);
    expect(player.played, hasLength(1));
    expect(utf8.decode(player.played.single), 'ID3-fake-audio-bytes');
  });

  /// Desktop: the file is handed to the machine's own player (Phone's
  /// a7214518), so the app is not playing anything and must not say it is.
  /// This is the shape Jose hit on 0.0.0159, where the button was a stub.
  testWidgets('on a desktop the control says the audio opens elsewhere and '
      'never claims to be playing', (tester) async {
    final player = _FakePlayer(playsInApp: false);

    await _pump(tester, player: player, recordingGate: Completer()..complete());

    expect(find.textContaining('opens in your audio player'), findsOneWidget);
    expect(find.byTooltip('Play in your audio player'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('play-c-call')));
    await tester.pumpAndSettle();

    expect(player.played, hasLength(1));
    expect(find.text('Playing'), findsNothing);
    expect(find.byTooltip('Stop'), findsNothing);
  });

  testWidgets('a machine with nothing that plays an MP3 is told so, in the '
      "player's own words", (tester) async {
    await _pump(
      tester,
      player: _FakePlayer(
        playsInApp: false,
        refuses: 'This computer has nothing set up to play an MP3.',
      ),
      recordingGate: Completer()..complete(),
    );

    await tester.tap(find.byKey(const ValueKey('play-c-call')));
    await tester.pumpAndSettle();

    expect(
      find.text('This computer has nothing set up to play an MP3.'),
      findsOneWidget,
    );
  });

  testWidgets('a reply goes out on Send and appears in the thread as ours', (
    tester,
  ) async {
    await _pump(
      tester,
      player: _FakePlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.byKey(const ValueKey('reply-box')), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('reply-box')),
      'See you Saturday.',
    );
    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();

    expect(_sent, ['See you Saturday.']);
    expect(find.text('See you Saturday.'), findsOneWidget);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('reply-box')))
          .controller!
          .text,
      isEmpty,
      reason: 'the box clears once Google has taken it',
    );
  });

  testWidgets('a call lead has no reply box and says why', (tester) async {
    await _pump(
      tester,
      player: _FakePlayer(),
      recordingGate: Completer()..complete(),
      canReply: false,
    );

    expect(find.byKey(const ValueKey('reply-box')), findsNothing);
    expect(find.textContaining('only accepts replies to text'), findsOneWidget);
  });

  testWidgets('a refused reply stays in the box with the reason', (
    tester,
  ) async {
    await _pump(
      tester,
      player: _FakePlayer(),
      recordingGate: Completer()..complete(),
      refuseSend: 'Google refused the reply (HTTP 400). It was not sent.',
    );

    await tester.enterText(find.byKey(const ValueKey('reply-box')), 'Hello?');
    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();

    expect(
      find.text('Google refused the reply (HTTP 400). It was not sent.'),
      findsOneWidget,
    );
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('reply-box')))
          .controller!
          .text,
      'Hello?',
    );
  });

  testWidgets('opening the thread marks the lead read, and a note saved in '
      'place shows who wrote it', (tester) async {
    var reads = 0;
    String? saved;

    await _pump(
      tester,
      player: _FakePlayer(),
      recordingGate: Completer()..complete(),
      onRead: () async => reads++,
      onNote: (text) async {
        saved = text;
        return {
          'id': 'lead-1',
          'contact_name': 'Marcus Bell',
          'phone': '+1 (555) 123-4567',
          'service': 'fireplace_chimney',
          'status': 'new',
          'note': text,
          'note_by': {'id': 'u-2', 'name': 'Maya'},
          'note_updated_at': DateTime.now().toUtc().toIso8601String(),
        };
      },
    );

    expect(reads, 1, reason: 'seen once, on opening');

    await tester.tap(find.byKey(const ValueKey('note-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('note-box')),
      'Wants Saturday.',
    );
    await tester.tap(find.byKey(const ValueKey('note-save')));
    await tester.pumpAndSettle();

    expect(saved, 'Wants Saturday.');
    expect(find.text('Wants Saturday.'), findsOneWidget);
    // On the label line, who then what: "Note · Maya · just now".
    expect(find.textContaining('Note · Maya · '), findsOneWidget);
    expect(find.byKey(const ValueKey('note-edit')), findsOneWidget);
  });

  testWidgets('messages read as a thread: theirs left, yours right, with who '
      'and when', (tester) async {
    await _pump(
      tester,
      player: _FakePlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.text('Is Saturday ok?'), findsOneWidget);
    expect(find.text('Yes, 10am works.'), findsOneWidget);
    expect(find.textContaining('They · '), findsOneWidget);
    expect(find.textContaining('You · '), findsOneWidget);
    expect(find.textContaining('CONSUMER'), findsNothing);
    expect(find.textContaining('ADVERTISER'), findsNothing);
  });
}

class _FakePlayer implements RecordingPlayer {
  _FakePlayer({this.playsInApp = true, this.refuses});

  @override
  final bool playsInApp;

  /// When set, play() throws this the way the desktop player does when the
  /// machine has nothing that opens an MP3.
  final String? refuses;

  final played = <List<int>>[];

  @override
  Future<void> play(List<int> bytes) async {
    if (refuses != null) throw RecordingPlaybackFailure(refuses!);
    played.add(bytes);
  }

  @override
  void stop() {}

  @override
  void dispose() {}
}

final _sent = <String>[];

Future<void> _pump(
  WidgetTester tester, {
  required RecordingPlayer player,
  required Completer<void> recordingGate,
  bool canReply = true,
  String? refuseSend,
  Future<void> Function()? onRead,
  Future<Map<String, dynamic>?> Function(String? note)? onNote,
}) async {
  _sent.clear();
  tester.view.physicalSize = const Size(600, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST' && request.url.path.endsWith('/messages')) {
        final text = (jsonDecode(request.body) as Map)['text'] as String;
        if (refuseSend != null) {
          return http.Response(
            jsonEncode({'message': refuseSend}),
            502,
            headers: {'content-type': 'application/json'},
          );
        }
        _sent.add(text);
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'c-sent',
              'channel': 'MESSAGE',
              'from': 'ADVERTISER',
              'occurred_at': '2026-09-08T18:00:00Z',
              'duration_ms': null,
              'text': text,
              'attachments': <String>[],
              'has_recording': false,
            },
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/recording')) {
        await recordingGate.future;
        return http.Response.bytes(
          utf8.encode('ID3-fake-audio-bytes'),
          200,
          headers: {'content-type': 'audio/mpeg'},
        );
      }

      if (request.url.path.endsWith('/leads/lead-1')) {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'lead-1',
              'can_reply': canReply,
              'conversations': [
                {
                  'id': 'c-call',
                  'channel': 'PHONE_CALL',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-06T10:00:00Z',
                  'duration_ms': 125000,
                  'text': null,
                  'attachments': <String>[],
                  'has_recording': true,
                },
                {
                  'id': 'c-in',
                  'channel': 'MESSAGE',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-07T09:00:00Z',
                  'duration_ms': null,
                  'text': 'Is Saturday ok?',
                  'attachments': <String>[],
                  'has_recording': false,
                },
                {
                  'id': 'c-out',
                  'channel': 'MESSAGE',
                  'from': 'ADVERTISER',
                  'occurred_at': '2026-09-07T09:30:00Z',
                  'duration_ms': null,
                  'text': 'Yes, 10am works.',
                  'attachments': <String>[],
                  'has_recording': false,
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{}',
        404,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadThread(
          api: api,
          player: player,
          lead: const {
            'id': 'lead-1',
            'contact_name': 'Marcus Bell',
            'phone': '+1 (555) 123-4567',
            'service': 'fireplace_chimney',
            'status': 'new',
            'franchise': {'id': 'f-1', 'name': 'Connecticut'},
          },
          onStatusChanged: (_) async {},
          onRead: onRead,
          onNote: onNote,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
