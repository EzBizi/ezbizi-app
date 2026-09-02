import 'package:flutter/widgets.dart';
import 'package:webview_windows/webview_windows.dart' as wvw;

/// The WebView2 surface, at zero size, so the microphone prompt has somewhere
/// to arrive.
///
/// Zero size rather than hidden: an `Offstage` or a `Visibility` with
/// `maintainState:false` unmounts the platform view, which is the same as not
/// mounting it at all — the prompt never fires and the phone registers but
/// cannot hear anybody.
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
    width: 0,
    height: 0,
    child: wvw.Webview(
      platformView,
      permissionRequested: (url, kind, isUserInitiated) async =>
          kind == wvw.WebviewPermissionKind.microphone
          ? wvw.WebviewPermissionDecision.allow
          : wvw.WebviewPermissionDecision.deny,
    ),
  );
}
