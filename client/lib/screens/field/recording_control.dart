import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/field_recorder.dart';
import '../../core/field_tracker.dart';

/// A control bound to the phone's safety recorder: Field's `RecordCard` and
/// the job header's `JobRecordPill`.
abstract class RecordingControlWidget extends StatefulWidget {
  const RecordingControlWidget({super.key, this.recorder, this.me});

  /// For tests; the app's own tracker's otherwise.
  final FieldRecorder? recorder;
  final ValueNotifier<Map<String, dynamic>?>? me;
}

/// What the two share: the recorder and `/field/me` they bind to (null on a
/// desk), a once-a-second redraw while recording, and [busy] while an action
/// is in flight.
mixin RecordingControl<W extends RecordingControlWidget> on State<W> {
  Timer? _tick;
  bool busy = false;

  FieldRecorder? get fieldRecorder =>
      widget.recorder ?? FieldTracker.current?.recorder;
  ValueNotifier<Map<String, dynamic>?>? get fieldMe =>
      widget.me ?? FieldTracker.current?.me;

  @override
  void initState() {
    super.initState();
    // The elapsed time is drawn here; the recorder only knows when it began.
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && (fieldRecorder?.active ?? false)) setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> run(Future<void> Function() act) async {
    setState(() => busy = true);
    await act();
    if (mounted) setState(() => busy = false);
  }
}

/// Elapsed recording time: `04:07`, or `1:04:07` past an hour.
String recordingClock(Duration d) {
  final h = d.inHours;
  final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
  final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
  return h > 0 ? '$h:$m:$s' : '$m:$s';
}
