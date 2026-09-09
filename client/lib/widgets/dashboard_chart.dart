import 'dart:math' as math;

import 'package:flutter/material.dart';

/// A pie, bar, line, or timeline drawn from the server’s slices / series.
///
/// The numbers are already aggregated. This plots them; it does not add them
/// up, drop a zero slice, or invent a bucket the payload omitted.
///
/// Colours come off [ThemeData.colorScheme]. A palette of hex values here
/// would ignore an organization’s own theme (§12) and, from a generated
/// scheme, is how purple leaked into the product once already.
class DashboardChart extends StatelessWidget {
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
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final parsedSlices = _readSlices(slices);
    final parsedSeries = _readSeries(series);
    final colors = _palette(scheme);
    final painter = _DashboardChartPainter(
      presentation: presentation,
      slices: parsedSlices,
      series: parsedSeries,
      colors: colors,
      track: scheme.outlineVariant,
      onLine: scheme.surface,
    );

    if (!painter.isDrawable) return const SizedBox.shrink();

    final legendLabels = presentation == 'pie' || presentation == 'bar'
        ? [for (final slice in parsedSlices) slice.label]
        : [for (final row in parsedSeries) row.label];
    final named = legendLabels.any((label) => label.isNotEmpty);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Semantics(
        label: painter.semanticsLabel,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              height: height,
              width: double.infinity,
              child: CustomPaint(painter: painter),
            ),
            if (named) ...[
              const SizedBox(height: 8),
              _ChartLegend(labels: legendLabels, colors: colors),
            ],
          ],
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
                    color: colors[i % colors.length],
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

List<Color> _palette(ColorScheme scheme) => [
      scheme.primary,
      scheme.secondary,
      scheme.tertiary,
      scheme.primaryContainer,
      scheme.secondaryContainer,
      scheme.tertiaryContainer,
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
bool _drawable(String presentation, List<_Slice> slices, List<_Series> series) =>
    switch (presentation) {
      'pie' || 'bar' => slices.any((s) => s.total > 0),
      'line' || 'timeline' => series.any((s) => s.points.isNotEmpty),
      _ => false,
    };

List<_Slice> _readSlices(List<dynamic>? raw) {
  if (raw == null) return const [];

  return [
    for (final item in raw)
      if (item is Map)
        _Slice(
          '${item['label'] ?? ''}',
          (item['total'] as num?)?.toDouble() ?? 0,
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
      points.add(_Point(at, (point['value'] as num?)?.toDouble() ?? 0));
    }
    if (points.isEmpty) continue;
    points.sort((a, b) => a.at.compareTo(b.at));
    out.add(_Series('${item['label'] ?? ''}', points));
  }
  return out;
}

class _Slice {
  const _Slice(this.label, this.total);

  final String label;
  final double total;
}

class _Point {
  const _Point(this.at, this.value);

  final DateTime at;
  final double value;
}

class _Series {
  const _Series(this.label, this.points);

  final String label;
  final List<_Point> points;
}

class _DashboardChartPainter extends CustomPainter {
  _DashboardChartPainter({
    required this.presentation,
    required this.slices,
    required this.series,
    required this.colors,
    required this.track,
    required this.onLine,
  });

  final String presentation;
  final List<_Slice> slices;
  final List<_Series> series;
  final List<Color> colors;
  final Color track;
  final Color onLine;

  bool get isDrawable => _drawable(presentation, slices, series);

  String get semanticsLabel {
    if (presentation == 'pie' || presentation == 'bar') {
      return [
        for (final slice in slices) '${slice.label} ${slice.total.round()}',
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

    final center = Offset(size.width / 2, size.height / 2);
    final outer = math.min(size.width, size.height) / 2 - 2;
    final thickness = outer * 0.42;
    final rect = Rect.fromCircle(
      center: center,
      radius: math.max(outer - thickness / 2, 1.0),
    );

    var start = -math.pi / 2;

    for (var i = 0; i < slices.length; i++) {
      final sweep = (slices[i].total / total) * 2 * math.pi;
      if (sweep <= 0) continue;

      canvas.drawArc(
        rect,
        start,
        sweep,
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = thickness
          ..strokeCap = StrokeCap.butt
          ..color = colors[i % colors.length],
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
        Paint()..color = colors[i % colors.length],
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
      final color = colors[s % colors.length];

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

        canvas.drawPath(
          fill,
          Paint()..color = color.withValues(alpha: 0.18),
        );
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
      old.track != track ||
      old.onLine != onLine ||
      !_sameSlices(old.slices, slices) ||
      !_sameSeries(old.series, series) ||
      !_sameColors(old.colors, colors);
}

bool _sameSlices(List<_Slice> a, List<_Slice> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i].label != b[i].label || a[i].total != b[i].total) return false;
  }
  return true;
}

bool _sameSeries(List<_Series> a, List<_Series> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i].label != b[i].label || !_samePoints(a[i].points, b[i].points)) {
      return false;
    }
  }
  return true;
}

bool _samePoints(List<_Point> a, List<_Point> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i].at != b[i].at || a[i].value != b[i].value) return false;
  }
  return true;
}

bool _sameColors(List<Color> a, List<Color> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i] != b[i]) return false;
  }
  return true;
}
