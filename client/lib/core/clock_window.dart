import 'client_platform.dart';
import 'clock_window_io.dart'
    if (dart.library.js_interop) 'clock_window_web.dart'
    as impl;

/// The lock screen's grip on the desktop window (design 2026-09-08 §10.2).
///
/// Same shape as client_platform.dart: one facade, two implementations, the
/// compiler picks. `window_manager` is only imported by the io half, and that
/// half only acts on Windows and macOS. Web covers the app and nothing else;
/// Android and iOS never lock (A1 Tools removed theirs 2026-07-17).
///
/// What this cannot do, and says so: stop Ctrl-Alt-Del, Task Manager, or a
/// second monitor's taskbar. It is a cover, not an OS lock.
class ClockWindow {
  /// Once, before `runApp`. A no-op everywhere the window is not ours.
  static Future<void> init() => impl.init();

  /// Whether this build ever shows the lock route at all.
  static bool get canLock =>
      ClientPlatform.name != 'android' && ClientPlatform.name != 'ios';

  /// Fullscreen, always on top, refuse close, take focus.
  static Future<void> lock() => impl.lock();

  /// Give all three back, in the order that does not leave a topmost window
  /// painted at the restored size.
  static Future<void> release() => impl.release();

  /// Show, restore if minimized, focus — for the reminder.
  static Future<void> raise() => impl.raise();
}
