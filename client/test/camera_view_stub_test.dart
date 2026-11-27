@TestOn('vm')
library;

import 'dart:async';
import 'dart:typed_data';

import 'package:ezbizi_client/screens/monitoring/camera_view_stub.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The off-web camera view (desktop/mobile): a native MJPEG/snapshot reader.
/// Jose, 2026-10-06 — the Ring cameras "should work on the app, not just on
/// web." The framing is the one bit with real logic, so it is a pure function
/// tested directly; the widget is driven through an injected byte stream.

// A synthetic "JPEG": the FFD8…FFD9 markers the reader frames on, with junk
// between. scanJpegFrames keys on the markers, and the widget's Image.memory
// has an errorBuilder, so a non-decodable frame still proves the frame PATH
// (bytes in -> a frame rendered) without shipping a real image in the test.
Uint8List _frame(int tag) =>
    Uint8List.fromList([0xFF, 0xD8, 0xAA, tag, 0xBB, 0xFF, 0xD9]);

void main() {
  group('scanJpegFrames', () {
    test('pulls complete frames and keeps a partial tail', () {
      // Two whole JPEGs, then the start of a third (FF D8) with no end yet.
      final stream = <int>[
        ..._frame(1), ..._frame(2), 0xFF, 0xD8, 0x11, 0x22,
      ];
      final (frames, tail) = scanJpegFrames(Uint8List.fromList(stream));

      expect(frames.length, 2, reason: 'Two complete frames in the buffer.');
      expect(frames.first.first, 0xFF);
      expect(frames.first[1], 0xD8);
      expect(frames.first.last, 0xD9);
      expect(tail, [0xFF, 0xD8, 0x11, 0x22],
          reason: 'The unfinished next frame is carried forward, not lost.');
    });

    test('no complete frame yields nothing and the whole buffer as tail', () {
      final (frames, tail) = scanJpegFrames(Uint8List.fromList([0xFF, 0xD8, 0x01]));
      expect(frames, isEmpty);
      expect(tail, [0xFF, 0xD8, 0x01]);
    });
  });

  group('jpegAspect', () {
    test('reads width/height from the SOF0 marker', () {
      // FFD8 SOI, then SOF0: FF C0, len 00 11, precision 08, height 0x0438
      // (1080), width 0x0780 (1920) — a 16:9 frame.
      final jpeg = Uint8List.fromList([
        0xFF, 0xD8,
        0xFF, 0xC0, 0x00, 0x11, 0x08, 0x04, 0x38, 0x07, 0x80,
      ]);
      final aspect = jpegAspect(jpeg);
      expect(aspect, isNotNull);
      expect(aspect!, closeTo(1920 / 1080, 0.0001));
    });

    test('returns null when there is no SOF (the synthetic test frame)', () {
      expect(jpegAspect(_frame(1)), isNull);
    });
  });

  testWidgets('renders a frame pushed on the injected stream', (tester) async {
    final controller = StreamController<List<int>>();
    addTearDown(controller.close);

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SizedBox(
          width: 200,
          height: 150,
          child: buildCameraImage('mjpeg://cam', open: (_) => controller.stream),
        ),
      ),
    ));

    // Before any bytes: a loading indicator, no image.
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.byType(Image), findsNothing);

    // Push one JPEG frame — the tile should show it.
    controller.add(_frame(1));
    await tester.pump();
    await tester.pump();

    expect(find.byType(Image), findsOneWidget,
        reason: 'A pushed JPEG frame renders inline, off-web, no browser.');
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });

  testWidgets('a frame whose end marker is split across two chunks still renders', (
    tester,
  ) async {
    // Audit 11-27: the buffer is scanned only when a chunk can end a frame,
    // including FF in one chunk and D9 in the next.
    final controller = StreamController<List<int>>();
    addTearDown(controller.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SizedBox(
          width: 200,
          height: 150,
          child: buildCameraImage('mjpeg://cam', open: (_) => controller.stream),
        ),
      ),
    ));

    final f = _frame(7);
    controller.add(f.sublist(0, f.length - 1)); // ends on FF
    await tester.pump();
    expect(find.byType(Image), findsNothing, reason: 'not complete yet');

    controller.add([0xD9]);
    await tester.pump();
    await tester.pump();
    expect(find.byType(Image), findsOneWidget);
  });
}
