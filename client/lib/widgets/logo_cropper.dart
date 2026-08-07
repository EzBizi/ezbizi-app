import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

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
  const LogoCropper({
    super.key,
    required this.bytes,
    required this.shapes,
  });

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

  /// The viewport the user composes in. The output is rendered at the shape's
  /// real size; this is only how big the preview is on screen.
  static const double _viewportWidth = 460;

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

  Future<void> _decode() async {
    final codec = await ui.instantiateImageCodec(widget.bytes);
    final frame = await codec.getNextFrame();

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

    final drawn = Size(image.width * _scale * ratio, image.height * _scale * ratio);

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

    return AlertDialog(
      title: const Text('Crop the logo'),
      content: SizedBox(
        width: _viewportWidth,
        child: image == null
            ? const SizedBox(height: 200, child: Center(child: CircularProgressIndicator()))
            : Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SegmentedButton<String>(
                    segments: [
                      for (final entry in widget.shapes.entries)
                        ButtonSegment(
                          value: entry.key,
                          label: Text((entry.value as Map)['label'] as String),
                          icon: Icon(entry.key == 'square'
                              ? Icons.crop_square
                              : Icons.crop_16_9),
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

                  _viewport(image),

                  const SizedBox(height: 10),
                  Row(children: [
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
                  ]),
                  Text(
                    'Drag to reposition. Exported at $_outWidth × $_outHeight.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
      ),
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
    );
  }

  Widget _viewport(ui.Image image) {
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
        width: _viewportWidth,
        height: _viewportHeight,
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
