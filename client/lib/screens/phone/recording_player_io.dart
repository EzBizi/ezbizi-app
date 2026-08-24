import 'recording_player.dart';

/// VM / desktop stub. MPEG playback is a browser audio element.
RecordingPlayer create() => _SilentRecordingPlayer();

class _SilentRecordingPlayer implements RecordingPlayer {
  @override
  Future<void> play(List<int> bytes) async {}

  @override
  void stop() {}

  @override
  void dispose() {}
}
