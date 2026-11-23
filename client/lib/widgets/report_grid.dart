import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/theme.dart';
import 'heading_pin.dart';
import 'table_download.dart';

/// One column of a [ReportGrid].
class GridColumn {
  const GridColumn(
    this.label, {
    this.numeric = false,
    this.pinned = false,
    this.main = false,
    this.headline = false,
    this.help,
  });

  final String label;

  /// Right-aligned in even figures.
  final bool numeric;

  /// Stays put while the rest scroll sideways: the columns that say which
  /// row this is (Branch, Source). Pinned columns come first. On a phone
  /// they are the card's title.
  final bool pinned;

  /// One of the (up to three) figures a phone's card shows before a tap.
  final bool main;

  /// The figure at the right of a phone card's title, such as net profit.
  final bool headline;

  /// What the column means, on hover over its heading.
  final String? help;
}

/// One cell. [tone] colours a figure that means something (a loss in red);
/// [muted] greys a dash or a missing value.
class GridCell {
  const GridCell(
    this.text, {
    this.tone,
    this.muted = false,
    this.tooltip,
    this.key,
  });

  /// "None": a dash, greyed, never a zero.
  const GridCell.none({this.tooltip, this.key})
    : text = '—',
      tone = null,
      muted = true;

  final String text;
  final Tone? tone;
  final bool muted;
  final String? tooltip;
  final Key? key;
}

/// The one table every Reports tab draws (UI queue 87, desktop 1).
///
/// Headings in plain words, figures right-aligned in even columns, the naming
/// columns pinned while the rest scroll sideways (with a line saying so), an
/// optional Total row, a row opened by a tap. Every row is the same height, so
/// the pinned and the scrolling halves line up without measuring each other.
///
/// ponytail: rows are built, not recycled; a report table holds tens of rows.
/// Put the body in a ListView.builder if one ever holds thousands.
class ReportGrid extends StatelessWidget {
  const ReportGrid({
    super.key,
    required this.columns,
    required this.rows,
    this.total,
    this.onTap,
    this.download,
  });

  final List<GridColumn> columns;
  final List<List<GridCell>> rows;
  final List<GridCell>? total;
  final ValueChanged<int>? onTap;

  /// The file name for a Download button over the table (Merav, 2026-11-19);
  /// none when null, as for someone without reports.export.
  final String? download;

  /// The table as a file's rows: headings, the rows, the total.
  List<List<Object?>> get sheet => sheetFor(columns, [...rows, ?total]);

  /// [rows] under [columns]' headings, each figure a number.
  static List<List<Object?>> sheetFor(
    List<GridColumn> columns,
    List<List<GridCell>> rows,
  ) => [
    [for (final c in columns) c.label],
    for (final row in rows)
      [
        for (final (i, c) in row.indexed)
          TableFile.value(c.text, numeric: columns[i].numeric),
      ],
  ];

  static const _rowHeight = 40.0;
  static const _pad = 12.0;

  /// Below this a row is a card (phone 4): a sideways table is wider than a
  /// phone and hides what the row is.
  static const cardsBelow = 600.0;

  @override
  Widget build(BuildContext context) {
    final grid = LayoutBuilder(
      builder: (context, box) =>
          box.maxWidth < cardsBelow ? _GridCards(grid: this) : _table(context),
    );
    if (download == null) return grid;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Align(
          alignment: Alignment.centerRight,
          child: TableDownload(name: download!, rows: () => sheet),
        ),
        const SizedBox(height: 8),
        grid,
      ],
    );
  }

  Widget _table(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final body = theme.textTheme.bodyMedium!.copyWith(
      fontFeatures: const [FontFeature.tabularFigures()],
    );
    final head = theme.textTheme.labelLarge!.copyWith(
      fontWeight: FontWeight.w700,
    );
    final bold = body.copyWith(fontWeight: FontWeight.w800);
    // The pin sits in the first heading, so that column makes room for it.
    final pin = HeadingPin.maybeOf(context) == null ? 0.0 : 28.0;

    // Each column as wide as its widest text, within reason.
    double measure(String text, TextStyle style) {
      final painter = TextPainter(
        text: TextSpan(text: text, style: style),
        textDirection: TextDirection.ltr,
        maxLines: 1,
      )..layout();
      return painter.width;
    }

    final natural = [
      for (final (i, c) in columns.indexed)
        (math.max(
                  measure(c.label, head) +
                      (c.help == null ? 0 : 18) +
                      (i == 0 ? pin : 0),
                  [
                    for (final r in rows) measure(r[i].text, body),
                    if (total != null) measure(total![i].text, bold),
                  ].fold<double>(0, math.max),
                ) +
                _pad * 2 +
                2)
            .clamp(64.0, 280.0),
    ];
    final pinned = [
      for (final (i, c) in columns.indexed)
        if (c.pinned) i,
    ];
    final free = [
      for (final (i, c) in columns.indexed)
        if (!c.pinned) i,
    ];

    Widget cell(int col, GridCell c, double width, {required bool isTotal}) {
      final column = columns[col];
      final tone = c.tone == null ? null : ToneColours.of(context, c.tone!);
      Widget text = Text(
        c.text,
        key: c.key,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        textAlign: column.numeric ? TextAlign.end : TextAlign.start,
        style: (isTotal ? bold : body).copyWith(
          color: tone?.ink ?? (c.muted ? scheme.outline : null),
          fontWeight: tone != null && !isTotal ? FontWeight.w700 : null,
        ),
      );
      if (c.tooltip != null) text = Tooltip(message: c.tooltip, child: text);
      return SizedBox(
        width: width,
        height: _rowHeight,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: _pad),
          child: Align(
            alignment: column.numeric
                ? Alignment.centerRight
                : Alignment.centerLeft,
            child: text,
          ),
        ),
      );
    }

    Widget half(List<int> cols, List<double> widths, {required bool first}) {
      final line = BorderSide(
        color: scheme.outlineVariant.withValues(alpha: .6),
      );
      Widget line0(List<Widget> cells, {Color? colour, int? tap}) {
        final row = Container(
          decoration: BoxDecoration(
            color: colour,
            border: Border(bottom: line),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: cells),
        );
        return tap == null || onTap == null
            ? row
            : InkWell(onTap: () => onTap!(tap), child: row);
      }

      // The headings stay at the top of the page while the rows pass under
      // them, if pinned (Jose, 2026-11-16).
      return PinnableHeading(
        heading: line0(colour: scheme.surfaceContainerHighest, [
          for (final (j, i) in cols.indexed)
            SizedBox(
              width: widths[j],
              height: _rowHeight,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: _pad),
                child: Align(
                  alignment: columns[i].numeric
                      ? Alignment.centerRight
                      : Alignment.centerLeft,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (first && j == 0) const HeadingPinButton(),
                      Flexible(
                        child: Tooltip(
                          message: columns[i].help ?? '',
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Flexible(
                                child: Text(
                                  columns[i].label,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: head,
                                ),
                              ),
                              if (columns[i].help != null) ...[
                                const SizedBox(width: 4),
                                Icon(
                                  Icons.info_outline,
                                  size: 14,
                                  color: theme.colorScheme.outline,
                                ),
                              ],
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ]),
        body: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final (r, row) in rows.indexed)
              line0(tap: r, [
                for (final (j, i) in cols.indexed)
                  cell(i, row[i], widths[j], isTotal: false),
              ]),
            if (total != null)
              line0(colour: scheme.primaryContainer.withValues(alpha: .45), [
                for (final (j, i) in cols.indexed)
                  cell(i, total![i], widths[j], isTotal: true),
              ]),
          ],
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        final pinnedWidths = [for (final i in pinned) natural[i]];
        final pinnedTotal = pinnedWidths.fold<double>(0, (a, b) => a + b);
        var freeWidths = [for (final i in free) natural[i]];
        final freeTotal = freeWidths.fold<double>(0, (a, b) => a + b);
        final room = box.maxWidth - pinnedTotal - 2;
        final fits = freeTotal <= room;
        // With room to spare the figures share it, so the table spans the page.
        if (fits && freeTotal > 0) {
          final grow = room / freeTotal;
          freeWidths = [for (final w in freeWidths) w * grow];
        }

        final scrolling = half(free, freeWidths, first: pinned.isEmpty);
        return Container(
          decoration: BoxDecoration(
            color: scheme.surface,
            border: Border.all(color: scheme.outlineVariant),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (pinned.isNotEmpty)
                    DecoratedBox(
                      decoration: BoxDecoration(
                        border: Border(
                          right: BorderSide(color: scheme.outlineVariant),
                        ),
                      ),
                      child: half(pinned, pinnedWidths, first: true),
                    ),
                  Expanded(
                    child: fits
                        ? scrolling
                        : SingleChildScrollView(
                            key: const Key('report-grid-scroll'),
                            scrollDirection: Axis.horizontal,
                            child: scrolling,
                          ),
                  ),
                ],
              ),
              if (!fits)
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 6, 12, 6),
                  child: Text(
                    'Scroll sideways for more columns ›',
                    key: const Key('report-grid-more'),
                    textAlign: TextAlign.end,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// Phone 4: each row a card titled by its naming columns, its headline figure
/// at the right, its main figures under it, and the rest a tap away.
class _GridCards extends StatefulWidget {
  const _GridCards({required this.grid});

  final ReportGrid grid;

  @override
  State<_GridCards> createState() => _GridCardsState();
}

class _GridCardsState extends State<_GridCards> {
  final _open = <int>{};

  @override
  Widget build(BuildContext context) {
    final grid = widget.grid;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final columns = grid.columns;
    final pinned = [
      for (final (i, c) in columns.indexed)
        if (c.pinned) i,
    ];
    final headline = columns.indexWhere((c) => c.headline);
    var main = [
      for (final (i, c) in columns.indexed)
        if (c.main) i,
    ];
    if (main.isEmpty) {
      main = [
        for (final (i, c) in columns.indexed)
          if (c.numeric && i != headline) i,
      ].take(3).toList();
    }
    final rest = [
      for (final i in columns.indexed.map((e) => e.$1))
        if (!pinned.contains(i) && !main.contains(i) && i != headline) i,
    ];
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final figures = theme.textTheme.titleSmall?.copyWith(
      fontFeatures: const [FontFeature.tabularFigures()],
    );

    Color? ink(GridCell c) => c.tone == null
        ? (c.muted ? scheme.outline : null)
        : ToneColours.of(context, c.tone!).ink;

    Widget card(int? index, List<GridCell> row) {
      final total = index == null;
      final open = index != null && _open.contains(index);
      final title = [
        for (final i in pinned)
          if (row[i].text.trim().isNotEmpty) row[i].text,
      ].join(' · ');

      return Card(
        margin: EdgeInsets.zero,
        color: total ? scheme.primaryContainer.withValues(alpha: .45) : null,
        child: InkWell(
          key: index == null ? null : ValueKey('report-grid-card-$index'),
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          onTap: index == null || rest.isEmpty
              ? null
              : () => setState(
                  () => open ? _open.remove(index) : _open.add(index),
                ),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    if (headline >= 0)
                      Text(
                        row[headline].text,
                        style: figures?.copyWith(
                          color: ink(row[headline]),
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    for (final i in main)
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(columns[i].label, style: muted),
                            Text(
                              row[i].text,
                              style: figures?.copyWith(color: ink(row[i])),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
                if (open) ...[
                  const Divider(height: 16),
                  for (final i in rest)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Row(
                        children: [
                          Expanded(child: Text(columns[i].label, style: muted)),
                          Text(
                            row[i].text,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: ink(row[i]),
                              fontFeatures: const [
                                FontFeature.tabularFigures(),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                ] else if (rest.isNotEmpty && index != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    '${rest.length} more ▾',
                    style: muted?.copyWith(color: scheme.primary),
                  ),
                ],
              ],
            ),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, row) in grid.rows.indexed) ...[
          card(i, row),
          const SizedBox(height: 8),
        ],
        if (grid.total != null) card(null, grid.total!),
      ],
    );
  }
}
