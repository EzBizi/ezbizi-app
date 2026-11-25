import 'package:flutter/widgets.dart';

import 'call_view_io.dart'
    if (dart.library.js_interop) 'call_view_web.dart'
    as impl;

/// The page a Chat call runs in (GET /api/v1/chat/call), hosted the way each
/// platform can: an iframe on the web, WebView2 on Windows, the system web view
/// on Android, iOS and macOS. The page draws the call and its controls;
/// [onEvent] hears `connected`, `ended` {alone, answered} and `failed`.
///
/// Conditionally imported, as voice_view.dart: the native side pulls plugins
/// the web build cannot compile.
Widget chatCallView({
  required String url,
  required void Function(Map<String, dynamic> event) onEvent,
  Key? key,
}) => impl.buildChatCallView(url: url, onEvent: onEvent, key: key);

/// Ask the page to hang up (the app's own End button, or the app closing).
void hangUpChatCall(Key key) => impl.hangUp(key);
