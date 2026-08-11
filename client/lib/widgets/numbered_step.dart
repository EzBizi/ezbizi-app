import 'package:flutter/material.dart';

/// One numbered step of a long form — a circle, a heading, a sentence, a body.
///
/// ── Why it is a widget rather than a copy ────────────────────────
///
/// The automation builder invented this shape for a real reason, written up in
/// its own docblock: a form that is four decisions deep does not fit a side
/// panel, and squeezing it into one turned the hardest decision into a dropdown
/// of raw keys. The answer was the whole page and the decisions numbered in the
/// order somebody makes them.
///
/// The contract designer is the same problem with a different vocabulary, and it
/// was going to reach for the same shape. Two private `_step` methods drawing
/// the same thing is how the two screens start disagreeing about the size of a
/// heading, so the drawing lives here and both call it.
class NumberedStep extends StatelessWidget {
  const NumberedStep({
    super.key,
    required this.number,
    required this.title,
    required this.note,
    required this.child,
    this.action,
  });

  final int number;
  final String title;

  /// What this step is for, in prose. Not a hint and not a placeholder: every
  /// step here decides something with a consequence, and the sentence is where
  /// the consequence is stated.
  final String note;

  final Widget child;

  /// The step's own control, on the right of the heading — "Add a step",
  /// "Add a question".
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CircleAvatar(
              radius: 13,
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Text(
                '$number',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: theme.colorScheme.onPrimaryContainer,
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: theme.textTheme.titleMedium),
                  const SizedBox(height: 2),
                  Text(note, style: theme.textTheme.bodyMedium),
                ],
              ),
            ),
            if (action != null) ...[const SizedBox(width: 12), action!],
          ],
        ),
        const SizedBox(height: 12),
        Padding(padding: const EdgeInsets.only(left: 38), child: child),
      ],
    );
  }
}
