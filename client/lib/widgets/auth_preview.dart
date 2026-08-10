import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../core/theme.dart';

/// The glimpse of the product on the sign-in panel.
///
/// ── Why a preview and not a feature list ─────────────────────────
///
/// A row of nouns with arrows between them — Customers → Jobs → Automations —
/// says nothing a person could not guess from the word "CRM". It is the shape
/// every SaaS sign-in page has, which is exactly why it reads as decoration.
///
/// This shows the thing instead: the week's work as a bar per day, what is
/// booked, what it is worth, and the next three jobs with times and towns. It
/// is the same information the dashboard actually renders, drawn small.
///
/// Everything is drawn — no screenshots. A screenshot goes stale the first time
/// the real screen changes, and nobody ever remembers to retake it.
///
/// The numbers are illustrative and deliberately modest: a page promising
/// "$2.4M this week" to a three-van business is telling them the product is not
/// for them.
class CustomerPreview extends StatelessWidget {
  const CustomerPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return const _PreviewFrame(
      children: [
        _WeekCard(),
        SizedBox(height: 12),
        Row(children: [
          Expanded(child: _StatCard(label: 'Scheduled', value: '18', trailing: '3 today')),
          SizedBox(width: 12),
          Expanded(child: _StatCard(label: 'Invoiced', value: '\$24,380', spark: true)),
        ]),
        SizedBox(height: 12),
        _UpNextCard(),
      ],
    );
  }
}

/// The same idea for the control plane, showing what that face is FOR.
///
/// Deliberately different content: an operator signing in here is not looking
/// at one business's week, they are looking at every business at once.
class StaffPreview extends StatelessWidget {
  const StaffPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return const _PreviewFrame(
      children: [
        _OrganizationsCard(),
        SizedBox(height: 12),
        _HealthCard(),
      ],
    );
  }
}

/// Holds the mock at a fixed design width and scales it down rather than
/// letting it overflow. A preview that breaks its own layout on a narrow panel
/// argues against the product it is advertising.
class _PreviewFrame extends StatelessWidget {
  const _PreviewFrame({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: SizedBox(
        width: 400,
        child: Column(mainAxisSize: MainAxisSize.min, children: children),
      ),
    );
  }
}

// ── the shared card shell ────────────────────────────────────────

class _Card extends StatelessWidget {
  const _Card({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        // Barely-there fill over the gradient rather than a solid panel: a
        // opaque card would read as a separate window sitting on top of the
        // page instead of part of it.
        color: Colors.white.withValues(alpha: 0.055),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
      ),
      child: child,
    );
  }
}

class _CardTitle extends StatelessWidget {
  const _CardTitle(this.text, {this.trailing});

  final String text;
  final String? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Text(
          text,
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.62),
            fontSize: 11,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.6,
          ),
        ),
        const Spacer(),
        if (trailing != null)
          Text(
            trailing!,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
      ],
    );
  }
}

// ── the week ─────────────────────────────────────────────────────

class _WeekCard extends StatelessWidget {
  const _WeekCard();

  // Mon–Sun. The weekend dips because field work does; a flat seven-bar chart
  // looks like placeholder data, which is what it would be.
  static const _days = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
  static const _values = [0.55, 0.8, 0.65, 1.0, 0.85, 0.35, 0.15];

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _CardTitle('THIS WEEK', trailing: '42 jobs'),
          const SizedBox(height: 16),
          SizedBox(
            // bar (56) + gap (8) + the day label's line box. It was 74,
            // which is 5px short of what the column actually needs.
            height: 82,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                for (var i = 0; i < _days.length; i++) ...[
                  if (i > 0) const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        Container(
                          height: 56 * _values[i],
                          decoration: BoxDecoration(
                            // Today reads as the accent; the rest recede. One
                            // highlighted bar is the whole reason a bar chart
                            // beats a number here.
                            color: i == 3
                                ? EzBiziTheme.accent
                                : Colors.white.withValues(alpha: 0.22),
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          _days[i],
                          style: TextStyle(
                            color: Colors.white.withValues(
                                alpha: i == 3 ? 0.9 : 0.4),
                            fontSize: 10,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ── a number, optionally with a trend ────────────────────────────

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.label,
    required this.value,
    this.trailing,
    this.spark = false,
  });

  final String label;
  final String value;
  final String? trailing;
  final bool spark;

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _CardTitle(label.toUpperCase()),
          const SizedBox(height: 10),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 24,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 8),
          if (spark)
            SizedBox(
              height: 22,
              width: double.infinity,
              child: CustomPaint(painter: _SparkPainter()),
            )
          else
            Text(
              trailing ?? '',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.45),
                fontSize: 11,
              ),
            ),
        ],
      ),
    );
  }
}

/// A single stroked line with a soft fill under it.
///
/// Hand-painted rather than pulled from a charting package: it is seven points
/// on a login screen, and a dependency for that is a dependency to patch,
/// audit and carry forever.
class _SparkPainter extends CustomPainter {
  static const _points = [0.35, 0.5, 0.42, 0.62, 0.55, 0.78, 0.92];

  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0) return;

    final path = Path();
    final step = size.width / (_points.length - 1);

    for (var i = 0; i < _points.length; i++) {
      final x = step * i;
      final y = size.height - (_points[i] * size.height);

      i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
    }

    final fill = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();

    canvas.drawPath(
      fill,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            EzBiziTheme.accent.withValues(alpha: 0.35),
            EzBiziTheme.accent.withValues(alpha: 0),
          ],
        ).createShader(Offset.zero & size),
    );

    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..color = EzBiziTheme.accent,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ── what is next ─────────────────────────────────────────────────

class _UpNextCard extends StatelessWidget {
  const _UpNextCard();

  static const _rows = [
    ('09:00', 'Chimney sweep', 'Hartford'),
    ('11:30', 'Level 2 inspection', 'Stamford'),
    ('14:00', 'Cap installation', 'Danbury'),
  ];

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _CardTitle('COMING UP', trailing: 'Today'),
          const SizedBox(height: 14),
          for (var i = 0; i < _rows.length; i++) ...[
            if (i > 0) const SizedBox(height: 12),
            Row(
              children: [
                Text(
                  _rows[i].$1,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.55),
                    fontSize: 12,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
                const SizedBox(width: 14),
                Container(
                  width: 5,
                  height: 5,
                  decoration: BoxDecoration(
                    color: EzBiziTheme.accent,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _rows[i].$2,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                  ),
                ),
                Text(
                  _rows[i].$3,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.45),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

// ── the control plane's version ──────────────────────────────────

class _OrganizationsCard extends StatelessWidget {
  const _OrganizationsCard();

  static const _rows = [
    ('Northeast Hearth Co.', '14 users', true),
    ('Coastal Services Group', '31 users', true),
    ('Blue Ridge Chimney', '6 users', false),
  ];

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _CardTitle('ORGANIZATIONS', trailing: '3 active'),
          const SizedBox(height: 14),
          for (var i = 0; i < _rows.length; i++) ...[
            if (i > 0) const SizedBox(height: 12),
            Row(
              children: [
                Container(
                  width: 6,
                  height: 6,
                  decoration: BoxDecoration(
                    // Amber, not red: a trial running out is a state, not a
                    // fault, and colouring it as a fault trains people to
                    // ignore the colour that means one.
                    color: _rows[i].$3
                        ? EzBiziTheme.accent
                        : const Color(0xFFE0A030),
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _rows[i].$1,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                  ),
                ),
                Text(
                  _rows[i].$2,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.45),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _HealthCard extends StatelessWidget {
  const _HealthCard();

  static const _rows = [
    ('Backups', 'Verified 4h ago'),
    ('Automation outbox', 'Nothing waiting'),
    ('Mail', 'Not configured'),
  ];

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _CardTitle('HEALTH'),
          const SizedBox(height: 14),
          for (var i = 0; i < _rows.length; i++) ...[
            if (i > 0) const SizedBox(height: 12),
            Row(
              children: [
                Icon(
                  // The third one is honestly unhealthy. A preview showing
                  // three green ticks would be the only screen in this app that
                  // lies about the state of the system.
                  i == 2 ? Icons.error_outline : Icons.check_circle_outline,
                  size: 15,
                  color: i == 2
                      ? const Color(0xFFE0A030)
                      : EzBiziTheme.accent,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _rows[i].$1,
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                  ),
                ),
                Text(
                  _rows[i].$2,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.45),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// A faint blueprint grid behind the panel.
///
/// Replaces two blurred circles, which are the default "modern SaaS" backdrop
/// and say nothing. A ruled grid is what operations software looks like —
/// schedules, rosters, timesheets — and it gives the floating cards something
/// to sit against so they read as depth rather than as flat translucent boxes.
class GridBackdrop extends StatelessWidget {
  const GridBackdrop({super.key});

  @override
  Widget build(BuildContext context) =>
      CustomPaint(painter: _GridPainter(), child: const SizedBox.expand());
}

class _GridPainter extends CustomPainter {
  static const _spacing = 34.0;

  @override
  void paint(Canvas canvas, Size size) {
    final line = Paint()
      ..color = Colors.white.withValues(alpha: 0.035)
      ..strokeWidth = 1;

    for (var x = 0.0; x < size.width; x += _spacing) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), line);
    }

    for (var y = 0.0; y < size.height; y += _spacing) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), line);
    }

    // One glow, warming the corner the cards sit in. Radial rather than a
    // rectangle so the grid fades out instead of stopping at an edge.
    canvas.drawCircle(
      Offset(size.width * 0.72, size.height * 0.18),
      math.max(size.width, size.height) * 0.45,
      Paint()
        ..shader = RadialGradient(
          colors: [
            EzBiziTheme.accent.withValues(alpha: 0.14),
            EzBiziTheme.accent.withValues(alpha: 0),
          ],
        ).createShader(Rect.fromCircle(
          center: Offset(size.width * 0.72, size.height * 0.18),
          radius: math.max(size.width, size.height) * 0.45,
        )),
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
