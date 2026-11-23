/// HR › Recruiting (UI queue 201, options 1 and 4; Shoval, owner,
/// 2026-11-20; server 9dfa1e434). Applicants from every source moving through
/// the organization's stages, each with a Relevant flag and interviews; and
/// what each position cost a month. Reading is `hr.view`; every write control
/// needs `hr.edit` and is not drawn without it.
///
/// Positions, stages and sources are the organization's own lists (Settings ›
/// Lists, hr.recruiting.*): their labels are the server's, never spelled here.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_search.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';

typedef _Json = Map<String, dynamic>;
typedef _Option = ({String value, String label});

/// A list as the server sends it: plain strings or {value, label}.
List<_Option> _optionsOf(Object? raw) => [
  for (final o in (raw as List?) ?? const [])
    if (o is Map)
      (value: '${o['value']}', label: '${o['label'] ?? o['value']}')
    else if (o != null)
      (value: '$o', label: '$o'),
];

String _labelIn(List<_Option> list, Object? value) {
  if (value == null) return '';
  for (final o in list) {
    if (o.value == '$value') return o.label;
  }
  return '$value';
}

/// "40 min", "3.2 h", "2 d": how long until somebody first answered.
String _waited(Object? fromIso, Object? toIso) {
  final from = DateTime.tryParse('${fromIso ?? ''}');
  final to = DateTime.tryParse('${toIso ?? ''}');
  if (from == null || to == null) return '—';
  return _minutes(to.difference(from).inMinutes);
}

String _minutes(num m) {
  if (m < 60) return '${m.round()} min';
  if (m < 48 * 60) return '${(m / 60).toStringAsFixed(1)} h';
  return '${(m / 1440).round()} d';
}

class RecruitingTab extends StatefulWidget {
  const RecruitingTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<RecruitingTab> createState() => _RecruitingTabState();
}

class _RecruitingTabState extends State<RecruitingTab> {
  String _sub = 'applicants';

  /// The lists, from the applicants answer; Spend uses the same ones.
  List<_Option> _positions = const [];
  List<_Option> _sources = const [];

  @override
  Widget build(BuildContext context) {
    const subs = [
      ('applicants', 'Applicants', Icons.person_search_outlined),
      ('spend', 'Spend', Icons.payments_outlined),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const Key('tour-hr-recruiting-tabs'),
          level: TabLevel.secondary,
          labels: [for (final s in subs) (s.$2, s.$3)],
          selected: subs.indexWhere((s) => s.$1 == _sub),
          onSelected: (i) => setState(() => _sub = subs[i].$1),
        ),
        Expanded(
          child: IndexedStack(
            index: _sub == 'applicants' ? 0 : 1,
            children: [
              _Applicants(
                api: widget.api,
                session: widget.session,
                onLists: (p, st, so) => setState(() {
                  _positions = p;
                  _sources = so;
                }),
              ),
              _Spend(
                api: widget.api,
                session: widget.session,
                positions: _positions,
                sources: _sources,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

bool _canEdit(Session s) => s.me?.can('hr.edit') ?? false;

List<_Option> _branchesOf(Session s) => [
  for (final f in s.me?.switchableFranchises ?? const <FranchiseSummary>[])
    (value: f.id, label: f.name),
];

// ── Applicants ──────────────────────────────────────────────────────

class _Applicants extends StatefulWidget {
  const _Applicants({
    required this.api,
    required this.session,
    required this.onLists,
  });

  final ApiClient api;
  final Session session;
  final void Function(
    List<_Option> positions,
    List<_Option> stages,
    List<_Option> sources,
  )
  onLists;

  @override
  State<_Applicants> createState() => _ApplicantsState();
}

class _ApplicantsState extends State<_Applicants> {
  final List<_Json> _rows = [];
  _Json _meta = const {};
  List<_Option> _positions = const [];
  List<_Option> _stages = const [];
  List<_Option> _sources = const [];
  bool _loading = true;
  String? _error;

  String? _position;
  String? _stage;
  String? _source;
  String? _branch;
  String _range = 'any';
  String _query = '';
  Timer? _typing;

  /// The applicant docked beside the list (wide) or last opened.
  String? _open;

  static const _ranges = [
    ('any', 'Any time', 0),
    ('7', 'Last 7 days', 7),
    ('30', 'Last 30 days', 30),
    ('90', 'Last 90 days', 90),
    ('365', 'Last 12 months', 365),
  ];

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _typing?.cancel();
    super.dispose();
  }

  Map<String, String> _filters({int page = 1}) {
    final days = _ranges.firstWhere((r) => r.$1 == _range).$3;
    return {
      'position': ?_position,
      'stage': ?_stage,
      'source': ?_source,
      'franchise_id': ?_branch,
      if (days > 0)
        'from': formatCalendarDay(
          DateTime.now().subtract(Duration(days: days)),
        ),
      if (_query.isNotEmpty) 'search': _query,
      'page': '$page',
    };
  }

  Future<void> _load({bool more = false}) async {
    setState(() => _loading = true);
    final page = more ? ((_meta['page'] as num?)?.toInt() ?? 1) + 1 : 1;
    final r = await widget.api.get('/hr/applicants', _filters(page: page));
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!r.ok) {
        _error = r.reason;
        return;
      }
      _error = null;
      if (!more) _rows.clear();
      _rows.addAll([
        for (final a in (r.data as List? ?? const []))
          if (a is Map) a.cast<String, dynamic>(),
      ]);
      _meta = ((r.body['meta'] as Map?) ?? const {}).cast<String, dynamic>();
      final lists = (r.body['lists'] as Map?) ?? const {};
      _positions = _optionsOf(lists['positions']);
      _stages = _optionsOf(lists['stages']);
      _sources = _optionsOf(lists['sources']);
    });
    widget.onLists(_positions, _stages, _sources);
  }

  void _search(String v) {
    _query = v.trim();
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 350), _load);
  }

  void _pick(void Function() set) {
    setState(set);
    unawaited(_load());
  }

  Future<void> _openApplicant(String id, {required bool docked}) async {
    setState(() => _open = id);
    if (docked) return;
    await showSidePanel<void>(
      context,
      builder: (_) => _ApplicantPanel(
        key: ValueKey('applicant-panel-$id'),
        api: widget.api,
        session: widget.session,
        id: id,
        stages: _stages,
        sources: _sources,
        positions: _positions,
        onChanged: _load,
      ),
    );
  }

  Future<void> _new() async {
    final made = await showSidePanel<String>(
      context,
      builder: (_) => _NewApplicantPanel(
        api: widget.api,
        session: widget.session,
        positions: _positions,
        stages: _stages,
        sources: _sources,
      ),
    );
    if (made != null && mounted) {
      await _load();
      if (mounted) setState(() => _open = made);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return LayoutBuilder(
      key: const Key('recruiting-applicants'),
      builder: (context, box) {
        final phone = box.maxWidth < 600;
        final docked = box.maxWidth >= 1100;
        final edit = _canEdit(widget.session);
        final branches = _branchesOf(widget.session);

        Widget picker(
          String key,
          String hint,
          String? value,
          List<_Option> options,
          ValueChanged<String?> set,
        ) => SizedBox(
          width: phone ? double.infinity : 170,
          child: SearchPickerField<String?>(
            key: ValueKey('recruiting-filter-$key'),
            value: value,
            hint: hint,
            isDense: true,
            options: [
              SearchOption(value: null, label: hint),
              for (final o in options)
                SearchOption(value: o.value, label: o.label),
            ],
            onChanged: (v) => _pick(() => set(v)),
          ),
        );

        final filters = [
          picker(
            'position',
            'Any position',
            _position,
            _positions,
            (v) => _position = v,
          ),
          picker('stage', 'Any stage', _stage, _stages, (v) => _stage = v),
          picker('source', 'Any source', _source, _sources, (v) => _source = v),
          if (branches.length > 1)
            picker(
              'branch',
              'Every branch',
              _branch,
              branches,
              (v) => _branch = v,
            ),
          SizedBox(
            width: phone ? double.infinity : 160,
            child: SearchPickerField<String>(
              key: const ValueKey('recruiting-filter-dates'),
              value: _range,
              isDense: true,
              options: [
                for (final r in _ranges) SearchOption(value: r.$1, label: r.$2),
              ],
              onChanged: (v) => _pick(() => _range = v ?? 'any'),
            ),
          ),
        ];

        final search = SizedBox(
          width: phone ? double.infinity : 240,
          child: ListSearch(
            key: const Key('tour-hr-recruiting-search'),
            hint: 'Name, phone, email',
            width: double.infinity,
            onChanged: _search,
          ),
        );

        final add = edit
            ? FilledButton.icon(
                key: const Key('recruiting-new'),
                onPressed: _new,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Applicant'),
              )
            : null;

        final chips = _Chips(meta: _meta);

        final list = _error != null && _rows.isEmpty
            ? Padding(
                padding: const EdgeInsets.all(16),
                child: ErrorNote(_error!),
              )
            : _rows.isEmpty && !_loading
            ? const EmptyState(
                icon: Icons.person_search_outlined,
                title: 'No applicants',
                message:
                    'Nobody matches these filters. Add an applicant, or widen '
                    'the dates.',
              )
            : ListView(
                key: const Key('recruiting-rows'),
                padding: EdgeInsets.fromLTRB(
                  phone ? 12 : 16,
                  0,
                  phone ? 12 : 16,
                  16,
                ),
                children: [
                  if (!phone) const _HeaderRow(),
                  for (final a in _rows)
                    phone
                        ? _ApplicantCard(
                            key: ValueKey('applicant-${a['id']}'),
                            row: a,
                            stages: _stages,
                            positions: _positions,
                            sources: _sources,
                            onTap: () =>
                                _openApplicant('${a['id']}', docked: false),
                          )
                        : _ApplicantRow(
                            key: ValueKey('applicant-${a['id']}'),
                            row: a,
                            selected: _open == '${a['id']}',
                            stages: _stages,
                            positions: _positions,
                            sources: _sources,
                            onTap: () =>
                                _openApplicant('${a['id']}', docked: docked),
                          ),
                  if ((_meta['page'] as num? ?? 1) <
                      (_meta['last_page'] as num? ?? 1))
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton(
                        key: const Key('recruiting-more'),
                        onPressed: _loading ? null : () => _load(more: true),
                        child: Text(
                          'Show more (${(_meta['total'] as num? ?? 0) - _rows.length})',
                        ),
                      ),
                    ),
                ],
              );

        final top = Padding(
          padding: EdgeInsets.fromLTRB(phone ? 12 : 16, 10, phone ? 12 : 16, 8),
          child: phone
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Expanded(child: chips),
                        ?add,
                      ],
                    ),
                    const SizedBox(height: 8),
                    search,
                    const SizedBox(height: 8),
                    ExpansionTile(
                      key: const Key('recruiting-filters'),
                      tilePadding: EdgeInsets.zero,
                      title: Text('Filters', style: theme.textTheme.labelLarge),
                      children: [
                        for (final f in filters)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: f,
                          ),
                      ],
                    ),
                  ],
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [search, ...filters],
                          ),
                        ),
                        if (add != null) ...[const SizedBox(width: 12), add],
                      ],
                    ),
                    const SizedBox(height: 10),
                    chips,
                  ],
                ),
        );

        final open = _open;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  top,
                  if (_loading) const LinearProgressIndicator(minHeight: 2),
                  Expanded(child: list),
                ],
              ),
            ),
            if (docked && open != null) ...[
              const VerticalDivider(width: 1),
              SizedBox(
                width: 400,
                child: _ApplicantPanel(
                  key: ValueKey('applicant-panel-$open'),
                  api: widget.api,
                  session: widget.session,
                  id: open,
                  stages: _stages,
                  sources: _sources,
                  positions: _positions,
                  docked: true,
                  onChanged: _load,
                  onClose: () => setState(() => _open = null),
                ),
              ),
            ],
          ],
        );
      },
    );
  }
}

/// The figures the server counts over every applicant the filters match;
/// a figure it does not send is not drawn (nothing counted from one page).
class _Chips extends StatelessWidget {
  const _Chips({required this.meta});

  final _Json meta;

  @override
  Widget build(BuildContext context) {
    Widget chip(String key, String label, String value, Tone tone) {
      final c = ToneColours.of(context, tone);
      return Container(
        key: ValueKey('recruiting-count-$key'),
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
        decoration: BoxDecoration(
          color: c.fill,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text.rich(
          TextSpan(
            text: '$label  ',
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: c.ink),
            children: [
              TextSpan(
                text: value,
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(color: c.ink),
              ),
            ],
          ),
        ),
      );
    }

    num? n(String k) => meta[k] as num?;
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        chip('total', 'Applicants', '${n('total') ?? 0}', Tone.neutral),
        if (n('relevant') case final v?)
          chip('relevant', 'Relevant', '$v', Tone.ok),
        if (n('interviewed') case final v?)
          chip('interviewed', 'Interviewed', '$v', Tone.info),
        if (n('hired') case final v?) chip('hired', 'Hired', '$v', Tone.ok),
        if (n('median_first_response_minutes') case final v?)
          chip('response', 'First response', _minutes(v), Tone.caution),
      ],
    );
  }
}

const _flexName = 5;
const _flexPosition = 3;
const _flexSource = 3;
const _flexStage = 3;
const _wNumber = 90.0;
const _wDate = 100.0;
const _wResponse = 120.0;

class _HeaderRow extends StatelessWidget {
  const _HeaderRow();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    Widget cell(String t) => Text(t.toUpperCase(), style: style);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 8),
      child: Row(
        children: [
          Expanded(flex: _flexName, child: cell('Applicant')),
          Expanded(flex: _flexPosition, child: cell('Position')),
          Expanded(flex: _flexSource, child: cell('Source')),
          Expanded(flex: _flexStage, child: cell('Stage')),
          SizedBox(width: _wNumber, child: cell('Interviews')),
          SizedBox(width: _wDate, child: cell('Applied')),
          SizedBox(width: _wResponse, child: cell('First response')),
        ],
      ),
    );
  }
}

Widget _stagePill(BuildContext context, List<_Option> stages, Object? stage) =>
    StatusPill(
      _labelIn(stages, stage),
      tone: switch ('$stage') {
        'hired' => Tone.ok,
        'rejected' || 'withdrawn' => Tone.bad,
        'interview' || 'interviewing' => Tone.info,
        _ => Tone.neutral,
      },
      dense: true,
    );

String _contact(_Json row) => [
  if ('${row['phone'] ?? ''}'.isNotEmpty) '${row['phone']}',
  if ('${row['email'] ?? ''}'.isNotEmpty) '${row['email']}',
].join(' · ');

class _ApplicantRow extends StatelessWidget {
  const _ApplicantRow({
    super.key,
    required this.row,
    required this.selected,
    required this.stages,
    required this.positions,
    required this.sources,
    required this.onTap,
  });

  final _Json row;
  final bool selected;
  final List<_Option> stages;
  final List<_Option> positions;
  final List<_Option> sources;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final formats = DisplayFormats.fallback;
    return Material(
      color: selected ? theme.colorScheme.primaryContainer : null,
      child: InkWell(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 8),
          decoration: BoxDecoration(
            border: Border(bottom: BorderSide(color: theme.dividerColor)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                flex: _flexName,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            '${row['name'] ?? ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleSmall,
                          ),
                        ),
                        if (row['relevant'] == true) ...[
                          const SizedBox(width: 6),
                          Icon(
                            Icons.star,
                            key: ValueKey('applicant-relevant-${row['id']}'),
                            size: 15,
                            color: theme.colorScheme.tertiary,
                          ),
                        ],
                      ],
                    ),
                    if (_contact(row).isNotEmpty)
                      Text(
                        _contact(row),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: muted,
                      ),
                  ],
                ),
              ),
              Expanded(
                flex: _flexPosition,
                child: Text(_labelIn(positions, row['position'])),
              ),
              Expanded(
                flex: _flexSource,
                child: Text(_labelIn(sources, row['source'])),
              ),
              Expanded(
                flex: _flexStage,
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: _stagePill(context, stages, row['stage']),
                ),
              ),
              SizedBox(
                width: _wNumber,
                child: Text('${row['interviews_count'] ?? 0}'),
              ),
              SizedBox(
                width: _wDate,
                child: Text(
                  formatListStamp(row['applied_at'], '—', formats: formats),
                ),
              ),
              SizedBox(
                width: _wResponse,
                child: Text(
                  _waited(row['applied_at'], row['first_response_at']),
                  style: row['first_response_at'] == null ? muted : null,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ApplicantCard extends StatelessWidget {
  const _ApplicantCard({
    super.key,
    required this.row,
    required this.stages,
    required this.positions,
    required this.sources,
    required this.onTap,
  });

  final _Json row;
  final List<_Option> stages;
  final List<_Option> positions;
  final List<_Option> sources;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final answered = row['first_response_at'] == null
        ? 'not answered yet'
        : 'answered in ${_waited(row['applied_at'], row['first_response_at'])}';
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${row['name'] ?? ''}${row['relevant'] == true ? ' ★' : ''}',
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  _stagePill(context, stages, row['stage']),
                ],
              ),
              Text(
                [
                  _labelIn(positions, row['position']),
                  if (row['source'] != null) _labelIn(sources, row['source']),
                ].join(' · '),
                style: muted,
              ),
              Text(
                'Applied ${formatListStamp(row['applied_at'], '—', formats: DisplayFormats.fallback)}'
                ' · $answered · ${row['interviews_count'] ?? 0} interviews',
                style: muted,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── One applicant ───────────────────────────────────────────────────

class _ApplicantPanel extends StatefulWidget {
  const _ApplicantPanel({
    super.key,
    required this.api,
    required this.session,
    required this.id,
    required this.stages,
    required this.sources,
    required this.positions,
    required this.onChanged,
    this.docked = false,
    this.onClose,
  });

  final ApiClient api;
  final Session session;
  final String id;
  final List<_Option> stages;
  final List<_Option> sources;
  final List<_Option> positions;
  final Future<void> Function() onChanged;
  final bool docked;
  final VoidCallback? onClose;

  @override
  State<_ApplicantPanel> createState() => _ApplicantPanelState();
}

class _ApplicantPanelState extends State<_ApplicantPanel> {
  _Json? _a;
  String? _error;
  bool _adding = false;
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _notes = TextEditingController();

  // A new interview, while it is being written.
  String _kind = 'phone';
  final _when = TextEditingController();

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _phone.dispose();
    _email.dispose();
    _notes.dispose();
    _when.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/hr/applicants/${widget.id}');
    if (!mounted) return;
    setState(() {
      if (!r.ok) {
        _error = r.reason;
        return;
      }
      _a = (r.data as Map).cast<String, dynamic>();
      _phone.text = '${_a!['phone'] ?? ''}';
      _email.text = '${_a!['email'] ?? ''}';
      _notes.text = '${_a!['notes'] ?? ''}';
    });
  }

  Future<void> _patch(Map<String, dynamic> body) async {
    final r = await widget.api.patch('/hr/applicants/${widget.id}', body);
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    setState(() => _error = null);
    await _load();
    await widget.onChanged();
  }

  Future<void> _patchInterview(String id, Map<String, dynamic> body) async {
    final r = await widget.api.patch('/hr/applicant-interviews/$id', body);
    if (!mounted) return;
    if (!r.ok) setState(() => _error = r.reason);
    await _load();
    await widget.onChanged();
  }

  Future<void> _addInterview() async {
    final at = parseWallDateTime(_when.text);
    if (at == null) {
      setState(() => _error = 'Choose when the interview is.');
      return;
    }
    final local = DateTime(
      at.day.year,
      at.day.month,
      at.day.day,
      at.hour,
      at.minute,
    );
    final r = await widget.api.post('/hr/applicants/${widget.id}/interviews', {
      'kind': _kind,
      'scheduled_at': local.toUtc().toIso8601String(),
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    setState(() {
      _adding = false;
      _when.clear();
    });
    await _load();
    await widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final a = _a;
    final theme = Theme.of(context);
    final edit = _canEdit(widget.session);
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget saveOnLeave(
      String key,
      String label,
      TextEditingController c,
      String field, {
      int lines = 1,
    }) => Focus(
      onFocusChange: (on) {
        if (!on && c.text.trim() != '${a?[field] ?? ''}') {
          unawaited(
            _patch({field: c.text.trim().isEmpty ? null : c.text.trim()}),
          );
        }
      },
      child: TextField(
        key: ValueKey('applicant-$key'),
        controller: c,
        enabled: edit,
        minLines: lines,
        maxLines: lines == 1 ? 1 : 6,
        decoration: InputDecoration(labelText: label),
      ),
    );

    final body = a == null
        ? (_error == null
              ? const Center(child: CircularProgressIndicator())
              : ErrorNote(_error!))
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                [
                  _labelIn(widget.positions, a['position']),
                  if (a['source'] != null)
                    'via ${_labelIn(widget.sources, a['source'])}',
                  'applied ${formatStamp(a['applied_at'], '—', formats: formats)}',
                ].join(' · '),
                style: muted,
              ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                ErrorNote(_error!),
              ],
              const SizedBox(height: 14),
              SearchPickerField<String>(
                key: const ValueKey('applicant-stage'),
                label: 'Stage',
                value: '${a['stage']}',
                options: [
                  for (final s in widget.stages)
                    SearchOption(value: s.value, label: s.label),
                ],
                onChanged: edit
                    ? (v) {
                        if (v != null && v != a['stage']) {
                          unawaited(_patch({'stage': v}));
                        }
                      }
                    : null,
              ),
              SwitchListTile(
                key: const ValueKey('applicant-relevant'),
                contentPadding: EdgeInsets.zero,
                title: const Text('Relevant'),
                value: a['relevant'] == true,
                onChanged: edit ? (v) => _patch({'relevant': v}) : null,
              ),
              saveOnLeave('phone', 'Phone', _phone, 'phone'),
              const SizedBox(height: 10),
              saveOnLeave('email', 'Email', _email, 'email'),
              const SizedBox(height: 16),
              Text('Interviews', style: theme.textTheme.titleSmall),
              const SizedBox(height: 6),
              for (final i in (a['interviews'] as List? ?? const []))
                if (i is Map)
                  _InterviewTile(
                    key: ValueKey('interview-${i['id']}'),
                    interview: i.cast<String, dynamic>(),
                    formats: formats,
                    canEdit: edit,
                    onPatch: (body) => _patchInterview('${i['id']}', body),
                  ),
              if ((a['interviews'] as List? ?? const []).isEmpty)
                Text('No interview yet.', style: muted),
              if (edit && !_adding)
                Align(
                  alignment: Alignment.centerLeft,
                  child: OutlinedButton.icon(
                    key: const Key('applicant-add-interview'),
                    onPressed: () => setState(() => _adding = true),
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Interview'),
                  ),
                ),
              if (_adding) ...[
                const SizedBox(height: 8),
                SegmentedButton<String>(
                  key: const Key('interview-kind'),
                  segments: const [
                    ButtonSegment(value: 'phone', label: Text('Phone')),
                    ButtonSegment(value: 'in_person', label: Text('In person')),
                  ],
                  selected: {_kind},
                  onSelectionChanged: (s) => setState(() => _kind = s.first),
                ),
                const SizedBox(height: 8),
                CalendarDayField(
                  key: const Key('interview-when'),
                  controller: _when,
                  formats: formats,
                  label: 'When',
                  withTime: true,
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [
                    TextButton(
                      onPressed: () => setState(() => _adding = false),
                      child: const Text('Cancel'),
                    ),
                    const SizedBox(width: 8),
                    FilledButton(
                      key: const Key('interview-save'),
                      onPressed: _addInterview,
                      child: const Text('Add interview'),
                    ),
                  ],
                ),
              ],
              const SizedBox(height: 16),
              saveOnLeave('notes', 'Notes', _notes, 'notes', lines: 3),
            ],
          );

    return SidePanel(
      docked: widget.docked,
      onClose: widget.onClose,
      title: '${a?['name'] ?? 'Applicant'}',
      child: body,
    );
  }
}

class _InterviewTile extends StatelessWidget {
  const _InterviewTile({
    super.key,
    required this.interview,
    required this.formats,
    required this.canEdit,
    required this.onPatch,
  });

  final _Json interview;
  final DisplayFormats formats;
  final bool canEdit;
  final void Function(Map<String, dynamic>) onPatch;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final outcome = interview['outcome'] as String?;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '${interview['kind'] == 'in_person' ? 'In person' : 'Phone'} · '
            '${formatStamp(interview['scheduled_at'], '—', formats: formats)}',
            style: theme.textTheme.titleSmall,
          ),
          if ('${interview['notes'] ?? ''}'.isNotEmpty)
            Text('${interview['notes']}', style: theme.textTheme.bodySmall),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              FilterChip(
                key: ValueKey('interview-held-${interview['id']}'),
                label: const Text('Held'),
                selected: interview['held'] == true,
                onSelected: canEdit ? (v) => onPatch({'held': v}) : null,
              ),
              for (final (value, label, tone) in const [
                ('pass', 'Pass', Tone.ok),
                ('fail', 'Fail', Tone.bad),
                ('no_show', 'No-show', Tone.caution),
              ])
                if (canEdit)
                  ChoiceChip(
                    key: ValueKey('interview-$value-${interview['id']}'),
                    label: Text(label),
                    selected: outcome == value,
                    onSelected: (on) => onPatch({'outcome': on ? value : null}),
                  )
                else if (outcome == value)
                  StatusPill(label, tone: tone, dense: true),
            ],
          ),
        ],
      ),
    );
  }
}

// ── A new applicant ─────────────────────────────────────────────────

class _NewApplicantPanel extends StatefulWidget {
  const _NewApplicantPanel({
    required this.api,
    required this.session,
    required this.positions,
    required this.stages,
    required this.sources,
  });

  final ApiClient api;
  final Session session;
  final List<_Option> positions;
  final List<_Option> stages;
  final List<_Option> sources;

  @override
  State<_NewApplicantPanel> createState() => _NewApplicantPanelState();
}

class _NewApplicantPanelState extends State<_NewApplicantPanel> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  String? _position;
  String? _source;
  String? _branch;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final scope = widget.session.me?.scope;
    // Standing in a branch: that branch (a branch user must name one).
    if (scope?.type == 'franchise') _branch = scope!.id;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _position == null) {
      setState(() => _error = 'A name and a position are needed.');
      return;
    }
    setState(() => _busy = true);
    final r = await widget.api.post('/hr/applicants', {
      'name': _name.text.trim(),
      'position': _position,
      if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
      'source': ?_source,
      'franchise_id': ?_branch,
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _busy = false;
        _error = r.reason;
      });
      return;
    }
    Navigator.of(context).pop('${(r.data as Map?)?['id'] ?? ''}');
  }

  @override
  Widget build(BuildContext context) {
    final branches = _branchesOf(widget.session);
    return SidePanel(
      key: const Key('recruiting-new-panel'),
      title: 'New applicant',
      actions: [
        FilledButton(
          key: const Key('recruiting-new-save'),
          onPressed: _busy ? null : _save,
          child: const Text('Add applicant'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 8),
          ],
          TextField(
            key: const Key('recruiting-new-name'),
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
          ),
          const SizedBox(height: 10),
          SearchPickerField<String>(
            key: const Key('recruiting-new-position'),
            label: 'Position',
            value: _position,
            options: [
              for (final p in widget.positions)
                SearchOption(value: p.value, label: p.label),
            ],
            onChanged: (v) => setState(() => _position = v),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const Key('recruiting-new-phone'),
            controller: _phone,
            decoration: const InputDecoration(labelText: 'Phone'),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const Key('recruiting-new-email'),
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
          ),
          const SizedBox(height: 10),
          SearchPickerField<String?>(
            key: const Key('recruiting-new-source'),
            label: 'Source',
            value: _source,
            options: [
              const SearchOption(value: null, label: 'Not set'),
              for (final s in widget.sources)
                SearchOption(value: s.value, label: s.label),
            ],
            onChanged: (v) => setState(() => _source = v),
          ),
          if (branches.isNotEmpty) ...[
            const SizedBox(height: 10),
            SearchPickerField<String?>(
              key: const Key('recruiting-new-branch'),
              label: 'Branch',
              value: _branch,
              options: [
                const SearchOption(
                  value: null,
                  label: 'The whole organization',
                ),
                for (final b in branches)
                  SearchOption(value: b.value, label: b.label),
              ],
              onChanged: (v) => setState(() => _branch = v),
            ),
          ],
        ],
      ),
    );
  }
}

// ── Spend ───────────────────────────────────────────────────────────

class _Spend extends StatefulWidget {
  const _Spend({
    required this.api,
    required this.session,
    required this.positions,
    required this.sources,
  });

  final ApiClient api;
  final Session session;
  final List<_Option> positions;
  final List<_Option> sources;

  @override
  State<_Spend> createState() => _SpendState();
}

class _SpendState extends State<_Spend> {
  List<_Json> _rows = const [];

  /// Hired applicants per position in the same months (server, meta.hires);
  /// null from a server that does not count them, and then no cost per hire.
  Map<String, num>? _hires;
  String? _error;
  bool _loading = true;

  /// The last six months, oldest first: "YYYY-MM".
  late final List<String> _months = () {
    final now = DateTime.now();
    return [
      for (var i = 5; i >= 0; i--)
        () {
          final d = DateTime(now.year, now.month - i);
          return '${d.year}-${d.month.toString().padLeft(2, '0')}';
        }(),
    ];
  }();

  // A new line being written.
  bool _adding = false;
  String? _newPosition;
  String? _newMonth;
  final _newAmount = TextEditingController();

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _newAmount.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/hr/recruiting-spend', {
      'from': _months.first,
      'to': _months.last,
    });
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!r.ok) {
        _error = r.reason;
        return;
      }
      _error = null;
      _rows = [
        for (final s in (r.data as List? ?? const []))
          if (s is Map) s.cast<String, dynamic>(),
      ];
      final hires = (r.body['meta'] as Map?)?['hires'];
      _hires = hires is Map
          ? {
              for (final e in hires.entries)
                if (e.value is num) '${e.key}': e.value as num,
            }
          : (hires is List ? <String, num>{} : null);
    });
  }

  String _monthOf(_Json r) => '${r['month'] ?? ''}'.substring(
    0,
    '${r['month'] ?? ''}'.length.clamp(0, 7),
  );

  static String _money(num v) {
    final whole = v.round();
    final s = whole.toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );
    return '\$$s';
  }

  static const _monthNames = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  String _monthLabel(String ym) {
    final m = int.tryParse(ym.length >= 7 ? ym.substring(5, 7) : '') ?? 1;
    return _monthNames[(m - 1).clamp(0, 11)];
  }

  Future<void> _setCell(String position, String month, String raw) async {
    final amount = num.tryParse(raw.replaceAll(RegExp(r'[\$,\s]'), ''));
    final existing = _rows
        .where((r) => r['position'] == position && _monthOf(r) == month)
        .toList();
    late final ApiResponse r;
    if (existing.isEmpty) {
      if (amount == null || amount == 0) return;
      r = await widget.api.post('/hr/recruiting-spend', {
        'month': month,
        'position': position,
        'amount': amount,
      });
    } else if (amount == null || amount == 0) {
      r = await widget.api.delete(
        '/hr/recruiting-spend/${existing.first['id']}',
      );
    } else {
      r = await widget.api.patch(
        '/hr/recruiting-spend/${existing.first['id']}',
        {'amount': amount},
      );
    }
    if (!mounted) return;
    if (!r.ok) setState(() => _error = r.reason);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edit = _canEdit(widget.session);
    final positions = {
      for (final p in widget.positions) p.value,
      for (final r in _rows) '${r['position']}',
    }.toList();
    num cell(String p, String m) => _rows
        .where((r) => r['position'] == p && _monthOf(r) == m)
        .fold<num>(0, (a, r) => a + ((r['amount'] as num?) ?? 0));

    final header = TableRow(
      children: [
        for (final t in [
          'Position',
          ..._months.map(_monthLabel),
          'Total',
          if (_hires != null) ...['Hires', 'Cost per hire'],
        ])
          Padding(
            padding: const EdgeInsets.all(8),
            child: Text(
              t.toUpperCase(),
              textAlign: t == 'Position' ? TextAlign.start : TextAlign.end,
              style: theme.textTheme.labelSmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
      ],
    );

    return ListView(
      key: const Key('recruiting-spend'),
      padding: const EdgeInsets.all(16),
      children: [
        if (_loading) const LinearProgressIndicator(minHeight: 2),
        if (_error != null) ErrorNote(_error!),
        Align(
          alignment: Alignment.centerRight,
          child: edit && !_adding
              ? FilledButton.icon(
                  key: const Key('spend-new'),
                  onPressed: () => setState(() => _adding = true),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Spend'),
                )
              : const SizedBox.shrink(),
        ),
        if (_adding)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 200,
                child: SearchPickerField<String>(
                  key: const Key('spend-new-position'),
                  label: 'Position',
                  value: _newPosition,
                  options: [
                    for (final p in widget.positions)
                      SearchOption(value: p.value, label: p.label),
                  ],
                  onChanged: (v) => setState(() => _newPosition = v),
                ),
              ),
              SizedBox(
                width: 140,
                child: SearchPickerField<String>(
                  key: const Key('spend-new-month'),
                  label: 'Month',
                  value: _newMonth,
                  options: [
                    for (final m in _months.reversed)
                      SearchOption(
                        value: m,
                        label: '${_monthLabel(m)} ${m.substring(0, 4)}',
                      ),
                  ],
                  onChanged: (v) => setState(() => _newMonth = v),
                ),
              ),
              SizedBox(
                width: 140,
                child: TextField(
                  key: const Key('spend-new-amount'),
                  controller: _newAmount,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Amount',
                    prefixText: r'$',
                  ),
                ),
              ),
              FilledButton(
                key: const Key('spend-new-save'),
                onPressed: () async {
                  final p = _newPosition;
                  final m = _newMonth;
                  if (p == null || m == null) return;
                  final extra =
                      num.tryParse(_newAmount.text.replaceAll(',', '')) ?? 0;
                  await _setCell(p, m, '${cell(p, m) + extra}');
                  if (mounted) {
                    setState(() {
                      _adding = false;
                      _newAmount.clear();
                    });
                  }
                },
                child: const Text('Add'),
              ),
              TextButton(
                onPressed: () => setState(() => _adding = false),
                child: const Text('Cancel'),
              ),
            ],
          ),
        const SizedBox(height: 8),
        if (positions.isEmpty && !_loading)
          const EmptyState(
            icon: Icons.payments_outlined,
            title: 'No positions yet',
            message: 'Positions are set in Settings › Lists.',
          )
        else
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minWidth: 900),
              child: Table(
                key: const Key('spend-table'),
                columnWidths: const {0: FixedColumnWidth(180)},
                defaultColumnWidth: const FixedColumnWidth(100),
                border: TableBorder(
                  horizontalInside: BorderSide(color: theme.dividerColor),
                ),
                children: [
                  header,
                  for (final p in positions)
                    TableRow(
                      children: [
                        Padding(
                          padding: const EdgeInsets.all(8),
                          child: Text(
                            _labelIn(widget.positions, p),
                            style: theme.textTheme.titleSmall,
                          ),
                        ),
                        for (final m in _months)
                          Padding(
                            padding: const EdgeInsets.all(4),
                            child: edit
                                ? _AmountCell(
                                    key: ValueKey('spend-$p-$m'),
                                    value: cell(p, m),
                                    onSave: (v) => _setCell(p, m, v),
                                  )
                                : Text(
                                    cell(p, m) == 0 ? '—' : _money(cell(p, m)),
                                    textAlign: TextAlign.end,
                                  ),
                          ),
                        Padding(
                          padding: const EdgeInsets.all(8),
                          child: Text(
                            _money(
                              _months.fold<num>(0, (a, m) => a + cell(p, m)),
                            ),
                            key: ValueKey('spend-total-$p'),
                            textAlign: TextAlign.end,
                            style: theme.textTheme.titleSmall,
                          ),
                        ),
                        if (_hires case final hires?) ...[
                          Padding(
                            padding: const EdgeInsets.all(8),
                            child: Text(
                              '${hires[p] ?? 0}',
                              key: ValueKey('spend-hires-$p'),
                              textAlign: TextAlign.end,
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.all(8),
                            child: Text(
                              (hires[p] ?? 0) == 0
                                  ? 'no hires'
                                  : _money(
                                      _months.fold<num>(
                                            0,
                                            (a, m) => a + cell(p, m),
                                          ) /
                                          hires[p]!,
                                    ),
                              key: ValueKey('spend-per-hire-$p'),
                              textAlign: TextAlign.end,
                            ),
                          ),
                        ],
                      ],
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// One month's spend for a position, saved when the box is left.
class _AmountCell extends StatefulWidget {
  const _AmountCell({super.key, required this.value, required this.onSave});

  final num value;
  final void Function(String) onSave;

  @override
  State<_AmountCell> createState() => _AmountCellState();
}

class _AmountCellState extends State<_AmountCell> {
  late final _text = TextEditingController(text: _show(widget.value));
  final _focus = FocusNode();

  static String _show(num v) => v == 0 ? '' : '${v.round()}';

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus && _text.text.trim() != _show(widget.value)) {
        widget.onSave(_text.text.trim());
      }
    });
  }

  @override
  void didUpdateWidget(_AmountCell old) {
    super.didUpdateWidget(old);
    if (!_focus.hasFocus && old.value != widget.value) {
      _text.text = _show(widget.value);
    }
  }

  @override
  void dispose() {
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _text,
    focusNode: _focus,
    textAlign: TextAlign.end,
    keyboardType: const TextInputType.numberWithOptions(decimal: true),
    decoration: const InputDecoration(
      isDense: true,
      hintText: '—',
      prefixText: r'$',
      border: OutlineInputBorder(),
    ),
    onSubmitted: (_) => _focus.unfocus(),
  );
}
