import 'package:flutter/material.dart';

import '../core/theme.dart';
import 'dashboard_chart.dart';

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

/// One thing that needs attention. A null [onTap] is text.
class OverviewWarning {
  const OverviewWarning({
    required this.sentence,
    this.tone = Tone.caution,
    this.onTap,
  });

  final String sentence;
  final Tone tone;
  final VoidCallback? onTap;
}

/// The server's warnings in the server's order; none is a sentence, not an
/// empty box.
class OverviewWarnings extends StatelessWidget {
  const OverviewWarnings({super.key, required this.warnings});

  final List<OverviewWarning> warnings;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (warnings.isEmpty) {
      return Row(
        children: [
          Icon(
            Icons.check_circle_outline,
            color: ToneColours.of(context, Tone.ok).ink,
          ),
          const SizedBox(width: 8),
          const Expanded(child: Text('Nothing needs attention.')),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final warning in warnings)
          InkWell(
            onTap: warning.onTap,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 44),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Icon(
                      warning.tone == Tone.bad
                          ? Icons.error_outline
                          : Icons.warning_amber_rounded,
                      size: 20,
                      color: ToneColours.of(context, warning.tone).ink,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        warning.sentence,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    if (warning.onTap != null)
                      Icon(
                        Icons.chevron_right,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                  ],
                ),
              ),
            ),
          ),
      ],
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

/// Lines over the server's buckets, drawn by the Dashboard's own chart.
///
/// [lines] are (label, points) index-aligned with [buckets]. Nothing to draw
/// is a sentence.
class OverviewTrend extends StatelessWidget {
  const OverviewTrend({
    super.key,
    required this.buckets,
    required this.lines,
    this.emptySentence = 'Nothing happened in this period.',
  });

  final List<String> buckets;
  final List<(String, List<num>)> lines;
  final String emptySentence;

  @override
  Widget build(BuildContext context) {
    final anything = lines.any((line) => line.$2.any((v) => v != 0));

    if (buckets.isEmpty || !anything) {
      return Text(
        emptySentence,
        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      );
    }

    return DashboardChart(
      presentation: 'line',
      series: [
        for (final (label, points) in lines)
          {
            'label': label,
            'points': [
              for (var i = 0; i < buckets.length && i < points.length; i++)
                {'at': buckets[i], 'value': points[i]},
            ],
          },
      ],
    );
  }
}

/// A list of `{label, value}` pairs as label ..... value rows.
class OverviewBreakdown extends StatelessWidget {
  const OverviewBreakdown({super.key, required this.rows});

  final List<(String, String)> rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      children: [
        for (final (label, value) in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                Expanded(child: Text(label, style: theme.textTheme.bodyMedium)),
                Text(
                  value,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Two blocks side by side on a desktop, stacked on a phone.
class OverviewPair extends StatelessWidget {
  const OverviewPair({super.key, required this.first, required this.second});

  final Widget first;
  final Widget second;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth < 900) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [first, const SizedBox(height: 16), second],
          );
        }

        // Not IntrinsicHeight: the chart lays out with a LayoutBuilder,
        // which cannot answer an intrinsic size.
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 3, child: first),
            const SizedBox(width: 16),
            Expanded(flex: 2, child: second),
          ],
        );
      },
    );
  }
}
