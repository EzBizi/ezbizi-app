import 'package:flutter/material.dart';

/// Records divided under headings — the day for jobs, the letter for customers.
///
/// Jose, 2026-09-24, choosing the Jobs and Customers screens (queue 24,
/// option 7): *"i like the table view, but it would be nice to also click and
/// have it change to sidebar and detailed view, and also have the time
/// divisions"*. Two widgets, one set of groups: [GroupedTable] is the table,
/// [GroupedList] is the sidebar it turns into when a row is open, and the phone
/// list. Both draw a group the same way, so the headings do not change shape
/// when the table does.
///
/// ── The groups are the CALLER's ──────────────────────────────────
///
/// These widgets draw headings; they never decide them. A list grouped by day
/// is also ORDERED by day, and the order comes from the server — so the caller
/// builds [RowGroup]s from rows already in order, and nothing here sorts.
class RowGroup<T> {
  const RowGroup({
    required this.heading,
    required this.rows,
    this.detail,
    this.count,
  });

  /// "Today", "A".
  final String heading;

  /// "Thursday 24 September" — the part a glance can skip.
  final String? detail;

  /// "38 jobs". Null when nobody measured it: a heading counting only the
  /// rows loaded so far would under-report every day the page cut in half.
  final String? count;

  final List<T> rows;
}

/// One column of a [GroupedTable].
class TableColumn<T> {
  const TableColumn(
    this.label, {
    required this.cell,
    this.flex = 1,
    this.width,
    this.numeric = false,
    this.sortKey,
  });

  final String label;
  final Widget Function(BuildContext context, T row) cell;

  /// What the header sends when clicked. Null for a column that cannot be
  /// sorted, which draws its label as plain text rather than as a control that
  /// does nothing. The sorting itself is the caller's -- see [GroupedTable.onSort].
  final String? sortKey;

  /// Share of the width left after the fixed columns. Ignored when [width] is
  /// set.
  final int flex;
  final double? width;

  /// Right-aligned, like every column of amounts people add up by eye.
  final bool numeric;
}

/// The table: labels once at the top, one ruled row per record, a band for
/// each group.
class GroupedTable<T> extends StatelessWidget {
  const GroupedTable({
    super.key,
    required this.columns,
    required this.groups,
    required this.idOf,
    required this.onOpen,
    this.footer,
    this.sortKey,
    this.sortAscending = true,
    this.onSort,
  });

  final List<TableColumn<T>> columns;
  final List<RowGroup<T>> groups;
  final String Function(T row) idOf;
  final ValueChanged<T> onOpen;

  /// "Load more", under the last row.
  final Widget? footer;

  /// The column the rows arrived sorted by, marked with an arrow.
  ///
  /// This widget still never sorts -- the rows come in order, as the class
  /// docblock says. A paged list sorted here would sort the loaded page and
  /// nothing else, so a header click is reported to [onSort] and the caller
  /// asks the server again.
  final String? sortKey;
  final bool sortAscending;
  final ValueChanged<String>? onSort;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final entries = _entries(groups);

    return Container(
      margin: const EdgeInsets.fromLTRB(24, 4, 24, 24),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Line(
            columns: columns,
            header: true,
            cells: [for (final c in columns) _headerCell(context, c)],
          ),
          Expanded(
            child: ListView.builder(
              itemCount: entries.length + (footer == null ? 0 : 1),
              itemBuilder: (context, i) {
                if (i == entries.length) return footer!;

                final entry = entries[i];
                final group = entry.group;
                if (group != null) return _Heading(group: group);

                final row = entry.row as T;

                return Material(
                  key: ValueKey('row-${idOf(row)}'),
                  type: MaterialType.transparency,
                  child: InkWell(
                    onTap: () => onOpen(row),
                    child: _Line(
                      columns: columns,
                      cells: [for (final c in columns) c.cell(context, row)],
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _headerCell(BuildContext context, TableColumn<T> column) {
    final scheme = Theme.of(context).colorScheme;
    final active = column.sortKey != null && column.sortKey == sortKey;
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: active ? scheme.onSurface : scheme.onSurfaceVariant,
    );
    final label = Text(column.label, style: style);

    if (column.sortKey == null || onSort == null) return label;

    return InkWell(
      onTap: () => onSort!(column.sortKey!),
      borderRadius: BorderRadius.circular(4),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(child: label),
            // The arrow only on the column in force. An arrow on every header
            // reads as "these are all sorted", and the one that is disappears
            // among them.
            if (active)
              Icon(
                sortAscending ? Icons.arrow_upward : Icons.arrow_downward,
                size: 14,
                color: scheme.onSurface,
              ),
          ],
        ),
      ),
    );
  }
}

/// The same groups as a list of two-line rows: beside an open record, and on
/// a phone.
class GroupedList<T> extends StatelessWidget {
  const GroupedList({
    super.key,
    required this.groups,
    required this.idOf,
    required this.tile,
    required this.onOpen,
    this.selectedId,
    this.footer,
  });

  final List<RowGroup<T>> groups;
  final String Function(T row) idOf;
  final Widget Function(BuildContext context, T row) tile;
  final ValueChanged<T> onOpen;

  /// The record open beside the list. A fill rather than a leading-edge bar,
  /// by the settled rule (UI-DESIGN-QUEUE, "No leading-edge accent bar").
  final String? selectedId;

  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final entries = _entries(groups);

    return ListView.builder(
      itemCount: entries.length + (footer == null ? 0 : 1),
      itemBuilder: (context, i) {
        if (i == entries.length) return footer!;

        final entry = entries[i];
        final group = entry.group;
        if (group != null) return _Heading(group: group, compact: true);

        final row = entry.row as T;
        final selected = idOf(row) == selectedId;

        return Material(
          key: ValueKey('row-${idOf(row)}'),
          color: selected ? scheme.primaryContainer : Colors.transparent,
          child: InkWell(
            onTap: () => onOpen(row),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
                ),
              ),
              child: tile(context, row),
            ),
          ),
        );
      },
    );
  }
}

/// Two lines: what it is and a fact on the right, then who and a mark on the
/// right. The shape of both lists, so a job and a customer read alike.
class TwoLineTile extends StatelessWidget {
  const TwoLineTile({
    super.key,
    required this.title,
    required this.subtitle,
    this.topRight,
    this.bottomRight,
    this.leading,
    this.badge,
    this.subtitleBadge,
    this.footer,
  });

  final String title;
  final String subtitle;
  final String? topRight;
  final Widget? bottomRight;
  final Widget? leading;

  /// A mark after the title that must be seen before anybody acts — "Shared
  /// customer". It keeps its size; the title is what shortens.
  final Widget? badge;

  /// The same, after the subtitle — "Not placed" beside the franchise it is
  /// missing an area of. The subtitle shortens; the mark does not.
  final Widget? subtitleBadge;

  /// A third line under the subtitle -- a job's type and first tag. Absent,
  /// the tile is the two lines it is named for.
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return Row(
      children: [
        if (leading != null) ...[leading!, const SizedBox(width: 12)],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Row(
                      children: [
                        Flexible(
                          child: Text(
                            title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        ?badge,
                      ],
                    ),
                  ),
                  if (topRight != null) ...[
                    const SizedBox(width: 8),
                    Text(
                      topRight!,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 3),
              Row(
                children: [
                  Expanded(
                    child: Row(
                      children: [
                        Flexible(
                          child: Text(
                            subtitle,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: muted,
                            ),
                          ),
                        ),
                        if (subtitleBadge != null) ...[
                          const SizedBox(width: 6),
                          subtitleBadge!,
                        ],
                      ],
                    ),
                  ),
                  if (bottomRight != null) ...[
                    const SizedBox(width: 8),
                    // Capped rather than Flexible: a Flexible beside the
                    // Expanded subtitle takes half the row and leaves the pill
                    // floating in the middle of it. The cap is for the reason
                    // every tag here has one — the label is organization-
                    // chosen text of no trusted length.
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 170),
                      child: bottomRight!,
                    ),
                  ],
                ],
              ),
              if (footer != null) ...[const SizedBox(height: 4), footer!],
            ],
          ),
        ),
      ],
    );
  }
}

/// A group's band. The same in the table and the list, so the headings stay
/// put when the table turns into the sidebar.
class _Heading extends StatelessWidget {
  const _Heading({required this.group, this.compact = false});

  final RowGroup<Object?> group;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return Container(
      padding: EdgeInsets.symmetric(horizontal: compact ? 16 : 12, vertical: 7),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHigh,
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      // The count at the far end, the detail taking whatever is left: a
      // Spacer beside a Flexible splits the room in half, which parked the
      // count mid-band and cut "Thursday 24 September" short in a 380px list.
      child: Row(
        children: [
          Expanded(
            child: Row(
              children: [
                Text(
                  group.heading,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                if (group.detail != null) ...[
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      group.detail!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: muted,
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (group.count != null) ...[
            const SizedBox(width: 8),
            Text(group.count!, style: muted),
          ],
        ],
      ),
    );
  }
}

/// A table line: the header or a record, cells laid out by [columns].
class _Line extends StatelessWidget {
  const _Line({
    required this.columns,
    required this.cells,
    this.header = false,
  });

  final List<TableColumn<Object?>> columns;
  final List<Widget> cells;
  final bool header;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      // A floor, not a height: a fixed height clips the first row that needs
      // more, and a floor below the tallest row leaves two heights (queue,
      // "The floor must clear the TALLEST row").
      constraints: BoxConstraints(minHeight: header ? 34 : 42),
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: header ? scheme.surfaceContainerLow : null,
        border: Border(
          bottom: BorderSide(
            color: scheme.outlineVariant,
            width: header ? 1 : 0.5,
          ),
        ),
      ),
      child: Row(
        children: [
          for (var i = 0; i < columns.length; i++)
            _sized(
              columns[i],
              Padding(
                padding: EdgeInsets.only(
                  right: i == columns.length - 1 ? 0 : 12,
                ),
                child: Align(
                  alignment: columns[i].numeric
                      ? Alignment.centerRight
                      : Alignment.centerLeft,
                  child: DefaultTextStyle.merge(
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    softWrap: false,
                    child: cells[i],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _sized(TableColumn<Object?> column, Widget child) =>
      column.width != null
      ? SizedBox(width: column.width, child: child)
      : Expanded(flex: column.flex, child: child);
}

/// Headings and rows, flattened for a lazy list.
typedef _Entry = ({RowGroup<Object?>? group, Object? row});

List<_Entry> _entries<T>(List<RowGroup<T>> groups) => [
  for (final group in groups) ...[
    // An empty heading draws no band: a list sorted by something other than
    // the thing it is usually divided by has no honest headings to show, and a
    // blank band would read as a group with no name.
    if (group.heading.isNotEmpty) (group: group, row: null),
    for (final row in group.rows) (group: null, row: row),
  ],
];

/// An open record docked beside its list: the [GroupedList] [list] 380 wide,
/// a divider, and the [detail] filling the rest, in one bordered card. Jobs,
/// Customers and Estimates open a row this way.
class DockedListDetail extends StatelessWidget {
  const DockedListDetail({super.key, required this.list, required this.detail});

  final Widget list;
  final Widget detail;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      margin: const EdgeInsets.fromLTRB(24, 4, 24, 24),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(width: 380, child: list),
          VerticalDivider(width: 1, color: scheme.outlineVariant),
          Expanded(child: detail),
        ],
      ),
    );
  }
}
