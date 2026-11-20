import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/phone_numbers.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/module_overview.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/table_download.dart';
import 'report_dates.dart';

/// Reports › Cancelled jobs and Pending jobs (Shoval, 2026-11-18). EzBizi's
/// own jobs, in the statuses the business lists as cancelled or pending
/// (Settings › Lists). Cancelled: the jobs created in the dates that are
/// cancelled now. Pending: every job pending now, oldest first. The server
/// filters and counts the whole set; the rows come a page at a time.
class JobStatusReport extends StatefulWidget {
  const JobStatusReport({
    super.key,
    required this.api,
    required this.span,
    required this.pending,
    this.formats = DisplayFormats.fallback,
    this.canExport = false,
  });

  final ApiClient api;

  /// The range from the Reports screen's shared date bar; Pending ignores it.
  final DateSpan span;

  /// Pending jobs, else Cancelled jobs.
  final bool pending;
  final DisplayFormats formats;

  /// A Download button over the table (reports.export).
  final bool canExport;

  @override
  State<JobStatusReport> createState() => _JobStatusReportState();
}

class _JobStatusReportState extends State<JobStatusReport> {
  static const _page = 200;
  static const _filePage = 5000;

  Set<String> _reasons = {};
  Set<String> _sources = {};
  Set<String> _techs = {};
  String _query = '';
  Timer? _typing;
  Map<String, dynamic>? _data;
  final List<Map<String, dynamic>> _rows = [];
  String? _error;

  String get _kind => widget.pending ? 'pending' : 'cancelled';

  /// Written out, so the route guard can read them.
  String get _path =>
      widget.pending ? '/reports/pending' : '/reports/cancelled';
  String get _title => widget.pending ? 'Pending jobs' : 'Cancelled jobs';

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
  void didUpdateWidget(JobStatusReport old) {
    super.didUpdateWidget(old);
    if (!widget.pending && widget.span != old.span) _load();
  }

  void _search(String value) {
    _query = value.trim();
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 350), _load);
  }

  Map<String, Object> get _filters => {
    if (!widget.pending) 'from': formatCalendarDay(widget.span.from),
    if (!widget.pending) 'to': formatCalendarDay(widget.span.to),
    if (_reasons.isNotEmpty) 'reason[]': _reasons.toList(),
    if (_sources.isNotEmpty) 'source[]': _sources.toList(),
    if (_techs.isNotEmpty) 'tech[]': _techs.toList(),
    if (_query.isNotEmpty) 'q': _query,
  };

  Future<void> _load({bool more = false}) async {
    final response = await widget.api.get(_path, {
      ..._filters,
      'offset': '${more ? _rows.length : 0}',
      'limit': '$_page',
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

  /// Every row the filters match, not only the pages shown.
  Future<List<List<Object?>>?> _everyRow() async {
    final rows = <String, Map<String, dynamic>>{};
    var offset = 0;
    int? count;
    while (count == null || offset < count) {
      final response = await widget.api.get(_path, {
        ..._filters,
        'offset': '$offset',
        'limit': '$_filePage',
      });
      if (!response.ok) return null;
      final data = (response.data as Map).cast<String, dynamic>();
      count = (data['count'] as num).toInt();
      final page = data['rows'] as List? ?? const [];
      if (page.isEmpty) break;
      offset += page.length;
      for (final r in page) {
        final row = (r as Map).cast<String, dynamic>();
        rows['${row['job_id']}'] = row;
      }
    }
    return ReportGrid.sheetFor(_columns, [
      for (final r in rows.values) _cells(r),
    ]);
  }

  Map<String, String> _choices(String key, String none) => {
    for (final c in (_data?[key] as List? ?? const []))
      '${c['value']}':
          '${c['value'] == 'none' ? none : c['value']} '
          '(${formatCount((c['count'] as num).toInt())})',
  };

  Widget _many(
    String label,
    Key key,
    Set<String> chosen,
    Map<String, String> choices,
    ValueChanged<Set<String>> pick,
  ) => SizedBox(
    width: 260,
    child: SearchMultiPickerField<String>(
      key: key,
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
        setState(() => pick(picked));
        _load();
      },
    ),
  );

  List<GridColumn> get _columns => [
    const GridColumn('EzBizi ID', pinned: true),
    const GridColumn('Workiz ID'),
    const GridColumn('Created'),
    if (widget.pending) const GridColumn('Age (days)', numeric: true),
    GridColumn(widget.pending ? 'Pending since' : 'Cancelled at'),
    const GridColumn('Client'),
    const GridColumn('Phone'),
    const GridColumn('Branch'),
    const GridColumn('Source', main: true),
    const GridColumn('Job type'),
    if (widget.pending) const GridColumn('Status'),
    GridColumn(widget.pending ? 'Sub-status' : 'Reason', main: true),
    const GridColumn('Technician'),
    const GridColumn('Value', numeric: true, main: true),
    const GridColumn('Created by'),
  ];

  List<GridCell> _cells(Map<String, dynamic> r) => [
    _text(r['number']),
    _text(r['workiz_number']),
    GridCell(_when(r['created_at'])),
    if (widget.pending) GridCell(formatCount((r['age_days'] as num).toInt())),
    GridCell(_when(r['since'])),
    _text(r['client']),
    r['phone'] == null
        ? const GridCell.none()
        : GridCell(
            formatPhoneNumber(toE164('${r['phone']}') ?? '${r['phone']}'),
          ),
    _text(r['branch']),
    _text(r['source']),
    _text(r['job_type']),
    if (widget.pending) _text(r['status']),
    _text(r['reason']),
    _text((r['techs'] as List? ?? const []).join(', ')),
    GridCell(formatMoney(((r['value_minor'] as num?) ?? 0).round())),
    _text(r['created_by']),
  ];

  GridCell _text(Object? v) =>
      v == null || '$v'.isEmpty ? const GridCell.none() : GridCell('$v');

  String _when(Object? stamp) {
    final at = DateTime.tryParse('${stamp ?? ''}'.replaceFirst(' ', 'T'));
    return at == null
        ? '—'
        : '${widget.formats.formatDate(at)} '
              '${widget.formats.formatTime(at.hour, at.minute)}';
  }

  List<OverviewFigure> _figures(Map<String, dynamic> data, int count) {
    final value = formatMoney(((data['value_minor'] as num?) ?? 0).round());
    if (widget.pending) {
      final oldest = _rows.isEmpty ? null : _rows.first['age_days'] as num?;
      return [
        OverviewFigure(label: 'Pending now', value: formatCount(count)),
        OverviewFigure(
          label: 'Oldest',
          value: oldest == null ? '—' : '${formatCount(oldest.toInt())} days',
        ),
        OverviewFigure(label: 'Value', value: value),
      ];
    }
    final all = ((data['all'] as num?) ?? 0).toInt();
    final created = ((data['created'] as num?) ?? 0).toInt();
    return [
      OverviewFigure(
        key: const ValueKey('cancelled-count'),
        label: 'Cancelled',
        value: formatCount(count),
        help: 'Jobs created in these dates that are cancelled now.',
      ),
      OverviewFigure(
        key: const ValueKey('cancelled-rate'),
        label: 'Of jobs created',
        value: created == 0 ? '—' : '${(all * 100 / created).round()}%',
        note: '${formatCount(all)} of ${formatCount(created)}',
        help:
            'Every cancelled job against every job created in these dates; '
            'the filters do not change it.',
      ),
      OverviewFigure(label: 'Value', value: value),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data;
    final count = (data?['count'] as num?)?.toInt() ?? 0;
    final statuses = ((data?['statuses'] as List?) ?? const []).join(', ');

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            reportSearch(
              key: widget.pending
                  ? const ValueKey('pending-search')
                  : const ValueKey('cancelled-search'),
              hint: 'Search every column',
              onChanged: _search,
            ),
            _many(
              widget.pending ? 'Sub-status' : 'Reason',
              widget.pending
                  ? const ValueKey('pending-reason')
                  : const ValueKey('cancelled-reason'),
              _reasons,
              _choices('reasons', 'None given'),
              (v) => _reasons = v,
            ),
            _many(
              'Source',
              ValueKey('$_kind-source'),
              _sources,
              _choices('sources', 'No source'),
              (v) => _sources = v,
            ),
            _many(
              'Technician',
              ValueKey('$_kind-tech'),
              _techs,
              _choices('techs', 'No technician'),
              (v) => _techs = v,
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (data != null && count == 0)
          EmptyState(
            key: widget.pending
                ? const ValueKey('pending-empty')
                : const ValueKey('cancelled-empty'),
            icon: widget.pending
                ? Icons.pending_actions_outlined
                : Icons.event_busy_outlined,
            title: widget.pending ? 'Nothing pending' : 'No cancelled jobs',
            message: widget.pending
                ? 'No job is in a pending status ($statuses).'
                : 'No job created in these dates is cancelled ($statuses).',
          )
        else if (data != null) ...[
          OverviewFigures(
            key: widget.pending
                ? const ValueKey('pending-totals')
                : const ValueKey('cancelled-totals'),
            figures: _figures(data, count),
          ),
          const SizedBox(height: 12),
          if (widget.canExport) ...[
            Align(
              alignment: Alignment.centerRight,
              child: TableDownload(
                name: widget.pending
                    ? '$_title ${formatCalendarDay(DateTime.now())}'
                    : TableFile.name(_title, widget.span),
                rows: _everyRow,
              ),
            ),
            const SizedBox(height: 8),
          ],
          ReportGrid(
            key: widget.pending
                ? const ValueKey('pending-table')
                : const ValueKey('cancelled-table'),
            columns: _columns,
            rows: [for (final r in _rows) _cells(r)],
          ),
          if (_rows.length < count) ...[
            const SizedBox(height: 12),
            Center(
              child: OutlinedButton(
                key: ValueKey('$_kind-more'),
                onPressed: () => _load(more: true),
                child: Text(
                  'Show ${formatCount((count - _rows.length).clamp(0, _page))} more '
                  'of ${formatCount(count - _rows.length)}',
                ),
              ),
            ),
          ],
          const SizedBox(height: 12),
          Text(
            widget.pending
                ? 'Every job pending now, oldest first; the dates do not apply. '
                      'Pending means the statuses in Settings › Lists › '
                      'Pending job statuses: $statuses.'
                : 'Cancelled means the statuses in Settings › Lists › '
                      'Cancelled job statuses: $statuses. Created is when the '
                      'job was booked.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}
