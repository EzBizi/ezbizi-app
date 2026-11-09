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
  final Map<String, List<String>> _statuses = {};
  final Map<String, bool?> _scheduled = {};
  List<String> _partTags = [];

  /// The company's real job statuses and tags, most used first.
  List<String> _knownStatuses = const [];
  List<String> _knownTags = const [];
  bool _ready = false;
  String? _saving;
  String? _error;

  @override
  void initState() {
    super.initState();
    _read();
  }

  @override
  void dispose() {
    for (final list in _labels.values) {
      for (final (c, _) in list) {
        c.dispose();
      }
    }
    super.dispose();
  }

  Future<void> _read() async {
    final (r, o) = await (
      widget.api.get('/settings', widget.scope),
      widget.api.get('/project-board/options'),
    ).wait;
    if (!mounted) return;
    final byKey = {
      for (final s in (r.ok && r.data is List ? r.data as List : const []))
        '${(s as Map)['key']}': s['value'],
    };
    final tabs = (byKey['project_board.tabs'] as Map?) ?? const {};
    final options = o.ok && o.data is Map ? o.data as Map : const {};
    setState(() {
      _error = r.ok ? null : r.message;
      _knownStatuses = [
        for (final s in (options['statuses'] as List?) ?? const []) '$s',
      ];
      _knownTags = [
        for (final t in (options['tags'] as List?) ?? const []) '$t',
      ];
      for (final (key, _, _) in _tabs) {
        final rule = (tabs[key] as Map?) ?? const {};
        _statuses[key] = [
          for (final s in (rule['statuses'] as List?) ?? const []) '$s',
        ];
        _scheduled[key] = rule['scheduled'] as bool?;
      }
      _partTags = [
        for (final t in (byKey['project_board.part_tags'] as List?) ?? const [])
          '$t',
      ];
      // Job Status starts from the company's own statuses, not an empty list.
      final status = _labels['status'];
      if (status != null && status.isEmpty) {
        for (final s in _knownStatuses) {
          status.add((
            TextEditingController(text: s),
            toneForJobStatus(s).name,
          ));
        }
      }
      _ready = true;
    });
  }

  Future<void> _save(String card, Map<String, Object> values) async {
    setState(() => _saving = card);
    String? failed;
    for (final MapEntry(:key, :value) in values.entries) {
      final r = await widget.api.patch('/settings/$key', {
        'value': value,
      }, widget.scope);
      if (!r.ok) failed ??= r.message;
    }
    if (!mounted) return;
    setState(() => _saving = null);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(failed ?? 'Project Management settings saved.')),
    );
    if (failed == null) widget.onSaved();
  }

  Map<String, Object> _labelValues() => {
    'project_board.labels': {
      for (final MapEntry(:key, :value) in _labels.entries)
        key: {
          for (final (c, tone) in value)
            if (c.text.trim().isNotEmpty) c.text.trim(): tone,
        },
    },
  };

  /// A titled card with its one-line explanation and its own Save.
  Widget _card(
    String id,
    String title,
    String explain,
    List<Widget> body,
    Map<String, Object> Function() values,
  ) {
    final theme = Theme.of(context);
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            Text(explain, style: theme.textTheme.bodySmall),
            const SizedBox(height: 12),
            ...body,
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                key: Key('project-board-save:$id'),
                onPressed: _saving != null ? null : () => _save(id, values()),
                child: Text(_saving == id ? 'Saving…' : 'Save'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Every known value as a chip, the chosen ones ticked; a chosen value the
  /// company no longer uses stays listed so it can be unticked.
  Widget _chips(String key, List<String> known, List<String> chosen) {
    bool same(String a, String b) => a.toLowerCase() == b.toLowerCase();
    return Wrap(
      key: Key(key),
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final v in [
          ...known,
          ...chosen.where((c) => !known.any((k) => same(k, c))),
        ])
          FilterChip(
            label: Text(v),
            selected: chosen.any((c) => same(c, v)),
            onSelected: (on) => setState(() {
              chosen.removeWhere((c) => same(c, v));
              if (on) chosen.add(v);
            }),
          ),
      ],
    );
  }

  Widget _labelRow(
    String column,
    int i,
    TextEditingController text,
    String tone,
  ) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: text,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'Value, as it reads',
              ),
            ),
          ),
          const SizedBox(width: 12),
          for (final (t, name) in _swatches)
            Tooltip(
              message: name,
              child: InkWell(
                key: ValueKey('project-board-swatch:$column:$i:${t.name}'),
                customBorder: const CircleBorder(),
                onTap: () =>
                    setState(() => _labels[column]![i] = (text, t.name)),
                child: Container(
                  width: 24,
                  height: 24,
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: ToneColours.of(context, t).ink,
                    border: tone == t.name
                        ? Border.all(
                            color: theme.colorScheme.onSurface,
                            width: 3,
                          )
                        : null,
                  ),
                ),
              ),
            ),
          const SizedBox(width: 12),
          SizedBox(
            width: 180,
            child: Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(
                text.text.isEmpty ? 'Preview' : text.text,
                tone:
                    Tone.values.where((t) => t.name == tone).firstOrNull ??
                    Tone.neutral,
              ),
            ),
          ),
          IconButton(
            tooltip: 'Remove',
            icon: const Icon(Icons.close),
            onPressed: () => setState(() => _labels[column]!.removeAt(i)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) return const LinearProgressIndicator();
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_error != null) Text(_error!),
        _card(
          'columns',
          'Columns',
          'Rename, drag to reorder, and choose which columns show.',
          [
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
                          decoration: const InputDecoration(isDense: true),
                          onChanged: (v) => c['label'] = v,
                        ),
                      ),
                      const SizedBox(width: 8),
                      const Text('Shown'),
                      Switch(
                        value: c['visible'] != false,
                        onChanged: (v) => setState(() => c['visible'] = v),
                      ),
                      Tooltip(
                        message:
                            'Draw each value as a coloured chip. Pick the colours under Label colours.',
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Checkbox(
                              value: c['as_label'] == true,
                              onChanged: (v) => setState(() {
                                c['as_label'] = v == true;
                                if (v == true) {
                                  _labels.putIfAbsent('${c['key']}', () => []);
                                }
                              }),
                            ),
                            const Text('Show as coloured label'),
                          ],
                        ),
                      ),
                    ],
                  ),
              ],
            ),
          ],
          () => {'project_board.columns': _columns, ..._labelValues()},
        ),
        _card(
          'labels',
          'Label colours',
          'The colour each value gets in a column shown as coloured labels.',
          [
            for (final c in _columns)
              if (c['as_label'] == true) ...[
                Text(
                  '${c['label'] ?? c['key']}',
                  style: theme.textTheme.titleSmall,
                ),
                for (final (i, (text, tone))
                    in (_labels['${c['key']}'] ?? []).indexed)
                  _labelRow('${c['key']}', i, text, tone),
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
                const SizedBox(height: 8),
              ],
          ],
          _labelValues,
        ),
        _card(
          'tabs',
          'Sub-tabs',
          'Which job statuses each sub-tab lists, and whether the job must have a scheduled date.',
          [
            for (final (key, name, _) in _tabs)
              Padding(
                padding: const EdgeInsets.only(bottom: 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(name, style: theme.textTheme.titleSmall),
                        ),
                        DropdownMenu<String>(
                          enableFilter: true,
                          requestFocusOnTap: true,
                          width: 240,
                          label: const Text('Scheduled date'),
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
                            DropdownMenuEntry(value: 'any', label: 'Any'),
                            DropdownMenuEntry(
                              value: 'yes',
                              label: 'Must be set',
                            ),
                            DropdownMenuEntry(
                              value: 'no',
                              label: 'Must be empty',
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    _chips(
                      'project-board-statuses:$key',
                      _knownStatuses,
                      _statuses[key]!,
                    ),
                  ],
                ),
              ),
          ],
          () => {
            'project_board.tabs': {
              for (final (key, _, _) in _tabs)
                key: {
                  'statuses': _statuses[key]!,
                  if (_scheduled[key] != null) 'scheduled': _scheduled[key]!,
                },
            },
          },
        ),
        _card(
          'parts',
          'Part status',
          'The job tags shown in the Part Status column.',
          [
            KeyedSubtree(
              key: const Key('project-board-part-tags'),
              child: _chips(
                'project-board-part-tags-chips',
                _knownTags,
                _partTags,
              ),
            ),
          ],
          () => {'project_board.part_tags': _partTags},
        ),
      ],
    );
  }
}

/// The label colours, named as a person sees them.
const _swatches = [
  (Tone.neutral, 'Grey'),
  (Tone.info, 'Blue'),
  (Tone.caution, 'Amber'),
  (Tone.review, 'Teal'),
  (Tone.ok, 'Green'),
  (Tone.bad, 'Red'),
];

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
