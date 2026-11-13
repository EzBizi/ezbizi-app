import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Web and Windows must draw the same shell (Jose, 11-13: "2 different
/// layouts ... missing modules on web ... differents fonts").
void main() {
  test('the theme font family is bundled in pubspec', () {
    final theme = File('lib/core/theme.dart').readAsStringSync();
    final family = RegExp(
      r"fontFamily: '([^']+)'",
    ).firstMatch(theme)!.group(1)!;
    final pubspec = File('pubspec.yaml').readAsStringSync();

    expect(
      RegExp('^\\s*- family: $family\\s*\$', multiLine: true).hasMatch(pubspec),
      isTrue,
      reason:
          'theme.dart names "$family" but pubspec does not bundle it, so '
          'each platform falls back to its own system font',
    );
    for (final m in RegExp(r'asset: (assets/fonts/\S+)').allMatches(pubspec)) {
      expect(File(m.group(1)!).existsSync(), isTrue, reason: m.group(1));
    }
  });

  test('the sidebar module set has no platform gate', () {
    // The module rows come from /me alone; a platform check in any of these
    // files would make the same account see different modules per platform.
    for (final path in [
      'lib/core/module_screens.dart',
      'lib/core/sidebar_order.dart',
    ]) {
      final src = File(path).readAsStringSync();
      expect(src.contains('kIsWeb'), isFalse, reason: path);
      expect(src.contains('Platform.is'), isFalse, reason: path);
    }
  });
}
