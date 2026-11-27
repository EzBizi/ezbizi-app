import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_search.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'work_hours_report_panel.dart';

/// One report EzBizi sends, opened from the Reports list (Jose, 2026-11-25:
/// "we are sending out a lot of reports on whatsapp, and yet they can't be
/// seen on the app"): what it covers, who gets it, when, on which channel and
/// by which rule, then every send - when, to whom, whether it arrived or was
/// read, and what it said - read from the messages themselves.
class SentReportPanel extends StatefulWidget {
  const SentReportPanel({
    super.key,
    required this.api,
    required this.report,
    required this.formats,
    required this.inPlace,
    required this.onClose,
  });

  final ApiClient api;

  /// A row of `GET /reports/sent`.
  final Map<String, dynamic> report;
  final DisplayFormats formats;
  final bool inPlace;
  final VoidCallback onClose;

  @override
  State<SentReportPanel> createState() => _SentReportPanelState();
}

/// What a channel is called on screen.
String channelLabel(String channel) => switch (channel) {
  'whatsapp' => 'WhatsApp',
  'group' => 'WhatsApp group',
  'email' => 'Email',
  'sms' => 'Text',
  _ => channel,
};

class _SentReportPanelState extends State<SentReportPanel> {
  List<Map<String, dynamic>>? _sends;
  int _total = 0;
  String? _error;
  String? _open;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/reports/sent/history', {
      'report': '${widget.report['key']}',
    });
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        _sends = [
          for (final row in (response.body['data'] as List?) ?? const [])
            if (row is Map) Map<String, dynamic>.from(row),
        ];
        _total = (response.body['total'] as num?)?.toInt() ?? _sends!.length;
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final report = widget.report;
    final rule = report['rule'] is Map
        ? Map<String, dynamic>.from(report['rule'] as Map)
        : null;
    final to = [
      for (final name in (report['to'] as List?) ?? const []) '$name',
    ];
    final channels = [
      for (final c in (report['channels'] as List?) ?? const [])
        channelLabel('$c'),
    ];

    return SidePanel(
      title: '${report['name'] ?? 'Report'}',
      subtitle: channels.isEmpty ? null : channels.join(' · '),
      docked: true,
      onClose: widget.onClose,
      onBack: widget.inPlace ? widget.onClose : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if ('${report['about'] ?? ''}'.isNotEmpty) ...[
            Text(
              '${report['about']}',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 16),
          ],
          reportPanelSection(
            context,
            'Covers',
            Text('${report['covers'] ?? '—'}'),
          ),
          if (to.isNotEmpty)
            reportPanelSection(context, 'Goes to', Text(to.join(', '))),
          reportPanelSection(
            context,
            'When',
            Text(
              '${report['trigger'] ?? (rule == null ? 'Sent automatically' : senderWhen(rule, widget.formats))}',
            ),
          ),
          if (rule != null)
            reportPanelSection(
              context,
              'Sent by',
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  reportSenders(context, {
                    'sent_by': [rule],
                  }, widget.formats),
                  TextButton.icon(
                    key: const ValueKey('sent-report-open-rule'),
                    style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    icon: const Icon(Icons.open_in_new, size: 18),
                    label: const Text('Open the rule'),
                    onPressed: () {
                      DeepLinks.open(
                        DeepLink(
                          module: 'automations',
                          type: 'automation',
                          id: '${rule['id']}',
                        ),
                      );
                      DeepLinks.openModule('automations');
                    },
                  ),
                ],
              ),
            ),
          reportPanelSection(
            context,
            _sends == null ? 'Sent' : 'Sent · $_total',
            _history(context),
          ),
        ],
      ),
    );
  }

  Widget _history(BuildContext context) {
    if (_error != null) return ErrorNote(_error!);
    final sends = _sends;
    if (sends == null) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (sends.isEmpty) {
      return const Text(
        'Nothing sent yet that EzBizi kept. Sends are kept from 2026-11-25; '
        "a rule's report texts from before then are here too.",
      );
    }

    final query = _query.trim().toLowerCase();
    final shown = query.isEmpty
        ? sends
        : [
            for (final send in sends)
              if ([
                send['to'],
                send['subject'],
                send['body'],
                send['status'],
              ].any((v) => '${v ?? ''}'.toLowerCase().contains(query)))
                send,
          ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          key: const Key('sent-report-search'),
          hint: 'Find a send',
          onChanged: (v) => setState(() => _query = v),
        ),
        const SizedBox(height: 8),
        if (_total > sends.length)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              'The newest ${sends.length} of $_total.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        if (shown.isEmpty) Text('No send matches "${_query.trim()}".'),
        for (final send in shown) _send(context, send),
      ],
    );
  }

  Widget _send(BuildContext context, Map<String, dynamic> send) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final id = '${send['id']}';
    final open = _open == id;
    final at = DateTime.tryParse('${send['at']}')?.toLocal();
    final f = widget.formats;
    final when = at == null
        ? ''
        : '${f.formatDate(at)} ${f.formatTime(at.hour, at.minute)}';
    final body = '${send['body'] ?? ''}'.trim();

    return Container(
      key: ValueKey('sent-report-send-$id'),
      margin: const EdgeInsets.only(bottom: 6),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => setState(() => _open = open ? null : id),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${send['to'] ?? ''}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        Text(
                          [
                            when,
                            channelLabel('${send['channel']}'),
                          ].where((s) => s.isNotEmpty).join(' · '),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  _status(send),
                  Icon(open ? Icons.expand_less : Icons.expand_more),
                ],
              ),
              if (open) ...[
                const SizedBox(height: 8),
                if ('${send['status_reason'] ?? ''}'.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Text(
                      '${send['status_reason']}',
                      style: TextStyle(
                        color: ToneColours.of(context, Tone.bad).ink,
                      ),
                    ),
                  ),
                if ('${send['subject'] ?? ''}'.isNotEmpty)
                  Text(
                    '${send['subject']}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                SelectableText(
                  body.isEmpty
                      ? 'What this one said was not kept: it went before 2026-11-25.'
                      : body,
                  key: ValueKey('sent-report-body-$id'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// Whether it arrived: Meta's word for a WhatsApp message; an email is sent.
  Widget _status(Map<String, dynamic> send) {
    final (label, tone) = switch ('${send['status']}') {
      'read' => ('Read', Tone.ok),
      'delivered' => ('Delivered', Tone.info),
      'sent' => ('Sent', Tone.neutral),
      'failed' => ('Failed', Tone.bad),
      'queued' => ('Not confirmed', Tone.caution),
      final other => (other, Tone.neutral),
    };

    return StatusPill(label, tone: tone, dense: true);
  }
}
