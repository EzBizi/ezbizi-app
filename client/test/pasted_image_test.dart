import 'dart:typed_data';

import 'package:ezbizi_client/core/pasted_image.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

/// 0.0.0562 on Windows: a pasted screenshot was BMP bytes named pasted-1.png,
/// and the server's content check refused it. The paste path re-encodes.
void main() {
  const pngSignature = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];

  test(
    'a Windows clipboard bitmap becomes a real PNG with the same pixels',
    () {
      final png = pastedImageAsPng(windowsClipboardBmp())!;

      expect(png.sublist(0, 8), pngSignature);
      final decoded = img.decodePng(png)!;
      expect([decoded.width, decoded.height], [3, 2]);
      // BI_RGB with a 40-byte header: the fourth byte is padding, not alpha.
      final p = decoded.getPixel(0, 0);
      expect([p.r, p.g, p.b, p.a], [200, 30, 30, 255]);
    },
  );

  test('PNG passes through untouched', () {
    final original = img.encodePng(img.Image(width: 2, height: 2));
    expect(identical(pastedImageAsPng(original), original), isTrue);
  });

  test('other picture formats become PNG too', () {
    final jpeg = img.encodeJpg(img.Image(width: 4, height: 4));
    expect(pastedImageAsPng(jpeg)!.sublist(0, 8), pngSignature);
  });

  test('empty or garbage bytes are no picture', () {
    expect(pastedImageAsPng(const []), isNull);
    expect(pastedImageAsPng(const [1, 2, 3]), isNull);
  });
}

/// What pasteboard's Windows `image` writes from CF_DIB: BITMAPFILEHEADER, a
/// 40-byte BITMAPINFOHEADER, 32 bits per pixel, BI_RGB, bottom-up rows.
Uint8List windowsClipboardBmp({int width = 3, int height = 2}) {
  final pixels = width * height * 4;
  final b = ByteData(14 + 40 + pixels)
    ..setUint8(0, 0x42) // B
    ..setUint8(1, 0x4D) // M
    ..setUint32(2, 14 + 40 + pixels, Endian.little)
    ..setUint32(10, 14 + 40, Endian.little)
    ..setUint32(14, 40, Endian.little)
    ..setInt32(18, width, Endian.little)
    ..setInt32(22, height, Endian.little)
    ..setUint16(26, 1, Endian.little)
    ..setUint16(28, 32, Endian.little)
    ..setUint32(30, 0, Endian.little)
    ..setUint32(34, pixels, Endian.little);
  for (var i = 0; i < width * height; i++) {
    final o = 54 + i * 4;
    b
      ..setUint8(o, 30) // blue
      ..setUint8(o + 1, 30) // green
      ..setUint8(o + 2, 200) // red
      ..setUint8(o + 3, 0); // padding, as GetDIBits leaves it
  }
  return b.buffer.asUint8List();
}
