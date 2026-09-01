import 'dart:math';

import 'token_store_io.dart' if (dart.library.js_interop) 'token_store_web.dart'
    as store;

/// The single per-install identifier every monitoring upload carries —
/// [MonitoringHeartbeat] and [ScreenshotService] (2026-08-31-monitoring-
/// screenshots, Task 6) both call [DeviceKey.value] rather than each holding
/// its own storage read/generate logic.
///
/// ── Why a shared cache, not just a shared storage KEY ─────────────
///
/// It would not have been enough for the two callers to merely agree on the
/// same storage key string and each independently do "read storage, else
/// generate + persist" — see token_store_io.dart: off the web this is STILL
/// a no-op (`readItem` always returns null, `writeItem` writes nothing),
/// because desktop persistence has not shipped yet. Two callers each doing
/// their own read-else-generate against a store that never actually stores
/// anything would each generate their OWN random uuid on every construction
/// — which is exactly the platform (Windows) both of these run on. Caching
/// the resolved value here, in this file's own top-level state, rather than
/// in a field on each service, is what keeps a heartbeat and a screenshot
/// upload from the same running process carrying two different device keys
/// — and therefore resolving to two different `monitored_devices` rows, or
/// the screenshot ingest 404ing outright (ScreenshotsController::store()
/// requires the heartbeat's row to already exist under this exact key).
class DeviceKey {
  DeviceKey._();

  static const String _storageKey = 'ezbizi.monitoring_device_key';

  static String? _cached;

  /// Reads the persisted key, or generates and persists one the first time
  /// anything in this process ever needs it. Every subsequent call — from
  /// either caller — returns the same cached value without touching storage
  /// again.
  static Future<String> value() async {
    final cached = _cached;
    if (cached != null) return cached;

    final stored = store.readItem(_storageKey);
    final key = (stored == null || stored.isEmpty) ? _uuidV4() : stored;

    _cached = key;
    // `persist: true` — localStorage, not sessionStorage — because a device
    // key identifies the INSTALL, not the browser tab: see
    // MonitoringHeartbeat's original doc comment on this, moved here
    // verbatim in spirit when this cache was extracted from it.
    store.writeItem(_storageKey, key, persist: true);

    return key;
  }
}

/// A random (RFC 4122) v4 uuid. No `uuid` package for this: it is sixteen
/// random bytes with two nibbles pinned to say "version 4, this variant" —
/// small enough that a whole dependency for it would cost more than it saves.
String _uuidV4() {
  final random = Random.secure();
  final bytes = List<int>.generate(16, (_) => random.nextInt(256));

  bytes[6] = (bytes[6] & 0x0F) | 0x40; // version 4
  bytes[8] = (bytes[8] & 0x3F) | 0x80; // variant 10xx

  String hex(int start, int end) => bytes
      .sublist(start, end)
      .map((b) => b.toRadixString(16).padLeft(2, '0'))
      .join();

  return '${hex(0, 4)}-${hex(4, 6)}-${hex(6, 8)}-${hex(8, 10)}-${hex(10, 16)}';
}
