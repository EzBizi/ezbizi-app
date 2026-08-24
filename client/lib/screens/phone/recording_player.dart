import 'recording_player_io.dart'
    if (dart.library.js_interop) 'recording_player_web.dart' as impl;

/// Plays a call recording fetched through EzBizi (design §9).
///
/// Tests inject a fake. The browser uses a blob URL and an audio element; the
/// VM stub is silent so `flutter test` still compiles.
abstract class RecordingPlayer {
  /// Decode [bytes] and start playback. Must not claim success without this.
  Future<void> play(List<int> bytes);

  /// Stop current playback, if any.
  void stop();

  void dispose();
}

RecordingPlayer createRecordingPlayer() => impl.create();
