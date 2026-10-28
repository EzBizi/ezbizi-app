import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/list_table.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'jobs/job_detail_panel.dart';

/// Project Management - 2 (Jose, 2026-10-26): Project Management rebuilt from
/// scratch beside the first, to compare. Three tabs (2026-10-28): every job
/// done pending approval, the ones paid and ready to submit (with Submit,
/// which moves the job to Submitted here and in Workiz), and the submitted.
/// A row opens the job with its timeline. Money only with Financials: the
/// server leaves the keys out, and so do the columns.
class ProjectManagement2Screen extends StatefulWidget {
  const ProjectManagement2Screen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<ProjectManagement2Screen> createState() =>
      _ProjectManagement2ScreenState();
}

class _ProjectManagement2ScreenState extends State<ProjectManagement2Screen> {
  bool _loading = true;
  String? _error;
  bool _loaded = false;
  List<Map<String, dynamic>> _rows = const [];
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';
  // The server pages (50 a page) and searches; "Load more" fetches the next.
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;
  bool _fetching = false;
  String _tab = 'pending';
  Map<String, dynamic> _counts = const {};

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;
  bool get _canSubmit =>
      _tab == 'ready' && (widget.session.me?.can('jobs.edit') ?? false);

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

  Future<void> _load({int page = 1}) async {
    setState(() {
      _loading = true;
      _fetching = true;
    });
    final q = _q;
    final tab = _tab;
    final response = await widget.api.get('/project-management-2/jobs', {
      'tab': tab,
      'page': '$page',
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted || q != _q || tab != _tab) return;
    setState(() {
      _loading = false;
      _fetching = false;
      _loaded = true;
      _error = response.ok ? null : response.message;
      if (!response.ok) return;
      final rows = ((response.body['data'] as List?) ?? const [])
          .map((r) => (r as Map).cast<String, dynamic>())
          .toList();
      _rows = page == 1 ? rows : [..._rows, ...rows];
      final meta = response.body['meta'] as Map?;
      _total = meta?['total'] as int? ?? _rows.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _counts = (meta?['counts'] as Map?)?.cast<String, dynamic>() ?? _counts;
      _page = page;
    });
  }

  void _switch(int i) {
    setState(() {
      _tab = _tabs[i].$1;
      _rows = const [];
    });
    _load();
  }

  /// Ready for Submission's button: asked once, then the job is Submitted
  /// and leaves this tab for the third.
  Future<void> _submit(Map<String, dynamic> row) async {
    final go = await showSidePanel<bool>(
      context,
      builder: (c) => SidePanel(
        title: 'Submit job ${row['job_number'] ?? ''}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(c).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('pm2-submit-confirm'),
            onPressed: () => Navigator.of(c).pop(true),
            child: const Text('Submit'),
          ),
        ],
        child: Text(
          '${row['customer'] ?? 'This job'} moves from done pending approval '
          'to Submitted, here and in Workiz.',
        ),
      ),
    );
    if (go != true || !mounted) return;
    final response = await widget.api.post(
      '/project-management-2/jobs/${row['id']}/submit',
      const {},
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          response.ok
              ? 'Job ${row['job_number'] ?? ''} is Submitted. Workiz follows in a few seconds.'
              : response.message,
        ),
      ),
    );
    _load();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  /// The company under the branch only when the list spans several, where
  /// two companies can each have an "Ohio".
  bool get _manyCompanies => _rows.map((r) => r['company']).toSet().length > 1;

  String _date(Object? ymd) => _day(ymd, widget.session);

  void _open(Map<String, dynamic> row) => showSidePanel<void>(
    context,
    builder: (_) =>
        _JobPanel(api: widget.api, session: widget.session, row: row),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;

    return AsyncBody(
      // Only the first load blanks the screen, so the search box keeps focus.
      loading: _loading && !_loaded,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TabBarLike(
            key: const Key('pm2-tabs'),
            labels: [
              for (final (key, label, icon) in _tabs)
                (
                  _counts[key] is int ? '$label · ${_counts[key]}' : label,
                  icon,
                ),
            ],
            selected: _tabs.indexWhere((t) => t.$1 == _tab),
            onSelected: _switch,
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 16,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${_heading[_tab]} · $_total jobs',
                style: theme.textTheme.titleMedium,
              ),
              SizedBox(
                width: 320,
                child: TextField(
                  key: const Key('pm2-search'),
                  controller: _search,
                  onChanged: _searched,
                  style: theme.textTheme.bodyMedium,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Customer, job ID or address',
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (rows.isEmpty && _q.isNotEmpty)
            Text('No job matches "$_q".')
          else if (rows.isEmpty)
            Text(_empty[_tab]!)
          else
            ListTable(
              rowKey: (i) => ValueKey('pm2-job:${rows[i]['id']}'),
              onTap: (i) => _open(rows[i]),
              columns: [
                const ListColumn('Job ID', flex: 2),
                const ListColumn('Customer', flex: 3),
                const ListColumn('Branch', flex: 2),
                const ListColumn('Status', flex: 3),
                const ListColumn('Tags', flex: 3),
                const ListColumn('Address', flex: 4),
                const ListColumn('Created', flex: 2),
                const ListColumn('Last modified', flex: 2),
                if (_money) ...[
                  for (final (label, _) in _moneyFields)
                    ListColumn(label, flex: 2),
                ],
                if (_canSubmit) const ListColumn('', flex: 2, end: true),
              ],
              rows: [
                for (final row in rows)
                  [
                    Text('${row['job_number'] ?? '—'}'),
                    Text(
                      '${row['customer'] ?? '—'}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${row['branch'] ?? '—'}'),
                        if (_manyCompanies && row['company'] != null)
                          Text(
                            '${row['company']}',
                            style: theme.textTheme.bodySmall,
                          ),
                      ],
                    ),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: StatusPill(
                        '${row['status']}',
                        tone: toneForJobStatus('${row['status']}'),
                      ),
                    ),
                    Wrap(
                      runSpacing: 4,
                      children: [
                        for (final tag in (row['tags'] as List? ?? const []))
                          Tag('$tag'),
                      ],
                    ),
                    Text(
                      '${row['address'] ?? '—'}',
                      style: theme.textTheme.bodySmall,
                    ),
                    Text(_date(row['created'])),
                    Text(_date(row['modified'])),
                    if (_money)
                      for (final (_, key) in _moneyFields)
                        Text(_amount(row, key)),
                    if (_canSubmit)
                      Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton.tonal(
                          key: ValueKey('pm2-submit:${row['id']}'),
                          onPressed: () => _submit(row),
                          child: const Text('Submit'),
                        ),
                      ),
                  ],
              ],
            ),
          if (_page < _lastPage)
            Center(
              child: TextButton(
                key: const Key('pm2-more'),
                onPressed: _fetching ? null : () => _load(page: _page + 1),
                child: Text(
                  _fetching
                      ? 'Loading…'
                      : 'Load more (${_total - rows.length} left)',
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Jose's three tabs (2026-10-28): the server's key, the label, the icon.
const _tabs = [
  ('pending', 'Pending', Icons.hourglass_empty),
  ('ready', 'Ready for Submission', Icons.task_alt),
  ('submitted', 'Submitted', Icons.send_outlined),
];

const _heading = {
  'pending': 'Done pending approval',
  'ready': 'Paid, ready to submit',
  'submitted': 'Submitted from done pending approval',
};

const _empty = {
  'pending': 'No jobs are done pending approval.',
  'ready': 'No job done pending approval has a payment yet.',
  'submitted': 'No job has gone from done pending approval to Submitted yet.',
};

/// Jose, 2026-10-27: "Pending, Paid, Total". Total is the price (Workiz's
/// invoice, else its estimate), Paid what came in less refunds, Pending
/// what is left. None of them where the job has no price.
const _moneyFields = [
  ('Pending', 'pending'),
  ('Paid', 'paid'),
  ('Total', 'total'),
];

String _day(Object? ymd, Session session) {
  final at = ymd is String ? DateTime.tryParse(ymd) : null;
  return at == null
      ? '—'
      : (session.me?.display ?? DisplayFormats.fallback).formatDate(at);
}

String _amount(Map<String, dynamic> row, String key) {
  final minor = row[key];
  return minor is int
      ? formatMoney(minor, currency: '${row['currency'] ?? 'USD'}')
      : '—';
}

/// One job, opened from a row: its fields, then everything that happened to
/// it, oldest first, from GET /project-management-2/jobs/{id}. The row draws
/// the fields until that answers; "Open the job" is the full job.
class _JobPanel extends StatefulWidget {
  const _JobPanel({
    required this.api,
    required this.session,
    required this.row,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> row;

  @override
  State<_JobPanel> createState() => _JobPanelState();
}

class _JobPanelState extends State<_JobPanel> {
  Map<String, dynamic>? _job;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    final response = await widget.api.get(
      '/project-management-2/jobs/${widget.row['id']}',
    );
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _job = (response.body['data'] as Map).cast<String, dynamic>();
      }
    });
  }

  Future<void> _openJob() async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => JobDetailPanel(
        api: widget.api,
        session: widget.session,
        id: '${widget.row['id']}',
      ),
    );
    if (changed == true && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final job = _job ?? widget.row;
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final money = widget.session.me?.can('jobs.financials') ?? false;
    final tags = job['tags'] as List? ?? const [];

    Widget field(String label, Widget value) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 110, child: Text(label, style: muted)),
          Expanded(child: value),
        ],
      ),
    );

    return SidePanel(
      title: '${job['customer'] ?? 'Job'}',
      subtitle: 'Job ${job['job_number'] ?? '—'} · ${job['branch'] ?? '—'}',
      actions: [
        OutlinedButton.icon(
          key: const Key('pm2-open-job'),
          onPressed: _openJob,
          icon: const Icon(Icons.open_in_new, size: 18),
          label: const Text('Open the job'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          field(
            'Status',
            Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(
                '${job['status']}',
                tone: toneForJobStatus('${job['status']}'),
              ),
            ),
          ),
          field(
            'Tags',
            tags.isEmpty
                ? const Text('—')
                : Wrap(
                    spacing: 4,
                    runSpacing: 4,
                    children: [for (final t in tags) Tag('$t')],
                  ),
          ),
          field('Address', Text('${job['address'] ?? '—'}')),
          field('Created', Text(_day(job['created'], widget.session))),
          field('Last modified', Text(_day(job['modified'], widget.session))),
          if (money)
            for (final (label, key) in _moneyFields)
              field(label, Text(_amount(job, key))),
          const Divider(height: 24),
          Text('Timeline', style: theme.textTheme.titleSmall),
          const SizedBox(height: 12),
          if (_error != null) ...[
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(onPressed: _load, child: const Text('Retry')),
            ),
          ] else if (_job == null)
            const LinearProgressIndicator()
          else
            for (final e in (_job!['timeline'] as List? ?? const []))
              if (e is Map)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Padding(
                        padding: const EdgeInsets.only(top: 6, right: 10),
                        child: Icon(
                          Icons.circle,
                          size: 8,
                          color: theme.colorScheme.primary,
                        ),
                      ),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('${e['what']}'),
                            Text(
                              [
                                formatStamp(e['at'], '—', formats: formats),
                                if (e['by'] is String) e['by'],
                                if (e['amount'] is int)
                                  formatMoney(
                                    e['amount'] as int,
                                    currency: '${job['currency'] ?? 'USD'}',
                                  ),
                              ].join(' · '),
                              style: muted,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
        ],
      ),
    );
  }
}
