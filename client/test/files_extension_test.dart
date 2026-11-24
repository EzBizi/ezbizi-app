import 'package:ezbizi_client/core/files.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
    'a download without an extension gets one from its bytes (Adeline 11-24)',
    () {
      String? saved;
      Files.debugOnDownload = (name, _) => saved = name;
      addTearDown(() => Files.debugOnDownload = null);
      Files.download('IMG_2041', [0xFF, 0xD8, 0xFF, 0xE0]);
      expect(saved, 'IMG_2041.jpg');
      Files.download('photo.png', [0xFF, 0xD8, 0xFF, 0xE0]);
      expect(saved, 'photo.png');
      expect(Files.withExtension('scan', [0x25, 0x50, 0x44, 0x46]), 'scan.pdf');
      expect(Files.withExtension('notes', [0x41, 0x42]), 'notes');
    },
  );
}
