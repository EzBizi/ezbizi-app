import 'package:flutter/material.dart';

import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/search_picker.dart';

/// The dates Project Management's Dashboard and its Recently paid stage cover
/// (UI queue 127, option 1 and phone 4): Today, Yesterday, This week, This
/// month or Custom. Custom opens the shared [DateRangePanel] in the page under
/// the control, never over it.
@immutable
class PmRange {
  const PmRange(this.preset, [this.custom]);

  static const today = PmRange('today');

  /// `today`, `yesterday`, `this_week`, `this_month` or `custom`.
  final String preset;

  /// The chosen dates, for `custom` only.
  final DateSpan? custom;

  DateSpan span(DateTime now) {
    final day = DateSpan.single(now).from;
    if (preset == 'custom' && custom != null) return custom!;
    for (final p in RangePreset.standard) {
      if (p.key == preset) return p.span(day);
    }
    return DateSpan.single(day);
  }

  /// What the server is sent: `from` and `to`, both included.
  Map<String, String> query(DateTime now) {
    final s = span(now);
    return {'from': s.fromKey, 'to': s.toKey};
  }

  @override
  bool operator ==(Object other) =>
      other is PmRange && other.preset == preset && other.custom == custom;

  @override
  int get hashCode => Object.hash(preset, custom);
}

const _choices = [
  ('today', 'Today'),
  ('yesterday', 'Yesterday'),
  ('this_week', 'This week'),
  ('this_month', 'This month'),
  ('custom', 'Custom'),
];

/// The range control: a segment on a wide screen, a picker on a phone.
class PmRangeControl extends StatefulWidget {
  const PmRangeControl({
    super.key,
    required this.range,
    required this.now,
    required this.formats,
    required this.narrow,
    required this.onChanged,
  });

  final PmRange range;
  final DateTime now;
  final DisplayFormats formats;
  final bool narrow;
  final ValueChanged<PmRange> onChanged;

  @override
  State<PmRangeControl> createState() => _PmRangeControlState();
}

class _PmRangeControlState extends State<PmRangeControl> {
  bool _picking = false;

  void _choose(String preset) {
    if (preset == 'custom') {
      setState(() => _picking = true);
      return;
    }
    setState(() => _picking = false);
    if (preset != widget.range.preset) widget.onChanged(PmRange(preset));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = DateSpan.single(widget.now).from;
    final span = widget.range.span(widget.now);
    final words = spanWords(span, today, widget.formats);
    final selected = _picking ? 'custom' : widget.range.preset;

    final Widget control;
    if (widget.narrow) {
      control = SearchPickerField<String>(
        key: const ValueKey('pm-range'),
        value: selected,
        isDense: true,
        options: [
          for (final (value, label) in _choices)
            SearchOption(
              value: value,
              label: value == 'custom' && widget.range.preset == 'custom'
                  ? words
                  : label,
            ),
        ],
        onChanged: (v) {
          if (v != null) _choose(v);
        },
      );
    } else {
      control = Wrap(
        spacing: 12,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SegmentedButton<String>(
            key: const ValueKey('pm-range'),
            showSelectedIcon: false,
            segments: [
              for (final (value, label) in _choices)
                ButtonSegment(
                  value: value,
                  label: Text(label, key: ValueKey('pm-range-$value')),
                ),
            ],
            selected: {selected},
            onSelectionChanged: (s) => _choose(s.first),
          ),
          Text(
            words,
            key: const ValueKey('pm-range-words'),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      );
    }

    if (!_picking) return control;

    final panel = DateRangePanel(
      key: const ValueKey('pm-range-panel'),
      initial: span,
      today: today,
      formats: widget.formats,
      narrow: widget.narrow,
      onApply: (chosen) {
        setState(() => _picking = false);
        widget.onChanged(PmRange('custom', chosen));
      },
      onCancel: () => setState(() => _picking = false),
    );

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        control,
        const SizedBox(height: 8),
        // The narrow panel fills the height it is given, and a list gives none.
        if (widget.narrow)
          SizedBox(
            height: MediaQuery.sizeOf(context).height * 0.7,
            child: panel,
          )
        else
          panel,
      ],
    );
  }
}
