import 'dart:async';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';

import 'clock_window.dart';
import 'os_notifications.dart';

/// Making a human notice that a call is arriving.
///
/// ── Why this exists ─────────────────────────────────────────────
///
/// The incoming-call bar was the only sign of a call. It is a strip across a
/// screen somebody has to be LOOKING at, so a call arriving while the window
/// was minimised, behind a browser, or on another monitor was a call nobody
/// knew about. Jose's words: we cannot afford to lose any call.
///
/// Three things, because one is not enough on any platform:
///
///   the tone   the only signal that works when nobody is looking at all
///   the raise  brings the window up so the bar is actually on screen
///   the toast  what is left when Windows refuses the focus steal, which it
///              routinely does for a window that is not already foreground
///
/// ── Why the tone is synthesised rather than shipped ─────────────
///
/// A ring is a couple of sine waves and a cadence. Generating it costs forty
/// lines, needs no asset, no licence and no file to go missing from a build,
/// and it is the same on every platform. [BytesSource] hands the bytes
/// straight to the platform, which is already how voicemail plays.
///
/// ── A RINGER, and deliberately not a ringback ───────────────────
///
/// These are two different sounds and the difference carries meaning. What a
/// CALLER hears while waiting is ringback: in North America 440 Hz + 480 Hz,
/// two seconds on and four off. What a phone does to get your attention is a
/// ringer, and it does not sound like that.
///
/// This played ringback at the person being CALLED. Jose reported it plainly:
/// an inbound call rang "as if I was the one calling". He was hearing the
/// caller's tone from the wrong end — and the two were identical by
/// construction, because Twilio supplies our outbound ringback with
/// `ringTone: 'us'` on the `<Dial>`, which is the same 440 + 480.
///
/// So the outbound side is untouched — a person ringing out SHOULD hear
/// standard ringback, that is what tells them the far end is ringing — and
/// this is now a ringer: a warbling double burst, higher and faster, which no
/// telephone network produces and nobody can mistake for a call they placed.
///
/// ── Nothing here is awaited by the desk ─────────────────────────
///
/// Every method returns void and swallows its own failures. A plugin call
/// awaited under a widget test's fake clock never completes, and this module
/// has already shipped a dialog that hung for exactly that reason — so the
/// desk updates its state and calls these afterwards, never through them. A
/// phone that cannot make a noise still has to be a phone that rings.
abstract class CallAlerts {
  /// A call is arriving from [from]. Starts the tone, raises the window, and
  /// posts a notification. Safe to call twice for the same call.
  void incoming(String from);

  /// The call was answered, declined, or went away. Silence.
  void stop();

  void dispose();
}

/// The real one.
class SystemCallAlerts implements CallAlerts {
  SystemCallAlerts();

  final AudioPlayer _player = AudioPlayer();

  /// Which call we are already ringing for, so a second notification for the
  /// same one does not restart the tone from the beginning every time the
  /// device repeats itself.
  String? _ringingFor;

  static const int _notificationId = 918273;

  @override
  void incoming(String from) {
    if (_ringingFor == from) return;
    _ringingFor = from;

    unawaited(_ring());
    unawaited(ClockWindow.raise());
    unawaited(
      OsNotifications.show(
        id: _notificationId,
        title: 'Incoming call',
        body: from.isEmpty ? 'Somebody is calling.' : from,
      ),
    );
  }

  @override
  void stop() {
    if (_ringingFor == null) return;
    _ringingFor = null;

    unawaited(_silence());
  }

  Future<void> _ring() async {
    try {
      await _player.stop();

      // The cadence is IN the buffer, so looping it is the whole ring — two
      // seconds of tone, four of silence, forever. A one-shot beep would say
      // a call arrived and stop saying it a second later, which is the same
      // problem as the bar.
      await _player.setReleaseMode(ReleaseMode.loop);
      await _player.play(BytesSource(_ringWav));
    } catch (_) {
      // A machine with no audio device is still a machine that must show the
      // bar and raise the window.
    }
  }

  Future<void> _silence() async {
    try {
      await _player.setReleaseMode(ReleaseMode.release);
      await _player.stop();
    } catch (_) {}
  }

  @override
  void dispose() {
    unawaited(_silence());
    _player.dispose();
  }

  /// The bytes, for a test. A player faithfully playing four seconds of
  /// silence passes every behavioural test there is.
  @visibleForTesting
  static Uint8List get ringToneBytes => _ringWav;

  /// Built once and kept, because a caller does not wait for a buffer.
  static final Uint8List _ringWav = _wav(_ringer());

  /// The shape of the ring, in milliseconds.
  ///
  /// Two short bursts and a rest — "ring-ring … ring-ring" — which is what a
  /// telephone does and what ringback never does. The rest is long enough
  /// that somebody can think between rings and short enough that a call
  /// arriving while they are out of the room keeps announcing itself.
  static const int _burstMs = 400;
  static const int _gapMs = 200;
  static const int _restMs = 2000;

  /// How fast the two pitches alternate inside a burst. Around 50 ms reads as
  /// a warble; much slower is two separate notes, much faster is a buzz.
  static const int _warbleMs = 50;

  /// Higher than ringback's 440/480 as well as faster, so the two are
  /// distinguishable through a laptop speaker in a noisy room, not only side
  /// by side.
  static const double _lowHz = 660;
  static const double _highHz = 880;

  /// 16-bit mono samples at 22.05 kHz — plenty for two sine waves, and a
  /// quarter the bytes of CD rate.
  static const int _rate = 22050;

  static int _samplesFor(int milliseconds) => (_rate * milliseconds) ~/ 1000;

  static Int16List _ringer() {
    final samples = Int16List(
      _samplesFor(_burstMs + _gapMs + _burstMs + _restMs),
    );

    // Well under full scale on purpose: a ring at full volume through a
    // headset somebody is wearing is its own kind of defect.
    const amplitude = 7000;
    final length = _samplesFor(_burstMs);

    void burst(int atMs) {
      final start = _samplesFor(atMs);

      // PHASE, not `sin(2π·f·i)`. The pitch changes every 50 ms, and computing
      // each sample from absolute time restarts the wave wherever it happens
      // to land — a step discontinuity, which is a click, eight times a
      // second. Advancing a running phase makes the switch inaudible.
      var phase = 0.0;

      for (var i = 0; i < length; i++) {
        final atWarble = (i * 1000) ~/ (_rate * _warbleMs);
        phase += 2 * math.pi * (atWarble.isEven ? _lowHz : _highHz) / _rate;

        // A 10 ms fade at each end of every burst, for the same reason: a
        // burst that begins mid-wave clicks, and here that would be twice
        // per ring rather than once per loop.
        final fade = math.min(
          1.0,
          math.min(i, length - 1 - i) / (_rate * 0.01),
        );

        samples[start + i] = (math.sin(phase) * amplitude * fade).round();
      }
    }

    burst(0);
    burst(_burstMs + _gapMs);

    return samples;
  }

  static Uint8List _wav(Int16List samples) {
    final bytes = samples.lengthInBytes;
    final header = ByteData(44);

    void ascii(int at, String s) {
      for (var i = 0; i < s.length; i++) {
        header.setUint8(at + i, s.codeUnitAt(i));
      }
    }

    ascii(0, 'RIFF');
    header.setUint32(4, 36 + bytes, Endian.little);
    ascii(8, 'WAVE');
    ascii(12, 'fmt ');
    header.setUint32(16, 16, Endian.little); // PCM chunk size
    header.setUint16(20, 1, Endian.little); // PCM
    header.setUint16(22, 1, Endian.little); // mono
    header.setUint32(24, _rate, Endian.little);
    header.setUint32(28, _rate * 2, Endian.little); // byte rate
    header.setUint16(32, 2, Endian.little); // block align
    header.setUint16(34, 16, Endian.little); // bits per sample
    ascii(36, 'data');
    header.setUint32(40, bytes, Endian.little);

    return Uint8List.fromList([
      ...header.buffer.asUint8List(),
      ...samples.buffer.asUint8List(),
    ]);
  }
}

/// Silence, for a test and for a build with no way to make a noise.
class SilentCallAlerts implements CallAlerts {
  /// Every call this was told about, so a test can assert the desk asked.
  final List<String> rang = [];
  int stopped = 0;

  @override
  void incoming(String from) => rang.add(from);

  @override
  void stop() => stopped++;

  @override
  void dispose() {}
}
