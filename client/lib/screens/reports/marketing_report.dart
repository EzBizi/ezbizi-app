import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

/// Ad cost against jobs, sales and profit, per branch and Workiz job source
/// (Jose, 2026-10-12). Profit is an estimate: the source's done-job sales
/// times the branch's P&L margin before marketing, as the note under the
/// table says.
class MarketingReport extends StatefulWidget {
  const MarketingReport({
    super.key,
    required this.api,
    this.now,
    this.formats = DisplayFormats.fallback,
  });

  final ApiClient api;
  final DateTime Function()? now;
  final DisplayFormats formats;

  @override
  State<MarketingReport> createState() => _MarketingReportState();
}

class _MarketingReportState extends State<MarketingReport> {
  late DateSpan _span = _lastWeek(_today);
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;

  /// Monday-start weeks, as the P&L's are.
  static DateSpan _lastWeek(DateTime today) {
    final monday = today.subtract(Duration(days: today.weekday - 1));
    return DateSpan(
      monday.subtract(const Duration(days: 7)),
      monday.subtract(const Duration(days: 1)),
    );
  }

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

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
    final (from, to) = (_span.from, _span.to);
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

  /// Profit with no P&L behind it says so on the cell, and why on hover
  /// (Jose kept asking why it was blank).
  Widget _cellWidget(Map<String, dynamic> row, String key) {
    final missing = row['profit_missing'];
    if ((key == 'profit_minor' || key == 'net_profit_minor') &&
        row[key] == null &&
        missing is String) {
      return Tooltip(
        message: 'No profit: $missing.',
        child: Text(
          'No P&L',
          key: ValueKey('no-pnl-${row['branch']}-${row['source']}-$key'),
          style: TextStyle(color: Theme.of(context).colorScheme.outline),
        ),
      );
    }

    return Text(_cell(row, key));
  }

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
        ReportDates(
          initial: _span,
          today: _today,
          formats: widget.formats,
          onChanged: (span) {
            setState(() => _span = span);
            _load();
          },
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
                        DataCell(_cellWidget(row, key)),
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
