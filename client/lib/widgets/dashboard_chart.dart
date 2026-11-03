import 'dart:math' as math;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/material.dart';

/// A pie, bar, line, or timeline drawn from the server’s slices / series.
///
/// The numbers are already aggregated. This plots them; it does not add them
/// up, drop a zero slice, or invent a bucket the payload omitted.
///
/// Series colours are a FIXED validated palette, not theme roles — see
/// [_palette] for why, and re-run the validator if you touch a value. This
/// docblock used to say the opposite: that hex here "would ignore an
/// organization’s own theme (§12) and, from a generated scheme, is how purple
/// leaked into the product once already". The first half is right about brand
/// colour and wrong about series identity; the second half was being caused by
/// the very code it warned against, since `tertiaryContainer` on the generated
/// scheme is the violet. Structural colours — the axis track, the ring between
/// marks — still come off [ThemeData.colorScheme], and should.
class DashboardChart extends StatefulWidget {
  const DashboardChart({
    super.key,
    required this.presentation,
    this.slices,
    this.series,
  });

  /// `pie` | `bar` | `line` | `timeline`
  final String presentation;

  final List<dynamic>? slices;
  final List<dynamic>? series;

  static const double height = 140;

  /// How much thicker the hovered wedge is drawn.
  ///
  /// Public and named because it is the whole VISIBLE half of the hover: at
  /// 1.0 the tooltip still appears, the state still updates, and the pointer
  /// still resolves the right wedge — and nothing on screen answers. Sabotage
  /// after committing found that zero tests failed when this became 1.0, while
  /// three failed for a wrong percentage. The half people SEE was unguarded.
  @visibleForTesting
  static const double liftFactor = 1.22;

  /// Whether a glance payload is worth drawing at all — ask before building.
  ///
  /// Exists so a screen deciding "is there a chart here" and this widget
  /// deciding "is there anything to paint" cannot answer differently. Both go
  /// through [_drawable]; this is the half that reads a raw payload map, and
  /// [_DashboardChartPainter.isDrawable] is the half that reads parsed lists.
  ///
  /// A screen may skip this and just build the widget — a non-drawable chart
  /// renders `SizedBox.shrink()` and costs nothing. Asking first is better
  /// because it keeps "no chart" expressible as an ABSENCE: a test can say
  /// `findsNothing`, which is what a reader means, rather than asserting a
  /// widget is present at zero size, which is how somebody comes to believe a
  /// chart is missing when it is merely invisible. That confusion has already
  /// cost one wrong answer to a peer.
  static bool canDraw(String presentation, Map<String, dynamic> data) {
    final rawSlices = data['slices'];
    final rawSeries = data['series'];

    return _drawable(
      presentation,
      _readSlices(rawSlices is List ? rawSlices : null),
      _readSeries(rawSeries is List ? rawSeries : null),
    );
  }

  @override
  State<DashboardChart> createState() => _DashboardChartState();
}

class _DashboardChartState extends State<DashboardChart> {
  /// Which wedge the pointer is on, or null. Pie only.
  int? _hovered;

  /// Where the pointer is, in the paint box's own coordinates.
  Offset _pointer = Offset.zero;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final presentation = widget.presentation;
    // Two lists on purpose: the picture folds, the reading does not.
    // `_foldTail`'s docblock has the argument.
    final allSlices = _readSlices(widget.slices);
    final parsedSlices = _foldTail(allSlices);
    final parsedSeries = _readSeries(widget.series);
    final colors = _statusColours(
      scheme,
      _colorsFor(
        scheme,
        slots: math.max(parsedSlices.length, parsedSeries.length),
      ),
      [for (final slice in parsedSlices) slice.label],
    );
    final painter = _DashboardChartPainter(
      presentation: presentation,
      slices: parsedSlices,
      spokenSlices: allSlices,
      series: parsedSeries,
      colors: colors,
      highlighted: _hovered,
      track: scheme.outlineVariant,
      onLine: scheme.surface,
    );

    if (!painter.isDrawable) return const SizedBox.shrink();

    final legendLabels = presentation == 'pie' || presentation == 'bar'
        ? [for (final slice in parsedSlices) slice.label]
        : [for (final row in parsedSeries) row.label];

    // WHETHER there is a legend is a question about the DATA; what the legend
    // SAYS is a question about the drawing. Those are different lists.
    //
    // `legendLabels` holds the folded list, which contains "4 more" — a label
    // this widget manufactures. Asking it whether anything is named lets the
    // tail switch the legend on by itself: a chart whose real categories were
    // all unlabelled would grow a legend whose only row is "12 more", which
    // maps nothing to nothing. Before the fold, that chart drew no legend.
    //
    // Not reachable today and I measured rather than assumed: every glance
    // builds slice labels from a status slug, a definition name or a date
    // bucket, so all-empty across nine-plus slices cannot happen. Raised by
    // UI/UX as a shape rather than a defect, with the honest note that they
    // had not established reachability — which is the right way to hand one
    // over. Fixed anyway, because it is one line and the coupling is the same
    // family as everything else this file has been wrong about: a value I
    // invented for the drawing leaking into a decision about the data.
    final named =
        (presentation == 'pie' || presentation == 'bar'
                ? allSlices.map((slice) => slice.label)
                : parsedSeries.map((row) => row.label))
            .any((label) => label.isNotEmpty);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Semantics(
        label: painter.semanticsLabel,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              height: DashboardChart.height,
              width: double.infinity,
              child: presentation == 'pie'
                  ? _hoverable(painter, parsedSlices)
                  : CustomPaint(painter: painter),
            ),
            // ── DO NOT DROP THIS TO SAVE VERTICAL SPACE ──────────────
            //
            // The legend is load-bearing for accessibility, not decoration.
            // Three of the eight light steps sit below 3:1 against white, and
            // the standard permits that ONLY with "relief" — identity must not
            // be carried by colour alone. The labelled dot per slice is that
            // relief, together with `semanticsLabel` above.
            //
            // So removing it on a narrow window is not a layout tidy-up, it is
            // taking the palette out of compliance. If space is the problem,
            // the answers are a shorter chart, a scrollable legend, or fewer
            // slices ([_foldTail] already caps them) — not no legend.
            //
            // Written HERE and not only beside the palette, which is where I
            // first put it: somebody deleting a legend has this file open at
            // this line, not at `_palette` four hundred lines down.
            if (named) ...[
              const SizedBox(height: 8),
              _ChartLegend(labels: legendLabels, colors: colors),
            ],
          ],
        ),
      ),
    );
  }

  /// The pie, with a pointer layer over it.
  ///
  /// Pie only. A bar chart's marks are already separated and labelled along an
  /// axis; a donut's are not, which is the whole reason a share is hard to read
  /// off one. Jose asked for this on the pie (2026-09-14) and it is where it
  /// earns its place.
  ///
  /// **Hover is a mouse fact.** `MouseRegion` never fires on a touch screen, so
  /// on a phone or tablet this layer does nothing at all. That is a real gap
  /// and it is NOT papered over with a tap handler: a tap inside a dashboard
  /// card competes with the card's own gestures, and a tooltip that must be
  /// dismissed is a different design rather than the same one ported. The
  /// percentages are in the semantics label for exactly this reason — see
  /// [_DashboardChartPainter.semanticsLabel]. The number is reachable without
  /// a mouse; only the convenience is not.
  Widget _hoverable(_DashboardChartPainter painter, List<_Slice> slices) {
    // The paint box's OWN size, not `context.size`. The State's context is the
    // whole column — chart plus gap plus legend — so its height is not the
    // donut's. The widths happen to agree today, which is exactly the kind of
    // coincidence that reads as correct until a layout changes underneath it.
    return LayoutBuilder(
      builder: (context, constraints) => MouseRegion(
        onHover: (event) =>
            _moveTo(event.localPosition, constraints.biggest, slices),
        onExit: (_) {
          if (_hovered != null) setState(() => _hovered = null);
        },
        // Touch gets the same answer by tapping, added 2026-09-15 after
        // shipping hover alone. `MouseRegion` never fires on a phone, so the
        // share was a number only a mouse could see.
        //
        // Safe to claim the tap: the card's reorder drag is bound to its GRIP
        // (`_GripDrag` in dashboard_grid.dart), not to the whole card, so
        // nothing else wants this gesture. Checked before adding it rather
        // than after.
        //
        // A tap on the wedge already showing CLOSES it, because touch has no
        // `onExit` — without that the tooltip is a thing you can open and not
        // put away.
        child: GestureDetector(
          behavior: HitTestBehavior.translucent,
          onTapDown: (details) =>
              _tapAt(details.localPosition, constraints.biggest, slices),
          child: Stack(
            children: [
              Positioned.fill(child: CustomPaint(painter: painter)),
              if (_hovered != null && _hovered! < slices.length)
                _tooltipFor(slices, _hovered!),
            ],
          ),
        ),
      ),
    );
  }

  void _tapAt(Offset position, Size size, List<_Slice> slices) {
    final found = _sliceAt(position, size, slices);

    setState(() {
      _hovered = found == _hovered ? null : found;
      _pointer = position;
    });
  }

  void _moveTo(Offset position, Size size, List<_Slice> slices) {
    final found = _sliceAt(position, size, slices);

    if (found != _hovered || _pointer != position) {
      setState(() {
        _hovered = found;
        _pointer = position;
      });
    }
  }

  /// Value first, label after — the reader already has the wedge under the
  /// pointer and wants the number, which is the legend's hierarchy inverted.
  Widget _tooltipFor(List<_Slice> slices, int index) {
    final theme = Theme.of(context);
    final total = slices.fold<double>(0, (sum, s) => sum + s.total);
    final slice = slices[index];

    return Positioned(
      left: math.max(0, math.min(_pointer.dx + 12, 240)),
      top: math.max(0, _pointer.dy - 34),
      child: IgnorePointer(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          decoration: BoxDecoration(
            color: theme.colorScheme.inverseSurface,
            borderRadius: BorderRadius.circular(4),
          ),
          child: Text(
            '${_shareOf(slice.total, total)} · ${slice.label}',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onInverseSurface,
            ),
          ),
        ),
      ),
    );
  }
}

/// Labels from the payload, coloured with the same theme swatches as the chart.
/// Totals stay on the wire; this does not add them up or print them.
class _ChartLegend extends StatelessWidget {
  const _ChartLegend({required this.labels, required this.colors});

  final List<String> labels;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 12,
      runSpacing: 4,
      children: [
        for (var i = 0; i < labels.length; i++)
          if (labels[i].isNotEmpty)
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: colors[i],
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  labels[i],
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
      ],
    );
  }
}

/// The donut's geometry, defined ONCE.
///
/// The painter needs it to draw and the hit test needs it to answer "which
/// wedge is under the pointer". Two copies of a centre and a radius is how the
/// picture and the pointer come to disagree by a few pixels at the rim, and
/// this file has spent a week removing exactly that shape — a second
/// definition of one rule, agreeing today.
class _PieGeometry {
  const _PieGeometry(this.center, this.ringRadius, this.thickness);

  /// Centre of the donut.
  final Offset center;

  /// Radius of the stroked arc's CENTRELINE, not its outer edge.
  final double ringRadius;

  final double thickness;

  static _PieGeometry? of(Size size) {
    if (size.width <= 0 || size.height <= 0) return null;

    final outer = math.min(size.width, size.height) / 2 - 2;
    final thickness = outer * 0.42;

    return _PieGeometry(
      Offset(size.width / 2, size.height / 2),
      math.max(outer - thickness / 2, 1.0),
      thickness,
    );
  }

  Rect get rect => Rect.fromCircle(center: center, radius: ringRadius);

  /// Whether a point is on the ring, with room to spare.
  ///
  /// The band is padded by 6 logical pixels each way because **the hit target
  /// must be bigger than the mark**: a reader aiming at a ~25px band with a
  /// mouse should not have to be inside the paint. Padding is symmetric, so it
  /// never makes the hole or the outside hittable by more than that margin.
  bool holds(Offset point) {
    final distance = (point - center).distance;
    const slack = 6.0;

    return distance >= ringRadius - thickness / 2 - slack &&
        distance <= ringRadius + thickness / 2 + slack;
  }
}

/// Which wedge sits under [point], or null.
///
/// Walks the same cumulative sweeps the painter walks, from the same start
/// angle, off the same geometry — so the answer cannot drift from the drawing.
int? _sliceAt(Offset point, Size size, List<_Slice> slices) {
  final geometry = _PieGeometry.of(size);
  if (geometry == null || !geometry.holds(point)) return null;

  final total = slices.fold<double>(0, (sum, s) => sum + s.total);
  if (total <= 0) return null;

  // Wedges start at twelve o'clock, which is -pi/2 in canvas angles.
  var fromStart =
      math.atan2(point.dy - geometry.center.dy, point.dx - geometry.center.dx) +
      math.pi / 2;
  while (fromStart < 0) {
    fromStart += 2 * math.pi;
  }

  var walked = 0.0;
  for (var i = 0; i < slices.length; i++) {
    final sweep = (slices[i].total / total) * 2 * math.pi;
    if (sweep <= 0) continue;
    if (fromStart >= walked && fromStart < walked + sweep) return i;
    walked += sweep;
  }

  return null;
}

/// A slice's share, as a reader would say it.
///
/// Whole percents, because a dashboard box is a glance and nobody acts on the
/// first decimal. A non-zero slice that rounds to nothing says "<1%" rather
/// than "0%", which would be a wedge on screen claiming to be absent.
String _shareOf(double value, double total) {
  if (total <= 0) return '0%';

  final percent = value / total * 100;
  if (percent > 0 && percent < 0.5) return '<1%';

  return '${percent.round()}%';
}

/// Everything past the eighth slice becomes ONE tail, named for its count.
///
/// ── Why the first version of this was the same bug ───────────────
///
/// `_colorsFor` gave every slot past the eighth the same neutral, and the
/// legend draws a dot per slice off that list — so ten slices put **two
/// identically-coloured dots against two different labels**. That is the exact
/// defect this whole change removes, reproduced at the far end of it, and
/// UI/UX caught it by reading the code rather than the summary.
///
/// My reasoning had been that grey says "this one is not an identity". That is
/// true of the WEDGE and false of the LEGEND: a legend row is a promise that
/// you can find that name in the picture. Three grey rows cannot keep it, and
/// nothing on screen says the mapping stopped — no ellipsis, again.
///
/// So the tail is one wedge and one row: **"3 more"**. Running out is now
/// something the chart SAYS rather than something it does. The total is
/// preserved, so a pie still sums to the whole.
///
/// Reachable rather than theoretical: `jobs.statuses` is a per-organization
/// setting with no cap, and Email bars one per mailbox, where ten is an
/// ordinary business.
///
/// **Series are deliberately NOT folded, and that is a known gap.** Summing
/// two time series is not summing two wedges — it would invent a line nobody
/// measured — so the honest fold there is a different design. No glance sends
/// more than a handful today (revenue is one series per currency), so it is
/// not reachable now. If one ever does, the legend will start making the same
/// unkeepable promise, and the fix is a design question rather than this
/// function.
List<_Slice> _foldTail(List<_Slice> slices) {
  const identities = 8;
  if (slices.length <= identities) return slices;

  final tail = slices.skip(identities);
  final total = tail.fold<double>(0, (sum, s) => sum + s.total);

  return [
    ...slices.take(identities),
    (label: '${tail.length} more', total: total),
  ];
}

/// One colour per slot, and **never the same colour twice**.
///
/// The palette had six swatches and every call site indexed it with
/// `i % colors.length`, so a seventh slice silently repeated the
/// first's colour. Cycling is the thing this function exists to remove: two
/// wedges in one colour is indistinguishable from a correct chart, and unlike
/// a truncated label there is no ellipsis to say it happened.
///
/// Past the eighth slot the answer is a NEUTRAL, not a ninth hue. A generated
/// hue would land wherever it landed and could sit next to anything; a grey
/// says "this one is not an identity" and stays honest at any count. It is
/// derived from the scheme rather than written down, so it is a neutral in
/// whichever theme the organization is using.
///
/// Nine slices is uncommon but reachable — `jobs.statuses` is a per-
/// organization setting with no cap, and Inspection bars by definition name
/// while Email bars per mailbox. The old code drew that case as two identical
/// wedges; this draws it as **eight** named ones and a single grey tail
/// ([_foldTail] does the folding, so only one slot here is ever neutral).
///
/// *Said "seven named ones" when written — wrong: `palette.take` hands back
/// all eight before the neutral starts. Caught by UI/UX reading the code.*
List<Color> _colorsFor(ColorScheme scheme, {required int slots}) {
  final palette = _palette(scheme);
  if (slots <= palette.length) return palette.take(slots).toList();

  // A mid neutral in either theme: half way between ink and paper.
  final neutral = Color.lerp(scheme.onSurface, scheme.surface, 0.55)!;

  return [...palette, for (var i = palette.length; i < slots; i++) neutral];
}

/// A job status keeps its meaning whatever position it lands in: Done green,
/// Canceled red (deep dive 2026-11-02 #6 had Canceled green and Done pink).
/// Still drawn from [_palette], so the validated set is unchanged; only which
/// slot a status takes is fixed.
List<Color> _statusColours(
  ColorScheme scheme,
  List<Color> colors,
  List<String> labels,
) {
  int? slotOf(String label) {
    final word = label.toLowerCase();
    if (word.contains('cancel')) return 7;
    if (word.contains('pending approval')) return 2;
    if (word.startsWith('done') || word.startsWith('completed')) return 5;
    if (word.contains('progress')) return 0;
    if (word.startsWith('pending')) return 3;
    return null;
  }

  final fixed = [for (final l in labels) slotOf(l)];
  if (fixed.every((s) => s == null)) return colors;

  // Statuses take their slot; every other slice takes the next slot nobody
  // took, so no two slices share a hue.
  final palette = _palette(scheme);
  final taken = fixed.whereType<int>().toSet();
  final free = [
    for (var i = 0; i < palette.length; i++)
      if (!taken.contains(i)) i,
  ];
  final out = [...colors];
  var next = 0;
  for (var i = 0; i < labels.length && i < out.length; i++) {
    final slot = fixed[i];
    if (slot != null) {
      out[i] = palette[slot];
    } else if (next < free.length) {
      out[i] = palette[free[next++]];
    }
  }
  return out;
}

/// Eight categorical hues in a fixed order, per mode. NOT theme roles.
///
/// ── Why this is hex and not `ColorScheme` ────────────────────────
///
/// It read `primary, secondary, tertiary` and their three containers, on the
/// reasoning that a hand-written palette is how purple leaked into the product
/// once. That reasoning is right about brand colour and wrong about this,
/// because **a scheme role is an identity, not a slot in a sequence.**
///
/// Two roles are allowed to be the same colour — `theme.dart:122,128` pins both
/// `primary` and `secondary` to `_cyanDeep` on light, deliberately, so any
/// widget reaching for either lands in the brand's range. Every widget in the
/// product wants exactly that. A chart is the one place that needs six things
/// to look DIFFERENT, and it was reading six names as if they were six colours.
/// Measured: Scheduled and In progress drew identically on every light-theme
/// jobs pie, at the default four statuses. And swatch six was `#E8DDFF`, the
/// violet `theme.dart`'s own docblock says was banished — a colour the app
/// never chose, arriving through a name the app did choose.
///
/// So series identity is not brand identity, and this palette is a fixed,
/// validated, documented set rather than anything derived. Jose asked for
/// colours that do not blend into one another (2026-09-11); these are that,
/// and they are checked rather than chosen by eye.
///
/// ── Validated, not eyeballed ─────────────────────────────────────
///
/// Both rows pass all six categorical checks against this app's real surfaces
/// (`#FFFFFF` light, `#10161B` dark) on the adjacent pairlist, which is the
/// right list for pie, bar and line where only neighbours touch:
///
///   light  worst adjacent CVD ΔE 9.1, normal-vision ΔE 19.6
///   dark   worst adjacent CVD ΔE 8.4, normal-vision ΔE 19.3
///
/// CVD is protanopia/deuteranopia simulated at severity 1.0 — red and green
/// next to each other is the classic failure and these clear it. Three light
/// steps sit below 3:1 on white, which the standard permits only with
/// "relief": identity must not be colour alone. This chart has it — the legend
/// draws a labelled dot per slice and `semanticsLabel` names every slice with
/// its total — so do not remove the legend to save space.
///
/// **If you change a single value here, re-run the validator for BOTH modes.**
/// A palette is a set, not eight independent choices: one substituted hue can
/// fail a pair it sits next to.
///
/// *Sixteen entries, fifteen values: `#008300` is byte-identical in both rows.
/// That is not an oversight and not a contradiction of "dark is its own
/// steps" — the green was validated in both modes and needed no separate step.
/// Noted because "eight per mode" reads as sixteen distinct colours.*
List<Color> _palette(ColorScheme scheme) => scheme.brightness == Brightness.dark
    ? const [
        Color(0xFF3987E5), // blue
        Color(0xFFD95926), // orange
        Color(0xFF199E70), // aqua
        Color(0xFFC98500), // yellow
        Color(0xFFD55181), // magenta
        Color(0xFF008300), // green
        Color(0xFF9085E9), // violet
        Color(0xFFE66767), // red
      ]
    : const [
        Color(0xFF2A78D6), // blue
        Color(0xFFEB6834), // orange
        Color(0xFF1BAF7A), // aqua
        Color(0xFFEDA100), // yellow
        Color(0xFFE87BA4), // magenta
        Color(0xFF008300), // green
        Color(0xFF4A3AA7), // violet
        Color(0xFFE34948), // red
      ];

/// Whether this presentation has anything to draw. THE definition, once.
///
/// It lived in three places until 2026-09-09: here, and as a private
/// `_chartable` in `screens/dashboard_screen.dart` and in
/// `screens/hr/dashboard_tab.dart`. The two screens agreed with each other
/// and **both disagreed with this one**, which is what made it worth fixing
/// rather than worth a test asserting the copies matched — you cannot pin
/// three things as agreeing when two of them already do not.
///
/// The disagreement was live, in the case Scheduling asked about: a glance
/// with real categories and a zero in every one made the screens say draw and
/// this say do not, so a chart went into the tree and then declined to paint.
/// The stricter rule is the right one — a box with categories and nothing in
/// them should draw no chart — so it is the one that survived.
bool _drawable(
  String presentation,
  List<_Slice> slices,
  List<_Series> series,
) => switch (presentation) {
  'pie' || 'bar' => slices.any((s) => s.total > 0),
  'line' || 'timeline' => series.any((s) => s.points.isNotEmpty),
  _ => false,
};

List<_Slice> _readSlices(List<dynamic>? raw) {
  if (raw == null) return const [];

  return [
    for (final item in raw)
      if (item is Map)
        (
          label: '${item['label'] ?? ''}',
          total: (item['total'] as num?)?.toDouble() ?? 0,
        ),
  ];
}

List<_Series> _readSeries(List<dynamic>? series) {
  if (series == null || series.isEmpty) return const [];

  final out = <_Series>[];
  for (final item in series) {
    if (item is! Map) continue;
    final raw = item['points'];
    if (raw is! List) continue;

    final points = <_Point>[];
    for (final point in raw) {
      if (point is! Map) continue;
      final atRaw = '${point['at'] ?? ''}';
      // Year buckets are `Y-m` (`2026-08`). DateTime.tryParse skips those.
      final at = DateTime.tryParse(atRaw) ?? DateTime.tryParse('$atRaw-01');
      if (at == null) continue;
      points.add((at: at, value: (point['value'] as num?)?.toDouble() ?? 0));
    }
    if (points.isEmpty) continue;
    points.sort((a, b) => a.at.compareTo(b.at));
    out.add(_Series('${item['label'] ?? ''}', points));
  }
  return out;
}

typedef _Slice = ({String label, double total});

typedef _Point = ({DateTime at, double value});

class _Series {
  const _Series(this.label, this.points);

  final String label;
  final List<_Point> points;
}

class _DashboardChartPainter extends CustomPainter {
  _DashboardChartPainter({
    required this.presentation,
    required this.slices,
    required this.spokenSlices,
    required this.series,
    required this.colors,
    required this.track,
    required this.onLine,
    this.highlighted,
  });

  final String presentation;

  /// What is DRAWN — folded to eight identities and a tail.
  final List<_Slice> slices;

  /// What is READ ALOUD — every category, unfolded.
  ///
  /// The fold exists because two wedges cannot share a colour and two legend
  /// rows cannot share a swatch. **A screen reader has neither problem**: it
  /// reads a list, and a list has no adjacency and no colour. Folding what it
  /// hears would solve a drawing problem by deleting data from the one channel
  /// that was never broken.
  ///
  /// So the non-visual channel is deliberately MORE complete than the visual
  /// one, rather than a copy of it. Found by UI/UX on the third pass of this
  /// change, after I folded before the painter was built and never asked what
  /// else read those slices — the same move as reading a guard and not the
  /// widget, and as reasoning about a drawing without asking what the header
  /// reads. **The thing that describes a change reads from a different source
  /// than the thing that is the change.**
  final List<_Slice> spokenSlices;

  final List<_Series> series;
  final List<Color> colors;
  final Color track;
  final Color onLine;

  /// The wedge under the pointer, drawn thicker so it answers the hover.
  final int? highlighted;

  bool get isDrawable => _drawable(presentation, slices, series);

  String get semanticsLabel {
    if (presentation == 'pie' || presentation == 'bar') {
      // The SHARE is spoken as well as the total, because hover is a mouse
      // fact and a tooltip must enhance rather than gate. Every number a
      // pointer can reveal is reachable without a pointer — which is the only
      // thing that makes the touch gap in `_hoverable` an inconvenience
      // instead of a feature somebody cannot have.
      final total = spokenSlices.fold<double>(0, (sum, s) => sum + s.total);

      return [
        for (final slice in spokenSlices)
          '${slice.label} ${slice.total.round()} '
              '(${_shareOf(slice.total, total)})',
      ].join(', ');
    }

    return [
      for (final row in series)
        [
          if (row.label.isNotEmpty) row.label,
          for (final point in row.points) '${point.value.round()}',
        ].join(' '),
    ].join(', ');
  }

  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0 || !isDrawable) return;

    switch (presentation) {
      case 'pie':
        _paintPie(canvas, size);
      case 'bar':
        _paintBar(canvas, size);
      case 'line':
        _paintLine(canvas, size, markers: false);
      case 'timeline':
        _paintLine(canvas, size, markers: true);
    }
  }

  void _paintPie(Canvas canvas, Size size) {
    final total = slices.fold<double>(0, (sum, s) => sum + s.total);
    if (total <= 0) return;

    final geometry = _PieGeometry.of(size);
    if (geometry == null) return;

    var start = -math.pi / 2;

    for (var i = 0; i < slices.length; i++) {
      final sweep = (slices[i].total / total) * 2 * math.pi;
      if (sweep <= 0) continue;

      // The hovered wedge grows on BOTH sides of the same centreline, so it
      // reads as lifted without moving: no angle changes, nothing overlaps a
      // neighbour, and the shares on screen stay the shares in the data.
      // Deliberately not a colour change — the palette is validated as a set
      // and a lightened member is a value nobody checked.
      final lifted = i == highlighted;

      canvas.drawArc(
        geometry.rect,
        start,
        sweep,
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth =
              geometry.thickness * (lifted ? DashboardChart.liftFactor : 1.0)
          ..strokeCap = StrokeCap.butt
          ..color = colors[i],
      );
      start += sweep;
    }
  }

  void _paintBar(Canvas canvas, Size size) {
    if (slices.isEmpty) return;

    final max = slices.fold<double>(0, (m, s) => math.max(m, s.total));
    if (max <= 0) return;

    canvas.drawLine(
      Offset(0, size.height - 0.5),
      Offset(size.width, size.height - 0.5),
      Paint()
        ..color = track
        ..strokeWidth = 1,
    );

    const gap = 8.0;
    final n = slices.length;
    final barWidth = math.max((size.width - gap * (n + 1)) / n, 1.0);
    const radius = Radius.circular(4);
    final usable = size.height - 8;

    for (var i = 0; i < n; i++) {
      final height = (slices[i].total / max) * usable;
      final left = gap + i * (barWidth + gap);
      final top = size.height - height;

      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(left, top, barWidth, height),
          radius,
        ),
        Paint()..color = colors[i],
      );
    }
  }

  void _paintLine(Canvas canvas, Size size, {required bool markers}) {
    final allPoints = [for (final row in series) ...row.points];
    if (allPoints.isEmpty) return;

    canvas.drawLine(
      Offset(8, size.height - 8),
      Offset(size.width - 8, size.height - 8),
      Paint()
        ..color = track
        ..strokeWidth = 1,
    );

    const inset = 8.0;
    final inner = Rect.fromLTWH(
      inset,
      inset,
      math.max(size.width - inset * 2, 1.0),
      math.max(size.height - inset * 2, 1.0),
    );

    var minMs = allPoints.first.at.millisecondsSinceEpoch.toDouble();
    var maxMs = minMs;
    var maxValue = 0.0;
    for (final point in allPoints) {
      final ms = point.at.millisecondsSinceEpoch.toDouble();
      if (ms < minMs) minMs = ms;
      if (ms > maxMs) maxMs = ms;
      if (point.value > maxValue) maxValue = point.value;
    }

    Offset at(_Point point) {
      final x = maxMs == minMs
          ? inner.center.dx
          : inner.left +
                ((point.at.millisecondsSinceEpoch - minMs) / (maxMs - minMs)) *
                    inner.width;
      final y = maxValue <= 0
          ? inner.bottom
          : inner.bottom - (point.value / maxValue) * inner.height;
      return Offset(x, y);
    }

    for (var s = 0; s < series.length; s++) {
      final points = series[s].points;
      if (points.isEmpty) continue;
      final color = colors[s];

      final path = Path();
      for (var i = 0; i < points.length; i++) {
        final o = at(points[i]);
        i == 0 ? path.moveTo(o.dx, o.dy) : path.lineTo(o.dx, o.dy);
      }

      if (points.length > 1) {
        final fill = Path.from(path)
          ..lineTo(at(points.last).dx, inner.bottom)
          ..lineTo(at(points.first).dx, inner.bottom)
          ..close();

        canvas.drawPath(fill, Paint()..color = color.withValues(alpha: 0.18));
      }

      canvas.drawPath(
        path,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round
          ..color = color,
      );

      if (markers || points.length == 1) {
        final radius = markers ? 4.5 : 3.0;
        for (final point in points) {
          final o = at(point);
          canvas.drawCircle(o, radius, Paint()..color = color);
          canvas.drawCircle(
            o,
            radius,
            Paint()
              ..style = PaintingStyle.stroke
              ..strokeWidth = 1.5
              ..color = onLine,
          );
        }
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashboardChartPainter old) =>
      old.presentation != presentation ||
      old.highlighted != highlighted ||
      old.track != track ||
      old.onLine != onLine ||
      !listEquals(old.slices, slices) ||
      !_sameSeries(old.series, series) ||
      !listEquals(old.colors, colors);
}

bool _sameSeries(List<_Series> a, List<_Series> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i].label != b[i].label || !listEquals(a[i].points, b[i].points)) {
      return false;
    }
  }
  return true;
}
