import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'floor_plan_canvas.dart';

/// Monitoring — General: an organization's machines laid out on a floor plan
/// (spec docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
///
/// The tab SHELL — the plan picker, the view-mode load of one plan's `layout`
/// + resolved `status`, and the 30s status poll — mounting [FloorPlanCanvas]
/// (Task 6) as the map area, still keyed `floor-plan-canvas`. Edit mode is
/// Task 7 and the summary sidebar is Task 8; the selection this tab holds
/// ([_selectedNodeId]) is what the sidebar will read, which is why the canvas
/// reports taps here rather than keeping the selection to itself.
///
/// ── The two grants, and what each gates ────────────────────────────
///
///   * `monitoring.view` — the same grant that gates the whole module — lets
///     someone SEE the tab and its plans. Without it there is no fetch at all,
///     only [noPermissionNote]: the list endpoint would 403 anyway, and asking
///     the server only to show the same sentence a beat later teaches nobody
///     anything (the rule every other Monitoring tab keeps).
///   * `monitoring.map.manage` — edit — is Task 7's. Here it only decides
///     whether the empty state offers an "add a plan" affordance; the affordance
///     itself is a placeholder until the editor lands.
///
/// ── The poll and its guard ─────────────────────────────────────────
///
/// The selected plan's `{id}` endpoint is re-read every [pollInterval] to keep
/// `status` fresh (plus a manual refresh). Every fetch — the first load, a
/// plan-switch, a poll tick — bumps [_loadGeneration] before its first await
/// and re-checks it after; a response that lands after a newer fetch has
/// started is stale and applies nothing. This is the exact generation guard
/// desktop_monitoring_tab's `_loadShots` uses, for the exact same reason: two
/// GETs in flight have no ordering guarantee, so a late answer for a plan you
/// have since left must never paint under the plan you are now looking at. The
/// poll timer is cancelled on dispose and on every plan-switch.
class GeneralTab extends StatefulWidget {
  const GeneralTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// How often the selected plan's status is re-read. Polling, not push —
  /// live push is deferred for the whole module until it earns its keep (spec
  /// "Deferred").
  static const Duration pollInterval = Duration(seconds: 30);

  static const String noPermissionNote =
      'Your role does not include monitoring.view, so floor plans are not '
      'shown here.';

  static const String noPlansNote = 'No floor plans yet.';

  /// Shown under [noPlansNote] only to someone who can edit — the real add
  /// flow is Task 7, so this is a hint, not a working button.
  static const String addPlanHint =
      'You can add a floor plan once map editing is available.';

  static const String selectPrompt = 'Select a floor plan to view it.';

  @override
  State<GeneralTab> createState() => _GeneralTabState();
}

class _GeneralTabState extends State<GeneralTab> {
  bool _loadingPlans = true;
  String? _plansError;
  List<Map<String, dynamic>> _plans = [];

  String? _selectedPlanId;
  bool _loadingPlan = false;
  String? _planError;
  Map<String, dynamic>? _layout;
  Map<String, dynamic>? _status;

  /// Which computer tile is selected, or null for none. Owned here, not by the
  /// canvas: the Task 8 sidebar answers a different question depending on it,
  /// so one tab-level answer keeps the two panes from disagreeing.
  String? _selectedNodeId;

  Timer? _pollTimer;

  /// Bumped every time a plan fetch STARTS (a select, a poll tick, a manual
  /// refresh). `_loadPlan` captures it before its await and re-checks it after;
  /// a response from an older generation is dropped. See the class doc comment.
  int _loadGeneration = 0;

  bool get _canView => widget.session.me?.can('monitoring.view') ?? false;

  bool get _canManage =>
      widget.session.me?.can('monitoring.map.manage') ?? false;

  @override
  void initState() {
    super.initState();
    if (_canView) _loadPlans();
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = _plans.isEmpty;
      _plansError = null;
    });

    final response = await widget.api.get('/monitoring/floor-plans');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingPlans = false;
        _plansError = response.message;
      });
      return;
    }

    final plans = (response.data as List<dynamic>).cast<Map<String, dynamic>>();

    setState(() {
      _loadingPlans = false;
      _plans = plans;
    });
  }

  void _selectPlan(String id) {
    if (id == _selectedPlanId) return;

    // Stop the old plan's poll before the id even changes, so a tick already
    // scheduled for it cannot fire against the new selection — the generation
    // guard would drop its response anyway, but cancelling is the honest thing.
    _pollTimer?.cancel();
    _pollTimer = null;

    setState(() {
      _selectedPlanId = id;
      _layout = null;
      _status = null;
      _planError = null;
      // Another plan's node ids mean nothing here.
      _selectedNodeId = null;
    });

    _loadPlan(id);
    _pollTimer =
        Timer.periodic(GeneralTab.pollInterval, (_) => _loadPlan(id));
  }

  void _refreshSelected() {
    final id = _selectedPlanId;
    if (id != null) _loadPlan(id);
  }

  /// Tapping the selected tile clears the selection, which is how you get the
  /// sidebar's whole-plan summary back without hunting for a close button.
  void _selectNode(String nodeId) {
    setState(() {
      _selectedNodeId = nodeId == _selectedNodeId ? null : nodeId;
    });
  }

  Future<void> _loadPlan(String planId) async {
    // Captured before any await; nothing between here and the increment can
    // race it, so every later check compares against whichever fetch is now
    // current.
    final generation = ++_loadGeneration;

    // A first load (or a switch, which clears `_layout`) has nothing on screen
    // yet, so it shows a spinner; a poll tick keeps the current map up and
    // swaps the data in when it lands.
    if (_layout == null) {
      setState(() {
        _loadingPlan = true;
        _planError = null;
      });
    }

    final response = await widget.api.get('/monitoring/floor-plans/$planId');

    if (!mounted || generation != _loadGeneration) return;

    if (!response.ok) {
      setState(() {
        _loadingPlan = false;
        _planError = response.message;
      });
      return;
    }

    final data = response.data as Map<String, dynamic>;

    final layout = (data['layout'] as Map<String, dynamic>?) ?? const {};

    setState(() {
      _loadingPlan = false;
      _planError = null;
      _layout = layout;
      _status = (data['status'] as Map<String, dynamic>?) ?? const {};
      // A poll can land on a layout somebody else has edited. A selection
      // pointing at a node that is no longer there would leave the sidebar
      // describing a deleted machine, so it is dropped rather than kept.
      if (_selectedNodeId != null && !_layoutHasNode(layout, _selectedNodeId!)) {
        _selectedNodeId = null;
      }
    });
  }

  static bool _layoutHasNode(Map<String, dynamic> layout, String nodeId) {
    final nodes = layout['nodes'];
    if (nodes is! List) return false;
    return nodes.any((n) => n is Map && n['id'] == nodeId);
  }

  @override
  Widget build(BuildContext context) {
    if (!_canView) {
      return _NoteCard(GeneralTab.noPermissionNote);
    }

    return AsyncBody(
      loading: _loadingPlans,
      error: _plansError,
      onRetry: _loadPlans,
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_plans.isEmpty) {
      return _EmptyState(canManage: _canManage);
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _PlanPicker(
            key: const Key('floor-plan-picker'),
            plans: _plans,
            selectedId: _selectedPlanId,
            onSelect: _selectPlan,
          ),
          const SizedBox(height: 16),
          _selectedArea(context),
        ],
      ),
    );
  }

  Widget _selectedArea(BuildContext context) {
    final id = _selectedPlanId;
    if (id == null) {
      return _NoteCard(GeneralTab.selectPrompt);
    }

    final theme = Theme.of(context);
    final plan = _plans.where((p) => p['id'] == id).firstOrNull;
    final name = plan?['name'] as String? ?? 'Floor plan';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                name,
                style: theme.textTheme.titleMedium,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            IconButton(
              key: const Key('general-refresh'),
              tooltip: 'Refresh status',
              onPressed: _refreshSelected,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (_loadingPlan)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_planError != null)
          ErrorNote(_planError!)
        else if (_layout != null) ...[
          _PlanCounts(layout: _layout!),
          const SizedBox(height: 8),
          FloorPlanCanvas(
            key: const Key('floor-plan-canvas'),
            layout: _layout!,
            status: _status ?? const {},
            selectedNodeId: _selectedNodeId,
            onSelectNode: _selectNode,
          ),
        ] else
          const SizedBox.shrink(),
      ],
    );
  }
}

/// The plan picker: the plans the viewer can see, grouped by scope so the same
/// office reads as one group. Org first, then each company, then each
/// franchise; the heading carries the scope's own label so two companies with
/// a plan each never merge. A `Wrap` of chips per group keeps it from
/// overflowing at 375px.
class _PlanPicker extends StatelessWidget {
  const _PlanPicker({
    super.key,
    required this.plans,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> plans;
  final String? selectedId;
  final ValueChanged<String> onSelect;

  static int _rank(String type) => switch (type) {
    'organization' => 0,
    'company' => 1,
    'franchise' => 2,
    _ => 3,
  };

  static String _typeTitle(String type) => switch (type) {
    'organization' => 'Organization',
    'company' => 'Company',
    'franchise' => 'Franchise',
    _ => type.isEmpty ? 'Other' : type,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Group by scope, preserving a stable order: rank by type, then the
    // scope label, so the group headings read top-down the way the org tree
    // does.
    final groups = <String, List<Map<String, dynamic>>>{};
    final groupOrder = <String>[];

    for (final plan in plans) {
      final scope = (plan['scope'] as Map<String, dynamic>?) ?? const {};
      final type = scope['type'] as String? ?? '';
      final label = scope['label'] as String? ?? '';
      final key = '${_rank(type)}|$type|$label';
      groups.putIfAbsent(key, () {
        groupOrder.add(key);
        return [];
      }).add(plan);
    }

    groupOrder.sort();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final key in groupOrder) ...[
          Builder(
            builder: (context) {
              final members = groups[key]!;
              final scope =
                  (members.first['scope'] as Map<String, dynamic>?) ??
                      const {};
              final type = scope['type'] as String? ?? '';
              final label = scope['label'] as String? ?? '';
              final heading =
                  label.isEmpty ? _typeTitle(type) : '${_typeTitle(type)} · $label';

              return Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(heading, style: theme.textTheme.titleSmall),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      children: [
                        for (final plan in members)
                          ChoiceChip(
                            key: ValueKey('floor-plan-${plan['id']}'),
                            label: Text(plan['name'] as String? ?? 'Untitled'),
                            selected: plan['id'] == selectedId,
                            onSelected: (_) => onSelect(plan['id'] as String),
                          ),
                      ],
                    ),
                  ],
                ),
              );
            },
          ),
        ],
      ],
    );
  }
}

/// What the plan holds, in words, above the map: how many computer tiles and
/// how many wall segments. The map itself is the picture; this is the caption
/// that lets you tell at a glance that you are looking at the plan you chose
/// and that it loaded whole — the same two counts a stale answer would get
/// wrong, which is what the tab's late-answer test reads.
class _PlanCounts extends StatelessWidget {
  const _PlanCounts({required this.layout});

  final Map<String, dynamic> layout;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final nodes = (layout['nodes'] as List<dynamic>?) ?? const [];
    final walls = (layout['walls'] as List<dynamic>?) ?? const [];

    return Text(
      '${nodes.length} computers · ${walls.length} walls',
      style: theme.textTheme.bodySmall
          ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
    );
  }
}

/// No plans yet. An editor additionally sees a hint that they can add one —
/// the real add flow is Task 7, so the affordance is a placeholder for now.
class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.canManage});

  final bool canManage;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(GeneralTab.noPlansNote, style: theme.textTheme.bodyMedium),
            if (canManage) ...[
              const SizedBox(height: 12),
              FilledButton.icon(
                key: const Key('general-add-plan'),
                // Task 7 wires this up; a disabled button states the
                // affordance without pretending to work yet.
                onPressed: null,
                icon: const Icon(Icons.add),
                label: const Text('New floor plan'),
              ),
              const SizedBox(height: 8),
              Text(
                GeneralTab.addPlanHint,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _NoteCard extends StatelessWidget {
  const _NoteCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
      ),
    );
  }
}
