import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../core/ui_strings.dart';

/// The week at a glance: one row per day, green in and coral out, or
/// *Day off* (design 2026-09-08 §10.3, Jose's reference layout).
///
/// Read-only unless [canEdit]. On Your account the organization's
/// self_schedule switch decides that; on Work Hours it is `work_hours.manage`.
///
/// Shared since 2026-09-24, when Work Hours needed the same card on each
/// person's week so that somebody other than the person could set it — one
/// card, so the two places cannot disagree about how a schedule looks.
class WorkScheduleCard extends StatelessWidget {
  const WorkScheduleCard({
    super.key,
    required this.schedule,
    required this.canEdit,
    required this.onEdit,
    this.caption,
  });

  final Map<String, dynamic>? schedule;
  final bool canEdit;
  final VoidCallback onEdit;

  /// One line under the heading, e.g. that these are the business hours
  /// because the person has no schedule of their own.
  final String? caption;

  static const days = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
  ];

  static String title(String day) => day[0].toUpperCase() + day.substring(1, 3);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final week = schedule?['days'];
    final map = week is Map ? week : const <String, dynamic>{};

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.schedule_outlined, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    UiStrings.t('time_clock.work_schedule'),
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                if (canEdit)
                  TextButton.icon(
                    onPressed: onEdit,
                    icon: const Icon(Icons.edit_outlined, size: 16),
                    label: const Text('Edit'),
                  ),
              ],
            ),
            if (caption != null)
              Text(
                caption!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            const SizedBox(height: 8),
            // A phone gets a different SHAPE, not a narrower one -- UI/UX work
            // schedule layouts 1 + 5, chosen by Jose 2026-09-09. The question
            // a phone is asked is "what am I doing today", and seven equal
            // rows make you find today before you can answer it.
            // Nobody has written a schedule: say that, rather than drawing
            // seven "Day off" rows. They are opposite facts and were rendering
            // identically -- a person with no schedule looked exactly like a
            // person who works no days, and neither could tell which they were.
            if (week is! Map)
              Text(
                UiStrings.t('time_clock.no_schedule'),
                style: theme.textTheme.bodyMedium,
              )
            else
              LayoutBuilder(
                builder: (context, c) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: c.maxWidth < 600
                      ? _phone(context, theme, map)
                      : [
                          for (final day in days)
                            _row(context, theme, map, day),
                        ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _row(BuildContext context, ThemeData theme, Map map, String day) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          SizedBox(
            width: 44,
            child: Text(title(day), style: theme.textTheme.titleSmall),
          ),
          if (map[day] is Map) ...[
            _chip(
              context,
              Icons.login,
              (map[day] as Map)['start'] as String? ?? '',
              EzBiziTheme.clockIn(context),
            ),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 8),
              child: Icon(Icons.arrow_forward, size: 14),
            ),
            _chip(
              context,
              Icons.logout,
              (map[day] as Map)['end'] as String? ?? '',
              Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ] else
            Text(
              UiStrings.t('time_clock.day_off'),
              style: theme.textTheme.bodyMedium?.copyWith(
                fontStyle: FontStyle.italic,
              ),
            ),
        ],
      ),
    );
  }

  /// Today at the top, then the rest of the week small.
  ///
  /// `DateTime.now().weekday` is 1..7 from Monday, which is the order [days]
  /// is written in -- so the -1 is an index and not an assumption about
  /// locale. The week still shows in full underneath: the point is that today
  /// is found for you, not that the other six stop being reachable.
  List<Widget> _phone(BuildContext context, ThemeData theme, Map map) {
    final today = days[DateTime.now().weekday - 1];
    return [
      _today(context, theme, map, today),
      const SizedBox(height: 4),
      for (final day in days)
        if (day != today) _row(context, theme, map, day),
    ];
  }

  Widget _today(BuildContext context, ThemeData theme, Map map, String today) {
    final window = map[today];
    final working = window is Map;
    final tint = working
        ? EzBiziTheme.clockIn(context)
        : theme.colorScheme.onSurfaceVariant;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.06),
        border: Border.all(color: tint.withValues(alpha: 0.35)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            'TODAY · ${today.toUpperCase()}',
            style: theme.textTheme.bodySmall?.copyWith(
              color: tint,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.4,
            ),
          ),
          const SizedBox(height: 4),
          if (working)
            // The times, at the size the one question deserves. No third line:
            // a schedule day holds `start` and `end` and nothing else, so
            // anything below this would be invented.
            Row(
              children: [
                Text(
                  window['start'] as String? ?? '',
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: EzBiziTheme.clockIn(context),
                  ),
                ),
                Text('  –  ', style: theme.textTheme.headlineSmall),
                Text(
                  window['end'] as String? ?? '',
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            )
          else
            Text(
              UiStrings.t('time_clock.day_off'),
              style: theme.textTheme.headlineSmall?.copyWith(color: tint),
            ),
        ],
      ),
    );
  }

  Widget _chip(BuildContext context, IconData icon, String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 4),
          Text(
            text,
            style: TextStyle(color: color, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
