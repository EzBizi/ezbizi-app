import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// The weekly and monthly report's goals, per branch (GET/PUT /reports/goals,
/// 2026-10-16). A value the PDF shows worse than its goal is red; an empty
/// goal colours nothing. "Every branch" is the organization view's own row,
/// which a branch's own goal overrides.
class ReportGoalsSettings extends StatefulWidget {
  const ReportGoalsSettings({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<ReportGoalsSettings> createState() => _ReportGoalsSettingsState();
}

class _ReportGoalsSettingsState extends State<ReportGoalsSettings> {
  /// Goals entered as a percent; the server keeps them as a share (0-1).
  static const _money = {'avg_sale'};

  List<Map<String, dynamic>> _keys = const [];
  List<Map<String, dynamic>> _goals = const [];
  final Map<String, TextEditingController> _fields = {};
  String? _branch; // null = every branch
  String? _error;
  String? _saved;
  bool _loading = true;
  bool _saving = false;

  Me get _me => widget.session.me!;
  bool get _organization => _me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    if (!_organization) _branch = _me.franchisesInView.firstOrNull?.id;
    _load();
  }

  @override
  void dispose() {
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final res = await widget.api.get('/reports/goals');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.message;
        return;
      }
      final data = (res.data as Map).cast<String, dynamic>();
      _keys = [
        for (final k in (data['keys'] as List? ?? const []))
          (k as Map).cast<String, dynamic>(),
      ];
      _goals = [
        for (final g in (data['goals'] as List? ?? const []))
          (g as Map).cast<String, dynamic>(),
      ];
      _fill();
    });
  }

  /// The chosen branch's own values into the boxes.
  void _fill() {
    for (final k in _keys) {
      final key = '${k['key']}';
      final goal = _goals
          .where((g) => g['franchise_id'] == _branch && g['key'] == key)
          .firstOrNull;
      final value = goal?['value'] as num?;
      (_fields[key] ??= TextEditingController()).text = value == null
          ? ''
          : _money.contains(key)
          ? value.toString()
          : _trim(value * 100);
    }
  }

  static String _trim(num v) =>
      v.toStringAsFixed(2).replaceFirst(RegExp(r'\.?0+$'), '');

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _saved = null;
    });
    final goals = [
      for (final k in _keys)
        () {
          final key = '${k['key']}';
          final n = num.tryParse(_fields[key]!.text.trim());
          return {
            'franchise_id': _branch,
            'key': key,
            'value': n == null ? null : (_money.contains(key) ? n : n / 100),
          };
        }(),
    ];
    final res = await widget.api.put('/reports/goals', {'goals': goals});
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (res.ok) {
        _error = null;
        _saved = 'Saved.';
        final data = (res.data as Map).cast<String, dynamic>();
        _goals = [
          for (final g in (data['goals'] as List? ?? const []))
            (g as Map).cast<String, dynamic>(),
        ];
        _fill();
      } else {
        _error = res.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    if (_loading) return const Center(child: CircularProgressIndicator());

    // Named with their company: forty branches called "Main" and
    // "Connecticut" told nobody which one (Jose, 2026-10-20).
    final company = {for (final c in _me.companiesInView) c.id: c.name};
    final places = [
      for (final f in _me.franchisesInView)
        (f.id, [?company[f.companyId], f.name].join(' · ')),
    ]..sort((a, b) => a.$2.compareTo(b.$2));
    final branches = [
      if (_organization) (null, 'Every branch'),
      ...places,
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Report goals', style: theme.textTheme.titleLarge),
        const SizedBox(height: 4),
        Text(
          'The weekly and monthly report shows a value in red when it is worse '
          'than its goal. Leave a goal empty to colour nothing. A branch\'s own '
          'goal is used over the one for every branch.',
          style: muted,
        ),
        const SizedBox(height: 12),
        // One searchable picker rather than a chip per branch.
        Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(
            width: 360,
            child: SearchPickerField<String?>(
              key: const ValueKey('report-goals-branch'),
              label: 'Goals for',
              isDense: true,
              value: _branch,
              options: [
                for (final (id, name) in branches)
                  SearchOption(value: id, label: name),
              ],
              onChanged: (id) => setState(() {
                _branch = id;
                _saved = null;
                _fill();
              }),
            ),
          ),
        ),
        const SizedBox(height: 12),
        for (final k in _keys)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: TextField(
              key: ValueKey('report-goal-${k['key']}'),
              controller: _fields['${k['key']}'],
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: InputDecoration(
                labelText: '${k['label']}',
                helperText: 'Red ${k['red_when']} this',
                prefixText: _money.contains('${k['key']}') ? '\$ ' : null,
                suffixText: _money.contains('${k['key']}') ? null : '%',
                border: const OutlineInputBorder(),
                isDense: true,
              ),
            ),
          ),
        if (_error != null) ErrorNote(_error!),
        if (_saved != null) Text(_saved!, style: muted),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            key: const Key('report-goals-save'),
            onPressed: _saving ? null : _save,
            child: const Text('Save goals'),
          ),
        ),
      ],
    );
  }
}
