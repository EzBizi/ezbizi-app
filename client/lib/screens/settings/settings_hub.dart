import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../settings_screen.dart';
import '../customers/memberships_settings.dart';
import '../email/settings_tab.dart';
import '../guidelines_screen.dart';
import '../marketing/newsletter_settings.dart';
import '../monitoring/camera_sites_settings.dart';
import '../phone/settings_tab.dart';
import '../reports/report_goals_settings.dart';
import '../scheduling_screen.dart';
import '../services/service_settings.dart';
import '../training/settings_tab.dart';

/// Settings › Settings as the hub of every module's settings (Jose,
/// 2026-10-15: "the settings tab should be in sync with them and have the full
/// parity, so all settings can be found there"; UI queue 99, desktop 1 and
/// phone 4).
///
/// Each module keeps its own Settings tab. This draws the SAME widget that tab
/// draws, behind the same grant, so there is one copy and nothing to keep in
/// step. "All settings" is the organization-wide page it always was.
class SettingsHub extends StatefulWidget {
  const SettingsHub({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Wider than this, the index sits on the left; narrower, it is a chip row.
  static const double sideAbove = 900;

  @override
  State<SettingsHub> createState() => _SettingsHubState();
}

typedef _Entry = (String key, String label, IconData icon, Widget Function());

class _SettingsHubState extends State<SettingsHub> {
  String _open = 'all';
  final Set<String> _shown = {'all'};

  /// Every module with a Settings tab, gated exactly as that tab is. Add a
  /// module's settings here when it gets a Settings tab of its own.
  List<_Entry> _entries(Me me) {
    final api = widget.api;
    final session = widget.session;
    bool reach(String key) => me.module(key)?.reachable ?? false;
    String label(String key, String fallback) =>
        me.module(key)?.label ?? fallback;

    return [
      (
        'all',
        'All settings',
        Icons.tune_outlined,
        () => SettingsScreen(api: api, session: session),
      ),
      if (reach('scheduling') && me.can('scheduling.settings.view'))
        (
          'scheduling',
          label('scheduling', 'Scheduling'),
          Icons.calendar_month_outlined,
          () => SchedulingSettings(api: api, session: session, visible: true),
        ),
      if (reach('field') && me.can('field.settings.view'))
        (
          'field',
          label('field', 'Field'),
          Icons.near_me_outlined,
          () => SettingsScreen(api: api, session: session, onlyGroup: 'field'),
        ),
      if (reach('work_hours') && me.can('work_hours.settings.view'))
        (
          'work_hours',
          label('work_hours', 'Work Hours'),
          Icons.schedule_outlined,
          () => SettingsScreen(
            api: api,
            session: session,
            onlyGroup: 'work_hours',
          ),
        ),
      // Leads' settings: lines, markets, queues, Pause all Google calls,
      // replies and booking (Leads, 2026-10-19).
      if (reach('leads') && me.can('leads.manage'))
        (
          'leads',
          label('leads', 'Leads'),
          Icons.call_received,
          () => SettingsScreen(api: api, session: session, onlyGroup: 'leads'),
        ),
      if (reach('phone') && me.can('phone.settings.view'))
        (
          'phone',
          label('phone', 'Communication'),
          Icons.call_outlined,
          () => PhoneSettingsTab(api: api, session: session),
        ),
      if (reach('email') && me.can('email.settings.view'))
        (
          'email',
          label('email', 'Email'),
          Icons.mail_outline,
          () => MailboxSettingsTab(api: api, session: session),
        ),
      if (reach('newsletter') && me.can('newsletter.settings.view'))
        (
          'newsletter',
          label('newsletter', 'Newsletter'),
          Icons.campaign_outlined,
          () => NewsletterSettings(
            api: api,
            session: session,
            viewName: me.scope.label,
            onChanged: () async {},
          ),
        ),
      if (reach('memberships') && me.can('memberships.settings.view'))
        (
          'memberships',
          label('memberships', 'Memberships'),
          Icons.card_membership_outlined,
          () => MembershipsSettings(api: api, session: session),
        ),
      if (reach('services') && me.can('services.settings.view'))
        (
          'services',
          label('services', 'Services'),
          Icons.handyman_outlined,
          () => ServiceSettings(api: api, session: session, visible: true),
        ),
      if (reach('training') && me.can('training.manage'))
        (
          'training',
          label('training', 'Training'),
          Icons.school_outlined,
          () => TrainingSettingsTab(api: api, session: session),
        ),
      if (reach('guidelines') &&
          me.can('guidelines.settings.view') &&
          me.can('guidelines.manage'))
        (
          'guidelines',
          label('guidelines', 'Guidelines'),
          Icons.menu_book_outlined,
          () => GuidelinesTab(api: api, session: session, editing: true),
        ),
      // The weekly and monthly report's goals (2026-10-16).
      if (reach('reports') && me.can('reports.settings'))
        (
          'reports',
          label('reports', 'Reports'),
          Icons.flag_outlined,
          () => ReportGoalsSettings(api: api, session: session),
        ),
      // Camera sites: the PC at each site that relays its recorder (UI queue
      // 117). No Monitoring Settings tab of its own yet; this is its page.
      if (reach('monitoring') && me.can('monitoring.cameras.view'))
        (
          'monitoring',
          label('monitoring', 'Monitoring'),
          Icons.videocam_outlined,
          () => CameraSitesSettings(api: api, session: session),
        ),
      if (reach('ai_assistance') && me.can('ai_assistance.settings.view'))
        (
          'ai_assistance',
          label('ai_assistance', 'AI Assistance'),
          Icons.smart_toy_outlined,
          () => SettingsScreen(
            api: api,
            session: session,
            onlyGroup: 'ai_assistance',
          ),
        ),
    ];
  }

  void _pick(String key) => setState(() {
    _open = key;
    _shown.add(key);
  });

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final entries = _entries(me);
    final open = entries.any((e) => e.$1 == _open) ? _open : 'all';
    final theme = Theme.of(context);

    // Each opened entry stays alive, as the Settings shell keeps its tabs.
    final body = Stack(
      children: [
        for (final e in entries)
          if (_shown.contains(e.$1))
            Offstage(
              offstage: e.$1 != open,
              child: KeyedSubtree(
                key: ValueKey('settings-hub-${e.$1}'),
                child: e.$4(),
              ),
            ),
      ],
    );

    return LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth < SettingsHub.sideAbove) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SingleChildScrollView(
                key: const ValueKey('settings-hub-jump'),
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
                child: Row(
                  children: [
                    for (final e in entries)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          key: ValueKey('settings-hub-chip-${e.$1}'),
                          label: Text(e.$2),
                          selected: e.$1 == open,
                          onSelected: (_) => _pick(e.$1),
                        ),
                      ),
                  ],
                ),
              ),
              Expanded(child: body),
            ],
          );
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: 220,
              child: ListView(
                key: const ValueKey('settings-hub-index'),
                padding: const EdgeInsets.fromLTRB(12, 16, 8, 16),
                children: [
                  for (final (i, e) in entries.indexed) ...[
                    if (i == 1)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(12, 14, 12, 4),
                        child: Text(
                          'BY MODULE',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                            fontWeight: FontWeight.w800,
                            letterSpacing: 0.6,
                          ),
                        ),
                      ),
                    ListTile(
                      key: ValueKey('settings-hub-item-${e.$1}'),
                      dense: true,
                      leading: Icon(e.$3, size: 20),
                      title: Text(e.$2),
                      selected: e.$1 == open,
                      selectedTileColor: theme.colorScheme.primaryContainer,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                      onTap: () => _pick(e.$1),
                    ),
                  ],
                ],
              ),
            ),
            const VerticalDivider(width: 1),
            Expanded(child: body),
          ],
        );
      },
    );
  }
}
