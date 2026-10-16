// Field safety recordings on the phone (ported from A1 Tools, 2026-10-16).
// docs/superpowers/specs/2026-09-25-field-module-design.md, recordings addendum.
//
// One-minute AAC files. Each is written to app support with a small JSON
// sidecar, sent, and deleted once the server has it, so a crash, a dead zone or
// a killed app costs at most the minute being recorded. The next launch sends
// whatever is left and closes the recording as `recovered`.

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/widgets.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

import 'api_client.dart';

enum FieldRecorderStatus { idle, recording, paused }

@immutable
class FieldRecorderState {
  const FieldRecorderState({
    this.status = FieldRecorderStatus.idle,
    this.startedAt,
    this.jobId,
    this.unsent = 0,
    this.error,
  });

  final FieldRecorderStatus status;
  final DateTime? startedAt;
  final String? jobId;

  /// Minutes recorded and not yet on the server, across every recording.
  final int unsent;

  /// Why the last start failed, in words for the technician.
  final String? error;

  FieldRecorderState copyWith({
    FieldRecorderStatus? status,
    DateTime? startedAt,
    String? jobId,
    int? unsent,
    String? error,
  }) => FieldRecorderState(
    status: status ?? this.status,
    startedAt: startedAt ?? this.startedAt,
    jobId: jobId ?? this.jobId,
    unsent: unsent ?? this.unsent,
    error: error,
  );
}

/// What the recorder needs from the `record` plugin; a fake stands in for tests.
abstract class FieldMic {
  Future<bool> hasPermission();
  Future<void> start(String path);
  Future<void> stop();
  Future<void> pause();
  Future<void> resume();
  Future<bool> isRecording();
}

class _PluginMic implements FieldMic {
  final _recorder = AudioRecorder();

  @override
  Future<bool> hasPermission() => _recorder.hasPermission();

  @override
  Future<void> start(String path) => _recorder.start(
    const RecordConfig(
      encoder: AudioEncoder.aacLc,
      numChannels: 1,
      sampleRate: 22050,
      bitRate: 32000, // about 14 MB an hour
      audioInterruption: AudioInterruptionMode.pauseResume,
      iosConfig: IosRecordConfig(
        // pauseResume needs mixWithOthers (the plugin's rule).
        categoryOptions: [
          IosAudioCategoryOption.mixWithOthers,
          IosAudioCategoryOption.defaultToSpeaker,
        ],
      ),
    ),
    path: path,
  );

  @override
  Future<void> stop() => _recorder.stop();

  @override
  Future<void> pause() => _recorder.pause();

  @override
  Future<void> resume() => _recorder.resume();

  @override
  Future<bool> isRecording() => _recorder.isRecording();
}

class FieldRecorder with WidgetsBindingObserver {
  FieldRecorder(
    this._api, {
    FieldMic? mic,
    Future<Directory> Function()? root,
    DateTime Function()? clock,
    this.chunk = const Duration(minutes: 1),
  }) : _mic = mic ?? _PluginMic(),
       _root = root ?? _defaultRoot,
       _now = clock ?? DateTime.now;

  static Future<Directory> _defaultRoot() async =>
      Directory('${(await getApplicationSupportDirectory()).path}/field-audio');

  /// The phone stops by itself after this long (the server's MAX_HOURS).
  static const maxLength = Duration(hours: 4);

  final ApiClient _api;
  final FieldMic _mic;
  final Future<Directory> Function() _root;
  final DateTime Function() _now;
  final Duration chunk;

  final state = ValueNotifier(const FieldRecorderState());

  String? _id;
  Directory? _dir;
  int _seq = 0;
  DateTime? _chunkStartedAt;
  Duration _pausedInChunk = Duration.zero;
  DateTime? _pausedAt;
  final List<Map<String, String>> _events = [];
  Timer? _rotate;
  Timer? _ceiling;
  bool _sending = false;

  bool get active => state.value.status != FieldRecorderStatus.idle;

  /// Send what an earlier run left behind. Call once at startup.
  Future<void> recover() async {
    final root = await _root();
    if (!await root.exists()) return;
    await for (final entity in root.list()) {
      if (entity is! Directory || entity.path == _dir?.path) continue;
      final meta = File('${entity.path}/meta.json');
      if (!await meta.exists()) continue;
      final m = jsonDecode(await meta.readAsString()) as Map<String, dynamic>;
      if (m['ended_at'] == null) {
        m['ended_at'] = _now().toUtc().toIso8601String();
        m['stop_reason'] = 'recovered';
        await meta.writeAsString(jsonEncode(m));
      }
    }
    await send();
  }

  /// [customerTold]: the technician confirmed they said the organization's
  /// notice (needed where /field/me says `recording.announce`). Starting at
  /// all means they confirmed the disclosure; the card asks before calling.
  Future<bool> start({String? jobId, bool customerTold = false}) async {
    if (active) return true;
    if (!await _mic.hasPermission()) {
      state.value = state.value.copyWith(
        error:
            'EzBizi needs the microphone. Allow it in the phone\'s settings.',
      );
      return false;
    }
    final now = _now();
    final response = await _api.post('/field/recordings', {
      'started_at': now.toUtc().toIso8601String(),
      'job_id': ?jobId,
      'disclosure_confirmed': true,
      'customer_told': customerTold,
    });
    if (!response.ok) {
      state.value = state.value.copyWith(error: response.message);
      return false;
    }
    _id = (response.body['data'] as Map)['id'] as String;
    _dir = Directory('${(await _root()).path}/$_id');
    await _dir!.create(recursive: true);
    _seq = 0;
    _events.clear();
    await _writeMeta();
    await _startChunk();
    _rotate = Timer.periodic(chunk, (_) => unawaited(_nextChunk()));
    _ceiling = Timer(maxLength, () => unawaited(stop('max_duration')));
    WidgetsBinding.instance.addObserver(this);
    state.value = FieldRecorderState(
      status: FieldRecorderStatus.recording,
      startedAt: now,
      jobId: jobId,
      unsent: state.value.unsent,
    );
    return true;
  }

  Future<void> pause() async {
    if (state.value.status != FieldRecorderStatus.recording) return;
    await _mic.pause();
    _pausedAt = _now();
    _event('pause');
    state.value = state.value.copyWith(status: FieldRecorderStatus.paused);
  }

  Future<void> resume() async {
    if (state.value.status != FieldRecorderStatus.paused) return;
    await _mic.resume();
    if (_pausedAt != null) _pausedInChunk += _now().difference(_pausedAt!);
    _pausedAt = null;
    _event('resume');
    state.value = state.value.copyWith(status: FieldRecorderStatus.recording);
  }

  /// Drops the timers without closing anything, as a killed app would.
  void dispose() {
    _rotate?.cancel();
    _ceiling?.cancel();
    WidgetsBinding.instance.removeObserver(this);
  }

  /// [reason] is one of the server's STOP_REASONS.
  Future<void> stop(String reason) async {
    if (!active) return;
    _rotate?.cancel();
    _ceiling?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    await _endChunk();
    _event('stop');
    await _writeMeta(endedAt: _now(), reason: reason);
    _id = null;
    _dir = null;
    state.value = FieldRecorderState(unsent: state.value.unsent);
    await send();
  }

  /// An answered call can suspend the app with no "interruption ended" from
  /// iOS: on return, a recorder that stopped is restarted on a new file and
  /// the hole is marked.
  @override
  void didChangeAppLifecycleState(AppLifecycleState lifecycle) {
    if (lifecycle != AppLifecycleState.resumed ||
        state.value.status != FieldRecorderStatus.recording) {
      return;
    }
    unawaited(() async {
      if (await _mic.isRecording()) return;
      _event('gap_phone_call');
      await _nextChunk();
    }());
  }

  Future<void> _startChunk() async {
    _chunkStartedAt = _now();
    _pausedInChunk = Duration.zero;
    await _mic.start('${_dir!.path}/$_seq.m4a');
  }

  Future<void> _endChunk() async {
    final started = _chunkStartedAt;
    if (started == null || _dir == null) return;
    if (_pausedAt != null) _pausedInChunk += _now().difference(_pausedAt!);
    _pausedAt = null;
    await _mic.stop();
    final length = _now().difference(started) - _pausedInChunk;
    await File('${_dir!.path}/$_seq.json').writeAsString(
      jsonEncode({
        'seq': _seq,
        'started_at': started.toUtc().toIso8601String(),
        'duration_ms': length.inMilliseconds.clamp(0, 600000),
      }),
    );
    _chunkStartedAt = null;
    _seq++;
  }

  Future<void> _nextChunk() async {
    if (!active) return;
    final paused = state.value.status == FieldRecorderStatus.paused;
    await _endChunk();
    await _startChunk();
    if (paused) {
      await _mic.pause();
      _pausedAt = _now();
    }
    await _writeMeta();
    unawaited(send());
  }

  void _event(String type) =>
      _events.add({'at': _now().toUtc().toIso8601String(), 'type': type});

  Future<void> _writeMeta({DateTime? endedAt, String? reason}) =>
      File('${_dir!.path}/meta.json').writeAsString(
        jsonEncode({
          'id': _id,
          'events': _events,
          'ended_at': ?endedAt?.toUtc().toIso8601String(),
          'stop_reason': ?reason,
        }),
      );

  /// Send every finished minute, then close recordings with nothing left.
  /// Safe to call any time; one pass runs at once.
  Future<void> send() async {
    if (_sending) return;
    _sending = true;
    try {
      final root = await _root();
      if (!await root.exists()) return;
      var unsent = 0;
      await for (final entity in root.list()) {
        if (entity is! Directory) continue;
        unsent += await _sendRecording(entity);
      }
      state.value = state.value.copyWith(unsent: unsent);
    } finally {
      _sending = false;
    }
  }

  /// Sends one recording's finished minutes; answers how many are left.
  Future<int> _sendRecording(Directory dir) async {
    final id = dir.uri.pathSegments.lastWhere((s) => s.isNotEmpty);
    final sidecars = await dir
        .list()
        .where((e) => e is File && RegExp(r'[/\\]\d+\.json$').hasMatch(e.path))
        .cast<File>()
        .toList();
    var left = 0;
    for (final sidecar in sidecars) {
      final audio = File(sidecar.path.replaceFirst(RegExp(r'\.json$'), '.m4a'));
      final meta = jsonDecode(await sidecar.readAsString()) as Map;
      if (!await audio.exists()) {
        await sidecar.delete();
        continue;
      }
      final bytes = await audio.readAsBytes();
      final response = await _api.postFile(
        '/field/recordings/$id/chunks',
        'audio',
        '${meta['seq']}.m4a',
        bytes,
        fields: {
          'seq': '${meta['seq']}',
          'started_at': '${meta['started_at']}',
          'duration_ms': '${meta['duration_ms']}',
          'sha256': sha256.convert(bytes).toString(),
        },
      );
      if (response.ok) {
        await audio.delete();
        await sidecar.delete();
      } else if (response.statusCode == 403 || response.statusCode == 404) {
        // The recording is gone or not this person's: nothing will take it.
        await audio.delete();
        await sidecar.delete();
      } else {
        left++;
      }
    }

    final metaFile = File('${dir.path}/meta.json');
    if (left > 0 || id == _id || !await metaFile.exists()) return left;
    final meta = jsonDecode(await metaFile.readAsString()) as Map;
    if (meta['ended_at'] == null) return left;
    final response = await _api.post('/field/recordings/$id/finish', {
      'ended_at': meta['ended_at'],
      'stop_reason': meta['stop_reason'] ?? 'recovered',
      'events': meta['events'] ?? const [],
    });
    if (response.ok ||
        response.statusCode == 403 ||
        response.statusCode == 404) {
      await dir.delete(recursive: true);
    }
    return left;
  }
}
