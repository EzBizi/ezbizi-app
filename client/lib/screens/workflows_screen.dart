import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';

/// Workflows & automations (plan §5.5, §2.1).
///
/// The main expression of the no-hardcoding rule: an organization builds its own
/// rules here and never asks for a deployment to do it.
///
/// ── Simple mode only, for now ────────────────────────────────────
///
/// The engine takes a nested AND/OR tree. This builds the flat "all of these"
/// case, which is what almost every real rule is, and sends it as `{"all": [...]}`
/// so the stored shape is already the full one. A nested builder can be added
/// later without touching a single saved rule — the same two-tier idea as
/// permissions: a simple surface over a model that is expressive underneath.
class WorkflowsScreen extends StatefulWidget {
  const WorkflowsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WorkflowsScreen> createState() => _WorkflowsScreenState();
}

class _WorkflowsScreenState extends State<WorkflowsScreen> {
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
  List<Map<String, dynamic>> _workflows = [];
  Map<String, dynamic>? _catalogue;

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
      // workflow's switch, or deleting one, leaves the rest of the list up.
      _loading = _workflows.isEmpty && _catalogue == null;
      _error = null;
    });

    final workflows = await widget.api.get('/workflows');
    final catalogue = await widget.api.get('/workflows/catalogue');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!workflows.ok) {
        _error = workflows.message;
        return;
      }

      _workflows = List<Map<String, dynamic>>.from(workflows.data as List);
      // The catalogue is what makes the builder possible at all; without it the
      // create button would offer nothing, so its failure is worth saying.
      if (catalogue.ok) {
        _catalogue = catalogue.data as Map<String, dynamic>;
      } else {
        _error =
            'Could not load what this system can automate: ${catalogue.message}';
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Workflows',
          note:
              'When something happens, do something. Rules are yours — they '
              'never need a new release.',
          action: FilledButton.icon(
            onPressed: _catalogue == null ? null : _create,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New workflow'),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _workflows.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: Text(
                        'No workflows yet.\n\n'
                        'A rule watches for something — a job being completed, a '
                        'customer moving franchise — and does something about it.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _workflows.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _workflows[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> workflow) {
    final live = workflow['is_live'] as bool? ?? false;
    final disabledReason = workflow['disabled_reason'] as String?;
    final runs = workflow['runs_count'] as int? ?? 0;

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
                          workflow['name'] as String,
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
                Switch(
                  value: workflow['is_active'] as bool? ?? false,
                  onChanged: (on) => _setActive(workflow, on),
                ),
                PopupMenuButton<String>(
                  itemBuilder: (_) => const [
                    PopupMenuItem(value: 'runs', child: Text('Run history')),
                    PopupMenuItem(
                      value: 'test',
                      child: Text('Test against a real event'),
                    ),
                    PopupMenuDivider(),
                    PopupMenuItem(value: 'delete', child: Text('Delete')),
                  ],
                  onSelected: (v) => _act(v, workflow),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'When ${workflow['trigger_key']} · version ${workflow['version'] ?? '—'} · '
              '$runs ${runs == 1 ? 'run' : 'runs'}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
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

  Future<void> _setActive(Map<String, dynamic> workflow, bool on) async {
    final response = await widget.api.patch('/workflows/${workflow['id']}', {
      'is_active': on,
    });

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  Future<void> _act(String action, Map<String, dynamic> workflow) async {
    switch (action) {
      case 'runs':
        await _showRuns(workflow);
      case 'test':
        await _test(workflow);
      case 'delete':
        await _delete(workflow);
    }
  }

  Future<void> _delete(Map<String, dynamic> workflow) async {
    final ok = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete ${workflow['name']}?',
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

    await widget.api.delete('/workflows/${workflow['id']}');
    if (mounted) _load();
  }

  Future<void> _test(Map<String, dynamic> workflow) async {
    final response = await widget.api.post(
      '/workflows/${workflow['id']}/test',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
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
        width: 520,
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
                  : 'The conditions did not match the most recent real event.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 14),
            ...would.map(
              (w) => ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.play_arrow_outlined, size: 18),
                title: Text(w['type'] as String),
                subtitle: Text(w['detail'] as String? ?? ''),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _showRuns(Map<String, dynamic> workflow) async {
    final response = await widget.api.get('/workflows/${workflow['id']}/runs');

    if (!mounted) return;

    final runs = response.ok
        ? List<Map<String, dynamic>>.from(response.data as List)
        : <Map<String, dynamic>>[];

    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: '${workflow['name']} — run history',
        width: 560,
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
        child: runs.isEmpty
            ? const Text('It has not run yet.')
            : ListView.separated(
                // The panel is full height and scrolls its own body, so the
                // list lays itself out in one piece instead of scrolling
                // inside the fixed box the dialog had to give it.
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                padding: EdgeInsets.zero,
                itemCount: runs.length,
                separatorBuilder: (_, _) => const Divider(height: 16),
                itemBuilder: (context, i) {
                  final run = runs[i];
                  final results = List<Map<String, dynamic>>.from(
                    run['action_results'] as List? ?? [],
                  );

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Tag(
                            run['status'] as String,
                            warn: run['status'] == 'failed',
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              '${(run['event'] as Map)['key'] ?? ''} · '
                              '${run['started_at']}',
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                          ),
                        ],
                      ),
                      // A skip without the reason is not an answer to anyone
                      // asking why their automation did not fire.
                      if (run['status'] == 'skipped')
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text(
                            'Conditions did not match.',
                            style: Theme.of(context).textTheme.bodyMedium,
                          ),
                        ),
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
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(r['detail'] as String? ?? ''),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  );
                },
              ),
      ),
    );
  }

  Future<void> _create() async {
    final made = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _NewWorkflowPanel(api: widget.api, catalogue: _catalogue!),
    );

    if (made == true && mounted) _load();
  }
}

class _NewWorkflowPanel extends StatefulWidget {
  const _NewWorkflowPanel({required this.api, required this.catalogue});

  final ApiClient api;
  final Map<String, dynamic> catalogue;

  @override
  State<_NewWorkflowPanel> createState() => _NewWorkflowPanelState();
}

class _NewWorkflowPanelState extends State<_NewWorkflowPanel> {
  final _name = TextEditingController();

  String? _trigger;
  final List<Map<String, String>> _conditions = [];
  final List<Map<String, String>> _actions = [];

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  List<String> get _triggers => [
    for (final m in widget.catalogue['modules'] as List)
      ...List<String>.from((m as Map)['triggers'] as List? ?? []),
  ];

  List<Map<String, dynamic>> get _operators =>
      List<Map<String, dynamic>>.from(widget.catalogue['operators'] as List);

  List<Map<String, dynamic>> get _actionTypes =>
      List<Map<String, dynamic>>.from(widget.catalogue['action_types'] as List);

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/workflows', {
      'name': _name.text.trim(),
      'trigger_key': _trigger,
      // Sent as the full tree shape even in simple mode, so a nested builder
      // added later needs no migration of anything already saved.
      if (_conditions.isNotEmpty)
        'conditions': {
          'all': _conditions
              .map(
                (c) => {
                  'field': c['field'],
                  'op': c['op'],
                  'value': c['value'],
                },
              )
              .toList(),
        },
      'actions': _actions.map((a) {
        return switch (a['type']) {
          'update_field' => {
            'type': 'update_field',
            'field': a['field'],
            'value': a['value'],
          },
          'webhook' => {'type': 'webhook', 'url': a['url']},
          _ => {'type': 'notify', 'message': a['message']},
        };
      }).toList(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'New workflow',
      width: 560,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed:
              (_busy ||
                  _trigger == null ||
                  _actions.isEmpty ||
                  _name.text.trim().isEmpty)
              ? null
              : _submit,
          child: const Text('Create'),
        ),
      ],
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _name,
            decoration: const InputDecoration(
              labelText: 'Name it something you will recognise',
            ),
            enabled: !_busy,
            autofocus: true,
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _trigger,
            decoration: const InputDecoration(
              labelText: 'When this happens',
              helperText: 'Only things this system actually emits are listed.',
            ),
            items: _triggers
                .map((t) => DropdownMenuItem(value: t, child: Text(t)))
                .toList(),
            onChanged: _busy ? null : (v) => setState(() => _trigger = v),
          ),

          const SizedBox(height: 20),
          _sectionHeader(
            context,
            'And all of these are true',
            'Optional — no '
                'conditions means every time.',
            () {
              setState(
                () => _conditions.add({'field': '', 'op': 'eq', 'value': ''}),
              );
            },
          ),

          ..._conditions.asMap().entries.map(
            (e) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    flex: 3,
                    child: TextFormField(
                      initialValue: e.value['field'],
                      decoration: const InputDecoration(
                        labelText: 'Field',
                        isDense: true,
                      ),
                      onChanged: (v) => e.value['field'] = v,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 3,
                    child: DropdownButtonFormField<String>(
                      initialValue: e.value['op'],
                      decoration: const InputDecoration(
                        labelText: 'Is',
                        isDense: true,
                      ),
                      items: _operators
                          .map(
                            (o) => DropdownMenuItem(
                              value: o['key'] as String,
                              child: Text(o['label'] as String),
                            ),
                          )
                          .toList(),
                      onChanged: (v) =>
                          setState(() => e.value['op'] = v ?? 'eq'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 3,
                    child: TextFormField(
                      initialValue: e.value['value'],
                      decoration: const InputDecoration(
                        labelText: 'Value',
                        isDense: true,
                      ),
                      onChanged: (v) => e.value['value'] = v,
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () =>
                        setState(() => _conditions.remove(e.value)),
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 16),
          _sectionHeader(context, 'Then do this', 'At least one.', () {
            setState(() => _actions.add({'type': 'notify', 'message': ''}));
          }),

          ..._actions.asMap().entries.map(
            (e) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    flex: 3,
                    child: DropdownButtonFormField<String>(
                      initialValue: e.value['type'],
                      decoration: const InputDecoration(
                        labelText: 'Action',
                        isDense: true,
                      ),
                      items: _actionTypes
                          .map(
                            (a) => DropdownMenuItem(
                              value: a['key'] as String,
                              child: Text(a['label'] as String),
                            ),
                          )
                          .toList(),
                      onChanged: (v) =>
                          setState(() => e.value['type'] = v ?? 'notify'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(flex: 5, child: _actionFields(e.value)),
                  IconButton(
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () => setState(() => _actions.remove(e.value)),
                  ),
                ],
              ),
            ),
          ),

          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],

          const SizedBox(height: 12),
          Text(
            'It is saved switched off. Turn it on once you have read it back.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ],
      ),
    );
  }

  Widget _actionFields(Map<String, String> action) {
    return switch (action['type']) {
      'update_field' => Row(
        children: [
          Expanded(
            child: TextFormField(
              initialValue: action['field'],
              decoration: const InputDecoration(
                labelText: 'Field',
                isDense: true,
              ),
              onChanged: (v) => action['field'] = v,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: TextFormField(
              initialValue: action['value'],
              decoration: const InputDecoration(
                labelText: 'Set to',
                isDense: true,
              ),
              onChanged: (v) => action['value'] = v,
            ),
          ),
        ],
      ),
      'webhook' => TextFormField(
        initialValue: action['url'],
        decoration: const InputDecoration(
          labelText: 'https:// URL',
          isDense: true,
          helperText: 'Public addresses only — internal ones are refused.',
        ),
        onChanged: (v) => action['url'] = v,
      ),
      _ => TextFormField(
        initialValue: action['message'],
        decoration: const InputDecoration(
          labelText: 'Message',
          isDense: true,
          helperText: 'Use {{ title }} to include a field.',
        ),
        onChanged: (v) => action['message'] = v,
      ),
    };
  }

  Widget _sectionHeader(
    BuildContext context,
    String title,
    String note,
    VoidCallback onAdd,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleMedium),
                Text(note, style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
          ),
          TextButton.icon(
            onPressed: _busy ? null : onAdd,
            icon: const Icon(Icons.add, size: 16),
            label: const Text('Add'),
          ),
        ],
      ),
    );
  }
}
