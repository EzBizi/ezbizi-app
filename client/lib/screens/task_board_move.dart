import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import 'task_board_sensitive.dart';

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
/// "Lead", "Customer", "Job" for a record type.
String recordTypeLabel(String type) => switch (type) {
  'lead' => 'Lead',
  'customer' => 'Customer',
  'job' => 'Job',
  'vehicle' => 'Vehicle',
  _ => type,
};

class TaskLinkPanel extends StatefulWidget {
  const TaskLinkPanel({super.key, required this.api, required this.task});

  final ApiClient api;
  final Map<String, dynamic> task;

  @override
  State<TaskLinkPanel> createState() => _TaskLinkPanelState();
}

class _TaskLinkPanelState extends State<TaskLinkPanel> {
  List<_Json> _links = const [];
  List<_Json> _found = const [];
  List<_Json> _records = const [];
  late Map? _about = widget.task['about'] as Map?;
  final _query = TextEditingController();
  Timer? _wait;
  String? _error;

  String get _id => widget.task['id'] as String;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void dispose() {
    _wait?.cancel();
    _query.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    final res = await widget.api.get('/tasks/$_id/links');
    if (!mounted) return;
    if (res.ok) {
      setState(() => _links = (res.body['data'] as List).cast<_Json>());
    }
  }

  /// One box across every board (Jose 11-17): type, see each task's board.
  void _search(String q) {
    _wait?.cancel();
    if (q.trim().length < 2) {
      return setState(() {
        _found = const [];
        _records = const [];
      });
    }
    _wait = Timer(const Duration(milliseconds: 250), () async {
      final res = await widget.api.get(
        '/task-search?q=${Uri.encodeQueryComponent(q.trim())}',
      );
      if (!mounted || _query.text != q || !res.ok) return;
      final linked = {for (final l in _links) l['id']};
      setState(() {
        _found = (res.body['data'] as List)
            .cast<_Json>()
            .where((t) => t['id'] != _id && !linked.contains(t['id']))
            .toList();
        _records = (res.body['records'] as List? ?? const []).cast<_Json>();
      });
    });
  }

  Future<void> _link(String other) async {
    final res = await widget.api.post('/tasks/$_id/links', {
      'linked_task_id': other,
    });
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    _query.clear();
    setState(() {
      _found = const [];
      _error = null;
    });
    await _refresh();
  }

  /// What the task is about: one lead, customer or job (null clears it).
  Future<void> _setAbout(_Json? record) async {
    final res = await widget.api.patch('/tasks/$_id', {
      'about': record == null
          ? null
          : {'type': record['type'], 'id': record['id']},
    });
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    _query.clear();
    setState(() {
      _about = record == null
          ? null
          : {'type': record['type'], 'id': record['id'], 'label': record['label']};
      _found = const [];
      _records = const [];
      _error = null;
    });
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
      subtitle: taskTitle(widget.task),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Link a lead, customer or job to open it from the task. Linked tasks show each other, and marking one Done marks them all Done.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          if (_about case final Map about)
            Row(
              key: const Key('task-link-about'),
              children: [
                Expanded(
                  child: Text(
                    '${about['label']}  ·  ${recordTypeLabel('${about['type']}')}',
                  ),
                ),
                IconButton(
                  key: const Key('task-unlink-about'),
                  tooltip: 'Unlink',
                  icon: const Icon(Icons.link_off, size: 18),
                  onPressed: () => _setAbout(null),
                ),
              ],
            ),
          for (final l in _links)
            Row(
              key: ValueKey('task-link-${l['id']}'),
              children: [
                Expanded(child: Text('${taskTitle(l)}  ·  ${l['board_name']}')),
                IconButton(
                  key: ValueKey('task-unlink-${l['id']}'),
                  tooltip: 'Unlink',
                  icon: const Icon(Icons.link_off, size: 18),
                  onPressed: () => _unlink(l['id'] as String),
                ),
              ],
            ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('task-link-search'),
            controller: _query,
            onChanged: _search,
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search),
              hintText: 'Find a task, lead, customer or job',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
          for (final r in _records)
            ListTile(
              key: ValueKey('task-link-record-${r['type']}-${r['id']}'),
              dense: true,
              leading: const Icon(Icons.person_pin_outlined, size: 18),
              title: Text('${r['label']}'),
              subtitle: Text(
                [recordTypeLabel('${r['type']}'), ?r['detail']].join(' · '),
              ),
              onTap: () => _setAbout(r),
            ),
          for (final t in _found)
            ListTile(
              key: ValueKey('task-link-pick-${t['id']}'),
              dense: true,
              leading: const Icon(Icons.add_link, size: 18),
              title: Text(taskTitle(t)),
              subtitle: Text('${t['board_name'] ?? ''}'),
              onTap: () => _link(t['id'] as String),
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
