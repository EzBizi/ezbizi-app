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
///
/// ── Corrections: a ⋯ on every shift and every day (Jose, 2026-09-24) ──
///
/// He asked for edit, delete, force clock out, extra hours, notes and day tags,
/// and picked layout 1 from the gallery for both sizes: a ⋯ on each row that
/// opens a menu on a desk and a bottom sheet on a phone. One list of actions,
/// two presentations, so the phone and the desk cannot disagree about what is
/// possible. Absent entirely — not disabled — without `work_hours.manage`.
///
/// Every correction answers with the whole week, and the screen replaces its
/// state from that answer: a correction moves the total, days worked and the
/// average, and none of them can be worked out here.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/theme.dart';
import '../../core/ui_strings.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/async_body.dart';
import '../../widgets/user_avatar.dart';
import '../../widgets/work_schedule_card.dart';
import '../../widgets/work_schedule_panel.dart';

class WorkHoursTab extends StatefulWidget {
  const WorkHoursTab({
    super.key,
    required this.api,
    this.session,
    this.canCorrect = false,
  });

  final ApiClient api;

  /// For the schedule editor's time format. Optional so a test can build the
  /// pane alone; the panel falls back to ISO without it.
  final Session? session;

  /// `work_hours.manage`. Off means the ⋯ menus are not drawn at all.
  final bool canCorrect;

  @override
  State<WorkHoursTab> createState() => _WorkHoursTabState();
}

class _WorkHoursTabState extends State<WorkHoursTab> {
  bool _loading = true;
  String? _error;

  /// The server's sentence when Clock in is switched off (409). A state of
  /// the organization rather than a failure: it used to be drawn as a red
  /// error with Try again, which no number of tries can change.
  String? _off;
  int _request = 0;

  /// Which week is shown, as the server's own date. Null means "this week",
  /// which is what the endpoint answers when `on` is omitted.
  String? _on;

  _Week? _week;
  String? _personId;

  String _search = '';

  /// On a phone, the person drawn in place of the list.
  String? _phoneOpenId;

  /// Jose, 2026-09-30: "by default it only shows people who have clocked in
  /// on that week … most recent on the top". Everyone else is one tap away,
  /// and a search always looks at everyone.
  bool _everyone = false;

  /// Only this role, or everybody's when null (Jose, 2026-09-30).
  String? _roleId;

  /// Corrections people asked for, waiting on this manager (Trey Brannan's
  /// suggestion, 2026-09-30). Arrives on the week; absent, so empty, without
  /// `work_hours.manage`.
  List<Map<String, dynamic>> _requests = const [];

  /// The first load, which a tapped notification waits for.
  late Future<void> _firstLoad;

  @override
  void initState() {
    super.initState();
    _firstLoad = _load();
    _takeLink();
    DeepLinks.pending.addListener(_takeLink);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_takeLink);
    super.dispose();
  }

  /// A tapped correction notification opens that request's person at the
  /// week of its clock-in (Notifications, 2026-10-12: "open the exact
  /// thing"). An approver finds it among the waiting requests on the week;
  /// whoever asked finds it among their own. Gone or decided elsewhere: the
  /// tab just stays where it is.
  void _takeLink() {
    final link = DeepLinks.take('work_hours');
    if (link == null || link.type != 'time_clock_correction_request') return;
    unawaited(_openRequest(link.id));
  }

  Future<void> _openRequest(String id) async {
    await _firstLoad;
    Map<String, dynamic>? row;
    bool same(Object? r) => r is Map && '${r['id']}' == id;
    for (final r in _requests) {
      if (same(r)) row = r;
    }
    if (row == null) {
      final mine = await widget.api.get('/time-clock/corrections');
      for (final r
          in (mine.ok ? mine.body['data'] as List? : null) ?? const []) {
        if (same(r)) row = Map<String, dynamic>.from(r as Map);
      }
    }
    if (!mounted || row == null) return;

    final person = (row['user'] as Map?)?['id']?.toString();
    final day = '${row['clocked_in'] ?? ''}'.split(' ').first;
    setState(() {
      _personId = person ?? _personId;
      _phoneOpenId = person;
    });
    await _load(on: day.isEmpty ? null : day);
  }

  Map<String, String>? get _onQuery => _on == null ? null : {'on': _on!};
  String get _onSuffix => _on == null ? '' : '?on=$_on';

  /// Send one correction and take the week it answers with.
  Future<void> _apply(BuildContext context, Future<ApiResponse> request) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final response = await request;
    if (!mounted) return;

    final data = response.body['data'];
    if (!response.ok || data is! Map<String, dynamic>) {
      messenger?.showSnackBar(SnackBar(content: Text(response.reason)));

      return;
    }

    setState(() => _week = _Week.from(data));
  }

  /// Somebody without `work_hours.manage` gets one item, on their own
  /// shifts only: ask for a correction that a manager approves.
  _Actions? get _actions {
    if (widget.canCorrect) return _manageActions;
    final me = widget.session?.me?.id;
    if (me == null) return null;

    return _Actions(
      manage: false,
      meId: me,
      shift: (context, shift, action) async {
        if (action != 'request') return;
        final body = await _editTimes(context, shift, request: true);
        if (body == null || !context.mounted) return;
        final messenger = ScaffoldMessenger.maybeOf(context);
        final response = await widget.api.post('/time-clock/corrections', {
          ...body,
          'shift_id': shift.id,
        });
        messenger?.showSnackBar(
          SnackBar(
            content: Text(
              response.ok
                  ? UiStrings.t('time_clock.request_sent')
                  : response.reason,
            ),
          ),
        );
      },
      day: (context, personId, date, _, action) async {
        if (action != 'request_day') return;
        final body = await _editTimes(
          context,
          _blankShift(personId, date),
          request: true,
          adding: true,
        );
        if (body == null || !context.mounted) return;
        final messenger = ScaffoldMessenger.maybeOf(context);
        // No shift_id: approving it adds the shift.
        final response = await widget.api.post('/time-clock/corrections', body);
        messenger?.showSnackBar(
          SnackBar(
            content: Text(
              response.ok
                  ? UiStrings.t('time_clock.request_sent')
                  : response.reason,
            ),
          ),
        );
      },
      schedule: (_, _) async {},
    );
  }

  /// A day with nothing clocked, as a shift to fill in: the person's own
  /// hours for that weekday when they have them, 9 to 5 when not.
  _Shift _blankShift(String personId, String date) {
    final person = _week?.people.where((p) => p.id == personId).firstOrNull;
    const names = [
      'monday',
      'tuesday',
      'wednesday',
      'thursday',
      'friday',
      'saturday',
      'sunday',
    ];
    final day = DateTime.tryParse(date);
    final hours = day == null ? null : person?.schedule[names[day.weekday - 1]];
    final start = hours is Map ? '${hours['start'] ?? '09:00'}' : '09:00';
    final end = hours is Map ? '${hours['end'] ?? '17:00'}' : '17:00';

    return _Shift(
      date: date,
      clockedIn: '',
      clockedOut: '',
      clockedInLocal: '$date $start',
      clockedOutLocal: '$date $end',
      durationSeconds: 0,
      open: false,
      outsideSchedule: false,
      endedBy: '',
      extensions: 0,
    );
  }

  Future<void> _decide(
    BuildContext context,
    Map<String, dynamic> request,
    String verb,
  ) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final id = request['id'];
    // Two literal paths, so ClientCallsRealRoutesTest can check each.
    final response = await widget.api.post(
      verb == 'approve'
          ? '/hr/time-clock/corrections/$id/approve'
          : '/hr/time-clock/corrections/$id/deny',
      {},
    );
    if (!mounted) return;
    if (!response.ok) {
      messenger?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
    // The week carries the waiting list, and an approval moves the totals.
    await _load(on: _on);
  }

  _Actions get _manageActions => _Actions(
    shift: (context, shift, action) async {
      final path = '/hr/time-clock/shifts/${shift.id}';
      switch (action) {
        case 'clock_out':
          await _apply(
            context,
            widget.api.post('$path/clock-out$_onSuffix', {}),
          );
        case 'edit':
          final body = await _editTimes(context, shift);
          if (body == null || !context.mounted) return;
          await _apply(context, widget.api.patch(path, body, _onQuery));
        case 'delete':
          if (!await _confirmDelete(context) || !context.mounted) return;
          await _apply(context, widget.api.delete(path, _onQuery));
      }
    },
    schedule: (context, person) async {
      final saved = await showWorkSchedulePanel(
        context,
        title: '${UiStrings.t('time_clock.work_schedule')} · ${person.name}',
        days: person.schedulePersonal ? person.schedule : null,
        formats: widget.session?.me?.display ?? DisplayFormats.fallback,
      );
      if (saved == null || !context.mounted) return;

      final messenger = ScaffoldMessenger.maybeOf(context);
      final response = await widget.api.patch(
        '/hr/people/${person.id}/schedule',
        {'days': saved},
      );
      if (!mounted) return;
      if (!response.ok) {
        messenger?.showSnackBar(SnackBar(content: Text(response.reason)));

        return;
      }
      // The schedule answer is the person, not the week, so fetch the
      // week again: the card and the totals both come from it.
      await _load(on: _on);
    },
    day: (context, personId, date, record, action) async {
      if (action == 'add') {
        final times = await _editTimes(
          context,
          _blankShift(personId, date),
          adding: true,
        );
        if (times == null || !context.mounted) return;
        final added = '/hr/time-clock/people/$personId/shifts';
        await _apply(context, widget.api.post('$added$_onSuffix', times));

        return;
      }
      final body = await switch (action) {
        'tag' => _chooseTag(context, record?.tag),
        'extra' => _chooseExtra(context, record?.extraMinutes ?? 0),
        'note' => _writeNote(context, record?.note),
        _ => Future<Map<String, dynamic>?>.value(),
      };
      if (body == null || !context.mounted) return;
      await _apply(
        context,
        widget.api.put(
          '/hr/time-clock/people/$personId/days/$date$_onSuffix',
          body,
        ),
      );
    },
  );

  Future<void> _load({String? on}) async {
    final request = ++_request;
    _on = on;

    setState(() {
      _loading = _week == null;
      _error = null;
      _off = null;
    });

    final response = await widget.api.get(
      on == null ? '/hr/time-clock/week' : '/hr/time-clock/week?on=$on',
    );

    // A slower earlier request must not overwrite a later answer: the week
    // arrows are two taps apart and the second one is the one wanted.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (response.statusCode == 409) {
        _off = response.message;

        return;
      }
      if (!response.ok) {
        _error = response.reason;

        return;
      }

      final data = response.body['data'] as Map<String, dynamic>?;
      _week = _Week.from(data);
      // Only present for `work_hours.manage` (TimeClockController::week).
      _requests = List<Map<String, dynamic>>.from(
        data?['correction_requests'] as List? ?? const [],
      );
      final people = _week?.people ?? const <_Person>[];
      if (_personId == null || !people.any((p) => p.id == _personId)) {
        _personId = _visible.firstOrNull?.id;
      }
    });
  }

  _Person? get _person {
    for (final p in _week?.people ?? const <_Person>[]) {
      if (p.id == _personId) return p;
    }

    return null;
  }

  /// Their latest clock-in this week, "Y-m-d H:i" (sorts as text); '' if none.
  static String _lastIn(_Person p) => p.shifts.fold(
    '',
    (last, s) => s.clockedInLocal.compareTo(last) > 0 ? s.clockedInLocal : last,
  );

  /// Who clocked in, latest first; then, when asked for, everyone else in
  /// the server's order.
  List<_Person> get _visible {
    final needle = _search.trim().toLowerCase();
    final matching = [
      for (final p in _week?.people ?? const <_Person>[])
        if (p.name.toLowerCase().contains(needle) &&
            (_roleId == null || p.roleId == _roleId))
          p,
    ];
    final clocked = [
      for (final p in matching)
        if (p.shifts.isNotEmpty) p,
    ]..sort((a, b) => _lastIn(b).compareTo(_lastIn(a)));
    if (!_everyone && needle.isEmpty) return clocked;

    return [
      ...clocked,
      for (final p in matching)
        if (p.shifts.isEmpty) p,
    ];
  }

  /// People on the list who clocked nothing this week.
  int get _idleCount => (_week?.people ?? const <_Person>[])
      .where(
        (p) => p.shifts.isEmpty && (_roleId == null || p.roleId == _roleId),
      )
      .length;

  /// The roles of the people in reach, by name: role id → name.
  Map<String, String> get _roles {
    final people = [...?_week?.people.where((p) => p.roleId != null)]
      ..sort((a, b) => a.roleName.compareTo(b.roleName));

    return {for (final p in people) p.roleId!: p.roleName};
  }

  @override
  Widget build(BuildContext context) {
    if (_off != null) return _switchedOff(context, _off!);

    final week = AsyncBody(
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
    if (_requests.isEmpty) return week;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Requests(
          requests: _requests,
          onDecide: (request, verb) => _decide(context, request, verb),
        ),
        Expanded(child: week),
      ],
    );
  }

  /// Drawn instead of the week, the way Paid Time Off draws "not set up":
  /// what is true, and where it is changed.
  Widget _switchedOff(BuildContext context, String sentence) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(sentence, style: theme.textTheme.titleMedium),
              const SizedBox(height: 12),
              Text(
                'It is switched on under Work Hours › Settings, by somebody '
                'who can change the organization’s settings. If that is not '
                'you, this is the whole answer rather than a step you are '
                'missing.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── desktop 1: the list beside one person ────────────────────────

  Widget _wide(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WeekBar(
          week: _week,
          onMove: (on) => _load(on: on),
        ),
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
                        actions: _actions,
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
    final open = _week?.people.where((p) => p.id == _phoneOpenId).firstOrNull;
    if (open != null) {
      // The person draws in the tab's own area with its own way back, never
      // as a pushed full-screen page (Jose's containment rule).
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                onPressed: () => setState(() => _phoneOpenId = null),
              ),
              Expanded(
                child: Text(
                  open.name,
                  style: Theme.of(context).textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          Expanded(
            child: _PersonWeek(
              api: widget.api,
              person: open,
              week: _week!,
              actions: _actions,
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WeekBar(
          week: _week,
          onMove: (on) => _load(on: on),
        ),
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
            key: const ValueKey('tour-work_hours-search'),
            decoration: InputDecoration(
              isDense: true,
              prefixIcon: const Icon(Icons.search, size: 18),
              hintText: UiStrings.t('time_clock.search_people'),
            ),
            onChanged: (value) => setState(() => _search = value),
          ),
        ),
        if (_roles.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 4),
            child: SearchPickerField<String?>(
              key: const ValueKey('tour-work_hours-role'),
              isDense: true,
              value: _roles.containsKey(_roleId) ? _roleId : null,
              options: [
                SearchOption(
                  value: null,
                  label: UiStrings.t('time_clock.all_roles'),
                ),
                for (final role in _roles.entries)
                  SearchOption(value: role.key, label: role.value),
              ],
              onChanged: (id) => setState(() => _roleId = id),
            ),
          ),
        if (_idleCount > 0)
          Align(
            alignment: Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 12, 4),
              child: TextButton(
                key: const ValueKey('tour-work_hours-everyone'),
                onPressed: () => setState(() => _everyone = !_everyone),
                child: Text(
                  _everyone
                      ? UiStrings.t('time_clock.only_clocked_in')
                      : UiStrings.t(
                          'time_clock.show_everyone',
                        ).replaceAll('{count}', '$_idleCount'),
                ),
              ),
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
                  key: const ValueKey('tour-work_hours-people'),
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

                      // In place, not pushed: the containment rule.
                      setState(() => _phoneOpenId = people[i].id);
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
      key: const ValueKey('tour-work_hours-week'),
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
            tooltip: UiStrings.t('time_clock.previous_week'),
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
            tooltip: UiStrings.t('time_clock.next_week'),
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
                        color: StateColour.of(context).on,
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
    this.actions,
  });

  final ApiClient api;
  final _Person person;
  final _Week week;

  /// Null without `work_hours.manage`: no ⋯ anywhere.
  final _Actions? actions;

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
        // Who works when (Jose, 2026-09-24): "right now only users can set
        // their schedule on their own, i need a panel to be able to set it
        // for them as well". The same card as Your account, with Edit for
        // work_hours.manage.
        WorkScheduleCard(
          // Empty is 'no schedule anywhere', which the card words itself;
          // seven Day off rows would claim a person who works no days.
          schedule: person.schedule.isEmpty ? null : {'days': person.schedule},
          canEdit: actions?.manage == true,
          onEdit: () => actions!.schedule(context, person),
          caption: person.schedulePersonal
              ? null
              : UiStrings.t('time_clock.using_business_hours'),
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
          // Newest first, and nothing that has not happened yet (Jose,
          // 2026-09-17: *"make it so it's descending / so sep 19 first / then
          // 18 / then 17 / also do not show the future days"*).
          //
          // Reversed and filtered HERE rather than in `week.days`, which stays
          // the seven ascending days the server sent: the week's own heading
          // is built from the first and last labels of that list, so turning
          // it round would silently reverse the heading and dropping from it
          // would take a label out of the range.
          for (final day in week.days.reversed)
            if (!day.inFuture)
              _DayCard(
                label: day.label,
                date: day.date,
                personId: person.id,
                shifts: days[day.date] ?? const [],
                record: person.records[day.date],
                actions: actions,
              ),
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
  const _DayCard({
    required this.label,
    required this.date,
    required this.personId,
    required this.shifts,
    this.record,
    this.actions,
  });

  /// The server's own words, in the organization's language and date format.
  final String label;
  final String date;
  final String personId;

  final List<_Shift> shifts;

  /// What HR said about the day: tag, extra minutes, note. Null when nothing.
  final _DayRecord? record;
  final _Actions? actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final extra = record?.extraMinutes ?? 0;
    var total = extra * 60;
    for (final s in shifts) {
      total += s.durationSeconds;
    }
    final tag = record?.tag;
    final note = record?.note;

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
                // One flex child, as on the shift row: a Flexible label beside
                // a Spacer put the day total mid-row.
                Expanded(
                  child: Row(
                    children: [
                      Flexible(
                        child: Text(label, style: theme.textTheme.labelLarge),
                      ),
                      if (tag != null) ...[
                        const SizedBox(width: 8),
                        _Chip(
                          text: UiStrings.t('time_clock.tag.$tag'),
                          colour: _tagColour(context, tag),
                        ),
                      ],
                    ],
                  ),
                ),
                Text(
                  formatWorked(total),
                  style: theme.textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                // Your own day, without manage: ask for its hours (a day you
                // worked and never clocked, 2026-10-01).
                if (actions != null &&
                    (actions!.manage || personId == actions!.meId))
                  _MenuButton(
                    tooltip: UiStrings.t('time_clock.day_actions'),
                    items: [
                      if (!actions!.manage)
                        (
                          'request_day',
                          UiStrings.t('time_clock.action.request_day'),
                          Icons.edit_calendar_outlined,
                          false,
                        ),
                      if (actions!.manage) ...[
                        (
                          'add',
                          UiStrings.t('time_clock.action.add_shift'),
                          Icons.add,
                          false,
                        ),
                        (
                          'tag',
                          UiStrings.t('time_clock.action.tag'),
                          Icons.sell_outlined,
                          false,
                        ),
                        (
                          'extra',
                          UiStrings.t('time_clock.action.extra'),
                          Icons.more_time,
                          false,
                        ),
                        (
                          'note',
                          UiStrings.t('time_clock.action.note'),
                          Icons.sticky_note_2_outlined,
                          false,
                        ),
                      ],
                    ],
                    onChosen: (action) =>
                        actions!.day(context, personId, date, record, action),
                  ),
              ],
            ),
          ),
          if (shifts.isEmpty && extra == 0)
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
          for (final shift in shifts)
            _ShiftRow(
              shift: shift,
              actions: actions,
              mine: personId == actions?.meId,
            ),
          if (extra > 0)
            Container(
              padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
              decoration: BoxDecoration(
                border: Border(top: BorderSide(color: scheme.outlineVariant)),
              ),
              child: Row(
                children: [
                  _Pip(colour: scheme.onSurfaceVariant),
                  const SizedBox(width: 6),
                  Text(
                    UiStrings.t('time_clock.extra_time'),
                    style: theme.textTheme.bodySmall,
                  ),
                  const Spacer(),
                  Text(
                    '+${formatWorked(extra * 60)}',
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
          if (note != null)
            Container(
              padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
              decoration: BoxDecoration(
                border: Border(top: BorderSide(color: scheme.outlineVariant)),
              ),
              child: Text(
                note,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

Color _tagColour(BuildContext context, String tag) {
  final scheme = Theme.of(context).colorScheme;

  return switch (tag) {
    'office' => scheme.primary,
    'home' => scheme.tertiary,
    'sick' => scheme.error,
    'holiday' || 'vacation' => StateColour.of(context).caution,
    _ => scheme.onSurfaceVariant,
  };
}

class _ShiftRow extends StatelessWidget {
  const _ShiftRow({required this.shift, this.actions, this.mine = false});

  /// The signed-in person's own shift, which they may ask to have corrected.
  final bool mine;

  final _Shift shift;
  final _Actions? actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      // The trailing button carries its own padding, so the row gives up its
      // right inset for it: at 390 the 12 was exactly the 4px that overflowed.
      padding: EdgeInsets.fromLTRB(12, 9, _hasMenu ? 0 : 12, 9),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          // The left group is ONE flex child. With the Outside chip's
          // Flexible beside a Spacer, the two split the free space and the
          // total landed mid-row (Jose, 2026-10-01).
          Expanded(
            child: Row(
              children: [
                // Green in, red out — his words. The times are the server's own
                // strings; nothing here formats a clock.
                _Pip(colour: StateColour.of(context).on),
                const SizedBox(width: 6),
                Text(shift.clockedIn, style: theme.textTheme.bodySmall),
                const SizedBox(width: 12),
                if (shift.open)
                  Text(
                    UiStrings.t('time_clock.still_in'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: StateColour.of(context).on,
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
                // Who set the end, when it was not the person: a correction rewrote
                // it, or somebody in HR pressed Clock out now.
                // Jose, 2026-10-01: "Shift corrected" for what was asked
                // for, "Edited" only for HR's changes nobody asked for;
                // nothing on your own edits (the server leaves it null).
                if (shift.edited != null || shift.endedBy == 'manager') ...[
                  const SizedBox(width: 8),
                  _Chip(
                    text: UiStrings.t(switch (shift.edited) {
                      'request' => 'time_clock.corrected',
                      'hr' => 'time_clock.edited',
                      _ => 'time_clock.ended_by_hr',
                    }),
                    colour: StateColour.of(context).caution,
                  ),
                ],
                if (shift.outsideSchedule) ...[
                  const SizedBox(width: 6),
                  Flexible(
                    child: _Chip(
                      text: UiStrings.t('time_clock.outside'),
                      colour: StateColour.of(context).caution,
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
              ],
            ),
          ),
          Text(
            formatWorked(shift.durationSeconds),
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          if (_hasMenu)
            _MenuButton(
              tooltip: UiStrings.t('time_clock.shift_actions'),
              items: actions!.manage
                  ? _manageItems
                  : [
                      (
                        'request',
                        UiStrings.t('time_clock.action.request'),
                        Icons.edit_calendar_outlined,
                        false,
                      ),
                    ],
              onChosen: (action) => actions!.shift(context, shift, action),
            ),
        ],
      ),
    );
  }

  /// Your own running shift too (Jose, 2026-10-01): its clock-in can be
  /// wrong while you are still on the clock.
  bool get _hasMenu => actions != null && (actions!.manage || mine);

  List<(String, String, IconData, bool)> get _manageItems => [
    if (shift.open)
      (
        'clock_out',
        UiStrings.t('time_clock.action.clock_out_now'),
        Icons.stop_circle_outlined,
        true,
      ),
    ('edit', UiStrings.t('time_clock.action.edit'), Icons.edit_outlined, false),
    (
      'delete',
      UiStrings.t('time_clock.action.delete'),
      Icons.delete_outline,
      true,
    ),
  ];
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
      label: label.isEmpty ? UiStrings.t('time_clock.this_week') : label,
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
    this.records = const {},
    this.schedule = const {},
    this.schedulePersonal = false,
    this.roleId,
    this.roleName = '',
  });

  /// The schedule the clock runs on for them, weekday → {start, end}. Their
  /// own, or the place's business hours when [schedulePersonal] is false.
  final Map<String, dynamic> schedule;
  final bool schedulePersonal;

  /// Their role, for the list's role filter.
  final String? roleId;
  final String roleName;

  final String id;
  final String name;
  final bool hasAvatar;
  final int totalSeconds;
  final int daysWorked;
  final int averageSeconds;

  /// On the clock right now.
  final bool open;

  final List<_Shift> shifts;

  /// What HR recorded per day, by date. Only days somebody said something
  /// about are here.
  final Map<String, _DayRecord> records;

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
    schedule: json['schedule'] is Map
        ? Map<String, dynamic>.from(json['schedule'] as Map)
        : const {},
    schedulePersonal: json['schedule_is_personal'] == true,
    roleId: json['role'] is Map ? '${(json['role'] as Map)['id']}' : null,
    roleName: json['role'] is Map
        ? '${(json['role'] as Map)['name'] ?? ''}'
        : '',
    records: {
      for (final row in (json['days'] as List?) ?? const [])
        if (row is Map<String, dynamic>) '${row['date']}': _DayRecord.from(row),
    },
  );
}

/// Tag, extra minutes and note on one day (`time_clock_days`).
class _DayRecord {
  const _DayRecord({this.tag, this.extraMinutes = 0, this.note});

  final String? tag;
  final int extraMinutes;
  final String? note;

  static _DayRecord from(Map<String, dynamic> json) => _DayRecord(
    tag: json['tag'] as String?,
    extraMinutes: (json['extra_minutes'] as num?)?.toInt() ?? 0,
    note: json['note'] as String?,
  );
}

class _Shift {
  const _Shift({
    this.id = '',
    this.clockedInLocal = '',
    this.clockedOutLocal,
    required this.date,
    required this.clockedIn,
    required this.clockedOut,
    required this.durationSeconds,
    required this.open,
    required this.outsideSchedule,
    required this.endedBy,
    required this.extensions,
    this.edited,
  });

  final String id;

  /// `hr`: HR changed it unasked ("Edited"). `request`: an approved
  /// correction ("Shift corrected"). Null: neither, or your own edit.
  final String? edited;

  /// "Y-m-d H:i" in the organization's zone, for the edit pickers. Sent back
  /// in the same shape, and read in the same zone, so no timezone is touched
  /// on this side.
  final String clockedInLocal;
  final String? clockedOutLocal;

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
    id: '${json['id'] ?? ''}',
    clockedInLocal: '${json['clocked_in_local'] ?? ''}',
    clockedOutLocal: json['clocked_out_local'] as String?,
    date: '${json['date'] ?? ''}',
    clockedIn: '${json['clocked_in'] ?? ''}',
    clockedOut: '${json['clocked_out'] ?? ''}',
    durationSeconds: (json['duration_seconds'] as num?)?.toInt() ?? 0,
    open: json['open'] as bool? ?? false,
    outsideSchedule: json['outside_schedule'] as bool? ?? false,
    endedBy: '${json['ended_by'] ?? ''}',
    extensions: (json['extensions'] as num?)?.toInt() ?? 0,
    edited: json['edited'] as String?,
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
  const _Day({
    required this.date,
    required this.weekday,
    required this.label,
    required this.inFuture,
  });

  final String date;

  /// Whether this day has not arrived yet, **in the view's timezone**.
  ///
  /// Given by the server (`2f34d5a8`) and not worked out here, because the
  /// only today this side has is the DEVICE's. That is the same confusion
  /// that had every reminder and auto clock-out running an hour late last
  /// week: a person in one timezone reading an organization set to another
  /// would hide a day that had already started for the people on it, or show
  /// one that had not.
  ///
  /// Today is never in the future.
  final bool inFuture;

  /// The English key, untranslated on purpose, for anything that branches on
  /// which day it is. Nobody reads this one.
  final String weekday;

  /// The only part a person reads.
  final String label;

  static _Day from(Map<String, dynamic> json) => _Day(
    date: '${json['date'] ?? ''}',
    weekday: '${json['weekday'] ?? ''}',
    label: '${json['label'] ?? ''}',
    // Absent reads as "has happened", so a server older than this build shows
    // the whole week rather than an empty one. A missing day is the louder
    // failure of the two.
    inFuture: json['in_future'] == true,
  );
}

// ── the correction menus and their dialogs ─────────────────────────

class _Actions {
  const _Actions({
    required this.shift,
    required this.day,
    required this.schedule,
    this.manage = true,
    this.meId,
  });

  /// `work_hours.manage`: every menu. Off: only "Request a correction" on
  /// [meId]'s own finished shifts.
  final bool manage;
  final String? meId;

  final Future<void> Function(BuildContext context, _Person person) schedule;

  final Future<void> Function(BuildContext context, _Shift shift, String action)
  shift;
  final Future<void> Function(
    BuildContext context,
    String personId,
    String date,
    _DayRecord? record,
    String action,
  )
  day;
}

/// The ⋯. A menu beside it on a desk, a bottom sheet on a phone — the same
/// items either way (layout 1 and 1).
class _MenuButton extends StatelessWidget {
  const _MenuButton({
    required this.tooltip,
    required this.items,
    required this.onChosen,
  });

  final String tooltip;

  /// (key, label, icon, destructive)
  final List<(String, String, IconData, bool)> items;
  final void Function(String) onChosen;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      icon: const Icon(Icons.more_horiz),
      iconSize: 18,
      visualDensity: VisualDensity.compact,
      onPressed: () async {
        final chosen = await (MediaQuery.sizeOf(context).width < 600
            ? _sheet(context)
            : _menu(context));
        if (chosen != null) onChosen(chosen);
      },
    );
  }

  Color? _colour(BuildContext context, bool destructive) =>
      destructive ? Theme.of(context).colorScheme.error : null;

  Future<String?> _menu(BuildContext context) {
    final box = context.findRenderObject()! as RenderBox;
    final overlay =
        Overlay.of(context).context.findRenderObject()! as RenderBox;
    final at = box.localToGlobal(Offset(0, box.size.height), ancestor: overlay);

    return showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(
        at.dx,
        at.dy,
        overlay.size.width - at.dx - box.size.width,
        0,
      ),
      items: [
        for (final (key, label, icon, destructive) in items)
          PopupMenuItem<String>(
            value: key,
            child: Row(
              children: [
                Icon(icon, size: 18, color: _colour(context, destructive)),
                const SizedBox(width: 10),
                // Flexible: a menu is at most 280 wide, and a longer label
                // (or its Spanish) would otherwise overflow it.
                Flexible(
                  child: Text(
                    label,
                    style: TextStyle(color: _colour(context, destructive)),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Future<String?> _sheet(BuildContext context) => showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final (key, label, icon, destructive) in items)
            ListTile(
              leading: Icon(icon, color: _colour(context, destructive)),
              title: Text(
                label,
                style: TextStyle(color: _colour(context, destructive)),
              ),
              onTap: () => Navigator.of(context).pop(key),
            ),
          const SizedBox(height: 8),
        ],
      ),
    ),
  );
}

Future<bool> _confirmDelete(BuildContext context) async {
  final labels = MaterialLocalizations.of(context);
  final yes = await showSidePanel<bool>(
    context,
    builder: (context) => SidePanel(
      title: UiStrings.t('time_clock.action.delete'),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(labels.cancelButtonLabel),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: Theme.of(context).colorScheme.error,
          ),
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(UiStrings.t('time_clock.action.delete')),
        ),
      ],
      child: Text(UiStrings.t('time_clock.delete_confirm')),
    ),
  );

  return yes ?? false;
}

/// "2026-09-23 17:20" — wall clock in the organization's zone, which is what
/// the server sends and what it reads back. Never converted here.
DateTime? _parseLocal(String? value) {
  if (value == null || value.isEmpty) return null;

  return DateTime.tryParse(value.replaceFirst(' ', 'T'));
}

String _formatLocal(DateTime at) =>
    '${at.year.toString().padLeft(4, '0')}-'
    '${at.month.toString().padLeft(2, '0')}-'
    '${at.day.toString().padLeft(2, '0')} '
    '${at.hour.toString().padLeft(2, '0')}:'
    '${at.minute.toString().padLeft(2, '0')}';

/// The edit dialog. Returns the PATCH body, or null when cancelled.
Future<Map<String, dynamic>?> _editTimes(
  BuildContext context,
  _Shift shift, {
  bool request = false,
  bool adding = false,
}) {
  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) =>
        _EditTimesDialog(shift: shift, request: request, adding: adding),
  );
}

class _EditTimesDialog extends StatefulWidget {
  const _EditTimesDialog({
    required this.shift,
    this.request = false,
    this.adding = false,
  });

  final _Shift shift;

  /// A shift that does not exist yet (Add shift, or asking for a day): both
  /// times are required, because an added shift is always a finished one.
  final bool adding;

  /// Asking a manager rather than editing: adds "What happened?", which the
  /// server requires, and sends for approval instead of saving.
  final bool request;

  @override
  State<_EditTimesDialog> createState() => _EditTimesDialogState();
}

class _EditTimesDialogState extends State<_EditTimesDialog> {
  late DateTime? _in = _parseLocal(widget.shift.clockedInLocal);
  late DateTime? _out = _parseLocal(widget.shift.clockedOutLocal);
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<DateTime?> _pick(DateTime? current) async {
    // Never the device clock: this screen reads only the server's (a guard in
    // the tests says so). Every shift has a start, so there is always one.
    final start = current ?? _in;
    if (start == null) return null;
    final day = await showDatePicker(
      context: context,
      initialDate: start,
      firstDate: start.subtract(const Duration(days: 60)),
      lastDate: start.add(const Duration(days: 2)),
    );
    if (day == null || !mounted) return null;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(start),
    );
    if (time == null) return null;

    return DateTime(day.year, day.month, day.day, time.hour, time.minute);
  }

  Widget _row(String label, DateTime? at, VoidCallback onTap) {
    final labels = MaterialLocalizations.of(context);

    return Row(
      children: [
        SizedBox(width: 96, child: Text(label)),
        Expanded(
          child: OutlinedButton(
            onPressed: onTap,
            child: Text(
              at == null
                  ? '—'
                  : '${labels.formatMediumDate(at)}  '
                        '${labels.formatTimeOfDay(TimeOfDay.fromDateTime(at))}',
            ),
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final labels = MaterialLocalizations.of(context);
    final valid =
        _in != null &&
        (!widget.adding || _out != null) &&
        (_out == null || _out!.isAfter(_in!)) &&
        (!widget.request || _reason.text.trim().isNotEmpty);

    return SidePanel(
      title: UiStrings.t(switch ((widget.adding, widget.request)) {
        (true, true) => 'time_clock.action.request_day',
        (true, false) => 'time_clock.action.add_shift',
        (false, true) => 'time_clock.action.request',
        (false, false) => 'time_clock.action.edit',
      }),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(labels.cancelButtonLabel),
        ),
        FilledButton(
          onPressed: !valid
              ? null
              : () => Navigator.of(context).pop(<String, dynamic>{
                  'clocked_in_at': _formatLocal(_in!),
                  // Absent keeps an open shift open; the server refuses a
                  // finished one being reopened anyway.
                  if (_out != null) 'clocked_out_at': _formatLocal(_out!),
                  if (widget.request) 'reason': _reason.text.trim(),
                }),
          child: Text(
            widget.request
                ? UiStrings.t('time_clock.request_send')
                : labels.saveButtonLabel,
          ),
        ),
      ],
      child: SizedBox(
        width: 380,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _row(UiStrings.t('time_clock.clock_in'), _in, () async {
              final at = await _pick(_in);
              if (at != null) setState(() => _in = at);
            }),
            // Asking about the shift you are in: only its start. Its end is
            // your Clock Out, still to come.
            if (!(widget.request && widget.shift.open)) ...[
              const SizedBox(height: 10),
              _row(UiStrings.t('time_clock.clock_out'), _out, () async {
                final at = await _pick(_out);
                if (at != null) setState(() => _out = at);
              }),
            ],
            if (widget.request) ...[
              const SizedBox(height: 12),
              TextField(
                key: const Key('correction-reason'),
                controller: _reason,
                maxLength: 1000,
                minLines: 2,
                maxLines: 4,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  labelText: UiStrings.t('time_clock.request_reason'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

const _tags = ['office', 'home', 'absent', 'sick', 'holiday', 'vacation'];

/// Returns `{'tag': …}` (null clears), or null when cancelled.
Future<Map<String, dynamic>?> _chooseTag(
  BuildContext context,
  String? current,
) {
  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) => SidePanel(
      title: UiStrings.t('time_clock.action.tag'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final tag in [null, ..._tags])
            InkWell(
              onTap: () =>
                  Navigator.of(context).pop(<String, dynamic>{'tag': tag}),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Row(
                  children: [
                    Icon(
                      tag == current
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      size: 18,
                    ),
                    const SizedBox(width: 10),
                    Text(
                      tag == null
                          ? UiStrings.t('time_clock.no_tag')
                          : UiStrings.t('time_clock.tag.$tag'),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    ),
  );
}

/// Returns `{'extra_minutes': n}`, or null when cancelled.
Future<Map<String, dynamic>?> _chooseExtra(BuildContext context, int current) {
  final hours = TextEditingController(text: '${current ~/ 60}');
  final minutes = TextEditingController(text: '${current % 60}');
  final labels = MaterialLocalizations.of(context);

  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) => SidePanel(
      title: UiStrings.t('time_clock.action.extra'),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(labels.cancelButtonLabel),
        ),
        FilledButton(
          onPressed: () {
            final total =
                (int.tryParse(hours.text.trim()) ?? 0) * 60 +
                (int.tryParse(minutes.text.trim()) ?? 0);
            // The server refuses outside 0..1440 with its own sentence.
            Navigator.of(
              context,
            ).pop(<String, dynamic>{'extra_minutes': total});
          },
          child: Text(labels.saveButtonLabel),
        ),
      ],
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: hours,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: UiStrings.t('time_clock.hours'),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: TextField(
              controller: minutes,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: UiStrings.t('time_clock.minutes'),
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

/// Returns `{'note': …}` (empty clears), or null when cancelled.
Future<Map<String, dynamic>?> _writeNote(
  BuildContext context,
  String? current,
) {
  final text = TextEditingController(text: current ?? '');
  final labels = MaterialLocalizations.of(context);

  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) => SidePanel(
      title: UiStrings.t('time_clock.action.note'),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(labels.cancelButtonLabel),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(<String, dynamic>{
            'note': text.text.trim().isEmpty ? null : text.text.trim(),
          }),
          child: Text(labels.saveButtonLabel),
        ),
      ],
      child: SizedBox(
        width: 380,
        child: TextField(
          controller: text,
          maxLines: 4,
          maxLength: 2000,
          autofocus: true,
        ),
      ),
    ),
  );
}

/// What people asked to have corrected, above the week, for whoever may
/// approve it. Each row: who, what the shift said, what they ask for, why.
class _Requests extends StatelessWidget {
  const _Requests({required this.requests, required this.onDecide});

  final List<Map<String, dynamic>> requests;
  final void Function(Map<String, dynamic> request, String verb) onDecide;

  static String _span(Object? inAt, Object? outAt) =>
      '${inAt ?? ''} – ${outAt ?? '…'}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      key: const Key('correction-requests'),
      margin: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${UiStrings.t('time_clock.requests_title')} (${requests.length})',
              style: theme.textTheme.titleSmall,
            ),
            for (final r in requests) ...[
              const Divider(height: 16),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          (r['user'] as Map?)?['name']?.toString() ?? '',
                          style: theme.textTheme.labelLarge,
                        ),
                        Text(
                          '${UiStrings.t('time_clock.request_was')}: '
                          '${r['shift_id'] == null ? UiStrings.t('time_clock.missing_day') : _span(r['was_in'], r['was_out'])}',
                          style: theme.textTheme.bodySmall,
                        ),
                        Text(
                          '${UiStrings.t('time_clock.request_asks')}: '
                          '${_span(r['clocked_in'], r['clocked_out'])}',
                          style: theme.textTheme.bodySmall?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        Text(
                          r['reason']?.toString() ?? '',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Wrap(
                    spacing: 8,
                    children: [
                      OutlinedButton(
                        onPressed: () => onDecide(r, 'deny'),
                        child: Text(UiStrings.t('time_clock.decline')),
                      ),
                      FilledButton(
                        onPressed: () => onDecide(r, 'approve'),
                        child: Text(UiStrings.t('time_clock.approve')),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
