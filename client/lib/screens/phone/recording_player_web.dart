import 'dart:js_interop';
import 'dart:typed_data';

import 'package:web/web.dart' as web;

import 'recording_player.dart';

/// Browser playback: object URL for the MPEG, then an HTML audio element.
RecordingPlayer create() => HtmlRecordingPlayer();

class HtmlRecordingPlayer implements RecordingPlayer {
  web.HTMLAudioElement? _audio;
  String? _url;

  /// The browser plays it in the page, so the screen can offer a stop.
  @override
  bool get playsInApp => true;

  @override
  Future<void> play(List<int> bytes) async {
    stop();
    final blob = web.Blob(
      [Uint8List.fromList(bytes).toJS].toJS,
      web.BlobPropertyBag(type: 'audio/mpeg'),
    );
    _url = web.URL.createObjectURL(blob);
    final audio = web.document.createElement('audio') as web.HTMLAudioElement
      ..src = _url!;
    _audio = audio;
    await audio.play().toDart;
  }

  @override
  void stop() {
    _audio?.pause();
    _audio?.remove();
    _audio = null;
    final url = _url;
    if (url != null) {
      web.URL.revokeObjectURL(url);
      _url = null;
    }
  }

  @override
  void dispose() => stop();
}
