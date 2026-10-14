import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/module_overview.dart';
import '../../core/numbers.dart';
import '../../core/job_money.dart';
import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

/// Thumbtack lead spend per category and branch (Jose, 2026-10-13: "add the
/// Thumbtack financials as well"). Only leads EzBizi received: Thumbtack
/// cannot list the rest, so this is not the whole Thumbtack bill.
class ThumbtackReport extends StatefulWidget {
  const ThumbtackReport({
    super.key,
    required this.api,
    this.now,
    this.formats = DisplayFormats.fallback,
  });

  final ApiClient api;
  final DateTime Function()? now;
  final DisplayFormats formats;

  @override
  State<ThumbtackReport> createState() => _ThumbtackReportState();
}

class _ThumbtackReportState extends State<ThumbtackReport> {
  late DateSpan _span = DateSpan(
    DateTime(_today.year, _today.month - 1),
    DateTime(_today.year, _today.month, 0),
  );
  String? _category;
  String? _branch;
  String? _state;
  Map<String, dynamic>? _data;
  String? _error;

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final (from, to) = (_span.from, _span.to);
    final response = await widget.api.get('/reports/thumbtack', {
      'from': _day(from),
      'to': _day(to),
      'category': ?_category,
      'branch': ?_branch,
      'state': ?_state,
    });
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        _data = (response.data as Map).cast<String, dynamic>();
        _error = null;
      } else {
        _error = response.message;
      }
    });
  }

  /// Money as every Reports tab writes it (UI queue 87).
  static String _money(Object? minor) =>
      minor is num ? formatMoney(minor.round()) : '—';

  static String _count(Object? n) => n is num ? formatCount(n.round()) : '—';

  Widget _filter(
    String label,
    String? value,
    Map<String, String> options,
    ValueChanged<String?> pick,
    Key key,
  ) => DropdownMenu<String?>(
    key: key,
    enableFilter: true,
    requestFocusOnTap: true,
    label: Text(label),
    initialSelection: value,
    onSelected: (v) {
      pick(v);
      _load();
    },
    dropdownMenuEntries: [
      const DropdownMenuEntry(value: null, label: 'All'),
      for (final o in options.entries)
        DropdownMenuEntry(value: o.key, label: o.value),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final data = _data;
    final rows = [
      for (final r in (data?['rows'] as List? ?? const []))
        (r as Map).cast<String, dynamic>(),
    ];
    int total(String key) =>
        rows.fold<int>(0, (sum, r) => sum + ((r[key] as num?) ?? 0).toInt());
    final spend = total('spend_minor');
    final charged = total('charged');
    final booked = total('booked');

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
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            _filter(
              'Category',
              _category,
              {
                for (final c in (data?['categories'] as List? ?? const []))
                  '$c': '$c',
              },
              (v) => _category = v,
              const ValueKey('thumbtack-category'),
            ),
            _filter(
              'State',
              _state,
              {
                for (final s in (data?['states'] as List? ?? const []))
                  '$s': '$s',
              },
              (v) => _state = v,
              const ValueKey('thumbtack-state'),
            ),
            _filter(
              'Branch',
              _branch,
              {
                for (final b in (data?['branches'] as List? ?? const []))
                  '${b['id']}': '${b['name']}',
                'none': 'Unassigned',
              },
              (v) => _branch = v,
              const ValueKey('thumbtack-branch'),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (rows.isEmpty)
          const EmptyState(
            key: ValueKey('thumbtack-empty'),
            icon: Icons.request_quote_outlined,
            title: 'No Thumbtack leads',
            message: 'None arrived in these dates.',
          )
        else ...[
          // UI queue 87, desktop 1: the figures, then the one table.
          OverviewFigures(
            key: const ValueKey('thumbtack-totals'),
            figures: [
              OverviewFigure(label: 'Spend', value: _money(spend)),
              OverviewFigure(
                label: 'Charged leads',
                value: _count(charged),
                note: '${_count(total('credited'))} refunded',
              ),
              OverviewFigure(
                label: 'Cost per lead',
                value: charged > 0 ? _money(spend ~/ charged) : '—',
              ),
              OverviewFigure(
                label: 'Booked',
                value: _count(booked),
                note: booked > 0 ? '${_money(spend ~/ booked)} each' : '',
              ),
            ],
          ),
          const SizedBox(height: 12),
          ReportGrid(
            key: const ValueKey('thumbtack-table'),
            columns: const [
              GridColumn('Category', pinned: true),
              GridColumn('State'),
              GridColumn('Branch'),
              GridColumn('Leads', numeric: true, main: true),
              GridColumn('Refunded', numeric: true),
              GridColumn('Spend', numeric: true, main: true),
              GridColumn('Cost per lead', numeric: true),
              GridColumn('Booked', numeric: true, main: true),
              GridColumn('Cost per booked', numeric: true),
            ],
            rows: [
              for (final r in rows)
                [
                  GridCell('${r['category'] ?? 'No category'}'),
                  r['state'] == null
                      ? const GridCell.none()
                      : GridCell('${r['state']}'),
                  GridCell('${r['branch'] ?? 'Unassigned'}'),
                  GridCell(_count(r['leads'])),
                  GridCell(_count(r['credited'])),
                  GridCell(_money(r['spend_minor'])),
                  r['cost_per_lead_minor'] == null
                      ? const GridCell.none()
                      : GridCell(_money(r['cost_per_lead_minor'])),
                  GridCell(_count(r['booked'])),
                  r['cost_per_booked_minor'] == null
                      ? const GridCell.none()
                      : GridCell(_money(r['cost_per_booked_minor'])),
                ],
            ],
            total: [
              const GridCell('Total'),
              const GridCell(''),
              const GridCell(''),
              GridCell(_count(total('leads'))),
              GridCell(_count(total('credited'))),
              GridCell(_money(spend)),
              charged > 0
                  ? GridCell(_money(spend ~/ charged))
                  : const GridCell.none(),
              GridCell(_count(booked)),
              booked > 0
                  ? GridCell(_money(spend ~/ booked))
                  : const GridCell.none(),
            ],
          ),
        ],
        const SizedBox(height: 12),
        Text(
          'Only the Thumbtack leads EzBizi received are here: Thumbtack '
          'cannot list the others, so this is not the whole Thumbtack bill. '
          'Spend counts charged leads; refunded (credited) ones are left out. '
          'Booked means the lead was matched to a job.',
          style: muted,
        ),
      ],
    );
  }
}
