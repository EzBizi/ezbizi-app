import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/device_zone.dart';
import '../../core/job_money.dart';
import '../../core/job_status.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'job_detail_panel.dart';
import 'job_labels.dart';
import 'pm_stages.dart';
import 'project_management_tab.dart' show PmTimeline, pmTechnician;

/// Project Management › Jobs (design 141, option 1; phone 4), which was
/// Pending Jobs.
///
/// One stage at a time from `jobs/project-management/jobs`, 100 to a page,
/// newest change first: In progress, Waiting review, Ready to schedule,
/// Scheduled, Done waiting payment ‖ Completed, Cancelled, each with its
/// count from `meta.counts` under the same filters. A stage `meta.mapped`
/// leaves out is not set up, and says where to set it up.
///
/// A row opens its timeline beside the table (on a phone, in its place),
/// read from the timeline endpoint by id, with the three Project Management
/// fields above it: review reason, external company and Needs 2nd visit,
/// each saved as it changes through PATCH /jobs/{id}.
class ProjectManagementJobs extends StatefulWidget {
  const ProjectManagementJobs({
    super.key,
    required this.api,
    required this.session,
    this.areas = const [],
    this.warnDays,
    this.reviewReasons = const [],
    this.redoWords = const [],
    this.disputeWords = const [],
    this.statuses = const [],
    this.intent,
    this.labels,
    this.generation = 0,
    this.onOpenSettings,
    this.now,
    this.zone = deviceZone,
  });

  final ApiClient api;
  final Session session;
  final List<PmArea> areas;

  /// `jobs.pm_warn_days`, the After payment bar's limit. Null: no bar.
  final int? warnDays;

  /// `jobs.review_reasons`, `(value, label)`.
  final List<(String, String)> reviewReasons;

  /// `jobs.pm_redo_words` and `jobs.pm_dispute_words`, for the flags column.
  final List<String> redoWords;
  final List<String> disputeWords;

  final List<JobStatus> statuses;

  /// A Dashboard tile's stage and filters; a new [PmJobsIntent.serial]
  /// applies it again.
  final PmJobsIntent? intent;

  final JobLabels? labels;
  final int generation;
  final VoidCallback? onOpenSettings;
  final DateTime Function()? now;
  final String? Function() zone;

  @override
  State<ProjectManagementJobs> createState() => _ProjectManagementJobsState();
}

class _ProjectManagementJobsState extends State<ProjectManagementJobs> {
  static const _tableFrom = 760.0;

  String _stage = pmStageKeys.first;
  PmFilters _filters = const PmFilters();
  int _page = 1;

  bool _loading = true;
  String? _error;
  int _request = 0;
  List<Map<String, dynamic>> _rows = const [];
  Map<String, int> _counts = const {};
  List<String>? _mapped;
  int _lastPage = 1;
  int _total = 0;

  final _search = TextEditingController();
  Timer? _typing;

  String? _openId;
  Map<String, dynamic>? _openTimeline;
  String? _openError;
  int _openRequest = 0;

  Me get _me => widget.session.me!;
  DateTime _now() => (widget.now ?? DateTime.now)();

  @override
  void initState() {
    super.initState();
    _adopt(widget.intent);
    _load();
  }

  @override
  void didUpdateWidget(ProjectManagementJobs old) {
    super.didUpdateWidget(old);
    final intent = widget.intent;
    if (intent != null && intent.serial != old.intent?.serial) {
      _adopt(intent);
      _rows = const [];
      _close();
      _load();
    } else if (old.generation != widget.generation) {
      _load();
    }
  }

  void _adopt(PmJobsIntent? intent) {
    if (intent == null) return;
    _stage = intent.stage;
    _filters = intent.filters;
    _search.text = intent.filters.q;
    _page = 1;
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
    setState(() {
      _filters = next;
      _page = 1;
    });
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });
    final response = await widget.api.get('/jobs/project-management/jobs', {
      'stage': _stage,
      if (_page > 1) 'page': '$_page',
      ..._filters.query(withSecondVisit: _stage == 'in_progress'),
    });
    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data;
      _rows = data is List
          ? [
              for (final r in data)
                if (r is Map) r.cast<String, dynamic>(),
            ]
          : const [];
      final meta = response.body['meta'];
      final counts = meta is Map ? meta['counts'] : null;
      _counts = {
        if (counts is Map)
          for (final e in counts.entries)
            if (e.value is int) '${e.key}': e.value as int,
      };
      final mapped = meta is Map ? meta['mapped'] : null;
      _mapped = mapped is List ? [for (final m in mapped) '$m'] : null;
      _lastPage = meta is Map && meta['last_page'] is int
          ? meta['last_page'] as int
          : 1;
      _total = meta is Map && meta['total'] is int
          ? meta['total'] as int
          : _rows.length;
      if (_openId != null && !_rows.any((r) => '${r['id']}' == _openId)) {
        _close();
      }
    });
  }

  void _choose(String stage) {
    if (stage == _stage) return;
    setState(() {
      _stage = stage;
      _page = 1;
      _rows = const [];
    });
    _close();
    _load();
  }

  void _turn(int page) {
    setState(() => _page = page);
    _close();
    _load();
  }

  Map<String, dynamic>? get _open {
    for (final r in _rows) {
      if ('${r['id']}' == _openId) return r;
    }
    return null;
  }

  String _status(Object? value) {
    if (value == null) return '—';
    for (final s in widget.statuses) {
      if (s.value == '$value') return s.label;
    }
    return jobStatusLabel(null, '$value');
  }

  /// The job's timeline row, read by id.
  Future<void> _openRow(String id) async {
    final request = ++_openRequest;
    setState(() {
      _openId = id;
      _openTimeline = null;
      _openError = null;
    });
    final response = await widget.api.get('/jobs/project-management', {
      'ids[]': [id],
      'timezone': ?widget.zone(),
    });
    if (!mounted || request != _openRequest) return;
    setState(() {
      final data = response.data;
      final row = response.ok && data is List
          ? data.whereType<Map>().where((r) => '${r['id']}' == id).firstOrNull
          : null;
      if (row == null) {
        _openError = response.ok
            ? 'This job is no longer in your view.'
            : response.message;
        return;
      }
      _openTimeline = row.cast<String, dynamic>();
    });
  }

  void _close() {
    if (!mounted) return;
    setState(() {
      _openId = null;
      _openTimeline = null;
      _openError = null;
      _openRequest++;
    });
  }

  /// A field saved on the server: the row takes the server's answer.
  void _saved(Map<String, dynamic> job) {
    final id = '${job['id']}';
    setState(() {
      _rows = [
        for (final r in _rows)
          if ('${r['id']}' == id)
            {
              ...r,
              for (final k in const [
                'review_reason',
                'external_company',
                'needs_second_visit',
              ])
                if (job.containsKey(k)) k: job[k],
            }
          else
            r,
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= _tableFrom;
        final open = _open;
        if (!wide && open != null) return _pane(context, open, back: true);

        return HeaderAboveList(
          header: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 12),
              _stages(context, wide: wide),
              _filtersRow(context, wide: wide),
            ],
          ),
          list: AsyncBody(
            key: const ValueKey('tour-pm-jobs-list'),
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _body(context, wide: wide),
          ),
        );
      },
    );
  }

  bool get _unmapped => _mapped != null && !_mapped!.contains(_stage);

  Widget _stages(BuildContext context, {required bool wide}) {
    final scheme = Theme.of(context).colorScheme;

    Widget chip(String key) {
      final closed = pmClosedStages.contains(key);
      final count = _counts[key];
      final mapped = _mapped == null || _mapped!.contains(key);
      final colour = closed ? scheme.onSurfaceVariant : null;
      return Padding(
        padding: const EdgeInsets.only(right: 6),
        child: ChoiceChip(
          key: ValueKey('pm-stage-$key'),
          showCheckmark: false,
          selected: _stage == key,
          label: Text.rich(
            TextSpan(
              text: pmStageName(key),
              style: TextStyle(color: _stage == key ? null : colour),
              children: [
                if (mapped && count != null)
                  TextSpan(
                    text: ' · ${formatCount(count)}',
                    style: TextStyle(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
              ],
            ),
            key: ValueKey('pm-stage-$key-label'),
          ),
          onSelected: (_) => _choose(key),
        ),
      );
    }

    return Padding(
      key: const ValueKey('tour-pm-jobs-stages'),
      padding: const EdgeInsets.only(bottom: 8),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: wide ? 24 : 16),
        child: Row(
          children: [
            for (final key in pmStageKeys)
              if (!pmClosedStages.contains(key)) chip(key),
            SizedBox(
              height: 28,
              child: VerticalDivider(
                key: const ValueKey('pm-stage-divider'),
                width: 14,
                thickness: 2,
                color: scheme.outlineVariant,
              ),
            ),
            for (final key in pmStageKeys)
              if (pmClosedStages.contains(key)) chip(key),
          ],
        ),
      ),
    );
  }

  String _franchise(String id) {
    for (final f in _me.franchises) {
      if (f.id == id) return f.name;
    }
    return '';
  }

  Widget _filtersRow(BuildContext context, {required bool wide}) {
    final search = SizedBox(
      width: wide ? 300 : double.infinity,
      child: TextField(
        key: const ValueKey('pm-jobs-search'),
        controller: _search,
        onChanged: _typed,
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Job, customer, address, phone or tech',
        ),
      ),
    );
    return Padding(
      key: const ValueKey('tour-pm-jobs-filters'),
      padding: EdgeInsets.fromLTRB(wide ? 24 : 16, 0, wide ? 24 : 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.areas.isNotEmpty) ...[
            PmAreaChips(
              areas: widget.areas,
              selected: _filters.areaId,
              allLabel: 'All areas',
              franchiseName: _franchise,
              onSelected: (id) => _refilter(_filters.copyWith(areaId: id)),
            ),
            const SizedBox(height: 8),
          ],
          PmFlagChips(
            filters: _filters,
            secondVisit: _stage == 'in_progress',
            onChanged: _refilter,
          ),
          const SizedBox(height: 8),
          Align(alignment: Alignment.centerLeft, child: search),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, {required bool wide}) {
    if (_unmapped) {
      return PmNotSetUp(
        key: const ValueKey('pm-jobs-unmapped'),
        stage: _stage,
        onSetUp: widget.onOpenSettings,
      );
    }
    final theme = Theme.of(context);
    if (_rows.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            _filters.q.trim().isNotEmpty
                ? 'No jobs here match “${_filters.q.trim()}”.'
                : 'No jobs are ${pmStageName(_stage).toLowerCase()}.',
            key: const ValueKey('pm-jobs-none'),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      );
    }

    final money = _rows.any((r) => r['due_minor'] is int);
    final pager = _pager(context);

    if (!wide) {
      return ListView.builder(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
        itemCount: _rows.length + 1,
        itemBuilder: (context, i) =>
            i == _rows.length ? pager : _card(context, _rows[i], money),
      );
    }

    final scheme = theme.colorScheme;
    final open = _open;
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 16),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border.all(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _headerRow(context, money),
                    Divider(height: 1, color: scheme.outlineVariant),
                    Expanded(
                      child: ListView.builder(
                        itemCount: _rows.length,
                        itemBuilder: (context, i) =>
                            _tableRow(context, _rows[i], money),
                      ),
                    ),
                    Divider(height: 1, color: scheme.outlineVariant),
                    pager,
                  ],
                ),
              ),
              if (open != null) ...[
                VerticalDivider(width: 1, color: scheme.outlineVariant),
                SizedBox(width: 380, child: _pane(context, open, back: false)),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// "‹ Newer · Page 1 of 3 · 245 jobs · Older ›".
  Widget _pager(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        children: [
          TextButton(
            key: const ValueKey('pm-jobs-newer'),
            onPressed: _page > 1 ? () => _turn(_page - 1) : null,
            child: const Text('‹ Newer'),
          ),
          Expanded(
            child: Text(
              'Page $_page of $_lastPage · ${formatCount(_total)} '
              '${_total == 1 ? 'job' : 'jobs'}',
              key: const ValueKey('pm-jobs-page'),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          TextButton(
            key: const ValueKey('pm-jobs-older'),
            onPressed: _page < _lastPage ? () => _turn(_page + 1) : null,
            child: const Text('Older ›'),
          ),
        ],
      ),
    );
  }

  List<int> _flex(bool money) => [5, 3, 3, 3, 3, 4, if (money) 2];

  Widget _cells(List<Widget> cells, bool money) {
    final flex = _flex(money);
    return Row(
      children: [
        for (final (i, c) in cells.indexed)
          Expanded(
            flex: flex[i],
            child: Padding(
              padding: const EdgeInsets.only(right: 10),
              child: Align(alignment: Alignment.centerLeft, child: c),
            ),
          ),
      ],
    );
  }

  Widget _headerRow(BuildContext context, bool money) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
      letterSpacing: 0.4,
    );
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
      child: _cells([
        for (final h in [
          'JOB',
          'CUSTOMER',
          'AREA',
          'TEAM',
          'FLAGS',
          'AFTER PAYMENT',
          if (money) 'DUE',
        ])
          Text(h, style: style),
      ], money),
    );
  }

  bool _hasWord(Map<String, dynamic> r, List<String> words) {
    final set = {for (final w in words) w.trim().toLowerCase()}..remove('');
    if (set.isEmpty) return false;
    return [
      for (final t in (r['tags'] as List?) ?? const []) '$t'.toLowerCase(),
    ].any(set.contains);
  }

  String? _reasonLabel(Object? value) {
    if (value is! String || value.isEmpty) return null;
    for (final (v, l) in widget.reviewReasons) {
      if (v == value) return l;
    }
    return value;
  }

  List<Widget> _flags(Map<String, dynamic> r) {
    final id = '${r['id']}';
    final reason = _reasonLabel(r['review_reason']);
    return [
      if (r['needs_second_visit'] == true)
        StatusPill(
          '2nd visit',
          key: ValueKey('pm-flag-2nd-$id'),
          tone: Tone.caution,
        ),
      if (_hasWord(r, widget.redoWords))
        StatusPill('Redo', key: ValueKey('pm-flag-redo-$id'), tone: Tone.bad),
      if (_hasWord(r, widget.disputeWords))
        StatusPill(
          'Dispute',
          key: ValueKey('pm-flag-dispute-$id'),
          tone: Tone.bad,
        ),
      if (reason != null)
        StatusPill(reason, key: ValueKey('pm-flag-reason-$id')),
    ];
  }

  Widget _team(Map<String, dynamic> r) {
    final external = r['external_company'];
    if (external is String && external.trim().isNotEmpty) {
      return StatusPill(
        external.trim(),
        key: ValueKey('pm-team-external-${r['id']}'),
        tone: Tone.review,
      );
    }
    return _one(pmTechnician(r) ?? '—');
  }

  Widget _after(Map<String, dynamic> r) {
    final days = r['days_since_payment'];
    final limit = widget.warnDays;
    if (days is! int || limit == null) return _one('—');
    return PmCountdownBar(
      key: ValueKey('pm-after-${r['id']}'),
      days: days,
      limit: limit,
      width: 70,
    );
  }

  Widget _tableRow(BuildContext context, Map<String, dynamic> r, bool money) {
    final scheme = Theme.of(context).colorScheme;
    final id = '${r['id']}';
    final flags = _flags(r);
    final due = r['due_minor'];

    return Material(
      color: id == _openId ? scheme.primaryContainer : scheme.surface,
      child: InkWell(
        key: ValueKey('pm-row-$id'),
        onTap: () => _openRow(id),
        child: Container(
          decoration: BoxDecoration(
            border: Border(
              bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
          child: _cells([
            Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: '${r['number'] ?? ''}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  TextSpan(text: '  ${r['title'] ?? ''}'),
                ],
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            _one(_name(r['customer'])),
            _one('${r['service_area'] ?? '—'}'),
            _team(r),
            flags.isEmpty
                ? _one('—')
                : Wrap(spacing: 4, runSpacing: 4, children: flags),
            _after(r),
            if (money) _one(due is int ? formatMoney(due) : '—'),
          ], money),
        ),
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> r, bool money) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final id = '${r['id']}';
    final flags = _flags(r);
    final due = r['due_minor'];
    final external = r['external_company'];

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: scheme.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: InkWell(
          key: ValueKey('pm-card-$id'),
          borderRadius: BorderRadius.circular(10),
          onTap: () => _openRow(id),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${r['number'] ?? ''} ${_name(r['customer'])}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 2),
                Text(
                  [
                    '${r['title'] ?? ''}',
                    '${r['service_area'] ?? ''}',
                    if (external is String && external.trim().isNotEmpty)
                      external.trim()
                    else
                      ?pmTechnician(r),
                    if (money && due is int) '${formatMoney(due)} due',
                  ].where((s) => s.isNotEmpty).join(' · '),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                if (flags.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Wrap(spacing: 4, runSpacing: 4, children: flags),
                ],
                if (r['days_since_payment'] is int &&
                    widget.warnDays != null) ...[
                  const SizedBox(height: 6),
                  _after(r),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _one(String text) =>
      Text(text, maxLines: 1, overflow: TextOverflow.ellipsis);

  /// The open job: its fields, then its timeline.
  Widget _pane(
    BuildContext context,
    Map<String, dynamic> row, {
    required bool back,
  }) {
    final fields = PmJobFields(
      key: ValueKey('pm-fields-${row['id']}'),
      api: widget.api,
      row: row,
      reasons: widget.reviewReasons,
      onSaved: _saved,
    );
    final timeline = _openTimeline;
    if (timeline == null) {
      final scheme = Theme.of(context).colorScheme;
      return ColoredBox(
        color: scheme.surface,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Align(
              alignment: back ? Alignment.centerLeft : Alignment.centerRight,
              child: IconButton(
                key: const ValueKey('pm-timeline-close'),
                tooltip: back ? 'Back' : 'Close',
                onPressed: _close,
                icon: Icon(back ? Icons.arrow_back : Icons.close),
              ),
            ),
            fields,
            const Divider(height: 1),
            Expanded(
              child: AsyncBody(
                loading: _openError == null,
                error: _openError,
                onRetry: () => _openRow('${row['id']}'),
                child: const SizedBox.shrink(),
              ),
            ),
          ],
        ),
      );
    }
    return PmTimeline(
      key: const ValueKey('pm-timeline'),
      back: back,
      row: timeline,
      now: _now(),
      me: _me,
      status: _status,
      above: fields,
      onClose: _close,
      onOpenJob: () => _openJob(row),
    );
  }

  Future<void> _openJob(Map<String, dynamic> row) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => JobDetailPanel(
        api: widget.api,
        session: widget.session,
        id: '${row['id']}',
        labels: widget.labels,
      ),
    );
    if (changed == true && mounted) _load();
  }
}

String _name(Object? ref) =>
    ref is Map && ref['name'] is String ? ref['name'] as String : '—';

/// Review reason, external company and Needs 2nd visit for one job, each
/// PATCHed to /jobs/{id} as it changes. A refusal puts the old value back
/// and says why.
class PmJobFields extends StatefulWidget {
  const PmJobFields({
    super.key,
    required this.api,
    required this.row,
    required this.reasons,
    required this.onSaved,
  });

  final ApiClient api;
  final Map<String, dynamic> row;
  final List<(String, String)> reasons;
  final ValueChanged<Map<String, dynamic>> onSaved;

  @override
  State<PmJobFields> createState() => _PmJobFieldsState();
}

class _PmJobFieldsState extends State<PmJobFields> {
  late String? _reason = widget.row['review_reason'] as String?;
  late bool _second = widget.row['needs_second_visit'] == true;
  late final _external = TextEditingController(
    text: '${widget.row['external_company'] ?? ''}',
  );

  /// What the server holds, set before anything is typed.
  late String _savedExternal;
  final _focus = FocusNode();
  bool _saving = false;
  String? _error;

  String get _id => '${widget.row['id']}';

  @override
  void initState() {
    super.initState();
    _savedExternal = _external.text;
    _focus.addListener(() {
      if (!_focus.hasFocus) _saveExternal();
    });
  }

  @override
  void dispose() {
    _external.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<bool> _patch(Map<String, dynamic> body) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.patch('/jobs/$_id', body);
    if (!mounted) return false;
    setState(() {
      _saving = false;
      if (!response.ok) _error = response.message;
    });
    if (response.ok) {
      final data = response.data;
      widget.onSaved({
        'id': _id,
        ...body,
        if (data is Map)
          for (final k in body.keys)
            if (data.containsKey(k)) k: data[k],
      });
    }
    return response.ok;
  }

  Future<void> _saveReason(String? v) async {
    final before = _reason;
    if (v == before) return;
    setState(() => _reason = v);
    if (!await _patch({'review_reason': v}) && mounted) {
      setState(() => _reason = before);
    }
  }

  Future<void> _saveSecond(bool v) async {
    setState(() => _second = v);
    if (!await _patch({'needs_second_visit': v}) && mounted) {
      setState(() => _second = !v);
    }
  }

  Future<void> _saveExternal() async {
    final text = _external.text.trim();
    if (text == _savedExternal.trim()) return;
    final before = _savedExternal;
    _savedExternal = text;
    if (!await _patch({'external_company': text.isEmpty ? null : text}) &&
        mounted) {
      _savedExternal = before;
      _external.text = before;
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SearchPickerField<String?>(
            key: const ValueKey('pm-field-reason'),
            value: _reason,
            label: 'Review reason',
            isDense: true,
            options: [
              const SearchOption(value: null, label: 'No reason'),
              for (final (v, l) in widget.reasons)
                SearchOption(value: v, label: l),
            ],
            onChanged: _saving ? null : _saveReason,
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('pm-field-external'),
            controller: _external,
            focusNode: _focus,
            enabled: !_saving,
            onSubmitted: (_) => _saveExternal(),
            decoration: const InputDecoration(
              isDense: true,
              labelText: 'External company',
              helperText: 'Empty means our own team',
            ),
          ),
          const SizedBox(height: 4),
          SwitchListTile(
            key: const ValueKey('pm-field-second-visit'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: const Text('Needs a 2nd visit'),
            value: _second,
            onChanged: _saving ? null : _saveSecond,
          ),
          if (_error != null)
            Text(
              _error!,
              key: const ValueKey('pm-field-error'),
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
            ),
        ],
      ),
    );
  }
}
