import 'package:flutter/material.dart';

import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';

/// The dates button, its arrows and the from-to panel, as Performance has
/// them (Jose, 2026-10-13: "i need specific date picker as well like all the
/// others"), for the report tabs that only had preset chips.
class ReportDates extends StatefulWidget {
  const ReportDates({
    super.key,
    required this.initial,
    required this.today,
    required this.formats,
    required this.onChanged,
  });

  final DateSpan initial;
  final DateTime today;
  final DisplayFormats formats;
  final ValueChanged<DateSpan> onChanged;

  @override
  State<ReportDates> createState() => _ReportDatesState();
}

class _ReportDatesState extends State<ReportDates> {
  late DateSpan _span = widget.initial;
  bool _picking = false;

  void _set(DateSpan span) {
    setState(() {
      _span = span;
      _picking = false;
    });
    widget.onChanged(span);
  }

  /// One length back or forward; a whole calendar month steps by months.
  /// Never past today. The same rule as Performance.
  void _move(int step) {
    final span = _span;
    final today = widget.today;
    final wholeMonth =
        span.from.day == 1 &&
        span.from.month == span.to.month &&
        (span.to == DateTime(span.to.year, span.to.month + 1, 0) ||
            span.to == today);

    var next = wholeMonth
        ? DateSpan(
            DateTime(span.from.year, span.from.month + step, 1),
            DateTime(span.from.year, span.from.month + step + 1, 0),
          )
        : span.shift(step);
    if (next.from.isAfter(today)) return;
    if (next.to.isAfter(today)) next = DateSpan(next.from, today);
    _set(next);
  }

  /// The narrow panel fills the height it is given, and these tabs hold it
  /// in a scrolling list, which gives none: Marketing, Meta and Thumbtack
  /// drew nothing at all on a phone once the dates were opened (UI audit,
  /// 2026-10-13). Most of a phone's height, then, and it scrolls inside.
  static Widget _bounded(BuildContext context, Widget panel) =>
      MediaQuery.sizeOf(context).width < 600
      ? SizedBox(height: MediaQuery.sizeOf(context).height * 0.7, child: panel)
      : panel;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DateRangeBar(
          span: _span,
          today: widget.today,
          formats: widget.formats,
          open: _picking,
          onToggle: () => setState(() => _picking = !_picking),
          onPrevious: () => _move(-1),
          onNext: _span.to.isBefore(widget.today) ? () => _move(1) : null,
        ),
        if (_picking) ...[
          const SizedBox(height: 8),
          _bounded(
            context,
            DateRangePanel(
              initial: _span,
              today: widget.today,
              formats: widget.formats,
              narrow: MediaQuery.sizeOf(context).width < 600,
              onApply: _set,
              onCancel: () => setState(() => _picking = false),
            ),
          ),
        ],
      ],
    );
  }
}
