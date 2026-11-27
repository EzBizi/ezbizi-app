import '../../widgets/list_search.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/table_download.dart';
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
    this.span,
    this.canExport = false,
  });

  /// The range from the Reports screen's shared date bar (review item 14);
  /// with it, this tab draws no date bar of its own.
  final DateSpan? span;

  /// A Download button over the table (reports.export; Merav, 2026-11-19).
  final bool canExport;

  final ApiClient api;
  final DateTime Function()? now;
  final DisplayFormats formats;

  @override
  State<MarketingReport> createState() => _MarketingReportState();
}

/// Swappable so a test sees the PDF instead of a print dialog.
@visibleForTesting
Future<void> Function(Uint8List pdf, String name) printPdf = (pdf, name) =>
    Printing.layoutPdf(onLayout: (_) async => pdf, name: name);

class _MarketingReportState extends State<MarketingReport> {
  late DateSpan _span = widget.span ?? _lastWeek(_today);

  @override
  void didUpdateWidget(MarketingReport old) {
    super.didUpdateWidget(old);
    if (widget.span != null && widget.span != old.span) {
      setState(() => _span = widget.span!);
      _load();
    }
  }

  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;
  String _query = '';

  /// Monday-start weeks, as the P&L's are.
  static DateSpan _lastWeek(DateTime today) {
    final monday = today.subtract(Duration(days: today.weekday - 1));
    return DateSpan(
      monday.subtract(const Duration(days: 7)),
      monday.subtract(const Duration(days: 1)),
    );
  }

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

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
      'from': formatCalendarDay(from),
      'to': formatCalendarDay(to),
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

  /// The explanation, one line per column heading (review item 15).
  static const _help = {
    'clicks':
        'From Google Ads. A dash: no ad account linked to the branch yet, not zero.',
    'conversions':
        'From Google Ads (LS Ads is Local Services, PPC the other campaigns).',
    'cost_minor':
        "From Google Ads. Marked * when one of the branch's ad accounts could not be read, so too low.",
    'leads': 'Leads in these dates.',
    'leads_to_jobs': 'The share of these leads that became a job.',
    'jobs_created': 'Workiz jobs booked in these dates.',
    'done_jobs': 'Jobs marked Done in these dates.',
    'gross_minor': 'Sales of the jobs marked Done in these dates.',
    'profit_minor':
        "An estimate: Workiz holds no job costs, so gross sales times the branch's P&L margin before marketing.",
    'net_profit_minor': 'Profit (est.) minus the ad cost.',
  };

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
    // A sub company's account that names no branch, shared out by jobs
    // (Angi Sweep, Ace, Jose 2026-11-16): an estimate, and the cell says so.
    final note = key == 'cost_minor' ? row['cost_note'] : null;
    return GridCell(
      note is String ? '≈ $text' : text,
      tooltip: note is String ? note : null,
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
    // Leads → jobs as each row counts it: the jobs those leads became (review
    // item 15), not every job created.
    final leadJobs = sum('lead_jobs');
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
      'lead_jobs': leadJobs,
      'leads_to_jobs': leads != null && leads > 0 && leadJobs != null
          ? num.parse((leadJobs / leads * 100).toStringAsFixed(1))
          : null,
      'cost_per_job_minor': cost != null && jobs != null && jobs > 0
          ? (cost / jobs).round()
          : null,
    };
  }

  /// Print (Merav, 2026-11-04; UI-UX approved): the dates, the four figures
  /// and every row with the Total, black on white, landscape. The header
  /// repeats on each page; the footer says the page and when it was printed.
  Future<void> _print() async {
    final t = _totals;
    final dates = _span.single
        ? widget.formats.formatDate(_span.from)
        : '${widget.formats.formatDate(_span.from)} – '
              '${widget.formats.formatDate(_span.to)}';
    final at = (widget.now ?? DateTime.now)();
    final printed =
        '${widget.formats.formatDate(at)} '
        '${widget.formats.formatTime(at.hour, at.minute)}';
    final figures = [
      ('Ad cost', _money(t['cost_minor'])),
      ('Leads', _count(t['leads'])),
      ('Gross sales', _money(t['gross_minor'])),
      ('Net profit', _money(t['net_profit_minor'])),
    ];
    String text(Map<String, dynamic> row, String key) {
      final missing = row['profit_missing'];
      if ((key == 'profit_minor' || key == 'net_profit_minor') &&
          row[key] == null &&
          missing is String) {
        return 'No P&L';
      }
      return _cell(row, key);
    }

    // ponytail: the PDF's built-in Helvetica has no dashes, arrows or dots;
    // plain stand-ins until a font is bundled.
    String plain(String v) => v
        .replaceAll('—', '-')
        .replaceAll('–', '-')
        .replaceAll('→', '->')
        .replaceAll('·', '|');

    final doc = pw.Document(title: plain('Marketing $dates'));
    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.letter.landscape,
        margin: const pw.EdgeInsets.all(28),
        footer: (c) => pw.Row(
          mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
          children: [
            pw.Text('Printed $printed', style: const pw.TextStyle(fontSize: 8)),
            pw.Text(
              'Page ${c.pageNumber} of ${c.pagesCount}',
              style: const pw.TextStyle(fontSize: 8),
            ),
          ],
        ),
        build: (_) => [
          pw.Text(
            plain('Marketing | $dates'),
            style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold),
          ),
          pw.SizedBox(height: 10),
          pw.Row(
            children: [
              for (final (label, value) in figures)
                pw.Expanded(
                  child: pw.Column(
                    crossAxisAlignment: pw.CrossAxisAlignment.start,
                    children: [
                      pw.Text(label, style: const pw.TextStyle(fontSize: 9)),
                      pw.Text(
                        plain(value),
                        style: pw.TextStyle(
                          fontSize: 14,
                          fontWeight: pw.FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
          pw.SizedBox(height: 12),
          pw.TableHelper.fromTextArray(
            headerCount: 1, // repeated on every page
            headers: [for (final (_, label) in _columns) plain(label)],
            data: [
              for (final row in _rows)
                [for (final (key, _) in _columns) plain(text(row, key))],
              [
                for (final (key, _) in _columns)
                  plain(_cell(t.cast<String, dynamic>(), key)),
              ],
            ],
            headerStyle: pw.TextStyle(
              fontSize: 8,
              fontWeight: pw.FontWeight.bold,
            ),
            cellStyle: const pw.TextStyle(fontSize: 8),
            cellAlignments: {
              for (var i = 2; i < _columns.length; i++)
                i: pw.Alignment.centerRight,
            },
            border: const pw.TableBorder(
              horizontalInside: pw.BorderSide(width: 0.3),
              bottom: pw.BorderSide(width: 0.3),
              top: pw.BorderSide(width: 0.3),
            ),
          ),
        ],
      ),
    );
    await printPdf(await doc.save(), 'Marketing $dates');
  }

  /// UI queue 87, desktop 1: the figures, then the one table, Branch and
  /// Source pinned, a Total row, a loss in red.
  List<Widget> _figuresAndTable() {
    final t = _totals;
    final net = t['net_profit_minor'];
    final leads = t['leads'];
    // The Total row stays the whole report's; the search narrows the rows.
    final query = _query.trim().toLowerCase();
    final shown = query.isEmpty
        ? _rows
        : [
            for (final row in _rows)
              if ('${row['branch'] ?? ''} ${row['source'] ?? ''}'
                  .toLowerCase()
                  .contains(query))
                row,
          ];
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
            note: t['lead_jobs'] is num
                ? '${_count(t['lead_jobs'])} became jobs'
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
      // Standard field height, as tall as Leads search (Jose, 2026-10-27).
      Align(
        alignment: Alignment.centerLeft,
        child: SizedBox(
          width: 240,
          child: ListSearch(
            key: const Key('marketing-report-search'),
            hint: 'Find a branch or source',
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
      ),
      const SizedBox(height: 12),
      if (shown.isEmpty)
        Text(
          'No rows match "${_query.trim()}".',
          style: Theme.of(context).textTheme.bodyMedium,
        )
      else
        ReportGrid(
          key: const Key('marketing-table'),
          download: widget.canExport
              ? TableFile.name('Marketing Reports', _span)
              : null,
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
                help: _help[key],
              ),
          ],
          rows: [
            for (final row in shown)
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
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: widget.span == null
                  ? ReportDates(
                      key: const ValueKey('tour-report-dates'),
                      initial: _span,
                      today: _today,
                      formats: widget.formats,
                      onChanged: (span) {
                        setState(() => _span = span);
                        _load();
                      },
                    )
                  : const SizedBox.shrink(),
            ),
            const SizedBox(width: 8),
            // Disabled, not hidden, with nothing to print, so it stays put.
            OutlinedButton.icon(
              key: const Key('marketing-print'),
              onPressed: _loading || _error != null || _rows.isEmpty
                  ? null
                  : _print,
              icon: const Icon(Icons.print_outlined, size: 18),
              label: const Text('Print'),
            ),
          ],
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
      ],
    );
  }
}
