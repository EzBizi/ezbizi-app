import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/theme.dart';
import 'call_flow_chart.dart';

/// The call flow as a chart (Jose, 2026-09-15: desktop 2 + mobile 4).
///
/// He picked 1 and 6 first, saw it built, and repicked against the running
/// screen: an n8n-style canvas on the desktop and the whole flow top-to-bottom
/// on a phone. The walk that shipped in between is gone rather than kept
/// beside them — two ways to read one screen is the confusion this tab exists
/// to remove.
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
/// second. `dead` is a caller reaching something that does nothing and
/// stopping there. `skipped` is a step the caller passes straight THROUGH,
/// which plays nothing — and nobody reports one, because the caller does not
/// know they missed a menu and the owner does not know theirs is silent.
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

  /// What is happening on the shown line right now — null before the first
  /// answer, and null again whenever the ask fails.
  CallFlowLive? _live;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  /// Ten seconds, and only while this pane is alive.
  ///
  /// One request per tick because the endpoint answers for ONE line, which is
  /// what the pane shows. Polling all thirteen would be thirteen Twilio calls
  /// every ten seconds, for ever, to fill in twelve numbers nobody is looking
  /// at.
  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 10), (_) => _loadLive());
  }

  Future<void> _loadLive() async {
    final id = _lineId;
    if (id == null) return;

    final response = await widget.api.get('/phone/call-flow/live?line=$id');

    // The line may have been changed while that was in flight; answering about
    // the old one would put another line's callers on this chart.
    if (!mounted || id != _lineId) return;

    setState(() {
      // The counts are an extra on a screen that works without them. A failed
      // ask clears them rather than taking the chart down: the graph never
      // came from Twilio and must still draw.
      _live = response.ok
          ? CallFlowLive.fromJson(response.body['data'] as Map<String, dynamic>?)
          : null;
    });
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

    if (_lineId != null) {
      _startPolling();
      await _loadLive();
    }
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
              // Flexible, not free-standing: the label is a line name and a
              // number, which grows, and a Row sized to its children plus a
              // growing child is the overflow this app keeps producing.
              Flexible(child: _picker(theme)),
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
            child: _Counts(graph: graph, live: _live),
          ),
          Expanded(
            child: LayoutBuilder(
              // The pane's own width, not the window's: this sits in a split,
              // so the window being wide says nothing about the room here.
              builder: (context, box) => box.maxWidth >= 720
                  ? _Canvas(graph: graph, live: _live)
                  : _Ribbon(graph: graph, live: _live),
            ),
          ),
        ],
      ],
    );
  }

  /// The line picker (Jose: picker 8, 2026-09-15 — "we also want a search bar
  /// for the number picker").
  ///
  /// Not a dropdown with a field bolted on. Thirteen lines is the awkward size:
  /// too many to scan, not enough to justify anything clever — and the question
  /// somebody opens this tab with is usually "which one is broken", not "where
  /// is Accounts". So the list leads with the lines that have a fault, before a
  /// character is typed.
  ///
  /// The counts need no server work. `/phone/call-flow` already answers with
  /// every line and its whole graph, so `deadCount` and `skippedCount` are
  /// there for all thirteen — I had told Jose this option was blocked on Phone
  /// sending a summary, and that was wrong: I had not read the payload.
  Widget _picker(ThemeData theme) => TextButton.icon(
    onPressed: _openPicker,
    icon: const Icon(Icons.search, size: 16),
    label: Text(
      _line?.heading ?? 'Choose a line',
      style: theme.textTheme.bodySmall,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    ),
  );

  Future<void> _openPicker() async {
    final chosen = await showDialog<String>(
      context: context,
      builder: (context) => _LinePicker(lines: _lines, current: _lineId),
    );

    if (chosen == null || !mounted) return;

    setState(() {
      _lineId = chosen;
      _live = null;
    });
    await _loadLive();
  }
}

/// How many things are wrong on a line, and the words for it.
int _faultsOn(_Line line) {
  final g = line.graph;
  if (g == null) return 0;

  return g.deadCount + g.skippedCount;
}

String? _faultWords(_Line line) {
  final g = line.graph;
  if (g == null) return null;

  final parts = <String>[
    if (g.deadCount == 1) '1 dead end' else if (g.deadCount > 1)
      '${g.deadCount} dead ends',
    if (g.skippedCount == 1) '1 does nothing' else if (g.skippedCount > 1)
      '${g.skippedCount} do nothing',
  ];

  return parts.isEmpty ? null : parts.join(' · ');
}

class _LinePicker extends StatefulWidget {
  const _LinePicker({required this.lines, required this.current});

  final List<_Line> lines;
  final String? current;

  @override
  State<_LinePicker> createState() => _LinePickerState();
}

class _LinePickerState extends State<_LinePicker> {
  String _term = '';

  /// Name OR digits. Half the time you know the number and not the label, and
  /// nobody types the punctuation, so the number is matched with it stripped.
  bool _matches(_Line line) {
    final needle = _term.trim().toLowerCase();
    if (needle.isEmpty) return true;
    if (line.label.toLowerCase().contains(needle)) return true;

    final digits = needle.replaceAll(RegExp(r'[^0-9]'), '');

    return digits.isNotEmpty && line.e164.contains(digits);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hits = widget.lines.where(_matches).toList();
    final faulty = hits.where((l) => _faultsOn(l) > 0).toList();
    final rest = hits.where((l) => _faultsOn(l) == 0).toList();

    return AlertDialog(
      contentPadding: const EdgeInsets.fromLTRB(0, 12, 0, 0),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: TextField(
                autofocus: true,
                decoration: InputDecoration(
                  isDense: true,
                  prefixIcon: const Icon(Icons.search, size: 18),
                  hintText: 'Search ${widget.lines.length} lines',
                  suffixText: hits.length == widget.lines.length
                      ? null
                      : '${hits.length} of ${widget.lines.length}',
                ),
                onChanged: (value) => setState(() => _term = value),
              ),
            ),
            const SizedBox(height: 8),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  if (faulty.isNotEmpty) ...[
                    _group(theme, 'Needs attention — ${faulty.length}'),
                    for (final l in faulty) _row(theme, l),
                  ],
                  if (rest.isNotEmpty) ...[
                    if (faulty.isNotEmpty) _group(theme, 'Everything else'),
                    for (final l in rest) _row(theme, l),
                  ],
                  if (hits.isEmpty)
                    Padding(
                      padding: const EdgeInsets.all(20),
                      child: Text(
                        'No line matches that.',
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _group(ThemeData theme, String text) => Padding(
    padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
    child: Text(
      text.toUpperCase(),
      style: theme.textTheme.labelSmall?.copyWith(
        fontWeight: FontWeight.w800,
        letterSpacing: 0.6,
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  Widget _row(ThemeData theme, _Line line) {
    final words = _faultWords(line);

    return ListTile(
      dense: true,
      selected: line.id == widget.current,
      title: Text(line.heading, style: theme.textTheme.bodyMedium),
      trailing: words == null
          ? null
          : Text(
              words,
              style: theme.textTheme.labelSmall?.copyWith(
                color: theme.colorScheme.error,
                fontWeight: FontWeight.w700,
              ),
            ),
      onTap: () => Navigator.of(context).pop(line.id),
    );
  }
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
  const _Counts({required this.graph, this.live});

  final CallFlowGraph graph;

  /// Null until the first live answer, and null again when it fails.
  final CallFlowLive? live;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dead = graph.deadCount;
    final skipped = graph.skippedCount;
    final onLine = live?.onLine ?? 0;

    // The healthy sentence is a TRAILING line rather than an early return.
    // It was an early return, and a line with no faults and two callers on it
    // would have said "every branch leads somewhere" and shown neither — the
    // live count hidden by the very state it is most worth seeing in, since
    // most lines are healthy most of the time.
    final healthy = dead == 0 && skipped == 0;

    // A Column of full-width rows rather than a Wrap of intrinsic ones. The
    // detail is a sentence and a sentence GROWS, so in a Row that sizes to its
    // children it ran 418px off the right edge at phone width — which is the
    // width this block matters most at, because the walk shows nothing else.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // First, because it is the only line here about right now rather than
        // about how the line is built. Drawn only when there IS somebody:
        // a permanent "0 on the line" is clutter on a screen whose normal
        // state is nobody calling.
        if (onLine > 0)
          _CountChip(
            colour: scheme.primary,
            label: onLine == 1 ? '1 call on this line now' : '$onLine calls on this line now',
            detail: 'ringing or connected right now',
          ),
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
            // NOT "never reached". The caller DOES reach it; it plays
            // nothing. The old wording sent a reader looking for a branch
            // nobody can get to, which is a different fault in a different
            // place.
            label: skipped == 1
                ? '1 step does nothing'
                : '$skipped steps do nothing',
            detail: 'a caller passes through and hears nothing',
          ),
        if (healthy)
          Padding(
            padding: EdgeInsets.only(top: onLine > 0 ? 6 : 0),
            child: Text(
              'Every branch of this flow leads somewhere.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
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

// ── Desktop: the canvas (Jose: desktop 2, 2026-09-15) ──────────────
//
// His words: "have it begin in the middle left, so you can expand up and down
// when nodes branch", and "more modern, with icons, arrows, and even a energy
// flow animation on the lines". He sent an n8n canvas as the reference: dark
// ground, an icon tile for the node, the name underneath it.
//
// The tile is why the detail text is not drawn here. It was: the menu node
// rendered the whole IVR prompt and stood five times taller than every other
// node, which is most of why the chart did not fit on his screen. The prompt is
// worth reading once, so it moved to a tap.

const double _tile = 56;
const double _nodeWidth = 128;
const double _nodeHeight = 92;
const double _columnGap = 104;
const double _rowGap = 18;

// The canvas palette lives in `theme.dart` as [FlowCanvas]: fixed rather than
// theme-derived, because a graph canvas is a drawing rather than a page — but
// declared with the rest of the colours, because ten literals accumulating in
// one screen is exactly what `theme_conventions_test` exists to stop, and it
// caught this.

/// "4 waiting · 1m 13s" — one place, because the canvas and the phone both
/// say it and two spellings of one fact is how they drift.
String waitingWords(QueueLive queue) {
  final people = queue.waiting == 1 ? '1 waiting' : '${queue.waiting} waiting';
  if (queue.waiting == 0 || queue.averageWaitSeconds <= 0) return people;

  final seconds = queue.averageWaitSeconds;
  final spell = seconds < 60
      ? '${seconds}s'
      : '${seconds ~/ 60}m ${(seconds % 60).toString().padLeft(2, '0')}s';

  return '$people · $spell';
}

IconData _iconFor(String kind) {
  switch (kind) {
    case CallFlowGraph.entryId:
      return Icons.call_outlined;
    case 'arrival':
      return Icons.call_received;
    case 'menu':
      return Icons.dialpad;
    case 'ring_group':
      return Icons.groups_outlined;
    case 'queue':
      return Icons.schedule;
    case 'voicemail':
      return Icons.voicemail_outlined;
    case 'forward':
      return Icons.call_made;
    default:
      // Not a fault and not a guess. A kind this build has never heard of is
      // drawn plainly rather than given a verdict.
      return Icons.circle_outlined;
  }
}

/// Where every node sits, computed once and shared by the boxes and the wires.
///
/// It used to be computed twice — the `Positioned` children from column/row,
/// and the painter from column/row again. Two expressions of one fact, and the
/// kind that agree until somebody edits one of them.
class _Geometry {
  _Geometry(this.layout) {
    final perColumn = <int, int>{};
    for (final p in layout.placed) {
      perColumn[p.column] = (perColumn[p.column] ?? 0) + 1;
    }
    final tallest = perColumn.values.isEmpty
        ? 1
        : perColumn.values.reduce(math.max);

    size = Size(
      layout.columns * (_nodeWidth + _columnGap),
      tallest * (_nodeHeight + _rowGap) + 72,
    );

    // THE CHANGE HE ASKED FOR. Each column is centred on the canvas midline,
    // so the caller sits middle-left and a branch grows symmetrically up AND
    // down rather than hanging off the bottom.
    final centreY = size.height / 2;
    for (final p in layout.placed) {
      final n = perColumn[p.column]!;
      final stack = n * _nodeHeight + (n - 1) * _rowGap;
      topLeft[p.node.id] = Offset(
        p.column * (_nodeWidth + _columnGap) + 12,
        centreY - stack / 2 + p.row * (_nodeHeight + _rowGap),
      );
    }
  }

  final FlowLayout layout;
  final Map<String, Offset> topLeft = {};
  late final Size size;

  /// Wires meet the TILE, not the block: the name sits under the tile, and a
  /// wire arriving at the middle of the block would run through the text.
  Offset rightOf(String id) =>
      topLeft[id]! + const Offset(_nodeWidth, _tile / 2);
  Offset leftOf(String id) => topLeft[id]! + const Offset(0, _tile / 2);
  Offset underOf(String id) =>
      topLeft[id]! + const Offset(_nodeWidth / 2, _tile);
}

class _Canvas extends StatefulWidget {
  const _Canvas({required this.graph, this.live});

  final CallFlowGraph graph;
  final CallFlowLive? live;

  @override
  State<_Canvas> createState() => _CanvasState();
}

class _CanvasState extends State<_Canvas> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );

  bool _running = false;

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  /// Reduce-motion turns the flow off, and that is not only an accessibility
  /// courtesy: a repeating controller never lets `pumpAndSettle` finish, so a
  /// harness that sets the flag also gets a chart that settles.
  void _sync(bool wanted) {
    if (wanted == _running) return;
    _running = wanted;
    if (wanted) {
      _pulse.repeat();
    } else {
      _pulse.stop();
      _pulse.value = 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    final layout = layOutFlow(widget.graph);
    final geometry = _Geometry(layout);
    final paper = FlowCanvas.of(context);

    _sync(!MediaQuery.of(context).disableAnimations);

    return ColoredBox(
      color: paper.ink,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
        child: SingleChildScrollView(
          child: SizedBox(
            width: geometry.size.width,
            height: geometry.size.height,
            child: Stack(
              children: [
                Positioned.fill(
                  child: AnimatedBuilder(
                    animation: _pulse,
                    builder: (context, _) => CustomPaint(
                      painter: FlowWirePainter(
                        layout: layout,
                        colour: paper.wire,
                        labelColour: paper.muted,
                        paper: paper,
                        phase: _pulse.value,
                      ),
                    ),
                  ),
                ),
                for (final p in layout.placed)
                  Positioned(
                    left: geometry.topLeft[p.node.id]!.dx,
                    top: geometry.topLeft[p.node.id]!.dy,
                    width: _nodeWidth,
                    child: _NodeTile(
                      node: p.node,
                      queue: widget.live?.forNode(p.node),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// One node: an icon tile with its name under it, the way his reference draws
/// them.
class _NodeTile extends StatelessWidget {
  const _NodeTile({required this.node, this.queue});

  final FlowNode node;

  /// Null when there is nothing honest to draw — not a queue, or Twilio could
  /// not be asked. Zero is a real answer and is drawn as one.
  final QueueLive? queue;

  String get _kindWord {
    switch (node.kind) {
      case CallFlowGraph.entryId:
        return 'Inbound';
      case 'menu':
        // Not 'Menu': that is the node's own title on most lines, and a tile
        // captioned with its own name twice reads as a rendering fault.
        return 'Keypad';
      case 'ring_group':
        return 'Ring group';
      case 'queue':
        return 'Queue';
      case 'voicemail':
        return 'Voicemail';
      case 'arrival':
        return 'Arrives';
      default:
        return '';
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final paper = FlowCanvas.of(context);

    // `id`, not `kind`. They are different namespaces that happen to coincide
    // on exactly one node, so a kind comparison works today and goes silent
    // the moment either side is renamed. Found by Phone.
    final isEntry = node.id == CallFlowGraph.entryId;

    // Somebody is on hold HERE, right now. Zero is a real answer and is drawn
    // as one, but it does not earn a ring.
    final holding = (queue?.waiting ?? 0) > 0;

    final edge = node.isDead
        ? scheme.error
        : node.isSkipped
        ? paper.amber
        : isEntry
        ? paper.accent
        : paper.tileLine;

    return Tooltip(
      message: node.detail,
      child: InkWell(
        onTap: () => showDialog<void>(
          context: context,
          builder: (context) => AlertDialog(
            title: Text(node.title),
            content: Text(node.detail),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Close'),
              ),
            ],
          ),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: _tile,
              height: _tile,
              decoration: BoxDecoration(
                color: node.isDead
                    ? scheme.error.withValues(alpha: 0.16)
                    : paper.tile,
                borderRadius: BorderRadius.circular(15),
                border: Border.all(
                  color: holding ? paper.accent : edge,
                  width: isEntry || node.isDead || holding ? 2 : 1,
                ),
              ),
              child: Icon(_iconFor(node.kind), size: 22, color: edge),
            ),
            const SizedBox(height: 7),
            Text(
              node.title,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.labelSmall?.copyWith(
                color: paper.text,
                fontWeight: FontWeight.w700,
              ),
            ),
            if (node.isDead || node.isSkipped)
              Text(
                node.isDead ? 'Dead end' : 'Does nothing',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: edge,
                  fontWeight: FontWeight.w800,
                  fontSize: 9,
                ),
              )
            // Jose: "see Live Calls flowing through the Node as well". It
            // replaces the kind word rather than joining it, because on a
            // queue tile "Queue" is the least interesting true thing on the
            // screen once somebody is actually holding.
            else if (queue != null)
              Text(
                waitingWords(queue!),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: queue!.waiting > 0 ? paper.accent : paper.muted,
                  fontWeight: FontWeight.w800,
                  fontSize: 9,
                ),
              )
            else if (_kindWord.isNotEmpty)
              Text(
                _kindWord,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: paper.muted,
                  fontSize: 9,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// The wires: an arrowhead on every one, and the energy flow he asked for.
///
/// Public so a test can reach it — nothing else in a widget test can see a
/// painter, and the wires are the half of this screen no `find.text` reaches.
class FlowWirePainter extends CustomPainter {
  FlowWirePainter({
    required this.layout,
    required this.colour,
    required this.labelColour,
    this.paper = FlowCanvas.light,
    this.phase = 0,
  }) : _geometry = _Geometry(layout);

  final FlowLayout layout;
  final Color colour;
  final Color labelColour;

  /// The canvas palette, so the label plates match the ground they sit on.
  final FlowCanvas paper;

  /// 0..1, driven by the pulse controller. 0 when reduce-motion is on.
  final double phase;

  final _Geometry _geometry;

  /// WHERE A LABEL GOES, and it is two rules rather than one.
  ///
  /// This painter used to put every label at the midpoint of its own wire,
  /// with no idea another label was already there — so wherever wires
  /// converge, which is every line with a queue, they collided. Jose's own
  /// screenshot reads "Ееrybody bese": "Everyone busy" and "Waited too long"
  /// painted at the same point.
  ///
  /// The first fix was also wrong. Labelling near the START assumes a fan
  /// separates early, and a fan-OUT has barely left its source there. Measured
  /// on the gallery: 13px of pitch for 11px-tall text. Labelling a fan-out
  /// near its DESTINATION gives 67px.
  double _labelAt(FlowEdge edge) {
    var out = 0;
    var into = 0;
    for (final e in [...layout.forward, ...layout.back]) {
      if (e.from == edge.from) out++;
      if (e.to == edge.to) into++;
    }
    if (out > 1 && into <= 1) return 0.78;
    if (into > 1 && out <= 1) return 0.22;

    return 0.5;
  }

  @override
  void paint(Canvas canvas, Size size) {
    _dots(canvas, size);

    final pen = Paint()
      ..color = colour
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke;

    // Parallel edges are one wire carrying both keys; the rule and the reason
    // live in `mergeParallel`, beside the layout, where they can be tested
    // without pumping a widget.
    for (final wire in mergeParallel(layout.forward)) {
      final edge = wire.edge;
      if (!_geometry.topLeft.containsKey(edge.from)) continue;
      if (!_geometry.topLeft.containsKey(edge.to)) continue;
      final from = _geometry.rightOf(edge.from);
      final to = _geometry.leftOf(edge.to);
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(
          from.dx + _columnGap * 0.55,
          from.dy,
          to.dx - _columnGap * 0.55,
          to.dy,
          to.dx,
          to.dy,
        );
      canvas.drawPath(path, pen);
      _flow(canvas, path, paper.accent, reverse: false);
      _arrow(canvas, path, colour);
      _label(canvas, wire.label, path, _labelAt(edge));
    }

    // Back-edges: drawn, never followed. They loop UNDER their source rather
    // than crossing the chart, so a queue returning to the group it came from
    // reads as a short return rather than a wire going the wrong way. The flow
    // on them runs backwards, so a return reads as one without its label.
    for (final edge in layout.back) {
      if (!_geometry.topLeft.containsKey(edge.from)) continue;
      if (!_geometry.topLeft.containsKey(edge.to)) continue;
      final from = _geometry.underOf(edge.from);
      final to = _geometry.underOf(edge.to);
      final dip = math.max(from.dy, to.dy) + 46;
      final path = Path()
        ..moveTo(from.dx, from.dy)
        ..cubicTo(from.dx, dip, to.dx, dip, to.dx, to.dy);
      canvas.drawPath(_dashes(path, dash: 5, gap: 4), pen);
      _flow(canvas, path, paper.amber, reverse: true);
      _arrow(canvas, path, colour);
      _label(canvas, edge.label, path, 0.5);
    }
  }

  void _dots(Canvas canvas, Size size) {
    final dot = Paint()..color = paper.grid;
    for (var x = 0.0; x < size.width; x += 17) {
      for (var y = 0.0; y < size.height; y += 17) {
        canvas.drawCircle(Offset(x, y), 1, dot);
      }
    }
  }

  /// The energy flow: a short bright dash travelling the wire.
  void _flow(Canvas canvas, Path path, Color colour, {required bool reverse}) {
    if (phase == 0) return;
    final travel = reverse ? 1 - phase : phase;
    final pen = Paint()
      ..color = colour
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    for (final metric in path.computeMetrics()) {
      final at = metric.length * travel;
      final start = math.max(0.0, at - 13);
      if (at <= start) continue;
      canvas.drawPath(metric.extractPath(start, at), pen);
    }
  }

  void _arrow(Canvas canvas, Path path, Color colour) {
    for (final metric in path.computeMetrics()) {
      final tip = metric.getTangentForOffset(metric.length);
      if (tip == null) continue;
      final angle = tip.angle;
      final head = Path()
        ..moveTo(tip.position.dx, tip.position.dy)
        ..lineTo(
          tip.position.dx - 7 * math.cos(angle - 0.4),
          tip.position.dy - 7 * math.sin(angle - 0.4),
        )
        ..lineTo(
          tip.position.dx - 7 * math.cos(angle + 0.4),
          tip.position.dy - 7 * math.sin(angle + 0.4),
        )
        ..close();
      canvas.drawPath(head, Paint()..color = colour);
    }
  }

  void _label(Canvas canvas, String text, Path path, double at) {
    if (text.isEmpty) return;

    for (final metric in path.computeMetrics()) {
      final spot = metric.getTangentForOffset(metric.length * at);
      if (spot == null) continue;

      final painter = TextPainter(
        text: TextSpan(
          text: text,
          style: TextStyle(
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: labelColour,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();

      final at0 = spot.position - Offset(painter.width / 2, painter.height + 3);

      // A plate behind the text, so no wire runs through a label.
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(
            at0.dx - 4,
            at0.dy - 1,
            painter.width + 8,
            painter.height + 2,
          ),
          const Radius.circular(4),
        ),
        Paint()..color = paper.ink,
      );
      painter.paint(canvas, at0);

      return;
    }
  }

  Path _dashes(Path source, {required double dash, required double gap}) {
    final out = Path();
    for (final metric in source.computeMetrics()) {
      var at = 0.0;
      while (at < metric.length) {
        final end = (at + dash).clamp(0.0, metric.length);
        if (end > at) out.addPath(metric.extractPath(at, end), Offset.zero);
        at += dash + gap;
      }
    }

    return out;
  }

  @override
  bool shouldRepaint(FlowWirePainter old) =>
      old.layout != layout ||
      old.colour != colour ||
      old.labelColour != labelColour ||
      old.paper != paper ||
      old.phase != phase;
}

// ── Phone: the whole flow, top to bottom (Jose: mobile 4) ──────────

/// Built for a phone rather than shrunk onto one.
///
/// The flow runs top to bottom because that is the direction a phone scrolls,
/// so his "expand up and down" becomes "the branches indent". Everything is on
/// one page, which is the complaint about the walk this replaces: you never saw
/// the whole shape, and a fault you had not walked to was invisible.
class _Ribbon extends StatelessWidget {
  const _Ribbon({required this.graph, this.live});

  final CallFlowGraph graph;
  final CallFlowLive? live;

  @override
  Widget build(BuildContext context) {
    final layout = layOutFlow(graph);
    final steps = layout.placed;

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      itemCount: steps.length,
      itemBuilder: (context, i) => _Step(
        node: steps[i].node,
        branches: graph.out(steps[i].node.id),
        graph: graph,
        queue: live?.forNode(steps[i].node),
        last: i == steps.length - 1,
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
    required this.node,
    required this.branches,
    required this.graph,
    required this.last,
    this.queue,
  });

  final FlowNode node;
  final List<FlowEdge> branches;
  final CallFlowGraph graph;
  final bool last;
  final QueueLive? queue;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final tint = node.isDead
        ? scheme.error
        : node.isSkipped
        ? FlowCanvas.light.amber
        : scheme.primary;

    // Only a branching step lists where it goes. A single next step is implied
    // by the rail, and listing it would say the same thing twice.
    final fan = branches.length > 1 ? branches : const <FlowEdge>[];

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: tint.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(_iconFor(node.kind), size: 14, color: tint),
              ),
              if (!last)
                Expanded(
                  child: Container(
                    width: 2,
                    margin: const EdgeInsets.symmetric(vertical: 4),
                    color: scheme.outlineVariant,
                  ),
                ),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: Text(
                          node.title,
                          style: theme.textTheme.titleSmall,
                        ),
                      ),
                      if (node.isDead || node.isSkipped) ...[
                        const SizedBox(width: 8),
                        Text(
                          node.isDead ? 'Dead end' : 'Does nothing',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: tint,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ] else if (queue != null) ...[
                        const SizedBox(width: 8),
                        Text(
                          waitingWords(queue!),
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: queue!.waiting > 0
                                ? scheme.primary
                                : scheme.onSurfaceVariant,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ],
                    ],
                  ),
                  if (node.detail.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        node.detail,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ),
                  if (fan.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    DecoratedBox(
                      decoration: BoxDecoration(
                        border: Border.all(color: scheme.outlineVariant),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Column(
                        children: [
                          for (final e in fan)
                            _BranchRow(
                              edge: e,
                              to: graph.node(e.to),
                              lastRow: e == fan.last,
                            ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _BranchRow extends StatelessWidget {
  const _BranchRow({
    required this.edge,
    required this.to,
    required this.lastRow,
  });

  final FlowEdge edge;
  final FlowNode? to;
  final bool lastRow;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final bad = to?.isDead ?? false;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
      decoration: BoxDecoration(
        border: lastRow
            ? null
            : Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          if (edge.label.isNotEmpty) ...[
            DecoratedBox(
              decoration: BoxDecoration(
                color: bad
                    ? scheme.error.withValues(alpha: 0.1)
                    : scheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(5),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                child: Text(
                  edge.label,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: bad ? scheme.error : scheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 9),
          ],
          // The icon of what it REACHES: at a glance the menu reads as "three
          // groups and a voicemail" without a word being read.
          Icon(
            _iconFor(to?.kind ?? ''),
            size: 14,
            color: bad ? scheme.error : scheme.onSurfaceVariant,
          ),
          const SizedBox(width: 7),
          Expanded(
            child: Text(
              to?.title ?? edge.to,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
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
