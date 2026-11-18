import 'dart:async';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Session persistence off the web.
///
/// This was a no-op — `readItem` returned null and `writeItem` did nothing —
/// left over from when only the web build shipped. The effect was that "keep me
/// signed in" had never worked anywhere except a browser: the desktop app held
/// its token in memory alone, so every restart signed you out. Nobody reported
/// it as "restarting logs me out", because restarting an app you are working in
/// is rare. It surfaced on 2 Sep as "updating logged me out" — the update
/// restarts the app, which is the same thing wearing a different hat.
///
/// The old comment said this should become the platform keychain rather than a
/// plain file, "because a token in a plain file on disk is worse than one that
/// does not survive a restart". That is still right, and it is what this is:
/// `flutter_secure_storage` is DPAPI on Windows, Keychain on macOS, libsecret
/// on Linux — encrypted to the logged-in user, not readable by another account
/// or by anything that copies the file off the disk.
///
/// ── Why there is a cache ─────────────────────────────────────────
///
/// [TokenStore] is synchronous, because the web build behind it is: browser
/// storage answers immediately. Every keychain is asynchronous. Rather than
/// make the whole call chain async for one platform, [init] reads the store
/// once into memory before the app draws, and reads are served from there.
/// Writes update memory first, so a read straight after a write is correct
/// whatever the disk is doing.
// Defaults, deliberately. v11 dropped the `encryptedSharedPreferences` option
// that older guidance tells you to set: on Android it is now the only
// behaviour, so passing it is a compile error rather than a hardening step.
const _storage = FlutterSecureStorage();

Map<String, String> _cache = {};
bool _ready = false;

/// Load persisted tokens into memory. Call once, before the app decides whether
/// anybody is signed in.
///
/// Never throws. A keychain can be unavailable — a locked login keyring on
/// Linux, a corrupt store, a first run — and the right outcome for "I could not
/// read your saved session" is the sign-in screen, not a crash on launch.
Future<void> init() async {
  if (_ready) return;
  _ready = true;

  try {
    _cache = await _storage.readAll();
  } catch (_) {
    _cache = {};
  }
}

String? readItem(String key) => _cache[key];

/// What the keychain itself holds for [key], read past the cache.
///
/// Throws when the keychain cannot answer, rather than returning null: the
/// caller compares this with what it expects, and "could not read" must not
/// look the same as "it is gone".
Future<String?> readPersisted(String key) => _storage.read(key: key);

/// Forget the in-memory copy so a test can run [init] again.
void debugReset() {
  _cache = {};
  _ready = false;
}

/// [persist] is the "keep me signed in" tick, and it means the same thing here
/// as on the web: off, the token lives only for as long as this process does;
/// on, it goes to the keychain and survives a restart — or an update.
///
/// A write with `persist: false` still DELETES any stored token, rather than
/// leaving the previous one behind. Signing in without the tick, on a machine
/// where somebody once ticked it, must not leave that older session on disk.
void writeItem(String key, String? value, {bool persist = false}) {
  if (value == null) {
    _cache.remove(key);
  } else {
    _cache[key] = value;
  }

  // Not awaited: the caller is synchronous and the cache above is already
  // correct, so nothing reads a stale value while this settles. Errors are
  // swallowed for the same reason init tolerates them — a keychain that will
  // not write is a session that does not outlive the process, which is
  // degraded, not broken, and not worth an unhandled exception at sign-in.
  unawaited(() async {
    try {
      if (value == null || !persist) {
        await _storage.delete(key: key);
      } else {
        await _storage.write(key: key, value: value);
      }
    } catch (_) {}
  }());
}
