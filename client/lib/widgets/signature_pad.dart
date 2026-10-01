import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../core/theme.dart';

/// The strokes drawn on a [SignaturePad], and the PNG they make.
class SignatureController extends ChangeNotifier {
  final List<List<Offset>> _strokes = [];
  Size _size = Size.zero;

  /// The one finger drawing; a second one resting on the glass is ignored.
  int? _pointer;

  bool get isEmpty => _strokes.isEmpty;

  void clear() {
    _strokes.clear();
    notifyListeners();
  }

  void _down(PointerDownEvent e) {
    if (_pointer != null) return;
    _pointer = e.pointer;
    _strokes.add([e.localPosition]);
    notifyListeners();
  }

  void _move(PointerMoveEvent e) {
    if (e.pointer != _pointer) return;
    _strokes.last.add(e.localPosition);
    notifyListeners();
  }

  void _lift(PointerEvent e) {
    if (e.pointer == _pointer) _pointer = null;
  }

  /// Black ink on a transparent ground, so it sits on a signature line
  /// without covering it. Null while nothing is drawn.
  Future<Uint8List?> toPng({double pixelRatio = 3}) async {
    if (isEmpty || _size.isEmpty) return null;

    final recorder = ui.PictureRecorder();
    _paint(Canvas(recorder)..scale(pixelRatio), _strokes);
    final image = await recorder.endRecording().toImage(
      (_size.width * pixelRatio).ceil(),
      (_size.height * pixelRatio).ceil(),
    );
    final png = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    return png?.buffer.asUint8List();
  }
}

void _paint(Canvas canvas, List<List<Offset>> strokes) {
  final pen = Paint()
    ..color = EzBiziTheme.paperInk
    ..strokeWidth = 2.5
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..style = PaintingStyle.stroke;

  for (final stroke in strokes) {
    // A tap is a dot: a path of one point draws nothing.
    final path = Path()..moveTo(stroke.first.dx, stroke.first.dy);
    for (final at in stroke.length == 1 ? [stroke.first] : stroke.skip(1)) {
      path.lineTo(at.dx, at.dy);
    }
    canvas.drawPath(path, pen);
  }
}

/// A box to sign in with a finger, a stylus or the mouse, as A1 Tools had
/// it: white paper, the border lit once something is drawn.
class SignaturePad extends StatelessWidget {
  const SignaturePad({
    super.key,
    required this.controller,
    this.enabled = true,
    this.height = 200,
  });

  final SignatureController controller;
  final bool enabled;
  final double height;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = controller;

    return ListenableBuilder(
      listenable: c,
      builder: (context, _) => Container(
        height: height,
        decoration: BoxDecoration(
          // White in both themes: it is the paper the signature lands on.
          color: EzBiziTheme.paper,
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          border: Border.all(
            color: c.isEmpty
                ? theme.colorScheme.outlineVariant
                : theme.colorScheme.primary,
            width: c.isEmpty ? 1 : 2,
          ),
        ),
        clipBehavior: Clip.antiAlias,
        // Wins the vertical drag from the form it sits in, so a downstroke
        // draws instead of scrolling the page; the ink itself comes from raw
        // pointer events, which take part in no gesture contest.
        child: GestureDetector(
          onVerticalDragUpdate: enabled ? (_) {} : null,
          child: Listener(
            onPointerDown: enabled ? c._down : null,
            onPointerMove: c._move,
            onPointerUp: c._lift,
            onPointerCancel: c._lift,
            child: LayoutBuilder(
              builder: (context, box) {
                c._size = box.biggest;
                return CustomPaint(
                  painter: _Ink(c._strokes),
                  child: c.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(
                                Icons.draw_outlined,
                                size: 36,
                                color: EzBiziTheme.paperMuted,
                              ),
                              const SizedBox(height: 8),
                              Text(
                                'Sign with your finger, a stylus or the mouse',
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: EzBiziTheme.paperMuted,
                                ),
                              ),
                            ],
                          ),
                        )
                      : const SizedBox.expand(),
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

class _Ink extends CustomPainter {
  _Ink(this.strokes);

  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) => _paint(canvas, strokes);

  // The same lists grow in place, so there is nothing to compare.
  @override
  bool shouldRepaint(_Ink old) => true;
}
