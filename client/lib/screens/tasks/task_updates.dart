import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:url_launcher/url_launcher.dart';

import 'task_models.dart';

sealed class TaskComposerResult {
  const TaskComposerResult();
}

final class TaskComposerSaved extends TaskComposerResult {
  const TaskComposerSaved();
}

final class TaskComposerTransientFailure extends TaskComposerResult {
  const TaskComposerTransientFailure(this.message);
  final String message;
}

final class TaskUpdateDraft {
  const TaskUpdateDraft({
    required this.body,
    required this.mentionUserIds,
    required this.attachmentCandidateIds,
    required this.idempotencyKey,
  });
  final String body;
  final List<String> mentionUserIds;
  final List<String> attachmentCandidateIds;
  final String idempotencyKey;
}

Uri? safeTaskExternalUri(String? raw) {
  if (raw == null) return null;
  final uri = Uri.tryParse(raw);
  if (uri == null ||
      uri.scheme != 'https' ||
      uri.host.isEmpty ||
      uri.userInfo.isNotEmpty) {
    return null;
  }
  return uri;
}

class TaskFormattedText extends StatelessWidget {
  const TaskFormattedText({
    super.key,
    required this.html,
    required this.plainFallback,
    this.launchExternal,
    this.onUnsafeLink,
  });
  final String html;
  final String plainFallback;
  final Future<bool> Function(Uri uri)? launchExternal;
  final ValueChanged<String>? onUnsafeLink;

  Future<void> _open(String? raw) async {
    final uri = safeTaskExternalUri(raw);
    if (uri == null) {
      onUnsafeLink?.call('Only secure HTTPS links can be opened.');
      return;
    }
    final opened =
        await (launchExternal?.call(uri) ??
            launchUrl(uri, mode: LaunchMode.externalApplication));
    if (!opened) onUnsafeLink?.call('That link could not be opened.');
  }

  @override
  Widget build(BuildContext context) => html.trim().isEmpty
      ? Text(plainFallback)
      : Html(
          data: html,
          onLinkTap: (url, _, _) => _open(url),
          style: <String, Style>{
            'body': Style(margin: Margins.zero, padding: HtmlPaddings.zero),
          },
        );
}

class TaskUpdateComposer extends StatefulWidget {
  const TaskUpdateComposer({
    super.key,
    required this.taskId,
    required this.onSubmit,
    this.onDraftChanged,
    this.accessRevoked = false,
    this.mentionUserIds = const <String>[],
    this.attachmentCandidates = const <TaskAttachmentCandidate>[],
    this.onPickFile,
    this.onRemoveAttachment,
    this.onCancelExtras,
    this.mentions,
    this.uploading = false,
    this.idempotencyKeyFactory,
  });
  final String taskId;
  final Future<TaskComposerResult> Function(TaskUpdateDraft draft) onSubmit;
  final TaskDraftChanged? onDraftChanged;
  final bool accessRevoked;
  final List<String> mentionUserIds;
  final List<TaskAttachmentCandidate> attachmentCandidates;
  final VoidCallback? onPickFile;
  final ValueChanged<String>? onRemoveAttachment;
  final VoidCallback? onCancelExtras;
  final Widget? mentions;
  final bool uploading;
  final String Function()? idempotencyKeyFactory;

  @override
  State<TaskUpdateComposer> createState() => _TaskUpdateComposerState();
}

class _TaskUpdateComposerState extends State<TaskUpdateComposer> {
  final TextEditingController _controller = TextEditingController();
  bool _saving = false;
  bool _dirty = false;
  String? _error;
  String? _idempotencyKey;
  String get _draftId => 'task:${widget.taskId}:update-composer';
  bool get _hasDraft =>
      _controller.text.trim().isNotEmpty ||
      widget.mentionUserIds.isNotEmpty ||
      widget.attachmentCandidates.isNotEmpty;

  @override
  void initState() {
    super.initState();
    _controller.addListener(_changed);
    _dirty = _hasDraft;
    if (_dirty) _idempotencyKey = _newIdempotencyKey();
  }

  @override
  void didUpdateWidget(covariant TaskUpdateComposer oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.taskId != widget.taskId ||
        (!oldWidget.accessRevoked && widget.accessRevoked)) {
      _clear(oldDraftId: 'task:${oldWidget.taskId}:update-composer');
    } else {
      _syncDirty();
    }
  }

  void _syncDirty() {
    final dirty = _hasDraft;
    if (dirty == _dirty) return;
    if (dirty) _idempotencyKey ??= _newIdempotencyKey();
    _dirty = dirty;
    widget.onDraftChanged?.call(_draftId, dirty);
  }

  void _changed() {
    final wasDirty = _dirty;
    _syncDirty();
    if (mounted && (wasDirty != _dirty || _error != null)) {
      setState(() => _error = null);
    }
  }

  void _clear({String? oldDraftId}) {
    if (_dirty) widget.onDraftChanged?.call(oldDraftId ?? _draftId, false);
    _dirty = false;
    _idempotencyKey = null;
    _controller.clear();
    widget.onCancelExtras?.call();
    _error = null;
  }

  Future<void> _submit() async {
    if (_saving || !_hasDraft) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final result = await widget.onSubmit(
      TaskUpdateDraft(
        body: _controller.text.trim(),
        mentionUserIds: widget.mentionUserIds,
        attachmentCandidateIds: widget.attachmentCandidates
            .map((candidate) => candidate.id)
            .toList(growable: false),
        idempotencyKey: _idempotencyKey ??= _newIdempotencyKey(),
      ),
    );
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (result is TaskComposerSaved) {
        _clear();
      } else if (result is TaskComposerTransientFailure) {
        _error = result.message;
      }
    });
  }

  String _newIdempotencyKey() =>
      widget.idempotencyKeyFactory?.call() ??
      '${widget.taskId}-${DateTime.now().microsecondsSinceEpoch}';

  @override
  void dispose() {
    if (_dirty) widget.onDraftChanged?.call(_draftId, false);
    _controller
      ..removeListener(_changed)
      ..clear()
      ..dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.accessRevoked) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _controller,
          enabled: !_saving,
          minLines: 3,
          maxLines: 8,
          decoration: const InputDecoration(
            labelText: 'Write an update',
            alignLabelWithHint: true,
          ),
        ),
        ?widget.mentions,
        for (final candidate in widget.attachmentCandidates)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.attach_file),
            title: Text(candidate.originalName),
            subtitle: Text(taskFileSize(candidate.sizeBytes)),
            trailing: IconButton(
              tooltip: 'Remove staged file',
              onPressed: _saving
                  ? null
                  : () => widget.onRemoveAttachment?.call(candidate.id),
              icon: const Icon(Icons.close),
            ),
          ),
        if (_error case final error?) ...[
          const SizedBox(height: 8),
          Text(
            error,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
        const SizedBox(height: 8),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: 8,
          children: [
            if (widget.onPickFile != null)
              TextButton.icon(
                onPressed: _saving || widget.uploading
                    ? null
                    : widget.onPickFile,
                icon: const Icon(Icons.attach_file),
                label: Text(widget.uploading ? 'Uploading…' : 'Attach file'),
              ),
            TextButton(
              onPressed: _saving || !_dirty ? null : () => setState(_clear),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: _saving || !_dirty ? null : _submit,
              child: Text(_saving ? 'Posting…' : 'Post update'),
            ),
          ],
        ),
      ],
    );
  }
}

class TaskUpdateCard extends StatefulWidget {
  const TaskUpdateCard({
    super.key,
    required this.update,
    required this.onEdit,
    required this.onArchive,
    required this.onDownload,
    this.onUnsafeLink,
  });
  final TaskUpdate update;
  final Future<String?> Function(String body) onEdit;
  final Future<String?> Function() onArchive;
  final ValueChanged<TaskAttachment> onDownload;
  final ValueChanged<String>? onUnsafeLink;

  @override
  State<TaskUpdateCard> createState() => _TaskUpdateCardState();
}

class _TaskUpdateCardState extends State<TaskUpdateCard> {
  bool _editing = false;
  bool _saving = false;
  late final TextEditingController _editor = TextEditingController(
    text: widget.update.body ?? '',
  );
  String? _error;

  @override
  void didUpdateWidget(covariant TaskUpdateCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!_editing && oldWidget.update.version != widget.update.version) {
      _editor.text = widget.update.body ?? '';
    }
  }

  Future<void> _save() async {
    final body = _editor.text.trim();
    if (_saving || body.isEmpty) return;
    setState(() => _saving = true);
    final error = await widget.onEdit(body);
    if (!mounted) return;
    setState(() {
      _saving = false;
      _error = error;
      if (error == null) _editing = false;
    });
  }

  @override
  void dispose() {
    _editor.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.only(bottom: 12),
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'A teammate',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              if (widget.update.version > 1) const Text('Edited'),
              if (widget.update.can('edit') || widget.update.can('archive'))
                PopupMenuButton<String>(
                  tooltip: 'Update actions',
                  onSelected: (action) async {
                    if (action == 'edit') setState(() => _editing = true);
                    if (action == 'archive') {
                      final error = await widget.onArchive();
                      if (mounted) setState(() => _error = error);
                    }
                  },
                  itemBuilder: (_) => [
                    if (widget.update.can('edit'))
                      const PopupMenuItem(
                        value: 'edit',
                        child: Text('Edit update'),
                      ),
                    if (widget.update.can('archive'))
                      const PopupMenuItem(
                        value: 'archive',
                        child: Text('Remove update'),
                      ),
                  ],
                ),
            ],
          ),
          if (_editing) ...[
            TextField(
              controller: _editor,
              minLines: 2,
              maxLines: 8,
              decoration: const InputDecoration(
                labelText: 'Formatted update (HTML)',
                helperText: 'Formatting is preserved when you save.',
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() => _editing = false),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Save changes'),
                ),
              ],
            ),
          ] else
            TaskFormattedText(
              html: widget.update.body ?? '',
              plainFallback: widget.update.bodyPlain ?? '',
              onUnsafeLink: widget.onUnsafeLink,
            ),
          for (final attachment in widget.update.attachments)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.description_outlined),
              title: Text(attachment.originalName),
              subtitle: Text(taskFileSize(attachment.sizeBytes)),
              trailing: IconButton(
                tooltip: 'Download ${attachment.originalName}',
                onPressed: () => widget.onDownload(attachment),
                icon: const Icon(Icons.download_outlined),
              ),
            ),
          if (widget.update.versions.isNotEmpty)
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text('Edit history (${widget.update.versions.length})'),
              children: [
                for (final version in widget.update.versions)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: TaskFormattedText(
                      html: version.body,
                      plainFallback: version.bodyPlain,
                      onUnsafeLink: (message) =>
                          setState(() => _error = message),
                    ),
                    subtitle: Text('Earlier version ${version.version}'),
                  ),
              ],
            ),
          if (_error case final error?)
            Text(
              error,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
        ],
      ),
    ),
  );
}

String taskFileSize(int bytes) {
  if (bytes < 1024) return '$bytes bytes';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).round()} KB';
  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}
