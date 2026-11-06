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

import 'side_panel.dart';

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
    this.contentPadding,
    this.allowClear = false,
    this.searchFrom = 8,
    this.create,
    this.trailing,
  });

  /// Drawn before the arrow inside the field, e.g. a copy button.
  final Widget? trailing;

  final T? value;
  final List<SearchOption<T>> options;

  /// Free entry: a word typed that no option carries is offered as
  /// "Add 'typed'", and this turns it into the value chosen. With it the
  /// search field is always shown, since typing is how a new word arrives.
  final T Function(String typed)? create;

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

  /// Overrides the theme's padding, e.g. to match a toolbar's height.
  final EdgeInsetsGeometry? contentPadding;

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
          // A dense picker sits in a filter row: 44 high, the ListSearch
          // height beside it (UI review 2026-11-02 #3).
          contentPadding:
              contentPadding ??
              (isDense
                  ? const EdgeInsets.symmetric(horizontal: 12, vertical: 11.5)
                  : null),
          enabled: enabled,
          // A dropdown's arrow, not a magnifier: the field opens a list to
          // pick from, and a magnifier made every one of them read as a
          // search box (UI review, 2026-10-08). The search is inside the list
          // once it is long enough to need one.
          // Material's 48-point floor on a suffix icon kept every dense
          // picker 48 high beside a 44 search.
          suffixIconConstraints: isDense
              ? const BoxConstraints(minWidth: 40, minHeight: 24)
              : null,
          suffixIcon: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              ?trailing,
              Icon(
                Icons.arrow_drop_down,
                color: enabled ? scheme.onSurfaceVariant : theme.disabledColor,
              ),
              if (trailing != null) const SizedBox(width: 8),
            ],
          ),
        ),
        // Empty only when there is no hint either. An empty decorator draws
        // its label INSIDE the field as a placeholder, which is exactly where
        // the hint is drawn, so a field with both showed "Who can see this
        // video" over "Choose" (API Integrations, 2026-10-05). With a hint the
        // label floats above it, as a text field's does.
        isEmpty: chosen == null && (hint ?? '').isEmpty,
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
    final picked = await showSidePanel<_Picked<T>>(
      context,
      builder: (context) => _SearchPickerDialog<T>(
        title: label,
        options: options,
        value: value,
        allowClear: allowClear,
        searchFrom: searchFrom,
        create: create,
      ),
    );

    // A dismissed dialog returns null and must change nothing. Only a real
    // choice comes back wrapped, so "chose nothing" and "chose the clear row"
    // stay different answers.
    if (picked != null) onChanged?.call(picked.value);
  }
}

/// The picker opened from any tap target, for a choice that is not a form
/// field - a status pill in a table row, for one. Null when dismissed.
Future<T?> showSearchPicker<T>(
  BuildContext context, {
  String? title,
  required List<SearchOption<T>> options,
  T? value,
  int searchFrom = 8,
  T Function(String typed)? create,
}) async {
  final picked = await showSidePanel<_Picked<T>>(
    context,
    builder: (context) => _SearchPickerDialog<T>(
      title: title,
      options: options,
      value: value,
      allowClear: false,
      searchFrom: searchFrom,
      create: create,
    ),
  );

  return picked?.value;
}

/// One choosable row.
///
/// ── Why this is not a bare ListTile any more ───────────────────────
///
/// Jose, 2026-09-24, on the WhatsApp template picker: *"stuff like this is
/// unacceptable, make it look better"* — five approved templates, each one's
/// entire body wrapped over four or five lines with nothing between them, so
/// the dialog read as one unbroken wall of `{{1}}` and prose.
///
/// Two causes and the control owns one of them. The call site was passing
/// `'$name — $body'` as the LABEL, which is its own fix. But a `ListTile` with
/// an unbounded `Text` will wrap any label to any height, so the next caller
/// with a long string gets the same wall — **the closed field has ellipsised
/// since the day it was written and the dialog never did.**
///
/// So: the name gets one line, the detail gets two, and rows are ruled apart.
/// A picker cannot stop somebody passing a paragraph, but it can stop a
/// paragraph from eating the list.
class _Row<T> extends StatelessWidget {
  const _Row({required this.option, required this.chosen, required this.onTap});

  final SearchOption<T> option;
  final bool chosen;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final detail = option.detail;

    return InkWell(
      onTap: option.enabled ? onTap : null,
      child: Container(
        decoration: BoxDecoration(
          color: chosen ? scheme.primary.withValues(alpha: 0.07) : null,
          border: Border(
            bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
          ),
        ),
        padding: const EdgeInsets.fromLTRB(20, 10, 16, 10),
        child: Row(
          children: [
            Expanded(
              child:
                  option.child ??
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        option.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                          color: option.enabled
                              ? scheme.onSurface
                              : scheme.onSurfaceVariant,
                        ),
                      ),
                      if (detail != null && detail.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(
                          detail,
                          // TWO, not one. A template body or an address needs a
                          // second line to be recognisable, and needs a limit
                          // to stay a row rather than a paragraph.
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ],
                  ),
            ),
            if (chosen) ...[
              const SizedBox(width: 10),
              Icon(Icons.check, size: 18, color: scheme.primary),
            ],
          ],
        ),
      ),
    );
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
    this.create,
  });

  final String? title;
  final List<SearchOption<T>> options;
  final T? value;
  final bool allowClear;
  final int searchFrom;
  final T Function(String typed)? create;

  @override
  State<_SearchPickerDialog<T>> createState() => _SearchPickerDialogState<T>();
}

class _SearchPickerDialogState<T> extends State<_SearchPickerDialog<T>> {
  String _term = '';

  bool get _searchable =>
      widget.create != null || widget.options.length >= widget.searchFrom;

  /// The typed word, when it is new: no option is called exactly that.
  String? get _new {
    final typed = _term.trim();
    if (widget.create == null || typed.isEmpty) return null;
    final lower = typed.toLowerCase();

    return widget.options.any((o) => o.label.toLowerCase() == lower)
        ? null
        : typed;
  }

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
    final fresh = _new;

    // A SidePanel, never a centred dialog (the standing rule; phone audit
    // 2026-11-04 found Role, Person and Company's pickers dimming the form).
    return SidePanel(
      title: widget.title ?? 'Choose',
      width: 420,
      scrollable: false,
      child: SizedBox(
        width: double.infinity,
        child: Column(
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
                  onSubmitted: fresh == null
                      ? null
                      : (_) => Navigator.of(
                          context,
                        ).pop(_Picked<T>(widget.create!(fresh))),
                ),
              ),
            Expanded(
              child: hits.isEmpty && fresh == null
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
                          _Row<T>(
                            option: o,
                            chosen: o.value == widget.value,
                            onTap: () =>
                                Navigator.of(context).pop(_Picked<T>(o.value)),
                          ),
                        if (fresh != null)
                          ListTile(
                            key: const Key('search-picker-add'),
                            dense: true,
                            leading: Icon(
                              Icons.add,
                              size: 18,
                              color: scheme.primary,
                            ),
                            title: Text(
                              'Add "$fresh"',
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w600,
                                color: scheme.primary,
                              ),
                            ),
                            onTap: () => Navigator.of(
                              context,
                            ).pop(_Picked<T>(widget.create!(fresh))),
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

/// Choosing several things from one list: a filter that matches any of them.
///
/// The field names what is chosen ("Chimney Sweep +1"), or [hint] when
/// nothing is, and opens the same searchable list with a tick per row.
/// Nothing changes until Done; Clear empties the choice in one go.
class SearchMultiPickerField<T> extends StatelessWidget {
  const SearchMultiPickerField({
    super.key,
    required this.values,
    required this.options,
    required this.onChanged,
    this.label,
    this.hint,
    this.searchFrom = 8,
  });

  final Set<T> values;
  final List<SearchOption<T>> options;
  final ValueChanged<Set<T>>? onChanged;
  final String? label;
  final String? hint;
  final int searchFrom;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final enabled = onChanged != null;
    final chosen = [
      for (final o in options)
        if (values.contains(o.value)) o.label,
    ];
    final text = chosen.isEmpty
        ? (hint ?? '')
        : chosen.length == 1
        ? chosen.first
        : '${chosen.first} +${chosen.length - 1}';

    return InkWell(
      onTap: enabled ? () => _open(context) : null,
      borderRadius: BorderRadius.circular(8),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          enabled: enabled,
          suffixIcon: Icon(
            Icons.arrow_drop_down,
            color: enabled ? scheme.onSurfaceVariant : theme.disabledColor,
          ),
        ),
        isEmpty: chosen.isEmpty && (hint ?? '').isEmpty,
        child: Text(
          text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: chosen.isEmpty ? scheme.onSurfaceVariant : null,
          ),
        ),
      ),
    );
  }

  Future<void> _open(BuildContext context) async {
    final picked = await showSidePanel<Set<T>>(
      context,
      builder: (context) => _SearchMultiDialog<T>(
        title: label,
        options: options,
        values: values,
        searchFrom: searchFrom,
      ),
    );

    if (picked != null) onChanged?.call(picked);
  }
}

class _SearchMultiDialog<T> extends StatefulWidget {
  const _SearchMultiDialog({
    required this.title,
    required this.options,
    required this.values,
    required this.searchFrom,
  });

  final String? title;
  final List<SearchOption<T>> options;
  final Set<T> values;
  final int searchFrom;

  @override
  State<_SearchMultiDialog<T>> createState() => _SearchMultiDialogState<T>();
}

class _SearchMultiDialogState<T> extends State<_SearchMultiDialog<T>> {
  String _term = '';
  late final Set<T> _chosen = {...widget.values};

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final needle = _term.trim().toLowerCase();
    final hits = [
      for (final o in widget.options)
        if (needle.isEmpty ||
            o.label.toLowerCase().contains(needle) ||
            (o.detail ?? '').toLowerCase().contains(needle))
          o,
    ];

    // A SidePanel, never a centred dialog (the standing rule; phone audit
    // 2026-11-04 found Role, Person and Company's pickers dimming the form).
    return SidePanel(
      title: widget.title ?? 'Choose',
      width: 420,
      scrollable: false,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(<T>{}),
          child: const Text('Clear'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(_chosen),
          child: const Text('Done'),
        ),
      ],
      child: SizedBox(
        width: double.infinity,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (widget.options.length >= widget.searchFrom)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: TextField(
                  autofocus: true,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Search',
                  ),
                  onChanged: (value) => setState(() => _term = value),
                ),
              ),
            Expanded(
              child: hits.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'Nothing matches that.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    )
                  : ListView(
                      shrinkWrap: true,
                      children: [
                        for (final o in hits)
                          CheckboxListTile(
                            key: ValueKey('search-multi-${o.label}'),
                            dense: true,
                            controlAffinity: ListTileControlAffinity.leading,
                            value: _chosen.contains(o.value),
                            title: Text(
                              o.label,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            onChanged: o.enabled
                                ? (on) => setState(() {
                                    final v = o.value as T;
                                    if (on == true) {
                                      _chosen.add(v);
                                    } else {
                                      _chosen.remove(v);
                                    }
                                  })
                                : null,
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

/// "Dana Reyes" -> "DR"; one name gives one letter, no name gives "?".
String initials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
  if (parts.isEmpty) return '?';
  final first = parts.first[0];
  final last = parts.length > 1 ? parts.last[0] : '';
  return '$first$last'.toUpperCase();
}
