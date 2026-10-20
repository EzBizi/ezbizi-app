import 'api_client.dart';

/// A bridge camera's live URL (camera bridge, phase 1). Good until [expiresAt]
/// to start a stream; a stream already running carries on.
class CameraPlay {
  const CameraPlay(this.url, this.expiresAt);

  final String url;
  final DateTime expiresAt;
}

class CameraWatchException implements Exception {
  const CameraWatchException(this.code, this.message, {this.lastSeen});

  /// `bridge_offline`, or `error` for anything else.
  final String code;
  final String message;

  /// When the site's bridge PC last checked in, for `bridge_offline`.
  final DateTime? lastSeen;

  @override
  String toString() => message;
}

/// Ask to watch [cameraId]. Call again every ~30 s while it is on screen:
/// that keeps the bridge streaming it.
Future<CameraPlay> watchCamera(
  ApiClient api,
  String cameraId, {
  String quality = 'sub',
}) async {
  final r = await api.post('/monitoring/cameras/$cameraId/watch', {
    'quality': quality,
  });
  final play = r.body['play'];
  if (r.ok && play is Map) {
    return CameraPlay(
      play['url'] as String,
      DateTime.parse(play['expires_at'] as String),
    );
  }
  final seen = r.body['last_checkin_at'];
  throw CameraWatchException(
    r.statusCode == 409 ? (r.body['code'] as String? ?? 'error') : 'error',
    r.message,
    lastSeen: seen is String ? DateTime.tryParse(seen) : null,
  );
}
