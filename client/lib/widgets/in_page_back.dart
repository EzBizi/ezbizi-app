import 'package:flutter/material.dart';

/// Presses the back arrow of whatever page is drawn inside a screen, as
/// Android's Back button should, and says whether there was one.
///
/// Records, forms and settings sections are drawn in place rather than pushed
/// as routes, each with its own ← button (about forty screens). The system
/// Back knew nothing of them and closed the app from inside a vehicle, a
/// person or a phone setting. Rather than teach forty screens, the shell asks
/// the screen: the last ← on screen that is actually showing — not offstage,
/// not in a paused tab — is the innermost page, and pressing it is what the
/// person meant. An arrow that is showing but disabled (the page is busy)
/// swallows the press rather than letting Back leave the app.
///
/// ponytail: finds the page by its arrow glyph. A ← that is not a way back, or
/// a way back drawn without one, breaks it; a registry is the upgrade if that
/// ever happens.
bool pressInPageBack(BuildContext root) {
  var seen = false;
  VoidCallback? press;

  void visit(Element element, bool inButton, VoidCallback? onPressed) {
    final widget = element.widget;
    if (widget is Offstage && widget.offstage) return;
    if (widget is TickerMode && !widget.enabled) return;

    if (widget is IconButton) {
      inButton = true;
      onPressed = widget.onPressed;
    } else if (widget is ButtonStyleButton) {
      inButton = true;
      onPressed = widget.onPressed;
    }

    if (inButton && widget is Icon && widget.icon == Icons.arrow_back) {
      seen = true;
      press = onPressed;
    }

    element.visitChildElements((child) => visit(child, inButton, onPressed));
  }

  root.visitChildElements((child) => visit(child, false, null));
  press?.call();

  return seen;
}
