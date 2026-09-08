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
  @override
  bool get playsInApp => true;

  final played = <List<int>>[];

  @override
  Future<void> play(List<int> bytes) async {
    played.add(bytes);
  }

  @override
  void stop() {}

  @override
  void dispose() {}
}

Future<void> _pump(
  WidgetTester tester, {
  required RecordingPlayer player,
  required Completer<void> recordingGate,
}) async {
  tester.view.physicalSize = const Size(600, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
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
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
