import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/device_zone.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'jobs/project_management_dashboard.dart';
import 'jobs/project_management_settings.dart';
import 'jobs/project_management_tab.dart';

/// Project Management, its own sidebar module since 2026-10-21 (Jose: "This
/// Project Management should be its own tab, not a sub tab of Jobs").
///
/// Three tabs since UI queue 127 (option 1, phone 4): Dashboard, what
/// happened in a date range; Pending Jobs, the table it used to be, opening
/// on Recently paid; Settings, which statuses it watches and which mean
/// cancelled.
///
/// The module's reach is its gate: the sidebar draws it only for somebody
/// holding `project_management.view`, and all three tabs are that one grant,
/// so nothing here checks a permission. Settings writes are the server's to
/// refuse, and each row says whether this person may change it.
///
/// It fetches the organization's settings once: the status words for the
/// timelines, and the two lists Settings edits. Until they arrive, or if they
/// cannot be read, the timelines show humanized keys.
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

  /// The countdown's clock, passed through for tests.
  final DateTime Function()? now;

  /// The device's IANA zone, passed through for tests.
  final String? Function() zone;

  @override
  State<ProjectManagementScreen> createState() =>
      _ProjectManagementScreenState();
}

const _watchedKey = watchedKey;
const _cancelledKey = cancelledKey;

class _ProjectManagementScreenState extends State<ProjectManagementScreen> {
  List<JobStatus> _statuses = const [];
  PmStatusSetting? _watched;
  PmStatusSetting? _cancelled;

  final Set<String> _saving = {};
  final Map<String, String> _errors = {};

  /// Bumped after a save, so the other tabs read again.
  int _generation = 0;

  String _tab = 'dashboard';
  final Set<String> _shown = {'dashboard'};

  String get _orgId => widget.session.me!.organization.id;

  @override
  void initState() {
    super.initState();
    _loadSettings();
  }

  Future<void> _loadSettings() async {
    final settings = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': _orgId,
    });
    if (!mounted || !settings.ok) return;

    final all = settings.data;
    final rows = <String, Map<String, dynamic>>{
      if (all is List)
        for (final s in all.whereType<Map<String, dynamic>>()) '${s['key']}': s,
    };
    final parsed = parseJobStatuses(rows['jobs.statuses']?['value']);
    setState(() {
      if (parsed.isNotEmpty) _statuses = parsed;
      _watched = _setting(_watchedKey, rows[_watchedKey]);
      _cancelled = _setting(_cancelledKey, rows[_cancelledKey]);
    });
  }

  /// A row as the settings API sends it; the job statuses stand in for its
  /// options when it has none.
  PmStatusSetting _setting(String key, Map<String, dynamic>? row) {
    final raw = row?['value'];
    final options = row?['options'];
    return PmStatusSetting(
      key: key,
      value: raw is List ? [for (final v in raw) '$v'] : const [],
      options: options is List && options.isNotEmpty
          ? [
              for (final o in options)
                if (o is Map) ('${o['value']}', '${o['label'] ?? o['value']}'),
            ]
          : [for (final s in _statuses) (s.value, s.label)],
      mayEdit: row == null || row['may_edit'] != false,
      reason: row?['may_edit_reason'] as String?,
    );
  }

  Future<void> _save(String key, List<String> value) async {
    // An empty watched list empties every Project Management list (0.0.0474).
    if (key == _watchedKey && value.isEmpty) return;
    setState(() {
      _saving.add(key);
      _errors.remove(key);
    });

    final response = await widget.api.patch(
      '/settings/$key',
      {'value': value},
      {'scope_type': 'organization', 'scope_id': _orgId},
    );
    if (!mounted) return;

    setState(() {
      _saving.remove(key);
      if (!response.ok) {
        // Nothing on screen moves: a chip showing a refused value looks saved.
        _errors[key] = response.message;
        return;
      }
      final saved = response.data is Map
          ? (response.data as Map)['value']
          : null;
      final list = saved is List ? [for (final v in saved) '$v'] : value;
      PmStatusSetting update(PmStatusSetting s) => PmStatusSetting(
        key: s.key,
        value: list,
        options: s.options,
        mayEdit: s.mayEdit,
        reason: s.reason,
      );
      if (key == _watchedKey && _watched != null) _watched = update(_watched!);
      if (key == _cancelledKey && _cancelled != null) {
        _cancelled = update(_cancelled!);
      }
      _generation++;
    });
  }

  List<(String, String, IconData, Widget Function())> get _tabs => [
    (
      'dashboard',
      'Dashboard',
      Icons.insights_outlined,
      () => ProjectManagementDashboard(
        api: widget.api,
        session: widget.session,
        statuses: _statuses,
        cancelled: _cancelled?.value,
        now: widget.now,
        zone: widget.zone,
        generation: _generation,
      ),
    ),
    (
      'pending',
      'Pending Jobs',
      Icons.pending_actions_outlined,
      () => ProjectManagementTab(
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
        watched: _watched,
        cancelled: _cancelled,
        saving: _saving,
        errors: _errors,
        onSave: _save,
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
            onSelected: (i) => setState(() {
              _tab = tabs[i].$1;
              _shown.add(_tab);
            }),
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
                  offstage: tab.$1 != _tab,
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
