import 'package:flutter/material.dart';

import '../../core/theme.dart';
import 'pc_specs_tab.dart';

/// The General tab's summary — the pane beside (or under) the floor plan that
/// says in words what the map says in colour.
///
/// Two states, and which one is showing is decided entirely by
/// [selectedNodeId]: nothing selected is a summary of the whole PLAN (its name,
/// its scope, and how its computers divide into online / offline / unassigned);
/// a tile selected is a summary of that one computer. Re-tapping the selected
/// tile clears the selection in the tab above, so the plan summary is never
/// somewhere you have to hunt for a close button to get back to.
///
/// ── The honesty rule, in a pane with room to spell it out ──────────
///
/// The canvas can only say green / red / grey. This pane has sentences, and
/// the rule it keeps is the same one `FloorPlanCanvas.tileColor` keeps:
/// ASSIGNED IS ASKED FIRST. A node whose status entry says `assigned:false` —
/// nobody on it, a person the viewer may not see, a person since deleted, or a
/// tile added in the current draft that the server has never resolved — reads
/// as [unassignedNote] and shows NO name and NO status, even if `online` came
/// back true beside it. "Online" about nobody is not a weaker claim than
/// "online" about someone; it is a claim about no one, and the pane does not
/// make it.
///
/// The same rule decides the specs block, which has THREE silences and tells
/// them apart rather than showing one blank for all three:
///
///   * the `specs` KEY is absent — the viewer lacks `monitoring.specs.view`,
///     and `FloorPlansController::status` withheld the key rather than sending
///     it null. Drawn as [PcSpecsTab.specsWithheldNote], the same sentence the
///     PC Specs roster gives, naming the permission.
///   * the key is present and null — the viewer may see specs and this person's
///     machines have reported none, which is [noAgentNote].
///   * the key is present with numbers — [SpecsLine], the roster's own widget,
///     so "32 GB RAM · 128 GB free" is one rendering with one set of labels
///     rather than a second that drifts the first time a key is added.
///
/// "seen 3m ago" is [seenAgo], imported from the roster for the same reason:
/// the two tabs disagreeing about how long ago a person was last seen would be
/// a bug you could only find by holding them side by side.
class FloorPlanSummary extends StatelessWidget {
  const FloorPlanSummary({
    super.key,
    required this.planName,
    required this.scopeLabel,
    required this.layout,
    required this.status,
    required this.selectedNodeId,
    this.onOpenDesktopMonitoring,
  });

  /// The plan's own name, as the picker spells it.
  final String planName;

  /// "Company · A-1 Chimney" and friends — composed by the tab, which is what
  /// holds the plan row. Empty when the plan named no scope label.
  final String scopeLabel;

  /// The layout being SHOWN — the draft while one is being edited, so the
  /// counts move as tiles are added rather than describing a saved plan that is
  /// no longer on screen.
  final Map<String, dynamic> layout;

  /// The server's per-node status map. A draft node simply has no entry, which
  /// is the unassigned reading — see the class doc comment.
  final Map<String, dynamic> status;

  final String? selectedNodeId;

  /// Switches the Monitoring screen to its Desktop Monitoring sub-tab. Null
  /// when the viewer lacks `monitoring.screenshots.view` — that tab's chip is
  /// locked for them, so an affordance pointing at it would be a button that
  /// can only ever fail. The tab above decides; this pane only draws what it
  /// was handed.
  final VoidCallback? onOpenDesktopMonitoring;

  /// The rail's width beside the map. Fixed, not a fraction: the map is
  /// fit-to-canvas and takes whatever is left, and a pane of sentences reads
  /// worse the wider it gets.
  static const double railWidth = 300;

  static const String unassignedNote = 'No one is assigned to this computer.';

  static const String noAgentNote =
      'No desktop agent has reported for this person, so there are no specs '
      'to show.';

  static const String openDesktopLabel = 'Open Desktop Monitoring';

  /// Said under the jump, because switching tabs is all it does. Pre-selecting
  /// this person inside Desktop Monitoring is not wired in v1, and a button
  /// that quietly does less than its name suggests is worse than one that says
  /// what it does.
  static const String openDesktopHint =
      'Opens the Desktop Monitoring tab, where you can choose this person.';

  @override
  Widget build(BuildContext context) {
    final node = _selectedNode;

    return Card(
      key: const Key('floor-plan-summary'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: node == null
              ? _planSummary(context)
              : _nodeSummary(context, node),
        ),
      ),
    );
  }

  List<Map<String, dynamic>> get _nodes {
    final raw = layout['nodes'];
    if (raw is! List) return const [];

    return raw.whereType<Map<String, dynamic>>().toList();
  }

  /// The selected node, or null when nothing is selected — or when the
  /// selection names a node this layout no longer has (a plan switch, a delete
  /// in the draft). Falling back to the plan summary is the honest answer
  /// there: the tab's selection is stale, and a pane about a tile that is not
  /// on the map would be describing nothing.
  Map<String, dynamic>? get _selectedNode {
    final id = selectedNodeId;
    if (id == null) return null;

    for (final node in _nodes) {
      if (node['id'] == id) return node;
    }

    return null;
  }

  List<Widget> _planSummary(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final counts = FloorPlanCounts.of(_nodes, status);

    return [
      Text(planName, style: theme.textTheme.titleMedium),
      if (scopeLabel.isNotEmpty) ...[
        const SizedBox(height: 2),
        Text(
          scopeLabel,
          style: theme.textTheme.bodySmall?.copyWith(
            color: scheme.onSurfaceVariant,
          ),
        ),
      ],
      const SizedBox(height: 12),
      Text('Computers', style: theme.textTheme.labelLarge),
      const SizedBox(height: 6),
      Text('${counts.total} in total', style: theme.textTheme.bodyMedium),
      const SizedBox(height: 4),
      _CountLine(
        colour: EzBiziTheme.clockIn(context),
        text: '${counts.online} online',
      ),
      _CountLine(colour: scheme.error, text: '${counts.offline} offline'),
      _CountLine(
        colour: scheme.outlineVariant,
        text: '${counts.unassigned} unassigned',
      ),
    ];
  }

  List<Widget> _nodeSummary(BuildContext context, Map<String, dynamic> node) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final raw = status[node['id']];
    final entry = raw is Map<String, dynamic> ? raw : null;
    final assigned = entry != null && entry['assigned'] == true;

    // The tile's own label is deliberately NOT repeated here. The canvas draws
    // it on the tile, and the selected tile is outlined — saying "Desk 3"
    // twice on one screen buys nothing, and the pane is about the PERSON.

    // Assigned is asked first — see the class doc comment. Nothing below this
    // branch runs for a tile with no resolvable person, so there is no path
    // that reaches a name or a status word for one.
    if (!assigned) {
      return [
        Text(
          unassignedNote,
          key: const Key('floor-plan-summary-unassigned'),
          style: theme.textTheme.bodyMedium,
        ),
      ];
    }

    final person = entry;
    final name = person['name'] as String? ?? '';
    final online = person['online'] == true;
    final seen = person['last_seen_at'] as String?;

    return [
      Text(
        name,
        key: const Key('floor-plan-summary-name'),
        style: theme.textTheme.titleMedium,
      ),
      const SizedBox(height: 6),
      _CountLine(
        colour: online ? EzBiziTheme.clockIn(context) : scheme.error,
        text: '${online ? 'Online' : 'Offline'} · seen ${seenAgo(seen)}',
      ),
      const SizedBox(height: 12),
      ..._specs(context, person),
      if (onOpenDesktopMonitoring != null) ...[
        const SizedBox(height: 12),
        OutlinedButton.icon(
          key: const Key('general-open-desktop-monitoring'),
          onPressed: onOpenDesktopMonitoring,
          icon: const Icon(Icons.desktop_windows_outlined),
          label: const Text(openDesktopLabel),
        ),
        const SizedBox(height: 4),
        Text(
          openDesktopHint,
          style: theme.textTheme.bodySmall?.copyWith(
            color: scheme.onSurfaceVariant,
          ),
        ),
      ],
    ];
  }

  /// The three silences, told apart — see the class doc comment. `containsKey`
  /// is what separates "withheld" from "reported nothing": a Map returns null
  /// for both, and they mean opposite things.
  List<Widget> _specs(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);

    if (!person.containsKey('specs')) {
      return [
        Text(PcSpecsTab.specsWithheldNote, style: theme.textTheme.bodyMedium),
      ];
    }

    final specs = person['specs'];

    if (specs is! Map<String, dynamic> || specs.isEmpty) {
      return [
        Text(
          noAgentNote,
          key: const Key('floor-plan-summary-no-specs'),
          style: theme.textTheme.bodyMedium,
        ),
      ];
    }

    return [SpecsLine(specs: specs)];
  }
}

/// How a plan's computers divide, by the SAME rule the canvas colours by:
/// assigned first, then online. So a node the server reported `online:true` on
/// while `assigned:false` counts as unassigned here and draws grey there — one
/// rule, two readings, and no way for the pane and the picture to disagree.
///
/// The three buckets always sum to [total]: every node lands in exactly one.
class FloorPlanCounts {
  const FloorPlanCounts({
    required this.total,
    required this.online,
    required this.offline,
    required this.unassigned,
  });

  /// Counts [nodes] (the SHOWN layout's) against the server's [status] map.
  factory FloorPlanCounts.of(
    List<Map<String, dynamic>> nodes,
    Map<String, dynamic> status,
  ) {
    var online = 0;
    var offline = 0;
    var unassigned = 0;

    for (final node in nodes) {
      final entry = status[node['id']];

      if (entry is! Map<String, dynamic> || entry['assigned'] != true) {
        unassigned++;
      } else if (entry['online'] == true) {
        online++;
      } else {
        offline++;
      }
    }

    return FloorPlanCounts(
      total: nodes.length,
      online: online,
      offline: offline,
      unassigned: unassigned,
    );
  }

  final int total;
  final int online;
  final int offline;
  final int unassigned;
}

/// A status dot and its sentence. The dot is the map's own colour for that
/// state, so the legend and the tiles cannot drift apart; the sentence is what
/// carries the meaning for anyone who cannot use the colour.
class _CountLine extends StatelessWidget {
  const _CountLine({required this.colour, required this.text});

  final Color colour;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 5),
            child: Container(
              width: 9,
              height: 9,
              decoration: BoxDecoration(shape: BoxShape.circle, color: colour),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
          ),
        ],
      ),
    );
  }
}
