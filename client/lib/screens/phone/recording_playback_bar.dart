import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/files.dart';
import '../../core/timestamps.dart';
import 'recording_player.dart';

/// Play, pause, scrub, and how far through you are.
///
/// ── Why one widget rather than a button per screen ───────────────
///
/// Voicemail, Records and a lead's thread all play the same kind of audio, and
/// each had drawn its own control: three Play buttons, three ways of reporting
/// a failure, and — on one of them — a label saying the sound was about to
/// come out of a different application. Behaviour that differs per screen for
/// no reason is behaviour somebody has to learn three times.
///
/// ── Why a scrubber and not just Play ─────────────────────────────
///
/// The job is listening to a voicemail, and the thing people actually do is
/// go back four seconds because somebody said a number too fast. A Play button
/// alone means starting the whole message again, so the control that looks
/// simpler is the one that makes the task harder.
///
/// ── Fetching is the caller's, playing is ours ────────────────────
///
/// [load] is called the first time somebody presses play and its result is
/// kept, so pressing play again after it has finished does not re-download.
/// Each screen fetches from its own endpoint with its own credentials; none of
/// them should have to know how to work a transport.
class RecordingPlaybackBar extends StatefulWidget {
  const RecordingPlaybackBar({
    super.key,
    required this.player,
    required this.load,
    this.dense = false,
    this.autoplay = false,
    this.knownLength,
    this.downloadName,
  });

  final RecordingPlayer player;

  /// Fetches the audio. Null means it could not be fetched, and the bar says
  /// so rather than sitting there looking ready.
  final Future<List<int>?> Function() load;

  /// A tighter version for a message row, where the bar sits inside a card
  /// that already has its own padding.
  final bool dense;

  /// Starts playing as soon as the bar is drawn, for a list that opens the
  /// bar from its own play button (Reports' calls, UI queue 71).
  final bool autoplay;

  /// The recording's length when the caller already knows it - a call's own
  /// duration - so the time reads "0:00 / 3:14" before anything is fetched
  /// instead of "--:--".
  final Duration? knownLength;

  /// With a name ("2026-10-19 1432 +12125551212"), a Download button saves
  /// the recording as that file (Jose, 2026-10-19). A screen passes one only
  /// where it lets the person play the recording.
  final String? downloadName;

  /// A file name for a call's recording: its time and the caller's number.
  static String nameFor(Object? startedAt, Object? number) {
    final t = DateTime.tryParse('$startedAt')?.toLocal();
    String two(int v) => v.toString().padLeft(2, '0');
    final when = t == null
        ? 'recording'
        : '${t.year}-${two(t.month)}-${two(t.day)} ${two(t.hour)}${two(t.minute)}';
    final digits = '${number ?? ''}'.replaceAll(RegExp(r'[^0-9+]'), '');
    return digits.isEmpty ? when : '$when $digits';
  }

  @override
  State<RecordingPlaybackBar> createState() => _RecordingPlaybackBarState();
}

class _RecordingPlaybackBarState extends State<RecordingPlaybackBar> {
  List<int>? _bytes;
  bool _busy = false;
  String? _error;

  /// Where the thumb is while a finger is on it.
  ///
  /// Held separately from the player's position because the two disagree on
  /// purpose during a drag: the sound is still at the old spot until the
  /// finger lifts, and a slider that snapped back to it on every position tick
  /// would be impossible to aim.
  double? _dragging;

  RecordingPlayback get _state => widget.player.state;

  /// True when this bar is the one making the sound.
  ///
  /// The player is shared by a whole screen, so "playing" alone would light up
  /// every row in the list the moment one of them started.
  bool get _isMine => _bytes != null && identical(_owner, this);

  static Object? _owner;

  /// Retrying while Windows has nowhere to play to - see [_waitForOutput].
  Timer? _waiting;

  @override
  void initState() {
    super.initState();
    if (widget.autoplay) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _press();
      });
    }
  }

  @override
  void dispose() {
    _waiting?.cancel();
    if (identical(_owner, this)) _owner = null;
    super.dispose();
  }

  Future<void> _press() async {
    if (_busy) return;

    // Pressed again while waiting for speakers: stop waiting.
    if (_waiting != null) {
      _waiting!.cancel();
      _waiting = null;
      setState(() => _error = null);

      return;
    }

    if (_isMine && _state.playing) {
      await widget.player.pause();
      if (mounted) setState(() {});

      return;
    }

    if (_isMine && !_state.playing && _state.position > Duration.zero) {
      await widget.player.resume();
      if (mounted) setState(() {});

      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final bytes = _bytes ?? await widget.load();

      if (bytes == null) {
        throw const RecordingPlaybackFailure(
          'That recording could not be fetched.',
        );
      }

      _bytes = bytes;
      _owner = this;
      await widget.player.play(bytes);
    } catch (error) {
      if (isNoAudioOutput(error)) {
        _waitForOutput();
      } else {
        _error = error is RecordingPlaybackFailure
            ? error.message
            : 'This recording would not play. $error';
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Windows cannot play at all with no speakers or headphones - other apps
  /// sit ready and start when some appear, so this does the same: it tries
  /// again every two seconds and plays the moment a device is there (Jose,
  /// 2026-09-28: "it should still play like any other app").
  void _waitForOutput() {
    _error =
        'Waiting for speakers or headphones. It will play as soon as '
        'some are connected.';
    var trying = false;
    _waiting = Timer.periodic(const Duration(seconds: 2), (_) async {
      if (trying || _bytes == null) return;
      trying = true;
      try {
        _owner = this;
        await widget.player.play(_bytes!);
        _waiting?.cancel();
        _waiting = null;
        if (mounted) setState(() => _error = null);
      } catch (_) {
        // Still nowhere to play to; the next tick tries again.
      } finally {
        trying = false;
      }
    });
  }

  Future<void> _seek(double seconds) async {
    await widget.player.seek(Duration(milliseconds: (seconds * 1000).round()));
    if (mounted) setState(() => _dragging = null);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return StreamBuilder<RecordingPlayback>(
      stream: widget.player.changes,
      builder: (context, _) {
        final mine = _isMine;
        final playing = mine && _state.playing;
        final total = mine ? _state.duration : Duration.zero;
        final at = mine ? _state.position : Duration.zero;
        final scrubbable = mine && total > Duration.zero;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            LayoutBuilder(
              builder: (context, box) => Row(
                children: [
                  IconButton(
                    key: const Key('recording-play'),
                    visualDensity: widget.dense ? VisualDensity.compact : null,
                    tooltip: playing ? 'Pause' : 'Play',
                    onPressed: _busy ? null : _press,
                    icon: _busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Icon(playing ? Icons.pause : Icons.play_arrow),
                  ),

                  Expanded(
                    child: SliderTheme(
                      data: SliderTheme.of(context).copyWith(
                        trackHeight: 3,
                        thumbShape: const RoundSliderThumbShape(
                          enabledThumbRadius: 6,
                        ),
                        overlayShape: const RoundSliderOverlayShape(
                          overlayRadius: 14,
                        ),
                      ),
                      child: Slider(
                        key: const Key('recording-scrub'),
                        // Disabled rather than hidden. A transport that appears
                        // only once audio is loaded moves the button under the
                        // cursor at the moment somebody is aiming at it.
                        value: scrubbable
                            ? (_dragging ??
                                  at.inMilliseconds
                                          .clamp(0, total.inMilliseconds)
                                          .toDouble() /
                                      1000)
                            : 0,
                        max: scrubbable
                            ? total.inMilliseconds.toDouble() / 1000
                            : 1,
                        onChanged: scrubbable
                            ? (value) => setState(() => _dragging = value)
                            : null,
                        onChangeEnd: scrubbable ? _seek : null,
                      ),
                    ),
                  ),

                  Text(
                    scrubbable
                        ? '${formatClock(_dragging == null ? at : Duration(seconds: _dragging!.round()))} / ${formatClock(total)}'
                        : switch (widget.knownLength) {
                            final length? when length > Duration.zero =>
                              '0:00 / ${formatClock(length)}',
                            _ => '--:--',
                          },
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                      // m:ss, and mm:ss past ten minutes. Tabular figures stop
                      // the whole row twitching as the seconds change width.
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                  const SizedBox(width: 6),
                  // Faster, to get through a call (Jose, 2026-10-09). Three
                  // where they fit; on a narrow bar one that steps 1, 1.5, 2.
                  if (box.maxWidth >= 360)
                    for (final rate in const [1.0, 1.5, 2.0])
                      _speedButton(context, rate)
                  else
                    _speedButton(
                      context,
                      widget.player.speed,
                      next: switch (widget.player.speed) {
                        1.0 => 1.5,
                        1.5 => 2.0,
                        _ => 1.0,
                      },
                    ),
                  if (widget.downloadName != null)
                    IconButton(
                      key: const Key('recording-download'),
                      visualDensity: widget.dense
                          ? VisualDensity.compact
                          : null,
                      tooltip: 'Download the recording',
                      onPressed: _busy ? null : _download,
                      icon: const Icon(Icons.download_outlined, size: 20),
                    ),
                ],
              ),
            ),

            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(left: 12, bottom: 4),
                child: Text(
                  _error!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  /// The recording as a file, fetched once like play does.
  Future<void> _download() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final bytes = _bytes ?? await widget.load();
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (bytes == null) {
        _error = 'The recording could not be fetched.';
      } else {
        _bytes = bytes;
      }
    });
    if (bytes != null) {
      Files.download('${widget.downloadName}${_extension(bytes)}', bytes);
    }
  }

  /// What the bytes are, from their first bytes: WAV, or else MP3.
  static String _extension(List<int> bytes) =>
      bytes.length > 3 &&
          bytes[0] == 0x52 &&
          bytes[1] == 0x49 &&
          bytes[2] == 0x46 &&
          bytes[3] == 0x46
      ? '.wav'
      : '.mp3';

  /// One speed. With [next], the single stepping button of a narrow bar:
  /// it shows the speed playing and moves to [next].
  Widget _speedButton(BuildContext context, double rate, {double? next}) {
    final scheme = Theme.of(context).colorScheme;
    final on = next == null ? widget.player.speed == rate : rate != 1;
    final label = rate == rate.roundToDouble() ? '${rate.round()}×' : '$rate×';

    return Tooltip(
      message: next == null
          ? 'Play at $label speed'
          : 'Playing at $label - tap for faster',
      child: InkWell(
        key: ValueKey(
          next == null ? 'recording-speed-$rate' : 'recording-speed-step',
        ),
        borderRadius: BorderRadius.circular(6),
        onTap: () => widget.player.setSpeed(next ?? rate),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 3),
          decoration: BoxDecoration(
            color: on ? scheme.primaryContainer : null,
            borderRadius: BorderRadius.circular(6),
          ),
          child: Text(
            label,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              fontWeight: on ? FontWeight.w800 : FontWeight.w600,
              color: on ? scheme.onPrimaryContainer : scheme.onSurfaceVariant,
            ),
          ),
        ),
      ),
    );
  }
}

/// Windows' answer when it has no speakers or headphones to play to:
/// "Activate failed to create mediasink" (measured on Jose's PC, 2026-09-28).
bool isNoAudioOutput(Object error) => '$error'.contains('create mediasink');
