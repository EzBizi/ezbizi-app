import 'package:flutter/material.dart';

import '../core/theme.dart';

/// One picture for [showImageGallery].
typedef GalleryImage = ({String name, ImageProvider image, VoidCallback? download});

/// Full-screen, dark, swipe between pictures, pinch or wheel to zoom
/// (Adeline 01a2137d, Jose 2026-11-25: "actually show the images here").
Future<void> showImageGallery(
  BuildContext context, {
  required List<GalleryImage> images,
  int start = 0,
}) => Navigator.of(context).push<void>(
  MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => Theme(
      data: EzBiziTheme.dark(),
      child: _Gallery(images: images, start: start),
    ),
  ),
);

class _Gallery extends StatefulWidget {
  const _Gallery({required this.images, required this.start});
  final List<GalleryImage> images;
  final int start;

  @override
  State<_Gallery> createState() => _GalleryState();
}

class _GalleryState extends State<_Gallery> {
  late final _pages = PageController(initialPage: widget.start);
  late int _at = widget.start;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  void _go(int to) => _pages.animateToPage(
    to,
    duration: const Duration(milliseconds: 200),
    curve: Curves.easeOut,
  );

  @override
  Widget build(BuildContext context) {
    final image = widget.images[_at];
    final n = widget.images.length;
    return Scaffold(
      key: const Key('image-gallery'),
      appBar: AppBar(
        title: Text(image.name, overflow: TextOverflow.ellipsis),
        actions: [
          if (n > 1) Center(child: Text('${_at + 1} of $n')),
          if (image.download != null)
            IconButton(
              tooltip: 'Download',
              icon: const Icon(Icons.download),
              onPressed: image.download,
            ),
        ],
      ),
      body: Stack(
        children: [
          PageView.builder(
            controller: _pages,
            itemCount: n,
            onPageChanged: (i) => setState(() => _at = i),
            itemBuilder: (context, i) => InteractiveViewer(
              maxScale: 6,
              child: Center(
                child: Image(
                  key: ValueKey('image-gallery-$i'),
                  image: widget.images[i].image,
                  fit: BoxFit.contain,
                  semanticLabel: widget.images[i].name,
                  errorBuilder: (context, _, _) => Text(
                    'Could not load ${widget.images[i].name}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ),
            ),
          ),
          if (_at > 0)
            Align(
              alignment: Alignment.centerLeft,
              child: IconButton(
                tooltip: 'Previous photo',
                icon: const Icon(Icons.chevron_left, size: 36),
                onPressed: () => _go(_at - 1),
              ),
            ),
          if (_at < n - 1)
            Align(
              alignment: Alignment.centerRight,
              child: IconButton(
                tooltip: 'Next photo',
                icon: const Icon(Icons.chevron_right, size: 36),
                onPressed: () => _go(_at + 1),
              ),
            ),
        ],
      ),
    );
  }
}
