import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../inventory_screen.dart';
import 'dashboard_pane.dart';

/// The Dashboard tab (task 14) — layout 6 of
/// `docs/previews/training-layouts.html` (Jose's approved pick): one row
/// per guide or test with a completion bar, and selecting a row opens a
/// PANE of the people behind it. Chosen over layout 5's people×training
/// matrix because a list is already one column at 375px and a matrix is
/// not — see the mockup's own "Dashboard — 5 or 6?" answer. No modal:
/// selection is a pane, drawn by [InventorySplitScaffold], the same 25/75
/// split Inventory and Fleet already use, collapsing to the whole body with
/// Back below `InventoryScreen.splitAbove`.
///
/// `GET /training/dashboard` (`DashboardController::index`) answers `{data:
/// [...], summary: {...}}`. Each row in `data` is one guide or one test:
/// `{id, title, kind, assigned, passed, in_progress, overdue, retaking}` —
/// `kind` (`'guide'` or `'test'`) was added so the pane's "Assign to more
/// people" control knows which of `guide_id`/`test_id` to post
/// (`DashboardController::row`'s own docblock) — carried straight through
/// as `widget.row['kind']`, never refetched.
///
/// `summary` — `{people_assigned, people_overdue, people_due_for_retaking}`
/// — is the one number on this screen that is NOT "count what is in front
/// of you": it names DISTINCT PEOPLE across every row, where the rows
/// themselves count assignments. [_SummaryStats] renders it straight off
/// the wire and never touches [_rows] — see that class's own docblock.
class TrainingDashboard extends StatefulWidget {
  const TrainingDashboard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrainingDashboard> createState() => _TrainingDashboardState();
}

class _TrainingDashboardState extends State<TrainingDashboard> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  Map<String, dynamic> _summary = const {};
  String? _selectedId;

  /// What the search field holds. The endpoint answers every row at once
  /// (no paging), so the filter runs here, over title and kind.
  String _query = '';

  List<Map<String, dynamic>> get _visibleRows {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return [
      for (final row in _rows)
        if ('${row['title'] ?? ''} ${row['kind'] ?? ''}'.toLowerCase().contains(q)) row,
    ];
  }

  @override
  void initState() {
    super.initState();
    _load();
    // A learner passing, failing or progressing, or something assigned:
    // the numbers re-read quietly; the open item is kept.
    _live = LiveChanges.of(widget.api).subscribe({'training'}, (_) {
      if (!_loading) return _load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the rows on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _rows.isEmpty;
        _error = null;
      });
    }

    final response = await widget.api.get('/training/dashboard');
    if (!mounted || (quiet && !response.ok)) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _rows = _mapList(response.data);
      final summary = response.body['summary'];
      _summary = summary is Map ? Map<String, dynamic>.from(summary) : const {};
    });
  }

  void _select(String id) => setState(() => _selectedId = id);
  void _clearPane() => setState(() => _selectedId = null);

  Map<String, dynamic>? get _selectedRow {
    final id = _selectedId;
    if (id == null) return null;
    for (final row in _rows) {
      if (row['id'] == id) return row;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    return InventorySplitScaffold(
      hasPane: _selectedId != null,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        key: const ValueKey('tour-training-dashboard-summary'),
        note: 'How training is going, guide by guide and test by test.',
        action: (_loading || _error != null)
            ? null
            : Wrap(
                spacing: 18,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  // As tall as the controls beside it (Jose, 2026-10-20).
                  SizedBox(
                    width: 240,
                    child: TextField(
                      key: const Key('training-dashboard-list-search'),
                      textAlignVertical: TextAlignVertical.center,
                      decoration: const InputDecoration(
                        isDense: true,
                        prefixIconConstraints: BoxConstraints(minWidth: 36),
                        prefixIcon: Icon(Icons.search, size: 18),
                        hintText: 'Find a guide or test',
                        border: OutlineInputBorder(),
                      ),
                      onChanged: (value) => setState(() => _query = value),
                    ),
                  ),
                  _SummaryStats(summary: _summary),
                ],
              ),
      ),
      list: _RowList(
        rows: _visibleRows,
        query: _query.trim(),
        selectedId: _selectedId,
        onSelect: _select,
      ),
      pane: ({required showBack}) {
        final row = _selectedRow;
        if (row == null) return const SizedBox.shrink();

        return DashboardPane(
          api: widget.api,
          session: widget.session,
          itemId: row['id'] as String,
          title: row['title'] as String? ?? '',
          row: row,
          showBack: showBack,
          onBack: _clearPane,
        );
      },
    );
  }
}

/// The organization-wide readout — three counts that name DISTINCT PEOPLE,
/// never a sum of the rows below.
///
/// This widget's constructor takes only [summary] — never the row list —
/// which is not just documentation: there is no `List<Map>` in scope here
/// for a future edit to reach for and sum by mistake. The three numbers
/// come straight off `summary.people_assigned`/`people_overdue`/
/// `people_due_for_retaking` (`DashboardController::index`'s own docblock,
/// "The summary counts PEOPLE, never assignments" — one person overdue on
/// three guides is 1, not 3). Proven in
/// `training_dashboard_tab_test.dart`'s "the summary is the distinct-people
/// count, never a sum of the per-row counts", and by the RED/GREEN swap in
/// the task-14 report: rendering `rows.fold(0, (n, r) => n + r['overdue'])`
/// here instead turns that test red.
class _SummaryStats extends StatelessWidget {
  const _SummaryStats({required this.summary});

  final Map<String, dynamic> summary;

  int get _assigned =>
      summary['people_assigned'] is num ? (summary['people_assigned'] as num).toInt() : 0;
  int get _overdue =>
      summary['people_overdue'] is num ? (summary['people_overdue'] as num).toInt() : 0;
  int get _retaking => summary['people_due_for_retaking'] is num
      ? (summary['people_due_for_retaking'] as num).toInt()
      : 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Wrap(
      spacing: 18,
      runSpacing: 6,
      children: [
        Text(
          _assigned == 1 ? '1 person assigned' : '$_assigned people assigned',
          style: theme.textTheme.titleSmall,
        ),
        Text(
          '$_overdue overdue',
          style: theme.textTheme.titleSmall?.copyWith(color: _overdue > 0 ? scheme.error : null),
        ),
        Text(
          '$_retaking due for retaking',
          style: theme.textTheme.titleSmall?.copyWith(color: _retaking > 0 ? scheme.error : null),
        ),
      ],
    );
  }
}

class _RowList extends StatelessWidget {
  const _RowList({
    required this.rows,
    required this.query,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> rows;
  final String query;
  final String? selectedId;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty && query.isNotEmpty) {
      return Center(child: Text('No guide or test matches "$query".'));
    }

    if (rows.isEmpty) {
      // Scope-filtered, so it says what showed up rather than what exists:
      // "nothing to watch YET" would tell a franchise manager the
      // organization has published none when it may have published plenty
      // they cannot see.
      return const Center(child: Text('Nothing to watch here — publish a guide or a test.'));
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      itemCount: rows.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final row = rows[i];
        final id = row['id'] as String?;
        return _Row(
          row: row,
          selected: id != null && id == selectedId,
          onTap: id == null ? null : () => onSelect(id),
        );
      },
    );
  }
}

/// One guide or test: a title, a completion bar (passed of assigned), and
/// the counts the mockup's own row draws ("9 assigned · 4 passed · 2
/// overdue"). `overdue`/`retaking` are dropped from the sentence when zero
/// — the mockup's own convention — but never merged into one clause with
/// each other, so a row that is both overdue AND retaking-heavy still
/// states both numbers rather than picking one to show.
class _Row extends StatelessWidget {
  const _Row({required this.row, required this.selected, required this.onTap});

  final Map<String, dynamic> row;
  final bool selected;
  final VoidCallback? onTap;

  int _of(String key) => row[key] is num ? (row[key] as num).toInt() : 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = row['title'] as String? ?? '';
    final assigned = _of('assigned');
    final passed = _of('passed');
    final overdue = _of('overdue');
    final retaking = _of('retaking');
    final fraction = assigned > 0 ? (passed / assigned).clamp(0.0, 1.0) : 0.0;

    final parts = <String>['$assigned assigned', '$passed passed'];
    if (overdue > 0) parts.add('$overdue overdue');
    if (retaking > 0) parts.add('$retaking due for retaking');

    return Card(
      child: ListTile(
        selected: selected,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: onTap,
        title: Text(title, style: theme.textTheme.titleMedium, overflow: TextOverflow.ellipsis),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(parts.join(' · '), style: theme.textTheme.bodyMedium),
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: fraction,
                  minHeight: 6,
                  backgroundColor: theme.colorScheme.surfaceContainerHighest,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

List<Map<String, dynamic>> _mapList(dynamic raw) {
  if (raw is! List) return const [];
  return [
    for (final entry in raw)
      if (entry is Map) Map<String, dynamic>.from(entry),
  ];
}
