import 'package:flutter/material.dart';

import '../core/theme.dart';

/// The pieces a module's General tab is built from (Inventory and Fleet,
/// Mia 2026-10-02: "a main tab ... which is basically a dashboard"). The
/// numbers are the server's; these only lay them out.

/// One headline figure. A null [onTap] is drawn as text, never a dead control.
class OverviewFigure {
  const OverviewFigure({
    required this.label,
    required this.value,
    this.note = '',
    this.tone,
    this.onTap,
    this.key,
  });

  final String label;
  final String value;
  final String note;
  final Tone? tone;
  final VoidCallback? onTap;
  final Key? key;
}

/// Figures in a wrapping grid: four across on a desktop, two on a phone.
class OverviewFigures extends StatelessWidget {
  const OverviewFigures({super.key, required this.figures});

  final List<OverviewFigure> figures;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final across = box.maxWidth >= 900
            ? 4
            : box.maxWidth >= 420
            ? 3
            : 2;
        const gap = 10.0;
        final width = (box.maxWidth - gap * (across - 1)) / across;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final figure in figures)
              SizedBox(width: width, child: _FigureCard(figure)),
          ],
        );
      },
    );
  }
}

class _FigureCard extends StatelessWidget {
  const _FigureCard(this.figure);

  final OverviewFigure figure;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ink = figure.tone == null
        ? null
        : ToneColours.of(context, figure.tone!).ink;
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return Material(
      key: figure.key,
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: InkWell(
        borderRadius: radius,
        onTap: figure.onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  figure.label.toUpperCase(),
                  style: theme.textTheme.labelSmall?.copyWith(
                    letterSpacing: 0.8,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  figure.value,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: ink,
                  ),
                ),
                if (figure.note.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    figure.note,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A titled block on a General tab.
class OverviewSection extends StatelessWidget {
  const OverviewSection({
    super.key,
    required this.title,
    required this.child,
    this.trailing,
  });

  final String title;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 12,
            runSpacing: 8,
            children: [
              Text(title, style: theme.textTheme.titleMedium),
              ?trailing,
            ],
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}

/// Week / month / quarter / year, the Dashboard's own vocabulary.
class OverviewPeriodPicker extends StatelessWidget {
  const OverviewPeriodPicker({
    super.key,
    required this.period,
    required this.onChanged,
  });

  final String period;
  final ValueChanged<String> onChanged;

  static const periods = [
    ('week', 'Week'),
    ('month', 'Month'),
    ('quarter', 'Quarter'),
    ('year', 'Year'),
  ];

  @override
  Widget build(BuildContext context) {
    return SegmentedButton<String>(
      showSelectedIcon: false,
      segments: [
        for (final (key, label) in periods)
          ButtonSegment(value: key, label: Text(label)),
      ],
      selected: {period},
      onSelectionChanged: (value) => onChanged(value.first),
    );
  }
}
