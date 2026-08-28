import 'package:flutter/material.dart';

import '../../widgets/async_body.dart';
import 'name_entry_panel.dart';
import 'task_api.dart';
import 'task_models.dart';

class TaskFolderAdministration extends StatefulWidget {
  const TaskFolderAdministration({
    super.key,
    required this.api,
    required this.directoryActions,
    required this.onChanged,
    required this.onUnavailable,
  });

  final TaskApi api;
  final List<String> directoryActions;
  final ValueChanged<List<TaskFolder>> onChanged;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskFolderAdministration> createState() =>
      _TaskFolderAdministrationState();
}

class _TaskFolderAdministrationState extends State<TaskFolderAdministration> {
  bool _loading = true;
  bool _saving = false;
  List<TaskFolder> _active = const [];
  List<TaskFolder> _archived = const [];
  String? _message;
  NameEntryRequest? _nameRequest;

  bool get _canCreate => widget.directoryActions.contains('create_folder');
  bool get _canViewArchived =>
      widget.directoryActions.contains('view_archived');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _message = null;
    });
    final active = await widget.api.folders();
    if (!mounted) return;
    if (active is TaskApiUnavailable<List<TaskFolder>>) {
      _unavailable(active.message);
      return;
    }
    TaskApiResult<List<TaskFolder>>? archived;
    if (_canViewArchived) archived = await widget.api.folders(archived: true);
    if (!mounted) return;
    if (archived is TaskApiUnavailable<List<TaskFolder>>) {
      _unavailable(archived.message);
      return;
    }
    setState(() {
      _loading = false;
      if (active is TaskApiSuccess<List<TaskFolder>>) {
        _active = active.data;
      } else {
        _message = active.message ?? 'Folders could not be loaded.';
      }
      if (archived is TaskApiSuccess<List<TaskFolder>>) {
        _archived = archived.data;
      } else if (archived != null && _message == null) {
        _message = archived.message ?? 'Archived folders could not be loaded.';
      }
    });
    widget.onChanged(List<TaskFolder>.unmodifiable(_active));
  }

  void _unavailable(String? message) {
    setState(() {
      _loading = false;
      _active = const [];
      _archived = const [];
    });
    widget.onUnavailable(message);
  }

  Future<String?> _nameDialog(String title, {String initial = ''}) {
    final request = NameEntryRequest(
      title: title,
      label: 'Folder name',
      submitLabel: 'Save',
      initial: initial,
    );
    setState(() => _nameRequest = request);
    return request.future.whenComplete(() {
      if (mounted) setState(() => _nameRequest = null);
    });
  }

  Future<void> _create() async {
    if (!_canCreate || _saving) return;
    final name = await _nameDialog('New folder');
    if (name == null || !mounted) return;
    await _apply(widget.api.createFolder(name));
  }

  Future<void> _rename(TaskFolder folder) async {
    if (!folder.can('update') || _saving) return;
    final name = await _nameDialog('Rename folder', initial: folder.name);
    if (name == null || !mounted) return;
    await _apply(widget.api.updateFolder(folder.id, name: name));
  }

  Future<void> _setArchived(TaskFolder folder) async {
    final action = folder.archived ? 'restore' : 'archive';
    if (!folder.can(action) || _saving) return;
    await _apply(
      widget.api.setFolderArchived(folder.id, archived: !folder.archived),
    );
  }

  Future<void> _apply(Future<TaskApiResult<TaskFolder>> request) async {
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await request;
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskFolder>) {
      _unavailable(result.message);
      return;
    }
    if (result is TaskApiSuccess<TaskFolder>) {
      setState(() {
        _saving = false;
        _active = _replaceFolder(
          _active,
          result.data,
          include: !result.data.archived,
        );
        _archived = _replaceFolder(
          _archived,
          result.data,
          include: result.data.archived,
        );
      });
      widget.onChanged(List<TaskFolder>.unmodifiable(_active));
      return;
    }
    setState(() {
      _saving = false;
      _message = result.message ?? 'The folder could not be changed.';
    });
  }

  List<TaskFolder> _replaceFolder(
    List<TaskFolder> source,
    TaskFolder folder, {
    required bool include,
  }) {
    final result = source.where((item) => item.id != folder.id).toList();
    if (include) result.add(folder);
    result.sort((a, b) => a.position.compareTo(b.position));
    return result;
  }

  @override
  Widget build(BuildContext context) {
    final request = _nameRequest;
    if (request != null) {
      return NameEntryPanel(request: request);
    }
    return AsyncBody(
    loading: _loading,
    error: null,
    onRetry: _load,
    child: ListView(
      padding: EdgeInsets.zero,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Folders',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (_canCreate)
              FilledButton.icon(
                onPressed: _saving ? null : _create,
                icon: const Icon(Icons.create_new_folder_outlined),
                label: const Text('New folder'),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Folders organize boards only in the exact place currently in view.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        for (final folder in _active) _folderTile(folder),
        if (_canViewArchived && _archived.isNotEmpty) ...[
          const SizedBox(height: 18),
          Text('Archived', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 6),
          for (final folder in _archived) _folderTile(folder),
        ],
        if (_message case final message?) ...[
          const SizedBox(height: 8),
          Text(message),
        ],
      ],
    ),
  );
  }

  Widget _folderTile(TaskFolder folder) => Card(
    child: ListTile(
      leading: Icon(folder.archived ? Icons.folder_off_outlined : Icons.folder),
      title: Text(folder.name),
      trailing: Wrap(
        children: [
          if (folder.can('update'))
            IconButton(
              tooltip: 'Rename ${folder.name}',
              onPressed: _saving ? null : () => _rename(folder),
              icon: const Icon(Icons.edit_outlined),
            ),
          if (folder.can('archive'))
            IconButton(
              tooltip: 'Archive ${folder.name}',
              onPressed: _saving ? null : () => _setArchived(folder),
              icon: const Icon(Icons.archive_outlined),
            ),
          if (folder.can('restore'))
            IconButton(
              tooltip: 'Restore ${folder.name}',
              onPressed: _saving ? null : () => _setArchived(folder),
              icon: const Icon(Icons.unarchive_outlined),
            ),
        ],
      ),
    ),
  );
}
