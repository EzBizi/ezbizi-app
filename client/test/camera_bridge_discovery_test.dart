import 'dart:io';

import 'package:ezbizi_client/core/camera_bridge/discovery.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('a recorder ProbeMatch gives its address, model and kind', () {
    final d = parseProbeMatch(
      File('test/fixtures/onvif_probe_match_nvr.xml').readAsStringSync(),
    )!;
    expect(d.address, '192.168.1.50');
    expect(d.label, 'NV2108E-HS');
    expect(d.kind, 'recorder');
    expect(d.key, 'urn:uuid:4a4b6c00-1234-5678-9abc-9c8ecd000001');
  });

  test('a reply with no address is ignored', () {
    expect(parseProbeMatch('<x>nothing</x>'), isNull);
  });
}
