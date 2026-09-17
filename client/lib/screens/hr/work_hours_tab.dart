/// HR › Work Hours — a week, a person at a time (Jose: desktop 1, mobile 4).
///
/// ── What he asked for, and the two screens inside it ──────────────
///
/// His list on 2026-09-17 contained two different screens. The first bullet
/// asked for *one expanding row per user* — a single list you open in place.
/// The last asked for *a left sidebar of users with the week beside it*. They
/// answer different questions and building one does not give you the other, so
/// it went to him as a numbered choice and he picked the sidebar.
///
/// **It is the only shape that satisfies both bullets**, which is why it was
/// the recommendation: the sidebar IS one row per person — his actual
/// complaint was appearing several times on the old screen — and selecting
/// somebody shows every clock in and out grouped by day, which is the
/// expansion he asked for.
///
/// On a phone the same idea is a list that pushes a screen. He chose that over
/// a list that expands in place; the case for expanding is in queue entry 20b
/// and he read it.
///
/// ── Four facts from Clock In's brief that shaped the drawing ──────
///
///   * **Everybody in reach is in `people`, including anyone who worked
///     nothing.** They are drawn greyed with an em dash, never dropped — a
///     name missing from this list reads as "not on the team", which is not
///     what "did not clock in" means.
///   * **An open shift reports the time worked SO FAR**, not null, because it
///     is summed into the total beside it. `open` is the green indicator, and
///     it sits on the row as well as the shift: "who is on the clock right
///     now" is what somebody opens this tab asking at 9am, and that is
///     answered before any click.
///   * **`clocked_in` / `clocked_out` arrive already formatted** in the
///     organization's clock and 12/24h setting. Nothing here formats a time.
///   * **Sunday is a default, not a rule.** The week start is an organization
///     setting, so the header reads what the server sends rather than assuming.
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/ui_strings.dart';
import '../../widgets/async_body.dart';
import '../../widgets/user_avatar.dart';

class WorkHoursTab extends StatefulWidget {
  const WorkHoursTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<WorkHoursTab> createState() => _WorkHoursTabState();
}

class _WorkHoursTabState extends State<WorkHoursTab> {
  bool _loading = true;
  String? _error;
  int _request = 0;

  /// Which week is shown, as the server's own date. Null means "this week",
  /// which is what the endpoint answers when `on` is omitted.
  String? _on;

  _Week? _week;
  String? _personId;

  String _search = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({String? on}) async {
    final request = ++_request;
    _on = on;

    setState(() {
      _loading = _week == null;
      _error = null;
    });

    final response = await widget.api.get(
      on == null ? '/hr/time-clock/week' : '/hr/time-clock/week?on=$on',
    );

    // A slower earlier request must not overwrite a later answer: the week
    // arrows are two taps apart and the second one is the one wanted.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;

        return;
      }

      _week = _Week.from(response.body['data'] as Map<String, dynamic>?);
      final people = _week?.people ?? const <_Person>[];
      if (_personId == null || !people.any((p) => p.id == _personId)) {
        _personId = people.isEmpty ? null : people.first.id;
      }
    });
  }

  _Person? get _person {
    for (final p in _week?.people ?? const <_Person>[]) {
      if (p.id == _personId) return p;
    }

    return null;
  }

  List<_Person> get _visible {
    final needle = _search.trim().toLowerCase();
    final people = _week?.people ?? const <_Person>[];
    if (needle.isEmpty) return people;

    return [
      for (final p in people)
        if (p.name.toLowerCase().contains(needle)) p,
    ];
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: () => _load(on: _on),
      child: LayoutBuilder(
        // The pane's own width, not the window's. HR sits in a split on a
        // wide screen and a tab on a narrow one, so the window says nothing
        // about the room here.
        builder: (context, box) =>
            box.maxWidth >= 760 ? _wide(context) : _narrow(context),
      ),
    );
  }

  // ── desktop 1: the list beside one person ────────────────────────

  Widget _wide(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WeekBar(week: _week, onMove: (on) => _load(on: on)),
        Expanded(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                width: 268,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    border: Border(
                      right: BorderSide(color: scheme.outlineVariant),
                    ),
                  ),
                  child: _peopleList(context, tappable: false),
                ),
              ),
              Expanded(
                child: _person == null
                    ? const SizedBox.shrink()
                    : _PersonWeek(
                        api: widget.api,
                        person: _person!,
                        week: _week!,
                      ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  // ── mobile 4: the list, tap through to a person ──────────────────

  Widget _narrow(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WeekBar(week: _week, onMove: (on) => _load(on: on)),
        Expanded(child: _peopleList(context, tappable: true)),
      ],
    );
  }

  Widget _peopleList(BuildContext context, {required bool tappable}) {
    final theme = Theme.of(context);
    final people = _visible;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
          child: TextField(
            decoration: InputDecoration(
              isDense: true,
              prefixIcon: const Icon(Icons.search, size: 18),
              hintText: UiStrings.t('time_clock.search_people'),
            ),
            onChanged: (value) => setState(() => _search = value),
          ),
        ),
        Expanded(
          child: people.isEmpty
              ? Center(
                  child: Text(
                    UiStrings.t('time_clock.no_hours'),
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : ListView.builder(
                  itemCount: people.length,
                  itemBuilder: (context, i) => _PersonRow(
                    api: widget.api,
                    person: people[i],
                    selected: !tappable && people[i].id == _personId,
                    trailing: tappable,
                    onTap: () {
                      if (!tappable) {
                        setState(() => _personId = people[i].id);

                        return;
                      }

                      Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (context) => Scaffold(
                            appBar: AppBar(title: Text(people[i].name)),
                            body: _PersonWeek(
                              api: widget.api,
                              person: people[i],
                              week: _week!,
                            ),
                          ),
                        ),
                      );
                    },
                  ),
                ),
        ),
      ],
    );
  }
}

/// Which week, and the way back to another one.
///
/// Not on Jose's list. The tab this replaces has day arrows, so shipping
/// without any way to look back would take away something he has today —
/// raised with him as a question rather than assumed either way.
class _WeekBar extends StatelessWidget {
  const _WeekBar({required this.week, required this.onMove});

  final _Week? week;
  final void Function(String?) onMove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final w = week;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          IconButton(
            onPressed: w == null ? null : () => onMove(w.previousOn),
            icon: const Icon(Icons.chevron_left),
            iconSize: 20,
            tooltip: 'Previous week',
          ),
          Expanded(
            child: Text(
              w == null ? '' : w.label,
              textAlign: TextAlign.center,
              style: theme.textTheme.labelLarge,
            ),
          ),
          IconButton(
            onPressed: w == null ? null : () => onMove(w.nextOn),
            icon: const Icon(Icons.chevron_right),
            iconSize: 20,
            tooltip: 'Next week',
          ),
        ],
      ),
    );
  }
}

/// One person in the list: picture, name, the week's total, and whether they
/// are on the clock right now.
class _PersonRow extends StatelessWidget {
  const _PersonRow({
    required this.api,
    required this.person,
    required this.selected,
    required this.trailing,
    required this.onTap,
  });

  final ApiClient api;
  final _Person person;
  final bool selected;
  final bool trailing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final worked = person.totalSeconds > 0;

    return InkWell(
      onTap: onTap,
      child: Container(
        color: selected ? scheme.primary.withValues(alpha: 0.08) : null,
        padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
        child: Row(
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                UserAvatar(
                  api: api,
                  userId: person.id,
                  name: person.name,
                  radius: 15,
                  hasAvatar: person.hasAvatar,
                ),
                // Green means on the clock right now. It is on the ROW rather
                // than only on the shift because that is the question somebody
                // opens this tab asking, and it should not need a click.
                if (person.open)
                  Positioned(
                    right: -1,
                    bottom: -1,
                    child: Container(
                      width: 11,
                      height: 11,
                      decoration: BoxDecoration(
                        color: const Color(0xFF1A7F37),
                        shape: BoxShape.circle,
                        border: Border.all(color: scheme.surface, width: 2),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                person.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                  // Somebody who worked nothing is still on the team. Greyed,
                  // never dropped.
                  color: worked ? null : scheme.onSurfaceVariant,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              worked ? formatWorked(person.totalSeconds) : '—',
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: worked ? FontWeight.w700 : FontWeight.w400,
                color: worked ? null : scheme.onSurfaceVariant,
              ),
            ),
            if (trailing) ...[
              const SizedBox(width: 4),
              Icon(
                Icons.chevron_right,
                size: 18,
                color: scheme.onSurfaceVariant,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// One person's week: the three numbers he asked for, then every clock in and
/// out grouped by day.
class _PersonWeek extends StatelessWidget {
  const _PersonWeek({
    required this.api,
    required this.person,
    required this.week,
  });

  final ApiClient api;
  final _Person person;
  final _Week week;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final days = person.byDay();

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
      children: [
        Row(
          children: [
            UserAvatar(
              api: api,
              userId: person.id,
              name: person.name,
              radius: 22,
              hasAvatar: person.hasAvatar,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(person.name, style: theme.textTheme.titleMedium),
                  if (week.timezoneLabel.isNotEmpty)
                    Text(
                      week.timezoneLabel,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            _Stat(
              label: UiStrings.t('time_clock.total'),
              value: formatWorked(person.totalSeconds),
            ),
            const SizedBox(width: 10),
            _Stat(
              label: UiStrings.t('time_clock.days_worked'),
              value: '${person.daysWorked}',
            ),
            const SizedBox(width: 10),
            _Stat(
              label: UiStrings.t('time_clock.average_per_day'),
              value: person.daysWorked == 0
                  ? '—'
                  : formatWorked(person.averageSeconds),
            ),
          ],
        ),
        const SizedBox(height: 18),
        if (week.days.isEmpty)
          // Only when the server sent no week at all. A week where nobody
          // worked still has seven days and seven headings.
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 24),
            child: Text(
              UiStrings.t('time_clock.no_hours'),
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          )
        else
          for (final day in week.days)
            _DayCard(label: day.label, shifts: days[day.date] ?? const []),
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Expanded(
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 11),
        decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
          border: Border.all(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(11),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                color: scheme.onSurfaceVariant,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.5,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DayCard extends StatelessWidget {
  const _DayCard({required this.label, required this.shifts});

  /// The server's own words, in the organization's language and date format.
  final String label;

  final List<_Shift> shifts;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    var total = 0;
    for (final s in shifts) {
      total += s.durationSeconds;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
              borderRadius: const BorderRadius.vertical(
                top: Radius.circular(10),
              ),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(label, style: theme.textTheme.labelLarge),
                ),
                Text(
                  formatWorked(total),
                  style: theme.textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          if (shifts.isEmpty)
            Container(
              padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
              decoration: BoxDecoration(
                border: Border(top: BorderSide(color: scheme.outlineVariant)),
              ),
              child: Text(
                UiStrings.t('time_clock.no_hours'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            ),
          for (final shift in shifts) _ShiftRow(shift: shift),
        ],
      ),
    );
  }
}

class _ShiftRow extends StatelessWidget {
  const _ShiftRow({required this.shift});

  final _Shift shift;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          // Green in, red out — his words. The times are the server's own
          // strings; nothing here formats a clock.
          _Pip(colour: const Color(0xFF1A7F37)),
          const SizedBox(width: 6),
          Text(shift.clockedIn, style: theme.textTheme.bodySmall),
          const SizedBox(width: 12),
          if (shift.open)
            Text(
              'still clocked in',
              style: theme.textTheme.bodySmall?.copyWith(
                color: const Color(0xFF1A7F37),
                fontWeight: FontWeight.w700,
              ),
            )
          else ...[
            _Pip(colour: scheme.error),
            const SizedBox(width: 6),
            Text(shift.clockedOut, style: theme.textTheme.bodySmall),
          ],
          // Carried over from the tab this replaces rather than reinvented.
          // Neither is on Jose's list, and dropping what a screen already
          // tells somebody is a loss whether or not anybody asked to keep it
          // -- the old tests pinned all three, which is how I found out.
          if (shift.endedBy == 'schedule') ...[
            const SizedBox(width: 8),
            _Chip(
              text: UiStrings.t('time_clock.auto'),
              colour: scheme.onSurfaceVariant,
            ),
          ],
          if (shift.outsideSchedule) ...[
            const SizedBox(width: 6),
            Flexible(
              child: _Chip(
                text: UiStrings.t('time_clock.outside'),
                colour: const Color(0xFF8A5A00),
              ),
            ),
          ],
          if (shift.extensions > 0) ...[
            const SizedBox(width: 6),
            _Chip(
              text: '+${shift.extensions}',
              colour: scheme.onSurfaceVariant,
            ),
          ],
          const Spacer(),
          Text(
            formatWorked(shift.durationSeconds),
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.text, required this.colour});

  final String text;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
        child: Text(
          text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.labelSmall?.copyWith(
            color: colour,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
  }
}

class _Pip extends StatelessWidget {
  const _Pip({required this.colour});

  final Color colour;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 8,
      height: 8,
      decoration: BoxDecoration(color: colour, shape: BoxShape.circle),
    );
  }
}

// ── the payload ────────────────────────────────────────────────────

class _Week {
  const _Week({
    required this.start,
    required this.end,
    required this.label,
    required this.timezoneLabel,
    required this.people,
    required this.days,
  });

  final String start;
  final String end;
  final String label;
  final String timezoneLabel;
  final List<_Person> people;

  /// All seven, in order from `week_start`, **including the days nobody
  /// worked** — which is what lets a Sunday with no hours carry a heading
  /// without this screen deriving one. It is the day loop as well as the
  /// labels: iterate it, filter the shifts by date, and an empty result IS
  /// the "no hours" row.
  final List<_Day> days;

  /// A date inside the week before this one, for the arrows.
  String? get previousOn => _shift(start, -7);
  String? get nextOn => _shift(start, 7);

  static String? _shift(String date, int days) {
    final at = DateTime.tryParse(date);
    if (at == null) return null;
    final moved = at.add(Duration(days: days));

    return '${moved.year.toString().padLeft(4, '0')}-'
        '${moved.month.toString().padLeft(2, '0')}-'
        '${moved.day.toString().padLeft(2, '0')}';
  }

  static _Week? from(Map<String, dynamic>? json) {
    if (json == null) return null;

    final start = '${json['week_start'] ?? ''}';
    final end = '${json['week_end'] ?? ''}';

    final days = [
      for (final row in (json['days'] as List?) ?? const [])
        if (row is Map<String, dynamic>) _Day.from(row),
    ];

    // Built from the server's own labels rather than two dates formatted
    // here. Spanish organizations get "domingo, 13 sept", and month-first or
    // day-first follows the organization's date_format, so this heading
    // cannot contradict every other date on the screen.
    final label = days.length < 2
        ? ''
        : '${days.first.label} – ${days.last.label}';

    return _Week(
      start: start,
      end: end,
      // Built from the server's dates rather than assuming Sunday: the week
      // start is an organization setting and all seven days are allowed.
      label: label.isEmpty
          ? UiStrings.t('time_clock.this_week')
          : label,
      days: days,
      timezoneLabel: '${json['timezone_label'] ?? ''}',
      people: [
        for (final row in (json['people'] as List?) ?? const [])
          if (row is Map<String, dynamic>) _Person.from(row),
      ],
    );
  }
}

class _Person {
  const _Person({
    required this.id,
    required this.name,
    required this.hasAvatar,
    required this.totalSeconds,
    required this.daysWorked,
    required this.averageSeconds,
    required this.open,
    required this.shifts,
  });

  final String id;
  final String name;
  final bool hasAvatar;
  final int totalSeconds;
  final int daysWorked;
  final int averageSeconds;

  /// On the clock right now.
  final bool open;

  final List<_Shift> shifts;

  /// Shifts under their date, in the order the server sent them.
  Map<String, List<_Shift>> byDay() {
    final out = <String, List<_Shift>>{};
    for (final s in shifts) {
      (out[s.date] ??= []).add(s);
    }

    return out;
  }

  static _Person from(Map<String, dynamic> json) => _Person(
    id: '${json['id']}',
    name: '${json['name'] ?? ''}',
    hasAvatar: json['has_avatar'] as bool? ?? false,
    totalSeconds: (json['total_seconds'] as num?)?.toInt() ?? 0,
    daysWorked: (json['days_worked'] as num?)?.toInt() ?? 0,
    averageSeconds: (json['average_seconds'] as num?)?.toInt() ?? 0,
    open: json['open'] as bool? ?? false,
    shifts: [
      for (final row in (json['shifts'] as List?) ?? const [])
        if (row is Map<String, dynamic>) _Shift.from(row),
    ],
  );
}

class _Shift {
  const _Shift({
    required this.date,
    required this.clockedIn,
    required this.clockedOut,
    required this.durationSeconds,
    required this.open,
    required this.outsideSchedule,
    required this.endedBy,
    required this.extensions,
  });

  final String date;

  /// Already formatted by the server in the organization's clock and 12/24h
  /// setting. Never re-formatted here.
  final String clockedIn;
  final String clockedOut;

  /// For an open shift this is the time worked SO FAR rather than null,
  /// because it is summed into the total shown beside it.
  final int durationSeconds;

  final bool open;
  final bool outsideSchedule;

  /// Who closed it. `schedule` means nobody did -- the system ended it -- and
  /// the tab this replaces called that **Auto** on the row.
  final String endedBy;

  /// How many times it was extended.
  final int extensions;

  static _Shift from(Map<String, dynamic> json) => _Shift(
    date: '${json['date'] ?? ''}',
    clockedIn: '${json['clocked_in'] ?? ''}',
    clockedOut: '${json['clocked_out'] ?? ''}',
    durationSeconds: (json['duration_seconds'] as num?)?.toInt() ?? 0,
    open: json['open'] as bool? ?? false,
    outsideSchedule: json['outside_schedule'] as bool? ?? false,
    endedBy: '${json['ended_by'] ?? ''}',
    extensions: (json['extensions'] as num?)?.toInt() ?? 0,
  );
}

/// "8h 12m" — a duration, which is arithmetic rather than a clock reading.
///
/// Safe to do here for the same reason the times are not: this is a count of
/// hours and minutes, with no locale, no timezone and no 12/24h question in
/// it. The moment it needs a word it belongs on the server like the rest.
String formatWorked(int seconds) {
  if (seconds <= 0) return '0h 00m';
  final hours = seconds ~/ 3600;
  final minutes = (seconds % 3600) ~/ 60;

  return '${hours}h ${minutes.toString().padLeft(2, '0')}m';
}

/// One of the week's seven days, named by the server.
///
/// **This replaces a local weekday array, and the swap was worth asking
/// for.** A weekday name is locale text on a screen that ships in English and
/// Spanish, and the app had no shared date formatter — three widgets each
/// hardcoded their own English list, so a fourth would have been wrong in the
/// same way three times over. Clock In put it on the payload beside the times
/// they already format.
class _Day {
  const _Day({required this.date, required this.weekday, required this.label});

  final String date;

  /// The English key, untranslated on purpose, for anything that branches on
  /// which day it is. Nobody reads this one.
  final String weekday;

  /// The only part a person reads.
  final String label;

  static _Day from(Map<String, dynamic> json) => _Day(
    date: '${json['date'] ?? ''}',
    weekday: '${json['weekday'] ?? ''}',
    label: '${json['label'] ?? ''}',
  );
}
