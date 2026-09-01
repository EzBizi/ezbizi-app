import 'dart:io';

/// Desktop/VM persistence for the monitoring device key.
///
/// Unlike the auth token — token_store_io.dart is a deliberate no-op, because a
/// bearer token in a plain file on disk is worse than one that dies on restart
/// — the device key is NOT a secret. It is a random per-install identifier the
/// server maps to a `monitored_devices` row; whoever controls the machine
/// already controls the agent, so persisting it in a plain per-user file leaks
/// nothing. And persist it MUST: without this, every relaunch generated a fresh
/// key and therefore a brand-new device row, so one physical machine showed up
/// in the fleet as a new device on every start. This one persists, to a file
/// under the user's per-user config directory.

/// Test seam: point persistence at a scratch directory instead of the real
/// config dir. Null in production.
Directory? debugStoreDirOverride;

Directory _configDir() {
  final env = Platform.environment;
  String? root;
  if (Platform.isWindows) {
    root = env['APPDATA']; // roaming, per-user
  } else if (Platform.isMacOS) {
    final home = env['HOME'];
    root = home == null ? null : '$home/Library/Application Support';
  } else {
    root = env['XDG_CONFIG_HOME'] ??
        (env['HOME'] == null ? null : '${env['HOME']}/.config');
  }
  // ponytail: no APPDATA/HOME (a headless or SYSTEM-service run) -> fall back to
  // the system temp dir. That degrades to per-run identity (the old behaviour),
  // never crashes. Given a real config dir, the key persists across restarts.
  final base = root ?? Directory.systemTemp.path;
  return Directory('$base${Platform.pathSeparator}ezbizi');
}

File _file(String key) {
  final dir = debugStoreDirOverride ?? _configDir();
  // `key` is a fixed constant with no path separators (see DeviceKey), so it is
  // a plain filename, not a path — nothing to traverse.
  return File('${dir.path}${Platform.pathSeparator}$key');
}

String? readItem(String key) {
  try {
    final f = _file(key);
    if (!f.existsSync()) return null;
    final value = f.readAsStringSync().trim();
    return value.isEmpty ? null : value;
  } catch (_) {
    // A missing or unreadable file just means "no stored key yet". Losing
    // persistence is survivable; crashing the agent on launch is not.
    return null;
  }
}

// `persist` is ignored here — a file always persists; the parameter exists only
// to match the web store's signature (where it chooses local vs session
// storage) so the conditional import stays type-compatible.
void writeItem(String key, String? value, {bool persist = false}) {
  try {
    final f = _file(key);
    if (value == null || value.isEmpty) {
      if (f.existsSync()) f.deleteSync();
      return;
    }
    f.parent.createSync(recursive: true);
    f.writeAsStringSync(value, flush: true);
  } catch (_) {
    // Same reason as readItem: a key that does not persist is a smaller problem
    // than an app that will not start.
  }
}
