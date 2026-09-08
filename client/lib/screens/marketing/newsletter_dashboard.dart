import 'package:flutter/material.dart';

import 'clock_label.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/month_calendar.dart';

/// Newsletter › Dashboard — what has gone out, what is waiting, and when.
///
/// ── Why the calendar is the point ───────────────────────────────
///
/// A scheduled newsletter is invisible until it arrives in somebody's inbox.
/// The composer can say "goes out Tuesday" about the ONE you have open; nothing
/// could answer "what is going out this month", which is the question a person
/// asks before booking a promotion or leaving for a week.
///
/// So the calendar carries the scheduled ones and opens them for editing — a
/// list that only shows you what you already knew about would not have been
/// worth building.
class NewsletterDashboard extends StatelessWidget {
  const NewsletterDashboard({
    super.key,
    required this.stats,
    required this.scheduled,
    required this.viewName,
    required this.onOpen,
  });

  final NewsletterStats stats;

  /// The scheduled ones, with the wall time they are promised for.
  final List<ScheduledNewsletter> scheduled;

  final String viewName;
  final ValueChanged<String> onOpen;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < 900;

        final numbers = _numbers(context);
        final calendar = MonthCalendar(
          title: 'Scheduled',
          expand: !narrow,
          entries: [
            for (final s in scheduled)
              if (s.at != null)
                CalendarEntry(
                  day: s.at!,
                  label: s.subject,
                  detail: '${s.subject} · ${s.wall}${clockSuffix(s.zone)}',
                  onTap: () => onOpen(s.id),
                ),
          ],
          emptyNote: 'Nothing is scheduled this month. A newsletter with a time '
              'on it appears here, and opens for editing until it goes.',
        );

        if (narrow) {
          return ListView(
            padding: const EdgeInsets.all(12),
            children: [numbers, const SizedBox(height: 12), calendar],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 260, child: SingleChildScrollView(child: numbers)),
              const SizedBox(width: 12),
              Expanded(child: calendar),
            ],
          ),
        );
      },
    );
  }

  Widget _numbers(BuildContext context) {
    final theme = Theme.of(context);

    return DashboardCard(
      title: 'Mailings in $viewName',
      icon: Icons.insights_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _stat(theme, 'Sent', '${stats.sent}'),
          _stat(theme, 'Scheduled', '${stats.scheduled}'),
          _stat(theme, 'Drafts', '${stats.drafts}'),
          const Divider(height: 20),
          _stat(theme, 'Addresses reached', '${stats.recipients}'),
          const SizedBox(height: 6),
          // Named for what it is. The provider reports what happens after it
          // takes the mailing; EzBizi knows only what was accepted, and
          // calling this "delivered" would be a claim it cannot stand behind.
          Text(
            'Addresses the mailing account accepted, across every send. What '
            'happens after that is reported where the account lives.',
            style: theme.textTheme.bodySmall,
          ),
          if (stats.lastSentAt != null) ...[
            const SizedBox(height: 10),
            Text(
              'Last one went out ${stats.lastSentAt}.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  Widget _stat(ThemeData theme, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          // Expanded, or a label the width of "Addresses reached" pushes the
          // number past the edge of a 228-point card — 30 points past it, on
          // the first run. The number keeps its natural width; the label
          // gives way.
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodyMedium,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: 8),
          Text(value, style: theme.textTheme.titleMedium),
        ],
      ),
    );
  }
}

class NewsletterStats {
  const NewsletterStats({
    this.drafts = 0,
    this.scheduled = 0,
    this.sent = 0,
    this.recipients = 0,
    this.lastSentAt,
  });

  final int drafts;
  final int scheduled;
  final int sent;
  final int recipients;
  final String? lastSentAt;

  factory NewsletterStats.fromJson(Map<String, dynamic> json) => NewsletterStats(
    drafts: json['drafts'] as int? ?? 0,
    scheduled: json['scheduled'] as int? ?? 0,
    sent: json['sent'] as int? ?? 0,
    recipients: json['recipients'] as int? ?? 0,
    lastSentAt: (json['last_sent_at'] as String?)?.split('T').first,
  );
}

class ScheduledNewsletter {
  const ScheduledNewsletter({
    required this.id,
    required this.subject,
    required this.wall,
    required this.at,
    this.zone,
  });

  final String id;
  final String subject;

  /// The wall time exactly as promised, for the tooltip.
  final String wall;

  /// And the clock it was promised ON. A wall time with no clock beside it is
  /// only half a promise once the view is above a franchise, where the default
  /// is a fixed offset rather than the place's own time.
  final String? zone;

  /// The same, parsed to a day for the grid. Null when it will not parse,
  /// which puts the entry nowhere rather than on the wrong day.
  final DateTime? at;

  factory ScheduledNewsletter.fromJson(Map<String, dynamic> json) {
    final wall = json['scheduled_for'] as String? ?? '';

    return ScheduledNewsletter(
      id: json['id'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      wall: wall,
      zone: json['scheduled_timezone'] as String?,
      at: DateTime.tryParse(wall.replaceFirst(' ', 'T')),
    );
  }
}
