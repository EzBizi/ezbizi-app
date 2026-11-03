import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

/// A tiny client for the OpenRGB SDK server (TCP 6742, protocol v0 — never
/// negotiated up, so every OpenRGB version answers the same way). Just what
/// lighting needs: list the devices, set a colour, pick a named mode.
class OpenRgbMode {
  OpenRgbMode(this.name, this.raw, this.colorCount);

  final String name;

  /// The mode exactly as the server sent it, for UPDATEMODE.
  final Uint8List raw;
  final int colorCount;
}

class OpenRgbController {
  OpenRgbController(
    this.index,
    this.type,
    this.name,
    this.vendor,
    this.modes,
    this.zones,
    this.leds,
  );

  final int index;
  final int type;
  final String name;
  final String vendor;
  final List<OpenRgbMode> modes;
  final int zones;
  final int leds;

  /// For specs.rgb_devices.
  Map<String, Object> toJson() => {
    'name': name,
    'vendor': vendor,
    'type': _types[type] ?? 'other',
    'zones': zones,
    'leds': leds,
  };
}

const _types = {
  0: 'motherboard',
  1: 'ram',
  2: 'gpu',
  3: 'cooler',
  4: 'ledstrip',
  5: 'keyboard',
  6: 'mouse',
  7: 'mousemat',
  8: 'headset',
  9: 'headset_stand',
  10: 'gamepad',
  11: 'light',
  12: 'speaker',
  13: 'virtual',
  14: 'storage',
  15: 'case',
  16: 'microphone',
  17: 'accessory',
  18: 'keypad',
};

class _Reader {
  _Reader(this.b) : d = ByteData.sublistView(b);
  final Uint8List b;
  final ByteData d;
  int o = 0;
  int u16() => d.getUint16((o += 2) - 2, Endian.little);
  int u32() => d.getUint32((o += 4) - 4, Endian.little);
  int i32() => d.getInt32((o += 4) - 4, Endian.little);
  String str() {
    final n = u16();
    final s = utf8
        .decode(b.sublist(o, o + n), allowMalformed: true)
        .replaceAll('\x00', '');
    o += n;
    return s;
  }
}

/// Parse a REQUEST_CONTROLLER_DATA reply (v0).
OpenRgbController parseController(int index, Uint8List data) {
  final r = _Reader(data)..o = 4; // data_size
  final type = r.i32();
  final name = r.str();
  r
    ..str() // description
    ..str() // version
    ..str() // serial
    ..str(); // location
  final modeCount = r.u16();
  r.i32(); // active mode
  final modes = <OpenRgbMode>[];
  for (var i = 0; i < modeCount; i++) {
    final start = r.o;
    final mname = r.str();
    r.o +=
        4 *
        9; // value, flags, speed min/max, colors min/max, speed, direction, color_mode
    final colors = r.u16();
    r.o += 4 * colors;
    modes.add(
      OpenRgbMode(mname, Uint8List.fromList(data.sublist(start, r.o)), colors),
    );
  }
  final zoneCount = r.u16();
  for (var i = 0; i < zoneCount; i++) {
    r
      ..str()
      ..o += 4 * 4; // type, leds min/max/count
    final matrix = r.u16();
    r.o += matrix; // (not `r.o += r.u16()`: o is read before u16 moves it)
  }
  final ledCount = r.u16();
  // v0 carries no vendor; the device name usually starts with it.
  return OpenRgbController(
    index,
    type,
    name,
    name.split(' ').first,
    modes,
    zoneCount,
    ledCount,
  );
}

/// RGBCONTROLLER_UPDATELEDS payload: every LED the same colour.
Uint8List colorsPayload(int leds, int r, int g, int b, {int brightness = 100}) {
  int s(int c) => (c * brightness / 100).round().clamp(0, 255);
  final out = ByteData(6 + leds * 4)
    ..setUint32(0, 6 + leds * 4, Endian.little)
    ..setUint16(4, leds, Endian.little);
  for (var i = 0; i < leds; i++) {
    out
      ..setUint8(6 + i * 4, s(r))
      ..setUint8(7 + i * 4, s(g))
      ..setUint8(8 + i * 4, s(b));
  }
  return out.buffer.asUint8List();
}

/// RGBCONTROLLER_UPDATEMODE payload: [mode] as the server sent it, with its
/// first colour (if it has any) replaced.
Uint8List modePayload(OpenRgbMode mode, int index, int r, int g, int b) {
  final raw = Uint8List.fromList(mode.raw);
  if (mode.colorCount > 0) {
    final at = raw.length - mode.colorCount * 4;
    raw
      ..[at] = r
      ..[at + 1] = g
      ..[at + 2] = b
      ..[at + 3] = 0;
  }
  final head = ByteData(8)
    ..setUint32(0, 8 + raw.length, Endian.little)
    ..setInt32(4, index, Endian.little);
  return Uint8List.fromList([...head.buffer.asUint8List(), ...raw]);
}

/// One connection to a local OpenRGB server.
class OpenRgbClient {
  OpenRgbClient._(this._socket) {
    _socket.listen(
      _onData,
      onDone: () => _closed = true,
      onError: (_) => _closed = true,
    );
  }

  static Future<OpenRgbClient> connect({int port = 6742}) async {
    final s = await Socket.connect(
      InternetAddress.loopbackIPv4,
      port,
      timeout: const Duration(seconds: 3),
    );
    final c = OpenRgbClient._(s);
    c._send(0, 50, Uint8List.fromList(utf8.encode('EzBizi\x00')));
    return c;
  }

  final Socket _socket;
  final _buf = BytesBuilder();
  Completer<Uint8List>? _waiting;
  bool _closed = false;
  bool get closed => _closed;

  /// The server re-detected its devices; ask for the list again.
  bool listChanged = false;

  void _onData(Uint8List chunk) {
    _buf.add(chunk);
    while (true) {
      final all = _buf.toBytes();
      if (all.length < 16) return;
      final n = ByteData.sublistView(all).getUint32(12, Endian.little);
      if (all.length < 16 + n) return;
      _buf
        ..clear()
        ..add(all.sublist(16 + n));
      // DEVICE_LIST_UPDATED (100) comes unasked when detection finishes
      // after we connect (slow boards: ASUS); it is no one's answer.
      if (ByteData.sublistView(all).getUint32(8, Endian.little) == 100) {
        listChanged = true;
        continue;
      }
      _waiting?.complete(all.sublist(16, 16 + n));
      _waiting = null;
    }
  }

  void _send(int dev, int id, [Uint8List? data]) {
    final h = ByteData(16)
      ..setUint8(0, 0x4F) // O
      ..setUint8(1, 0x52) // R
      ..setUint8(2, 0x47) // G
      ..setUint8(3, 0x42) // B
      ..setUint32(4, dev, Endian.little)
      ..setUint32(8, id, Endian.little)
      ..setUint32(12, data?.length ?? 0, Endian.little);
    _socket.add(h.buffer.asUint8List());
    if (data != null) _socket.add(data);
  }

  Future<Uint8List> _ask(int dev, int id) {
    // One question in flight: a second would take the first's reply slot.
    if (_waiting != null) {
      return Future.error(StateError('OpenRGB request already in flight'));
    }
    _waiting = Completer<Uint8List>();
    _send(dev, id);
    // 30 s, not 5: on ASUS boards OpenRGB answers only after its SMBus
    // detection, and 8 PCs timed out at 5 s (prod, 2026-11-02).
    return _waiting!.future
        .timeout(const Duration(seconds: 30))
        .whenComplete(() => _waiting = null);
  }

  Future<List<OpenRgbController>> controllers() async {
    final n = ByteData.sublistView(
      await _ask(0, 0),
    ).getUint32(0, Endian.little);
    return [for (var i = 0; i < n; i++) parseController(i, await _ask(i, 1))];
  }

  /// Direct mode, every LED one colour.
  void setColor(
    OpenRgbController c,
    int r,
    int g,
    int b, {
    int brightness = 100,
  }) {
    _send(c.index, 1100); // SETCUSTOMMODE
    _send(
      c.index,
      1050,
      colorsPayload(c.leds, r, g, b, brightness: brightness),
    );
  }

  void setMode(OpenRgbController c, int modeIndex, int r, int g, int b) =>
      _send(c.index, 1101, modePayload(c.modes[modeIndex], modeIndex, r, g, b));

  Future<void> close() async => _socket.destroy();
}
