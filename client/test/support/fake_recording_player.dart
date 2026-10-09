import 'dart:async';

import 'package:ezbizi_client/screens/phone/recording_player.dart';

/// A recording player for tests: records what it was asked to play, and can
/// be told to refuse.
///
/// One fake rather than one per test file. Three files each carried their own
/// copy, so the interface gaining `pause`, `resume` and `seek` broke all three
/// in the same way at once — and a fake that has to be edited in three places
/// is a fake that will drift into three different behaviours.
///
/// It drives the real [RecordingPlayback] value and the real change stream, so
/// a widget reading position or duration off the player is exercised here the
/// same way it is in the app.
class FakeRecordingPlayer implements RecordingPlayer {
  FakeRecordingPlayer({this.refuses, this.duration = const Duration(seconds: 30)});

  /// When set, [play] throws this instead of starting.
  String? refuses;

  /// The length the fake reports once playing, so a scrubber has something to
  /// scrub. Zero models audio whose duration the platform never worked out.
  final Duration duration;

  final played = <List<int>>[];
  final seeks = <Duration>[];
  int stops = 0;
  int pauses = 0;
  int resumes = 0;
  bool disposed = false;

  final _changes = StreamController<RecordingPlayback>.broadcast();
  RecordingPlayback _state = const RecordingPlayback();

  @override
  double speed = 1;

  @override
  Future<void> setSpeed(double next) async {
    speed = next;
    _set(_state);
  }

  @override
  RecordingPlayback get state => _state;

  @override
  Stream<RecordingPlayback> get changes => _changes.stream;

  @override
  Future<void> play(List<int> bytes) async {
    if (refuses != null) throw RecordingPlaybackFailure(refuses!);

    played.add(bytes);
    _set(RecordingPlayback(playing: true, duration: duration));
  }

  @override
  Future<void> pause() async {
    pauses++;
    _set(_state.copyWith(playing: false));
  }

  @override
  Future<void> resume() async {
    resumes++;
    _set(_state.copyWith(playing: true));
  }

  @override
  Future<void> stop() async {
    stops++;
    _set(const RecordingPlayback());
  }

  @override
  Future<void> seek(Duration to) async {
    seeks.add(to);
    _set(_state.copyWith(position: to));
  }

  /// Pretend the recording reached its end on its own — the change no caller
  /// asks for and every transport has to notice.
  void finish() => _set(const RecordingPlayback());

  void _set(RecordingPlayback next) {
    _state = next;
    if (!_changes.isClosed) _changes.add(next);
  }

  @override
  void dispose() {
    disposed = true;
    _changes.close();
  }
}
