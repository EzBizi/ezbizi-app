import 'dart:typed_data';

import 'package:flutter/widgets.dart';

import 'pdf_view_io.dart' if (dart.library.js_interop) 'pdf_view_web.dart'
    as impl;

/// A PDF on screen, as it prints. The browser shows it in its own viewer;
/// everywhere else its pages are drawn as pictures, because Android's
/// WebView cannot show a PDF at all.
class PdfView extends StatelessWidget {
  const PdfView({super.key, required this.bytes});

  final Uint8List bytes;

  @override
  Widget build(BuildContext context) => impl.buildPdfView(bytes);
}
