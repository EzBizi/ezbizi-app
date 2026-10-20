import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/camera_bridge/bridge_api.dart';
import 'package:ezbizi_client/core/camera_bridge/bridge_store.dart';
import 'package:ezbizi_client/core/camera_bridge/camera_bridge_service.dart';
import 'package:ezbizi_client/core/camera_bridge/discovery.dart';
import 'package:ezbizi_client/core/camera_bridge/engine.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _Store implements BridgeStore {
  String? key = 'k';
  @override
  Future<String?> siteKey() async => key;
  @override
  Future<void> saveSiteKey(String k) async => key = k;
  @override
  Future<void> clearSiteKey() async => key = null;
  @override
  Future<Recorder?> recorder() async =>
      const Recorder(host: '192.168.1.50', username: 'admin', password: 'p@ss');
  @override
  Future<void> saveRecorder(Recorder r) async {}
  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class _Engine extends Engine {
  _Engine() : super(exe: 'x', configFile: File('unused'));
  final applied = <List<WantedCamera>>[];
  List<Map<String, String>> report = const [];
  bool stopped = false;

  @override
  Future<void> apply(List<WantedCamera> wanted, Recorder? recorder) async =>
      applied.add(wanted);
  @override
  Future<List<Map<String, String>>> health() async => report;
  @override
  void stop() => stopped = true;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

Map<String, dynamic> _wanted(String quality) => {
  'camera_id': 'c1',
  'channel': '3',
  'quality': quality,
  'publish': {
    'url': 'rtmps://relay.test:1936/cam-c1-$quality?user=bridge&pass=t',
    'token': 't',
  },
};

void main() {
  test(
    'the wanted set from work reaches the engine, then an empty set idles it',
    () async {
      final replies = [
        _json({
          'version': 1,
          'wanted': [_wanted('sub')],
        }),
        _json({'version': 2, 'wanted': []}),
        _json({'code': 'bridge_unpaired'}, 401), // ends the loop
      ];
      final engine = _Engine();
      final s = CameraBridgeService(
        api: BridgeApi(
          'k',
          client: MockClient(
            (r) async =>
                r.url.path.endsWith('/work') ? replies.removeAt(0) : _json({}),
          ),
        ),
        store: _Store(),
        engine: engine,
        discover: () async => const [],
        sleep: (_) async {},
      );
      await s.start();
      await pumpEventQueue(times: 50);

      expect(engine.applied.length, 2);
      expect(engine.applied[0].single.path, 'cam-c1-sub');
      expect(engine.applied[0].single.channel, '3');
      expect(engine.applied[1], isEmpty);
    },
  );

  test('sub asks the recorder for subtype=1, main for subtype=0', () {
    const r = Recorder(
      host: '192.168.1.50',
      username: 'admin',
      password: 'p@ss',
    );
    expect(
      recorderRtsp(r, '3', 'sub'),
      'rtsp://admin:p%40ss@192.168.1.50:554/cam/realmonitor?channel=3&subtype=1',
    );
    expect(recorderRtsp(r, '3', 'main'), endsWith('channel=3&subtype=0'));

    final yaml = go2rtcConfig(r, [WantedCamera.fromJson(_wanted('sub'))]);
    expect(
      yaml,
      contains(
        'cam-c1-sub: "rtsp://admin:p%40ss@192.168.1.50:554/cam/realmonitor?channel=3&subtype=1"',
      ),
    );
    expect(
      yaml,
      contains(
        'cam-c1-sub: "rtmps://relay.test:1936/cam-c1-sub?user=bridge&pass=t"',
      ),
    );
    expect(yaml, contains('listen: "127.0.0.1:1984"'));
  });

  test(
    'an auth failure from the engine is carried by the next checkin',
    () async {
      Map<String, dynamic>? sent;
      final engine = _Engine()
        ..report = [
          {'camera_id': 'c1', 'status': 'auth_failed'},
        ];
      final s = CameraBridgeService(
        api: BridgeApi(
          'k',
          client: MockClient((r) async {
            sent = jsonDecode(r.body) as Map<String, dynamic>;
            return http.Response('', 204);
          }),
        ),
        store: _Store(),
        engine: engine,
        discover: () async => [
          const FoundDevice(
            key: 'u',
            address: '192.168.1.50',
            kind: 'recorder',
          ),
        ],
      );
      await s.checkin();

      expect(sent!['cameras'], [
        {'camera_id': 'c1', 'status': 'auth_failed'},
      ]);
      expect((sent!['found'] as List).single['address'], '192.168.1.50');
    },
  );

  test('a 401 bridge_unpaired stops the service and forgets the key', () async {
    final store = _Store();
    final engine = _Engine();
    final s = CameraBridgeService(
      api: BridgeApi(
        'k',
        client: MockClient(
          (_) async => _json({'code': 'bridge_unpaired'}, 401),
        ),
      ),
      store: store,
      engine: engine,
      discover: () async => const [],
    );
    await s.checkin();

    expect(store.key, isNull);
    expect(engine.stopped, isTrue);
    expect(s.state.value, BridgeState.unpaired);
  });

  test('work failures back off 1, 2, 4 … 30 s and never spin', () async {
    var calls = 0;
    final sleeps = <int>[];
    final s = CameraBridgeService(
      api: BridgeApi(
        'k',
        client: MockClient((r) async {
          if (!r.url.path.endsWith('/work')) return http.Response('', 204);
          return ++calls > 7 ? _json({}, 401) : http.Response('down', 502);
        }),
      ),
      store: _Store(),
      engine: _Engine(),
      discover: () async => const [],
      sleep: (d) async => sleeps.add(d.inSeconds),
    );
    await s.start();
    await pumpEventQueue(times: 100);

    expect(sleeps, [1, 2, 4, 8, 16, 30, 30]);
  });
}
