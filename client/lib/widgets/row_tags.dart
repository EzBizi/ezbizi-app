import 'package:flutter/material.dart';

/// A list row's tags in one right-aligned column of fixed width, just left of
/// the time, so pills form one straight edge down the list (design 187, Jose
/// 2026-11-06: "moving all the tabs to the left side of the time, so they
/// all line up"). At most [shown], then "+N"; the rest are in its tooltip.
class RowTags extends StatelessWidget {
  const RowTags({
    super.key,
    required this.tags,
    required this.width,
    this.shown = 2,
    this.hidden = const [],
  });

  final List<Widget> tags;
  final double width;
  final int shown;

  /// What the hidden tags say, for the "+N" tooltip.
  final List<String> hidden;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final more = tags.length - shown;

    return SizedBox(
      width: width,
      // Right-aligned and clipped on the left: a pill too wide for the column
      // loses its start, never the edge the column exists to keep.
      child: ClipRect(
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          reverse: true,
          physics: const NeverScrollableScrollPhysics(),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final (i, t) in tags.take(shown).indexed) ...[
                if (i > 0) const SizedBox(width: 4),
                t,
              ],
              if (more > 0) ...[
                const SizedBox(width: 4),
                Tooltip(
                  message: hidden.join('\n'),
                  child: Text(
                    '+$more',
                    style: theme.textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
