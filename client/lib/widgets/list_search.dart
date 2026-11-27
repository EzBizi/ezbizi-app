import 'package:flutter/material.dart';

/// The one search box a list carries (UI review 2026-11-02 #4: three styles
/// for the same control — outlined, filled full-width, small filled).
///
/// Outlined, [height] 44 so it lines up with the pickers and chips beside it
/// (the filter-bar height Leads settled on in 6af32366a), about 280 wide on a
/// desktop and the full width on a phone.
class ListSearch extends StatelessWidget {
  const ListSearch({
    super.key,
    this.controller,
    required this.hint,
    this.onChanged,
    this.onSubmitted,
    this.width = 280,
    this.autofocus = false,
    this.onClear,
  });

  final TextEditingController? controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;

  /// The width at a desktop; below [phoneBreak] it takes all it is given.
  final double width;
  final bool autofocus;

  /// When set, a clear button shows while [controller] has text.
  final VoidCallback? onClear;

  static const height = 44.0;
  static const phoneBreak = 600.0;

  @override
  Widget build(BuildContext context) {
    final field = TextField(
      controller: controller,
      autofocus: autofocus,
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      textInputAction: TextInputAction.search,
      decoration: InputDecoration(
        isDense: true,
        filled: false,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 12,
          vertical: 12,
        ),
        prefixIcon: const Icon(Icons.search, size: 20),
        hintText: hint,
        suffixIcon: onClear == null || (controller?.text.isEmpty ?? true)
            ? null
            : IconButton(
                icon: const Icon(Icons.clear, size: 18),
                tooltip: 'Clear the search',
                onPressed: onClear,
              ),
      ),
    );
    final phone = MediaQuery.sizeOf(context).width < phoneBreak;
    return SizedBox(
      height: height,
      width: phone ? double.infinity : width,
      child: field,
    );
  }
}
