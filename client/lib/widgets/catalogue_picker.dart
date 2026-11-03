import '../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'tag.dart';

/// One choosable thing — an event to listen for, or an action to run.
class PickerItem {
  const PickerItem({
    required this.key,
    required this.label,
    this.description,
    this.enabled = true,
    this.badge,
    this.note,
  });

  final String key;
  final String label;
  final String? description;

  /// False draws the row and refuses the tap. Used for actions the engine
  /// cannot run yet: they are part of what this system is going to be, and
  /// hiding them answers nobody's question about why an obvious action is
  /// missing. See [badge] — a disabled row without one is just a bug.
  final bool enabled;

  /// The word that says WHY a row looks different — "Not built yet".
  final String? badge;

  /// One measured line under the description, such as "23 times in the last
  /// 30 days". Null draws nothing: a missing number is not a zero.
  final String? note;
}

/// A named set of items — a module, or the group of actions that work anywhere.
class PickerGroup {
  const PickerGroup({
    required this.key,
    required this.label,
    required this.items,
  });

  final String key;
  final String label;
  final List<PickerItem> items;
}

/// Choosing one thing out of a few dozen, grouped, with each one explained.
///
/// ── Why two panes rather than a row of tabs ──────────────────────
///
/// The owner asked for the actions and events to be "separated by category
/// (tab)", against a flat dropdown of twenty raw keys that was already
/// unusable. Tabs would do it. A column of categories beside the events does
/// the same job better at this size, for two reasons:
///
///   - The categories stay on screen while you read the events. With tabs, the
///     answer to "what else is there" is hidden behind the thing you are
///     currently looking at, so people click through all six to find out.
///   - It grows the right way. Six categories fit a tab row; fifteen do not,
///     and a horizontally scrolling tab row hides options off the edge of the
///     one axis a wide screen has plenty of. A column just gets taller.
///
/// Below roughly 640px there is no room for a column, and it becomes the chip
/// row the tab idea describes — same content, the shape that fits.
///
/// The search box is there because a category rail only helps somebody who is
/// browsing. Anyone who arrives already knowing they want "completed" should
/// not have to guess which module owns it, so typing filters every group at
/// once and the rail reports what is left in each.
class CataloguePicker extends StatefulWidget {
  const CataloguePicker({
    super.key,
    required this.groups,
    required this.selected,
    required this.onSelected,
    required this.searchHint,
    this.height = 300,
    this.cards = false,
  });

  final List<PickerGroup> groups;

  /// The key of the chosen item, or null while nothing is chosen.
  final String? selected;

  final ValueChanged<String> onSelected;
  final String searchHint;

  /// Fixed, so the picker scrolls internally instead of pushing the rest of the
  /// form off the page. A chooser that makes you scroll the whole screen to see
  /// its options is the dropdown problem again in a taller costume.
  final double height;

  /// Draws the chosen category's items as cards across the width, rather than
  /// as rows. For a picker that has a screen to itself — the When step of the
  /// automation builder — where a row of text a thousand pixels wide is
  /// harder to scan than cards side by side.
  final bool cards;

  @override
  State<CataloguePicker> createState() => _CataloguePickerState();
}

class _CataloguePickerState extends State<CataloguePicker> {
  final _search = TextEditingController();

  /// Which category the reader has clicked. Not necessarily the one being
  /// shown — see [_showing], which drops it when a search empties it out.
  String? _group;

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String get _query => _search.text.trim().toLowerCase();

  bool _matches(PickerItem item) {
    if (_query.isEmpty) return true;

    return item.label.toLowerCase().contains(_query) ||
        item.key.toLowerCase().contains(_query) ||
        (item.description ?? '').toLowerCase().contains(_query);
  }

  List<PickerItem> _matching(PickerGroup group) =>
      group.items.where(_matches).toList();

  /// The category actually on screen.
  ///
  /// Derived rather than stored, because the honest answer changes as somebody
  /// types: a search that empties the selected category should show them the
  /// results they can see, not an empty pane beside a rail full of counts. The
  /// group they clicked is remembered in [_group] and comes back when the
  /// search box is cleared.
  PickerGroup? get _showing {
    final chosen = widget.selected;

    for (final group in widget.groups) {
      final isChosen =
          group.key == _group ||
          (_group == null &&
              chosen != null &&
              group.items.any((i) => i.key == chosen));

      if (isChosen && _matching(group).isNotEmpty) return group;
    }

    for (final group in widget.groups) {
      if (_matching(group).isNotEmpty) return group;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final showing = _showing;
    final total = widget.groups.fold<int>(0, (n, g) => n + g.items.length);
    final found = widget.groups.fold<int>(0, (n, g) => n + _matching(g).length);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          key: const Key('catalogue-picker-search'),
          controller: _search,
          hint: widget.searchHint,
          onClear: _search.clear,
        ),
        if (_query.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            found == 0
                ? 'Nothing matches “${_search.text.trim()}”.'
                : '$found of $total match “${_search.text.trim()}”.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 10),
        SizedBox(
          height: widget.height,
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: Theme.of(context).dividerColor),
              borderRadius: BorderRadius.circular(10),
            ),
            child: LayoutBuilder(
              builder: (context, constraints) {
                // One measurement decides both halves. Asking MediaQuery inside
                // the rail instead would let the window and the box disagree —
                // a chip row squeezed into a 210px column is what that looks
                // like.
                final wide = constraints.maxWidth >= 640;
                final items = _items(context, showing);

                if (!wide) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      SizedBox(
                        height: 52,
                        child: _rail(context, showing, wide),
                      ),
                      const Divider(height: 1),
                      Expanded(child: items),
                    ],
                  );
                }

                return Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(width: 210, child: _rail(context, showing, wide)),
                    const VerticalDivider(width: 1),
                    Expanded(child: items),
                  ],
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  /// The categories. A column when there is room for one, a chip row when there
  /// is not — the narrow shape is the tab row the wide one improves on.
  Widget _rail(BuildContext context, PickerGroup? showing, bool wide) {
    final entries = [
      for (final group in widget.groups)
        (group, _matching(group).length, group.key == showing?.key),
    ];

    if (!wide) {
      return ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        children: [
          for (final (group, count, selected) in entries)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text('${group.label}  $count'),
                selected: selected,
                onSelected: count == 0
                    ? null
                    : (_) => setState(() => _group = group.key),
              ),
            ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 6),
      children: [
        for (final (group, count, selected) in entries)
          ListTile(
            dense: true,
            selected: selected,
            enabled: count > 0,
            title: Text(group.label, overflow: TextOverflow.ellipsis),
            trailing: Text(
              '$count',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            onTap: () => setState(() => _group = group.key),
          ),
      ],
    );
  }

  Widget _items(BuildContext context, PickerGroup? showing) {
    if (showing == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            _query.isEmpty
                ? 'There is nothing to choose from here yet.'
                : 'No match. Clear the search to see everything.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    final items = _matching(showing);

    if (widget.cards) return _cardGrid(context, items);

    return ListView.separated(
      padding: const EdgeInsets.symmetric(vertical: 4),
      itemCount: items.length,
      separatorBuilder: (_, _) => const Divider(height: 1, indent: 12),
      itemBuilder: (context, i) => _row(context, items[i]),
    );
  }

  /// Cards in rows of equal width, and every card in a row the same height:
  /// a Wrap would size each to its own text, and two choices side by side that
  /// are different sizes read as two different kinds of thing.
  Widget _cardGrid(BuildContext context, List<PickerItem> items) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = (constraints.maxWidth / 250).floor().clamp(1, 4);
        final rows = <List<PickerItem>>[
          for (var i = 0; i < items.length; i += columns)
            items.sublist(i, (i + columns).clamp(0, items.length)),
        ];

        return ListView(
          padding: const EdgeInsets.all(10),
          children: [
            for (final row in rows)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (var c = 0; c < columns; c++) ...[
                        if (c > 0) const SizedBox(width: 10),
                        Expanded(
                          child: c < row.length
                              ? _card(context, row[c])
                              : const SizedBox.shrink(),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _card(BuildContext context, PickerItem item) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final chosen = item.key == widget.selected;

    return Material(
      color: chosen ? scheme.primaryContainer : scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: chosen ? scheme.primary : scheme.outlineVariant,
          width: chosen ? 2 : 1,
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: item.enabled ? () => widget.onSelected(item.key) : null,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      item.label,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: item.enabled
                            ? (chosen ? scheme.onPrimaryContainer : null)
                            : scheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                  if (chosen)
                    Icon(Icons.check_circle, size: 18, color: scheme.primary),
                ],
              ),
              if (item.badge != null) ...[
                const SizedBox(height: 4),
                Tag(item.badge!, warn: !item.enabled),
              ],
              if (item.description != null) ...[
                const SizedBox(height: 4),
                Text(
                  item.description!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
              if (item.note != null) ...[
                const SizedBox(height: 6),
                Text(
                  item.note!,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: scheme.primary,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _row(BuildContext context, PickerItem item) {
    final scheme = Theme.of(context).colorScheme;
    final chosen = item.key == widget.selected;

    final title = Row(
      children: [
        Flexible(
          child: Text(
            item.label,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontWeight: chosen ? FontWeight.w600 : FontWeight.w500,
              color: item.enabled ? null : scheme.onSurfaceVariant,
            ),
          ),
        ),
        if (item.badge != null) Tag(item.badge!, warn: !item.enabled),
      ],
    );

    return ListTile(
      dense: true,
      enabled: item.enabled,
      selected: chosen,
      leading: Icon(
        item.enabled
            ? (chosen ? Icons.check_circle : Icons.radio_button_unchecked)
            : Icons.do_not_disturb_on_outlined,
        size: 20,
        color: chosen ? scheme.primary : scheme.onSurfaceVariant,
      ),
      title: title,
      subtitle: item.description == null
          ? null
          : Text(item.description!, style: const TextStyle(fontSize: 12)),
      onTap: item.enabled ? () => widget.onSelected(item.key) : null,
    );
  }
}
