import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/module_overview.dart';
import '../../core/theme.dart';
import '../../core/numbers.dart';
import '../../core/job_money.dart';
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

  /// Money as every Reports tab writes it now: `$9,479.00` (UI queue 87).
  static String _money(Object? minor) =>
      minor is num ? formatMoney(minor.round()) : '—';

  static String _count(Object? n) => n is num ? formatCount(n.round()) : '—';

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
  GridCell _gridCell(Map<String, dynamic> row, String key) {
    final missing = row['profit_missing'];
    if ((key == 'profit_minor' || key == 'net_profit_minor') &&
        row[key] == null &&
        missing is String) {
      return GridCell(
        'No P&L',
        key: ValueKey('no-pnl-${row['branch']}-${row['source']}-$key'),
        muted: true,
        tooltip: 'No profit: $missing.',
      );
    }
    final text = _cell(row, key);
    if (text == '—') return const GridCell.none();
    final value = row[key];
    return GridCell(
      text,
      tone: key == 'net_profit_minor' && value is num && value < 0
          ? Tone.bad
          : null,
    );
  }

  String _cell(Map<String, dynamic> row, String key) => switch (key) {
    'branch' || 'source' => '${row[key] ?? ''}',
    'leads_to_jobs' => row[key] is num ? '${row[key]}%' : '—',
    'cost_minor' when (row['cost_refused'] as num? ?? 0) > 0 =>
      '${_money(row[key])} *',
    final k when k.endsWith('_minor') => _money(row[key]),
    _ => _count(row[key]),
  };

  /// The Total row: sums where a sum means something, rates worked out from
  /// the sums, and a dash where no row has a figure.
  Map<String, Object?> get _totals {
    num? sum(String key) {
      num? total;
      for (final row in _rows) {
        final v = row[key];
        if (v is num) total = (total ?? 0) + v;
      }
      return total;
    }

    final leads = sum('leads');
    final jobs = sum('jobs_created');
    final cost = sum('cost_minor');
    return {
      'branch': 'Total',
      'source': _rows.length == 1 ? '1 row' : '${_rows.length} rows',
      for (final key in [
        'clicks',
        'conversions',
        'cost_minor',
        'leads',
        'jobs_created',
        'done_jobs',
        'gross_minor',
        'profit_minor',
        'net_profit_minor',
      ])
        key: sum(key),
      'leads_to_jobs': leads != null && leads > 0 && jobs != null
          ? num.parse((jobs / leads * 100).toStringAsFixed(1))
          : null,
      'cost_per_job_minor': cost != null && jobs != null && jobs > 0
          ? (cost / jobs).round()
          : null,
    };
  }

  /// UI queue 87, desktop 1: the figures, then the one table, Branch and
  /// Source pinned, a Total row, a loss in red.
  List<Widget> _figuresAndTable() {
    final t = _totals;
    final net = t['net_profit_minor'];
    final leads = t['leads'];
    final jobs = t['jobs_created'];
    return [
      OverviewFigures(
        figures: [
          OverviewFigure(
            key: const Key('marketing-fact-cost'),
            label: 'Ad cost',
            value: _money(t['cost_minor']),
          ),
          OverviewFigure(
            label: 'Leads',
            value: _count(leads),
            note: jobs is num
                ? '${_count(jobs)} became jobs'
                      '${t['leads_to_jobs'] is num ? ' · ${t['leads_to_jobs']}%' : ''}'
                : '',
          ),
          OverviewFigure(label: 'Gross sales', value: _money(t['gross_minor'])),
          OverviewFigure(
            key: const Key('marketing-fact-net'),
            label: 'Net profit',
            value: _money(net),
            tone: net is num && net < 0 ? Tone.bad : null,
            note: 'estimated, after ad cost',
          ),
        ],
      ),
      const SizedBox(height: 12),
      ReportGrid(
        key: const Key('marketing-table'),
        columns: [
          for (final (key, label) in _columns)
            GridColumn(
              label,
              numeric: key != 'branch' && key != 'source',
              pinned: key == 'branch' || key == 'source',
              // A phone's card: cost, leads and jobs, net profit beside
              // the name.
              main:
                  key == 'cost_minor' ||
                  key == 'leads' ||
                  key == 'jobs_created',
              headline: key == 'net_profit_minor',
            ),
        ],
        rows: [
          for (final row in _rows)
            [for (final (key, _) in _columns) _gridCell(row, key)],
        ],
        total: [
          for (final (key, _) in _columns)
            switch (_cell(t.cast<String, dynamic>(), key)) {
              '—' => const GridCell.none(),
              final text => GridCell(
                text,
                tone: key == 'net_profit_minor' && net is num && net < 0
                    ? Tone.bad
                    : null,
              ),
            },
        ],
      ),
    ];
  }

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
          key: const ValueKey('tour-report-dates'),
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
          const EmptyState(
            key: Key('marketing-empty'),
            icon: Icons.campaign_outlined,
            title: 'Nothing in these dates',
            message: 'No Workiz jobs in these dates.',
          )
        else
          ..._figuresAndTable(),
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
