import 'package:web/web.dart' as web;

/// Platform identity in a browser. See client_platform.dart.
///
/// Flatly `web`, not the underlying operating system. What the server is
/// deciding is whether THIS BUILD OF THE CLIENT is too old to talk to it, and a
/// browser running on Windows is not a Windows build — it is the web build,
/// served from the server itself, with an entirely different way of becoming
/// current again. Reporting `windows` here would measure a browser tab against
/// the minimum set for the installed Windows app: two different release trains,
/// one number, and everybody in a browser on Windows locked out the day the
/// desktop minimum moves.
String platformName() => 'web';

/// A browser has no disk to put an asset on. See client_platform.dart.
///
/// Assets are served over HTTP and the only thing that could be returned here
/// is a URL, which is not a file path and would be wrong for every caller —
/// they want this precisely because something outside the process has to OPEN
/// the file.
String? installedAssetPath(String assetKey) => null;

/// Fetch the app again from the server.
///
/// This is the entire remedy on the web, and it is why the web copy on the
/// update screen says "reload" rather than "download". The page is served fresh
/// on every load, so the newest client is always one round trip away.
bool reload() {
  try {
    web.window.location.reload();
    return true;
  } catch (_) {
    // Some embedded webviews refuse programmatic navigation. Saying so lets the
    // screen fall back to asking the person to reload the page themselves,
    // which is a sentence they can act on — rather than a button that silently
    // does nothing, which is a dead end wearing the costume of a way out.
    return false;
  }
}

/// No phone operating system in a browser. See client_platform.dart.
String? osVersion() => null;
