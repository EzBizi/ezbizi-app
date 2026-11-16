import 'package:flutter/material.dart';

/// One month, two clicks: the first day picked starts the range, the second
/// ends it (Jose, 2026-11-16: the timeline cell showed two half-width
/// calendars with digits stacked). Days are yyyy-mm-dd strings, as stored.
class RangeCalendar extends StatefulWidget {
  const RangeCalendar({
    super.key,
    required this.from,
    required this.to,
    this.onChanged,
  });

  final String? from;
  final String? to;

  /// (from, to); both null when cleared.
  final void Function(String? from, String? to)? onChanged;

  @override
  State<RangeCalendar> createState() => _RangeCalendarState();
}

DateTime? _day(String? s) => s == null ? null : DateTime.tryParse(s);
String _iso(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

class _RangeCalendarState extends State<RangeCalendar> {
  late DateTime? _from = _day(widget.from);
  late DateTime? _to = _day(widget.to);
  late DateTime _month = DateTime(
    (_from ?? DateTime.now()).year,
    (_from ?? DateTime.now()).month,
  );

  /// True after the first click, until the second.
  bool _picking = false;

  void _tap(DateTime d) {
    if (!_picking || _from == null || d.isBefore(_from!)) {
      setState(() {
        _from = d;
        _to = d;
        _picking = true;
      });
    } else {
      setState(() {
        _to = d;
        _picking = false;
      });
    }
    widget.onChanged?.call(_iso(_from!), _iso(_to!));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    const names = [
      'January',
      'February',
      'March',
      'April',
      'May',
      'June',
      'July',
      'August',
      'September',
      'October',
      'November',
      'December',
    ];
    final first = DateTime(_month.year, _month.month);
    final lead = first.weekday % 7; // Sunday first.
    final days = DateTime(_month.year, _month.month + 1, 0).day;
    final today = DateUtils.dateOnly(DateTime.now());

    Widget cell(int i) {
      final n = i - lead + 1;
      if (n < 1 || n > days) return const SizedBox.shrink();
      final d = DateTime(_month.year, _month.month, n);
      final end = d == _from || d == _to;
      final inside =
          _from != null &&
          _to != null &&
          !d.isBefore(_from!) &&
          !d.isAfter(_to!);
      return InkWell(
        key: ValueKey('range-day-${_iso(d)}'),
        onTap: widget.onChanged == null ? null : () => _tap(d),
        borderRadius: BorderRadius.circular(6),
        child: Container(
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: end
                ? scheme.primary
                : inside
                ? scheme.primaryContainer
                : null,
            borderRadius: BorderRadius.circular(6),
            border: d == today && !end
                ? Border.all(color: scheme.primary)
                : null,
          ),
          child: Text(
            '$n',
            style: TextStyle(
              fontSize: 13,
              color: end
                  ? scheme.onPrimary
                  : inside
                  ? scheme.onPrimaryContainer
                  : null,
            ),
          ),
        ),
      );
    }

    return SizedBox(
      width: 280,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${names[_month.month - 1]} ${_month.year}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              IconButton(
                key: const ValueKey('range-prev'),
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.chevron_left),
                onPressed: () => setState(
                  () => _month = DateTime(_month.year, _month.month - 1),
                ),
              ),
              IconButton(
                key: const ValueKey('range-next'),
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.chevron_right),
                onPressed: () => setState(
                  () => _month = DateTime(_month.year, _month.month + 1),
                ),
              ),
            ],
          ),
          Row(
            children: [
              for (final w in ['S', 'M', 'T', 'W', 'T', 'F', 'S'])
                Expanded(
                  child: Center(
                    child: Text(
                      w,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          GridView.count(
            crossAxisCount: 7,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 2,
            crossAxisSpacing: 2,
            children: [for (var i = 0; i < lead + days; i++) cell(i)],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Text(
                  _picking ? 'Now pick the last day' : 'Pick the first day',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),
              if (_from != null && widget.onChanged != null)
                TextButton(
                  key: const ValueKey('range-clear'),
                  onPressed: () {
                    setState(() {
                      _from = _to = null;
                      _picking = false;
                    });
                    widget.onChanged?.call(null, null);
                  },
                  child: const Text('Clear'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
