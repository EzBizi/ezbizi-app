import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-14: recordings would not play on iOS, which cannot open an
/// extensionless file without being told the type.
void main() {
  test('a recording names its own audio type', () {
    expect(audioMimeType([0x49, 0x44, 0x33, 4]), 'audio/mpeg');
    expect(audioMimeType([0xFF, 0xFB, 0x90, 0]), 'audio/mpeg');
    expect(audioMimeType([0x52, 0x49, 0x46, 0x46, 0]), 'audio/wav');
    expect(audioMimeType([0, 0, 0, 0x20, 0x66, 0x74, 0x79, 0x70, 0x4D]), 'audio/mp4');
    expect(audioMimeType([1, 2, 3]), isNull);
  });
}
