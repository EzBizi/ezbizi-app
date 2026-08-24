import 'package:flutter/material.dart';

/// Brand mark for a social network, keyed by the server's `value`.
///
/// Drawn here rather than loaded from the network: the catalogue has to render
/// with no extra request, and a letter in a coloured square is the same mark
/// on every tile.
class SocialNetworkMark extends StatelessWidget {
  const SocialNetworkMark({super.key, required this.network, this.size = 32});

  /// Catalogue `value` — `facebook_page`, `instagram_professional`, …
  final String network;
  final double size;

  @override
  Widget build(BuildContext context) {
    final spec = _spec(network);
    return SizedBox(
      width: size,
      height: size,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: spec.color,
          gradient: spec.gradient,
          borderRadius: BorderRadius.circular(size * 0.22),
        ),
        child: CustomPaint(painter: spec.painter),
      ),
    );
  }
}

class _Spec {
  const _Spec({required this.painter, this.color, this.gradient});

  final Color? color;
  final Gradient? gradient;
  final CustomPainter painter;
}

_Spec _spec(String network) {
  return switch (network) {
    'facebook_page' => _Spec(
      color: const Color(0xFF1877F2),
      painter: _GlyphPainter('f', const Offset(0.42, 0.46)),
    ),
    'instagram_professional' => const _Spec(
      gradient: LinearGradient(
        begin: Alignment.bottomLeft,
        end: Alignment.topRight,
        colors: [Color(0xFFF58529), Color(0xFFDD2A7B), Color(0xFF8134AF)],
      ),
      painter: _InstagramPainter(),
    ),
    'threads' => _Spec(
      color: const Color(0xFF000000),
      painter: _GlyphPainter('@', const Offset(0.50, 0.48), size: 0.58),
    ),
    'linkedin' => _Spec(
      color: const Color(0xFF0A66C2),
      painter: _GlyphPainter('in', const Offset(0.50, 0.48), size: 0.42),
    ),
    'tiktok' => const _Spec(
      color: Color(0xFF010101),
      painter: _TikTokPainter(),
    ),
    'pinterest' => _Spec(
      color: const Color(0xFFE60023),
      painter: _GlyphPainter('P', const Offset(0.50, 0.50)),
    ),
    'youtube' => const _Spec(
      color: Color(0xFFFF0000),
      painter: _YouTubePainter(),
    ),
    'x' => const _Spec(
      color: Color(0xFF000000),
      painter: _XPainter(),
    ),
    'bluesky' => const _Spec(
      color: Color(0xFF1185FE),
      painter: _BlueskyPainter(),
    ),
    _ => _Spec(
      color: const Color(0xFF5F6368),
      painter: _GlyphPainter('?', const Offset(0.50, 0.48)),
    ),
  };
}

class _GlyphPainter extends CustomPainter {
  _GlyphPainter(this.glyph, this.center, {this.size = 0.62});

  final String glyph;
  final Offset center;
  final double size;

  @override
  void paint(Canvas canvas, Size dim) {
    final tp = TextPainter(
      text: TextSpan(
        text: glyph,
        style: TextStyle(
          color: Colors.white,
          fontSize: dim.shortestSide * size,
          fontWeight: FontWeight.w800,
          height: 1,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    tp.paint(
      canvas,
      Offset(
        dim.width * center.dx - tp.width / 2,
        dim.height * center.dy - tp.height / 2,
      ),
    );
  }

  @override
  bool shouldRepaint(covariant _GlyphPainter old) => old.glyph != glyph;
}

class _InstagramPainter extends CustomPainter {
  const _InstagramPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final paint = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = s * 0.08;
    final inset = s * 0.22;
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(inset, inset, s - inset * 2, s - inset * 2),
        Radius.circular(s * 0.18),
      ),
      paint,
    );
    canvas.drawCircle(Offset(s * 0.5, s * 0.5), s * 0.14, paint);
    canvas.drawCircle(
      Offset(s * 0.70, s * 0.30),
      s * 0.04,
      Paint()..color = Colors.white,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _YouTubePainter extends CustomPainter {
  const _YouTubePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final path = Path()
      ..moveTo(s * 0.40, s * 0.32)
      ..lineTo(s * 0.72, s * 0.50)
      ..lineTo(s * 0.40, s * 0.68)
      ..close();
    canvas.drawPath(path, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _XPainter extends CustomPainter {
  const _XPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final paint = Paint()
      ..color = Colors.white
      ..strokeWidth = s * 0.12
      ..strokeCap = StrokeCap.round;
    canvas.drawLine(Offset(s * 0.28, s * 0.28), Offset(s * 0.72, s * 0.72), paint);
    canvas.drawLine(Offset(s * 0.72, s * 0.28), Offset(s * 0.28, s * 0.72), paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _TikTokPainter extends CustomPainter {
  const _TikTokPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final paint = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = s * 0.10
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    canvas.drawCircle(Offset(s * 0.40, s * 0.62), s * 0.16, paint);
    canvas.drawLine(Offset(s * 0.56, s * 0.62), Offset(s * 0.56, s * 0.28), paint);
    canvas.drawLine(Offset(s * 0.56, s * 0.28), Offset(s * 0.72, s * 0.36), paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _BlueskyPainter extends CustomPainter {
  const _BlueskyPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final paint = Paint()..color = Colors.white;
    final left = Path()
      ..moveTo(s * 0.50, s * 0.55)
      ..cubicTo(s * 0.22, s * 0.18, s * 0.08, s * 0.42, s * 0.32, s * 0.72)
      ..cubicTo(s * 0.40, s * 0.82, s * 0.50, s * 0.72, s * 0.50, s * 0.55);
    final right = Path()
      ..moveTo(s * 0.50, s * 0.55)
      ..cubicTo(s * 0.78, s * 0.18, s * 0.92, s * 0.42, s * 0.68, s * 0.72)
      ..cubicTo(s * 0.60, s * 0.82, s * 0.50, s * 0.72, s * 0.50, s * 0.55);
    canvas.drawPath(left, paint);
    canvas.drawPath(right, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
