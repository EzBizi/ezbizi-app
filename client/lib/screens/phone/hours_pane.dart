import 'package:flutter/material.dart';

import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

import 'phone_search.dart';

/// Phone › Settings › Hours — when a line is open, in its own wall clock.
///
/// ── Why the timezone is on the schedule ──────────────────────────
///
/// A schedule is read by `HoursClock` against local wall time, not UTC. "We
/// close at five" means five where the phone is, and a group with a line in
/// Connecticut and another in Georgia closes at two different instants. Putting
/// the zone on the schedule rather than on the organization is what lets both
/// be true at once.
///
/// ── Why an empty day is closed, and says so ──────────────────────
///
/// `HoursClock` treats a day with no intervals as closed. That is the right
/// default but a silent one, so this screen never shows a blank row: a day is
/// either a pair of times or the word Closed. A grid you have to interpret is
/// how somebody ends up with a line that never answers on Saturdays without
/// knowing they said so.
class HoursPane extends StatefulWidget {
  const HoursPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<HoursPane> createState() => _HoursPaneState();
}

class _HoursPaneState extends State<HoursPane> {
  /// Sunday first, matching `HoursClock.DAY_KEYS` — the server indexes this by
  /// `date('w')`, so the order here is not a display choice.
  static const _days = <(String, String)>[
    ('sun', 'Sunday'),
    ('mon', 'Monday'),
    ('tue', 'Tuesday'),
    ('wed', 'Wednesday'),
    ('thu', 'Thursday'),
    ('fri', 'Friday'),
    ('sat', 'Saturday'),
  ];

  bool _loading = true;
  String? _error;

  List<_Schedule> _schedules = const [];

  /// What is typed in the search box. /phone/hours returns every schedule, so
  /// the filter runs here.
  String _query = '';
  List<_Conn> _connections = const [];

  _Draft? _draft;
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'set opening hours');

  Future<void> _load() async {
    setState(() {
      _loading = _schedules.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/hours'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    final failed = results.firstWhere((r) => !r.ok, orElse: () => results.first);

    if (!failed.ok) {
      setState(() {
        _loading = false;
        _error = failed.reason;
      });
      return;
    }

    setState(() {
      _schedules = _Schedule.listFrom(results[0].body);
      _connections = _Conn.listFrom(results[1].body);
      _loading = false;
    });
  }

  Future<void> _save() async {
    final draft = _draft!;

    if (draft.name.trim().isEmpty) {
      setState(() => _formError = 'Give these hours a name.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final body = <String, dynamic>{
      'name': draft.name.trim(),
      'timezone': draft.timezone,
      'weeks': draft.weeksPayload,
      'exceptions': draft.exceptionsPayload,
    };

    final response = draft.id == null
        ? await widget.api.post('/phone/hours', {
            ...body,
            'connection_id': draft.connectionId,
          })
        : await widget.api.patch('/phone/hours/${draft.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _draft = null;
    });

    await _load();
  }

  Future<void> _delete(_Schedule schedule) async {
    final response = await widget.api.delete('/phone/hours/${schedule.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return ErrorRetry(_error!, onRetry: _load);
    }

    if (_connections.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Opening hours belong to a phone account. Once phone service is '
          'switched on for a company, its hours are set here.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    if (_draft != null) return _form(theme);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Hours', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'When a line is open. A line with no hours is always open.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),

        Row(
          children: [
            Refused(
              reason: _canEdit ? null : _editRefusal,
              child: FilledButton(
                onPressed: _canEdit
                    ? () => setState(() {
                        _draft = _Draft.blank(_connections.first.id);
                        _formError = null;
                      })
                    : null,
                child: const Text('Add hours'),
              ),
            ),
            const SizedBox(width: 12),
            if (_schedules.isNotEmpty)
              Expanded(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: phoneSearchBox(
                    key: const ValueKey('comm-hours-search'),
                    hint: 'Search name or timezone',
                    onChanged: (value) => setState(() => _query = value),
                  ),
                ),
              ),
          ],
        ),

        const SizedBox(height: 20),

        if (_schedules.isEmpty)
          Text(
            'No hours set yet. Every line answers around the clock until you '
            'add some.',
            style: theme.textTheme.bodySmall,
          )
        else
          _table(theme),
      ],
    );
  }

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  /// Name, timezone, open today, and the next closed day (layout A, Mia,
  /// 2026-10-02). "Today" is the schedule's own date, which the server sends:
  /// the reader's date is a day off for part of every evening on a coast.
  Widget _table(ThemeData theme) {
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final shown = [
      for (final schedule in _schedules)
        if (phoneSearchHit(_query, [
          schedule.name,
          schedule.timezone,
          _zoneLabel(schedule.timezone),
        ]))
          schedule,
    ];

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Nothing matches "${_query.trim()}".',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Hours', flex: 3),
        ListColumn('Timezone', flex: 3),
        ListColumn('Today', flex: 3),
        ListColumn('Closed next', flex: 2),
        ListColumn('', flex: 3, end: true),
      ],
      rowKey: (i) => ValueKey('hours:${shown[i].id}'),
      rows: [
        for (final schedule in shown)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  schedule.name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text(_summarise(schedule), style: small),
              ],
            ),
            Text(
              _zoneLabel(schedule.timezone),
              overflow: TextOverflow.ellipsis,
            ),
            _today(schedule),
            Text(_nextClosed(schedule), style: small),
            _canEdit
                ? Wrap(
                    alignment: WrapAlignment.end,
                    children: [
                      TextButton(
                        onPressed: () => setState(() {
                          _draft = _Draft.of(schedule);
                          _formError = null;
                        }),
                        child: const Text('Edit'),
                      ),
                      TextButton(
                        onPressed: () => _delete(schedule),
                        child: const Text('Remove'),
                      ),
                    ],
                  )
                : const SizedBox.shrink(),
          ],
      ],
    );
  }

  /// "Open 9:00 AM – 5:00 PM", or Closed when the day has no hours or is one
  /// of the closed dates — the same two checks HoursClock makes on a call. A
  /// dash when the server did not say what today is there.
  Widget _today(_Schedule schedule) {
    final today = DateTime.tryParse(schedule.today ?? '');
    if (today == null) return const Text('—');

    if (schedule.closedDates.contains(schedule.today)) {
      return const StatusPill('Closed today');
    }

    // DateTime.weekday is 1 (Monday) to 7 (Sunday); _days starts on Sunday.
    final intervals = schedule.weeks[_days[today.weekday % 7].$1] ?? const [];
    if (intervals.isEmpty) return const StatusPill('Closed');

    final hours = intervals
        .map(
          (i) =>
              '${_formats.formatWallClock(i.$1)} – '
              '${_formats.formatWallClock(i.$2)}',
        )
        .join(', ');

    return StatusPill('Open $hours', tone: Tone.ok);
  }

  /// The first closed date from today on, or a dash.
  String _nextClosed(_Schedule schedule) {
    final today = schedule.today;
    final next = schedule.closedDates
        .where((d) => today == null || d.compareTo(today) >= 0)
        .firstOrNull;
    final at = next == null ? null : DateTime.tryParse(next);

    return at == null ? '—' : _formats.formatDate(at);
  }

  /// "Open Mon–Fri" rather than a grid nobody reads in a list row.
  String _summarise(_Schedule schedule) {
    final open = _days
        .where((d) => (schedule.weeks[d.$1] ?? const []).isNotEmpty)
        .map((d) => d.$2.substring(0, 3))
        .toList();

    if (open.isEmpty) return 'Closed every day';
    if (open.length == 7) return 'Open every day';

    return 'Open ${open.join(', ')}';
  }

  Widget _form(ThemeData theme) {
    final draft = _draft!;

    return ListView(
      // Keyed so a test can scroll THIS list. Every TextFormField carries its
      // own Scrollable, so "the last scrollable on screen" is a text field.
      key: const ValueKey('hours-form'),
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back to the list',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => setState(() {
                _draft = null;
                _formError = null;
              }),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(
                draft.id == null ? 'New hours' : 'Edit hours',
                style: theme.textTheme.titleMedium,
              ),
            ),
          ],
        ),

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 16),
        TextFormField(
          initialValue: draft.name,
          decoration: const InputDecoration(
            labelText: 'What these hours are called',
            hintText: 'Office hours',
            border: OutlineInputBorder(),
          ),
          onChanged: (v) => draft.name = v,
        ),

        const SizedBox(height: 16),
        SearchPickerField<String>(
          value: draft.timezone,
          label: 'Clock these hours run on',
          border: OutlineInputBorder(),
          options: [
            for (final zone in zonesFor(draft.timezone))
              SearchOption(value: zone, label: _zoneLabel(zone)),
          ],
          onChanged: (v) => setState(() => draft.timezone = v ?? 'UTC'),
        ),

        const SizedBox(height: 20),
        Text('The week', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'Times are 24-hour. A day with no times is closed, and says so.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),

        for (final (key, label) in _days) _dayRow(draft, key, label, theme),

        const SizedBox(height: 20),
        Text('Days it is closed anyway', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'Holidays and one-offs. These beat the week above.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),

        for (final date in draft.closedDates)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Row(
              children: [
                Expanded(child: Text(date)),
                TextButton(
                  onPressed: () => setState(() => draft.closedDates.remove(date)),
                  child: const Text('Remove'),
                ),
              ],
            ),
          ),

        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            icon: const Icon(Icons.add),
            label: const Text('Add a closed day'),
            onPressed: () async {
              final now = DateTime.now();

              // A date picker, not a modal dialog of our own — the platform
              // control is what people already know, and §6's rule is about
              // EzBizi inventing pop-ups, not about native pickers.
              final picked = await showDatePicker(
                context: context,
                firstDate: DateTime(now.year - 1),
                lastDate: DateTime(now.year + 3),
                initialDate: now,
              );

              if (picked == null || !mounted) return;

              final iso = '${picked.year.toString().padLeft(4, '0')}-'
                  '${picked.month.toString().padLeft(2, '0')}-'
                  '${picked.day.toString().padLeft(2, '0')}';

              setState(() {
                if (!draft.closedDates.contains(iso)) draft.closedDates.add(iso);
                draft.closedDates.sort();
              });
            },
          ),
        ),

        const SizedBox(height: 24),
        Row(
          children: [
            FilledButton(
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 12),
            TextButton(
              onPressed: () => setState(() {
                _draft = null;
                _formError = null;
              }),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  /// Both states sit in a box at least this tall, so the week does not step.
  ///
  /// ── Why a number, and why a MINIMUM ──────────────────────────
  ///
  /// An open day draws two dense outlined fields and measured 56; a closed day
  /// draws one line of bodySmall and measured 48. Seven rows alternating
  /// between two heights with nothing ruled between them is what reads as
  /// floating — Jose's word for the same shape in Web Management's hours, and
  /// the generalisation UI/UX recorded from it: a row with a per-row CONTROL
  /// and a text-only ALTERNATE STATE has two heights.
  ///
  /// A minimum rather than a fixed height, because the Web Management fix
  /// reached for fixed first and clipped — content ran 78px past the edge at
  /// 375. A floor lets a row grow when it must and holds the list even when it
  /// need not.
  ///
  /// The number is pinned by phone_hours_pane_test, which compares an open row
  /// to a closed one at two widths. If Material ever changes a dense field's
  /// metrics, that test fails rather than the list quietly stepping again —
  /// which is the only reason a hard-coded 56 is honest here.
  static const double _dayRowMinHeight = 56;

  Widget _dayRow(_Draft draft, String key, String label, ThemeData theme) {
    final day = draft.week[key]!;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: _dayRowMinHeight),
        child: Row(
        children: [
          SizedBox(
            width: 108,
            child: Text(label, style: theme.textTheme.bodyMedium),
          ),
          Switch(
            key: ValueKey('day:$key'),
            value: day.open,
            onChanged: (on) => setState(() => day.open = on),
          ),
          const SizedBox(width: 8),
          if (!day.open)
            Expanded(child: Text('Closed', style: theme.textTheme.bodySmall))
          else ...[
            Expanded(
              child: TextFormField(
                key: ValueKey('from:$key'),
                initialValue: day.from,
                keyboardType: TextInputType.datetime,
                inputFormatters: _timeInput,
                decoration: const InputDecoration(
                  labelText: 'From',
                  hintText: '09:00',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                onChanged: (v) => day.from = v,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextFormField(
                key: ValueKey('to:$key'),
                initialValue: day.to,
                keyboardType: TextInputType.datetime,
                inputFormatters: _timeInput,
                decoration: const InputDecoration(
                  labelText: 'To',
                  hintText: '17:00',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                onChanged: (v) => day.to = v,
              ),
            ),
          ],
        ],
        ),
      ),
    );
  }
}

/// Keeps a time field to something that IS a time.
///
/// These were plain text boxes. "08:00 sd asdasd" typed straight in, saved,
/// and HoursClock then compared it as a string — a schedule that looks set and
/// answers wrongly on every call. The server refuses it now; this stops it
/// being typed at all, which is the half a person actually experiences.
///
/// A mask rather than a picker: the house rule is no modals, and Flutter's
/// time picker is a dialog.
final List<TextInputFormatter> _timeInput = <TextInputFormatter>[
  FilteringTextInputFormatter.allow(RegExp(r'[0-9:]')),
  LengthLimitingTextInputFormatter(5),

  // Insert the colon as they type, so 0900 becomes 09:00 without anybody
  // having to know the format is the thing being enforced.
  TextInputFormatter.withFunction((old, updated) {
    final digits = updated.text.replaceAll(':', '');
    if (digits.length < 3) return updated;

    final head = digits.substring(0, 2);
    final tail = digits.substring(2, digits.length.clamp(0, 4));
    final text = '$head:$tail';

    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }),
];

/// Whole-hour UTC offsets, stored as zone names PHP accepts.
///
/// Same convention as SettingRegistry: people pick UTC-5, not a city, and the
/// wire value is `UTC` or `Etc/GMT…`. POSIX signs are inverted there —
/// `Etc/GMT+5` IS UTC-5 — which is exactly the trap this list exists to hide.
/// The clocks a business keeps hours on — real places, not offsets.
///
/// ── Why fixed offsets were wrong, not just narrow ────────────────
///
/// This was `UTC` and twenty-seven `Etc/GMT±N` entries. Two things followed.
///
/// A fixed offset does not observe daylight saving. A company open 09:00–17:00
/// in New York is open 09:00–17:00 all year, but stored as `Etc/GMT+5` those
/// hours answer an hour early for the half of the year New York spends on EDT
/// — so the line reports CLOSED at 08:30 in January and OPEN at 08:30 in July,
/// and nothing on the screen suggests the clock moved.
///
/// And the server accepts any IANA identifier
/// (`DateTimeZone::listIdentifiers(ALL_WITH_BC)`), while every franchise row
/// carries a real one — `America/New_York` on all eight today. Creating hours
/// from a franchise-scoped number defaults the schedule to the franchise's
/// zone, which was then not in this list at all: opening that schedule threw
/// a DropdownButtonFormField assertion and the editor rendered nothing.
///
/// So the list is places, and [zonesFor] guarantees whatever a schedule
/// already holds is among them.
const Map<String, String> _zoneNames = <String, String>{
  'America/New_York': 'Eastern — New York',
  'America/Detroit': 'Eastern — Detroit',
  'America/Chicago': 'Central — Chicago',
  'America/Denver': 'Mountain — Denver',
  'America/Phoenix': 'Arizona (no daylight saving)',
  'America/Los_Angeles': 'Pacific — Los Angeles',
  'America/Anchorage': 'Alaska',
  'Pacific/Honolulu': 'Hawaii (no daylight saving)',
  'America/Toronto': 'Eastern — Toronto',
  'America/Winnipeg': 'Central — Winnipeg',
  'America/Edmonton': 'Mountain — Edmonton',
  'America/Vancouver': 'Pacific — Vancouver',
  'UTC': 'UTC',
};

/// The options to offer, with [current] guaranteed present.
///
/// A dropdown whose value is not among its items is an assertion, not a
/// fallback — so a zone set through the API, or by a franchise this list has
/// never heard of, must still open. It is shown by its raw identifier, which
/// is honest: we do not know a friendly name for it.
List<String> zonesFor(String? current) {
  final zones = _zoneNames.keys.toList();

  if (current != null && current.isNotEmpty && !zones.contains(current)) {
    zones.insert(0, current);
  }

  return zones;
}

String _zoneLabel(String zone) {
  final known = _zoneNames[zone];
  if (known != null) return known;

  // A fixed offset from before this list held places. Read it back for the
  // person looking at it rather than showing them POSIX's inverted sign.
  final match = RegExp(r'^Etc/GMT([+-])(\d+)$').firstMatch(zone);
  if (match != null) {
    final sign = match.group(1) == '+' ? '-' : '+';

    return 'UTC$sign${match.group(2)} (fixed, no daylight saving)';
  }

  return zone;
}

class _DayDraft {
  _DayDraft({required this.open, required this.from, required this.to});

  bool open;
  String from;
  String to;
}

class _Draft {
  _Draft({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.timezone,
    required this.week,
    required this.closedDates,
  });

  final String? id;
  final String connectionId;
  String name;
  String timezone;
  final Map<String, _DayDraft> week;
  final List<String> closedDates;

  static _Draft blank(String connectionId) => _Draft(
    id: null,
    connectionId: connectionId,
    name: '',

    // A REAL ZONE, not an offset.
    //
    // This was 'Etc/GMT+5', so every schedule created from this screen began
    // on a clock that does not observe daylight saving — the hours then answer
    // an hour early for the half of the year New York spends on EDT. The
    // dropdown offering places fixed what could be CHOSEN; this is where the
    // wrong one was being chosen for you.
    //
    // It matches what Phone already does elsewhere: HoursController defaults a
    // franchise-scoped schedule to the franchise's own timezone, which is
    // America/New_York on every franchise row in production, and falls back to
    // that same value when a franchise has none. So the blank form now agrees
    // with the server rather than contradicting it.
    //
    // Deliberately NOT read from the organization's timezone setting. Phone
    // does not consult that setting anywhere — it reads the franchise column
    // or refuses and makes somebody choose — and the platform default is
    // itself a fixed offset that the Marketing session has escalated to Jose
    // as a product decision. Reaching for it here would import the defect this
    // line exists to remove.
    timezone: 'America/New_York',
    week: {
      for (final key in const ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])
        key: _DayDraft(
          // Monday to Friday nine to five is what almost every business means
          // by "office hours", and a form that starts empty makes somebody
          // type the same thing fourteen times.
          open: key != 'sun' && key != 'sat',
          from: '09:00',
          to: '17:00',
        ),
    },
    closedDates: <String>[],
  );

  static _Draft of(_Schedule schedule) => _Draft(
    id: schedule.id,
    connectionId: schedule.connectionId,
    name: schedule.name,
    timezone: schedule.timezone,
    week: {
      for (final key in const ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])
        key: () {
          final intervals = schedule.weeks[key] ?? const [];
          final first = intervals.isEmpty ? null : intervals.first;

          return _DayDraft(
            open: first != null,
            from: first?.$1 ?? '09:00',
            to: first?.$2 ?? '17:00',
          );
        }(),
    },
    closedDates: [...schedule.closedDates],
  );

  Map<String, dynamic> get weeksPayload => {
    for (final entry in week.entries)
      entry.key: entry.value.open
          ? [
              [entry.value.from.trim(), entry.value.to.trim()],
            ]
          : <List<String>>[],
  };

  List<Map<String, dynamic>> get exceptionsPayload => [
    for (final date in closedDates) {'on': date, 'closed': true},
  ];
}

@immutable
class _Schedule {
  const _Schedule({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.timezone,
    required this.weeks,
    required this.closedDates,
    this.today,
  });

  final String id;
  final String connectionId;
  final String name;
  final String timezone;

  /// `Y-m-d` on the schedule's own clock, from the server; null on an older
  /// one.
  final String? today;

  /// day key → list of (from, to)
  final Map<String, List<(String, String)>> weeks;
  final List<String> closedDates;

  static List<_Schedule> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().map((row) {
      final weeks = <String, List<(String, String)>>{};
      final rawWeeks = row['weeks'];

      if (rawWeeks is Map) {
        rawWeeks.forEach((key, value) {
          if (value is! List) return;

          weeks[key.toString()] = value
              .whereType<List>()
              .where((pair) => pair.length >= 2)
              .map((pair) => (pair[0].toString(), pair[1].toString()))
              .toList();
        });
      }

      final closed = <String>[];
      final rawExceptions = row['exceptions'];

      if (rawExceptions is List) {
        for (final entry in rawExceptions.whereType<Map>()) {
          if (entry['closed'] == true && entry['on'] != null) {
            closed.add(entry['on'].toString());
          }
        }
      }

      return _Schedule(
        id: row['id'].toString(),
        connectionId: row['connection_id']?.toString() ?? '',
        name: row['name']?.toString() ?? 'Hours',
        timezone: row['timezone']?.toString() ?? 'UTC',
        weeks: weeks,
        closedDates: closed..sort(),
        today: row['today'] as String?,
      );
    }).toList(growable: false);
  }
}

@immutable
class _Conn {
  const _Conn({required this.id, required this.label});

  final String id;
  final String label;

  static List<_Conn> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Conn(
            id: r['id'].toString(),
            label: r['scope_name']?.toString() ?? 'This place',
          ),
        )
        .toList(growable: false);
  }
}
