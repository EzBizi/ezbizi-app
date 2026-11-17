import 'package:flutter/material.dart';

import '../core/theme.dart';

/// An empty box that says so: amber, "Not filled in", its label above it.
///
/// Jose, 2026-09-25, on Web Management: an empty box showed its label inside,
/// where a value sits, and "it's still hard to differentiate what is a filled
/// field vs what is a placeholder". HR's record took the same look on
/// 2026-09-29 ("like we do on marketing SEO"). It lives here rather than in
/// either screen because two copies of one look drift into two looks.
InputDecoration notFilledIn(
  BuildContext context,
  InputDecoration decoration, {
  required bool empty,
}) {
  final caution = StateColour.of(context).caution;

  return decoration.copyWith(
    floatingLabelBehavior: FloatingLabelBehavior.always,
    hintText: empty ? 'Not filled in' : decoration.hintText,
    hintStyle: empty
        ? Theme.of(context).textTheme.bodyMedium?.copyWith(
            fontStyle: FontStyle.italic,
            color: caution,
          )
        : decoration.hintStyle,
    filled: empty ? true : decoration.filled,
    fillColor: empty ? caution.withValues(alpha: 0.07) : decoration.fillColor,
    enabledBorder: empty
        ? OutlineInputBorder(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            borderSide: BorderSide(color: caution.withValues(alpha: 0.7)),
          )
        : decoration.enabledBorder,
  );
}

/// How many amber boxes a row's record has, beside the row in its list.
/// Nothing at all at zero, rather than a badge saying 0.
class MissingCount extends StatelessWidget {
  const MissingCount(
    this.count, {
    super.key,
    required this.tooltip,
    this.suffix = '',
  });

  final int count;
  final String tooltip;

  /// Words after the number, e.g. ' missing': a phone has no hover, so a
  /// bare number there means nothing (UI-UX, 2026-11-16).
  final String suffix;

  @override
  Widget build(BuildContext context) {
    if (count <= 0) return const SizedBox.shrink();

    final caution = StateColour.of(context).caution;

    return Tooltip(
      message: tooltip,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
        decoration: BoxDecoration(
          color: caution.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          '$count$suffix',
          style: Theme.of(context).textTheme.labelSmall?.copyWith(
            color: caution,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}
