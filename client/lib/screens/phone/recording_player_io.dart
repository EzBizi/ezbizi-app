import 'dart:io';

import 'package:url_launcher/url_launcher.dart';

import 'recording_player.dart';

/// Desktop playback: write the audio out and hand it to the machine.
///
/// ── What this used to be ─────────────────────────────────────────
///
/// A stub whose `play()` was an empty method. So on Windows the Play button on
/// a voicemail was drawn, enabled, pressed, and reported success while playing
/// nothing at all — and the pane's catch swallowed failures besides, so there
/// was not even an error to notice. The server was fine the whole time: the
/// recording fetches in 7kB of MPEG.
///
/// That is the existence-not-function shape this module keeps producing, and
/// this one had the extra sting of being MY stub, written to let `flutter
/// test` compile and then left standing in production.
///
/// ── Why the OS player rather than an audio package ───────────────
///
/// Flutter has no built-in audio, and pulling in a playback dependency for one
/// button is more surface than the button is worth. Every desktop already has
/// something that plays an MP3, and handing the file over is the platform
/// feature rather than a reimplementation of it.
///
/// The cost is honest and worth naming: playback leaves the app, so there is
/// no in-app stop. [stop] therefore does nothing rather than pretending — see
/// [RecordingPlayer.playsInApp], which is how a screen knows to say "opens in
/// your audio player" instead of drawing a transport it cannot drive.
RecordingPlayer create() => _DesktopRecordingPlayer();

class _DesktopRecordingPlayer implements RecordingPlayer {
  File? _written;

  @override
  bool get playsInApp => false;

  @override
  Future<void> play(List<int> bytes) async {
    // Thrown, not swallowed. A recording that cannot be played is something
    // the screen has to be able to say out loud.
    // Directory.systemTemp rather than path_provider: dart:io already answers
    // this on every desktop, and a package for one line is a dependency to
    // keep patched forever.
    final file = File(
      '${Directory.systemTemp.path}${Platform.pathSeparator}'
      'ezbizi-recording-${DateTime.now().millisecondsSinceEpoch}.mp3',
    );

    await file.writeAsBytes(bytes, flush: true);
    _written = file;

    final opened = await launchUrl(Uri.file(file.path));
    if (!opened) {
      throw const RecordingPlaybackFailure(
        'This computer has nothing set up to play an MP3.',
      );
    }
  }

  /// Nothing to stop: the audio is somebody else's window now.
  @override
  void stop() {}

  @override
  void dispose() {
    // Best effort. A leftover temp file is the operating system's problem;
    // failing to delete one is not worth taking the screen down for.
    try {
      _written?.deleteSync();
    } catch (_) {
      // Still open in the player, most likely.
    }
    _written = null;
  }
}
