import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/job_money.dart';
import '../../core/job_status.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'job_detail_panel.dart';
import 'job_labels.dart';

/// Jobs › Project Management (UI queue: option 1, phone 4; Jose 2026-10-21).
///
/// Every job in a status the organization watches (`meta.watched`, the
/// setting "Statuses that wait on an estimate"), closest to its cancel first,
/// as the server sorts them. A row opens its timeline beside the table; on a
/// phone a card opens it in place of the list.
///
/// One request, unpaginated (about a thousand rows). The stage is filtered
/// here rather than on the server so the segment's counts are of the rows
/// loaded; branch and days are the server's filters.
class ProjectManagementTab extends StatefulWidget {
  const ProjectManagementTab({
    super.key,
    required this.api,
    required this.session,
    this.statuses = const [],
    this.labels,
    this.now,
  });

  final ApiClient api;
  final Session session;

  /// The organization's status words, for the timeline. Empty: humanized keys.
  final List<JobStatus> statuses;

  final JobLabels? labels;

  /// The clock the countdown reads. Injectable because the countdown is the
  /// behaviour, and a test that cannot move "now" asserts nothing about it.
  final DateTime Function()? now;

  @override
  State<ProjectManagementTab> createState() => _ProjectManagementTabState();
}

/// The four stages, in the segment's order.
const pmStages = ['waiting', 'partial', 'full', 'closed'];

String pmStageLabel(String? stage) => switch (stage) {
  'waiting' => 'Waiting',
  'partial' => 'Partly paid',
  'full' => 'Paid in full',
  'closed' => 'Closed',
  _ => stage ?? '—',
};

Tone pmStageTone(String? stage) => switch (stage) {
  'waiting' => Tone.caution,
  'partial' => Tone.info,
  'full' => Tone.ok,
  _ => Tone.neutral,
};

/// The DEADLINE column's words and tone, from a row's `waiting`.
///
/// Running: "cancels in N days", amber under 7 days and red under 2, in hours
/// under a day, then "Due today" on the deadline's own day and "Overdue"
/// after it, read in local time. Stopped: "Paid · stopped". Closed: "Closed".
({String text, Tone tone}) pmCountdown(Object? waiting, DateTime now) {
  if (waiting is! Map) return (text: '—', tone: Tone.neutral);

  switch (waiting['timer']) {
    case 'stopped':
      return (text: 'Paid · stopped', tone: Tone.ok);
    case 'closed':
      return (text: 'Closed', tone: Tone.neutral);
    case 'running':
      break;
    default:
      return (text: '—', tone: Tone.neutral);
  }

  final raw = waiting['deadline_at'];
  final at = raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
  if (at == null) return (text: '—', tone: Tone.neutral);

  final local = now.toLocal();
  final left = at.difference(local);

  if (!left.isNegative && left > Duration.zero) {
    if (left < const Duration(days: 1)) {
      final hours = left.inHours < 1 ? 1 : left.inHours;
      return (
        text: 'cancels in $hours ${hours == 1 ? 'hour' : 'hours'}',
        tone: Tone.bad,
      );
    }
    final days = left.inDays;
    return (
      text: 'cancels in $days ${days == 1 ? 'day' : 'days'}',
      tone: left < const Duration(days: 2)
          ? Tone.bad
          : left < const Duration(days: 7)
          ? Tone.caution
          : Tone.neutral,
    );
  }

  final sameDay =
      at.year == local.year && at.month == local.month && at.day == local.day;
  return (text: sameDay ? 'Due today' : 'Overdue', tone: Tone.bad);
}

class _ProjectManagementTabState extends State<ProjectManagementTab> {
  /// Below this the table has no room, and the rows are cards.
  static const _tableFrom = 760.0;

  bool _loading = true;
  String? _error;
  int _request = 0;

  List<Map<String, dynamic>> _rows = const [];
  List<String> _watched = const [];
  int _deadlineDays = 30;

  /// The segment: a stage, or null for All. Waiting first, as drawn.
  String? _stage = 'waiting';
  String? _branch;
  int? _minDays;

  String? _openId;

  Me get _me => widget.session.me!;

  DateTime _now() => (widget.now ?? DateTime.now)();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/jobs/project-management', {
      'franchise_id': ?_branch,
      if (_minDays != null) 'min_days': '$_minDays',
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
      final watched = meta is Map ? meta['watched'] : null;
      _watched = watched is List ? [for (final w in watched) '$w'] : const [];
      final days = meta is Map ? meta['deadline_days'] : null;
      _deadlineDays = days is int ? days : 30;
      if (_openId != null && !_rows.any((r) => '${r['id']}' == _openId)) {
        _openId = null;
      }
    });
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
  }

  Map<String, int> get _counts {
    final counts = {for (final s in pmStages) s: 0};
    for (final r in _rows) {
      final s = '${r['stage']}';
      if (counts.containsKey(s)) counts[s] = counts[s]! + 1;
    }
    return counts;
  }

  List<Map<String, dynamic>> get _shown => _stage == null
      ? _rows
      : [
          for (final r in _rows)
            if (r['stage'] == _stage) r,
        ];

  Map<String, dynamic>? get _open {
    for (final r in _rows) {
      if ('${r['id']}' == _openId) return r;
    }
    return null;
  }

  String _status(Object? value) => value == null ? '—' : _label('$value');

  String _label(String value) {
    for (final s in widget.statuses) {
      if (s.value == value) return s.label;
    }
    return jobStatusLabel(null, value);
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= _tableFrom;
        final open = _open;

        // A phone draws the timeline in place of the list.
        if (!wide && open != null) {
          return _Timeline(
            key: const ValueKey('pm-timeline'),
            row: open,
            now: _now(),
            me: _me,
            status: _status,
            onClose: () => setState(() => _openId = null),
            onOpenJob: () => _openJob(open),
          );
        }

        return HeaderAboveList(
          header: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              PanelHeader(note: _note()),
              _segment(context, wide: wide),
              _filters(context, wide: wide),
            ],
          ),
          list: AsyncBody(
            key: const ValueKey('tour-jobs-pm-list'),
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _body(context, wide: wide),
          ),
        );
      },
    );
  }

  String _note() {
    if (_loading || _error != null) {
      return 'Jobs waiting on an estimate, closest to cancelling first.';
    }
    return 'Jobs waiting on an estimate, closest to cancelling first. '
        'With nothing paid, a job cancels $_deadlineDays days after its '
        'estimate.';
  }

  Widget _segment(BuildContext context, {required bool wide}) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final counts = _counts;

    Widget chip(String? value, String label, {int? count, Tone? tone}) =>
        ChoiceChip(
          key: ValueKey('pm-stage-${value ?? 'all'}'),
          avatar: tone == null ? null : ToneDot(tone),
          showCheckmark: false,
          selected: _stage == value,
          label: Text.rich(
            TextSpan(
              text: label,
              children: [
                if (count != null)
                  TextSpan(
                    text: ' · ${formatCount(count)}',
                    style: TextStyle(color: muted, fontWeight: FontWeight.w500),
                  ),
              ],
            ),
          ),
          onSelected: (_) => setState(() => _stage = value),
        );

    final chips = [
      for (final s in pmStages)
        chip(s, pmStageLabel(s), count: counts[s], tone: pmStageTone(s)),
      chip(null, 'All'),
    ];

    return Padding(
      key: const ValueKey('tour-jobs-pm-stages'),
      padding: EdgeInsets.fromLTRB(wide ? 24 : 0, 0, wide ? 24 : 0, 8),
      child: wide
          ? Wrap(spacing: 6, runSpacing: 6, children: chips)
          : SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(
                children: [
                  for (final c in chips)
                    Padding(padding: const EdgeInsets.only(right: 6), child: c),
                ],
              ),
            ),
    );
  }

  Widget _picker<T>({
    required Key key,
    required T value,
    required List<SearchOption<T>> options,
    required ValueChanged<T> onChanged,
    double width = 180,
  }) => SizedBox(
    width: width,
    child: SearchPickerField<T>(
      key: key,
      value: value,
      border: InputBorder.none,
      isDense: true,
      options: options,
      onChanged: (v) {
        if (v == value) return;
        _refilter(() => onChanged(v as T));
      },
    ),
  );

  Widget _filters(BuildContext context, {required bool wide}) {
    final branches = branchOptionsInView(_me);

    return Padding(
      key: const ValueKey('tour-jobs-pm-filters'),
      padding: EdgeInsets.fromLTRB(wide ? 24 : 16, 0, wide ? 24 : 16, 8),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (branches.isNotEmpty)
            _picker<String?>(
              key: const ValueKey('pm-branch'),
              value: _branch,
              width: 220,
              options: [
                const SearchOption(value: null, label: 'All branches'),
                for (final b in branches)
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (v) => _branch = v,
            ),
          _picker<int?>(
            key: const ValueKey('pm-min-days'),
            value: _minDays,
            width: 170,
            options: [
              const SearchOption(value: null, label: 'At least 0 days'),
              for (final d in const [7, 14, 21, 28])
                SearchOption(value: d, label: 'At least $d days'),
            ],
            onChanged: (v) => _minDays = v,
          ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, {required bool wide}) {
    if (_watched.isEmpty) {
      return const _Empty(
        key: ValueKey('pm-unwatched'),
        title: 'Nothing is being watched yet.',
        body:
            'Choose the statuses that wait on an estimate in Settings › All '
            'settings, under Jobs. Their jobs then show here.',
      );
    }

    final rows = _shown;
    if (rows.isEmpty) {
      return _Empty(
        key: const ValueKey('pm-none'),
        title: _stage == null
            ? 'No jobs here.'
            : 'No jobs are ${pmStageLabel(_stage).toLowerCase()}.',
        body: 'Jobs in the watched statuses show here.',
      );
    }

    final now = _now();
    if (!wide) {
      return ListView.builder(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
        itemCount: rows.length,
        itemBuilder: (context, i) => _card(context, rows[i], now),
      );
    }

    final scheme = Theme.of(context).colorScheme;
    final open = _open;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 16),
      child: DecoratedBox(
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
                    _headerRow(context),
                    Divider(height: 1, color: scheme.outlineVariant),
                    Expanded(
                      child: ListView.builder(
                        itemCount: rows.length,
                        itemBuilder: (context, i) =>
                            _tableRow(context, rows[i], now),
                      ),
                    ),
                  ],
                ),
              ),
              if (open != null) ...[
                VerticalDivider(width: 1, color: scheme.outlineVariant),
                SizedBox(
                  width: 360,
                  child: _Timeline(
                    key: const ValueKey('pm-timeline'),
                    row: open,
                    now: now,
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
      ),
    );
  }

  static const _flex = [5, 3, 3, 2, 2, 3];

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
          'BRANCH',
          'STAGE',
          'WAITING',
          'DEADLINE',
        ])
          Text(h, style: style),
      ]),
    );
  }

  Widget _tableRow(BuildContext context, Map<String, dynamic> r, DateTime now) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final id = '${r['id']}';
    final countdown = pmCountdown(r['waiting'], now);
    final selected = id == _openId;

    return Material(
      color: selected ? scheme.primaryContainer : scheme.surface,
      child: InkWell(
        key: ValueKey('pm-row-$id'),
        onTap: () => setState(() => _openId = id),
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
            _one(_name(r['franchise'])),
            StatusPill(
              pmStageLabel(r['stage'] as String?),
              tone: pmStageTone(r['stage'] as String?),
            ),
            _one(_waited(r['waiting'])),
            StatusPill(
              countdown.text,
              key: ValueKey('pm-countdown-$id'),
              tone: countdown.tone,
            ),
          ]),
        ),
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> r, DateTime now) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final id = '${r['id']}';
    final countdown = pmCountdown(r['waiting'], now);

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
          onTap: () => setState(() => _openId = id),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${r['number'] ?? ''} ${_name(r['customer'])}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                    const SizedBox(width: 8),
                    StatusPill(
                      countdown.text,
                      key: ValueKey('pm-countdown-$id'),
                      tone: countdown.tone,
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  [
                    '${r['title'] ?? ''}',
                    pmStageLabel(r['stage'] as String?),
                    _waited(r['waiting']),
                  ].where((s) => s.isNotEmpty && s != '—').join(' · '),
                  maxLines: 2,
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

String _waited(Object? waiting) {
  final days = waiting is Map ? waiting['days_waiting'] : null;
  if (days is! int) return '—';
  return '$days ${days == 1 ? 'day' : 'days'}';
}

class _Empty extends StatelessWidget {
  const _Empty({super.key, required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                title,
                textAlign: TextAlign.center,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                body,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One job's header and its timeline, a dot per event.
class _Timeline extends StatelessWidget {
  const _Timeline({
    super.key,
    required this.row,
    required this.now,
    required this.me,
    required this.status,
    required this.onClose,
    required this.onOpenJob,
  });

  final Map<String, dynamic> row;
  final DateTime now;
  final Me me;
  final String Function(Object?) status;
  final VoidCallback onClose;
  final VoidCallback onOpenJob;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final waiting = row['waiting'];
    final deadline = parseCalendarDay(
      waiting is Map ? waiting['deadline'] as String? : null,
    );
    final events = [
      for (final e in (row['timeline'] as List?) ?? const [])
        if (e is Map) e.cast<String, dynamic>(),
    ];

    final sub = [
      '${row['number'] ?? ''} ${row['title'] ?? ''}'.trim(),
      _name(row['franchise']),
      if (_waited(waiting) != '—') 'waiting ${_waited(waiting)}',
      if (deadline != null) 'cancels on ${me.display.formatDate(deadline)}',
    ].join(' · ');

    return ColoredBox(
      color: scheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _name(row['customer']),
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        sub,
                        key: const ValueKey('pm-timeline-sub'),
                        style: muted,
                      ),
                    ],
                  ),
                ),
                IconButton(
                  key: const ValueKey('pm-timeline-close'),
                  tooltip: 'Close',
                  onPressed: onClose,
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
          ),
          Divider(height: 1, color: scheme.outlineVariant),
          Expanded(
            child: events.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text('Nothing has happened yet.', style: muted),
                  )
                : ListView.builder(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                    itemCount: events.length,
                    itemBuilder: (context, i) => _event(
                      context,
                      events[i],
                      last: i == events.length - 1,
                    ),
                  ),
          ),
          Divider(height: 1, color: scheme.outlineVariant),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton.icon(
                key: const ValueKey('pm-open-job'),
                onPressed: onOpenJob,
                icon: const Icon(Icons.open_in_new, size: 18),
                label: const Text('Open the job'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _event(
    BuildContext context,
    Map<String, dynamic> e, {
    required bool last,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    final (title, tone) = switch (e['kind']) {
      'entered' => ('Entered “${status(e['status'])}”', Tone.info),
      'payment' => (
        e['marks'] == 'full' ? 'Payment · paid in full' : 'Payment · partial',
        Tone.ok,
      ),
      'status' => (
        'Status ${status(e['from'])} → ${status(e['to'])}',
        Tone.neutral,
      ),
      'tags' => ('Tags ${_tags(e['tags'])}', Tone.caution),
      _ => ('${e['kind'] ?? 'Event'}', Tone.neutral),
    };

    final at = e['at'] is String ? DateTime.tryParse(e['at'])?.toLocal() : null;
    final money = e['amount_minor'] ?? e['total_minor'];
    final detail = [
      if (at != null) me.display.formatDate(at),
      if (e['by'] is String && (e['by'] as String).isNotEmpty)
        e['by'] as String,
      if (money is int) formatMoney(money),
    ].join(' · ');

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 18,
            child: Column(
              children: [
                const SizedBox(height: 4),
                Container(
                  width: 10,
                  height: 10,
                  decoration: BoxDecoration(
                    color: ToneColours.of(context, tone).ink,
                    shape: BoxShape.circle,
                  ),
                ),
                if (!last)
                  Expanded(
                    child: Container(width: 2, color: scheme.outlineVariant),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  if (detail.isNotEmpty) Text(detail, style: muted),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  static String _tags(Object? tags) {
    if (tags is! Map) return '';
    final added = [
      for (final t in (tags['added'] as List?) ?? const []) '+ $t',
    ];
    final removed = [
      for (final t in (tags['removed'] as List?) ?? const []) '− $t',
    ];
    return [...added, ...removed].join(', ');
  }
}
