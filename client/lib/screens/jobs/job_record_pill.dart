import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/field_recorder.dart';
import '../../core/field_tracker.dart';
import '../../widgets/side_panel.dart';
import '../field/recording_control.dart';

/// The job header's "● Record" (design 177, part B; Jose 2026-11-03, "as u
/// recommend"): starts the safety recording against this job, asking first in
/// a side panel with Field's card's rule -- the disclosure every time, and
/// "I told the customer" where `/field/me` says `recording.announce`.
/// While recording it is the screen's only red: "● 00:12 · Stop".
///
/// Binds to [FieldTracker.current], which exists only on a phone, so on a
/// desk this draws nothing.
class JobRecordPill extends RecordingControlWidget {
  const JobRecordPill({
    super.key,
    required this.jobId,
    super.recorder,
    super.me,
  });

  final String jobId;

  @override
  State<JobRecordPill> createState() => _JobRecordPillState();
}

class _JobRecordPillState extends State<JobRecordPill>
    with RecordingControl<JobRecordPill> {
  @override
  Widget build(BuildContext context) {
    final recorder = fieldRecorder;
    final meNotifier = fieldMe;
    if (recorder == null || meNotifier == null) return const SizedBox.shrink();

    return ValueListenableBuilder<Map<String, dynamic>?>(
      valueListenable: meNotifier,
      builder: (context, me, _) => ValueListenableBuilder<FieldRecorderState>(
        valueListenable: recorder.state,
        builder: (context, state, _) {
          final scheme = Theme.of(context).colorScheme;
          final rules = (me?['recording'] as Map?) ?? const {};

          if (state.status == FieldRecorderStatus.idle) {
            if (me?['may_record'] != true || rules['allowed'] != true) {
              return const SizedBox.shrink();
            }
            return OutlinedButton.icon(
              key: const ValueKey('job-record-start'),
              onPressed: busy ? null : () => _consent(context, recorder, rules),
              icon: Icon(
                Icons.fiber_manual_record,
                size: 14,
                color: scheme.error,
              ),
              label: const Text('Record'),
            );
          }

          // ponytail: a recording at another job is Field's card's to show.
          if (state.jobId != widget.jobId) return const SizedBox.shrink();

          final started = state.startedAt;
          final elapsed = started == null
              ? Duration.zero
              : DateTime.now().difference(started);
          return FilledButton.icon(
            key: const ValueKey('job-record-stop'),
            style: FilledButton.styleFrom(
              backgroundColor: scheme.error,
              foregroundColor: scheme.onError,
            ),
            onPressed: busy ? null : () => run(() => recorder.stop('done')),
            icon: const Icon(Icons.fiber_manual_record, size: 14),
            label: Text('${recordingClock(elapsed)} · Stop'),
          );
        },
      ),
    );
  }

  Future<void> _consent(
    BuildContext context,
    FieldRecorder recorder,
    Map rules,
  ) async {
    final announce = rules['announce'] == true;
    final told = await showSidePanel<bool>(
      context,
      builder: (context) =>
          _ConsentPanel(announce: announce, notice: '${rules['notice'] ?? ''}'),
    );
    if (told == null || !mounted) return;
    await run(
      () => recorder.start(jobId: widget.jobId, customerTold: announce && told),
    );
  }
}

/// Before every start: what is recorded and who hears it, and where it is
/// owed, the notice the customer is told. Pops whether they were told.
class _ConsentPanel extends StatefulWidget {
  const _ConsentPanel({required this.announce, required this.notice});

  final bool announce;
  final String notice;

  @override
  State<_ConsentPanel> createState() => _ConsentPanelState();
}

class _ConsentPanelState extends State<_ConsentPanel> {
  bool _told = false;

  @override
  Widget build(BuildContext context) {
    final small = Theme.of(context).textTheme.bodyMedium;
    return SidePanel(
      title: 'Start recording',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('job-record-confirm'),
          onPressed: widget.announce && !_told
              ? null
              : () => Navigator.of(context).pop(_told),
          child: const Text('Start'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            fieldRecordingDisclosure,
            key: const ValueKey('job-record-disclosure'),
            style: small,
          ),
          if (widget.announce) ...[
            const SizedBox(height: 12),
            Text(
              'Tell the customer: "${widget.notice}"',
              style: small?.copyWith(fontWeight: FontWeight.w700),
            ),
            CheckboxListTile(
              key: const ValueKey('job-record-told'),
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _told,
              onChanged: (v) => setState(() => _told = v ?? false),
              title: const Text('I told the customer'),
            ),
          ],
        ],
      ),
    );
  }
}
