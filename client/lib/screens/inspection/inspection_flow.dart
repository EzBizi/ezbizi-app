/// An inspection definition, drawn as the flow a technician actually walks.
///
/// Jose, 2026-09-17: *"this flow chart we did is amazing and i want it
/// implemented in other parts of the app specifically the Inspection
/// Builder"*. The drawing is Phone's, lifted to `widgets/flow_chart.dart`; this
/// file is only the two things that are Inspection's — what the graph IS, and
/// what a box is called.
///
/// ── What earns the screen, and it is not the picture ───────────────
///
/// The Builder already lists screens and fields, so a chart that only redrew
/// that list in boxes would be decoration. What the list cannot show is the
/// thing a `visible_if` does to the walk: **a screen that can never appear
/// looks exactly like one that appears every time**, in the list and in the
/// phone preview both, because the preview renders the screen you selected
/// rather than the screen a technician would reach.
///
/// So the two states carry over from the call flow with their meanings intact:
///
///   * `dead` — a condition that can never be true where it is asked. Almost
///     always a question named before it is asked: `visible_if` compares
///     against the answers given SO FAR, and a field on a later screen has no
///     answer yet, so `eq` against it is false every time, for ever, silently.
///   * `skipped` — a screen with no questions on it. The technician passes
///     through and is asked nothing.
///
/// ── The one that is not a state ────────────────────────────────────
///
/// `ne` and `not_in` against that same unanswered field are the mirror: not
/// never, but **always**. There is no node state for "this condition does
/// nothing", and inventing one would be a third colour for a thing that is not
/// a fault — so it is said on the wire instead, where the condition is.
library;

import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/flow_chart.dart';

/// The start box. Not `caller`: this graph has no caller, and reusing that id
/// would put two namespaces on one string again.
const String inspectionEntry = 'start';

/// Where a screen sits in the definition, recovered from its node id.
///
/// The id is a POSITION, and positions move when somebody reorders screens.
/// Nothing may hold one across an edit — read it at the moment of the tap and
/// throw it away, which is what [InspectionFlowPane] does.
int? screenIndexOf(FlowNode node) {
  if (!node.id.startsWith('screen-')) return null;

  return int.tryParse(node.id.substring(7));
}

/// Can this condition ever be true, here?
enum ConditionReach {
  /// It depends on the answers, which is what a condition is for.
  live,

  /// False every time. The screen cannot be reached.
  never,

  /// True every time. The condition is not doing anything.
  always,
}

/// One inspection definition as a graph.
///
/// Returns null for a definition with no screens — there is no flow to draw
/// and an empty canvas would look like a failure to load.
CallFlowGraph? inspectionFlow(Map<String, dynamic>? definition) {
  final screens = _screensOf(definition);
  if (screens.isEmpty) return null;

  // Which screen first ASKS each question. A condition may only read answers
  // from strictly earlier screens, so this is the whole reachability test.
  final askedOn = <String, int>{};
  for (var i = 0; i < screens.length; i++) {
    for (final field in _fieldsOf(screens[i])) {
      final key = '${field['key'] ?? ''}';
      if (key.isNotEmpty) askedOn.putIfAbsent(key, () => i);
    }
  }

  final labels = <String, String>{};
  for (final screen in screens) {
    for (final field in _fieldsOf(screen)) {
      final key = '${field['key'] ?? ''}';
      final label = '${field['label'] ?? ''}';
      if (key.isNotEmpty) labels[key] = label.isEmpty ? key : label;
    }
  }

  final nodes = <FlowNode>[
    FlowNode(
      id: inspectionEntry,
      kind: 'start',
      title: '${definition?['name'] ?? 'Inspection'}',
      detail: '${definition?['intro'] ?? 'The technician opens the form.'}',
      state: 'ok',
    ),
  ];

  final edges = <FlowEdge>[];

  // The spine, and the boxes that hang off it.
  //
  // A conditional screen is not simply "next": from the step before it you
  // either see it or you do not, and both are real paths. Drawing the skip as
  // its own wire is what turns a list of screens into the fan-out the canvas
  // was built for, and it is also the truer sentence.
  var anchor = inspectionEntry;
  final pending = <String>[];

  for (var i = 0; i < screens.length; i++) {
    final screen = screens[i];
    final fields = _fieldsOf(screen);
    final id = 'screen-$i';
    final condition = screen['visible_if'];
    final reach = conditionReach(condition, askedOn, i);
    final optional = condition is Map && condition.isNotEmpty;

    // `name` is what the Builder, the A1 port and the run view write; `title`
    // is read only as the report does, for a definition stored some other way.
    final name = '${screen['name'] ?? screen['title'] ?? ''}'.trim();

    nodes.add(
      FlowNode(
        id: id,
        kind: 'screen',
        title: name.isEmpty ? 'Screen ${i + 1}' : name,
        detail: _screenDetail(screen, fields, condition, labels, reach),
        state: reach == ConditionReach.never
            ? 'dead'
            : fields.isEmpty
            ? 'skipped'
            : 'ok',
      ),
    );

    edges.add(
      FlowEdge(
        from: anchor,
        to: id,
        label: optional ? _edgeWords(condition, labels, reach) : '',
      ),
    );
    for (final earlier in pending) {
      edges.add(FlowEdge(from: earlier, to: id));
    }

    if (optional && reach != ConditionReach.always) {
      // It may not be shown, so the step after it can also arrive from here.
      pending.add(id);
    } else {
      anchor = id;
      pending.clear();
    }
  }

  nodes.add(
    const FlowNode(
      id: 'submitted',
      kind: 'submitted',
      title: 'Report submitted',
      detail: 'The answers are frozen and the report is filed.',
      state: 'ok',
    ),
  );
  edges.add(FlowEdge(from: anchor, to: 'submitted'));
  for (final earlier in pending) {
    edges.add(FlowEdge(from: earlier, to: 'submitted'));
  }

  return CallFlowGraph(nodes: nodes, edges: edges, entry: inspectionEntry);
}

/// Whether a `visible_if` can ever be true on the screen at [at].
///
/// [askedOn] maps a field key to the screen that first asks it. A key missing
/// from it is asked by nobody at all, which fails the same way as one asked
/// too late and is not worth a second verdict.
ConditionReach conditionReach(
  dynamic condition,
  Map<String, int> askedOn,
  int at,
) {
  if (condition is! Map || condition.isEmpty) return ConditionReach.live;

  final op = '${condition['op'] ?? ''}';

  if (op == 'and' || op == 'or') {
    final raw = condition['conditions'];
    final children = raw is List ? raw : const [];
    if (children.isEmpty) {
      // What those words mean over an empty set, matching `conditionPasses`.
      return op == 'and' ? ConditionReach.always : ConditionReach.never;
    }

    final reaches = [
      for (final child in children) conditionReach(child, askedOn, at),
    ];

    if (op == 'and') {
      if (reaches.contains(ConditionReach.never)) return ConditionReach.never;
      if (reaches.every((r) => r == ConditionReach.always)) {
        return ConditionReach.always;
      }
      return ConditionReach.live;
    }

    if (reaches.contains(ConditionReach.always)) return ConditionReach.always;
    if (reaches.every((r) => r == ConditionReach.never)) {
      return ConditionReach.never;
    }
    return ConditionReach.live;
  }

  final field = '${condition['field'] ?? ''}';

  // A condition naming nothing is shown rather than hidden — `conditionPasses`
  // returns true for it, and this has to agree or the chart describes a form
  // nobody is filling in.
  if (field.isEmpty) return ConditionReach.always;

  final asked = askedOn[field];
  if (asked != null && asked < at) return ConditionReach.live;

  // No answer exists at this point in the walk, so every comparison is decided
  // already: `eq`/`in` cannot match nothing, and `ne`/`not_in` always do.
  switch (op) {
    case 'eq':
    case 'in':
      return ConditionReach.never;
    case 'ne':
    case 'not_in':
      return ConditionReach.always;
    default:
      // An operator this build does not know shows the field. Same rule as
      // `conditionPasses`, for the same reason.
      return ConditionReach.always;
  }
}

/// The condition in words, for the wire it is drawn on.
String conditionWords(dynamic condition, Map<String, String> labels) {
  if (condition is! Map || condition.isEmpty) return '';

  final op = '${condition['op'] ?? ''}';

  if (op == 'and' || op == 'or') {
    final raw = condition['conditions'];
    final children = raw is List ? raw : const [];
    final parts = [
      for (final child in children)
        if (conditionWords(child, labels).isNotEmpty)
          conditionWords(child, labels),
    ];
    if (parts.isEmpty) return '';

    return parts.join(op == 'and' ? ' and ' : ' or ');
  }

  final field = '${condition['field'] ?? ''}';
  if (field.isEmpty) return '';

  final name = labels[field] ?? field;
  final values = op == 'in' || op == 'not_in'
      ? [
          for (final v in (condition['values'] is List
              ? condition['values'] as List
              : const []))
            '$v',
        ]
      : ['${condition['value'] ?? ''}'];
  final spoken = values.where((v) => v.isNotEmpty).join(' or ');
  if (spoken.isEmpty) return name;

  return op == 'ne' || op == 'not_in'
      ? '$name is not $spoken'
      : '$name is $spoken';
}

String _edgeWords(
  dynamic condition,
  Map<String, String> labels,
  ConditionReach reach,
) {
  final words = conditionWords(condition, labels);

  // Said on the wire rather than given a node state, because a condition that
  // does nothing is not a broken screen — the screen is fine and shown every
  // time, and its author almost certainly meant otherwise.
  if (reach == ConditionReach.always) {
    return words.isEmpty ? 'Always' : 'Always — $words never fails here';
  }

  return words;
}

String _screenDetail(
  Map<String, dynamic> screen,
  List<Map<String, dynamic>> fields,
  dynamic condition,
  Map<String, String> labels,
  ConditionReach reach,
) {
  if (reach == ConditionReach.never) {
    final words = conditionWords(condition, labels);

    return words.isEmpty
        ? 'This screen can never be shown.'
        : 'Never shown: "$words" is asked before that answer exists.';
  }

  if (fields.isEmpty) return 'No questions on this screen.';

  final conditional = fields
      .where((f) => f['visible_if'] is Map && (f['visible_if'] as Map).isNotEmpty)
      .length;
  final count = fields.length == 1 ? '1 question' : '${fields.length} questions';

  return conditional == 0 ? count : '$count, $conditional shown by condition';
}

List<Map<String, dynamic>> _screensOf(Map<String, dynamic>? definition) {
  final raw = definition?['screens'];
  if (raw is! List) return const [];

  return [
    for (final s in raw)
      if (s is Map) Map<String, dynamic>.from(s),
  ];
}

List<Map<String, dynamic>> _fieldsOf(Map<String, dynamic> screen) {
  final raw = screen['fields'];
  if (raw is! List) return const [];

  return [
    for (final f in raw)
      if (f is Map) Map<String, dynamic>.from(f),
  ];
}

/// Inspection's side of the chart's seam: what one box is called.
FlowNodeLook inspectionLook(FlowNode node) {
  switch (node.kind) {
    case 'start':
      return const FlowNodeLook(
        icon: Icons.play_circle_outline,
        caption: 'Starts here',
      );
    case 'submitted':
      return const FlowNodeLook(
        icon: Icons.assignment_turned_in_outlined,
        caption: 'Filed',
      );
    default:
      return FlowNodeLook(
        icon: Icons.checklist_rtl,
        // The question count, which is the one thing about a screen you cannot
        // read off its name.
        caption: node.detail.split(',').first.split(':').first.trim(),
      );
  }
}

/// The chart, on whichever of the two layouts fits.
class InspectionFlowPane extends StatelessWidget {
  const InspectionFlowPane({
    super.key,
    required this.definition,
    required this.onOpenScreen,
  });

  final Map<String, dynamic>? definition;

  /// Tapping a screen box selects it in the Builder, so the chart is a way to
  /// navigate the definition rather than a second place to read it.
  final void Function(int screenIndex) onOpenScreen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final graph = inspectionFlow(definition);

    if (graph == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            definition == null
                ? 'Choose an inspection to see its flow.'
                : 'Add a screen and the flow appears here.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      );
    }

    void open(FlowNode node) {
      final index = screenIndexOf(node);
      if (index != null) onOpenScreen(index);
    }

    return Column(
      children: [
        _Faults(graph: graph),
        Expanded(
          child: LayoutBuilder(
            // The pane's width, not the window's: this sits in a split beside
            // two others, so a wide window says nothing about the room here.
            builder: (context, box) => box.maxWidth < 720
                ? FlowChartRibbon(
                    graph: graph,
                    look: inspectionLook,
                    onOpen: open,
                  )
                : FlowChartCanvas(
                    graph: graph,
                    look: inspectionLook,
                    onOpen: open,
                  ),
          ),
        ),
      ],
    );
  }
}

/// The two counts, above the drawing rather than inside it.
///
/// Phone's reason holds here unchanged: a correct flow and a broken one draw
/// identically unless the states are read, and on the narrow layout nothing is
/// visible until you have scrolled to it.
class _Faults extends StatelessWidget {
  const _Faults({required this.graph});

  final CallFlowGraph graph;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dead = graph.deadCount;
    final skipped = graph.skippedCount;

    if (dead == 0 && skipped == 0) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
        child: Row(
          children: [
            Icon(Icons.check_circle_outline, size: 15, color: scheme.primary),
            const SizedBox(width: 6),
            Text(
              'Every screen can be reached.',
              style: theme.textTheme.labelSmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
      child: Wrap(
        spacing: 8,
        runSpacing: 4,
        children: [
          if (dead > 0)
            _Chip(
              text: dead == 1
                  ? '1 screen can never be shown'
                  : '$dead screens can never be shown',
              colour: scheme.error,
            ),
          if (skipped > 0)
            _Chip(
              text: skipped == 1
                  ? '1 screen asks nothing'
                  : '$skipped screens ask nothing',
              colour: FlowCanvas.light.amber,
            ),
        ],
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.text, required this.colour});

  final String text;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        style: theme.textTheme.labelSmall?.copyWith(
          color: colour,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
