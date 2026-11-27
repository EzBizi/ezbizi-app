import 'dart:convert';
import 'dart:typed_data';

import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/pdf_panel.dart';
import 'package:ezbizi_client/widgets/pdf_view_io.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-11-18: the work order opens in the app first; Print, Share and
/// Download are there, and nothing downloads until Download.
void main() {
  testWidgets('a PDF opens in a panel; Download, Print and Share act on it', (
    tester,
  ) async {
    final drawn = pdfPages;
    final printed = printPdfBytes;
    final shared = sharePdfBytes;
    addTearDown(() {
      pdfPages = drawn;
      printPdfBytes = printed;
      sharePdfBytes = shared;
      Files.debugOnDownload = null;
    });
    pdfPages = (pdf) => Stream.value(_png);
    final calls = <String>[];
    printPdfBytes = (pdf, name) async => calls.add('print $name');
    sharePdfBytes = (pdf, name) async => calls.add('share $name');
    Files.debugOnDownload = (name, _) => calls.add('download $name');

    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final pdf = Uint8List.fromList(utf8.encode('%PDF-1.4 invented'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showPdfPanel(
                context,
                title: 'Work order J-1001',
                filename: 'Work order J-1001.pdf',
                bytes: pdf,
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pdf-panel')), findsOneWidget);
    expect(find.byKey(const Key('pdf-page-0')), findsOneWidget);
    expect(calls, isEmpty, reason: 'opening is not downloading');

    await tester.tap(find.byKey(const Key('pdf-zoom-in')));
    await tester.pumpAndSettle();
    expect(find.text('125%'), findsOneWidget);

    for (final key in ['pdf-print', 'pdf-share', 'pdf-download']) {
      await tester.tap(find.byKey(Key(key)));
      await tester.pumpAndSettle();
    }
    expect(calls, [
      'print Work order J-1001.pdf',
      'share Work order J-1001.pdf',
      'download Work order J-1001.pdf',
    ]);
  });
}

final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
);
