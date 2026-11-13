import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/module_overview.dart';
import 'report_dates.dart';
import 'report_tab.dart';

/// Thumbtack lead spend per category and branch (Jose, 2026-10-13: "add the
/// Thumbtack financials as well"). Only leads EzBizi received: Thumbtack
/// cannot list the rest, so this is not the whole Thumbtack bill.
class ThumbtackReport extends ReportTab {
  const ThumbtackReport({
    super.key,
    required super.api,
    super.now,
    super.formats,
    super.span,
  });

  @override
  State<ThumbtackReport> createState() => _ThumbtackReportState();
}

class _ThumbtackReportState extends ReportTabState<ThumbtackReport> {
  String? _category;
  String? _branch;
  String? _state;

  @override
  String get endpoint => '/reports/thumbtack';

  @override
  Map<String, String?> get filterParams => {
    'category': _category,
    'branch': _branch,
    'state': _state,
  };

  @override
  Key get searchKey => const Key('thumbtack-report-search');
  @override
  String get searchHint => 'Find a category or branch';

  @override
  List<Object?> searchFields(Map<String, dynamic> r) => [
    r['category'] ?? 'No category',
    r['state'],
    r['branch'] ?? 'Unassigned',
  ];

  @override
  List<Widget> filters(Map<String, dynamic>? data) => [
    reportFilter(
      'Category',
      _category,
      {for (final c in (data?['categories'] as List? ?? const [])) '$c': '$c'},
      (v) => _category = v,
      const ValueKey('thumbtack-category'),
      load,
    ),
    reportFilter(
      'State',
      _state,
      {for (final s in (data?['states'] as List? ?? const [])) '$s': '$s'},
      (v) => _state = v,
      const ValueKey('thumbtack-state'),
      load,
    ),
    reportFilter(
      'Branch',
      _branch,
      {
        for (final b in (data?['branches'] as List? ?? const []))
          '${b['id']}': '${b['name']}',
        'none': 'Unassigned',
      },
      (v) => _branch = v,
      const ValueKey('thumbtack-branch'),
      load,
    ),
  ];

  @override
  Widget get emptyState => const EmptyState(
    key: ValueKey('thumbtack-empty'),
    icon: Icons.request_quote_outlined,
    title: 'No Thumbtack leads',
    message: 'None arrived in these dates.',
  );

  @override
  String get noMatchNoun => 'rows';

  static int _total(List<Map<String, dynamic>> rows, String key) =>
      rows.fold<int>(0, (sum, r) => sum + ((r[key] as num?) ?? 0).toInt());

  @override
  Widget figures(List<Map<String, dynamic>> rows) {
    final spend = _total(rows, 'spend_minor');
    final charged = _total(rows, 'charged');
    final booked = _total(rows, 'booked');
    return OverviewFigures(
      key: const ValueKey('thumbtack-totals'),
      figures: [
        OverviewFigure(label: 'Spend', value: reportMoney(spend)),
        OverviewFigure(
          label: 'Charged leads',
          value: reportCount(charged),
          note: '${reportCount(_total(rows, 'credited'))} refunded',
        ),
        OverviewFigure(
          label: 'Cost per lead',
          value: charged > 0 ? reportMoney(spend ~/ charged) : '—',
        ),
        OverviewFigure(
          label: 'Booked',
          value: reportCount(booked),
          note: booked > 0 ? '${reportMoney(spend ~/ booked)} each' : '',
        ),
      ],
    );
  }

  @override
  Widget table(
    List<Map<String, dynamic>> rows,
    List<Map<String, dynamic>> shown,
  ) {
    final spend = _total(rows, 'spend_minor');
    final charged = _total(rows, 'charged');
    final booked = _total(rows, 'booked');
    return ReportGrid(
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
        for (final r in shown)
          [
            GridCell('${r['category'] ?? 'No category'}'),
            r['state'] == null
                ? const GridCell.none()
                : GridCell('${r['state']}'),
            GridCell('${r['branch'] ?? 'Unassigned'}'),
            GridCell(reportCount(r['leads'])),
            GridCell(reportCount(r['credited'])),
            GridCell(reportMoney(r['spend_minor'])),
            r['cost_per_lead_minor'] == null
                ? const GridCell.none()
                : GridCell(reportMoney(r['cost_per_lead_minor'])),
            GridCell(reportCount(r['booked'])),
            r['cost_per_booked_minor'] == null
                ? const GridCell.none()
                : GridCell(reportMoney(r['cost_per_booked_minor'])),
          ],
      ],
      total: [
        const GridCell('Total'),
        const GridCell(''),
        const GridCell(''),
        GridCell(reportCount(_total(rows, 'leads'))),
        GridCell(reportCount(_total(rows, 'credited'))),
        GridCell(reportMoney(spend)),
        charged > 0
            ? GridCell(reportMoney(spend ~/ charged))
            : const GridCell.none(),
        GridCell(reportCount(booked)),
        booked > 0
            ? GridCell(reportMoney(spend ~/ booked))
            : const GridCell.none(),
      ],
    );
  }

  @override
  String get note =>
      'Only the Thumbtack leads EzBizi received are here: Thumbtack '
      'cannot list the others, so this is not the whole Thumbtack bill. '
      'Spend counts charged leads; refunded (credited) ones are left out. '
      'Booked means the lead was matched to a job.';
}
