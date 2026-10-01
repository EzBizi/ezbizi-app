import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../core/theme.dart';

/// Each page of a PDF as a PNG: the platform's own renderer on Android, iOS
/// and macOS, pdfium on Windows. Replaced in widget tests, where no platform
/// renderer answers.
@visibleForTesting
Stream<Uint8List> Function(Uint8List pdf) pdfPages = (pdf) =>
    Printing.raster(pdf, dpi: 144).asyncMap((page) => page.toPng());

Widget buildPdfView(Uint8List bytes) => _PdfPages(bytes);

class _PdfPages extends StatefulWidget {
  const _PdfPages(this.bytes);

  final Uint8List bytes;

  @override
  State<_PdfPages> createState() => _PdfPagesState();
}

class _PdfPagesState extends State<_PdfPages> {
  List<Uint8List> _pages = const [];
  String? _error;
  StreamSubscription<Uint8List>? _drawing;

  @override
  void initState() {
    super.initState();
    _draw();
  }

  @override
  void didUpdateWidget(covariant _PdfPages old) {
    super.didUpdateWidget(old);
    if (!identical(old.bytes, widget.bytes)) _draw();
  }

  @override
  void dispose() {
    _drawing?.cancel();
    super.dispose();
  }

  /// The pages already shown stay until the new ones are all drawn, so a
  /// redraw after an answer changes does not flash an empty pane.
  void _draw() {
    _drawing?.cancel();
    final pages = <Uint8List>[];
    _drawing = pdfPages(widget.bytes).listen(
      pages.add,
      onError: (Object _) {
        if (mounted) {
          setState(() => _error = 'The PDF could not be drawn on this device.');
        }
      },
      onDone: () {
        if (mounted) {
          setState(() {
            _pages = pages;
            _error = null;
          });
        }
      },
      cancelOnError: true,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_error != null || _pages.isEmpty) {
      return Text(
        _error ?? 'Drawing the PDF…',
        style: theme.textTheme.bodyMedium,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, png) in _pages.indexed) ...[
          if (i > 0) const SizedBox(height: 12),
          DecoratedBox(
            key: Key('pdf-page-$i'),
            // White in both themes: a page's blank areas are transparent, and
            // the paper it prints on is white.
            decoration: BoxDecoration(
              color: EzBiziTheme.paper,
              border: Border.all(color: theme.colorScheme.outlineVariant),
            ),
            child: Image.memory(
              png,
              fit: BoxFit.fitWidth,
              filterQuality: FilterQuality.medium,
              gaplessPlayback: true,
            ),
          ),
        ],
      ],
    );
  }
}
