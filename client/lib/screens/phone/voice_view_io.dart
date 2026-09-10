import 'package:flutter/widgets.dart';
import 'package:webview_windows/webview_windows.dart' as wvw;

/// The WebView2 surface, at ONE pixel, so the microphone prompt has somewhere
/// to arrive.
///
/// Not hidden: an `Offstage` or a `Visibility` with `maintainState:false`
/// unmounts the platform view, which is the same as not mounting it at all —
/// the prompt never fires and the phone registers but cannot hear anybody.
///
/// ── and NOT zero, which ate half a desktop ──────────────────────────────
///
/// This was `SizedBox(width: 0, height: 0)` and it disabled a slab of screen.
/// Reported by Bar on an ultrawide: EzBizi snapped to the right half, and the
/// left half stopped accepting clicks — while running, while minimised, and
/// only cured by killing the process.
///
/// `webview_windows` creates the surface visible unconditionally
/// (`webview.cc:140`, `put_IsVisible(true)`) but applies `put_Bounds` only
/// from `SetSurfaceSize`, which early-returns unless BOTH dimensions are
/// greater than zero (`webview.cc:381`). At 0×0 it is therefore
/// visible-and-never-bounded: it keeps WebView2's default rect as a top-level
/// window — layered at alpha 0 so you cannot see it, tool-window so it has no
/// taskbar button, no-activate so it never takes focus — but WITHOUT
/// `WS_EX_TRANSPARENT`, so it still hit-tests and swallows every click inside
/// that rect.
///
/// It lives in the `msedgewebview2` process, which is why minimising EzBizi
/// never cleared it and why killing `ezbizi.exe` did.
///
/// One logical pixel is enough for `put_Bounds` to run and pin the window
/// inside ours. `Opacity(0)` keeps it off the screen without unmounting it.
///
/// A1 Tools hit exactly this on 2026-08-11 and shipped the same fix in
/// 5.5.306; it is still in place there at `crm_screen.dart:921`. Jose named
/// that prior art, which is the only reason this took minutes rather than a
/// day. Do not "tidy" this back to zero.
///
/// The permission answer is microphone-only. Everything else this page could
/// ask for — camera, geolocation, notifications, clipboard — is refused,
/// because a page running somebody else's JavaScript should be given exactly
/// what it needs to do its job and nothing more.
Widget buildVoiceDeviceView(Object? platformView) {
  if (platformView is! wvw.WebviewController || !platformView.value.isInitialized) {
    return const SizedBox.shrink();
  }

  return SizedBox(
    width: 1,
    height: 1,
    child: Opacity(
      opacity: 0,
      child: wvw.Webview(
        platformView,
        permissionRequested: (url, kind, isUserInitiated) async =>
            kind == wvw.WebviewPermissionKind.microphone
            ? wvw.WebviewPermissionDecision.allow
            : wvw.WebviewPermissionDecision.deny,
      ),
    ),
  );
}
