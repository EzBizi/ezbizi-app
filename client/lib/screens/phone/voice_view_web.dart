import 'package:flutter/widgets.dart';

/// Nothing to mount in a browser: the page IS the browser, and it handles its
/// own microphone prompt natively.
Widget buildVoiceDeviceView(Object? platformView) => const SizedBox.shrink();
