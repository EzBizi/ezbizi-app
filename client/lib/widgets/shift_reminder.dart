import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/clock_window.dart';
import '../core/punch_position.dart';
import '../core/session.dart';
import '../core/ui_strings.dart';

/// Arms one timer from the end the server stated, and shows one dialog
/// (design 2026-09-08 §10.1).
///
/// The server closes the shift at `scheduled_end_at`; this only tells the
/// person it is coming. Shown ONCE per end value — extending changes the
/// value, so it comes back before the new end. That latch is what A1 Tools
/// had to add after its two-minute poll turned the countdown into a loop.
class ShiftReminderController {
  ShiftReminderController({
    required this.api,
    required this.session,
    required this.context,
  });

  final ApiClient api;
  final Session session;
  final BuildContext Function() context;

  Timer? _timer;
  Timer? _refetch;
  DateTime? _armedFor;
  DateTime? _shownFor;
  bool _showing = false;

  /// Call whenever the session's time_clock block may have changed.
  void sync() {
    final status = session.me?.timeClock;
    final end = status?.open == null ? null : status?.scheduledEndAt;

    if (end == null) {
      _timer?.cancel();
      _timer = null;
      _armedFor = null;
      return;
    }

    if (end == _armedFor) return;

    _timer?.cancel();
    _armedFor = end;

    final fireAt = end.subtract(Duration(minutes: status!.warningMinutes));
    final wait = fireAt.difference(DateTime.now().toUtc());

    _timer = Timer(wait.isNegative ? Duration.zero : wait, () => _show(end));
  }

  void dispose() {
    _timer?.cancel();
    _timer = null;
    _refetch?.cancel();
    _refetch = null;
  }

  Future<void> _show(DateTime end) async {
    if (_showing || _shownFor == end) return;
    final status = session.me?.timeClock;
    if (status == null || status.open == null || status.scheduledEndAt != end) {
      return;
    }

    _showing = true;
    _shownFor = end;

    // Not awaited: a platform call must never gate the dialog. In a widget
    // test with no plugin behind it the future never completes, and on a
    // real desktop the raise and the dialog land on the same frame anyway.
    unawaited(ClockWindow.raise());

    final ctx = context();
    if (!ctx.mounted) {
      _showing = false;
      return;
    }

    await showDialog<void>(
      context: ctx,
      barrierDismissible: false,
      builder: (_) => ShiftReminderDialog(
        endAt: end,
        extensionMinutes: status.extensionMinutes,
        endLabel: TimeOfDay.fromDateTime(end.toLocal()).format(ctx),
        onExtend: () => _post('/time-clock/extend'),
        onClockOut: () =>
            _post('/time-clock/clock-out', needsLocation: status.needsLocation),
        onExpired: _refetchSoon,
      ),
    );

    _showing = false;
  }

  Future<void> _post(String path, {bool needsLocation = false}) async {
    final response = await api.post(path, await punchPosition(needsLocation));
    _apply(response);
    sync();
  }

  void _apply(ApiResponse response) {
    if (!response.ok || response.data is! Map) return;
    final block = (response.data as Map)['time_clock'];
    if (block is Map) session.applyTimeClock(Map<String, dynamic>.from(block));
  }

  /// The server closes the shift on the same instant. Two seconds is the
  /// scheduler's slack plus the wire; the 60-second poll is the safety net.
  void _refetchSoon() {
    _refetch?.cancel();
    _refetch = Timer(const Duration(seconds: 2), () async {
      _apply(await api.get('/time-clock/me'));
      sync();
    });
  }
}

class ShiftReminderDialog extends StatefulWidget {
  const ShiftReminderDialog({
    super.key,
    required this.endAt,
    required this.extensionMinutes,
    required this.endLabel,
    required this.onExtend,
    required this.onClockOut,
    required this.onExpired,
  });

  final DateTime endAt;
  final int extensionMinutes;
  final String endLabel;
  final Future<void> Function() onExtend;
  final Future<void> Function() onClockOut;
  final VoidCallback onExpired;

  @override
  State<ShiftReminderDialog> createState() => _ShiftReminderDialogState();
}

class _ShiftReminderDialogState extends State<ShiftReminderDialog> {
  Timer? _tick;
  bool _busy = false;

  Duration get _left {
    final d = widget.endAt.difference(DateTime.now().toUtc());
    return d.isNegative ? Duration.zero : d;
  }

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_left == Duration.zero) {
        _tick?.cancel();
        Navigator.of(context).pop();
        widget.onExpired();
        return;
      }
      setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    _tick?.cancel();
    await action();
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final left = _left;
    final mm = left.inMinutes;
    final ss = (left.inSeconds % 60).toString().padLeft(2, '0');

    return AlertDialog(
      title: Text(UiStrings.t('time_clock.reminder_title')),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            UiStrings.t(
              'time_clock.reminder_body',
            ).replaceFirst('{time}', widget.endLabel),
          ),
          const SizedBox(height: 12),
          Text('$mm:$ss', style: Theme.of(context).textTheme.headlineMedium),
        ],
      ),
      actions: [
        // Closes the dialog without acting; the server still closes the
        // shift at the end. A barrier dialog must still have a way out.
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Not now'),
        ),
        OutlinedButton(
          onPressed: _busy ? null : () => _run(widget.onExtend),
          child: Text(
            UiStrings.t(
              'time_clock.extend',
            ).replaceFirst('{minutes}', '${widget.extensionMinutes}'),
          ),
        ),
        FilledButton(
          onPressed: _busy ? null : () => _run(widget.onClockOut),
          child: Text(UiStrings.t('time_clock.clock_out')),
        ),
      ],
    );
  }
}
