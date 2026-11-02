import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';

/// Scheduling › Overbooking (Jose, 2026-09-29: "a new tab on scheduling that
/// warns me about all the overbookings"). Days booked past what an area or a
/// franchise takes, from today forward, and the areas with no spot left in the
/// next seven days. The rules are the server's (App\Support\Scheduling\
/// Overbooking); the same list is what the morning warning email reports.
class OverbookingPane extends StatefulWidget {
  const OverbookingPane({
    super.key,
    required this.api,
    required this.visible,
    this.embedded = false,
  });

  final ApiClient api;
  final bool visible;

  /// Drawn inside another panel (Reports' Overbooking warning): the morning
  /// email's seven days only, with no heading, spans or scroll of its own.
  final bool embedded;

  @override
  State<OverbookingPane> createState() => _OverbookingPaneState();
}

class _OverbookingPaneState extends State<OverbookingPane> {
  /// Rows opened to their jobs (Bar, via Jose, 2026-10-12: "make everything
  /// clickable to show its full information"), keyed by the row's key, each
  /// with the request that fills it. A reload closes them: the list must be
  /// the count, and the count may have moved.
  final Map<String, Future<ApiResponse>> _opened = {};
  static const _spans = [7, 14, 30, 60];

  int _days = 30;

  /// One day on its own, picked to fix it (Jose, 2026-09-29); null is the
  /// span in [_days].
  String? _date;
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    if (widget.embedded) _days = 7;
    _load();
  }

  @override
  void didUpdateWidget(covariant OverbookingPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });

    _opened.clear();
    final response = await widget.api.get(
      '/scheduling/overbooking',
      _date == null ? {'days': '$_days'} : {'date': _date!},
    );
    if (!mounted || ticket != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _data = response.body['data'] as Map<String, dynamic>?;
    });
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _date == null ? now : DateTime.parse(_date!),
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 2),
    );
    if (picked == null || !mounted) return;
    setState(
      () => _date =
          '${picked.year}-${picked.month.toString().padLeft(2, '0')}-'
          '${picked.day.toString().padLeft(2, '0')}',
    );
    _load();
  }

  static String _day(String ymd) {
    final d = DateTime.parse(ymd);
    return '${shortWeekdays[d.weekday - 1]}, ${shortMonths[d.month - 1]} ${d.day}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final data = _data;
    final over = (data?['overbooked'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final full = (data?['fully_booked'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    // The server's number (Shoval, 2026-10-05: full "10 days and above" is
    // when ads get paused), so the tab and the morning email always agree.
    final fullDays = (data?['full_days'] as num?)?.toInt() ?? 10;

    final byDate = <String, List<Map<String, dynamic>>>{};
    for (final r in over) {
      (byDate[r['date'] as String] ??= []).add(r);
    }

    final body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!widget.embedded) ...[
          Row(
            children: [
              Expanded(
                child: Text('Overbooking', style: theme.textTheme.titleMedium),
              ),
              IconButton(
                tooltip: 'Refresh',
                icon: const Icon(Icons.refresh),
                onPressed: _load,
              ),
            ],
          ),
          Text(
            'Days booked past what an area or a franchise takes, and '
            'areas with no spot left for the next $fullDays days or more. '
            'A warning email goes out each morning when an area is that '
            'full.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: 14),
        ],
        // Two tiles, one size (Jose: "you always make that mistake").
        IntrinsicHeight(
          key: const ValueKey('tour-scheduling-overbooking-counts'),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _stat(
                  theme,
                  key: 'overbooked',
                  count: over.length,
                  label: over.length == 1
                      ? 'overbooked day'
                      : 'overbooked days',
                  tone: over.isEmpty ? Tone.ok : Tone.bad,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _stat(
                  theme,
                  key: 'full',
                  count: full.length,
                  label: full.length == 1
                      ? 'area fully booked ($fullDays+ days)'
                      : 'areas fully booked ($fullDays+ days)',
                  tone: full.isEmpty ? Tone.ok : Tone.caution,
                ),
              ),
            ],
          ),
        ),
        if (!widget.embedded) ...[
          const SizedBox(height: 16),
          Wrap(
            key: const ValueKey('tour-scheduling-overbooking-span'),
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'Overbooked in the next',
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              for (final n in _spans)
                ChoiceChip(
                  label: Text('$n days'),
                  selected: _date == null && _days == n,
                  onSelected: (_) {
                    setState(() {
                      _days = n;
                      _date = null;
                    });
                    _load();
                  },
                ),
              _date == null
                  ? ActionChip(
                      key: const Key('overbooking-pick-date'),
                      avatar: const Icon(Icons.event, size: 18),
                      label: const Text('Pick a date'),
                      onPressed: _pickDate,
                    )
                  : InputChip(
                      key: const Key('overbooking-pick-date'),
                      avatar: const Icon(Icons.event, size: 18),
                      label: Text(_day(_date!)),
                      selected: true,
                      onPressed: _pickDate,
                      deleteButtonTooltipMessage: 'Clear the date',
                      onDeleted: () {
                        setState(() => _date = null);
                        _load();
                      },
                    ),
            ],
          ),
        ],
        const SizedBox(height: 10),
        if (over.isEmpty)
          _allClear(
            theme,
            _date != null
                ? 'Nothing is overbooked on ${_day(_date!)}.'
                : 'No overbooked days from today through the next '
                      '$_days days.',
          )
        else
          for (final e in byDate.entries) ...[
            Padding(
              padding: const EdgeInsets.only(top: 10, bottom: 4),
              child: Text(
                _day(e.key).toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  letterSpacing: 0.8,
                  color: muted,
                ),
              ),
            ),
            for (final r in e.value) _overRow(theme, r),
          ],
        const SizedBox(height: 24),
        Text(
          'Fully booked for $fullDays days or more',
          key: const ValueKey('tour-scheduling-fully-booked'),
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 6),
        if (full.isEmpty)
          _allClear(
            theme,
            'Every area that works in the next $fullDays days still has a '
            'spot.',
          )
        else
          for (final r in full) _fullRow(theme, r),
      ],
    );

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: widget.embedded
          ? body
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 32),
              children: [
                Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 900),
                    child: body,
                  ),
                ),
              ],
            ),
    );
  }

  Widget _stat(
    ThemeData theme, {
    required String key,
    required int count,
    required String label,
    required Tone tone,
  }) {
    final c = ToneColours.of(context, tone);
    return Container(
      key: ValueKey('overbooking-stat-$key'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Text(
            '$count',
            style: theme.textTheme.headlineSmall?.copyWith(
              color: c.ink,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodyMedium?.copyWith(color: c.ink),
            ),
          ),
        ],
      ),
    );
  }

  Widget _row(
    ThemeData theme, {
    required Key key,
    required IconData icon,
    required Tone tone,
    required String title,
    required String detail,
    String? pill,
    VoidCallback? onTap,
    bool open = false,
    Widget? below,
  }) {
    final c = ToneColours.of(context, tone);
    final head = Row(
      children: [
        Icon(icon, size: 20, color: c.ink),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: theme.textTheme.titleSmall),
              Text(
                detail,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
        if (pill != null)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: c.fill,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              pill,
              style: theme.textTheme.labelMedium?.copyWith(
                color: c.ink,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        if (onTap != null)
          Icon(
            open ? Icons.expand_less : Icons.expand_more,
            color: theme.colorScheme.onSurfaceVariant,
          ),
      ],
    );

    return Container(
      key: key,
      margin: const EdgeInsets.symmetric(vertical: 3),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: head,
            ),
          ),
          if (open && below != null) below,
        ],
      ),
    );
  }

  /// The jobs behind an overbooked row, each with its client and appointment.
  Widget _jobs(ThemeData theme, Future<ApiResponse> request) {
    final muted = theme.colorScheme.onSurfaceVariant;
    return FutureBuilder<ApiResponse>(
      future: request,
      builder: (context, snap) {
        final response = snap.data;
        if (response == null) {
          return const Padding(
            padding: EdgeInsets.all(12),
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (!response.ok) {
          return Padding(
            padding: const EdgeInsets.all(12),
            child: Text(response.message, style: TextStyle(color: muted)),
          );
        }
        final jobs = (response.body['data'] as List? ?? const [])
            .cast<Map<String, dynamic>>();
        return SelectionArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final j in jobs) _job(theme, j),
              if (jobs.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'No jobs on this day any more.',
                    style: TextStyle(color: muted),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _job(ThemeData theme, Map<String, dynamic> j) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final client = (j['customer'] as Map?)?.cast<String, dynamic>() ?? {};
    final techs = (j['technicians'] as List? ?? const []).join(', ');
    final time = j['time'] == null
        ? 'No time set'
        : [
            _clock(j['time'] as String),
            if (j['end_time'] != null) _clock(j['end_time'] as String),
          ].join(' – ');

    Widget line(IconData icon, String? text) => text == null || text.isEmpty
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(top: 3),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 16, color: muted),
                const SizedBox(width: 8),
                Expanded(child: Text(text, style: theme.textTheme.bodySmall)),
              ],
            ),
          );

    return Container(
      key: ValueKey(
        'overbooked-job-${j['job_id'] ?? j['lead_id'] ?? j['number']}-${j['time']}',
      ),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            [
              time,
              j['service'],
              if (j['number'] != null) '#${j['number']}',
            ].whereType<String>().join('  ·  '),
            style: theme.textTheme.titleSmall,
          ),
          Text(
            [client['name'], j['status']].whereType<String>().join('  ·  '),
            style: theme.textTheme.bodyMedium,
          ),
          line(Icons.phone_outlined, client['phone'] as String?),
          line(Icons.mail_outline, client['email'] as String?),
          line(Icons.place_outlined, client['address'] as String?),
          line(
            Icons.engineering_outlined,
            techs.isEmpty ? 'No technician yet' : techs,
          ),
          line(Icons.notes_outlined, j['notes'] as String?),
        ],
      ),
    );
  }

  /// "14:00" → "2:00 PM".
  static String _clock(String hhmm) {
    final h = int.tryParse(hhmm.substring(0, 2)) ?? 0;
    final m = hhmm.substring(3, 5);
    return '${h % 12 == 0 ? 12 : h % 12}:$m ${h < 12 ? 'AM' : 'PM'}';
  }

  Widget _overRow(ThemeData theme, Map<String, dynamic> r) {
    final franchise = r['level'] == 'franchise';
    final title = franchise
        ? '${r['name']} (whole franchise)'
        : [r['franchise_name'], r['name']].whereType<String>().join(' › ');
    final detail = r['closed'] == true
        ? '${r['booked']} booked on a day this area is closed'
        : '${r['booked']} booked of ${r['capacity']}'
              '${franchise ? ' (daily limit)' : ''}';

    final id = 'overbooked-${r['date']}-${r['level']}-${r['name']}';
    final request = _opened[id];

    return _row(
      theme,
      key: ValueKey(id),
      icon: franchise ? Icons.domain : Icons.warning_amber_rounded,
      tone: Tone.bad,
      title: title,
      detail: detail,
      pill: '${r['over']} over',
      open: request != null,
      below: request == null ? null : _jobs(theme, request),
      onTap: () => setState(() {
        if (_opened.remove(id) == null) {
          _opened[id] = widget.api.get('/scheduling/overbooking/jobs', {
            'date': r['date'] as String,
            if (franchise)
              'franchise_id': '${r['franchise_id']}'
            else
              'area_id': '${r['area_id']}',
          });
        }
      }),
    );
  }

  Widget _fullRow(ThemeData theme, Map<String, dynamic> r) {
    final next = r['next_opening'] as String?;
    return _row(
      theme,
      key: ValueKey('full-${r['area_id']}'),
      icon: Icons.event_busy_outlined,
      tone: Tone.caution,
      title: [r['franchise_name'], r['name']].whereType<String>().join(' › '),
      detail: next == null
          ? 'No opening found in the next year'
          : 'Next opening ${_day(next)} (${_daysAway(next)} days away)',
    );
  }

  static int _daysAway(String ymd) {
    final now = DateTime.now();
    return DateTime.parse(
      ymd,
    ).difference(DateTime(now.year, now.month, now.day)).inDays;
  }

  Widget _allClear(ThemeData theme, String text) {
    final c = ToneColours.of(context, Tone.ok);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(Icons.check_circle_outline, color: c.ink, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodyMedium?.copyWith(color: c.ink),
            ),
          ),
        ],
      ),
    );
  }
}
