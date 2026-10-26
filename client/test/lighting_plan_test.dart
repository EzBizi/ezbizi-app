import 'dart:io';
import 'dart:typed_data';

import 'package:ezbizi_client/core/lighting/lighting_service.dart';
import 'package:ezbizi_client/core/lighting/openrgb_client.dart';
import 'package:flutter_test/flutter_test.dart';

/// What the PC does for each wanted lighting, on the real ASRock reply.
void main() {
  final board = parseController(
    0,
    Uint8List.fromList(
      File('test/fixtures/openrgb_v0_asrock_b760m.bin').readAsBytesSync(),
    ),
  );

  test('static sets the colour at its brightness', () {
    final s =
        planFor({'mode': 'static', 'color': '#FF0080', 'brightness': 70}, board)
            as SetColor;
    expect([s.r, s.g, s.b, s.brightness], [0xFF, 0x00, 0x80, 70]);
  });

  test('off is black', () {
    final s = planFor({'mode': 'off', 'color': null}, board) as SetColor;
    expect([s.r, s.g, s.b], [0, 0, 0]);
  });

  test('breathing uses the board\'s own Breathing mode', () {
    final s =
        planFor({
              'mode': 'breathing',
              'color': '#00FF00',
              'brightness': 100,
            }, board)
            as UseMode;
    expect(board.modes[s.index].name, 'Breathing');
  });

  test('rainbow uses the board\'s Rainbow or Spectrum mode', () {
    final s = planFor({'mode': 'rainbow', 'color': null}, board) as UseMode;
    expect(board.modes[s.index].name, anyOf('Rainbow', 'Spectrum Cycle'));
  });

  test('a device without the effect falls back to a plain colour', () {
    final plain = OpenRgbController(0, 0, 'Plain', 'X', [], 1, 4);
    expect(
      planFor({'mode': 'breathing', 'color': '#0000FF'}, plain),
      isA<SetColor>(),
    );
  });
}
