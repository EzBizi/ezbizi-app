import 'package:web/web.dart' as web;

/// Session persistence in a browser.
///
/// sessionStorage, not localStorage. It survives a refresh — which is the whole
/// complaint — but dies with the tab, so a shared or public machine does not
/// keep somebody signed in after the browser closes, and nothing leaks between
/// tabs.
/// Nothing to load: browser storage answers synchronously, so the cache the
/// desktop store needs has no counterpart here. Present only so `main` can
/// await one call on every platform.
Future<void> init() async {}

String? readItem(String key) {
  try {
    // sessionStorage first, then localStorage. The two hold the same key and
    // only one is ever written for a given sign-in, so checking both is how a
    // "keep me signed in" session is found after the browser has been closed.
    final value = web.window.sessionStorage.getItem(key) ??
        web.window.localStorage.getItem(key);

    return (value == null || value.isEmpty) ? null : value;
  } catch (_) {
    // Private browsing modes and some embedded webviews throw on access rather
    // than returning null. Losing persistence is survivable; crashing on launch
    // is not.
    return null;
  }
}

/// What survives the browser closing for [key]: localStorage only, because a
/// sessionStorage copy is the "do not keep me signed in" one.
Future<String?> readPersisted(String key) async {
  final value = web.window.localStorage.getItem(key);
  return (value == null || value.isEmpty) ? null : value;
}

void debugReset() {}

void writeItem(String key, String? value, {bool persist = false}) {
  try {
    // Cleared from BOTH on the way out, whichever it was written to. Signing
    // out of one while a copy lingers in the other is how somebody ends up
    // believing they have left.
    web.window.sessionStorage.removeItem(key);
    web.window.localStorage.removeItem(key);

    if (value != null && value.isNotEmpty) {
      if (persist) {
        web.window.localStorage.setItem(key, value);
      } else {
        web.window.sessionStorage.setItem(key, value);
      }
    }
  } catch (_) {
    // Same reason. A session that does not persist is a smaller problem than an
    // app that will not start.
  }
}
