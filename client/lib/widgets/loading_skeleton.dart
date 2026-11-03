import 'package:flutter/material.dart';

/// Grey rows where the content will be, for a load that takes seconds rather
/// than a blink (deep dive 2026-11-02: Email › Settings about 8 s, Profit &
/// Loss over 15 s, behind a lone spinner that read as a hang).
///
/// ponytail: static bars, no shimmer; add one if a still skeleton reads as
/// frozen.
class LoadingSkeleton extends StatelessWidget {
  const LoadingSkeleton({super.key, this.rows = 6});

  final int rows;

  @override
  Widget build(BuildContext context) {
    final fill = Theme.of(context).colorScheme.surfaceContainerHighest;
    Widget bar(double widthFactor, double height) => FractionallySizedBox(
      alignment: Alignment.centerLeft,
      widthFactor: widthFactor,
      child: Container(
        height: height,
        decoration: BoxDecoration(
          color: fill,
          borderRadius: BorderRadius.circular(6),
        ),
      ),
    );
    return Semantics(
      label: 'Loading',
      child: ListView(
        key: const Key('loading-skeleton'),
        padding: const EdgeInsets.all(16),
        physics: const NeverScrollableScrollPhysics(),
        children: [
          for (var i = 0; i < rows; i++) ...[
            bar(i.isEven ? 0.6 : 0.45, 14),
            const SizedBox(height: 8),
            bar(i.isEven ? 0.9 : 0.8, 10),
            const SizedBox(height: 20),
          ],
        ],
      ),
    );
  }
}
