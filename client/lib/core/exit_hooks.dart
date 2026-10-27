import 'dart:async';

/// Native resources Dart must release BEFORE the Windows engine is destroyed.
///
/// 2026-10-27, "The app closed unexpectedly last time" (27 users, every
/// version, always while the updater closed the app): an access violation at
/// flutter_windows.dll+0x14BBD, which the 3.41.6 engine PDB resolves to
/// FlutterDesktopMessengerSetCallback. The engine's destructor detaches its
/// messenger FIRST and then runs the plugin destructors; webview_windows'
/// ~WebviewBridge calls SetMethodCallHandler(nullptr), which then dereferences
/// a null engine. A WebView disposed while the engine is alive is erased by its
/// plugin there and then, so nothing is left for that destructor to do.
///
/// The Windows runner asks for this (`prepareToExit` on the watchdog channel)
/// when the window is being destroyed and waits, bounded, for the answer.
class ExitHooks {
  ExitHooks._();

  static final Set<Future<void> Function()> _hooks = {};

  /// Register [release]; returns it so the caller can [remove] it.
  static Future<void> Function() add(Future<void> Function() release) {
    _hooks.add(release);
    return release;
  }

  static void remove(Future<void> Function()? release) {
    if (release != null) _hooks.remove(release);
  }

  /// Run every hook, never throwing, each bounded so one that never completes
  /// cannot hold the rest.
  static Future<void> runAll({
    Duration each = const Duration(seconds: 2),
  }) async {
    final hooks = _hooks.toList();
    _hooks.clear();
    await Future.wait(
      hooks.map((h) async {
        try {
          await h().timeout(each);
        } catch (_) {}
      }),
    );
  }
}
