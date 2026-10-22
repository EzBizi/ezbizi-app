import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

// Project Management › Reports and › Table (design 141, option 1; phone 4):
// Financials' weekly Repair Team report ("WhatsApp Shoval Report"), under
// /financials, for one branch (`franchise_id`).
//
// Reports: ‹ week ›, the Draft / Approved badge, Copy for WhatsApp (the
// server's `text`), Approve with financials.approve, a card per technician
// and the teams combined, cost from gross red above `cost_alert_percent`
// from the Project Management overview, and the average production cost by
// month, place and team.
//
// Table: one row per team per week, a cell per field; the fields the server
// lists in `missing` are amber, counted, and filterable; the `editable`
// fields take a typed value with financials.edit, which reopens an approved
// week, said inline before the save.

/// The Monday a week starts on, as Financials counts weeks.
DateTime pmWeekStart(DateTime day) {
  final d = DateTime(day.year, day.month, day.day);
  return d.subtract(Duration(days: d.weekday - DateTime.monday));
}

String pmYmd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

/// One report field: the key `missing[]` and `editable` use, its name, and
/// its words for a card (null: no value).
typedef PmReportField = (String, String, String? Function(Map card));

String? _money(Object? minor) => minor is int ? formatMoney(minor) : null;

String _pct(num v) {
  final n = v.toDouble();
  return '${n == n.roundToDouble() ? n.toStringAsFixed(0) : n.toStringAsFixed(1)}%';
}

String? _share(Object? share) {
  if (share is! Map || share['cents'] is! int) return null;
  final pct = share['pct'];
  return [
    formatMoney(share['cents'] as int),
    if (pct is num) _pct(pct),
  ].join(' · ');
}

String? _hours(Object? h) =>
    h is num ? (h == h.roundToDouble() ? h.toStringAsFixed(0) : '$h') : null;

/// The fields in the report's order.
final List<PmReportField> pmReportFields = [
  ('hours', 'Total hours', (c) => _hours(c['hours'])),
  ('paycheck', 'Paycheck', (c) => _money(c['paycheck_cents'])),
  ('helper', 'Helper', (c) => _money(c['helper_cents'])),
  ('operation', 'Operation', (c) => _money(c['operation_cents'])),
  ('gas', 'Gas', (c) => _money(c['gas_cents'])),
  ('parts', 'Parts', (c) => _money(c['parts_cents'])),
  ('total_cost', 'Total cost', (c) => _money(c['total_cost_cents'])),
  ('tech_share', 'Tech share', (c) => _share(c['tech_share'])),
  ('external_share', 'External share', (c) => _share(c['external_share'])),
  ('contracting', 'Contracting', (c) => _share(c['contracting_profit'])),
  ('company_share', 'Company share', (c) => _share(c['company_share'])),
  ('jobs', 'Jobs done', (c) => c['jobs'] is int ? '${c['jobs']}' : null),
  ('jobs_amount', 'Jobs amount', (c) => _money(c['jobs_amount_cents'])),
  (
    'cost_from_gross',
    'Cost from gross',
    (c) => c['cost_from_gross_pct'] is num
        ? _pct(c['cost_from_gross_pct'] as num)
        : null,
  ),
];

/// A field's value as the editor shows it: dollars, or hours.
String pmEditText(Map card, String field) {
  final v = switch (field) {
    'hours' => card['hours'],
    'tech_share' => (card['tech_share'] as Map?)?['cents'],
    'external_share' => (card['external_share'] as Map?)?['cents'],
    'contracting' => (card['contracting_profit'] as Map?)?['cents'],
    _ => card['${field}_cents'],
  };
  if (v is! num) return '';
  if (field == 'hours') return _hours(v)!;
  final dollars = v / 100;
  return dollars == dollars.roundToDouble()
      ? dollars.toStringAsFixed(0)
      : dollars.toStringAsFixed(2);
}

List<String> _missing(Map card) => [
  for (final m in (card['missing'] as List?) ?? const []) '$m',
];

/// Whether cost from gross is over the alert.
bool pmCostHigh(Map card, int? alert) {
  final v = card['cost_from_gross_pct'];
  return alert != null && v is num && v > alert;
}

/// The branch the report is for: the view's one branch, else a picker over
/// the branches in view.
mixin _RepairPlace<T extends StatefulWidget> on State<T> {
  Session get session;
  Me get me => session.me!;

  late String? place = me.franchisesInView.length == 1
      ? me.franchisesInView.single.id
      : me.franchisesInView.firstOrNull?.id;

  Widget? placePicker(VoidCallback onChanged) {
    final branches = me.franchisesInView;
    if (branches.length < 2) return null;
    return SizedBox(
      width: 240,
      child: SearchPickerField<String>(
        key: const ValueKey('pm-report-branch'),
        value: place,
        isDense: true,
        options: [
          for (final b in branches) SearchOption(value: b.id, label: b.name),
        ],
        onChanged: (v) {
          if (v == null || v == place) return;
          setState(() => place = v);
          onChanged();
        },
      ),
    );
  }

  Widget noPlace(BuildContext context) => Padding(
    padding: const EdgeInsets.all(24),
    child: Text(
      'No branch is in view.',
      key: const ValueKey('pm-report-no-branch'),
      style: Theme.of(context).textTheme.bodyMedium,
    ),
  );
}

/// Reports: the week's cards, and the production cost.
class ProjectManagementReports extends StatefulWidget {
  const ProjectManagementReports({
    super.key,
    required this.api,
    required this.session,
    this.now,
  });

  final ApiClient api;
  final Session session;
  final DateTime Function()? now;

  @override
  State<ProjectManagementReports> createState() =>
      _ProjectManagementReportsState();
}

class _ProjectManagementReportsState extends State<ProjectManagementReports>
    with _RepairPlace {
  @override
  Session get session => widget.session;

  DateTime _today() => (widget.now ?? DateTime.now)();

  /// Last week: the one just finished.
  late DateTime _week = pmWeekStart(_today()).subtract(const Duration(days: 7));

  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _report;

  /// The place has no repair sheet for this week.
  bool _empty = false;
  List<Map<String, dynamic>> _production = const [];
  int? _alert;
  int _request = 0;
  bool _approving = false;
  String? _approveError;

  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (place == null) return setState(() => _loading = false);
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    final today = _today();
    final results = await Future.wait([
      widget.api.get('/financials/repair-report', {
        'franchise_id': place!,
        'week_start': pmYmd(_week),
      }),
      widget.api.get('/financials/production-cost', {
        'from': pmYmd(DateTime(today.year, today.month - 2, 1)),
        'to': pmYmd(today),
        'franchise_id': place!,
      }),
      if (_alert == null) widget.api.get('/jobs/project-management/overview'),
    ]);
    if (!mounted || request != _request) return;
    final [report, production, ...rest] = results;
    setState(() {
      _loading = false;
      if (rest.isNotEmpty && rest.first.ok && rest.first.data is Map) {
        final a = (rest.first.data as Map)['cost_alert_percent'];
        if (a is int) _alert = a;
      }
      if (!report.ok) {
        _error = report.message;
        return;
      }
      // With a franchise_id, data[] is that one place.
      final places = report.data;
      _report = places is List
          ? places.whereType<Map>().firstOrNull?.cast<String, dynamic>()
          : null;
      _empty = _report == null;
      _production = production.ok && production.data is List
          ? [
              for (final r in production.data as List)
                if (r is Map) r.cast<String, dynamic>(),
            ]
          : const [];
    });
  }

  void _shift(int weeks) {
    setState(() => _week = _week.add(Duration(days: 7 * weeks)));
    _load();
  }

  Future<void> _approve() async {
    setState(() {
      _approving = true;
      _approveError = null;
    });
    final response = await widget.api.post(
      '/financials/repair-report/approve',
      {'franchise_id': place, 'week_start': pmYmd(_week)},
    );
    if (!mounted) return;
    setState(() {
      _approving = false;
      if (!response.ok) {
        _approveError = response.message;
        return;
      }
      if (response.data is Map) {
        _report = (response.data as Map).cast<String, dynamic>();
      }
    });
  }

  Future<void> _copy() async {
    final text = _report?['text'];
    if (text is! String) return;
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Copied for WhatsApp')));
  }

  @override
  Widget build(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 600;
    final pad = phone ? 16.0 : 24.0;
    final picker = placePicker(_load);

    return ListView(
      key: const ValueKey('pm-reports'),
      padding: EdgeInsets.fromLTRB(pad, 8, pad, 24),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ?picker,
            _weekPicker(context),
            SizedBox(
              width: phone ? double.infinity : 240,
              child: TextField(
                key: const ValueKey('pm-reports-search'),
                controller: _search,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  isDense: true,
                  prefixIcon: Icon(Icons.search, size: 18),
                  hintText: 'Search technicians',
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (place == null)
          noPlace(context)
        else
          AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _report == null
                ? Text(
                    _empty ? 'No repair report for this place this week.' : '',
                    key: const ValueKey('pm-reports-empty'),
                  )
                : _body(context, phone: phone),
          ),
      ],
    );
  }

  Widget _weekPicker(BuildContext context) {
    final theme = Theme.of(context);
    final formats = me.display;
    final end = _week.add(const Duration(days: 6));
    return Row(
      key: const ValueKey('tour-pm-report-week'),
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          key: const ValueKey('pm-week-back'),
          tooltip: 'The week before',
          onPressed: _loading ? null : () => _shift(-1),
          icon: const Icon(Icons.chevron_left),
        ),
        Flexible(
          child: Text(
            '${formats.formatDate(_week)} – ${formats.formatDate(end)}',
            key: const ValueKey('pm-week-label'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        IconButton(
          key: const ValueKey('pm-week-next'),
          tooltip: 'The week after',
          onPressed: _loading || !_week.isBefore(pmWeekStart(_today()))
              ? null
              : () => _shift(1),
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }

  bool _matches(Map t) {
    final q = _search.text.trim().toLowerCase();
    return q.isEmpty || '${t['technician'] ?? ''}'.toLowerCase().contains(q);
  }

  Widget _body(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final r = _report!;
    final approved = r['week_status'] == 'approved';
    final at = r['approved_at'] is String
        ? DateTime.tryParse(r['approved_at'] as String)?.toLocal()
        : null;
    final techs = [
      for (final t in (r['technicians'] as List?) ?? const [])
        if (t is Map && _matches(t)) t,
    ];
    final combined = r['combined'];
    final cardWidth = phone ? double.infinity : 250.0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 10,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              '${r['place'] ?? ''} · Weekly',
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            StatusPill(
              approved ? 'Approved' : 'Unapproved draft',
              key: const ValueKey('pm-report-status'),
              tone: approved ? Tone.ok : Tone.caution,
            ),
            if (approved)
              Text(
                [
                  if (r['approved_by'] is String)
                    'Approved by ${r['approved_by']}',
                  if (at != null) me.display.formatDate(at),
                ].join(' · '),
                key: const ValueKey('pm-report-approved-by'),
                style: muted,
              ),
            OutlinedButton.icon(
              key: const ValueKey('pm-report-copy'),
              onPressed: r['text'] is String ? _copy : null,
              icon: const Icon(Icons.content_copy, size: 16),
              label: const Text('Copy for WhatsApp'),
            ),
            if (!approved && me.can('financials.approve'))
              FilledButton(
                key: const ValueKey('pm-report-approve'),
                onPressed: _approving ? null : _approve,
                child: const Text('Approve'),
              ),
          ],
        ),
        if (_approveError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _approveError!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        const SizedBox(height: 12),
        if ((r['technicians'] as List?)?.isEmpty ?? true)
          Text(
            'No repair jobs this week.',
            key: const ValueKey('pm-reports-none'),
            style: muted,
          )
        else
          Wrap(
            key: const ValueKey('tour-pm-report-cards'),
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final t in techs)
                SizedBox(
                  width: cardWidth,
                  child: _card(
                    context,
                    '${t['technician'] ?? ''}',
                    t,
                    id: 'pm-report-card-${t['technician']}',
                  ),
                ),
              if (combined is Map)
                SizedBox(
                  width: cardWidth,
                  child: _card(
                    context,
                    'All repair teams combined (${combined['count'] ?? 0})',
                    combined,
                    id: 'pm-report-combined',
                    strong: true,
                  ),
                ),
            ],
          ),
        const SizedBox(height: 24),
        _productionTable(context),
      ],
    );
  }

  Widget _card(
    BuildContext context,
    String title,
    Map card, {
    required String id,
    bool strong = false,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final high = pmCostHigh(card, _alert);
    final bad = ToneColours.of(context, Tone.bad).ink;
    final caution = ToneColours.of(context, Tone.caution);
    final missing = _missing(card);

    return DecoratedBox(
      key: ValueKey(id),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(
          color: strong ? scheme.primary : scheme.outlineVariant,
          width: strong ? 2 : 1,
        ),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            for (final (field, label, words) in pmReportFields)
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Row(
                  children: [
                    Expanded(child: Text(label, style: muted)),
                    Text(
                      words(card) ??
                          (missing.contains(field) ? 'missing' : '—'),
                      key: field == 'cost_from_gross'
                          ? ValueKey('$id-gross')
                          : null,
                      style: field == 'cost_from_gross' && high
                          ? TextStyle(color: bad, fontWeight: FontWeight.w800)
                          : missing.contains(field)
                          ? TextStyle(
                              color: caution.ink,
                              fontWeight: FontWeight.w700,
                            )
                          : theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// Average production cost per job, by month, place and team.
  Widget _productionTable(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: scheme.onSurfaceVariant,
      letterSpacing: 0.4,
    );
    Widget row(List<String> cells, {TextStyle? style, Key? key}) => Container(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
        ),
      ),
      child: Row(
        children: [
          for (final (i, c) in cells.indexed)
            Expanded(
              flex: i < 3 ? 3 : 2,
              child: Text(
                c,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: style,
              ),
            ),
        ],
      ),
    );

    return Column(
      key: const ValueKey('pm-production'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Average production cost per job',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        Text(
          'Tech, helper, operation, gas and parts, the last three months.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: scheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 8),
        if (_production.isEmpty)
          Text(
            'No production cost in these months.',
            key: const ValueKey('pm-production-none'),
            style: theme.textTheme.bodySmall,
          )
        else
          DecoratedBox(
            decoration: BoxDecoration(
              color: scheme.surface,
              border: Border.all(color: scheme.outlineVariant),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                row(['MONTH', 'PLACE', 'TEAM', 'JOBS', 'AVG'], style: head),
                for (final p in _production)
                  row([
                    '${p['month'] ?? ''}',
                    '${p['place'] ?? ''}',
                    '${p['team'] ?? ''}',
                    p['jobs'] is int ? formatCount(p['jobs'] as int) : '—',
                    _money(p['avg_cents']) ?? '—',
                  ], key: ValueKey('pm-production-${p['month']}-${p['team']}')),
              ],
            ),
          ),
      ],
    );
  }
}

/// Table: one row per team per week, a column per field.
class ProjectManagementReportTable extends StatefulWidget {
  const ProjectManagementReportTable({
    super.key,
    required this.api,
    required this.session,
    this.now,
  });

  final ApiClient api;
  final Session session;
  final DateTime Function()? now;

  @override
  State<ProjectManagementReportTable> createState() =>
      _ProjectManagementReportTableState();
}

class _ProjectManagementReportTableState
    extends State<ProjectManagementReportTable>
    with _RepairPlace {
  @override
  Session get session => widget.session;

  static const _rowHeight = 44.0;
  static const _nameWidth = 210.0;
  static const _cellWidth = 120.0;

  /// Eight weeks at a time, ending with this one.
  static const _span = 8;

  DateTime _today() => (widget.now ?? DateTime.now)();
  late DateTime _from = pmWeekStart(
    _today(),
  ).subtract(const Duration(days: 7 * (_span - 1)));
  DateTime get _to => _from.add(const Duration(days: 7 * _span - 1));

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  List<String> _editable = const [];
  int _request = 0;

  final _search = TextEditingController();
  bool _gapsOnly = false;

  /// The cell being typed into: week, team and field.
  ({String week, String team, String field})? _editing;
  final _edit = TextEditingController();
  String? _editError;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _edit.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (place == null) return setState(() => _loading = false);
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get('/financials/repair-report/table', {
      'franchise_id': place!,
      'from': pmYmd(_from),
      'to': pmYmd(_to),
    });
    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data;
      _rows = data is List
          ? [
              for (final r in data)
                if (r is Map) r.cast<String, dynamic>(),
            ]
          : const [];
      final editable = response.body['editable'];
      _editable = editable is List
          ? [for (final e in editable) '$e']
          : const [];
    });
  }

  void _shift(int spans) {
    setState(() {
      _from = _from.add(Duration(days: 7 * _span * spans));
      _editing = null;
    });
    _load();
  }

  bool get _mayEdit => me.can('financials.edit');

  void _startEdit(Map<String, dynamic> row, String field) {
    setState(() {
      _editing = (
        week: '${row['week_start']}',
        team: '${row['technician']}',
        field: field,
      );
      _edit.text = pmEditText(row, field);
      _editError = null;
    });
  }

  Future<void> _saveEdit() async {
    final e = _editing;
    if (e == null) return;
    final text = _edit.text.trim();
    final value = text.isEmpty ? null : double.tryParse(text);
    if (text.isNotEmpty && (value == null || value < 0)) {
      setState(() => _editError = 'A number, or empty to clear it.');
      return;
    }
    setState(() {
      _saving = true;
      _editError = null;
    });
    final response = await widget.api.patch('/financials/repair-report/value', {
      'franchise_id': place,
      'week_start': e.week,
      'team': e.team,
      'field': e.field,
      'value': value,
    });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (!response.ok) {
        _editError = response.message;
        return;
      }
      _editing = null;
      final week = response.data;
      if (week is Map) _adoptWeek(week);
    });
  }

  /// The week the server answered replaces that week's rows.
  void _adoptWeek(Map week) {
    final from = '${week['from']}';
    final fresh = [
      for (final t in (week['technicians'] as List?) ?? const [])
        if (t is Map)
          <String, dynamic>{
            'week_start': week['from'],
            'week_end': week['to'],
            'week_status': week['week_status'],
            ...t.cast<String, dynamic>(),
          },
    ];
    final at = _rows.indexWhere((r) => '${r['week_start']}' == from);
    _rows = [
      for (final r in _rows)
        if ('${r['week_start']}' != from) r,
    ]..insertAll(at < 0 ? _rows.length : at, fresh);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final phone = MediaQuery.sizeOf(context).width < 600;
    final pad = phone ? 16.0 : 24.0;
    final missing = _rows.fold<int>(0, (a, r) => a + _missing(r).length);
    final q = _search.text.trim().toLowerCase();
    final rows = [
      for (final r in _rows)
        if ((!_gapsOnly || _missing(r).isNotEmpty) &&
            (q.isEmpty || '${r['technician'] ?? ''}'.toLowerCase().contains(q)))
          r,
    ];
    final caution = ToneColours.of(context, Tone.caution);
    final picker = placePicker(_load);
    final formats = me.display;
    final editingRow = _editing == null
        ? null
        : _rows
              .where(
                (r) =>
                    '${r['week_start']}' == _editing!.week &&
                    '${r['technician']}' == _editing!.team,
              )
              .firstOrNull;

    return ListView(
      key: const ValueKey('pm-table'),
      padding: EdgeInsets.fromLTRB(pad, 8, pad, 24),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ?picker,
            Row(
              key: const ValueKey('tour-pm-report-week'),
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  key: const ValueKey('pm-week-back'),
                  tooltip: 'Earlier weeks',
                  onPressed: _loading ? null : () => _shift(-1),
                  icon: const Icon(Icons.chevron_left),
                ),
                Flexible(
                  child: Text(
                    '${formats.formatDate(_from)} – ${formats.formatDate(_to)}',
                    key: const ValueKey('pm-week-label'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                IconButton(
                  key: const ValueKey('pm-week-next'),
                  tooltip: 'Later weeks',
                  onPressed: _loading || !_to.isBefore(_today())
                      ? null
                      : () => _shift(1),
                  icon: const Icon(Icons.chevron_right),
                ),
              ],
            ),
            if (!_loading && _error == null)
              StatusPill(
                missing == 0
                    ? 'No fields missing'
                    : '${formatCount(missing)} '
                          '${missing == 1 ? 'field' : 'fields'} missing',
                key: const ValueKey('pm-table-missing'),
                tone: missing == 0 ? Tone.ok : Tone.caution,
              ),
            FilterChip(
              key: const ValueKey('pm-table-gaps'),
              selected: _gapsOnly,
              label: const Text('Show only rows with gaps'),
              onSelected: (v) => setState(() => _gapsOnly = v),
            ),
            SizedBox(
              width: phone ? double.infinity : 240,
              child: TextField(
                key: const ValueKey('pm-table-search'),
                controller: _search,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  isDense: true,
                  prefixIcon: Icon(Icons.search, size: 18),
                  hintText: 'Search technicians',
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (place == null)
          noPlace(context)
        else
          AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_editing != null) _editor(context, editingRow),
                if (rows.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    child: Text(
                      _rows.isEmpty
                          ? 'No repair report in these weeks.'
                          : 'No rows match.',
                      key: const ValueKey('pm-table-none'),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  )
                else
                  _grid(context, rows, caution),
              ],
            ),
          ),
      ],
    );
  }

  /// The value being typed, with the warning an approved week needs.
  Widget _editor(BuildContext context, Map<String, dynamic>? row) {
    final theme = Theme.of(context);
    final e = _editing!;
    final label = pmReportFields.firstWhere((f) => f.$1 == e.field).$2;
    final approved = row?['week_status'] == 'approved';
    return Padding(
      key: const ValueKey('pm-table-editor'),
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (approved)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: StatusPill(
                'The week of ${e.week} is approved. Saving reopens it as a '
                'draft.',
                key: const ValueKey('pm-table-reopen-warning'),
                tone: Tone.caution,
              ),
            ),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${e.team} · $label · week of ${e.week}',
                style: theme.textTheme.bodyMedium,
              ),
              SizedBox(
                width: 140,
                child: TextField(
                  key: const ValueKey('pm-table-edit'),
                  controller: _edit,
                  autofocus: true,
                  enabled: !_saving,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  onSubmitted: (_) => _saveEdit(),
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: e.field == 'hours' ? 'Hours' : 'Dollars',
                    errorText: _editError,
                  ),
                ),
              ),
              FilledButton(
                key: const ValueKey('pm-table-edit-save'),
                onPressed: _saving ? null : _saveEdit,
                child: const Text('Save'),
              ),
              TextButton(
                key: const ValueKey('pm-table-edit-cancel'),
                onPressed: _saving
                    ? null
                    : () => setState(() => _editing = null),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _grid(
    BuildContext context,
    List<Map<String, dynamic>> rows,
    ToneColours caution,
  ) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: scheme.onSurfaceVariant,
      letterSpacing: 0.4,
    );
    Widget cell(
      Widget child,
      double width, {
      Color? fill,
      Key? key,
      VoidCallback? onTap,
    }) {
      final box = Container(
        width: width,
        height: _rowHeight,
        alignment: Alignment.centerLeft,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        decoration: BoxDecoration(
          color: fill,
          border: Border(
            bottom: BorderSide(color: scheme.outlineVariant, width: 0.5),
          ),
        ),
        child: child,
      );
      return onTap == null
          ? KeyedSubtree(key: key, child: box)
          : InkWell(key: key, onTap: onTap, child: box);
    }

    // The name column stays put; the fields scroll sideways beside it.
    return DecoratedBox(
      key: const ValueKey('tour-pm-table-grid'),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: _nameWidth,
              child: Column(
                children: [
                  cell(Text('WEEK · TECH', style: head), _nameWidth),
                  for (final r in rows)
                    cell(
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${r['week_start']} · ${r['technician'] ?? ''}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          if (r['week_status'] == 'approved')
                            const Icon(Icons.verified_outlined, size: 16),
                        ],
                      ),
                      _nameWidth,
                    ),
                ],
              ),
            ),
            Expanded(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Column(
                  children: [
                    Row(
                      children: [
                        for (final (_, label, _) in pmReportFields)
                          cell(
                            Text(label.toUpperCase(), style: head),
                            _cellWidth,
                          ),
                      ],
                    ),
                    for (final r in rows)
                      Row(
                        children: [
                          for (final (field, _, words) in pmReportFields)
                            Builder(
                              builder: (context) {
                                final text = words(r);
                                final gap = _missing(r).contains(field);
                                final id =
                                    '${r['week_start']}-${r['technician']}-$field';
                                final editable =
                                    _mayEdit && _editable.contains(field);
                                return cell(
                                  Text(
                                    text ?? (gap ? 'missing' : '—'),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: gap
                                        ? TextStyle(
                                            color: caution.ink,
                                            fontWeight: FontWeight.w700,
                                          )
                                        : theme.textTheme.bodySmall,
                                  ),
                                  _cellWidth,
                                  fill: gap ? caution.fill : null,
                                  key: ValueKey(
                                    gap
                                        ? 'pm-table-missing-$id'
                                        : 'pm-table-cell-$id',
                                  ),
                                  onTap: editable
                                      ? () => _startEdit(r, field)
                                      : null,
                                );
                              },
                            ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The average production cost per job over [rows], or null with no jobs.
int? pmAverageCost(List rows) {
  var cost = 0;
  var jobs = 0;
  for (final r in rows) {
    if (r is Map && r['jobs'] is int && r['cost_cents'] is int) {
      jobs += r['jobs'] as int;
      cost += r['cost_cents'] as int;
    }
  }
  return jobs == 0 ? null : cost ~/ jobs;
}
