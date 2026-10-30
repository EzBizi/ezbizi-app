import 'dart:io';
import 'dart:typed_data';

import 'package:ezbizi_client/core/lighting/openrgb_client.dart';
import 'package:flutter_test/flutter_test.dart';

/// The OpenRGB SDK reply parser, against a REAL controller-data reply
/// captured from OpenRGB 1.1 on an ASRock B760M-C (protocol v0, 2026-10-26).
void main() {
  final real = File(
    'test/fixtures/openrgb_v0_asrock_b760m.bin',
  ).readAsBytesSync();

  test('a real controller reply parses to its name, modes, zones and LEDs', () {
    final c = parseController(0, Uint8List.fromList(real));
    expect(c.name, 'ASRock B760M-C');
    expect(c.modes, hasLength(16));
    expect(c.modes.map((m) => m.name), contains('Direct'));
    expect(c.zones, 4);
    expect(c.leds, 241);
  });

  test('a colour is packed R, G, B, 0 per LED, scaled by brightness', () {
    final b = colorsPayload(3, 0xFF, 0x80, 0x00, brightness: 50);
    final d = ByteData.sublistView(b);
    expect(d.getUint32(0, Endian.little), b.length); // data_size counts itself
    expect(d.getUint16(4, Endian.little), 3);
    expect(b.sublist(6, 10), [0x80, 0x40, 0x00, 0x00]);
  });

  test('a mode update carries the index and the mode exactly as sent', () {
    final c = parseController(0, Uint8List.fromList(real));
    final i = c.modes.indexWhere((m) => m.name == 'Breathing');
    final b = modePayload(c.modes[i], i, 0x11, 0x22, 0x33);
    final d = ByteData.sublistView(b);
    expect(d.getUint32(0, Endian.little), b.length);
    expect(d.getInt32(4, Endian.little), i);
    // The ASRock's modes carry no colours of their own (it colours per LED),
    // so the mode goes back byte for byte.
    expect(b.sublist(8), c.modes[i].raw);
  });

  // A slow board (ASUS) is still detecting when we connect; OpenRGB then
  // pushes DEVICE_LIST_UPDATED (id 100) unasked. It must not be taken for
  // the answer we are waiting on.
  test('an unasked device-list-updated notice is not the answer', () async {
    final server = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    addTearDown(server.close);
    Uint8List packet(int id, List<int> data) {
      final h = ByteData(16)
        ..setUint32(0, 0x4247524F, Endian.little)
        ..setUint32(8, id, Endian.little)
        ..setUint32(12, data.length, Endian.little);
      return Uint8List.fromList([...h.buffer.asUint8List(), ...data]);
    }

    server.listen((s) {
      var sent = false;
      s.listen((bytes) {
        // Answer the first request carrying id 0 (controller count).
        if (sent || bytes.length < 16) return;
        sent = true;
        s.add(packet(100, []));
        s.add(packet(0, [0, 0, 0, 0]));
      });
    });

    final c = await OpenRgbClient.connect(port: server.port);
    addTearDown(c.close);
    expect(await c.controllers(), isEmpty);
  });
}
