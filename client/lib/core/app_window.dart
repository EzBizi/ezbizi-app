import 'package:flutter/foundation.dart';

import 'app_window_io.dart'
    if (dart.library.js_interop) 'app_window_web.dart'
    as impl;

/// More than one EzBizi window (Jose, 2026-11-24: dispatchers kept EzBizi and
/// monday half and half; with monday gone they want two EzBizis).
///
/// A second window is a second copy of the app opened on one module or tab,
/// signed in with the same token, so it shares the view and company. On the
/// desktop it is its own OS window that snaps beside the first; on the web it
/// is a browser window.
///
/// Only the FIRST window holds what must happen once per person: the
/// softphone, the updater, the tray icon, monitoring, the shift reminder, OS
/// notifications and the clock-in lock. A second window shows the screens and
/// nothing rings in it twice.
class AppWindow {
  /// Where a second window was opened, or null in the first window.
  static final Uri? start = impl.start();

  static bool get secondary => start != null;

  /// Whether this build can open another window (desktop and web).
  static bool get canOpen => impl.canOpen;

  /// The path for a module, or one of its tabs.
  static String pathFor(String module, {String? tab, String? label}) => Uri(
    path: '/$module',
    queryParameters: {'tab': ?tab, 'label': ?label},
  ).toString().replaceFirst(RegExp(r'\?$'), '');

  /// Open [path] (from [pathFor]) in a new window.
  static Future<void> open(String path) => opener(path);

  /// What [open] calls; a test records the path instead of starting a copy.
  @visibleForTesting
  static Future<void> Function(String path) opener = impl.open;

  /// A second desktop window closes with the first (Windows does it natively;
  /// macOS and Linux look every few seconds). Once, before runApp.
  static void watchFirst() => impl.watchFirst();

  /// The OS window's title, so two windows tell apart in the taskbar.
  static Future<void> setTitle(String title) => impl.setTitle(title);
}
