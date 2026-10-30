import 'package:flutter/material.dart';

import '../../core/deep_links.dart';
import '../../core/job_money.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/status_pill.dart';

// What Project Management's tabs share: a job's timeline, its
// technician, and a rule step's outcome and link. The table this file was
// named for, Pending Jobs, became the Jobs tab (design 141,
// project_management_jobs.dart).

/// An outcome as its chip says it: a Logs row, or a rule's step in a
/// timeline.
({String label, Tone tone}) pmLogOutcome(Object? outcome) => switch (outcome) {
  // Not "Done": that is a job status, and a stage's name (design 144).
  'succeeded' => (label: 'Succeeded', tone: Tone.ok),
  'manual' => (label: 'By hand', tone: Tone.info),
  'failed' => (label: 'Failed', tone: Tone.bad),
  'skipped' => (label: 'Skipped', tone: Tone.neutral),
  _ => (label: '${outcome ?? '—'}', tone: Tone.neutral),
};

/// Opens a rule in Automations, the way a notification about a rule does:
/// the link for Automations to take, then the module. Null when there is no
/// rule, no shell to switch modules, or Automations is not this person's.
VoidCallback? pmOpenRule(BuildContext context, Me me, Object? rule) {
  final id = rule is Map ? rule['id'] : null;
  final open = OpenDestination.of(context);
  if (id is! String || id.isEmpty || open == null) return null;
  if (!me.reaches('automations')) return null;
  return () {
    DeepLinks.open(DeepLink(module: 'automations', type: 'automation', id: id));
    open('automations');
  };
}

/// A rule's name as a link that opens it; plain text when it cannot open.
class PmRuleLink extends StatelessWidget {
  const PmRuleLink({
    super.key,
    required this.label,
    required this.rule,
    required this.me,
    this.style,
  });

  final String label;
  final Object? rule;
  final Me me;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final go = pmOpenRule(context, me, rule);
    final text = Text(
      label,
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      style: go == null
          ? style
          : (style ?? const TextStyle()).copyWith(
              color: Theme.of(context).colorScheme.primary,
              fontWeight: FontWeight.w700,
            ),
    );
    if (go == null) return text;
    return Semantics(
      link: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(4),
        onTap: go,
        child: text,
      ),
    );
  }
}

/// The job's technician, or null when it has none.
String? pmTechnician(Map<String, dynamic> row) {
  final t = row['technician'];
  return t is String && t.trim().isNotEmpty ? t.trim() : null;
}

String _name(Object? ref) =>
    ref is Map && ref['name'] is String ? ref['name'] as String : '—';

String _waited(Object? waiting) {
  final days = waiting is Map ? waiting['days_waiting'] : null;
  if (days is! int) return '—';
  return '$days ${days == 1 ? 'day' : 'days'}';
}

/// One job's header and its timeline, a dot per event. Jobs and Logs both
/// open it.
class PmTimeline extends StatelessWidget {
  const PmTimeline({
    super.key,
    required this.row,
    required this.now,
    required this.me,
    required this.statuses,
    required this.onClose,
    required this.onOpenJob,
    this.back = false,
    this.above,
  });

  final Map<String, dynamic> row;
  final DateTime now;
  final Me me;
  final List<JobStatus> statuses;
  final VoidCallback onClose;
  final VoidCallback onOpenJob;

  /// Drawn full screen on a phone: a back arrow before the name, where the
  /// side pane has a close cross after it.
  final bool back;

  /// Drawn between the header and the events: the Jobs tab's editable
  /// fields.
  final Widget? above;

  /// A status value as the organization names it.
  String _status(Object? value) {
    if (value == null) return '—';
    for (final s in statuses) {
      if (s.value == '$value') return s.label;
    }
    return jobStatusLabel(null, '$value');
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final waiting = row['waiting'];
    final deadline = parseCalendarDay(
      waiting is Map ? waiting['deadline'] as String? : null,
    );
    final events = [
      for (final e in (row['timeline'] as List?) ?? const [])
        if (e is Map) e.cast<String, dynamic>(),
    ];

    final sub = [
      '${row['number'] ?? ''} ${row['title'] ?? ''}'.trim(),
      _name(row['franchise']),
      if (_waited(waiting) != '—') 'waiting ${_waited(waiting)}',
      if (deadline != null) 'cancels on ${me.display.formatDate(deadline)}',
    ].join(' · ');

    return ColoredBox(
      color: scheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (back)
                  Padding(
                    padding: const EdgeInsets.only(right: 4),
                    child: IconButton(
                      key: const ValueKey('pm-timeline-close'),
                      tooltip: 'Back',
                      onPressed: onClose,
                      icon: const Icon(Icons.arrow_back),
                    ),
                  ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _name(row['customer']),
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        sub,
                        key: const ValueKey('pm-timeline-sub'),
                        style: muted,
                      ),
                    ],
                  ),
                ),
                if (!back)
                  IconButton(
                    key: const ValueKey('pm-timeline-close'),
                    tooltip: 'Close',
                    onPressed: onClose,
                    icon: const Icon(Icons.close),
                  ),
              ],
            ),
          ),
          if (above != null) ...[
            Divider(height: 1, color: scheme.outlineVariant),
            above!,
          ],
          Divider(height: 1, color: scheme.outlineVariant),
          Expanded(
            child: events.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text('Nothing has happened yet.', style: muted),
                  )
                : ListView.builder(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                    itemCount: events.length,
                    itemBuilder: (context, i) => _event(
                      context,
                      events[i],
                      last: i == events.length - 1,
                    ),
                  ),
          ),
          Divider(height: 1, color: scheme.outlineVariant),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton.icon(
                key: const ValueKey('pm-open-job'),
                onPressed: onOpenJob,
                icon: const Icon(Icons.open_in_new, size: 18),
                label: const Text('Open the job'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _event(
    BuildContext context,
    Map<String, dynamic> e, {
    required bool last,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    final what = e['what'] is String && (e['what'] as String).isNotEmpty
        ? e['what'] as String
        : null;
    final (title, tone) = switch (e['kind']) {
      // The status change it was, since 2026-10-22: from and to, and `what`.
      'entered' when what != null => (what, Tone.info),
      'entered' when e['to'] != null => (
        'Status ${_status(e['from'])} → ${_status(e['to'])}',
        Tone.info,
      ),
      'entered' => ('Entered “${_status(e['status'])}”', Tone.info),
      // One step a rule took: its outcome chip, the rule, and why not.
      'action' => (what ?? 'A rule ran', pmLogOutcome(e['outcome']).tone),
      'payment' => (
        e['marks'] == 'full' ? 'Payment · paid in full' : 'Payment · partial',
        Tone.ok,
      ),
      'status' => (
        'Status ${_status(e['from'])} → ${_status(e['to'])}',
        Tone.neutral,
      ),
      'tags' => ('Tags ${_tags(e['tags'])}', Tone.caution),
      _ => ('${e['kind'] ?? 'Event'}', Tone.neutral),
    };

    final at = e['at'] is String ? DateTime.tryParse(e['at'])?.toLocal() : null;
    final money = e['amount_minor'] ?? e['total_minor'];
    final by = e['by'] is String && (e['by'] as String).isNotEmpty
        ? e['by'] as String
        : null;
    // "EzBizi · <rule>": the rule opens from here too (UI queue 139).
    final rule = e['rule'] is Map ? e['rule'] as Map : null;
    final detail = [
      if (at != null) me.display.formatDate(at),
      if (by != null && rule == null) by,
      if (money is int) formatMoney(money),
    ].join(' · ');

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 18,
            child: Column(
              children: [
                const SizedBox(height: 4),
                Container(
                  width: 10,
                  height: 10,
                  decoration: BoxDecoration(
                    color: ToneColours.of(context, tone).ink,
                    shape: BoxShape.circle,
                  ),
                ),
                if (!last)
                  Expanded(
                    child: Container(width: 2, color: scheme.outlineVariant),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (e['kind'] == 'action')
                    Wrap(
                      spacing: 6,
                      runSpacing: 2,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        StatusPill(
                          pmLogOutcome(e['outcome']).label,
                          tone: pmLogOutcome(e['outcome']).tone,
                        ),
                      ],
                    )
                  else
                    Text(
                      title,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  if (e['kind'] == 'action' &&
                      e['reason'] is String &&
                      (e['reason'] as String).isNotEmpty &&
                      e['outcome'] != 'succeeded')
                    Text(
                      e['reason'] as String,
                      style: e['outcome'] == 'failed'
                          ? theme.textTheme.bodySmall?.copyWith(
                              color: scheme.error,
                            )
                          : muted,
                    ),
                  if (detail.isNotEmpty) Text(detail, style: muted),
                  if (by != null && rule != null)
                    PmRuleLink(
                      key: ValueKey('pm-timeline-rule-${rule['id']}'),
                      label: by,
                      rule: rule,
                      me: me,
                      style: muted,
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  static String _tags(Object? tags) {
    if (tags is! Map) return '';
    final added = [
      for (final t in (tags['added'] as List?) ?? const []) '+ $t',
    ];
    final removed = [
      for (final t in (tags['removed'] as List?) ?? const []) '− $t',
    ];
    return [...added, ...removed].join(', ');
  }
}
