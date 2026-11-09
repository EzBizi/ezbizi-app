import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/list_search.dart';
import '../widgets/list_table.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import 'jobs/job_detail_panel.dart';

/// Project Management (Jose, 2026-11-06): six sub-tabs of jobs, from
/// unscheduled to complete, each with a Franchise filter (All by default),
/// and a Settings sub-tab. Nothing about the table is fixed here: which
/// statuses make a sub-tab, the columns (name, order, shown, drawn as a
/// label) and the labels' colours are the company's settings, which the
/// server sends with the rows. Money only with Financials: the server leaves
/// the keys out, and so do the columns.
class ProjectBoardScreen extends StatefulWidget {
  const ProjectBoardScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<ProjectBoardScreen> createState() => _ProjectBoardScreenState();
}

class _ProjectBoardScreenState extends State<ProjectBoardScreen> {
  bool _loading = true;
  bool _loaded = false;
  bool _fetching = false;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  List<Map<String, dynamic>> _franchises = const [];
  List<Map<String, dynamic>> _columns = const [];
  Map<String, dynamic> _labels = const {};
  Map<String, String> _scope = const {};
  Map<String, dynamic> _counts = const {};
  String _tab = _tabs.first.$1;
  String? _franchise;
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;
  bool get _canSet => widget.session.me?.can('organizations.edit') ?? false;
  List<(String, String, IconData)> get _shown => [
    ..._tabs,
    if (_canSet) ('settings', 'Settings', Icons.tune),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// The search stays as it is across sub-tabs and franchises.
  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  Future<void> _load({int page = 1}) async {
    setState(() {
      _loading = true;
      _fetching = true;
    });
    final tab = _tab == 'settings' ? _tabs.first.$1 : _tab;
    final franchise = _franchise;
    final q = _q;
    final response = await widget.api.get('/project-board/jobs', {
      'tab': tab,
      'page': '$page',
      if (franchise != null && franchise.startsWith('company:'))
        'company_id': franchise.substring(8)
      else
        'franchise_id': ?franchise,
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted || franchise != _franchise || q != _q) return;
    setState(() {
      _loading = false;
      _fetching = false;
      _loaded = true;
      _error = response.ok ? null : response.message;
      if (!response.ok) return;
      final rows = _maps(response.body['data']);
      _rows = page == 1 ? rows : [..._rows, ...rows];
      final meta = (response.body['meta'] as Map?) ?? const {};
      _total = meta['total'] as int? ?? _rows.length;
      _lastPage = meta['last_page'] as int? ?? 1;
      _counts = (meta['counts'] as Map?)?.cast<String, dynamic>() ?? _counts;
      _franchises = _maps(meta['places']);
      _columns = _maps(meta['columns']);
      _labels = (meta['labels'] as Map?)?.cast<String, dynamic>() ?? const {};
      _scope = ((meta['settings_scope'] as Map?) ?? const {}).map(
        (k, v) => MapEntry('$k', '$v'),
      );
      _page = page;
    });
  }

  void _switch(int i) {
    setState(() {
      _tab = _shown[i].$1;
      if (_tab != 'settings') _rows = const [];
    });
    if (_tab != 'settings') _load();
  }

  void _pick(String? franchise) {
    setState(() {
      _franchise = franchise;
      _rows = const [];
    });
    _load();
  }

  void _open(Map<String, dynamic> row) => showSidePanel<bool>(
    context,
    builder: (_) => JobDetailPanel(
      api: widget.api,
      session: widget.session,
      id: '${row['id']}',
    ),
  ).then((_) => _load());

  String _date(Object? ymd) {
    final at = ymd is String ? DateTime.tryParse(ymd) : null;
    return at == null
        ? '—'
        : (widget.session.me?.display ?? DisplayFormats.fallback).formatDate(
            at,
          );
  }

  Widget _cell(Map<String, dynamic> row, Map<String, dynamic> column) {
    final key = '${column['key']}';
    final value = row[key];
    if (_moneyKeys.contains(key)) {
      return Text(
        value is int
            ? formatMoney(value, currency: '${row['currency'] ?? 'USD'}')
            : '—',
      );
    }
    if (value is List) {
      // Tags: one chip each, coloured like any label column.
      if (value.isEmpty) return const Text('—');
      final named = (_labels[key] as Map?) ?? const {};
      return Wrap(
        spacing: 4,
        runSpacing: 4,
        children: [
          for (final t in value)
            StatusPill(
              '$t',
              tone:
                  Tone.values
                      .where(
                        (tone) =>
                            tone.name ==
                            '${named.entries.where((e) => '${e.key}'.toLowerCase() == '$t'.toLowerCase()).firstOrNull?.value}',
                      )
                      .firstOrNull ??
                  Tone.neutral,
            ),
        ],
      );
    }
    final text = key == 'created' || key == 'scheduled'
        ? _date(value)
        : (value == null || '$value'.isEmpty ? '—' : '$value');
    if (column['as_label'] == true && text != '—') {
      final named = ((_labels[key] as Map?) ?? const {}).entries
          .where((e) => '${e.key}'.toLowerCase() == text.toLowerCase())
          .firstOrNull
          ?.value;
      final tone =
          Tone.values.where((t) => t.name == '$named').firstOrNull ??
          (key == 'status' ? toneForJobStatus(text) : Tone.neutral);
      return Align(
        alignment: Alignment.centerLeft,
        child: StatusPill(text, tone: tone),
      );
    }
    return Text(
      text,
      style: key == 'customer'
          ? Theme.of(
              context,
            ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700)
          : null,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    final shown = _shown;
    final selected = shown.indexWhere((t) => t.$1 == _tab);
    final columns = [
      for (final c in _columns)
        if (c['visible'] != false && (_money || !_moneyKeys.contains(c['key'])))
          c,
    ];

    // The sub-tabs stay drawn while a sub-tab loads or fails.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: TabBarLike(
            key: const Key('project-board-tabs'),
            labels: [
              for (final (key, name, icon) in shown)
                (_counts[key] is int ? '$name · ${_counts[key]}' : name, icon),
            ],
            selected: selected,
            onSelected: _switch,
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading && !_loaded,
            error: _error,
            onRetry: _load,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
              children: [
                if (_tab == 'settings')
                  _BoardSettings(
                    api: widget.api,
                    scope: _scope,
                    columns: _columns,
                    labels: _labels,
                    onSaved: _load,
                  )
                else ...[
                  Wrap(
                    spacing: 16,
                    runSpacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        '${shown[selected].$2} · $_total jobs',
                        style: theme.textTheme.titleMedium,
                      ),
                      DropdownMenu<String>(
                        enableFilter: true,
                        requestFocusOnTap: true,
                        key: const Key('project-board-franchise'),
                        label: const Text('Franchise'),
                        initialSelection: _franchise ?? '',
                        onSelected: (v) =>
                            _pick(v == null || v.isEmpty ? null : v),
                        dropdownMenuEntries: [
                          const DropdownMenuEntry(value: '', label: 'All'),
                          // Hierarchy order from the server, indented by level.
                          for (final f in _franchises)
                            DropdownMenuEntry(
                              value: '${f['value']}',
                              label: '${f['label']}',
                              labelWidget: Padding(
                                padding: EdgeInsets.only(
                                  left: 16.0 * ((f['depth'] as int?) ?? 0),
                                ),
                                child: Text('${f['label']}'),
                              ),
                            ),
                        ],
                      ),
                      ListSearch(
                        key: const Key('project-board-search'),
                        controller: _search,
                        hint: 'Customer, job number or address',
                        onChanged: _searched,
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  if (rows.isEmpty && _fetching)
                    const LinearProgressIndicator()
                  else if (rows.isEmpty)
                    Text(
                      _q.isNotEmpty
                          ? 'No job matches "$_q".'
                          : 'No jobs in ${shown[selected].$2}.',
                    )
                  else
                    ListTable(
                      rowKey: (i) =>
                          ValueKey('project-board-job:${rows[i]['id']}'),
                      onTap: (i) => _open(rows[i]),
                      columns: [
                        for (final c in columns)
                          ListColumn(
                            '${c['label'] ?? c['key']}',
                            flex: c['key'] == 'required' || c['key'] == 'tags'
                                ? 4
                                : 2,
                            end: _moneyKeys.contains(c['key']),
                          ),
                      ],
                      rows: [
                        for (final row in rows)
                          [for (final c in columns) _cell(row, c)],
                      ],
                    ),
                  if (_page < _lastPage)
                    Center(
                      child: TextButton(
                        key: const Key('project-board-more'),
                        onPressed: _fetching
                            ? null
                            : () => _load(page: _page + 1),
                        child: Text(
                          _fetching
                              ? 'Loading…'
                              : 'Load more (${_total - rows.length} left)',
                        ),
                      ),
                    ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// The Settings sub-tab: the company's columns, label colours, which job
/// statuses make each sub-tab, and the tags that are a part status. Saved
/// as the settings they are (project_board.*), so MCP and Settings read the
/// same values.
class _BoardSettings extends StatefulWidget {
  const _BoardSettings({
    required this.api,
    required this.scope,
    required this.columns,
    required this.labels,
    required this.onSaved,
  });

  final ApiClient api;
  final Map<String, String> scope;
  final List<Map<String, dynamic>> columns;
  final Map<String, dynamic> labels;
  final VoidCallback onSaved;

  @override
  State<_BoardSettings> createState() => _BoardSettingsState();
}

class _BoardSettingsState extends State<_BoardSettings> {
  late final List<Map<String, dynamic>> _columns = [
    for (final c in widget.columns) {...c},
  ];
  late final Map<String, List<(TextEditingController, String)>> _labels = {
    for (final MapEntry(:key, :value) in widget.labels.entries)
      key: [
        for (final MapEntry(key: v, value: tone) in (value as Map).entries)
          (TextEditingController(text: '$v'), '$tone'),
      ],
  };
  final Map<String, TextEditingController> _statuses = {};
  final Map<String, bool?> _scheduled = {};
  final _partTags = TextEditingController();
  bool _ready = false;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _read();
  }

  @override
  void dispose() {
    for (final c in _statuses.values) {
      c.dispose();
    }
    for (final list in _labels.values) {
      for (final (c, _) in list) {
        c.dispose();
      }
    }
    _partTags.dispose();
    super.dispose();
  }

  Future<void> _read() async {
    final r = await widget.api.get('/settings', widget.scope);
    if (!mounted) return;
    final byKey = {
      for (final s in (r.ok && r.data is List ? r.data as List : const []))
        '${(s as Map)['key']}': s['value'],
    };
    final tabs = (byKey['project_board.tabs'] as Map?) ?? const {};
    setState(() {
      _error = r.ok ? null : r.message;
      for (final (key, _, _) in _tabs) {
        final rule = (tabs[key] as Map?) ?? const {};
        _statuses[key] = TextEditingController(
          text: ((rule['statuses'] as List?) ?? const []).join(', '),
        );
        _scheduled[key] = rule['scheduled'] as bool?;
      }
      _partTags.text = ((byKey['project_board.part_tags'] as List?) ?? const [])
          .join(', ');
      _ready = true;
    });
  }

  List<String> _split(String text) => [
    for (final s in text.split(','))
      if (s.trim().isNotEmpty) s.trim(),
  ];

  Future<void> _save() async {
    setState(() => _saving = true);
    final values = <String, Object>{
      'project_board.columns': _columns,
      'project_board.labels': {
        for (final MapEntry(:key, :value) in _labels.entries)
          key: {
            for (final (c, tone) in value)
              if (c.text.trim().isNotEmpty) c.text.trim(): tone,
          },
      },
      'project_board.tabs': {
        for (final (key, _, _) in _tabs)
          key: {
            'statuses': _split(_statuses[key]!.text),
            if (_scheduled[key] != null) 'scheduled': _scheduled[key]!,
          },
      },
      'project_board.part_tags': _split(_partTags.text),
    };
    String? failed;
    for (final MapEntry(:key, :value) in values.entries) {
      final r = await widget.api.patch('/settings/$key', {
        'value': value,
      }, widget.scope);
      if (!r.ok) failed ??= r.message;
    }
    if (!mounted) return;
    setState(() => _saving = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(failed ?? 'Project Management settings saved.')),
    );
    if (failed == null) widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) return const LinearProgressIndicator();
    final theme = Theme.of(context);
    final heading = theme.textTheme.titleMedium;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_error != null) Text(_error!),
        Text('Columns', style: heading),
        Text(
          'Drag to reorder. Label draws the values as coloured labels.',
          style: theme.textTheme.bodySmall,
        ),
        ReorderableListView(
          key: const Key('project-board-columns'),
          shrinkWrap: true,
          buildDefaultDragHandles: false,
          physics: const NeverScrollableScrollPhysics(),
          onReorder: (from, to) => setState(() {
            final c = _columns.removeAt(from);
            _columns.insert(to > from ? to - 1 : to, c);
          }),
          children: [
            for (final (i, c) in _columns.indexed)
              Row(
                key: ValueKey('project-board-column:${c['key']}'),
                children: [
                  ReorderableDragStartListener(
                    index: i,
                    child: const Icon(Icons.drag_indicator),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextFormField(
                      initialValue: '${c['label'] ?? ''}',
                      decoration: InputDecoration(
                        isDense: true,
                        helperText: '${c['key']}',
                      ),
                      onChanged: (v) => c['label'] = v,
                    ),
                  ),
                  const SizedBox(width: 8),
                  const Text('Shown'),
                  Switch(
                    value: c['visible'] != false,
                    onChanged: (v) => setState(() => c['visible'] = v),
                  ),
                  const Text('Label'),
                  Checkbox(
                    value: c['as_label'] == true,
                    onChanged: (v) => setState(() {
                      c['as_label'] = v == true;
                      if (v == true) {
                        _labels.putIfAbsent('${c['key']}', () => []);
                      }
                    }),
                  ),
                ],
              ),
          ],
        ),
        const SizedBox(height: 24),
        Text('Label colours', style: heading),
        for (final c in _columns)
          if (c['as_label'] == true) ...[
            const SizedBox(height: 8),
            Text(
              '${c['label'] ?? c['key']}',
              style: theme.textTheme.titleSmall,
            ),
            for (final (i, (text, tone))
                in (_labels['${c['key']}'] ?? []).indexed)
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: text,
                      decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'Value, as it reads',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  DropdownMenu<String>(
                    enableFilter: true,
                    requestFocusOnTap: true,
                    initialSelection: tone,
                    onSelected: (v) => setState(
                      () => _labels['${c['key']}']![i] = (text, v ?? tone),
                    ),
                    dropdownMenuEntries: [
                      for (final t in Tone.values)
                        DropdownMenuEntry(value: t.name, label: t.name),
                    ],
                  ),
                  IconButton(
                    tooltip: 'Remove',
                    icon: const Icon(Icons.close),
                    onPressed: () =>
                        setState(() => _labels['${c['key']}']!.removeAt(i)),
                  ),
                ],
              ),
            TextButton.icon(
              key: ValueKey('project-board-add-label:${c['key']}'),
              onPressed: () => setState(
                () => (_labels['${c['key']}'] ??= []).add((
                  TextEditingController(),
                  Tone.neutral.name,
                )),
              ),
              icon: const Icon(Icons.add),
              label: const Text('Add value'),
            ),
          ],
        const SizedBox(height: 24),
        Text('Sub-tabs', style: heading),
        Text(
          'The job statuses each sub-tab lists, separated by commas.',
          style: theme.textTheme.bodySmall,
        ),
        for (final (key, name, _) in _tabs)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(
              children: [
                SizedBox(width: 180, child: Text(name)),
                Expanded(
                  child: TextField(
                    key: ValueKey('project-board-statuses:$key'),
                    controller: _statuses[key],
                    decoration: const InputDecoration(isDense: true),
                  ),
                ),
                const SizedBox(width: 8),
                DropdownMenu<String>(
                  enableFilter: true,
                  requestFocusOnTap: true,
                  label: const Text('Scheduled'),
                  initialSelection: switch (_scheduled[key]) {
                    true => 'yes',
                    false => 'no',
                    null => 'any',
                  },
                  onSelected: (v) => _scheduled[key] = switch (v) {
                    'yes' => true,
                    'no' => false,
                    _ => null,
                  },
                  dropdownMenuEntries: const [
                    DropdownMenuEntry(value: 'any', label: 'Either'),
                    DropdownMenuEntry(value: 'yes', label: 'Has a date'),
                    DropdownMenuEntry(value: 'no', label: 'No date'),
                  ],
                ),
              ],
            ),
          ),
        const SizedBox(height: 24),
        Text('Part status tags', style: heading),
        TextField(
          key: const Key('project-board-part-tags'),
          controller: _partTags,
          decoration: const InputDecoration(
            isDense: true,
            helperText:
                'Job tags shown in the Part Status column, separated by commas.',
          ),
        ),
        const SizedBox(height: 24),
        FilledButton(
          key: const Key('project-board-save'),
          onPressed: _saving ? null : _save,
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
      ],
    );
  }
}

List<Map<String, dynamic>> _maps(Object? list) => [
  for (final m in (list as List?) ?? const [])
    (m as Map).cast<String, dynamic>(),
];

const _moneyKeys = {'paid', 'pending', 'total'};

/// Jose's six sub-tabs (2026-11-06): the server's key, the label, the icon.
const _tabs = [
  ('unscheduled', 'Unscheduled', Icons.event_busy_outlined),
  ('ready_to_schedule', 'Ready to Schedule', Icons.event_available_outlined),
  ('scheduled', 'Scheduled', Icons.calendar_month_outlined),
  ('in_progress', 'In Progress', Icons.construction_outlined),
  ('done_waiting_payment', 'Done Waiting Payment', Icons.payments_outlined),
  ('complete', 'Complete', Icons.task_alt),
];
