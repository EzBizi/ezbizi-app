import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

typedef _Json = Map<String, dynamic>;

/// Move to… / Copy to… (Jose, 2026-10-28): a board you can write, then one
/// of its groups. Pops the chosen group id.
class TaskDestinationPanel extends StatefulWidget {
  const TaskDestinationPanel({
    super.key,
    required this.api,
    required this.title,
    required this.action,
    this.boardId,
  });

  final ApiClient api;
  final String title;
  final String action;

  /// The board to start on: the open one.
  final String? boardId;

  @override
  State<TaskDestinationPanel> createState() => _TaskDestinationPanelState();
}

class _TaskDestinationPanelState extends State<TaskDestinationPanel> {
  List<_Json> _boards = const [];
  List<_Json> _groups = const [];
  String? _board;
  String? _group;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadBoards();
  }

  Future<void> _loadBoards() async {
    final res = await widget.api.get('/task-boards');
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    final boards = (res.body['data'] as List)
        .cast<_Json>()
        .where((b) => b['can_edit'] == true)
        .toList();
    setState(() => _boards = boards);
    final start = boards.any((b) => b['id'] == widget.boardId)
        ? widget.boardId
        : null;
    if (start != null) await _pickBoard(start);
  }

  Future<void> _pickBoard(String? id) async {
    setState(() {
      _board = id;
      _group = null;
      _groups = const [];
    });
    if (id == null) return;
    final res = await widget.api.get('/task-boards/$id');
    if (!mounted || _board != id) return;
    if (!res.ok) return setState(() => _error = res.reason);
    setState(
      () => _groups = ((res.body['data'] as Map)['groups'] as List? ?? const [])
          .cast<_Json>(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.title,
      actions: [
        FilledButton(
          key: const Key('task-destination-go'),
          onPressed: _group == null
              ? null
              : () => Navigator.of(context).pop(_group),
          child: Text(widget.action),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SearchPickerField<String>(
            key: const Key('task-destination-board'),
            label: 'Board',
            value: _board,
            options: [
              for (final b in _boards)
                SearchOption(
                  value: b['id'] as String,
                  label: '${b['name']}',
                  detail: b['company_name'] as String?,
                ),
            ],
            onChanged: _pickBoard,
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: const Key('task-destination-group'),
            label: 'Group',
            value: _group,
            options: [
              for (final g in _groups)
                SearchOption(value: g['id'] as String, label: '${g['name']}'),
            ],
            onChanged: _groups.isEmpty
                ? null
                : (v) => setState(() => _group = v),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// One choice for many tasks (bulk Status, bulk Assign). Pops the value;
/// [none] is offered as an option meaning "nobody" and pops ''.
class TaskChoicePanel extends StatefulWidget {
  const TaskChoicePanel({
    super.key,
    required this.title,
    required this.label,
    required this.options,
    this.none,
  });

  final String title;
  final String label;
  final Map<String, String> options;
  final String? none;

  @override
  State<TaskChoicePanel> createState() => _TaskChoicePanelState();
}

class _TaskChoicePanelState extends State<TaskChoicePanel> {
  String? _value;

  @override
  Widget build(BuildContext context) => SidePanel(
    title: widget.title,
    actions: [
      FilledButton(
        key: const Key('task-choice-go'),
        onPressed: _value == null
            ? null
            : () => Navigator.of(context).pop(_value),
        child: const Text('Apply'),
      ),
    ],
    child: SearchPickerField<String>(
      key: const Key('task-choice'),
      label: widget.label,
      value: _value,
      options: [
        if (widget.none != null) SearchOption(value: '', label: widget.none!),
        for (final e in widget.options.entries)
          SearchOption(value: e.key, label: e.value),
      ],
      onChanged: (v) => setState(() => _value = v),
    ),
  );
}

/// Link… (Jose, 2026-10-28): the tasks this one is linked to, and a board
/// then a task to link. Linked tasks show each other; Done carries across.
class TaskLinkPanel extends StatefulWidget {
  const TaskLinkPanel({super.key, required this.api, required this.task});

  final ApiClient api;
  final Map<String, dynamic> task;

  @override
  State<TaskLinkPanel> createState() => _TaskLinkPanelState();
}

class _TaskLinkPanelState extends State<TaskLinkPanel> {
  List<_Json> _links = const [];
  List<_Json> _boards = const [];
  List<_Json> _tasks = const [];
  String? _board;
  String? _pick;
  String? _error;

  String get _id => widget.task['id'] as String;

  @override
  void initState() {
    super.initState();
    _refresh();
    widget.api.get('/task-boards').then((res) {
      if (mounted && res.ok) {
        setState(() => _boards = (res.body['data'] as List).cast<_Json>());
      }
    });
  }

  Future<void> _refresh() async {
    final res = await widget.api.get('/tasks/$_id/links');
    if (!mounted) return;
    if (res.ok) {
      setState(() => _links = (res.body['data'] as List).cast<_Json>());
    }
  }

  Future<void> _pickBoard(String? id) async {
    setState(() {
      _board = id;
      _pick = null;
      _tasks = const [];
    });
    if (id == null) return;
    final res = await widget.api.get('/task-boards/$id');
    if (!mounted || _board != id || !res.ok) return;
    setState(
      () => _tasks = ((res.body['data'] as Map)['tasks'] as List? ?? const [])
          .cast<_Json>()
          .where((t) => t['id'] != _id && t['parent_task_id'] == null)
          .toList(),
    );
  }

  Future<void> _link() async {
    final res = await widget.api.post('/tasks/$_id/links', {
      'linked_task_id': _pick,
    });
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    setState(() {
      _pick = null;
      _error = null;
    });
    await _refresh();
  }

  Future<void> _unlink(String other) async {
    final res = await widget.api.delete('/tasks/$_id/links/$other');
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SidePanel(
      title: 'Linked tasks',
      subtitle: '${widget.task['title']}',
      actions: [
        FilledButton(
          key: const Key('task-link-go'),
          onPressed: _pick == null ? null : _link,
          child: const Text('Link'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _links.isEmpty
                ? 'Not linked to any task. Done or reopened on one carries to the others.'
                : 'Done or reopened on one carries to the others.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          for (final l in _links)
            Row(
              key: ValueKey('task-link-${l['id']}'),
              children: [
                Expanded(child: Text('${l['title']}  ·  ${l['board_name']}')),
                IconButton(
                  key: ValueKey('task-unlink-${l['id']}'),
                  tooltip: 'Unlink',
                  icon: const Icon(Icons.link_off, size: 18),
                  onPressed: () => _unlink(l['id'] as String),
                ),
              ],
            ),
          const SizedBox(height: 16),
          SearchPickerField<String>(
            key: const Key('task-link-board'),
            label: 'Board',
            value: _board,
            options: [
              for (final b in _boards)
                SearchOption(value: b['id'] as String, label: '${b['name']}'),
            ],
            onChanged: _pickBoard,
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: const Key('task-link-task'),
            label: 'Task',
            value: _pick,
            options: [
              for (final t in _tasks)
                SearchOption(value: t['id'] as String, label: '${t['title']}'),
            ],
            onChanged: _tasks.isEmpty ? null : (v) => setState(() => _pick = v),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          ],
        ],
      ),
    );
  }
}
