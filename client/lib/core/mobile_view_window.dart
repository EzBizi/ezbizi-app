import 'mobile_view_window_io.dart'
    if (dart.library.js_interop) 'mobile_view_window_web.dart'
    as impl;

/// The desktop window, narrowed to a phone and put back (Jose, 2026-09-14:
/// "the mobile icon should also resize the window").
///
/// Same shape as clock_window.dart: one facade, two implementations, the
/// compiler picks, and `window_manager` is imported only by the io half.
///
/// ── Why this is the SECOND half of the feature and not the whole of it ──
///
/// `HomeShell` already overrides the viewport and constrains itself to phone
/// width, and that stays. This does not replace it, for a reason that file
/// learned the hard way: **a platform setter can succeed and do nothing.** A
/// window manager may clamp a width, a tiling one may refuse the resize
/// outright, and either way `setSize` returns without complaint. If the
/// layout depended on the window actually having shrunk, that produces the
/// worst state this control has — a desktop layout inside something calling
/// itself mobile view.
///
/// So the override is what makes the app phone-shaped, and this makes the
/// window match. When it fails, the mode still works and the window is
/// simply the size it was.
class MobileViewWindow {
  /// Remember the current size and narrow to [width]. Height is untouched: a
  /// phone viewport is a statement about width, and forcing a height would
  /// move somebody's window for no reason.
  static Future<void> shrink(double width) => impl.shrink(width);

  /// Back to whatever it was before the first [shrink].
  static Future<void> restore() => impl.restore();
}
