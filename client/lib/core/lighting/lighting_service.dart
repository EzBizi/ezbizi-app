import 'dart:async';
import 'dart:io';

import '../api_client.dart';
import '../camera_bridge/engine.dart' show enginePathFor;
import '../device_key.dart';
import '../monitoring_heartbeat.dart';
import 'openrgb_client.dart';

/// What to do to one device for a wanted lighting.
sealed class LightingStep {}

class SetColor extends LightingStep {
  SetColor(this.r, this.g, this.b, this.brightness);
  final int r, g, b, brightness;
}

class UseMode extends LightingStep {
  UseMode(this.index, this.r, this.g, this.b, this.brightness);
  final int index, r, g, b, brightness;
}

/// Brand lighting apps that grab the same devices and fight OpenRGB.
const brandApps = {
  'icue.exe': 'Corsair iCUE',
  'armourycrate.service.exe': 'ASUS Armoury Crate',
  'lightingservice.exe': 'ASUS Aura',
  'mysticlight.exe': 'MSI Mystic Light',
  'msi center.exe': 'MSI Center',
  'rgbfusion.exe': 'Gigabyte RGB Fusion',
  'gcc.exe': 'Gigabyte Control Center',
  'signalrgb.exe': 'SignalRGB',
  'razer synapse service.exe': 'Razer Synapse',
  'lghub.exe': 'Logitech G HUB',
  'nzxt cam.exe': 'NZXT CAM',
  'polychrome rgb.exe': 'ASRock Polychrome',
};

/// The step for [wanted] ({mode, color, brightness}) on [c].
LightingStep planFor(Map<String, dynamic> wanted, OpenRgbController c) {
  final mode = wanted['mode'] as String? ?? 'off';
  final hex = (wanted['color'] as String? ?? '#FFFFFF').substring(1);
  final v = int.parse(hex, radix: 16);
  final (r, g, b) = ((v >> 16) & 0xFF, (v >> 8) & 0xFF, v & 0xFF);
  final bright = (wanted['brightness'] as num?)?.toInt() ?? 100;

  int find(List<String> names) => c.modes.indexWhere(
    (m) => names.any((n) => m.name.toLowerCase().contains(n)),
  );

  switch (mode) {
    case 'off':
      return SetColor(0, 0, 0, 0);
    case 'breathing':
      final i = find(['breathing', 'breath']);
      if (i >= 0) return UseMode(i, r, g, b, bright);
    case 'rainbow':
      final i = find(['rainbow', 'spectrum', 'cycle', 'wave']);
      if (i >= 0) return UseMode(i, 255, 255, 255, bright);
      return SetColor(255, 255, 255, bright);
  }
  return SetColor(r, g, b, bright);
}

/// Runs the bundled OpenRGB on this PC, reports its lights in specs, and
/// applies whatever lighting a manager set in EzBizi (polled every 10 s).
class LightingService {
  LightingService(this.api);

  final ApiClient api;
  Process? _proc;
  File? _exe;
  int _fails = 0;
  OpenRgbClient? _rgb;
  List<OpenRgbController> _devices = const [];
  int? _applied;
  Timer? _poll;
  int _ticks = 0;

  static LightingService? _instance;

  static void start(ApiClient api) {
    if (!Platform.isWindows || _instance != null) return;
    _instance = LightingService(api).._run();
  }

  static void stop() {
    _instance?._stop();
    _instance = null;
  }

  void _status(String s) => MonitoringHeartbeat.extraSpecs['rgb_status'] = s;

  Future<void> _run() async {
    final exe = File(
      enginePathFor(
        Platform.resolvedExecutable,
      ).replaceFirst(RegExp(r'go2rtc\.exe$'), r'openrgb\OpenRGB.exe'),
    );
    if (!exe.existsSync()) {
      _status('engine_missing');
      return;
    }
    _exe = exe;
    try {
      _rgb = await OpenRgbClient.connect(); // one already running
    } catch (_) {
      await _startEngine();
    }
    await _rescan();
    _poll = Timer.periodic(
      const Duration(seconds: 10),
      (_) => unawaited(_tick()),
    );
    unawaited(_tick());
  }

  Future<void> _startEngine() async {
    final exe = _exe!;
    _proc = await Process.start(exe.path, [
      '--server',
      '--server-port',
      '6742',
    ], workingDirectory: exe.parent.path);
  }

  /// A stuck engine (connects, never answers) is ours to restart: stop every
  /// OpenRGB running from OUR folder, by path, never by name (somebody's own
  /// OpenRGB is left alone), and start a fresh one.
  Future<void> _restartEngine() async {
    unawaited(_rgb?.close());
    _rgb = null;
    _proc?.kill();
    final path = _exe!.path.replaceAll("'", "''");
    await Process.run('powershell.exe', [
      '-NoProfile',
      '-NonInteractive',
      '-Command',
      "Get-Process OpenRGB -ErrorAction SilentlyContinue | "
          "Where-Object { \$_.Path -eq '$path' } | Stop-Process -Force",
    ]);
    await _startEngine();
  }

  /// The last lines of OpenRGB's own log, so a PC that will not answer says
  /// why (the agent cannot see detection from the socket).
  static String? openRgbLogTail() {
    try {
      final dir = Directory(
        '${Platform.environment['APPDATA']}\\OpenRGB\\logs',
      );
      final logs = dir.listSync().whereType<File>().toList()
        ..sort(
          (a, b) => a.statSync().modified.compareTo(b.statSync().modified),
        );
      if (logs.isEmpty) return null;
      final lines = logs.last.readAsLinesSync();
      return lines.skip(lines.length > 15 ? lines.length - 15 : 0).join('\n');
    } catch (_) {
      return null;
    }
  }

  Future<void> _connect() async {
    if (_rgb != null && !_rgb!.closed) return;
    for (var i = 0; i < 15; i++) {
      try {
        _rgb = await OpenRgbClient.connect();
        return;
      } catch (_) {
        await Future<void>.delayed(const Duration(seconds: 2));
      }
    }
  }

  Future<void> _rescan() async {
    try {
      await _connect();
      _devices = await _rgb!.controllers();
      MonitoringHeartbeat.extraSpecs['rgb_devices'] = [
        for (final d in _devices) d.toJson(),
      ];
      final running = await _brandAppRunning();
      _status(
        running != null
            ? 'brand_app_running:$running'
            : (_devices.isEmpty ? 'no_devices' : 'ok'),
      );
      _applied = null; // new devices get the current lighting too
      MonitoringHeartbeat.extraSpecs
        ..remove('rgb_error')
        ..remove('rgb_log');
      _fails = 0;
    } catch (e) {
      _status('engine_not_responding');
      MonitoringHeartbeat.extraSpecs['rgb_error'] = '$e';
      MonitoringHeartbeat.extraSpecs['rgb_log'] = openRgbLogTail();
      // Every third failure in a row, start the engine afresh.
      if (++_fails % 3 == 0 && _exe != null) await _restartEngine();
    }
  }

  Future<String?> _brandAppRunning() async {
    final r = await Process.run('tasklist', ['/fo', 'csv', '/nh']);
    final names = (r.stdout as String).toLowerCase();
    for (final e in brandApps.entries) {
      if (names.contains('"${e.key}"')) return e.value;
    }
    return null;
  }

  /// One tick at a time. The timer fires every 10 s whether or not the last
  /// tick finished; a rescan waiting on a slow OpenRGB overlapped the next,
  /// whose request replaced the first's pending reply on the one socket, so
  /// both timed out (12 PCs engine_not_responding, prod 2026-11-03).
  bool _busy = false;

  Future<void> _tick() async {
    if (_busy) return;
    _busy = true;
    try {
      await _tickOnce();
    } finally {
      _busy = false;
    }
  }

  Future<void> _tickOnce() async {
    final ok = MonitoringHeartbeat.extraSpecs['rgb_status'] == 'ok';
    if (_rgb?.listChanged == true || ++_ticks % (ok ? 60 : 6) == 0) {
      _rgb?.listChanged = false;
      await _rescan(); // every 10 min when fine, every minute while not
    }
    final r = await api.get('/monitoring/lighting', {
      'device_key': await DeviceKey.value(),
    });
    final wanted = r.ok ? r.body['lighting'] : null;
    if (wanted is! Map<String, dynamic>) return;
    final version = (wanted['version'] as num?)?.toInt();
    if (version == null || version == _applied) return;
    try {
      await _connect();
      for (final d in _devices) {
        switch (planFor(wanted, d)) {
          case SetColor(:final r, :final g, :final b, :final brightness):
            _rgb!.setColor(d, r, g, b, brightness: brightness);
          case UseMode(
            :final index,
            :final r,
            :final g,
            :final b,
            :final brightness,
          ):
            _rgb!.setMode(d, index, r, g, b);
            _rgb!.setColor(
              d,
              r,
              g,
              b,
              brightness: brightness,
            ); // per-LED colour, for modes that use it
        }
      }
      _applied = version;
    } catch (_) {
      _status('engine_not_responding');
    }
  }

  void _stop() {
    _poll?.cancel();
    unawaited(_rgb?.close());
    _proc?.kill();
  }
}
