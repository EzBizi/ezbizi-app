import 'dart:typed_data';

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import 'job_workiz.dart';

/// A job's own photos and files (UI queue 119; Jose: option 1 and phone 4).
///
/// The generic attachments endpoint with `attachable_type=jobs`: the server
/// asks JobPolicy `view` to list and download, `update` (Jobs Edit, within
/// reach) to add and remove. Workiz's files sit under them, read only, until
/// they are copied in.
class JobAttachments extends ChangeNotifier {
  JobAttachments({required this.api, required this.jobId});

  final ApiClient api;
  final String jobId;

  List<Map<String, dynamic>> items = const [];
  bool loading = true;
  String? error;

  /// The server's own limit, so the drop zone can say it before an upload.
  String? maxReadable;

  /// Picture bytes by attachment id; the endpoint serves bytes behind the
  /// token, so a plain network image cannot draw them.
  final Map<String, Uint8List> pictures = {};

  /// Uploads not yet on the list: sending, or failed with a reason.
  final List<JobUpload> uploads = [];

  bool _disposed = false;

  Map<String, String> get _subject => {
    'attachable_type': 'jobs',
    'attachable_id': jobId,
  };

  Future<void> load() async {
    loading = items.isEmpty;
    error = null;
    _notify();

    final response = await api.get('/attachments', _subject);
    if (_disposed) return;

    loading = false;
    if (!response.ok) {
      error = response.message;
      _notify();
      return;
    }

    items = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    final limits = response.body['limits'];
    if (limits is Map && limits['max_readable'] is String) {
      maxReadable = limits['max_readable'] as String;
    }
    _notify();

    for (final row in items) {
      await _fetchPicture(row);
    }
  }

  Future<void> _fetchPicture(Map<String, dynamic> row) async {
    final id = row['id'] as String?;
    if (id == null || pictures.containsKey(id) || !isJobPicture(row)) return;
    final bytes = await api.getBytes('/attachments/$id');
    if (_disposed || bytes == null) return;
    pictures[id] = Uint8List.fromList(bytes);
    _notify();
  }

  Future<void> upload(String name, List<int> bytes) async {
    final upload = JobUpload(name, bytes);
    uploads.add(upload);
    _notify();
    await _send(upload);
  }

  Future<void> retry(JobUpload upload) async {
    upload.error = null;
    _notify();
    await _send(upload);
  }

  void dismiss(JobUpload upload) {
    uploads.remove(upload);
    _notify();
  }

  Future<void> _send(JobUpload upload) async {
    final response = await api.postFile(
      '/attachments',
      'file',
      upload.name,
      upload.bytes,
      fields: _subject,
    );
    if (_disposed) return;

    if (!response.ok) {
      upload.error = response.reason;
      _notify();
      return;
    }

    uploads.remove(upload);
    final row = response.data;
    if (row is Map) {
      final added = Map<String, dynamic>.from(row);
      items = [...items, added];
      if (isJobPicture(added) && added['id'] is String) {
        pictures[added['id'] as String] = Uint8List.fromList(upload.bytes);
      }
    }
    _notify();
  }

  /// Null when it went; otherwise the server's reason.
  Future<String?> remove(String id) async {
    final response = await api.delete('/attachments/$id');
    if (_disposed) return null;
    if (!response.ok) return response.reason;

    items = [
      for (final row in items)
        if (row['id'] != id) row,
    ];
    pictures.remove(id);
    _notify();
    return null;
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

class JobUpload {
  JobUpload(this.name, this.bytes);

  final String name;
  final List<int> bytes;
  String? error;
}

/// A picture Flutter can draw from bytes; anything else is a file tile.
bool isJobPicture(Map<String, dynamic> row) => const {
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'image/bmp',
}.contains(row['mime']);

/// The Photos & files tab: the job's own on top, Workiz's under them.
class JobFiles extends StatefulWidget {
  const JobFiles({
    super.key,
    required this.files,
    required this.workiz,
    required this.formats,
    required this.addRefusal,
    this.cameraSupported,
    this.pick,
    this.takePhoto,
  });

  final JobAttachments files;
  final List<({String name, String url})> workiz;
  final DisplayFormats formats;

  /// Why this reader may not add or remove (no Jobs Edit), or null.
  final String? addRefusal;

  /// Seams for tests; default to [Files].
  final bool? cameraSupported;
  final Future<PickedBytes?> Function()? pick;
  final Future<PickedBytes?> Function()? takePhoto;

  @override
  State<JobFiles> createState() => _JobFilesState();
}

class _JobFilesState extends State<JobFiles> {
  String? _hovered;
  bool _dropping = false;
  String? _removeError;

  JobAttachments get _files => widget.files;
  bool get _canAdd => widget.addRefusal == null;

  Future<void> _choose() async {
    final picked = await (widget.pick ?? Files.pickBytes)();
    if (picked == null || !mounted) return;
    await _files.upload(picked.name, picked.bytes);
  }

  Future<void> _camera() async {
    final picked = await (widget.takePhoto ?? Files.takePhoto)();
    if (picked == null || !mounted) return;
    await _files.upload(picked.name, picked.bytes);
  }

  Future<void> _drop(List<DropItem> items) async {
    setState(() => _dropping = false);
    for (final item in items) {
      if (item is DropItemDirectory) continue;
      final bytes = await item.readAsBytes();
      if (!mounted) return;
      await _files.upload(item.name, bytes);
    }
  }

  String _name(Map<String, dynamic> row) {
    final name = '${row['original_name'] ?? ''}'.trim();
    return name.isEmpty ? 'A file' : name;
  }

  String _byline(Map<String, dynamic> row) {
    final who = (row['uploaded_by'] as Map?)?['name'] as String?;
    final when = formatListStamp(
      row['uploaded_at'],
      '',
      formats: widget.formats,
    );
    return [
      if (who != null && who.isNotEmpty) who,
      if (when.isNotEmpty) when,
    ].join(' · ');
  }

  /// Asks once, in a panel, then removes.
  Future<void> _remove(Map<String, dynamic> row) async {
    final id = row['id'] as String?;
    if (id == null || !_canAdd) return;
    setState(() => _removeError = null);

    final name = _name(row);
    final sure = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        key: const Key('job-file-remove-panel'),
        title: 'Remove $name?',
        subtitle: _byline(row),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            key: const Key('job-file-remove-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Remove'),
          ),
        ],
        child: const Text(
          'It comes off this job for everyone who can see it. '
          "Workiz's files are not touched.",
        ),
      ),
    );
    if (sure != true || !mounted) return;

    final refused = await _files.remove(id);
    if (!mounted) return;
    setState(() => _removeError = refused);
  }

  Future<void> _download(Map<String, dynamic> row) async {
    final id = row['id'] as String?;
    if (id == null) return;
    final bytes = await _files.api.getBytes('/attachments/$id');
    if (!mounted) return;
    if (bytes == null) {
      setState(() => _removeError = 'Could not download that file.');
      return;
    }
    Files.download(_name(row), bytes);
  }

  Future<void> _view(Map<String, dynamic> row) async {
    final id = row['id'] as String? ?? '';
    final bytes = _files.pictures[id];
    final next = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        key: const Key('job-file-viewer'),
        title: _name(row),
        subtitle: _byline(row),
        width: 760,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop('download'),
            child: const Text('Download'),
          ),
          Refused(
            reason: widget.addRefusal,
            child: OutlinedButton.icon(
              onPressed: _canAdd
                  ? () => Navigator.of(context).pop('remove')
                  : null,
              icon: const Icon(Icons.close, size: 18),
              label: const Text('Remove'),
            ),
          ),
        ],
        child: bytes == null
            ? const Padding(
                padding: EdgeInsets.all(32),
                child: Text(
                  'The picture has not loaded. Download opens the original.',
                ),
              )
            : ClipRRect(
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
                child: Image.memory(
                  bytes,
                  fit: BoxFit.contain,
                  semanticLabel: _name(row),
                  errorBuilder: (context, _, _) =>
                      Text('Could not show ${_name(row)}.'),
                ),
              ),
      ),
    );
    if (!mounted) return;
    if (next == 'remove') await _remove(row);
    if (next == 'download') await _download(row);
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _files,
      builder: (context, _) => LayoutBuilder(builder: _layout),
    );
  }

  Widget _layout(BuildContext context, BoxConstraints box) {
    final theme = Theme.of(context);
    // The window, not this tab: the job panel's content is at most 720 wide
    // docked and 472 floating, so a tab-width breakpoint at 760 never opens.
    final phone = MediaQuery.sizeOf(context).width < 760;
    final camera = widget.cameraSupported ?? Files.canTakePhoto;
    const gap = 8.0;
    final across = phone
        ? 3
        : box.maxWidth >= 600
        ? 6
        : box.maxWidth >= 420
        ? 4
        : 3;
    final side = ((box.maxWidth - gap * (across - 1)) / across).floorToDouble();
    final count = _files.items.length;
    final limit = _files.maxReadable;

    Widget label(String text) => Text(
      text.toUpperCase(),
      style: theme.textTheme.labelSmall?.copyWith(
        fontWeight: FontWeight.w800,
        letterSpacing: 0.6,
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );

    final own = Column(
      key: const Key('job-own-files'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (phone) ...[
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (camera)
                Refused(
                  reason: widget.addRefusal,
                  child: FilledButton.icon(
                    key: const Key('job-file-camera'),
                    onPressed: _canAdd ? _camera : null,
                    icon: const Icon(Icons.photo_camera_outlined, size: 18),
                    label: const Text('Take a photo'),
                  ),
                ),
              Refused(
                reason: widget.addRefusal,
                child: (camera
                    ? OutlinedButton.icon(
                        key: const Key('job-file-add'),
                        onPressed: _canAdd ? _choose : null,
                        icon: const Icon(Icons.attach_file, size: 18),
                        label: const Text('Choose files'),
                      )
                    : FilledButton.icon(
                        key: const Key('job-file-add'),
                        onPressed: _canAdd ? _choose : null,
                        icon: const Icon(Icons.attach_file, size: 18),
                        label: const Text('Choose files'),
                      )),
              ),
            ],
          ),
          const SizedBox(height: 12),
        ],
        Row(
          children: [
            Expanded(child: label('Added in EzBizi · $count')),
            if (!phone)
              Refused(
                reason: widget.addRefusal,
                child: FilledButton.icon(
                  key: const Key('job-file-add'),
                  onPressed: _canAdd ? _choose : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add photos or files'),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        if (_files.loading)
          const LinearProgressIndicator()
        else if (_files.error != null) ...[
          ErrorNote(_files.error!),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: _files.load,
              child: const Text('Try again'),
            ),
          ),
        ] else if (count == 0 && _files.uploads.isEmpty)
          Text(
            'Nothing added in EzBizi yet.',
            style: theme.textTheme.bodySmall,
          ),
        if (count > 0 || _files.uploads.isNotEmpty)
          Wrap(
            spacing: gap,
            runSpacing: gap,
            children: [
              for (final row in _files.items) _tile(context, row, side),
              for (final upload in _files.uploads)
                _uploadTile(context, upload, side),
            ],
          ),
        if (_removeError != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_removeError!),
        ],
        if (!phone && _canAdd) ...[
          const SizedBox(height: 10),
          DropTarget(
            onDragEntered: (_) => setState(() => _dropping = true),
            onDragExited: (_) => setState(() => _dropping = false),
            onDragDone: (details) => _drop(details.files),
            child: Container(
              key: const Key('job-file-drop'),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: _dropping
                    ? theme.colorScheme.primaryContainer
                    : theme.colorScheme.surface,
                border: Border.all(
                  color: _dropping
                      ? theme.colorScheme.primary
                      : theme.colorScheme.outlineVariant,
                  width: _dropping ? 2 : 1,
                ),
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
              alignment: Alignment.center,
              child: Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    'Drop photos or files here, or ',
                    style: theme.textTheme.bodySmall,
                  ),
                  InkWell(
                    onTap: _choose,
                    child: Text(
                      'choose files',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.primary,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (limit != null)
                    Text(
                      ' · up to $limit each',
                      style: theme.textTheme.bodySmall,
                    ),
                ],
              ),
            ),
          ),
        ],
      ],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        own,
        if (widget.workiz.isNotEmpty) ...[
          const SizedBox(height: 22),
          WorkizFiles(files: widget.workiz, across: phone ? 3 : null),
        ],
      ],
    );
  }

  Widget _tile(BuildContext context, Map<String, dynamic> row, double side) {
    final theme = Theme.of(context);
    final id = row['id'] as String? ?? '';
    final name = _name(row);
    final picture = isJobPicture(row);
    final bytes = _files.pictures[id];
    final showRemove = _canAdd && _hovered == id;

    final face = picture && bytes != null
        ? Image.memory(
            bytes,
            fit: BoxFit.cover,
            cacheWidth: 300,
            semanticLabel: name,
            errorBuilder: (context, _, _) =>
                const Center(child: Icon(Icons.broken_image_outlined)),
          )
        : Center(
            child: Icon(
              picture
                  ? Icons.image_outlined
                  : name.toLowerCase().endsWith('.pdf')
                  ? Icons.picture_as_pdf_outlined
                  : Icons.insert_drive_file_outlined,
              size: 28,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          );

    return SizedBox(
      key: ValueKey('job-own-file-$id'),
      width: side,
      child: MouseRegion(
        onEnter: (_) => setState(() => _hovered = id),
        onExit: (_) => setState(() {
          if (_hovered == id) _hovered = null;
        }),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox.square(
              dimension: side,
              child: Material(
                color: theme.colorScheme.surfaceContainerHighest,
                clipBehavior: Clip.antiAlias,
                borderRadius: BorderRadius.circular(8),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    InkWell(
                      key: ValueKey('job-own-open-$id'),
                      onTap: () => picture ? _view(row) : _download(row),
                      onLongPress: _canAdd ? () => _remove(row) : null,
                      // No Tooltip: on touch it takes the long press that
                      // Remove needs, and the name is printed underneath.
                      child: face,
                    ),
                    if (showRemove)
                      Positioned(
                        top: 4,
                        right: 4,
                        child: IconButton.filledTonal(
                          key: ValueKey('job-own-remove-$id'),
                          tooltip: 'Remove $name',
                          visualDensity: VisualDensity.compact,
                          iconSize: 16,
                          onPressed: () => _remove(row),
                          icon: const Icon(Icons.close),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 4),
            Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            Text(
              _byline(row),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.labelSmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _uploadTile(BuildContext context, JobUpload upload, double side) {
    final theme = Theme.of(context);
    final error = upload.error;

    return SizedBox(
      key: ValueKey('job-upload-${upload.name}'),
      width: side,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox.square(
            dimension: side,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: error != null
                    ? theme.colorScheme.errorContainer
                    : theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: error == null
                  ? const Padding(
                      padding: EdgeInsets.all(10),
                      child: Center(child: LinearProgressIndicator()),
                    )
                  : Stack(
                      children: [
                        Center(
                          child: TextButton(
                            key: ValueKey('job-upload-retry-${upload.name}'),
                            onPressed: () => _files.retry(upload),
                            child: const Text('Try again'),
                          ),
                        ),
                        Positioned(
                          top: 0,
                          right: 0,
                          child: IconButton(
                            tooltip: 'Leave ${upload.name} out',
                            visualDensity: VisualDensity.compact,
                            iconSize: 16,
                            onPressed: () => _files.dismiss(upload),
                            icon: const Icon(Icons.close),
                          ),
                        ),
                      ],
                    ),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            upload.name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          Text(
            error ?? 'Uploading…',
            maxLines: error == null ? 1 : 3,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall?.copyWith(
              color: error != null
                  ? theme.colorScheme.error
                  : theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}
