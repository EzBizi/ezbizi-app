import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'side_panel.dart';

/// Crop an image to one of the two logo shapes, and produce it exactly (§12).
///
/// ── Why the client does the cropping ─────────────────────────────
///
/// The server refuses anything that is not exactly 512x512 or 1024x256. That
/// rule is what makes a row of company logos line up instead of looking
/// accidental — but on its own it would be a wall: almost no logo anybody has
/// on disk is already one of those sizes.
///
/// So this produces the file rather than checking it. Whatever goes in, what
/// comes out is a PNG of exactly the chosen dimensions, which means the
/// validator never has to refuse anything a person did through the app.
///
/// ── Hand-rolled rather than a package ────────────────────────────
///
/// A crop is a transform and a canvas: pan and scale, then draw the visible
/// region into an offscreen picture at the target size. Flutter has all of it.
/// A cropping package would bring a transitive tree, its own gesture model and
/// its own idea of output format, to save about a hundred lines.
///
/// The export is the part worth reading — see [_export]. It reverses exactly the
/// transform the preview applied, so what you saw is what you get.
class LogoCropper extends StatefulWidget {
  const LogoCropper({super.key, required this.bytes, required this.shapes});

  /// The image the user picked, as-is.
  final Uint8List bytes;

  /// From /branding/shapes — the server's list, not a copy.
  ///
  /// The output has to match the validator exactly, so both read the same
  /// numbers rather than each carrying their own.
  final Map<String, dynamic> shapes;

  @override
  State<LogoCropper> createState() => _LogoCropperState();
}

class _LogoCropperState extends State<LogoCropper> {
  ui.Image? _image;
  String _shape = 'square';

  /// Pan, in viewport pixels, and zoom as a multiple of "just covers".
  Offset _offset = Offset.zero;
  double _zoom = 1;

  Offset _dragAnchor = Offset.zero;
  Offset _offsetAnchor = Offset.zero;
  double _zoomAnchor = 1;

  bool _busy = false;

  /// The widest the preview is ever drawn. Below this it shrinks to whatever
  /// the panel has left, so the body always fits.
  ///
  /// That is not only tidiness. The panel scrolls its body by default, and a
  /// scroll view with anything to scroll installs a vertical-drag recognizer
  /// above everything inside it — which accepts at a smaller slop than this
  /// widget's scale recognizer and takes the gesture. On a short window the one
  /// instruction this screen gives, "drag to reposition", stopped working
  /// vertically. So the panel is told not to scroll, and the preview fits.
  static const double _maxViewportWidth = 460;

  /// The size the preview was last laid out at, in logical pixels.
  ///
  /// [_offset] is measured against it and [_export] scales by it, so it is read
  /// during layout rather than assumed.
  double _viewportWidth = _maxViewportWidth;

  @override
  void initState() {
    super.initState();
    _decode();
  }

  @override
  void dispose() {
    _image?.dispose();
    super.dispose();
  }

  // An undecodable file spun forever (audit 2026-11-27).
  bool _unreadable = false;

  Future<void> _decode() async {
    final ui.FrameInfo frame;
    try {
      final codec = await ui.instantiateImageCodec(widget.bytes);
      frame = await codec.getNextFrame();
    } catch (_) {
      if (mounted) setState(() => _unreadable = true);
      return;
    }

    if (!mounted) {
      frame.image.dispose();
      return;
    }

    setState(() => _image = frame.image);
  }

  Map<String, dynamic> get _shapeSpec =>
      widget.shapes[_shape] as Map<String, dynamic>;

  int get _outWidth => _shapeSpec['width'] as int;
  int get _outHeight => _shapeSpec['height'] as int;

  double get _viewportHeight => _viewportWidth * _outHeight / _outWidth;

  /// The scale at which the image exactly covers the viewport with no gaps.
  ///
  /// Everything else is expressed as a multiple of this, so switching between
  /// square and wide keeps the composition sensible instead of leaving the
  /// image floating in a corner.
  double get _coverScale {
    final image = _image!;

    return (_viewportWidth / image.width) > (_viewportHeight / image.height)
        ? _viewportWidth / image.width
        : _viewportHeight / image.height;
  }

  double get _scale => _coverScale * _zoom;

  /// Keep the image covering the viewport.
  ///
  /// Without this somebody can drag the artwork half out of frame and export a
  /// logo with a transparent stripe down one side — which passes every check
  /// the server makes and looks broken everywhere it is shown.
  void _clamp() {
    final image = _image!;
    final w = image.width * _scale;
    final h = image.height * _scale;

    final maxX = ((w - _viewportWidth) / 2).clamp(0.0, double.infinity);
    final maxY = ((h - _viewportHeight) / 2).clamp(0.0, double.infinity);

    _offset = Offset(
      _offset.dx.clamp(-maxX, maxX),
      _offset.dy.clamp(-maxY, maxY),
    );
  }

  /// Render the visible region at the shape's true size.
  ///
  /// The preview draws the image scaled by [_scale] and shifted by [_offset]
  /// inside a viewport [_viewportWidth] across. The export does the same thing
  /// into a canvas [_outWidth] across, so every distance is multiplied by the
  /// ratio between them. Reversing the same transform rather than recomputing a
  /// crop rectangle is what guarantees the output matches the preview.
  Future<Uint8List?> _export() async {
    final image = _image!;
    final ratio = _outWidth / _viewportWidth;

    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);

    final drawn = Size(
      image.width * _scale * ratio,
      image.height * _scale * ratio,
    );

    canvas.drawImageRect(
      image,
      Rect.fromLTWH(0, 0, image.width.toDouble(), image.height.toDouble()),
      Rect.fromLTWH(
        (_outWidth - drawn.width) / 2 + _offset.dx * ratio,
        (_outHeight - drawn.height) / 2 + _offset.dy * ratio,
        drawn.width,
        drawn.height,
      ),
      Paint()..filterQuality = FilterQuality.high,
    );

    final picture = recorder.endRecording();
    final rendered = await picture.toImage(_outWidth, _outHeight);
    picture.dispose();

    // PNG, always. A logo is flat colour and hard edges — exactly what JPEG is
    // worst at — and transparency is what lets a mark sit on a dark header.
    final data = await rendered.toByteData(format: ui.ImageByteFormat.png);
    rendered.dispose();

    return data?.buffer.asUint8List();
  }

  @override
  Widget build(BuildContext context) {
    final image = _image;

    return SidePanel(
      title: 'Crop the logo',
      // The viewport, plus the panel's own padding either side. Anything
      // narrower would squeeze the thing being composed.
      width: _maxViewportWidth + 60,
      // The preview owns its drags — see [_maxViewportWidth].
      scrollable: false,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: image == null || _busy
              ? null
              : () async {
                  setState(() => _busy = true);

                  final bytes = await _export();

                  if (context.mounted) Navigator.of(context).pop(bytes);
                },
          child: Text(_busy ? 'Working…' : 'Use this'),
        ),
      ],
      child: _unreadable
          ? const Center(
              child: Text('This file is not an image we can read. Try a PNG or JPEG.'),
            )
          : image == null
          ? const Center(child: CircularProgressIndicator())
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SegmentedButton<String>(
                  segments: [
                    for (final entry in widget.shapes.entries)
                      ButtonSegment(
                        value: entry.key,
                        label: Text((entry.value as Map)['label'] as String),
                        icon: Icon(
                          entry.key == 'square'
                              ? Icons.crop_square
                              : Icons.crop_16_9,
                        ),
                      ),
                  ],
                  selected: {_shape},
                  onSelectionChanged: (v) => setState(() {
                    _shape = v.first;
                    // Recentre: the old pan was measured against a viewport
                    // of a different shape and means nothing in the new one.
                    _offset = Offset.zero;
                    _zoom = 1;
                  }),
                ),
                const SizedBox(height: 6),
                Text(
                  (_shapeSpec['help'] as String?) ?? '',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 14),

                // Loose, so it takes what is left after the controls above
                // and below rather than pushing them off the panel.
                Flexible(
                  child: LayoutBuilder(
                    builder: (context, constraints) =>
                        _viewport(image, _fit(constraints)),
                  ),
                ),

                const SizedBox(height: 10),
                Row(
                  children: [
                    const Icon(Icons.zoom_out, size: 18),
                    Expanded(
                      child: Slider(
                        value: _zoom,
                        min: 1,
                        max: 4,
                        onChanged: (v) => setState(() {
                          _zoom = v;
                          _clamp();
                        }),
                      ),
                    ),
                    const Icon(Icons.zoom_in, size: 18),
                  ],
                ),
                Text(
                  'Drag to reposition. Exported at $_outWidth × $_outHeight.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
    );
  }

  /// The biggest preview of the current shape that fits, keeping its ratio.
  ///
  /// Called during layout, so it also records the width the composition is
  /// measured in and re-clamps the pan — a window resize would otherwise leave
  /// [_offset] describing a viewport that no longer exists.
  Size _fit(BoxConstraints constraints) {
    final byHeight = constraints.maxHeight.isFinite
        ? constraints.maxHeight * _outWidth / _outHeight
        : double.infinity;

    final width = [
      _maxViewportWidth,
      constraints.maxWidth,
      byHeight,
    ].reduce((a, b) => a < b ? a : b);

    if (width != _viewportWidth) {
      _viewportWidth = width;
      if (_image != null) _clamp();
    }

    return Size(width, width * _outHeight / _outWidth);
  }

  Widget _viewport(ui.Image image, Size size) {
    final scheme = Theme.of(context).colorScheme;

    return GestureDetector(
      onScaleStart: (details) {
        _dragAnchor = details.localFocalPoint;
        _offsetAnchor = _offset;
        _zoomAnchor = _zoom;
      },
      onScaleUpdate: (details) => setState(() {
        _zoom = (_zoomAnchor * details.scale).clamp(1.0, 4.0);
        _offset = _offsetAnchor + (details.localFocalPoint - _dragAnchor);
        _clamp();
      }),
      child: Container(
        width: size.width,
        height: size.height,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          // A chequerboard would be truer to transparency, but this is a
          // preview of a mark that will sit on the app's own surfaces — showing
          // it on one of those is the more useful lie.
          color: scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: scheme.outlineVariant),
        ),
        child: CustomPaint(
          painter: _CropPainter(image: image, scale: _scale, offset: _offset),
        ),
      ),
    );
  }
}

class _CropPainter extends CustomPainter {
  const _CropPainter({
    required this.image,
    required this.scale,
    required this.offset,
  });

  final ui.Image image;
  final double scale;
  final Offset offset;

  @override
  void paint(Canvas canvas, Size size) {
    final drawn = Size(image.width * scale, image.height * scale);

    canvas.drawImageRect(
      image,
      Rect.fromLTWH(0, 0, image.width.toDouble(), image.height.toDouble()),
      Rect.fromLTWH(
        (size.width - drawn.width) / 2 + offset.dx,
        (size.height - drawn.height) / 2 + offset.dy,
        drawn.width,
        drawn.height,
      ),
      Paint()..filterQuality = FilterQuality.high,
    );
  }

  @override
  bool shouldRepaint(covariant _CropPainter old) =>
      old.image != image || old.scale != scale || old.offset != offset;
}
