import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/device_zone.dart';
import '../../core/job_status.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'job_detail_panel.dart';
import 'job_labels.dart';
import 'pm_frame.dart';
import 'pm_range.dart';
import 'project_management_tab.dart' show PmRuleLink, PmTimeline, pmLogOutcome;

/// Project Management › Logs (UI queue 139, option 1 and phone 4): what
/// EzBizi and people changed on jobs in a date range, and why a rule did not.
///
/// From `jobs/project-management/logs`, newest first. The outcome segment
/// opens on Changes (rules that did something, and edits by hand), which is
/// every row the server sends without an outcome, less the skipped ones.
/// Skipped runs outnumber the rest by far, so under Changes they fold into
/// one grey line per rule from `meta.skipped_by_rule`, whose Why? lists the
/// reasons counted, in place. Skipped lists them one by one.
///
/// A job number opens the timeline Pending Jobs uses, read with `ids[]`; a
/// rule's name opens the rule in Automations. On a phone the rows are cards
/// and the timeline takes the screen, with a back arrow.
class ProjectManagementLogs extends StatefulWidget {
  const ProjectManagementLogs({
    super.key,
    required this.api,
    required this.session,
    this.statuses = const [],
    this.labels,
    this.now,
    this.generation = 0,
    this.zone = deviceZone,
  });

  final ApiClient api;
  final Session session;

  /// The organization's status words, for the timeline.
  final List<JobStatus> statuses;

  final JobLabels? labels;
  final DateTime Function()? now;

  /// Bumped by the screen when Settings changes: read again.
  final int generation;

  /// The device's IANA zone, sent so the server's days are the viewer's.
  final String? Function() zone;

  @override
  State<ProjectManagementLogs> createState() => _ProjectManagementLogsState();
}

/// The outcome segment, in the order drawn: its key and its name.
const pmLogViews = [
  ('changes', 'Changes'),
  ('failed', 'Failed'),
  ('skipped', 'Skipped'),
  ('all', 'All'),
];

class _ProjectManagementLogsState extends State<ProjectManagementLogs> {
  static const _phoneBelow = 600.0;

  PmRange _range = PmRange.today;
  String? _branch;
  String? _rule;
  String _view = 'changes';

  /// Search (Jose: every list gets one), sent as `q` 400 ms after typing.
  final _search = TextEditingController();
  Timer? _typing;
  String get _q => _search.text.trim();

  bool _loading = true;
  String? _error;
  int _request = 0;

  List<Map<String, dynamic>> _rows = const [];
  Map<String, int> _counts = const {};
  List<Map<String, dynamic>> _skipped = const [];

  /// Every rule seen in this tab, for the Rule picker: id to name.
  final Map<String, String> _rules = {};

  /// The folded lines whose reasons are showing, by rule id.
  final Set<String> _why = {};

  /// The job whose timeline is open, and its Pending Jobs row once read.
  String? _openId;
  Map<String, dynamic>? _openRow;
  String? _openError;
  int _openRequest = 0;

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
    _typing = Timer(const Duration(milliseconds: 400), _load);
  }

  @override
  void didUpdateWidget(ProjectManagementLogs old) {
    super.didUpdateWidget(old);
    if (old.generation != widget.generation) _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/jobs/project-management/logs', {
      ..._range.query(_now()),
      'timezone': ?widget.zone(),
      'franchise_id': ?_branch,
      'automation_id': ?_rule,
      if (_q.isNotEmpty) 'q': _q,
      // Changes and All are one call: every row, skipped hidden here.
      if (_view == 'failed' || _view == 'skipped') 'outcome': _view,
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
      final skipped = meta is Map ? meta['skipped_by_rule'] : null;
      _skipped = skipped is List
          ? [
              for (final s in skipped)
                if (s is Map) s.cast<String, dynamic>(),
            ]
          : const [];
      for (final r in [..._rows, ..._skipped]) {
        final rule = r['rule'];
        if (rule is Map && rule['id'] is String) {
          _rules['${rule['id']}'] = '${rule['name'] ?? 'A rule'}';
        }
      }
    });
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
  }

  int _count(String key) => _counts[key] ?? 0;

  String _viewLabel(String key, String label) => switch (key) {
    'changes' =>
      '$label · ${formatCount(_count('succeeded') + _count('manual'))}',
    'failed' || 'skipped' => '$label · ${formatCount(_count(key))}',
    _ => label,
  };

  List<Map<String, dynamic>> get _shown => _view == 'changes'
      ? [
          for (final r in _rows)
            if (r['outcome'] != 'skipped') r,
        ]
      : _rows;

  /// The folded skipped lines, under Changes only. The server's search
  /// narrows them with the rows.
  List<Map<String, dynamic>> get _folded =>
      _view == 'changes' ? _skipped : const [];

  bool get _nothing =>
      _q.isEmpty &&
      !_loading &&
      _error == null &&
      _rows.isEmpty &&
      ['succeeded', 'failed', 'skipped', 'manual'].every((k) => _count(k) == 0);

  String _status(Object? value) {
    if (value == null) return '—';
    for (final s in widget.statuses) {
      if (s.value == '$value') return s.label;
    }
    return jobStatusLabel(null, '$value');
  }

  /// The job's Pending Jobs row, which carries the timeline.
  Future<void> _openJob(String id) async {
    final request = ++_openRequest;
    setState(() {
      _openId = id;
      _openRow = null;
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
      _openRow = row.cast<String, dynamic>();
    });
  }

  void _close() => setState(() {
    _openId = null;
    _openRow = null;
    _openError = null;
    _openRequest++;
  });

  Future<void> _openDetail(Map<String, dynamic> row) async {
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

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < _phoneBelow;
        if (phone && _openId != null) return _pane(back: true);
        final pad = PmFrame.gutter(constraints.maxWidth);
        final c = _controls(context, phone: phone);
        final scheme = Theme.of(context).colorScheme;

        final Widget content;
        if (phone) {
          content = ListView(
            key: const ValueKey('pm-logs'),
            padding: EdgeInsets.fromLTRB(pad, 0, pad, 16),
            children: [
              AsyncBody(
                loading: _loading && _rows.isEmpty,
                error: _error,
                onRetry: _load,
                child: Column(
                  key: const ValueKey('tour-pm-logs-list'),
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: _items(context, phone: true),
                ),
              ),
            ],
          );
        } else {
          content = Padding(
            key: const ValueKey('pm-logs'),
            padding: EdgeInsets.fromLTRB(pad, 0, pad, 16),
            child: AsyncBody(
              loading: _loading && _rows.isEmpty,
              error: _error,
              onRetry: _load,
              child: DecoratedBox(
                key: const ValueKey('tour-pm-logs-list'),
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
                              child: ListView(
                                children: _items(context, phone: false),
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (_openId != null) ...[
                        VerticalDivider(width: 1, color: scheme.outlineVariant),
                        SizedBox(width: 360, child: _pane(back: false)),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          );
        }

        return PmFrame(
          tab: 'logs',
          purpose:
              'What EzBizi and people changed on jobs, and why a rule '
              'did not.',
          scopeText:
              'Every rule run on a job in your branches, and every edit a '
              'person made to a job, by the day on this device.',
          search: c.search,
          place: c.branch,
          filters: c.filters,
          child: content,
        );
      },
    );
  }

  /// The open job's timeline, or its loading and refusal.
  Widget _pane({required bool back}) {
    final row = _openRow;
    if (row != null) {
      return PmTimeline(
        key: const ValueKey('pm-timeline'),
        back: back,
        row: row,
        now: _now(),
        me: _me,
        status: _status,
        onClose: _close,
        onOpenJob: () => _openDetail(row),
      );
    }
    return ColoredBox(
      key: const ValueKey('pm-logs-pane'),
      color: Theme.of(context).colorScheme.surface,
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
          Expanded(
            child: AsyncBody(
              loading: _openError == null,
              error: _openError,
              onRetry: () => _openJob(_openId!),
              child: const SizedBox.shrink(),
            ),
          ),
        ],
      ),
    );
  }

  ({Widget search, Widget? branch, List<Widget> filters}) _controls(
    BuildContext context, {
    required bool phone,
  }) {
    final branches = branchOptionsInView(_me);
    final range = PmRangeControl(
      key: const ValueKey('tour-pm-logs-range'),
      range: _range,
      now: _now(),
      formats: _me.display,
      narrow: phone,
      onChanged: (r) => _refilter(() => _range = r),
    );

    final search = TextField(
      key: const ValueKey('pm-logs-search'),
      controller: _search,
      onChanged: _typed,
      decoration: const InputDecoration(
        isDense: true,
        prefixIcon: Icon(Icons.search, size: 18),
        hintText: 'Job, customer, rule or person',
      ),
    );

    final pickers = [
      if (branches.isNotEmpty)
        SearchPickerField<String?>(
          key: const ValueKey('pm-logs-branch'),
          value: _branch,
          isDense: true,
          options: [
            const SearchOption(value: null, label: 'All branches'),
            for (final b in branches) SearchOption(value: b.id, label: b.label),
          ],
          onChanged: (v) {
            if (v != _branch) _refilter(() => _branch = v);
          },
        ),
      SearchPickerField<String?>(
        key: const ValueKey('pm-logs-rule'),
        value: _rule,
        isDense: true,
        options: [
          const SearchOption(value: null, label: 'Any rule'),
          for (final e
              in _rules.entries.toList()
                ..sort((a, b) => a.value.compareTo(b.value)))
            SearchOption(value: e.key, label: e.value),
        ],
        onChanged: (v) {
          if (v != _rule) _refilter(() => _rule = v);
        },
      ),
    ];

    final Widget outcome = phone
        ? SearchPickerField<String>(
            key: const ValueKey('pm-logs-outcome'),
            value: _view,
            isDense: true,
            options: [
              for (final (key, label) in pmLogViews)
                SearchOption(value: key, label: _viewLabel(key, label)),
            ],
            onChanged: (v) {
              if (v != null && v != _view) _refilter(() => _view = v);
            },
          )
        : SegmentedButton<String>(
            key: const ValueKey('pm-logs-outcome'),
            showSelectedIcon: false,
            segments: [
              for (final (key, label) in pmLogViews)
                ButtonSegment(
                  value: key,
                  label: Text(
                    _viewLabel(key, label),
                    key: ValueKey('pm-logs-outcome-$key'),
                  ),
                ),
            ],
            selected: {_view},
            onSelectionChanged: (s) {
              if (s.first != _view) _refilter(() => _view = s.first);
            },
          );

    Widget sized(Widget w) => phone ? w : SizedBox(width: 240, child: w);
    return (
      search: search,
      branch: branches.isEmpty ? null : pickers.first,
      filters: [
        sized(pickers.last),
        KeyedSubtree(
          key: const ValueKey('tour-pm-logs-outcome'),
          child: outcome,
        ),
        range,
      ],
    );
  }

  List<Widget> _items(BuildContext context, {required bool phone}) {
    if (_nothing) {
      return [
        _sentence(
          context,
          'Nothing happened in these dates.',
          const ValueKey('pm-logs-empty'),
        ),
      ];
    }
    final rows = _shown;
    final folded = _folded;
    if (rows.isEmpty && folded.isEmpty) {
      return [
        _sentence(context, switch (_view) {
          _ when _q.isNotEmpty => 'Nothing here matches “$_q”.',
          'failed' => 'Nothing failed in these dates.',
          'skipped' => 'Nothing was skipped in these dates.',
          _ => 'Nothing changed in these dates.',
        }, const ValueKey('pm-logs-none')),
      ];
    }
    return [
      for (final (i, r) in rows.indexed)
        phone ? _card(context, i, r) : _tableRow(context, i, r),
      for (final s in folded) _fold(context, s, phone: phone),
    ];
  }

  Widget _sentence(BuildContext context, String text, Key key) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        text,
        key: key,
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  static const _flex = [2, 2, 3, 6, 4, 2];

  Widget _cells(List<Widget> cells) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      for (final (i, c) in cells.indexed)
        Expanded(
          flex: _flex[i],
          child: Padding(
            padding: const EdgeInsets.only(right: 10),
            child: Align(alignment: Alignment.topLeft, child: c),
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
          'WHEN',
          'JOB',
          'CUSTOMER',
          'WHAT CHANGED',
          'BY',
          'OUTCOME',
        ])
          Text(h, style: style),
      ]),
    );
  }

  Map<String, dynamic> _job(Map<String, dynamic> r) =>
      r['job'] is Map ? (r['job'] as Map).cast<String, dynamic>() : const {};

  /// What changed: the run's steps (`lines`) when the server sends them, a
  /// failed or skipped step in its tone; otherwise each change's sentence.
  List<({String text, String? outcome})> _what(Map<String, dynamic> r) {
    final lines = r['lines'];
    if (lines is List && lines.isNotEmpty) {
      return [
        for (final l in lines)
          if (l is Map && l['what'] is String && '${l['what']}'.isNotEmpty)
            (text: '${l['what']}', outcome: l['outcome'] as String?),
      ];
    }
    return [
      for (final c in (r['changes'] as List?) ?? const [])
        if (c is Map && c['detail'] is String && '${c['detail']}'.isNotEmpty)
          (text: '${c['detail']}', outcome: null),
    ];
  }

  Widget _line(BuildContext context, ({String text, String? outcome}) w) {
    final theme = Theme.of(context);
    final color = switch (w.outcome) {
      'failed' => theme.colorScheme.error,
      'skipped' => theme.colorScheme.onSurfaceVariant,
      _ => null,
    };
    return Text(w.text, style: color == null ? null : TextStyle(color: color));
  }

  String _when(Map<String, dynamic> r) =>
      formatListStamp(r['at'], '—', formats: _me.display, now: _now());

  /// The job number, which opens its timeline.
  Widget _jobLink(BuildContext context, int i, Map<String, dynamic> r) {
    final job = _job(r);
    final id = job['id'];
    final number = '${job['number'] ?? '—'}';
    final style = TextStyle(
      fontWeight: FontWeight.w700,
      color: id is String ? Theme.of(context).colorScheme.primary : null,
    );
    final text = Text(
      number,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: style,
    );
    if (id is! String) return text;
    return Semantics(
      link: true,
      child: InkWell(
        key: ValueKey('pm-logs-job-$i'),
        borderRadius: BorderRadius.circular(4),
        onTap: () => _openJob(id),
        child: text,
      ),
    );
  }

  /// The rule as a link, or the person who made the change.
  Widget _by(BuildContext context, int i, Map<String, dynamic> r) {
    if (r['kind'] == 'automation' && r['rule'] is Map) {
      final rule = r['rule'] as Map;
      return PmRuleLink(
        key: ValueKey('pm-logs-rule-$i'),
        label: '${rule['name'] ?? 'A rule'}',
        rule: rule,
        me: _me,
      );
    }
    final by = r['by'];
    return Text(
      by is String && by.isNotEmpty ? by : '—',
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
    );
  }

  Widget _chip(int i, Map<String, dynamic> r) {
    final o = pmLogOutcome(r['outcome']);
    return StatusPill(
      o.label,
      key: ValueKey('pm-logs-outcome-$i'),
      tone: o.tone,
    );
  }

  /// Why it failed (in the error tone) or was skipped, under the row.
  Widget? _reason(BuildContext context, int i, Map<String, dynamic> r) {
    final reason = r['reason'];
    if (reason is! String || reason.isEmpty) return null;
    if (r['outcome'] != 'failed' && r['outcome'] != 'skipped') return null;
    final theme = Theme.of(context);
    final failed = r['outcome'] == 'failed';
    return Text(
      reason,
      key: ValueKey('pm-logs-reason-$i'),
      style: theme.textTheme.bodySmall?.copyWith(
        color: failed
            ? theme.colorScheme.error
            : theme.colorScheme.onSurfaceVariant,
        fontWeight: failed ? FontWeight.w600 : null,
      ),
    );
  }

  Widget _tableRow(BuildContext context, int i, Map<String, dynamic> r) {
    final scheme = Theme.of(context).colorScheme;
    final what = _what(r);
    final reason = _reason(context, i, r);
    final open = _openId != null && _job(r)['id'] == _openId;

    return Container(
      key: ValueKey('pm-logs-row-$i'),
      decoration: BoxDecoration(
        color: open ? scheme.primaryContainer : scheme.surface,
        border: Border(
          bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
        ),
      ),
      padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cells([
            Text(_when(r), maxLines: 1, overflow: TextOverflow.ellipsis),
            _jobLink(context, i, r),
            Text(
              '${_job(r)['customer'] ?? '—'}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (what.isEmpty) const Text('—'),
                for (final w in what) _line(context, w),
              ],
            ),
            _by(context, i, r),
            _chip(i, r),
          ]),
          if (reason != null)
            Padding(padding: const EdgeInsets.only(top: 4), child: reason),
        ],
      ),
    );
  }

  Widget _card(BuildContext context, int i, Map<String, dynamic> r) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final what = _what(r);
    final reason = _reason(context, i, r);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return Padding(
      key: ValueKey('pm-logs-row-$i'),
      padding: const EdgeInsets.only(bottom: 8),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border.all(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _jobLink(context, i, r),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      '${_job(r)['customer'] ?? '—'}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 8),
                  _chip(i, r),
                ],
              ),
              for (final w in what) ...[
                const SizedBox(height: 2),
                _line(context, w),
              ],
              if (reason != null) ...[const SizedBox(height: 2), reason],
              const SizedBox(height: 4),
              Wrap(
                spacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text('${_when(r)} ·', style: muted),
                  DefaultTextStyle.merge(
                    style: muted,
                    child: _by(context, i, r),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// One grey line for a rule's skipped runs; Why? lists the reasons counted.
  Widget _fold(
    BuildContext context,
    Map<String, dynamic> s, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final rule = s['rule'] is Map ? s['rule'] as Map : const {};
    final id = '${rule['id']}';
    final count = s['count'] is int ? s['count'] as int : 0;
    final open = _why.contains(id);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final reasons = [
      for (final r in (s['reasons'] as List?) ?? const [])
        if (r is Map) r,
    ];

    return Padding(
      key: ValueKey('pm-logs-fold-$id'),
      padding: phone
          ? const EdgeInsets.only(bottom: 8)
          : const EdgeInsets.fromLTRB(8, 6, 8, 0),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 8, 6),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    '${rule['name'] ?? 'A rule'}: skipped '
                    '${formatCount(count)} ${count == 1 ? 'time' : 'times'} ·',
                    style: muted,
                  ),
                  TextButton(
                    key: ValueKey('pm-logs-why-$id'),
                    onPressed: () =>
                        setState(() => open ? _why.remove(id) : _why.add(id)),
                    child: Text(open ? 'Hide why' : 'Why?'),
                  ),
                ],
              ),
              if (open)
                Padding(
                  key: ValueKey('pm-logs-reasons-$id'),
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final r in reasons)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            '${r['reason'] ?? ''} · '
                            '${formatCount(r['count'] is int ? r['count'] as int : 0)}',
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
