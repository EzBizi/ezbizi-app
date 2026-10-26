import 'package:flutter/material.dart';

import '../../core/branch_options.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/status_pill.dart';
import 'pm_frame.dart';
import 'pm_stages.dart';
import 'project_management_tab.dart' show pmOpenRule;

/// `GET jobs/project-management/rules`, read once by the screen and shared:
/// the Rules tab draws it, and the Overview's ⓘ say its sentences.
@immutable
class PmRules {
  const PmRules({this.configs = const [], this.automations = const []});

  factory PmRules.parse(Object? data) {
    List<Map<String, dynamic>> maps(Object? v) => [
      for (final m in v is List ? v : const [])
        if (m is Map) m.cast<String, dynamic>(),
    ];
    final d = data is Map ? data : const {};
    return PmRules(
      configs: maps(d['configs']),
      automations: maps(d['automations']),
    );
  }

  /// One per group of branches set up alike.
  final List<Map<String, dynamic>> configs;
  final List<Map<String, dynamic>> automations;

  static Map<String, dynamic> _map(Object? v) =>
      v is Map ? v.cast<String, dynamic>() : const {};

  static List<Map<String, dynamic>> stagesOf(Map<String, dynamic> config) => [
    for (final s in (config['stages'] as List?) ?? const [])
      if (s is Map) s.cast<String, dynamic>(),
  ];

  static Map<String, dynamic> alertOf(Map<String, dynamic> config, String k) =>
      _map(_map(config['alerts'])[k]);

  /// The first config's scope: what the scope bar says is set where.
  String? get scopeSetAt {
    if (configs.isEmpty) return null;
    final at = _map(configs.first['scope'])['set_at'];
    return at is String ? at : null;
  }

  /// A stage's rule sentence, each branch group's when they differ.
  String? stageSentence(String key, Me? me) {
    final lines = <String>[];
    for (final c in configs) {
      for (final s in stagesOf(c)) {
        if (s['key'] == key && s['sentence'] is String) {
          lines.add(
            configs.length > 1
                ? '${pmBranchGroupLabel(c, me)}: ${s['sentence']}'
                : '${s['sentence']}',
          );
        }
      }
    }
    return lines.isEmpty ? null : lines.join('\n');
  }

  /// The scope sentence, each branch group's when they differ.
  String? get scopeSentence {
    final lines = {
      for (final c in configs)
        if (_map(c['scope'])['sentence'] is String)
          _map(c['scope'])['sentence'] as String,
    };
    return lines.isEmpty ? null : lines.join('\n');
  }

  /// The daily check's own words, by its name.
  String? serverJob(String name) {
    for (final c in configs) {
      for (final j in (c['server_jobs'] as List?) ?? const []) {
        if (j is Map && j['name'] == name && j['what'] is String) {
          return '${j['when']}: ${j['what']}';
        }
      }
    }
    return null;
  }
}

/// A branch group as "Company · Branch, Company · Branch" (branch_options):
/// the server sends the branches' own names and the place they share.
String pmBranchGroupLabel(Map<String, dynamic> config, Me? me) {
  final place = PmRules._map(config['place']);
  final names = [
    for (final b in (config['branches'] as List?) ?? const []) '$b',
  ];
  String one(String name) {
    if (place['type'] == 'company' && place['name'] is String) {
      return '${place['name']} · $name';
    }
    for (final f in me?.franchises ?? const <FranchiseSummary>[]) {
      if (f.name == name) return branchLabelOf(me, f.id, name);
    }
    return name;
  }

  return names.map(one).join(', ');
}

/// The sections the Rules tab scrolls to.
const pmRuleSections = [
  'warnings',
  'scope',
  'stages',
  'automations',
  'daily',
  'alerts',
  'numbers',
];

/// Where a tab's "How this is measured ›" lands on Rules.
String pmRulesSectionFor(String tab) => switch (tab) {
  'dashboard' => 'numbers',
  'pending' => 'stages',
  'assignments' => 'automations',
  'reports' => 'numbers',
  'logs' => 'automations',
  'settings' => 'scope',
  _ => 'scope',
};

/// The Settings cards a Rules section is changed in.
String pmSettingsCardFor(String section) => switch (section) {
  'warnings' || 'stages' || 'numbers' => 'stages',
  'daily' || 'alerts' => 'alerts',
  _ => 'scope',
};

/// A rule's trigger key in words.
String pmTriggerSentence(Object? trigger) => switch (trigger) {
  'job.payment_received' => 'a payment is received on a job',
  'job.status_changed' => "a job's status changes",
  'job.created' => 'a job is created',
  'job.updated' => 'a job is changed',
  'job.estimate_aging' => 'the daily estimate check finds a job',
  'job.completed' => 'a job is completed',
  _ =>
    '${trigger ?? 'something happens'}'
        .replaceAll('.', ' ')
        .replaceAll('_', ' '),
};

/// One row of "How each number is measured": what the number is, and the
/// rule behind it, in plain words (the audit's section 3, 2026-10-23).
typedef PmMeasure = ({String number, String rule});

List<PmMeasure> pmMeasures({int? warnDays, int? stoppedDays}) {
  final warn = warnDays == null ? 'the warning days' : '$warnDays days';
  final stopped = stoppedDays == null
      ? 'the stopped days'
      : '$stoppedDays days';
  return [
    (
      number: 'Every count',
      rule:
          'Only jobs in scope ("Which jobs count"), in branches you can see, '
          'narrowed by the search, service area and filters on the page.',
    ),
    for (final key in pmStageKeys)
      (
        number: pmStageName(key),
        rule:
            'Jobs in scope matching the stage\'s statuses, schedule and money '
            'due ("How a job moves between stages").',
      ),
    (
      number: 'Ready to schedule · oldest',
      rule:
          'Days since the oldest of these jobs last changed status; since it '
          'was created if it never did.',
    ),
    (
      number: 'Scheduled · next 7 days',
      rule: 'Scheduled from the start of today to the end of 7 days from now.',
    ),
    (
      number: 'In progress · our own technicians / external company',
      rule: 'Split by whether the job has an external company set.',
    ),
    (
      number: 'Pipeline',
      rule:
          'Jobs in Waiting review, Ready to schedule, Scheduled or In '
          'progress. The money is the sum of their Workiz total prices.',
    ),
    (
      number: 'Paid but not started',
      rule:
          'Jobs in Waiting review, Ready to schedule or Scheduled whose first '
          'payment was $warn or more ago.',
    ),
    (
      number: 'Payments stopped',
      rule:
          'Jobs in any stage that have a payment, still owe money, and whose '
          'last payment is more than $stopped old.',
    ),
    (
      number: 'Waiting review with no reason',
      rule: 'Jobs in Waiting review with no review reason picked.',
    ),
    (
      number: 'Open jobs by technician',
      rule:
          'Jobs in Waiting review, Ready to schedule, Scheduled or In '
          "progress, by the job's technician; Unassigned when it has none.",
    ),
    (
      number: 'Jobs · After first payment',
      rule:
          'Days since the first payment, against the warning days. Shown only '
          'in the stages not started yet, where the warning applies.',
    ),
    (
      number: 'Repair team',
      rule:
          "Financials' Repair Team sheet, one branch and one week at a time; "
          "not limited to Project Management's jobs. Shares are a percentage "
          'of total cost. Cost from gross is company share divided by jobs '
          'amount, red above the cost alert.',
    ),
    (
      number: 'Logs',
      rule:
          'Rule runs on jobs in your branches, and edits people made to jobs, '
          'in the dates chosen, by the day on this device.',
    ),
  ];
}

/// Project Management › Rules (design 144, option 1): how it all works,
/// read live from the rules endpoint and never edited here. Each block says
/// where it is changed ("Change ›"): the right Settings card, or the rule in
/// Automations.
class ProjectManagementRules extends StatefulWidget {
  const ProjectManagementRules({
    super.key,
    required this.session,
    required this.rules,
    this.error,
    this.loading = false,
    this.onRetry,
    this.focus,
    this.onChange,
    this.scope = const PmScope(),
  });

  final Session session;

  /// Null while it loads, or when it could not.
  final PmRules? rules;
  final String? error;
  final bool loading;
  final VoidCallback? onRetry;

  /// A section to scroll to, and a serial telling two asks apart.
  final (String, int)? focus;

  /// "Change ›": the Settings card by its key.
  final void Function(String card)? onChange;

  final PmScope scope;

  @override
  State<ProjectManagementRules> createState() => _ProjectManagementRulesState();
}

class _ProjectManagementRulesState extends State<ProjectManagementRules> {
  final _keys = {for (final s in pmRuleSections) s: GlobalKey()};
  final _search = TextEditingController();
  String _q = '';

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _scrollSoon();
  }

  @override
  void didUpdateWidget(ProjectManagementRules old) {
    super.didUpdateWidget(old);
    if (widget.focus != old.focus ||
        (old.rules == null && widget.rules != null)) {
      _scrollSoon();
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _scrollSoon() {
    final focus = widget.focus;
    if (focus == null) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final c = _keys[focus.$1]?.currentContext;
      if (c == null || !c.mounted) return;
      Scrollable.ensureVisible(c, duration: Duration.zero);
    });
  }

  bool _matches(Iterable<Object?> words) {
    if (_q.isEmpty) return true;
    return words.any((w) => '${w ?? ''}'.toLowerCase().contains(_q));
  }

  @override
  Widget build(BuildContext context) {
    return PmFrame(
      tab: 'rules',
      purpose:
          'How Project Management works, read from your live settings and '
          'rules. Nothing here is edited; each part links to where it is '
          'changed.',
      scope: widget.scope,
      search: TextField(
        key: const ValueKey('pm-rules-search'),
        controller: _search,
        onChanged: (t) => setState(() => _q = t.trim().toLowerCase()),
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Find a rule, stage or number',
        ),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final pad = PmFrame.gutter(constraints.maxWidth);
          final rules = widget.rules;
          if (rules == null) {
            return Center(
              child: widget.error != null
                  ? Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          widget.error!,
                          key: const ValueKey('pm-rules-error'),
                        ),
                        if (widget.onRetry != null)
                          TextButton(
                            onPressed: widget.onRetry,
                            child: const Text('Try again'),
                          ),
                      ],
                    )
                  : const CircularProgressIndicator(),
            );
          }
          // Not a lazy list: a section scrolled to must be built.
          return SingleChildScrollView(
            key: const ValueKey('pm-rules'),
            padding: EdgeInsets.fromLTRB(pad, 0, pad, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(context, rules),
            ),
          );
        },
      ),
    );
  }

  List<Widget> _blocks(BuildContext context, PmRules rules) {
    final many = rules.configs.length > 1;
    final warnings = [
      for (final c in rules.configs)
        for (final w in (c['warnings'] as List?) ?? const []) (c, '$w'),
    ];
    Widget gap() => const SizedBox(height: 16);

    return [
      KeyedSubtree(
        key: _keys['warnings'],
        child: warnings.isEmpty
            ? const SizedBox.shrink()
            : Column(
                key: const ValueKey('tour-pm-rules-warnings'),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final (i, (c, w)) in warnings.indexed)
                    _warning(
                      context,
                      i,
                      many ? pmBranchGroupLabel(c, _me) : null,
                      w,
                    ),
                  gap(),
                ],
              ),
      ),
      _block(
        context,
        section: 'scope',
        anchor: const ValueKey('tour-pm-rules-scope'),
        title: 'Which jobs count',
        children: [for (final c in rules.configs) _scopePart(context, c, many)],
      ),
      gap(),
      _block(
        context,
        section: 'stages',
        anchor: const ValueKey('tour-pm-rules-stages'),
        title: 'How a job moves between stages',
        children: [
          for (final c in rules.configs) _stagesPart(context, c, many),
        ],
      ),
      gap(),
      _block(
        context,
        section: 'automations',
        anchor: const ValueKey('tour-pm-rules-automations'),
        title: 'What changes jobs automatically',
        change: _openAutomations(context),
        changeLabel: 'Open Automations ›',
        children: [
          if (rules.automations.isEmpty)
            _muted(context, 'No rule acts on jobs.')
          else
            for (final a in rules.automations)
              if (_matches([
                a['name'],
                pmTriggerSentence(a['trigger']),
                a['conditions'],
                ...(a['actions'] as List? ?? const []),
              ]))
                _automation(context, a),
        ],
      ),
      gap(),
      _block(
        context,
        section: 'daily',
        anchor: const ValueKey('tour-pm-rules-daily'),
        title: 'Daily checks',
        children: [for (final c in rules.configs) _dailyPart(context, c, many)],
      ),
      gap(),
      _block(
        context,
        section: 'alerts',
        anchor: const ValueKey('tour-pm-rules-alerts'),
        title: 'Alerts',
        children: [
          for (final c in rules.configs) _alertsPart(context, c, many),
        ],
      ),
      gap(),
      _block(
        context,
        section: 'numbers',
        anchor: const ValueKey('tour-pm-rules-numbers'),
        title: 'How each number is measured',
        children: [_numbers(context, rules)],
      ),
    ];
  }

  VoidCallback? _openAutomations(BuildContext context) {
    final open = OpenDestination.of(context);
    if (open == null || !_me.reaches('automations')) return null;
    return () => open('automations');
  }

  TextStyle? _mutedStyle(BuildContext context) => Theme.of(context)
      .textTheme
      .bodySmall
      ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant);

  Widget _muted(BuildContext context, String text, {Key? key}) =>
      Text(text, key: key, style: _mutedStyle(context));

  Widget _link(
    BuildContext context,
    String text,
    VoidCallback onTap, {
    Key? key,
  }) {
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      key: key,
      borderRadius: BorderRadius.circular(4),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Text(
          text,
          style: _mutedStyle(
            context,
          )?.copyWith(color: scheme.primary, fontWeight: FontWeight.w700),
        ),
      ),
    );
  }

  Widget _block(
    BuildContext context, {
    required String section,
    required Key anchor,
    required String title,
    required List<Widget> children,
    VoidCallback? change,
    String changeLabel = 'Change ›',
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final onChange =
        change ??
        (widget.onChange == null
            ? null
            : () => widget.onChange!(pmSettingsCardFor(section)));
    return KeyedSubtree(
      key: _keys[section],
      child: DecoratedBox(
        key: anchor,
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border.all(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      title,
                      key: ValueKey('pm-rules-title-$section'),
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (onChange != null)
                    _link(
                      context,
                      changeLabel,
                      onChange,
                      key: ValueKey('pm-rules-change-$section'),
                    ),
                ],
              ),
              const SizedBox(height: 8),
              ...children,
            ],
          ),
        ),
      ),
    );
  }

  /// A group's label above its part, when several groups are set up apart.
  Widget _group(
    BuildContext context,
    Map<String, dynamic> c,
    bool many,
    List<Widget> body,
  ) {
    if (!many) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: body,
      );
    }
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            pmBranchGroupLabel(c, _me),
            key: ValueKey('pm-rules-group-${pmBranchGroupLabel(c, _me)}'),
            style: theme.textTheme.labelLarge?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          ...body,
        ],
      ),
    );
  }

  Widget _warning(BuildContext context, int i, String? group, String text) {
    final tone = ToneColours.of(context, Tone.caution);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: DecoratedBox(
        key: ValueKey('pm-rules-warning-$i'),
        decoration: BoxDecoration(
          color: tone.fill,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.warning_amber_rounded, size: 18, color: tone.ink),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  group == null ? text : '$group: $text',
                  style: TextStyle(color: tone.ink),
                ),
              ),
              if (widget.onChange != null) ...[
                const SizedBox(width: 8),
                _link(
                  context,
                  'Fix in Settings ›',
                  () => widget.onChange!(pmSettingsCardFor('warnings')),
                  key: ValueKey('pm-rules-fix-$i'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  String _setAt(Object? at) {
    final label = pmSetAtLabel(at is String ? at : null);
    return label.isEmpty ? '' : 'Set at $label';
  }

  Widget _scopePart(BuildContext context, Map<String, dynamic> c, bool many) {
    final scope = PmRules._map(c['scope']);
    final count = scope['count'];
    return _group(context, c, many, [
      Text('${scope['sentence'] ?? ''}'),
      const SizedBox(height: 2),
      _muted(
        context,
        [
          if (count is int)
            '${formatCount(count)} ${count == 1 ? 'job counts' : 'jobs count'} now',
          if (_setAt(scope['set_at']).isNotEmpty) _setAt(scope['set_at']),
        ].join(' · '),
      ),
    ]);
  }

  Widget _stagesPart(BuildContext context, Map<String, dynamic> c, bool many) {
    final byKey = {for (final s in PmRules.stagesOf(c)) '${s['key']}': s};
    return _group(context, c, many, [
      for (final key in pmStageKeys)
        if (_matches([pmStageName(key), byKey[key]?['sentence']]))
          Padding(
            key: ValueKey('pm-rules-stage-$key'),
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: PmStageDot(key),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 170,
                  child: Text(
                    pmStageName(key),
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                Expanded(
                  child: Text(
                    byKey[key] == null
                        ? 'Not set up: no status puts a job here.'
                        : '${byKey[key]!['sentence']}',
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  byKey[key]?['count'] is int
                      ? formatCount(byKey[key]!['count'] as int)
                      : '—',
                  key: ValueKey('pm-rules-stage-$key-count'),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ],
            ),
          ),
      if (_setAt(c['stages_set_at']).isNotEmpty)
        _muted(context, _setAt(c['stages_set_at'])),
    ]);
  }

  Widget _automation(BuildContext context, Map<String, dynamic> a) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final on = a['active'] == true;
    final actions = [for (final x in (a['actions'] as List?) ?? const []) '$x'];
    final conditions = '${a['conditions'] ?? ''}';
    final onlyIf = conditions.startsWith('When ')
        ? conditions.substring(5)
        : conditions == 'Always.'
        ? 'always'
        : conditions;
    final runs = a['runs_7d'] is int ? a['runs_7d'] as int : 0;
    final skipped = a['skipped_7d'] is int ? a['skipped_7d'] as int : 0;
    final skip = a['last_skip_reason'];
    final open = pmOpenRule(context, _me, a);

    Widget line(String label, String text) => Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 70,
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w700,
                color: scheme.onSurfaceVariant,
              ),
            ),
          ),
          Expanded(child: Text(text)),
        ],
      ),
    );

    return Padding(
      key: ValueKey('pm-rules-automation-${a['id']}'),
      padding: const EdgeInsets.only(bottom: 12),
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border.all(color: scheme.outlineVariant),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                spacing: 8,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    '${a['name'] ?? 'A rule'}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  StatusPill(
                    on ? 'ON' : 'OFF',
                    key: ValueKey('pm-rules-onoff-${a['id']}'),
                    tone: on ? Tone.ok : Tone.neutral,
                  ),
                  Text(
                    'This week: ${formatCount(runs)} '
                    '${runs == 1 ? 'run' : 'runs'}, ${formatCount(skipped)} '
                    'skipped',
                    key: ValueKey('pm-rules-week-${a['id']}'),
                    style: _mutedStyle(context),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              line('When', pmTriggerSentence(a['trigger'])),
              line('Only if', onlyIf),
              line(
                'Then',
                actions.isEmpty
                    ? 'Nothing.'
                    : [
                        for (final (i, x) in actions.indexed) '${i + 1}. $x',
                      ].join('\n'),
              ),
              if (skip is String && skip.isNotEmpty) line('Last skip', skip),
              if (open != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: _link(
                    context,
                    'Open the rule ›',
                    open,
                    key: ValueKey('pm-rules-open-${a['id']}'),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _table(
    BuildContext context,
    List<String> head,
    List<List<String>> rows, {
    required List<int> flex,
    String keyPrefix = 'row',
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final headStyle = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: scheme.onSurfaceVariant,
      letterSpacing: 0.4,
    );
    Widget row(List<Widget> cells, {Key? key}) => Container(
      key: key,
      padding: const EdgeInsets.symmetric(vertical: 6),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final (i, c) in cells.indexed)
            Expanded(
              flex: flex[i],
              child: Padding(
                padding: const EdgeInsets.only(right: 10),
                child: c,
              ),
            ),
        ],
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        row([for (final h in head) Text(h.toUpperCase(), style: headStyle)]),
        for (final (i, r) in rows.indexed)
          row([
            Text(r.first, style: const TextStyle(fontWeight: FontWeight.w700)),
            for (final c in r.skip(1)) Text(c),
          ], key: ValueKey('pm-rules-$keyPrefix-$i')),
      ],
    );
  }

  Widget _dailyPart(BuildContext context, Map<String, dynamic> c, bool many) {
    final jobs = [
      for (final j in (c['server_jobs'] as List?) ?? const [])
        if (j is Map) ['${j['name']}', '${j['when']}', '${j['what']}'],
    ];
    return _group(context, c, many, [
      _table(
        context,
        const ['Check', 'Runs', 'What it does'],
        [
          for (final j in jobs)
            if (_matches(j)) j,
        ],
        flex: const [3, 3, 6],
        keyPrefix: 'daily',
      ),
    ]);
  }

  Widget _alertsPart(BuildContext context, Map<String, dynamic> c, bool many) {
    String value(String k) {
      final v = PmRules.alertOf(c, k)['value'];
      if (v == null || (v is String && v.isEmpty)) return 'Not set';
      if (v is List) return v.isEmpty ? 'None' : v.join(', ');
      return switch (k) {
        'warn_days' || 'stopped_days' => '$v days',
        'cost_alert_percent' => '$v%',
        _ => '$v',
      };
    }

    const labels = [
      ('warn_days', 'Paid but not started after'),
      ('warning_email', 'Warning email goes to'),
      ('stopped_days', 'Payments stopped after'),
      ('cost_alert_percent', 'Cost from gross is red above'),
      ('redo_words', 'Redo words'),
      ('dispute_words', 'Dispute words'),
      ('task_group', 'Assignments read the Task Board group'),
    ];
    return _group(context, c, many, [
      _table(
        context,
        const ['Alert', 'Value', 'Where it is set'],
        [
          for (final (k, label) in labels)
            if (_matches([label, value(k)]))
              [
                label,
                value(k),
                pmSetAtLabel(
                  PmRules.alertOf(c, k)['set_at'] is String
                      ? PmRules.alertOf(c, k)['set_at'] as String
                      : null,
                ),
              ],
        ],
        flex: const [5, 4, 4],
        keyPrefix: 'alert',
      ),
    ]);
  }

  Widget _numbers(BuildContext context, PmRules rules) {
    int? first(String k) {
      for (final c in rules.configs) {
        final v = PmRules.alertOf(c, k)['value'];
        if (v is int) return v;
      }
      return null;
    }

    final rows = [
      for (final m in pmMeasures(
        warnDays: first('warn_days'),
        stoppedDays: first('stopped_days'),
      ))
        if (_matches([m.number, m.rule])) [m.number, m.rule],
    ];
    return _table(
      context,
      const ['Number', 'How it is measured'],
      rows,
      flex: const [4, 8],
      keyPrefix: 'number',
    );
  }
}
