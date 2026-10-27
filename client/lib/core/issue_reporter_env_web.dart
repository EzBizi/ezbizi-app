import 'dart:js_interop';
import 'dart:js_interop_unsafe';

import 'package:flutter/foundation.dart' show kIsWasm;
import 'package:web/web.dart' as web;

/// What a report says about the browser. See issue_reporter.dart.
///
/// The lifecycle state stays `resumed` while Chrome throttles a background
/// tab (2026-10-27: ~2,100 once-a-minute "freezes"), so the tab's own
/// visibility and focus are read at every tick.
bool pageVisible() {
  try {
    return web.document.visibilityState == 'visible' && web.document.hasFocus();
  } catch (_) {
    return true;
  }
}

void onPageHidden(void Function() callback) {
  try {
    web.document.addEventListener(
      'visibilitychange',
      ((web.Event _) {
        if (web.document.visibilityState != 'visible') callback();
      }).toJS,
    );
    web.window.addEventListener('blur', ((web.Event _) => callback()).toJS);
  } catch (_) {}
}

String platformLabel() => 'web';

String osVersion() {
  try {
    return web.window.navigator.platform;
  } catch (_) {
    return '';
  }
}

String? userAgent() {
  try {
    final ua = web.window.navigator.userAgent;
    return ua.length <= 160 ? ua : ua.substring(0, 160);
  } catch (_) {
    return null;
  }
}

String renderer() => kIsWasm ? 'skwasm' : 'canvaskit';

/// Chromium's non-standard `performance.memory.usedJSHeapSize`; null elsewhere.
int? memoryBytes() {
  try {
    final memory = (web.window.performance as JSObject).getProperty<JSObject?>(
      'memory'.toJS,
    );
    final used = memory?.getProperty<JSNumber?>('usedJSHeapSize'.toJS);
    return used?.toDartInt;
  } catch (_) {
    return null;
  }
}
