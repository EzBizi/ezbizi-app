import 'package:flutter/material.dart';

import '../core/theme.dart';

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
    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        final across = (box.maxWidth / 170).floor().clamp(
          2,
          tiles.length + facts.length,
        );
        final width = (box.maxWidth - gap * (across - 1)) / across;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final tile in tiles)
              SizedBox(width: width, child: _tile(context, tile)),
            for (final fact in facts) SizedBox(width: width, child: fact),
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
                maxLines: 1,
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
                maxLines: 1,
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
            maxLines: 1,
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
            maxLines: 1,
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
    this.namedWhenStacked = true,
  });

  final String label;

  /// Whether a stacked row prefixes this cell with [label]. Off where the
  /// value says what it is and the words would push the row to a third line.
  final bool namedWhenStacked;
  final int flex;

  /// Right-aligned, for numbers and the actions column.
  final bool end;
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
  });

  final List<ListColumn> columns;

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
  Widget build(BuildContext context) {
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
        final scrolls =
            wide &&
            widths.isNotEmpty &&
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
              alignment: columns[i].end
                  ? Alignment.centerRight
                  : Alignment.centerLeft,
              child: child,
            ),
          ),
        );

        Widget rowOf(int r, int from, int to) {
          final cells = rows[r];
          final body = wide
              ? Row(
                  children: [
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

          return Material(
            key: rowKey?.call(r),
            color: Colors.transparent,
            child: InkWell(
              onTap: onTap == null ? null : () => onTap!(r),
              child: Container(
                constraints: scrolls
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

        Widget headerRow(int from, int to) => Row(
          children: [
            for (var i = from; i < to; i++)
              cell(
                i,
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        columns[i].label.toUpperCase(),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: columns[i].end ? TextAlign.end : null,
                        style: theme.textTheme.labelSmall?.copyWith(
                          letterSpacing: 0.8,
                          fontWeight: FontWeight.w700,
                          color: theme.colorScheme.onSurfaceVariant,
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
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: lazy ? MainAxisSize.max : MainAxisSize.min,
            children: [if (wide) headerBox(headerRow(from, to)), rowsWidget],
          );
        }

        Widget content;
        final lazy = height != null;
        if (!scrolls) {
          content = Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: lazy ? MainAxisSize.max : MainAxisSize.min,
            children: [
              if (wide) headerBox(headerRow(0, columns.length)),
              if (lazy)
                Expanded(
                  child: ListView.builder(
                    itemCount: rows.length,
                    itemBuilder: (context, r) => row(r),
                  ),
                )
              else
                for (var r = 0; r < rows.length; r++) row(r),
            ],
          );
        } else {
          // One vertical scroll drives both halves when lazy is off; with a
          // height, each half is one scroll view kept in step.
          final first = stickyFirstColumn ? 1 : 0;
          final rest = SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: natural.skip(first).fold<double>(0, (a, b) => a + b) + 28,
              child: half(first, columns.length, lazy: false),
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
          if (lazy) content = SingleChildScrollView(child: content);
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
                ?footer,
              ],
            ),
          ),
        );

        return lazy ? SizedBox(height: height, child: table) : table;
      },
    );
  }
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
