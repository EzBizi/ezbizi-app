import 'dart:js_interop';
import 'dart:typed_data';
import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

int _seq = 0;

Widget buildPdfView(Uint8List bytes) => _PdfFrame(bytes);

/// The browser's own PDF viewer, in a frame one Letter page tall; it scrolls
/// through the pages itself.
class _PdfFrame extends StatefulWidget {
  const _PdfFrame(this.bytes);

  final Uint8List bytes;

  @override
  State<_PdfFrame> createState() => _PdfFrameState();
}

class _PdfFrameState extends State<_PdfFrame> {
  late String _viewType;
  String? _url;

  @override
  void initState() {
    super.initState();
    _register();
  }

  @override
  void didUpdateWidget(covariant _PdfFrame old) {
    super.didUpdateWidget(old);
    if (!identical(old.bytes, widget.bytes)) setState(_register);
  }

  @override
  void dispose() {
    if (_url case final url?) web.URL.revokeObjectURL(url);
    super.dispose();
  }

  void _register() {
    if (_url case final url?) web.URL.revokeObjectURL(url);
    final url = web.URL.createObjectURL(
      web.Blob(
        [widget.bytes.toJS].toJS,
        web.BlobPropertyBag(type: 'application/pdf'),
      ),
    );
    _url = url;
    _viewType = 'ezbizi-pdf-${_seq++}';
    ui_web.platformViewRegistry.registerViewFactory(_viewType, (int _) {
      final frame = web.HTMLIFrameElement()..src = url;
      frame.style
        ..setProperty('border', '0')
        ..setProperty('width', '100%')
        ..setProperty('height', '100%');
      return frame;
    });
  }

  @override
  Widget build(BuildContext context) => AspectRatio(
    aspectRatio: 8.5 / 11,
    child: HtmlElementView(viewType: _viewType),
  );
}
