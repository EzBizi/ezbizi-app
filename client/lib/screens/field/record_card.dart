import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/field_recorder.dart';
import '../../core/field_tracker.dart';

/// Field › Dashboard: the safety recording control (UI queue 102, design 1:
/// a card under the SOS banner; no answer in 10 minutes, so the
/// recommendation).
///
/// Binds to [FieldTracker.current]'s recorder and `/field/me`, which exist
/// only on a phone, so on a desk this draws nothing. Leaving the job and the
/// four-hour limit stop the recorder by themselves; this only shows it.
class RecordCard extends StatefulWidget {
  const RecordCard({super.key, this.recorder, this.me});

  /// For tests; the app's own tracker's otherwise.
  final FieldRecorder? recorder;
  final ValueNotifier<Map<String, dynamic>?>? me;

  @override
  State<RecordCard> createState() => _RecordCardState();
}

class _RecordCardState extends State<RecordCard> {
  Timer? _tick;
  bool _asking = false;
  bool _busy = false;

  FieldRecorder? get _recorder =>
      widget.recorder ?? FieldTracker.current?.recorder;
  ValueNotifier<Map<String, dynamic>?>? get _me =>
      widget.me ?? FieldTracker.current?.me;

  @override
  void initState() {
    super.initState();
    // The elapsed time is drawn here; the recorder only knows when it began.
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && (_recorder?.active ?? false)) setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  static String _clock(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return h > 0 ? '$h:$m:$s' : '$m:$s';
  }

  Future<void> _run(Future<void> Function() act) async {
    setState(() => _busy = true);
    await act();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final recorder = _recorder;
    final meNotifier = _me;
    if (recorder == null || meNotifier == null) return const SizedBox.shrink();

    return ValueListenableBuilder<Map<String, dynamic>?>(
      valueListenable: meNotifier,
      builder: (context, me, _) => ValueListenableBuilder<FieldRecorderState>(
        valueListenable: recorder.state,
        builder: (context, state, _) {
          final job = me?['at_job'] as Map?;
          final idle = state.status == FieldRecorderStatus.idle;
          // Not offered without the grant; a recording already running is
          // always shown, so it can be stopped.
          if (idle && me?['may_record'] != true) return const SizedBox.shrink();
          return _card(context, recorder, state, job);
        },
      ),
    );
  }

  Widget _card(
    BuildContext context,
    FieldRecorder recorder,
    FieldRecorderState state,
    Map? job,
  ) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall;
    final idle = state.status == FieldRecorderStatus.idle;
    final paused = state.status == FieldRecorderStatus.paused;
    final started = state.startedAt;
    final elapsed = started == null
        ? Duration.zero
        : DateTime.now().difference(started);
    final number = job?['number'] ?? job?['id'];

    final String title;
    final String? line;
    if (idle) {
      title = number == null ? 'Safety recording' : 'At JOB-$number';
      line = number == null ? 'Start when you arrive at a job.' : null;
    } else {
      title = '${paused ? 'Paused' : 'Recording'} · ${_clock(elapsed)}';
      line = state.unsent > 0
          ? '${state.unsent} ${state.unsent == 1 ? 'minute' : 'minutes'} '
                'still to send'
          : (number == null ? null : 'JOB-$number');
    }

    final dot = Container(
      width: 12,
      height: 12,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: idle
            ? theme.colorScheme.outline
            : paused
            ? theme.colorScheme.tertiary
            : theme.colorScheme.error,
      ),
    );

    return Card(
      key: const ValueKey('tour-field-record'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 12, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // A state that requires it: the line to say first.
            if (idle && job?['notice'] == true)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Let the customer know this visit may be recorded for '
                  'safety and quality.',
                  key: const ValueKey('field-record-notice'),
                  style: small?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
            Row(
              children: [
                dot,
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: theme.textTheme.titleSmall),
                      if (line != null) Text(line, style: small),
                    ],
                  ),
                ),
                if (idle)
                  FilledButton.icon(
                    key: const ValueKey('field-record-start'),
                    style: FilledButton.styleFrom(
                      backgroundColor: theme.colorScheme.error,
                      foregroundColor: theme.colorScheme.onError,
                    ),
                    onPressed: _busy
                        ? null
                        : () => _run(
                            () => recorder.start(jobId: job?['id'] as String?),
                          ),
                    icon: const Icon(Icons.fiber_manual_record, size: 16),
                    label: const Text('Start'),
                  )
                else ...[
                  OutlinedButton(
                    key: const ValueKey('field-record-pause'),
                    onPressed: _busy
                        ? null
                        : () => _run(paused ? recorder.resume : recorder.pause),
                    child: Text(paused ? 'Resume' : 'Pause'),
                  ),
                  const SizedBox(width: 6),
                  OutlinedButton(
                    key: const ValueKey('field-record-stop'),
                    onPressed: _busy
                        ? null
                        : () => setState(() => _asking = !_asking),
                    child: const Text('Stop'),
                  ),
                ],
              ],
            ),
            if (state.error != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  state.error!,
                  style: small?.copyWith(color: theme.colorScheme.error),
                ),
              ),
            // Stop asks why, in place: not a dialog.
            if (!idle && _asking)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text('Why stop?', style: small),
                    for (final (reason, label) in const [
                      ('done', 'Done'),
                      ('customer_request', 'The customer asked'),
                      ('personal', 'Personal'),
                    ])
                      FilledButton.tonal(
                        key: ValueKey('field-record-stop-$reason'),
                        onPressed: _busy
                            ? null
                            : () {
                                setState(() => _asking = false);
                                _run(() => recorder.stop(reason));
                              },
                        child: Text(label),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
