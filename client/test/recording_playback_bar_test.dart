import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_playback_bar.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_recording_player.dart';

/// Jose, 2026-10-09: "add 1.5, 2, speed, so u can listen to them faster".
void main() {
  Future<FakeRecordingPlayer> pump(
    WidgetTester tester, {
    Duration? knownLength,
  }) async {
    final player = FakeRecordingPlayer();
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: 420,
            child: RecordingPlaybackBar(
              player: player,
              load: () async => [1, 2, 3],
              knownLength: knownLength,
            ),
          ),
        ),
      ),
    );
    return player;
  }

  testWidgets('a speed button sets how fast the player plays', (tester) async {
    final player = await pump(tester);

    await tester.tap(find.byKey(const ValueKey('recording-speed-1.5')));
    await tester.pump();
    expect(player.speed, 1.5);

    await tester.tap(find.byKey(const ValueKey('recording-speed-2.0')));
    await tester.pump();
    expect(player.speed, 2);
    expect(find.text('2×'), findsOneWidget);
  });

  testWidgets('a known length reads before anything is fetched', (
    tester,
  ) async {
    await pump(tester, knownLength: const Duration(minutes: 3, seconds: 14));
    expect(find.text('0:00 / 3:14'), findsOneWidget);
    expect(find.text('--:--'), findsNothing);
  });

  testWidgets('a length of zero is not a length', (tester) async {
    await pump(tester, knownLength: Duration.zero);
    expect(find.text('--:--'), findsOneWidget);
  });
}
