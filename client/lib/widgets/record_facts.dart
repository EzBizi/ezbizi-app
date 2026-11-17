import 'package:flutter/material.dart';

import 'copy_button.dart';

/// One labelled fact on a record: a label, its value, and a copy button after
/// the value when somebody would paste it elsewhere (a number, a phone).
class RecordFact {
  const RecordFact(this.label, this.value, {this.copy = false});

  final String label;
  final String value;
  final bool copy;
}

/// Design 196 (Jose, 2026-11-17, on the lead header: "this is how stuff
/// should be all around the app"): a record's facts as the lead draws them,
/// a label column and the value, each fact once and only when it has
/// something. Two columns when there is room, one on a phone.
class RecordFacts extends StatelessWidget {
  const RecordFacts(this.facts, {super.key});

  final List<RecordFact> facts;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final shown = facts.where((f) => f.value.trim().isNotEmpty).toList();
    if (shown.isEmpty) return const SizedBox.shrink();
    final labelStyle = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
    );

    return LayoutBuilder(
      builder: (context, box) {
        final columns = box.maxWidth >= 440 ? 2 : 1;
        const gap = 14.0;
        final width = (box.maxWidth - gap * (columns - 1)) / columns;
        return Wrap(
          spacing: gap,
          runSpacing: 2,
          children: [
            for (final f in shown)
              SizedBox(
                key: ValueKey('fact-${f.label}'),
                width: width,
                // As tall as a copy button, so rows with and without one
                // line up.
                height: 24,
                child: Row(
                  children: [
                    SizedBox(
                      width: 76,
                      child: Text(f.label, style: labelStyle),
                    ),
                    Flexible(
                      child: Text(
                        f.value,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                    if (f.copy) CopyButton(f.value, what: f.label),
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}
