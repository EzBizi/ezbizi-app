import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/branch_options.dart';
import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'job_labels.dart';
import 'pm_stages.dart';
import 'pm_frame.dart';
import 'project_management_rules.dart';

/// Project Management › Overview, which was Dashboard (design 144, option 1;
/// phone 4). Its key stays `dashboard`, so nothing stored moves.
///
/// What needs attention today, from `jobs/project-management/overview`:
/// the five stages as one flow (Waiting review › Ready to schedule ›
/// Scheduled › In progress › Done, waiting payment), each tile the same
/// size, with its rule in one line and its count; the pipeline; then "Needs
/// attention", three cards; then waiting review by service area and open
/// jobs by technician. Every number has an ⓘ with the rule that counted it,
/// in the rules endpoint's own words where it has them, and every number
/// opens Jobs filtered to exactly what it counted.
///
/// The overview takes no dates: its numbers are the jobs as they stand.
/// Average production cost is gone (design 144): it came from Financials'
/// sheet, ignored every filter on the page, and said "the last three
/// months" for two months and a part.
class ProjectManagementDashboard extends StatefulWidget {
  const ProjectManagementDashboard({
    super.key,
    required this.api,
    required this.session,
    this.areas = const [],
    this.stoppedDays,
    this.warnDays,
    this.labels,
    this.generation = 0,
    this.onOpenJobs,
    this.onOpenSettings,
    this.scope = const PmScope(),
    this.rules,
  });

  /// Which jobs these numbers are about, as the scope bar says.
  final PmScope scope;

  final ApiClient api;
  final Session session;

  /// The service areas in reach, for the place picker. Empty: none.
  final List<PmArea> areas;

  /// `jobs.pm_stopped_days` and `jobs.pm_warn_days`, for the words. Null:
  /// not read.
  final int? stoppedDays;
  final int? warnDays;

  final JobLabels? labels;

  /// Bumped by the screen when Settings changes: count again.
  final int generation;

  /// A number's tap: Jobs filtered to exactly what it counted.
  final void Function(PmJobsIntent intent)? onOpenJobs;
  final VoidCallback? onOpenSettings;

  /// The rules endpoint, for the ⓘ sentences. Null: the client's own.
  final PmRules? rules;

  @override
  State<ProjectManagementDashboard> createState() =>
      _ProjectManagementDashboardState();
}

/// The three Needs attention cards, in order.
const pmAttentionCards = ['not_started', 'stopped', 'no_reason'];

class _ProjectManagementDashboardState
    extends State<ProjectManagementDashboard> {
  /// Every flow tile the same size, whatever it says.
  static const _tileHeight = 148.0;
  static const _phoneTileHeight = 104.0;

  PmFilters _filters = const PmFilters();
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  int _request = 0;

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
      _filters.query(withSecondVisit: false),
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

  int? get _warnDays {
    final rows = (_data?['waiting_to_start'] as List?) ?? const [];
    for (final r in rows) {
      if (r is Map && r['limit'] is int) return r['limit'] as int;
    }
    return widget.warnDays;
  }

  void _open(PmJobsIntent Function(int serial) intent) {
    widget.onOpenJobs?.call(intent(0));
  }

  @override
  Widget build(BuildContext context) {
    return PmFrame(
      tab: 'dashboard',
      purpose:
          "What needs attention today, across Project Management's open "
          'jobs.',
      scope: widget.scope,
      search: TextField(
        key: const ValueKey('pm-dash-search'),
        controller: _search,
        onChanged: _typed,
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: pmJobSearchHint,
        ),
      ),
      place: widget.areas.isEmpty
          ? null
          : PmAreaChips(
              key: const ValueKey('tour-pm-dash-areas'),
              areas: widget.areas,
              selected: _filters.areaId,
              allLabel: 'All areas',
              franchiseName: _franchise,
              onSelected: (id) => _refilter(_filters.copyWith(areaId: id)),
            ),
      filters: [
        PmFlagChips(
          key: const ValueKey('tour-pm-dash-flags'),
          filters: _filters,
          secondVisit: false,
          onChanged: _refilter,
        ),
      ],
      child: LayoutBuilder(
        builder: (context, constraints) {
          final phone = constraints.maxWidth < PmFrame.phoneBelow;
          final pad = PmFrame.gutter(constraints.maxWidth);
          return ListView(
            key: const ValueKey('pm-dash'),
            padding: EdgeInsets.fromLTRB(pad, 0, pad, 24),
            children: [
              AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _data == null
                    ? const SizedBox.shrink()
                    : Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _flow(context, phone: phone),
                          const SizedBox(height: 10),
                          _pipeline(context),
                          const SizedBox(height: 16),
                          _attention(context, phone: phone),
                          const SizedBox(height: 16),
                          _boxes(context, phone: phone),
                        ],
                      ),
              ),
            ],
          );
        },
      ),
    );
  }

  // ── The flow ──────────────────────────────────────────────────────────

  /// A stage's rule: the rules endpoint's sentence, else the client's.
  String _stageRule(String key) =>
      widget.rules?.stageSentence(key, _me) ??
      'Jobs in scope whose status is one of the ${pmStageName(key)} '
          'statuses in Settings.';

  String _stageInfo(String key) {
    final scope = widget.rules?.scopeSentence;
    return [
      '${pmStageName(key)} = ${_stageRule(key)}',
      ?scope,
      if (key == 'ready_to_schedule')
        'Oldest: days since the oldest of these jobs last changed status.',
      if (key == 'scheduled')
        'Next 7 days: scheduled from the start of today to the end of 7 '
            'days from now.',
      if (key == 'in_progress')
        'External company: the job has an external company set.',
    ].join('\n');
  }

  /// A stage tile's count and its second line.
  (int, String) _stageNumbers(String key) {
    final m = _map(key);
    final count = _int(m['count']) ?? 0;
    switch (key) {
      case 'ready_to_schedule':
        final oldest = _int(m['oldest_days']);
        return (
          count,
          oldest == null
              ? ''
              : 'Oldest $oldest ${oldest == 1 ? 'day' : 'days'} in this status',
        );
      case 'scheduled':
        return (
          count,
          '${formatCount(_int(m['next_7_days']) ?? 0)} in the next 7 days',
        );
      case 'in_progress':
        return (
          count,
          'Our own technicians ${formatCount(_int(m['ours']) ?? 0)} · '
              'external company ${formatCount(_int(m['external']) ?? 0)}',
        );
      case 'done_waiting_payment':
        final due = _int(m['due_minor']);
        return (
          count,
          due == null ? '' : '${formatMoney(due, cents: false)} due',
        );
      case 'waiting_review':
        final none = _noReason;
        return (count, '${formatCount(none)} with no reason picked');
    }
    return (count, '');
  }

  int get _noReason {
    for (final r
        in (_map('waiting_review')['by_reason'] as List?) ?? const []) {
      if (r is Map && r['value'] == null && r['count'] is int) {
        return r['count'] as int;
      }
    }
    return 0;
  }

  Widget _flow(BuildContext context, {required bool phone}) {
    final scheme = Theme.of(context).colorScheme;
    final tiles = [
      for (final key in pmStageKeys)
        SizedBox(
          height: phone ? _phoneTileHeight : _tileHeight,
          child: _stageTile(context, key, phone: phone),
        ),
    ];
    Widget arrow(IconData icon) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
      child: Icon(icon, size: 18, color: scheme.onSurfaceVariant),
    );

    if (phone) {
      return Column(
        key: const ValueKey('tour-pm-dash-tiles'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, t) in tiles.indexed) ...[
            if (i > 0) Center(child: arrow(Icons.keyboard_arrow_down)),
            t,
          ],
        ],
      );
    }
    return Row(
      key: const ValueKey('tour-pm-dash-tiles'),
      children: [
        for (final (i, t) in tiles.indexed) ...[
          if (i > 0) arrow(Icons.chevron_right),
          Expanded(child: t),
        ],
      ],
    );
  }

  Widget _stageTile(BuildContext context, String key, {required bool phone}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final unmapped = !_mapped.contains(key);
    final (count, second) = _stageNumbers(key);
    final open = unmapped || widget.onOpenJobs == null
        ? null
        : () => _open((s) => PmJobsIntent(key, _filters, s));

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
              top: BorderSide(color: pmStageColour(context, key), width: 4),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(12, 8, 10, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  PmStageDot(key, key: ValueKey('pm-tile-$key-dot')),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      pmStageName(key),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelLarge?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  PmInfo(_stageInfo(key), key: ValueKey('pm-info-$key')),
                ],
              ),
              const SizedBox(height: 2),
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
                      minimumSize: const Size(0, 28),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text('Set up in Settings'),
                  ),
              ] else ...[
                Text(
                  formatCount(count),
                  key: ValueKey('pm-tile-$key-value'),
                  maxLines: 1,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                if (!phone)
                  Text(
                    _stageRule(key).split('\n').first,
                    key: ValueKey('pm-tile-$key-rule'),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: muted,
                  ),
                const Spacer(),
                if (second.isNotEmpty)
                  Text(
                    second,
                    key: ValueKey('pm-tile-$key-sub'),
                    maxLines: 1,
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

  // ── The pipeline ──────────────────────────────────────────────────────

  int get _pipelineCount => [
    for (final k in pmPipelineStages)
      if (_mapped.contains(k)) _int(_map(k)['count']) ?? 0,
  ].fold(0, (a, b) => a + b);

  Widget _pipeline(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final money = _int(_data?['pipeline_minor']);
    final count = _pipelineCount;
    final rule = pmMeasures().firstWhere((m) => m.number == 'Pipeline').rule;
    final open = widget.onOpenJobs == null
        ? null
        : () => _open(
            (s) => PmJobsIntent(
              null,
              _filters,
              s,
              stages: [
                for (final k in pmPipelineStages)
                  if (_mapped.contains(k)) k,
              ],
              label: 'Pipeline: Waiting review to In progress',
            ),
          );
    return Align(
      alignment: Alignment.centerLeft,
      child: InkWell(
        key: const ValueKey('pm-tile-pipeline'),
        borderRadius: BorderRadius.circular(6),
        onTap: open,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 2),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: Text.rich(
                  TextSpan(
                    children: [
                      const TextSpan(text: 'Pipeline: '),
                      TextSpan(
                        text:
                            '${formatCount(count)} open '
                            '${count == 1 ? 'job' : 'jobs'}',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      if (money != null)
                        TextSpan(
                          text: ' · ${formatMoney(money, cents: false)} total',
                        ),
                      TextSpan(
                        text: '  See them ›',
                        style: TextStyle(
                          color: scheme.primary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  key: const ValueKey('pm-tile-pipeline-value'),
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              const SizedBox(width: 6),
              PmInfo(rule, key: const ValueKey('pm-info-pipeline')),
            ],
          ),
        ),
      ),
    );
  }

  // ── Needs attention ───────────────────────────────────────────────────

  int get _notStarted {
    var n = 0;
    for (final r in (_data?['waiting_to_start'] as List?) ?? const []) {
      if (r is Map && r['days'] is int && r['limit'] is int) {
        if ((r['days'] as int) >= (r['limit'] as int)) n++;
      }
    }
    return n;
  }

  ({int count, String text, String info, PmJobsIntent Function(int) intent})
  _card(String key) {
    final warn = _warnDays;
    final stopped = widget.stoppedDays;
    final measures = pmMeasures(warnDays: warn, stoppedDays: stopped);
    String rule(String n) => measures.firstWhere((m) => m.number == n).rule;
    switch (key) {
      case 'not_started':
        final email = widget.rules?.serverJob('Paid but not started warning');
        return (
          count: _notStarted,
          text: warn == null
              ? 'paid but not started past the warning days'
              : 'paid $warn or more days ago, not started',
          info: [rule('Paid but not started'), ?email].join('\n'),
          intent: (s) => PmJobsIntent(
            null,
            _filters.copyWith(waitingToStart: true),
            s,
            label: warn == null
                ? 'Paid, not started'
                : 'Paid $warn or more days ago, not started',
          ),
        );
      case 'stopped':
        return (
          count: _int(_data?['stopped_payments']) ?? 0,
          text: stopped == null
              ? 'payments stopped'
              : 'payments stopped: nothing paid for over $stopped days',
          info: rule('Payments stopped'),
          intent: (s) => PmJobsIntent(
            null,
            _filters.copyWith(stopped: true),
            s,
            label: 'Payments stopped, every stage',
          ),
        );
      default:
        return (
          count: _noReason,
          text: 'waiting review with no reason picked',
          info:
              '${rule('Waiting review with no reason')} Pick one on the '
              "job's timeline in Jobs.",
          intent: (s) => PmJobsIntent(
            'waiting_review',
            _filters.copyWith(noReason: true),
            s,
            label: 'Waiting review with no reason',
          ),
        );
    }
  }

  Widget _attention(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final cards = [
      for (final key in pmAttentionCards)
        SizedBox(height: 92, child: _attentionCard(context, key)),
    ];
    return Column(
      key: const ValueKey('tour-pm-dash-attention'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Needs attention',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        if (phone)
          for (final (i, c) in cards.indexed) ...[
            if (i > 0) const SizedBox(height: 8),
            c,
          ]
        else
          Row(
            children: [
              for (final (i, c) in cards.indexed) ...[
                if (i > 0) const SizedBox(width: 10),
                Expanded(child: c),
              ],
            ],
          ),
      ],
    );
  }

  Widget _attentionCard(BuildContext context, String key) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final c = _card(key);
    // Red is "needs attention", and only when there is something to attend.
    final edge = c.count > 0
        ? ToneColours.of(context, Tone.bad).ink
        : scheme.outlineVariant;
    return Material(
      color: scheme.surface,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: ValueKey('pm-attention-$key'),
        onTap: widget.onOpenJobs == null ? null : () => _open(c.intent),
        child: Container(
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: edge, width: 4)),
          ),
          padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    formatCount(c.count),
                    key: ValueKey('pm-attention-$key-value'),
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: c.count > 0 ? edge : null,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 3),
                      child: Text(
                        c.text,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
                  PmInfo(c.info, key: ValueKey('pm-info-attention-$key')),
                ],
              ),
              const Spacer(),
              Text(
                'See them ›',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
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
        title: 'Waiting review by service area',
        info:
            'Jobs in Waiting review, by their service area ("No service '
            'area" when they have none), split by review reason.',
        child: _byLocation(context),
      ),
      _box(
        context,
        anchor: const ValueKey('tour-pm-dash-teams'),
        title: 'Open jobs by technician',
        info: pmMeasures()
            .firstWhere((m) => m.number == 'Open jobs by technician')
            .rule,
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
    required String info,
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
            Row(
              children: [
                Flexible(
                  child: Text(
                    title.toUpperCase(),
                    style: theme.textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurfaceVariant,
                      letterSpacing: 0.4,
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                PmInfo(info, key: ValueKey('pm-info-box-$title')),
              ],
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
    // No stage's colour: these bars count people, not a stage.
    final colour = PersonColours.hue(context, 9).ink;
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
}
