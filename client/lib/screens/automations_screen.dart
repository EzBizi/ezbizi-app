import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/automation_catalogue.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../core/theme.dart';
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
  /// Which pane is showing, by KEY rather than by index.
  ///
  /// An index into a list that changes length with permissions is a bug waiting
  /// for the first person who cannot open Build: the same `1` means a different
  /// pane depending on who is looking, and nothing would say so.
  String _tab = 'rules';

  /// What the list is narrowed to (UI queue 73): words in a rule's name or
  /// sentence, and 'all', 'on', 'off' or 'attention'.
  String _query = '';
  String _show = 'all';

  /// The tabs this person may open, in the order the bar draws them.
  ///
  /// ABSENT `/me` ANSWERS NO. A screen that defaults to showing everything
  /// while its permissions load is a screen that shows the builder to somebody
  /// who may not have it, for as long as the request takes.
  List<(String, String, IconData)> get _tabs => [
    for (final tab in const [
      ('rules', 'Your automations', Icons.list_alt_outlined),
      ('build', 'Build', Icons.handyman_outlined),
    ])
      if (widget.session.me?.permissions.contains(
            'automations.${tab.$1}.view',
          ) ??
          false)
        tab,
  ];

  /// The automation the builder is editing, as `GET /automations/{id}` gave it.
  /// Null means the builder is making a new one.
  Map<String, dynamic>? _editing;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    DeepLinks.pending.addListener(_openLinked);
    _load();
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_openLinked);
    super.dispose();
  }

  /// A tapped notification about a rule opens that rule (Notifications,
  /// 2026-10-12). Taken only once the list is in, so the rule can be found;
  /// one this person's view does not list stays on the list.
  void _openLinked() {
    if (_automations.isEmpty) return;
    final link = DeepLinks.take('automations');
    if (link == null || link.type != 'automation') return;
    final rule = _automations.where((a) => a['id'] == link.id).firstOrNull;
    if (rule != null) _open(rule);
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
    // NOT ASKED FOR WHEN THE BUILDER IS NOT THIS PERSON'S. The catalogue is the
    // build tab's own endpoint and now answers 403 without that grant, so
    // requesting it anyway would put "Could not load what this system can
    // automate" across the top of a list that loaded perfectly.
    final catalogue = _mayOpen('build')
        ? await widget.api.get('/automations/catalogue')
        : null;

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!automations.ok) {
        _error = automations.message;
        return;
      }

      _automations = List<Map<String, dynamic>>.from(automations.data as List);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _openLinked();
      });
      // The catalogue is what makes the builder possible at all; without it the
      // create button would offer nothing, so its failure is worth saying.
      if (catalogue == null) {
        // Nothing to say: a person without the build tab is not missing
        // anything they can see.
      } else if (catalogue.ok) {
        _catalogue = AutomationCatalogue.fromJson(
          catalogue.data as Map<String, dynamic>,
        );
      } else {
        _error =
            'Could not load what this system can automate: ${catalogue.message}';
      }
    });
  }

  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('automations.$tab.view') ?? false;

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
          // The live count (UI queue 73). It was "Rules are yours — they never
          // need a new release", which is a developer's sentence.
          note: _automations.isEmpty
              ? 'Rules that do the next step for you when something happens.'
              : '${_automations.length} '
                    '${_automations.length == 1 ? 'rule' : 'rules'} · '
                    '${_automations.where((a) => a['is_active'] == true).length} on',
          action: _tab != 'rules'
              ? null
              : Refused(
                  // Visible and refused, never hidden: somebody looking for
                  // "New automation" and not finding it concludes the app is
                  // broken, and never that their role is missing one grant.
                  reason: noCreate,
                  child: FilledButton.icon(
                    key: const ValueKey('tour-automations-new'),
                    onPressed: (noCreate != null || _catalogue == null)
                        ? null
                        : _new,
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('New automation'),
                  ),
                ),
        ),
        if (_tabs.length > 1)
          Material(
            color: Theme.of(context).colorScheme.surface,
            child: TabBarLike(
              key: const ValueKey('tour-automations-tabs'),
              labels: [for (final tab in _tabs) (tab.$2, tab.$3)],
              selected: _tabs.indexWhere((tab) => tab.$1 == _tab),
              onSelected: (i) => setState(() => _tab = _tabs[i].$1),
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
              if (_mayOpen('rules'))
                Offstage(offstage: _tab != 'rules', child: _listTab(context)),
              if (_mayOpen('build'))
                Offstage(offstage: _tab != 'build', child: _buildTab(context)),
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
          : _groups(context),
    );
  }

  /// A rule that wants somebody: switched off by EzBizi, its last run failed,
  /// or nothing sets it off.
  bool _needsAttention(Map<String, dynamic> automation) =>
      automation['disabled_reason'] != null ||
      automation['last_run_status'] == 'failed' ||
      _catalogue?.triggerIsRaised(automation['trigger_key'] as String?) ==
          false;

  /// The module a rule's event belongs to: "Jobs" from "Jobs › Created".
  String _moduleOf(Map<String, dynamic> automation) {
    final trigger = automation['trigger_key'] as String? ?? '';
    final said = _catalogue?.describeTrigger(trigger) ?? trigger;
    final at = said.indexOf(' › ');
    return at < 0 ? 'Other' : said.substring(0, at);
  }

  /// What a rule does, in the catalogue's own words: "Send a text, then
  /// Set a field". Null until the list carries `action_keys`.
  String? _does(Map<String, dynamic> automation) {
    final keys = automation['action_keys'];
    if (keys is! List || keys.isEmpty) return null;
    String label(String key) {
      for (final type
          in _catalogue?.actionTypes ?? const <CatalogueActionType>[]) {
        if (type.key == key) return type.label;
      }
      for (final module in _catalogue?.modules ?? const <CatalogueModule>[]) {
        for (final action in module.actions) {
          if (action.key == key) return action.label;
        }
      }
      return key;
    }

    return [for (final key in keys) label('$key')].join(', then ');
  }

  Widget _groups(BuildContext context) {
    final theme = Theme.of(context);
    final words = _query.trim().toLowerCase();
    bool shown(Map<String, dynamic> a) {
      final on = a['is_active'] == true;
      final keep = switch (_show) {
        'on' => on,
        'off' => !on,
        'attention' => _needsAttention(a),
        _ => true,
      };
      if (!keep) return false;
      if (words.isEmpty) return true;
      final said = [
        a['name'],
        a['description'],
        _catalogue?.describeTrigger('${a['trigger_key']}'),
        _does(a),
      ].whereType<String>().join(' ').toLowerCase();
      return said.contains(words);
    }

    final groups = <String, List<Map<String, dynamic>>>{};
    for (final a in _automations.where(shown)) {
      groups.putIfAbsent(_moduleOf(a), () => []).add(a);
    }

    int count(bool Function(Map<String, dynamic>) test) =>
        _automations.where(test).length;
    Widget chip(String key, String label, int n) => ChoiceChip(
      key: ValueKey('automations-show-$key'),
      label: Text('$label $n'),
      selected: _show == key,
      showCheckmark: false,
      onSelected: (_) => setState(() => _show = key),
    );

    return LayoutBuilder(
      // CARDS ACROSS, NOT ONE PER LINE. Jose, 2026-09-22: "i would also
      // like to see the current automations in card format too, so i
      // can see more at the same time." A full-width row on a 27-inch
      // screen spent about a fifth of its width on the rule and the
      // rest on nothing, so four rules filled a display that could show
      // sixteen.
      //
      // The count comes from the WIDTH rather than from a breakpoint
      // name: this pane sits beside a sidebar that opens and closes, so
      // "desktop" is not one width here. 400 is where the name, its
      // tags and the switch stop competing for the same line.
      builder: (context, constraints) {
        const target = 400.0;
        const gap = 12.0;
        const sides = 48.0;

        final columns = ((constraints.maxWidth - sides) / target).floor().clamp(
          1,
          4,
        );

        final width =
            (constraints.maxWidth - sides - (columns - 1) * gap) / columns;

        // ONE HEIGHT PER ROW. A Wrap sizes each card to itself, so a
        // name on two lines or one more tag made a card taller than
        // the one beside it. Rows of [columns], each as tall as its
        // tallest card, keep every card in a row the same size.
        Widget grid(List<Map<String, dynamic>> rules) => Column(
          children: [
            for (var i = 0; i < rules.length; i += columns)
              Padding(
                padding: EdgeInsets.only(top: i == 0 ? 0 : gap),
                child: IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (
                        var j = i;
                        j < i + columns && j < rules.length;
                        j++
                      ) ...[
                        if (j > i) const SizedBox(width: gap),
                        SizedBox(width: width, child: _row(context, rules[j])),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );

        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(
                    width: 260,
                    child: TextField(
                      key: const Key('automations-search'),
                      onChanged: (text) => setState(() => _query = text),
                      decoration: const InputDecoration(
                        isDense: true,
                        prefixIcon: Icon(Icons.search),
                        hintText: 'Find a rule',
                      ),
                    ),
                  ),
                  chip('all', 'All', _automations.length),
                  chip('on', 'On', count((a) => a['is_active'] == true)),
                  chip('off', 'Off', count((a) => a['is_active'] != true)),
                  chip('attention', 'Needs attention', count(_needsAttention)),
                ],
              ),
              if (groups.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 24),
                  child: Text(
                    'No rule matches. Clear the search or pick All.',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              for (final group in groups.entries) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(2, 20, 0, 8),
                  child: Text(
                    group.key.toUpperCase(),
                    style: theme.textTheme.labelMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0.6,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                grid(group.value),
              ],
            ],
          ),
        );
      },
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> automation) {
    final noEdit = _noEdit;
    final noDelete = _noDelete;
    final noCreate = _noCreate;
    final disabledReason = automation['disabled_reason'] as String?;
    final runs = automation['runs_count'] as int? ?? 0;
    final trigger = automation['trigger_key'] as String? ?? '';
    final description = automation['description'] as String?;
    final scope = (automation['scope'] as Map?)?['label'] as String?;

    final theme = Theme.of(context);

    return Card(
      // The whole card opens the rule (UI queue 73); it used to do nothing,
      // so everything hid in the ⋮ menu.
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: ValueKey('automation-${automation['id']}'),
        onTap: () => _open(automation),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    // A WRAP, NOT A ROW, since these cards became three abreast.
                    // A Tag sizes to its own text and takes no part in a Row's
                    // negotiation, so at card width a long place name pushed the
                    // switch off the edge instead of ellipsising — the name was
                    // Flexible and the tags beside it were not. Wrapping puts the
                    // tags on their own line when they no longer fit, which is
                    // the only outcome that keeps every one of them readable.
                    child: Wrap(
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 0,
                      runSpacing: 4,
                      children: [
                        Text(
                          automation['name'] as String,
                          // Two lines: on a phone one line cut every rule to
                          // "Flag a customer with no w…", which is not what it
                          // does.
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        // The switch is the status (UI queue 73): a red "Off"
                        // beside an off switch read as an error. Only a rule
                        // EzBizi itself switched off is tagged.
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
                        key: const ValueKey('tour-automations-live'),
                        value: automation['is_active'] as bool? ?? false,
                        onChanged: noEdit != null
                            ? null
                            : (on) => _setActive(automation, on),
                      ),
                    ),
                  ),
                  PopupMenuButton<String>(
                    key: const ValueKey('tour-automations-menu'),
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
                      // A copy starts OFF, so it cannot send beside its
                      // original before somebody changes where it applies.
                      refusedMenuItem(
                        context,
                        value: 'duplicate',
                        label: 'Make a copy',
                        reason: noCreate,
                      ),
                      const PopupMenuItem(
                        value: 'runs',
                        child: Text('Run history'),
                      ),
                      const PopupMenuItem(
                        value: 'run_now',
                        // Distinct from "Test against a real event", which
                        // rehearses and performs nothing. This one really sends,
                        // so the wording has to say so before it is pressed
                        // rather than after.
                        child: Text('Run it now for real'),
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
                // The rule as a sentence (UI queue 73). The raw event key told a
                // reader nothing; the catalogue knows what it is called, and
                // falls back to the key when nothing declares it any more —
                // which is itself the useful fact.
                'When ${_catalogue?.describeTrigger(trigger) ?? trigger}'
                '${switch (_does(automation)) {
                  final does? => ' → $does',
                  null => '',
                }}',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 4),
              _lastRun(context, automation, runs, scope),
              if (description != null && description.trim().isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(description, style: Theme.of(context).textTheme.bodySmall),
              ],
              /*
             * A rule that can never run, said loudly, on the screen where
             * somebody thinks they have finished.
             *
             * The picker marks an unraised trigger quietly — there it is
             * information helping a choice, the same register as "Not built
             * yet" on an action row. Here it is not a choice any more: the
             * rule is saved, switched on, looks exactly like the live ones
             * beside it, and nothing will ever set it off. The fault has
             * HAPPENED, which is what this register means, and this is the
             * surface where nobody is looking because the work felt done.
             *
             * The volume tracks whether the fault exists yet, not how
             * important the fact is. Division agreed with the UI/UX session,
             * 2026-09-08.
             *
             * `== false` and not `!`: a null catalogue is still loading and a
             * trigger it does not carry is one this build cannot judge, and
             * neither is a licence to call somebody's rule broken.
             */
              if (_catalogue?.triggerIsRaised(trigger) == false) ...[
                const SizedBox(height: 10),
                ErrorNote(
                  'Nothing sets this off yet. The rule is saved and switched on, '
                  'but the event it waits for is not something EzBizi reports '
                  'today, so it will not run.',
                ),
              ],
              // Surfaced, not buried: a rule that switched itself off after
              // repeated failure has to say so where somebody will see it.
              if (disabledReason != null) ...[
                const SizedBox(height: 10),
                // WHEN it stopped, leading. `disabled_at` has been served by the
                // controller all along and rendered nowhere, so the card could not
                // tell "this broke this morning" from "this has been off a
                // fortnight" - which is the difference that decides what somebody
                // does next. formatStamp so it reads in the organization's own
                // date and time format rather than the browser's.
                ErrorNote(
                  '${formatStamp(automation['disabled_at'], 'At an unknown time', formats: _formats)}'
                  ' — $disabledReason',
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// When it last ran and how that went, how often this week, and where it
  /// applies — the line that says whether a rule is doing its job.
  Widget _lastRun(
    BuildContext context,
    Map<String, dynamic> automation,
    int runs,
    String? scope,
  ) {
    final theme = Theme.of(context);
    final status = automation['last_run_status'] as String?;
    final at = automation['last_run_at'];
    final week = automation['runs_this_week'];
    final small = theme.textTheme.bodySmall;

    return Wrap(
      spacing: 12,
      runSpacing: 2,
      children: [
        if (at == null)
          Text(
            runs == 0 ? 'Never run' : '$runs ${runs == 1 ? 'run' : 'runs'}',
            style: small,
          )
        else
          Text.rich(
            TextSpan(
              style: small,
              children: [
                const TextSpan(text: 'Last run '),
                TextSpan(
                  text:
                      '${formatStamp(at, 'at an unknown time', formats: _formats)} · '
                      '${switch (status) {
                        'worked' => 'worked',
                        'failed' => 'failed',
                        'skipped' => 'skipped',
                        'running' => 'running',
                        _ => 'ran',
                      }}',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: switch (status) {
                      'failed' => theme.colorScheme.error,
                      'worked' => ToneColours.of(context, Tone.ok).ink,
                      _ => null,
                    },
                  ),
                ),
              ],
            ),
          ),
        if (week is int) Text('$week this week', style: small),
        // WHERE IT APPLIES. At the organization every rule in the business is
        // listed, so without this a rule for one company is indistinguishable
        // from one that runs everywhere — which is exactly how Jose came to
        // switch his attention to a rule that was not his branch's.
        if (scope != null) Text(scope, style: small),
      ],
    );
  }

  /// A rule, opened beside the list (UI queue 73): what it does, its recent
  /// runs, and what can be done with it — Run it now apart from Try it.
  Future<void> _open(Map<String, dynamic> automation) async {
    final response = await widget.api.get(
      '/automations/${automation['id']}/runs',
    );
    if (!mounted) return;

    final runs = response.ok
        ? List<Map<String, dynamic>>.from(response.data as List)
        : <Map<String, dynamic>>[];
    final trigger = automation['trigger_key'] as String? ?? '';
    final noEdit = _noEdit;
    final noCreate = _noCreate;
    final noDelete = _noDelete;

    Future<void> then(String action) async {
      Navigator.of(context).pop();
      await _act(action, automation);
    }

    await showSidePanel<void>(
      context,
      builder: (context) {
        final theme = Theme.of(context);
        return SidePanel(
          title: '${automation['name']}',
          width: 560,
          actions: [
            Refused(
              reason: noDelete,
              child: TextButton(
                onPressed: noDelete != null ? null : () => then('delete'),
                child: const Text('Delete'),
              ),
            ),
            Refused(
              reason: noCreate,
              child: OutlinedButton(
                onPressed: noCreate != null ? null : () => then('duplicate'),
                child: const Text('Make a copy'),
              ),
            ),
            Refused(
              reason: noEdit,
              child: OutlinedButton(
                key: const Key('automation-open-try'),
                onPressed: noEdit != null ? null : () => then('test'),
                child: const Text('Try it on a recent event'),
              ),
            ),
            Refused(
              reason: noEdit,
              child: FilledButton(
                key: const Key('automation-open-edit'),
                onPressed: noEdit != null ? null : () => then('edit'),
                child: const Text('Edit'),
              ),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'When ${_catalogue?.describeTrigger(trigger) ?? trigger}'
                '${switch (_does(automation)) {
                  final does? => ' → $does',
                  null => '',
                }}',
                style: theme.textTheme.titleSmall?.copyWith(
                  color: theme.colorScheme.primary,
                ),
              ),
              if ('${automation['description'] ?? ''}'.trim().isNotEmpty) ...[
                const SizedBox(height: 6),
                Text('${automation['description']}'),
              ],
              const SizedBox(height: 6),
              _lastRun(
                context,
                automation,
                automation['runs_count'] as int? ?? 0,
                (automation['scope'] as Map?)?['label'] as String?,
              ),
              const SizedBox(height: 16),
              Text('Recent runs', style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              if (!response.ok)
                ErrorNote(response.message)
              else if (runs.isEmpty)
                const Text('It has not run yet.')
              else
                for (final run in runs.take(5)) ...[
                  _runEntry(context, run),
                  const Divider(height: 20),
                ],
              if (runs.length > 5)
                TextButton(
                  onPressed: () => then('runs'),
                  child: Text('All ${runs.length} runs'),
                ),
              const SizedBox(height: 16),
              // Apart from Try it, and saying what it does before it is
              // pressed: this one really sends.
              TextButton.icon(
                key: const Key('automation-open-run-now'),
                onPressed: () => then('run_now'),
                icon: const Icon(Icons.play_arrow),
                label: const Text('Run it now for real'),
              ),
            ],
          ),
        );
      },
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
      canEditHoursReports: widget.session.me?.can('work_hours.manage') ?? false,
      onTry: switch (_editing) {
        final saved? => () => _test(saved),
        null => null,
      },
      onCancel: () => setState(() {
        _editing = null;
        _tab = 'rules';
      }),
      onSaved: () {
        setState(() {
          _editing = null;
          _tab = 'rules';
        });
        _load();
      },
    );
  }

  void _new() => setState(() {
    _editing = null;
    _tab = 'build';
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
      _tab = 'build';
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
      case 'duplicate':
        await _duplicate(automation);
      case 'runs':
        await _showRuns(automation);
      case 'test':
        await _test(automation);
      case 'run_now':
        await _runNow(automation);
      case 'delete':
        await _delete(automation);
    }
  }

  /// Copy a rule, switched off, and open the copy to be renamed and pointed
  /// somewhere else. The server copies it: the rule as read here has its
  /// webhook secrets masked, and re-posting it would save the mask.
  Future<void> _duplicate(Map<String, dynamic> automation) async {
    final response = await widget.api.post(
      '/automations/${automation['id']}/duplicate',
      const {},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text(
          'Copied, and switched off. Change where it applies, then turn it on.',
        ),
      ),
    );

    _load();

    setState(() {
      _editing = Map<String, dynamic>.from(response.data as Map);
      _tab = 'build';
    });
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

  /// Run it for real, after saying plainly that it will.
  ///
  /// "Test against a real event" rehearses and performs nothing, which is the
  /// right default and does not answer "does the email actually arrive". A rule
  /// provable only by waiting for a missed call is a rule that sits broken for a
  /// fortnight — which is what happened to the one that started this.
  ///
  /// CONFIRMED FIRST, and the confirmation names the consequence rather than
  /// asking "are you sure": the steps really run, so real messages go to real
  /// people. Somebody who wanted the rehearsal and pressed this by mistake has
  /// one chance to notice, and "are you sure" would not give them one.
  Future<void> _runNow(Map<String, dynamic> automation) async {
    final go = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Run it now for real',
        width: 480,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Run it for real'),
          ),
        ],
        child: const Text(
          'This runs the steps against the most recent real event — for real. '
          'Any emails, texts or messages go to the people the rule names, the '
          'same as if the event had just happened. '
          'To see what it WOULD do without sending anything, close this and '
          'choose "Test against a real event" instead.',
        ),
      ),
    );

    if (go != true || !mounted) return;

    final response = await widget.api.post(
      '/automations/${automation['id']}/run-now',
      {},
    );

    if (!mounted) return;

    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: response.ok ? 'It ran' : 'Could not run it',
        width: 480,
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
        child: response.ok
            ? _ranResult(response.data as Map<String, dynamic>)
            : ErrorNote(response.message),
      ),
    );
  }

  Widget _ranResult(Map<String, dynamic> data) {
    final matched = data['matched'] as bool? ?? false;
    final did = List<Map<String, dynamic>>.from(data['did'] as List? ?? []);

    if (!matched) {
      return const Text(
        'The conditions did not match that event, so no step ran. Open Run '
        'history to see which clause failed and what value it read.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final step in did) ...[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _verdictIcon(context, step['ok'] as bool? ?? false),
              const SizedBox(width: 8),
              Expanded(child: Text('${step['detail'] ?? step['type'] ?? ''}')),
            ],
          ),
          const SizedBox(height: 8),
        ],
        if (did.isEmpty)
          const Text('The rule matched but has no steps to run.'),
      ],
    );
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
                // TWO DIFFERENT PROBLEMS THAT LOOKED IDENTICAL. A field the
                // event does not carry reads as nothing, and so does a value
                // that simply did not match — and this line said the same words
                // for both. One is a typo or a wrong field name and is fixed in
                // seconds; the other means the rule is right and the data was
                // not. A real rule went through five versions in two weeks,
                // four of them the first problem, and the run log said "the
                // value at the time was nothing" every time.
                Text(
                  node['carried'] == false
                      ? 'this event carries no field called “$field” — pick one '
                            'from the list when you edit the rule'
                      : 'the value at the time was ${_shown(actual)}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: node['carried'] == false
                        ? Theme.of(context).colorScheme.error
                        : null,
                  ),
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
