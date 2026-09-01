@TestOn('vm')
library;

import 'dart:io';

import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:flutter_test/flutter_test.dart';

// The whole point of the desktop device-key store: a value written in one run is
// read back in the next. A fresh readItem() call reads the file from scratch, so
// it stands in for "the agent restarted" — which is exactly the case the old
// no-op store got wrong (regenerating a new device row every launch).
void main() {
  const key = 'ezbizi.monitoring_device_key';

  test('device key round-trips through the config file across "restarts"', () {
    final dir = Directory.systemTemp.createTempSync('ezbizi_devkey_test');
    store.debugStoreDirOverride = dir;
    addTearDown(() {
      store.debugStoreDirOverride = null;
      if (dir.existsSync()) dir.deleteSync(recursive: true);
    });

    // Nothing stored yet -> the caller generates a key (behaviour lives in
    // DeviceKey; here we assert the store starts empty).
    expect(store.readItem(key), isNull);

    store.writeItem(key, 'abc-123', persist: true);

    // A brand-new read (no in-memory cache in this store) returns the persisted
    // value — the restart survives.
    expect(store.readItem(key), 'abc-123');

    // Clearing removes it.
    store.writeItem(key, null);
    expect(store.readItem(key), isNull);
  });

  test('an unreadable/absent store never throws, just returns null', () {
    store.debugStoreDirOverride =
        Directory('does-not-exist-${DateTime.now().microsecondsSinceEpoch}');
    addTearDown(() => store.debugStoreDirOverride = null);

    expect(store.readItem(key), isNull);
  });
}
