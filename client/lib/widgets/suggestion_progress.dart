import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Where an approved suggestion is on its way to the app (Jose, 2026-11-17:
/// "so people know their stuff is being implemented", like the bar in
/// What's been built). Approved → Being built → In testing → Releasing →
/// Live. Nothing for a suggestion that is not approved.
class SuggestionProgress extends StatelessWidget {
  const SuggestionProgress({
    super.key,
    required this.row,
    this.upcoming,
    this.compact = false,
  });

  /// The suggestion: `status`, `progress`, `release_version`.
  final Map<String, dynamic> row;

  /// `/release/upcoming` ({version, percent}), when a release is on its way.
  final Map? upcoming;

  /// The list row: the bar and one line. The detail adds each step's name.
  final bool compact;

  static const steps = ['approved', 'building', 'testing', 'releasing', 'live'];
  static const _labels = [
    'Approved',
    'Being built',
    'In testing',
    'Releasing',
    'Live',
  ];

  @override
  Widget build(BuildContext context) {
    if (row['status'] != 'approved') return const SizedBox.shrink();
    final at = steps.indexOf('${row['progress'] ?? 'approved'}');
    if (at < 0) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final version = (row['release_version'] as String?)?.replaceFirst('v', '');
    final live = at == steps.length - 1;
    final done = live ? EzBiziTheme.clockIn(context) : theme.colorScheme.primary;
    final upcomingVersion = '${upcoming?['version'] ?? ''}'.replaceFirst(
      'v',
      '',
    );
    final onItsWay =
        at == 3 && version != null && version == upcomingVersion
        ? (upcoming?['percent'] as num?)?.toInt()
        : null;

    String label(int i) => i == steps.length - 1 && version != null
        ? 'Live in v$version'
        : _labels[i];

    final line = onItsWay != null
        ? 'v$version is on its way · $onItsWay%'
        : live
        ? label(at)
        : '${label(at)} · step ${at + 1} of ${steps.length}';

    return Column(
      key: ValueKey('suggestion-progress-${row['id']}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            for (var i = 0; i < steps.length; i++) ...[
              if (i > 0) const SizedBox(width: 3),
              Expanded(
                child: Container(
                  height: compact ? 4 : 6,
                  decoration: BoxDecoration(
                    color: i <= at
                        ? done
                        : theme.colorScheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
              ),
            ],
          ],
        ),
        if (!compact) ...[
          const SizedBox(height: 4),
          Row(
            children: [
              for (var i = 0; i < steps.length; i++) ...[
                if (i > 0) const SizedBox(width: 3),
                Expanded(
                  child: Text(
                    label(i),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.labelSmall?.copyWith(
                      fontWeight: i == at ? FontWeight.w700 : null,
                      color: i == at
                          ? theme.colorScheme.onSurface
                          : theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ],
        const SizedBox(height: 4),
        Text(
          line,
          key: const ValueKey('suggestion-progress-line'),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodySmall?.copyWith(
            fontWeight: FontWeight.w600,
            color: live ? done : null,
          ),
        ),
        if (onItsWay != null) ...[
          const SizedBox(height: 4),
          LinearProgressIndicator(
            value: onItsWay / 100,
            minHeight: compact ? 3 : 5,
            borderRadius: BorderRadius.circular(3),
            semanticsLabel: 'Update progress',
          ),
        ],
      ],
    );
  }
}
