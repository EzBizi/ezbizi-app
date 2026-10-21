import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import 'jobs/project_management_tab.dart';

/// Project Management, its own sidebar module since 2026-10-21 (Jose: "This
/// Project Management should be its own tab, not a sub tab of Jobs").
///
/// The module's reach is its gate: the sidebar draws it only for somebody
/// holding `project_management.view`, so nothing here checks a permission.
///
/// As a Jobs tab it borrowed the organization's status words from the Jobs
/// list; on its own it fetches them, the same way Jobs does. Until they
/// arrive, or if they cannot be read, the timeline shows humanized keys.
class ProjectManagementScreen extends StatefulWidget {
  const ProjectManagementScreen({
    super.key,
    required this.api,
    required this.session,
    this.now,
  });

  final ApiClient api;
  final Session session;

  /// The countdown's clock, passed through for tests.
  final DateTime Function()? now;

  @override
  State<ProjectManagementScreen> createState() =>
      _ProjectManagementScreenState();
}

class _ProjectManagementScreenState extends State<ProjectManagementScreen> {
  List<JobStatus> _statuses = const [];

  @override
  void initState() {
    super.initState();
    _loadStatuses();
  }

  Future<void> _loadStatuses() async {
    final settings = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': widget.session.me!.organization.id,
    });
    if (!mounted || !settings.ok) return;

    final all = settings.data;
    final rows = all is List
        ? all
              .whereType<Map<String, dynamic>>()
              .where((s) => s['key'] == 'jobs.statuses')
              .toList()
        : const <Map<String, dynamic>>[];
    final parsed = parseJobStatuses(rows.isEmpty ? null : rows.first['value']);
    if (parsed.isNotEmpty) setState(() => _statuses = parsed);
  }

  @override
  Widget build(BuildContext context) => ProjectManagementTab(
    api: widget.api,
    session: widget.session,
    statuses: _statuses,
    now: widget.now,
  );
}
