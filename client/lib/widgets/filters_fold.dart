import 'package:flutter/material.dart';

/// Mobile review 2026-11-13, P10: on a phone the filters filled the first
/// screen (Fleet's six chip rows, Re-Scheduling's pickers) and the first
/// record sat below the fold. Below [foldBelow] the filters fold behind one
/// "Filters · n" button; wider, they draw as they are.
class FiltersFold extends StatefulWidget {
  const FiltersFold({
    super.key,
    required this.count,
    required this.child,
    this.width,
    this.padding = const EdgeInsets.fromLTRB(16, 0, 16, 8),
  });

  /// How many of the folded filters are set; shown on the button.
  final int count;
  final Widget child;

  /// The room the list has. Null: the window's width.
  final double? width;
  final EdgeInsets padding;

  static const foldBelow = 600.0;

  @override
  State<FiltersFold> createState() => _FiltersFoldState();
}

class _FiltersFoldState extends State<FiltersFold> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final width = widget.width ?? MediaQuery.sizeOf(context).width;
    if (width >= FiltersFold.foldBelow) return widget.child;
    final n = widget.count;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Padding(
          padding: widget.padding,
          child: Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              key: const Key('filters-fold'),
              onPressed: () => setState(() => _open = !_open),
              icon: Icon(_open ? Icons.expand_less : Icons.tune, size: 18),
              label: Text(n == 0 ? 'Filters' : 'Filters · $n'),
            ),
          ),
        ),
        if (_open) widget.child,
      ],
    );
  }
}
