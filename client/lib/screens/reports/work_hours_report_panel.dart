import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/schedule_words.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../hr/work_hours_reports_tab.dart';

/// Who it goes to: people by name, addresses as typed.
String workHoursReportRecipients(
  Map<String, dynamic> report, {
  required List<Map<String, dynamic>> people,
}) {
  final to = [
    for (final r in (report['recipients'] as List? ?? const []))
      '$r'.startsWith('user:')
          ? people.firstWhere(
                      (p) => '${p['id']}' == '$r'.substring(5),
                      orElse: () => const {},
                    )['name']
                    as String? ??
                'Someone'
          : '$r',
  ];

  return to.isEmpty ? 'Nobody to send to yet' : to.join(', ');
}

/// One Work Hours report, opened from the Reports library (queue 33, Jose's
/// 1 and 4): who is in it, where it goes, the rules that send it and its week
/// of hours. Docked beside the list on a desk, in place of it on a phone.
///
/// It is still Work Hours' report. "Edit who is in it" opens the editor Work
/// Hours uses, so a change here is the same change everywhere the report is
/// sent.
class WorkHoursReportPanel extends StatefulWidget {
  const WorkHoursReportPanel({
    super.key,
    required this.api,
    required this.session,
    required this.report,
    required this.roles,
    required this.people,
    required this.inPlace,
    required this.onClose,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> report;
  final List<Map<String, dynamic>> roles;
  final List<Map<String, dynamic>> people;

  /// A phone: drawn in place of the list, with a way back.
  final bool inPlace;

  final VoidCallback onClose;

  /// Edited: the list re-reads, and hands this panel the new copy.
  final VoidCallback onChanged;

  @override
  State<WorkHoursReportPanel> createState() => _WorkHoursReportPanelState();
}

class _WorkHoursReportPanelState extends State<WorkHoursReportPanel> {
  String _week = 'last';
  Map<String, dynamic>? _preview;
  String? _error;
  bool _sending = false;
  String? _sent;

  String get _id => '${widget.report['id']}';
  bool get _canManage => widget.session.me?.can('work_hours.manage') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(WorkHoursReportPanel old) {
    super.didUpdateWidget(old);
    // Edited elsewhere and re-read: the week may count other people now.
    if (!identical(old.report, widget.report)) _load();
  }

  Future<void> _load() async {
    setState(() {
      _preview = null;
      _error = null;
    });
    final response = await widget.api.get(
      '/hr/time-clock/reports/$_id/preview',
      {'week': _week},
    );
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _preview = Map<String, dynamic>.from(response.body['data'] as Map);
    });
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _sent = null;
      _error = null;
    });
    final response = await widget.api.post(
      '/hr/time-clock/reports/$_id/send?week=$_week',
      const {},
    );
    if (!mounted) return;
    setState(() {
      _sending = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final to = (response.body['data']?['to'] as List? ?? const []).length;
      _sent = to == 1 ? 'Sent to 1 address.' : 'Sent to $to addresses.';
    });
  }

  Future<void> _edit() async {
    final saved = await editWorkHoursReport(
      context,
      api: widget.api,
      report: widget.report,
      roles: widget.roles,
      people: widget.people,
    );
    if (saved && mounted) widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: '${widget.report['name']}',
      subtitle: 'Work Hours · edited in Work Hours › Reports',
      docked: true,
      onClose: widget.onClose,
      onBack: widget.inPlace ? widget.onClose : null,
      actions: [
        if (_canManage) ...[
          OutlinedButton(
            key: const ValueKey('hours-report-edit'),
            onPressed: _edit,
            child: const Text('Edit who is in it'),
          ),
          FilledButton.icon(
            key: const ValueKey('hours-report-send'),
            onPressed: _sending || _preview == null ? null : _send,
            icon: const Icon(Icons.send, size: 16),
            label: const Text('Send now'),
          ),
        ],
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          reportPanelSection(context, 'Who is in it', _who(context)),
          reportPanelSection(
            context,
            'Goes to',
            Text(
              workHoursReportRecipients(widget.report, people: widget.people),
            ),
          ),
          reportPanelSection(
            context,
            'Sent by',
            reportSenders(context, widget.report, widget.session.me!.display),
          ),
          _hours(context),
          if (_sent != null) ...[
            const SizedBox(height: 12),
            Text(
              _sent!,
              style: TextStyle(color: ToneColours.of(context, Tone.ok).ink),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  /// Roles, then who is added and who is left out, as the editor has them.
  Widget _who(BuildContext context) {
    String name(List<Map<String, dynamic>> list, Object? id) =>
        list.firstWhere(
              (e) => '${e['id']}' == '$id',
              orElse: () => const {},
            )['name']
            as String? ??
        '';
    final report = widget.report;
    final roles = [
      for (final id in (report['role_ids'] as List? ?? const []))
        name(widget.roles, id),
    ];
    final added = [
      for (final id in (report['include_user_ids'] as List? ?? const []))
        name(widget.people, id),
    ];
    final left = [
      for (final id in (report['exclude_user_ids'] as List? ?? const []))
        name(widget.people, id),
    ];

    if (roles.isEmpty && added.isEmpty) {
      return const Text('Everybody you can see on Work Hours');
    }

    Widget chip(String label, Tone tone, [IconData? icon]) {
      final colours = ToneColours.of(context, tone);
      return Chip(
        visualDensity: VisualDensity.compact,
        backgroundColor: colours.fill,
        side: BorderSide.none,
        avatar: icon == null ? null : Icon(icon, size: 14, color: colours.ink),
        label: Text(label, style: TextStyle(color: colours.ink)),
      );
    }

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final r in roles)
          if (r.isNotEmpty) chip(r, Tone.info),
        for (final p in added)
          if (p.isNotEmpty) chip(p, Tone.ok, Icons.add),
        for (final p in left)
          if (p.isNotEmpty) chip(p, Tone.neutral, Icons.remove),
      ],
    );
  }

  Widget _hours(BuildContext context) {
    final theme = Theme.of(context);
    final data = _preview;
    final rows = (data?['rows'] as List? ?? const []);

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.all(10),
            child: Align(
              alignment: Alignment.centerLeft,
              child: SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'last', label: Text('Last week')),
                  ButtonSegment(
                    value: 'current',
                    label: Text('This week so far'),
                  ),
                ],
                selected: {_week},
                onSelectionChanged: (s) {
                  setState(() => _week = s.first);
                  _load();
                },
              ),
            ),
          ),
          if (data == null && _error == null)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (data != null && rows.isEmpty)
            const Padding(
              padding: EdgeInsets.fromLTRB(12, 4, 12, 12),
              child: Text('Nobody in this report clocked any time this week.'),
            ),
          for (final row in rows.whereType<Map>())
            _hourLine(context, '${row['name']}', '${row['hours']}'),
          if (data != null && rows.isNotEmpty)
            _hourLine(
              context,
              rows.length == 1
                  ? 'Total (1 person)'
                  : 'Total (${rows.length} people)',
              '${data['total']}',
              total: true,
            ),
        ],
      ),
    );
  }

  Widget _hourLine(
    BuildContext context,
    String name,
    String hours, {
    bool total = false,
  }) {
    final weight = total ? FontWeight.w800 : FontWeight.w400;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(name, style: TextStyle(fontWeight: weight)),
          ),
          Text(
            hours,
            style: TextStyle(fontWeight: total ? weight : FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

/// On and able to send: switched on and not switched off by its failures.
bool senderIsOn(Map<dynamic, dynamic> rule) =>
    rule['is_active'] == true && rule['disabled_at'] == null;

/// When a rule sends: its schedule in words, and what stops it. "Sundays 8:00
/// PM (New York)", "Off", "Switched itself off after failing".
String senderWhen(Map<dynamic, dynamic> rule, DisplayFormats formats) {
  if (rule['disabled_at'] != null) {
    return 'Switched itself off after failing to send';
  }
  final when =
      scheduleWords(rule['schedule'], formats) ?? 'When its trigger happens';

  return rule['is_active'] == true ? when : 'Off · $when';
}

/// A labelled block of a report panel.
Widget reportPanelSection(BuildContext context, String label, Widget child) {
  final theme = Theme.of(context);

  return Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: theme.textTheme.labelSmall?.copyWith(
            fontWeight: FontWeight.w800,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 6),
        child,
      ],
    ),
  );
}

/// The rules whose current version sends this report. Their names are
/// Automations' to show: without `automations.view` the server says only
/// whether one does.
Widget reportSenders(
  BuildContext context,
  Map<String, dynamic> report,
  DisplayFormats formats,
) {
  final theme = Theme.of(context);
  final muted = theme.colorScheme.onSurfaceVariant;
  final rules = report['sent_by'];

  if (rules is! List) {
    return Text(
      report['sent_automatically'] == true
          ? 'Sent automatically'
          : 'Not sent by any automation',
    );
  }
  if (rules.isEmpty) return const Text('Not sent by any automation');

  return Column(
    children: [
      for (final rule in rules.whereType<Map>())
        Container(
          margin: const EdgeInsets.only(bottom: 6),
          padding: const EdgeInsets.fromLTRB(10, 8, 12, 8),
          decoration: BoxDecoration(
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Icon(
                Icons.schedule,
                size: 20,
                color: ToneColours.of(
                  context,
                  senderIsOn(rule) ? Tone.info : Tone.neutral,
                ).ink,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${rule['name']}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    Text(
                      [
                        senderWhen(rule, formats),
                        if (senderIsOn(rule))
                          if (DateTime.tryParse('${rule['next_run_at']}')
                              case final next?)
                            'next ${formats.formatDate(next.toLocal())}',
                      ].join(' · '),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: rule['disabled_at'] != null
                            ? ToneColours.of(context, Tone.bad).ink
                            : muted,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
    ],
  );
}
