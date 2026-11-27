import 'package:flutter/widgets.dart';

import '../../widgets/date_range_picker.dart';

/// The one date range every Reports tab reads, set by the Reports screen
/// (Jose, 2026-11-16: switching tabs keeps it). A tab drawn by a child module
/// with dates of its own (Google Ads) reads and sets it through this; with no
/// scope above it, it keeps its own.
class ReportRange extends InheritedWidget {
  const ReportRange({
    super.key,
    required this.span,
    required this.onChanged,
    required super.child,
  });

  final DateSpan span;
  final ValueChanged<DateSpan> onChanged;

  static ReportRange? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<ReportRange>();

  @override
  bool updateShouldNotify(ReportRange old) => old.span != span;
}
