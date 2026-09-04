import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/automation_catalogue.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/refused.dart';
import '../widgets/side_panel.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'automation_builder.dart';

/// Automations (plan §5.5, §2.1).
///
/// The main expression of the no-hardcoding rule: an organization builds its own
/// rules here and never asks for a deployment to do it.
///
/// ── Two tabs, not a panel over the list ──────────────────────────
///
/// Writing a rule used to happen in a 560px drawer laid over the list. That is
/// the wrong shape twice over: the list behind it is not context anybody needs
/// while writing a rule, and the form is far too big for the width — its
/// trigger picker was a dropdown of twenty raw event keys, which is what the
/// owner objected to. So the two jobs are two tabs on the main screen. Reading
/// what you have is one; building one is the other, with the whole page.
///
/// Panels stay for the things that genuinely sit BESIDE a row — its run
/// history, a rehearsal, confirming a delete — which is what a side panel is
/// for (see side_panel.dart).
class AutomationsScreen extends StatefulWidget {
  const AutomationsScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<AutomationsScreen> createState() => _AutomationsScreenState();
}

class _AutomationsScreenState extends State<AutomationsScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  List<Map<String, dynamic>> _automations = [];
  AutomationCatalogue? _catalogue;

  /// 0 is the list, 1 is the builder.
  int _tab = 0;

  /// The automation the builder is editing, as `GET /automations/{id}` gave it.
  /// Null means the builder is making a new one.
  Map<String, dynamic>? _editing;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. An
      // organization with no rules yet is the normal starting state, so an
      // empty list does not mean "not loaded"; the catalogue does, because it
      // is the same for everyone and arrives with the first answer. Flipping a
      // automation's switch, or deleting one, leaves the rest of the list up.
      _loading = _automations.isEmpty && _catalogue == null;
      _error = null;
    });

    final automations = await widget.api.get('/automations');
    final catalogue = await widget.api.get('/automations/catalogue');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!automations.ok) {
        _error = automations.message;
        return;
      }

      _automations = List<Map<String, dynamic>>.from(automations.data as List);
      // The catalogue is what makes the builder possible at all; without it the
      // create button would offer nothing, so its failure is worth saying.
      if (catalogue.ok) {
        _catalogue = AutomationCatalogue.fromJson(
          catalogue.data as Map<String, dynamic>,
        );
      } else {
        _error =
            'Could not load what this system can automate: ${catalogue.message}';
      }
    });
  }

  /// Why this person may not build one, or null when they may.
  ///
  /// Read from the permission set `/me` already sent. Before this, the screen
  /// downloaded that set and consulted it nowhere: New, the switch on every row
  /// and Delete were offered to a view-only holder, who got a 403 for each.
  String? get _noCreate =>
      widget.session.me?.refusal('automations.create', 'build an automation');

  /// Editing a rule and switching one on or off are the same authority — see
  /// AutomationPolicy, which routes both through `automations.edit`.
  String? get _noEdit =>
      widget.session.me?.refusal('automations.edit', 'change an automation');

  String? get _noDelete =>
      widget.session.me?.refusal('automations.delete', 'delete an automation');

  @override
  Widget build(BuildContext context) {
    final noCreate = _noCreate;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          note:
              'When something happens, do something. Rules are yours — they '
              'never need a new release.',
          action: _tab != 0
              ? null
              : Refused(
                  // Visible and refused, never hidden: somebody looking for
                  // "New automation" and not finding it concludes the app is
                  // broken, and never that their role is missing one grant.
                  reason: noCreate,
                  child: FilledButton.icon(
                    onPressed: (noCreate != null || _catalogue == null)
                        ? null
                        : _new,
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('New automation'),
                  ),
                ),
        ),
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: const [
              ('Your automations', Icons.list_alt_outlined),
              ('Build', Icons.handyman_outlined),
            ],
            selected: _tab,
            onSelected: (i) => setState(() => _tab = i),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Both tabs stay alive. Glancing at the list halfway through writing a
          // rule must not throw the half-written rule away, and a rebuilt
          // builder would do exactly that — its text lives in controllers its
          // State owns.
          //
          // Offstage rather than IndexedStack, which keeps its hidden children
          // merely invisible: they stay in the semantics tree, so a screen
          // reader would read out the whole builder while you are looking at
          // the list. Offstage takes the hidden half out of layout, painting
          // and semantics while leaving the State exactly where it was.
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(offstage: _tab != 0, child: _listTab(context)),
              Offstage(offstage: _tab != 1, child: _buildTab(context)),
            ],
          ),
        ),
      ],
    );
  }

  // ── the list ─────────────────────────────────────────────────────

  Widget _listTab(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _automations.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text(
                      'No automations yet.\n\n'
                      'A rule watches for something — a job being completed, a '
                      'customer moving franchise — and does something about it.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 16),
                    Refused(
                      reason: _noCreate,
                      child: OutlinedButton.icon(
                        onPressed: (_noCreate != null || _catalogue == null)
                            ? null
                            : _new,
                        icon: const Icon(Icons.add, size: 18),
                        label: const Text('Build your first one'),
                      ),
                    ),
                  ],
                ),
              ),
            )
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
              itemCount: _automations.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) => _row(context, _automations[i]),
            ),
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> automation) {
    final noEdit = _noEdit;
    final noDelete = _noDelete;
    final live = automation['is_live'] as bool? ?? false;
    final disabledReason = automation['disabled_reason'] as String?;
    final runs = automation['runs_count'] as int? ?? 0;
    final trigger = automation['trigger_key'] as String? ?? '';
    final description = automation['description'] as String?;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Row(
                    children: [
                      Flexible(
                        child: Text(
                          automation['name'] as String,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ),
                      Tag(live ? 'Live' : 'Off', warn: !live),
                      if (disabledReason != null)
                        const Tag('Auto-disabled', warn: true),
                    ],
                  ),
                ),
                Refused(
                  reason: noEdit,
                  child: Semantics(
                    // A bare Switch announces "on" or "off" and nothing about
                    // what. The rule's name is a sibling widget, which a screen
                    // reader has no reason to read as belonging to it.
                    label: 'Live — ${automation['name']}',
                    child: Switch(
                      value: automation['is_active'] as bool? ?? false,
                      onChanged: noEdit != null
                          ? null
                          : (on) => _setActive(automation, on),
                    ),
                  ),
                ),
                PopupMenuButton<String>(
                  // Every menu on this screen is drawn from the same icon, so
                  // "Show menu" — the framework's default — is what a screen
                  // reader reads for all of them.
                  tooltip: 'What to do with ${automation['name']}',
                  itemBuilder: (context) => [
                    refusedMenuItem(
                      context,
                      value: 'edit',
                      label: 'Open and edit',
                      reason: noEdit,
                    ),
                    const PopupMenuItem(
                      value: 'runs',
                      child: Text('Run history'),
                    ),
                    // A rehearsal is authorized as `update`, not `view` —
                    // AutomationsController::test — because rehearsing is part
                    // of authoring a rule rather than of reading one.
                    refusedMenuItem(
                      context,
                      value: 'test',
                      label: 'Test against a real event',
                      reason: noEdit,
                    ),
                    const PopupMenuDivider(),
                    refusedMenuItem(
                      context,
                      value: 'delete',
                      label: 'Delete',
                      reason: noDelete,
                    ),
                  ],
                  onSelected: (v) => _act(v, automation),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              // The raw event key told a reader nothing. The catalogue knows
              // what it is called, and falls back to the key when nothing
              // declares it any more — which is itself the useful fact.
              'When ${_catalogue?.describeTrigger(trigger) ?? trigger} · '
              'version ${automation['version'] ?? '—'} · '
              '$runs ${runs == 1 ? 'run' : 'runs'}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            if (description != null && description.trim().isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(description, style: Theme.of(context).textTheme.bodySmall),
            ],
            // Surfaced, not buried: a rule that switched itself off after
            // repeated failure has to say so where somebody will see it.
            if (disabledReason != null) ...[
              const SizedBox(height: 10),
              ErrorNote(disabledReason),
            ],
          ],
        ),
      ),
    );
  }

  // ── the builder ──────────────────────────────────────────────────

  Widget _buildTab(BuildContext context) {
    final catalogue = _catalogue;

    // The tab is reachable by tapping it, not only through "New automation", so
    // gating the button alone would leave a whole form somebody can fill in and
    // then be refused at the save — which is the worst place to find out.
    final refused = _editing == null ? _noCreate : _noEdit;

    if (refused != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: ErrorNote(refused),
          ),
        ),
      );
    }

    if (catalogue == null) {
      return AsyncBody(
        loading: _loading,
        error:
            _error ??
            'Nothing can be built until the list of what this system can '
                'automate has loaded.',
        onRetry: _load,
        child: const SizedBox.shrink(),
      );
    }

    return AutomationBuilder(
      // A new key throws the form away and starts again, which is exactly what
      // opening a different automation should do — and what pressing "New
      // automation" while one is open should do too.
      key: ValueKey(_editing?['id'] ?? 'new'),
      api: widget.api,
      catalogue: catalogue,
      formats: _formats,
      editing: _editing,
      onCancel: () => setState(() {
        _editing = null;
        _tab = 0;
      }),
      onSaved: () {
        setState(() {
          _editing = null;
          _tab = 0;
        });
        _load();
      },
    );
  }

  void _new() => setState(() {
    _editing = null;
    _tab = 1;
  });

  /// Read a rule back before editing it.
  ///
  /// The list carries a summary; the conditions, the actions and the version
  /// history only come from the detail endpoint. Until this existed there was
  /// no way at all to see what a saved rule actually did.
  Future<void> _edit(Map<String, dynamic> automation) async {
    final response = await widget.api.get('/automations/${automation['id']}');

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    setState(() {
      _editing = Map<String, dynamic>.from(response.data as Map);
      _tab = 1;
    });
  }

  // ── row actions ──────────────────────────────────────────────────

  Future<void> _setActive(Map<String, dynamic> automation, bool on) async {
    final response = await widget.api.patch(
      '/automations/${automation['id']}',
      {'is_active': on},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  Future<void> _act(String action, Map<String, dynamic> automation) async {
    switch (action) {
      case 'edit':
        await _edit(automation);
      case 'runs':
        await _showRuns(automation);
      case 'test':
        await _test(automation);
      case 'delete':
        await _delete(automation);
    }
  }

  Future<void> _delete(Map<String, dynamic> automation) async {
    final ok = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete ${automation['name']}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Delete'),
          ),
        ],
        child: const Text(
          'Its run history is kept — a record pointing at a rule that no longer '
          'exists would be unreadable.',
        ),
      ),
    );

    if (ok != true || !mounted) return;

    final response = await widget.api.delete(
      '/automations/${automation['id']}',
    );

    if (!mounted) return;

    // A refusal used to be indistinguishable from a success: the answer was
    // thrown away and the list reloaded, so a rule somebody lacked the
    // permission to delete simply reappeared with no explanation.
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    // The builder may be holding the thing that just stopped existing.
    if (_editing?['id'] == automation['id']) {
      setState(() => _editing = null);
    }

    _load();
  }

  Future<void> _test(Map<String, dynamic> automation) async {
    final response = await widget.api.post(
      '/automations/${automation['id']}/test',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      // Shown in the panel rather than a snackbar: "nothing has triggered this
      // yet, so there is no real event to try it against" is a paragraph, and a
      // snackbar takes it away while it is still being read.
      await showSidePanel<void>(
        context,
        builder: (context) => SidePanel(
          title: 'Could not rehearse it',
          width: 480,
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close'),
            ),
          ],
          child: ErrorNote(response.message),
        ),
      );
      return;
    }

    final data = response.data as Map<String, dynamic>;
    final matched = data['matched'] as bool? ?? false;
    final would = List<Map<String, dynamic>>.from(
      data['would_do'] as List? ?? [],
    );

    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: matched ? 'It would run' : 'It would not run',
        width: 560,
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              matched
                  ? 'Against the most recent real event. Nothing was actually '
                        'done — this is a rehearsal.'
                  : 'Against the most recent real event. Clause by clause:',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 14),
            ..._trace(context, data['condition_trace']),
            if (would.isNotEmpty) const SizedBox(height: 14),
            ...would.map(
              (w) => ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.play_arrow_outlined, size: 18),
                title: Text(_actionTypeLabel(w['type'])),
                subtitle: Text(w['detail'] as String? ?? ''),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _showRuns(Map<String, dynamic> automation) async {
    final response = await widget.api.get(
      '/automations/${automation['id']}/runs',
    );

    if (!mounted) return;

    final runs = response.ok
        ? List<Map<String, dynamic>>.from(response.data as List)
        : <Map<String, dynamic>>[];

    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: '${automation['name']} — run history',
        width: 620,
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
        child: !response.ok
            ? ErrorNote(response.message)
            : runs.isEmpty
            ? const Text('It has not run yet.')
            : ListView.separated(
                // The panel is full height and scrolls its own body, so the
                // list lays itself out in one piece instead of scrolling
                // inside the fixed box the dialog had to give it.
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                padding: EdgeInsets.zero,
                itemCount: runs.length,
                separatorBuilder: (_, _) => const Divider(height: 24),
                itemBuilder: (context, i) => _runEntry(context, runs[i]),
              ),
      ),
    );
  }

  Widget _runEntry(BuildContext context, Map<String, dynamic> run) {
    final status = run['status'] as String? ?? 'unknown';
    final results = List<Map<String, dynamic>>.from(
      run['action_results'] as List? ?? [],
    );
    final error = run['error'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Tag(status, warn: status == 'failed'),
            if (run['is_test'] == true) const Tag('Rehearsal'),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                // Named, like everywhere else on this screen. A run log is read
                // by whoever is asking why something did or did not happen, and
                // `job.status_changed` is not the language they asked in.
                '${_catalogue?.describeTrigger('${(run['event'] as Map?)?['key'] ?? ''}') ?? ''} · '
                '${formatStamp(run['started_at'], 'time unknown', formats: _formats)}',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
          ],
        ),
        // The server sends the reason a run failed and it was being dropped on
        // the floor, which left "failed" as the entire explanation.
        if (error != null && error.trim().isNotEmpty) ...[
          const SizedBox(height: 8),
          ErrorNote(error),
        ],
        if (status == 'skipped') ...[
          const SizedBox(height: 8),
          Text(
            'It did not run because the conditions did not match:',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 4),
          ..._trace(context, run['condition_trace']),
        ],
        ...results.map(
          (r) => Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  r['ok'] == true ? Icons.check : Icons.close,
                  size: 16,
                  color: r['ok'] == true
                      ? null
                      : Theme.of(context).colorScheme.error,
                  semanticLabel: r['ok'] == true ? 'done' : 'failed',
                ),
                const SizedBox(width: 8),
                Expanded(child: Text(r['detail'] as String? ?? '')),
              ],
            ),
          ),
        ),
      ],
    );
  }

  // ── reading a condition trace back ───────────────────────────────

  /// What the evaluator decided, clause by clause.
  ///
  /// The server has always sent this — §5.5 asks for "trigger, conditions
  /// evaluated, actions taken, outcome" and the evaluator returns a trace for
  /// exactly that reason — and the screen threw it away and printed the fixed
  /// sentence "Conditions did not match." Which condition, and what the value
  /// actually was, is the whole of what somebody debugging a rule came here to
  /// find out.
  List<Widget> _trace(BuildContext context, Object? node, {int depth = 0}) {
    if (node is! Map) {
      return depth > 0
          ? const []
          : [
              Text(
                'No record of how the conditions were judged.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ];
    }

    final reason = node['reason'];

    if (reason is String) {
      return [
        Text(
          reason == 'no conditions'
              ? 'It has no conditions, so it matches every time.'
              : reason,
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ];
    }

    final matched = node['matched'] == true;
    final children = node['children'];

    if (node['type'] == 'condition') {
      return [_clause(context, node, matched, depth)];
    }

    final heading = switch (node['type']) {
      'all' => 'All of these:',
      'any' => 'Any of these:',
      'not' => 'None of these:',
      _ => 'These:',
    };

    return [
      Padding(
        padding: EdgeInsets.only(left: depth * 16.0, top: depth == 0 ? 0 : 6),
        child: Row(
          children: [
            _verdictIcon(context, matched),
            const SizedBox(width: 8),
            Text(heading, style: Theme.of(context).textTheme.bodyMedium),
          ],
        ),
      ),
      if (children is List)
        for (final child in children)
          ..._trace(context, child, depth: depth + 1),
    ];
  }

  Widget _clause(BuildContext context, Map node, bool matched, int depth) {
    final field = '${node['field']}';
    final op = '${node['op']}';
    final label = _catalogue?.operatorLabel(op) ?? op;
    final expected = node['expected'];
    final actual = node['actual'];

    final comparison = arityOf(op) == OperatorArity.none
        ? '$field $label'
        : '$field $label ${_shown(expected)}';

    return Padding(
      padding: EdgeInsets.only(left: depth * 16.0, top: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _verdictIcon(context, matched),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(comparison),
                Text(
                  'the value at the time was ${_shown(actual)}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// "Send a notification" rather than `notify`, when the catalogue knows it.
  String _actionTypeLabel(Object? type) {
    for (final choice in _catalogue?.actionTypes ?? const <CatalogueChoice>[]) {
      if (choice.key == type) return choice.label;
    }

    return '$type';
  }

  Widget _verdictIcon(BuildContext context, bool matched) => Icon(
    matched ? Icons.check_circle_outline : Icons.cancel_outlined,
    size: 16,
    color: matched ? null : Theme.of(context).colorScheme.error,
    semanticLabel: matched ? 'matched' : 'did not match',
  );

  /// A stored value as something a person can read, with empty distinguishable
  /// from absent — the two produce completely different verdicts.
  String _shown(Object? value) => switch (value) {
    null => 'nothing',
    final List list => list.map(_shown).join(', '),
    '' => 'an empty value',
    _ => '“$value”',
  };
}
