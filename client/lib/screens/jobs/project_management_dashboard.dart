import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/branch_options.dart';
import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/side_panel.dart';
import 'job_detail_panel.dart';
import 'job_labels.dart';
import 'pm_stages.dart';
import 'project_management_reports.dart' show pmAverageCost, pmYmd;

/// Project Management › Dashboard (design 141, option 1; phone 4).
///
/// Where every job stands now, from `jobs/project-management/overview`:
/// service area chips, search and the flag chips on top; eight tiles in
/// pipeline order, each opening Jobs on its stage under the same filters;
/// then waiting review by location and reason, paid jobs waiting to start as
/// countdown bars, and jobs per team. Money only when the server sends it
/// (Jobs Financials). A stage `jobs.pm_stages` leaves unmapped says so, with
/// the way to Settings.
///
/// The overview takes no dates, so there is no range control: its numbers
/// are the jobs as they stand.
class ProjectManagementDashboard extends StatefulWidget {
  const ProjectManagementDashboard({
    super.key,
    required this.api,
    required this.session,
    this.areas = const [],
    this.stoppedDays,
    this.labels,
    this.generation = 0,
    this.onOpenJobs,
    this.onOpenSettings,
  });

  final ApiClient api;
  final Session session;

  /// The service areas in reach, for the chips. Empty: no chips.
  final List<PmArea> areas;

  /// `jobs.pm_stopped_days`, for the Stopped tile's words. Null: not read.
  final int? stoppedDays;

  final JobLabels? labels;

  /// Bumped by the screen when Settings changes: count again.
  final int generation;

  /// A tile's tap: Jobs on that stage, under these filters.
  final void Function(String stage, PmFilters filters)? onOpenJobs;
  final VoidCallback? onOpenSettings;

  @override
  State<ProjectManagementDashboard> createState() =>
      _ProjectManagementDashboardState();
}

/// The eight tiles in pipeline order: the key, its name, and the stage a tap
/// opens (null: it opens nothing).
const pmDashboardTiles = [
  ('waiting_review', 'Waiting review', 'waiting_review'),
  ('in_progress', 'In progress', 'in_progress'),
  ('ready_to_schedule', 'Ready to schedule', 'ready_to_schedule'),
  ('scheduled', 'Scheduled', 'scheduled'),
  ('done_waiting_payment', 'Done, waiting payment', 'done_waiting_payment'),
  ('pipeline', 'Pipeline (open projects)', 'in_progress'),
  ('avg_production_cost', 'Avg production cost', null),
  ('stopped_payments', 'Stopped payments', 'done_waiting_payment'),
];

class _ProjectManagementDashboardState
    extends State<ProjectManagementDashboard> {
  static const _phoneBelow = 600.0;

  /// Every tile the same size, whatever it says.
  static const _tileHeight = 132.0;

  PmFilters _filters = const PmFilters();
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  int _request = 0;

  /// Financials' average production cost, while the overview's is null.
  int? _avgCost;

  final _search = TextEditingController();
  Timer? _typing;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ProjectManagementDashboard old) {
    super.didUpdateWidget(old);
    if (old.generation != widget.generation) _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String text) {
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 400), () {
      _refilter(_filters.copyWith(q: text));
    });
  }

  void _refilter(PmFilters next) {
    if (next == _filters) return;
    setState(() => _filters = next);
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });
    final response = await widget.api.get(
      '/jobs/project-management/overview',
      _filters.query(),
    );
    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data;
      _data = data is Map ? data.cast<String, dynamic>() : const {};
    });
    _loadAvgCost(request);
  }

  /// The overview sends the average production cost as null until it has
  /// its own; Financials has it per month, place and team, and this person
  /// may read it with financials.bases.view.
  Future<void> _loadAvgCost(int request) async {
    final data = _data;
    if (data == null ||
        !data.containsKey('avg_production_cost_minor') ||
        data['avg_production_cost_minor'] != null ||
        !_me.can('financials.bases.view')) {
      return;
    }
    final now = DateTime.now();
    final response = await widget.api.get('/financials/production-cost', {
      'from': pmYmd(DateTime(now.year, now.month - 2, 1)),
      'to': pmYmd(now),
    });
    if (!mounted || request != _request || !response.ok) return;
    final rows = response.data;
    setState(() => _avgCost = rows is List ? pmAverageCost(rows) : null);
  }

  Map<String, dynamic> _map(String key) {
    final v = _data?[key];
    return v is Map ? v.cast<String, dynamic>() : const {};
  }

  int? _int(Object? v) => v is int ? v : null;

  List<String> get _mapped => [
    for (final m in (_data?['mapped'] as List?) ?? const []) '$m',
  ];

  String _franchise(String id) {
    // "Company · Branch" (branch_options.dart): two companies' "Main".
    for (final f in _me.franchises) {
      if (f.id == id) return branchLabelOf(_me, id, f.name);
    }
    return '';
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < _phoneBelow;
        final pad = phone ? 16.0 : 24.0;
        return ListView(
          key: const ValueKey('pm-dash'),
          padding: EdgeInsets.fromLTRB(pad, 12, pad, 24),
          children: [
            _controls(context, phone: phone),
            const SizedBox(height: 12),
            AsyncBody(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _data == null
                  ? const SizedBox.shrink()
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _tiles(context, constraints.maxWidth - pad * 2, phone),
                        const SizedBox(height: 12),
                        _boxes(context, phone: phone),
                      ],
                    ),
            ),
          ],
        );
      },
    );
  }

  Widget _controls(BuildContext context, {required bool phone}) {
    final search = SizedBox(
      width: phone ? double.infinity : 300,
      child: TextField(
        key: const ValueKey('pm-dash-search'),
        controller: _search,
        onChanged: _typed,
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Search jobs',
        ),
      ),
    );
    final areas = widget.areas.isEmpty
        ? null
        : PmAreaChips(
            key: const ValueKey('tour-pm-dash-areas'),
            areas: widget.areas,
            selected: _filters.areaId,
            franchiseName: _franchise,
            onSelected: (id) => _refilter(_filters.copyWith(areaId: id)),
          );
    final flags = PmFlagChips(
      key: const ValueKey('tour-pm-dash-flags'),
      filters: _filters,
      onChanged: _refilter,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (phone) ...[
          ?areas,
          if (areas != null) const SizedBox(height: 8),
          search,
        ] else
          Row(
            children: [
              Expanded(child: areas ?? const SizedBox.shrink()),
              const SizedBox(width: 12),
              search,
            ],
          ),
        const SizedBox(height: 8),
        flags,
      ],
    );
  }

  /// Four to a row on a wide screen, two on a phone; money tiles only when
  /// the server sends money.
  Widget _tiles(BuildContext context, double width, bool phone) {
    final perRow = phone ? 2 : 4;
    const gap = 10.0;
    final tileWidth = (width - gap * (perRow - 1)) / perRow;
    final data = _data!;

    return Wrap(
      key: const ValueKey('tour-pm-dash-tiles'),
      spacing: gap,
      runSpacing: gap,
      children: [
        for (final (key, label, stage) in pmDashboardTiles)
          if (switch (key) {
            'pipeline' => data.containsKey('pipeline_minor'),
            'avg_production_cost' => data.containsKey(
              'avg_production_cost_minor',
            ),
            _ => true,
          })
            SizedBox(
              width: tileWidth,
              height: _tileHeight,
              child: _tile(context, key, label, stage),
            ),
      ],
    );
  }

  Widget _tile(BuildContext context, String key, String label, String? stage) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final unmapped = pmStageKeys.contains(key) && !_mapped.contains(key);
    final (value, bar, sub, tone) = _tileBody(context, key);

    final open = stage == null || unmapped || widget.onOpenJobs == null
        ? null
        : () => widget.onOpenJobs!(
            stage,
            key == 'stopped_payments'
                ? _filters.copyWith(stopped: true)
                : _filters,
          );

    return Material(
      color: scheme.surface,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: ValueKey('pm-tile-$key'),
        onTap: open,
        child: Container(
          decoration: BoxDecoration(
            border: Border(
              left: BorderSide(
                color: tone == null
                    ? scheme.outlineVariant
                    : ToneColours.of(context, tone).ink,
                width: 4,
              ),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: scheme.onSurfaceVariant,
                  letterSpacing: 0.4,
                ),
              ),
              const SizedBox(height: 4),
              if (unmapped) ...[
                Text(
                  'Not set up',
                  key: ValueKey('pm-tile-$key-unmapped'),
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const Spacer(),
                if (widget.onOpenSettings != null)
                  TextButton(
                    key: ValueKey('pm-tile-$key-setup'),
                    onPressed: widget.onOpenSettings,
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(0, 32),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text('Set up in Settings'),
                  ),
              ] else ...[
                Text(
                  value,
                  key: ValueKey('pm-tile-$key-value'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                if (bar != null) ...[const SizedBox(height: 6), bar],
                const Spacer(),
                if (sub.isNotEmpty)
                  Text(
                    sub,
                    key: ValueKey('pm-tile-$key-sub'),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: muted,
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// A tile's number, its bar, its line underneath and its edge tone.
  (String, Widget?, String, Tone?) _tileBody(BuildContext context, String key) {
    final data = _data!;
    switch (key) {
      case 'waiting_review':
        final m = _map(key);
        final reasons = _reasons(m['by_reason']);
        final total = _int(m['count']) ?? 0;
        return (
          formatCount(total),
          total == 0 ? null : _reasonBar(context, reasons, total),
          [
            for (final r in reasons)
              if (r.count > 0) '${r.label} ${formatCount(r.count)}',
          ].join(' · '),
          Tone.caution,
        );
      case 'in_progress':
        final m = _map(key);
        final count = _int(m['count']) ?? 0;
        final ours = _int(m['ours']) ?? 0;
        final external = _int(m['external']) ?? 0;
        return (
          formatCount(count),
          count == 0
              ? null
              : PmBar(
                  key: const ValueKey('pm-tile-in_progress-bar'),
                  segments: [
                    (ours / count, PersonColours.hue(context, 0).ink),
                    (external / count, PersonColours.hue(context, 5).ink),
                  ],
                ),
          [
            'Us ${formatCount(ours)}',
            'External ${formatCount(external)}',
            '2nd visit ${formatCount(_int(m['second_visit']) ?? 0)}',
            'Redo ${formatCount(_int(m['redo']) ?? 0)} / '
                'new ${formatCount(_int(m['new']) ?? 0)}',
          ].join(' · '),
          Tone.info,
        );
      case 'ready_to_schedule':
        final m = _map(key);
        final oldest = _int(m['oldest_days']);
        return (
          formatCount(_int(m['count']) ?? 0),
          null,
          oldest == null
              ? ''
              : 'Oldest waiting $oldest ${oldest == 1 ? 'day' : 'days'}',
          Tone.info,
        );
      case 'scheduled':
        final m = _map(key);
        return (
          formatCount(_int(m['count']) ?? 0),
          null,
          'Next 7 days: ${formatCount(_int(m['next_7_days']) ?? 0)}',
          Tone.info,
        );
      case 'done_waiting_payment':
        final m = _map(key);
        final total = _int(m['total_minor']);
        final due = _int(m['due_minor']);
        return (
          formatCount(_int(m['count']) ?? 0),
          null,
          [
            if (total != null) '${formatMoney(total, cents: false)} total',
            if (due != null) '${formatMoney(due, cents: false)} due',
          ].join(' · '),
          Tone.ok,
        );
      case 'pipeline':
        final open = _int(data['pipeline_minor']);
        return (
          open == null ? '—' : formatMoney(open, cents: false),
          null,
          'In progress, waiting review, ready and scheduled',
          Tone.ok,
        );
      case 'avg_production_cost':
        final own = _int(data['avg_production_cost_minor']);
        final avg = own ?? _avgCost;
        return (
          avg == null ? '—' : formatMoney(avg, cents: false),
          null,
          avg == null
              ? 'No production cost yet'
              : own == null
              ? 'Per job, the last three months'
              : 'Tech, helper, operation, gas and parts',
          null,
        );
      case 'stopped_payments':
        final days = widget.stoppedDays;
        return (
          formatCount(_int(data['stopped_payments']) ?? 0),
          null,
          days == null
              ? 'Partly paid, then nothing'
              : 'Partly paid, nothing for $days+ days',
          Tone.bad,
        );
    }
    return ('—', null, '', null);
  }

  List<({String? value, String label, int count})> _reasons(Object? list) => [
    for (final r in (list as List?) ?? const [])
      if (r is Map)
        (
          value: r['value'] as String?,
          label: '${r['label'] ?? r['value'] ?? 'No reason'}',
          count: r['count'] is int ? r['count'] as int : 0,
        ),
  ];

  Color _reasonColour(
    BuildContext context,
    List<({String? value, String label, int count})> reasons,
    int i,
  ) => pmReasonColour(context, i, none: reasons[i].value == null);

  Widget _reasonBar(
    BuildContext context,
    List<({String? value, String label, int count})> reasons,
    int total,
  ) => PmBar(
    segments: [
      for (final (i, r) in reasons.indexed)
        (r.count / total, _reasonColour(context, reasons, i)),
    ],
  );

  Widget _boxes(BuildContext context, {required bool phone}) {
    final boxes = [
      _box(
        context,
        anchor: const ValueKey('tour-pm-dash-review'),
        title: 'Waiting review by location and reason',
        child: _byLocation(context),
      ),
      _box(
        context,
        anchor: const ValueKey('tour-pm-dash-start'),
        title: 'Waiting to start after payment',
        child: _waitingToStart(context),
      ),
      _box(
        context,
        anchor: const ValueKey('tour-pm-dash-teams'),
        title: 'Jobs per team',
        child: _perTeam(context),
      ),
    ];
    if (phone) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, b) in boxes.indexed) ...[
            if (i > 0) const SizedBox(height: 10),
            b,
          ],
        ],
      );
    }
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, b) in boxes.indexed) ...[
            if (i > 0) const SizedBox(width: 10),
            Expanded(child: b),
          ],
        ],
      ),
    );
  }

  Widget _box(
    BuildContext context, {
    required Key anchor,
    required String title,
    required Widget child,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return DecoratedBox(
      key: anchor,
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: FontWeight.w700,
                color: scheme.onSurfaceVariant,
                letterSpacing: 0.4,
              ),
            ),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }

  /// Cards showing every row instead of their first few.
  final _expanded = <String>{};

  /// The first [cap] of [rows], then a line that shows the rest in place.
  /// Jose, 2026-10-22: Waiting to start listed 377 jobs ("endless").
  Widget _capped(
    BuildContext context, {
    required String card,
    required List<Widget> rows,
    required int cap,
    required String Function(int more) moreLine,
    List<Widget> footer = const [],
  }) {
    final open = _expanded.contains(card);
    final more = rows.length - cap;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ...(open || more <= 0 ? rows : rows.take(cap)),
        if (more > 0)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: ValueKey('pm-$card-more'),
              style: TextButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                visualDensity: VisualDensity.compact,
              ),
              onPressed: () => setState(
                () => open ? _expanded.remove(card) : _expanded.add(card),
              ),
              child: Text(open ? 'Show fewer' : moreLine(more)),
            ),
          ),
        ...footer,
      ],
    );
  }

  Widget _none(BuildContext context, String text, Key key) => Text(
    text,
    key: key,
    style: Theme.of(context).textTheme.bodySmall?.copyWith(
      color: Theme.of(context).colorScheme.onSurfaceVariant,
    ),
  );

  Widget _barRow(
    BuildContext context, {
    required Key key,
    required String name,
    required int count,
    required Widget bar,
  }) => Padding(
    key: key,
    padding: const EdgeInsets.only(bottom: 6),
    child: Row(
      children: [
        SizedBox(
          width: 96,
          child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
        ),
        const SizedBox(width: 8),
        Expanded(child: bar),
        const SizedBox(width: 8),
        SizedBox(
          width: 32,
          child: Text(
            formatCount(count),
            textAlign: TextAlign.right,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
      ],
    ),
  );

  Widget _byLocation(BuildContext context) {
    final review = _map('waiting_review');
    if (!_mapped.contains('waiting_review')) {
      return _none(
        context,
        'Waiting review is not set up.',
        const ValueKey('pm-review-unmapped'),
      );
    }
    final locations = [
      for (final l in (review['by_location'] as List?) ?? const [])
        if (l is Map) l.cast<String, dynamic>(),
    ];
    if (locations.isEmpty) {
      return _none(
        context,
        'Nothing waits for review.',
        const ValueKey('pm-review-none'),
      );
    }
    // The legend is the overall reasons, in the order each bar uses.
    final legend = _reasons(review['by_reason']);
    final theme = Theme.of(context);

    // Each bar is its location against the largest one, so the bars compare;
    // its segments are the reasons, in the legend's colours.
    final most = locations
        .map((l) => _int(l['count']) ?? 0)
        .fold<int>(0, (a, b) => b > a ? b : a);

    return _capped(
      context,
      card: 'review',
      cap: 12,
      moreLine: (n) => 'Show all · $n more',
      rows: [
        for (final l in locations)
          _barRow(
            context,
            key: ValueKey('pm-review-loc-${l['name']}'),
            name: '${l['name']}',
            count: _int(l['count']) ?? 0,
            bar: most == 0
                ? const PmBar(segments: [])
                : _reasonBar(context, _reasons(l['by_reason']), most),
          ),
      ],
      footer: [
        const SizedBox(height: 4),
        Wrap(
          key: const ValueKey('pm-review-legend'),
          spacing: 12,
          runSpacing: 4,
          children: [
            for (final (i, r) in legend.indexed)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 10,
                    height: 10,
                    decoration: BoxDecoration(
                      color: _reasonColour(context, legend, i),
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                  const SizedBox(width: 4),
                  Text(r.label, style: theme.textTheme.bodySmall),
                ],
              ),
          ],
        ),
      ],
    );
  }

  Widget _waitingToStart(BuildContext context) {
    final rows = [
      for (final r in (_data?['waiting_to_start'] as List?) ?? const [])
        if (r is Map) r.cast<String, dynamic>(),
    ];
    if (rows.isEmpty) {
      return _none(
        context,
        'No paid job is waiting to start.',
        const ValueKey('pm-start-none'),
      );
    }
    final scheme = Theme.of(context).colorScheme;
    final limit = _int(rows.first['limit']) ?? 0;
    final over = rows.where((r) => (_int(r['days']) ?? 0) >= limit).length;
    return _capped(
      context,
      card: 'start',
      cap: 10,
      moreLine: (n) {
        // Longest first, so the hidden rows over the limit are the rest.
        final hidden = (over - 10).clamp(0, n);
        return hidden == n
            ? 'and $n more over the $limit-day limit · See all'
            : 'and $n more, $hidden over the $limit-day limit · See all';
      },
      rows: [
        for (final r in rows)
          Builder(
            builder: (context) {
              final job = r['job'] is Map ? r['job'] as Map : const {};
              final id = '${job['id']}';
              final days = _int(r['days']) ?? 0;
              final limit = _int(r['limit']) ?? 0;
              final name = [
                '${job['number'] ?? ''}',
                '${job['customer'] ?? ''}',
              ].where((s) => s.isNotEmpty).join(' · ');
              return InkWell(
                key: ValueKey('pm-start-$id'),
                borderRadius: BorderRadius.circular(6),
                onTap: () => _openJob(id),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      SizedBox(
                        width: 150,
                        // Cut short at this width: the whole name on hover.
                        child: Tooltip(
                          key: ValueKey('pm-start-name-$id'),
                          message: name,
                          child: Text(
                            name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: scheme.onSurface,
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: PmCountdownBar(
                          key: ValueKey('pm-start-bar-$id'),
                          days: days,
                          limit: limit,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
      ],
    );
  }

  Widget _perTeam(BuildContext context) {
    final teams = [
      for (final t in (_data?['per_team'] as List?) ?? const [])
        if (t is Map) t.cast<String, dynamic>(),
    ];
    if (teams.isEmpty) {
      return _none(context, 'No open jobs.', const ValueKey('pm-team-none'));
    }
    // "Unassigned" (1,334 live on 2026-10-22, against 26 for the busiest
    // team) set the scale and left every team's bar near empty. Teams scale
    // against the busiest team; Unassigned goes last, muted, capped at full.
    final named = [
      for (final t in teams)
        if (t['name'] != 'Unassigned') t,
    ];
    final unassigned = [
      for (final t in teams)
        if (t['name'] == 'Unassigned') t,
    ];
    final most = (named.isEmpty ? teams : named)
        .map((t) => _int(t['count']) ?? 0)
        .fold<int>(0, (a, b) => b > a ? b : a);
    final colour = PersonColours.hue(context, 0).ink;
    final muted = ToneColours.of(context, Tone.neutral).ink;
    Widget row(Map<String, dynamic> t, Color c) {
      final n = _int(t['count']) ?? 0;
      return _barRow(
        context,
        key: ValueKey('pm-team-${t['name']}'),
        name: '${t['name']}',
        count: n,
        bar: PmBar(
          key: ValueKey('pm-team-bar-${t['name']}'),
          segments: [if (most > 0) ((n / most).clamp(0.0, 1.0), c)],
        ),
      );
    }

    return _capped(
      context,
      card: 'teams',
      cap: 12,
      moreLine: (n) => 'Show all · $n more',
      rows: [
        for (final t in named) row(t, colour),
        for (final t in unassigned) row(t, muted),
      ],
    );
  }

  Future<void> _openJob(String id) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => JobDetailPanel(
        api: widget.api,
        session: widget.session,
        id: id,
        labels: widget.labels,
      ),
    );
    if (changed == true && mounted) _load();
  }
}
