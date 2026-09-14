import 'dart:io' show Platform;
import 'dart:ui' show Size;

import 'package:window_manager/window_manager.dart';

/// The io half of mobile_view_window.dart. Windows and macOS, the same pair
/// `clock_window_io.dart` acts on; Android, iOS and Linux are no-ops.
bool get _desktop => Platform.isWindows || Platform.isMacOS;

/// The size before the first shrink, or null when the window is its own size.
///
/// Held here rather than in the shell because the shell is rebuilt constantly
/// and this has to outlive every one of those rebuilds — and because putting
/// the window back is this file's job either way.
Size? _before;

Future<void> shrink(double width) async {
  if (!_desktop) return;

  try {
    // `??=` rather than `=`: shrinking twice without a restore in between
    // must not remember the phone width as the size to go back to.
    _before ??= await windowManager.getSize();

    await windowManager.setSize(Size(width, _before!.height));
  } catch (_) {
    // Deliberately swallowed, and deliberately NOT behind `kDebugMode` —
    // that is the mistake clock_window_io.dart documents, where a failure
    // was only ever reported in a build nobody runs. There is nothing to
    // report here: the viewport override has already made the app
    // phone-shaped, so a refused resize costs the window's outline and
    // nothing else.
  }
}

Future<void> restore() async {
  if (!_desktop) return;

  final was = _before;
  _before = null;
  if (was == null) return;

  try {
    await windowManager.setSize(was);
  } catch (_) {
    // As above. Worst case somebody drags the edge back themselves.
  }
}
