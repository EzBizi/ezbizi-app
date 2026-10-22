import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/device_zone.dart';
import '../../core/job_money.dart';
import '../../core/job_status.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/date_range_picker.dart' show DateSpan, dayKey;
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'job_detail_panel.dart';
import 'job_labels.dart';
import 'pm_range.dart';
import 'project_management_tab.dart'
    show PmTags, PmTimeline, pmTags, pmTechnician;

/// Project Management › Dashboard (UI queue 127, option 1 and phone 4).
///
/// What happened in a date range, today by default: five numbers from
/// `jobs/project-management/stats`, each a count and the jobs behind it. A
/// number lists its jobs underneath (`?ids[]=`), with what happened, when and
/// who did it; a job opens the same timeline Pending Jobs uses. On a phone the
/// list and the timeline each take the screen, with a back arrow.
class ProjectManagementDashboard extends StatefulWidget {
  const ProjectManagementDashboard({
    super.key,
    required this.api,
    required this.session,
    this.statuses = const [],
    this.cancelled,
    this.labels,
    this.now,
    this.generation = 0,
    this.zone = deviceZone,
  });

  final ApiClient api;
  final Session session;

  /// The organization's status words, for the timeline. Empty: humanized keys.
  final List<JobStatus> statuses;

  /// `jobs.cancelled_statuses`, null until read. Empty: Cancelled counts
  /// nothing, and says why.
  final List<String>? cancelled;

  final JobLabels? labels;
  final DateTime Function()? now;

  /// Bumped by the screen when Settings changes: count again.
  final int generation;

  /// The device's IANA zone, sent so the server's days are the viewer's.
  final String? Function() zone;

  @override
  State<ProjectManagementDashboard> createState() =>
      _ProjectManagementDashboardState();
}

/// The five numbers, in the order drawn: the stats key and its name.
const pmDashboardTiles = [
  ('payments', 'Paid'),
  ('status_changes', 'Status changes'),
  ('tag_changes', 'Tag changes'),
  ('cancellations', 'Cancelled'),
  ('deadlines_due', 'Deadlines due'),
];

class _ProjectManagementDashboardState
    extends State<ProjectManagementDashboard> {
  /// Below this the drill-down and the timeline each take the screen.
  static const _phoneBelow = 600.0;

  /// Every tile the same size, whatever it says (a fixed height, and widths
  /// shared out equally).
  static const _tileHeight = 116.0;

  PmRange _range = PmRange.today;
  String? _branch;

  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _stats;
  int _request = 0;

  /// The chosen tile, its jobs, and the one open.
  String? _tile;
  bool _listing = false;
  String? _listError;
  List<Map<String, dynamic>> _rows = const [];
  int _listRequest = 0;
  String? _openId;

  /// Search in a tile's jobs (Jose: every list gets one), sent as `q` 400 ms
  /// after typing. The server matches it as Pending Jobs' search does.
  final _search = TextEditingController();
  Timer? _typing;

  Me get _me => widget.session.me!;
  DateTime _now() => (widget.now ?? DateTime.now)();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String _) {
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 400), () {
      if (_tile != null) _list(_tile!);
    });
  }

  Widget _searchField({required EdgeInsets padding}) => Padding(
    padding: padding,
    child: Align(
      alignment: Alignment.centerLeft,
      child: SizedBox(
        width: 300,
        child: TextField(
          key: const ValueKey('pm-dash-search'),
          controller: _search,
          onChanged: _typed,
          decoration: const InputDecoration(
            isDense: true,
            prefixIcon: Icon(Icons.search, size: 18),
            hintText: 'Job, customer, address, phone or tech',
          ),
        ),
      ),
    ),
  );

  @override
  void didUpdateWidget(ProjectManagementDashboard old) {
    super.didUpdateWidget(old);
    if (old.generation != widget.generation) _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _stats == null;
      _error = null;
    });

    final response = await widget.api.get('/jobs/project-management/stats', {
      ..._range.query(_now()),
      'timezone': ?widget.zone(),
      'franchise_id': ?_branch,
    });
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data;
      _stats = data is Map ? data.cast<String, dynamic>() : const {};
    });
    if (_tile != null) _list(_tile!);
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
  }

  Map<String, dynamic>? _bucket(String key) {
    final b = _stats?[key];
    return b is Map ? b.cast<String, dynamic>() : null;
  }

  int _count(String key) {
    final c = _bucket(key)?['count'];
    return c is int ? c : 0;
  }

  /// Whether a tile has anything to list.
  List<String> _ids(String key) => _count(key) == 0
      ? const []
      : [
          for (final id in (_bucket(key)?['job_ids'] as List?) ?? const [])
            '$id',
        ];

  /// A tile's jobs, asked for by the stat's name and the same dates, branch
  /// and zone as the numbers (Jose, 2026-10-22: Yesterday's 514 ids broke
  /// the 500 cap). A count of none sends nothing.
  Future<void> _list(String key) async {
    final ids = _ids(key);
    final request = ++_listRequest;
    setState(() {
      _tile = key;
      _listError = null;
      if (ids.isEmpty) {
        _rows = const [];
        _listing = false;
        _openId = null;
      } else {
        _listing = true;
      }
    });
    if (ids.isEmpty) return;

    final response = await widget.api.get('/jobs/project-management', {
      'event': key,
      if (_search.text.trim().isNotEmpty) 'q': _search.text.trim(),
      ..._range.query(_now()),
      'timezone': ?widget.zone(),
      'franchise_id': ?_branch,
    });
    if (!mounted || request != _listRequest) return;

    setState(() {
      _listing = false;
      if (!response.ok) {
        _listError = response.message;
        return;
      }
      final data = response.data;
      _rows = data is List
          ? [
              for (final r in data)
                if (r is Map) r.cast<String, dynamic>(),
            ]
          : const [];
      if (_openId != null && !_rows.any((r) => '${r['id']}' == _openId)) {
        _openId = null;
      }
    });
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

  String _tileLabel(String key) => pmDashboardTiles
      .firstWhere((t) => t.$1 == key, orElse: () => (key, key))
      .$2;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < _phoneBelow;
        final open = _open;

        if (phone && open != null) {
          return PmTimeline(
            key: const ValueKey('pm-timeline'),
            back: true,
            row: open,
            now: _now(),
            me: _me,
            status: _status,
            onClose: () => setState(() => _openId = null),
            onOpenJob: () => _openJob(open),
          );
        }
        if (phone && _tile != null) return _phoneList(context);

        final controls = _controls(context, phone: phone);
        final tiles = _tiles(context, constraints.maxWidth - (phone ? 32 : 48));

        if (phone) {
          return ListView(
            key: const ValueKey('pm-dash'),
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
            children: [
              controls,
              const SizedBox(height: 12),
              AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [tiles, const SizedBox(height: 12), _hint()],
                ),
              ),
            ],
          );
        }

        return Padding(
          key: const ValueKey('pm-dash'),
          padding: const EdgeInsets.fromLTRB(24, 12, 24, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              controls,
              const SizedBox(height: 12),
              Expanded(
                child: AsyncBody(
                  loading: _loading,
                  error: _error,
                  onRetry: _load,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      tiles,
                      const SizedBox(height: 12),
                      Expanded(child: _wideList(context)),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _controls(BuildContext context, {required bool phone}) {
    final branches = branchOptionsInView(_me);
    final range = PmRangeControl(
      key: const ValueKey('tour-pm-dash-range'),
      range: _range,
      now: _now(),
      formats: _me.display,
      narrow: phone,
      onChanged: (r) => _refilter(() => _range = r),
    );
    if (branches.isEmpty) return range;

    final branch = SearchPickerField<String?>(
      key: const ValueKey('pm-dash-branch'),
      value: _branch,
      isDense: true,
      options: [
        const SearchOption(value: null, label: 'All branches'),
        for (final b in branches) SearchOption(value: b.id, label: b.label),
      ],
      onChanged: (v) {
        if (v == _branch) return;
        _refilter(() => _branch = v);
      },
    );

    if (phone) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [range, const SizedBox(height: 8), branch],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        range,
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(width: 260, child: branch),
        ),
      ],
    );
  }

  /// Five across on a wide screen, two to a row on a phone (phone 4).
  Widget _tiles(BuildContext context, double width) {
    final perRow = width < _phoneBelow - 32 ? 2 : 5;
    const gap = 12.0;
    final tileWidth = (width - gap * (perRow - 1)) / perRow;

    return Wrap(
      key: const ValueKey('tour-pm-dash-tiles'),
      spacing: gap,
      runSpacing: gap,
      children: [
        for (final (key, label) in pmDashboardTiles)
          SizedBox(
            width: tileWidth,
            height: _tileHeight,
            child: _tileCard(context, key, label),
          ),
      ],
    );
  }

  Widget _tileCard(BuildContext context, String key, String label) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final selected = _tile == key;
    final amount = key == 'payments' ? (_bucket(key)?['amount_minor']) : null;
    final unset = key == 'cancellations' && widget.cancelled?.isEmpty == true;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return Material(
      color: selected ? scheme.primaryContainer : scheme.surface,
      shape: RoundedRectangleBorder(
        side: BorderSide(
          color: selected ? scheme.primary : scheme.outlineVariant,
          width: selected ? 2 : 1,
        ),
        borderRadius: BorderRadius.circular(12),
      ),
      child: InkWell(
        key: ValueKey('pm-tile-$key'),
        borderRadius: BorderRadius.circular(12),
        onTap: () => _list(key),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: scheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                formatCount(_count(key)),
                key: ValueKey('pm-tile-$key-count'),
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (amount is int)
                Text(
                  formatMoney(amount),
                  key: const ValueKey('pm-tile-payments-amount'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                )
              else if (unset)
                Text(
                  'Choose which statuses mean cancelled in Settings',
                  key: const ValueKey('pm-tile-cancellations-hint'),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
            ],
          ),
        ),
      ),
    );
  }

  bool get _empty =>
      _stats != null && pmDashboardTiles.every((t) => _count(t.$1) == 0);

  /// Under the tiles before one is chosen, or when the range is empty.
  Widget _hint() {
    final theme = Theme.of(context);
    final text = _empty
        ? 'Nothing happened in these dates.'
        : 'Choose a number to list its jobs.';
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 24),
      child: Text(
        text,
        key: ValueKey(_empty ? 'pm-dash-empty' : 'pm-dash-choose'),
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _wideList(BuildContext context) {
    if (_empty || _tile == null) {
      return Align(alignment: Alignment.topCenter, child: _hint());
    }

    final scheme = Theme.of(context).colorScheme;
    final open = _open;

    return DecoratedBox(
      key: const ValueKey('tour-pm-dash-list'),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _searchField(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                  ),
                  _headerRow(context),
                  Divider(height: 1, color: scheme.outlineVariant),
                  Expanded(child: _listBody(context, table: true)),
                ],
              ),
            ),
            if (open != null) ...[
              VerticalDivider(width: 1, color: scheme.outlineVariant),
              SizedBox(
                width: 360,
                child: PmTimeline(
                  key: const ValueKey('pm-timeline'),
                  row: open,
                  now: _now(),
                  me: _me,
                  status: _status,
                  onClose: () => setState(() => _openId = null),
                  onOpenJob: () => _openJob(open),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// The chosen tile's jobs, full screen on a phone, with a way back.
  Widget _phoneList(BuildContext context) {
    final theme = Theme.of(context);
    final key = _tile!;

    return Column(
      key: const ValueKey('tour-pm-dash-list'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 16, 4),
          child: Row(
            children: [
              IconButton(
                key: const ValueKey('pm-dash-back'),
                tooltip: 'Back',
                onPressed: () => setState(() {
                  _tile = null;
                  _rows = const [];
                }),
                icon: const Icon(Icons.arrow_back),
              ),
              Expanded(
                child: Text(
                  '${_tileLabel(key)} · ${formatCount(_count(key))}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
        ),
        _searchField(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8)),
        Divider(height: 1, color: theme.colorScheme.outlineVariant),
        Expanded(child: _listBody(context, table: false)),
      ],
    );
  }

  Widget _listBody(BuildContext context, {required bool table}) {
    final theme = Theme.of(context);
    if (_listing || _listError != null) {
      return AsyncBody(
        loading: _listing,
        error: _listError,
        onRetry: () => _list(_tile!),
        child: const SizedBox.shrink(),
      );
    }
    if (_rows.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _search.text.trim().isNotEmpty
              ? 'No jobs here match “${_search.text.trim()}”.'
              : 'No jobs for ${_tileLabel(_tile!).toLowerCase()} in these dates.',
          key: const ValueKey('pm-dash-none'),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      );
    }

    final lines = [for (final r in _rows) (row: r, step: _stepFor(r))];
    return ListView.builder(
      padding: table
          ? EdgeInsets.zero
          : const EdgeInsets.fromLTRB(16, 8, 16, 16),
      itemCount: lines.length,
      itemBuilder: (context, i) => table
          ? _tableRow(context, lines[i].row, lines[i].step)
          : _card(context, lines[i].row, lines[i].step),
    );
  }

  /// What the tile counted for this job: the latest step of its kind inside
  /// the dates, as the server words it.
  ({String what, String when, String by}) _stepFor(Map<String, dynamic> r) {
    final formats = _me.display;
    final now = _now();

    if (_tile == 'deadlines_due') {
      final waiting = r['waiting'];
      final day = parseCalendarDay(
        waiting is Map ? waiting['deadline'] as String? : null,
      );
      return (
        what: 'Cancels unless something is paid',
        when: day == null ? '—' : formats.formatDate(day),
        by: '—',
      );
    }

    // Entering a watched status is a status change too, with from, to and
    // `what` since 2026-10-22 (a job whose only change was its entry read "—").
    final kinds = switch (_tile) {
      'payments' => const {'payment'},
      'tag_changes' => const {'tags'},
      _ => const {'status', 'entered'},
    };
    final span = _range.span(now);
    final steps = [
      for (final e in (r['timeline'] as List?) ?? const [])
        if (e is Map && kinds.contains(e['kind']) && _inside(e['at'], span))
          if (_tile != 'cancellations' ||
              (widget.cancelled ?? const []).contains('${e['to']}'))
            e.cast<String, dynamic>(),
    ];
    if (steps.isEmpty) return (what: '—', when: '—', by: '—');

    final step = steps.last;
    final money = step['amount_minor'];
    // The server's sentence, in the organization's status words (0d70ec402).
    final what = [
      '${step['what'] ?? step['kind'] ?? ''}',
      if (money is int) formatMoney(money),
    ].join(' · ');
    final by = step['by'];
    return (
      what: what,
      when: formatListStamp(step['at'], '—', formats: formats, now: now),
      by: by is String && by.isNotEmpty ? by : '—',
    );
  }

  static bool _inside(Object? at, DateSpan span) {
    final t = at is String ? DateTime.tryParse(at)?.toLocal() : null;
    if (t == null) return false;
    final day = dayKey(t);
    return day.compareTo(span.fromKey) >= 0 && day.compareTo(span.toKey) <= 0;
  }

  static const _flex = [4, 3, 3, 5, 2, 2];

  Widget _cells(List<Widget> cells) => Row(
    children: [
      for (final (i, c) in cells.indexed)
        Expanded(
          flex: _flex[i],
          child: Padding(
            padding: const EdgeInsets.only(right: 10),
            child: Align(alignment: Alignment.centerLeft, child: c),
          ),
        ),
    ],
  );

  Widget _headerRow(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
      letterSpacing: 0.4,
    );
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
      child: _cells([
        for (final h in const [
          'JOB',
          'CUSTOMER',
          'TECHNICIAN',
          'WHAT',
          'WHEN',
          'BY',
        ])
          Text(h, style: style),
      ]),
    );
  }

  Widget _tableRow(
    BuildContext context,
    Map<String, dynamic> r,
    ({String what, String when, String by}) step,
  ) {
    final scheme = Theme.of(context).colorScheme;
    final id = '${r['id']}';

    return Material(
      color: id == _openId ? scheme.primaryContainer : scheme.surface,
      child: InkWell(
        key: ValueKey('pm-dash-row-$id'),
        onTap: () => setState(() => _openId = id),
        child: Container(
          decoration: BoxDecoration(
            border: Border(
              bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
          child: _cells([
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
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
                if (pmTags(r).isNotEmpty) ...[
                  const SizedBox(height: 4),
                  PmTags(pmTags(r), key: ValueKey('pm-tags-$id')),
                ],
              ],
            ),
            _one(_name(r['customer'])),
            _one(pmTechnician(r) ?? '—'),
            _one(step.what),
            _one(step.when),
            _one(step.by),
          ]),
        ),
      ),
    );
  }

  Widget _card(
    BuildContext context,
    Map<String, dynamic> r,
    ({String what, String when, String by}) step,
  ) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final id = '${r['id']}';

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: scheme.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: InkWell(
          key: ValueKey('pm-dash-card-$id'),
          borderRadius: BorderRadius.circular(10),
          onTap: () => setState(() => _openId = id),
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
                // The second line: who works it, and its tags.
                if (pmTechnician(r) != null || pmTags(r).isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      if (pmTechnician(r) != null)
                        Text(
                          pmTechnician(r)!,
                          key: ValueKey('pm-dash-tech-$id'),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      if (pmTags(r).isNotEmpty)
                        PmTags(pmTags(r), key: ValueKey('pm-tags-$id')),
                    ],
                  ),
                ],
                const SizedBox(height: 2),
                Text(step.what, maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 2),
                Text(
                  [step.when, step.by].where((s) => s != '—').join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _one(String text) =>
      Text(text, maxLines: 1, overflow: TextOverflow.ellipsis);

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
