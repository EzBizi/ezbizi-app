import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';

/// Which side of Re-Scheduling an offer is for (Jose, 2026-11-09): the same
/// texts, preview and per-customer status, two directions.
enum OfferKind {
  /// Underbooking: an open spot offered to customers booked later.
  underbooked,

  /// Overbooking: customers on a day booked past what it takes, offered the
  /// next day with room.
  overbooked,
}

/// One area's offer on one day: who would be texted, what they would read,
/// and Send (`POST /scheduling/overbooking/offers/preview` and `…/send`,
/// `kind` naming the direction). Nothing goes out without Send, inside the
/// hours and limits Settings › Re-Scheduling sets.
class OfferPanel extends StatefulWidget {
  const OfferPanel({
    super.key,
    required this.api,
    required this.areaId,
    required this.date,
    this.kind = OfferKind.underbooked,
  });

  final ApiClient api;
  final String areaId;
  final String date;
  final OfferKind kind;

  @override
  State<OfferPanel> createState() => _OfferPanelState();
}

class _OfferPanelState extends State<OfferPanel> {
  bool _loading = true;
  bool _sending = false;
  String? _error;

  /// The server's 422 sentence (outside the send hours): Send stays off.
  String? _refused;
  List<Map<String, dynamic>> _open = const [];
  List<Map<String, dynamic>> _candidates = const [];
  int _maxSends = 0;
  String _message = '';
  String? _offerDate;
  Set<String> _ticked = {};
  int? _sent;
  List<Map<String, dynamic>> _failed = const [];

  bool get _over => widget.kind == OfferKind.overbooked;

  Map<String, Object> get _target => {
    'area_id': widget.areaId,
    'date': widget.date,
    if (_over) 'kind': 'overbooked',
  };

  @override
  void initState() {
    super.initState();
    _preview();
  }

  Future<void> _preview() async {
    final response = await widget.api.post(
      '/scheduling/overbooking/offers/preview',
      _target,
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final d = response.body['data'] as Map<String, dynamic>? ?? {};
      _open = (d['open'] as List? ?? const []).cast<Map<String, dynamic>>();
      _candidates = (d['candidates'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _maxSends = _int(d['max_sends']);
      _message = d['message'] as String? ?? '';
      _offerDate = d['offer_date'] as String?;
      _ticked = {for (final c in _candidates.take(_maxSends)) '${c['job_id']}'};
    });
  }

  Future<void> _send() async {
    setState(() => _sending = true);
    final response = await widget.api.post(
      '/scheduling/overbooking/offers/send',
      {
        ..._target,
        'job_ids': [
          for (final c in _candidates)
            if (_ticked.contains('${c['job_id']}')) '${c['job_id']}',
        ],
      },
    );
    if (!mounted) return;
    setState(() {
      _sending = false;
      if (response.statusCode == 422) {
        _refused = response.message;
        return;
      }
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final d = response.body['data'] as Map<String, dynamic>? ?? {};
      _sent = (d['sent'] as List? ?? const []).length;
      _failed = (d['failed'] as List? ?? const []).cast<Map<String, dynamic>>();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(12),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_error != null && _candidates.isEmpty) {
      return Text(_error!, style: TextStyle(color: theme.colorScheme.error));
    }
    final label = theme.textTheme.labelSmall?.copyWith(
      letterSpacing: 0.8,
      color: muted,
    );
    final count = _ticked.length;
    final done = _sent != null;

    return Column(
      key: const ValueKey('booked-offer-panel'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          _over && _offerDate != null
              ? 'MOVE TO ${offerDay(_offerDate!).toUpperCase()}'
              : 'OPEN WINDOWS',
          style: label,
        ),
        const SizedBox(height: 4),
        if (_open.isEmpty)
          Text(
            _over
                ? 'No day in the next weeks has a spot to move to.'
                : 'No window has a spot left.',
            style: TextStyle(color: muted),
          )
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final w in _open)
                _pill(
                  context,
                  '${offerClock('${w['start_time']}')}–'
                  '${offerClock('${w['end_time']}')}'
                  ' · ${_int(w['remaining'])} left',
                ),
            ],
          ),
        const SizedBox(height: 12),
        Text(
          _over
              ? 'OFFER TO (UP TO $_maxSends) · BOOKED THAT DAY'
              : 'OFFER TO (UP TO $_maxSends) · BOOKED LATER',
          style: label,
        ),
        if (_candidates.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Text(
              _over
                  ? 'Nobody booked that day can be texted.'
                  : 'Nobody in this area is booked later.',
              style: TextStyle(color: muted),
            ),
          ),
        for (final c in _candidates)
          Builder(
            builder: (context) {
              final jobId = '${c['job_id']}';
              final on = _ticked.contains(jobId);
              final full = !on && count >= _maxSends;
              final when = '${c['scheduled_for'] ?? ''}';
              return CheckboxListTile(
                key: ValueKey('booked-candidate-$jobId'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: on,
                onChanged: done || full
                    ? null
                    : (v) => setState(() {
                        if (v == true) {
                          _ticked.add(jobId);
                        } else {
                          _ticked.remove(jobId);
                        }
                      }),
                title: Text('${c['customer_name'] ?? 'Customer'}'),
                subtitle: Text(
                  [
                    if (when.length >= 10)
                      'booked ${offerDay(when.substring(0, 10))}',
                    c['phone'],
                  ].whereType<String>().join(' · '),
                ),
              );
            },
          ),
        const SizedBox(height: 8),
        Text('THEY\'LL GET', style: label),
        const SizedBox(height: 4),
        Container(
          key: const ValueKey('booked-offer-message'),
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Text(_message),
        ),
        const SizedBox(height: 4),
        Text(
          'The wording is in Scheduling › Settings.',
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
        const SizedBox(height: 12),
        if (_refused != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _refused!,
              key: const ValueKey('booked-offer-refused'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (done) ...[
          Text(
            _sent == 1 ? 'Sent 1 text.' : 'Sent $_sent texts.',
            key: const ValueKey('booked-offer-sent'),
            style: theme.textTheme.titleSmall,
          ),
          for (final f in _failed)
            Text(
              '${_nameOf('${f['job_id']}')}: ${f['reason']}',
              style: TextStyle(color: theme.colorScheme.error),
            ),
          const SizedBox(height: 4),
          Text(
            'Replies land in Leads as "Reschedule".',
            key: const ValueKey('booked-offer-replies'),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        ] else
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              key: const ValueKey('booked-offer-send'),
              icon: const Icon(Icons.send, size: 18),
              label: Text('Send to $count'),
              onPressed: count == 0 || _sending || _refused != null
                  ? null
                  : _send,
            ),
          ),
      ],
    );
  }

  String _nameOf(String jobId) =>
      _candidates
              .where((c) => '${c['job_id']}' == jobId)
              .firstOrNull?['customer_name']
          as String? ??
      'A customer';

  Widget _pill(BuildContext context, String text) {
    final c = ToneColours.of(context, Tone.ok);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelMedium?.copyWith(
          color: c.ink,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  static int _int(Object? v) => (v as num?)?.toInt() ?? 0;
}

/// "2026-11-02" → "Mon Nov 2".
String offerDay(String ymd) {
  final d = DateTime.parse(ymd);
  return '${shortWeekdays[d.weekday - 1]} ${shortMonths[d.month - 1]} ${d.day}';
}

/// "14:00:00" → "2pm", "10:30" → "10:30am".
String offerClock(String t) {
  if (t.length < 5) return t;
  final h = int.tryParse(t.substring(0, 2)) ?? 0;
  final m = t.substring(3, 5);
  final hh = h % 12 == 0 ? 12 : h % 12;
  return '$hh${m == '00' ? '' : ':$m'}${h < 12 ? 'am' : 'pm'}';
}
