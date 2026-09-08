import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:window_manager/window_manager.dart';

/// The io half of clock_window.dart. Windows and macOS only; every other
/// platform with dart:io (Android, iOS, Linux) is a no-op here.
bool get _desktop => Platform.isWindows || Platform.isMacOS;

Future<void> init() async {
  if (!_desktop) return;
  await _attempt('ensureInitialized', windowManager.ensureInitialized);
}

Future<void> lock() async {
  if (!_desktop) return;
  await _attempt('fullScreen', () => windowManager.setFullScreen(true));
  await _attempt('alwaysOnTop', () => windowManager.setAlwaysOnTop(true));
  await _attempt('preventClose', () => windowManager.setPreventClose(true));
  await _attempt('focus', windowManager.focus);
}

Future<void> release() async {
  if (!_desktop) return;
  // Topmost and prevent-close first, fullscreen last: dropping fullscreen
  // while still topmost repaints a topmost window at the restored size.
  await _attempt('alwaysOnTop', () => windowManager.setAlwaysOnTop(false));
  await _attempt('preventClose', () => windowManager.setPreventClose(false));
  await _attempt('fullScreen', () => windowManager.setFullScreen(false));
}

Future<void> raise() async {
  if (!_desktop) return;
  await _attempt('show', windowManager.show);
  var minimized = false;
  try {
    minimized = await windowManager.isMinimized();
  } catch (_) {}
  if (minimized) {
    await _attempt('restore', windowManager.restore);
  }
  await _attempt('focus', windowManager.focus);
}

/// Each call on its own: one failure must not strand the others, which is
/// exactly how a half-released lock screen happens.
Future<void> _attempt(String what, Future<void> Function() op) async {
  try {
    await op();
  } catch (e) {
    if (kDebugMode) debugPrint('[ClockWindow] $what failed: $e');
  }
}
