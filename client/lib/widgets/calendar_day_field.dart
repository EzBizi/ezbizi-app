/// A calendar day — or a day and a time — in ONE field that takes typing and
/// opens a calendar.
///
/// Jose, 2026-11-03, "1 - yes", to "Should the 3-box date fields become one
/// date picker?". The three Month/Day/Year boxes (and Hour/Minute/AM beside
/// them) took five tabs to fill in and read as five fields.
///
/// ── What keeps one box from inventing a format ───────────────────
///
/// The reason there were boxes was that a bare box made people guess a format.
/// So the hint is the organization's own pattern (`MM/DD/YYYY`, `DD/MM/YYYY`
/// or `YYYY-MM-DD`, from `date_format`), numbers are read in THAT order, and
/// on leaving the field it is rewritten the way the organization writes a
/// date — so 3/8 still cannot be two different days.
///
/// ── Why the calendar is not `showDatePicker` ─────────────────────
///
/// That is a modal dialog, and this app does not use those. The calendar
/// opens under the field, and the form around it stays on screen. The stored
/// value is still `YYYY-MM-DD` (or `YYYY-MM-DD HH:MM` when [withTime] is set),
/// so every caller keeps the controller it already had.
library;

import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../core/timestamps.dart';
import 'not_filled_in.dart';

class CalendarDayField extends StatefulWidget {
  const CalendarDayField({
    super.key,
    required this.controller,
    required this.formats,
    this.label,
    this.helper,
    this.enabled = true,
    this.withTime = false,
    this.dense = false,
    this.firstDate,
    this.lastDate,
    this.onChanged,
    this.flagEmpty = false,
    this.fill = true,
    this.trailing,
  });

  /// Holds `YYYY-MM-DD`, or `YYYY-MM-DD HH:MM` with [withTime]; empty for none.
  final TextEditingController controller;

  final DisplayFormats formats;
  final String? label;
  final String? helper;
  final bool enabled;
  final bool withTime;
  final bool dense;
  final DateTime? firstDate;
  final DateTime? lastDate;
  final ValueChanged<String>? onChanged;

  /// Marks an empty field "Not filled in" (an optional answer left blank).
  final bool flagEmpty;

  /// Takes the full width, as every other box in a form does (Jose,
  /// 2026-11-06: "the date of birth field is not the same size as the
  /// rest"); false keeps a date's 220.
  final bool fill;

  /// An extra control after the calendar button, e.g. a copy button.
  final Widget? trailing;

  @override
  State<CalendarDayField> createState() => _CalendarDayFieldState();
}

class _CalendarDayFieldState extends State<CalendarDayField> {
  static final _first = DateTime(1900, 1, 1);
  static final _last = DateTime(2100, 12, 31);

  final _text = TextEditingController();
  final _focus = FocusNode();

  bool _open = false;
  bool _writing = false;
  String? _invalid;

  DateTime get _firstDate => widget.firstDate ?? _first;
  DateTime get _lastDate => widget.lastDate ?? _last;

  @override
  void initState() {
    super.initState();
    _text.text = _shown();
    widget.controller.addListener(_onController);
    _focus.addListener(_onBlur);
  }

  @override
  void didUpdateWidget(covariant CalendarDayField old) {
    super.didUpdateWidget(old);
    if (old.controller != widget.controller) {
      old.controller.removeListener(_onController);
      widget.controller.addListener(_onController);
      _text.text = _shown();
    } else if (old.formats != widget.formats) {
      _text.text = _shown();
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onController);
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  /// The stored value, written the organization's way.
  String _shown() {
    final held = parseWallDateTime(widget.controller.text);
    if (held == null) return '';
    final day = widget.formats.formatDate(held.day);
    if (!widget.withTime) return day;
    return '$day ${widget.formats.formatTime(held.hour, held.minute)}';
  }

  /// The pattern a person types, in the organization's order.
  String get _pattern {
    final parts = widget.formats.dateParts;
    final sep = widget.formats.dateFormat == 'YYYY-MM-DD' ? '-' : '/';
    final day = parts
        .map(
          (p) => switch (p) {
            DatePart.month => 'MM',
            DatePart.day => 'DD',
            DatePart.year => 'YYYY',
          },
        )
        .join(sep);
    if (!widget.withTime) return day;
    return widget.formats.timeFormat == '12h' ? '$day 9:00 AM' : '$day 09:00';
  }

  void _onController() {
    if (_writing) return;
    final shown = _shown();
    if (widget.controller.text.trim().isEmpty || shown.isNotEmpty) {
      _text.text = shown;
      _invalid = null;
    }
    if (mounted) setState(() {});
  }

  void _onBlur() {
    if (_focus.hasFocus) return;
    _commit(final_: true);
  }

  /// What was typed, read in the organization's order, or why not.
  ({DateTime day, int hour, int minute})? _read(String raw) {
    final numbers = [
      for (final m in RegExp(r'\d+').allMatches(raw)) m.group(0)!,
    ];
    if (numbers.length < 3) return null;

    final parts = widget.formats.dateParts;
    int? month, day, year;
    String? yearText;
    for (var i = 0; i < 3; i++) {
      final n = int.tryParse(numbers[i]);
      switch (parts[i]) {
        case DatePart.month:
          month = n;
        case DatePart.day:
          day = n;
        case DatePart.year:
          year = n;
          yearText = numbers[i];
      }
    }
    if (month == null || day == null || year == null) return null;
    if ((yearText ?? '').length != 4) return null;
    final date = parseCalendarDay(
      '${year.toString().padLeft(4, '0')}-'
      '${month.toString().padLeft(2, '0')}-'
      '${day.toString().padLeft(2, '0')}',
    );
    if (date == null) return null;
    if (!widget.withTime) return (day: date, hour: 0, minute: 0);

    if (numbers.length < 4) return null;
    var hour = int.tryParse(numbers[3]) ?? -1;
    final minute = numbers.length > 4 ? int.tryParse(numbers[4]) ?? -1 : 0;
    final lower = raw.toLowerCase();
    final pm = RegExp(r'\dp|\sp').hasMatch(lower);
    final am = RegExp(r'\da|\sa').hasMatch(lower);
    if (pm || am) {
      if (hour < 1 || hour > 12) return null;
      hour = hour % 12 + (pm ? 12 : 0);
    }
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59) return null;
    return (day: date, hour: hour, minute: minute);
  }

  /// Stores what was typed; [final_] (leaving the field) also rewrites the
  /// text the organization's way and names a problem.
  void _commit({bool final_ = false}) {
    final raw = _text.text.trim();
    if (raw.isEmpty) {
      _setIso('');
      setState(() => _invalid = null);
      return;
    }

    final read = _read(raw);
    if (read == null) {
      if (final_) {
        setState(() => _invalid = 'Type it as $_pattern, or pick a day.');
      }
      return;
    }
    if (read.day.isBefore(_firstDate) || read.day.isAfter(_lastDate)) {
      setState(() => _invalid = 'That day is out of range.');
      return;
    }

    final held = parseWallDateTime(widget.controller.text);
    final same =
        held != null &&
        DateUtils.isSameDay(held.day, read.day) &&
        (!widget.withTime ||
            (held.hour == read.hour && held.minute == read.minute));
    if (same) {
      // Unchanged to the minute: hand back exactly what was loaded (a job's
      // seconds would otherwise be dropped and read as a person's edit).
      widget.onChanged?.call(widget.controller.text);
    } else {
      _setIso(
        widget.withTime
            ? formatWallDateTime(read.day, read.hour, read.minute)
            : formatCalendarDay(read.day),
      );
    }
    if (final_) _text.text = _shown();
    setState(() => _invalid = null);
  }

  void _setIso(String value) {
    if (widget.controller.text == value) {
      widget.onChanged?.call(value);
      return;
    }
    _writing = true;
    widget.controller.text = value;
    _writing = false;
    widget.onChanged?.call(value);
  }

  void _pick(DateTime day) {
    final held = parseWallDateTime(widget.controller.text);
    _setIso(
      widget.withTime
          // A day picked for a field that had no time yet starts at 9.
          ? formatWallDateTime(day, held?.hour ?? 9, held?.minute ?? 0)
          : formatCalendarDay(day),
    );
    _text.text = _shown();
    setState(() {
      _open = false;
      _invalid = null;
    });
  }

  DateTime _initialPickerDate() {
    var at = parseWallDateTime(widget.controller.text)?.day ?? DateTime.now();
    if (at.isBefore(_firstDate)) at = _firstDate;
    if (at.isAfter(_lastDate)) at = _lastDate;
    return at;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = widget.label?.trim();
    final unfilled = widget.flagEmpty && widget.controller.text.trim().isEmpty;
    final note =
        _invalid ?? widget.helper ?? (unfilled ? 'Not filled in' : null);

    var decoration = InputDecoration(
      labelText: label == null || label.isEmpty ? null : label,
      hintText: _pattern,
      floatingLabelBehavior: FloatingLabelBehavior.always,
      // The theme's own height unless dense, so it matches the boxes beside it.
      isDense: widget.dense ? true : null,
      contentPadding: widget.dense
          ? const EdgeInsets.symmetric(horizontal: 12, vertical: 10)
          : null,
      suffixIcon: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            key: const Key('calendar-day-pick'),
            tooltip: _open ? 'Hide the calendar' : 'Pick a day on the calendar',
            onPressed: widget.enabled
                ? () => setState(() => _open = !_open)
                : null,
            icon: Icon(
              _open ? Icons.calendar_month : Icons.calendar_today_outlined,
              size: 20,
            ),
          ),
          ?widget.trailing,
        ],
      ),
    );
    if (unfilled) {
      decoration = notFilledIn(
        context,
        decoration,
        empty: true,
      ).copyWith(hintText: _pattern);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxWidth: widget.fill
                  ? double.infinity
                  : (widget.withTime ? 280 : 220),
            ),
            child: TextField(
              key: const Key('calendar-day-text'),
              controller: _text,
              focusNode: _focus,
              enabled: widget.enabled,
              keyboardType: TextInputType.datetime,
              decoration: decoration,
              onChanged: (_) => _commit(),
              onSubmitted: (_) => _commit(final_: true),
            ),
          ),
        ),
        if (note != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              note,
              style: theme.textTheme.bodySmall?.copyWith(
                color: _invalid != null
                    ? theme.colorScheme.error
                    : unfilled && widget.helper == null
                    ? StateColour.of(context).caution
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        if (_open)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Material(
              color: theme.colorScheme.surface,
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
                side: BorderSide(color: theme.colorScheme.outlineVariant),
              ),
              child: CalendarDatePicker(
                key: ValueKey(
                  parseWallDateTime(widget.controller.text)?.day ?? 'open',
                ),
                initialDate: _initialPickerDate(),
                firstDate: _firstDate,
                lastDate: _lastDate,
                onDateChanged: _pick,
              ),
            ),
          ),
      ],
    );
  }
}
