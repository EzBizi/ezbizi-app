@TestOn('vm')
library;

import 'dart:io';

import 'package:ezbizi_client/core/device_key_store_io.dart' as local_store;
import 'package:ezbizi_client/core/token_store.dart';
import 'package:flutter_test/flutter_test.dart';

/// Proves test/flutter_test_config.dart isolates the suite from the real
/// per-user stores of the app installed on this machine.
void main() {
  test('the plain store writes under the run\'s temp root, not APPDATA', () {
    final root = local_store.debugConfigRootOverride;
    expect(root, isNotNull, reason: 'flutter_test_config.dart did not run');
    expect(root!.path, startsWith(Directory.systemTemp.path));

    local_store.writeItem('isolation_probe', 'x', persist: true);
    addTearDown(() => local_store.writeItem('isolation_probe', null));

    expect(
      File(
        '${root.path}${Platform.pathSeparator}ezbizi'
        '${Platform.pathSeparator}isolation_probe',
      ).existsSync(),
      isTrue,
    );
    expect(local_store.debugRealPathsUnderTest, isEmpty);
  });

  test('the guard sees a test reaching the real per-user path', () {
    // Not blind: with the root removed, resolving a path is recorded, which is
    // what fails the run in flutter_test_config's tearDownAll. A read of a key
    // that does not exist, so nothing is written to the real folder.
    final root = local_store.debugConfigRootOverride;
    local_store.debugConfigRootOverride = null;
    try {
      local_store.readItem('isolation_probe_never_written');
      expect(local_store.debugRealPathsUnderTest, isNotEmpty);
    } finally {
      local_store.debugConfigRootOverride = root;
      local_store.debugRealPathsUnderTest.clear();
    }
  });

  test('the keychain is the in-memory fake', () async {
    TokenStore.debugReset();
    await TokenStore.init();
    TokenStore.save(tenant: 'isolation-probe', persist: true);
    expect(await TokenStore.persistedTenant(), 'isolation-probe');
    TokenStore.clear();
    await Future<void>.delayed(Duration.zero);
    expect(await TokenStore.persistedTenant(), isNull);
  });
}
