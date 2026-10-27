import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../widgets/error_note.dart';

/// One run of one saved report (spec 2026-09-10).
///
/// ── Two surfaces ─────────────────────────────────────────────────
///
/// Wide: the real table. Narrow: the count first, then each row as a card —
/// UI/UX's option 5, on the reasoning that the question a phone asks is "how
/// many" more often than "which ones", and that a row should be readable
/// without scrolling sideways.
///
/// Sizing comes from [LayoutBuilder] rather than MediaQuery so this is correct
/// inside a split pane and testable at a given width.
///
/// ── Three of the seven states live here ─────────────────────────
///
/// Refused, empty results, results, and results-capped. Refused and empty are
/// deliberately different widgets: refused means *you may not*, empty means
/// *there is nothing, for you*.
///
/// The empty sentence matters more than it looks. The server returns zero rows
/// identically whether the franchise is genuinely empty or merely outside this
/// person's reach — it cannot tell them apart and should not try — so this
/// sentence is the only thing that can decline to let the reader conclude
/// which. It is UI/UX's wording, used verbatim.
class ReportRunView extends StatefulWidget {
  const ReportRunView({
    super.key,
    required this.api,
    required this.reportId,
    required this.name,
  });

  final ApiClient api;
  final String reportId;
  final String name;

  static const double tableAbove = 900;

  /// Verbatim from UI/UX (`89398365`). Do not improve at build time.
  static const String foundNothingHeading = 'This report found nothing.';
  static const String foundNothingBody =
      'No rows matched, for the records you can see. Somebody with a wider '
      'reach may get results from the same report.';

  @override
  State<ReportRunView> createState() => _ReportRunViewState();
}

class _ReportRunViewState extends State<ReportRunView> {
  ReportRun? _run;
  String? _error;
  bool _loading = true;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _run0();
  }

  Future<void> _run0() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/reports/${widget.reportId}/run',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _run = ReportRun.fromJson(
        (response.body['data'] as Map).cast<String, dynamic>(),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_error!));
    }

    final run = _run!;

    if (run.rows.isEmpty) {
      return _foundNothing(context);
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= ReportRunView.tableAbove;
        final query = _query.trim().toLowerCase();
        final rows = query.isEmpty
            ? run.rows
            : [
                for (final row in run.rows)
                  if (run.columns.any(
                    (column) => _cell(
                      row[column.key],
                      column.type,
                    ).toLowerCase().contains(query),
                  ))
                    row,
              ];

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              alignment: WrapAlignment.spaceBetween,
              children: [
                // The count leads, because "how many" is the question it is
                // usually asked; on a computer it was missing altogether.
                Text(
                  run.count == 1 ? '1 row' : '${run.count} rows',
                  style: wide
                      ? Theme.of(context).textTheme.titleMedium
                      : Theme.of(context).textTheme.headlineSmall,
                ),
                // Standard field height, as tall as Leads search (Jose, 2026-10-27).
                SizedBox(
                  width: 240,
                  child: TextField(
                    key: const Key('report-run-search'),
                    textAlignVertical: TextAlignVertical.center,
                    decoration: const InputDecoration(
                      isDense: true,
                      prefixIconConstraints: BoxConstraints(minWidth: 36),
                      prefixIcon: Icon(Icons.search, size: 18),
                      hintText: 'Find a row',
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (v) => setState(() => _query = v),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (run.capped) ...[_capLine(context), const SizedBox(height: 12)],
            Expanded(
              child: rows.isEmpty
                  ? Text(
                      'No rows match "${_query.trim()}".',
                      style: Theme.of(context).textTheme.bodyMedium,
                    )
                  : wide
                  ? _table(context, run, rows)
                  : _cards(context, run, rows),
            ),
          ],
        );
      },
    );
  }

  /// Only reached when there are rows — see [build]. "The first 5,000" of
  /// nothing is not a truthful sentence, and a line under an empty table reads
  /// as a fault.
  Widget _capLine(BuildContext context) {
    final theme = Theme.of(context);

    return Text(
      'Showing the first 5,000 rows. This report matched more.',
      // Muted, never colorScheme.error. Nothing failed: the reader did nothing
      // wrong and the report worked. Dressing an advisory as a failure spends
      // the credibility of the screens where red does mean stop.
      style: theme.textTheme.bodySmall,
    );
  }

  Widget _table(
    BuildContext context,
    ReportRun run,
    List<Map<String, dynamic>> rows,
  ) {
    return SingleChildScrollView(
      scrollDirection: Axis.vertical,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columns: [
            for (final column in run.columns)
              DataColumn(
                label: Text(column.label),
                numeric: column.type == 'integer' || column.type == 'money',
              ),
          ],
          rows: [
            for (final row in rows)
              DataRow(
                cells: [
                  for (final column in run.columns)
                    // `column.key` contains a dot — `jobs.title` — and is a
                    // FLAT key rather than a path. Splitting on it finds
                    // nothing and draws a blank table.
                    DataCell(Text(_cell(row[column.key], column.type))),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _cards(
    BuildContext context,
    ReportRun run,
    List<Map<String, dynamic>> rows,
  ) {
    final theme = Theme.of(context);

    return ListView.separated(
      itemCount: rows.length,
      separatorBuilder: (_, _) => const SizedBox(height: 12),
      itemBuilder: (context, i) {
        final row = rows[i];

        return Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final column in run.columns) ...[
                  Text(
                    column.label.toUpperCase(),
                    style: theme.textTheme.labelSmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    _cell(row[column.key], column.type),
                    style: theme.textTheme.bodyMedium,
                  ),
                  if (column != run.columns.last) const SizedBox(height: 12),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _foundNothing(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              ReportRunView.foundNothingHeading,
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Text(
              ReportRunView.foundNothingBody,
              style: theme.textTheme.bodyMedium,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }

  /// As the Builder's preview writes the same column: money is kept in
  /// cents, and the run printed "29320" where the preview said $293.20.
  String _cell(Object? value, String type) {
    if (value == null) return '';
    if (value is bool) return value ? 'Yes' : 'No';
    return switch (type) {
      'money' when value is num => formatMoney(value.round()),
      'datetime' || 'date' => '$value'.split('T').first,
      _ => value.toString(),
    };
  }
}

/// The response of `POST /reports/{id}/run`.
class ReportRun {
  const ReportRun({
    required this.columns,
    required this.rows,
    required this.capped,
    required this.count,
    required this.cap,
  });

  factory ReportRun.fromJson(Map<String, dynamic> json) {
    return ReportRun(
      columns: (json['columns'] as List<dynamic>? ?? const [])
          .cast<Map<String, dynamic>>()
          .map(ReportColumn.fromJson)
          .toList(),
      rows: (json['rows'] as List<dynamic>? ?? const [])
          .map((row) => (row as Map).cast<String, dynamic>())
          .toList(),
      // `capped` is its own boolean and is never inferred from the count: a
      // report with exactly `cap` real rows is not truncated, and that is a
      // bug nobody would ever reproduce.
      capped: json['capped'] as bool? ?? false,
      count: json['count'] as int? ?? 0,
      cap: json['cap'] as int? ?? 0,
    );
  }

  final List<ReportColumn> columns;
  final List<Map<String, dynamic>> rows;
  final bool capped;
  final int count;
  final int cap;
}

class ReportColumn {
  const ReportColumn({
    required this.key,
    required this.label,
    required this.type,
  });

  factory ReportColumn.fromJson(Map<String, dynamic> json) => ReportColumn(
    key: json['key'] as String,
    label: json['label'] as String? ?? '',
    type: json['type'] as String? ?? 'string',
  );

  final String key;
  final String label;
  final String type;
}
