import 'package:flutter/material.dart';

import 'clock_label.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/list_table.dart';
import '../../widgets/month_calendar.dart';
import '../../widgets/refused.dart';
import '../../widgets/status_pill.dart';

/// Newsletter › Dashboard — what has gone out, what is booked, what is still
/// a draft.
///
/// ── Layout A (Mia, 2026-10-02) ──────────────────────────────────
///
/// Tiles, then every newsletter as a table, with the month calendar beside it
/// on a wide screen. The tiles are the HR pattern: Sent, Scheduled and Drafts
/// filter the table; Addresses reached and Last one went out are facts and
/// filter nothing.
///
/// The tiles count from the server's `stats`, which covers every newsletter in
/// view. The list is capped at fifty, so a filter is asked of the server too
/// (`?status=`) rather than applied to the fifty — see [rows].
///
/// ── Why the calendar stays ──────────────────────────────────────
///
/// A scheduled newsletter is invisible until it arrives in somebody's inbox.
/// The table answers "what is there"; the calendar answers "what is going out
/// this month", which is the question a person asks before booking a
/// promotion or leaving for a week. Its entries open for editing.
class NewsletterDashboard extends StatelessWidget {
  const NewsletterDashboard({
    super.key,
    required this.stats,
    required this.rows,
    required this.scheduled,
    required this.viewName,
    required this.session,
    required this.status,
    required this.onStatus,
    required this.onOpen,
    required this.onNew,
  });

  final NewsletterStats stats;

  /// The table: every newsletter in view, or those with [status].
  final List<NewsletterRow> rows;

  /// The scheduled ones, with the wall time they are promised for.
  final List<ScheduledNewsletter> scheduled;

  final String viewName;
  final Session session;

  /// The tile picked: `sent`, `scheduled` or `draft`; null is everything.
  final String? status;
  final ValueChanged<String?> onStatus;

  /// Null when this role cannot open the Composer: the rows are then to read.
  final ValueChanged<String>? onOpen;
  final VoidCallback onNew;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 1100;

        final calendar = MonthCalendar(
          key: const ValueKey('tour-newsletter-calendar'),
          title: 'Scheduled',
          entries: [
            for (final s in scheduled)
              if (s.at != null)
                CalendarEntry(
                  day: s.at!,
                  label: s.subject,
                  detail: '${s.subject} · ${s.wall}${clockSuffix(s.zone)}',
                  onTap: () => onOpen?.call(s.id),
                ),
          ],
          emptyNote: 'Nothing is scheduled this month. A newsletter with a time '
              'on it appears here, and opens for editing until it goes.',
        );

        return ListView(
          padding: const EdgeInsets.all(12),
          children: [
            _bar(context),
            const SizedBox(height: 12),
            _tiles(context),
            const SizedBox(height: 12),
            if (wide)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 3, child: _table(context)),
                  const SizedBox(width: 12),
                  Expanded(flex: 2, child: calendar),
                ],
              )
            else ...[
              _table(context),
              const SizedBox(height: 12),
              calendar,
            ],
          ],
        );
      },
    );
  }

  Widget _bar(BuildContext context) {
    final can = session.me?.can('newsletter.create') ?? false;

    return Row(
      children: [
        Expanded(
          child: Text(
            'Mailings in $viewName',
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
        Refused(
          reason: session.me?.refusal('newsletter.create', 'write a newsletter'),
          child: FilledButton.icon(
            key: const ValueKey('tour-marketing-new-newsletter'),
            onPressed: can ? onNew : null,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New newsletter'),
          ),
        ),
      ],
    );
  }

  /// Three tiles that filter and two that state a fact, five across when
  /// there is room.
  ///
  /// FilterTiles lays out only the tiles it is given, so the two facts are
  /// drawn here at the same width beside it rather than as filters that
  /// would filter nothing.
  Widget _tiles(BuildContext context) {
    final filters = FilterTiles(
      key: const ValueKey('tour-marketing-status-tiles'),
      prefix: 'newsletters',
      picked: status,
      onPick: onStatus,
      tiles: [
        FilterTile(
          key: 'sent',
          label: 'Sent',
          count: stats.sent,
          note: 'went out',
          tone: Tone.ok,
        ),
        FilterTile(
          key: 'scheduled',
          label: 'Scheduled',
          count: stats.scheduled,
          note: 'booked',
          tone: Tone.info,
        ),
        FilterTile(
          key: 'draft',
          label: 'Drafts',
          count: stats.drafts,
          note: 'not sent',
        ),
      ],
    );

    // Named for what it is. The provider reports what happens after it takes
    // the mailing; EzBizi knows only what was accepted, and calling this
    // "delivered" would be a claim it cannot stand behind.
    final reached = FactTile(
      key: const Key('newsletters-fact-reached'),
      label: 'Addresses reached',
      value: '${stats.recipients}',
      note: 'accepted by the mailing account',
    );
    final lastAt = DateTime.tryParse(stats.lastSentAt ?? '')?.toLocal();
    final last = FactTile(
      key: const Key('newsletters-fact-last'),
      label: 'Last one went out',
      value: lastAt == null
          ? '—'
          : (session.me?.display ?? DisplayFormats.fallback).formatDate(lastAt),
      note: lastAt == null ? 'nothing sent yet' : '',
    );

    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        if (box.maxWidth >= 5 * 170) {
          final width = (box.maxWidth - gap * 4) / 5;
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(width: width * 3 + gap * 2, child: filters),
              const SizedBox(width: gap),
              SizedBox(width: width, child: reached),
              const SizedBox(width: gap),
              SizedBox(width: width, child: last),
            ],
          );
        }

        final half = (box.maxWidth - gap) / 2;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            filters,
            const SizedBox(height: gap),
            Row(
              children: [
                SizedBox(width: half, child: reached),
                const SizedBox(width: gap),
                SizedBox(width: half, child: last),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _table(BuildContext context) {
    if (rows.isEmpty) {
      return DashboardNote(
        key: const ValueKey('tour-newsletter-empty'),
        status == null
            ? 'No newsletters in $viewName yet. New newsletter starts one.'
            : 'None of the newsletters in $viewName are ${_tileWord(status!)}.',
      );
    }

    final formats = session.me?.display ?? DisplayFormats.fallback;
    final theme = Theme.of(context);

    return ListTable(
      key: const ValueKey('tour-newsletter-table'),
      columns: const [
        ListColumn('Newsletter', flex: 4),
        ListColumn('Status', flex: 2),
        ListColumn('Mailing list', flex: 3),
        ListColumn('When', flex: 3),
        ListColumn('Reached', flex: 2, end: true),
        ListColumn('', flex: 2, end: true),
      ],
      rowKey: (i) => ValueKey('newsletter-${rows[i].id}'),
      onTap: onOpen == null ? null : (i) => onOpen!(rows[i].id),
      rows: [
        for (final n in rows)
          [
            Text(
              n.subject,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
            ),
            StatusPill(n.statusLabel, tone: n.tone),
            Text(
              n.audienceName == null
                  ? 'No list chosen'
                  : '${n.audienceName} · ${n.audienceWhere}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
            Text(n.when(formats), style: theme.textTheme.bodySmall),
            Text(
              n.recipients == null ? '—' : '${n.recipients}',
              style: theme.textTheme.bodyMedium,
            ),
            if (onOpen == null)
              const SizedBox.shrink()
            else
              TextButton(
                onPressed: () => onOpen!(n.id),
                child: Text(n.status == 'draft' || n.status == 'failed' ? 'Open' : 'View'),
              ),
          ],
      ],
    );
  }

  static String _tileWord(String key) => switch (key) {
    'sent' => 'sent',
    'scheduled' => 'scheduled',
    _ => 'drafts',
  };
}

/// One line of the table, from a GET /newsletters row.
class NewsletterRow {
  const NewsletterRow({
    required this.id,
    required this.subject,
    required this.status,
    this.audienceName,
    this.audienceKind,
    this.scheduledFor,
    this.scheduledTimezone,
    this.sentAt,
    this.recipients,
  });

  final String id;
  final String subject;
  final String status;
  final String? audienceName;
  final String? audienceKind;
  final String? scheduledFor;
  final String? scheduledTimezone;

  /// `last_send.sent_at`: when the provider took it.
  final String? sentAt;

  /// `last_send.recipients` on a sent one: addresses the provider accepted.
  final int? recipients;

  String get audienceWhere =>
      audienceKind == 'mailchimp' ? 'in Mailchimp' : 'built from your customers';

  String get statusLabel => switch (status) {
    'sent' => 'Sent',
    'sending' => 'Sending',
    'scheduled' => 'Scheduled',
    'failed' => 'Failed',
    _ => 'Draft',
  };

  Tone get tone => switch (status) {
    'sent' => Tone.ok,
    'sending' || 'scheduled' => Tone.info,
    'failed' => Tone.bad,
    _ => Tone.neutral,
  };

  /// The time it went, or the wall time it is promised for and its clock.
  String when(DisplayFormats formats) {
    if (sentAt != null && status != 'scheduled') {
      return formatStamp(sentAt, '—', formats: formats);
    }
    final wall = scheduledFor;
    final at = wall == null ? null : DateTime.tryParse(wall.replaceFirst(' ', 'T'));
    if (at == null) return '—';

    return '${formats.formatDate(at)} ${formats.formatTime(at.hour, at.minute)}'
        '${clockSuffix(scheduledTimezone)}';
  }

  factory NewsletterRow.fromJson(Map<String, dynamic> json) {
    final audience = json['audience'];
    final send = json['last_send'];
    final status = json['status'] as String? ?? 'draft';

    return NewsletterRow(
      id: json['id'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      status: status,
      audienceName: audience is Map ? audience['name'] as String? : null,
      audienceKind: audience is Map ? audience['kind'] as String? : null,
      scheduledFor: json['scheduled_for'] as String?,
      scheduledTimezone: json['scheduled_timezone'] as String?,
      sentAt: send is Map ? send['sent_at'] as String? : null,
      // Only a sent one has reached anybody; a queued send carries 0, which
      // would read as "reached nobody".
      recipients: status == 'sent' && send is Map ? (send['recipients'] as num?)?.toInt() : null,
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

  /// ISO 8601, as the server sent it.
  final String? lastSentAt;

  factory NewsletterStats.fromJson(Map<String, dynamic> json) => NewsletterStats(
    drafts: json['drafts'] as int? ?? 0,
    scheduled: json['scheduled'] as int? ?? 0,
    sent: json['sent'] as int? ?? 0,
    recipients: json['recipients'] as int? ?? 0,
    lastSentAt: json['last_sent_at'] as String?,
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
