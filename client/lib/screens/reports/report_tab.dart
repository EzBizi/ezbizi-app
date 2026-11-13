import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

/// A Reports tab that reads one endpoint for a date range and some filters,
/// then draws its figures and one table (UI queue 87): the Thumbtack and
/// Facebook ad spend tabs. What they show is theirs; the dates, the search,
/// the loading, empty and error states are drawn here once.
abstract class ReportTab extends StatefulWidget {
  const ReportTab({
    super.key,
    required this.api,
    this.now,
    this.formats = DisplayFormats.fallback,
    this.span,
  });

  /// The range from the Reports screen's shared date bar (review item 14);
  /// with it, this tab draws no date bar of its own.
  final DateSpan? span;

  final ApiClient api;
  final DateTime Function()? now;
  final DisplayFormats formats;
}

/// Money as every Reports tab writes it (UI queue 87).
String reportMoney(Object? minor) =>
    minor is num ? formatMoney(minor.round()) : '—';

String reportCount(Object? n) => n is num ? formatCount(n.round()) : '—';

abstract class ReportTabState<W extends ReportTab> extends State<W> {
  // ── What each tab says ─────────────────────────────────────────────────

  String get endpoint;

  /// The filters sent with the dates, in order; a null one is left out.
  Map<String, String?> get filterParams;

  Key get searchKey;
  String get searchHint;

  /// What the search box looks in, per row.
  List<Object?> searchFields(Map<String, dynamic> row);

  List<Widget> filters(Map<String, dynamic>? data);
  Widget get emptyState;

  /// "rows", "campaigns": in "No rows match …".
  String get noMatchNoun;
  Widget figures(List<Map<String, dynamic>> rows);
  Widget table(
    List<Map<String, dynamic>> rows,
    List<Map<String, dynamic>> shown,
  );
  String get note;

  /// Drawn under the note.
  List<Widget> trailing(Map<String, dynamic>? data, TextStyle? muted) =>
      const [];

  // ── State ──────────────────────────────────────────────────────────────

  late DateSpan _span =
      widget.span ??
      DateSpan(
        DateTime(_today.year, _today.month - 1),
        DateTime(_today.year, _today.month, 0),
      );

  @override
  void didUpdateWidget(W oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.span != null && widget.span != oldWidget.span) {
      setState(() => _span = widget.span!);
      load();
    }
  }

  Map<String, dynamic>? _data;
  String? error;
  String _query = '';

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    final (from, to) = (_span.from, _span.to);
    final response = await widget.api.get(endpoint, {
      'from': formatCalendarDay(from),
      'to': formatCalendarDay(to),
      for (final e in filterParams.entries)
        if (e.value != null) e.key: e.value!,
    });
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        _data = (response.data as Map).cast<String, dynamic>();
        error = null;
      } else {
        error = response.message;
      }
    });
  }

  // ── Drawing ────────────────────────────────────────────────────────────

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
    // The figures and the Total row stay the whole report's; the search
    // narrows the rows.
    final query = _query.trim().toLowerCase();
    final shown = query.isEmpty
        ? rows
        : [
            for (final r in rows)
              if (searchFields(
                r,
              ).any((v) => '${v ?? ''}'.toLowerCase().contains(query)))
                r,
          ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (widget.span == null) ...[
          ReportDates(
            key: const ValueKey('tour-report-dates'),
            initial: _span,
            today: _today,
            formats: widget.formats,
            onChanged: (span) {
              setState(() => _span = span);
              load();
            },
          ),
          const SizedBox(height: 12),
        ],
        Wrap(
          spacing: 12,
          runSpacing: 12,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            reportSearch(
              key: searchKey,
              hint: searchHint,
              onChanged: (v) => setState(() => _query = v),
            ),
            ...filters(data),
          ],
        ),
        const SizedBox(height: 12),
        if (error != null) ErrorNote(error!),
        if (data == null && error == null)
          const Center(child: CircularProgressIndicator())
        else if (rows.isEmpty)
          emptyState
        else ...[
          // UI queue 87, desktop 1: the figures, then the one table.
          figures(rows),
          const SizedBox(height: 12),
          if (shown.isEmpty)
            Text(
              'No $noMatchNoun match "${_query.trim()}".',
              style: theme.textTheme.bodyMedium,
            )
          else
            table(rows, shown),
        ],
        const SizedBox(height: 12),
        Text(note, style: muted),
        ...trailing(data, muted),
      ],
    );
  }
}
