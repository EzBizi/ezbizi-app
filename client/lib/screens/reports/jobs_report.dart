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

/// Every job created in the dates (Jose, 2026-11-11: "All created Jobs.
/// Filter by Source, Filter by Phone Number Dialed, Filter by Area. extra
/// column with workiz id and ezbizi id"). The server filters and counts the
/// whole range; the rows come a page at a time.
class JobsReport extends StatefulWidget {
  const JobsReport({
    super.key,
    required this.api,
    required this.span,
    this.formats = DisplayFormats.fallback,
    this.canExport = false,
  });

  /// A Download button over the table (reports.export; Merav, 2026-11-19).
  final bool canExport;

  /// The range from the Reports screen's shared date bar.
  final DateSpan span;

  final ApiClient api;
  final DisplayFormats formats;

  @override
  State<JobsReport> createState() => _JobsReportState();
}

class _JobsReportState extends State<JobsReport> {
  static const _page = 200;

  /// The server's largest page (5,000), for a file of every row.
  static const _filePage = 5000;

  Set<String> _sources = {};
  Set<String> _dialed = {};
  String? _area;
  String _query = '';
  Timer? _typing;
  Map<String, dynamic>? _data;
  final List<Map<String, dynamic>> _rows = [];
  String? _error;

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

  /// The search runs on the server, over the whole range, once typing pauses.
  void _search(String value) {
    _query = value.trim();
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 350), _load);
  }

  @override
  void didUpdateWidget(JobsReport old) {
    super.didUpdateWidget(old);
    if (widget.span != old.span) _load();
  }

  /// The first page afresh, or [more] the next one under it.
  Future<void> _load({bool more = false}) async {
    final response = await widget.api.get('/reports/jobs', {
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

  Map<String, Object> get _filters => {
    'from': formatCalendarDay(widget.span.from),
    'to': formatCalendarDay(widget.span.to),
    if (_sources.isNotEmpty) 'source[]': _sources.toList(),
    if (_dialed.isNotEmpty) 'dialed[]': _dialed.toList(),
    'area': ?_area,
    if (_query.isNotEmpty) 'q': _query,
  };

  /// Every row the filters match, not only the pages shown: a file of the
  /// first 200 would pass for the whole range. Null when a page failed.
  Future<List<List<Object?>>?> _everyRow() async {
    final rows = <String, Map<String, dynamic>>{};
    var offset = 0;
    int? count;
    while (count == null || offset < count) {
      final response = await widget.api.get('/reports/jobs', {
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
      // A job synced in between shifts the pages; keep each job once.
      for (final r in page) {
        final row = (r as Map).cast<String, dynamic>();
        rows['${row['workiz_id']}'] = row;
      }
    }
    return ReportGrid.sheetFor(_columns, [
      for (final r in rows.values) _cells(r),
    ]);
  }

  /// A filter's choices with how many jobs each has; 'none' is the jobs with none.
  Map<String, String> _choices(String key, String none, [String? name]) => {
    for (final c in (_data?[key] as List? ?? const []))
      '${c['value']}':
          '${c['value'] == 'none'
              ? none
              : name == null
              ? c['value']
              : _line(c['value'], c[name])} '
          '(${formatCount((c['count'] as num).toInt())})',
  };

  /// Source and Number dialed take several at once, searched as you type
  /// (Jose, 2026-11-12: "Multiselect for both numbers dialed and sources",
  /// "search for the sources and lines too"). A choice the dates no longer
  /// hold stays listed, so it can be seen and taken off.
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
          SearchOption(value: c.key, label: c.value, detail: c.key),
        for (final v in chosen)
          if (!choices.containsKey(v))
            SearchOption(value: v, label: _line(v, null), detail: v),
      ],
      onChanged: (picked) {
        setState(() => pick(picked));
        _load();
      },
    ),
  );

  static String _line(Object? number, Object? name) =>
      [_phone(number), ?name].join(' · ');

  /// Workiz keeps bare digits (7326748312): written as every number in the
  /// app is (Jose, 2026-11-16: "give format to numbers"). Display only; the
  /// search still reads the digits.
  static String _phone(Object? number) =>
      formatPhoneNumber(toE164('${number ?? ''}') ?? '${number ?? ''}');

  static const _grossHelp =
      "Each job's Workiz total: its line items after discounts, plus tax. "
      'Every job in the list counts, cancelled ones too. Not net: Workiz '
      'jobs carry no costs.';

  /// What "Without duplicates" left out, as the server counted it.
  static String _duplicatesHelp(Object? kinds) {
    final k = (kinds as Map?) ?? const {};
    int n(String kind) => ((k[kind] as num?) ?? 0).toInt();
    return 'Jobs created, less the two kinds of duplicate Workiz makes:\n'
        '• tagged PAID – ESTIMATED (Settings › Lists, as every "jobs '
        'created" figure leaves out): ${formatCount(n('tagged'))}\n'
        '• its \$0 copy of a finished job, status "done pending approval": '
        '${formatCount(n('copies'))}';
  }

  static const _columns = [
    GridColumn('EzBizi ID', pinned: true),
    GridColumn('Workiz ID'),
    GridColumn('Created'),
    GridColumn('Client'),
    GridColumn('Phone'),
    GridColumn('Branch'),
    GridColumn('Service area'),
    GridColumn('Source', main: true),
    GridColumn('Number dialed', main: true),
    GridColumn('Called at'),
    GridColumn('Job type'),
    GridColumn('Status'),
    GridColumn('Job total', numeric: true, main: true, help: _grossHelp),
    GridColumn('Created by'),
  ];

  /// One job's row, as the table shows it and as a file has it.
  List<GridCell> _cells(Map<String, dynamic> r) => [
    r['ezbizi_number'] == null && r['ezbizi_syncing'] == true
        ? const GridCell(
            'Syncing',
            muted: true,
            tooltip:
                'Just in from Workiz; EzBizi makes its copy '
                'within a minute or two.',
          )
        : _text(r['ezbizi_number']),
    GridCell(
      '${r['workiz_number'] ?? r['workiz_id']}',
      tooltip: 'Workiz job ${r['workiz_id']}',
    ),
    GridCell(_when(r['created_at'])),
    _text(r['client']),
    r['phone'] == null ? const GridCell.none() : GridCell(_phone(r['phone'])),
    _text(r['branch']),
    _text(r['service_area']),
    _text(r['source']),
    r['dialed'] == null
        ? const GridCell.none()
        : GridCell(_line(r['dialed'], r['dialed_name'])),
    r['called_at'] == null
        ? const GridCell.none()
        : GridCell(_when(r['called_at'])),
    _text(r['job_type']),
    _text(r['status']),
    GridCell(formatMoney((((r['total'] as num?) ?? 0) * 100).round())),
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data;
    final count = (data?['count'] as num?)?.toInt() ?? 0;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            reportSearch(
              key: const ValueKey('jobs-search'),
              hint: 'Search every column',
              onChanged: _search,
            ),
            _many(
              'Source',
              const ValueKey('jobs-source'),
              _sources,
              _choices('sources', 'No source'),
              (v) => _sources = v,
            ),
            _many(
              'Number dialed',
              const ValueKey('jobs-dialed'),
              _dialed,
              _choices('dialed', 'No call found', 'name'),
              (v) => _dialed = v,
            ),
            reportFilter(
              'Area',
              _area,
              _choices('areas', 'No area'),
              (v) => _area = v,
              const ValueKey('jobs-area'),
              _load,
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (data != null && count == 0)
          const EmptyState(
            key: ValueKey('jobs-empty'),
            icon: Icons.work_outline,
            title: 'No jobs',
            message: 'None were created in these dates.',
          )
        else if (data != null) ...[
          OverviewFigures(
            key: const ValueKey('jobs-totals'),
            figures: [
              OverviewFigure(
                label: 'Jobs created (all)',
                value: formatCount(count),
                help:
                    'Every job created in Workiz in these dates, '
                    'duplicates included.',
              ),
              // Jose, 2026-11-16: "another box that counts the number without
              // the duplicates ... its a workiz issue".
              OverviewFigure(
                key: const ValueKey('jobs-unique'),
                label: 'Without duplicates',
                value: formatCount(((data['unique'] as num?) ?? count).toInt()),
                help: _duplicatesHelp(data['duplicates']),
              ),
              // Jose: "specify what total means like gross, net etc".
              OverviewFigure(
                key: const ValueKey('jobs-gross'),
                label: 'Gross, incl. tax',
                value: formatMoney(
                  (((data['total'] as num?) ?? 0) * 100).round(),
                ),
                help: _grossHelp,
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (widget.canExport) ...[
            Align(
              alignment: Alignment.centerRight,
              child: TableDownload(
                name: TableFile.name('Jobs', widget.span),
                rows: _everyRow,
              ),
            ),
            const SizedBox(height: 8),
          ],
          ReportGrid(
            key: const ValueKey('jobs-table'),
            columns: _columns,
            rows: [for (final r in _rows) _cells(r)],
          ),
          if (_rows.length < count) ...[
            const SizedBox(height: 12),
            Center(
              child: OutlinedButton(
                key: const ValueKey('jobs-more'),
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
            data['calls_read'] == false
                ? 'The phone system could not be read, so Number dialed '
                      'shows only the calls Workiz tied to a job.'
                : 'Number dialed is the line the client called: the call '
                      'Workiz tied to the job, else the client\'s last call '
                      'in the week before it was booked. Called at is when '
                      'that call started.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}
