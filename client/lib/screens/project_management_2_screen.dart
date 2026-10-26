import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/list_table.dart';
import '../widgets/status_pill.dart';
import '../widgets/tag.dart';

/// Project Management - 2 (Jose, 2026-10-26): Project Management rebuilt from
/// scratch beside the first, to compare. For now one list, every job done
/// pending approval, in his columns. Money only with Financials: the server
/// leaves the keys out, and so do the columns.
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
  List<Map<String, dynamic>> _rows = const [];

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final response = await widget.api.get('/project-management-2/jobs');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _rows = ((response.body['data'] as List?) ?? const [])
          .map((r) => (r as Map).cast<String, dynamic>())
          .toList();
    });
  }

  /// The company under the branch only when the list spans several, where
  /// two companies can each have an "Ohio".
  bool get _manyCompanies => _rows.map((r) => r['company']).toSet().length > 1;

  String _date(Object? ymd) {
    final at = ymd is String ? DateTime.tryParse(ymd) : null;
    return at == null
        ? '—'
        : (widget.session.me?.display ?? DisplayFormats.fallback).formatDate(
            at,
          );
  }

  String _amount(Map<String, dynamic> row, String key) {
    final minor = row[key];
    return minor is int
        ? formatMoney(minor, currency: '${row['currency'] ?? 'USD'}')
        : '—';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Done pending approval · ${_rows.length} jobs',
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 12),
          if (_rows.isEmpty)
            const Text('No jobs are done pending approval.')
          else
            ListTable(
              rowKey: (i) => ValueKey('pm2-job:${_rows[i]['id']}'),
              columns: [
                const ListColumn('Job ID', flex: 2),
                const ListColumn('Customer', flex: 3),
                const ListColumn('Branch', flex: 2),
                const ListColumn('Status', flex: 3),
                const ListColumn('Tags', flex: 3),
                const ListColumn('Address', flex: 4),
                const ListColumn('Created', flex: 2),
                const ListColumn('Last modified', flex: 2),
                if (_money) const ListColumn('Money pending', flex: 2),
                if (_money) const ListColumn('Money owed', flex: 2),
              ],
              rows: [
                for (final row in _rows)
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
                        tone: toneForJobStatus('done pending approval'),
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
                    if (_money) Text(_amount(row, 'money_pending')),
                    if (_money) Text(_amount(row, 'money_owed')),
                  ],
              ],
            ),
        ],
      ),
    );
  }
}
