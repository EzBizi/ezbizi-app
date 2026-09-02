import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

/// "Blocked by" (task-4 brief, task-12 brief): the dependency edges on
/// [snapshot.task], display-only — no drag-to-reschedule, no propagation,
/// nothing here blocks completion. `TaskTimelineView` draws the same edges
/// as lines between bars; this is the other place they show up.
///
/// Adding one happens IN THIS PANEL: tapping "Add dependency" expands an
/// inline search section in place, the same shape `TaskRecordLinks` already
/// uses for linking a record. There is no `showDialog`/`AlertDialog`
/// anywhere in this file — StartUp.md's no-pop-ups rule, the same
/// constraint `NameEntryPanel`'s docblock argues from.
class TaskDependencyPanel extends StatefulWidget {
  const TaskDependencyPanel({
    super.key,
    required this.api,
    required this.snapshot,
    required this.onChanged,
  });

  final TaskApi api;
  final TaskDetailSnapshot snapshot;
  final ValueChanged<TaskDetailSnapshot> onChanged;

  @override
  State<TaskDependencyPanel> createState() => _TaskDependencyPanelState();
}

class _TaskDependencyPanelState extends State<TaskDependencyPanel> {
  final _search = TextEditingController();
  bool _pickerOpen = false;
  bool _busy = false;
  String? _notice;
  List<TaskQuickSearchResult> _matches = const [];
  int _searchGeneration = 0;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant TaskDependencyPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.snapshot != widget.snapshot) {
      _searchGeneration++;
      _notice = null;
      _matches = const [];
      _search.clear();
    }
  }

  void _openPicker() {
    if (_busy) return;
    setState(() {
      _pickerOpen = true;
      _notice = null;
      _matches = const [];
    });
  }

  void _closePicker() {
    setState(() {
      _pickerOpen = false;
      _notice = null;
      _matches = const [];
      _search.clear();
    });
  }

  /// Quick search is global (task-9 brief: `GET tasks/search`); results are
  /// filtered down to the same board, minus the task itself and anything
  /// already blocking it. `TaskDependencyWriter::add` enforces same-board
  /// server-side too — this filter only keeps an obviously-cross-board or
  /// already-linked pick from ever reaching that refusal in the first place.
  Future<void> _searchFor(String text) async {
    final generation = ++_searchGeneration;
    final query = text.trim();
    if (query.length < 2) {
      setState(() => _matches = const []);
      return;
    }
    final result = await widget.api.quickSearch(query);
    if (!mounted || generation != _searchGeneration) return;
    if (result is TaskApiSuccess<List<TaskQuickSearchResult>>) {
      final boardId = widget.snapshot.task.boardId;
      final selfId = widget.snapshot.task.id;
      final existing = (widget.snapshot.task.blockedBy ?? const <TaskBlockedByRef>[])
          .map((ref) => ref.taskId)
          .toSet();
      setState(() {
        _matches = result.data
            .where(
              (match) =>
                  match.boardId == boardId &&
                  match.taskId != selfId &&
                  !existing.contains(match.taskId),
            )
            .toList(growable: false);
      });
    } else {
      setState(() {
        _matches = const [];
        _notice = result.message ?? 'Task search is unavailable.';
      });
    }
  }

  Future<void> _add(TaskQuickSearchResult candidate) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.addDependency(
      widget.snapshot.task.id,
      blockerTaskId: candidate.taskId,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskDependency>) {
      await _refresh();
      if (mounted) _closePicker();
    } else {
      setState(
        () => _notice = result.message ?? 'The dependency could not be added.',
      );
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _remove(TaskBlockedByRef ref) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.removeDependency(ref.id);
    if (!mounted) return;
    if (result is TaskApiSuccess<bool> && result.data) {
      await _refresh();
    } else {
      setState(
        () => _notice = result.message ?? 'The dependency could not be removed.',
      );
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
      setState(
        () => _notice = result.message ?? 'Dependencies could not be refreshed.',
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final blockedBy = widget.snapshot.task.blockedBy ?? const <TaskBlockedByRef>[];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text('Blocked by', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            if (!_pickerOpen)
              TextButton.icon(
                onPressed: _busy ? null : _openPicker,
                icon: const Icon(Icons.add_link),
                label: const Text('Add dependency'),
              ),
          ],
        ),
        if (blockedBy.isEmpty && !_pickerOpen)
          const Text('Not blocked by anything.')
        else if (blockedBy.isNotEmpty)
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final ref in blockedBy)
                InputChip(
                  key: ValueKey('dependency-chip-${ref.id}'),
                  label: Text(
                    [
                      if (ref.number != null) ref.number!,
                      if (ref.title != null) ref.title!,
                    ].join(' '),
                  ),
                  onDeleted: _busy ? null : () => _remove(ref),
                ),
            ],
          ),
        if (_pickerOpen) _picker(),
        if (_notice case final notice?) ...[
          const SizedBox(height: 8),
          Text(notice, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        ],
      ],
    );
  }

  Widget _picker() => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: TextField(
                key: const ValueKey('dependency-search-field'),
                controller: _search,
                enabled: !_busy,
                onChanged: _searchFor,
                decoration: const InputDecoration(labelText: "Search this board's tasks"),
              ),
            ),
            IconButton(
              tooltip: 'Cancel',
              icon: const Icon(Icons.close),
              onPressed: _busy ? null : _closePicker,
            ),
          ],
        ),
        for (final candidate in _matches)
          ListTile(
            key: ValueKey('dependency-candidate-${candidate.taskId}'),
            contentPadding: EdgeInsets.zero,
            title: Text(candidate.title),
            subtitle: Text(candidate.number),
            onTap: _busy ? null : () => _add(candidate),
          ),
      ],
    ),
  );
}
