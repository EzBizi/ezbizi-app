import 'package:web/web.dart' as web;

/// Session persistence in a browser.
///
/// sessionStorage, not localStorage. It survives a refresh — which is the whole
/// complaint — but dies with the tab, so a shared or public machine does not
/// keep somebody signed in after the browser closes, and nothing leaks between
/// tabs.
String? readItem(String key) {
  try {
    final value = web.window.sessionStorage.getItem(key);

    return (value == null || value.isEmpty) ? null : value;
  } catch (_) {
    // Private browsing modes and some embedded webviews throw on access rather
    // than returning null. Losing persistence is survivable; crashing on launch
    // is not.
    return null;
  }
}

void writeItem(String key, String? value) {
  try {
    if (value == null || value.isEmpty) {
      web.window.sessionStorage.removeItem(key);
    } else {
      web.window.sessionStorage.setItem(key, value);
    }
  } catch (_) {
    // Same reason. A session that does not persist is a smaller problem than an
    // app that will not start.
  }
}
