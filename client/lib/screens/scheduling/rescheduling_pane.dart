import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'availability_suggestion.dart';

/// Scheduling › Re-Scheduling (Jose, 2026-10-13: "a separate tab in
/// scheduling called Re-Scheduling where it shows such suggestions and what
/// to replace them for, to better fit a comprehensive route for the
/// technicians"; UI queue 86, desktop 1 and phone 4).
///
/// Each row is a booked job alone or far from other work on its day, with the
/// window beside other work it could move to, biggest saving first (the
/// server's order). Moving changes the customer's appointment in Workiz, so
/// the suggestion is shown first and Move is a second, confirmed step, only
/// for people who can dispatch.
class ReschedulingPane extends StatefulWidget {
  const ReschedulingPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<ReschedulingPane> createState() => _ReschedulingPaneState();
}

class _ReschedulingPaneState extends State<ReschedulingPane> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  int _request = 0;

  /// The area picked, or null for every area. Filtered here: the server's
  /// all-areas answer is one call and already holds every row.
  String? _area;

  /// What the search bar holds; matched against the job's visible words.
  String _query = '';

  /// The open row, by its job; on a phone the list gives way to it.
  String? _open;
  bool _confirming = false;
  bool _moving = false;
  String? _moveError;
  String? _moved;

  @override
  void initState() {
    super.initState();
    if (widget.visible) _load();
  }

  @override
  void didUpdateWidget(covariant ReschedulingPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/reschedules');
    if (!mounted || ticket != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _rows = [
        for (final r in (response.body['data'] as List? ?? const []))
          if (r is Map) r.cast<String, dynamic>(),
      ];
    });
  }

  bool get _canMove {
    final permissions = widget.session.me?.permissions ?? const <String>[];
    return permissions.contains('scheduling.schedule.view') &&
        permissions.contains('scheduling.edit');
  }

  static Map<String, dynamic> _map(Object? v) =>
      (v as Map?)?.cast<String, dynamic>() ?? const {};

  static String _id(Map<String, dynamic> row) {
    final job = _map(row['job']);
    return '${job['workiz_job_id'] ?? job['job_id'] ?? job['lead_id'] ?? job['number']}';
  }

  static const _weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static String _day(Object? ymd) {
    final d = ymd is String ? DateTime.tryParse(ymd) : null;
    if (d == null) return '';
    return '${_weekdays[d.weekday - 1]} ${_months[d.month - 1]} ${d.day}';
  }

  static String _span(Object? start, Object? end) => [
    if (start is String && start.isNotEmpty) JobDotCard.clock(start),
    if (end is String && end.isNotEmpty) JobDotCard.clock(end),
  ].join('–');

  /// A repair or a redo, tagged so the dispatcher sees what is being moved
  /// (Jose, 2026-10-14: "should be included, but tag them appropriately, so
  /// the dispatcher can make an informed decision"). `service` is Workiz's
  /// job type.
  static Widget? _tag(Object? service) {
    final type = service is String ? service : '';
    final lower = type.toLowerCase();
    if (lower.contains('redo')) return StatusPill(type, tone: Tone.bad);
    if (lower.contains('repair')) return StatusPill(type, tone: Tone.caution);
    return null;
  }

  static String _miles(num m) =>
      '${m == m.roundToDouble() ? m.toInt() : m.toStringAsFixed(1)} mi';

  static String _now(Map<String, dynamic> row) {
    final job = _map(row['job']);
    return [
      _day(job['date']),
      _span(job['time'], job['end_time']),
    ].where((s) => s.isNotEmpty).join(' · ');
  }

  static String _then(Map<String, dynamic> row) {
    final s = _map(row['suggested']);
    final w = _map(s['window']);
    return [
      _day(s['date']),
      _span(w['start_time'], w['end_time']),
    ].where((t) => t.isNotEmpty).join(' · ');
  }

  static String _beside(Map<String, dynamic> row) {
    final n = (_map(row['suggested'])['nearby'] as num?)?.toInt() ?? 0;
    return 'beside ${n == 1 ? '1 job' : '$n jobs'}';
  }

  static String _nearest(Map<String, dynamic> row) {
    final now = row['current_nearest_miles'];
    final then = _map(row['suggested'])['nearest_miles'];
    return '${now is num ? _miles(now) : 'alone'} → '
        '${then is num ? _miles(then) : '—'}';
  }

  static String _saves(Map<String, dynamic> row) {
    final now = row['current_nearest_miles'];
    final then = _map(row['suggested'])['nearest_miles'];
    if (now is! num) return 'was alone';
    return then is num ? _miles(now - then) : '';
  }

  Future<void> _move(Map<String, dynamic> row) async {
    final job = _map(row['job']);
    // The Workiz row the dispatch call takes; job_id is EzBizi's own job,
    // which that call does not find (Scheduling, 50a609b6).
    final id = job['workiz_job_id'];
    final s = _map(row['suggested']);
    final w = _map(s['window']);
    if (id == null) return;

    setState(() {
      _moving = true;
      _moveError = null;
    });
    final response = await widget.api.post(
      '/scheduling/board/jobs/$id/reschedule',
      {
        'date': s['date'],
        'start_time': w['start_time'],
        'end_time': w['end_time'],
      },
    );
    if (!mounted) return;

    setState(() {
      _moving = false;
      if (!response.ok) {
        _moveError = response.message;
        return;
      }
      _moved = '#${job['number'] ?? ''} moved to ${_then(row)}.';
      _confirming = false;
      _open = null;
    });
    if (response.ok) await _load();
  }

  /// The row's number, service, customer, address and area hold the term.
  bool _matches(Map<String, dynamic> row) {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return true;
    final job = _map(row['job']);
    final customer = _map(job['customer']);
    return [
      job['number'],
      job['service'],
      job['status'],
      customer['name'],
      customer['phone'],
      customer['address'],
      row['area_name'],
    ].any((v) => v != null && '$v'.toLowerCase().contains(needle));
  }

  void _select(Map<String, dynamic> row) => setState(() {
    _open = _id(row);
    _confirming = false;
    _moveError = null;
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final areas = <String, String>{
      for (final r in _rows)
        if (r['area_id'] is String) r['area_id'] as String: '${r['area_name']}',
    };
    final inArea = [
      for (final r in _rows)
        if (_area == null || r['area_id'] == _area) r,
    ];
    final rows = [
      for (final r in inArea)
        if (_matches(r)) r,
    ];

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, box) {
          final wide = box.maxWidth >= 900;
          Map<String, dynamic>? open;
          for (final r in rows) {
            if (_id(r) == _open) open = r;
          }
          if (wide && open == null && rows.isNotEmpty) open = rows.first;

          // On a phone the open job replaces the list, inside the tab.
          if (!wide && open != null) {
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    key: const Key('rescheduling-back'),
                    onPressed: () => setState(() => _open = null),
                    icon: const Icon(Icons.arrow_back),
                    label: const Text('Re-Scheduling'),
                  ),
                ),
                _detail(theme, open, phone: true),
              ],
            );
          }

          final bar = Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Wrap(
              spacing: 12,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SizedBox(
                  width: 240,
                  child: SearchPickerField<String?>(
                    key: const Key('rescheduling-area'),
                    value: _area,
                    label: 'Area',
                    options: [
                      SearchOption<String?>(
                        value: null,
                        label: 'All areas · ${_rows.length}',
                      ),
                      for (final MapEntry(:key, :value) in areas.entries)
                        SearchOption<String?>(
                          value: key,
                          label:
                              '$value · ${_rows.where((r) => r['area_id'] == key).length}',
                        ),
                    ],
                    onChanged: (v) => setState(() {
                      _area = v;
                      _open = null;
                    }),
                  ),
                ),
                // Standard field height, as tall as Leads search (Jose, 2026-10-27).
                SizedBox(
                  width: 240,
                  child: TextField(
                    key: const Key('rescheduling-search'),
                    textAlignVertical: TextAlignVertical.center,
                    decoration: const InputDecoration(
                      isDense: true,
                      prefixIconConstraints: BoxConstraints(minWidth: 36),
                      prefixIcon: Icon(Icons.search, size: 18),
                      hintText: 'Find a job',
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (v) => setState(() {
                      _query = v;
                      _open = null;
                    }),
                  ),
                ),
                Text(
                  rows.isEmpty ? '' : 'Biggest saving first',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                IconButton(
                  tooltip: 'Refresh',
                  onPressed: _load,
                  icon: const Icon(Icons.refresh),
                ),
              ],
            ),
          );

          final moved = _moved == null
              ? null
              : Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                  child: Text(
                    _moved!,
                    key: const Key('rescheduling-moved'),
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: ToneColours.of(context, Tone.ok).ink,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                );

          if (inArea.isNotEmpty && rows.isEmpty) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                bar,
                ?moved,
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                  child: Text(
                    'No jobs match "${_query.trim()}".',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              ],
            );
          }

          if (rows.isEmpty) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                bar,
                ?moved,
                Expanded(
                  child: Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'Nothing to move: every booked job has other work '
                        'near it on its day.',
                        key: const Key('rescheduling-empty'),
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                  ),
                ),
              ],
            );
          }

          final list = ListView.separated(
            key: const Key('rescheduling-list'),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
            itemCount: rows.length,
            separatorBuilder: (_, _) => SizedBox(height: wide ? 0 : 8),
            itemBuilder: (context, i) => wide
                ? _row(theme, rows[i], selected: identical(rows[i], open))
                : _card(theme, rows[i]),
          );

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              bar,
              ?moved,
              Expanded(
                child: wide
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: list),
                          SizedBox(
                            width: 440,
                            child: SingleChildScrollView(
                              padding: const EdgeInsets.fromLTRB(0, 0, 16, 24),
                              child: _detail(theme, open!, phone: false),
                            ),
                          ),
                        ],
                      )
                    : list,
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _row(
    ThemeData theme,
    Map<String, dynamic> row, {
    required bool selected,
  }) {
    final scheme = theme.colorScheme;
    final job = _map(row['job']);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    Widget two(String top, String bottom, {TextStyle? style}) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          top,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style:
              style ??
              theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
        ),
        Text(
          bottom,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: muted,
        ),
      ],
    );

    return Material(
      color: selected ? scheme.primaryContainer : scheme.surface,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        key: ValueKey('rescheduling-row-${_id(row)}'),
        borderRadius: BorderRadius.circular(10),
        onTap: () => _select(row),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              Expanded(
                flex: 13,
                child: Row(
                  children: [
                    Flexible(
                      child: two(
                        [
                          if (job['number'] != null) '#${job['number']}',
                          if (job['service'] is String) job['service'],
                        ].join(' · '),
                        [
                          if (_map(job['customer'])['name'] is String)
                            _map(job['customer'])['name'],
                          row['area_name'] ?? '',
                        ].join(' · '),
                      ),
                    ),
                    if (_tag(job['service']) case final tag?) ...[
                      const SizedBox(width: 6),
                      tag,
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(flex: 16, child: _arrow(theme, row)),
              const SizedBox(width: 10),
              Expanded(flex: 10, child: two(_nearest(row), _beside(row))),
              SizedBox(
                width: 80,
                child: Text(
                  _saves(row),
                  textAlign: TextAlign.end,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: ToneColours.of(context, Tone.ok).ink,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _arrow(ThemeData theme, Map<String, dynamic> row) {
    final scheme = theme.colorScheme;
    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: _now(row),
            style: TextStyle(
              color: scheme.onSurfaceVariant,
              decoration: TextDecoration.lineThrough,
            ),
          ),
          const TextSpan(text: '  →  '),
          TextSpan(
            text: _then(row),
            style: TextStyle(
              color: scheme.primary,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
      maxLines: 2,
      style: theme.textTheme.bodySmall,
    );
  }

  /// Phone 4: the same row as a card.
  Widget _card(ThemeData theme, Map<String, dynamic> row) {
    final job = _map(row['job']);
    final customer = _map(job['customer'])['name'];

    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        key: ValueKey('rescheduling-row-${_id(row)}'),
        borderRadius: BorderRadius.circular(10),
        onTap: () => _select(row),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      [
                        if (job['number'] != null) '#${job['number']}',
                        ?(customer is String ? customer : null),
                      ].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  if (_tag(job['service']) case final tag?) ...[
                    tag,
                    const SizedBox(width: 6),
                  ],
                  Text(
                    _saves(row),
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: ToneColours.of(context, Tone.ok).ink,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              _arrow(theme, row),
              const SizedBox(height: 2),
              Text(
                [
                  if (job['service'] is String) job['service'],
                  row['area_name'] ?? '',
                  _beside(row),
                ].join(' · '),
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

  /// The suggested day as its windows: each one's spots taken of its
  /// capacity, the jobs already in it with their type and distance, and this
  /// job, dashed, in the window it would take (Jose, 2026-10-14: "showing the
  /// type of jobs set for that day and how the rescheduled one would fit").
  /// "1 open", or "1 → 0 open" where the move changes it, in [ink].
  Widget _opens(ThemeData theme, int before, {int? after, Color? ink}) {
    final style = theme.textTheme.labelMedium?.copyWith(
      fontWeight: FontWeight.w800,
    );
    if (after == null || after == before) {
      return Text('$before open', style: style);
    }
    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: '$before',
            style: TextStyle(
              decoration: TextDecoration.lineThrough,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          TextSpan(
            text: ' → $after open',
            style: TextStyle(color: ink),
          ),
        ],
      ),
      style: style,
    );
  }

  /// The day the job leaves, window by window: the one it is in gets back
  /// the spot it frees, unless that window was over its limit anyway (UI
  /// queue 92, 1 and 4). The counts are the server's (`current.spots`, in
  /// Availability's numbers); drawn only when it sends them.
  Widget _nowPlan(ThemeData theme, Map<String, dynamic> row) {
    final current = _map(row['current']);
    final spots = _map(current['spots']);
    final windows = [
      for (final w in (spots['windows'] as List? ?? const []))
        if (w is Map) w.cast<String, dynamic>(),
    ];
    if (windows.isEmpty) return const SizedBox.shrink();
    final at = (current['window_index'] as num?)?.toInt();
    final info = ToneColours.of(context, Tone.info);
    int n(Object? v) => (v as num?)?.toInt() ?? 0;

    return Column(
      key: const ValueKey('rescheduling-now-plan'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Flexible(
              child: Text(
                'Now · ${_day(current['date'] ?? _map(row['job'])['date'])} in '
                '${row['area_name'] ?? 'this area'} · ',
                style: theme.textTheme.titleSmall,
              ),
            ),
            _opens(
              theme,
              n(spots['before']),
              after: n(spots['after']),
              ink: info.ink,
            ),
          ],
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final (i, w) in windows.indexed)
              Container(
                key: ValueKey('rescheduling-now-window-$i'),
                width: 150,
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: i == at
                      ? info.fill
                      : theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(8),
                  border: i == at
                      ? Border.all(color: info.ink, width: 2)
                      : null,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      i == at
                          ? '${_span(w['start_time'], w['end_time'])} · this job'
                          : _span(w['start_time'], w['end_time']),
                      style: theme.textTheme.bodySmall,
                    ),
                    _opens(
                      theme,
                      n(w['before']),
                      after: n(w['after']),
                      ink: info.ink,
                    ),
                  ],
                ),
              ),
          ],
        ),
        const SizedBox(height: 14),
      ],
    );
  }

  Widget _dayPlan(ThemeData theme, Map<String, dynamic> row) {
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final job = _map(row['job']);
    final s = _map(row['suggested']);
    final windows = [
      if (s['day'] case {'windows': final List days})
        for (final w in days)
          if (w is Map) w.cast<String, dynamic>(),
    ];
    if (windows.isEmpty) return const SizedBox.shrink();
    final jobs = [
      for (final j in (s['jobs'] as List? ?? const []))
        if (j is Map) j.cast<String, dynamic>(),
    ];
    final at = (s['window_index'] as num?)?.toInt();
    final booked = jobs.length;
    final left = windows.fold<int>(
      0,
      (n, w) => n + ((w['remaining'] as num?)?.toInt() ?? 0),
    );
    final ok = ToneColours.of(context, Tone.ok);
    final caution = ToneColours.of(context, Tone.caution);
    // The server's before → after for this day (Scheduling 528f5824); without
    // it, the window it takes loses one.
    final spots = _map(s['spots']);
    final spotWindows = [
      for (final w in (spots['windows'] as List? ?? const []))
        if (w is Map) w.cast<String, dynamic>(),
    ];

    Widget line(Map<String, dynamic> j) => Container(
      margin: const EdgeInsets.only(top: 4),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              [
                if (j['number'] != null) '#${j['number']}',
                if (j['service'] is String) j['service'],
                if (j['miles'] is num) _miles(j['miles'] as num),
              ].join(' · '),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ),
          ?_tag(j['service']),
        ],
      ),
    );

    Widget window(int i, Map<String, dynamic> w) {
      final here = i == at;
      final taken = (w['taken'] as num?)?.toInt() ?? 0;
      final capacity = (w['capacity'] as num?)?.toInt() ?? 0;
      return Container(
        key: ValueKey('rescheduling-window-$i'),
        width: 210,
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: here ? ok.fill : scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(8),
          border: here ? Border.all(color: ok.ink, width: 2) : null,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '${_span(w['start_time'], w['end_time'])} · '
              '$taken of $capacity taken',
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            if (i < spotWindows.length)
              _opens(
                theme,
                (spotWindows[i]['before'] as num?)?.toInt() ?? 0,
                after: (spotWindows[i]['after'] as num?)?.toInt(),
                ink: caution.ink,
              )
            else
              _opens(
                theme,
                (w['remaining'] as num?)?.toInt() ?? 0,
                after: here
                    ? math.max(0, ((w['remaining'] as num?)?.toInt() ?? 0) - 1)
                    : null,
                ink: caution.ink,
              ),
            for (final j in jobs)
              if ((j['window'] as num?)?.toInt() == i) line(j),
            if (here)
              Container(
                key: const ValueKey('rescheduling-fits-here'),
                margin: const EdgeInsets.only(top: 4),
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: ok.ink, width: 1.5),
                ),
                child: Text(
                  '${job['number'] != null ? '#${job['number']}' : 'This job'}'
                  ' would go here',
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: ok.ink,
                  ),
                ),
              ),
          ],
        ),
      );
    }

    final untimed = [
      for (final j in jobs)
        if (j['window'] == null) j,
    ];

    return Column(
      key: const ValueKey('rescheduling-day-plan'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _nowPlan(theme, row),
        Row(
          children: [
            Flexible(
              child: Text(
                'Suggested · ${_day(s['date'])} in '
                '${row['area_name'] ?? 'this area'} · '
                '$booked ${booked == 1 ? 'job' : 'jobs'} · ',
                style: theme.textTheme.titleSmall,
              ),
            ),
            _opens(
              theme,
              (spots['before'] as num?)?.toInt() ?? left,
              after:
                  (spots['after'] as num?)?.toInt() ??
                  (at == null ? null : math.max(0, left - 1)),
              ink: caution.ink,
            ),
          ],
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [for (final (i, w) in windows.indexed) window(i, w)],
        ),
        if (untimed.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text('No set time', style: muted),
          for (final j in untimed) line(j),
        ],
      ],
    );
  }

  Widget _detail(
    ThemeData theme,
    Map<String, dynamic> row, {
    required bool phone,
  }) {
    final scheme = theme.colorScheme;
    final job = _map(row['job']);
    final customer = _map(job['customer']);
    final s = _map(row['suggested']);
    final w = _map(s['window']);
    final name = customer['name'] is String
        ? customer['name'] as String
        : 'the customer';
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final jobs = [
      for (final j in (s['jobs'] as List? ?? const []))
        if (j is Map && j['latitude'] is num && j['longitude'] is num)
          j.cast<String, dynamic>(),
    ];
    final lat = job['latitude'];
    final lng = job['longitude'];
    final technicians = [
      for (final t in (job['technicians'] as List? ?? const []))
        if (t is String) t else if (t is Map && t['name'] is String) t['name'],
    ];
    final remaining = (w['remaining'] as num?)?.toInt();

    Widget day(String kicker, String when, String why, {required bool to}) =>
        Expanded(
          child: Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: to
                  ? ToneColours.of(context, Tone.ok).fill
                  : scheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  kicker.toUpperCase(),
                  style: theme.textTheme.labelSmall?.copyWith(
                    letterSpacing: 0.8,
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  when,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 2),
                Text(why, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
        );

    final now = row['current_nearest_miles'];
    final then = s['nearest_miles'];
    final panels = [
      day(
        'Now',
        _now(row),
        now is num
            ? 'nearest other job ${_miles(now)}'
            : 'no other job that day',
        to: false,
      ),
      day(
        'Suggested',
        _then(row),
        [
          if (then is num) '${_miles(then)} from the nearest',
          _beside(row),
          if (remaining != null)
            remaining == 1 ? '1 spot left' : '$remaining spots left',
        ].join(' · '),
        to: true,
      ),
    ];

    return Column(
      key: ValueKey('rescheduling-detail-${_id(row)}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (lat is num && lng is num && jobs.isNotEmpty) ...[
          RouteMap(
            key: ValueKey('rescheduling-map-${_id(row)}'),
            api: widget.api,
            origin: LatLng(lat.toDouble(), lng.toDouble()),
            originLabel: 'This job',
            jobs: jobs,
            caption: "${_day(s['date'])}'s booked jobs nearby",
          ),
          const SizedBox(height: 12),
        ],
        Card(
          key: const ValueKey('tour-scheduling-reschedule-job'),
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  [
                    if (job['number'] != null) '#${job['number']}',
                    if (job['service'] is String) job['service'],
                  ].join(' · '),
                  style: theme.textTheme.titleMedium,
                ),
                Text(
                  [
                    name,
                    if (customer['address'] is String) customer['address'],
                    if (customer['phone'] is String) customer['phone'],
                    if (technicians.isNotEmpty) technicians.join(', '),
                  ].join(' · '),
                  style: muted,
                ),
                const SizedBox(height: 10),
                phone
                    ? Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(children: [panels[0]]),
                          const SizedBox(height: 8),
                          Row(children: [panels[1]]),
                        ],
                      )
                    : IntrinsicHeight(
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            panels[0],
                            const Padding(
                              padding: EdgeInsets.symmetric(horizontal: 6),
                              child: Center(child: Icon(Icons.arrow_forward)),
                            ),
                            panels[1],
                          ],
                        ),
                      ),
                const SizedBox(height: 14),
                _dayPlan(theme, row),
                if (_moveError != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _moveError!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.error,
                    ),
                  ),
                ],
                const SizedBox(height: 10),
                if (!_canMove || job['workiz_job_id'] == null)
                  Text(
                    _canMove
                        ? 'Only a Workiz job can be moved from here.'
                        : 'Moving a job needs the Schedule and the right to edit '
                              'Scheduling.',
                    style: muted,
                  )
                else ...[
                  // Jose, 2026-10-14: "Make sure to confirm with the client
                  // before rescheduling", then "do you wish to proceed with
                  // the change?" before anything is written.
                  Row(
                    key: const Key('rescheduling-warning'),
                    children: [
                      Icon(
                        Icons.warning_amber_rounded,
                        size: 18,
                        color: StateColour.of(context).caution,
                      ),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          'Make sure to confirm with the client before '
                          'rescheduling.',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: StateColour.of(context).caution,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  if (_confirming)
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          "Move $name's appointment to ${_then(row)}? "
                          'This changes it in Workiz. '
                          'Do you wish to proceed with the change?',
                          style: theme.textTheme.bodyMedium,
                        ),
                        TextButton(
                          onPressed: _moving
                              ? null
                              : () => setState(() => _confirming = false),
                          child: const Text('Cancel'),
                        ),
                        FilledButton(
                          key: const Key('rescheduling-confirm'),
                          onPressed: _moving ? null : () => _move(row),
                          child: Text(_moving ? 'Moving…' : 'Yes, proceed'),
                        ),
                      ],
                    )
                  else
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            "Moving changes $name's appointment.",
                            style: muted,
                          ),
                        ),
                        FilledButton(
                          key: const Key('rescheduling-move'),
                          onPressed: () => setState(() => _confirming = true),
                          child: const Text('Move…'),
                        ),
                      ],
                    ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
