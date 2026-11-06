import 'package:flutter/material.dart';

import '../core/theme.dart';

/// A small state label beside a name — "Demo", "Read only", "Disabled".
class Tag extends StatelessWidget {
  const Tag(
    this.label, {
    super.key,
    this.warn = false,
    this.good = false,
    this.bad = false,
  });

  final String label;

  /// Something the reader needs to notice, not just know. Amber, not red:
  /// "12 missing" on every onboarding row is a to-do, and red is kept for a
  /// problem (phone audit 2026-11-04, Section 3 #8).
  final bool warn;

  /// Something working as it should — "Live", "Connected", "Paid".
  ///
  /// The app's own green rather than a hex, so this cannot drift away from the
  /// punch-in button, and so it stays legible when a dark theme is on.
  /// Ignored when [warn] is set: a tag cannot be both.
  final bool good;

  /// A problem, such as "Not passed". Wins over [warn] and [good].
  final bool bad;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final caution = ToneColours.of(context, Tone.caution);
    final problem = ToneColours.of(context, Tone.bad);

    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: bad
              ? problem.fill
              : warn
              ? caution.fill
              : good
              ? EzBiziTheme.clockIn(context).withValues(alpha: 0.14)
              : scheme.surfaceContainerHighest.withValues(alpha: 0.6),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          // A tag shortens rather than pushing the row it sits in off the
          // screen. It is a label beside a name, never the thing being read.
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: bad
                ? problem.ink
                : warn
                ? caution.ink
                : good
                ? EzBiziTheme.clockIn(context)
                : null,
          ),
        ),
      ),
    );
  }
}
