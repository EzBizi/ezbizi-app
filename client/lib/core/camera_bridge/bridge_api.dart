import 'dart:convert';

import 'package:http/http.dart' as http;

import '../api_client.dart';

/// The site key stopped working (unpaired or disconnected in EzBizi).
class BridgeUnpaired implements Exception {
  const BridgeUnpaired();
}

class WantedCamera {
  const WantedCamera({
    required this.cameraId,
    required this.channel,
    required this.quality,
    required this.publishUrl,
  });

  factory WantedCamera.fromJson(Map<String, dynamic> j) => WantedCamera(
    cameraId: j['camera_id'] as String,
    channel: j['channel'] as String? ?? '1',
    quality: j['quality'] as String,
    publishUrl: (j['publish'] as Map)['url'] as String,
  );

  final String cameraId;
  final String channel;

  /// `sub` (tiles) or `main` (the tapped camera).
  final String quality;
  final String publishUrl;

  /// The relay path, `cam-<id>-<quality>` — also the go2rtc stream name.
  String get path => 'cam-$cameraId-$quality';
}

/// The bridge PC's own endpoints (server: CameraBridgeController), with the
/// site key instead of a person's login.
class BridgeApi {
  BridgeApi(this.siteKey, {http.Client? client})
    : _http = client ?? http.Client();

  final String siteKey;
  final http.Client _http;

  Uri _uri(String path, [Map<String, String>? q]) => Uri.parse(
    '${ApiClient.baseUrl}/api/v1/monitoring/bridge$path',
  ).replace(queryParameters: q);

  Map<String, String> get _headers => {
    'Authorization': 'Bridge $siteKey',
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  Future<void> checkin(Map<String, dynamic> body) async {
    _check(
      await _http.post(
        _uri('/checkin'),
        headers: _headers,
        body: jsonEncode(body),
      ),
    );
  }

  /// Long-poll: returns when the wanted set moves past [since], or after the
  /// server's hold (~25 s).
  Future<({int version, List<WantedCamera> wanted})> work(int since) async {
    final r = _check(
      await _http
          .get(_uri('/work', {'since': '$since'}), headers: _headers)
          .timeout(const Duration(seconds: 60)),
    );
    final j = jsonDecode(r.body) as Map<String, dynamic>;
    return (
      version: j['version'] as int,
      wanted: [
        for (final w in j['wanted'] as List)
          WantedCamera.fromJson(w as Map<String, dynamic>),
      ],
    );
  }

  http.Response _check(http.Response r) {
    if (r.statusCode == 401) throw const BridgeUnpaired();
    if (r.statusCode >= 300) {
      throw http.ClientException('HTTP ${r.statusCode}', r.request?.url);
    }
    return r;
  }
}
