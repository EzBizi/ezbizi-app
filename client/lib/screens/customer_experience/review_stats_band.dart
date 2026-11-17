import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/side_panel.dart';

/// Review stats beside the reply queue (design 154 #7): the ratings
/// histogram, an average gauge, and who wrote the replies, from GET /reviews
/// `meta.stats`. Draft all and the send window sit in the same band.
///
/// The send window is not a setting of its own: replies a rule publishes
/// wait that rule's wait, spread over up to twice it (A1 Leads' pacing), so
/// the band links to Automations instead of drawing a second control.
class ReviewStatsBand extends StatelessWidget {
  const ReviewStatsBand({
    super.key,
    required this.stats,
    this.onDraftAll,
    this.toDraft = 0,
    this.drafting = false,
    this.onSendWindow,
    this.compact = false,
  });

  final Map<String, dynamic> stats;

  /// Writes drafts for the unanswered reviews in view; null hides the button.
  final VoidCallback? onDraftAll;
  final int toDraft;
  final bool drafting;

  /// Opens the rules whose wait paces the replies; null when Automations is
  /// not this person's.
  final VoidCallback? onSendWindow;

  /// A phone (design 195): one tappable line; the whole band opens beside.
  final bool compact;

  Map<int, int> get _histogram {
    final raw = stats['histogram'];
    return {
      for (var r = 1; r <= 5; r++)
        r: raw is Map ? ((raw['$r'] ?? raw[r]) as num?)?.toInt() ?? 0 : 0,
    };
  }

  /// `meta.stats.monthly`: twelve {month 'Y-m', count, average}, oldest first.
  List<Map> get _monthly =>
      (stats['monthly'] is List ? stats['monthly'] as List : const [])
          .whereType<Map>()
          .toList();

  /// Tallest monthly bar, in logical pixels.
  static const double monthlyBarMax = 40;

  int _reply(String k) =>
      ((stats['replies'] as Map?)?[k] as num?)?.toInt() ?? 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final h = _histogram;
    final total = h.values.fold<int>(0, (a, b) => a + b);
    final most = h.values.fold<int>(0, (a, b) => a > b ? a : b);
    final average = total == 0
        ? null
        : h.entries.fold<int>(0, (a, e) => a + e.key * e.value) / total;
    final published = _reply('published');
    final rate = total == 0 ? null : (published * 100 / total).round();
    final scheme = theme.colorScheme;

    if (compact) {
      return InkWell(
        key: const ValueKey('review-stats-line'),
        onTap: () => showSidePanel<void>(
          context,
          builder: (_) => SidePanel(
            title: 'Reviews at a glance',
            child: ReviewStatsBand(
              stats: stats,
              toDraft: toDraft,
              drafting: drafting,
              onDraftAll: onDraftAll,
              onSendWindow: onSendWindow,
            ),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 12, 4),
          child: Row(
            children: [
              Text(
                average == null ? '— ★' : '${average.toStringAsFixed(1)} ★',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  [
                    '$total rated',
                    if (rate != null) '$rate% replied',
                    if (toDraft > 0) '$toDraft to draft',
                  ].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),
              Icon(Icons.chevron_right, color: scheme.onSurfaceVariant),
            ],
          ),
        ),
      );
    }

    final bars = Column(
      key: const ValueKey('cx-histogram'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var r = 5; r >= 1; r--)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: [
                SizedBox(
                  width: 28,
                  child: Text('$r★', style: theme.textTheme.bodySmall),
                ),
                Expanded(
                  child: Container(
                    height: 10,
                    decoration: BoxDecoration(
                      color: scheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(5),
                    ),
                    alignment: Alignment.centerLeft,
                    child: FractionallySizedBox(
                      widthFactor: most == 0 ? 0 : h[r]! / most,
                      heightFactor: 1,
                      child: Container(
                        key: ValueKey('cx-histogram-bar-$r'),
                        decoration: BoxDecoration(
                          color: r <= 2
                              ? ToneColours.of(context, Tone.bad).ink
                              : scheme.primary,
                          borderRadius: BorderRadius.circular(5),
                        ),
                      ),
                    ),
                  ),
                ),
                SizedBox(
                  width: 40,
                  child: Text(
                    '${h[r]}',
                    textAlign: TextAlign.right,
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
      ],
    );

    final gauge = Column(
      key: const ValueKey('cx-gauge'),
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          average == null ? '—' : '${average.toStringAsFixed(1)} ★',
          style: theme.textTheme.headlineSmall,
        ),
        const SizedBox(height: 4),
        Container(
          width: 120,
          height: 8,
          decoration: BoxDecoration(
            color: scheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(4),
          ),
          alignment: Alignment.centerLeft,
          child: FractionallySizedBox(
            widthFactor: average == null ? 0 : average / 5,
            heightFactor: 1,
            child: Container(
              key: const ValueKey('cx-gauge-fill'),
              decoration: BoxDecoration(
                color: scheme.primary,
                borderRadius: BorderRadius.circular(4),
              ),
            ),
          ),
        ),
        const SizedBox(height: 4),
        Text('$total rated', style: theme.textTheme.bodySmall),
      ],
    );

    Widget figure(String key, String value, String label) => Padding(
      key: ValueKey('cx-replies-$key'),
      padding: const EdgeInsets.only(right: 16, bottom: 4),
      child: Text.rich(
        TextSpan(
          children: [
            TextSpan(
              text: value,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            TextSpan(text: ' $label'),
          ],
        ),
        style: theme.textTheme.bodySmall,
      ),
    );

    final months = _monthly;
    final mostInMonth = months.fold<int>(
      0,
      (m, r) => ((r['count'] as num?)?.toInt() ?? 0) > m
          ? (r['count'] as num).toInt()
          : m,
    );
    final latestAverage = months.reversed
        .map((r) => (r['average'] as num?)?.toDouble())
        .firstWhere((a) => a != null, orElse: () => null);
    final monthly = months.isEmpty
        ? null
        : Column(
            key: const ValueKey('cx-monthly'),
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                height: monthlyBarMax,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    for (var i = 0; i < months.length; i++)
                      Tooltip(
                        message:
                            '${months[i]['month']}: ${months[i]['count'] ?? 0} '
                            'reviews'
                            '${months[i]['average'] == null ? '' : ' · ${(months[i]['average'] as num).toStringAsFixed(1)} ★'}',
                        child: Container(
                          key: ValueKey('cx-monthly-bar-$i'),
                          width: 10,
                          margin: const EdgeInsets.only(right: 3),
                          height: mostInMonth == 0
                              ? 1
                              : 1 +
                                    (((months[i]['count'] as num?)?.toInt() ??
                                                0) /
                                            mostInMonth) *
                                        (monthlyBarMax - 1),
                          decoration: BoxDecoration(
                            color: scheme.primary,
                            borderRadius: BorderRadius.circular(2),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 4),
              Text(
                latestAverage == null
                    ? 'Reviews a month, 12 months'
                    : 'Reviews a month · latest avg '
                          '${latestAverage.toStringAsFixed(1)} ★',
                key: const ValueKey('cx-monthly-average'),
                style: theme.textTheme.bodySmall,
              ),
            ],
          );

    final replies = Wrap(
      children: [
        figure('rate', rate == null ? '—' : '$rate%', 'replied'),
        figure('ai', '${_reply('by_ai')}', 'AI replies sent'),
        figure('drafts', '${_reply('drafts')}', 'drafts waiting'),
        if (_reply('failed') > 0)
          figure('failed', '${_reply('failed')}', 'failed to post'),
      ],
    );

    final actions = Wrap(
      spacing: 8,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (onDraftAll != null)
          FilledButton.tonal(
            key: const ValueKey('cx-draft-all'),
            onPressed: drafting || toDraft == 0 ? null : onDraftAll,
            child: Text(drafting ? 'Drafting…' : 'Draft all $toDraft'),
          ),
        if (onSendWindow != null)
          TextButton(
            key: const ValueKey('cx-send-window'),
            onPressed: onSendWindow,
            child: const Text('Send window: each rule\'s wait · Edit'),
          ),
      ],
    );

    return LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < 700;
        return Padding(
          key: const ValueKey('cx-review-stats'),
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
          child: narrow
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        gauge,
                        const SizedBox(width: 16),
                        Expanded(child: replies),
                      ],
                    ),
                    if (monthly != null) ...[
                      const SizedBox(height: 8),
                      monthly,
                    ],
                    actions,
                  ],
                )
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    gauge,
                    const SizedBox(width: 24),
                    SizedBox(width: 260, child: bars),
                    if (monthly != null) ...[
                      const SizedBox(width: 24),
                      monthly,
                    ],
                    const SizedBox(width: 24),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [replies, const SizedBox(height: 4), actions],
                      ),
                    ),
                  ],
                ),
        );
      },
    );
  }
}
