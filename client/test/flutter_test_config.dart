import 'dart:async';
import 'dart:io';

import 'package:ezbizi_client/core/device_key_store_io.dart' as local_store;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

/// Every test file runs through this first (flutter_test convention).
///
/// Tests must never touch the real per-user stores of the app installed on
/// the machine running them. 2026-11-16: the suite, run many times a day on
/// the desk PC, kept rewriting the live app's
/// %APPDATA%\ezbizi\ezbizi.monitoring_device_key, because tests reset
/// `debugStoreDirOverride` to null and the next write fell through to APPDATA.
///
///  * the plain store's root is a temp directory for the whole run, and the
///    run fails if any test still resolves the real APPDATA/HOME path;
///  * the keychain (token store, camera bridge) is the plugin's in-memory
///    fake, so no test can reach %APPDATA%\EzBizi\EzBizi's
///    flutter_secure_storage.dat, where the real sign-in lives.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  local_store.debugConfigRootOverride = Directory.systemTemp.createTempSync(
    'ezbizi-test-appdata-',
  );
  FlutterSecureStorage.setMockInitialValues({});

  tearDownAll(() {
    expect(
      local_store.debugRealPathsUnderTest,
      isEmpty,
      reason:
          'A test resolved the real per-user store. It would have written '
          'the installed app\'s data on this machine.',
    );
  });

  await testMain();
}
