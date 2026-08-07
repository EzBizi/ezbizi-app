import 'package:flutter/material.dart';
import '../core/build_info.dart';

/// The version, small and out of the way.
///
/// Shown on the sign-in screen and at the foot of the sidebar — the two places
/// somebody already is when they wonder whether the app has updated. Quiet
/// enough to ignore for months and specific enough to settle the question in
/// one glance when it matters.
class BuildStamp extends StatelessWidget {
  const BuildStamp({super.key, this.onDark = false});

  /// On the sign-in panel's dark half the theme's own muted colour disappears.
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    final colour = onDark
        ? Colors.white.withValues(alpha: 0.4)
        : Theme.of(context).colorScheme.onSurfaceVariant.withValues(alpha: 0.7);

    return Tooltip(
      message: BuildInfo.full,
      child: Text(
        BuildInfo.short,
        style: TextStyle(
          fontSize: 11,
          color: colour,
          // Tabular figures so the string does not jitter between builds — it
          // sits in the corner of every screen and a number that shifts width
          // draws the eye for no reason.
          fontFeatures: const [FontFeature.tabularFigures()],
        ),
      ),
    );
  }
}
