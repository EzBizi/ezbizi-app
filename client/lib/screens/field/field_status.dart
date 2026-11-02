import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// One person's state on the Field map (Jose, 2026-10-26), shared by the
/// list dot, the map pin, the technician bar and the legend.
///
/// Every rule reads a server flag, so the thresholds are the tenant's Field
/// settings: `stopped` is `field.stopped_minutes` (default 20) and `silent`
/// is `field.lost_signal_minutes` (default 15), both in FieldEvaluator.
enum FieldPersonState {
  /// Should be reporting and has no position (`last_seen_at` null). Solid red.
  notReported('Not reported', EzBiziTheme.fieldRed, Tone.bad),

  /// Was reporting and has stopped: the server's `silent` flag, raised only
  /// for a phone that should be reporting. Warning yellow, dashed ring
  /// (Jose, 2026-10-30), so it no longer reads as not reported.
  lostSignal('Lost signal', EzBiziTheme.fieldYellow, Tone.caution),

  /// Stopped too long (`stopped`). Purple.
  stopped('Stopped too long', EzBiziTheme.fieldPurple, Tone.review),

  /// Reporting: a fix in the last 30 minutes (`fresh`). Green.
  ongoing('Reporting', EzBiziTheme.fieldGreen, Tone.ok),

  /// Not expected to report: `should_track` false when the server sends it,
  /// or a stale fix the server raised no lost-signal alert for. Solid grey.
  offline('Offline', EzBiziTheme.fieldSlate, Tone.neutral);

  const FieldPersonState(this.label, this.colour, this.tone);

  final String label;
  final Color colour;
  final Tone tone;

  /// Readable ink for text in this state on a panel.
  Color text(BuildContext context) => ToneColours.of(context, tone).ink;
}

/// `should_track` is not in GET /field/map yet (only `clocked_in_at`, which
/// says nothing in work-hours mode), so until it is, everybody with no
/// position reads as not reported.
FieldPersonState fieldPersonState(Map<String, dynamic> p) {
  final fresh = p['fresh'] == true;
  if (p['should_track'] == false && !fresh) return FieldPersonState.offline;
  if (p['last_seen_at'] == null) return FieldPersonState.notReported;
  if (p['stopped'] == true) return FieldPersonState.stopped;
  if (p['silent'] == true) return FieldPersonState.lostSignal;
  if (!fresh) return FieldPersonState.offline;
  return FieldPersonState.ongoing;
}

/// List order (Jose, 2026-10-30): reporting, stopped, lost signal, not
/// reported, then offline; by name within each group.
const fieldListOrder = [
  FieldPersonState.ongoing,
  FieldPersonState.stopped,
  FieldPersonState.lostSignal,
  FieldPersonState.notReported,
  FieldPersonState.offline,
];

List<Map<String, dynamic>> fieldSortByAttention(
  Iterable<Map<String, dynamic>> people,
) => [...people]
  ..sort((a, b) {
    final s =
        fieldListOrder.indexOf(fieldPersonState(a)) -
        fieldListOrder.indexOf(fieldPersonState(b));
    if (s != 0) return s;
    return '${(a['user'] as Map)['name']}'.toLowerCase().compareTo(
      '${(b['user'] as Map)['name']}'.toLowerCase(),
    );
  });

/// "JC" for "John Clements".
String fieldInitials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty);
  final letters = parts.take(2).map((w) => w[0].toUpperCase()).join();
  return letters.isEmpty ? '?' : letters;
}

/// A round person marker: the state colour as a ring (dashed for lost
/// signal) around the initials. Never a filled square, so it cannot be
/// mistaken for a job.
class FieldStateRing extends StatelessWidget {
  const FieldStateRing({
    super.key,
    required this.state,
    this.initials,
    this.size = 28,
  });

  final FieldPersonState state;
  final String? initials;
  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return CustomPaint(
      key: ValueKey('field-state-${state.name}'),
      painter: FieldRingPainter(
        colour: state.colour,
        fill: scheme.surface,
        dashed: state == FieldPersonState.lostSignal,
        width: size >= 20 ? 3 : 2,
      ),
      child: SizedBox(
        width: size,
        height: size,
        child: initials == null
            ? null
            : Center(
                child: Text(
                  initials!,
                  style: TextStyle(
                    fontSize: size * 0.36,
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurface,
                  ),
                ),
              ),
      ),
    );
  }
}

class FieldRingPainter extends CustomPainter {
  FieldRingPainter({
    required this.colour,
    required this.fill,
    required this.dashed,
    required this.width,
  });

  final Color colour;
  final Color fill;
  final bool dashed;
  final double width;

  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    final r = size.shortestSide / 2 - width / 2;
    canvas.drawCircle(c, r, Paint()..color = fill);
    final ring = Paint()
      ..color = colour
      ..style = PaintingStyle.stroke
      ..strokeWidth = width;
    if (!dashed) {
      canvas.drawCircle(c, r, ring);
      return;
    }
    const dashes = 8;
    const sweep = 3.14159265 * 2 / dashes;
    final rect = Rect.fromCircle(center: c, radius: r);
    for (var i = 0; i < dashes; i++) {
      canvas.drawArc(rect, i * sweep, sweep * 0.6, false, ring);
    }
  }

  @override
  bool shouldRepaint(FieldRingPainter old) =>
      old.colour != colour || old.fill != fill || old.dashed != dashed;
}
