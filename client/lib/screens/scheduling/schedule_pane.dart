import '../../core/branch_options.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Schedule — one day's board: technicians down the side, time slots across,
/// each job in the cell of whoever is doing it; unassigned jobs in a list
/// beside it.
///
/// Jose, 2026-09-25, with a screenshot of A1 Tools' Scheduling board: "start
/// building the schedule screen as well ... as usual with our theme, design".
///
/// ── Dispatching goes to Workiz ──────────────────────────────────
///
/// Tap a job and a panel opens beside the board: who is on it, assign, unassign,
/// move. Every change is written TO Workiz (Jose: "it should be possible on
/// both with the sync") and the board reloads Workiz's own answer. A refusal
/// shows Workiz's sentence. Somebody without Scheduling's edit sees the same
/// panel with no buttons — the server says `can_dispatch`, so nothing is drawn
/// that would only be refused.
class SchedulePane extends StatefulWidget {
  const SchedulePane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<SchedulePane> createState() => _SchedulePaneState();
}

class _SchedulePaneState extends State<SchedulePane> {
  static const _weekdays = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
    'Sunday', //
  ];

  static const double _techColumn = 190;
  static const double _windowColumn = 250;

  DateTime _day = DateUtils.dateOnly(DateTime.now());
  String? _franchiseId;
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _board;
  final _unassignedSearch = TextEditingController();
  int _request = 0;

  /// The job whose panel is open, by mirror row.
  String? _openJobId;
  bool _busy = false;
  String? _dispatchError;
  String? _assignMemberId;
  DateTime? _moveDay;
  int? _moveWindow;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant SchedulePane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _unassignedSearch.dispose();
    super.dispose();
  }

  static String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = _board == null;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/board', {
      'date': _ymd(_day),
      'franchise_id': ?_franchiseId,
    });
    if (!mounted || ticket != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _board = response.body['data'] as Map<String, dynamic>?;
    });
  }

  void _go(DateTime day) {
    setState(() => _day = DateUtils.dateOnly(day));
    _load();
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: DateTime(_day.year - 1),
      lastDate: DateTime(_day.year + 2),
    );
    if (picked != null) _go(picked);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    final board = _board;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _toolbar(theme, board),
        const Divider(height: 1),
        if (_error != null)
          Padding(padding: const EdgeInsets.all(16), child: ErrorNote(_error!)),
        if (board != null)
          Expanded(
            child: LayoutBuilder(
              builder: (context, box) {
                final wide = box.maxWidth >= 1000;
                final grid = _grid(theme, board);
                final open = _openJob(board);
                final panel = open == null
                    ? _unassignedPanel(theme, board, wide: wide)
                    : _jobPanel(theme, board, open, wide: wide);

                return wide
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(child: grid),
                          VerticalDivider(
                            width: 1,
                            color: EzBiziTheme.edge(context),
                          ),
                          SizedBox(width: 330, child: panel),
                        ],
                      )
                    : ListView(
                        children: [
                          SizedBox(height: 420, child: grid),
                          const Divider(height: 1),
                          panel,
                        ],
                      );
              },
            ),
          ),
      ],
    );
  }

  Widget _toolbar(ThemeData theme, Map<String, dynamic>? board) {
    final counts = board?['counts'] as Map<String, dynamic>? ?? const {};
    final franchises = (board?['franchises'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final label =
        '${_weekdays[_day.weekday - 1]}, ${shortMonths[_day.month - 1]} '
        '${_day.day}, ${_day.year}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      child: Wrap(
        key: const ValueKey('tour-scheduling-schedule-day'),
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          IconButton(
            tooltip: 'Previous day',
            icon: const Icon(Icons.chevron_left),
            onPressed: () => _go(_day.subtract(const Duration(days: 1))),
          ),
          TextButton(
            key: const Key('schedule-day'),
            onPressed: _pickDay,
            child: Text(label, style: theme.textTheme.titleSmall),
          ),
          IconButton(
            tooltip: 'Next day',
            icon: const Icon(Icons.chevron_right),
            onPressed: () => _go(_day.add(const Duration(days: 1))),
          ),
          OutlinedButton(
            onPressed: () => _go(DateTime.now()),
            child: const Text('Today'),
          ),
          _count(
            theme,
            Icons.event_available_outlined,
            '${counts['scheduled'] ?? 0} scheduled',
            Tone.info,
          ),
          _count(
            theme,
            Icons.pending_actions_outlined,
            '${counts['unassigned'] ?? 0} unassigned',
            Tone.caution,
          ),
          if (franchises.length > 1)
            SizedBox(
              width: 220,
              child: SearchPickerField<String?>(
                key: const Key('schedule-location'),
                value: _franchiseId,
                label: 'Location',
                isDense: true,
                options: [
                  const SearchOption(value: null, label: 'All locations'),
                  for (final f in franchises)
                    SearchOption(
                      value: f['id'] as String,
                      label: branchLabelOf(
                        widget.session.me,
                        f['id'] as String?,
                        f['name'] as String? ?? '',
                      ),
                    ),
                ],
                onChanged: (v) {
                  setState(() => _franchiseId = v);
                  _load();
                },
              ),
            ),
          IconButton(
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh),
            onPressed: _load,
          ),
        ],
      ),
    );
  }

  Widget _count(ThemeData theme, IconData icon, String text, Tone tone) {
    final c = ToneColours.of(context, tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: c.ink),
          const SizedBox(width: 6),
          Text(
            text,
            style: theme.textTheme.bodySmall?.copyWith(
              color: c.ink,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  /// Technicians × windows, plus a "No time" column only when a job needs it.
  Widget _grid(ThemeData theme, Map<String, dynamic> board) {
    final windows = (board['windows'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final techs = (board['technicians'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final cards = (board['scheduled'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final muted = theme.colorScheme.onSurfaceVariant;

    if (windows.isEmpty) {
      return _empty(
        theme,
        'No time slots yet. Add them in Scheduling › Settings › Time slots, '
        'and the board lays itself out by them.',
      );
    }
    if (techs.isEmpty) {
      return _empty(theme, 'No technicians in this view.');
    }

    final noTime = cards.any((c) => c['window'] == null);
    final columns = windows.length + (noTime ? 1 : 0);
    final width = _techColumn + columns * _windowColumn;

    final byCell = <String, List<Map<String, dynamic>>>{};
    for (final c in cards) {
      byCell
          .putIfAbsent('${c['technician_id']}|${c['window']}', () => [])
          .add(c);
    }

    Widget header(String text) => Container(
      width: _windowColumn,
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: theme.textTheme.labelMedium?.copyWith(
          color: muted,
          fontWeight: FontWeight.w700,
        ),
      ),
    );

    return SingleChildScrollView(
      key: const ValueKey('tour-scheduling-board'),
      scrollDirection: Axis.horizontal,
      child: SizedBox(
        width: width,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                SizedBox(width: _techColumn, child: header('Technician')),
                if (noTime) header('No time set'),
                for (final w in windows)
                  header(
                    '${_time(w['start_time'] as String)} – '
                    '${_time(w['end_time'] as String)}',
                  ),
              ],
            ),
            Divider(height: 1, color: EzBiziTheme.edge(context)),
            Expanded(
              child: ListView.separated(
                itemCount: techs.length,
                separatorBuilder: (_, _) =>
                    Divider(height: 1, color: EzBiziTheme.edge(context)),
                itemBuilder: (context, i) {
                  final t = techs[i];
                  final id = t['id'] as String;

                  Widget cell(int? window) => Container(
                    width: _windowColumn,
                    constraints: const BoxConstraints(minHeight: 64),
                    padding: const EdgeInsets.all(4),
                    decoration: BoxDecoration(
                      border: Border(
                        left: BorderSide(color: EzBiziTheme.edge(context)),
                      ),
                    ),
                    child: Column(
                      children: [
                        for (final c in byCell['$id|$window'] ?? const [])
                          _jobCard(theme, c),
                      ],
                    ),
                  );

                  return IntrinsicHeight(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SizedBox(
                          width: _techColumn,
                          child: Padding(
                            padding: const EdgeInsets.all(10),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  t['name'] as String? ?? '',
                                  style: theme.textTheme.titleSmall,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                Text(
                                  '${t['jobs']} '
                                  '${t['jobs'] == 1 ? 'job' : 'jobs'}'
                                  '${t['franchise_name'] == null ? '' : ' · ${t['franchise_name']}'}',
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: muted,
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ],
                            ),
                          ),
                        ),
                        if (noTime) cell(null),
                        for (var w = 0; w < windows.length; w++) cell(w),
                      ],
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _unassignedPanel(
    ThemeData theme,
    Map<String, dynamic> board, {
    required bool wide,
  }) {
    final q = _unassignedSearch.text.trim().toLowerCase();
    final all = (board['unassigned'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final shown = q.isEmpty
        ? all
        : all.where((c) {
            final hay = [
              c['customer'],
              c['number'],
              c['address'],
              c['city'],
              c['job_type'],
            ].whereType<String>().join(' ').toLowerCase();
            return hay.contains(q);
          }).toList();

    final list = [
      for (final c in shown) _jobCard(theme, c, showWindow: true),
      if (shown.isEmpty)
        Padding(
          padding: const EdgeInsets.all(16),
          child: Text(
            all.isEmpty ? 'Everything that day has a technician.' : 'No match.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
    ];

    return Column(
      key: const ValueKey('tour-scheduling-unassigned'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: wide ? MainAxisSize.max : MainAxisSize.min,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
          child: Text(
            'Unassigned (${all.length})',
            style: theme.textTheme.titleSmall,
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
          child: TextField(
            key: const Key('schedule-unassigned-search'),
            controller: _unassignedSearch,
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search, size: 18),
              hintText: 'Customer, job #, address',
              border: OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() {}),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 2, 12, 6),
          child: Text(
            board['can_dispatch'] == true
                ? 'Tap a job to assign, unassign or move it. Changes go '
                      'straight to Workiz.'
                : 'Jobs are assigned in Workiz; this board shows what is '
                      'booked.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        if (wide)
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
              children: list,
            ),
          )
        else
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
            child: Column(children: list),
          ),
      ],
    );
  }

  Widget _jobCard(
    ThemeData theme,
    Map<String, dynamic> c, {
    bool showWindow = false,
  }) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final status = c['status'] as String? ?? '';
    final tone = _toneFor(status);
    final colours = ToneColours.of(context, tone);
    final when = c['time'] == null ? 'No time set' : _time(c['time'] as String);

    final selected = c['job_id'] != null && c['job_id'] == _openJobId;

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.symmetric(vertical: 3),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(
          color: selected
              ? theme.colorScheme.primary
              : EzBiziTheme.edge(context),
          width: selected ? 2 : 1,
        ),
        borderRadius: BorderRadius.circular(8),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: c['job_id'] == null ? null : () => _open(c),
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      c['customer'] as String? ?? 'No name',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (status.isNotEmpty)
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 6,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: colours.fill,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        status,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: colours.ink,
                        ),
                      ),
                    ),
                ],
              ),
              if ((c['number'] as String? ?? '').isNotEmpty)
                Text(
                  '#${c['number']}',
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              if (c['job_type'] != null)
                Text(
                  c['job_type'] as String,
                  style: theme.textTheme.bodySmall,
                  overflow: TextOverflow.ellipsis,
                ),
              Text(
                [
                  if (showWindow || c['time'] != null) when,
                  c['city'],
                ].whereType<String>().join(' · '),
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// The open job, looked up afresh in each board so the panel shows what
  /// Workiz answered after a change. Null when it has left this day.
  Map<String, dynamic>? _openJob(Map<String, dynamic> board) {
    if (_openJobId == null) return null;
    for (final key in ['unassigned', 'scheduled']) {
      for (final c in (board[key] as List? ?? const [])) {
        if ((c as Map)['job_id'] == _openJobId) {
          return c.cast<String, dynamic>();
        }
      }
    }
    return null;
  }

  void _open(Map<String, dynamic> c) => setState(() {
    _openJobId = c['job_id'] as String?;
    _dispatchError = null;
    _assignMemberId = null;
    _moveDay = _day;
    _moveWindow = c['window'] as int?;
  });

  void _close() => setState(() {
    _openJobId = null;
    _dispatchError = null;
  });

  /// One write, then the board again: Workiz's answer, not ours.
  Future<void> _dispatch(String action, Map<String, dynamic> body) async {
    final id = _openJobId;
    if (id == null) return;

    setState(() {
      _busy = true;
      _dispatchError = null;
    });

    // Spelled out, not '$action': ClientCallsRealRoutesTest reads these
    // literals to prove each one is a route.
    final path = switch (action) {
      'assign' => '/scheduling/board/jobs/$id/assign',
      'unassign' => '/scheduling/board/jobs/$id/unassign',
      _ => '/scheduling/board/jobs/$id/reschedule',
    };
    final response = await widget.api.post(path, body);
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (!response.ok) _dispatchError = response.message;
      if (response.ok) _assignMemberId = null;
    });

    if (!response.ok) return;

    // A moved job leaves this day; follow it so its panel stays open.
    if (action == 'reschedule' && _moveDay != null && _moveDay != _day) {
      _go(_moveDay!);
    } else {
      await _load();
    }
  }

  Future<void> _pickMoveDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _moveDay ?? _day,
      firstDate: DateUtils.dateOnly(DateTime.now()),
      lastDate: DateTime(_day.year + 2),
    );
    if (picked != null) setState(() => _moveDay = DateUtils.dateOnly(picked));
  }

  Widget _jobPanel(
    ThemeData theme,
    Map<String, dynamic> board,
    Map<String, dynamic> c, {
    required bool wide,
  }) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final canDispatch = board['can_dispatch'] == true;
    final windows = (board['windows'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final team = (c['team'] as List? ?? const []).cast<Map<String, dynamic>>();
    final onIt = {for (final t in team) t['member_id']};
    final area = (c['service_area'] as String? ?? '').trim().toLowerCase();

    // Workiz refuses a technician outside the job's service area (measured),
    // so only the ones who cover it are offered — and only from the job's own
    // branch, whose Workiz account it is.
    final covering = [
      for (final t
          in (board['technicians'] as List? ?? const [])
              .cast<Map<String, dynamic>>())
        if (t['member_id'] != null &&
            t['franchise_id'] == c['franchise_id'] &&
            !onIt.contains(t['member_id']) &&
            (t['service_areas'] as List? ?? const []).any(
              (a) => '$a'.trim().toLowerCase() == area,
            ))
          t,
    ];

    Widget heading(String text) => Padding(
      padding: const EdgeInsets.only(top: 16, bottom: 6),
      child: Text(
        text,
        style: theme.textTheme.labelSmall?.copyWith(
          letterSpacing: 0.8,
          color: muted,
        ),
      ),
    );

    final moveLabel = _moveDay == null
        ? 'Pick a day'
        : '${_weekdays[_moveDay!.weekday - 1].substring(0, 3)}, '
              '${shortMonths[_moveDay!.month - 1]} ${_moveDay!.day}';

    final children = <Widget>[
      Row(
        children: [
          Expanded(
            child: Text(
              c['customer'] as String? ?? 'No name',
              style: theme.textTheme.titleMedium,
            ),
          ),
          IconButton(
            tooltip: 'Close',
            icon: const Icon(Icons.close),
            onPressed: _close,
          ),
        ],
      ),
      Text(
        [
          if ((c['number'] as String? ?? '').isNotEmpty) '#${c['number']}',
          c['job_type'],
          c['status'],
        ].whereType<String>().join(' · '),
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      ),
      Text(
        [
          c['time'] == null ? 'No time set' : _time(c['time'] as String),
          c['service_area'],
          c['city'],
        ].whereType<String>().join(' · '),
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      ),
      if (_dispatchError != null) ...[
        const SizedBox(height: 12),
        ErrorNote(_dispatchError!),
      ],
      heading('ON THIS JOB'),
      if (team.isEmpty) Text('Nobody yet.', style: theme.textTheme.bodyMedium),
      for (final t in team)
        Row(
          children: [
            Expanded(
              child: Text(
                t['name'] as String? ?? '',
                style: theme.textTheme.bodyMedium,
              ),
            ),
            if (canDispatch && t['member_id'] != null)
              TextButton(
                onPressed: _busy
                    ? null
                    : () =>
                          _dispatch('unassign', {'member_id': t['member_id']}),
                child: const Text('Unassign'),
              ),
          ],
        ),
      if (canDispatch) ...[
        heading('ASSIGN'),
        if (covering.isEmpty)
          Text(
            'No other technician in Workiz covers '
            '${c['service_area'] ?? 'this area'}.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          )
        else ...[
          SearchPickerField<String>(
            key: const Key('dispatch-assign'),
            value: _assignMemberId,
            hint: 'Technician',
            isDense: true,
            options: [
              for (final t in covering)
                SearchOption(
                  value: t['member_id'] as String,
                  label: t['name'] as String? ?? '',
                  detail: '${t['jobs']} ${t['jobs'] == 1 ? 'job' : 'jobs'}',
                ),
            ],
            onChanged: (v) => setState(() => _assignMemberId = v),
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: _busy || _assignMemberId == null
                  ? null
                  : () => _dispatch('assign', {'member_id': _assignMemberId}),
              child: const Text('Assign'),
            ),
          ),
        ],
        heading('MOVE'),
        Row(
          children: [
            OutlinedButton.icon(
              key: const Key('dispatch-move-day'),
              onPressed: _busy ? null : _pickMoveDay,
              icon: const Icon(Icons.event, size: 18),
              label: Text(moveLabel),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: SearchPickerField<int>(
                key: const Key('dispatch-move-window'),
                value: _moveWindow,
                hint: 'Time slot',
                isDense: true,
                options: [
                  for (var i = 0; i < windows.length; i++)
                    SearchOption(
                      value: i,
                      label:
                          '${_time(windows[i]['start_time'] as String)} – '
                          '${_time(windows[i]['end_time'] as String)}',
                    ),
                ],
                onChanged: (v) => setState(() => _moveWindow = v),
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed:
                _busy ||
                    _moveDay == null ||
                    _moveWindow == null ||
                    _moveWindow! >= windows.length
                ? null
                : () => _dispatch('reschedule', {
                    'date': _ymd(_moveDay!),
                    'start_time': windows[_moveWindow!]['start_time'],
                    'end_time': windows[_moveWindow!]['end_time'],
                  }),
            child: const Text('Move'),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          'The job keeps its length and starts when the slot does.',
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
      ],
    ];

    final body = Padding(
      key: const ValueKey('tour-scheduling-job'),
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );

    return wide ? SingleChildScrollView(child: body) : body;
  }

  static Tone _toneFor(String status) => switch (status.trim().toLowerCase()) {
    'submitted' => Tone.info,
    'in progress' => Tone.caution,
    'done pending approval' => Tone.review,
    'done' => Tone.ok,
    'canceled' || 'cancelled' => Tone.bad,
    _ => Tone.neutral,
  };

  Widget _empty(ThemeData theme, String text) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    ),
  );

  static String _time(String hm) {
    final parts = hm.split(':');
    final h = int.tryParse(parts.first) ?? 0;
    final m = parts.length > 1 ? parts[1] : '00';
    final h12 = h % 12 == 0 ? 12 : h % 12;
    final period = h < 12 ? 'AM' : 'PM';
    return m == '00' ? '$h12 $period' : '$h12:$m $period';
  }
}
