import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/tab_bar_like.dart';
import 'financial_cells.dart';

String _money(int cents) =>
    formatCell(const {'type': 'currency', 'options': {}}, cents / 100);

String _ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

const _kinds = {
  'refund': 'Refund',
  'dispute': 'Dispute',
  'check_return': 'Returned check',
  'repair_move': 'Split change',
};

/// What GET /financials/adjustments holds for one period, read-only, inside
/// an area of the Financials home. [kind] narrows it on the server
/// (repair_move for Repair Teams); [exclude] drops kinds another area shows.
class FinancialAdjustmentsList extends StatefulWidget {
  const FinancialAdjustmentsList({
    super.key,
    required this.api,
    this.kind,
    this.exclude = const {},
    this.now,
  });

  final ApiClient api;
  final String? kind;
  final Set<String> exclude;

  /// Today, for tests.
  final DateTime? now;

  @override
  State<FinancialAdjustmentsList> createState() =>
      _FinancialAdjustmentsListState();
}

class _FinancialAdjustmentsListState extends State<FinancialAdjustmentsList> {
  static const _periods = ['This month', 'Last month'];
  int _period = 0;
  List<Map>? _rows;
  String? _error;

  (String, String) get _range {
    final now = widget.now ?? DateTime.now();
    final first = DateTime(now.year, now.month - _period);
    final last = DateTime(first.year, first.month + 1, 0);
    return (_ymd(first), _ymd(last));
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _rows = null;
      _error = null;
    });
    final (from, to) = _range;
    final r = await widget.api.get(
      '/financials/adjustments?from=$from&to=$to'
      '${widget.kind == null ? '' : '&kind=${widget.kind}'}',
    );
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        _rows = [
          for (final a in (r.body['data'] as List).cast<Map>())
            if (!widget.exclude.contains(a['kind'])) a,
        ];
      } else {
        _error = r.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final (from, to) = _range;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TabBarLike(
                key: const ValueKey('financials-adjustments-period'),
                level: TabLevel.tertiary,
                labels: [for (final p in _periods) (p, Icons.date_range)],
                selected: _period,
                onSelected: (i) {
                  setState(() => _period = i);
                  _load();
                },
              ),
              Text('$from to $to', style: TextStyle(color: muted)),
            ],
          ),
        ),
        Expanded(
          child: switch ((_rows, _error)) {
            (_, final String error) => Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(error, textAlign: TextAlign.center),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: _load,
                    child: const Text('Try again'),
                  ),
                ],
              ),
            ),
            (null, _) => const Center(child: CircularProgressIndicator()),
            (final List<Map> rows, _) when rows.isEmpty => Center(
              child: Text(
                'Nothing recorded in this period.',
                style: TextStyle(color: muted),
              ),
            ),
            (final List<Map> rows, _) => ListView.separated(
              itemCount: rows.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (_, i) => _row(rows[i]),
            ),
          },
        ),
      ],
    );
  }

  Widget _row(Map a) {
    final who = a['kind'] == 'repair_move'
        ? [
            if (a['from_technician'] != null) '${a['from_technician']}',
            if (a['technician'] != null) '${a['technician']}',
          ].join(' → ')
        : '${a['technician'] ?? ''}';

    return ListTile(
      title: Text(
        [
          _kinds[a['kind']] ?? '${a['kind']}',
          if (a['date'] != null) '${a['date']}',
          if (a['place'] != null) '${a['place']}',
        ].join(' · '),
      ),
      subtitle: Text(
        [
          if (who.isNotEmpty) who,
          if (a['team'] != null) 'Team ${a['team']}',
          if (a['job_number'] != null) 'Job ${a['job_number']}',
          if (a['reason'] != null) '${a['reason']}',
        ].join(' · '),
      ),
      trailing: a['cents'] == null
          ? null
          : Text(_money((a['cents'] as num).toInt())),
    );
  }
}
