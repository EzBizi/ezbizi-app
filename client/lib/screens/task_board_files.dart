import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../widgets/pdf_view.dart';
import '../widgets/side_panel.dart';

/// Files on a task update (Task Board item 2). The limits mirror
/// TasksController::storeUpdate so a refusal the screen already knows about
/// is said before the upload, not after; the server's check still counts.
const taskUpdateMaxFiles = 10;
const taskUpdateMaxBytes = 25 * 1024 * 1024;

/// EZBIZI_ATTACHMENT_EXTENSIONS' default plus UPDATE_EXTRA_EXTENSIONS.
const taskUpdateExtensions = <String>[
  'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'doc', 'docx', //
  'xls', 'xlsx', 'csv', 'txt', 'rtf', 'gif', 'ppt', 'pptx', 'mp4', 'mov',
  'webm', 'm4v',
];

/// The picker, replaced in widget tests where no OS dialog can open.
Future<PickedBytes?> Function() pickTaskUpdateFile = () =>
    Files.pickBytes(accept: taskUpdateExtensions.map((e) => '.$e').join(','));

/// Why a file cannot join an update that already has [already] files, in
/// the server's words; null when it can.
String? taskUpdateFileRefusal(String name, int bytes, int already) {
  if (already >= taskUpdateMaxFiles) {
    return 'An update takes at most $taskUpdateMaxFiles files.';
  }
  if (bytes > taskUpdateMaxBytes) {
    return 'That file is too large. The most an update takes is 25 MB per '
        'file.';
  }
  final dot = name.lastIndexOf('.');
  final ext = dot < 0 ? '' : name.substring(dot + 1).toLowerCase();
  if (!taskUpdateExtensions.contains(ext)) {
    return 'That kind of file is not accepted here. Accepted: '
        '${taskUpdateExtensions.join(', ')}.';
  }
  return null;
}

String fileSizeLabel(num bytes) {
  if (bytes < 1024) return '$bytes B';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}

IconData fileIcon(String? mime) {
  final m = mime ?? '';
  if (m == 'application/pdf') return Icons.picture_as_pdf_outlined;
  if (m.startsWith('image/')) return Icons.image_outlined;
  if (m.startsWith('video/')) return Icons.movie_outlined;
  if (m.contains('sheet') || m.contains('excel') || m.contains('csv')) {
    return Icons.table_chart_outlined;
  }
  return Icons.insert_drive_file_outlined;
}

/// An absolute attachment URL as the API client's path and query: the
/// bearer token travels only through the client, never in a URL.
Future<ApiBytesResponse> _fetch(ApiClient api, String url) =>
    fetchTaskAttachment(api, url);

/// [_fetch], for the board's Gallery.
Future<ApiBytesResponse> fetchTaskAttachment(ApiClient api, String url) {
  final uri = Uri.parse(url);
  final at = uri.path.indexOf('/api/v1');
  final path = at < 0 ? uri.path : uri.path.substring(at + '/api/v1'.length);
  return api.getBytesResponse(
    path,
    uri.queryParameters.isEmpty ? null : uri.queryParameters,
  );
}

/// Opens an image or a PDF in the viewer; anything else is saved through
/// the app's download path.
Future<void> openTaskAttachment(
  BuildContext context,
  ApiClient api,
  Map<String, dynamic> a,
) async {
  if (a['inline_url'] is String) {
    await showSidePanel<void>(
      context,
      builder: (_) => TaskAttachmentViewer(api: api, attachment: a),
    );
    return;
  }
  final res = await _fetch(api, '${a['url']}');
  if (res.bytes != null) Files.download('${a['name']}', res.bytes!);
}

/// An update's files: images as thumbnails, everything else as a chip.
class TaskAttachments extends StatelessWidget {
  const TaskAttachments({
    super.key,
    required this.api,
    required this.attachments,
  });

  final ApiClient api;
  final List<Map<String, dynamic>> attachments;

  @override
  Widget build(BuildContext context) {
    if (attachments.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final a in attachments)
            a['thumbnail_url'] is String
                ? _Thumbnail(api: api, attachment: a)
                : TaskFileChip(
                    key: ValueKey('task-attachment-${a['id']}'),
                    name: '${a['name']}',
                    mime: a['mime'] as String?,
                    size: a['size'] as num?,
                    onTap: () => openTaskAttachment(context, api, a),
                  ),
        ],
      ),
    );
  }
}

/// A file as a chip: its kind, its name, its size.
class TaskFileChip extends StatelessWidget {
  const TaskFileChip({
    super.key,
    required this.name,
    this.mime,
    this.size,
    this.onTap,
    this.onDeleted,
  });

  final String name;
  final String? mime;
  final num? size;
  final VoidCallback? onTap;
  final VoidCallback? onDeleted;

  @override
  Widget build(BuildContext context) {
    final label = Text(
      size == null ? name : '$name · ${fileSizeLabel(size!)}',
      overflow: TextOverflow.ellipsis,
    );
    final icon = Icon(fileIcon(mime), size: 18);
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 260),
      child: onDeleted != null
          ? InputChip(avatar: icon, label: label, onDeleted: onDeleted)
          : ActionChip(avatar: icon, label: label, onPressed: onTap),
    );
  }
}

class _Thumbnail extends StatefulWidget {
  const _Thumbnail({required this.api, required this.attachment});

  final ApiClient api;
  final Map<String, dynamic> attachment;

  @override
  State<_Thumbnail> createState() => _ThumbnailState();
}

class _ThumbnailState extends State<_Thumbnail> {
  Uint8List? _bytes;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _fetch(widget.api, '${widget.attachment['thumbnail_url']}').then((res) {
      if (!mounted) return;
      setState(() {
        final bytes = res.bytes;
        if (bytes == null) {
          _failed = true;
        } else {
          _bytes = Uint8List.fromList(bytes);
        }
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final a = widget.attachment;
    return Tooltip(
      message: '${a['name']}',
      child: InkWell(
        key: ValueKey('task-attachment-${a['id']}'),
        borderRadius: BorderRadius.circular(8),
        onTap: () => openTaskAttachment(context, widget.api, a),
        child: Container(
          width: 72,
          height: 72,
          clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(
            color: scheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: scheme.outlineVariant),
          ),
          child: _bytes != null
              ? Image.memory(
                  _bytes!,
                  key: ValueKey('task-thumb-${a['id']}'),
                  fit: BoxFit.cover,
                  errorBuilder: (_, _, _) =>
                      Icon(Icons.image_outlined, color: scheme.outline),
                )
              : Icon(
                  _failed ? Icons.broken_image_outlined : Icons.image_outlined,
                  color: scheme.outline,
                ),
        ),
      ),
    );
  }
}

/// An image or a PDF, fetched with the session's token, and Download.
class TaskAttachmentViewer extends StatefulWidget {
  const TaskAttachmentViewer({
    super.key,
    required this.api,
    required this.attachment,
    this.onGoToTask,
  });

  final ApiClient api;
  final Map<String, dynamic> attachment;

  /// The Gallery's way back to the file's task; none from the task itself.
  final VoidCallback? onGoToTask;

  @override
  State<TaskAttachmentViewer> createState() => _TaskAttachmentViewerState();
}

class _TaskAttachmentViewerState extends State<TaskAttachmentViewer> {
  Uint8List? _bytes;
  String? _error;

  bool get _pdf => widget.attachment['mime'] == 'application/pdf';

  @override
  void initState() {
    super.initState();
    _fetch(widget.api, '${widget.attachment['inline_url']}').then((res) {
      if (!mounted) return;
      setState(() {
        if (res.bytes == null) {
          _error = res.message ?? 'That file could not be opened.';
        } else {
          _bytes = Uint8List.fromList(res.bytes!);
        }
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final a = widget.attachment;
    return SidePanel(
      key: const Key('task-attachment-viewer'),
      title: '${a['name']}',
      subtitle: a['size'] is num ? fileSizeLabel(a['size'] as num) : null,
      width: 720,
      actions: [
        if (widget.onGoToTask != null)
          OutlinedButton.icon(
            key: const Key('gallery-go-to-task'),
            onPressed: widget.onGoToTask,
            icon: const Icon(Icons.open_in_new, size: 18),
            label: const Text('Go to task'),
          ),
        FilledButton.icon(
          key: const Key('task-attachment-download'),
          onPressed: _bytes == null
              ? null
              : () => Files.download('${a['name']}', _bytes!),
          icon: const Icon(Icons.download_outlined, size: 18),
          label: const Text('Download'),
        ),
      ],
      child: _error != null
          ? Text(
              _error!,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.error,
              ),
            )
          : _bytes == null
          ? const Center(child: CircularProgressIndicator())
          : _pdf
          ? PdfView(key: const Key('task-attachment-pdf'), bytes: _bytes!)
          : InteractiveViewer(
              maxScale: 6,
              child: Image.memory(
                _bytes!,
                key: const Key('task-attachment-image'),
                fit: BoxFit.contain,
              ),
            ),
    );
  }
}
