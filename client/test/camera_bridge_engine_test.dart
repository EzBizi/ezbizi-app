import 'dart:async';
import 'dart:io';

import 'package:ezbizi_client/core/camera_bridge/bridge_api.dart';
import 'package:ezbizi_client/core/camera_bridge/bridge_store.dart';
import 'package:ezbizi_client/core/camera_bridge/engine.dart';
import 'package:flutter_test/flutter_test.dart';

/// A go2rtc stand-in: exits when killed, or when the test says it crashed.
class _Proc implements Process {
  final _exit = Completer<int>();
  bool killed = false;
  void crash() => _exit.complete(1);
  @override
  bool kill([ProcessSignal signal = ProcessSignal.sigterm]) {
    killed = true;
    if (!_exit.isCompleted) _exit.complete(0);
    return true;
  }

  @override
  Future<int> get exitCode => _exit.future;
  @override
  Stream<List<int>> get stdout => const Stream.empty();
  @override
  Stream<List<int>> get stderr => const Stream.empty();
  @override
  int get pid => 4242;
  @override
  IOSink get stdin => throw UnimplementedError();
}

WantedCamera _w(String token) => WantedCamera(
  cameraId: 'c1',
  channel: '1',
  quality: 'sub',
  publishUrl: 'rtmps://relay.test:1936/cam-c1-sub?user=bridge&pass=$token',
);

const _rec = Recorder(host: '192.168.1.50', username: 'admin', password: 'p');

void main() {
  late Directory dir;
  late List<_Proc> started;
  late Engine engine;

  setUp(() {
    dir = Directory.systemTemp.createTempSync('engine_test');
    final exe = File('${dir.path}/go2rtc.exe')..writeAsStringSync('');
    started = [];
    engine = Engine(
      exe: exe.path,
      configFile: File('${dir.path}/go2rtc.yaml'),
      start: (_, _) async {
        final p = _Proc();
        started.add(p);
        return p;
      },
      restartDelay: Duration.zero,
    );
  });
  tearDown(() async {
    engine.stop();
    await Future<void>.delayed(const Duration(milliseconds: 300)); // a restart's write
    dir.deleteSync(recursive: true);
  });

  test(
    'a fresh publish token for the same cameras does not restart go2rtc',
    () async {
      await engine.apply([_w('t1')], _rec);
      await engine.apply([
        _w('t2'),
      ], _rec); // every long-poll answer mints a new one
      expect(started.length, 1);
    },
  );

  test('a different set restarts it, after the old one has exited', () async {
    await engine.apply([_w('t1')], _rec);
    await engine.apply([], _rec);
    expect(started.single.killed, isTrue);
    await engine.apply([_w('t3')], _rec);
    expect(started.length, 2);
  });

  test('a crashed go2rtc is started again', () async {
    await engine.apply([_w('t1')], _rec);
    started.single.crash();
    // The restart writes the config file: real I/O, not just microtasks.
    await Future<void>.delayed(const Duration(milliseconds: 300));
    expect(started.length, 2);
  });

  test('after stop, a late apply starts nothing', () async {
    engine.stop();
    await engine.apply([_w('t1')], _rec);
    expect(started, isEmpty);
  });
}
