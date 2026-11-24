import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

/// Whether this device types on an on-screen keyboard (a phone or tablet,
/// native or in a browser). A function so a test can say which device it is.
bool Function() hasSoftKeyboard = () =>
    defaultTargetPlatform == TargetPlatform.android ||
    defaultTargetPlatform == TargetPlatform.iOS;

/// `autofocus:` for a box that is focused merely because something OPENED: a
/// picker's search bar, the first field of a form panel.
///
/// Jose, 2026-11-24: "Whenever opening any dropdown with search bar, do not
/// open keyboard by default". On a phone the keyboard then covers half of what
/// just opened; it comes up when the box is tapped. A desktop keeps the focus,
/// where it costs nothing and saves a click.
///
/// A literal `autofocus: true` is only for a box whose one purpose is typing,
/// opened by asking to type (a code, a password, a rename, a reply), and says
/// so with a `// keyboard:` comment. client/test/soft_keyboard_guard_test.dart.
bool get autofocusOnOpen => !hasSoftKeyboard();

/// The on-screen keyboard is up. Read from the window, not `MediaQuery`: inside
/// a Scaffold body (every module) `MediaQuery.viewInsets` is already zeroed by
/// the resize, so `MediaQuery.viewInsetsOf(context).bottom > 0` never fires
/// there. The body shrinking is what rebuilds a LayoutBuilder that asks this.
bool keyboardUp(BuildContext context) => View.of(context).viewInsets.bottom > 0;
