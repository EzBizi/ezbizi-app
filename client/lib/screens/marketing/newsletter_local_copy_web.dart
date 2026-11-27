import 'package:web/web.dart' as web;

/// localStorage, so the copy survives a refresh or an expired session.
/// Private modes that throw lose the copy rather than the editor.
String? read(String key) {
  try {
    return web.window.localStorage.getItem(key);
  } catch (_) {
    return null;
  }
}

void write(String key, String? value) {
  try {
    if (value == null) {
      web.window.localStorage.removeItem(key);
    } else {
      web.window.localStorage.setItem(key, value);
    }
  } catch (_) {}
}
