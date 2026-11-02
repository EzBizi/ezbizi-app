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
    this.inline = false,
    this.trailing = const [],
  });

  /// One row on a wide screen: quick buttons, then ‹ picker ›, then
  /// [trailing] pushed right (UI-UX review of d1a6760d1). On a phone the
  /// quick buttons sit above, with [trailing] at the end of the picker row.
  final bool inline;

  /// Shown at the end of the row: a tab's Sources link and refresh.
  final List<Widget> trailing;

  final DateSpan initial;
  final DateTime today;
  final DisplayFormats formats;
  final ValueChanged<DateSpan> onChanged;

  /// The quick ranges, keyed as before, worded as the picker words them
  /// (review 2026-10-30, 14: "1 month" beside "Last month" read as the same).
  /// Performance draws the same chips.
  static Map<String, DateSpan> quick(DateTime t) {
    DateTime back(int days) => DateTime(t.year, t.month, t.day - days);
    return {
      'Today': DateSpan.single(t),
      'Yesterday': DateSpan.single(back(1)),
      '1 week': DateSpan(back(6), t),
      '1 month': DateSpan(back(29), t),
    };
  }

  static const quickLabels = {
    '1 week': 'Last 7 days',
    '1 month': 'Last 30 days',
  };

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

  /// One tap for the common ranges (Jose, 2026-10-14: "add easy options to
  /// select 1 month - 1 week - yesterday - today, besides the custom").
  Map<String, DateSpan> get _quick => ReportDates.quick(widget.today);

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.inline)
          LayoutBuilder(
            builder: (context, box) {
              final quick = SingleChildScrollView(
                // One line on a phone too (UI-UX review): it scrolls sideways.
                scrollDirection: Axis.horizontal,
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final q in _quick.entries)
                      ChoiceChip(
                        key: ValueKey('report-dates-${q.key}'),
                        label: Text(ReportDates.quickLabels[q.key] ?? q.key),
                        selected: _span == q.value,
                        showCheckmark: false,
                        onSelected: (_) => _set(q.value),
                      ),
                  ],
                ),
              );
              final dates = DateRangeBar(
                span: _span,
                today: widget.today,
                formats: widget.formats,
                open: _picking,
                onToggle: () => setState(() => _picking = !_picking),
                onPrevious: () => _move(-1),
                onNext: _span.to.isBefore(widget.today) ? () => _move(1) : null,
              );
              final phoneDates = DateRangeBar(
                expand: true,
                phone: true,
                span: _span,
                today: widget.today,
                formats: widget.formats,
                open: _picking,
                onToggle: () => setState(() => _picking = !_picking),
                onPrevious: () => _move(-1),
                onNext: _span.to.isBefore(widget.today) ? () => _move(1) : null,
              );
              if (box.maxWidth < 600) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    quick,
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(child: phoneDates),
                        ...widget.trailing,
                      ],
                    ),
                  ],
                );
              }
              return Row(
                children: [
                  Expanded(
                    child: Row(
                      children: [
                        Flexible(child: quick),
                        const SizedBox(width: 12),
                        dates,
                      ],
                    ),
                  ),
                  ...widget.trailing,
                ],
              );
            },
          )
        else ...[
          SingleChildScrollView(
            // One line on a phone too (UI-UX review): it scrolls sideways.
            scrollDirection: Axis.horizontal,
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final q in _quick.entries)
                  ChoiceChip(
                    key: ValueKey('report-dates-${q.key}'),
                    label: Text(ReportDates.quickLabels[q.key] ?? q.key),
                    selected: _span == q.value,
                    showCheckmark: false,
                    onSelected: (_) => _set(q.value),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          DateRangeBar(
            span: _span,
            today: widget.today,
            formats: widget.formats,
            open: _picking,
            onToggle: () => setState(() => _picking = !_picking),
            onPrevious: () => _move(-1),
            onNext: _span.to.isBefore(widget.today) ? () => _move(1) : null,
          ),
        ],
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

/// A report's filter: typed to narrow, "All" first; [reload] runs after
/// [pick].
Widget reportFilter(
  String label,
  String? value,
  Map<String, String> options,
  ValueChanged<String?> pick,
  Key key,
  VoidCallback reload,
) => DropdownMenu<String?>(
  key: key,
  enableFilter: true,
  requestFocusOnTap: true,
  label: Text(label),
  initialSelection: value,
  onSelected: (v) {
    pick(v);
    reload();
  },
  dropdownMenuEntries: [
    const DropdownMenuEntry(value: null, label: 'All'),
    for (final o in options.entries)
      DropdownMenuEntry(value: o.key, label: o.value),
  ],
);

/// A report's search box, the standard field height: as tall as Leads search
/// (Jose, 2026-10-27).
Widget reportSearch({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 240,
  child: TextField(
    key: key,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      prefixIcon: const Icon(Icons.search, size: 18),
      hintText: hint,
      border: const OutlineInputBorder(),
    ),
    onChanged: onChanged,
  ),
);
