import 'package:flutter/material.dart';

import 'clock_label.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/month_calendar.dart';
import '../../widgets/refused.dart';
import '../../widgets/status_pill.dart';

/// Newsletter › Dashboard — what has gone out, what is booked, what is still
/// a draft.
///
/// ── Layout (newsletter P1c, 2026-11-18) ─────────────────────────
///
/// The campaign list comes first and takes the full width: internal name
/// over subject, a status chip, the mailing list, and the one thing worth
/// knowing next (a draft's next step, a scheduled one's local send time, a
/// sent one's provider result). The six mostly empty tiles became one
/// compact strip of status filters with honest counts and two facts, and the
/// month calendar is a secondary view behind a toggle, collapsed by default.
/// Rows wrap and ellipsize (full name in a tooltip); nothing scrolls sideways.
///
/// The counts come from the server's `stats`, which covers every newsletter
/// in view. The list is capped at fifty, so a filter is asked of the server
/// too (`?status=`) rather than applied to the fifty — see [rows].
class NewsletterDashboard extends StatefulWidget {
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
    this.onTemplate,
    this.search,
    this.query = '',
  });

  /// The list's search box, drawn in the bar beside New newsletter, and
  /// what it last searched for.
  final Widget? search;
  final String query;

  final NewsletterStats stats;

  /// The list: every newsletter in view, or those with [status].
  final List<NewsletterRow> rows;

  /// The scheduled ones, with the wall time they are promised for.
  final List<ScheduledNewsletter> scheduled;

  final String viewName;
  final Session session;

  /// The filter picked: `sent`, `scheduled`, `draft` or `failed`; null is all.
  final String? status;
  final ValueChanged<String?> onStatus;

  /// Null when this role cannot open the Composer: the rows are then to read.
  final ValueChanged<String>? onOpen;
  final VoidCallback onNew;

  /// Start from template (branded starters); null hides it.
  final VoidCallback? onTemplate;

  @override
  State<NewsletterDashboard> createState() => _NewsletterDashboardState();
}

class _NewsletterDashboardState extends State<NewsletterDashboard> {
  bool _calendar = false;

  @override
  Widget build(BuildContext context) {
    final w = widget;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _bar(context),
        const SizedBox(height: 12),
        _strip(context),
        const SizedBox(height: 12),
        _list(context),
        const SizedBox(height: 16),
        Column(
          key: const ValueKey('tour-newsletter-calendar'),
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextButton.icon(
              key: const ValueKey('newsletter-calendar-toggle'),
              onPressed: () => setState(() => _calendar = !_calendar),
              icon: Icon(_calendar ? Icons.expand_less : Icons.calendar_month_outlined, size: 18),
              label: Text(_calendar
                  ? 'Hide calendar'
                  : 'Show calendar (${w.scheduled.length} scheduled)'),
            ),
            if (_calendar)
              MonthCalendar(
                key: const ValueKey('newsletter-calendar'),
                title: 'Scheduled',
                entries: [
                  for (final s in w.scheduled)
                    if (s.at != null)
                      CalendarEntry(
                        day: s.at!,
                        label: s.subject,
                        detail: '${s.subject} · ${s.wall}${clockSuffix(s.zone)}',
                        onTap: () => w.onOpen?.call(s.id),
                      ),
                ],
                emptyNote: 'Nothing is scheduled this month. A newsletter with a time '
                    'on it appears here, and opens for editing until it goes.',
              ),
          ],
        ),
      ],
    );
  }

  Widget _bar(BuildContext context) {
    final w = widget;
    final can = w.session.me?.can('newsletter.create') ?? false;
    final newButton = Refused(
      reason: w.session.me?.refusal('newsletter.create', 'write a newsletter'),
      child: FilledButton.icon(
        key: const ValueKey('tour-marketing-new-newsletter'),
        onPressed: can ? w.onNew : null,
        icon: const Icon(Icons.add, size: 18),
        label: const Text('New newsletter'),
      ),
    );
    final template = w.onTemplate == null
        ? null
        : Padding(
            padding: const EdgeInsets.only(right: 8),
            child: OutlinedButton.icon(
              key: const ValueKey('newsletter-start-template'),
              onPressed: can ? w.onTemplate : null,
              icon: const Icon(Icons.auto_awesome_mosaic_outlined, size: 18),
              label: const Text('Start from template'),
            ),
          );
    final title = Text(
      'Mailings in ${w.viewName}',
      style: Theme.of(context).textTheme.titleLarge,
    );

    if (MediaQuery.sizeOf(context).width < 600) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [Expanded(child: title), newButton]),
          if (template != null) ...[
            const SizedBox(height: 8),
            Align(alignment: Alignment.centerLeft, child: template),
          ],
          if (w.search case final search?) ...[
            const SizedBox(height: 8),
            search,
          ],
        ],
      );
    }

    return Row(
      children: [
        Expanded(child: title),
        if (w.search case final search?) ...[
          Flexible(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 280),
              child: search,
            ),
          ),
          const SizedBox(width: 8),
        ],
        ?template,
        newButton,
      ],
    );
  }

  /// Status filters with the server's counts, then two facts, on one line
  /// when it fits.
  Widget _strip(BuildContext context) {
    final w = widget;
    final theme = Theme.of(context);
    final s = w.stats;
    final total = s.drafts + s.scheduled + s.sent + s.failed;

    Widget chip(String? key, String label, int count) {
      final picked = w.status == key;
      return FilterChip(
        key: Key('newsletters-tile-${key ?? 'all'}'),
        selected: picked,
        showCheckmark: false,
        onSelected: (_) => w.onStatus(key == null || picked ? null : key),
        label: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(label),
            const SizedBox(width: 6),
            Text('$count', style: const TextStyle(fontWeight: FontWeight.w700)),
          ],
        ),
      );
    }

    Widget fact(Key key, String label, String value) => Row(
      key: key,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('$label: ', style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        Text(value, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
      ],
    );

    final lastAt = DateTime.tryParse(s.lastSentAt ?? '')?.toLocal();
    return Wrap(
      key: const ValueKey('tour-marketing-status-tiles'),
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        chip(null, 'All', total),
        chip('draft', 'Draft', s.drafts),
        chip('scheduled', 'Scheduled', s.scheduled),
        chip('sent', 'Sent', s.sent),
        if (s.failed > 0 || w.status == 'failed') chip('failed', 'Failed', s.failed),
        const SizedBox(width: 8),
        // Accepted, not delivered: the provider reports what happens after.
        Tooltip(
          message: 'Addresses the mailing account accepted. Not a delivery count: the provider reports what happens after.',
          child: fact(const Key('newsletters-fact-reached'), 'Accepted', '${s.recipients}'),
        ),
        fact(
          const Key('newsletters-fact-last'),
          'Last sent',
          lastAt == null
              ? 'never'
              : (w.session.me?.display ?? DisplayFormats.fallback).formatDate(lastAt),
        ),
      ],
    );
  }

  Widget _list(BuildContext context) {
    final w = widget;
    if (w.rows.isEmpty) {
      return DashboardNote(
        key: const ValueKey('tour-newsletter-empty'),
        w.query.isNotEmpty
            ? 'No newsletter matches "${w.query}".'
            : w.status == null
            ? 'No newsletters in ${w.viewName} yet. New newsletter starts one.'
            : 'None of the newsletters in ${w.viewName} are ${_word(w.status!)}.',
      );
    }
    final formats = w.session.me?.display ?? DisplayFormats.fallback;
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('tour-newsletter-table'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: LayoutBuilder(
        builder: (context, box) {
          final wide = box.maxWidth >= 760;
          return Column(
            children: [
              for (var i = 0; i < w.rows.length; i++) ...[
                if (i > 0) const Divider(height: 1),
                _row(context, w.rows[i], formats, wide),
              ],
            ],
          );
        },
      ),
    );
  }

  Widget _row(BuildContext context, NewsletterRow n, DisplayFormats formats, bool wide) {
    final w = widget;
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);

    final names = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Tooltip(
          message: n.displayName,
          child: Text(
            n.displayName,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
        if (n.hasName)
          Text(
            n.subject,
            key: ValueKey('newsletter-subject-${n.id}'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: muted,
          ),
      ],
    );
    final audience = Text(
      n.audienceName == null ? 'No list chosen' : '${n.audienceName} · ${n.audienceWhere}',
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodySmall,
    );
    final detail = Text(
      n.detail(formats),
      key: ValueKey('newsletter-detail-${n.id}'),
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodySmall,
    );
    final action = w.onOpen == null
        ? const SizedBox(width: 72)
        : SizedBox(
            width: 72,
            child: TextButton(
              onPressed: () => w.onOpen!(n.id),
              child: Text(n.status == 'draft' || n.status == 'failed' ? 'Open' : 'View'),
            ),
          );
    final pill = StatusPill(n.statusLabel, tone: n.tone, dense: true);

    final body = wide
        ? Row(
            children: [
              Expanded(flex: 5, child: names),
              const SizedBox(width: 12),
              SizedBox(width: 104, child: Align(alignment: Alignment.centerLeft, child: pill)),
              Expanded(flex: 3, child: audience),
              const SizedBox(width: 12),
              Expanded(flex: 4, child: detail),
              action,
            ],
          )
        : Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    names,
                    const SizedBox(height: 4),
                    Wrap(spacing: 8, runSpacing: 4, children: [pill, audience]),
                    const SizedBox(height: 4),
                    detail,
                  ],
                ),
              ),
              action,
            ],
          );

    return InkWell(
      key: ValueKey('newsletter-${n.id}'),
      onTap: w.onOpen == null ? null : () => w.onOpen!(n.id),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: body,
      ),
    );
  }

  static String _word(String key) => switch (key) {
    'sent' => 'sent',
    'scheduled' => 'scheduled',
    'failed' => 'failed',
    _ => 'drafts',
  };
}

/// One line of the list, from a GET /newsletters row.
class NewsletterRow {
  const NewsletterRow({
    required this.id,
    required this.subject,
    required this.status,
    this.name,
    this.audienceName,
    this.audienceKind,
    this.scheduledFor,
    this.scheduledTimezone,
    this.sentAt,
    this.recipients,
    this.nextStep,
    this.deliveryState,
    this.deliveryLabel,
    this.failure,
  });

  final String id;
  final String subject;
  final String status;

  /// The internal campaign name; null when never typed.
  final String? name;
  final String? audienceName;
  final String? audienceKind;
  final String? scheduledFor;
  final String? scheduledTimezone;

  /// `last_send.sent_at`: when the provider took it.
  final String? sentAt;

  /// `last_send.recipients` on a sent one: addresses the provider accepted.
  final int? recipients;

  /// A draft's next unresolved step, as the server reads it.
  final String? nextStep;

  /// `delivery.state` / `delivery.label`: scheduled · queued · sending ·
  /// accepted · failed, from the stored send only. Never "delivered".
  final String? deliveryState;
  final String? deliveryLabel;
  final String? failure;

  bool get hasName => (name ?? '').trim().isNotEmpty;
  String get displayName => hasName ? name!.trim() : subject;

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

  /// The one thing worth knowing next, by status.
  String detail(DisplayFormats formats) {
    switch (status) {
      case 'scheduled':
        final label = deliveryState == 'queued' ? 'Queued · ' : 'Sends ';
        return '$label${when(formats)}';
      case 'sending':
        return deliveryLabel ?? 'Sending';
      case 'sent':
        final label = deliveryLabel ?? 'Accepted by provider';
        final n = recipients;
        final at = sentAt == null ? '' : ' · ${formatStamp(sentAt, '', formats: formats)}';
        return n == null ? '$label$at' : '$label · $n addresses$at';
      case 'failed':
        return failure == null || failure!.isEmpty ? 'Failed' : 'Failed: $failure';
      default:
        return nextStep == null ? 'Draft' : 'Next: $nextStep';
    }
  }

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
    final delivery = json['delivery'];
    final status = json['status'] as String? ?? 'draft';

    return NewsletterRow(
      id: json['id'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      name: json['name'] as String?,
      status: status,
      audienceName: audience is Map ? audience['name'] as String? : null,
      audienceKind: audience is Map ? audience['kind'] as String? : null,
      scheduledFor: json['scheduled_for'] as String?,
      scheduledTimezone: json['scheduled_timezone'] as String?,
      sentAt: send is Map ? send['sent_at'] as String? : null,
      // Only a sent one has reached anybody; a queued send carries 0, which
      // would read as "reached nobody".
      recipients: status == 'sent' && send is Map ? (send['recipients'] as num?)?.toInt() : null,
      nextStep: json['next_step'] as String?,
      deliveryState: delivery is Map ? delivery['state'] as String? : null,
      deliveryLabel: delivery is Map ? delivery['label'] as String? : null,
      failure: send is Map ? send['failure'] as String? : null,
    );
  }
}

class NewsletterStats {
  const NewsletterStats({
    this.drafts = 0,
    this.scheduled = 0,
    this.sent = 0,
    this.failed = 0,
    this.recipients = 0,
    this.lastSentAt,
  });

  final int drafts;
  final int scheduled;
  final int sent;
  final int failed;
  final int recipients;

  /// ISO 8601, as the server sent it.
  final String? lastSentAt;

  factory NewsletterStats.fromJson(Map<String, dynamic> json) => NewsletterStats(
    drafts: json['drafts'] as int? ?? 0,
    scheduled: json['scheduled'] as int? ?? 0,
    sent: json['sent'] as int? ?? 0,
    failed: json['failed'] as int? ?? 0,
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
