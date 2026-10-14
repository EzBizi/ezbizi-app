import 'dart:async';
import 'dart:typed_data';

import 'package:audioplayers/audioplayers.dart';

/// Where a recording has got to. One value, so a screen cannot draw a
/// transport out of step with the sound.
class RecordingPlayback {
  const RecordingPlayback({
    this.playing = false,
    this.position = Duration.zero,
    this.duration = Duration.zero,
  });

  final bool playing;
  final Duration position;

  /// Zero until the platform has decoded enough to know. A slider reads this
  /// and stays disabled while it is zero rather than drawing a scrubber over
  /// an unknown length.
  final Duration duration;

  bool get loaded => duration > Duration.zero;

  RecordingPlayback copyWith({
    bool? playing,
    Duration? position,
    Duration? duration,
  }) => RecordingPlayback(
    playing: playing ?? this.playing,
    position: position ?? this.position,
    duration: duration ?? this.duration,
  );
}

/// Plays a call recording or voicemail INSIDE the app, on every platform.
///
/// ── What this replaced, and why it was wrong ─────────────────────
///
/// Windows used to write the audio into the system temp directory and hand the
/// file to whatever the machine opens an MP3 with. The button read "Play in
/// your audio player". Two things were wrong and only one was about taste:
///
///   1. EzBizi carries LIVE CALL AUDIO on that same machine. An app that can
///      hold a phone call and cannot play a recording is not missing a
///      capability, it is missing the code below.
///   2. It left a customer's voicemail unencrypted in %TEMP% after playback,
///      outside the app, where nothing in §9 retention reaches it. That is a
///      privacy leak the interface complaint was hiding.
///
/// There is no conditional import and no platform file any more — one
/// implementation for android, ios, macos, linux, windows and web, because
/// `audioplayers` ships a platform implementation for each of them.
///
/// ── Played from memory, never from a file ────────────────────────
///
/// [BytesSource] hands the bytes to the platform directly: `SHCreateMemStream`
/// on Windows, a blob on the web, memory on Android. NOT on iOS, macOS and
/// Linux: audioplayers writes them to an app-private temp file there, named
/// by a hash and without an extension, which is why the type is passed.
/// The audio type a recording's first bytes say it is: Twilio and Google
/// send MP3, some voicemail WAV. Null when unknown, and the platform guesses.
String? audioMimeType(List<int> bytes) {
  bool starts(List<int> magic) =>
      bytes.length >= magic.length &&
      List.generate(magic.length, (i) => bytes[i] == magic[i]).every((b) => b);
  if (starts([0x49, 0x44, 0x33]) || (bytes.length > 1 && bytes[0] == 0xFF && bytes[1] & 0xE0 == 0xE0)) {
    return 'audio/mpeg';
  }
  if (starts([0x52, 0x49, 0x46, 0x46])) return 'audio/wav';
  if (starts([0x4F, 0x67, 0x67, 0x53])) return 'audio/ogg';
  if (bytes.length > 8 && bytes[4] == 0x66 && bytes[5] == 0x74 && bytes[6] == 0x79 && bytes[7] == 0x70) {
    return 'audio/mp4';
  }
  return null;
}

abstract class RecordingPlayer {
  RecordingPlayback get state;

  /// Every change, including the track ending on its own — which is a change
  /// no caller asked for and every transport has to hear about.
  Stream<RecordingPlayback> get changes;

  /// Decode [bytes] and start. Throws [RecordingPlaybackFailure] when it
  /// cannot, and never returns quietly on failure: a `play()` that could do
  /// nothing and say nothing is exactly what shipped last time.
  Future<void> play(List<int> bytes);

  Future<void> pause();

  Future<void> resume();

  Future<void> stop();

  Future<void> seek(Duration to);

  /// How fast it plays: 1, 1.5 or 2 (Jose, 2026-10-09: "add 1.5, 2, speed,
  /// so u can listen to them faster"). Kept by the player, which a screen
  /// shares, so every recording on that screen plays at the speed picked.
  double get speed;

  Future<void> setSpeed(double speed);

  void dispose();
}

/// Playback could not start, and the reason is worth showing somebody.
class RecordingPlaybackFailure implements Exception {
  const RecordingPlaybackFailure(this.message);

  final String message;

  @override
  String toString() => message;
}

RecordingPlayer createRecordingPlayer() => AudioplayersRecordingPlayer();

/// The real one.
class AudioplayersRecordingPlayer implements RecordingPlayer {
  AudioplayersRecordingPlayer() {
    _subscriptions = [
      _player.onPositionChanged.listen(
        (position) => _set(_state.copyWith(position: position)),
      ),
      _player.onDurationChanged.listen(
        (duration) => _set(_state.copyWith(duration: duration)),
      ),

      // The end of the track. Without this the button stays on Pause forever
      // after the recording finishes, which is a transport describing a state
      // the sound left a minute ago.
      _player.onPlayerComplete.listen(
        (_) => _set(
          _state.copyWith(playing: false, position: Duration.zero),
        ),
      ),
    ];
  }

  final AudioPlayer _player = AudioPlayer();
  final _changes = StreamController<RecordingPlayback>.broadcast();
  late final List<StreamSubscription<Object?>> _subscriptions;

  RecordingPlayback _state = const RecordingPlayback();
  double _speed = 1;

  @override
  RecordingPlayback get state => _state;

  @override
  Stream<RecordingPlayback> get changes => _changes.stream;

  @override
  double get speed => _speed;

  @override
  Future<void> setSpeed(double speed) async {
    _speed = speed;
    await _player.setPlaybackRate(speed);
    // Same state, said again, so every transport redraws its speed.
    _set(_state);
  }

  @override
  Future<void> play(List<int> bytes) async {
    if (bytes.isEmpty) {
      throw const RecordingPlaybackFailure('That recording is empty.');
    }

    try {
      await _player.stop();
      _set(const RecordingPlayback(playing: true));
      // The type is named: iOS and macOS play bytes from an extensionless
      // temp file, and AVPlayer cannot open one it cannot identify (Jose,
      // 2026-10-14: recordings would not play on iOS).
      await _player.play(BytesSource(Uint8List.fromList(bytes), mimeType: audioMimeType(bytes)));
      // A new source can start at normal speed; the one picked carries on.
      if (_speed != 1) await _player.setPlaybackRate(_speed);
    } catch (error) {
      _set(const RecordingPlayback());

      // The platform's own words, kept. "Playback failed" sends somebody to
      // check their speakers for what is usually a codec or a permission.
      throw RecordingPlaybackFailure('This recording would not play. $error');
    }
  }

  @override
  Future<void> pause() async {
    await _player.pause();
    _set(_state.copyWith(playing: false));
  }

  @override
  Future<void> resume() async {
    await _player.resume();
    _set(_state.copyWith(playing: true));
  }

  @override
  Future<void> stop() async {
    await _player.stop();
    _set(const RecordingPlayback());
  }

  @override
  Future<void> seek(Duration to) async {
    await _player.seek(to);
    _set(_state.copyWith(position: to));
  }

  void _set(RecordingPlayback next) {
    _state = next;
    if (!_changes.isClosed) _changes.add(next);
  }

  @override
  void dispose() {
    for (final subscription in _subscriptions) {
      subscription.cancel();
    }
    _changes.close();
    _player.dispose();
  }
}
