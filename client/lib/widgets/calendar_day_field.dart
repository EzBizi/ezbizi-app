import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/timestamps.dart';

/// A calendar day, typed as three number boxes, with a calendar beside them.
///
/// ── Why this is not a single text field ──────────────────────────
///
/// A box that asked for `2026-09-01` (or `A calendar day — 2026-09-01` as a
/// hint) made the reader invent a format, and the one they invented was never
/// the one the server stored. Three boxes named Month, Day and Year are the
/// order the owner asked for; the organization setting still decides which
/// box is first, so 3/8 cannot be two different days.
///
/// ── Why the calendar is not `showDatePicker` ─────────────────────
///
/// That is a modal dialog, and this app does not use those. The calendar
/// opens under the boxes, the way a menu does, and the form around it stays
/// on screen. The stored value is still `YYYY-MM-DD` (or `YYYY-MM-DD HH:MM`
/// when [withTime] is set), so every caller keeps the same controller it
/// already had.
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
  });

  /// ISO day, or wall clock `YYYY-MM-DD HH:MM` when [withTime] is set. Empty
  /// means no day. Callers already posted this spelling; this widget does not
  /// change it.
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

  @override
  State<CalendarDayField> createState() => _CalendarDayFieldState();
}

class _CalendarDayFieldState extends State<CalendarDayField> {
  static final _first = DateTime(1900, 1, 1);
  static final _last = DateTime(2100, 12, 31);

  final _month = TextEditingController();
  final _day = TextEditingController();
  final _year = TextEditingController();
  final _hour = TextEditingController();
  final _minute = TextEditingController();

  final _monthFocus = FocusNode();
  final _dayFocus = FocusNode();
  final _yearFocus = FocusNode();
  final _hourFocus = FocusNode();
  final _minuteFocus = FocusNode();

  bool _open = false;
  bool _pm = false;
  bool _writing = false;
  String? _invalid;

  DateTime get _firstDate => widget.firstDate ?? _first;
  DateTime get _lastDate => widget.lastDate ?? _last;

  @override
  void initState() {
    super.initState();
    _fromController();
    widget.controller.addListener(_onController);
    for (final node in [_monthFocus, _dayFocus, _yearFocus]) {
      node.addListener(_onBlur);
    }
  }

  @override
  void didUpdateWidget(covariant CalendarDayField old) {
    super.didUpdateWidget(old);
    if (old.controller != widget.controller) {
      old.controller.removeListener(_onController);
      widget.controller.addListener(_onController);
      _fromController();
    } else if (old.formats != widget.formats) {
      // Same day, new order (or 12h vs 24h). Re-read so the boxes keep the
      // month in the month box when Day moves first.
      _fromController();
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onController);
    for (final c in [_month, _day, _year, _hour, _minute]) {
      c.dispose();
    }
    for (final n in [
      _monthFocus,
      _dayFocus,
      _yearFocus,
      _hourFocus,
      _minuteFocus,
    ]) {
      n.dispose();
    }
    super.dispose();
  }

  void _onController() {
    if (_writing) return;
    _fromController();
    if (mounted) setState(() {});
  }

  void _onBlur() {
    if (_monthFocus.hasFocus || _dayFocus.hasFocus || _yearFocus.hasFocus) {
      return;
    }
    _pad(_month, 2);
    _pad(_day, 2);
    _commit();
  }

  void _fromController() {
    final parsed = parseWallDateTime(widget.controller.text);
    if (parsed == null) {
      if (widget.controller.text.trim().isEmpty) {
        _month.clear();
        _day.clear();
        _year.clear();
        _hour.clear();
        _minute.clear();
        _pm = false;
        _invalid = null;
      }
      return;
    }

    _month.text = parsed.day.month.toString().padLeft(2, '0');
    _day.text = parsed.day.day.toString().padLeft(2, '0');
    _year.text = '${parsed.day.year}';
    if (widget.withTime) {
      final hour = parsed.hour;
      if (widget.formats.timeFormat == '12h') {
        final twelve = hour % 12 == 0 ? 12 : hour % 12;
        _hour.text = '$twelve';
        _pm = hour >= 12;
      } else {
        _hour.text = hour.toString().padLeft(2, '0');
        _pm = false;
      }
      _minute.text = parsed.minute.toString().padLeft(2, '0');
    }
    _invalid = null;
  }

  void _pad(TextEditingController box, int width) {
    final text = box.text.trim();
    if (text.isEmpty) return;
    final n = int.tryParse(text);
    if (n == null) return;
    final padded = n.toString().padLeft(width, '0');
    if (padded != box.text) box.value = box.value.copyWith(text: padded);
  }

  DateTime? _typedDay() {
    final month = int.tryParse(_month.text.trim());
    final day = int.tryParse(_day.text.trim());
    final year = int.tryParse(_year.text.trim());
    if (month == null || day == null || year == null) return null;
    if (_year.text.trim().length < 4) return null;
    return parseCalendarDay(
      '${year.toString().padLeft(4, '0')}-'
      '${month.toString().padLeft(2, '0')}-'
      '${day.toString().padLeft(2, '0')}',
    );
  }

  (int, int)? _typedClock() {
    if (!widget.withTime) return (0, 0);

    final minute = int.tryParse(_minute.text.trim());
    final rawHour = int.tryParse(_hour.text.trim());
    if (minute == null || rawHour == null) return null;
    if (minute > 59) return null;

    if (widget.formats.timeFormat == '12h') {
      if (rawHour < 1 || rawHour > 12) return null;
      var hour = rawHour % 12;
      if (_pm) hour += 12;
      return (hour, minute);
    }

    if (rawHour > 23) return null;
    return (rawHour, minute);
  }

  void _commit() {
    final emptyDate =
        _month.text.trim().isEmpty &&
        _day.text.trim().isEmpty &&
        _year.text.trim().isEmpty;

    if (emptyDate &&
        (!widget.withTime ||
            (_hour.text.trim().isEmpty && _minute.text.trim().isEmpty))) {
      _setIso('');
      setState(() => _invalid = null);
      return;
    }

    final day = _typedDay();
    if (day == null) {
      setState(
        () => _invalid =
            _year.text.trim().length == 4 &&
                _month.text.trim().isNotEmpty &&
                _day.text.trim().isNotEmpty
            ? 'That day is not on the calendar.'
            : null,
      );
      return;
    }

    if (day.isBefore(_firstDate) || day.isAfter(_lastDate)) {
      setState(() => _invalid = 'That day is out of range.');
      return;
    }

    if (!widget.withTime) {
      _setIso(formatCalendarDay(day));
      setState(() => _invalid = null);
      return;
    }

    final clock = _typedClock();
    if (clock == null) {
      setState(() => _invalid = null);
      return;
    }

    // The minute already held is not a change, whatever its text. A value
    // loaded with seconds — a job booked at 09:43:33 — used to be rewritten as
    // 09:43 on every click in and out of a box: the seconds were dropped on
    // the next save, and anything comparing text read an edit nobody made
    // (Jobs, 83533a1c, which fixed it in the job panel; this is the root).
    final held = parseWallDateTime(widget.controller.text);
    if (held != null &&
        held.day.year == day.year &&
        held.day.month == day.month &&
        held.day.day == day.day &&
        held.hour == clock.$1 &&
        held.minute == clock.$2) {
      widget.onChanged?.call(widget.controller.text);
      setState(() => _invalid = null);
      return;
    }

    _setIso(formatWallDateTime(day, clock.$1, clock.$2));
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

  void _onPart(DatePart part, String raw) {
    final box = switch (part) {
      DatePart.month => _month,
      DatePart.day => _day,
      DatePart.year => _year,
    };
    final max = part == DatePart.year ? 4 : 2;
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    final clipped = digits.length > max ? digits.substring(0, max) : digits;
    if (clipped != box.text) {
      box.value = TextEditingValue(
        text: clipped,
        selection: TextSelection.collapsed(offset: clipped.length),
      );
    }

    if (part != DatePart.year && clipped.length == 2) {
      _focusAfter(part);
    } else if (part == DatePart.month &&
        clipped.length == 1 &&
        (int.tryParse(clipped) ?? 0) >= 2) {
      _pad(_month, 2);
      _focusAfter(part);
    } else if (part == DatePart.day &&
        clipped.length == 1 &&
        (int.tryParse(clipped) ?? 0) >= 4) {
      _pad(_day, 2);
      _focusAfter(part);
    }

    _commit();
  }

  void _focusAfter(DatePart part) {
    final order = widget.formats.dateParts;
    final i = order.indexOf(part);
    if (i < 0 || i == order.length - 1) {
      if (widget.withTime) {
        _hourFocus.requestFocus();
      }
      return;
    }
    switch (order[i + 1]) {
      case DatePart.month:
        _monthFocus.requestFocus();
      case DatePart.day:
        _dayFocus.requestFocus();
      case DatePart.year:
        _yearFocus.requestFocus();
    }
  }

  void _onHour(String raw) {
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    final clipped = digits.length > 2 ? digits.substring(0, 2) : digits;
    _hour.value = TextEditingValue(
      text: clipped,
      selection: TextSelection.collapsed(offset: clipped.length),
    );
    if (clipped.length == 2) _minuteFocus.requestFocus();
    _commit();
  }

  void _onMinute(String raw) {
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    final clipped = digits.length > 2 ? digits.substring(0, 2) : digits;
    _minute.value = TextEditingValue(
      text: clipped,
      selection: TextSelection.collapsed(offset: clipped.length),
    );
    _commit();
  }

  void _pick(DateTime day) {
    _month.text = day.month.toString().padLeft(2, '0');
    _day.text = day.day.toString().padLeft(2, '0');
    _year.text = '${day.year}';
    if (widget.withTime && _hour.text.trim().isEmpty) {
      if (widget.formats.timeFormat == '12h') {
        _hour.text = '9';
        _minute.text = '00';
        _pm = false;
      } else {
        _hour.text = '09';
        _minute.text = '00';
      }
    }
    setState(() => _open = false);
    _commit();
  }

  void _toggleCalendar() {
    setState(() => _open = !_open);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = widget.label?.trim();
    final note = _invalid ?? widget.helper;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (label != null && label.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            for (final part in widget.formats.dateParts) _partBox(part),
            if (widget.withTime) ...[
              _timeBox(
                controller: _hour,
                focus: _hourFocus,
                hint: widget.formats.timeFormat == '12h' ? 'H' : 'HH',
                label: 'Hour',
                keyName: 'hour',
                onChanged: _onHour,
                width: 56,
              ),
              Text(':', style: theme.textTheme.titleMedium),
              _timeBox(
                controller: _minute,
                focus: _minuteFocus,
                hint: 'MM',
                label: 'Minute',
                keyName: 'minute',
                onChanged: _onMinute,
                width: 56,
              ),
              if (widget.formats.timeFormat == '12h')
                OutlinedButton(
                  onPressed: widget.enabled
                      ? () {
                          setState(() => _pm = !_pm);
                          _commit();
                        }
                      : null,
                  child: Text(_pm ? 'PM' : 'AM'),
                ),
            ],
            IconButton(
              key: const Key('calendar-day-pick'),
              tooltip: _open
                  ? 'Hide the calendar'
                  : 'Pick a day on the calendar',
              onPressed: widget.enabled ? _toggleCalendar : null,
              icon: Icon(
                _open ? Icons.calendar_month : Icons.calendar_today_outlined,
                size: 20,
              ),
            ),
          ],
        ),
        if (note != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              note,
              style: theme.textTheme.bodySmall?.copyWith(
                color: _invalid != null
                    ? theme.colorScheme.error
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
                key: ValueKey(_typedDay() ?? 'open'),
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

  DateTime _initialPickerDate() {
    var at = _typedDay() ?? DateTime.now();
    if (at.isBefore(_firstDate)) at = _firstDate;
    if (at.isAfter(_lastDate)) at = _lastDate;
    return at;
  }

  Widget _partBox(DatePart part) {
    final (controller, focus, hint, label, max, keyName, width) = switch (part) {
      DatePart.month => (
        _month,
        _monthFocus,
        'MM',
        'Month',
        2,
        'month',
        64.0,
      ),
      DatePart.day => (_day, _dayFocus, 'DD', 'Day', 2, 'day', 64.0),
      DatePart.year => (_year, _yearFocus, 'YYYY', 'Year', 4, 'year', 88.0),
    };

    return SizedBox(
      width: width,
      child: TextField(
        key: Key('calendar-day-$keyName'),
        controller: controller,
        focusNode: focus,
        enabled: widget.enabled,
        keyboardType: TextInputType.number,
        textInputAction: TextInputAction.next,
        inputFormatters: [
          FilteringTextInputFormatter.digitsOnly,
          LengthLimitingTextInputFormatter(max),
        ],
        decoration: InputDecoration(
          hintText: hint,
          labelText: label,
          isDense: true,
          contentPadding: EdgeInsets.symmetric(
            horizontal: 10,
            vertical: widget.dense ? 10 : 12,
          ),
        ),
        onChanged: (value) => _onPart(part, value),
      ),
    );
  }

  Widget _timeBox({
    required TextEditingController controller,
    required FocusNode focus,
    required String hint,
    required String label,
    required String keyName,
    required ValueChanged<String> onChanged,
    required double width,
  }) {
    return SizedBox(
      width: width,
      child: TextField(
        key: Key('calendar-day-$keyName'),
        controller: controller,
        focusNode: focus,
        enabled: widget.enabled,
        keyboardType: TextInputType.number,
        inputFormatters: [
          FilteringTextInputFormatter.digitsOnly,
          LengthLimitingTextInputFormatter(2),
        ],
        decoration: InputDecoration(
          hintText: hint,
          labelText: label,
          isDense: true,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 10,
            vertical: 12,
          ),
        ),
        onChanged: onChanged,
      ),
    );
  }
}
