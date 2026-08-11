import 'package:flutter/material.dart';

/// A control somebody may not use — still on screen, and saying why.
///
/// ── The rule this exists to keep ─────────────────────────────────
///
/// A control that cannot be used stays visible and explains itself. Hiding it is
/// not compliance: somebody looking for "Add customer" and not finding it
/// concludes the app is broken or that they are on the wrong screen, and the one
/// thing they will never conclude is "my role is missing customers.create", which
/// is the fact that would have ended it.
///
/// The two failures underneath look identical from the outside and have
/// completely different remedies — a permission somebody adds to a role, or an
/// assignment somebody widens (§4) — so the sentence has to say which. See
/// [Me.refusal] for the permission half; the reach half is the caller's, because
/// only the screen knows what it was about to act on.
///
/// ── Why a tooltip rather than a line of text ─────────────────────
///
/// Because the alternative is a paragraph under every disabled button on the
/// screen, most of which are enabled for most people, and a layout that changes
/// shape depending on your role is its own kind of confusing. The tooltip is
/// reachable three ways — hover, long press, and a screen reader, which reads it
/// as the control's own description — so it is not a desktop-only affordance.
///
/// Menus are the exception. A tooltip inside an open menu is unreachable in
/// practice, so [refusedMenuItem] puts the reason on a second line instead.
class Refused extends StatelessWidget {
  const Refused({super.key, required this.reason, required this.child});

  /// Null when the control may be used — [child] is returned untouched, so a
  /// call site does not need two branches.
  final String? reason;

  /// The control, already built disabled. This widget does not disable
  /// anything: what a control does when pressed is the caller's business, and a
  /// wrapper that quietly swallowed `onPressed` would be a second, invisible
  /// place where a button stops working.
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final reason = this.reason;

    if (reason == null) return child;

    return Tooltip(
      message: reason,
      // Long enough to read a sentence that names a permission and a remedy.
      // The default is 1.5s, which is about four words.
      showDuration: const Duration(seconds: 12),
      waitDuration: const Duration(milliseconds: 300),
      triggerMode: TooltipTriggerMode.longPress,
      child: child,
    );
  }
}

/// A menu entry that stays in the menu when it cannot be chosen.
///
/// Same rule as [Refused], different shape: the reason goes under the label
/// rather than into a tooltip, because a tooltip over an open menu is a gesture
/// nobody performs. Greying a row and saying nothing is the version of this that
/// reads as a bug — it is indistinguishable from a menu item that is simply
/// broken.
PopupMenuItem<T> refusedMenuItem<T>(
  BuildContext context, {
  required T value,
  required String label,
  String? reason,
}) {
  if (reason == null) {
    return PopupMenuItem<T>(value: value, child: Text(label));
  }

  return PopupMenuItem<T>(
    value: value,
    enabled: false,
    // The row grows to hold two lines. Without this the reason is clipped to the
    // menu's default 48, which turns an explanation into a fragment.
    height: 1,
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 320),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label),
          const SizedBox(height: 4),
          Text(reason, style: Theme.of(context).textTheme.bodySmall),
        ],
      ),
    ),
  );
}
