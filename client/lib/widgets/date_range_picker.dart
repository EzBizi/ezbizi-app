import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/theme.dart';
import '../core/timestamps.dart';
import 'calendar_day_field.dart';

/// Choosing the dates a report covers (UI queue 57; Jose, 2026-10-06: "From
/// date to date / Or specific date / Week is confussing what week is it ? Etc
/// i should see the range / Maybe dropdown like google" — then "as u
/// recommend", desktop 1 and phone 4).
///
/// One button that always says the dates in words ([DateRangeBar]), and a
/// picker that opens IN the page under it ([DateRangePanel]): the quick
/// choices, each with the dates it means beside it, a calendar to click one
/// day or two, From and To to type, and a count of the days before Apply. No
/// dialog and no overlay — it pushes the page down, the way a date field's
/// own calendar opens.

/// Two calendar days, both included. One day is `from == to`.
@immutable
class DateSpan {
  DateSpan(DateTime from, DateTime to)
    : from = _day(from.isAfter(to) ? to : from),
      to = _day(from.isAfter(to) ? from : to);

  DateSpan.single(DateTime day) : this(day, day);

  final DateTime from;
  final DateTime to;

  static DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);

  int get days => to.difference(from).inDays + 1;
  bool get single => from == to;

  String get fromKey => dayKey(from);
  String get toKey => dayKey(to);

  /// The same length, [steps] lengths earlier or later.
  DateSpan shift(int steps) => DateSpan(
    DateTime(from.year, from.month, from.day + steps * days),
    DateTime(to.year, to.month, to.day + steps * days),
  );

  @override
  bool operator ==(Object other) =>
      other is DateSpan && other.from == from && other.to == to;

  @override
  int get hashCode => Object.hash(from, to);

  @override
  String toString() => '$fromKey..$toKey';
}

/// `YYYY-MM-DD`, what the server is sent.
String dayKey(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

/// A quick choice: its name, and the dates it means today.
class RangePreset {
  const RangePreset(this.key, this.label, this.span);

  final String key;
  final String label;
  final DateSpan Function(DateTime today) span;

  /// Weeks run Monday to Sunday, the way the server counts them.
  static DateTime _monday(DateTime d) =>
      DateTime(d.year, d.month, d.day - (d.weekday - 1));

  static final List<RangePreset> standard = [
    RangePreset('today', 'Today', (t) => DateSpan.single(t)),
    RangePreset(
      'yesterday',
      'Yesterday',
      (t) => DateSpan.single(DateTime(t.year, t.month, t.day - 1)),
    ),
    RangePreset('this_week', 'This week', (t) => DateSpan(_monday(t), t)),
    RangePreset('last_week', 'Last week', (t) {
      final monday = _monday(t);
      return DateSpan(
        DateTime(monday.year, monday.month, monday.day - 7),
        DateTime(monday.year, monday.month, monday.day - 1),
      );
    }),
    RangePreset(
      'last_7',
      'Last 7 days',
      (t) => DateSpan(DateTime(t.year, t.month, t.day - 6), t),
    ),
    RangePreset(
      'this_month',
      'This month',
      (t) => DateSpan(DateTime(t.year, t.month, 1), t),
    ),
    RangePreset(
      'last_month',
      'Last month',
      (t) => DateSpan(
        DateTime(t.year, t.month - 1, 1),
        DateTime(t.year, t.month, 0),
      ),
    ),
    RangePreset(
      'last_30',
      'Last 30 days',
      (t) => DateSpan(DateTime(t.year, t.month, t.day - 29), t),
    ),
  ];

  /// The quick choice these dates are, if they are one.
  static RangePreset? matching(DateSpan span, DateTime today) {
    for (final preset in standard) {
      if (preset.span(today) == span) return preset;
    }
    return null;
  }
}

/// One day in words: "Mon, Oct 5", or "Mon, 5 Oct" where the day comes first.
/// The year only when it is not this one.
String dayWords(
  DateTime d,
  DateTime today,
  DisplayFormats formats, {
  bool weekday = true,
}) {
  final dayFirst = formats.dateParts.first == DatePart.day;
  final date = dayFirst
      ? '${d.day} ${shortMonths[d.month - 1]}'
      : '${shortMonths[d.month - 1]} ${d.day}';
  final year = d.year == today.year ? '' : ', ${d.year}';
  return weekday ? '${shortWeekdays[d.weekday - 1]}, $date$year' : '$date$year';
}

/// The dates in words: "Tue, Oct 6", "Mon, Oct 5 – today", "Sep 1 – Sep 30".
///
/// Weekdays while the span is a week or less, where they are what tells one
/// day from the next; past that they are noise. "today" when it ends today,
/// because that is the question a running week leaves open.
String spanWords(DateSpan span, DateTime today, DisplayFormats formats) {
  final now = DateSpan.single(today).from;
  final weekday = span.days <= 7;
  if (span.single) return dayWords(span.from, now, formats);
  final to = span.to == now
      ? 'today'
      : dayWords(span.to, now, formats, weekday: weekday);
  return '${dayWords(span.from, now, formats, weekday: weekday)} – $to';
}

/// What the closed control says: the quick choice by name, then its dates.
String spanTitle(DateSpan span, DateTime today) =>
    RangePreset.matching(span, today)?.label ??
    (span.single ? 'One day' : 'Custom');

/// [‹] [This week · Mon, Oct 5 – today ▾] [›]
///
/// The arrows step by whatever length is chosen: a week, a month, or ten days.
class DateRangeBar extends StatelessWidget {
  const DateRangeBar({
    super.key,
    required this.span,
    required this.today,
    required this.formats,
    required this.open,
    required this.onToggle,
    this.onPrevious,
    this.onNext,
    this.expand = false,
    this.phone = false,
  });

  /// Phone width: no period name, the dates in full.
  final bool phone;

  /// Below this a phone's row has no room for "Custom" beside the dates,
  /// the arrows and a refresh.
  static const double _customFits = 430;

  final DateSpan span;
  final DateTime today;
  final DisplayFormats formats;
  final bool open;
  final VoidCallback? onToggle;

  /// Null greys the arrow out: at the end of time there is no later.
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  /// A phone: the button takes the width the arrows leave it.
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = spanTitle(span, today);
    final unit = span.days == 1 ? 'day' : '${span.days} days';
    final showTitle =
        !phone ||
        (RangePreset.matching(span, today) == null &&
            MediaQuery.sizeOf(context).width >= _customFits);

    final button = OutlinedButton(
      key: const Key('date-range-button'),
      onPressed: onToggle,
      style: OutlinedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        side: BorderSide(
          color: open ? theme.colorScheme.primary : theme.colorScheme.outline,
          width: open ? 2 : 1,
        ),
      ),
      child: Row(
        mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
        children: [
          Icon(
            Icons.calendar_month_outlined,
            size: 18,
            color: theme.colorScheme.primary,
          ),
          const SizedBox(width: 8),
          // A phone leaves the period's name to the quick buttons and shows
          // the dates whole (UI-UX, 2026-10-30); "Custom" only where it fits.
          if (showTitle) ...[
            Text(
              title,
              key: const Key('date-range-title'),
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(width: 8),
          ],
          // One flexible child only: a second would take half the row
          // whether it needed it or not, and cut the dates short.
          Flexible(
            fit: expand ? FlexFit.tight : FlexFit.loose,
            child: Text(
              spanWords(span, today, formats),
              key: const Key('date-range-words'),
              softWrap: false,
              overflow: phone ? TextOverflow.visible : TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Icon(
            open ? Icons.arrow_drop_up : Icons.arrow_drop_down,
            color: theme.colorScheme.primary,
          ),
        ],
      ),
    );

    return Row(
      mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
      children: [
        IconButton(
          key: const Key('date-range-previous'),
          tooltip: 'Previous $unit',
          onPressed: onPrevious,
          icon: const Icon(Icons.chevron_left),
        ),
        if (expand) Expanded(child: button) else Flexible(child: button),
        IconButton(
          key: const Key('date-range-next'),
          tooltip: 'Next $unit',
          onPressed: onNext,
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }
}

/// The picker, opened in the page under its button.
class DateRangePanel extends StatefulWidget {
  const DateRangePanel({
    super.key,
    required this.initial,
    required this.today,
    required this.formats,
    required this.onApply,
    required this.onCancel,
    this.narrow = false,
  });

  final DateSpan initial;
  final DateTime today;
  final DisplayFormats formats;
  final ValueChanged<DateSpan> onApply;
  final VoidCallback onCancel;

  /// The longest span the report will run. Longer is refused here, in the
  /// same words, rather than sent to be refused.
  static const int maxDays = 92;

  /// A phone: one month, the list over it, and the buttons at the foot.
  final bool narrow;

  @override
  State<DateRangePanel> createState() => _DateRangePanelState();
}

class _DateRangePanelState extends State<DateRangePanel> {
  late DateSpan _span = widget.initial;

  /// The first click of two: one day chosen, waiting to see whether a second
  /// makes it a range.
  DateTime? _anchor;

  /// The month drawn on the right (the left one is the month before it).
  late DateTime _month = DateTime(
    widget.initial.to.year,
    widget.initial.to.month,
  );

  late final _fromText = TextEditingController(text: widget.initial.fromKey);
  late final _toText = TextEditingController(text: widget.initial.toKey);

  DateTime get _today => DateSpan.single(widget.today).from;

  @override
  void dispose() {
    _fromText.dispose();
    _toText.dispose();
    super.dispose();
  }

  /// [move] brings the chosen dates into view. A click on the calendar does
  /// not: the months must stay where they are under the pointer, or the
  /// second click of a range lands on a different day than the one aimed at.
  void _choose(DateSpan span, {bool move = true}) {
    setState(() {
      _span = span;
      _anchor = null;
      if (move) _month = DateTime(span.to.year, span.to.month);
    });
    if (_fromText.text != span.fromKey) _fromText.text = span.fromKey;
    if (_toText.text != span.toKey) _toText.text = span.toKey;
  }

  /// One click starts a choice (and is a one-day choice if nothing follows);
  /// the next click finishes it as a range, whichever way round.
  void _tap(DateTime day) {
    final anchor = _anchor;
    if (anchor == null) {
      _choose(DateSpan.single(day), move: false);
      setState(() => _anchor = day);
    } else {
      _choose(DateSpan(anchor, day), move: false);
    }
  }

  void _typed() {
    final from = DateTime.tryParse(_fromText.text.trim());
    final to = DateTime.tryParse(_toText.text.trim());
    if (from == null || to == null) return;
    setState(() {
      _span = DateSpan(from, to);
      _anchor = null;
      _month = DateTime(_span.to.year, _span.to.month);
    });
  }

  String? get _problem {
    if (_span.to.isAfter(_today)) return 'Choose dates up to today.';
    if (_span.days > DateRangePanel.maxDays) {
      return 'Choose ${DateRangePanel.maxDays} days or fewer. '
          'This is ${_span.days}.';
    }
    return null;
  }

  /// Escape closes the picker on every report tab (review 2026-10-30, 14).
  @override
  Widget build(BuildContext context) => CallbackShortcuts(
    bindings: {
      const SingleActivator(LogicalKeyboardKey.escape): widget.onCancel,
    },
    child: Focus(autofocus: true, child: _panel(context)),
  );

  Widget _panel(BuildContext context) {
    final theme = Theme.of(context);
    final presets = _presets(context);
    final problem = _problem;

    final fields = Wrap(
      spacing: 12,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 300,
          child: CalendarDayField(
            key: const Key('date-range-from'),
            controller: _fromText,
            formats: widget.formats,
            label: 'From',
            dense: true,
            lastDate: _today,
            onChanged: (_) => _typed(),
          ),
        ),
        SizedBox(
          width: 300,
          child: CalendarDayField(
            key: const Key('date-range-to'),
            controller: _toText,
            formats: widget.formats,
            label: 'To',
            dense: true,
            lastDate: _today,
            onChanged: (_) => _typed(),
          ),
        ),
        if (!widget.narrow)
          Text(
            'Click one day for one date, two for a range.',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );

    final months = widget.narrow
        ? _MonthGrid(
            month: _month,
            span: _span,
            today: _today,
            onTap: _tap,
            onPrevious: () => setState(
              () => _month = DateTime(_month.year, _month.month - 1),
            ),
            onNext: _month.year == _today.year && _month.month == _today.month
                ? null
                : () => setState(
                    () => _month = DateTime(_month.year, _month.month + 1),
                  ),
          )
        : Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _MonthGrid(
                  month: DateTime(_month.year, _month.month - 1),
                  span: _span,
                  today: _today,
                  onTap: _tap,
                  onPrevious: () => setState(
                    () => _month = DateTime(_month.year, _month.month - 1),
                  ),
                ),
              ),
              const SizedBox(width: 20),
              Expanded(
                child: _MonthGrid(
                  month: _month,
                  span: _span,
                  today: _today,
                  onTap: _tap,
                  onNext:
                      _month.year == _today.year && _month.month == _today.month
                      ? null
                      : () => setState(
                          () =>
                              _month = DateTime(_month.year, _month.month + 1),
                        ),
                ),
              ),
            ],
          );

    final summary = Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: _span.days == 1 ? '1 day' : '${_span.days} days',
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
          TextSpan(text: ' · ${spanWords(_span, _today, widget.formats)}'),
        ],
      ),
      key: const Key('date-range-summary'),
      style: theme.textTheme.bodyMedium,
    );

    final apply = FilledButton(
      key: const Key('date-range-apply'),
      onPressed: problem == null ? () => widget.onApply(_span) : null,
      child: Text(
        widget.narrow
            ? (_span.days == 1 ? 'Show 1 day' : 'Show ${_span.days} days')
            : 'Apply',
      ),
    );
    final cancel = OutlinedButton(
      onPressed: widget.onCancel,
      child: const Text('Cancel'),
    );

    final warning = problem == null
        ? null
        : Text(
            problem,
            key: const Key('date-range-problem'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: ToneColours.of(context, Tone.bad).ink,
            ),
          );

    if (widget.narrow) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ListView(
              children: [
                ...presets,
                const Divider(height: 1),
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      fields,
                      const SizedBox(height: 12),
                      months,
                      const SizedBox(height: 8),
                      summary,
                      ?warning,
                    ],
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Expanded(child: cancel),
                const SizedBox(width: 8),
                Expanded(child: apply),
              ],
            ),
          ),
        ],
      );
    }

    return Container(
      key: const Key('date-range-panel'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: 300,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  const SizedBox(height: 6),
                  ...presets,
                  const SizedBox(height: 6),
                ],
              ),
            ),
            const VerticalDivider(width: 1),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    fields,
                    const SizedBox(height: 12),
                    months,
                    const Spacer(),
                    const Divider(height: 20),
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [summary, ?warning],
                          ),
                        ),
                        cancel,
                        const SizedBox(width: 8),
                        apply,
                      ],
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

  List<Widget> _presets(BuildContext context) {
    final theme = Theme.of(context);
    final matched = RangePreset.matching(_span, _today);
    final custom = matched == null;

    Widget row(
      String key,
      String label,
      String dates,
      bool on,
      VoidCallback onTap,
    ) {
      return Material(
        color: on ? theme.colorScheme.primaryContainer : Colors.transparent,
        child: InkWell(
          key: ValueKey('date-preset-$key'),
          onTap: onTap,
          child: Padding(
            padding: EdgeInsets.symmetric(
              horizontal: 14,
              vertical: widget.narrow ? 12 : 8,
            ),
            child: Row(
              children: [
                Text(
                  label,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    dates,
                    textAlign: TextAlign.right,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return [
      for (final preset in RangePreset.standard)
        row(
          preset.key,
          preset.label,
          spanWords(preset.span(_today), _today, widget.formats),
          matched?.key == preset.key,
          () => _choose(preset.span(_today)),
        ),
      const Divider(height: 9),
      row(
        'custom',
        'Custom',
        custom
            ? '${spanWords(_span, _today, widget.formats)} · ${_span.days} days'
            : 'Pick on the calendar',
        custom,
        () => setState(() => _anchor = null),
      ),
    ];
  }
}

/// One month, Monday first, the chosen days shaded and today ringed.
class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    required this.month,
    required this.span,
    required this.today,
    required this.onTap,
    this.onPrevious,
    this.onNext,
  });

  final DateTime month;
  final DateSpan span;
  final DateTime today;
  final ValueChanged<DateTime> onTap;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final first = DateTime(month.year, month.month, 1);
    final lead = first.weekday - 1;
    final count = DateTime(month.year, month.month + 1, 0).day;
    final primary = theme.colorScheme.primary;
    final tint = theme.colorScheme.primaryContainer;

    Widget cell(int index) {
      final n = index - lead + 1;
      if (n < 1 || n > count) return const SizedBox.shrink();
      final day = DateTime(month.year, month.month, n);
      final future = day.isAfter(today);
      final inside = !day.isBefore(span.from) && !day.isAfter(span.to);
      final end = day == span.from || day == span.to;

      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: Material(
          color: end ? primary : (inside ? tint : Colors.transparent),
          borderRadius: BorderRadius.horizontal(
            left: Radius.circular(day == span.from ? 14 : 0),
            right: Radius.circular(day == span.to ? 14 : 0),
          ),
          child: InkWell(
            key: ValueKey('date-day-${dayKey(day)}'),
            onTap: future ? null : () => onTap(day),
            child: Container(
              height: 30,
              alignment: Alignment.center,
              decoration: day == today && !end
                  ? BoxDecoration(
                      border: Border.all(color: primary, width: 1.5),
                      borderRadius: BorderRadius.circular(14),
                    )
                  : null,
              child: Text(
                '$n',
                style: theme.textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w600,
                  color: end
                      ? theme.colorScheme.onPrimary
                      : future
                      ? theme.colorScheme.outline
                      : inside
                      ? theme.colorScheme.onPrimaryContainer
                      : null,
                ),
              ),
            ),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            SizedBox(
              width: 36,
              height: 36,
              child: onPrevious == null
                  ? null
                  : IconButton(
                      tooltip: 'Earlier month',
                      visualDensity: VisualDensity.compact,
                      onPressed: onPrevious,
                      icon: const Icon(Icons.chevron_left, size: 20),
                    ),
            ),
            Expanded(
              child: Text(
                '${longMonths[month.month - 1]} ${month.year}',
                textAlign: TextAlign.center,
                style: theme.textTheme.labelLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            SizedBox(
              width: 36,
              height: 36,
              child: onNext == null
                  ? null
                  : IconButton(
                      tooltip: 'Later month',
                      visualDensity: VisualDensity.compact,
                      onPressed: onNext,
                      icon: const Icon(Icons.chevron_right, size: 20),
                    ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Row(
          children: [
            for (final w in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'])
              Expanded(
                child: Text(
                  w,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 2),
        for (var week = 0; week * 7 < lead + count; week++)
          Row(
            children: [
              for (var d = 0; d < 7; d++) Expanded(child: cell(week * 7 + d)),
            ],
          ),
      ],
    );
  }
}
