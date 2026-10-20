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
  static const _site = 'ezbizi.camera_bridge.site_name';
  static const _off = 'ezbizi.camera_bridge.off';

  Future<String?> siteKey() => _s.read(key: _key);
  Future<void> saveSiteKey(String key) => _s.write(key: _key, value: key);
  Future<void> clearSiteKey() async {
    await _s.delete(key: _key);
    await _s.delete(key: _site);
  }

  /// The site this PC is paired with, as named in EzBizi.
  Future<String?> siteName() => _s.read(key: _site);
  Future<void> saveSiteName(String name) => _s.write(key: _site, value: name);

  /// Switched off on this PC (kept across restarts).
  Future<bool> switchedOff() async => await _s.read(key: _off) == '1';
  Future<void> setSwitchedOff(bool off) =>
      off ? _s.write(key: _off, value: '1') : _s.delete(key: _off);

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
