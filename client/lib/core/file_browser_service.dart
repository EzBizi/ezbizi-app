import 'dart:async';
import 'dart:convert';

import 'api_client.dart';
import 'file_access_types.dart';

/// The VIEWER half of remote file access (2026-10-01-monitoring-files) — a
/// manager browsing and downloading a monitored machine's files. The agent half
/// (file_access_service.dart) answers; this asks.
///
/// It is a round trip over a poll channel, so each call is two steps: POST the
/// request, then poll the per-request result slot until the agent has answered.
/// The backend contract:
///   POST /monitoring/devices/{id}/files {op,path} -> {data:{request_id}}
///   GET  /monitoring/devices/{id}/files/{rid}     -> {data:{status:pending|ready|error,...}}
///
/// A device that never answers (offline, or the agent not running) is the case
/// the poll budget is for: after [maxPolls] still-pending reads the call gives
/// up with `offline`, which the screen shows rather than spinning forever.
class FileBrowserService {
  FileBrowserService(
    this._api, {
    Duration pollInterval = const Duration(milliseconds: 900),
    int maxPolls = 12,
  })  : _pollInterval = pollInterval,
        _maxPolls = maxPolls;

  final ApiClient _api;
  final Duration _pollInterval;
  final int _maxPolls;

  /// Opens Files with a current authenticator code (Jose, 2026-10-12). Null on
  /// success — Files then stays open until 15 minutes pass with no request —
  /// or the server's sentence to show under the code field ("That code did
  /// not verify…", "Too many attempts…").
  Future<String?> unlock(String code) async {
    final res = await _api.post('/monitoring/files/unlock', {'code': code.trim()});
    if (res.ok) _noteUntil(res.data);
    return res.ok ? null : res.reason;
  }

  /// "Lock now" — ends the unlock on the SERVER, not just on this screen.
  Future<void> lock() async {
    await _api.delete('/monitoring/files/unlock');
    _unlockedUntil = null;
  }

  /// When Files locks again, by the server's clock — set by [unlock] and
  /// refreshed by every file request (the unlock slides with use). Null when
  /// locked or not yet known. Read it after a call; never count it down from a
  /// client-side guess.
  DateTime? get unlockedUntil => _unlockedUntil;
  DateTime? _unlockedUntil;

  void _noteUntil(Object? data) {
    final raw = data is Map ? data['unlocked_until'] : null;
    final at = raw is String ? DateTime.tryParse(raw) : null;
    if (at != null) _unlockedUntil = at;
  }

  /// A directory's entries, or a [FileAccessException] with the agent's slug.
  /// An empty [path] asks for the roots (the drives). Slug `locked` means Files
  /// needs an authenticator code first ([unlock]).
  Future<FileListing> list(String deviceId, String path) async {
    final answer = await _run(deviceId, 'list', path);

    return FileListing(
      path: answer['path'] as String? ?? path,
      quickLinks: [
        for (final raw in (answer['quick_links'] as List? ?? const []))
          if (raw is Map && raw['path'] is String)
            QuickLink(
              label: raw['label'] as String? ?? raw['path'] as String,
              path: raw['path'] as String,
            ),
      ],
      entries: [
        for (final raw in (answer['entries'] as List? ?? const []))
          if (raw is Map)
            FileEntry(
              name: raw['name'] as String? ?? '',
              isDir: raw['is_dir'] == true,
              sizeBytes: (raw['size_bytes'] as num?)?.toInt(),
              modifiedAt: raw['modified_at'] as String?,
            ),
      ],
    );
  }

  /// A file's bytes (base64-decoded), or a [FileAccessException].
  Future<FileDownload> download(String deviceId, String path) async {
    final answer = await _run(deviceId, 'download', path);

    return FileDownload(
      name: answer['name'] as String? ?? _basename(path),
      bytes: base64OrEmpty(answer['content_base64'] as String?),
    );
  }

  /// POST the request, then poll its slot until the agent answers or the budget
  /// runs out. An `error` answer is thrown as a [FileAccessException] carrying
  /// the slug; exhausting the budget throws `offline`.
  Future<Map<String, dynamic>> _run(String deviceId, String op, String path) async {
    final posted = await _api.post('/monitoring/devices/$deviceId/files', {
      'op': op,
      'path': path,
    });

    if (!posted.ok) {
      // Locked behind the authenticator: the screen shows the code prompt.
      if (posted.body['code'] == 'files_locked') {
        _unlockedUntil = null;
        throw const FileAccessException('locked');
      }
      throw const FileAccessException('unreadable');
    }

    _noteUntil(posted.data);

    final requestId = posted.data is Map
        ? (posted.data as Map)['request_id'] as String?
        : null;

    if (requestId == null) {
      throw const FileAccessException('unreadable');
    }

    for (var i = 0; i < _maxPolls; i++) {
      await Future<void>.delayed(_pollInterval);

      final res = await _api.get('/monitoring/devices/$deviceId/files/$requestId');
      if (!res.ok || res.data is! Map) {
        continue;
      }

      final data = (res.data as Map).cast<String, dynamic>();
      final status = data['status'];

      if (status == 'pending') {
        continue;
      }

      if (status == 'error') {
        throw FileAccessException(data['error'] as String? ?? 'unreadable');
      }

      return data;
    }

    // No answer within the budget — the agent is not running or the device is
    // offline. The screen pairs this with last_seen_at to say which.
    throw const FileAccessException('offline');
  }

  static String _basename(String path) {
    final parts = path
        .replaceAll('\\', '/')
        .split('/')
        .where((s) => s.isNotEmpty)
        .toList();

    return parts.isEmpty ? path : parts.last;
  }
}

/// A directory listing.
class FileListing {
  const FileListing({
    required this.path,
    required this.entries,
    this.quickLinks = const [],
  });

  final String path;
  final List<FileEntry> entries;

  /// Explorer's Quick access — only on the roots ("This PC") listing, and only
  /// from agents new enough to send it; otherwise empty.
  final List<QuickLink> quickLinks;
}

/// One Quick access folder on the monitored machine (Desktop, Downloads…).
class QuickLink {
  const QuickLink({required this.label, required this.path});

  final String label;
  final String path;
}

/// One entry in a listing. Matches the backend's {name, is_dir, size_bytes,
/// modified_at}.
class FileEntry {
  const FileEntry({
    required this.name,
    required this.isDir,
    this.sizeBytes,
    this.modifiedAt,
  });

  final String name;
  final bool isDir;
  final int? sizeBytes;
  final String? modifiedAt;
}

/// A downloaded file's bytes, ready for [Files.download].
class FileDownload {
  const FileDownload({required this.name, required this.bytes});

  final String name;
  final List<int> bytes;
}

/// Decode base64, tolerant of a null or malformed blob (an empty file, or an
/// answer that somehow carried no content) — an empty list rather than a throw,
/// so a zero-byte download saves a zero-byte file instead of failing.
List<int> base64OrEmpty(String? encoded) {
  if (encoded == null || encoded.isEmpty) return const [];
  try {
    return base64Decode(encoded);
  } catch (_) {
    return const [];
  }
}
