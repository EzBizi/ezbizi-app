import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../widgets/search_picker.dart';
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
];

/// The settings scope of the place being viewed: what the switcher is on.
Map<String, String> pmPlaceScope(Me me) => {
  'scope_type': me.scope.type,
  'scope_id': me.scope.id ?? me.organization.id,
};

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

/// Project Management › Settings (design 141), written as sentences, for the
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
  });

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

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/settings', _scope);
    if (!mounted) return;
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final narrow = MediaQuery.sizeOf(context).width < 600;
    if (_loadError != null) {
      return Center(
        child: Text(_loadError!, key: const ValueKey('pm-set-error')),
      );
    }
    if (_rows == null) return const Center(child: CircularProgressIndicator());

    return ListView(
      key: const ValueKey('pm-settings'),
      padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24, vertical: 16),
      children: [
        Text(
          'Set for ${_me.scope.label}',
          key: const ValueKey('pm-set-place'),
          style: theme.textTheme.titleMedium?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 16),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-stages'),
          title: 'Stages',
          note:
              'Each stage is the statuses that put a job there, plus a rule '
              'on schedule and money due.',
          keys: const [pmStagesKey],
          child: _stages(context, narrow: narrow),
        ),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-reasons'),
          title: 'Review reasons',
          note:
              'Why a job waits for review. Shown on a job, and on the '
              'Dashboard.',
          keys: const [pmReasonsKey],
          child: _reasons(context),
        ),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-words'),
          title: 'Redo and dispute words',
          note: 'Tags or job types, any case.',
          keys: const [pmRedoKey, pmDisputeKey],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _words(context, pmRedoKey, 'A job is a redo when it has'),
              const SizedBox(height: 12),
              _words(context, pmDisputeKey, 'A job is in dispute when it has'),
            ],
          ),
        ),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-warn'),
          title: 'Paid but not started',
          keys: const [pmWarnDaysKey, pmWarnEmailKey],
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              const Text('Warn when a paid job hasn’t started after'),
              _number(pmWarnDaysKey),
              const Text('days, daily, to'),
              SizedBox(
                width: narrow ? double.infinity : 260,
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
            ],
          ),
        ),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-stopped'),
          title: 'Stopped payments and cost',
          keys: const [pmStoppedKey, pmCostKey],
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              const Text('A partly paid job counts as stopped after'),
              _number(pmStoppedKey),
              const Text(
                'days without a payment · cost from gross is red above',
              ),
              _number(pmCostKey),
              const Text('%'),
            ],
          ),
        ),
        _section(
          context,
          anchor: const ValueKey('tour-pm-settings-watched'),
          title: 'Estimate clock',
          note:
              'Jobs in these statuses are checked once a day for how long '
              'their estimate has waited.',
          keys: const [watchedKey],
          child: _watched(context),
        ),
      ],
    );
  }

  Widget _section(
    BuildContext context, {
    required Key anchor,
    required String title,
    String? note,
    required List<String> keys,
    required Widget child,
  }) {
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

    return Padding(
      key: anchor,
      padding: const EdgeInsets.only(bottom: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          if (note != null) ...[
            const SizedBox(height: 2),
            Text(note, style: muted),
          ],
          const SizedBox(height: 8),
          child,
          if (from.isNotEmpty || own.isNotEmpty)
            Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (from.isNotEmpty)
                  Text(
                    'From ${from.join(', ')}',
                    key: ValueKey('pm-set-from-${keys.first}'),
                    style: muted,
                  ),
                if (own.isNotEmpty)
                  TextButton(
                    key: ValueKey('pm-set-inherit-${keys.first}'),
                    onPressed: own.any(_saving.contains)
                        ? null
                        : () => _inherit(own),
                    child: Text('Use $_parentName’s again'),
                  ),
              ],
            ),
          for (final r in reasons) Text(r, style: muted),
          for (final e in errors)
            Text(
              e,
              key: ValueKey('pm-set-error-${keys.first}'),
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
            ),
        ],
      ),
    );
  }

  Widget _number(String key) => SizedBox(
    width: 76,
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

  Widget _stages(BuildContext context, {required bool narrow}) {
    final rules = _rules;
    final editable = _mayEdit(pmStagesKey);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final key in pmStageKeys)
          _stageRow(context, key, rules, editable, narrow: narrow),
      ],
    );
  }

  Widget _stageRow(
    BuildContext context,
    String key,
    Map<String, PmStageRule> rules,
    bool editable, {
    required bool narrow,
  }) {
    final theme = Theme.of(context);
    final rule = rules[key] ?? PmStageRule(key, const [], null, null);
    final left = [
      for (final s in widget.statuses)
        if (!rule.statuses.contains(s.value)) s,
    ];

    void put(PmStageRule next) => _saveRules({...rules, key: next});

    Widget tri(String what, bool? value, void Function(bool?) set) => Wrap(
      spacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(what, style: theme.textTheme.bodySmall),
        for (final (v, label) in const [
          (null, 'Any'),
          (true, 'Yes'),
          (false, 'No'),
        ])
          ChoiceChip(
            key: ValueKey('pm-set-stage-$key-$what-${v ?? 'any'}'),
            showCheckmark: false,
            selected: value == v,
            label: Text(label),
            onSelected: editable && rule.statuses.isNotEmpty
                ? (_) {
                    if (value != v) set(v);
                  }
                : null,
          ),
      ],
    );

    return Padding(
      key: ValueKey('pm-set-stage-$key'),
      padding: const EdgeInsets.only(bottom: 10),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: narrow ? double.infinity : 170,
            child: Text(
              '${pmStageName(key)} =',
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
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
              width: 190,
              child: SearchPickerField<String>(
                key: ValueKey('pm-set-stage-$key-add'),
                value: null,
                hint: '+ Add a status',
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
          tri(
            'scheduled',
            rule.scheduled,
            (v) => put(PmStageRule(key, rule.statuses, v, rule.due)),
          ),
          tri(
            'due',
            rule.due,
            (v) => put(PmStageRule(key, rule.statuses, rule.scheduled, v)),
          ),
        ],
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

  Widget _words(BuildContext context, String key, String sentence) {
    final list = _strings(_value(key));
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('$sentence:', style: theme.textTheme.bodyMedium),
        const SizedBox(height: 6),
        _ChipList(
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
        ),
      ],
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
