import 'recording_player_io.dart'
    if (dart.library.js_interop) 'recording_player_web.dart' as impl;

/// Plays a call recording fetched through EzBizi (design §9).
///
/// Tests inject a fake. The browser uses a blob URL and an audio element; the
/// VM stub is silent so `flutter test` still compiles.
abstract class RecordingPlayer {
  /// Whether the audio plays INSIDE the app.
  ///
  /// False on desktop, where the file is handed to whatever the machine uses
  /// for an MP3. A screen reads this to say where the sound is about to come
  /// from, and to leave out a stop control it could not honour — a transport
  /// that cannot drive the thing it points at is the same lie as a Play button
  /// that plays nothing.
  bool get playsInApp => true;

  /// Decode [bytes] and start playback. Must not claim success without this.
  ///
  /// Throws [RecordingPlaybackFailure] when it cannot. Returning quietly is
  /// what let a silent stub stand in production for weeks.
  Future<void> play(List<int> bytes);

  /// Stop current playback, if any.
  void stop();

  void dispose();
}

RecordingPlayer createRecordingPlayer() => impl.create();

/// Playback could not start, and the reason is worth showing somebody.
class RecordingPlaybackFailure implements Exception {
  const RecordingPlaybackFailure(this.message);

  final String message;

  @override
  String toString() => message;
}
