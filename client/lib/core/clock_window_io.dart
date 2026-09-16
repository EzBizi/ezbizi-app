import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:window_manager/window_manager.dart';

/// The io half of clock_window.dart. Windows and macOS only; every other
/// platform with dart:io (Android, iOS, Linux) is a no-op here.
bool get _desktop => Platform.isWindows || Platform.isMacOS;

/// ── Why a listener and not just flags ────────────────────────────
///
/// The first version of this file set fullscreen, always-on-top and
/// prevent-close and stopped there. Measured on the 0.0.0173 Windows build:
/// the lock screen could be MINIMIZED, RESIZED and ignored, with the title
/// bar still drawn — so it covered nothing. Three separate holes:
///
///   * nothing intercepted minimize (window_manager raises the event; a flag
///     cannot answer it),
///   * resize / maximize / close were never disabled although the plugin has
///     a setter for each,
///   * fullscreen did not apply at all, and [_apply] swallowed the failure
///     because `kDebugMode` is false in the build people run.
///
/// So the grip is now belt AND braces: every escape the window manager offers
/// is switched off by a flag, the events that a flag cannot stop are answered
/// by this listener, and fullscreen is READ BACK rather than assumed.
class _LockGuard extends WindowListener {
  /// True only between [lock] and [release]. Every callback checks it, because
  /// `release()` itself leaves fullscreen — without this the guard would
  /// re-enter fullscreen on the way out and the lock would never let go.
  bool active = false;

  @override
  void onWindowMinimize() {
    if (!active) return;
    unawaited(windowManager.restore());
    unawaited(windowManager.focus());
  }

  @override
  void onWindowClose() {
    if (!active) return;
    unawaited(windowManager.show());
    unawaited(windowManager.focus());
  }

  @override
  void onWindowLeaveFullScreen() {
    if (!active) return;
    unawaited(windowManager.setFullScreen(true));
  }
}

final _guard = _LockGuard();
var _listening = false;

Future<void> init() async {
  if (!_desktop) return;
  await _apply('ensureInitialized', windowManager.ensureInitialized);
}

Future<void> lock() async {
  if (!_desktop) return;

  if (!_listening) {
    _listening = true;
    windowManager.addListener(_guard);
  }
  _guard.active = true;

  // Every way out of the window, switched off. Ordered least to most
  // disruptive so a failure part way through still leaves the escapes shut.
  await _apply('resizable', () => windowManager.setResizable(false));
  await _apply('minimizable', () => windowManager.setMinimizable(false));
  await _apply('maximizable', () => windowManager.setMaximizable(false));
  await _apply('closable', () => windowManager.setClosable(false));
  await _apply('preventClose', () => windowManager.setPreventClose(true));
  // The buttons in the screenshot. Hidden rather than merely disabled: a
  // greyed close button still says "this is an ordinary window you may put
  // away", which is the wrong sentence for a lock screen.
  await _apply('titleBar', () => windowManager.setTitleBarStyle(TitleBarStyle.hidden));
  await _apply('alwaysOnTop', () => windowManager.setAlwaysOnTop(true));

  final applied = await _apply('fullScreen', () => windowManager.setFullScreen(true));
  await _apply('focus', windowManager.focus);

  // `applied` false means there is no plugin behind the channel — a widget
  // test, or a host that does not have these calls. Retrying there would
  // leave pending timers under the fake clock for nothing.
  if (!applied) return;

  // Read back rather than assume. The shipped version asked for fullscreen
  // once, during the first frame's initState, and the window people saw had
  // a title bar — so the ask can be lost, and a lock screen that quietly is
  // not one is worse than no lock screen at all.
  for (var attempt = 0; attempt < 3; attempt++) {
    // Checked EVERY time round, not once on the way in. This loop outlives
    // its caller by up to three quarters of a second, and somebody clocking
    // in inside that window would have release() hand the desktop back and
    // then this retry take it straight back — a locked screen with no lock
    // screen on it, and no way out but the power button.
    if (!_guard.active) return;
    if (await _isFullScreen()) return;
    await Future<void>.delayed(const Duration(milliseconds: 250));
    if (!_guard.active) return;
    await _apply('fullScreen retry', () => windowManager.setFullScreen(true));
  }
}

Future<void> release() async {
  if (!_desktop) return;

  // FIRST, before any setter: the guard must stop answering events, or
  // leaving fullscreen below puts us straight back into it.
  _guard.active = false;

  // Every single thing lock() took, given back — including the four the
  // first version never took and so never returned. A window left
  // unminimizable after somebody clocks in is a worse bug than the one this
  // fixes, and it is invisible until they try.
  await _apply('alwaysOnTop', () => windowManager.setAlwaysOnTop(false));
  await _apply('preventClose', () => windowManager.setPreventClose(false));

  // setClosable writes the CLASS style (GCL_STYLE / CS_NOCLOSE), which
  // leaving fullscreen does not touch, so it is the one that may stay here.
  await _apply('closable', () => windowManager.setClosable(true));

  // ── LEAVING FULLSCREEN UNDOES ANY WINDOW STYLE SET BEFORE IT ──
  //
  // Jose clocked in and got a window with no caption at all — nothing to
  // drag, minimize or close — and clocking out and in again cleared it.
  //
  // We build against window_manager 0.5.2 (pubspec.lock; 0.4.3 differs here
  // and reading it would have given the wrong answer). Its restore-from-
  // fullscreen branch ends:
  //
  //   SetWindowLongPtr(GWL_STYLE, g_style_before_fullscreen | (WS_THICKFRAME | WS_MAXIMIZEBOX));
  //   ...
  //   title_bar_style_ = g_title_bar_style_before_fullscreen;
  //   SetWindowPos(..., SWP_FRAMECHANGED);
  //
  // Both saved values were captured on the way IN, i.e. from the locked
  // window. lock() strips WS_MINIMIZEBOX and sets the title bar to hidden
  // BEFORE going fullscreen, so the snapshot holds no minimize box and
  // "hidden" — and the exit hands exactly that back, then recomputes the
  // frame with it. Everything we restored above was overwritten a line later.
  //
  // Note what is NOT re-added on that line: WS_MINIMIZEBOX, WS_CAPTION,
  // WS_SYSMENU. Only WS_THICKFRAME and WS_MAXIMIZEBOX are ORed back, which is
  // why resize survived and minimize and the caption did not.
  //
  // The maximized branch above it neither restores title_bar_style_ nor uses
  // the stale frame, which is why a second clock-in cycle appeared to repair
  // it — the window came back zoomed and took the other path.
  //
  // So fullscreen goes here, and everything that writes GWL_STYLE or the
  // title bar goes after it. Still after alwaysOnTop(false) for the original
  // reason: dropping fullscreen while topmost repaints a topmost window at
  // the restored size, which is the "cut image" artifact.
  await _apply('fullScreen', () => windowManager.setFullScreen(false));

  await _apply('minimizable', () => windowManager.setMinimizable(true));
  await _apply('maximizable', () => windowManager.setMaximizable(true));
  await _apply('resizable', () => windowManager.setResizable(true));
  await _apply('titleBar', () => windowManager.setTitleBarStyle(TitleBarStyle.normal));
}

Future<void> raise() async {
  if (!_desktop) return;
  await _apply('show', windowManager.show);
  var minimized = false;
  try {
    minimized = await windowManager.isMinimized();
  } catch (_) {}
  if (minimized) {
    await _apply('restore', windowManager.restore);
  }
  await _apply('focus', windowManager.focus);
}

Future<bool> _isFullScreen() async {
  try {
    return await windowManager.isFullScreen();
  } catch (_) {
    return false;
  }
}

/// Each call on its own: one failure must not strand the others, which is
/// exactly how a half-released lock screen happens.
///
/// Returns whether it went through, which is what tells [lock] the difference
/// between "the window said no" and "there is no window here".
Future<bool> _apply(String what, Future<void> Function() op) async {
  try {
    await op();

    return true;
  } catch (e) {
    if (kDebugMode) debugPrint('[ClockWindow] $what failed: $e');

    return false;
  }
}
