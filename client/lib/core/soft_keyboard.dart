import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

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

/// The app's scrolling, plus one rule: dragging a page puts the keyboard away.
///
/// Mobile review 11-24: on a phone Flutter never closes the keyboard on a tap
/// outside the box, and the iPhone number pad has no Done key, so after a
/// Year, an amount or a multi-line note the keyboard stayed over half the
/// screen with no way down. Dragging the page is how iPhone apps close it.
/// A scroll made by the app (bringing a field into view) is not a drag.
final ScrollBehavior dragClosesKeyboard = const MaterialScrollBehavior()
    .copyWith(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
    );

/// The on-screen keyboard is up. Read from the window, not `MediaQuery`: inside
/// a Scaffold body (every module) `MediaQuery.viewInsets` is already zeroed by
/// the resize, so `MediaQuery.viewInsetsOf(context).bottom > 0` never fires
/// there. The body shrinking is what rebuilds a LayoutBuilder that asks this.
bool keyboardUp(BuildContext context) => View.of(context).viewInsets.bottom > 0;

/// Hides [child] on a phone while the keyboard is up, keeping its state:
/// summary tiles above a search, a floating Compose or Keypad button. Typing,
/// the results get the room (mobile review 11-24: Jobs showed one job, and
/// Project Management's totals pushed its table 35 px past the bottom).
class FoldWhileTyping extends StatefulWidget {
  const FoldWhileTyping({super.key, required this.child});

  final Widget child;

  @override
  State<FoldWhileTyping> createState() => _FoldWhileTypingState();
}

class _FoldWhileTypingState extends State<FoldWhileTyping>
    with WidgetsBindingObserver {
  bool _up = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // A Scaffold body sees no keyboard inset, so nothing rebuilds this when the
  // keyboard comes up; the window's metrics change does.
  @override
  void didChangeMetrics() {
    if (!mounted) return;
    final up = keyboardUp(context);
    if (up != _up) setState(() => _up = up);
  }

  @override
  Widget build(BuildContext context) => Visibility(
    visible: !(_up && MediaQuery.sizeOf(context).width < 900),
    maintainState: true,
    child: widget.child,
  );
}
