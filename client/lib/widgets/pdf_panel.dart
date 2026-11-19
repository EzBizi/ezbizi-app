import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../core/files.dart';
import 'pdf_view.dart';
import 'side_panel.dart';

/// Print, swappable in tests where no platform printer answers.
@visibleForTesting
Future<void> Function(Uint8List pdf, String name) printPdfBytes = (pdf, name) =>
    Printing.layoutPdf(onLayout: (_) async => pdf, name: name);

/// The share sheet (mail, messages, the crew's chat), swappable in tests.
@visibleForTesting
Future<void> Function(Uint8List pdf, String name) sharePdfBytes = (pdf, name) =>
    Printing.sharePdf(bytes: pdf, filename: name);

/// A PDF opened in the app first (Jose, 2026-11-18: "should first open it in
/// the app, instead of just direct download"): its pages, zoom, and Print,
/// Share and Download from there. The one viewer is [PdfView].
Future<void> showPdfPanel(
  BuildContext context, {
  required String title,
  required String filename,
  required Uint8List bytes,
}) => showSidePanel<void>(
  context,
  builder: (_) => _PdfPanel(title: title, filename: filename, bytes: bytes),
);

class _PdfPanel extends StatefulWidget {
  const _PdfPanel({
    required this.title,
    required this.filename,
    required this.bytes,
  });

  final String title;
  final String filename;
  final Uint8List bytes;

  @override
  State<_PdfPanel> createState() => _PdfPanelState();
}

class _PdfPanelState extends State<_PdfPanel> {
  double _zoom = 1;

  void _zoomBy(double step) =>
      setState(() => _zoom = (_zoom + step).clamp(1.0, 3.0));

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      key: const Key('pdf-panel'),
      title: widget.title,
      width: 860,
      scrollable: false,
      actions: [
        OutlinedButton.icon(
          key: const Key('pdf-print'),
          onPressed: () => printPdfBytes(widget.bytes, widget.filename),
          icon: const Icon(Icons.print_outlined, size: 18),
          label: const Text('Print'),
        ),
        // On the web a share is a download, which Download already is.
        if (!kIsWeb)
          OutlinedButton.icon(
            key: const Key('pdf-share'),
            onPressed: () => sharePdfBytes(widget.bytes, widget.filename),
            icon: const Icon(Icons.ios_share, size: 18),
            label: const Text('Share'),
          ),
        FilledButton.icon(
          key: const Key('pdf-download'),
          onPressed: () => Files.download(widget.filename, widget.bytes),
          icon: const Icon(Icons.download_outlined, size: 18),
          label: const Text('Download'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // The browser's own viewer zooms itself; the drawn pages need this.
          if (!kIsWeb)
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                IconButton(
                  key: const Key('pdf-zoom-out'),
                  tooltip: 'Zoom out',
                  onPressed: _zoom > 1 ? () => _zoomBy(-0.25) : null,
                  icon: const Icon(Icons.zoom_out),
                ),
                Text('${(_zoom * 100).round()}%'),
                IconButton(
                  key: const Key('pdf-zoom-in'),
                  tooltip: 'Zoom in',
                  onPressed: _zoom < 3 ? () => _zoomBy(0.25) : null,
                  icon: const Icon(Icons.zoom_in),
                ),
              ],
            ),
          Expanded(
            child: LayoutBuilder(
              builder: (context, box) => SingleChildScrollView(
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: SizedBox(
                    width: box.maxWidth * _zoom,
                    child: PdfView(bytes: widget.bytes),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
