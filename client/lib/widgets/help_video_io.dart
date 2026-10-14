import 'dart:async';
import 'dart:io';

import 'package:gal/gal.dart';
import 'package:window_manager/window_manager.dart';

import '../core/files.dart';

/// The native half of the how-to video's Download and Fullscreen (Jose,
/// 2026-10-14: "the fullscreen option and also the download option all
/// inside the app").

/// Saves the video: to the gallery on a phone (file_selector cannot save on
/// Android or iOS), through a Save dialog on a computer. Answers what to
/// tell the person, or null when the dialog says it for itself.
Future<String?> saveHelpVideo(String filename, List<int> bytes) async {
  if (!(Platform.isAndroid || Platform.isIOS)) {
    Files.download(filename, bytes);
    return null;
  }
  final file = File('${Directory.systemTemp.path}/$filename');
  await file.writeAsBytes(bytes, flush: true);
  try {
    await Gal.putVideo(file.path);
    return 'Saved to Photos.';
  } finally {
    unawaited(file.delete().then((_) {}, onError: (Object _) {}));
  }
}

/// The window itself fullscreen on a computer; a phone's whole screen is
/// handled by the caller (SystemChrome).
Future<void> setHelpVideoWindowFullScreen(bool on) async {
  if (!(Platform.isWindows || Platform.isMacOS || Platform.isLinux)) return;
  try {
    await windowManager.ensureInitialized();
    await windowManager.setFullScreen(on);
  } catch (_) {
    // The overlay alone still fills the app window.
  }
}
