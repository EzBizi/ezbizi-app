import 'dart:convert';

import 'package:flutter/material.dart';

import 'task_models.dart';

/// Shared typed cells and their small, state-free editing contracts. Network
/// policy stays in [TaskApi]; this widget only presents the server definition.
class TaskColumnCell extends StatelessWidget {
  const TaskColumnCell({
    super.key,
    required this.task,
    required this.definition,
    required this.value,
    required this.onChanged,
    this.enabled = true,
    this.participants = const [],
    this.onDraftChanged,
  });

  final TaskRow task;
  final TaskColumnDefinition definition;
  final TaskCellValue value;
  final Future<void> Function(TaskColumnDefinition definition, Object? value)
  onChanged;
  final bool enabled;
  final List<TaskParticipant> participants;
  final TaskDraftChanged? onDraftChanged;

  String get _draftId => 'task:${task.id}:custom:${definition.id}';

  static bool isSortable(TaskColumnKind kind) => switch (kind) {
    TaskColumnKind.text ||
    TaskColumnKind.number ||
    TaskColumnKind.checkbox ||
    TaskColumnKind.date ||
    TaskColumnKind.link => true,
    TaskColumnKind.people ||
    TaskColumnKind.label ||
    TaskColumnKind.file ||
    TaskColumnKind.unknown => false,
  };

  bool get _editable => enabled && !definition.isReadOnly && !value.isReadOnly;

  @override
  Widget build(BuildContext context) {
    if (!_editable) {
      return Semantics(
        readOnly: true,
        label: '${definition.name}: ${_rawText(value.rawValue)}. Read only.',
        child: Text(_rawText(value.rawValue)),
      );
    }
    return switch (definition.kind) {
      TaskColumnKind.text => _TextCell(
        initial: value.value as String? ?? '',
        label: definition.name,
        onSaved: (text) => onChanged(definition, text),
        draftId: _draftId,
        onDraftChanged: onDraftChanged,
      ),
      TaskColumnKind.number => _NumberCell(
        initial: value.value as num?,
        suffix: definition.numberSuffix,
        label: definition.name,
        onSaved: (number) => onChanged(definition, number),
        draftId: _draftId,
        onDraftChanged: onDraftChanged,
      ),
      TaskColumnKind.checkbox => Tooltip(
        message: 'Toggle ${definition.name}',
        child: Semantics(
          label: definition.name,
          child: Checkbox(
            value: value.value as bool? ?? false,
            onChanged: (checked) {
              if (checked != null) onChanged(definition, checked);
            },
          ),
        ),
      ),
      TaskColumnKind.date => _DateCell(
        initial: value.value as DateTime?,
        label: definition.name,
        onSaved: (date) => onChanged(definition, date),
        draftId: _draftId,
        onDraftChanged: onDraftChanged,
      ),
      TaskColumnKind.people => _PeopleCell(
        selected: List<String>.from(value.value as List<String>? ?? const []),
        candidates: participants,
        onSaved: (ids) => onChanged(definition, ids),
      ),
      TaskColumnKind.label => _LabelCell(
        definition: definition,
        selected: List<String>.from(value.value as List<String>? ?? const []),
        onSaved: (ids) => onChanged(definition, ids),
      ),
      TaskColumnKind.link => _LinkCell(
        initial: value.value as TaskLinkValue?,
        label: definition.name,
        onSaved: (link) => onChanged(definition, link),
        draftId: _draftId,
        onDraftChanged: onDraftChanged,
      ),
      // File mutations are intentionally closed until Task 10A proves the
      // off-instance restore path. Keep values visible without pretending it
      // can be changed.
      TaskColumnKind.file => Semantics(
        readOnly: true,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (value.rawValue != null) Text(_rawText(value.rawValue)),
            const Text('Files are unavailable until the restore gate passes.'),
          ],
        ),
      ),
      TaskColumnKind.unknown => Text(_rawText(value.rawValue)),
    };
  }

  static String _rawText(Object? raw) => raw == null
      ? 'No value'
      : raw is String
      ? raw
      : raw is List
      ? raw.join(', ')
      : jsonEncode(raw);
}

class _TextCell extends StatefulWidget {
  const _TextCell({
    required this.initial,
    required this.label,
    required this.onSaved,
    required this.draftId,
    this.onDraftChanged,
  });
  final String initial;
  final String label;
  final Future<void> Function(String) onSaved;
  final String draftId;
  final TaskDraftChanged? onDraftChanged;
  @override
  State<_TextCell> createState() => _TextCellState();
}

class _TextCellState extends State<_TextCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial,
  );
  bool _dirty = false;
  void _mark(String value) {
    _dirty = value != widget.initial;
    widget.onDraftChanged?.call(widget.draftId, _dirty);
  }

  @override
  void didUpdateWidget(covariant _TextCell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initial == widget.initial) return;
    if (_controller.text == widget.initial) {
      _mark(_controller.text);
    } else if (!_dirty) {
      _controller.text = widget.initial;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(widget.draftId, false);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    decoration: InputDecoration(isDense: true, labelText: widget.label),
    onChanged: _mark,
    onSubmitted: (value) async {
      await widget.onSaved(value);
    },
  );
}

class _NumberCell extends StatefulWidget {
  const _NumberCell({
    required this.initial,
    required this.suffix,
    required this.label,
    required this.onSaved,
    required this.draftId,
    this.onDraftChanged,
  });
  final num? initial;
  final String? suffix;
  final String label;
  final Future<void> Function(num?) onSaved;
  final String draftId;
  final TaskDraftChanged? onDraftChanged;
  @override
  State<_NumberCell> createState() => _NumberCellState();
}

class _NumberCellState extends State<_NumberCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial?.toString() ?? '',
  );
  String? _error;
  bool _dirty = false;

  String get _initialText => widget.initial?.toString() ?? '';

  @override
  void didUpdateWidget(covariant _NumberCell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initial == widget.initial) return;
    if (_controller.text == _initialText) {
      _dirty = false;
      widget.onDraftChanged?.call(widget.draftId, false);
    } else if (!_dirty) {
      _controller.text = _initialText;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(widget.draftId, false);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    keyboardType: const TextInputType.numberWithOptions(
      decimal: true,
      signed: true,
    ),
    decoration: InputDecoration(
      isDense: true,
      labelText: widget.label,
      suffixText: widget.suffix,
      errorText: _error,
    ),
    onChanged: (value) {
      _dirty = value != _initialText;
      widget.onDraftChanged?.call(widget.draftId, _dirty);
    },
    onSubmitted: (text) async {
      final trimmed = text.trim();
      if (trimmed.isEmpty) {
        await widget.onSaved(null);
        return;
      }
      final parsed = num.tryParse(trimmed);
      if (parsed == null || !parsed.isFinite) {
        setState(() => _error = 'Enter a valid number.');
        return;
      }
      setState(() => _error = null);
      await widget.onSaved(parsed);
    },
  );
}

class _DateCell extends StatefulWidget {
  const _DateCell({
    required this.initial,
    required this.label,
    required this.onSaved,
    required this.draftId,
    this.onDraftChanged,
  });
  final DateTime? initial;
  final String label;
  final Future<void> Function(String?) onSaved;
  final String draftId;
  final TaskDraftChanged? onDraftChanged;
  @override
  State<_DateCell> createState() => _DateCellState();
}

class _DateCellState extends State<_DateCell> {
  late final TextEditingController _controller = TextEditingController(
    text: _format(widget.initial),
  );
  bool _dirty = false;
  static String _format(DateTime? date) => date == null
      ? ''
      : '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

  @override
  void didUpdateWidget(covariant _DateCell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initial == widget.initial) return;
    final initial = _format(widget.initial);
    if (_controller.text == initial) {
      _dirty = false;
      widget.onDraftChanged?.call(widget.draftId, false);
    } else if (!_dirty) {
      _controller.text = initial;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(widget.draftId, false);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    keyboardType: TextInputType.datetime,
    decoration: InputDecoration(
      isDense: true,
      labelText: widget.label,
      hintText: 'YYYY-MM-DD',
    ),
    onChanged: (value) {
      _dirty = value != _format(widget.initial);
      widget.onDraftChanged?.call(widget.draftId, _dirty);
    },
    onSubmitted: (text) async {
      await widget.onSaved(text.trim().isEmpty ? null : text.trim());
    },
  );
}

class _PeopleCell extends StatelessWidget {
  const _PeopleCell({
    required this.selected,
    required this.candidates,
    required this.onSaved,
  });
  final List<String> selected;
  final List<TaskParticipant> candidates;
  final ValueChanged<List<String>> onSaved;
  @override
  Widget build(BuildContext context) {
    final candidateIds = candidates.map((candidate) => candidate.id).toSet();
    return Wrap(
      spacing: 4,
      children: [
        // Keep an out-of-date server value visible rather than silently dropping
        // it; it cannot be selected again unless the server offers it.
        for (final id in selected.where((id) => !candidateIds.contains(id)))
          Chip(label: Text(id)),
        for (final candidate in candidates)
          FilterChip(
            label: Text(candidate.name),
            selected: selected.contains(candidate.id),
            onSelected: (checked) {
              final next = [...selected];
              checked ? next.add(candidate.id) : next.remove(candidate.id);
              onSaved(next);
            },
          ),
      ],
    );
  }
}

class _LabelCell extends StatelessWidget {
  const _LabelCell({
    required this.definition,
    required this.selected,
    required this.onSaved,
  });
  final TaskColumnDefinition definition;
  final List<String> selected;
  final ValueChanged<List<String>> onSaved;
  @override
  Widget build(BuildContext context) => Wrap(
    spacing: 4,
    children: [
      for (final option in definition.options.where(
        (option) => !option.archived,
      ))
        FilterChip(
          label: Text(option.name),
          selected: selected.contains(option.id),
          onSelected: (isSelected) {
            final next = [...selected];
            isSelected ? next.add(option.id) : next.remove(option.id);
            onSaved(next);
          },
        ),
    ],
  );
}

class _LinkCell extends StatefulWidget {
  const _LinkCell({
    required this.initial,
    required this.label,
    required this.onSaved,
    required this.draftId,
    this.onDraftChanged,
  });
  final TaskLinkValue? initial;
  final String label;
  final Future<void> Function(Map<String, Object?>?) onSaved;
  final String draftId;
  final TaskDraftChanged? onDraftChanged;
  @override
  State<_LinkCell> createState() => _LinkCellState();
}

class _LinkCellState extends State<_LinkCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial?.url ?? '',
  );
  bool _dirty = false;

  @override
  void didUpdateWidget(covariant _LinkCell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initial == widget.initial) return;
    final initial = widget.initial?.url ?? '';
    if (_controller.text == initial) {
      _dirty = false;
      widget.onDraftChanged?.call(widget.draftId, false);
    } else if (!_dirty) {
      _controller.text = initial;
    }
  }

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(widget.draftId, false);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    decoration: InputDecoration(
      isDense: true,
      labelText: widget.label,
      hintText: 'https://',
    ),
    onChanged: (value) {
      _dirty = value != (widget.initial?.url ?? '');
      widget.onDraftChanged?.call(widget.draftId, _dirty);
    },
    onSubmitted: (url) async {
      await widget.onSaved(
        url.trim().isEmpty ? null : <String, Object?>{'url': url.trim()},
      );
    },
  );
}

sealed class TaskCellSaveOutcome {
  const TaskCellSaveOutcome(this.message);
  final String? message;
}

final class TaskCellSaveSuccess extends TaskCellSaveOutcome {
  const TaskCellSaveSuccess(this.values) : super(null);
  final Map<String, Object?> values;
}

final class TaskCellSaveFailure extends TaskCellSaveOutcome {
  const TaskCellSaveFailure(super.message);
}

/// Keeps scalar edits responsive, while group/order/archive-like operations do
/// not mutate the row until the authoritative server response arrives.
final class TaskCellEditController {
  TaskCellEditController(this.task);
  final TaskRow task;
  final Map<String, Object?> _values = <String, Object?>{};

  Object? valueFor(String field) =>
      _values[field] ??
      switch (field) {
        'title' => task.title,
        'group_id' => task.groupId,
        'owner_user_id' => task.ownerUserId,
        'status_option_id' => task.statusOptionId,
        'priority_option_id' => task.priorityOptionId,
        'start_on' => task.startOn,
        'due_on' => task.dueOn,
        _ => task.values[field]?.value,
      };

  Future<TaskCellSaveOutcome> saveScalar({
    required String field,
    required Object? value,
    required Future<TaskCellSaveOutcome> Function(String field, Object? value)
    send,
  }) async {
    final prior = valueFor(field);
    _values[field] = value;
    final outcome = await send(field, value);
    if (outcome is TaskCellSaveFailure) _values[field] = prior;
    if (outcome is TaskCellSaveSuccess) _values.addAll(outcome.values);
    return outcome;
  }

  Future<TaskCellSaveOutcome> saveStructural({
    required String field,
    required Object? value,
    required Future<TaskCellSaveOutcome> Function(String field, Object? value)
    send,
  }) async {
    await Future<void>.delayed(Duration.zero);
    final outcome = await send(field, value);
    if (outcome is TaskCellSaveSuccess) _values.addAll(outcome.values);
    return outcome;
  }
}

final class TaskSelection {
  final Map<String, TaskRow> _tasks = <String, TaskRow>{};
  int get count => _tasks.length;
  bool contains(String taskId) => _tasks.containsKey(taskId);
  void toggle(TaskRow task) =>
      contains(task.id) ? _tasks.remove(task.id) : _tasks[task.id] = task;
  void clear() => _tasks.clear();
  Map<String, Object?> bulkPayload(Map<String, Object?> changes) =>
      <String, Object?>{
        'tasks': _tasks.values
            .map(
              (task) => <String, Object?>{
                'id': task.id,
                'expected_version': task.version,
              },
            )
            .toList(growable: false),
        'changes': changes,
      };
}
