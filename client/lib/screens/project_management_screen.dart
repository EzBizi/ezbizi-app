import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/device_zone.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'jobs/pm_stages.dart';
import 'jobs/project_management_assignments.dart';
import 'jobs/project_management_dashboard.dart';
import 'jobs/project_management_jobs.dart';
import 'jobs/project_management_logs.dart';
import 'jobs/project_management_reports.dart';
import 'jobs/project_management_settings.dart';

/// Project Management, its own sidebar module since 2026-10-21 (Jose: "This
/// Project Management should be its own tab, not a sub tab of Jobs").
///
/// Seven tabs: Dashboard, where every job stands; Reports and Table,
/// Financials' weekly Repair Team report; Jobs (which was Pending Jobs, key
/// `pending`), one stage at a time; Assignments (design 143), Action 1's
/// Task Board cards by technician; Logs (UI queue 139); Settings, for the
/// place being viewed.
///
/// The module's reach is its gate: the sidebar draws it only for somebody
/// holding `project_management.view`, and every tab is that one grant.
/// Reports and Table also need `financials.bases.view`, which their endpoint
/// requires, so they are not drawn without it.
///
/// It reads the place's settings once, and again after Settings saves: the
/// status words, the warning days, the review reasons and the redo and
/// dispute words the other tabs draw with. And the service areas in reach,
/// from GET /jobs's meta (Scheduling's own list needs scheduling.view).
class ProjectManagementScreen extends StatefulWidget {
  const ProjectManagementScreen({
    super.key,
    required this.api,
    required this.session,
    this.now,
    this.zone = deviceZone,
  });

  final ApiClient api;
  final Session session;

  /// The clock, passed through for tests.
  final DateTime Function()? now;

  /// The device's IANA zone, passed through for tests.
  final String? Function() zone;

  @override
  State<ProjectManagementScreen> createState() =>
      _ProjectManagementScreenState();
}

class _ProjectManagementScreenState extends State<ProjectManagementScreen> {
  List<JobStatus> _statuses = const [];
  List<PmArea> _areas = const [];
  int? _warnDays;
  int? _stoppedDays;
  List<(String, String)> _reasons = const [];
  List<String> _redo = const [];
  List<String> _dispute = const [];
  List<String> _scopeStatuses = const [];
  bool _partlyPaidOnly = false;

  /// Bumped after a save, so the other tabs read again.
  int _generation = 0;

  PmJobsIntent? _intent;
  int _serial = 0;

  String _tab = 'dashboard';
  final Set<String> _shown = {'dashboard'};

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _loadSettings();
    _loadAreas();
  }

  Future<void> _loadSettings() async {
    final settings = await widget.api.get('/settings', pmPlaceScope(_me));
    if (!mounted || !settings.ok) return;

    final all = settings.data;
    final rows = <String, Object?>{
      if (all is List)
        for (final s in all.whereType<Map>()) '${s['key']}': s['value'],
    };
    List<String> strings(Object? v) => [
      for (final s in v is List ? v : const [])
        if ('$s'.trim().isNotEmpty) '$s',
    ];
    final parsed = parseJobStatuses(rows['jobs.statuses']);
    setState(() {
      if (parsed.isNotEmpty) _statuses = parsed;
      final warn = rows[pmWarnDaysKey];
      _warnDays = warn is int ? warn : null;
      final stopped = rows[pmStoppedKey];
      _stoppedDays = stopped is int ? stopped : null;
      _reasons = [
        for (final r in (rows[pmReasonsKey] as List?) ?? const [])
          if (r is Map && r['value'] != null)
            ('${r['value']}', '${r['label'] ?? r['value']}'),
      ];
      _redo = strings(rows[pmRedoKey]);
      _dispute = strings(rows[pmDisputeKey]);
      _scopeStatuses = strings(rows[pmScopeStatusesKey]);
      _partlyPaidOnly = rows[pmPartlyPaidKey] == true;
    });
  }

  Future<void> _loadAreas() async {
    final response = await widget.api.get('/jobs', {'per_page': '1'});
    if (!mounted || !response.ok) return;
    setState(() => _areas = pmAreas(response.body['meta']));
  }

  void _saved() {
    _loadSettings();
    setState(() => _generation++);
  }

  void _go(String tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  void _openJobs(String? stage, PmFilters filters) {
    _intent = PmJobsIntent(stage, filters, ++_serial);
    _go('pending');
  }

  /// The scope line's words: the statuses by their own names, and the place
  /// as "Company · Branch".
  PmScope get _scope {
    final names = {for (final st in _statuses) st.value: st.label};
    final place = _me.scope;
    return PmScope(
      statuses: [for (final v in _scopeStatuses) names[v] ?? v],
      partlyPaidOnly: _partlyPaidOnly,
      place: place.type == 'franchise' && place.id != null
          ? branchLabelOf(_me, place.id!, place.label)
          : place.label,
    );
  }

  bool get _financials => _me.can('financials.bases.view');

  List<(String, String, IconData, Widget Function())> get _tabs => [
    (
      'dashboard',
      'Dashboard',
      Icons.insights_outlined,
      () => ProjectManagementDashboard(
        api: widget.api,
        session: widget.session,
        areas: _areas,
        stoppedDays: _stoppedDays,
        generation: _generation,
        onOpenJobs: _openJobs,
        onOpenSettings: () => _go('settings'),
        scope: _scope,
      ),
    ),
    if (_financials) ...[
      (
        'reports',
        'Reports',
        Icons.summarize_outlined,
        () => ProjectManagementReports(
          api: widget.api,
          session: widget.session,
          now: widget.now,
        ),
      ),
      (
        'table',
        'Table',
        Icons.table_chart_outlined,
        () => ProjectManagementReportTable(
          api: widget.api,
          session: widget.session,
          now: widget.now,
        ),
      ),
    ],
    (
      'pending',
      'Jobs',
      Icons.pending_actions_outlined,
      () => ProjectManagementJobs(
        api: widget.api,
        session: widget.session,
        areas: _areas,
        warnDays: _warnDays,
        reviewReasons: _reasons,
        redoWords: _redo,
        disputeWords: _dispute,
        statuses: _statuses,
        intent: _intent,
        generation: _generation,
        onOpenSettings: () => _go('settings'),
        now: widget.now,
        zone: widget.zone,
        scope: _scope,
      ),
    ),
    (
      'assignments',
      'Assignments',
      Icons.assignment_ind_outlined,
      () => ProjectManagementAssignments(
        api: widget.api,
        session: widget.session,
        areas: _areas,
        generation: _generation,
        onOpenSettings: () => _go('settings'),
        scope: _scope,
        statuses: _statuses,
        now: widget.now,
        zone: widget.zone,
      ),
    ),
    (
      'logs',
      'Logs',
      Icons.receipt_long_outlined,
      () => ProjectManagementLogs(
        api: widget.api,
        session: widget.session,
        statuses: _statuses,
        now: widget.now,
        zone: widget.zone,
        generation: _generation,
      ),
    ),
    (
      'settings',
      'Settings',
      Icons.tune,
      () => ProjectManagementSettings(
        api: widget.api,
        session: widget.session,
        statuses: _statuses,
        onSaved: _saved,
      ),
    ),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tabs = _tabs;
    final index = tabs.indexWhere((t) => t.$1 == _tab);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-pm-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: index < 0 ? 0 : index,
            onSelected: (i) => _go(tabs[i].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (final tab in tabs)
                Offstage(
                  key: ValueKey('pm-pane-${tab.$1}'),
                  offstage: tab.$1 != (index < 0 ? tabs.first.$1 : _tab),
                  child: _shown.contains(tab.$1)
                      ? tab.$4()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
