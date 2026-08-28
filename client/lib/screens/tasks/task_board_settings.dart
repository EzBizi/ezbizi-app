import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

class TaskBoardSettings extends StatefulWidget {
  const TaskBoardSettings({
    super.key,
    required this.api,
    required this.board,
    required this.folders,
    required this.onChanged,
    required this.onUnavailable,
  });

  final TaskApi api;
  final TaskBoard board;
  final List<TaskFolder> folders;
  final ValueChanged<TaskBoard> onChanged;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskBoardSettings> createState() => _TaskBoardSettingsState();
}

class _TaskBoardSettingsState extends State<TaskBoardSettings> {
  late final TextEditingController _name = TextEditingController(
    text: widget.board.name,
  );
  late final TextEditingController _description = TextEditingController(
    text: widget.board.description ?? '',
  );
  late TaskBoardVisibility _visibility = widget.board.visibility;
  late String? _folderId = widget.board.folderId;
  late bool _favorite = widget.board.isFavorite;
  bool _saving = false;
  String? _message;

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !widget.board.can('update')) return;
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _message = 'Enter a board name.');
      return;
    }
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.updateBoard(
      widget.board.id,
      expectedStructureVersion: widget.board.structureVersion,
      changes: <String, Object?>{
        'name': name,
        'description': _description.text.trim().isEmpty
            ? null
            : _description.text.trim(),
        'visibility': _visibility == TaskBoardVisibility.private
            ? 'private'
            : 'scope',
        'folder_id': _folderId,
      },
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskBoard>) {
      setState(() => _saving = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskBoard>) {
      setState(() {
        _saving = false;
        _message = 'Board settings saved.';
      });
      widget.onChanged(result.data);
    } else {
      setState(() {
        _saving = false;
        _message = result.message ?? 'Board settings could not be saved.';
      });
    }
  }

  Future<void> _saveFavorite(bool value) async {
    final previous = _favorite;
    setState(() {
      _favorite = value;
      _message = null;
    });
    final result = await widget.api.saveBoardPreference(
      widget.board.id,
      isFavorite: value,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskBoardPreference>) {
      setState(() => _favorite = previous);
      widget.onUnavailable(result.message);
    } else if (result is! TaskApiSuccess<TaskBoardPreference>) {
      setState(() {
        _favorite = previous;
        _message = result.message ?? 'Favorite could not be changed.';
      });
    }
  }

  Future<void> _setArchived(bool archived) async {
    if (_saving) return;
    final action = archived ? 'archive' : 'restore';
    if (!widget.board.can(action)) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.setBoardArchived(
      widget.board.id,
      expectedStructureVersion: widget.board.structureVersion,
      archived: archived,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskStructureMutation<TaskBoard>>) {
      setState(() => _saving = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskStructureMutation<TaskBoard>>) {
      setState(() => _saving = false);
      widget.onChanged(result.data.data);
    } else {
      setState(() {
        _saving = false;
        _message = result.message ?? 'The board could not be changed.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final canUpdate = widget.board.can('update');
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('General', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            'Board-wide names and structure are separate from each saved view’s hidden columns, order, and widths.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 18),
          TextField(
            key: const ValueKey('task-board-name'),
            controller: _name,
            enabled: canUpdate && !_saving,
            decoration: const InputDecoration(
              labelText: 'Board name',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _description,
            enabled: canUpdate && !_saving,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Description',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<TaskBoardVisibility>(
            initialValue: _visibility,
            decoration: const InputDecoration(
              labelText: 'Visibility',
              border: OutlineInputBorder(),
            ),
            items: const [
              DropdownMenuItem(
                value: TaskBoardVisibility.scope,
                child: Text('Visible in this view'),
              ),
              DropdownMenuItem(
                value: TaskBoardVisibility.private,
                child: Text('Private — invited members'),
              ),
            ],
            onChanged: canUpdate && !_saving
                ? (value) => setState(() => _visibility = value!)
                : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String?>(
            initialValue: _folderId,
            decoration: const InputDecoration(
              labelText: 'Folder',
              border: OutlineInputBorder(),
            ),
            items: <DropdownMenuItem<String?>>[
              const DropdownMenuItem<String?>(
                value: null,
                child: Text('No folder'),
              ),
              for (final folder in widget.folders.where(
                (item) => !item.archived,
              ))
                DropdownMenuItem<String?>(
                  value: folder.id,
                  child: Text(folder.name),
                ),
            ],
            onChanged: canUpdate && !_saving
                ? (value) => setState(() => _folderId = value)
                : null,
          ),
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: const Text('Favorite board'),
            subtitle: const Text(
              'Keep it near the top of your board directory.',
            ),
            value: _favorite,
            onChanged: _saving ? null : _saveFavorite,
          ),
          if (canUpdate) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save changes'),
              ),
            ),
          ],
          if (_message case final message?) ...[
            const SizedBox(height: 10),
            Text(
              message,
              style: TextStyle(
                color: message.endsWith('saved.')
                    ? Theme.of(context).colorScheme.primary
                    : Theme.of(context).colorScheme.error,
              ),
            ),
          ],
          if (widget.board.can('archive') || widget.board.can('restore')) ...[
            const SizedBox(height: 28),
            const Divider(),
            const SizedBox(height: 12),
            Text(
              widget.board.can('restore') ? 'Archived board' : 'Archive',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            Text(
              widget.board.can('restore')
                  ? 'Restore this board to ordinary board lists.'
                  : 'The board leaves ordinary views but remains available to authorized history and export.',
            ),
            const SizedBox(height: 10),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                onPressed: _saving
                    ? null
                    : () => _setArchived(widget.board.can('archive')),
                icon: Icon(
                  widget.board.can('restore')
                      ? Icons.unarchive_outlined
                      : Icons.archive_outlined,
                ),
                label: Text(
                  widget.board.can('restore')
                      ? 'Restore board'
                      : 'Archive board',
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
