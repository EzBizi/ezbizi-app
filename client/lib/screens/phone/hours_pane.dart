import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

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
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
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

        Align(
          alignment: Alignment.centerLeft,
          child: Refused(
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
        ),

        const SizedBox(height: 20),

        if (_schedules.isEmpty)
          Text(
            'No hours set yet. Every line answers around the clock until you '
            'add some.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final schedule in _schedules)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                key: ValueKey('hours:${schedule.id}'),
                title: Text(schedule.name),
                subtitle: Text(
                  '${_summarise(schedule)} · ${_zoneLabel(schedule.timezone)}',
                  style: theme.textTheme.bodySmall,
                ),
                trailing: _canEdit
                    ? Row(
                        mainAxisSize: MainAxisSize.min,
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
                    : null,
              ),
            ),
      ],
    );
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
        DropdownButtonFormField<String>(
          initialValue: draft.timezone,
          decoration: const InputDecoration(
            labelText: 'Clock these hours run on',
            border: OutlineInputBorder(),
          ),
          items: [
            for (final zone in _zones)
              DropdownMenuItem(value: zone, child: Text(_zoneLabel(zone))),
          ],
          onChanged: (v) => setState(() => draft.timezone = v ?? 'UTC'),
        ),

        const SizedBox(height: 20),
        Text('The week', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'A day with no times is closed, and says so.',
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

  Widget _dayRow(_Draft draft, String key, String label, ThemeData theme) {
    final day = draft.week[key]!;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
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
    );
  }
}

/// Whole-hour UTC offsets, stored as zone names PHP accepts.
///
/// Same convention as SettingRegistry: people pick UTC-5, not a city, and the
/// wire value is `UTC` or `Etc/GMT…`. POSIX signs are inverted there —
/// `Etc/GMT+5` IS UTC-5 — which is exactly the trap this list exists to hide.
final List<String> _zones = <String>[
  for (var h = -12; h <= 14; h++)
    if (h == 0) 'UTC' else 'Etc/GMT${h > 0 ? '-' : '+'}${h.abs()}',
];

String _zoneLabel(String zone) {
  if (zone == 'UTC') return 'UTC';

  final match = RegExp(r'^Etc/GMT([+-])(\d+)$').firstMatch(zone);
  if (match == null) return zone;

  // Flip the POSIX sign back for the reader.
  final sign = match.group(1) == '+' ? '-' : '+';

  return 'UTC$sign${match.group(2)}';
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
    timezone: 'Etc/GMT+5',
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
  });

  final String id;
  final String connectionId;
  final String name;
  final String timezone;

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
