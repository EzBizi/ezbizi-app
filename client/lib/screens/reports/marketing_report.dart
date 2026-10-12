import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

/// Ad cost against jobs, sales and profit, per branch and Workiz job source
/// (Jose, 2026-10-12). Profit is an estimate: the source's done-job sales
/// times the branch's P&L margin before marketing, as the note under the
/// table says.
class MarketingReport extends StatefulWidget {
  const MarketingReport({super.key, required this.api, this.now});

  final ApiClient api;
  final DateTime Function()? now;

  @override
  State<MarketingReport> createState() => _MarketingReportState();
}

class _MarketingReportState extends State<MarketingReport> {
  String _period = 'last_week';
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;

  static const _periods = {
    'this_week': 'This week',
    'last_week': 'Last week',
    'this_month': 'This month',
    'last_month': 'Last month',
  };

  /// Monday-start weeks, as the P&L's are.
  (DateTime, DateTime) _range() {
    final now = (widget.now ?? DateTime.now)();
    final today = DateTime(now.year, now.month, now.day);
    final monday = today.subtract(Duration(days: today.weekday - 1));

    return switch (_period) {
      'this_week' => (monday, today),
      'this_month' => (DateTime(today.year, today.month), today),
      'last_month' => (
        DateTime(today.year, today.month - 1),
        DateTime(today.year, today.month, 0),
      ),
      _ => (
        monday.subtract(const Duration(days: 7)),
        monday.subtract(const Duration(days: 1)),
      ),
    };
  }

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final (from, to) = _range();
    final response = await widget.api.get('/reports/marketing', {
      'from': _day(from),
      'to': _day(to),
    });
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _rows = [
          for (final row in (response.data?['rows'] as List? ?? const []))
            (row as Map).cast<String, dynamic>(),
        ];
      } else {
        _error = response.message;
      }
    });
  }

  static String _money(Object? minor) {
    if (minor is! num) return '—';
    final dollars = (minor / 100).round();
    final digits = dollars.abs().toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );

    return '${dollars < 0 ? '-' : ''}\$$digits';
  }

  static String _count(Object? n) => n is num ? '${n.round()}' : '—';

  static const _columns = <(String, String)>[
    ('branch', 'Branch'),
    ('source', 'Source'),
    ('clicks', 'Clicks'),
    ('conversions', 'Conversions'),
    ('cost_minor', 'Cost'),
    ('leads', 'Leads'),
    ('leads_to_jobs', 'Leads → jobs'),
    ('jobs_created', 'Jobs created'),
    ('cost_per_job_minor', 'Avg cost per job'),
    ('done_jobs', 'Done jobs'),
    ('gross_minor', 'Gross sales'),
    ('profit_minor', 'Profit (est.)'),
    ('net_profit_minor', 'Net profit'),
  ];

  String _cell(Map<String, dynamic> row, String key) => switch (key) {
    'branch' || 'source' => '${row[key] ?? ''}',
    'leads_to_jobs' => row[key] is num ? '${row[key]}%' : '—',
    'cost_minor' when (row['cost_refused'] as num? ?? 0) > 0 =>
      '${_money(row[key])} *',
    final k when k.endsWith('_minor') => _money(row[key]),
    _ => _count(row[key]),
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 8,
          children: [
            for (final p in _periods.entries)
              ChoiceChip(
                key: ValueKey('marketing-${p.key}'),
                label: Text(p.value),
                selected: _period == p.key,
                showCheckmark: false,
                onSelected: (_) {
                  setState(() => _period = p.key);
                  _load();
                },
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null)
          ErrorNote(_error!)
        else if (_loading)
          const Center(child: CircularProgressIndicator())
        else if (_rows.isEmpty)
          Text('No Workiz jobs in these dates.', style: muted)
        else
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columns: [
                for (final (key, label) in _columns)
                  DataColumn(
                    label: Text(label),
                    numeric: key != 'branch' && key != 'source',
                  ),
              ],
              rows: [
                for (final row in _rows)
                  DataRow(
                    cells: [
                      for (final (key, _) in _columns)
                        DataCell(Text(_cell(row, key))),
                    ],
                  ),
              ],
            ),
          ),
        const SizedBox(height: 12),
        Text(
          'Jobs created are booked in these dates; done jobs and gross sales '
          'are jobs marked Done in them. Workiz holds no job costs, so profit '
          'is an estimate: gross sales times the branch\'s P&L margin before '
          'marketing. Net profit is that minus the ad cost. Clicks, '
          'conversions and cost come from Google Ads (LS Ads is Local '
          'Services, PPC the other campaigns); a dash means no ad account is '
          'linked to the branch yet, not zero. A cost marked * is too low: '
          "one of the branch's ad accounts could not be read.",
          style: muted,
        ),
      ],
    );
  }
}
