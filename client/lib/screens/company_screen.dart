import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/empty_state.dart';
import '../widgets/list_search.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';

/// Company (design 175, Jose 2026-11-03, "as you recommend with everything"):
/// who answers to whom and owns which area, who owns each step of the work,
/// and each person's and branch's figures beside the goals that exist. It
/// reads HR's GET hr/ownership and Reports' own endpoints and stores nothing.
class CompanyScreen extends StatefulWidget {
  const CompanyScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<CompanyScreen> createState() => _CompanyScreenState();
}

const _stepLabels = {
  'lead': 'Lead',
  'booking': 'Booking',
  'job': 'Job',
  'payment': 'Payment',
  'review': 'Review',
};

class _CompanyScreenState extends State<CompanyScreen> {
  int _tab = 0;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _people = [];
  List<Map<String, dynamic>> _owners = [];
  List<String> _steps = _stepLabels.keys.toList();

  bool _may(String tab) => widget.session.me?.can('company.$tab.view') ?? false;
  bool get _canEdit => widget.session.me?.can('hr.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/hr/ownership');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!r.ok) {
        _error = r.message;
        return;
      }
      final d = (r.body['data'] as Map).cast<String, dynamic>();
      _people = [for (final p in d['people'] as List) (p as Map).cast()];
      _owners = [for (final o in d['owners'] as List) (o as Map).cast()];
      _steps = [for (final s in d['steps'] as List? ?? _steps) '$s'];
    });
  }

  String _nameOf(String? id) =>
      '${_people.firstWhere((p) => p['id'] == id, orElse: () => const {})['name'] ?? '—'}';

  List<SearchOption<String>> get _personOptions => [
    for (final p in _people)
      SearchOption(
        value: '${p['id']}',
        label: '${p['name']}',
        detail: (p['role'] as Map?)?['name'] as String?,
      ),
  ];

  /// Sets or clears one owner (PUT hr/ownership); organization-wide only.
  // ponytail: owners are org-wide; per-branch owners when a branch asks for one.
  Future<String?> _setOwner(String kind, String key, String? userId) async {
    final r = await widget.api.put('/hr/ownership', {
      'kind': kind,
      'key': key,
      'user_id': userId,
    });
    if (!r.ok) return r.message;
    await _load();
    return null;
  }

  Future<void> _pickOwner(String kind, String key, String title) async {
    final current = _owners.firstWhere(
      (o) => o['kind'] == kind && o['key'] == key && o['scope_type'] == null,
      orElse: () => const {},
    );
    await showSidePanel<void>(
      context,
      builder: (_) => _OwnerPanel(
        title: title,
        options: _personOptions,
        initial: (current['user'] as Map?)?['id'] as String?,
        askKey: kind == 'area' && key.isEmpty,
        save: (k, user) => _setOwner(kind, k.isEmpty ? key : k, user),
      ),
    );
  }

  Future<void> _pickManager(Map<String, dynamic> person) async {
    await showSidePanel<void>(
      context,
      builder: (_) => _OwnerPanel(
        title: '${person['name']} reports to',
        options: [
          for (final o in _personOptions)
            if (o.value != person['id']) o,
        ],
        initial: person['reports_to_user_id'] as String?,
        save: (_, user) async {
          final r = await widget.api.patch('/hr/people/${person['id']}', {
            'reports_to_user_id': user,
          });
          if (!r.ok) return r.message;
          await _load();
          return null;
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final tabs = [
      if (_may('ownership'))
        ('Ownership', Icons.account_tree_outlined, _ownership),
      if (_may('processes')) ('Processes', Icons.route_outlined, _processes),
      if (_may('kpis'))
        (
          'KPIs',
          Icons.speed_outlined,
          (BuildContext _) => CompanyKpis(
            api: widget.api,
            companyNames: [
              for (final c in widget.session.me?.companies ?? const []) c.name,
            ],
          ),
        ),
    ];
    if (tabs.isEmpty) {
      return const Center(
        child: Text('No Company tabs have been granted to your role.'),
      );
    }
    final selected = _tab < tabs.length ? _tab : 0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-company-tabs'),
            labels: [for (final t in tabs) (t.$1, t.$2)],
            selected: selected,
            onSelected: (i) => setState(() => _tab = i),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _error != null && tabs[selected].$1 != 'KPIs'
              ? Center(child: Text(_error!))
              : Builder(builder: tabs[selected].$3),
        ),
      ],
    );
  }

  String _query = '';

  Widget _ownership(BuildContext context) {
    final q = _query.toLowerCase();
    final match = [
      for (final p in _people)
        if (q.isEmpty ||
            '${p['name']} ${(p['role'] as Map?)?['name'] ?? ''}'
                .toLowerCase()
                .contains(q))
          p,
    ];
    final ids = {for (final p in _people) p['id']};
    // The tree: people with no manager (or one who left) at the top, their
    // reports under them. While searching it is a flat list of the matches.
    final children = <String?, List<Map<String, dynamic>>>{};
    for (final p in _people) {
      final m = p['reports_to_user_id'];
      children
          .putIfAbsent(ids.contains(m) ? m as String? : null, () => [])
          .add(p);
    }
    final rows = <(Map<String, dynamic>, int)>[];
    void walk(String? id, int depth, Set<String> seen) {
      for (final p in children[id] ?? const <Map<String, dynamic>>[]) {
        if (!seen.add('${p['id']}')) continue;
        rows.add((p, depth));
        walk('${p['id']}', depth + 1, seen);
      }
    }

    if (q.isEmpty) {
      walk(null, 0, {});
    } else {
      rows.addAll([for (final p in match) (p, 0)]);
    }
    final areas = [
      for (final o in _owners)
        if (o['kind'] == 'area') o,
    ];
    final noManager = _people
        .where((p) => p['reports_to_user_id'] == null)
        .length;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ListSearch(
              key: const ValueKey('company-ownership-search'),
              hint: 'Find a person or role',
              onChanged: (v) => setState(() => _query = v),
            ),
            Text(
              '${_people.length} people · $noManager report to nobody',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (rows.isEmpty)
          const EmptyState(
            icon: Icons.account_tree_outlined,
            title: 'Nobody matches',
            message: 'Try a different name or role.',
          ),
        for (final (p, depth) in rows)
          ListTile(
            key: ValueKey('company-person-${p['id']}'),
            contentPadding: EdgeInsets.only(left: 8 + depth * 24.0, right: 8),
            dense: true,
            leading: depth > 0
                ? const Icon(Icons.subdirectory_arrow_right, size: 18)
                : null,
            title: Text('${p['name']}'),
            subtitle: Text(
              [
                (p['role'] as Map?)?['name'] ?? 'No role',
                for (final a in p['assigned'] as List? ?? const [])
                  [
                    (a as Map)['company'],
                    a['branch'],
                  ].whereType<String>().join(' · '),
              ].where((s) => '$s'.isNotEmpty).join('  ·  '),
            ),
            trailing: _canEdit
                ? TextButton(
                    onPressed: () => _pickManager(p),
                    child: Text(
                      p['reports_to_user_id'] == null
                          ? 'Set who they report to'
                          : 'Reports to ${_nameOf(p['reports_to_user_id'] as String?)}',
                    ),
                  )
                : null,
          ),
        const SizedBox(height: 24),
        Row(
          children: [
            Text('Area owners', style: Theme.of(context).textTheme.titleSmall),
            const Spacer(),
            if (_canEdit)
              TextButton.icon(
                onPressed: () => _pickOwner('area', '', 'New area owner'),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Area owner'),
              ),
          ],
        ),
        if (areas.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text('No area has an owner yet.'),
          ),
        for (final o in areas)
          ListTile(
            dense: true,
            title: Text('${o['key']}'),
            subtitle: Text('${(o['user'] as Map?)?['name'] ?? '—'}'),
            onTap: _canEdit
                ? () => _pickOwner('area', '${o['key']}', '${o['key']} owner')
                : null,
          ),
      ],
    );
  }

  Widget _processes(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 600;
    final cards = [
      for (final s in _steps)
        () {
          final owner = _owners.firstWhere(
            (o) =>
                o['kind'] == 'process' &&
                o['key'] == s &&
                o['scope_type'] == null,
            orElse: () => const {},
          );
          final name = (owner['user'] as Map?)?['name'] as String?;
          return Card(
            key: ValueKey('company-step-$s'),
            child: InkWell(
              onTap: _canEdit
                  ? () =>
                        _pickOwner('process', s, '${_stepLabels[s] ?? s} owner')
                  : null,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      _stepLabels[s] ?? s,
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    const SizedBox(height: 6),
                    name == null
                        ? const StatusPill('No owner', tone: Tone.caution)
                        : Text(name),
                  ],
                ),
              ),
            ),
          );
        }(),
    ];
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Who owns each step of the work',
          key: const ValueKey('company-steps'),
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: 12),
        if (wide)
          Row(
            children: [
              for (var i = 0; i < cards.length; i++) ...[
                Expanded(child: cards[i]),
                if (i < cards.length - 1)
                  const Icon(Icons.arrow_forward, size: 18),
              ],
            ],
          )
        else
          ...cards,
      ],
    );
  }
}

/// Picks one person (and, for a new area, names it), saving in place; a
/// refusal — a circle in who reports to whom, say — shows under the field.
class _OwnerPanel extends StatefulWidget {
  const _OwnerPanel({
    required this.title,
    required this.options,
    required this.initial,
    required this.save,
    this.askKey = false,
  });

  final String title;
  final List<SearchOption<String>> options;
  final String? initial;
  final bool askKey;
  final Future<String?> Function(String key, String? userId) save;

  @override
  State<_OwnerPanel> createState() => _OwnerPanelState();
}

class _OwnerPanelState extends State<_OwnerPanel> {
  late String? _user = widget.initial;
  final _key = TextEditingController();
  String? _error;
  bool _saving = false;

  Future<void> _save(String? user) async {
    if (widget.askKey && _key.text.trim().isEmpty) {
      setState(
        () => _error = 'Name the area first, for example Office or Field.',
      );
      return;
    }
    setState(() => _saving = true);
    final error = await widget.save(_key.text.trim(), user);
    if (!mounted) return;
    if (error == null) {
      Navigator.of(context).pop();
    } else {
      setState(() {
        _saving = false;
        _error = error;
      });
    }
  }

  @override
  void dispose() {
    _key.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.title,
      actions: [
        if (widget.initial != null)
          TextButton(
            onPressed: _saving ? null : () => _save(null),
            child: const Text('Clear'),
          ),
        FilledButton(
          key: const Key('company-owner-save'),
          onPressed: _saving || _user == null ? null : () => _save(_user),
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.askKey) ...[
            TextField(
              key: const Key('company-area-key'),
              controller: _key,
              maxLength: 60,
              decoration: const InputDecoration(
                labelText: 'Area',
                hintText: 'Office, Field, Marketing…',
              ),
            ),
            const SizedBox(height: 12),
          ],
          SearchPickerField<String>(
            key: const Key('company-owner-person'),
            label: 'Person',
            value: _user,
            options: widget.options,
            onChanged: (v) => setState(() {
              _user = v;
              _error = null;
            }),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}

/// Each person's phone figures for the last 30 days, and every goal the
/// branches have. Nobody has a per-person goal (Reports, 2026-11-03), so the
/// person rows say so rather than inventing one.
class CompanyKpis extends StatefulWidget {
  const CompanyKpis({
    super.key,
    required this.api,
    this.now,
    this.companyNames = const [],
  });

  final ApiClient api;

  /// The companies' own names. A company's main line answers calls as if it
  /// were a person ("A1 Chimney"); a name matching one of these is that line,
  /// drawn as the Total at the bottom rather than as somebody on the team.
  final List<String> companyNames;
  final DateTime Function()? now;

  @override
  State<CompanyKpis> createState() => _CompanyKpisState();
}

class _CompanyKpisState extends State<CompanyKpis> {
  bool _loading = true;
  Map<String, dynamic>? _calls;
  Map<String, dynamic>? _goals;
  String _query = '';

  String _day(DateTime d) =>
      '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final today = (widget.now ?? DateTime.now)();
    final results = await Future.wait([
      widget.api.get('/reports/experimental/calls', {
        'from': _day(today.subtract(const Duration(days: 29))),
        'to': _day(today),
      }),
      widget.api.get('/reports/goals'),
    ]);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (results[0].ok) _calls = results[0].body;
      if (results[1].ok) _goals = (results[1].body['data'] as Map?)?.cast();
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    final calls = (_calls?['calls'] as List? ?? const []).whereType<Map>();
    final q = _query.toLowerCase();
    String bare(Object? v) =>
        '${v ?? ''}'.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');
    final companies = {for (final n in widget.companyNames) bare(n)}
      ..remove('');
    // Calls nobody answered come back as a person with no name; they are not
    // somebody to show (coordinator, 2026-11-04).
    final everyone = [
      for (final p in (_calls?['people'] as List? ?? const []).whereType<Map>())
        if (bare(p['person']).isNotEmpty) p,
    ];
    final lines = [
      for (final p in everyone)
        if (companies.contains(bare(p['person']))) p,
    ];
    final people = [
      for (final p in everyone)
        if (!lines.contains(p) &&
            (q.isEmpty || '${p['person']}'.toLowerCase().contains(q)))
          p,
    ];
    final labels = {
      for (final k in (_goals?['keys'] as List? ?? const []).whereType<Map>())
        '${k['key']}': '${k['label']}',
    };
    final goals = (_goals?['goals'] as List? ?? const [])
        .whereType<Map>()
        .toList();
    final muted = Theme.of(context).textTheme.bodySmall;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        ListSearch(
          key: const ValueKey('company-kpi-search'),
          hint: 'Find a person',
          onChanged: (v) => setState(() => _query = v),
        ),
        const SizedBox(height: 12),
        Text(
          'People · last 30 days',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        if (_calls == null)
          const Padding(
            padding: EdgeInsets.all(8),
            child: Text('Call figures could not be loaded.'),
          ),
        for (final p in [...people, ...lines])
          () {
            final line = lines.contains(p);
            final answered = (p['answered'] as num?)?.toInt() ?? 0;
            final booked = calls
                .where(
                  (c) => c['answered_by'] == p['person'] && c['booked'] == true,
                )
                .length;
            final rate = answered == 0
                ? '—'
                : '${(booked / answered * 100).round()}%';
            return ListTile(
              key: ValueKey('company-kpi-${p['person']}'),
              dense: true,
              title: Text(line ? 'Total · ${p['person']}' : '${p['person']}'),
              subtitle: Text(
                '$answered answered · $booked booked · booking rate $rate',
              ),
              trailing: line ? null : Text('No goal', style: muted),
            );
          }(),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(
            'Technician figures come with the tech report.',
            style: muted,
          ),
        ),
        const SizedBox(height: 16),
        Text('Branch goals', style: Theme.of(context).textTheme.titleSmall),
        if (goals.isEmpty)
          const Padding(
            padding: EdgeInsets.all(8),
            child: Text('No goals are set. Reports › Settings › Goals.'),
          ),
        for (final g in goals)
          ListTile(
            dense: true,
            title: Text(labels['${g['key']}'] ?? '${g['key']}'),
            subtitle: Text(
              g['franchise_id'] == null ? 'Every branch' : 'One branch',
            ),
            trailing: Text('Goal ${g['value']}'),
          ),
      ],
    );
  }
}
