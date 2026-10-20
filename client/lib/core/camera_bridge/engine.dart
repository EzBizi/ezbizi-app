import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'bridge_api.dart';
import 'bridge_store.dart';

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
/// ponytail: a change restarts the process (sub-second); go2rtc's live config
/// API if restarts ever drop a stream someone else is watching.
class Engine {
  Engine({required this.exe, required this.configFile});

  final String exe;
  final File configFile;

  Process? _proc;
  String _applied = '';
  List<WantedCamera> _wanted = const [];
  final Set<String> _authFailed = {};

  String get version => '1.9.14';

  /// Idempotent: the same set twice does nothing.
  Future<void> apply(List<WantedCamera> wanted, Recorder? recorder) async {
    final config = recorder == null || wanted.isEmpty
        ? ''
        : go2rtcConfig(recorder, wanted);
    if (config == _applied) return;
    _applied = config;
    _wanted = wanted;
    _authFailed.clear();
    _proc?.kill();
    _proc = null;
    if (config.isEmpty) return; // idle: nothing wanted

    await configFile.writeAsString(config, flush: true);
    final p = await Process.start(exe, ['-config', configFile.path]);
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

  void stop() {
    _proc?.kill();
    _proc = null;
    _applied = '';
  }
}
