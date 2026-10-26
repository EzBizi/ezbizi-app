import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../widgets/search_picker.dart';
import 'pm_frame.dart';
import 'pm_stages.dart';

/// The statuses Project Management watches for the estimate clock; never
/// saved empty.
const watchedKey = 'jobs.estimate_aging_statuses';

const pmStagesKey = 'jobs.pm_stages';
const pmReasonsKey = 'jobs.review_reasons';
const pmRedoKey = 'jobs.pm_redo_words';
const pmDisputeKey = 'jobs.pm_dispute_words';
const pmWarnDaysKey = 'jobs.pm_warn_days';
const pmWarnEmailKey = 'jobs.pm_warning_email';
const pmStoppedKey = 'jobs.pm_stopped_days';
const pmCostKey = 'jobs.pm_cost_alert_percent';

/// Which jobs Project Management includes (eb2f82f36): statuses (empty:
/// any) and partly paid only.
const pmScopeStatusesKey = 'jobs.pm_scope_statuses';
const pmPartlyPaidKey = 'jobs.pm_partly_paid_only';

const pmSettingKeys = [
  pmStagesKey,
  pmReasonsKey,
  pmRedoKey,
  pmDisputeKey,
  pmWarnDaysKey,
  pmWarnEmailKey,
  pmStoppedKey,
  pmCostKey,
  watchedKey,
  pmScopeStatusesKey,
  pmPartlyPaidKey,
];

/// The settings scope of the place being viewed: what the switcher is on.
Map<String, String> pmPlaceScope(Me me) => {
  'scope_type': me.scope.type,
  'scope_id': me.scope.id ?? me.organization.id,
};

/// The place being viewed as every Project Management tab names it: a
/// branch as "Company · Branch" (branch_options.dart), else its own name.
String pmPlaceLabel(Me me) {
  final s = me.scope;
  return s.type == 'franchise' && s.id != null
      ? branchLabelOf(me, s.id!, s.label)
      : s.label;
}

/// One stage's rule, as `jobs.pm_stages` holds it.
@immutable
class PmStageRule {
  const PmStageRule(this.key, this.statuses, this.scheduled, this.due);

  final String key;
  final List<String> statuses;
  final bool? scheduled;
  final bool? due;

  Map<String, dynamic> toJson() => {
    'key': key,
    'statuses': statuses,
    'scheduled': scheduled,
    'due': due,
  };

  static Map<String, PmStageRule> parse(Object? value) => {
    for (final r in value is List ? value : const [])
      if (r is Map && pmStageKeys.contains(r['key']))
        '${r['key']}': PmStageRule(
          '${r['key']}',
          [for (final s in (r['statuses'] as List?) ?? const []) '$s'],
          r['scheduled'] is bool ? r['scheduled'] as bool : null,
          r['due'] is bool ? r['due'] as bool : null,
        ),
  };
}

/// Project Management › Settings (design 141; cards per topic, design
/// 142), for the
/// place being viewed (Jose, 2026-10-22: Project Management resolves branch,
/// then company, then organization). Every write goes to that place's own
/// scope; a value inherited says where from, and a value set here can go
/// back to the parent's with "Use (the parent)'s again".
///
/// Stages are statuses plus scheduled yes/no/any and money due yes/no/any;
/// review reasons, redo and dispute words are chip lists; the paid-not-started
/// warning, stopped days and the cost alert are numbers in a sentence. The
/// watched statuses (the estimate clock) stay, never saved empty.
class ProjectManagementSettings extends StatefulWidget {
  const ProjectManagementSettings({
    super.key,
    required this.api,
    required this.session,
    this.statuses = const [],
    this.onSaved,
    this.focus,
    this.onRules,
  });

  /// A card to scroll to, by its key ([pmSettingsCards]), and a serial
  /// telling two asks apart: a Rules block's Change, or the scope bar's.
  final (String, int)? focus;

  /// "On the Rules tab ›": the Rules section a card's settings drive.
  final void Function(String section)? onRules;

  final ApiClient api;
  final Session session;

  /// The organization's job statuses, the stage chips' choices.
  final List<JobStatus> statuses;

  /// After a write, so the other tabs read again.
  final VoidCallback? onSaved;

  @override
  State<ProjectManagementSettings> createState() =>
      _ProjectManagementSettingsState();
}

class _ProjectManagementSettingsState extends State<ProjectManagementSettings> {
  Map<String, Map<String, dynamic>>? _rows;
  String? _loadError;
  final Set<String> _saving = {};
  final Map<String, String> _errors = {};

  Me get _me => widget.session.me!;
  Map<String, String> get _scope => pmPlaceScope(_me);

  final _cardKeys = {for (final c in pmSettingsCards) c: GlobalKey()};
  final _search = TextEditingController();
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ProjectManagementSettings old) {
    super.didUpdateWidget(old);
    if (widget.focus != old.focus) _scrollSoon();
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
      final c = _cardKeys[focus.$1]?.currentContext;
      if (c == null || !c.mounted) return;
      Scrollable.ensureVisible(c, duration: Duration.zero);
    });
  }

  Future<void> _load() async {
    final response = await widget.api.get('/settings', _scope);
    if (!mounted) return;
    _scrollSoon();
    setState(() {
      if (!response.ok) {
        _loadError = response.message;
        return;
      }
      final data = response.data;
      _rows = {
        if (data is List)
          for (final s in data.whereType<Map>())
            if (pmSettingKeys.contains(s['key']))
              '${s['key']}': s.cast<String, dynamic>(),
      };
    });
  }

  Object? _value(String key) => _rows?[key]?['value'];

  bool _mayEdit(String key) {
    final row = _rows?[key];
    return row != null && row['may_edit'] != false && !_saving.contains(key);
  }

  /// Set at this place rather than inherited, and this place has a parent.
  bool _own(String key) {
    final source = _rows?[key]?['source'];
    return _me.scope.type != 'organization' &&
        source is Map &&
        source['inherited'] == false;
  }

  String? _from(String key) {
    final source = _rows?[key]?['source'];
    return source is Map && source['inherited'] == true
        ? '${source['name'] ?? ''}'
        : null;
  }

  /// The name of the place this one inherits from.
  String get _parentName {
    final scope = _me.scope;
    if (scope.type == 'franchise') {
      for (final f in _me.franchises) {
        if (f.id == scope.id) {
          for (final c in _me.companies) {
            if (c.id == f.companyId) return c.name;
          }
        }
      }
    }
    if (scope.type == 'company') {
      for (final c in _me.companies) {
        if (c.id == scope.id && c.parentCompanyId != null) {
          for (final p in _me.companies) {
            if (p.id == c.parentCompanyId) return p.name;
          }
        }
      }
    }
    return _me.organization.name;
  }

  Future<void> _write(String key, Object? value) async {
    setState(() {
      _saving.add(key);
      _errors.remove(key);
    });
    final response = await widget.api.patch('/settings/$key', {
      'value': value,
    }, _scope);
    _settle(key, response);
  }

  Future<void> _inherit(List<String> keys) async {
    for (final key in keys) {
      if (!_own(key)) continue;
      setState(() {
        _saving.add(key);
        _errors.remove(key);
      });
      final response = await widget.api.delete('/settings/$key', _scope);
      _settle(key, response);
    }
  }

  void _settle(String key, ApiResponse response) {
    if (!mounted) return;
    setState(() {
      _saving.remove(key);
      if (!response.ok) {
        _errors[key] = response.message;
        return;
      }
      final data = response.data;
      if (data is Map && data['key'] == key) {
        _rows = {...?_rows, key: data.cast<String, dynamic>()};
      }
    });
    if (response.ok) widget.onSaved?.call();
  }

  List<String> _strings(Object? v) => [
    for (final s in v is List ? v : const [])
      if ('$s'.trim().isNotEmpty) '$s',
  ];

  /// Two cards a row from here; one column below (design 142).
  static const _twoColumnsFrom = 900.0;

  /// The stage table's columns from here; stage cards below (phone 4).
  static const _tableFrom = 760.0;

  static const _stageColumn = 210.0;
  static const _ruleColumn = 196.0;

  /// The label column of Alerts and the words card.
  static const _labelColumn = 230.0;

  /// Every number field the same narrow width, its unit after it.
  static const _numberWidth = 76.0;

  @override
  Widget build(BuildContext context) {
    return PmFrame(
      tab: 'settings',
      purpose:
          'Change the rules Project Management counts by. Each card links to '
          'its line on the Rules tab.',
      scopeText: [
        'Set for ${pmPlaceLabel(_me)}',
        _me.scope.type == 'franchise'
            ? 'this branch only'
            : 'its branches use these unless they set their own',
      ].join(' · '),
      search: TextField(
        key: const ValueKey('pm-set-search'),
        controller: _search,
        onChanged: (t) => setState(() => _q = t.trim().toLowerCase()),
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Find a setting',
        ),
      ),
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_loadError != null) {
      return Center(
        child: Text(_loadError!, key: const ValueKey('pm-set-error')),
      );
    }
    if (_rows == null) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < 600;
        final gutter = narrow ? 16.0 : 24.0;
        final two = constraints.maxWidth - 2 * gutter >= _twoColumnsFrom;

        final reasons = _card(
          context,
          card: 'reasons',
          anchor: const ValueKey('tour-pm-settings-reasons'),
          title: 'Review reasons',
          note:
              'Why a job waits for review. Shown on a job, and on the '
              'Overview.',
          keys: const [pmReasonsKey],
          child: _reasons(context),
        );
        final words = _card(
          context,
          card: 'words',
          anchor: const ValueKey('tour-pm-settings-words'),
          title: 'Redo and dispute words',
          note: 'Tags or job types, any case.',
          keys: const [pmRedoKey, pmDisputeKey],
          child: LayoutBuilder(
            builder: (context, c) {
              final side = c.maxWidth >= 460;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _labelled(
                    context,
                    side: side,
                    keys: const [pmRedoKey, pmDisputeKey],
                    own: pmRedoKey,
                    label: 'A job is a redo when it has',
                    child: _words(context, pmRedoKey),
                  ),
                  const SizedBox(height: 12),
                  _labelled(
                    context,
                    side: side,
                    keys: const [pmRedoKey, pmDisputeKey],
                    own: pmDisputeKey,
                    label: 'A job is in dispute when it has',
                    child: _words(context, pmDisputeKey),
                  ),
                ],
              );
            },
          ),
        );
        final alerts = _card(
          context,
          card: 'alerts',
          anchor: const ValueKey('tour-pm-settings-warn'),
          title: 'Alerts',
          note: 'Paid but not started, stopped payments and cost.',
          keys: const [pmWarnDaysKey, pmWarnEmailKey, pmStoppedKey, pmCostKey],
          child: _alerts(context),
        );
        final watched = _card(
          context,
          card: 'watched',
          anchor: const ValueKey('tour-pm-settings-watched'),
          title: 'Estimate clock',
          note:
              'Jobs in these statuses are checked once a day for how long '
              'their estimate has waited.',
          keys: const [watchedKey],
          child: _watched(context),
        );

        Widget pair(Widget? a, Widget? b) {
          if (a == null || b == null) {
            return a ?? b ?? const SizedBox.shrink();
          }
          return two
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: a),
                    const SizedBox(width: 16),
                    Expanded(child: b),
                  ],
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [a, const SizedBox(height: 16), b],
                );
        }

        final scope = _card(
          context,
          card: 'scope',
          anchor: const ValueKey('tour-pm-settings-scope'),
          title: 'Which jobs Project Management includes',
          note: 'Every tab counts and lists only these jobs.',
          keys: const [pmScopeStatusesKey, pmPartlyPaidKey],
          child: _included(context),
        );
        final stages = _card(
          context,
          card: 'stages',
          anchor: const ValueKey('tour-pm-settings-stages'),
          title: 'Stages',
          note: 'Which statuses put a job in each stage.',
          keys: const [pmStagesKey],
          child: _stages(context),
        );
        final shown = [
          ?scope,
          ?stages,
          if (reasons != null || words != null) pair(reasons, words),
          if (alerts != null || watched != null) pair(alerts, watched),
        ];

        // Not a lazy list: a card scrolled to must be built.
        return SingleChildScrollView(
          key: const ValueKey('pm-settings'),
          padding: EdgeInsets.fromLTRB(gutter, 0, gutter, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (shown.isEmpty)
                Text(
                  'No setting matches “$_q”.',
                  key: const ValueKey('pm-set-none'),
                ),
              for (final (i, w) in shown.indexed) ...[
                if (i > 0) const SizedBox(height: 16),
                w,
              ],
            ],
          ),
        );
      },
    );
  }

  /// A card per topic. Its header says once whether the card's settings are
  /// this place's own ("Own settings · Use (the parent)'s again") or come
  /// from the parent ("From (the parent)").
  Widget? _card(
    BuildContext context, {
    required String card,
    required Key anchor,
    required String title,
    String? note,
    required List<String> keys,
    required Widget child,
  }) {
    if (_q.isNotEmpty && !'$title ${note ?? ''}'.toLowerCase().contains(_q)) {
      return null;
    }
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final from = {for (final k in keys) ?_from(k)}..remove('');
    final own = keys.where(_own).toList();
    final errors = [for (final k in keys) ?_errors[k]];
    final reasons = {
      for (final k in keys)
        if (_rows?[k]?['may_edit'] == false &&
            _rows?[k]?['may_edit_reason'] is String)
          _rows![k]!['may_edit_reason'] as String,
    };

    final Widget? state = own.isNotEmpty
        ? Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'Own settings ·',
                key: ValueKey('pm-set-own-${keys.first}'),
                style: muted,
              ),
              TextButton(
                key: ValueKey('pm-set-inherit-${keys.first}'),
                style: TextButton.styleFrom(
                  visualDensity: VisualDensity.compact,
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                ),
                onPressed: own.any(_saving.contains)
                    ? null
                    : () => _inherit(own),
                child: Text('Use $_parentName’s again'),
              ),
            ],
          )
        : from.isNotEmpty
        ? Text(
            'From ${from.join(', ')}',
            key: ValueKey('pm-set-from-${keys.first}'),
            style: muted,
          )
        : null;

    final rules = widget.onRules;
    return KeyedSubtree(
      key: _cardKeys[card],
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
              Wrap(
                key: ValueKey('pm-set-head-${keys.first}'),
                alignment: WrapAlignment.spaceBetween,
                crossAxisAlignment: WrapCrossAlignment.center,
                spacing: 12,
                children: [
                  Text(
                    title,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  ?state,
                ],
              ),
              if (rules != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: InkWell(
                    key: ValueKey('pm-set-rules-$card'),
                    borderRadius: BorderRadius.circular(4),
                    onTap: () => rules(pmRulesSectionForCard(card)),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Text(
                        'On the Rules tab ›',
                        style: muted?.copyWith(
                          color: scheme.primary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ),
                ),
              if (note != null) ...[
                const SizedBox(height: 2),
                Text(note, style: muted),
              ],
              const SizedBox(height: 12),
              child,
              for (final r in reasons) ...[
                const SizedBox(height: 6),
                Text(r, style: muted),
              ],
              for (final e in errors) ...[
                const SizedBox(height: 6),
                Text(
                  e,
                  key: ValueKey('pm-set-error-${keys.first}'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.error,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// A label in the fixed left column and its field beside it; on a narrow
  /// card, the label above. In a card that is partly this place's own, a
  /// setting still inherited says where from under its label.
  Widget _labelled(
    BuildContext context, {
    required bool side,
    required List<String> keys,
    required String own,
    required String label,
    required Widget child,
  }) {
    final theme = Theme.of(context);
    final from = keys.any(_own) ? _from(own) : null;
    final head = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodyMedium),
        if (from != null && from.isNotEmpty)
          Text(
            'From $from',
            key: ValueKey('pm-set-from-$own'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
      ],
    );
    if (!side) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [head, const SizedBox(height: 6), child],
      );
    }
    return Row(
      children: [
        SizedBox(width: _labelColumn, child: head),
        const SizedBox(width: 12),
        Expanded(child: child),
      ],
    );
  }

  Widget _alerts(BuildContext context) {
    const keys = [pmWarnDaysKey, pmWarnEmailKey, pmStoppedKey, pmCostKey];
    return LayoutBuilder(
      builder: (context, c) {
        final side = c.maxWidth >= 460;
        Widget unit(String key, String text) => Row(
          children: [
            _number(key),
            const SizedBox(width: 8),
            Expanded(child: Text(text)),
          ],
        );
        Widget row(String key, String label, Widget child) => Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: _labelled(
            context,
            side: side,
            keys: keys,
            own: key,
            label: label,
            child: child,
          ),
        );
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            row(
              pmWarnDaysKey,
              'Warn when a paid job hasn’t started after',
              unit(pmWarnDaysKey, 'days, then daily'),
            ),
            row(
              pmWarnEmailKey,
              'Send the warning to',
              Align(
                alignment: Alignment.centerLeft,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 280),
                  child: _PmTextSetting(
                    key: const ValueKey('pm-set-$pmWarnEmailKey'),
                    value: '${_value(pmWarnEmailKey) ?? ''}',
                    enabled: _mayEdit(pmWarnEmailKey),
                    hint: 'Email address (empty sends nothing)',
                    keyboard: TextInputType.emailAddress,
                    validate: (t) =>
                        t.isEmpty || RegExp(r'^\S+@\S+\.\S+$').hasMatch(t)
                        ? null
                        : 'That is not an email address.',
                    onSave: (t) => _write(pmWarnEmailKey, t),
                  ),
                ),
              ),
            ),
            row(
              pmStoppedKey,
              'A partly paid job counts as stopped after',
              unit(pmStoppedKey, 'days without a payment'),
            ),
            row(
              pmCostKey,
              'Cost from gross is red above',
              unit(pmCostKey, '%'),
            ),
          ],
        );
      },
    );
  }

  Widget _number(String key) => SizedBox(
    width: _numberWidth,
    child: _PmTextSetting(
      key: ValueKey('pm-set-$key'),
      value: '${_value(key) ?? ''}',
      enabled: _mayEdit(key),
      keyboard: TextInputType.number,
      validate: (t) => int.tryParse(t) == null || int.parse(t) < 0
          ? 'A whole number.'
          : null,
      onSave: (t) => _write(key, int.parse(t)),
    ),
  );

  // ── stages ─────────────────────────────────────────────────────

  Map<String, PmStageRule> get _rules => PmStageRule.parse(_value(pmStagesKey));

  void _saveRules(Map<String, PmStageRule> rules) {
    _write(pmStagesKey, [
      for (final key in pmStageKeys)
        if (rules[key] != null && rules[key]!.statuses.isNotEmpty)
          rules[key]!.toJson(),
    ]);
  }

  String _statusLabel(String value) {
    for (final s in widget.statuses) {
      if (s.value == value) return s.label;
    }
    return jobStatusLabel(null, value);
  }

  /// An aligned table: Stage | Statuses | Scheduled | Money due, every
  /// control at the same x on every row. Narrow: a small card per stage.
  Widget _stages(BuildContext context) {
    final theme = Theme.of(context);
    final rules = _rules;
    final editable = _mayEdit(pmStagesKey);
    return LayoutBuilder(
      builder: (context, c) {
        if (c.maxWidth < _tableFrom) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final (i, key) in pmStageKeys.indexed) ...[
                if (i > 0) const SizedBox(height: 8),
                _stageCard(context, key, rules, editable),
              ],
            ],
          );
        }
        final head = theme.textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
          letterSpacing: 0.4,
        );
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              key: const ValueKey('pm-set-stage-head'),
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  SizedBox(
                    width: _stageColumn,
                    child: Text('STAGE', style: head),
                  ),
                  Expanded(child: Text('STATUSES', style: head)),
                  SizedBox(
                    width: _ruleColumn,
                    child: Text('SCHEDULED', style: head),
                  ),
                  SizedBox(
                    width: _ruleColumn,
                    child: Text('MONEY DUE', style: head),
                  ),
                ],
              ),
            ),
            for (final key in pmStageKeys)
              DecoratedBox(
                decoration: BoxDecoration(
                  border: Border(
                    top: BorderSide(color: theme.colorScheme.outlineVariant),
                  ),
                ),
                child: Padding(
                  key: ValueKey('pm-set-stage-$key'),
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(
                    children: [
                      SizedBox(
                        width: _stageColumn,
                        child: _stageName(context, key),
                      ),
                      Expanded(child: _statuses(context, key, rules, editable)),
                      SizedBox(
                        width: _ruleColumn,
                        child: _tri(key, rules, editable, 'scheduled'),
                      ),
                      SizedBox(
                        width: _ruleColumn,
                        child: _tri(key, rules, editable, 'due'),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  /// Phone 4: one stage as a small card.
  Widget _stageCard(
    BuildContext context,
    String key,
    Map<String, PmStageRule> rules,
    bool editable,
  ) {
    final theme = Theme.of(context);
    Widget rule(String label, String what) => Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          SizedBox(
            width: 84,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: _tri(key, rules, editable, what)),
        ],
      ),
    );
    return DecoratedBox(
      key: ValueKey('pm-set-stage-$key'),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _stageName(context, key),
            const SizedBox(height: 8),
            _statuses(context, key, rules, editable),
            rule('Scheduled', 'scheduled'),
            rule('Money due', 'due'),
          ],
        ),
      ),
    );
  }

  Widget _stageName(BuildContext context, String key) => Row(
    children: [
      PmStageDot(key, key: ValueKey('pm-set-stage-$key-dot')),
      const SizedBox(width: 8),
      Flexible(
        child: Text(
          pmStageName(key),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
    ],
  );

  Widget _statuses(
    BuildContext context,
    String key,
    Map<String, PmStageRule> rules,
    bool editable,
  ) {
    final theme = Theme.of(context);
    final rule = rules[key] ?? PmStageRule(key, const [], null, null);
    final left = [
      for (final s in widget.statuses)
        if (!rule.statuses.contains(s.value)) s,
    ];
    void put(PmStageRule next) => _saveRules({...rules, key: next});

    return Padding(
      padding: const EdgeInsets.only(right: 12),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (rule.statuses.isEmpty)
            Text(
              'not set up',
              key: ValueKey('pm-set-stage-$key-unmapped'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          for (final s in rule.statuses)
            InputChip(
              key: ValueKey('pm-set-stage-$key-status-$s'),
              label: Text(_statusLabel(s)),
              deleteButtonTooltipMessage: 'Remove',
              onDeleted: editable
                  ? () => put(
                      PmStageRule(
                        key,
                        [
                          for (final x in rule.statuses)
                            if (x != s) x,
                        ],
                        rule.scheduled,
                        rule.due,
                      ),
                    )
                  : null,
            ),
          if (editable && left.isNotEmpty)
            SizedBox(
              width: 170,
              child: SearchPickerField<String>(
                key: ValueKey('pm-set-stage-$key-add'),
                value: null,
                hint: '+ Add',
                isDense: true,
                options: [
                  for (final s in left)
                    SearchOption(value: s.value, label: s.label),
                ],
                onChanged: (v) {
                  if (v == null) return;
                  put(
                    PmStageRule(
                      key,
                      [...rule.statuses, v],
                      rule.scheduled,
                      rule.due,
                    ),
                  );
                },
              ),
            ),
        ],
      ),
    );
  }

  /// Any / Yes / No for scheduled or money due. Each segment's label keeps
  /// the key the chips had: `pm-set-stage-<stage>-<what>-<any|true|false>`.
  Widget _tri(
    String key,
    Map<String, PmStageRule> rules,
    bool editable,
    String what,
  ) {
    final rule = rules[key] ?? PmStageRule(key, const [], null, null);
    final value = what == 'scheduled' ? rule.scheduled : rule.due;
    String id(bool? v) => '${v ?? 'any'}';
    void set(bool? v) => _saveRules({
      ...rules,
      key: what == 'scheduled'
          ? PmStageRule(key, rule.statuses, v, rule.due)
          : PmStageRule(key, rule.statuses, rule.scheduled, v),
    });

    return Align(
      alignment: Alignment.centerLeft,
      child: SegmentedButton<String>(
        key: ValueKey('pm-set-stage-$key-$what'),
        showSelectedIcon: false,
        style: const ButtonStyle(visualDensity: VisualDensity.compact),
        segments: [
          for (final (v, label) in const [
            (null, 'Any'),
            (true, 'Yes'),
            (false, 'No'),
          ])
            ButtonSegment(
              value: id(v),
              label: Text(
                label,
                key: ValueKey('pm-set-stage-$key-$what-${id(v)}'),
              ),
            ),
        ],
        selected: {id(value)},
        onSelectionChanged: editable && rule.statuses.isNotEmpty
            ? (picked) {
                final v = switch (picked.first) {
                  'true' => true,
                  'false' => false,
                  _ => null,
                };
                if (v != value) set(v);
              }
            : null,
      ),
    );
  }

  // ── chip lists ─────────────────────────────────────────────────

  List<(String, String)> get _reasonList => [
    for (final r in (_value(pmReasonsKey) as List?) ?? const [])
      if (r is Map && r['value'] != null)
        ('${r['value']}', '${r['label'] ?? r['value']}'),
  ];

  static String _slug(String label) => label
      .trim()
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '_')
      .replaceAll(RegExp(r'^_+|_+$'), '');

  Widget _reasons(BuildContext context) {
    final list = _reasonList;
    final editable = _mayEdit(pmReasonsKey);
    List<Map<String, String>> json(List<(String, String)> l) => [
      for (final (v, label) in l) {'value': v, 'label': label},
    ];
    return _ChipList(
      keyName: pmReasonsKey,
      chips: [for (final (v, label) in list) (v, label)],
      editable: editable,
      addHint: '+ Add a reason',
      onRemove: (v) => _write(
        pmReasonsKey,
        json([
          for (final r in list)
            if (r.$1 != v) r,
        ]),
      ),
      onAdd: (label) {
        var value = _slug(label);
        if (value.isEmpty) return;
        final taken = {for (final r in list) r.$1};
        final base = value;
        for (var n = 2; taken.contains(value); n++) {
          value = '${base}_$n';
        }
        _write(pmReasonsKey, json([...list, (value, label.trim())]));
      },
    );
  }

  Widget _words(BuildContext context, String key) {
    final list = _strings(_value(key));
    return _ChipList(
      keyName: key,
      chips: [for (final w in list) (w, w)],
      editable: _mayEdit(key),
      addHint: '+ Add a word',
      onRemove: (w) => _write(key, [
        for (final x in list)
          if (x != w) x,
      ]),
      onAdd: (w) {
        final word = w.trim();
        if (word.isEmpty ||
            list.any((x) => x.toLowerCase() == word.toLowerCase())) {
          return;
        }
        _write(key, [...list, word]);
      },
    );
  }

  // ── scope ──────────────────────────────────────────────────────

  Widget _included(BuildContext context) {
    final theme = Theme.of(context);
    const keys = [pmScopeStatusesKey, pmPartlyPaidKey];
    final value = _strings(_value(pmScopeStatusesKey));
    final editable = _mayEdit(pmScopeStatusesKey);
    final left = [
      for (final s in widget.statuses)
        if (!value.contains(s.value)) s,
    ];
    return LayoutBuilder(
      builder: (context, c) {
        final side = c.maxWidth >= 460;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _labelled(
              context,
              side: side,
              keys: keys,
              own: pmScopeStatusesKey,
              label: 'Jobs in these statuses',
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  // Empty is any status, the server's reading.
                  if (value.isEmpty)
                    Text(
                      'Any status',
                      key: const ValueKey('pm-set-$pmScopeStatusesKey-any'),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  for (final v in value)
                    InputChip(
                      key: ValueKey('pm-set-$pmScopeStatusesKey-$v'),
                      label: Text(_statusLabel(v)),
                      deleteButtonTooltipMessage: 'Remove',
                      onDeleted: editable
                          ? () => _write(pmScopeStatusesKey, [
                              for (final x in value)
                                if (x != v) x,
                            ])
                          : null,
                    ),
                  if (editable && left.isNotEmpty)
                    SizedBox(
                      width: 170,
                      child: SearchPickerField<String>(
                        key: const ValueKey('pm-set-$pmScopeStatusesKey-add'),
                        value: null,
                        hint: '+ Add',
                        isDense: true,
                        options: [
                          for (final s in left)
                            SearchOption(value: s.value, label: s.label),
                        ],
                        onChanged: (v) {
                          if (v != null) {
                            _write(pmScopeStatusesKey, [...value, v]);
                          }
                        },
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            _labelled(
              context,
              side: side,
              keys: keys,
              own: pmPartlyPaidKey,
              label: 'Only partly paid jobs (a deposit paid, money still due)',
              child: Align(
                alignment: Alignment.centerLeft,
                child: Switch(
                  key: const ValueKey('pm-set-$pmPartlyPaidKey'),
                  value: _value(pmPartlyPaidKey) == true,
                  onChanged: _mayEdit(pmPartlyPaidKey)
                      ? (v) => _write(pmPartlyPaidKey, v)
                      : null,
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  // ── watched ────────────────────────────────────────────────────

  Widget _watched(BuildContext context) {
    final theme = Theme.of(context);
    final value = _strings(_value(watchedKey));
    final editable = _mayEdit(watchedKey);
    // An empty watched list empties every estimate clock (0.0.0474).
    final keepsLast = value.length == 1;
    final left = [
      for (final s in widget.statuses)
        if (!value.contains(s.value)) s,
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (value.isEmpty)
              Text(
                'None yet.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            for (final v in value)
              InputChip(
                key: ValueKey('pm-settings-$watchedKey-$v'),
                label: Text(_statusLabel(v)),
                deleteButtonTooltipMessage: 'Remove',
                onDeleted: editable && !keepsLast
                    ? () => _write(watchedKey, [
                        for (final x in value)
                          if (x != v) x,
                      ])
                    : null,
              ),
            if (editable && left.isNotEmpty)
              SizedBox(
                width: 220,
                child: SearchPickerField<String>(
                  key: const ValueKey('pm-settings-$watchedKey-add'),
                  value: null,
                  hint: '+ Add a status',
                  isDense: true,
                  options: [
                    for (final s in left)
                      SearchOption(value: s.value, label: s.label),
                  ],
                  onChanged: (v) {
                    if (v != null) _write(watchedKey, [...value, v]);
                  },
                ),
              ),
          ],
        ),
        if (keepsLast && editable) ...[
          const SizedBox(height: 6),
          Text(
            'Keep at least one status; add another before removing this one.',
            key: const ValueKey('pm-settings-$watchedKey-keep-one'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}

/// Removable chips and a field to add one, each change saved at once.
class _ChipList extends StatefulWidget {
  const _ChipList({
    required this.keyName,
    required this.chips,
    required this.editable,
    required this.addHint,
    required this.onRemove,
    required this.onAdd,
  });

  final String keyName;

  /// `(value, label)`.
  final List<(String, String)> chips;
  final bool editable;
  final String addHint;
  final ValueChanged<String> onRemove;
  final ValueChanged<String> onAdd;

  @override
  State<_ChipList> createState() => _ChipListState();
}

class _ChipListState extends State<_ChipList> {
  final _add = TextEditingController();

  @override
  void dispose() {
    _add.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (widget.chips.isEmpty)
          Text(
            'None yet.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        for (final (value, label) in widget.chips)
          InputChip(
            key: ValueKey('pm-set-${widget.keyName}-$value'),
            label: Text(label),
            deleteButtonTooltipMessage: 'Remove',
            onDeleted: widget.editable ? () => widget.onRemove(value) : null,
          ),
        if (widget.editable)
          SizedBox(
            width: 200,
            child: TextField(
              key: ValueKey('pm-set-${widget.keyName}-add'),
              controller: _add,
              decoration: InputDecoration(
                isDense: true,
                hintText: widget.addHint,
              ),
              onSubmitted: (t) {
                if (t.trim().isEmpty) return;
                widget.onAdd(t);
                _add.clear();
              },
            ),
          ),
      ],
    );
  }
}

/// A value typed into a sentence, saved on Enter or when the field is left,
/// only when it changed and reads.
class _PmTextSetting extends StatefulWidget {
  const _PmTextSetting({
    super.key,
    required this.value,
    required this.enabled,
    required this.onSave,
    this.validate,
    this.hint,
    this.keyboard,
  });

  final String value;
  final bool enabled;
  final ValueChanged<String> onSave;
  final String? Function(String)? validate;
  final String? hint;
  final TextInputType? keyboard;

  @override
  State<_PmTextSetting> createState() => _PmTextSettingState();
}

class _PmTextSettingState extends State<_PmTextSetting> {
  late final _text = TextEditingController(text: widget.value);
  final _focus = FocusNode();
  String? _error;

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus) _save();
    });
  }

  @override
  void didUpdateWidget(_PmTextSetting old) {
    super.didUpdateWidget(old);
    if (old.value != widget.value) {
      _sent = null;
      if (!_focus.hasFocus) _text.text = widget.value;
    }
  }

  @override
  void dispose() {
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  /// What was last sent, so Enter and then leaving the field save once.
  String? _sent;

  void _save() {
    final t = _text.text.trim();
    if (t == widget.value.trim() || t == _sent) return;
    final error = widget.validate?.call(t);
    setState(() => _error = error);
    if (error != null) return;
    _sent = t;
    widget.onSave(t);
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _text,
    focusNode: _focus,
    enabled: widget.enabled,
    keyboardType: widget.keyboard,
    onSubmitted: (_) => _save(),
    decoration: InputDecoration(
      isDense: true,
      hintText: widget.hint,
      errorText: _error,
    ),
  );
}

/// The Settings cards, by the key a Rules block's Change names.
const pmSettingsCards = [
  'scope',
  'stages',
  'reasons',
  'words',
  'alerts',
  'watched',
];

/// The Rules section a Settings card's settings drive.
String pmRulesSectionForCard(String card) => switch (card) {
  'stages' => 'stages',
  'reasons' => 'numbers',
  'words' || 'alerts' => 'alerts',
  'watched' => 'daily',
  _ => 'scope',
};
