import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The recorder (or camera) the bridge pulls from, as typed on the bridge PC.
/// Never leaves this PC: EzBizi's server only ever sees channel numbers.
class Recorder {
  const Recorder({
    required this.host,
    required this.username,
    required this.password,
  });

  final String host;
  final String username;
  final String password;
}

/// The site key and recorder login, in the OS keychain (Windows Credential
/// Manager). ponytail: one recorder per site; a list when a site has two.
class BridgeStore {
  BridgeStore([FlutterSecureStorage? storage])
    : _s = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _s;

  static const _key = 'ezbizi.camera_bridge.site_key';
  static const _host = 'ezbizi.camera_bridge.recorder_host';
  static const _user = 'ezbizi.camera_bridge.recorder_user';
  static const _pass = 'ezbizi.camera_bridge.recorder_pass';

  Future<String?> siteKey() => _s.read(key: _key);
  Future<void> saveSiteKey(String key) => _s.write(key: _key, value: key);
  Future<void> clearSiteKey() => _s.delete(key: _key);

  Future<Recorder?> recorder() async {
    final host = await _s.read(key: _host);
    if (host == null || host.isEmpty) return null;
    return Recorder(
      host: host,
      username: await _s.read(key: _user) ?? '',
      password: await _s.read(key: _pass) ?? '',
    );
  }

  Future<void> saveRecorder(Recorder r) async {
    await _s.write(key: _host, value: r.host);
    await _s.write(key: _user, value: r.username);
    await _s.write(key: _pass, value: r.password);
  }
}
