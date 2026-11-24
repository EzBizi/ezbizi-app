import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/timestamps.dart';
import '../widgets/side_panel.dart';

/// Which of Monday's My Work buckets a due day falls in, seen from [today].
String myWorkBucket(String? due, DateTime today) {
  final day = due == null ? null : DateTime.tryParse(due);
  if (day == null) return 'No date';
  final d = DateTime(day.year, day.month, day.day);
  final t = DateTime(today.year, today.month, today.day);
  if (d.isBefore(t)) return 'Past dates';
  if (d == t) return 'Today';
  // Weeks start on Monday, as Monday's do.
  final weekEnd = t.add(Duration(days: 7 - t.weekday));
  if (!d.isAfter(weekEnd)) return 'This week';
  if (!d.isAfter(weekEnd.add(const Duration(days: 7)))) return 'Next week';
  return 'Later';
}

const myWorkBuckets = [
  'Past dates',
  'Today',
  'This week',
  'Next week',
  'Later',
  'No date',
];

/// Monday's My Work (gap review P1, 11-24): the open tasks I own on every
/// board, in Monday's buckets (table) or by day (calendar). A row opens its
/// task through [onOpenTask].
class MyWorkPanel extends StatefulWidget {
  const MyWorkPanel({
    super.key,
    required this.api,
    required this.formats,
    this.onOpenTask,
    this.today,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final void Function(String taskId)? onOpenTask;

  /// Tests pin the day.
  final DateTime? today;

  @override
  State<MyWorkPanel> createState() => _MyWorkPanelState();
}

class _MyWorkPanelState extends State<MyWorkPanel> {
  List<Map<String, dynamic>>? _tasks;
  String? _error;
  bool _calendar = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _tasks = null);
    final res = await widget.api.get('/my-work', {if (_done) 'done': '1'});
    if (!mounted) return;
    setState(() {
      _error = res.ok ? null : res.reason;
      _tasks = res.ok
          ? [
              for (final t in (res.body['data'] as List).whereType<Map>())
                t.cast<String, dynamic>(),
            ]
          : const [];
    });
  }

  Widget _row(BuildContext context, Map<String, dynamic> t, TextStyle? muted) =>
      ListTile(
        key: ValueKey('my-work-${t['id']}'),
        dense: true,
        contentPadding: EdgeInsets.zero,
        title: Text('${t['title']}'),
        subtitle: Text(
          [
            '${t['board_name'] ?? ''}',
            if (t['due_on'] != null)
              formats(DateTime.tryParse('${t['due_on']}')),
          ].where((s) => s.isNotEmpty).join(' · '),
          style: muted,
        ),
        onTap: widget.onOpenTask == null
            ? null
            : () => widget.onOpenTask!('${t['id']}'),
      );

  String formats(DateTime? d) => d == null ? '' : widget.formats.formatDate(d);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final today = widget.today ?? DateTime.now();
    final tasks = _tasks ?? const <Map<String, dynamic>>[];
    final heading = theme.textTheme.titleSmall?.copyWith(
      fontWeight: FontWeight.w700,
    );

    final List<Widget> body;
    if (_calendar) {
      final days = <String, List<Map<String, dynamic>>>{};
      for (final t in tasks) {
        days.putIfAbsent('${t['due_on'] ?? ''}', () => []).add(t);
      }
      final keys = days.keys.toList()
        ..sort((a, b) => a.isEmpty ? 1 : (b.isEmpty ? -1 : a.compareTo(b)));
      body = [
        for (final k in keys) ...[
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              k.isEmpty ? 'No date' : formats(DateTime.tryParse(k)),
              key: ValueKey('my-work-day-$k'),
              style: heading,
            ),
          ),
          for (final t in days[k]!) _row(context, t, muted),
        ],
      ];
    } else {
      body = [
        for (final b in myWorkBuckets)
          if (tasks.any(
            (t) => myWorkBucket(t['due_on'] as String?, today) == b,
          )) ...[
            Padding(
              padding: const EdgeInsets.only(top: 10, bottom: 4),
              child: Text(
                '$b · ${tasks.where((t) => myWorkBucket(t['due_on'] as String?, today) == b).length}',
                key: ValueKey('my-work-bucket-$b'),
                style: heading?.copyWith(
                  color: b == 'Past dates' ? theme.colorScheme.error : null,
                ),
              ),
            ),
            for (final t in tasks)
              if (myWorkBucket(t['due_on'] as String?, today) == b)
                _row(context, t, muted),
          ],
      ];
    }

    return SidePanel(
      title: 'My Work',
      subtitle: 'What you own on every board.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            children: [
              ChoiceChip(
                key: const Key('my-work-table'),
                label: const Text('Table'),
                selected: !_calendar,
                onSelected: (_) => setState(() => _calendar = false),
              ),
              ChoiceChip(
                key: const Key('my-work-calendar'),
                label: const Text('Calendar'),
                selected: _calendar,
                onSelected: (_) => setState(() => _calendar = true),
              ),
              FilterChip(
                key: const Key('my-work-done'),
                label: const Text('Show done'),
                selected: _done,
                onSelected: (v) {
                  setState(() => _done = v);
                  _load();
                },
              ),
            ],
          ),
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          if (_tasks == null)
            const LinearProgressIndicator()
          else if (tasks.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text('Nothing assigned to you.', style: muted),
            )
          else
            ...body,
        ],
      ),
    );
  }
}
