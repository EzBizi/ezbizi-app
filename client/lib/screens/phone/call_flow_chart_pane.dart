import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import 'call_flow_chart.dart';
import 'call_flow_edit_drawer.dart';

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
  const CallFlowChartPane({
    super.key,
    required this.api,
    required this.session,
    this.lineId,
    this.leading,
  });

  final ApiClient api;

  /// Needed because the drawer hosts the real settings panes, and they take
  /// it. Threading it here rather than giving the drawer its own is the point:
  /// one pane, one session, one editor.
  final Session session;

  /// The line to draw first: the one a Call flow row was opened on (UI queue
  /// 51). Null draws the first line, as this pane always has.
  final String? lineId;

  /// Drawn at the start of the header — Call flow's way back to its list. In
  /// the header rather than above it, so the line named on screen is always
  /// the picker's, whichever line somebody switches to from here.
  final Widget? leading;

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

  /// The box whose settings are open, or null.
  FlowNode? _editing;

  /// True when the last attempt to re-read the graph failed.
  ///
  /// Phone's warning, and it is the same rule as `queues_known` one level up:
  /// **a refetch that fails silently leaves a shape on screen that looks
  /// current and is not.** Somebody who has just changed a menu and sees the
  /// old menu drawn confidently will conclude the change did not save. So a
  /// failed refresh says so and leaves the chart alone, rather than either
  /// hiding the chart or pretending.
  bool _stale = false;

  @override
  void initState() {
    super.initState();
    _lineId = widget.lineId;
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
    _poll = Timer.periodic(const Duration(seconds: 10), (_) async {
      await _loadLive();
      // Only while somebody has a pane open. With no drawer there is nothing
      // changing the flow from this screen, and the counts are the only thing
      // that moves on their own.
      if (mounted && _editing != null) await _refreshGraph();
    });
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
          ? CallFlowLive.fromJson(
              response.body['data'] as Map<String, dynamic>?,
            )
          : null;
    });
  }

  /// Re-read the graph, keeping the line and the chart that is on screen.
  ///
  /// Jose, 2026-09-17: *"i click one of the nodes and change something, the
  /// chart does not update automatically, it should be live, so whatever
  /// change is made the chart updates."*
  ///
  /// Not `_load()`: that one owns the first paint and the error state, and a
  /// refresh that failed must not blank a chart somebody is reading. This only
  /// ever REPLACES a good graph with a newer good graph.
  ///
  /// On the poll tick rather than only on close, because the hosted panes save
  /// with their own buttons and somebody may save three times without closing
  /// the drawer. The endpoint answers for every line at once and has no cache
  /// behind it, so this is one extra request every ten seconds, and only while
  /// a drawer is actually open.
  Future<void> _refreshGraph() async {
    final response = await widget.api.get('/phone/call-flow');
    if (!mounted) return;

    setState(() {
      if (!response.ok) {
        _stale = true;

        return;
      }

      _stale = false;
      _lines = _Line.listFrom(response.body);
      if (_lineId != null && !_lines.any((l) => l.id == _lineId)) {
        // The line itself is gone -- deleted from Settings while this was
        // open. Falling back to the first one is better than drawing a chart
        // for something that no longer exists.
        _lineId = _lines.isEmpty ? null : _lines.first.id;
      }
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
          padding: EdgeInsets.fromLTRB(
            widget.leading == null ? 24 : 8,
            24,
            24,
            0,
          ),
          child: Row(
            children: [
              ?widget.leading,
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
            child: _Counts(graph: graph, live: _live, stale: _stale),
          ),
          Expanded(
            child: LayoutBuilder(
              // The pane's own width, not the window's: this sits in a split,
              // so the window being wide says nothing about the room here.
              builder: (context, box) {
                if (box.maxWidth < 720) {
                  // The editor takes the ribbon's place, with a way back:
                  // containment (Jose: "u broke the containment rule"), not a
                  // screen pushed over the module.
                  if (_editing != null) {
                    return CallFlowEditDrawer(
                      node: _editing!,
                      api: widget.api,
                      session: widget.session,
                      width: double.infinity,
                      asPage: true,
                      onClose: () {
                        setState(() => _editing = null);
                        _refreshGraph();
                      },
                    );
                  }

                  return FlowChartRibbon(
                    graph: graph,
                    look: _phoneLook(_live),
                    onOpen: _openEditor,
                  );
                }

                return Row(
                  children: [
                    Expanded(
                      child: FlowChartCanvas(
                        graph: graph,
                        look: _phoneLook(_live),
                        onOpen: _openEditor,
                      ),
                    ),
                    if (_editing != null)
                      CallFlowEditDrawer(
                        node: _editing!,
                        api: widget.api,
                        session: widget.session,
                        onClose: () {
                          setState(() => _editing = null);
                          _refreshGraph();
                        },
                      ),
                  ],
                );
              },
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
  /// Wide draws the drawer beside the chart; narrow draws it in the chart's
  /// place, full width, with a way back.
  ///
  /// Full width rather than the inline expansion first recommended: once the
  /// drawer hosts the REAL settings panes, they need the width they already
  /// have in Settings. Narrow used to PUSH a screen for that width, which the
  /// containment rule forbids; the pane's own width is the same room without
  /// leaving the module. Which of the two is decided by the layout, from the
  /// pane's width — this used to ask the window, and a wide window around a
  /// narrow pane set an editor that nothing drew.
  void _openEditor(FlowNode node) => setState(() => _editing = node);

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
    if (g.deadCount == 1)
      '1 dead end'
    else if (g.deadCount > 1)
      '${g.deadCount} dead ends',
    if (g.skippedCount == 1)
      '1 does nothing'
    else if (g.skippedCount > 1)
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
  const _Counts({required this.graph, this.live, this.stale = false});

  final CallFlowGraph graph;

  /// The last re-read of the graph failed, so this drawing may be older than
  /// the settings behind it.
  final bool stale;

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
            label: onLine == 1
                ? '1 call on this line now'
                : '$onLine calls on this line now',
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
        // ABOVE the healthy sentence, because it is a claim ABOUT that
        // sentence: "every branch leads somewhere" is a statement about a
        // shape that may no longer be the shape.
        if (stale)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              children: [
                Icon(Icons.sync_problem, size: 14, color: scheme.error),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    'This chart could not be re-read after your change, so it '
                    'may be out of date. Reopen the tab to see it as it is.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.error,
                    ),
                  ),
                ),
              ],
            ),
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
    case phoneEntryNode:
      return Icons.call_outlined;
    case 'arrival':
      return Icons.call_received;
    case 'override':
      return Icons.alt_route;
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
    // Jose: "i also feel like we are missing stuff still like showing what
    // happens when someone does take the call, and afterwards". Phone emits
    // these three now; without an entry here they fell through to the
    // unknown-kind circle, which is the right drawing for a kind we have
    // never heard of and the wrong one for three we have.
    case 'answered':
      return Icons.call_end_outlined;
    case 'recorded':
      return Icons.fiber_manual_record_outlined;
    case 'wrap_up':
      return Icons.assignment_turned_in_outlined;
    default:
      // Not a fault and not a guess. A kind this build has never heard of is
      // drawn plainly rather than given a verdict.
      return Icons.circle_outlined;
  }
}

/// Phone's answer for one box: what it is, and who is holding in it.
///
/// The whole of Phone's side of the seam. Everything the canvas draws that is
/// NOT this -- the layering, the wires, dead and skipped -- is the same
/// drawing the Inspection Builder gets, which is the point of moving it.
FlowLook _phoneLook(CallFlowLive? live) => (node) {
  final queue = live?.forNode(node);

  return FlowNodeLook(
    icon: _iconFor(node.kind),
    caption: _kindWord(node.kind),
    // Jose: "see Live Calls flowing through the Node as well".
    note: queue == null ? '' : waitingWords(queue),
    // Somebody is on hold HERE, right now. Zero is a real answer and is drawn
    // as one, but it does not earn a ring.
    alert: (queue?.waiting ?? 0) > 0,
  );
};

String _kindWord(String kind) {
  switch (kind) {
    case phoneEntryNode:
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
    case 'override':
      return 'Override';
    case 'answered':
      return 'Picked up';
    case 'recorded':
      return 'Recording';
    case 'wrap_up':
      return 'After the call';
    default:
      return '';
  }
}

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
          graph: CallFlowGraph.fromJson(
            row['graph'] as Map<String, dynamic>?,
            // Named here rather than inherited from a default, so that
            // renaming Phone's entry node breaks Phone rather than quietly
            // leaving inspections working and this one drawing nothing.
            entry: phoneEntryNode,
          ),
        ),
    ];
  }
}
