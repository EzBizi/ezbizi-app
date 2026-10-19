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
    String? downloadName,
    double width = 420,
  }) async {
    final player = FakeRecordingPlayer();
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: width,
            child: RecordingPlaybackBar(
              player: player,
              load: () async => [1, 2, 3],
              knownLength: knownLength,
              downloadName: downloadName,
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

  // Jose, 2026-10-19: "add option to download a recording as well".
  testWidgets('a Download button only where the screen names the file, and '
      'it fits a narrow bar', (tester) async {
    await pump(tester);
    expect(find.byKey(const Key('recording-download')), findsNothing);

    for (final width in [360.0, 300.0]) {
      await pump(
        tester,
        downloadName: '2026-10-19 1432 +12125551212',
        width: width,
      );
      expect(find.byKey(const Key('recording-download')), findsOneWidget);
      expect(tester.takeException(), isNull);
    }
  });

  test('a call recording is named by its time and number', () {
    expect(
      RecordingPlaybackBar.nameFor('2026-10-19 14:32:00', '+1 (212) 555-1212'),
      '2026-10-19 1432 +12125551212',
    );
    expect(RecordingPlaybackBar.nameFor(null, null), 'recording');
  });
}
