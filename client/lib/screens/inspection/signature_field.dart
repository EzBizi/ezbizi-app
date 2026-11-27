import 'package:flutter/gestures.dart';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';

/// The `signature` field: somebody draws on the glass, and it becomes a PNG.
///
/// ── Why an image and not a typed name ────────────────────────────
///
/// This field used to render as a TextField, which collected whatever the
/// technician typed and printed it on the report in the position a signature
/// goes. That is worse than not offering the field at all: it reads as somebody
/// having signed, and nobody did. A drawn mark is not a legal instrument either
/// — HR's contract signing is the ceremony that produces one, with a document
/// and a fingerprint over it — but it is an honest record of the person who was
/// standing there, which is what an inspection needs.
///
/// ── It is an attachment, like a photograph ───────────────────────
///
/// The raster goes up through the same `/attachments` path the photo field
/// uses, against the RUN, and the answer holds the attachment id in a
/// one-element list. That shape is deliberate rather than incidental: it means
/// the report renderer, the reports pane and the §9 purge all treat a signature
/// as a picture without any of them learning a fourth answer shape, and the
/// re-point at submit carries it to the report with everything else.
class InspectionSignatureField extends StatefulWidget {
  const InspectionSignatureField({
    super.key,
    required this.api,
    required this.runId,
    required this.label,
    required this.ids,
    required this.onChanged,
  });

  final ApiClient api;
  final String runId;
  final String label;
  final List<String> ids;
  final ValueChanged<List<String>> onChanged;

  @override
  State<InspectionSignatureField> createState() =>
      _InspectionSignatureFieldState();
}

class _InspectionSignatureFieldState extends State<InspectionSignatureField> {
  static const double _height = 170;

  /// Ink on paper, and the one place in this file that names a colour.
  ///
  /// The house rule is that colours come from theme.dart. It does not apply to
  /// these two, and the reason is that they are not UI: they are the pixels of a
  /// document that leaves the app, gets embedded in a PDF and is printed. Taking
  /// them from the theme would mean a signature drawn in dark mode rasterises as
  /// near-white ink, which is invisible on paper and would not be discovered
  /// until a customer opened the report. The pad's own chrome below is themed
  /// normally.
  static const Color _inkOnPaper = Color(0xFF000000);
  static const Color _paper = Color(0xFFFFFFFF);

  final List<List<Offset>> _strokes = [];
  Uint8List? _existing;
  bool _busy = false;
  String? _error;

  bool get _signed => widget.ids.isNotEmpty;

  @override
  void initState() {
    super.initState();
    _loadExisting();
  }

  @override
  void didUpdateWidget(InspectionSignatureField old) {
    super.didUpdateWidget(old);
    if (old.ids != widget.ids) _loadExisting();
  }

  Future<void> _loadExisting() async {
    if (widget.ids.isEmpty) {
      if (mounted) setState(() => _existing = null);
      return;
    }
    final bytes = await widget.api.getBytes('/attachments/${widget.ids.first}');
    if (!mounted || bytes == null) return;
    setState(() => _existing = Uint8List.fromList(bytes));
  }

  /// The drawn strokes, rasterised at 3x so the PDF is not a blur.
  ///
  /// The pad is about 170 logical pixels tall; a PDF page renders it far larger
  /// than that, and a 1x raster of a handwritten line is unreadable once it is
  /// scaled up on paper.
  Future<Uint8List?> _raster(Size size) async {
    const scale = 3.0;
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);

    canvas.scale(scale);
    canvas.drawRect(
      Rect.fromLTWH(0, 0, size.width, size.height),
      Paint()..color = _paper,
    );

    final pen = Paint()
      ..color = _inkOnPaper
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    for (final stroke in _strokes) {
      if (stroke.length < 2) {
        // A dot — a tap with no drag. Drawn as a point rather than skipped, so
        // a signature that is genuinely one mark is not silently empty.
        if (stroke.length == 1) {
          canvas.drawPoints(ui.PointMode.points, stroke, pen);
        }
        continue;
      }
      final path = Path()..moveTo(stroke.first.dx, stroke.first.dy);
      for (final point in stroke.skip(1)) {
        path.lineTo(point.dx, point.dy);
      }
      canvas.drawPath(path, pen);
    }

    final image = await recorder.endRecording().toImage(
      (size.width * scale).round(),
      (size.height * scale).round(),
    );
    final data = await image.toByteData(format: ui.ImageByteFormat.png);

    return data?.buffer.asUint8List();
  }

  Future<void> _save(Size size) async {
    if (_strokes.isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final bytes = await _raster(size);
    if (!mounted) return;

    if (bytes == null) {
      setState(() {
        _busy = false;
        _error = 'The signature could not be saved. Try again.';
      });
      return;
    }

    final response = await widget.api.postFile(
      '/attachments',
      'file',
      'signature.png',
      bytes,
      fields: {
        'attachable_type': 'inspection_runs',
        'attachable_id': widget.runId,
      },
    );

    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    final id = (response.data as Map?)?['id'] as String?;
    if (id == null) return;

    setState(() {
      _existing = bytes;
      _strokes.clear();
    });
    widget.onChanged([id]);
  }

  /// Signing again REPLACES. The old attachment is deleted rather than left
  /// behind — an inspection with two signatures on one field, one of them
  /// unreferenced, is a question nobody can answer later.
  Future<void> _redo() async {
    final previous = widget.ids;

    setState(() {
      _existing = null;
      _strokes.clear();
      _error = null;
    });
    widget.onChanged(const []);

    for (final id in previous) {
      await widget.api.delete('/attachments/$id');
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(widget.label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        if (_signed)
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                height: _height,
                decoration: BoxDecoration(
                  border: Border.all(color: EzBiziTheme.edge(context)),
                  borderRadius: BorderRadius.circular(8),
                  color: _paper,
                ),
                child: _existing == null
                    ? const Center(
                        child: SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : Image.memory(_existing!, fit: BoxFit.contain),
              ),
              const SizedBox(height: 8),
              OutlinedButton(
                key: Key('sign-again-${widget.label}'),
                onPressed: _busy ? null : _redo,
                child: const Text('Sign again'),
              ),
            ],
          )
        else
          LayoutBuilder(
            builder: (context, constraints) {
              final size = Size(constraints.maxWidth, _height);

              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Eager pan, so a vertical stroke draws instead of
                  // scrolling the form underneath (audit 11-27).
                  RawGestureDetector(
                    key: Key('signature-pad-${widget.label}'),
                    gestures: {
                      _EagerPan:
                          GestureRecognizerFactoryWithHandlers<_EagerPan>(
                            _EagerPan.new,
                            (r) {
                              r.onStart = (d) => setState(
                                () => _strokes.add([d.localPosition]),
                              );
                              r.onUpdate = (d) => setState(() {
                                if (_strokes.isEmpty) _strokes.add([]);
                                _strokes.last.add(d.localPosition);
                              });
                            },
                          ),
                    },
                    child: Container(
                      height: _height,
                      width: size.width,
                      decoration: BoxDecoration(
                        border: Border.all(color: EzBiziTheme.edge(context)),
                        borderRadius: BorderRadius.circular(8),
                        color: _paper,
                      ),
                      child: CustomPaint(
                        painter: _SignaturePainter(_strokes),
                        size: size,
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Sign above with a finger or a stylus.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    children: [
                      FilledButton(
                        key: Key('signature-save-${widget.label}'),
                        onPressed: _busy || _strokes.isEmpty
                            ? null
                            : () => _save(size),
                        child: Text(_busy ? 'Saving…' : 'Use this signature'),
                      ),
                      OutlinedButton(
                        onPressed: _busy || _strokes.isEmpty
                            ? null
                            : () => setState(_strokes.clear),
                        child: const Text('Clear'),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
      ],
    );
  }
}

class _SignaturePainter extends CustomPainter {
  const _SignaturePainter(this.strokes);

  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) {
    // Same ink as the raster, so what is drawn is what is saved.
    final pen = Paint()
      ..color = _InspectionSignatureFieldState._inkOnPaper
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    for (final stroke in strokes) {
      if (stroke.length == 1) {
        canvas.drawPoints(ui.PointMode.points, stroke, pen);
        continue;
      }
      for (var i = 0; i < stroke.length - 1; i++) {
        canvas.drawLine(stroke[i], stroke[i + 1], pen);
      }
    }
  }

  @override
  bool shouldRepaint(_SignaturePainter old) => true;
}

/// Wins the arena at once, so the ListView never takes a signature stroke.
class _EagerPan extends PanGestureRecognizer {
  @override
  void addAllowedPointer(PointerDownEvent event) {
    super.addAllowedPointer(event);
    resolve(GestureDisposition.accepted);
  }
}
