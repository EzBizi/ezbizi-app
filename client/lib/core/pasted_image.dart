import 'dart:typed_data';

import 'package:image/image.dart' as img;

/// A picture from the clipboard, as real PNG bytes — or null when the bytes
/// are no picture at all.
///
/// The clipboard does not hand back PNG everywhere. On Windows `pasteboard`
/// reads CF_DIB and writes it out as a .bmp file, so a pasted screenshot
/// arrived as BMP bytes under the name `pasted-1.png`, and the server's
/// content check (`files.*.mimes`) refused it (0.0.0562, Jose 11-12: "Attach
/// a picture, a PDF, or a .md or .txt file."). Linux hands back PNG; a
/// browser hands back whatever `image/*` it holds.
///
/// PNG passes through untouched; anything `package:image` can decode (BMP,
/// TIFF, JPEG, GIF, WebP…) is re-encoded as PNG, so the bytes always match
/// the `.png` name the paste gives them.
Uint8List? pastedImageAsPng(List<int> bytes) {
  if (bytes.isEmpty) return null;
  final data = bytes is Uint8List ? bytes : Uint8List.fromList(bytes);
  if (isPng(data)) return data;

  final img.Image? decoded;
  try {
    // Throws, rather than returning null, on some short or garbled input.
    decoded = img.decodeImage(data);
  } catch (_) {
    return null;
  }
  if (decoded == null || decoded.width == 0 || decoded.height == 0) {
    return null;
  }
  return img.encodePng(decoded);
}

const _pngSignature = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];

/// Whether [bytes] start with the eight-byte PNG signature.
bool isPng(List<int> bytes) {
  if (bytes.length < _pngSignature.length) return false;
  for (var i = 0; i < _pngSignature.length; i++) {
    if (bytes[i] != _pngSignature[i]) return false;
  }
  return true;
}
