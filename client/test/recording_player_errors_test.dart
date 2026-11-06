import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// A machine with no audio output (health sweep, 2026-11-06): Windows builds
/// its media engine when the player is made, which is when Reports opens, and
/// says "Activate failed to create mediasink" on the player's event stream.
/// Nothing listened for errors there, so each one was an uncaught exception:
/// 290 client issues from one person opening Reports.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;

  tearDown(() {
    messenger.setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers'),
      null,
    );
    messenger.setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers.global'),
      null,
    );
  });

  test(
    'a platform error on the event stream is not uncaught, and stops the transport',
    () async {
      messenger.setMockMethodCallHandler(
        const MethodChannel('xyz.luan/audioplayers.global'),
        (_) async => null,
      );
      messenger.setMockMethodCallHandler(
        const MethodChannel('xyz.luan/audioplayers'),
        (call) async {
          if (call.method == 'create') {
            final id = (call.arguments as Map)['playerId'];
            messenger.setMockStreamHandler(
              EventChannel('xyz.luan/audioplayers/events/$id'),
              MockStreamHandler.inline(
                onListen: (_, sink) =>
                    sink.error(code: '4', message: 'MediaEngine error'),
              ),
            );
          }
          return null;
        },
      );

      final player = createRecordingPlayer();
      final states = <RecordingPlayback>[];
      final sub = player.changes.listen(states.add);

      await pumpEventQueue();

      expect(states, isNotEmpty, reason: 'the error reached nobody');
      expect(player.state.playing, isFalse);

      await sub.cancel();
      player.dispose();
    },
  );
}
