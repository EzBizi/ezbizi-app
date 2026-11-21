/// Shoval's operations reports (2026-11-18; UI queue 199, option 1): eight
/// tabs in three families — Jobs › Estimates; Office › Tasks, Complaints,
/// Dispatch, Accounting weekly, Recruiting; Technicians › Tech report, AI
/// checks. One shape for all, as the Jobs tab's: search and filters, the
/// figures, Download, the grid, and a row that opens what it is about.
///
/// The server (merge 3c06e5ea7) counts and derives everything; nothing here
/// adds a figure up. What is derived (texted, answered, contacted, photos,
/// ETA calls) says so in its help.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/phone_numbers.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/module_overview.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/table_download.dart';
import 'report_dates.dart';

typedef OpsRow = Map<String, dynamic>;

/// What one report is: where it reads, how it is filtered, what it shows.
class OpsSpec {
  const OpsSpec({
    required this.key,
    required this.anchor,
    required this.title,
    required this.path,
    required this.icon,
    required this.columns,
    required this.cells,
    required this.figures,
    required this.emptyTitle,
    required this.emptyMessage,
    this.paged = false,
    this.search = false,
    this.multi = const [],
    this.total,
    this.footnote,
    this.open,
    this.drill = false,
  });

  final String key;

  /// The page's own key, written out so a walkthrough can point at it.
  final Key anchor;
  final String title;

  /// Written out in full: ClientCallsRealRoutesTest reads it.
  final String path;
  final IconData icon;
  final List<GridColumn> columns;
  final List<GridCell> Function(OpsRow row, DisplayFormats formats) cells;
  final List<OverviewFigure> Function(OpsRow data, DisplayFormats formats)
  figures;
  final String emptyTitle;
  final String emptyMessage;

  /// Rows a page at a time with offset/limit and a count; else all at once.
  final bool paged;
  final bool search;

  /// (query key, label, the data's [{value, count}] list, labels by value).
  final List<(String, String, String, Map<String, String>)> multi;

  /// The totals row, from the data.
  final List<GridCell> Function(OpsRow data)? total;
  final String Function(OpsRow data)? footnote;

  /// What a tapped row opens.
  final void Function(BuildContext context, OpsRow row)? open;

  /// AI checks: a row opens the technician's missed jobs, check by check.
  final bool drill;
}

class OpsReport extends StatefulWidget {
  const OpsReport({
    super.key,
    required this.api,
    required this.span,
    required this.spec,
    this.formats = DisplayFormats.fallback,
    this.canExport = false,
  });

  final ApiClient api;
  final DateSpan span;
  final OpsSpec spec;
  final DisplayFormats formats;
  final bool canExport;

  @override
  State<OpsReport> createState() => _OpsReportState();
}

class _OpsReportState extends State<OpsReport> {
  static const _page = 200;
  static const _filePage = 5000;

  final Map<String, Set<String>> _picked = {};
  String _query = '';
  Timer? _typing;
  OpsRow? _data;
  final List<OpsRow> _rows = [];
  String? _error;

  OpsSpec get _spec => widget.spec;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    super.dispose();
  }

  @override
  void didUpdateWidget(OpsReport old) {
    super.didUpdateWidget(old);
    if (widget.span != old.span) _load();
  }

  void _search(String value) {
    _query = value.trim();
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 350), _load);
  }

  Map<String, Object> get _filters => {
    'from': formatCalendarDay(widget.span.from),
    'to': formatCalendarDay(widget.span.to),
    for (final e in _picked.entries)
      if (e.value.isNotEmpty) '${e.key}[]': e.value.toList(),
    if (_query.isNotEmpty) 'q': _query,
  };

  Future<void> _load({bool more = false}) async {
    final response = await widget.api.get(_spec.path, {
      ..._filters,
      if (_spec.paged) 'offset': '${more ? _rows.length : 0}',
      if (_spec.paged) 'limit': '$_page',
    });
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _error = null;
      _data = (response.data as Map).cast<String, dynamic>();
      if (!more) _rows.clear();
      _rows.addAll([
        for (final r in (_data!['rows'] as List? ?? const []))
          (r as Map).cast<String, dynamic>(),
      ]);
    });
  }

  int get _count => (_data?['count'] as num?)?.toInt() ?? _rows.length;

  Future<List<List<Object?>>?> _everyRow() async {
    if (!_spec.paged) {
      return ReportGrid.sheetFor(_spec.columns, [
        for (final r in _rows) _spec.cells(r, widget.formats),
      ]);
    }
    final rows = <OpsRow>[];
    var offset = 0;
    int? count;
    while (count == null || offset < count) {
      final response = await widget.api.get(_spec.path, {
        ..._filters,
        'offset': '$offset',
        'limit': '$_filePage',
      });
      if (!response.ok) return null;
      final data = (response.data as Map).cast<String, dynamic>();
      count = (data['count'] as num?)?.toInt() ?? 0;
      final page = data['rows'] as List? ?? const [];
      if (page.isEmpty) break;
      offset += page.length;
      rows.addAll([for (final r in page) (r as Map).cast<String, dynamic>()]);
    }
    return ReportGrid.sheetFor(_spec.columns, [
      for (final r in rows) _spec.cells(r, widget.formats),
    ]);
  }

  Widget _many(
    String param,
    String label,
    String list,
    Map<String, String> labels,
  ) {
    final chosen = _picked[param] ?? const <String>{};
    final choices = {
      for (final c in (_data?[list] as List? ?? const []))
        if (c is Map)
          '${c['value']}':
              '${labels['${c['value']}'] ?? c['value']} '
              '(${formatCount((c['count'] as num? ?? 0).toInt())})',
    };
    return SizedBox(
      width: 240,
      child: SearchMultiPickerField<String>(
        key: ValueKey('${_spec.key}-$param'),
        label: label,
        hint: 'All',
        values: chosen,
        searchFrom: 0,
        selectShown: true,
        options: [
          for (final c in choices.entries)
            SearchOption(value: c.key, label: c.value),
          for (final v in chosen)
            if (!choices.containsKey(v)) SearchOption(value: v, label: v),
        ],
        onChanged: (picked) {
          setState(() => _picked[param] = picked);
          _load();
        },
      ),
    );
  }

  Future<void> _drill(OpsRow row) async {
    final tech = row['tech'] == null ? 'none' : '${row['tech']}';
    await showSidePanel<void>(
      context,
      builder: (_) => _TechChecksPanel(
        api: widget.api,
        span: widget.span,
        formats: widget.formats,
        tech: tech,
        name: '${row['tech'] ?? 'No technician'}',
        row: row,
        rules: ((_data?['rules'] as Map?) ?? const {}).cast<String, dynamic>(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data;
    final count = _count;
    final open = _spec.drill
        ? (int i) => _drill(_rows[i])
        : _spec.open == null
        ? null
        : (int i) => _spec.open!(context, _rows[i]);

    return ListView(
      key: _spec.anchor,
      padding: const EdgeInsets.all(16),
      children: [
        if (_spec.search || _spec.multi.isNotEmpty) ...[
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              if (_spec.search)
                reportSearch(
                  key: ValueKey('${_spec.key}-search'),
                  hint: 'Search every column',
                  onChanged: _search,
                ),
              for (final (param, label, list, labels) in _spec.multi)
                _many(param, label, list, labels),
            ],
          ),
          const SizedBox(height: 12),
        ],
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (data != null) ...[
          OverviewFigures(
            key: ValueKey('${_spec.key}-figures'),
            figures: _spec.figures(data, widget.formats),
          ),
          const SizedBox(height: 12),
          if (_rows.isEmpty)
            EmptyState(
              key: ValueKey('${_spec.key}-empty'),
              icon: _spec.icon,
              title: _spec.emptyTitle,
              message: _spec.emptyMessage,
            )
          else ...[
            if (widget.canExport) ...[
              Align(
                alignment: Alignment.centerRight,
                child: TableDownload(
                  name: TableFile.name(_spec.title, widget.span),
                  rows: _everyRow,
                ),
              ),
              const SizedBox(height: 8),
            ],
            ReportGrid(
              key: ValueKey('${_spec.key}-table'),
              columns: _spec.columns,
              rows: [for (final r in _rows) _spec.cells(r, widget.formats)],
              total: _spec.total?.call(data),
              onTap: open,
            ),
            if (_spec.paged && _rows.length < count) ...[
              const SizedBox(height: 12),
              Center(
                child: OutlinedButton(
                  key: ValueKey('${_spec.key}-more'),
                  onPressed: () => _load(more: true),
                  child: Text(
                    'Show ${formatCount((count - _rows.length).clamp(0, _page))} more '
                    'of ${formatCount(count - _rows.length)}',
                  ),
                ),
              ),
            ],
          ],
          if (_spec.footnote?.call(data) case final String note) ...[
            const SizedBox(height: 12),
            Text(
              note,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ],
      ],
    );
  }
}

// ── Words and cells ─────────────────────────────────────────────────

GridCell _text(Object? v) =>
    v == null || '$v'.isEmpty ? const GridCell.none() : GridCell('$v');

GridCell _n(Object? v) => v == null
    ? const GridCell.none()
    : GridCell(formatCount((v as num).round()));

String _money(Object? minor) => formatMoney(((minor as num?) ?? 0).round());

GridCell _moneyCell(Object? minor) =>
    minor == null ? const GridCell.none() : GridCell(_money(minor));

String _when(Object? stamp, DisplayFormats f) {
  final at = DateTime.tryParse('${stamp ?? ''}'.replaceFirst(' ', 'T'));
  if (at == null) return '—';
  final local = at.isUtc ? at.toLocal() : at;
  return '${f.formatDate(local)} ${f.formatTime(local.hour, local.minute)}';
}

String _day(Object? stamp, DisplayFormats f) {
  final at = DateTime.tryParse('${stamp ?? ''}');
  return at == null ? '—' : f.formatDate(at);
}

GridCell _hours(Object? h) => h == null
    ? const GridCell.none()
    : GridCell((h as num).toStringAsFixed(h < 10 ? 1 : 0));

GridCell _phone(Object? p) => p == null || '$p'.isEmpty
    ? const GridCell.none()
    : GridCell(formatPhoneNumber(toE164('$p') ?? '$p'));

/// A count with its share of [of]: "9 · 10%".
GridCell _share(Object? n, Object? of, {bool flag = false}) {
  if (n == null) return const GridCell.none(tooltip: 'Not checked');
  final count = (n as num).toInt();
  final all = ((of as num?) ?? 0).toInt();
  final pct = all == 0 ? 0 : (count * 100 / all).round();
  return GridCell(
    all == 0 ? formatCount(count) : '${formatCount(count)} · $pct%',
    tone: flag && pct >= 10 ? Tone.caution : null,
  );
}

Map<String, dynamic> _fig(OpsRow data, String key) =>
    ((data['figures'] as Map?)?[key] as Map?)?.cast<String, dynamic>() ??
    const {};

void _openJob(BuildContext context, OpsRow row) {
  final id = row['job_id'];
  if (id == null) return;
  DeepLinks.open(DeepLink(module: 'jobs', type: 'job', id: '$id'));
  OpenDestination.of(context)?.call('jobs');
}

// ── The eight ───────────────────────────────────────────────────────

const _estimateStates = {
  'draft': 'Draft',
  'open': 'Open',
  'approved': 'Approved',
  'declined': 'Declined',
  'archived': 'Archived',
};

final estimatesSpec = OpsSpec(
  key: 'estimates',
  anchor: ValueKey('tour-reports-estimates'),
  title: 'Estimates',
  path: '/reports/estimates',
  icon: Icons.request_quote_outlined,
  paged: true,
  search: true,
  multi: const [('state', 'State', 'states', _estimateStates)],
  emptyTitle: 'No estimates',
  emptyMessage: 'No estimate was made in these dates.',
  figures: (d, _) {
    OverviewFigure f(String key, String label, {String? help}) {
      final x = _fig(d, key);
      return OverviewFigure(
        key: ValueKey('estimates-$key'),
        label: label,
        value: formatCount(((x['count'] as num?) ?? 0).toInt()),
        note: _money(x['value_minor']),
        help: help,
      );
    }

    const derived =
        'From the texts we sent and the texts and calls that came back; '
        'replies outside EzBizi are not seen.';
    return [
      f('total', 'Estimates'),
      f('open', 'Open'),
      f('approved', 'Approved'),
      f('declined', 'Declined'),
      f('texted_no_answer', 'Texted, no answer', help: derived),
      f('closed_no_answer', 'Closed, never answered', help: derived),
      f('closed_answered', 'Closed after an answer', help: derived),
    ];
  },
  columns: const [
    GridColumn('Estimate', pinned: true),
    GridColumn('Made in'),
    GridColumn('State', main: true),
    GridColumn('Created'),
    GridColumn('Client', main: true),
    GridColumn('Phone'),
    GridColumn('Branch'),
    GridColumn('Job'),
    GridColumn('Texted'),
    GridColumn('Answered'),
    GridColumn('Reason'),
    GridColumn('Value', numeric: true, main: true),
  ],
  cells: (r, f) => [
    _text(r['number']),
    _text(r['store'] == 'workiz' ? 'Workiz' : 'EzBizi'),
    GridCell(
      _estimateStates['${r['state']}'] ?? '${r['state'] ?? ''}',
      tone: switch (r['state']) {
        'approved' => Tone.ok,
        'declined' => Tone.bad,
        'open' when r['no_answer'] == true => Tone.caution,
        _ => null,
      },
    ),
    GridCell(_day(r['created_at'], f)),
    _text(r['client']),
    _phone(r['phone']),
    _text(r['branch']),
    _text(r['job_number']),
    r['texted_at'] == null
        ? const GridCell.none()
        : GridCell(_when(r['texted_at'], f)),
    r['answered_at'] == null
        ? GridCell(r['texted_at'] == null ? '' : 'No answer', muted: true)
        : GridCell(_when(r['answered_at'], f)),
    _text(r['reason']),
    _moneyCell(r['value_minor']),
  ],
  open: _openJob,
);

final tasksSpec = OpsSpec(
  key: 'tasks',
  anchor: ValueKey('tour-reports-tasks'),
  title: 'Tasks',
  path: '/reports/tasks',
  icon: Icons.checklist_outlined,
  emptyTitle: 'No tasks',
  emptyMessage: 'Nobody had a task open or done in these dates.',
  figures: (d, _) {
    final t = ((d['totals'] as Map?) ?? const {}).cast<String, dynamic>();
    return [
      OverviewFigure(
        label: 'Open',
        value: formatCount((t['open'] as num? ?? 0).toInt()),
      ),
      OverviewFigure(
        label: 'Overdue',
        value: formatCount((t['overdue'] as num? ?? 0).toInt()),
        tone: (t['overdue'] as num? ?? 0) > 0 ? Tone.bad : null,
      ),
      OverviewFigure(
        label: 'Done',
        value: formatCount((t['done'] as num? ?? 0).toInt()),
      ),
    ];
  },
  columns: const [
    GridColumn('Owner', pinned: true),
    GridColumn('Open', numeric: true, main: true),
    GridColumn('Done', numeric: true, main: true),
    GridColumn('Overdue', numeric: true, main: true),
    GridColumn('Avg days to done', numeric: true),
  ],
  cells: (r, _) => [
    GridCell('${r['name'] ?? 'Nobody'}'),
    _n(r['open']),
    _n(r['done']),
    GridCell(
      formatCount((r['overdue'] as num? ?? 0).toInt()),
      tone: (r['overdue'] as num? ?? 0) > 0 ? Tone.bad : null,
    ),
    _hours(r['avg_days_to_done']),
  ],
  footnote: (_) =>
      'A task shared by two owners counts on both rows, and once in the '
      'figures, so the rows do not add up to them.',
);

const _complaintKinds = {
  'bad_review': 'Google review',
  'bad_rating': 'Website rating',
  'call_complaint': 'Call',
  'bbb': 'BBB',
  'attorney_general': 'Attorney General',
  'lawsuit': 'Lawsuit',
  'other': 'Other',
};

const _complaintStatuses = {
  'new': 'New',
  'in_progress': 'In progress',
  'solved': 'Solved',
  'not_solvable': "Can't be solved",
};

final complaintsSpec = OpsSpec(
  key: 'complaints',
  anchor: ValueKey('tour-reports-complaints'),
  title: 'Complaints',
  path: '/reports/complaints',
  icon: Icons.sentiment_dissatisfied_outlined,
  paged: true,
  search: true,
  multi: const [
    ('status', 'Status', 'statuses', _complaintStatuses),
    ('kind', 'Kind', 'kinds', _complaintKinds),
  ],
  emptyTitle: 'No complaints',
  emptyMessage: 'No complaint came in in these dates.',
  figures: (d, _) {
    final f = ((d['figures'] as Map?) ?? const {}).cast<String, dynamic>();
    final t = ((d['targets'] as Map?) ?? const {}).cast<String, dynamic>();
    String pct(Object? v) => v == null ? '—' : '${(v as num).round()}%';
    String h(Object? v) =>
        v == null ? '—' : '${(v as num).toStringAsFixed(1)} h';
    const contacted =
        'The first call or text to the customer after the complaint came in, '
        'else its first note.';
    return [
      OverviewFigure(
        label: 'Complaints',
        value: formatCount((f['total'] as num? ?? 0).toInt()),
      ),
      OverviewFigure(
        label: 'Open',
        value: formatCount((f['open'] as num? ?? 0).toInt()),
      ),
      OverviewFigure(
        key: const ValueKey('complaints-contact-kept'),
        label: 'Called within ${t['contact_hours'] ?? '?'}h',
        value: pct(f['contact_kept_percent']),
        note: 'average ${h(f['avg_hours_to_contact'])}',
        help: contacted,
      ),
      OverviewFigure(
        label: 'Solved within ${t['solve_hours'] ?? '?'}h',
        value: pct(f['solve_kept_percent']),
        note: 'average ${h(f['avg_hours_to_solve'])}',
      ),
      OverviewFigure(
        label: 'Never contacted',
        value: formatCount((f['not_contacted'] as num? ?? 0).toInt()),
        tone: (f['not_contacted'] as num? ?? 0) > 0 ? Tone.bad : null,
        help: contacted,
      ),
    ];
  },
  columns: const [
    GridColumn('Came in', pinned: true),
    GridColumn('Kind'),
    GridColumn('Customer', main: true),
    GridColumn('Phone'),
    GridColumn('Branch'),
    GridColumn('Owner'),
    GridColumn('Status', main: true),
    GridColumn(
      'Contacted',
      help:
          'The first call or text to the customer after the complaint came '
          'in, else its first note.',
    ),
    GridColumn('Hours to contact', numeric: true, main: true),
    GridColumn('Solved'),
    GridColumn('Hours to solve', numeric: true),
  ],
  cells: (r, f) => [
    GridCell(_when(r['raised_at'], f)),
    _text(_complaintKinds['${r['kind']}'] ?? r['kind']),
    _text(r['customer']),
    _phone(r['phone']),
    _text(r['branch']),
    _text(r['owner']),
    _text(_complaintStatuses['${r['status']}'] ?? r['status']),
    r['contacted_at'] == null
        ? const GridCell('Not yet', muted: true)
        : GridCell(
            '${_when(r['contacted_at'], f)} · ${switch (r['contacted_by']) {
              'call' => 'call',
              'text' => 'text',
              'note' => 'note',
              _ => '',
            }}',
          ),
    r['hours_to_contact'] == null
        ? const GridCell.none()
        : GridCell(
            (r['hours_to_contact'] as num).toStringAsFixed(1),
            tone: r['contact_kept'] == false ? Tone.bad : null,
          ),
    r['solved_at'] == null
        ? const GridCell.none()
        : GridCell(_when(r['solved_at'], f)),
    r['hours_to_solve'] == null
        ? const GridCell.none()
        : GridCell(
            (r['hours_to_solve'] as num).toStringAsFixed(1),
            tone: r['solve_kept'] == false ? Tone.bad : null,
          ),
  ],
  open: (context, r) {
    if (r['source_type'] == 'lead' && r['source_id'] != null) {
      OpenDestination.leadOf(context)?.call('${r['source_id']}');
    } else if (r['source_type'] == 'review' && r['source_id'] != null) {
      DeepLinks.open(
        DeepLink(module: 'reviews', type: 'review', id: '${r['source_id']}'),
      );
      OpenDestination.of(context)?.call('reviews');
    }
  },
);

final dispatchSpec = OpsSpec(
  key: 'dispatch',
  anchor: ValueKey('tour-reports-dispatch'),
  title: 'Dispatch',
  path: '/reports/dispatch',
  icon: Icons.support_agent_outlined,
  emptyTitle: 'No leads',
  emptyMessage: 'No lead came in in these dates.',
  figures: (d, _) {
    final t = ((d['totals'] as Map?) ?? const {}).cast<String, dynamic>();
    int n(String k) => ((t[k] as num?) ?? 0).toInt();
    String m(Object? v) => v == null ? '—' : '${(v as num).round()} min';
    return [
      OverviewFigure(label: 'Leads', value: formatCount(n('leads'))),
      OverviewFigure(label: 'Calls', value: formatCount(n('calls'))),
      OverviewFigure(
        label: 'Messages',
        value: formatCount(n('messages')),
        note: '${formatCount(n('answered'))} answered',
        help:
            'Every lead that was not a phone call: texts, chat, forms, '
            'bookings, email. Answered: a person replied after it came in; '
            'automatic replies do not count.',
      ),
      OverviewFigure(
        key: const ValueKey('dispatch-median'),
        label: 'Median time to answer',
        value: m(t['median_minutes_to_answer']),
        note: 'average ${m(t['avg_minutes_to_answer'])}',
        help:
            'The middle answer time: one lead answered overnight moves the '
            'average far more than the median.',
      ),
    ];
  },
  columns: const [
    GridColumn('Dispatcher', pinned: true),
    GridColumn('Leads', numeric: true, main: true),
    GridColumn('Calls', numeric: true),
    GridColumn('Messages', numeric: true),
    GridColumn('Answered', numeric: true),
    GridColumn('Unanswered', numeric: true, main: true),
    GridColumn('Median min', numeric: true, main: true),
    GridColumn('Average min', numeric: true),
  ],
  cells: (r, _) => [
    GridCell('${r['dispatcher'] ?? 'Nobody took it'}'),
    _n(r['leads']),
    _n(r['calls']),
    _n(r['messages']),
    _n(r['answered']),
    GridCell(
      formatCount((r['unanswered'] as num? ?? 0).toInt()),
      tone: (r['unanswered'] as num? ?? 0) > 0 ? Tone.caution : null,
    ),
    _n(r['median_minutes_to_answer']),
    _n(r['avg_minutes_to_answer']),
  ],
  footnote: (_) => 'How each call went is scored under Reports › Performance.',
);

Map<String, String> _accountingIssues(Object? days) => {
  'done_unpaid': 'Done, still owing',
  'done_zero': r'Done for $0',
  'invoice_overdue': 'Invoice unpaid after ${days ?? 'N'} days',
  'paid_cancelled': 'Cancelled, money kept',
  'payment_deleted': 'Payment deleted',
};

final accountingSpec = OpsSpec(
  key: 'accounting',
  anchor: ValueKey('tour-reports-accounting'),
  title: 'Accounting weekly',
  path: '/reports/accounting',
  icon: Icons.account_balance_outlined,
  paged: true,
  search: true,
  emptyTitle: 'Nothing to fix',
  emptyMessage: 'No job, invoice or payment has a problem in these dates.',
  figures: (d, _) {
    final labels = _accountingIssues(d['invoice_days']);
    return [
      for (final e in labels.entries)
        () {
          final x = _fig(d, e.key);
          final n = ((x['count'] as num?) ?? 0).toInt();
          return OverviewFigure(
            key: ValueKey('accounting-${e.key}'),
            label: e.value,
            value: formatCount(n),
            note: _money(x['amount_minor']),
            tone: n > 0 ? Tone.caution : null,
          );
        }(),
    ];
  },
  columns: const [
    GridColumn('Issue', pinned: true, main: true),
    GridColumn('Date'),
    GridColumn('Job'),
    GridColumn('Client', main: true),
    GridColumn('Branch'),
    GridColumn('Detail'),
    GridColumn('Amount', numeric: true, main: true),
  ],
  cells: (r, f) => [
    _text(
      r['issue'] == 'invoice_overdue'
          ? 'Invoice unpaid, past due'
          : _accountingIssues(null)['${r['issue']}'] ?? r['issue'],
    ),
    GridCell(_day(r['date'], f)),
    _text(r['job_number']),
    _text(r['client']),
    _text(r['branch']),
    _text(r['detail']),
    _moneyCell(r['amount_minor']),
  ],
  open: _openJob,
);

final recruitingSpec = OpsSpec(
  key: 'recruiting',
  anchor: ValueKey('tour-reports-recruiting'),
  title: 'Recruiting',
  path: '/reports/recruiting',
  icon: Icons.person_search_outlined,
  emptyTitle: 'No applicants yet',
  emptyMessage:
      'Applicants and spend are kept under Human Resources › Recruiting.',
  figures: (d, _) {
    final t = ((d['totals'] as Map?) ?? const {}).cast<String, dynamic>();
    return [
      OverviewFigure(
        label: 'Spend',
        value: _money(t['spend_minor']),
        help: 'Every month these dates touch: HR books spend by the month.',
      ),
      OverviewFigure(
        label: 'Inquiries',
        value: formatCount((t['inquiries'] as num? ?? 0).toInt()),
      ),
      OverviewFigure(
        label: 'Hires',
        value: formatCount((t['hires'] as num? ?? 0).toInt()),
      ),
      OverviewFigure(
        label: 'Cost per hire',
        value: t['cost_per_hire_minor'] == null
            ? '—'
            : _money(t['cost_per_hire_minor']),
      ),
    ];
  },
  columns: const [
    GridColumn('Position', pinned: true),
    GridColumn(
      'Spend',
      numeric: true,
      main: true,
      help: 'Every month these dates touch: HR books spend by the month.',
    ),
    GridColumn('Inquiries', numeric: true, main: true),
    GridColumn('Avg response (h)', numeric: true),
    GridColumn('Not answered', numeric: true),
    GridColumn('Interviews', numeric: true),
    GridColumn('In person', numeric: true),
    GridColumn('Relevant', numeric: true),
    GridColumn('Hires', numeric: true, main: true),
    GridColumn('Cost per hire', numeric: true),
  ],
  cells: (r, _) => [
    _text(r['position']),
    _moneyCell(r['spend_minor']),
    _n(r['inquiries']),
    _hours(r['avg_hours_to_respond']),
    _n(r['not_responded']),
    _n(r['interviews']),
    _n(r['in_person']),
    _n(r['relevant']),
    _n(r['hires']),
    _moneyCell(r['cost_per_hire_minor']),
  ],
);

List<GridColumn> _checkColumns(OpsRow? rules) => [
  GridColumn(
    'No ETA call',
    numeric: true,
    help:
        'No call or text to the client in the ${rules?['eta_hours'] ?? 'N'} '
        "hours before the start. Calls from a technician's own phone are not "
        'seen.',
  ),
  GridColumn(
    'Closed late',
    numeric: true,
    help:
        'Closed more than ${rules?['close_hours'] ?? 'N'} hours after it ended.',
  ),
  const GridColumn(
    'No inspection report',
    numeric: true,
    help:
        'A job of a type listed in Settings › Lists › Job types that need an '
        'inspection report, with none made. Empty: no type is listed.',
  ),
  GridColumn(
    'Too few photos',
    numeric: true,
    help:
        'Fewer than ${rules?['min_photos'] ?? 'N'} photos. Photos kept '
        'outside Workiz and EzBizi are not seen.',
  ),
];

final techChecksSpec = OpsSpec(
  key: 'tech_checks',
  anchor: ValueKey('tour-reports-tech-checks'),
  title: 'AI checks',
  path: '/reports/tech-checks',
  icon: Icons.fact_check_outlined,
  drill: true,
  emptyTitle: 'No done jobs',
  emptyMessage: 'No job was done in these dates.',
  figures: (d, _) => [
    OverviewFigure(
      label: 'Done jobs',
      value: formatCount((d['jobs'] as num? ?? 0).toInt()),
    ),
    OverviewFigure(
      label: 'Technicians',
      value: formatCount((d['rows'] as List? ?? const []).length),
    ),
  ],
  columns: [
    const GridColumn('Technician', pinned: true),
    const GridColumn('Jobs', numeric: true, main: true),
    ..._checkColumns(null),
  ],
  cells: (r, _) => [
    GridCell('${r['tech'] ?? 'No technician'}'),
    _n(r['jobs']),
    _share(r['eta_missed'], r['jobs'], flag: true),
    _share(r['close_missed'], r['jobs'], flag: true),
    _share(r['inspection_missed'], r['jobs'], flag: true),
    _share(r['photos_missed'], r['jobs'], flag: true),
  ],
  footnote: (d) {
    final rules = (d['rules'] as Map?) ?? const {};
    return 'Click a technician to see the jobs behind each count. Rules '
        '(Settings › Lists › Technician check rules): an ETA call in the '
        '${rules['eta_hours'] ?? 'N'} h before the start, closed within '
        '${rules['close_hours'] ?? 'N'} h, at least '
        '${rules['min_photos'] ?? 'N'} photos.';
  },
);

final techsSpec = OpsSpec(
  key: 'techs',
  anchor: ValueKey('tour-reports-techs'),
  title: 'Tech report',
  path: '/reports/technicians',
  icon: Icons.engineering_outlined,
  emptyTitle: 'No done jobs',
  emptyMessage: 'No job was done in these dates.',
  figures: (d, _) => [
    OverviewFigure(
      label: 'Done jobs',
      value: formatCount((d['jobs'] as num? ?? 0).toInt()),
    ),
    OverviewFigure(
      label: 'Revenue',
      value: _money(
        (d['rows'] as List? ?? const []).fold<num>(
          0,
          (a, r) => a + (((r as Map)['revenue_minor'] as num?) ?? 0),
        ),
      ),
      help: 'A job with two technicians counts on both rows, and here.',
    ),
  ],
  columns: [
    const GridColumn('Technician', pinned: true),
    const GridColumn('Jobs', numeric: true, main: true),
    const GridColumn('Revenue', numeric: true, main: true),
    const GridColumn('Complaints', numeric: true),
    const GridColumn(
      'Redo',
      numeric: true,
      help: 'Jobs of the types in Settings › Lists › Redo job types.',
    ),
    ..._checkColumns(null),
  ],
  cells: (r, _) => [
    GridCell('${r['tech'] ?? 'No technician'}'),
    _n(r['jobs']),
    _moneyCell(r['revenue_minor']),
    _n(r['complaints']),
    _n(r['redo']),
    _share(r['eta_missed'], r['jobs'], flag: true),
    _share(r['close_missed'], r['jobs'], flag: true),
    _share(r['inspection_missed'], r['jobs'], flag: true),
    _share(r['photos_missed'], r['jobs'], flag: true),
  ],
  footnote: (_) => 'A job with two technicians counts on both rows.',
);

// ── AI checks: the jobs behind a count ──────────────────────────────

class _TechChecksPanel extends StatefulWidget {
  const _TechChecksPanel({
    required this.api,
    required this.span,
    required this.formats,
    required this.tech,
    required this.name,
    required this.row,
    required this.rules,
  });

  final ApiClient api;
  final DateSpan span;
  final DisplayFormats formats;
  final String tech;
  final String name;
  final OpsRow row;
  final OpsRow rules;

  @override
  State<_TechChecksPanel> createState() => _TechChecksPanelState();
}

class _TechChecksPanelState extends State<_TechChecksPanel> {
  static const _checks = [
    ('eta', 'No ETA call', 'eta_missed'),
    ('close', 'Closed late', 'close_missed'),
    ('inspection', 'No inspection report', 'inspection_missed'),
    ('photos', 'Too few photos', 'photos_missed'),
  ];

  String _check = 'eta';
  List<OpsRow>? _jobs;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _jobs = null);
    final r = await widget.api.get('/reports/tech-checks', {
      'from': formatCalendarDay(widget.span.from),
      'to': formatCalendarDay(widget.span.to),
      'tech': widget.tech,
      'check': _check,
    });
    if (!mounted) return;
    setState(() {
      if (!r.ok) {
        _error = r.message;
        return;
      }
      _error = null;
      _jobs = [
        for (final j in ((r.data as Map?)?['rows'] as List? ?? const []))
          (j as Map).cast<String, dynamic>(),
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final jobs = _jobs;
    return SidePanel(
      key: const ValueKey('tech-checks-panel'),
      title: widget.name,
      subtitle: 'The jobs behind each count',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final (key, label, field) in _checks)
                if (widget.row[field] != null)
                  ChoiceChip(
                    key: ValueKey('tech-check-$key'),
                    showCheckmark: false,
                    label: Text(
                      '$label · ${formatCount((widget.row[field] as num).toInt())}',
                    ),
                    selected: _check == key,
                    onSelected: (_) {
                      _check = key;
                      _load();
                    },
                  ),
            ],
          ),
          const SizedBox(height: 12),
          if (_error != null) ErrorNote(_error!),
          if (jobs == null && _error == null)
            const Center(child: CircularProgressIndicator())
          else if (jobs != null && jobs.isEmpty)
            const Text('No job missed this check.')
          else if (jobs != null)
            for (final j in jobs)
              Card(
                key: ValueKey('tech-check-job-${j['job_id']}'),
                child: ListTile(
                  title: Text([?j['number'], ?j['client']].join(' · ')),
                  subtitle: Text(
                    [
                      ?j['job_type'],
                      'start ${_when(j['start'], widget.formats)}',
                      if (j['closed_at'] != null)
                        'closed ${_when(j['closed_at'], widget.formats)}',
                      j['photos'] == null
                          ? 'photos not read'
                          : '${j['photos']} photos',
                    ].join(' · '),
                    style: theme.textTheme.bodySmall,
                  ),
                  trailing: const Icon(Icons.open_in_new, size: 18),
                  onTap: j['job_id'] == null
                      ? null
                      : () {
                          Navigator.of(context).maybePop();
                          _openJob(context, j);
                        },
                ),
              ),
        ],
      ),
    );
  }
}
