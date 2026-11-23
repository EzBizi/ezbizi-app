import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/theme.dart';

/// What a suggestion is about and what came with it: the sidebar tab it names
/// and its attached screenshots or notes, each one a chip that downloads it.
///
/// Shared by the author's tab and the staff queue. [base] is the row's own
/// path (`/suggestions/{id}` or `/staff/suggestions/{id}`); each file is
/// `$base/files/{index}` below it.
class SuggestionAbout extends StatefulWidget {
  const SuggestionAbout({
    super.key,
    required this.api,
    required this.base,
    required this.row,
  });

  final ApiClient api;
  final String base;
  final Map<String, dynamic> row;

  @override
  State<SuggestionAbout> createState() => _SuggestionAboutState();
}

class _SuggestionAboutState extends State<SuggestionAbout> {
  String? _error;

  /// Each picture's bytes, asked for once with the session's token on the
  /// same scoped route as Download, so whoever cannot read the suggestion gets
  /// no image. ponytail: fetched on open, not on scroll; a suggestion holds at
  /// most five files, all in view at once.
  final _pictures = <int, Future<ApiBytesResponse>>{};

  List<Map<String, dynamic>> get _files =>
      List<Map<String, dynamic>>.from(widget.row['files'] as List? ?? const []);

  List<Map<String, dynamic>> get _images => [
    for (final f in _files)
      if (isPreviewableImage(f['mime'] as String?)) f,
  ];

  @override
  void initState() {
    super.initState();
    _images.forEach(_fetch);
  }

  void _fetch(Map<String, dynamic> file) {
    final index = file['index'] as int;
    _pictures[index] = widget.api.getBytesResponse(
      '${widget.base}/files/$index',
    );
  }

  Future<void> _open(Map<String, dynamic> file) async {
    setState(() => _error = null);
    final response = await widget.api.getBytesResponse(
      '${widget.base}/files/${file['index']}',
    );
    if (!mounted) return;
    final bytes = response.bytes;
    if (bytes == null) {
      setState(() => _error = response.message);
      return;
    }
    Files.download(file['name'] as String? ?? 'file', bytes);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = widget.row['module_label'] as String?;
    final images = _images;
    final others = [
      for (final f in _files)
        if (!images.contains(f)) f,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'About: ${label ?? 'Something new'}',
          style: theme.textTheme.labelMedium,
        ),
        if (images.isNotEmpty) ...[
          const SizedBox(height: 10),
          Wrap(
            key: const Key('suggestion-gallery'),
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final (i, f) in images.indexed)
                _Thumb(
                  file: f,
                  bytes: _pictures[f['index']]!,
                  onOpen: () => showSuggestionImages(
                    context,
                    images: images,
                    pictures: _pictures,
                    start: i,
                  ),
                  onRetry: () => setState(() => _fetch(f)),
                  onDownload: () => _open(f),
                ),
            ],
          ),
        ],
        if (others.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final file in others)
                ActionChip(
                  key: ValueKey('suggestion-file-${file['index']}'),
                  avatar: Icon(fileIcon(file['mime'] as String?), size: 18),
                  label: Text(file['name'] as String? ?? 'file'),
                  tooltip: 'Download',
                  onPressed: () => _open(file),
                ),
            ],
          ),
        ],
        if (_error != null) ...[
          const SizedBox(height: 6),
          Text(
            _error!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}

/// The same list when it already is one, so a rebuild draws the decoded
/// picture again instead of decoding it again.
Uint8List _u8(List<int> b) => b is Uint8List ? b : Uint8List.fromList(b);

/// A picture Flutter draws on every platform. HEIC and the rest stay a link.
bool isPreviewableImage(String? mime) => const {
  'image/png',
  'image/jpeg',
  'image/gif',
  'image/webp',
  'image/bmp',
}.contains((mime ?? '').toLowerCase());

/// One picture above its name, in a box of fixed size so nothing jumps when
/// it arrives. A failure offers Retry, and the name downloads the original.
class _Thumb extends StatelessWidget {
  const _Thumb({
    required this.file,
    required this.bytes,
    required this.onOpen,
    required this.onRetry,
    required this.onDownload,
  });

  final Map<String, dynamic> file;
  final Future<ApiBytesResponse> bytes;
  final VoidCallback onOpen;
  final VoidCallback onRetry;
  final VoidCallback onDownload;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final index = file['index'];
    final name = file['name'] as String? ?? 'picture';

    return SizedBox(
      width: 168,
      child: FutureBuilder<ApiBytesResponse>(
        future: bytes,
        builder: (context, snap) {
          final data = snap.data?.bytes;
          final failed =
              snap.connectionState == ConnectionState.done && data == null;
          Widget failure() => Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.broken_image_outlined, color: scheme.outline),
              TextButton(
                key: ValueKey('suggestion-thumb-retry-$index'),
                onPressed: onRetry,
                child: const Text('Retry'),
              ),
            ],
          );

          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Tooltip(
                message: 'Open $name',
                child: InkWell(
                  key: ValueKey('suggestion-thumb-$index'),
                  onTap: data == null ? null : onOpen,
                  borderRadius: BorderRadius.circular(8),
                  child: Container(
                    width: 168,
                    height: 112,
                    clipBehavior: Clip.antiAlias,
                    decoration: BoxDecoration(
                      color: scheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: scheme.outlineVariant),
                    ),
                    child: failed
                        ? failure()
                        : data == null
                        ? Icon(Icons.image_outlined, color: scheme.outline)
                        : Image.memory(
                            _u8(data),
                            key: ValueKey('suggestion-thumb-image-$index'),
                            fit: BoxFit.contain,
                            cacheWidth: 336,
                            errorBuilder: (_, _, _) => failure(),
                          ),
                  ),
                ),
              ),
              const SizedBox(height: 4),
              InkWell(
                key: ValueKey('suggestion-thumb-name-$index'),
                onTap: onDownload,
                child: Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// The pictures full screen, fitted. Pinch, wheel, +/- or the buttons zoom;
/// arrows, swipe or Previous/Next move; Esc or Close returns to the same
/// suggestion and the route hands focus back where it was.
Future<void> showSuggestionImages(
  BuildContext context, {
  required List<Map<String, dynamic>> images,
  required Map<int, Future<ApiBytesResponse>> pictures,
  required int start,
}) => Navigator.of(context).push<void>(
  // A screen, not a dialog (DEVELOPMENT-RULES); dark like any photo viewer.
  MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => Theme(
      data: EzBiziTheme.dark(),
      child: _ImageViewer(images: images, pictures: pictures, start: start),
    ),
  ),
);

class _ImageViewer extends StatefulWidget {
  const _ImageViewer({
    required this.images,
    required this.pictures,
    required this.start,
  });

  final List<Map<String, dynamic>> images;
  final Map<int, Future<ApiBytesResponse>> pictures;
  final int start;

  @override
  State<_ImageViewer> createState() => _ImageViewerState();
}

class _ImageViewerState extends State<_ImageViewer> {
  late int _at = widget.start;
  late final _pages = PageController(initialPage: widget.start);
  final _zoom = TransformationController();
  final _loaded = <int, ApiBytesResponse>{};

  @override
  void initState() {
    super.initState();
    for (final f in widget.images) {
      final index = f['index'] as int;
      widget.pictures[index]?.then((r) {
        if (mounted) setState(() => _loaded[index] = r);
      });
    }
  }

  @override
  void dispose() {
    _pages.dispose();
    _zoom.dispose();
    super.dispose();
  }

  void _go(int step) {
    final to = _at + step;
    if (to >= 0 && to < widget.images.length) _pages.jumpToPage(to);
  }

  void _zoomBy(double factor) {
    final scale = (_zoom.value.getMaxScaleOnAxis() * factor).clamp(1.0, 6.0);
    setState(() => _zoom.value = Matrix4.diagonal3Values(scale, scale, 1));
  }

  @override
  Widget build(BuildContext context) {
    final count = widget.images.length;
    final name = widget.images[_at]['name'] as String? ?? 'picture';
    final bytes = _loaded[widget.images[_at]['index']]?.bytes;
    final scale = _zoom.value.getMaxScaleOnAxis();

    return Scaffold(
      key: const Key('suggestion-image-viewer'),
      body: CallbackShortcuts(
        bindings: {
          const SingleActivator(LogicalKeyboardKey.arrowLeft): () => _go(-1),
          const SingleActivator(LogicalKeyboardKey.arrowRight): () => _go(1),
          const SingleActivator(LogicalKeyboardKey.equal): () => _zoomBy(1.25),
          const SingleActivator(LogicalKeyboardKey.minus): () => _zoomBy(0.8),
          const SingleActivator(LogicalKeyboardKey.escape): () =>
              Navigator.of(context).maybePop(),
        },
        child: Focus(
          autofocus: true,
          child: Column(
            children: [
              SafeArea(
                bottom: false,
                child: Row(
                  children: [
                    IconButton(
                      key: const Key('suggestion-image-close'),
                      tooltip: 'Close',
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.close),
                    ),
                    Expanded(
                      child: Text(
                        count > 1 ? '$name  ·  ${_at + 1} of $count' : name,
                        key: const Key('suggestion-image-title'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    IconButton(
                      key: const Key('suggestion-image-zoom-out'),
                      tooltip: 'Zoom out',
                      onPressed: scale > 1 ? () => _zoomBy(0.8) : null,
                      icon: const Icon(Icons.zoom_out),
                    ),
                    IconButton(
                      key: const Key('suggestion-image-zoom-in'),
                      tooltip: 'Zoom in',
                      onPressed: scale < 6 ? () => _zoomBy(1.25) : null,
                      icon: const Icon(Icons.zoom_in),
                    ),
                    IconButton(
                      key: const Key('suggestion-image-download'),
                      tooltip: 'Download original',
                      onPressed: bytes == null
                          ? null
                          : () => Files.download(name, bytes),
                      icon: const Icon(Icons.download_outlined),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    PageView.builder(
                      controller: _pages,
                      itemCount: count,
                      onPageChanged: (i) => setState(() {
                        _at = i;
                        _zoom.value = Matrix4.identity();
                      }),
                      itemBuilder: (context, i) {
                        final r = _loaded[widget.images[i]['index']];
                        if (r?.bytes == null) {
                          return Center(
                            child: Text(
                              r == null
                                  ? ''
                                  : r.message ??
                                        'That picture could not be opened.',
                            ),
                          );
                        }
                        return InteractiveViewer(
                          transformationController: i == _at ? _zoom : null,
                          maxScale: 6,
                          child: Center(
                            child: Image.memory(
                              _u8(r!.bytes!),
                              key: ValueKey('suggestion-image-$i'),
                              fit: BoxFit.contain,
                            ),
                          ),
                        );
                      },
                    ),
                    if (_at > 0)
                      Align(
                        alignment: Alignment.centerLeft,
                        child: IconButton.filledTonal(
                          key: const Key('suggestion-image-previous'),
                          tooltip: 'Previous',
                          onPressed: () => _go(-1),
                          icon: const Icon(Icons.chevron_left),
                        ),
                      ),
                    if (_at < count - 1)
                      Align(
                        alignment: Alignment.centerRight,
                        child: IconButton.filledTonal(
                          key: const Key('suggestion-image-next'),
                          tooltip: 'Next',
                          onPressed: () => _go(1),
                          icon: const Icon(Icons.chevron_right),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A picture, a PDF, or a written note.
IconData fileIcon(String? mimeOrName) {
  final s = (mimeOrName ?? '').toLowerCase();
  if (s.startsWith('image/') ||
      RegExp(r'\.(png|jpe?g|gif|webp|heic|heif)$').hasMatch(s)) {
    return Icons.image_outlined;
  }
  if (s.contains('pdf')) return Icons.picture_as_pdf_outlined;
  return Icons.description_outlined;
}

/// A rail section's heading and how many rows sit under it. Shared by the
/// author's tab and the staff queue, like [suggestionPill].
Widget suggestionBand(BuildContext context, String label, int count) {
  final theme = Theme.of(context);

  return Padding(
    padding: const EdgeInsets.fromLTRB(14, 14, 14, 6),
    child: Row(
      children: [
        Text(label, style: theme.textTheme.labelSmall),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
          decoration: BoxDecoration(
            border: Border.all(color: EzBiziTheme.edge(context)),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text('$count', style: theme.textTheme.labelSmall),
        ),
      ],
    ),
  );
}

/// The state in words — never the raw key. `new` gets an outline rather
/// than a fill because it is the one state with nothing decided about it
/// yet; the other three borrow the same colours the rest of the app already
/// uses for the same idea (`EzBiziTheme.clockIn` for a good outcome, the
/// real `errorContainer` for a refusal) rather than inventing new ones.
///
/// One copy for the author's tab and the staff queue, so the two halves of
/// one table read as one product.
Widget suggestionPill(BuildContext context, String status) {
  final theme = Theme.of(context);
  final scheme = theme.colorScheme;

  final (label, fg, bg, outlined) = switch (status) {
    'reviewing' => (
      'In review',
      scheme.primary,
      scheme.primaryContainer.withValues(alpha: 0.35),
      false,
    ),
    'approved' => (
      'Approved',
      EzBiziTheme.clockIn(context),
      EzBiziTheme.clockIn(context).withValues(alpha: 0.12),
      false,
    ),
    'denied' => (
      'Denied',
      scheme.onErrorContainer,
      scheme.errorContainer,
      false,
    ),
    _ => ('New', scheme.onSurface, scheme.surface, true),
  };

  return Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(
      color: bg,
      borderRadius: BorderRadius.circular(999),
      border: outlined ? Border.all(color: scheme.outline) : null,
    ),
    child: Text(label, style: theme.textTheme.labelSmall?.copyWith(color: fg)),
  );
}
