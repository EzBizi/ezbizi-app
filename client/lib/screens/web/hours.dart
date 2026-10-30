import 'package:flutter/material.dart';

import '../../core/timestamps.dart';
import '../../widgets/shortcode_button.dart';

/// Hours of operation — the only field that is not a line of text.
///
/// ── The value on the wire stays a STRING, deliberately ──
///
/// `[ezbizi_hours]` renders into somebody's footer, the plugin caches a string,
/// and installs in the wild already read one. So the grid is an editor over a
/// canonical string, NOT a new data type: no migration, no change to the field
/// catalogue, no change to what any plugin receives. A picker that forced a
/// structured column would have broken every install to make this screen nicer.
///
/// ── Which means format and parse have to be exact inverses ──
///
/// `formatWeek` writes the string the grid means; `parseWeek` reads it back and
/// returns NULL for anything this file did not write. That null is what keeps a
/// value typed before the grid existed — "by appointment November to March" —
/// visible rather than silently replaced, and it is why the two functions are
/// tested against each other rather than against fixed strings.
///
/// Times are formatted here rather than through MaterialLocalizations for the
/// same reason: a value formatted in one locale and parsed in another would
/// round-trip into free text and lose the grid.

/// One day. `closed` wins; the times are kept so unchecking Closed restores
/// what was there rather than snapping to a default nobody chose.
@immutable
class DayHours {
  const DayHours({
    required this.closed,
    required this.open,
    required this.close,
  });

  const DayHours.shut()
    : closed = true,
      open = const TimeOfDay(hour: 9, minute: 0),
      close = const TimeOfDay(hour: 17, minute: 0);

  final bool closed;
  final TimeOfDay open;
  final TimeOfDay close;

  DayHours copyWith({bool? closed, TimeOfDay? open, TimeOfDay? close}) =>
      DayHours(
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
    final sameAsRun = i < 7 && week[i].signature == week[runStart].signature;

    if (sameAsRun) continue;

    final span = runStart == i - 1
        ? shortWeekdays[runStart]
        : '${shortWeekdays[runStart]}–${shortWeekdays[i - 1]}';

    final day = week[runStart];

    parts.add(
      day.closed
          ? '$span closed'
          : '$span ${formatTime(day.open)} – ${formatTime(day.close)}',
    );

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

    final from = shortWeekdays.indexWhere(
      (d) => d.toLowerCase() == match.group(1)!.toLowerCase(),
    );
    final to = match.group(2) == null
        ? from
        : shortWeekdays.indexWhere(
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

/// Hours of operation: seven days, two pickers each, and a Closed switch.
///
/// ── There is no text box any more, and that is deliberate ──
///
/// This began as an editable box with the grid as an optional aid. Jose asked
/// for the grid to BE the field, and he is right: somebody setting opening
/// hours should not be typing a formatted string, and the box invited typos
/// into a value that renders on a public page.
///
/// The string did not go anywhere. It is still exactly what crosses the wire
/// and what `[ezbizi_hours]` renders, composed by `formatWeek` from the grid.
/// That is why this needed no plugin release and no migration: the INPUT
/// changed, the value did not.
///
/// ── What removing the box cost ──
///
/// "By appointment, November to March" can no longer be expressed. That was a
/// real capability and it is worth naming rather than discovering later: a
/// business whose hours do not fit a seven-day grid now has no way to say so.
/// If one turns up, the answer is a third per-day state or a free-text escape
/// hatch beneath the grid — not the box back.
///
/// A value this grid cannot parse — one typed before the box was removed —
/// still opens rather than being silently replaced: `parseWeek` returns null,
/// the grid falls back to a sensible week, and the original stays on screen
/// until somebody sets a day.
class HoursField extends StatefulWidget {
  const HoursField({
    super.key,
    required this.controller,
    required this.enabled,
    required this.label,
    required this.shortcode,
    this.inherited = '',
    this.inheritedFrom,
  });

  final TextEditingController controller;
  final bool enabled;
  final String label;
  final String shortcode;

  /// What the primary site publishes, when this site says nothing.
  final String inherited;
  final String? inheritedFrom;

  @override
  State<HoursField> createState() => _HoursFieldState();
}

class _HoursFieldState extends State<HoursField> {
  late List<DayHours> _week;

  /// Text stored before the grid existed that the grid cannot express. Kept
  /// visible so nobody loses it without being shown it first.
  String? _unparsed;

  bool get _isInheriting =>
      widget.controller.text.trim().isEmpty && widget.inherited.isNotEmpty;

  @override
  void initState() {
    super.initState();
    _read();
  }

  void _read() {
    final text = widget.controller.text.trim();
    final parsed = parseWeek(text);

    _week = parsed ?? parseWeek(widget.inherited) ?? defaultWeek();
    _unparsed = parsed == null && text.isNotEmpty ? text : null;
  }

  void _write() {
    widget.controller.text = formatWeek(_week);
    setState(() => _unparsed = null);
  }

  Future<void> _pick(int day, {required bool opening}) async {
    final picked = await showTimePicker(
      context: context,
      initialTime: opening ? _week[day].open : _week[day].close,
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

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(widget.label, style: theme.textTheme.titleSmall),
              ),
              ShortcodeButton(code: widget.shortcode),
            ],
          ),
          if (_isInheriting)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Publishing ${widget.inherited}, from '
                '${widget.inheritedFrom ?? 'the primary site'}. Change a day '
                'below to give this site its own.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ),
          if (_unparsed != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Saved as "$_unparsed", which this grid cannot show. Setting '
                'any day replaces it.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
          const SizedBox(height: 6),
          /*
           * -- Why the rows are boxed and ruled rather than stacked --
           *
           * Jose, 2026-09-08: "feels like its floating". It did, and the cause
           * was measurable rather than a matter of taste.
           *
           * Every OPEN day drew two `TextButton`s, and a TextButton carries
           * Material's 48dp minimum tap target plus its own padding - so an
           * open day was a tall box with the weekday stranded in whitespace
           * beside it. A CLOSED day drew a single line of text and was about
           * half that height. Seven rows alternating between two heights, with
           * nothing ruling them, gives the eye no grid to sit on, and that is
           * what reads as floating.
           *
           * So: one fixed height for all seven whatever the day says, a
           * hairline between them, and the times stripped of the padding that
           * caused it. The tap target is not lost - the ROW is 44 high and the
           * button fills it, so shrinking the button's own minimum does not
           * make a time harder to hit.
           */
          DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Column(
              children: [
                for (var day = 0; day < 7; day++)
                  Container(
                    /*
                     * MINIMUM, not fixed. Fixed was the first attempt and it
                     * clipped: at phone width the two times and their
                     * separator run 78px past the edge, and a fixed height
                     * gives the Wrap below nowhere to put the second line.
                     * 48 clears the tallest row (an open day measures 45), so all seven agree at any pane wide enough for one line. Uniform where it fits, honest where it does not — a row
                     * that grows is better than content nobody can see.
                     */
                    constraints: const BoxConstraints(minHeight: 48),
                    padding: const EdgeInsets.symmetric(
                      vertical: 2,
                    ).add(const EdgeInsets.only(left: 12, right: 8)),
                    decoration: BoxDecoration(
                      // Every row but the last carries a rule, so the seven
                      // read as one table rather than seven loose lines.
                      border: day == 6
                          ? null
                          : Border(
                              bottom: BorderSide(
                                color: theme.colorScheme.outlineVariant,
                              ),
                            ),
                    ),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 92,
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
                                  style: theme.textTheme.bodyMedium?.copyWith(
                                    color: theme.colorScheme.onSurfaceVariant,
                                  ),
                                )
                              /*
                               * A Row that may SHRINK, and neither of the two
                               * obvious alternatives - all three were measured
                               * at 375 on 2026-09-08.
                               *
                               *   plain Row  -> 78px off the right edge, into
                               *                 space nobody can see or tap.
                               *   Wrap       -> no overflow, but it takes a
                               *                 second line, so an open day
                               *                 measured 61 against a closed
                               *                 day's 48. That is a 13px step
                               *                 per row: the very "floating"
                               *                 this grid was fixed for, still
                               *                 live on a phone while desk
                               *                 width looked perfect.
                               *   FittedBox  -> one line at every width.
                               *
                               * scaleDown does nothing until it has to, so at
                               * desk width these are ordinary buttons at full
                               * size; at 375 they give up a little size rather
                               * than a line, and every row stays equal.
                               *
                               * The height above stays a MINIMUM rather than
                               * becoming fixed: a minimum still floors the
                               * short states, and nothing here can now exceed
                               * it, so there is no reason to add a cap that
                               * would clip if the text ever grew.
                               */
                              : FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.centerLeft,
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      _TimeButton(
                                        label: formatTime(_week[day].open),
                                        onPressed: widget.enabled
                                            ? () => _pick(day, opening: true)
                                            : null,
                                      ),
                                      Text(
                                        'to',
                                        style: theme.textTheme.bodySmall
                                            ?.copyWith(
                                              color: theme
                                                  .colorScheme
                                                  .onSurfaceVariant,
                                            ),
                                      ),
                                      _TimeButton(
                                        label: formatTime(_week[day].close),
                                        onPressed: widget.enabled
                                            ? () => _pick(day, opening: false)
                                            : null,
                                      ),
                                    ],
                                  ),
                                ),
                        ),
                        Switch(
                          value: !_week[day].closed,
                          // Without this the switch asserts its own 48dp tap
                          // target and fights the row height set above.
                          materialTapTargetSize:
                              MaterialTapTargetSize.shrinkWrap,
                          onChanged: widget.enabled
                              ? (open) {
                                  _week[day] = _week[day].copyWith(
                                    closed: !open,
                                  );
                                  _write();
                                }
                              : null,
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A time, tappable, without Material's 48dp tap target driving the row.
///
/// `TextButton` defaults to a 48dp minimum height and 16dp of horizontal
/// padding. Seven rows of those is what made this grid float, so this keeps the
/// look and the behaviour of a button while letting the ROW decide how tall it
/// is. The row is 44 and the button fills it, so the target is comfortable.
class _TimeButton extends StatelessWidget {
  const _TimeButton({required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onPressed,
      style: TextButton.styleFrom(
        minimumSize: Size.zero,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        textStyle: Theme.of(context).textTheme.bodyMedium,
      ),
      child: Text(label),
    );
  }
}
