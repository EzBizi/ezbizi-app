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
  });

  /// The range from the Reports screen's shared date bar.
  final DateSpan span;

  final ApiClient api;
  final DisplayFormats formats;

  @override
  State<JobsReport> createState() => _JobsReportState();
}

class _JobsReportState extends State<JobsReport> {
  static const _page = 200;

  String? _source;
  String? _dialed;
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
      'from': formatCalendarDay(widget.span.from),
      'to': formatCalendarDay(widget.span.to),
      'source': ?_source,
      'dialed': ?_dialed,
      'area': ?_area,
      if (_query.isNotEmpty) 'q': _query,
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

  static String _line(Object? number, Object? name) =>
      [formatPhoneNumber('$number'), ?name].join(' · ');

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
              hint: 'Find a client, phone or job number',
              onChanged: _search,
            ),
            reportFilter(
              'Source',
              _source,
              _choices('sources', 'No source'),
              (v) => _source = v,
              const ValueKey('jobs-source'),
              _load,
            ),
            reportFilter(
              'Number dialed',
              _dialed,
              _choices('dialed', 'No call found', 'name'),
              (v) => _dialed = v,
              const ValueKey('jobs-dialed'),
              _load,
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
              OverviewFigure(label: 'Jobs created', value: formatCount(count)),
              OverviewFigure(
                label: 'Total',
                value: formatMoney(
                  (((data['total'] as num?) ?? 0) * 100).round(),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ReportGrid(
            key: const ValueKey('jobs-table'),
            columns: const [
              GridColumn('EzBizi ID', pinned: true),
              GridColumn('Workiz ID'),
              GridColumn('Created'),
              GridColumn('Client'),
              GridColumn('Phone'),
              GridColumn('Branch'),
              GridColumn('Service area'),
              GridColumn('Source', main: true),
              GridColumn('Number dialed', main: true),
              GridColumn('Job type'),
              GridColumn('Status'),
              GridColumn('Total', numeric: true, main: true),
              GridColumn('Created by'),
            ],
            rows: [
              for (final r in _rows)
                [
                  _text(r['ezbizi_number']),
                  GridCell(
                    '${r['workiz_number'] ?? r['workiz_id']}',
                    tooltip: 'Workiz job ${r['workiz_id']}',
                  ),
                  GridCell(_when(r['created_at'])),
                  _text(r['client']),
                  r['phone'] == null
                      ? const GridCell.none()
                      : GridCell(formatPhoneNumber('${r['phone']}')),
                  _text(r['branch']),
                  _text(r['service_area']),
                  _text(r['source']),
                  r['dialed'] == null
                      ? const GridCell.none()
                      : GridCell(_line(r['dialed'], r['dialed_name'])),
                  _text(r['job_type']),
                  _text(r['status']),
                  GridCell(
                    formatMoney((((r['total'] as num?) ?? 0) * 100).round()),
                  ),
                  _text(r['created_by']),
                ],
            ],
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
                      'in the week before it was booked.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}
