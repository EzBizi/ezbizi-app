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

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final parsed = _readSlices(slices);
    final colors = _palette(scheme);
    final painter = _DashboardChartPainter(
      presentation: presentation,
      slices: parsed,
      points: _readPoints(series),
      colors: colors,
      track: scheme.outlineVariant,
      onLine: scheme.surface,
    );

    if (!painter.isDrawable) return const SizedBox.shrink();

    final named = presentation == 'pie' || presentation == 'bar';

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
              _SliceLegend(slices: parsed, colors: colors),
            ],
          ],
        ),
      ),
    );
  }
}

/// Labels from the payload, coloured with the same theme swatches as the chart.
/// Totals stay on the wire; this does not add them up or print them.
class _SliceLegend extends StatelessWidget {
  const _SliceLegend({required this.slices, required this.colors});

  final List<_Slice> slices;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 12,
      runSpacing: 4,
      children: [
        for (var i = 0; i < slices.length; i++)
          if (slices[i].label.isNotEmpty)
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
                  slices[i].label,
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

List<_Point> _readPoints(List<dynamic>? series) {
  if (series == null || series.isEmpty) return const [];

  final first = series.first;
  if (first is! Map) return const [];

  final raw = first['points'];
  if (raw is! List) return const [];

  final points = <_Point>[];
  for (final item in raw) {
    if (item is! Map) continue;
    final atRaw = '${item['at'] ?? ''}';
    // Year buckets are `Y-m` (`2026-08`). DateTime.tryParse skips those.
    final at = DateTime.tryParse(atRaw) ?? DateTime.tryParse('$atRaw-01');
    if (at == null) continue;
    points.add(_Point(at, (item['value'] as num?)?.toDouble() ?? 0));
  }

  points.sort((a, b) => a.at.compareTo(b.at));
  return points;
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

class _DashboardChartPainter extends CustomPainter {
  _DashboardChartPainter({
    required this.presentation,
    required this.slices,
    required this.points,
    required this.colors,
    required this.track,
    required this.onLine,
  });

  final String presentation;
  final List<_Slice> slices;
  final List<_Point> points;
  final List<Color> colors;
  final Color track;
  final Color onLine;

  bool get isDrawable => switch (presentation) {
        'pie' || 'bar' => slices.any((s) => s.total > 0),
        'line' || 'timeline' => points.isNotEmpty,
        _ => false,
      };

  String get semanticsLabel {
    if (presentation == 'pie' || presentation == 'bar') {
      return [
        for (final slice in slices) '${slice.label} ${slice.total.round()}',
      ].join(', ');
    }

    return [
      for (final point in points) '${point.value.round()}',
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
    if (points.isEmpty) return;

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

    final minMs = points.first.at.millisecondsSinceEpoch.toDouble();
    final maxMs = points.last.at.millisecondsSinceEpoch.toDouble();
    final maxValue = points.fold<double>(0, (m, p) => math.max(m, p.value));

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
        Paint()..color = colors.first.withValues(alpha: 0.18),
      );
    }

    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..color = colors.first,
    );

    if (markers || points.length == 1) {
      final radius = markers ? 4.5 : 3.0;
      for (final point in points) {
        final o = at(point);
        canvas.drawCircle(o, radius, Paint()..color = colors.first);
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

  @override
  bool shouldRepaint(covariant _DashboardChartPainter old) =>
      old.presentation != presentation ||
      old.track != track ||
      old.onLine != onLine ||
      !_sameSlices(old.slices, slices) ||
      !_samePoints(old.points, points) ||
      !_sameColors(old.colors, colors);
}

bool _sameSlices(List<_Slice> a, List<_Slice> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i].label != b[i].label || a[i].total != b[i].total) return false;
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
