import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/list_table.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'report_dates.dart';

/// One report a person can open in the workspace.
class WorkspaceReport {
  const WorkspaceReport(this.key, this.label, {this.text = false});

  /// period / job_profitability / meta, or a daily text's kind.
  final String key;
  final String label;
  final bool text;
}

/// Pick a report, preview it, export, approve or send it (takeover row 10,
/// UI queue 157, Jose 2026-10-29: options 1 + 4). The list on the left, the
/// paged preview in the middle, the actions on the right; on a phone the list
/// first, then the report with its actions under the preview.
class ReportWorkspace extends StatefulWidget {
  const ReportWorkspace({
    super.key,
    required this.api,
    required this.session,
    this.now,
  });

  final ApiClient api;
  final Session session;
  final DateTime Function()? now;

  static const tables = [
    WorkspaceReport('period', 'Period'),
    WorkspaceReport('job_profitability', 'Job profitability'),
    WorkspaceReport('meta', 'Meta ad spend'),
  ];

  static const texts = [
    WorkspaceReport('job_assignments', 'Job assignments', text: true),
    WorkspaceReport('ppc', 'PPC', text: true),
    WorkspaceReport('marketing_ppc', 'Marketing PPC', text: true),
    WorkspaceReport('dispatchers', 'Dispatchers', text: true),
    WorkspaceReport('technician_sales', 'Technician sales', text: true),
    WorkspaceReport('weekly_losses', 'Weekly losses', text: true),
    WorkspaceReport('weekly_reviews', 'Weekly reviews', text: true),
  ];

  static const rowsPerPage = 25;

  @override
  State<ReportWorkspace> createState() => _ReportWorkspaceState();
}

class _ReportWorkspaceState extends State<ReportWorkspace> {
  WorkspaceReport? _open;
  String _query = '';
  String? _branch;
  String _period = 'week';
  late DateSpan _span = _lastWeek;

  Map<String, dynamic>? _data;
  Map<String, dynamic>? _batch;
  String? _error;
  String? _notice;
  bool _busy = false;
  int _page = 0;

  Me? get _me => widget.session.me;

  bool get _orgView => _me?.scope.type == 'organization';

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

  DateSpan get _lastWeek {
    final monday = _today.subtract(Duration(days: _today.weekday - 1));
    return DateSpan(
      monday.subtract(const Duration(days: 7)),
      monday.subtract(const Duration(days: 1)),
    );
  }

  @override
  void initState() {
    super.initState();
    // Outside the organization view a table needs a branch: the server
    // refuses the whole organization there.
    if (!_orgView) _branch = _me?.franchisesInView.firstOrNull?.id;
  }

  void _choose(WorkspaceReport r) {
    setState(() {
      _open = r;
      _data = null;
      _batch = null;
      _error = null;
      _notice = null;
      _page = 0;
      if (r.text) _span = DateSpan.single(_today);
    });
    _load();
  }

  void _setPeriod(String period) {
    setState(() {
      _period = period;
      _span = switch (period) {
        'month' => DateSpan(
          DateTime(_today.year, _today.month - 1),
          DateTime(_today.year, _today.month, 0),
        ),
        'year' => DateSpan(DateTime(_today.year), _today),
        _ => _lastWeek,
      };
    });
    _load();
  }

  Map<String, String> get _range => {
    'from': formatCalendarDay(_span.from),
    'to': formatCalendarDay(_span.to),
  };

  Future<void> _load() async {
    final open = _open;
    if (open == null) return;
    setState(() => _busy = true);
    final ApiResponse response;
    if (open.text) {
      response = await widget.api.get('/reports/texts/${open.key}', {
        'date': formatCalendarDay(_span.to),
        'franchise_id': ?_branch,
      });
    } else if (open.key == 'meta') {
      response = await widget.api.get('/reports/meta', _range);
    } else {
      response = await widget.api.get('/reports/period', {
        ..._range,
        'franchise_id': ?_branch,
        'format': 'json',
        'report': open.key,
      });
    }
    ApiResponse? batch;
    if (open.key == 'period' && _period != 'year') {
      batch = await widget.api.get('/reports/batches/$_period');
    }
    if (!mounted || _open != open) return;
    setState(() {
      _busy = false;
      _page = 0;
      if (response.ok) {
        _data = (response.data as Map).cast<String, dynamic>();
        _error = null;
      } else {
        _error = response.message;
      }
      _batch = batch != null && batch.ok && batch.data is Map
          ? (batch.data as Map).cast<String, dynamic>()
          : null;
    });
  }

  Future<void> _download(String format) async {
    final open = _open!;
    final response = await widget.api.getBytesResponse('/reports/period', {
      ..._range,
      'franchise_id': ?_branch,
      'format': format,
      'report': open.key,
    });
    if (!mounted) return;
    final bytes = response.bytes;
    if (bytes == null) {
      setState(
        () => _error = response.message ?? 'The file could not be made.',
      );
      return;
    }
    Files.download(
      '${open.label} ${_range['from']} to ${_range['to']}.$format',
      bytes,
    );
  }

  Future<void> _approve() async {
    final id = _batch?['id'];
    final response = await widget.api.post('/reports/batches/$id/approve', {});
    if (!mounted) return;
    setState(() => _notice = response.ok ? 'Approved. Sending now.' : null);
    if (!response.ok) setState(() => _error = response.message);
    _load();
  }

  Future<void> _sendNow() async {
    final me = _me;
    if (me == null) return;
    final response = await widget.api.post(
      '/reports/texts/${_open!.key}/send',
      {
        'user_ids': [me.id],
        'franchise_id': ?_branch,
        'date': formatCalendarDay(_span.to),
      },
    );
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        final sent = (response.data as Map?)?['sent'];
        _notice = sent == 0
            ? 'Nothing was sent: your profile has no phone number.'
            : 'Sent to your WhatsApp.';
        _error = null;
      } else {
        _error = response.message;
      }
    });
  }

  // ── pieces ──────────────────────────────────────────────────────────

  Widget _list(BuildContext context) {
    final theme = Theme.of(context);
    final q = _query.trim().toLowerCase();
    List<Widget> group(String title, List<WorkspaceReport> items) {
      final shown = [
        for (final r in items)
          if (q.isEmpty || r.label.toLowerCase().contains(q)) r,
      ];
      if (shown.isEmpty) return const [];
      return [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
          child: Text(
            title.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        for (final r in shown)
          ListTile(
            key: ValueKey('workspace-report-${r.key}'),
            dense: true,
            selected: _open?.key == r.key,
            title: Text(r.label),
            onTap: () => _choose(r),
          ),
      ];
    }

    return ListView(
      key: const ValueKey('workspace-list'),
      children: [
        Padding(
          padding: const EdgeInsets.all(8),
          child: ListSearch(
            key: const Key('workspace-search'),
            hint: 'Find a report',
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
        ...group('Tables', ReportWorkspace.tables),
        ...group('Daily texts', ReportWorkspace.texts),
      ],
    );
  }

  Widget _topBar(BuildContext context) {
    final open = _open!;
    final places = _me?.franchisesInView ?? const <FranchiseSummary>[];
    final companies = {
      for (final c in _me?.companies ?? const <CompanySummary>[]) c.id: c.name,
    };

    return Wrap(
      spacing: 12,
      runSpacing: 12,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (open.key != 'meta')
          SizedBox(
            width: 280,
            child: SearchPickerField<String?>(
              key: const ValueKey('workspace-place'),
              label: 'Place',
              isDense: true,
              value: _branch,
              options: [
                if (_orgView)
                  const SearchOption<String?>(
                    value: null,
                    label: 'HQ · whole organization',
                  ),
                for (final f in places)
                  SearchOption<String?>(
                    value: f.id,
                    label: [?companies[f.companyId], f.name].join(' · '),
                  ),
              ],
              onChanged: (v) {
                setState(() => _branch = v);
                _load();
              },
            ),
          ),
        if (open.key == 'period')
          SegmentedButton<String>(
            key: const ValueKey('workspace-period'),
            segments: const [
              ButtonSegment(value: 'week', label: Text('Weekly')),
              ButtonSegment(value: 'month', label: Text('Monthly')),
              ButtonSegment(value: 'year', label: Text('Yearly')),
            ],
            selected: {_period},
            onSelectionChanged: (s) => _setPeriod(s.first),
          ),
        ReportDates(
          key: ValueKey('workspace-dates-${_span.from}-${_span.to}'),
          initial: _span,
          today: _today,
          formats: _me?.display ?? DisplayFormats.fallback,
          onChanged: (span) {
            setState(() => _span = span);
            _load();
          },
        ),
      ],
    );
  }

  Widget _preview(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data;
    if (_busy && data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (data == null) return const SizedBox.shrink();

    if (_open!.text) {
      // The daily text is WhatsApp's: *bold* drawn bold, not with its
      // asterisks (phone audit 2026-11-04, Section 4 #9).
      final text = '${data['text'] ?? ''}';
      return SelectableText.rich(
        TextSpan(
          children: [
            for (final (i, part)
                in text.split(RegExp(r'\*(?=\S)|(?<=\S)\*')).indexed)
              TextSpan(
                text: part,
                style: i.isOdd
                    ? const TextStyle(fontWeight: FontWeight.w700)
                    : null,
              ),
          ],
        ),
        key: const ValueKey('workspace-text'),
        style: theme.textTheme.bodyMedium,
      );
    }

    // Tables: each sheet, header row first; Meta's rows as maps.
    final sheets = <String, List<List<Object?>>>{};
    if (data['sheets'] case final Map s) {
      for (final e in s.entries) {
        sheets['${e.key}'] = [
          for (final r in e.value as List) List<Object?>.from(r as List),
        ];
      }
    } else if (data['rows'] case final List rows) {
      const keys = [
        'name',
        'state',
        'category',
        'branch',
        'spend_minor',
        'leads',
        'clicks',
      ];
      sheets['Campaigns'] = [
        ['Campaign', 'State', 'Category', 'Branch', 'Spend', 'Leads', 'Clicks'],
        for (final r in rows)
          [
            for (final k in keys)
              k == 'spend_minor'
                  ? ((r[k] as num? ?? 0) / 100).toStringAsFixed(2)
                  : r[k],
          ],
      ];
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final e in sheets.entries) ...[
          Text(e.key, style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          _pagedTable(context, e.value),
          const SizedBox(height: 16),
        ],
      ],
    );
  }

  Widget _pagedTable(BuildContext context, List<List<Object?>> rows) {
    if (rows.isEmpty) return const SizedBox.shrink();
    final body = rows.skip(1).toList();
    const per = ReportWorkspace.rowsPerPage;
    final pages = (body.length / per).ceil().clamp(1, 1 << 20);
    final page = _page.clamp(0, pages - 1);
    final shown = body.skip(page * per).take(per);
    String cell(Object? v) => v is double ? v.toStringAsFixed(2) : '${v ?? ''}';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ListTable(
          key: const Key('report-workspace-table'),
          columns: [for (final h in rows.first) ListColumn(cell(h))],
          rows: [
            for (final r in shown)
              [
                for (var i = 0; i < rows.first.length; i++)
                  Text(i < r.length ? cell(r[i]) : ''),
              ],
          ],
        ),
        if (pages > 1)
          Row(
            children: [
              IconButton(
                tooltip: 'Previous page',
                onPressed: page > 0
                    ? () => setState(() => _page = page - 1)
                    : null,
                icon: const Icon(Icons.chevron_left),
              ),
              Text('Page ${page + 1} of $pages'),
              IconButton(
                tooltip: 'Next page',
                onPressed: page < pages - 1
                    ? () => setState(() => _page = page + 1)
                    : null,
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
      ],
    );
  }

  Widget _actions(BuildContext context) {
    final theme = Theme.of(context);
    final open = _open!;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final batch = _batch;
    final step = '${batch?['step'] ?? ''}';
    final rules = [
      for (final r in (_data?['rules'] as List? ?? const []))
        (r as Map).cast<String, dynamic>(),
    ];

    Widget stepChip(String label, bool done) => Chip(
      avatar: Icon(
        done ? Icons.check_circle : Icons.radio_button_unchecked,
        size: 16,
      ),
      label: Text(label),
    );

    return Column(
      key: const ValueKey('workspace-actions'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (batch != null) ...[
          Text('Status', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              stepChip('Draft', step == 'draft' || step == 'sent'),
              stepChip('Approved', step == 'sent'),
              stepChip('Sent', step == 'sent'),
            ],
          ),
          Text(
            '${batch['starts_on']} – ${batch['ends_on']} · version ${batch['revision']}',
            style: muted,
          ),
          if (batch['can_approve'] == true) ...[
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('workspace-approve'),
              onPressed: _approve,
              child: const Text('Approve'),
            ),
          ],
          const SizedBox(height: 16),
        ],
        if (!open.text && open.key != 'meta') ...[
          Text('Export', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('workspace-pdf'),
            onPressed: () => _download('pdf'),
            icon: const Icon(Icons.picture_as_pdf_outlined),
            label: const Text('PDF'),
          ),
          if (_me?.can('reports.export') ?? false) ...[
            const SizedBox(height: 8),
            OutlinedButton.icon(
              key: const ValueKey('workspace-xlsx'),
              onPressed: () => _download('xlsx'),
              icon: const Icon(Icons.table_chart_outlined),
              label: const Text('Excel'),
            ),
          ],
        ],
        if (open.text) ...[
          Text('WhatsApp', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          FilledButton.icon(
            key: const ValueKey('workspace-send'),
            onPressed: _sendNow,
            icon: const Icon(Icons.send_outlined),
            label: const Text('Send to WhatsApp now'),
          ),
          Text('Sends it to you.', style: muted),
          const SizedBox(height: 16),
          Text('Scheduled', style: theme.textTheme.titleSmall),
          if (rules.isEmpty) Text('No rule sends this text.', style: muted),
          for (final r in rules)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('${r['name']}'),
              subtitle: Text(
                [
                  _when(r['schedule']),
                  if ((r['to'] as List?)?.isNotEmpty ?? false)
                    'to ${(r['to'] as List).join(', ')}',
                  if (r['active'] != true) 'off',
                ].join(' · '),
              ),
            ),
        ],
        if (_notice case final n?) ...[
          const SizedBox(height: 8),
          Text(n, style: muted),
        ],
      ],
    );
  }

  static String _when(Object? schedule) {
    if (schedule is! Map) return '';
    final days = (schedule['days'] as List?)
        ?.map((d) => '$d'.substring(0, 1).toUpperCase() + '$d'.substring(1, 3))
        .join(', ');
    return [
      schedule['kind'] == 'weekly' ? (days ?? 'Weekly') : 'Daily',
      '${schedule['time'] ?? ''}',
    ].join(' · ');
  }

  Widget _report(BuildContext context, {required bool wide}) {
    final error = _error;
    final preview = [
      _topBar(context),
      const SizedBox(height: 16),
      if (error != null) ...[ErrorNote(error), const SizedBox(height: 12)],
      _preview(context),
    ];

    if (!wide) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [...preview, const SizedBox(height: 24), _actions(context)],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: ListView(padding: const EdgeInsets.all(16), children: preview),
        ),
        const VerticalDivider(width: 1),
        SizedBox(
          width: 280,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [_actions(context)],
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final open = _open;
        // Phone: the list, then the report as the list's next page.
        if (constraints.maxWidth < 700) {
          if (open == null) return _list(context);
          return SidePanel(
            key: const ValueKey('workspace-phone-report'),
            title: open.label,
            docked: true,
            scrollable: false,
            onBack: () => setState(() => _open = null),
            child: _report(context, wide: false),
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 240, child: _list(context)),
            const VerticalDivider(width: 1),
            Expanded(
              child: open == null
                  ? Center(
                      child: Text(
                        'Pick a report on the left.',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    )
                  : _report(context, wide: constraints.maxWidth >= 1000),
            ),
          ],
        );
      },
    );
  }
}
