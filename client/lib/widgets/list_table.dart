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
  });

  final List<ListColumn> columns;

  /// One widget per column, in order.
  final List<List<Widget>> rows;
  final void Function(int index)? onTap;
  final Key Function(int index)? rowKey;

  /// Under the last row: a "load more", for instance.
  final Widget? footer;
  static const double stackBelow = 720;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= stackBelow;

        Widget cell(int i, Widget child) => Expanded(
          flex: columns[i].flex,
          child: Align(
            alignment: columns[i].end
                ? Alignment.centerRight
                : Alignment.centerLeft,
            child: child,
          ),
        );

        Widget row(int r) {
          final cells = rows[r];
          final body = wide
              ? Row(
                  children: [
                    for (var i = 0; i < cells.length; i++) cell(i, cells[i]),
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
                constraints: const BoxConstraints(minHeight: 52),
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

        return DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            border: Border.all(color: EzBiziTheme.edge(context)),
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (wide)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    decoration: BoxDecoration(
                      border: Border(
                        bottom: BorderSide(color: EzBiziTheme.edge(context)),
                      ),
                    ),
                    child: Row(
                      children: [
                        for (var i = 0; i < columns.length; i++)
                          cell(
                            i,
                            Text(
                              columns[i].label.toUpperCase(),
                              style: theme.textTheme.labelSmall?.copyWith(
                                letterSpacing: 0.8,
                                fontWeight: FontWeight.w700,
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                for (var r = 0; r < rows.length; r++) row(r),
                ?footer,
              ],
            ),
          ),
        );
      },
    );
  }
}

/// A person's initials in a circle beside their name and a line under it.
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
