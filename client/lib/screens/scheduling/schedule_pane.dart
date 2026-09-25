import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Schedule — one day's board: technicians down the side, time slots across,
/// each job in the cell of whoever is doing it; unassigned jobs in a list
/// beside it.
///
/// Jose, 2026-09-25, with a screenshot of A1 Tools' Scheduling board: "start
/// building the schedule screen as well ... as usual with our theme, design".
///
/// ── Read-only, and says so ──────────────────────────────────────
///
/// A-1 assigns jobs in Workiz. Assigning from here would write to Workiz — the
/// Workiz module's integration and Jose's decision — so this board shows the
/// day and changes nothing. There is no Schedule button to press that would
/// silently do nothing.
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
  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
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
                final panel = _unassignedPanel(theme, board, wide: wide);

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
        '${_weekdays[_day.weekday - 1]}, ${_months[_day.month - 1]} '
        '${_day.day}, ${_day.year}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      child: Wrap(
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
                      label: f['name'] as String? ?? '',
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
            'Jobs are assigned in Workiz; this board shows what is booked.',
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

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.symmetric(vertical: 3),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(8),
      ),
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
    );
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
