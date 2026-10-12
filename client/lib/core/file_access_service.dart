import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;

import 'api_client.dart';
import 'device_key.dart';
import 'file_access_reader.dart';
import 'file_access_types.dart';
import 'screenshot_capability.dart';

/// Remote file access — the AGENT half (2026-10-01-monitoring-files). The viewer
/// half is the Flutter Files screen; the backend contract both sides implement:
/// `GET /monitoring/file-requests?device_key={key}` returns AND clears this
/// device's pending requests as `{"requests":[{id,op,path}]}`, each is run on
/// this machine's own disk via [FileAccessReader], and the answer is POSTed to
/// `/monitoring/file-requests/{id}`.
///
/// ── The same adaptive poll as Remote Control ────────────────────────
///
/// This is [RemoteControlService]'s loop with a different question: one
/// self-rescheduling [Timer] at [_slow] (~1.5s) baseline that drops to [_fast]
/// (~400ms) the moment a poll returns work, so a manager clicking through
/// folders gets each answer back quickly, and an agent nobody is browsing costs
/// one cheap poll a second and change. A websocket would replace the loop for
/// true low latency; that is the upgrade if this ever feels slow, not a knob to
/// shrink here.
///
/// ── Read-only, and the server owns the real cap ─────────────────────
///
/// The reader is handed [_maxDownloadBytes] so it refuses an over-size file as
/// `too_large` before reading it, matching the server's inline ceiling. There
/// is no write path: the loop only ever calls [FileAccessReader.list] and
/// [FileAccessReader.read], so there is nothing here that could create, move or
/// delete a file even if a request asked it to — the server names the two
/// operations and this honours exactly those two.
///
/// ── Windows-only, and never on web ──────────────────────────────────
///
/// Same guard as [RemoteControlService] and [ScreenshotService]: only the
/// Windows agent should poll `file-requests` and only it has a real
/// [FileAccessReader] (every other build gets the refusing stub through the
/// conditional import). An explicitly injected [reader] bypasses the guard —
/// always a test.
class FileAccessService {
  FileAccessService(
    this._api, {
    Duration fast = const Duration(milliseconds: 400),
    Duration slow = const Duration(milliseconds: 1500),
    int idleTicksBeforeSlow = 3,
    FileAccessReader? reader,
  })  : _fast = fast,
        _slow = slow,
        _idleTicksBeforeSlow = idleTicksBeforeSlow,
        _reader = reader ?? defaultFileAccessReader {
    // No reader injected AND not the Windows agent: do not start. Nothing else
    // can answer a file request, so polling for one would be a loop that pulls
    // work it can only ever fail.
    if (reader == null && !screenshotCaptureSupported) return;

    _interval = slow;
    _scheduleNext();
  }

  final ApiClient _api;
  final Duration _fast;
  final Duration _slow;
  final int _idleTicksBeforeSlow;
  final FileAccessReader _reader;

  /// Matches FilesController::MAX_DOWNLOAD_BYTES — the reader refuses above it,
  /// and the server refuses again, so a file over the cap never reaches the
  /// result slot from either side.
  static const int _maxDownloadBytes = 5 * 1024 * 1024;

  Timer? _timer;
  Duration _interval = const Duration(milliseconds: 1500);
  int _idleTicks = 0;
  bool _disposed = false;

  void _scheduleNext() {
    if (_disposed) return;
    _timer = Timer(_interval, _tick);
  }

  Future<void> _tick() async {
    if (_disposed) return;

    try {
      final deviceKey = await DeviceKey.value();

      final response = await _api.get('/monitoring/file-requests', {
        'device_key': deviceKey,
      });

      // Not ok covers a 404 (never heartbeat-registered) and the 403 an exempt
      // device gets, alike: neither is work, and neither should knock the
      // cadence off baseline — just try again next tick.
      if (response.ok) {
        final requests = _requestsFrom(response.body['requests']);

        for (final request in requests) {
          await _answer(deviceKey, request);
        }

        // Work present → quicken; sustained quiet → fall back, the same idle
        // bookkeeping Remote Control uses.
        if (requests.isNotEmpty) {
          _idleTicks = 0;
          _interval = _fast;
        } else if (++_idleTicks >= _idleTicksBeforeSlow) {
          _interval = _slow;
        }
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('FileAccessService: poll failed: $e');
      }
    } finally {
      _scheduleNext();
    }
  }

  /// Run one request and POST its answer. An error the reader raises becomes the
  /// manager-facing `{status:error, error:slug}`; anything unexpected is an
  /// honest `unreadable` rather than a dropped request the manager waits on
  /// forever.
  Future<void> _answer(String deviceKey, Map<String, Object?> request) async {
    final id = request['id'] as String?;
    final op = request['op'] as String?;
    final path = (request['path'] as String?) ?? '';

    if (id == null || op == null) return;

    Map<String, dynamic> answer;

    try {
      if (op == 'list') {
        final entries = await _reader.list(path);
        answer = {
          'device_key': deviceKey,
          'status': 'ready',
          'op': 'list',
          'path': path,
          'entries': entries,
          // This PC carries Explorer's Quick access alongside the drives.
          if (path.isEmpty) 'quick_links': await _reader.quickLinks(),
        };
      } else {
        final file = await _reader.read(path, _maxDownloadBytes);
        answer = {
          'device_key': deviceKey,
          'status': 'ready',
          'op': 'download',
          ...file,
        };
      }
    } on FileAccessException catch (ex) {
      answer = {
        'device_key': deviceKey,
        'status': 'error',
        'op': op,
        'error': ex.slug,
        'path': path,
      };
    } catch (_) {
      answer = {
        'device_key': deviceKey,
        'status': 'error',
        'op': op,
        'error': 'unreadable',
        'path': path,
      };
    }

    await _api.post('/monitoring/file-requests/$id', answer);
  }

  /// Rebuilt rather than cast so one malformed request is dropped instead of
  /// throwing the whole tick into the catch above.
  static List<Map<String, Object?>> _requestsFrom(Object? raw) => [
        if (raw is List)
          for (final request in raw)
            if (request is Map) Map<String, Object?>.from(request),
      ];

  /// Stops the poll loop; same shape as [RemoteControlService.dispose]. There is
  /// no transient state to clear — an in-flight answer simply finishes or is
  /// abandoned, and the server's result slot expires on its own TTL.
  void dispose() {
    _disposed = true;
    _timer?.cancel();
    _timer = null;
  }
}
