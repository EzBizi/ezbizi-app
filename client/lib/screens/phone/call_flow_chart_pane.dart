import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import 'call_flow_chart.dart';

/// The call flow as a chart (Jose, 2026-09-15, desktop 1 + mobile 6).
///
/// His words: *"more graphic / Call Flowchart / a graphic view, where it shows
/// the current state and separates into nodes like n8n / from left to right or
/// up to down / and begins with Caller ---- (what happens)"*.
///
/// Beside the Call flow pane rather than replacing it: that one is the list,
/// this is the picture, and they answer different questions.
///
/// **Read-only.** He said *shows me*. An editable canvas is a much larger
/// thing and would want its own conversation.
///
/// ── What earns this screen, and it is not the picture ────────────
///
/// A correct flow and a broken one draw identically unless the states are
/// visible, so the counts are the first thing on the screen and the drawing is
/// second. `dead` is a caller reaching something that does nothing. `skipped`
/// is configured and never reached at all — and nobody reports one, because
/// the caller does not know they missed a menu and the owner does not know
/// theirs is skipped.
///
/// That is truest on a phone, where [_Walk] shows one node at a time: nothing
/// is visible until you have walked to it, so the count is the ONLY thing
/// answering "is any of this broken?" at a glance. It is not a caption there.
class CallFlowChartPane extends StatefulWidget {
  const CallFlowChartPane({super.key, required this.api});

  final ApiClient api;

  @override
  State<CallFlowChartPane> createState() => _CallFlowChartPaneState();
}

class _CallFlowChartPaneState extends State<CallFlowChartPane> {
  bool _loading = true;
  String? _error;
  List<_Line> _lines = const [];
  String? _lineId;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _lines.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/call-flow');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;

        return;
      }
      _lines = _Line.listFrom(response.body);
      if (_lineId == null || !_lines.any((l) => l.id == _lineId)) {
        _lineId = _lines.isEmpty ? null : _lines.first.id;
      }
    });
  }

  _Line? get _line {
    for (final l in _lines) {
      if (l.id == _lineId) return l;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    if (_lines.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No lines yet. Import or buy one on the Numbers screen and its call '
          'flow appears here.',
          style: theme.textTheme.bodySmall,
        ),
      );
    }

    final line = _line;
    final graph = line?.graph;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
          child: Row(
            children: [
              Expanded(
                child: Text('Call flow', style: theme.textTheme.titleMedium),
              ),
              if (_lines.length > 1) _picker(theme),
            ],
          ),
        ),
        if (graph == null)
          Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              'This line has nothing set up yet, so there is no flow to draw.',
              style: theme.textTheme.bodySmall,
            ),
          )
        else ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 14, 24, 0),
            child: _Counts(graph: graph),
          ),
          Expanded(
            child: LayoutBuilder(
              // The pane's own width, not the window's: this sits in a split,
              // so the window being wide says nothing about the room here.
              builder: (context, box) => box.maxWidth >= 720
                  ? _Canvas(graph: graph)
                  : _Walk(graph: graph),
            ),
          ),
        ],
      ],
    );
  }

  Widget _picker(ThemeData theme) => DropdownButtonHideUnderline(
    child: DropdownButton<String>(
      value: _lineId,
      isDense: true,
      style: theme.textTheme.bodySmall,
      onChanged: (id) => setState(() => _lineId = id),
      items: [
        for (final l in _lines)
          DropdownMenuItem(value: l.id, child: Text(l.heading)),
      ],
    ),
  );
}

/// The two counts, first on the screen.
///
/// `dead` is drawn in the error colour because a caller reaching nothing IS a
/// failure. `skipped` deliberately is NOT: it is configuration that never
/// runs, not a call going wrong, and colouring it as an error would say the
/// line is broken when it is only carrying something useless. It gets the
/// outline colour and a dashed swatch, which is the same distinction the rest
/// of the app draws between a fault and an advisory.
class _Counts extends StatelessWidget {
  const _Counts({required this.graph});

  final CallFlowGraph graph;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dead = graph.deadCount;
    final skipped = graph.skippedCount;

    if (dead == 0 && skipped == 0) {
      return Text(
        'Every branch of this flow leads somewhere.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: scheme.onSurfaceVariant,
        ),
      );
    }

    // A Column of full-width rows rather than a Wrap of intrinsic ones. The
    // detail is a sentence and a sentence GROWS, so in a Row that sizes to its
    // children it ran 418px off the right edge at phone width — which is the
    // width this block matters most at, because the walk shows nothing else.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (dead > 0)
          _CountChip(
            colour: scheme.error,
            label: dead == 1 ? '1 dead end' : '$dead dead ends',
            detail: dead == 1
                ? 'a caller reaches this and nothing happens'
                : 'a caller reaches these and nothing happens',
          ),
        if (skipped > 0)
          _CountChip(
            colour: scheme.onSurfaceVariant,
            dashed: true,
            label: skipped == 1 ? '1 never reached' : '$skipped never reached',
            detail: 'configured, and no caller ever gets there',
          ),
      ],
    );
  }
}

class _CountChip extends StatelessWidget {
  const _CountChip({
    required this.colour,
    required this.label,
    required this.detail,
    this.dashed = false,
  });

  final Color colour;
  final String label;
  final String detail;
  final bool dashed;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            margin: const EdgeInsets.only(top: 4),
            width: 10,
            height: 10,
            decoration: BoxDecoration(
              color: dashed ? null : colour,
              border: dashed ? Border.all(color: colour, width: 1.5) : null,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 7),
          Text(
            label,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w700,
              color: colour,
            ),
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              '— $detail',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Desktop: left to right, layered ────────────────────────────────

const double _nodeWidth = 168;
const double _nodeHeight = 64;
const double _columnGap = 64;
const double _rowGap = 16;

/// The chart. Columns left to right, one per keypress from the caller.
class _Canvas extends StatelessWidget {
  const _Canvas({required this.graph});

  final CallFlowGraph graph;

  @override
  Widget build(BuildContext context) {
    final layout = layOutFlow(graph);
    final rowsPerColumn = <int, int>{};
    for (final p in layout.placed) {
      rowsPerColumn[p.column] = (rowsPerColumn[p.column] ?? 0) + 1;
    }
    final tallest = rowsPerColumn.values.isEmpty
        ? 1
        : rowsPerColumn.values.reduce((a, b) => a > b ? a : b);

    final width = layout.columns * (_nodeWidth + _columnGap);
    final height = tallest * (_nodeHeight + _rowGap) + 40;

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: SingleChildScrollView(
        child: SizedBox(
          width: width,
          height: height,
          child: Stack(
            children: [
              Positioned.fill(
                child: CustomPaint(
                  painter: FlowWirePainter(
                    layout: layout,
                    colour: Theme.of(context).colorScheme.outlineVariant,
                    labelColour: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
              for (final p in layout.placed)
                Positioned(
                  left: p.column * (_nodeWidth + _columnGap),
                  top: p.row * (_nodeHeight + _rowGap),
                  width: _nodeWidth,
                  child: _NodeCard(node: p.node),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One box on the canvas.
class _NodeCard extends StatelessWidget {
  const _NodeCard({required this.node});

  final FlowNode node;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final edge = node.isDead
        ? scheme.error
        : node.isSkipped
        ? scheme.onSurfaceVariant
        : node.kind == CallFlowGraph.entryId
        ? scheme.primary
        : scheme.outlineVariant;

    return Container(
      constraints: const BoxConstraints(minHeight: _nodeHeight),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: node.isDead
            ? scheme.errorContainer.withValues(alpha: 0.25)
            : scheme.surface,
        border: Border.all(color: edge, width: node.state == 'ok' ? 1 : 1.5),
        borderRadius: BorderRadius.circular(9),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            node.title,
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          if (node.detail.isNotEmpty)
            Text(
              node.detail,
              style: theme.textTheme.labelSmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          if (node.isDead || node.isSkipped)
            Padding(
              padding: const EdgeInsets.only(top: 3),
              child: Text(
                node.isDead ? 'Dead end' : 'Never reached',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: edge,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// The wires.
///
/// Public so a test can reach it: a painter is the half of a feature nothing
/// else can see — `find.text` cannot read a canvas — so this one is named,
/// constructed directly and asked [shouldRepaint] rather than trusted.
class FlowWirePainter extends CustomPainter {
  const FlowWirePainter({
    required this.layout,
    required this.colour,
    required this.labelColour,
  });

  final FlowLayout layout;
  final Color colour;
  final Color labelColour;

  Offset _rightOf(PlacedNode p) => Offset(
    p.column * (_nodeWidth + _columnGap) + _nodeWidth,
    p.row * (_nodeHeight + _rowGap) + _nodeHeight / 2,
  );

  Offset _leftOf(PlacedNode p) => Offset(
    p.column * (_nodeWidth + _columnGap),
    p.row * (_nodeHeight + _rowGap) + _nodeHeight / 2,
  );

  @override
  void paint(Canvas canvas, Size size) {
    final pen = Paint()
      ..color = colour
      ..strokeWidth = 1.4
      ..style = PaintingStyle.stroke;

    for (final edge in layout.forward) {
      final a = layout.find(edge.from);
      final b = layout.find(edge.to);
      if (a == null || b == null) continue;
      final from = _rightOf(a);
      final to = _leftOf(b);
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(
          from.dx + _columnGap / 2,
          from.dy,
          to.dx - _columnGap / 2,
          to.dy,
          to.dx,
          to.dy,
        );
      canvas.drawPath(path, pen);
      _label(canvas, edge.label, from, to);
    }

    // Back-edges: drawn, never followed. They loop UNDER their source rather
    // than crossing the chart, so a queue returning to the group it came from
    // reads as a short return rather than as a wire going the wrong way.
    for (final edge in layout.back) {
      final a = layout.find(edge.from);
      final b = layout.find(edge.to);
      if (a == null || b == null) continue;
      final from = _leftOf(a);
      final to = _rightOf(b);
      final dip = math.max(from.dy, to.dy) + _nodeHeight / 2 + 12;
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(from.dx - 24, dip, to.dx + 24, dip, to.dx, to.dy);
      canvas.drawPath(_dashed(path), pen);
      _label(canvas, edge.label, from, to);
    }
  }

  Path _dashed(Path source) {
    final out = Path();
    for (final metric in source.computeMetrics()) {
      var at = 0.0;
      while (at < metric.length) {
        final next = at + 5;
        out.addPath(
          metric.extractPath(at, next.clamp(0, metric.length)),
          Offset.zero,
        );
        at = next + 4;
      }
    }

    return out;
  }

  void _label(Canvas canvas, String text, Offset from, Offset to) {
    if (text.isEmpty) return;
    final painter = TextPainter(
      text: TextSpan(
        text: text,
        style: TextStyle(fontSize: 10, color: labelColour),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    painter.paint(
      canvas,
      Offset(
        (from.dx + to.dx) / 2 - painter.width / 2,
        (from.dy + to.dy) / 2 - painter.height - 2,
      ),
    );
  }

  @override
  bool shouldRepaint(FlowWirePainter old) =>
      old.layout != layout || old.colour != colour;
}

// ── Phone: walk it the way a caller does ───────────────────────────

/// One node at a time, with what can happen next.
///
/// The most literal reading of "begins with Caller ---- (what happens)". Its
/// cost is stated rather than hidden: you never see the whole shape, which is
/// why the counts above it are not decoration.
class _Walk extends StatefulWidget {
  const _Walk({required this.graph});

  final CallFlowGraph graph;

  @override
  State<_Walk> createState() => _WalkState();
}

class _WalkState extends State<_Walk> {
  List<String> _trail = const [CallFlowGraph.entryId];

  @override
  void didUpdateWidget(_Walk old) {
    super.didUpdateWidget(old);
    // A different line is a different flow; staying where you were would
    // leave the breadcrumb describing a call to another number.
    if (!identical(old.graph, widget.graph)) {
      _trail = const [CallFlowGraph.entryId];
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final here = widget.graph.node(_trail.last);
    if (here == null) return const SizedBox.shrink();

    final next = widget.graph.out(here.id);

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
      children: [
        if (_trail.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                for (var i = 0; i < _trail.length; i++) ...[
                  if (i > 0)
                    Text(
                      ' › ',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  InkWell(
                    onTap: i == _trail.length - 1
                        ? null
                        : () =>
                              setState(() => _trail = _trail.sublist(0, i + 1)),
                    child: Text(
                      widget.graph.node(_trail[i])?.title ?? '',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: i == _trail.length - 1
                            ? scheme.onSurface
                            : scheme.primary,
                        fontWeight: i == _trail.length - 1
                            ? FontWeight.w700
                            : FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        _NodeCard(node: here),
        const SizedBox(height: 16),
        if (next.isEmpty)
          Text(
            'The call ends here.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          )
        else ...[
          Text(
            'What can happen next',
            style: theme.textTheme.labelSmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          for (final edge in next) _option(edge, theme),
        ],
      ],
    );
  }

  Widget _option(FlowEdge edge, ThemeData theme) {
    final scheme = theme.colorScheme;
    final target = widget.graph.node(edge.to);
    if (target == null) return const SizedBox.shrink();

    // A step that returns to somewhere already on the trail is the cycle. It
    // is offered — a caller really can go round — and named, so the walk does
    // not silently look like a path that ends.
    final loops = _trail.contains(edge.to);

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: OutlinedButton(
        onPressed: () => setState(() => _trail = [..._trail, edge.to]),
        style: OutlinedButton.styleFrom(
          alignment: Alignment.centerLeft,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          side: BorderSide(
            color: target.isDead ? scheme.error : scheme.outlineVariant,
          ),
        ),
        child: Row(
          children: [
            if (edge.label.isNotEmpty)
              SizedBox(
                width: 84,
                child: Text(
                  edge.label,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurface,
                  ),
                ),
              ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    target.title,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.onSurface,
                    ),
                  ),
                  if (target.isDead)
                    Text(
                      target.detail.isEmpty
                          ? 'Nothing happens here'
                          : target.detail,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.error,
                        fontWeight: FontWeight.w600,
                      ),
                    )
                  else if (loops)
                    Text(
                      'back to where you have been',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
            Icon(Icons.chevron_right, size: 18, color: scheme.onSurfaceVariant),
          ],
        ),
      ),
    );
  }
}

// ── the payload ────────────────────────────────────────────────────

class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.graph,
  });

  final String id;
  final String e164;
  final String label;
  final CallFlowGraph? graph;

  String get heading => label.trim().isEmpty || label == e164
      ? formatPhoneNumber(e164)
      : '$label · ${formatPhoneNumber(e164)}';

  static List<_Line> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Line(
          id: row['id'].toString(),
          e164: (row['e164'] ?? '') as String,
          label: (row['label'] ?? '') as String,
          graph: CallFlowGraph.fromJson(row['graph'] as Map<String, dynamic>?),
        ),
    ];
  }
}
