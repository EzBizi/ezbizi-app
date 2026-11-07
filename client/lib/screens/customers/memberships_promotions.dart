import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Customers › Memberships › Promotions (Jose, 2026-11-06).
///
/// Pick the dates of Done jobs, see who would be texted, edit the text and
/// send. One text per customer phone, never to a number that replied STOP,
/// always with the opt-out line. Answers land in Leads › Promotions. Sending
/// takes `memberships.edit`; without it the tab previews only.
class MembershipsPromotions extends StatefulWidget {
  const MembershipsPromotions({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsPromotions> createState() => _MembershipsPromotionsState();
}

class _MembershipsPromotionsState extends State<MembershipsPromotions> {
  DateTimeRange? _range;
  Map<String, dynamic>? _preview;
  List<dynamic> _sent = const [];
  String? _error;
  bool _busy = false;

  /// Second press of Send confirms, in place (no dialogs in this app).
  bool _confirming = false;
  final _body = TextEditingController();

  bool get _canSend => widget.session.me?.can('memberships.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _loadSent();
  }

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Map<String, String> get _dates => {
    'from': _day(_range!.start),
    'to': _day(_range!.end),
  };

  Future<void> _loadSent() async {
    final response = await widget.api.get('/memberships/promotions');
    if (!mounted || !response.ok) return;
    setState(() => _sent = (response.data as List?) ?? const []);
  }

  Future<void> _pickDates() async {
    final now = DateTime.now();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 5),
      lastDate: now,
      initialDateRange: _range,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _range = picked;
      _confirming = false;
    });
    await _loadPreview();
  }

  Future<void> _loadPreview() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.get(
      '/memberships/promotions/preview',
      _dates,
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _preview = Map<String, dynamic>.from(response.data as Map);
      if (_body.text.trim().isEmpty) {
        _body.text = '${_preview!['message'] ?? ''}';
      }
    });
  }

  Future<void> _send() async {
    if (!_confirming) {
      setState(() => _confirming = true);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/memberships/promotions', {
      ..._dates,
      'body': _body.text.trim(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _confirming = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _preview = null;
      _range = null;
    });
    await _loadSent();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final preview = _preview;
    final count = (preview?['recipients'] as num?)?.toInt() ?? 0;

    return ListView(
      key: const ValueKey('memberships-promotions'),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Text the customers of Done jobs',
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          'One text per phone. Numbers that replied STOP are skipped and every '
          'text ends with "Reply STOP to opt out." Answers arrive in '
          'Leads › Promotions.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton.icon(
            key: const ValueKey('promotions-dates'),
            onPressed: _busy ? null : _pickDates,
            icon: const Icon(Icons.date_range),
            label: Text(
              _range == null
                  ? 'Pick the job dates'
                  : 'Jobs ${_day(_range!.start)} to ${_day(_range!.end)}',
            ),
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
        if (preview != null) ...[
          const SizedBox(height: 16),
          Text(
            '$count customers will be texted'
            '${(preview['opted_out'] ?? 0) == 0 ? '' : ' (${preview['opted_out']} replied STOP and are skipped)'}.',
            key: const ValueKey('promotions-count'),
            style: theme.textTheme.bodyLarge,
          ),
          for (final r in (preview['sample'] as List? ?? const []).take(5))
            Text(
              '${r['name'] ?? 'No name'} · ${r['phone']} · job ${r['job_date'] ?? ''}',
              style: theme.textTheme.bodySmall,
            ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('promotions-body'),
            controller: _body,
            minLines: 3,
            maxLines: 6,
            maxLength: 600,
            onChanged: (_) => setState(() => _confirming = false),
            decoration: const InputDecoration(
              labelText: 'Message',
              helperText:
                  '{first_name} and {company} are filled in for each customer.',
            ),
          ),
          const SizedBox(height: 8),
          if (_canSend)
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.icon(
                key: const ValueKey('promotions-send'),
                onPressed: _busy || count == 0 || _body.text.trim().isEmpty
                    ? null
                    : _send,
                icon: const Icon(Icons.send),
                label: Text(
                  _confirming ? 'Press again to text $count customers' : 'Send',
                ),
              ),
            )
          else
            Text(
              'Sending needs permission to edit memberships.',
              style: theme.textTheme.bodySmall,
            ),
        ],
        if (_sent.isNotEmpty) ...[
          const SizedBox(height: 24),
          Text('Sent', style: theme.textTheme.titleSmall),
          for (final p in _sent)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(
                'Jobs ${p['jobs_from']} to ${p['jobs_to']}: '
                '${p['sent']} of ${p['recipients']} sent'
                '${(p['failed'] ?? 0) == 0 ? '' : ', ${p['failed']} failed'}'
                ', ${p['replies']} replies'
                '${p['finished_at'] == null ? ' (sending)' : ''}',
              ),
              subtitle: Text(
                '${p['body']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
        ],
      ],
    );
  }
}
