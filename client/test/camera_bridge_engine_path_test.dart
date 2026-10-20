import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/camera_bridge/bridge_api.dart';
import 'package:ezbizi_client/core/camera_bridge/bridge_store.dart';
import 'package:ezbizi_client/core/camera_bridge/camera_bridge_service.dart';
import 'package:ezbizi_client/core/camera_bridge/engine.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('the engine lives in engine/ beside the executable', () {
    final p = enginePathFor(r'C:\Program Files\EzBizi\ezbizi.exe');
    expect(p, r'C:\Program Files\EzBizi\engine\go2rtc.exe');
  });

  test(
    'a missing engine is reported as engine_missing, not looped on',
    () async {
      final engine = Engine(
        exe: r'C:\nowhere\go2rtc.exe',
        configFile: File('unused'),
      );
      expect(engine.missing, isTrue);
      await engine.apply(const [], null); // no throw, no process

      Map<String, dynamic>? sent;
      final s = CameraBridgeService(
        api: BridgeApi(
          'k',
          client: MockClient((r) async {
            sent = jsonDecode(r.body) as Map<String, dynamic>;
            return http.Response('', 204);
          }),
        ),
        store: BridgeStore(),
        engine: engine,
        discover: () async => const [],
      );
      await s.checkin();
      expect(sent!['reason'], 'engine_missing');
    },
  );
}
