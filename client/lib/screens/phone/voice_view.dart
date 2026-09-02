import 'package:flutter/widgets.dart';

import 'voice_view_io.dart'
    if (dart.library.js_interop) 'voice_view_web.dart' as impl;

/// Mounts whatever a [VoiceDevice] needs in the widget tree, or nothing.
///
/// Only Windows needs this, and what it needs is not a picture: WebView2
/// delivers the microphone prompt to the WIDGET's delegate, so a controller
/// that never enters the tree is never asked and `getUserMedia` fails while
/// the phone looks healthy. Everywhere else this is an empty box.
///
/// Conditionally imported for the same reason the devices are — the Windows
/// side pulls in `dart:io` and a plugin the web build cannot compile.
Widget voiceDeviceView(Object? platformView) =>
    impl.buildVoiceDeviceView(platformView);
