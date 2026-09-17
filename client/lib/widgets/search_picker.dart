/// Choosing one thing from a list, without a popup you have to scan.
///
/// Jose, 2026-09-17, looking at a person dropdown twelve names long: *"this
/// sort of popup dropdown are terrible ui design, anything that offers users
/// should be a search bar picker instead. So i want you to go and find every
/// single use of this sort of dropdown in the app and replace it."*
///
/// ── The rule that makes "every single use" safe ────────────────────
///
/// There are **106 `DropdownButtonFormField`s across 53 files**, and they are
/// not one thing. Some offer twelve people; some offer *Everyone at once* or
/// *In order*. A search box over two options is worse than the dropdown it
/// replaced — it asks you to type where you could have pointed.
///
/// So this is one control that behaves like both: **the search field appears
/// when there are enough options to need one** ([searchFrom]) and stays away
/// when there are not. That makes replacing every dropdown the right move
/// uniformly, rather than a judgement call at 106 call sites where somebody
/// would get it wrong quietly.
///
/// ── Why it is not a Dropdown with a filter bolted on ───────────────
///
/// A `DropdownButtonFormField` renders its menu in an overlay sized to the
/// button, anchored to it, and it closes on any tap outside — which is why the
/// twelve-name list in his screenshot runs off the bottom of the window and
/// cannot be reached without scrolling inside a floating strip. The list needs
/// to be somewhere with room, so it opens a dialog rather than an overlay.
library;

import 'package:flutter/material.dart';

/// One choosable thing.
class SearchOption<T> {
  const SearchOption({
    required this.value,
    required this.label,
    this.detail,
    this.child,
    this.enabled = true,
  });

  /// Nullable, exactly as `DropdownMenuItem.value` is.
  ///
  /// Not an oversight carried over: several of these lists offer a row that
  /// MEANS nothing chosen -- "Not set", "Nobody has the keys" -- and it is a
  /// real option somebody picks, not the absence of one. Making this
  /// non-nullable would have forced ten call sites to cast a `String?` they
  /// had no reason to believe was non-null.
  final T? value;

  /// What it is called, and what search matches against.
  final String label;

  /// A second line — a number, a place, a role. Searched as well, because
  /// people look things up by whatever they remember about them.
  final String? detail;

  /// A richer row than a line of text -- an icon beside a name, two lines with
  /// different weights.
  ///
  /// **[label] is still required when this is set, and it is not decoration:**
  /// it is what search matches on. A row with an icon has no single string in
  /// it, so something has to say which words the list filters by, and a picker
  /// that filters on the wrong words looks perfectly correct while hiding the
  /// thing somebody typed the name of.
  final Widget? child;

  final bool enabled;
}

/// A field that looks like the form control beside it and opens a list you can
/// search.
class SearchPickerField<T> extends StatelessWidget {
  const SearchPickerField({
    super.key,
    required this.value,
    required this.options,
    required this.onChanged,
    this.label,
    this.hint,
    this.helperText,
    this.helperMaxLines,
    this.errorText,
    this.border,
    this.isDense = false,
    this.allowClear = false,
    this.searchFrom = 8,
  });

  final T? value;
  final List<SearchOption<T>> options;

  /// Null disables the field, the same way it disables a dropdown.
  final ValueChanged<T?>? onChanged;

  final String? label;

  /// What the field says when nothing is chosen.
  final String? hint;

  /// The sentence under the field. Carried because several of the dropdowns
  /// this replaces use it to explain what a choice MEANS -- "the whole group
  /// can see it" -- and dropping it while swapping the control would lose
  /// information nobody asked to lose.
  final String? helperText;
  final int? helperMaxLines;

  /// What is wrong with the value it currently holds.
  ///
  /// Carried because at least one of these fields uses it to NAME a stored
  /// value it cannot offer -- an automation rule pointing at a field the
  /// subject no longer has. The alternative is a box that silently went blank,
  /// which is the thing people file bugs about.
  final String? errorText;

  /// Carried through so a swapped field keeps the frame it had. Several of
  /// these sit in dense forms with an outline the rest of the row shares.
  final InputBorder? border;
  final bool isDense;

  /// Whether the list offers a way back to nothing chosen. Off by default,
  /// because most of these fields are required and a stray "None" row invites
  /// somebody to empty a thing they were asked to fill.
  final bool allowClear;

  /// How many options before a search field is worth having.
  ///
  /// Below it the list is short enough to point at, and a search box would ask
  /// somebody to type where they could have looked.
  final int searchFrom;

  SearchOption<T>? get _current {
    for (final o in options) {
      if (o.value == value) return o;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final chosen = _current;
    final enabled = onChanged != null;

    return InkWell(
      onTap: enabled ? () => _open(context) : null,
      borderRadius: BorderRadius.circular(8),
      child: InputDecorator(
        // The same decoration the dropdown had, so a form does not change
        // shape when a field is swapped.
        decoration: InputDecoration(
          labelText: label,
          helperText: helperText,
          helperMaxLines: helperMaxLines,
          errorText: errorText,
          border: border,
          isDense: isDense,
          enabled: enabled,
          suffixIcon: Icon(
            Icons.search,
            size: 18,
            color: scheme.onSurfaceVariant,
          ),
        ),
        isEmpty: chosen == null,
        child: chosen == null
            ? Text(
                hint ?? '',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              )
            : Text(
                chosen.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium,
              ),
      ),
    );
  }

  Future<void> _open(BuildContext context) async {
    final picked = await showDialog<_Picked<T>>(
      context: context,
      builder: (context) => _SearchPickerDialog<T>(
        title: label,
        options: options,
        value: value,
        allowClear: allowClear,
        searchFrom: searchFrom,
      ),
    );

    // A dismissed dialog returns null and must change nothing. Only a real
    // choice comes back wrapped, so "chose nothing" and "chose the clear row"
    // stay different answers.
    if (picked != null) onChanged?.call(picked.value);
  }
}

/// Wrapped so that clearing and dismissing are not the same null.
class _Picked<T> {
  const _Picked(this.value);

  final T? value;
}

class _SearchPickerDialog<T> extends StatefulWidget {
  const _SearchPickerDialog({
    required this.title,
    required this.options,
    required this.value,
    required this.allowClear,
    required this.searchFrom,
  });

  final String? title;
  final List<SearchOption<T>> options;
  final T? value;
  final bool allowClear;
  final int searchFrom;

  @override
  State<_SearchPickerDialog<T>> createState() => _SearchPickerDialogState<T>();
}

class _SearchPickerDialogState<T> extends State<_SearchPickerDialog<T>> {
  String _term = '';

  bool get _searchable => widget.options.length >= widget.searchFrom;

  List<SearchOption<T>> get _hits {
    final needle = _term.trim().toLowerCase();
    if (needle.isEmpty) return widget.options;

    return [
      for (final o in widget.options)
        if (o.label.toLowerCase().contains(needle) ||
            (o.detail ?? '').toLowerCase().contains(needle))
          o,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final hits = _hits;

    return AlertDialog(
      contentPadding: const EdgeInsets.fromLTRB(0, 12, 0, 0),
      title: widget.title == null ? null : Text(widget.title!),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_searchable)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: TextField(
                  autofocus: true,
                  decoration: InputDecoration(
                    isDense: true,
                    prefixIcon: const Icon(Icons.search, size: 18),
                    hintText: 'Search',
                    suffixText: hits.length == widget.options.length
                        ? null
                        : '${hits.length} of ${widget.options.length}',
                  ),
                  onChanged: (value) => setState(() => _term = value),
                ),
              ),
            Flexible(
              child: hits.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'Nothing matches that.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    )
                  : ListView(
                      shrinkWrap: true,
                      children: [
                        if (widget.allowClear && _term.trim().isEmpty)
                          ListTile(
                            dense: true,
                            title: Text(
                              'None',
                              style: theme.textTheme.bodyMedium?.copyWith(
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                            onTap: () =>
                                Navigator.of(context).pop(const _Picked(null)),
                          ),
                        for (final o in hits)
                          ListTile(
                            dense: true,
                            selected: o.value == widget.value,
                            enabled: o.enabled,
                            title: o.child ?? Text(o.label),
                            subtitle: o.detail == null || o.child != null
                                ? null
                                : Text(o.detail!),
                            onTap: () =>
                                Navigator.of(context).pop(_Picked<T>(o.value)),
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
