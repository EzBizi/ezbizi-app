import '../core/files.dart';

/// The web half: the browser's own download; the overlay already fills the
/// tab, so there is no window to make fullscreen.
Future<String?> saveHelpVideo(String filename, List<int> bytes) async {
  Files.download(filename, bytes);
  return null;
}

Future<void> setHelpVideoWindowFullScreen(bool on) async {}
