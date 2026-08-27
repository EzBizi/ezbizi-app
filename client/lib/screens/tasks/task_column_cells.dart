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
  });

  final TaskRow task;
  final TaskColumnDefinition definition;
  final TaskCellValue value;
  final Future<void> Function(TaskColumnDefinition definition, Object? value)
  onChanged;
  final bool enabled;
  final List<TaskParticipant> participants;

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
      ),
      TaskColumnKind.number => _NumberCell(
        initial: value.value as num?,
        suffix: definition.numberSuffix,
        label: definition.name,
        onSaved: (number) => onChanged(definition, number),
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
      ),
      // File mutations are intentionally closed until Task 10A proves the
      // off-instance restore path. Keep values visible without pretending it
      // can be changed.
      TaskColumnKind.file => Semantics(
        readOnly: true,
        child: Text('Files are unavailable until the restore gate passes.'),
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
  });
  final String initial;
  final String label;
  final ValueChanged<String> onSaved;
  @override
  State<_TextCell> createState() => _TextCellState();
}

class _TextCellState extends State<_TextCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial,
  );
  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    decoration: InputDecoration(isDense: true, labelText: widget.label),
    onSubmitted: widget.onSaved,
  );
}

class _NumberCell extends StatefulWidget {
  const _NumberCell({
    required this.initial,
    required this.suffix,
    required this.label,
    required this.onSaved,
  });
  final num? initial;
  final String? suffix;
  final String label;
  final ValueChanged<num?> onSaved;
  @override
  State<_NumberCell> createState() => _NumberCellState();
}

class _NumberCellState extends State<_NumberCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial?.toString() ?? '',
  );
  @override
  void dispose() {
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
    ),
    onSubmitted: (text) =>
        widget.onSaved(text.trim().isEmpty ? null : num.tryParse(text)),
  );
}

class _DateCell extends StatefulWidget {
  const _DateCell({
    required this.initial,
    required this.label,
    required this.onSaved,
  });
  final DateTime? initial;
  final String label;
  final ValueChanged<String?> onSaved;
  @override
  State<_DateCell> createState() => _DateCellState();
}

class _DateCellState extends State<_DateCell> {
  late final TextEditingController _controller = TextEditingController(
    text: _format(widget.initial),
  );
  static String _format(DateTime? date) => date == null
      ? ''
      : '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
  @override
  void dispose() {
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
    onSubmitted: (text) =>
        widget.onSaved(text.trim().isEmpty ? null : text.trim()),
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
  });
  final TaskLinkValue? initial;
  final String label;
  final ValueChanged<Map<String, Object?>?> onSaved;
  @override
  State<_LinkCell> createState() => _LinkCellState();
}

class _LinkCellState extends State<_LinkCell> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial?.url ?? '',
  );
  @override
  void dispose() {
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
    onSubmitted: (url) => widget.onSaved(
      url.trim().isEmpty ? null : <String, Object?>{'url': url.trim()},
    ),
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
