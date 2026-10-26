import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Numbered steps in a row: the one on screen filled, finished ones ticked.
///
/// The automation builder's timeline (1 Name, 2 When, …), lifted out so a
/// second screen can show the same thing (Jose, 2026-10-26: the inspection
/// a technician walks shows its screens as steps). On a phone each step is a
/// dot and only the current one carries its words, as in the builder.
///
/// ponytail: the automation builder still draws its own copy; move it onto
/// this when that screen is next touched.
class StepTimeline extends StatelessWidget {
  const StepTimeline({
    super.key,
    required this.labels,
    required this.current,
    this.onTap,
    this.keyPrefix = 'step',
  });

  final List<String> labels;

  /// The step on screen. Every step before it counts as done.
  final int current;

  /// Called with a step index. Only earlier steps are tappable: going back is
  /// always safe, jumping ahead would skip required answers.
  final ValueChanged<int>? onTap;

  final String keyPrefix;

  /// Below this the steps become dots.
  static const double _wordsAbove = 600;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < _wordsAbove;
        final children = <Widget>[];

        for (var i = 0; i < labels.length; i++) {
          if (i > 0) {
            final line = _line(context, i - 1 < current);
            children.add(
              narrow ? Expanded(child: line) : SizedBox(width: 18, child: line),
            );
          }
          children.add(
            narrow ? _dot(context, i) : Expanded(child: _step(context, i)),
          );
        }

        return Row(children: children);
      },
    );
  }

  VoidCallback? _tap(int step) =>
      onTap != null && step < current ? () => onTap!(step) : null;

  Widget _line(BuildContext context, bool done) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 4),
    child: SizedBox(
      height: 2,
      child: ColoredBox(
        color: done
            ? ToneColours.of(context, Tone.ok).ink
            : EzBiziTheme.edge(context),
      ),
    ),
  );

  Widget _mark(BuildContext context, int step) {
    final scheme = Theme.of(context).colorScheme;
    final isCurrent = step == current;
    final done = step < current;
    final ok = ToneColours.of(context, Tone.ok);

    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: isCurrent
            ? scheme.primary
            : done
            ? ok.fill
            : scheme.surface,
        border: Border.all(
          color: isCurrent
              ? scheme.primary
              : done
              ? ok.ink
              : scheme.outline,
          width: 1.5,
        ),
      ),
      child: done
          ? Icon(Icons.check, size: 14, color: ok.ink)
          : Text(
              '${step + 1}',
              style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: isCurrent ? scheme.onPrimary : scheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
    );
  }

  Widget _step(BuildContext context, int step) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final isCurrent = step == current;

    return Material(
      color: isCurrent ? scheme.primaryContainer : scheme.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        key: ValueKey('$keyPrefix-$step'),
        borderRadius: BorderRadius.circular(10),
        onTap: _tap(step),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
          child: Row(
            children: [
              _mark(context, step),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  labels[step],
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: isCurrent ? scheme.onPrimaryContainer : null,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _dot(BuildContext context, int step) {
    final isCurrent = step == current;

    return InkWell(
      key: ValueKey('$keyPrefix-$step'),
      borderRadius: BorderRadius.circular(14),
      onTap: _tap(step),
      child: Padding(
        padding: const EdgeInsets.all(2),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _mark(context, step),
            if (isCurrent) ...[
              const SizedBox(width: 6),
              Text(labels[step], style: Theme.of(context).textTheme.labelLarge),
            ],
          ],
        ),
      ),
    );
  }
}
