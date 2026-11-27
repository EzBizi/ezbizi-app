import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// The Windows keychain file must be the same file in every version.
///
/// flutter_secure_storage_windows keeps the saved sign-in in
/// %APPDATA%\<CompanyName>\<ProductName>\flutter_secure_storage.dat, and
/// path_provider_windows reads those two names from the running exe's
/// VERSIONINFO (windows/runner/Runner.rc). A version, a build number or a
/// rename in either one gives each update a fresh, empty file: every update
/// would sign everybody out. Checked 2026-11-16 ("every new update seem to log
/// me out"): it was not the cause then; this keeps it that way.
void main() {
  test('the keychain folder names carry no version and do not change', () {
    final rc = File('windows/runner/Runner.rc').readAsStringSync();

    String value(String name) {
      final match = RegExp('VALUE "$name", (.+?) "\\\\0"').firstMatch(rc);
      expect(match, isNotNull, reason: '$name is missing from Runner.rc');
      return match!.group(1)!.trim();
    }

    expect(value('CompanyName'), '"EzBizi"');
    expect(value('ProductName'), '"EzBizi"');
  });

  test('the installer never deletes per-user app data', () {
    final iss = File('../scripts/windows-installer.iss').readAsStringSync();

    expect(iss, contains('PrivilegesRequired=lowest'));
    for (final section in ['[InstallDelete]', '[UninstallDelete]']) {
      final at = iss.indexOf(section);
      if (at < 0) continue;
      final end = iss.indexOf('\n[', at + 1);
      final body = iss.substring(at, end < 0 ? iss.length : end);
      expect(
        body.toLowerCase(),
        isNot(contains('appdata')),
        reason:
            '$section must not touch {userappdata}: the saved sign-in '
            'lives there.',
      );
    }
  });
}
