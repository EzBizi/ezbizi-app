import 'package:flutter/material.dart';

import '../../core/files.dart';
import 'task_api.dart';
import 'task_models.dart';

class TaskTransferFlow extends StatefulWidget {
  const TaskTransferFlow({
    super.key,
    required this.api,
    required this.board,
    required this.activeViewId,
    required this.onUnavailable,
    this.pickFile,
    this.download,
    this.onImported,
  });

  final TaskApi api;
  final TaskBoard board;
  final String activeViewId;
  final ValueChanged<String?> onUnavailable;
  final Future<PickedBytes?> Function()? pickFile;
  final void Function(String filename, List<int> bytes)? download;
  final VoidCallback? onImported;

  @override
  State<TaskTransferFlow> createState() => _TaskTransferFlowState();
}

class _TaskTransferFlowState extends State<TaskTransferFlow> {
  static const _fields = <(String, String)>[
    ('title', 'Task title'),
    ('description', 'Description'),
    ('group', 'Group'),
    ('owner_email', 'Owner email'),
    ('status', 'Status'),
    ('priority', 'Priority'),
    ('start_on', 'Start date'),
    ('due_on', 'Due date'),
    ('source_row', 'Source row'),
    ('parent_source_row', 'Parent source row'),
  ];

  PickedBytes? _file;
  TaskImportPreview? _preview;
  Map<String, String> _mapping = <String, String>{};
  bool _busy = false;
  bool _exporting = false;
  String? _message;
  TaskTransferCancelToken? _cancelToken;

  Future<void> _chooseFile() async {
    if (_busy) return;
    final file =
        await (widget.pickFile?.call() ??
            Files.pickBytes(accept: '.csv,.xlsx'));
    if (!mounted || file == null) return;
    setState(() {
      _file = file;
      _preview = null;
      _mapping = <String, String>{};
      _busy = true;
      _message = null;
    });
    final result = await widget.api.previewImport(
      widget.board.id,
      activeViewId: widget.activeViewId,
      filename: file.name,
      bytes: file.bytes,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskImportPreview>) {
      setState(() => _busy = false);
      widget.onUnavailable(result.message);
      return;
    }
    if (result is TaskApiSuccess<TaskImportPreview>) {
      setState(() {
        _busy = false;
        _preview = result.data;
        _mapping = _suggestMapping(result.data.headings);
      });
      return;
    }
    setState(() {
      _busy = false;
      _message = result.message ?? 'The file could not be inspected.';
    });
  }

  Map<String, String> _suggestMapping(List<String> headings) {
    final normalized = <String, String>{
      for (final heading in headings) _normalize(heading): heading,
    };
    String? find(Iterable<String> names) {
      for (final name in names) {
        final heading = normalized[_normalize(name)];
        if (heading != null) return heading;
      }
      return null;
    }

    final candidates = <String, String?>{
      'title': find(const ['task', 'task name', 'title', 'name']),
      'description': find(const ['description', 'notes']),
      'group': find(const ['group', 'section']),
      'owner_email': find(const [
        'owner email',
        'owner_email',
        'assignee email',
      ]),
      'status': find(const ['status']),
      'priority': find(const ['priority']),
      'start_on': find(const ['start date', 'start_on']),
      'due_on': find(const ['due date', 'due_on']),
      'source_row': find(const ['source row', 'source_row']),
      'parent_source_row': find(const [
        'parent source row',
        'parent_source_row',
      ]),
    };
    return <String, String>{
      for (final entry in candidates.entries)
        if (entry.value != null) entry.key: entry.value!,
    };
  }

  String _normalize(String value) => value
      .trim()
      .toLowerCase()
      .replaceAll(RegExp(r'[_\-]+'), ' ')
      .replaceAll(RegExp(r'\s+'), ' ');

  Future<void> _runPreview() async {
    final file = _file;
    if (_busy || file == null || !_mapping.containsKey('title')) return;
    setState(() {
      _busy = true;
      _message = null;
    });
    final result = await widget.api.previewImport(
      widget.board.id,
      activeViewId: widget.activeViewId,
      filename: file.name,
      bytes: file.bytes,
      mapping: TaskImportMapping(
        fields: Map<String, String>.unmodifiable(_mapping),
        decisions: const <String, Object?>{},
      ),
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskImportPreview>) {
      setState(() => _busy = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskImportPreview>) {
      setState(() {
        _busy = false;
        _preview = result.data;
      });
    } else {
      setState(() {
        _busy = false;
        _message = result.message ?? 'The import could not be previewed.';
      });
    }
  }

  Future<void> _commit() async {
    final preview = _preview;
    final token = preview?.sessionToken;
    if (_busy ||
        preview == null ||
        preview.requiresMapping ||
        preview.hasBlockingErrors ||
        token == null) {
      return;
    }
    setState(() {
      _busy = true;
      _message = null;
    });
    final result = await widget.api.commitImport(
      widget.board.id,
      activeViewId: widget.activeViewId,
      sessionToken: token,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskImportCommit>) {
      setState(() => _busy = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskImportCommit>) {
      setState(() {
        _busy = false;
        _message = '${result.data.created} tasks imported.';
        _preview = null;
        _file = null;
      });
      widget.onImported?.call();
    } else {
      setState(() {
        _busy = false;
        _message = result.message ?? 'The tasks could not be imported.';
      });
    }
  }

  Future<void> _export(TaskExportFormat format) async {
    if (_exporting) return;
    final token = TaskTransferCancelToken();
    setState(() {
      _exporting = true;
      _cancelToken = token;
      _message = null;
    });
    final result = await widget.api.exportBoard(
      widget.board.id,
      format: format,
      activeViewId: widget.activeViewId,
      includeArchived: format == TaskExportFormat.json,
      cancelToken: token,
    );
    if (!mounted || token != _cancelToken) return;
    if (result is TaskApiUnavailable<List<int>>) {
      setState(() {
        _exporting = false;
        _cancelToken = null;
      });
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<List<int>>) {
      final filename = '${widget.board.name}.${format.name}';
      (widget.download ?? Files.download)(filename, result.data);
      setState(() {
        _exporting = false;
        _cancelToken = null;
        _message = '$filename is ready.';
      });
    } else {
      setState(() {
        _exporting = false;
        _cancelToken = null;
        _message = result.message ?? 'The export could not be created.';
      });
    }
  }

  void _cancelExport() {
    _cancelToken?.cancel();
    setState(() {
      _exporting = false;
      _cancelToken = null;
      _message = 'Export cancelled.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final canImport = widget.board.can('import');
    final canExport = widget.board.can('export');
    final canManifest = widget.board.can('archive_manifest');
    if (!canImport && !canExport && !canManifest) {
      return const SizedBox.shrink();
    }
    return ListView(
      children: [
        Text('Transfer tasks', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Files are reconciled against this board before anything is created.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (canImport) ...[
          const SizedBox(height: 20),
          Text('Import', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              onPressed: _busy ? null : _chooseFile,
              icon: const Icon(Icons.upload_file_outlined),
              label: Text(
                _busy && _file == null ? 'Inspecting…' : 'Choose CSV or XLSX',
              ),
            ),
          ),
          if (_file case final file?) ...[
            const SizedBox(height: 8),
            Text(file.name),
          ],
          if (_preview case final preview?) ...[
            const SizedBox(height: 12),
            ..._fields
                .where(
                  (field) =>
                      field.$1 == 'title' || _mapping.containsKey(field.$1),
                )
                .map((field) => _mappingField(field, preview)),
            if (_fields.any(
              (field) => field.$1 != 'title' && !_mapping.containsKey(field.$1),
            ))
              ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: const Text('More mappings'),
                children: _fields
                    .where(
                      (field) =>
                          field.$1 != 'title' &&
                          !_mapping.containsKey(field.$1),
                    )
                    .map((field) => _mappingField(field, preview))
                    .toList(growable: false),
              ),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _busy || !_mapping.containsKey('title')
                    ? null
                    : _runPreview,
                child: Text(_busy ? 'Checking…' : 'Preview import'),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              '${preview.counts.rows} rows · ${preview.counts.ready} ready · ${preview.counts.errors} errors',
            ),
            for (final issue in preview.reconciliation)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  Icons.error_outline,
                  color: Theme.of(context).colorScheme.error,
                ),
                title: Text(issue.message),
                subtitle: Text(
                  [
                    if (issue.row != null) 'Row ${issue.row}',
                    if (issue.field != null) issue.field!,
                  ].join(' · '),
                ),
              ),
            for (final unsupported in preview.unsupportedFields)
              Text('${unsupported.heading}: not mapped'),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                onPressed:
                    _busy ||
                        preview.requiresMapping ||
                        preview.hasBlockingErrors ||
                        preview.sessionToken == null
                    ? null
                    : _commit,
                icon: const Icon(Icons.playlist_add_check),
                label: Text('Import ${preview.counts.ready} tasks'),
              ),
            ),
          ],
        ],
        if (canExport || canManifest) ...[
          const SizedBox(height: 24),
          Text('Export', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          const Text(
            'Exports are generated for the current view. A JSON archive manifest includes archived records, but not attachment file bytes.',
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (canExport)
                OutlinedButton(
                  onPressed: _exporting
                      ? null
                      : () => _export(TaskExportFormat.csv),
                  child: const Text('Export CSV'),
                ),
              if (canExport)
                OutlinedButton(
                  onPressed: _exporting
                      ? null
                      : () => _export(TaskExportFormat.xlsx),
                  child: const Text('Export XLSX'),
                ),
              if (canManifest)
                OutlinedButton(
                  onPressed: _exporting
                      ? null
                      : () => _export(TaskExportFormat.json),
                  child: const Text('Archive manifest'),
                ),
            ],
          ),
          if (_exporting) ...[
            const SizedBox(height: 12),
            const LinearProgressIndicator(),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: _cancelExport,
                child: const Text('Cancel'),
              ),
            ),
          ],
        ],
        if (_message case final message?) ...[
          const SizedBox(height: 12),
          Text(message),
        ],
      ],
    );
  }

  Widget _mappingField((String, String) field, TaskImportPreview preview) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: DropdownButtonFormField<String>(
          key: ValueKey('task-import-${field.$1}'),
          initialValue: _mapping[field.$1],
          decoration: InputDecoration(
            labelText: field.$2,
            border: const OutlineInputBorder(),
          ),
          items: [
            const DropdownMenuItem<String>(
              value: '',
              child: Text('Not mapped'),
            ),
            ...preview.headings.map(
              (heading) =>
                  DropdownMenuItem(value: heading, child: Text(heading)),
            ),
          ],
          onChanged: _busy
              ? null
              : (value) => setState(() {
                  if (value == null || value.isEmpty) {
                    _mapping.remove(field.$1);
                  } else {
                    _mapping[field.$1] = value;
                  }
                }),
        ),
      );
}
