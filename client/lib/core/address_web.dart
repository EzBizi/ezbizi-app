import 'dart:js_interop';

import 'package:web/web.dart' as web;

/// The browser address bar. See address.dart.
String currentPath() {
  try {
    return web.window.location.pathname;
  } catch (_) {
    return '/';
  }
}

void setPath(String path, {required bool replace}) {
  try {
    if (replace) {
      web.window.history.replaceState(null, '', path);
    } else {
      web.window.history.pushState(null, '', path);
    }
  } catch (_) {
    // Some embedded webviews refuse history manipulation. Losing the URL is
    // untidy; crashing on a tap is worse.
  }
}

void Function() listen(void Function(String path) onPop) {
  final handler = ((web.Event _) {
    onPop(currentPath());
  }).toJS;

  web.window.addEventListener('popstate', handler);
  return () => web.window.removeEventListener('popstate', handler);
}
