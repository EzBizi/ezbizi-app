import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

/// Declares, edits, or clears a task's recurrence (task-5 brief's writer,
/// task-13 brief's UI) — `TaskApi.setRecurrence`, the same versioned write
/// path `TaskDependencyPanel` and `TaskCollaborationPanel` already use for
/// their own single-purpose mutations.
///
/// A PANEL, never a dialog — the editor opens inline in place, the same
/// shape `TaskDependencyPanel`'s "Add dependency" section uses. No
/// `showDialog`/`AlertDialog` anywhere in this file.
///
/// Both modes are offered in plain wording, never the wire spelling:
/// "when it is completed" (`after_completion`) or "on a fixed schedule"
/// (`on_schedule`).
///
/// The server REFUSES recurrence on a subtask (`Refusal::recurrenceOnSub-
/// task`, task-6 brief). This panel makes no attempt to guess that ahead of
/// time from `parentTaskId` and disable itself over it — every task gets
/// the same controls and the same write attempt; a subtask simply gets the
/// server's own refusal sentence back, shown exactly as sent. House rule 4:
/// the client shows the SERVER's refusal, never a second, invented copy of
/// the reason.
class TaskRecurrencePanel extends StatefulWidget {
  const TaskRecurrencePanel({
    super.key,
    required this.api,
    required this.snapshot,
    required this.onChanged,
  });

  final TaskApi api;
  final TaskDetailSnapshot snapshot;
  final ValueChanged<TaskDetailSnapshot> onChanged;

  @override
  State<TaskRecurrencePanel> createState() => _TaskRecurrencePanelState();
}

enum _RecurrenceEnd { never, until, count }

class _TaskRecurrencePanelState extends State<TaskRecurrencePanel> {
  bool _editing = false;
  bool _busy = false;
  String? _notice;

  TaskRecurrenceMode _mode = TaskRecurrenceMode.afterCompletion;
  final _every = TextEditingController(text: '1');
  TaskRecurrenceUnit _unit = TaskRecurrenceUnit.day;
  _RecurrenceEnd _end = _RecurrenceEnd.never;
  final _until = TextEditingController();
  final _count = TextEditingController();

  @override
  void didUpdateWidget(covariant TaskRecurrencePanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.snapshot != widget.snapshot) {
      _editing = false;
      _notice = null;
    }
  }

  @override
  void dispose() {
    _every.dispose();
    _until.dispose();
    _count.dispose();
    super.dispose();
  }

  void _startEditing() {
    if (_busy) return;
    final current = widget.snapshot.recurrence;
    setState(() {
      _editing = true;
      _notice = null;
      _mode = current?.mode ?? TaskRecurrenceMode.afterCompletion;
      _every.text = '${current?.every ?? 1}';
      _unit = current?.unit ?? TaskRecurrenceUnit.day;
      _until.text = current?.until == null ? '' : _isoDate(current!.until!);
      _count.text = current?.count == null ? '' : '${current!.count}';
      _end = current?.until != null
          ? _RecurrenceEnd.until
          : current?.count != null
          ? _RecurrenceEnd.count
          : _RecurrenceEnd.never;
    });
  }

  void _cancelEditing() {
    if (_busy) return;
    setState(() {
      _editing = false;
      _notice = null;
    });
  }

  Future<void> _save() async {
    if (_busy) return;
    final every = int.tryParse(_every.text.trim());
    if (every == null || every < 1) {
      setState(() => _notice = 'Every must be a whole number of 1 or more.');
      return;
    }
    DateTime? until;
    if (_end == _RecurrenceEnd.until) {
      until = _parseIsoDate(_until.text.trim());
      if (until == null) {
        setState(() => _notice = 'Enter the end date as YYYY-MM-DD.');
        return;
      }
    }
    int? count;
    if (_end == _RecurrenceEnd.count) {
      count = int.tryParse(_count.text.trim());
      if (count == null || count < 1) {
        setState(() => _notice = 'The count must be a whole number of 1 or more.');
        return;
      }
    }

    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.setRecurrence(
      widget.snapshot.task.id,
      expectedTaskVersion: widget.snapshot.task.version,
      recurrence: TaskRecurrence(
        mode: _mode,
        every: every,
        unit: _unit,
        until: until,
        count: count,
      ),
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskRow>) {
      await _refresh();
      if (mounted) setState(() => _editing = false);
    } else {
      setState(() => _notice = result.message ?? 'Recurrence could not be saved.');
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _clear() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.setRecurrence(
      widget.snapshot.task.id,
      expectedTaskVersion: widget.snapshot.task.version,
      recurrence: null,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskRow>) {
      await _refresh();
      if (mounted) setState(() => _editing = false);
    } else {
      setState(() => _notice = result.message ?? 'Recurrence could not be cleared.');
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _refresh() async {
    final result = await widget.api.taskDetail(
      widget.snapshot.task.boardId,
      widget.snapshot.task.id,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskDetailSnapshot>) {
      widget.onChanged(result.data);
    } else {
      setState(() => _notice = result.message ?? 'Recurrence could not be refreshed.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final recurrence = widget.snapshot.recurrence;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text('Recurrence', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            if (!_editing)
              TextButton.icon(
                onPressed: _busy ? null : _startEditing,
                icon: const Icon(Icons.repeat),
                label: Text(recurrence == null ? 'Add recurrence' : 'Edit'),
              ),
          ],
        ),
        if (!_editing)
          Text(key: const ValueKey('recurrence-summary'), _summary(recurrence))
        else
          _editor(context, recurrence),
        if (_notice case final notice?) ...[
          const SizedBox(height: 8),
          Text(notice, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        ],
      ],
    );
  }

  String _summary(TaskRecurrence? recurrence) {
    if (recurrence == null) return 'This task does not repeat.';
    final when = _modeLabel(recurrence.mode);
    final every = recurrence.every == 1
        ? _unitLabel(recurrence.unit, 1)
        : '${recurrence.every} ${_unitLabel(recurrence.unit, recurrence.every)}';
    final end = recurrence.until != null
        ? ', until ${_isoDate(recurrence.until!)}'
        : recurrence.count != null
        ? ', for ${recurrence.count} time${recurrence.count == 1 ? '' : 's'}'
        : '';
    return 'Repeats every $every, $when$end.';
  }

  Widget _editor(BuildContext context, TaskRecurrence? current) => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              key: const ValueKey('recurrence-mode-after-completion'),
              label: const Text('when it is completed'),
              selected: _mode == TaskRecurrenceMode.afterCompletion,
              onSelected: _busy
                  ? null
                  : (_) => setState(() => _mode = TaskRecurrenceMode.afterCompletion),
            ),
            ChoiceChip(
              key: const ValueKey('recurrence-mode-on-schedule'),
              label: const Text('on a fixed schedule'),
              selected: _mode == TaskRecurrenceMode.onSchedule,
              onSelected: _busy
                  ? null
                  : (_) => setState(() => _mode = TaskRecurrenceMode.onSchedule),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            SizedBox(
              width: 72,
              child: TextField(
                key: const ValueKey('recurrence-every-field'),
                controller: _every,
                enabled: !_busy,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Every'),
              ),
            ),
            const SizedBox(width: 12),
            DropdownButton<TaskRecurrenceUnit>(
              key: const ValueKey('recurrence-unit-picker'),
              value: _unit,
              underline: const SizedBox.shrink(),
              items: [
                for (final unit in TaskRecurrenceUnit.values)
                  DropdownMenuItem(value: unit, child: Text(_unitLabel(unit, 2))),
              ],
              onChanged: _busy
                  ? null
                  : (value) {
                      if (value != null) setState(() => _unit = value);
                    },
            ),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              key: const ValueKey('recurrence-end-never'),
              label: const Text('No end'),
              selected: _end == _RecurrenceEnd.never,
              onSelected: _busy
                  ? null
                  : (_) => setState(() => _end = _RecurrenceEnd.never),
            ),
            ChoiceChip(
              key: const ValueKey('recurrence-end-until'),
              label: const Text('Until a date'),
              selected: _end == _RecurrenceEnd.until,
              onSelected: _busy
                  ? null
                  : (_) => setState(() => _end = _RecurrenceEnd.until),
            ),
            ChoiceChip(
              key: const ValueKey('recurrence-end-count'),
              label: const Text('After a count'),
              selected: _end == _RecurrenceEnd.count,
              onSelected: _busy
                  ? null
                  : (_) => setState(() => _end = _RecurrenceEnd.count),
            ),
          ],
        ),
        if (_end == _RecurrenceEnd.until) ...[
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('recurrence-until-field'),
            controller: _until,
            enabled: !_busy,
            decoration: const InputDecoration(
              labelText: 'Ends on',
              hintText: 'YYYY-MM-DD',
            ),
          ),
        ],
        if (_end == _RecurrenceEnd.count) ...[
          const SizedBox(height: 12),
          SizedBox(
            width: 120,
            child: TextField(
              key: const ValueKey('recurrence-count-field'),
              controller: _count,
              enabled: !_busy,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Occurrences'),
            ),
          ),
        ],
        const SizedBox(height: 12),
        Row(
          children: [
            FilledButton(
              key: const ValueKey('recurrence-save-button'),
              onPressed: _busy ? null : _save,
              child: const Text('Save'),
            ),
            const SizedBox(width: 8),
            TextButton(
              onPressed: _busy ? null : _cancelEditing,
              child: const Text('Cancel'),
            ),
            const Spacer(),
            if (current != null)
              TextButton(
                key: const ValueKey('recurrence-remove-button'),
                onPressed: _busy ? null : _clear,
                child: const Text('Remove recurrence'),
              ),
          ],
        ),
      ],
    ),
  );

  static String _modeLabel(TaskRecurrenceMode mode) => switch (mode) {
    TaskRecurrenceMode.afterCompletion => 'when it is completed',
    TaskRecurrenceMode.onSchedule => 'on a fixed schedule',
  };

  static String _unitLabel(TaskRecurrenceUnit unit, int count) => switch (unit) {
    TaskRecurrenceUnit.day => count == 1 ? 'day' : 'days',
    TaskRecurrenceUnit.week => count == 1 ? 'week' : 'weeks',
    TaskRecurrenceUnit.month => count == 1 ? 'month' : 'months',
  };

  static String _isoDate(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';

  static DateTime? _parseIsoDate(String text) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(text);
    if (match == null) return null;
    final year = int.parse(match.group(1)!);
    final month = int.parse(match.group(2)!);
    final day = int.parse(match.group(3)!);
    final date = DateTime.utc(year, month, day);
    if (date.year != year || date.month != month || date.day != day) return null;
    return date;
  }
}
