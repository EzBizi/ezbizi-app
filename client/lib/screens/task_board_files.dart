import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

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
  Map<String, dynamic> a, {
  List<Map<String, dynamic>> siblings = const [],
}) async {
  if (_isImage(a)) {
    final images = [
      for (final s in siblings.isEmpty ? [a] : siblings)
        if (_isImage(s)) s,
    ];
    final at = images.indexWhere((s) => s['id'] == a['id']);
    await showTaskImageLightbox(context, api, images, at < 0 ? 0 : at);
    return;
  }
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
/// Two or more can be selected together and downloaded at once (Merav via
/// Jose 11-19: "Multiple Select Attachments").
class TaskAttachments extends StatefulWidget {
  const TaskAttachments({
    super.key,
    required this.api,
    required this.attachments,
  });

  final ApiClient api;
  final List<Map<String, dynamic>> attachments;

  @override
  State<TaskAttachments> createState() => _TaskAttachmentsState();
}

class _TaskAttachmentsState extends State<TaskAttachments> {
  bool _selecting = false;
  final Set<String> _picked = {};

  Future<void> _downloadPicked() async {
    for (final a in widget.attachments) {
      if (!_picked.contains('${a['id']}')) continue;
      final res = await _fetch(widget.api, '${a['url']}');
      if (res.bytes != null) Files.download('${a['name']}', res.bytes!);
    }
    if (mounted) setState(() => _picked.clear());
  }

  @override
  Widget build(BuildContext context) {
    final attachments = widget.attachments;
    if (attachments.isEmpty) return const SizedBox.shrink();
    Widget item(Map<String, dynamic> a) {
      final file = a['thumbnail_url'] is String
          ? _Thumbnail(api: widget.api, attachment: a, siblings: attachments)
          : TaskFileChip(
              key: ValueKey('task-attachment-${a['id']}'),
              name: '${a['name']}',
              mime: a['mime'] as String?,
              size: a['size'] as num?,
              onTap: () => openTaskAttachment(
                context,
                widget.api,
                a,
                siblings: attachments,
              ),
            );
      if (!_selecting) return file;
      final id = '${a['id']}';
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Checkbox(
            key: ValueKey('task-attachment-pick-$id'),
            value: _picked.contains(id),
            onChanged: (on) => setState(
              () => on == true ? _picked.add(id) : _picked.remove(id),
            ),
          ),
          // Shrinks in a narrow panel (Kimberly 11-25) instead of overflowing.
          Flexible(child: file),
        ],
      );
    }

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [for (final a in attachments) item(a)],
          ),
          if (attachments.length > 1)
            Wrap(
              children: [
                TextButton(
                  key: const Key('task-attachments-select'),
                  onPressed: () => setState(() {
                    _selecting = !_selecting;
                    _picked.clear();
                  }),
                  child: Text(_selecting ? 'Done' : 'Select files'),
                ),
                if (_selecting)
                  TextButton.icon(
                    key: const Key('task-attachments-download'),
                    onPressed: _picked.isEmpty ? null : _downloadPicked,
                    icon: const Icon(Icons.download_outlined, size: 18),
                    label: Text('Download ${_picked.length}'),
                  ),
              ],
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
  const _Thumbnail({
    required this.api,
    required this.attachment,
    this.siblings = const [],
  });

  final ApiClient api;
  final Map<String, dynamic> attachment;
  final List<Map<String, dynamic>> siblings;

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
        onTap: () => openTaskAttachment(
          context,
          widget.api,
          a,
          siblings: widget.siblings,
        ),
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

bool _isImage(Map<String, dynamic> a) =>
    a['inline_url'] is String && '${a['mime'] ?? ''}'.startsWith('image/');

/// Monday's image lightbox (Adeline 11-24): centred over the board, full
/// screen on a phone; zoom, previous/next across the task's images,
/// Download, Esc or the close button.
Future<void> showTaskImageLightbox(
  BuildContext context,
  ApiClient api,
  List<Map<String, dynamic>> images,
  int start,
) => showDialog<void>(
  context: context,
  builder: (_) => TaskImageLightbox(api: api, images: images, start: start),
);

class TaskImageLightbox extends StatefulWidget {
  const TaskImageLightbox({
    super.key,
    required this.api,
    required this.images,
    this.start = 0,
  });

  final ApiClient api;
  final List<Map<String, dynamic>> images;
  final int start;

  @override
  State<TaskImageLightbox> createState() => _TaskImageLightboxState();
}

class _TaskImageLightboxState extends State<TaskImageLightbox> {
  late int _at = widget.start;
  final _bytes = <int, Uint8List?>{};

  @override
  void initState() {
    super.initState();
    _load(_at);
  }

  void _load(int i) {
    if (_bytes.containsKey(i)) return;
    _bytes[i] = null;
    _fetch(widget.api, '${widget.images[i]['inline_url']}').then((res) {
      if (mounted && res.bytes != null) {
        setState(() => _bytes[i] = Uint8List.fromList(res.bytes!));
      }
    });
  }

  void _go(int d) {
    final n = _at + d;
    if (n < 0 || n >= widget.images.length) return;
    setState(() => _at = n);
    _load(n);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.images[_at];
    final bytes = _bytes[_at];
    final phone = MediaQuery.sizeOf(context).width < 600;
    final many = widget.images.length > 1;
    final scheme = Theme.of(context).colorScheme;
    final ink = scheme.onInverseSurface;
    final body = Column(
      children: [
        Row(
          children: [
            const SizedBox(width: 16),
            Expanded(
              child: Text(
                '${a['name']}${many ? '  ·  ${_at + 1} of ${widget.images.length}' : ''}',
                overflow: TextOverflow.ellipsis,
                style: TextStyle(color: ink, fontSize: 15),
              ),
            ),
            IconButton(
              key: const Key('lightbox-download'),
              tooltip: 'Download',
              color: ink,
              onPressed: bytes == null
                  ? null
                  : () => Files.download('${a['name']}', bytes),
              icon: const Icon(Icons.download_outlined),
            ),
            IconButton(
              key: const Key('lightbox-close'),
              tooltip: 'Close',
              color: ink,
              onPressed: () => Navigator.of(context).pop(),
              icon: const Icon(Icons.close),
            ),
          ],
        ),
        Expanded(
          child: Row(
            children: [
              if (many && !phone)
                IconButton(
                  key: const Key('lightbox-previous'),
                  color: ink,
                  iconSize: 36,
                  onPressed: _at > 0 ? () => _go(-1) : null,
                  icon: const Icon(Icons.chevron_left),
                ),
              Expanded(
                child: GestureDetector(
                  onHorizontalDragEnd: phone && many
                      ? (d) => _go((d.primaryVelocity ?? 0) < 0 ? 1 : -1)
                      : null,
                  child: bytes == null
                      ? const Center(child: CircularProgressIndicator())
                      : InteractiveViewer(
                          key: ValueKey('lightbox-image-${a['id']}'),
                          maxScale: 8,
                          child: Center(
                            child: Image.memory(bytes, fit: BoxFit.contain),
                          ),
                        ),
                ),
              ),
              if (many && !phone)
                IconButton(
                  key: const Key('lightbox-next'),
                  color: ink,
                  iconSize: 36,
                  onPressed: _at < widget.images.length - 1
                      ? () => _go(1)
                      : null,
                  icon: const Icon(Icons.chevron_right),
                ),
            ],
          ),
        ),
      ],
    );
    return CallbackShortcuts(
      bindings: {
        const SingleActivator(LogicalKeyboardKey.arrowLeft): () => _go(-1),
        const SingleActivator(LogicalKeyboardKey.arrowRight): () => _go(1),
      },
      child: Focus(
        autofocus: true,
        child: phone
            ? Dialog.fullscreen(
                backgroundColor: scheme.inverseSurface,
                child: SafeArea(child: body),
              )
            : Dialog(
                key: const Key('task-image-lightbox'),
                backgroundColor: scheme.inverseSurface,
                insetPadding: const EdgeInsets.all(40),
                child: body,
              ),
      ),
    );
  }
}
