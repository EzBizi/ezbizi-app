import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/files.dart';
import 'task_api.dart';
import 'task_models.dart';
import 'task_updates.dart';

typedef TaskParticipantSearch =
    Future<TaskApiResult<List<TaskParticipant>>> Function(String query);

class TaskParticipantPicker extends StatefulWidget {
  const TaskParticipantPicker({
    super.key,
    required this.selectedIds,
    required this.onChanged,
    required this.search,
    this.label = 'Find people',
    this.privacyEpoch = 0,
    this.onUnavailable,
    this.enabled = true,
  });

  final List<String> selectedIds;
  final ValueChanged<List<String>> onChanged;
  final TaskParticipantSearch search;
  final String label;
  final int privacyEpoch;
  final ValueChanged<String?>? onUnavailable;
  final bool enabled;

  @override
  State<TaskParticipantPicker> createState() => _TaskParticipantPickerState();
}

class _TaskParticipantPickerState extends State<TaskParticipantPicker> {
  Timer? _debounce;
  int _searchGeneration = 0;
  bool _loading = false;
  List<TaskParticipant> _candidates = const [];
  String? _message;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  @override
  void didUpdateWidget(covariant TaskParticipantPicker oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.privacyEpoch != widget.privacyEpoch) {
      _searchGeneration++;
      _debounce?.cancel();
      _candidates = const [];
      _message = null;
      _loading = false;
    }
  }

  void _queueSearch(String query) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => _search(query));
  }

  Future<void> _search(String query) async {
    final generation = ++_searchGeneration;
    final privacy = widget.privacyEpoch;
    if (mounted) {
      setState(() {
        _loading = true;
        _message = null;
      });
    }
    final result = await widget.search(query);
    if (!mounted ||
        generation != _searchGeneration ||
        privacy != widget.privacyEpoch) {
      return;
    }
    if (result is TaskApiUnavailable<List<TaskParticipant>>) {
      setState(() {
        _loading = false;
        _candidates = const [];
      });
      widget.onUnavailable?.call(result.message);
      return;
    }
    if (result is TaskApiSuccess<List<TaskParticipant>>) {
      setState(() {
        _loading = false;
        _candidates = result.data;
      });
      return;
    }
    setState(() {
      _loading = false;
      _candidates = const [];
      _message = result.message ?? 'People could not be loaded.';
    });
  }

  @override
  void dispose() {
    _searchGeneration++;
    _debounce?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final selected = widget.selectedIds.toSet();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          enabled: widget.enabled,
          onChanged: _queueSearch,
          decoration: InputDecoration(
            labelText: widget.label,
            prefixIcon: const Icon(Icons.search),
            suffixIcon: _loading
                ? const Padding(
                    padding: EdgeInsets.all(14),
                    child: SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : null,
          ),
        ),
        if (_message case final message?) ...[
          const SizedBox(height: 8),
          Text(
            message,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
        for (final candidate in _candidates)
          CheckboxListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            value: selected.contains(candidate.id),
            title: Text(candidate.name),
            subtitle: Text(candidate.email),
            onChanged: !widget.enabled
                ? null
                : (checked) {
                    final next = {...selected};
                    checked == true
                        ? next.add(candidate.id)
                        : next.remove(candidate.id);
                    widget.onChanged(next.toList(growable: false));
                  },
          ),
      ],
    );
  }
}

class TaskCollaborationPanel extends StatefulWidget {
  const TaskCollaborationPanel({
    super.key,
    required this.snapshot,
    required this.api,
    required this.privacyEpoch,
    required this.onChanged,
    required this.onUnavailable,
    this.onDraftChanged,
    this.pickFile,
  });

  final TaskDetailSnapshot snapshot;
  final TaskApi api;
  final int privacyEpoch;
  final ValueChanged<TaskDetailSnapshot> onChanged;
  final ValueChanged<String?> onUnavailable;
  final TaskDraftChanged? onDraftChanged;
  final Future<PickedBytes?> Function()? pickFile;

  @override
  State<TaskCollaborationPanel> createState() => _TaskCollaborationPanelState();
}

class _TaskCollaborationPanelState extends State<TaskCollaborationPanel> {
  late TaskDetailSnapshot _snapshot = widget.snapshot;
  List<String> _mentions = const [];
  List<TaskAttachmentCandidate> _candidates = const [];
  bool _uploading = false;
  String? _message;

  bool _current(int privacy, String taskId) =>
      mounted &&
      privacy == widget.privacyEpoch &&
      taskId == widget.snapshot.task.id;

  @override
  void didUpdateWidget(covariant TaskCollaborationPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.snapshot.task.id != widget.snapshot.task.id ||
        oldWidget.privacyEpoch != widget.privacyEpoch) {
      _mentions = const [];
      _candidates = const [];
      _uploading = false;
      _message = null;
    }
    _snapshot = widget.snapshot;
  }

  void _replace(TaskDetailSnapshot snapshot) {
    if (!mounted) return;
    setState(() => _snapshot = snapshot);
    widget.onChanged(snapshot);
  }

  void _unavailable(String? message) {
    if (mounted) {
      setState(() {
        _snapshot = _snapshot.copyWith(updates: const [], activities: const []);
        _mentions = const [];
        _candidates = const [];
        _message = null;
      });
    }
    widget.onUnavailable(message);
  }

  Future<void> _refresh(int privacy, String taskId) async {
    final result = await widget.api.taskDetail(_snapshot.task.boardId, taskId);
    if (!_current(privacy, taskId)) return;
    if (result is TaskApiUnavailable<TaskDetailSnapshot>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<TaskDetailSnapshot>) {
      _replace(result.data);
    } else {
      setState(
        () =>
            _message = result.message ?? 'Task details could not be refreshed.',
      );
    }
  }

  Future<TaskComposerResult> _post(TaskUpdateDraft draft) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.createTaskUpdate(
      taskId,
      body: draft.body,
      mentionUserIds: draft.mentionUserIds,
      attachmentCandidateIds: draft.attachmentCandidateIds,
      idempotencyKey: draft.idempotencyKey,
    );
    if (!_current(privacy, taskId)) {
      return const TaskComposerTransientFailure(
        'This task is no longer available.',
      );
    }
    if (result is TaskApiUnavailable<TaskUpdate>) {
      _unavailable(result.message);
      return const TaskComposerTransientFailure(
        'This task is no longer available.',
      );
    }
    if (result is! TaskApiSuccess<TaskUpdate>) {
      return TaskComposerTransientFailure(
        result.message ?? 'The update could not be saved. Try again.',
      );
    }
    setState(() {
      _mentions = const [];
      _candidates = const [];
    });
    _replace(_snapshot.copyWith(updates: [..._snapshot.updates, result.data]));
    await _refresh(privacy, taskId);
    return const TaskComposerSaved();
  }

  Future<void> _pickFile() async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final picked = await (widget.pickFile?.call() ?? Files.pickBytes());
    if (picked == null || !_current(privacy, taskId)) return;
    setState(() {
      _uploading = true;
      _message = null;
    });
    final result = await widget.api.uploadUpdateAttachment(
      taskId,
      filename: picked.name,
      bytes: picked.bytes,
    );
    if (!_current(privacy, taskId)) return;
    if (result is TaskApiUnavailable<TaskAttachmentCandidate>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<TaskAttachmentCandidate>) {
      setState(() {
        _uploading = false;
        _candidates = [..._candidates, result.data];
      });
    } else {
      setState(() {
        _uploading = false;
        _message = result.message ?? 'That file could not be staged.';
      });
    }
  }

  Future<String?> _edit(TaskUpdate update, String body) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.updateTaskUpdate(
      taskId,
      update.id,
      expectedUpdateVersion: update.version,
      body: body,
      mentionUserIds: update.mentionUserIds,
    );
    if (!_current(privacy, taskId)) return 'This task is no longer available.';
    if (result is TaskApiUnavailable<TaskUpdate>) {
      _unavailable(result.message);
      return 'This task is no longer available.';
    }
    if (result is! TaskApiSuccess<TaskUpdate>) {
      return result.message ?? 'The update could not be saved.';
    }
    _replace(
      _snapshot.copyWith(
        updates: [
          for (final item in _snapshot.updates)
            item.id == update.id ? result.data : item,
        ],
      ),
    );
    await _refresh(privacy, taskId);
    return null;
  }

  Future<String?> _archive(TaskUpdate update) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.archiveTaskUpdate(
      taskId,
      update.id,
      expectedUpdateVersion: update.version,
    );
    if (!_current(privacy, taskId)) return 'This task is no longer available.';
    if (result is TaskApiUnavailable<TaskUpdate>) {
      _unavailable(result.message);
      return 'This task is no longer available.';
    }
    if (result is! TaskApiSuccess<TaskUpdate>) {
      return result.message ?? 'The update could not be removed.';
    }
    _replace(
      _snapshot.copyWith(
        updates: _snapshot.updates
            .where((item) => item.id != update.id)
            .toList(growable: false),
      ),
    );
    await _refresh(privacy, taskId);
    return null;
  }

  Future<void> _download(TaskAttachment attachment) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.downloadAttachment(attachment.id);
    if (!_current(privacy, taskId)) return;
    if (result is TaskApiUnavailable<List<int>>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<List<int>>) {
      Files.download(attachment.originalName, result.data);
    } else {
      setState(
        () => _message = result.message ?? 'That file could not be downloaded.',
      );
    }
  }

  Future<void> _saveCollaborators(List<String> ids) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.updateCollaborators(
      taskId,
      expectedTaskVersion: _snapshot.task.version,
      userIds: ids,
    );
    if (!_current(privacy, taskId)) return;
    if (result is TaskApiUnavailable<TaskRow>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<TaskRow>) {
      _replace(_snapshot.copyWith(task: result.data));
      await _refresh(privacy, taskId);
    } else {
      setState(
        () =>
            _message = result.message ?? 'Collaborators could not be changed.',
      );
    }
  }

  Future<void> _setWatch(TaskWatchChoice choice) async {
    final privacy = widget.privacyEpoch;
    final taskId = _snapshot.task.id;
    final result = await widget.api.setWatchPreference(taskId, choice);
    if (!_current(privacy, taskId)) return;
    if (result is TaskApiUnavailable<TaskWatchMutation>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<TaskWatchMutation>) {
      await _refresh(privacy, taskId);
    } else {
      setState(
        () => _message =
            result.message ?? 'Your notification choice could not be saved.',
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final task = _snapshot.task;
    final descriptionHtml = task.description ?? '';
    final descriptionPlain = task.descriptionPlain ?? '';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'Description',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 8),
        descriptionHtml.isEmpty && descriptionPlain.isEmpty
            ? const Text('No description yet.')
            : TaskFormattedText(
                html: descriptionHtml,
                plainFallback: descriptionPlain,
                onUnsafeLink: (message) => setState(() => _message = message),
              ),
        const SizedBox(height: 24),
        Row(
          children: [
            const Expanded(
              child: Text(
                'Watching',
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            if (_snapshot.watch.mandatory)
              const Text('Owner · always watching')
            else if (_snapshot.can('watch') || _snapshot.can('mute'))
              SegmentedButton<TaskWatchChoice>(
                emptySelectionAllowed: true,
                segments: [
                  if (_snapshot.can('watch'))
                    const ButtonSegment(
                      value: TaskWatchChoice.watched,
                      label: Text('Watch'),
                    ),
                  if (_snapshot.can('mute'))
                    const ButtonSegment(
                      value: TaskWatchChoice.muted,
                      label: Text('Mute'),
                    ),
                ],
                selected:
                    _snapshot.watch.preference == TaskWatchChoice.automatic
                    ? const <TaskWatchChoice>{}
                    : <TaskWatchChoice>{_snapshot.watch.preference},
                onSelectionChanged: (choices) {
                  if (choices.isNotEmpty) _setWatch(choices.single);
                },
              ),
            if (_snapshot.can('clear_watch'))
              TextButton(
                onPressed: () => _setWatch(TaskWatchChoice.automatic),
                child: const Text('Use automatic'),
              ),
          ],
        ),
        if (_snapshot.can('manage_collaborators')) ...[
          const SizedBox(height: 24),
          Text(
            'Collaborators (${task.collaboratorUserIds.length})',
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          TaskParticipantPicker(
            key: ValueKey('collaborators:${task.id}:${widget.privacyEpoch}'),
            selectedIds: task.collaboratorUserIds,
            onChanged: _saveCollaborators,
            search: (query) => widget.api.participants(
              task.boardId,
              purpose: TaskParticipantPurpose.collaborator,
              search: query,
            ),
            label: 'Find collaborators',
            privacyEpoch: widget.privacyEpoch,
            onUnavailable: _unavailable,
          ),
        ],
        const SizedBox(height: 24),
        const Text(
          'Conversation',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 8),
        if (_snapshot.updates.isEmpty) const Text('No updates yet.'),
        for (final update in _snapshot.updates)
          TaskUpdateCard(
            key: ValueKey(update.id),
            update: update,
            onEdit: (body) => _edit(update, body),
            onArchive: () => _archive(update),
            onDownload: _download,
            onUnsafeLink: (message) => setState(() => _message = message),
          ),
        if (_snapshot.can('create_update'))
          TaskUpdateComposer(
            key: ValueKey('composer:${task.id}:${widget.privacyEpoch}'),
            taskId: task.id,
            onSubmit: _post,
            onDraftChanged: widget.onDraftChanged,
            mentionUserIds: _mentions,
            attachmentCandidates: _candidates,
            uploading: _uploading,
            onPickFile: _pickFile,
            onRemoveAttachment: (id) => setState(
              () => _candidates = _candidates
                  .where((candidate) => candidate.id != id)
                  .toList(growable: false),
            ),
            onCancelExtras: () => setState(() {
              _mentions = const [];
              _candidates = const [];
            }),
            mentions: ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text(
                _mentions.isEmpty
                    ? 'Mention people'
                    : '${_mentions.length} mentioned',
              ),
              children: [
                TaskParticipantPicker(
                  selectedIds: _mentions,
                  onChanged: (ids) => setState(() => _mentions = ids),
                  search: (query) => widget.api.participants(
                    task.boardId,
                    purpose: TaskParticipantPurpose.mention,
                    search: query,
                  ),
                  label: 'Find people to mention',
                  privacyEpoch: widget.privacyEpoch,
                  onUnavailable: _unavailable,
                ),
              ],
            ),
          ),
        if (_snapshot.activities.isNotEmpty) ...[
          const SizedBox(height: 24),
          ExpansionTile(
            tilePadding: EdgeInsets.zero,
            title: Text('Activity (${_snapshot.activities.length})'),
            children: [
              for (final activity in _snapshot.activities)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.history),
                  title: Text(activity.summary),
                ),
            ],
          ),
        ],
        if (_message case final message?) ...[
          const SizedBox(height: 8),
          Text(
            message,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
      ],
    );
  }
}
