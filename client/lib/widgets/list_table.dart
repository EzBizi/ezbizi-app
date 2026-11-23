import 'package:flutter/gestures.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/theme.dart';
import 'heading_pin.dart';
import 'list_filters.dart';
import 'side_panel.dart';

/// Count tiles that filter a list, then the list as a table: the layout
/// Inventory uses, chosen for HR's Invitations, Onboarding and Contracts
/// (Mia, 2026-10-02, layout A).

/// One tile: a count, what it counts, and the filter it applies. A null [key]
/// is the tile for everything.
class FilterTile {
  const FilterTile({
    required this.key,
    required this.label,
    required this.count,
    this.note = '',
    this.tone,
  });

  final String? key;
  final String label;

  /// Null while the server has not said - drawn as a dash, never as 0.
  final int? count;
  final String note;
  final Tone? tone;
}

/// The tiles in a wrapping row. Tapping a picked tile clears it.
class FilterTiles extends StatelessWidget {
  const FilterTiles({
    super.key,
    required this.prefix,
    required this.tiles,
    required this.picked,
    required this.onPick,
    this.facts = const [],
  });

  /// Keys each tile `<prefix>-tile-<key>`, `all` for the null one.
  final String prefix;
  final List<FilterTile> tiles;
  final String? picked;
  final ValueChanged<String?> onPick;

  /// Tiles that state a number and filter nothing ([FactTile]), after [tiles]
  /// and at their width, so a fact never reads as a filter that does nothing.
  final List<Widget> facts;

  @override
  Widget build(BuildContext context) {
    // Mobile review 2026-11-13, P10 (preview §8 A): on a phone six tiles took
    // three rows before the first record (HR Invitations, Onboarding). There
    // they fold into one sideways row of pills, the same filters and counts.
    if (MediaQuery.sizeOf(context).width < 600 && facts.isEmpty) {
      return FilterPills<String?>(
        key: Key('$prefix-tiles-folded'),
        padding: EdgeInsets.zero,
        selected: picked,
        onSelected: (key) => onPick(key == picked ? null : key),
        items: [
          for (final tile in tiles)
            FilterPill(
              tile.key,
              tile.label,
              count: tile.count,
              tone: (tile.count ?? 0) == 0 ? null : tile.tone,
              key: Key('$prefix-tile-${tile.key ?? 'all'}'),
            ),
        ],
      );
    }
    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        final across = (box.maxWidth / 170).floor().clamp(
          2,
          tiles.length + facts.length,
        );
        final width = (box.maxWidth - gap * (across - 1)) / across;

        // Rows of equal-height tiles rather than a Wrap (desktop review
        // 2026-11-13, D4): a label or note may take two lines now instead of
        // being cut ("plans and their averag…", "cannot be dialled insi…"),
        // and a tile beside a two-line one stretches to match it.
        final all = [for (final tile in tiles) _tile(context, tile), ...facts];
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var at = 0; at < all.length; at += across)
              Padding(
                padding: EdgeInsets.only(
                  bottom: at + across < all.length ? gap : 0,
                ),
                child: IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (var i = at; i < at + across && i < all.length; i++)
                        Padding(
                          padding: EdgeInsets.only(
                            right: i < at + across - 1 ? gap : 0,
                          ),
                          child: SizedBox(width: width, child: all[i]),
                        ),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _tile(BuildContext context, FilterTile tile) {
    final theme = Theme.of(context);
    final chosen = picked == tile.key;
    final ink = tile.tone == null || (tile.count ?? 0) == 0
        ? null
        : ToneColours.of(context, tile.tone!).ink;
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return Material(
      key: Key('$prefix-tile-${tile.key ?? 'all'}'),
      color: chosen
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(
          color: chosen ? theme.colorScheme.primary : EzBiziTheme.edge(context),
        ),
      ),
      child: InkWell(
        borderRadius: radius,
        onTap: () => onPick(chosen ? null : tile.key),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                tile.label.toUpperCase(),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  letterSpacing: 0.8,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                tile.count == null ? '—' : '${tile.count}',
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: ink,
                ),
              ),
              Text(
                tile.note,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A tile that states a number and filters nothing: [FilterTiles]' look, with
/// no tap. The Newsletter's dashboard and Social's (UI queue 76) both say
/// facts beside their filters, and two private copies would drift apart.
class FactTile extends StatelessWidget {
  const FactTile({
    super.key,
    required this.label,
    required this.value,
    required this.note,
  });

  final String label;
  final String value;
  final String note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // 11 and not the filter tiles' 12: a Container's border is laid out inside
    // its size, where a Material's outline is not, and a fact sat 2 px taller
    // than the filters beside it.
    return Container(
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall?.copyWith(
              letterSpacing: 0.8,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.headlineSmall?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          Text(
            note,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

/// A column: its heading and how much of the row it takes.
class ListColumn {
  const ListColumn(
    this.label, {
    this.flex = 2,
    this.end = false,
    this.center = false,
    this.namedWhenStacked = true,
    this.onSort,
    this.sorted,
    this.frame,
  });

  final String label;

  /// Wraps the drawn heading, e.g. to drag it to another place (Project
  /// Management, Jose 2026-11-17: "Just let me drag them").
  final Widget Function(Widget heading)? frame;

  /// Given, the header is a button that sorts by this column (Jose,
  /// 2026-11-10: "clicking the column orders it"). The list does the
  /// sorting; this only asks.
  final VoidCallback? onSort;

  /// This column is the sort: true descending, false ascending; null not
  /// sorted by it. Drawn as an arrow beside the label.
  final bool? sorted;

  /// Whether a stacked row prefixes this cell with [label]. Off where the
  /// value says what it is and the words would push the row to a third line.
  final bool namedWhenStacked;
  final int flex;

  /// Right-aligned, for numbers and the actions column.
  final bool end;

  /// Centred under its heading (Jose, 2026-11-12: PM's money columns).
  final bool center;
}

/// Rows under headings, columns lined up. Narrower than [stackBelow], each row
/// becomes its first cell over the rest, so nothing is squeezed to a sliver.
class ListTable extends StatelessWidget {
  const ListTable({
    super.key,
    required this.columns,
    required this.rows,
    this.onTap,
    this.rowKey,
    this.footer,
    this.height,
    this.widths = const {},
    this.onResize,
    this.stickyFirstColumn = false,
    this.groupBy,
    this.rowTone,
    this.groupMemory,
    this.selectable = false,
    this.bulkActions = const [],
  });

  final List<ListColumn> columns;

  /// Given, rows sit under a header per group, in the order each group first
  /// appears, and a header click collapses its group (monday port batch 2).
  /// Ignored once the table scrolls sideways.
  final ListGroup Function(int index)? groupBy;

  /// Each row's label colour, for the split bar a collapsed group still shows.
  final Tone? Function(int index)? rowTone;

  /// Names the list so its collapsed groups survive leaving the screen, for
  /// this session only. Null: forgotten with the widget.
  final String? groupMemory;
  static final Map<String, Set<String>> _collapsedByList = {};

  /// Row checkboxes, a select-all in the header and, once anything is ticked,
  /// a bar under the rows with [bulkActions].
  final bool selectable;
  final List<BulkAction> bulkActions;

  /// One widget per column, in order.
  final List<List<Widget>> rows;
  final void Function(int index)? onTap;
  final Key Function(int index)? rowKey;

  /// Under the last row: a "load more", for instance.
  final Widget? footer;

  /// Given, the rows scroll inside this height under a header that stays put
  /// (monday port, desk 2026-11-09). Null: the table is as tall as its rows
  /// and the page scrolls it, as before.
  final double? height;

  /// Column index => pixels, for columns somebody dragged. Others keep flex.
  final Map<int, double> widths;

  /// Given, each heading gets a drag edge; the caller saves the width
  /// (see Me.listColumnWidths and the `lists.column_widths` preference).
  final void Function(int column, double width)? onResize;

  /// Keeps the first column in place while the rest scroll sideways, once
  /// the dragged widths no longer fit. Rows are then one line, [rowHeight].
  // ponytail: fixed row height so the two halves line up; measure rows if a
  // sticky table ever needs wrapped cells.
  final bool stickyFirstColumn;
  static const double rowHeight = 52;
  static const double flexUnit = 60;
  static const double stackBelow = 720;

  @override
  Widget build(BuildContext context) => _ListTableBody(table: this);

  Widget _render(BuildContext context, _ListTableBodyState s) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= stackBelow;

        // Wider than the window once dragged: lay columns at fixed widths and
        // scroll sideways.
        final natural = [
          for (var i = 0; i < columns.length; i++)
            widths[i] ?? columns[i].flex * flexUnit,
        ];
        // Also undragged: squeezed flex cells clipped the last column with
        // no way to reach it (Jose, 2026-11-12, 0.0.0565).
        final scrolls =
            wide &&
            natural.fold<double>(0, (a, b) => a + b) + 28 > box.maxWidth;

        Widget sized(int i, Widget child) => scrolls || widths[i] != null
            ? SizedBox(width: natural[i], child: child)
            : Expanded(flex: columns[i].flex, child: child);

        Widget cell(int i, Widget child) => sized(
          i,
          // A gutter between columns, so a right-aligned amount never
          // runs into the left-aligned column after it.
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Align(
              alignment: columns[i].center
                  ? Alignment.center
                  : columns[i].end
                  ? Alignment.centerRight
                  : Alignment.centerLeft,
              child: child,
            ),
          ),
        );

        Widget rowOf(int r, int from, int to) {
          final cells = rows[r];
          final tick = selectable && from == 0
              ? SizedBox(
                  width: 40,
                  child: Checkbox(
                    key: Key('list-select-$r'),
                    value: s.selected.contains(r),
                    onChanged: (_) => s.toggle(r),
                  ),
                )
              : null;
          Widget body = wide
              ? Row(
                  children: [
                    ?tick,
                    for (var i = from; i < to && i < cells.length; i++)
                      cell(i, cells[i]),
                  ],
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    cells.first,
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 12,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      // Each value says which column it is: flattened bare,
                      // PM-2 rows read "11/4/2026 11/4/2026 $0.00 $1,249.00"
                      // with no way to tell them apart (phone audit
                      // 2026-11-04, Section 4 #3).
                      children: [
                        for (var i = 1; i < cells.length; i++)
                          columns[i].label.isEmpty ||
                                  !columns[i].namedWhenStacked
                              ? cells[i]
                              : Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text(
                                      '${columns[i].label} ',
                                      style: theme.textTheme.bodySmall
                                          ?.copyWith(
                                            color: theme
                                                .colorScheme
                                                .onSurfaceVariant,
                                          ),
                                    ),
                                    Flexible(child: cells[i]),
                                  ],
                                ),
                      ],
                    ),
                  ],
                );
          if (!wide && tick != null) {
            body = Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                tick,
                Expanded(child: body),
              ],
            );
          }

          return Material(
            key: rowKey?.call(r),
            color: Colors.transparent,
            child: InkWell(
              onTap: onTap == null ? null : () => onTap!(r),
              child: Container(
                // Only a sticky split needs equal heights; otherwise a row
                // grows with its content at any column width (Jose,
                // 2026-11-12: "if a column is too compressed row should grow").
                constraints: scrolls && stickyFirstColumn
                    ? const BoxConstraints.tightFor(height: rowHeight)
                    : const BoxConstraints(minHeight: rowHeight),
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(color: EzBiziTheme.edge(context)),
                  ),
                ),
                child: body,
              ),
            ),
          );
        }

        Widget row(int r) => rowOf(r, 0, columns.length);

        Row headerRow(int from, int to) => Row(
          children: [
            if (selectable && from == 0)
              SizedBox(
                width: 40,
                child: Checkbox(
                  key: const Key('list-select-all'),
                  tristate: true,
                  value: s.selected.isEmpty
                      ? false
                      : s.selected.length == rows.length
                      ? true
                      : null,
                  onChanged: (_) => s.toggleAll(),
                ),
              ),
            for (var i = from; i < to; i++)
              cell(
                i,
                Row(
                  children: [
                    // Nothing outside Reports (see HeadingPin).
                    if (i == 0) const HeadingPinButton(),
                    Expanded(
                      child: (columns[i].frame ?? (Widget h) => h)(
                        InkWell(
                          key: columns[i].onSort == null
                              ? null
                              : Key('list-sort-$i'),
                          onTap: columns[i].onSort,
                          child: Row(
                            mainAxisAlignment: columns[i].center
                                ? MainAxisAlignment.center
                                : columns[i].end
                                ? MainAxisAlignment.end
                                : MainAxisAlignment.start,
                            children: [
                              Flexible(
                                child: Text(
                                  columns[i].label.toUpperCase(),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  textAlign: columns[i].center
                                      ? TextAlign.center
                                      : columns[i].end
                                      ? TextAlign.end
                                      : null,
                                  style: theme.textTheme.labelSmall?.copyWith(
                                    letterSpacing: 0.8,
                                    fontWeight: FontWeight.w700,
                                    color: columns[i].sorted == null
                                        ? theme.colorScheme.onSurfaceVariant
                                        : theme.colorScheme.onSurface,
                                  ),
                                ),
                              ),
                              if (columns[i].sorted case final desc?)
                                Icon(
                                  desc
                                      ? Icons.arrow_downward
                                      : Icons.arrow_upward,
                                  size: 14,
                                ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    if (onResize != null)
                      _ResizeEdge(
                        key: Key('list-resize-$i'),
                        onDrag: (dx) =>
                            onResize!(i, (natural[i] + dx).clamp(60.0, 800.0)),
                      ),
                  ],
                ),
              ),
          ],
        );

        Widget headerBox(Widget child) => Container(
          height: 40,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border(
              bottom: BorderSide(color: EzBiziTheme.edge(context)),
            ),
          ),
          child: child,
        );

        // Inside Reports the header may stay on screen while the page scrolls
        // the rows (HeadingPin, Jose 2026-11-16); a table with a height keeps
        // its header already. Elsewhere the layout is as it was.
        final floats =
            wide && height == null && HeadingPin.maybeOf(context) != null;

        // Rows (and the header) for columns [from, to), one per row. Used
        // whole, or split in two when the first column stays put.
        Widget half(int from, int to, {required bool lazy}) {
          final rowsWidget = lazy
              ? Expanded(
                  child: ListView.builder(
                    itemCount: rows.length,
                    itemBuilder: (context, r) => rowOf(r, from, to),
                  ),
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (var r = 0; r < rows.length; r++) rowOf(r, from, to),
                  ],
                );
          if (floats && !lazy) {
            return PinnableHeading(
              heading: headerBox(headerRow(from, to)),
              body: rowsWidget,
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: lazy ? MainAxisSize.max : MainAxisSize.min,
            children: [if (wide) headerBox(headerRow(from, to)), rowsWidget],
          );
        }

        Widget content;
        final lazy = height != null;
        if (!scrolls) {
          // Rows, or group headers with their rows under them: an int is a
          // row, a ListGroup its header.
          final items = <Object>[];
          if (groupBy == null) {
            items.addAll(List.generate(rows.length, (r) => r));
          } else {
            final groups = <String, (ListGroup, List<int>)>{};
            for (var r = 0; r < rows.length; r++) {
              final g = groupBy!(r);
              groups.putIfAbsent(g.key, () => (g, []));
              groups[g.key]!.$2.add(r);
            }
            for (final (g, members) in groups.values) {
              items.add((group: g, rows: members));
              if (!s.collapsed.contains(g.key)) items.addAll(members);
            }
          }
          Widget item(Object it) =>
              it is int ? row(it) : _groupHeader(context, s, it as _GroupItem);

          content = floats
              ? PinnableHeading(
                  heading: headerBox(headerRow(0, columns.length)),
                  body: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: [for (final it in items) item(it)],
                  ),
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: lazy ? MainAxisSize.max : MainAxisSize.min,
                  children: [
                    if (wide)
                      headerBox(headerRow(0, columns.length))
                    else if (selectable)
                      headerBox(
                        Row(
                          children: [
                            headerRow(0, 0).children.first,
                            Text(
                              'Select all',
                              style: theme.textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                    if (lazy)
                      Expanded(
                        child: ListView.builder(
                          itemCount: items.length,
                          itemBuilder: (context, i) => item(items[i]),
                        ),
                      )
                    else
                      for (final it in items) item(it),
                  ],
                );
        } else {
          // One vertical scroll drives both halves when lazy is off; with a
          // height, each half is one scroll view kept in step.
          final first = stickyFirstColumn ? 1 : 0;
          // With a height and no sticky column, rows scroll inside the
          // sideways view, so its bar stays pinned at the bottom of the
          // screen on any row (Jose, 2026-11-13).
          final pinned = lazy && first == 0;
          // A visible bar: a mouse wheel cannot reach the right-hand columns
          // (Jose, 2026-11-12: "stuff being hidden towards the right side").
          // Middle button held: drag pans sideways (Jose, 2026-11-16).
          final rest = Listener(
            onPointerMove: (e) {
              if (e.buttons & kMiddleMouseButton == 0 ||
                  !s.sideways.hasClients) {
                return;
              }
              final p = s.sideways.position;
              s.sideways.jumpTo(
                (p.pixels - e.delta.dx).clamp(0.0, p.maxScrollExtent),
              );
            },
            child: Scrollbar(
              controller: s.sideways,
              thumbVisibility: true,
              child: SingleChildScrollView(
                controller: s.sideways,
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.only(bottom: 12),
                child: SizedBox(
                  width:
                      natural.skip(first).fold<double>(0, (a, b) => a + b) + 28,
                  child: half(first, columns.length, lazy: pinned),
                ),
              ),
            ),
          );
          content = Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (first == 1)
                SizedBox(
                  width: natural[0] + 28,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      border: Border(
                        right: BorderSide(color: EzBiziTheme.edge(context)),
                      ),
                    ),
                    child: half(0, 1, lazy: false),
                  ),
                ),
              Expanded(child: rest),
            ],
          );
          // ponytail: sideways tables scroll as one block under a height; a
          // lazy split would need two synced controllers.
          if (lazy && !pinned) {
            content = SingleChildScrollView(child: content);
          }
        }

        final table = DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            border: Border.all(color: EzBiziTheme.edge(context)),
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: lazy ? MainAxisSize.max : MainAxisSize.min,
              children: [
                if (lazy) Expanded(child: content) else content,
                if (s.selected.isNotEmpty) _bulkBar(context, s),
                ?footer,
              ],
            ),
          ),
        );

        return lazy ? SizedBox(height: height, child: table) : table;
      },
    );
  }

  Widget _groupHeader(
    BuildContext context,
    _ListTableBodyState s,
    _GroupItem it,
  ) {
    final theme = Theme.of(context);
    final g = it.group;
    final open = !s.collapsed.contains(g.key);
    final ink = ToneColours.of(context, g.tone ?? Tone.neutral).ink;

    // How the group's rows split by label: one segment per tone, in order of
    // first appearance, drawn only while collapsed.
    final split = <Tone, int>{};
    if (rowTone != null) {
      for (final r in it.rows) {
        final t = rowTone!(r);
        if (t != null) split[t] = (split[t] ?? 0) + 1;
      }
    }

    return Material(
      key: Key('list-group-${g.key}'),
      color: theme.colorScheme.surfaceContainerLow,
      child: InkWell(
        onTap: () => s.toggleGroup(g.key),
        child: Container(
          decoration: BoxDecoration(
            border: Border(
              left: BorderSide(color: ink, width: 4),
              bottom: BorderSide(color: EzBiziTheme.edge(context)),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(10, 8, 14, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Icon(
                    open ? Icons.expand_more : Icons.chevron_right,
                    size: 20,
                    color: ink,
                  ),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      g.label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: ink,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    '${it.rows.length}',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
              if (!open && split.isNotEmpty) ...[
                const SizedBox(height: 6),
                ClipRRect(
                  borderRadius: BorderRadius.circular(2),
                  child: Row(
                    key: Key('list-group-split-${g.key}'),
                    children: [
                      for (final e in split.entries)
                        Expanded(
                          flex: e.value,
                          child: Container(
                            height: 4,
                            color: ToneColours.of(context, e.key).ink,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _bulkBar(BuildContext context, _ListTableBodyState s) {
    final theme = Theme.of(context);
    final picked = s.selected.toList()..sort();
    final bad = theme.colorScheme.error;

    Future<void> run(BulkAction a) async {
      if (a.destructive) {
        final sure = await showSidePanel<bool>(
          context,
          builder: (panel) => SidePanel(
            title: '${a.label} ${picked.length} selected?',
            actions: [
              TextButton(
                onPressed: () => Navigator.of(panel).pop(false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const Key('list-bulk-confirm'),
                style: FilledButton.styleFrom(backgroundColor: bad),
                onPressed: () => Navigator.of(panel).pop(true),
                child: Text(a.label),
              ),
            ],
            child: Text(
              'This applies to all ${picked.length} selected rows.',
              style: theme.textTheme.bodyMedium,
            ),
          ),
        );
        if (sure != true) return;
      }
      await a.onRun(picked);
      s.clear();
    }

    return Container(
      key: const Key('list-bulk-bar'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(
            '${picked.length} selected',
            style: theme.textTheme.titleSmall?.copyWith(
              color: theme.colorScheme.onPrimaryContainer,
              fontWeight: FontWeight.w700,
            ),
          ),
          for (final a in bulkActions)
            TextButton.icon(
              key: Key('list-bulk-${a.label}'),
              style: a.destructive
                  ? TextButton.styleFrom(foregroundColor: bad)
                  : null,
              onPressed: () => run(a),
              icon: Icon(a.icon, size: 18),
              label: Text(a.label),
            ),
          TextButton(
            key: const Key('list-bulk-clear'),
            onPressed: s.clear,
            child: const Text('Clear'),
          ),
        ],
      ),
    );
  }
}

/// A group under [ListTable.groupBy]: its key, its heading and its colour.
typedef ListGroup = ({String key, String label, Tone? tone});
typedef _GroupItem = ({ListGroup group, List<int> rows});

/// ListToolbar's Group by choice as a [ListTable.groupBy]: groups [data] by
/// [field]'s value, an option's label where it has one, "(none)" when empty.
/// Null [field]: no groups. [tone] colours a group from its value.
ListGroup Function(int)? groupByField(
  FilterField? field,
  List<Map<String, dynamic>> data, {
  Tone? Function(String value)? tone,
}) {
  if (field == null) return null;
  final labels = {for (final (id, label) in field.options) id: label};
  return (r) {
    final v = field.read(data[r]);
    final key = v == null || '$v'.isEmpty ? '' : '$v';
    return (
      key: key,
      label: key.isEmpty ? '(none)' : labels[key] ?? key,
      tone: tone?.call(key),
    );
  };
}

/// An action on the ticked rows. [onRun] gets their indexes, in order; a
/// [destructive] one is red and asks first, in a side panel.
class BulkAction {
  const BulkAction(
    this.label,
    this.icon,
    this.onRun, {
    this.destructive = false,
  });

  final String label;
  final IconData icon;
  final FutureOr<void> Function(List<int> rows) onRun;
  final bool destructive;
}

class _ListTableBody extends StatefulWidget {
  const _ListTableBody({required this.table});

  final ListTable table;

  @override
  State<_ListTableBody> createState() => _ListTableBodyState();
}

class _ListTableBodyState extends State<_ListTableBody> {
  final selected = <int>{};
  final sideways = ScrollController();
  late Set<String> collapsed = _memory();

  @override
  void dispose() {
    sideways.dispose();
    super.dispose();
  }

  Set<String> _memory() {
    final id = widget.table.groupMemory;
    return id == null
        ? <String>{}
        : ListTable._collapsedByList.putIfAbsent(id, () => <String>{});
  }

  @override
  void didUpdateWidget(_ListTableBody old) {
    super.didUpdateWidget(old);
    // Indexes name rows; a different list makes old ticks point elsewhere.
    if (old.table.rows.length != widget.table.rows.length) selected.clear();
    if (old.table.groupMemory != widget.table.groupMemory) {
      collapsed = _memory();
    }
  }

  void toggle(int r) => setState(
    () => selected.contains(r) ? selected.remove(r) : selected.add(r),
  );

  void toggleAll() => setState(() {
    if (selected.length == widget.table.rows.length) {
      selected.clear();
    } else {
      selected.addAll(List.generate(widget.table.rows.length, (r) => r));
    }
  });

  void toggleGroup(String key) => setState(
    () => collapsed.contains(key) ? collapsed.remove(key) : collapsed.add(key),
  );

  void clear() => setState(selected.clear);

  @override
  Widget build(BuildContext context) => widget.table._render(context, this);
}

/// The few pixels at a heading's right edge that drag its column wider.
class _ResizeEdge extends StatelessWidget {
  const _ResizeEdge({super.key, required this.onDrag});

  final ValueChanged<double> onDrag;

  @override
  Widget build(BuildContext context) {
    return MouseRegion(
      cursor: SystemMouseCursors.resizeColumn,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onHorizontalDragUpdate: (d) => onDrag(d.delta.dx),
        child: SizedBox(
          width: 10,
          height: 28,
          child: Center(
            child: Container(width: 1, color: EzBiziTheme.edge(context)),
          ),
        ),
      ),
    );
  }
}

class PersonCell extends StatelessWidget {
  const PersonCell({super.key, required this.name, this.sub});

  final String name;
  final String? sub;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initials = name
        .split(RegExp(r'[\s@._-]+'))
        .where((p) => p.isNotEmpty)
        .take(2)
        .map((p) => p[0].toUpperCase())
        .join();

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        CircleAvatar(
          radius: 16,
          backgroundColor: theme.colorScheme.primaryContainer,
          // A name that is only a phone number has no initials ("+(").
          child: RegExp(r'[A-Za-z]').hasMatch(initials)
              ? Text(
                  initials,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: theme.colorScheme.onPrimaryContainer,
                  ),
                )
              : Icon(
                  Icons.phone_outlined,
                  size: 16,
                  color: theme.colorScheme.onPrimaryContainer,
                ),
        ),
        const SizedBox(width: 10),
        Flexible(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (sub != null && sub!.isNotEmpty)
                Text(
                  sub!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
