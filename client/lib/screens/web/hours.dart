import 'package:flutter/material.dart';

/// Hours of operation — the only field that is not a line of text.
///
/// ── The value on the wire stays a STRING, deliberately ──
///
/// `[ezbizi_hours]` renders into somebody's footer, the plugin caches a string,
/// and installs in the wild already read one. So this is an input aid over a
/// canonical string, NOT a new data type: no migration, no change to the field
/// catalogue, no change to what any plugin receives. A picker that forced a
/// structured column would have broken every install to make this screen nicer.
///
/// ── Which means format and parse have to be exact inverses ──
///
/// The box stays editable, because real hours do not always fit a grid — "by
/// appointment November to March" is a legitimate answer. So reopening the
/// editor has to read back whatever was stored: `parseWeek` returns null for
/// anything this file did not write, and the screen then leaves the person in
/// the text box rather than silently discarding what they typed.
///
/// Times are formatted here rather than through MaterialLocalizations for the
/// same reason — a value formatted in one locale and parsed in another would
/// round-trip into free text and lose the grid.

/// One day. `closed` wins; the times are kept so unchecking Closed restores
/// what was there rather than snapping to a default nobody chose.
@immutable
class DayHours {
  const DayHours({required this.closed, required this.open, required this.close});

  const DayHours.shut()
      : closed = true,
        open = const TimeOfDay(hour: 9, minute: 0),
        close = const TimeOfDay(hour: 17, minute: 0);

  final bool closed;
  final TimeOfDay open;
  final TimeOfDay close;

  DayHours copyWith({bool? closed, TimeOfDay? open, TimeOfDay? close}) => DayHours(
        closed: closed ?? this.closed,
        open: open ?? this.open,
        close: close ?? this.close,
      );

  /// What two days must share to be collapsed into one span.
  String get signature => closed ? 'closed' : '${_hhmm(open)}-${_hhmm(close)}';

  @override
  bool operator ==(Object other) =>
      other is DayHours &&
      other.closed == closed &&
      other.open == open &&
      other.close == close;

  @override
  int get hashCode => Object.hash(closed, open, close);
}

const List<String> weekdayNames = [
  'Mon',
  'Tue',
  'Wed',
  'Thu',
  'Fri',
  'Sat',
  'Sun',
];

const List<String> weekdayLongNames = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
];

/// A week nobody has edited yet: weekdays open, weekend shut. A guess, but a
/// guess somebody can correct in two clicks beats seven empty rows.
List<DayHours> defaultWeek() => [
      for (var i = 0; i < 5; i++)
        const DayHours(
          closed: false,
          open: TimeOfDay(hour: 8, minute: 0),
          close: TimeOfDay(hour: 17, minute: 0),
        ),
      const DayHours.shut(),
      const DayHours.shut(),
    ];

String _hhmm(TimeOfDay t) =>
    '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

String formatTime(TimeOfDay t) {
  final suffix = t.hour >= 12 ? 'PM' : 'AM';
  var hour = t.hour % 12;
  if (hour == 0) hour = 12;

  return '$hour:${t.minute.toString().padLeft(2, '0')} $suffix';
}

/// `Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed`
///
/// Consecutive days sharing hours collapse into a span, because seven
/// identical lines is not what anybody puts in a footer — and the collapsing is
/// the reason this is worth writing rather than joining strings at the call
/// site.
String formatWeek(List<DayHours> week) {
  assert(week.length == 7);

  final parts = <String>[];
  var runStart = 0;

  for (var i = 1; i <= 7; i++) {
    final sameAsRun =
        i < 7 && week[i].signature == week[runStart].signature;

    if (sameAsRun) continue;

    final span = runStart == i - 1
        ? weekdayNames[runStart]
        : '${weekdayNames[runStart]}–${weekdayNames[i - 1]}';

    final day = week[runStart];

    parts.add(day.closed
        ? '$span closed'
        : '$span ${formatTime(day.open)} – ${formatTime(day.close)}');

    runStart = i;
  }

  return parts.join(' · ');
}

/// The inverse, or null when the text did not come from `formatWeek`.
///
/// Null is the important return: it means somebody typed their own sentence,
/// and the screen must show them the text box rather than a grid that would
/// overwrite it on the next save.
List<DayHours>? parseWeek(String text) {
  final trimmed = text.trim();
  if (trimmed.isEmpty) return null;

  final week = List<DayHours?>.filled(7, null);

  for (final part in trimmed.split('·')) {
    final chunk = part.trim();
    if (chunk.isEmpty) return null;

    final match = RegExp(
      r'^([A-Za-z]{3})(?:–([A-Za-z]{3}))?\s+(closed|(\d{1,2}):(\d{2})\s*(AM|PM)\s*–\s*(\d{1,2}):(\d{2})\s*(AM|PM))$',
      caseSensitive: false,
    ).firstMatch(chunk);

    if (match == null) return null;

    final from = weekdayNames.indexWhere(
      (d) => d.toLowerCase() == match.group(1)!.toLowerCase(),
    );
    final to = match.group(2) == null
        ? from
        : weekdayNames.indexWhere(
            (d) => d.toLowerCase() == match.group(2)!.toLowerCase(),
          );

    if (from < 0 || to < 0 || to < from) return null;

    final DayHours value;

    if (match.group(3)!.toLowerCase() == 'closed') {
      value = const DayHours.shut();
    } else {
      final open = _time(match.group(4)!, match.group(5)!, match.group(6)!);
      final close = _time(match.group(7)!, match.group(8)!, match.group(9)!);

      if (open == null || close == null) return null;

      value = DayHours(closed: false, open: open, close: close);
    }

    for (var i = from; i <= to; i++) {
      // A day named twice means the text is not something we wrote.
      if (week[i] != null) return null;
      week[i] = value;
    }
  }

  if (week.any((d) => d == null)) return null;

  return week.cast<DayHours>();
}

TimeOfDay? _time(String hour, String minute, String meridiem) {
  var h = int.tryParse(hour);
  final m = int.tryParse(minute);

  if (h == null || m == null || h < 1 || h > 12 || m < 0 || m > 59) return null;

  if (meridiem.toUpperCase() == 'PM' && h != 12) h += 12;
  if (meridiem.toUpperCase() == 'AM' && h == 12) h = 0;

  return TimeOfDay(hour: h, minute: m);
}

/// The hours field: a text box that writes itself, and a grid that writes it.
///
/// The box stays editable and stays the source of truth, because the value it
/// holds is what a website renders. The grid is a convenience over it — open
/// it, set the days, and the box updates. Type something the grid cannot
/// express and the grid steps aside rather than fighting you for the field.
class HoursField extends StatefulWidget {
  const HoursField({
    super.key,
    required this.controller,
    required this.enabled,
    required this.label,
    required this.hint,
  });

  final TextEditingController controller;
  final bool enabled;
  final String label;
  final String hint;

  @override
  State<HoursField> createState() => _HoursFieldState();
}

class _HoursFieldState extends State<HoursField> {
  bool _open = false;
  late List<DayHours> _week;

  @override
  void initState() {
    super.initState();
    _week = parseWeek(widget.controller.text) ?? defaultWeek();
  }

  /// Reopening reads the BOX, not the last grid state — somebody may have
  /// typed over it since, and the box is what gets saved.
  void _toggle() {
    setState(() {
      if (!_open) _week = parseWeek(widget.controller.text) ?? defaultWeek();
      _open = !_open;
    });
  }

  void _write() {
    widget.controller.text = formatWeek(_week);
    setState(() {});
  }

  Future<void> _pick(int day, {required bool opening}) async {
    final current = opening ? _week[day].open : _week[day].close;

    final picked = await showTimePicker(
      context: context,
      initialTime: current,
      helpText: '${weekdayLongNames[day]} — ${opening ? 'opens' : 'closes'}',
    );

    if (picked == null) return;

    _week[day] = opening
        ? _week[day].copyWith(open: picked)
        : _week[day].copyWith(close: picked);

    _write();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final freeText = parseWeek(widget.controller.text) == null &&
        widget.controller.text.trim().isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: TextField(
                controller: widget.controller,
                enabled: widget.enabled,
                decoration: InputDecoration(
                  labelText: widget.label,
                  helperText: widget.hint,
                  helperMaxLines: 2,
                ),
              ),
            ),
            if (widget.enabled) ...[
              const SizedBox(width: 8),
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: OutlinedButton(
                  onPressed: _toggle,
                  child: Text(_open ? 'Done' : 'Set hours'),
                ),
              ),
            ],
          ],
        ),
        if (_open) ...[
          const SizedBox(height: 8),
          if (freeText)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                'These hours were written by hand. Using the grid will replace '
                'them.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
          DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Column(
              children: [
                for (var day = 0; day < 7; day++)
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 4,
                    ),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 96,
                          child: Text(
                            weekdayLongNames[day],
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        Expanded(
                          child: _week[day].closed
                              ? Text(
                                  'Closed',
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: theme.colorScheme.outline,
                                  ),
                                )
                              : Wrap(
                                  spacing: 6,
                                  crossAxisAlignment: WrapCrossAlignment.center,
                                  children: [
                                    TextButton(
                                      onPressed: () =>
                                          _pick(day, opening: true),
                                      child: Text(formatTime(_week[day].open)),
                                    ),
                                    const Text('to'),
                                    TextButton(
                                      onPressed: () =>
                                          _pick(day, opening: false),
                                      child: Text(formatTime(_week[day].close)),
                                    ),
                                  ],
                                ),
                        ),
                        Switch(
                          value: !_week[day].closed,
                          onChanged: (open) {
                            _week[day] = _week[day].copyWith(closed: !open);
                            _write();
                          },
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
