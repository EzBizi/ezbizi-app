import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'bridge_api.dart';
import 'bridge_store.dart';

/// Where the bundled go2rtc sits: `engine\go2rtc.exe` beside the app.
String enginePathFor(String appExe) {
  final i = appExe.lastIndexOf(RegExp(r'[\\/]'));
  final sep = i < 0 ? r'\' : appExe[i];
  return '${appExe.substring(0, i + 1)}engine${sep}go2rtc.exe';
}

/// The RTSP URL for one recorder channel. Amcrest/Dahua: subtype 0 is the
/// main stream, 1 the sub stream.
String recorderRtsp(Recorder r, String channel, String quality) {
  final user = Uri.encodeComponent(r.username);
  final pass = Uri.encodeComponent(r.password);
  final sub = quality == 'main' ? 0 : 1;
  return 'rtsp://$user:$pass@${r.host}:554/cam/realmonitor?channel=$channel&subtype=$sub';
}

/// go2rtc's config for exactly [wanted]: pull each from the recorder, publish
/// it to the relay. Loopback API only; no inbound listeners on the site PC.
String go2rtcConfig(Recorder r, List<WantedCamera> wanted) {
  final b = StringBuffer()
    ..writeln('api:')
    ..writeln('  listen: "127.0.0.1:1984"')
    ..writeln('rtsp:')
    ..writeln('  listen: ""')
    ..writeln('webrtc:')
    ..writeln('  listen: ""')
    ..writeln('streams:');
  for (final w in wanted) {
    b.writeln(
      '  ${w.path}: ${jsonEncode(recorderRtsp(r, w.channel, w.quality))}',
    );
  }
  b.writeln('publish:');
  for (final w in wanted) {
    b.writeln('  ${w.path}: ${jsonEncode(w.publishUrl)}');
  }
  return b.toString();
}

/// Runs go2rtc (bundled beside the app) for whatever is wanted right now.
///
/// ponytail: a change of cameras restarts the process (sub-second); go2rtc's
/// live config API if restarts ever drop a stream someone else is watching.
class Engine {
  Engine({
    required this.exe,
    required this.configFile,
    Future<Process> Function(String exe, List<String> args)? start,
    this.restartDelay = const Duration(seconds: 2),
  }) : _start = start ?? Process.start;

  final String exe;
  final File configFile;
  final Future<Process> Function(String, List<String>) _start;
  final Duration restartDelay;

  Process? _proc;
  String _key = '';
  bool _disposed = false;
  List<WantedCamera> _wanted = const [];
  Recorder? _recorder;
  final Set<String> _authFailed = {};

  String get version => '1.9.14';

  /// The binary is not where the installer puts it; the bridge says so in
  /// every check-in instead of trying to start it.
  bool get missing => !File(exe).existsSync();

  /// Idempotent. Keyed on the cameras, not their publish URLs: every
  /// long-poll answer mints a fresh token, and a running publish outlives
  /// the token it started with.
  Future<void> apply(List<WantedCamera> wanted, Recorder? recorder) async {
    if (_disposed || missing) return;
    final key = recorder == null || wanted.isEmpty
        ? ''
        : '${recorder.host}|${recorder.username}|${recorder.password}|'
              '${[for (final w in wanted) '${w.path}@${w.channel}'].join(',')}';
    if (key == _key) return;
    _key = key;
    _wanted = wanted;
    _recorder = recorder;
    _authFailed.clear();
    await _kill();
    if (key.isNotEmpty) await _launch();
  }

  Future<void> _launch() async {
    if (_disposed || _recorder == null || _wanted.isEmpty) return;
    await configFile.writeAsString(
      go2rtcConfig(_recorder!, _wanted),
      flush: true,
    );
    final p = await _start(exe, ['-config', configFile.path]);
    _proc = p;
    // go2rtc logs a refused login per stream; that is how auth_failed is known.
    for (final s in [p.stdout, p.stderr]) {
      s.transform(utf8.decoder).transform(const LineSplitter()).listen((line) {
        if (!line.contains('401')) return;
        for (final w in _wanted) {
          if (line.contains(w.path)) _authFailed.add(w.cameraId);
        }
      });
    }
    // Crashed (not killed by us): start it again with the latest config.
    unawaited(
      p.exitCode.then((_) async {
        if (!identical(_proc, p) || _disposed) return;
        _proc = null;
        await Future<void>.delayed(restartDelay);
        if (_proc == null && _key.isNotEmpty) await _launch();
      }),
    );
  }

  /// Kill and wait for it to exit, so the next one can bind 127.0.0.1:1984.
  Future<void> _kill() async {
    final p = _proc;
    _proc = null;
    if (p == null) return;
    p.kill();
    await p.exitCode.timeout(
      const Duration(seconds: 5),
      onTimeout: () {
        p.kill(ProcessSignal.sigkill);
        return -1;
      },
    );
  }

  /// Per wanted camera: `ok`, `unreachable` or `auth_failed`, from go2rtc's
  /// own API (a stream with a producer is flowing).
  Future<List<Map<String, String>>> health() async {
    if (_proc == null) return const [];
    Map<String, dynamic> streams = const {};
    try {
      final c = HttpClient()..connectionTimeout = const Duration(seconds: 2);
      final res = await (await c.getUrl(
        Uri.parse('http://127.0.0.1:1984/api/streams'),
      )).close();
      streams =
          jsonDecode(await res.transform(utf8.decoder).join())
              as Map<String, dynamic>;
      c.close();
    } catch (_) {}
    return [
      for (final w in _wanted)
        {
          'camera_id': w.cameraId,
          'status': _authFailed.contains(w.cameraId)
              ? 'auth_failed'
              : ((streams[w.path] as Map?)?['producers'] as List?)
                        ?.isNotEmpty ==
                    true
              ? 'ok'
              : 'unreachable',
        },
    ];
  }

  /// For good: a late [apply] (a long-poll still in flight) starts nothing.
  void stop() {
    _disposed = true;
    _key = '';
    unawaited(_kill());
  }
}
