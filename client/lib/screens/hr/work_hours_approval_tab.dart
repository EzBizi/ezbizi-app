/// Work Hours › Daily approval (UI queue 136, options 1 and 4; Shoval via
/// Jose, 2026-10-22). A manager reads one day of their people's clock-ins,
/// yesterday unless told otherwise, and approves each person's day, or
/// adjusts a shift first with a reason. Adjusting does not approve.
///
/// The list follows the top-bar view (the server reads it), so there is no
/// branch picker here: the view's name is shown instead.
///
/// Every time on this screen is a string the server formatted in the view's
/// zone and the organization's 12/24h setting. The day bar places those same
/// strings; nothing here reads the device clock to decide a day.
library;

import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// The notification record a Daily approval reminder carries.
const approvalLinkType = 'time_clock_day_approval';

class WorkHoursApprovalTab extends StatefulWidget {
  const WorkHoursApprovalTab({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<WorkHoursApprovalTab> createState() => _WorkHoursApprovalTabState();
}

class _WorkHoursApprovalTabState extends State<WorkHoursApprovalTab> {
  Map<String, dynamic>? _day;
  String? _error;
  bool _loading = true;
  bool _busy = false;
  bool _calendarOpen = false;
  String? _scopeKey;

  /// What the search bar holds; matched against each person's name and role.
  String _query = '';

  bool get _canApprove =>
      widget.session.me?.permissions.contains('work_hours.approve') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _scopeKey = _currentScope();
    final link = _takeOurs();
    unawaited(_load(date: link?.date));
    DeepLinks.pending.addListener(_onLink);
    widget.session.addListener(_onSession);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_onLink);
    widget.session.removeListener(_onSession);
    super.dispose();
  }

  String? _currentScope() {
    final scope = widget.session.me?.scope;
    return scope == null ? null : '${scope.type}:${scope.id}';
  }

  /// Only a Daily approval link: a correction request is General's.
  DeepLink? _takeOurs() {
    final pending = DeepLinks.pending.value;
    if (pending == null ||
        pending.module != 'work_hours' ||
        pending.type != approvalLinkType) {
      return null;
    }
    return DeepLinks.take('work_hours');
  }

  void _onLink() {
    final link = _takeOurs();
    if (link != null) unawaited(_load(date: link.date));
  }

  /// The top-bar view moved: the same day, for the new view.
  void _onSession() {
    final key = _currentScope();
    if (key == _scopeKey) return;
    _scopeKey = key;
    unawaited(_load(date: _day?['date'] as String?));
  }

  Future<void> _load({String? date}) async {
    setState(() => _loading = true);
    final response = await widget.api.get('/hr/time-clock/approvals', {
      if (date != null && date.isNotEmpty) 'date': date,
    });
    if (!mounted) return;
    _adopt(response);
  }

  void _adopt(ApiResponse response) {
    setState(() {
      _loading = false;
      _busy = false;
      if (response.ok && response.data is Map) {
        _day = Map<String, dynamic>.from(response.data as Map);
        _error = null;
      } else {
        _error = response.forbidden
            ? 'Approving daily hours has not been opened to you. Somebody who '
                  'can edit roles adds "Approve daily hours" under Settings › '
                  'Roles & permissions.'
            : response.reason;
      }
    });
  }

  void _move(int days) {
    final day = parseCalendarDay(_day?['date'] as String?);
    if (day == null) return;
    unawaited(
      _load(
        date: formatCalendarDay(DateTime(day.year, day.month, day.day + days)),
      ),
    );
  }

  Future<void> _post(String path, Map<String, dynamic> body) async {
    setState(() => _busy = true);
    final response = await widget.api.post(path, body);
    if (!mounted) return;
    if (response.ok) {
      _adopt(response);
    } else {
      setState(() => _busy = false);
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
  }

  Future<void> _approve(Map<String, dynamic> row) => _post(
    '/hr/time-clock/approvals',
    {'user_id': '${(row['person'] as Map?)?['id']}', 'date': _day?['date']},
  );

  Future<void> _approveAll() =>
      _post('/hr/time-clock/approvals/all', {'date': _day?['date']});

  Future<void> _adjust(Map<String, dynamic> row) async {
    final personId = '${(row['person'] as Map?)?['id']}';
    await showSidePanel<void>(
      context,
      builder: (context) => _AdjustPanel(
        api: widget.api,
        formats: _formats,
        dayLabel: '${_day?['label'] ?? ''}',
        row: row,
        personId: personId,
        onSaved: (response) {
          if (mounted) _adopt(response);
        },
      ),
    );
  }

  List<Map<String, dynamic>> get _rows => [
    for (final r in (_day?['rows'] as List?) ?? const [])
      if (r is Map) Map<String, dynamic>.from(r),
  ];

  int _count(String key) =>
      ((_day?['counts'] as Map?)?[key] as num?)?.toInt() ?? 0;

  @override
  Widget build(BuildContext context) {
    final day = _day;
    if (day == null) {
      if (_error != null) {
        return Padding(
          padding: const EdgeInsets.all(16),
          child: Align(alignment: Alignment.topLeft, child: ErrorNote(_error!)),
        );
      }
      return const Center(child: CircularProgressIndicator());
    }

    return LayoutBuilder(
      key: const ValueKey('tour-work_hours-approval'),
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        final all = _rows;
        // The bar's scale stays the whole day's, so a search does not move it.
        final axis = _Axis.of(all);
        final needle = _query.trim().toLowerCase();
        final rows = needle.isEmpty
            ? all
            : [
                for (final r in all)
                  if ([
                    (r['person'] as Map?)?['name'],
                    (r['person'] as Map?)?['role'],
                    r['status'],
                    if (r['no_show'] == true) 'no-show',
                  ].any(
                    (v) => v != null && '$v'.toLowerCase().contains(needle),
                  ))
                    r,
              ];

        final list = all.isNotEmpty && rows.isEmpty
            ? Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                child: Text(
                  'No people match "${_query.trim()}".',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              )
            : rows.isEmpty
            ? const EmptyState(
                icon: Icons.fact_check_outlined,
                title: 'Nobody to approve on this day',
                message:
                    'Nobody in this view clocked in, and nobody was scheduled.',
              )
            : ListView.separated(
                key: const ValueKey('tour-work_hours-approval-rows'),
                padding: EdgeInsets.fromLTRB(
                  phone ? 12 : 16,
                  phone ? 8 : 0,
                  phone ? 12 : 16,
                  16,
                ),
                itemCount: rows.length + (phone ? 0 : 1),
                separatorBuilder: (_, _) => phone
                    ? const SizedBox(height: 10)
                    : const Divider(height: 1),
                itemBuilder: (context, i) {
                  if (!phone && i == 0) return const _HeaderRow();
                  final row = rows[phone ? i : i - 1];
                  final key = ValueKey(
                    'approval-row-${(row['person'] as Map?)?['id']}',
                  );
                  return phone
                      ? _PhoneCard(
                          key: key,
                          row: row,
                          axis: axis,
                          formats: _formats,
                          actions: _actions(row, phone: true),
                        )
                      : _TableRow(
                          key: key,
                          row: row,
                          axis: axis,
                          formats: _formats,
                          actions: _actions(row, phone: false),
                        );
                },
              );

        final waiting = _count('waiting');

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _top(day, phone: phone),
            if (_loading || _busy) const LinearProgressIndicator(minHeight: 2),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: ErrorNote(_error!),
              ),
            Expanded(child: list),
            if (phone && _canApprove && waiting > 0)
              SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                  child: FilledButton.icon(
                    key: const ValueKey('tour-work_hours-approval-all'),
                    onPressed: _busy ? null : _approveAll,
                    icon: const Icon(Icons.done_all, size: 18),
                    label: Text('Approve all $waiting'),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  List<Widget> _actions(Map<String, dynamic> row, {required bool phone}) {
    if (!_canApprove) return const [];
    final id = (row['person'] as Map?)?['id'];
    final shifts = (row['shifts'] as List?) ?? const [];
    return [
      if (shifts.isNotEmpty)
        OutlinedButton(
          key: ValueKey('approval-adjust-$id'),
          onPressed: _busy ? null : () => _adjust(row),
          child: const Text('Adjust'),
        ),
      if (row['status'] != 'approved')
        FilledButton(
          key: ValueKey('approval-approve-$id'),
          onPressed: _busy ? null : () => _approve(row),
          child: const Text('Approve'),
        ),
    ];
  }

  Widget _top(Map<String, dynamic> day, {required bool phone}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final waiting = _count('waiting');
    final view = widget.session.me?.scope.shortPath ?? '';

    final dayControl = Row(
      key: const ValueKey('tour-work_hours-approval-day'),
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          key: const ValueKey('approval-previous-day'),
          tooltip: 'The day before',
          onPressed: _loading ? null : () => _move(-1),
          icon: const Icon(Icons.chevron_left),
        ),
        Flexible(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 240),
            child: Text(
              '${day['label'] ?? day['date'] ?? ''}',
              key: const ValueKey('approval-day-label'),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleMedium,
            ),
          ),
        ),
        IconButton(
          key: const ValueKey('approval-next-day'),
          tooltip: 'The day after',
          onPressed: _loading ? null : () => _move(1),
          icon: const Icon(Icons.chevron_right),
        ),
        IconButton(
          key: const ValueKey('approval-calendar'),
          tooltip: _calendarOpen
              ? 'Hide the calendar'
              : 'Pick a day on the calendar',
          onPressed: () => setState(() => _calendarOpen = !_calendarOpen),
          icon: Icon(
            _calendarOpen
                ? Icons.calendar_month
                : Icons.calendar_today_outlined,
            size: 20,
          ),
        ),
      ],
    );

    final summary = Wrap(
      key: const ValueKey('tour-work_hours-approval-summary'),
      spacing: 8,
      runSpacing: 8,
      children: [
        _Count('Waiting', waiting, Tone.caution, keyName: 'waiting'),
        _Count('Approved', _count('approved'), Tone.ok, keyName: 'approved'),
        _Count('No-shows', _count('no_shows'), Tone.bad, keyName: 'no_shows'),
      ],
    );

    final calendar = _calendarOpen
        ? Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Align(
              alignment: Alignment.topLeft,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 360),
                child: Material(
                  color: scheme.surface,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(color: scheme.outlineVariant),
                  ),
                  child: CalendarDatePicker(
                    key: const ValueKey('approval-calendar-picker'),
                    initialDate:
                        parseCalendarDay(day['date'] as String?) ??
                        DateTime(2000),
                    firstDate: DateTime(2000),
                    lastDate: DateTime(2100, 12, 31),
                    onDateChanged: (picked) {
                      setState(() => _calendarOpen = false);
                      unawaited(_load(date: formatCalendarDay(picked)));
                    },
                  ),
                ),
              ),
            ),
          )
        : const SizedBox.shrink();

    final viewName = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.place_outlined, size: 16, color: scheme.onSurfaceVariant),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            view,
            key: const ValueKey('approval-view-name'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ),
      ],
    );

    // Standard field height, as tall as Leads search (Jose, 2026-10-27).
    final search = SizedBox(
      width: 240,
      child: ListSearch(
        key: const Key('work-hours-approval-search'),
        hint: 'Find a person',
        onChanged: (v) => setState(() => _query = v),
      ),
    );

    if (phone) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(4, 4, 12, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: dayControl,
                  ),
                ),
                Text(
                  '$waiting waiting',
                  key: const ValueKey('approval-phone-waiting'),
                  style: theme.textTheme.labelLarge,
                ),
              ],
            ),
            Padding(padding: const EdgeInsets.only(left: 12), child: viewName),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 0, 0),
              child: search,
            ),
            calendar,
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 16,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [dayControl, viewName],
          ),
          calendar,
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.only(left: 8),
            child: Wrap(
              spacing: 16,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                summary,
                search,
                if (_canApprove && waiting > 0)
                  FilledButton.icon(
                    key: const ValueKey('tour-work_hours-approval-all'),
                    onPressed: _busy ? null : _approveAll,
                    icon: const Icon(Icons.done_all, size: 18),
                    label: Text('Approve all $waiting waiting'),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// One of Waiting / Approved / No-shows.
class _Count extends StatelessWidget {
  const _Count(this.label, this.value, this.tone, {required this.keyName});

  final String label;
  final int value;
  final Tone tone;
  final String keyName;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colours = ToneColours.of(context, tone);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
          ),
          const SizedBox(width: 8),
          Text(
            '$value',
            key: ValueKey('approval-count-$keyName'),
            style: theme.textTheme.titleMedium?.copyWith(color: colours.ink),
          ),
        ],
      ),
    );
  }
}

// ── What a row says ─────────────────────────────────────────────────

/// "8:00 AM" or "08:00" as minutes after midnight.
int? _clockMinutes(String? s) {
  if (s == null) return null;
  final m = RegExp(r'^(\d{1,2}):(\d{2})\s*([AaPp][Mm])?$').firstMatch(s.trim());
  if (m == null) return null;
  var hour = int.parse(m[1]!);
  final minute = int.parse(m[2]!);
  final half = m[3]?.toUpperCase();
  if (half == 'AM' && hour == 12) hour = 0;
  if (half == 'PM' && hour != 12) hour += 12;
  return hour * 60 + minute;
}

/// "2026-10-21 16:05" as minutes after the row's midnight (past 1440 when a
/// shift ends the next day).
int? _localMinutes(String? local, String? date) {
  final at = parseWallDateTime(local);
  final day = parseCalendarDay(date);
  if (at == null || day == null) return null;
  final days = DateTime.utc(
    at.day.year,
    at.day.month,
    at.day.day,
  ).difference(DateTime.utc(day.year, day.month, day.day)).inDays;
  return days * 1440 + at.hour * 60 + at.minute;
}

String _duration(int seconds) {
  final minutes = (seconds / 60).round();
  final h = minutes ~/ 60;
  final m = minutes % 60;
  if (h == 0 && m > 0) return '${m}m';
  return m == 0 ? '${h}h' : '${h}h ${m}m';
}

List<Map<String, dynamic>> _shiftsOf(Map<String, dynamic> row) => [
  for (final s in (row['shifts'] as List?) ?? const [])
    if (s is Map) Map<String, dynamic>.from(s),
];

List<Map<String, dynamic>> _adjustmentsOf(Map<String, dynamic> row) => [
  for (final a in (row['adjustments'] as List?) ?? const [])
    if (a is Map) Map<String, dynamic>.from(a),
];

String? _date(Map<String, dynamic> row) {
  final shifts = _shiftsOf(row);
  final local = shifts.isEmpty ? null : shifts.first['clocked_in_local'];
  return local is String ? local.split(' ').first : null;
}

/// The scheduled span in minutes after midnight, or null.
(int, int)? _scheduled(Map<String, dynamic> row) {
  final s = row['scheduled'];
  if (s is! Map) return null;
  final start = _clockMinutes(s['start'] as String?);
  var end = _clockMinutes(s['end'] as String?);
  if (start == null || end == null) return null;
  if (end <= start) end += 1440;
  return (start, end);
}

/// "7h 38m of 8h", or "7h 38m" without a schedule.
String _workedText(Map<String, dynamic> row) {
  final worked = _duration((row['worked_seconds'] as num?)?.toInt() ?? 0);
  final span = _scheduled(row);
  return span == null
      ? worked
      : '$worked of ${_duration((span.$2 - span.$1) * 60)}';
}

/// The shift's first adjustment: its `was_*` are the times before anybody
/// changed them.
Map<String, dynamic>? _originalOf(Map<String, dynamic> row, Object? shiftId) {
  for (final a in _adjustmentsOf(row)) {
    if ('${a['shift_id']}' == '$shiftId') return a;
  }
  return null;
}

/// "7:58–12:02 · 12:31–16:05", an adjusted time after its struck-through
/// original.
Widget _shiftsText(BuildContext context, Map<String, dynamic> row) {
  final theme = Theme.of(context);
  final scheme = theme.colorScheme;
  final base = theme.textTheme.bodyMedium;

  if (row['no_show'] == true) {
    return Text(
      "Didn't clock in",
      key: const ValueKey('approval-no-show'),
      style: base?.copyWith(color: scheme.error, fontWeight: FontWeight.w700),
    );
  }

  final struck = base?.copyWith(
    color: scheme.onSurfaceVariant,
    decoration: TextDecoration.lineThrough,
  );
  final spans = <InlineSpan>[];
  final shifts = _shiftsOf(row);
  for (final (i, shift) in shifts.indexed) {
    if (i > 0) spans.add(const TextSpan(text: ' · '));
    final original = _originalOf(row, shift['id']);
    void time(String? now, Object? was) {
      if (was is String && now != null && was != now) {
        spans.add(TextSpan(text: was, style: struck));
        spans.add(const TextSpan(text: ' '));
      }
      if (now != null) spans.add(TextSpan(text: now));
    }

    time(shift['clocked_in'] as String?, original?['was_in']);
    spans.add(const TextSpan(text: '–'));
    time(shift['clocked_out'] as String?, original?['was_out']);
  }

  return Text.rich(
    TextSpan(style: base, children: spans),
    key: ValueKey('approval-shifts-${(row['person'] as Map?)?['id']}'),
  );
}

/// Late in / early out, when there is either.
List<Widget> _chips(Map<String, dynamic> row) {
  final late = (row['late_seconds'] as num?)?.toInt() ?? 0;
  final early = (row['early_seconds'] as num?)?.toInt() ?? 0;
  return [
    if (late >= 60) StatusPill('Late ${_duration(late)}', tone: Tone.caution),
    if (early >= 60)
      StatusPill('Early ${_duration(early)}', tone: Tone.caution),
  ];
}

List<Widget> _status(Map<String, dynamic> row, DisplayFormats formats) {
  final adjusted = _adjustmentsOf(row).isNotEmpty;
  if (row['status'] == 'approved') {
    final by = (row['approved_by'] as Map?)?['name'];
    final at = formatStamp(row['approved_at'], '', formats: formats);
    return [
      StatusPill(
        [
          if (by != null) 'Approved by $by' else 'Approved',
          if (at.isNotEmpty) at,
        ].join(' · '),
        tone: Tone.ok,
      ),
      if (adjusted) const StatusPill('Adjusted', tone: Tone.info),
    ];
  }
  return [
    adjusted
        ? const StatusPill('Adjusted', tone: Tone.info)
        : const StatusPill('Waiting', tone: Tone.neutral),
  ];
}

// ── The day bar ─────────────────────────────────────────────────────

/// One hour axis for every row, so bars compare across people.
class _Axis {
  const _Axis(this.start, this.end);

  final int start;
  final int end;

  static _Axis of(List<Map<String, dynamic>> rows) {
    int? lo;
    int? hi;
    void take(int? m) {
      if (m == null) return;
      lo = lo == null || m < lo! ? m : lo;
      hi = hi == null || m > hi! ? m : hi;
    }

    for (final row in rows) {
      final span = _scheduled(row);
      if (span != null) {
        take(span.$1);
        take(span.$2);
      }
      final date = _date(row);
      for (final s in _shiftsOf(row)) {
        take(_localMinutes(s['clocked_in_local'] as String?, date));
        take(_localMinutes(s['clocked_out_local'] as String?, date));
      }
    }
    if (lo == null || hi == null) return const _Axis(6 * 60, 20 * 60);
    final start = (lo! ~/ 60) * 60;
    final end = ((hi! + 59) ~/ 60) * 60;
    return _Axis(start, end <= start ? start + 60 : end);
  }
}

class _DayBar extends StatelessWidget {
  const _DayBar({required this.row, required this.axis});

  final Map<String, dynamic> row;
  final _Axis axis;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final date = _date(row);
    final worked = <(int, int)>[
      for (final s in _shiftsOf(row))
        if (_localMinutes(s['clocked_in_local'] as String?, date)
            case final from?)
          (
            from,
            _localMinutes(s['clocked_out_local'] as String?, date) ?? from,
          ),
    ];

    return SizedBox(
      height: 16,
      child: CustomPaint(
        key: ValueKey('approval-bar-${(row['person'] as Map?)?['id']}'),
        painter: _DayBarPainter(
          axis: axis,
          worked: worked,
          scheduled: _scheduled(row),
          track: scheme.surfaceContainerHighest,
          fill: scheme.primary,
          schedule: scheme.outline,
        ),
      ),
    );
  }
}

class _DayBarPainter extends CustomPainter {
  _DayBarPainter({
    required this.axis,
    required this.worked,
    required this.scheduled,
    required this.track,
    required this.fill,
    required this.schedule,
  });

  final _Axis axis;
  final List<(int, int)> worked;
  final (int, int)? scheduled;
  final Color track;
  final Color fill;
  final Color schedule;

  double _x(int minutes, Size size) =>
      ((minutes - axis.start) / (axis.end - axis.start)).clamp(0.0, 1.0) *
      size.width;

  @override
  void paint(Canvas canvas, Size size) {
    final mid = size.height / 2;
    final radius = const Radius.circular(3);
    canvas.drawRRect(
      RRect.fromRectAndRadius(Rect.fromLTWH(0, mid - 3, size.width, 6), radius),
      Paint()..color = track,
    );

    // The schedule, dashed, around where the shifts sit.
    if (scheduled case (final from, final to)) {
      final paint = Paint()
        ..color = schedule
        ..strokeWidth = 1.2;
      final left = _x(from, size);
      final right = _x(to, size);
      for (final y in [1.0, size.height - 1]) {
        for (var x = left; x < right; x += 6) {
          canvas.drawLine(
            Offset(x, y),
            Offset((x + 3).clamp(left, right), y),
            paint,
          );
        }
      }
      for (final x in [left, right]) {
        for (var y = 1.0; y < size.height - 1; y += 5) {
          canvas.drawLine(
            Offset(x, y),
            Offset(x, (y + 2.5).clamp(1.0, size.height - 1)),
            paint,
          );
        }
      }
    }

    for (final (from, to) in worked) {
      final left = _x(from, size);
      final right = _x(to, size);
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTRB(
            left,
            mid - 4,
            right < left + 2 ? left + 2 : right,
            mid + 4,
          ),
          radius,
        ),
        Paint()..color = fill,
      );
    }
  }

  @override
  bool shouldRepaint(_DayBarPainter old) =>
      old.axis.start != axis.start ||
      old.axis.end != axis.end ||
      old.worked.toString() != worked.toString() ||
      old.scheduled != scheduled ||
      old.track != track ||
      old.fill != fill ||
      old.schedule != schedule;
}

// ── Desktop: a table ────────────────────────────────────────────────

const _flexPerson = 3;
const _flexShifts = 4;
const _flexBar = 4;
const _widthWorked = 120.0;
const _flexStatus = 3;
const _widthActions = 196.0;

class _HeaderRow extends StatelessWidget {
  const _HeaderRow();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    Widget cell(String text) => Text(text.toUpperCase(), style: style);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          Expanded(flex: _flexPerson, child: cell('Person')),
          Expanded(flex: _flexShifts, child: cell('Shifts')),
          Expanded(flex: _flexBar, child: cell('Day')),
          SizedBox(width: _widthWorked, child: cell('Worked')),
          Expanded(flex: _flexStatus, child: cell('Status')),
          const SizedBox(width: _widthActions),
        ],
      ),
    );
  }
}

class _TableRow extends StatelessWidget {
  const _TableRow({
    super.key,
    required this.row,
    required this.axis,
    required this.formats,
    required this.actions,
  });

  final Map<String, dynamic> row;
  final _Axis axis;
  final DisplayFormats formats;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final person = (row['person'] as Map?) ?? const {};
    final chips = _chips(row);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Expanded(
            flex: _flexPerson,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${person['name'] ?? ''}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                if (person['role'] != null)
                  Text(
                    '${person['role']}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            flex: _flexShifts,
            child: Padding(
              padding: const EdgeInsets.only(right: 12),
              child: _shiftsText(context, row),
            ),
          ),
          Expanded(
            flex: _flexBar,
            child: Padding(
              padding: const EdgeInsets.only(right: 16),
              child: _DayBar(row: row, axis: axis),
            ),
          ),
          SizedBox(
            width: _widthWorked,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _workedText(row),
                  key: ValueKey('approval-worked-${person['id']}'),
                  style: theme.textTheme.bodyMedium,
                ),
                if (chips.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Wrap(spacing: 4, runSpacing: 4, children: chips),
                ],
              ],
            ),
          ),
          Expanded(
            flex: _flexStatus,
            child: Wrap(
              spacing: 4,
              runSpacing: 4,
              children: _status(row, formats),
            ),
          ),
          SizedBox(
            width: _widthActions,
            child: Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              runSpacing: 6,
              children: actions,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Phone: a card per person ────────────────────────────────────────

class _PhoneCard extends StatelessWidget {
  const _PhoneCard({
    super.key,
    required this.row,
    required this.axis,
    required this.formats,
    required this.actions,
  });

  final Map<String, dynamic> row;
  final _Axis axis;
  final DisplayFormats formats;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final person = (row['person'] as Map?) ?? const {};

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${person['name'] ?? ''}',
                style: theme.textTheme.titleSmall,
              ),
              if (person['role'] != null)
                Text(
                  '${person['role']}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ..._chips(row),
            ],
          ),
          const SizedBox(height: 6),
          _shiftsText(context, row),
          const SizedBox(height: 2),
          Text(
            _workedText(row),
            key: ValueKey('approval-worked-${person['id']}'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          _DayBar(row: row, axis: axis),
          const SizedBox(height: 8),
          Wrap(spacing: 4, runSpacing: 4, children: _status(row, formats)),
          if (actions.isNotEmpty) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                for (final (i, a) in actions.indexed) ...[
                  if (i > 0) const SizedBox(width: 8),
                  Expanded(child: a),
                ],
              ],
            ),
          ],
        ],
      ),
    );
  }
}

// ── Adjust ──────────────────────────────────────────────────────────

/// How a shift ended, for "Was 16:05 (Auto)".
String _endedBy(Object? endedBy) => switch (endedBy) {
  'person' => 'they clocked out',
  'schedule' => 'Auto',
  'automation' => 'an automation',
  'manager' => 'a manager',
  'correction' => 'corrected',
  _ => '',
};

/// Each shift's in and out, a reason, and the changes already made. Saving
/// posts each changed shift and stays open on the refreshed list of changes.
class _AdjustPanel extends StatefulWidget {
  const _AdjustPanel({
    required this.api,
    required this.formats,
    required this.dayLabel,
    required this.row,
    required this.personId,
    required this.onSaved,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final String dayLabel;
  final Map<String, dynamic> row;
  final String personId;
  final ValueChanged<ApiResponse> onSaved;

  @override
  State<_AdjustPanel> createState() => _AdjustPanelState();
}

class _AdjustPanelState extends State<_AdjustPanel> {
  late Map<String, dynamic> _row = widget.row;
  final _reason = TextEditingController();
  final Map<String, (TextEditingController, TextEditingController)> _times = {};
  final Map<String, (String?, String?)> _held = {};
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fill();
  }

  void _fill() {
    for (final shift in _shiftsOf(_row)) {
      final id = '${shift['id']}';
      final inAt = shift['clocked_in_local'] as String?;
      final outAt = shift['clocked_out_local'] as String?;
      final pair = _times.putIfAbsent(
        id,
        () => (TextEditingController(), TextEditingController()),
      );
      pair.$1.text = inAt ?? '';
      pair.$2.text = outAt ?? '';
      _held[id] = (inAt, outAt);
    }
  }

  @override
  void dispose() {
    _reason.dispose();
    for (final pair in _times.values) {
      pair.$1.dispose();
      pair.$2.dispose();
    }
    super.dispose();
  }

  /// Per shift, the fields that differ from what the server holds.
  Map<String, Map<String, String>> get _changes => {
    for (final MapEntry(key: id, value: pair) in _times.entries)
      if (<String, String>{
            if (pair.$1.text.trim().isNotEmpty &&
                pair.$1.text.trim() != _held[id]?.$1)
              'clocked_in_at': pair.$1.text.trim(),
            if (pair.$2.text.trim().isNotEmpty &&
                pair.$2.text.trim() != _held[id]?.$2)
              'clocked_out_at': pair.$2.text.trim(),
          }
          case final fields when fields.isNotEmpty)
        id: fields,
  };

  Future<void> _save() async {
    final reason = _reason.text.trim();
    final changes = _changes;
    if (reason.isEmpty || changes.isEmpty) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    ApiResponse? last;
    for (final MapEntry(key: id, value: fields) in changes.entries) {
      final response = await widget.api.post(
        '/hr/time-clock/approvals/shifts/$id/adjust',
        {...fields, 'reason': reason},
      );
      if (!mounted) return;
      if (!response.ok) {
        setState(() {
          _saving = false;
          _error = response.reason;
        });
        if (last != null) widget.onSaved(last);
        return;
      }
      last = response;
    }
    if (last == null) return;
    widget.onSaved(last);
    final rows = ((last.data as Map?)?['rows'] as List?) ?? const [];
    final fresh = rows.whereType<Map>().where(
      (r) => '${(r['person'] as Map?)?['id']}' == widget.personId,
    );
    setState(() {
      _saving = false;
      if (fresh.isNotEmpty) _row = Map<String, dynamic>.from(fresh.first);
      _reason.clear();
      _fill();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final person = (_row['person'] as Map?) ?? const {};
    final shifts = _shiftsOf(_row);
    final adjustments = _adjustmentsOf(_row);
    final canSave =
        !_saving && _reason.text.trim().isNotEmpty && _changes.isNotEmpty;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return SidePanel(
      title: '${person['name'] ?? ''} · ${widget.dayLabel}',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
        FilledButton(
          key: const ValueKey('approval-adjust-save'),
          onPressed: canSave ? _save : null,
          child: const Text('Save the change'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, shift) in shifts.indexed) ...[
            if (i > 0) const SizedBox(height: 16),
            Text('Shift ${i + 1}', style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            KeyedSubtree(
              key: ValueKey('approval-adjust-in-${shift['id']}'),
              child: CalendarDayField(
                controller: _times['${shift['id']}']!.$1,
                formats: widget.formats,
                label: 'In',
                withTime: true,
                dense: true,
                onChanged: (_) => setState(() {}),
              ),
            ),
            const SizedBox(height: 8),
            KeyedSubtree(
              key: ValueKey('approval-adjust-out-${shift['id']}'),
              child: CalendarDayField(
                controller: _times['${shift['id']}']!.$2,
                formats: widget.formats,
                label: 'Out',
                withTime: true,
                dense: true,
                onChanged: (_) => setState(() {}),
              ),
            ),
            if (_wasLine(shift) case final was?)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  was,
                  key: ValueKey('approval-was-${shift['id']}'),
                  style: muted,
                ),
              ),
          ],
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('approval-adjust-reason'),
            controller: _reason,
            maxLength: 500,
            minLines: 2,
            maxLines: 4,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Reason (required)',
              helperText: 'Kept with the change. Adjusting does not approve.',
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_error!),
          ],
          if (adjustments.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Changes', style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            for (final (i, a) in adjustments.indexed)
              Padding(
                key: ValueKey('approval-change-$i'),
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(_changeLine(a), style: theme.textTheme.bodySmall),
              ),
          ],
        ],
      ),
    );
  }

  /// "Was 16:05 (Auto)": the original, before the first adjustment, and
  /// how the shift ended.
  String? _wasLine(Map<String, dynamic> shift) {
    final original = _originalOf(_row, shift['id']);
    final how = _endedBy(shift['ended_by']);
    final wasOut = original?['was_out'] ?? shift['clocked_out'];
    final wasIn = original?['was_in'];
    final out = wasOut is String
        ? '$wasOut${how.isEmpty ? '' : ' ($how)'}'
        : null;
    if (wasIn is String && wasIn != shift['clocked_in']) {
      return out == null ? 'Was in $wasIn' : 'Was in $wasIn, out $out';
    }
    return out == null ? null : 'Was $out';
  }

  String _changeLine(Map<String, dynamic> a) {
    final moves = [
      if (a['was_in'] != a['now_in'])
        'In ${a['was_in'] ?? '—'} → ${a['now_in'] ?? '—'}',
      if (a['was_out'] != a['now_out'])
        'Out ${a['was_out'] ?? '—'} → ${a['now_out'] ?? '—'}',
    ];
    return [
      ...moves,
      if (a['by'] != null) '${a['by']}',
      formatStamp(a['at'], '', formats: widget.formats),
      '"${a['reason'] ?? ''}"',
    ].where((s) => s.isNotEmpty).join(' · ');
  }
}
