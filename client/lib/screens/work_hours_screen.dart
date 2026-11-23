/// Work Hours — its own module since 2026-09-24.
///
/// Jose: "separate it from human resources and make a new main tab called
/// Work Hours which will have the General - Paid Time Off - Settings on it",
/// and "general being the current work hours sub tab". So the three panes are
/// the ones that already existed, moved rather than redrawn: General is the
/// week, Paid Time Off is HR's PTO tab, Settings is the Clock in and paid time
/// off rows from Settings, filtered to their group.
///
/// Tabs are tracked by KEY, never by position, for the reason HR learned:
/// once the list is filtered by grant, slot 1 is a different pane for
/// different people.
library;

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'hr/pto_tab.dart';
import 'hr/work_hours_approval_tab.dart';
import 'hr/work_hours_payroll_tab.dart';
import 'hr/work_hours_reports_tab.dart';
import 'hr/work_hours_tab.dart';
import 'settings_screen.dart';

class WorkHoursScreen extends StatefulWidget {
  const WorkHoursScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WorkHoursScreen> createState() => _WorkHoursScreenState();
}

class _WorkHoursScreenState extends State<WorkHoursScreen> {
  String _tab = 'general';
  final Set<String> _shown = {'general'};

  @override
  void initState() {
    super.initState();
    // A Daily approval reminder tapped before this screen existed.
    final waiting = DeepLinks.pending.value;
    if (waiting?.module == 'work_hours' && waiting?.type == approvalLinkType) {
      _tab = 'approval';
      _shown.add('approval');
    }
    // A PTO request (design 146) opens Paid Time Off, which shows Requests.
    if (waiting?.module == 'work_hours' &&
        waiting?.type == ptoRequestLinkType) {
      _tab = 'pto';
      _shown.add('pto');
    }
    // Before the tabs' own listeners: a tapped notification brings its tab
    // to the front (a correction is General's, a day to approve is Daily
    // approval's), and that tab then takes the link.
    DeepLinks.pending.addListener(_toLinkedTab);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_toLinkedTab);
    super.dispose();
  }

  void _toLinkedTab() {
    final link = DeepLinks.pending.value;
    if (link?.module != 'work_hours') return;
    final tab = switch (link?.type) {
      approvalLinkType => 'approval',
      ptoRequestLinkType => 'pto',
      _ => 'general',
    };
    if (_tab == tab) return;
    setState(() {
      _tab = tab;
      _shown.add(tab);
    });
  }

  bool _may(String key) =>
      widget.session.me?.permissions.contains(key) ?? false;

  List<(String, String, IconData, Widget Function())> get _tabs => [
    if (_may('work_hours.general.view'))
      (
        'general',
        'General',
        Icons.schedule_outlined,
        () => WorkHoursTab(
          api: widget.api,
          session: widget.session,
          canCorrect: _may('work_hours.manage'),
        ),
      ),
    if (_may('work_hours.pto.view'))
      (
        'pto',
        'Paid Time Off',
        Icons.beach_access_outlined,
        () => PtoTab(api: widget.api, session: widget.session),
      ),
    // Saved weekly hours reports (2026-09-28), sent by hand here or by an
    // automation rule on a schedule.
    if (_may('work_hours.reports.view'))
      (
        'reports',
        'Reports',
        Icons.summarize_outlined,
        () => WorkHoursReportsTab(
          api: widget.api,
          canManage: _may('work_hours.manage'),
        ),
      ),
    // Daily approval (design 136, 2026-10-22). Approving and adjusting need
    // `work_hours.approve`; without it the tab only reads.
    if (_may('work_hours.approval.view'))
      (
        'approval',
        'Daily approval',
        Icons.fact_check_outlined,
        () => WorkHoursApprovalTab(api: widget.api, session: widget.session),
      ),
    // Payroll (design 198, 2026-11-19): one week's pay, bonus, deductions
    // and tech expenses, approved per person; behind `work_hours.payroll`.
    if (_may('work_hours.payroll'))
      (
        'payroll',
        'Payroll',
        Icons.payments_outlined,
        () => WorkHoursPayrollTab(api: widget.api, session: widget.session),
      ),
    if (_may('work_hours.settings.view'))
      (
        'settings',
        'Settings',
        Icons.tune,
        () => SettingsScreen(
          key: const ValueKey('tour-work_hours-settings'),
          api: widget.api,
          session: widget.session,
          onlyGroup: 'work_hours',
        ),
      ),
  ];

  /// The tab on screen: `_tab` if they may open it, else the first they may.
  /// Both the bar and the stack read this, so they cannot disagree.
  String get _active {
    final tabs = _tabs;
    if (tabs.isEmpty || tabs.any((t) => t.$1 == _tab)) return _tab;

    return tabs.first.$1;
  }

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    final theme = Theme.of(context);

    if (tabs.isEmpty) {
      // `work_hours.view` with no tab — reachable by a role somebody makes
      // tomorrow. Said out loud rather than drawn blank.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            'No part of Work Hours has been opened to you yet. Somebody who '
            'can edit roles adds General, Paid Time Off or Settings under '
            'Settings › Roles & permissions.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final active = _active;
    final index = tabs.indexWhere((t) => t.$1 == active);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-work_hours-tabs'),
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
                  offstage: tab.$1 != active,
                  child: _shown.contains(tab.$1) || tab.$1 == active
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
