import 'package:flutter/material.dart';

/// A click target for a table cell: a pointer and a tap, without the focus,
/// actions, semantics and ink an [InkWell] builds. A Task Board row had
/// nineteen InkWells, a screen of rows thousands of widgets (Jose 11-16).
class CellTap extends StatelessWidget {
  const CellTap({
    super.key,
    required this.onTap,
    this.onSecondaryTapDown,
    required this.child,
  });

  final VoidCallback? onTap;
  final GestureTapDownCallback? onSecondaryTapDown;
  final Widget child;

  @override
  Widget build(BuildContext context) => MouseRegion(
    cursor: onTap == null ? MouseCursor.defer : SystemMouseCursors.click,
    child: GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      onSecondaryTapDown: onSecondaryTapDown,
      child: child,
    ),
  );
}
