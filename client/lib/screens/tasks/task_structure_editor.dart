import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

class TaskStructureEditor extends StatefulWidget {
  const TaskStructureEditor({
    super.key,
    required this.api,
    required this.board,
    required this.onStructureChanged,
    required this.onUnavailable,
  });

  final TaskApi api;
  final TaskBoard board;
  final ValueChanged<int> onStructureChanged;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskStructureEditor> createState() => _TaskStructureEditorState();
}

class _TaskStructureEditorState extends State<TaskStructureEditor> {
  late int _version = widget.board.structureVersion;
  late List<TaskGroup> _groups = List<TaskGroup>.from(widget.board.groups);
  late List<TaskStatusOption> _statuses = List<TaskStatusOption>.from(
    widget.board.statuses,
  );
  late List<TaskPriorityOption> _priorities = List<TaskPriorityOption>.from(
    widget.board.priorities,
  );
  late List<TaskCoreColumn> _core = List<TaskCoreColumn>.from(
    widget.board.coreColumns,
  );
  late List<TaskColumnDefinition> _columns = List<TaskColumnDefinition>.from(
    widget.board.customColumns,
  );
  bool _busy = false;
  String? _message;
  String? _editKey;
  String? _editLabel;
  final TextEditingController _editName = TextEditingController();
  final TextEditingController _editSuffix = TextEditingController();
  Future<void> Function(String name, String? suffix)? _editSave;
  TaskColumnKind _newColumnKind = TaskColumnKind.text;

  @override
  void dispose() {
    _editName.dispose();
    _editSuffix.dispose();
    super.dispose();
  }

  void _edit(
    String key,
    String label,
    String current,
    Future<void> Function(String name, String? suffix) save, {
    String? suffix,
  }) {
    setState(() {
      _editKey = key;
      _editLabel = label;
      _editName.text = current;
      _editSuffix.text = suffix ?? '';
      _editSave = save;
      _message = null;
    });
  }

  Future<void> _saveEdit() async {
    final save = _editSave;
    final name = _editName.text.trim();
    if (_busy || save == null || name.isEmpty) return;
    await save(
      name,
      _editSuffix.text.trim().isEmpty ? null : _editSuffix.text.trim(),
    );
    if (mounted && _message == null) {
      setState(() {
        _editKey = null;
        _editLabel = null;
        _editSave = null;
      });
    }
  }

  Future<void> _mutation<T>(
    Future<TaskApiResult<TaskStructureMutation<T>>> request,
    void Function(T data) apply,
  ) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _message = null;
    });
    final result = await request;
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskStructureMutation<T>>) {
      setState(() => _busy = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskStructureMutation<T>>) {
      setState(() {
        _busy = false;
        _version = result.data.structureVersion;
        apply(result.data.data);
      });
      widget.onStructureChanged(result.data.structureVersion);
    } else {
      setState(() {
        _busy = false;
        _message =
            result.message ?? 'The board structure could not be changed.';
      });
    }
  }

  Future<void> _setGroupArchived(TaskGroup group) => _mutation(
    widget.api.setGroupArchived(
      widget.board.id,
      group.id,
      expectedStructureVersion: _version,
      archived: !group.archived,
    ),
    (changed) => _groups = _replace(_groups, changed, (item) => item.id),
  );

  Future<void> _saveGroup(TaskGroup? group, String name) => _mutation(
    group == null
        ? widget.api.createGroup(
            widget.board.id,
            expectedStructureVersion: _version,
            name: name,
          )
        : widget.api.updateGroup(
            widget.board.id,
            group.id,
            expectedStructureVersion: _version,
            name: name,
            color: group.color,
          ),
    (changed) => _groups = group == null
        ? [..._groups, changed]
        : _replace(_groups, changed, (item) => item.id),
  );

  Future<void> _setStatusArchived(TaskStatusOption status) => _mutation(
    widget.api.setStatusArchived(
      widget.board.id,
      status.id,
      expectedStructureVersion: _version,
      archived: !status.archived,
    ),
    (changed) => _statuses = _replace(_statuses, changed, (item) => item.id),
  );

  Future<void> _saveStatus(
    TaskStatusOption? status,
    String name, {
    TaskStatusCategory? category,
  }) => _mutation(
    status == null
        ? widget.api.createStatus(
            widget.board.id,
            expectedStructureVersion: _version,
            name: name,
            category: category ?? TaskStatusCategory.notStarted,
          )
        : widget.api.updateStatus(
            widget.board.id,
            status.id,
            expectedStructureVersion: _version,
            name: name,
            category: category ?? status.category,
            color: status.color,
          ),
    (changed) => _statuses = status == null
        ? [..._statuses, changed]
        : _replace(_statuses, changed, (item) => item.id),
  );

  Future<void> _setPriorityArchived(TaskPriorityOption priority) => _mutation(
    widget.api.setPriorityArchived(
      widget.board.id,
      priority.id,
      expectedStructureVersion: _version,
      archived: !priority.archived,
    ),
    (changed) =>
        _priorities = _replace(_priorities, changed, (item) => item.id),
  );

  Future<void> _savePriority(
    TaskPriorityOption? priority,
    String name, {
    TaskPriorityRank? rank,
  }) => _mutation(
    priority == null
        ? widget.api.createPriority(
            widget.board.id,
            expectedStructureVersion: _version,
            name: name,
            rank: rank ?? TaskPriorityRank.normal,
          )
        : widget.api.updatePriority(
            widget.board.id,
            priority.id,
            expectedStructureVersion: _version,
            name: name,
            rank: rank ?? priority.rank,
            color: priority.color,
          ),
    (changed) => _priorities = priority == null
        ? [..._priorities, changed]
        : _replace(_priorities, changed, (item) => item.id),
  );

  Future<void> _saveCore(TaskCoreColumn column, String name) => _mutation(
    widget.api.updateCoreLabel(
      widget.board.id,
      column.key,
      expectedStructureVersion: _version,
      displayLabel: name,
    ),
    (changed) => _core = _replace(_core, changed, (item) => item.key),
  );

  Future<void> _setColumnArchived(TaskColumnDefinition column) => _mutation(
    widget.api.setColumnArchived(
      widget.board.id,
      column.id,
      expectedStructureVersion: _version,
      archived: !column.archived,
    ),
    (changed) => _columns = _replace(_columns, changed, (item) => item.id),
  );

  Future<void> _saveColumn(
    TaskColumnDefinition? column,
    String name,
    String? suffix,
  ) => _mutation(
    column == null
        ? widget.api.createColumn(
            widget.board.id,
            expectedStructureVersion: _version,
            name: name,
            type: _newColumnKind,
            numberSuffix: _newColumnKind == TaskColumnKind.number
                ? suffix
                : null,
          )
        : widget.api.updateColumn(
            widget.board.id,
            column.id,
            expectedStructureVersion: _version,
            name: name,
            numberSuffix: column.kind == TaskColumnKind.number ? suffix : null,
          ),
    (changed) => _columns = column == null
        ? [..._columns, changed]
        : _replace(_columns, changed, (item) => item.id),
  );

  @override
  Widget build(BuildContext context) {
    if (!widget.board.can('manage_structure')) return const SizedBox.shrink();
    return ListView(
      padding: EdgeInsets.zero,
      children: [
        Text('Structure', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Every change uses structure version $_version. Board labels change for everyone; saved-view hide, order, and width remain personal or view-specific.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (_message case final message?) ...[
          const SizedBox(height: 8),
          Text(
            message,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
        if (_editKey != null) _editor(),
        _section(
          title: 'Groups',
          addLabel: 'Add group',
          onAdd: () => _edit(
            'group:new',
            'New group',
            '',
            (name, _) => _saveGroup(null, name),
          ),
          children: [
            for (final (index, group) in _groups.indexed)
              _row(
                name: group.name,
                subtitle: group.archived ? 'Archived' : 'Task group',
                color: group.color,
                canUpdate: group.can('update'),
                canArchive: group.can(group.archived ? 'restore' : 'archive'),
                onRename: () => _edit(
                  'group:${group.id}',
                  'Rename group',
                  group.name,
                  (name, _) => _saveGroup(group, name),
                ),
                onArchive: () => _setGroupArchived(group),
                archiveTooltip:
                    '${group.archived ? 'Restore' : 'Archive'} ${group.name}',
                onMoveUp: index > 0 ? () => _moveGroups(index, -1) : null,
                onMoveDown: index < _groups.length - 1
                    ? () => _moveGroups(index, 1)
                    : null,
              ),
          ],
        ),
        _section(
          title: 'Statuses',
          addLabel: 'Add status',
          onAdd: () => _edit(
            'status:new',
            'New status',
            '',
            (name, _) => _saveStatus(null, name),
          ),
          children: [
            for (final status in _statuses)
              _row(
                name: status.name,
                subtitle: status.rawCategory.replaceAll('_', ' '),
                color: status.color,
                canUpdate: status.can('update'),
                canArchive: status.can(status.archived ? 'restore' : 'archive'),
                onRename: () => _edit(
                  'status:${status.id}',
                  'Rename status',
                  status.name,
                  (name, _) => _saveStatus(status, name),
                ),
                onArchive: () => _setStatusArchived(status),
                archiveTooltip:
                    '${status.archived ? 'Restore' : 'Archive'} ${status.name}',
                category: PopupMenuButton<TaskStatusCategory>(
                  tooltip: 'Change ${status.name} category',
                  enabled: status.can('update') && !_busy,
                  onSelected: (category) =>
                      _saveStatus(status, status.name, category: category),
                  itemBuilder: (_) => [
                    for (final category in TaskStatusCategory.values.where(
                      (item) => item != TaskStatusCategory.unknown,
                    ))
                      PopupMenuItem(
                        value: category,
                        child: Text(_words(category.name)),
                      ),
                  ],
                ),
              ),
          ],
        ),
        _section(
          title: 'Priorities',
          addLabel: 'Add priority',
          onAdd: () => _edit(
            'priority:new',
            'New priority',
            '',
            (name, _) => _savePriority(null, name),
          ),
          children: [
            for (final priority in _priorities)
              _row(
                name: priority.name,
                subtitle: priority.rawRank,
                color: priority.color,
                canUpdate: priority.can('update'),
                canArchive: priority.can(
                  priority.archived ? 'restore' : 'archive',
                ),
                onRename: () => _edit(
                  'priority:${priority.id}',
                  'Rename priority',
                  priority.name,
                  (name, _) => _savePriority(priority, name),
                ),
                onArchive: () => _setPriorityArchived(priority),
                archiveTooltip:
                    '${priority.archived ? 'Restore' : 'Archive'} ${priority.name}',
                category: PopupMenuButton<TaskPriorityRank>(
                  tooltip: 'Change ${priority.name} rank',
                  enabled: priority.can('update') && !_busy,
                  onSelected: (rank) =>
                      _savePriority(priority, priority.name, rank: rank),
                  itemBuilder: (_) => [
                    for (final rank in TaskPriorityRank.values.where(
                      (item) => item != TaskPriorityRank.unknown,
                    ))
                      PopupMenuItem(
                        value: rank,
                        child: Text(_words(rank.name)),
                      ),
                  ],
                ),
              ),
          ],
        ),
        _section(
          title: 'Core columns',
          children: [
            for (final column in _core)
              _row(
                name: column.displayLabel,
                subtitle: 'Core · ${column.key}',
                canUpdate: column.can('update'),
                onRename: () => _edit(
                  'core:${column.key}',
                  'Rename core column',
                  column.displayLabel,
                  (name, _) => _saveCore(column, name),
                ),
              ),
          ],
        ),
        _section(
          title: 'Custom columns',
          addLabel: 'Add column',
          onAdd: () => _edit(
            'column:new',
            'New column',
            '',
            (name, suffix) => _saveColumn(null, name, suffix),
          ),
          children: [
            for (final column in _columns)
              _row(
                name: column.name,
                subtitle: [
                  column.rawType,
                  if (column.numberSuffix != null) column.numberSuffix!,
                  if (column.archived) 'Archived',
                ].join(' · '),
                canUpdate: column.can('update'),
                canArchive: column.can(column.archived ? 'restore' : 'archive'),
                onRename: () => _edit(
                  'column:${column.id}',
                  'Edit column',
                  column.name,
                  (name, suffix) => _saveColumn(column, name, suffix),
                  suffix: column.numberSuffix,
                ),
                onArchive: () => _setColumnArchived(column),
                archiveTooltip:
                    '${column.archived ? 'Restore' : 'Archive'} ${column.name}',
              ),
          ],
        ),
      ],
    );
  }

  Widget _editor() => Card(
    margin: const EdgeInsets.only(top: 16),
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _editLabel!,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _editName,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'Name',
              border: OutlineInputBorder(),
            ),
          ),
          if (_editKey == 'column:new') ...[
            const SizedBox(height: 8),
            DropdownButtonFormField<TaskColumnKind>(
              initialValue: _newColumnKind,
              decoration: const InputDecoration(
                labelText: 'Column type',
                border: OutlineInputBorder(),
              ),
              items: [
                for (final kind in TaskColumnKind.values.where(
                  (item) => item != TaskColumnKind.unknown,
                ))
                  DropdownMenuItem(value: kind, child: Text(_words(kind.name))),
              ],
              onChanged: _busy
                  ? null
                  : (value) => setState(() => _newColumnKind = value!),
            ),
          ],
          if (_editKey!.startsWith('column:') &&
              (_newColumnKind == TaskColumnKind.number ||
                  _editSuffix.text.isNotEmpty)) ...[
            const SizedBox(height: 8),
            TextField(
              controller: _editSuffix,
              decoration: const InputDecoration(
                labelText: 'Number suffix',
                hintText: 'hours, %, ft…',
                border: OutlineInputBorder(),
              ),
            ),
          ],
          const SizedBox(height: 8),
          Wrap(
            alignment: WrapAlignment.end,
            spacing: 8,
            children: [
              TextButton(
                onPressed: _busy
                    ? null
                    : () => setState(() {
                        _editKey = null;
                        _editSave = null;
                      }),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: _busy ? null : _saveEdit,
                child: const Text('Save'),
              ),
            ],
          ),
        ],
      ),
    ),
  );

  Widget _section({
    required String title,
    String? addLabel,
    VoidCallback? onAdd,
    required List<Widget> children,
  }) => ExpansionTile(
    key: PageStorageKey<String>('task-structure-$title'),
    initiallyExpanded: title == 'Groups',
    tilePadding: EdgeInsets.zero,
    title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
    trailing: addLabel == null
        ? null
        : TextButton.icon(
            onPressed: _busy ? null : onAdd,
            icon: const Icon(Icons.add, size: 18),
            label: Text(addLabel),
          ),
    children: children.isEmpty
        ? const <Widget>[
            ListTile(contentPadding: EdgeInsets.zero, title: Text('None yet.')),
          ]
        : children,
  );

  Widget _row({
    required String name,
    required String subtitle,
    String? color,
    bool canUpdate = false,
    bool canArchive = false,
    VoidCallback? onRename,
    VoidCallback? onArchive,
    String? archiveTooltip,
    VoidCallback? onMoveUp,
    VoidCallback? onMoveDown,
    Widget? category,
  }) => ListTile(
    contentPadding: EdgeInsets.zero,
    leading: CircleAvatar(
      radius: 8,
      backgroundColor: _color(
        color,
        Theme.of(context).colorScheme.outlineVariant,
      ),
    ),
    title: Text(name),
    subtitle: Text(subtitle),
    trailing: Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        ?category,
        if (onMoveUp != null)
          IconButton(
            tooltip: 'Move $name up',
            onPressed: _busy ? null : onMoveUp,
            icon: const Icon(Icons.arrow_upward, size: 18),
          ),
        if (onMoveDown != null)
          IconButton(
            tooltip: 'Move $name down',
            onPressed: _busy ? null : onMoveDown,
            icon: const Icon(Icons.arrow_downward, size: 18),
          ),
        if (canUpdate)
          IconButton(
            tooltip: 'Rename $name',
            onPressed: _busy ? null : onRename,
            icon: const Icon(Icons.edit_outlined, size: 18),
          ),
        if (canArchive)
          IconButton(
            tooltip: archiveTooltip,
            onPressed: _busy ? null : onArchive,
            icon: const Icon(Icons.archive_outlined, size: 18),
          ),
      ],
    ),
  );

  Future<void> _moveGroups(int index, int delta) async {
    final reordered = List<TaskGroup>.from(_groups);
    final item = reordered.removeAt(index);
    reordered.insert(index + delta, item);
    await _mutation(
      widget.api.reorderGroups(
        widget.board.id,
        expectedStructureVersion: _version,
        orderedIds: reordered.map((item) => item.id).toList(growable: false),
      ),
      (_) => _groups = reordered,
    );
  }
}

List<T> _replace<T>(List<T> values, T changed, String Function(T) id) => [
  for (final value in values)
    if (id(value) == id(changed)) changed else value,
];

Color _color(String? raw, Color fallback) {
  if (raw == null) return fallback;
  final hex = raw.replaceFirst('#', '');
  if (!RegExp(r'^[0-9a-fA-F]{6}$').hasMatch(hex)) return fallback;
  return Color(int.parse('FF$hex', radix: 16));
}

String _words(String value) => value
    .replaceAllMapped(
      RegExp(r'([a-z])([A-Z])'),
      (match) => '${match[1]} ${match[2]}',
    )
    .replaceAll('_', ' ');
