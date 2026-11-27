/// "Remind me" in the task panel (Shoval's list for switching Monday off,
/// 2026-11-20; server e11a0bd1). A reminder is the reader's own: when it is
/// due their bell rings once, "Reminder: " and the title. Pending ones are listed
/// with a remove; one already rung is not shown.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/timestamps.dart';
import '../widgets/calendar_day_field.dart';

class TaskReminders extends StatefulWidget {
  const TaskReminders({
    super.key,
    required this.api,
    required this.taskId,
    required this.formats,
  });

  final ApiClient api;
  final String taskId;
  final DisplayFormats formats;

  @override
  State<TaskReminders> createState() => _TaskRemindersState();
}

class _TaskRemindersState extends State<TaskReminders> {
  List<Map<String, dynamic>> _pending = const [];
  bool _adding = false;
  bool _busy = false;
  String? _error;
  final _when = TextEditingController();
  final _note = TextEditingController();

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _when.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/tasks/${widget.taskId}/reminders');
    if (!mounted || !r.ok) return;
    setState(
      () => _pending = [
        for (final m in (r.data as List? ?? const []))
          if (m is Map && m['sent_at'] == null) m.cast<String, dynamic>(),
      ],
    );
  }

  Future<void> _add() async {
    final at = parseWallDateTime(_when.text);
    if (at == null) {
      setState(() => _error = 'Choose a day and a time.');
      return;
    }
    final local = DateTime(
      at.day.year,
      at.day.month,
      at.day.day,
      at.hour,
      at.minute,
    );
    setState(() => _busy = true);
    final r = await widget.api.post('/tasks/${widget.taskId}/reminders', {
      'remind_at': local.toUtc().toIso8601String(),
      if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _busy = false;
        _error = r.reason;
      });
      return;
    }
    setState(() {
      _busy = false;
      _adding = false;
      _error = null;
      _when.clear();
      _note.clear();
    });
    await _load();
  }

  Future<void> _remove(Object? id) async {
    final r = await widget.api.delete('/tasks/${widget.taskId}/reminders/$id');
    if (!mounted) return;
    if (!r.ok) setState(() => _error = r.reason);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      key: const ValueKey('task-reminders'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final r in _pending)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Row(
              key: ValueKey('task-reminder-${r['id']}'),
              children: [
                Icon(
                  Icons.alarm,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    [
                      formatStamp(r['remind_at'], '—', formats: widget.formats),
                      if ('${r['note'] ?? ''}'.isNotEmpty) '${r['note']}',
                    ].join(' · '),
                  ),
                ),
                IconButton(
                  key: ValueKey('task-reminder-remove-${r['id']}'),
                  tooltip: 'Remove this reminder',
                  visualDensity: VisualDensity.compact,
                  onPressed: () => _remove(r['id']),
                  icon: const Icon(Icons.close, size: 18),
                ),
              ],
            ),
          ),
        if (_error != null)
          Text(
            _error!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        if (!_adding)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('task-remind-me'),
              onPressed: () => setState(() => _adding = true),
              icon: const Icon(Icons.alarm_add_outlined, size: 18),
              label: const Text('Remind me'),
            ),
          )
        else ...[
          CalendarDayField(
            key: const ValueKey('task-remind-when'),
            controller: _when,
            formats: widget.formats,
            label: 'When',
            withTime: true,
            firstDate: DateTime.now(),
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('task-remind-note'),
            controller: _note,
            maxLength: 200,
            decoration: const InputDecoration(
              labelText: 'Note (optional)',
              counterText: '',
            ),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              TextButton(
                onPressed: () => setState(() {
                  _adding = false;
                  _error = null;
                }),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const ValueKey('task-remind-save'),
                onPressed: _busy ? null : _add,
                child: const Text('Set reminder'),
              ),
            ],
          ),
          Text(
            'Your bell rings once, at that time. Only you see it.',
            style: muted,
          ),
        ],
      ],
    );
  }
}
