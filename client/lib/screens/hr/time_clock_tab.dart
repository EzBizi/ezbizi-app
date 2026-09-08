import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/ui_strings.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';

/// One day of clock-in shifts for people in reach (design §9).
///
/// The date comes from the payload, never the device clock. Rows are not
/// tappable; the server's order is the order on screen.
class TimeClockTab extends StatefulWidget {
  const TimeClockTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<TimeClockTab> createState() => _TimeClockTabState();
}

class _TimeClockTabState extends State<TimeClockTab> {
  bool _loading = true;
  String? _error;
  int _request = 0;

  String? _date;
  String? _on;
  String? _timezoneLabel;
  List<Map<String, dynamic>> _shifts = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({String? on}) async {
    final request = ++_request;
    _on = on;

    setState(() {
      _loading = _date == null;
      _error = null;
    });

    final response = on == null
        ? await widget.api.get('/hr/time-clock')
        : await widget.api.get('/hr/time-clock', {'on': on});

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }

      final data = response.data;
      if (data is! Map) {
        _error = response.message;
        return;
      }

      final page = Map<String, dynamic>.from(data);
      _date = page['date'] as String?;
      _timezoneLabel = page['timezone_label'] as String?;
      final raw = page['shifts'];
      _shifts = [
        for (final row in raw is List ? raw : const [])
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  void _move(int days) {
    final date = _date;
    if (date == null) return;
    _load(on: _shiftDate(date, days));
  }

  @override
  Widget build(BuildContext context) {
    final zone = _timezoneLabel;
    final caption = zone == null
        ? ''
        : UiStrings.t('time_clock.times_in').replaceFirst('{zone}', zone);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(note: caption),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            IconButton(
              tooltip: 'Previous day',
              onPressed: _date == null ? null : () => _move(-1),
              icon: const Icon(Icons.chevron_left),
            ),
            TextButton(
              onPressed: _date == null ? null : () => _load(on: _date),
              child: Text(_date ?? ''),
            ),
            IconButton(
              tooltip: 'Next day',
              onPressed: _date == null ? null : () => _move(1),
              icon: const Icon(Icons.chevron_right),
            ),
          ],
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: () => _load(on: _on),
            child: _shifts.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text(
                        UiStrings.t('time_clock.empty'),
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _shifts.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _shifts[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> shift) {
    final user = shift['user'];
    final name = user is Map ? (user['name'] as String? ?? '') : '';
    final clockedIn = shift['clocked_in'] as String? ?? '';
    final clockedOut = shift['clocked_out'] as String?;
    final out = (clockedOut == null || clockedOut.isEmpty)
        ? UiStrings.t('time_clock.in')
        : clockedOut;
    final seconds = shift['duration_seconds'];
    final endedBy = shift['ended_by'];
    final outside = shift['outside_schedule'] == true;
    final extensions = shift['extensions'];

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
        // Who closed it, and whether it was on schedule (design 2026-09-08
        // §10.4). Words, not colour: the tags read on their own.
        title: Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(name, style: Theme.of(context).textTheme.titleMedium),
            if (endedBy == 'schedule') Tag(UiStrings.t('time_clock.auto')),
            if (outside) Tag(UiStrings.t('time_clock.outside'), warn: true),
            if (extensions is int && extensions > 0) Tag('+$extensions'),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Row(
            children: [
              Text(clockedIn),
              const SizedBox(width: 16),
              Text(out),
              const SizedBox(width: 16),
              Text(_duration(seconds is int ? seconds : null)),
            ],
          ),
        ),
      ),
    );
  }
}

/// Civil YYYY-MM-DD arithmetic, no timezone.
String _shiftDate(String ymd, int days) {
  final parts = ymd.split('-');
  final next = DateTime.utc(
    int.parse(parts[0]),
    int.parse(parts[1]),
    int.parse(parts[2]) + days,
  );
  return '${next.year.toString().padLeft(4, '0')}-'
      '${next.month.toString().padLeft(2, '0')}-'
      '${next.day.toString().padLeft(2, '0')}';
}

/// `H:MM`, or an em dash while the shift is still open.
String _duration(int? seconds) {
  if (seconds == null) return '\u2014';
  final hours = seconds ~/ 3600;
  final minutes = (seconds % 3600) ~/ 60;
  return '$hours:${minutes.toString().padLeft(2, '0')}';
}
