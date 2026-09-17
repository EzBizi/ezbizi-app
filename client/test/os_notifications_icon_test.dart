import 'package:ezbizi_client/core/os_notifications.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// The Windows toast icon, which fails silently in both directions.
///
/// Nothing throws when this breaks. The plugin writes the path into
/// `HKCU\Software\Classes\AppUserModelId\EzBizi.DesktopClient\IconUri` and
/// Windows opens that file later, when it draws the toast — so a missing asset
/// produces a null path, an unwritten registry value, and a toast with the
/// generic icon. Which is exactly what it looked like before this was fixed,
/// and is therefore indistinguishable from the bug itself.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('the icon OsNotifications names is an asset this build ships', () async {
    // rootBundle, not File: `flutter test` runs against the asset manifest the
    // pubspec produced, so this reds if the declaration is dropped as well as
    // if the file is renamed. Checking the path on disk would only catch the
    // second, and the first is the easier of the two to do by accident while
    // tidying pubspec.yaml.
    final icon = await rootBundle.load(OsNotifications.windowsIconAsset);

    expect(icon.lengthInBytes, greaterThan(0));
  });
}
