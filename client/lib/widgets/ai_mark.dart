import 'package:flutter/material.dart';

/// What AI set or wrote, said in words (UI queue 77, recommendation 1, taken
/// under Jose's standing ten-minute rule on 2026-10-12).
///
/// One marker in two sizes: [AiMark.new] is the small "✦ AI" chip after a
/// value AI filled in, [AiMark.badge] heads something AI wrote ("Draft by
/// AI"). The glyph is the one Draft with AI already uses, and the word is
/// always there: a colour alone tells nobody anything.
///
/// The caller drops the mark once a person edits the value. From then on the
/// words are theirs, and a mark that stayed would credit AI with them.
class AiMark extends StatelessWidget {
  /// The chip after a value.
  const AiMark({super.key, this.tooltip}) : label = null;

  /// The heading on something AI wrote.
  const AiMark.badge(String this.label, {super.key, this.tooltip});

  /// Null for the chip, which says "AI".
  final String? label;

  /// Where it came from and how to change it.
  final String? tooltip;

  static const String defaultTooltip = 'Set by AI. Edit it to change it.';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ink = theme.colorScheme.primary;
    final label = this.label;

    final Widget mark = label == null
        ? Container(
            height: 18,
            padding: const EdgeInsets.only(left: 4, right: 6),
            decoration: BoxDecoration(
              color: theme.colorScheme.surface,
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: ink.withValues(alpha: 0.4)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.auto_awesome_outlined, size: 11, color: ink),
                const SizedBox(width: 2),
                Text(
                  'AI',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: ink,
                    fontWeight: FontWeight.w700,
                    height: 1,
                  ),
                ),
              ],
            ),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.auto_awesome_outlined, size: 14, color: ink),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  label,
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: ink,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          );

    return Tooltip(
      message: tooltip ?? defaultTooltip,
      child: mark,
    );
  }
}
