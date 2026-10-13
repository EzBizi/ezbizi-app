import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'phone/alerts_tab.dart';
import 'phone/phone_tab.dart';
import 'phone/records_tab.dart';
import 'phone/reports_tab.dart';
import 'phone/settings_tab.dart';
import 'phone/team_tab.dart';

/// Phone — Call, Records, Reports, Settings.
///
/// One destination with three faces. The sidebar already says Phone, so there
/// is no heading above the chips repeating it. Unopened tabs do not fetch.
class PhoneScreen extends StatefulWidget {
  const PhoneScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends State<PhoneScreen> {
  /// The tab on screen, by KEY: which index a tab has depends on which tabs
  /// this person may open.
  String _tab = 'phone';

  /// Which tabs have been shown at least once.
  ///
  /// A tab nobody has opened must not fetch; a tab they have opened must keep
  /// its State when they glance at another one. Same Offstage stack as HR.
  final Set<String> _shown = {};

  /// Every tab, keyed as the registry declares them (`phone.<key>.view`).
  late final List<(String, String, IconData, Widget Function())> _declared = [
    // Phone, holding Dial, Messages and Voicemail. The module is
    // Communication now and a tab called Dial inside it would name one of
    // three things it contains.
    (
      'phone',
      'Phone',
      Icons.phone_outlined,
      () => PhoneTab(
        api: widget.api,
        session: widget.session,
        onOpenSettings: () => setState(() => _tab = 'settings'),
      ),
    ),
    (
      // Users, not Team. This tab assigns extensions and outbound lines to
      // PEOPLE; the live roster you call and message is under Phone. Two tabs
      // called Team, one of them administrative, is a name that tells you
      // nothing about which one you want.
      'users',
      'Users',
      Icons.badge_outlined,
      () => TeamTab(api: widget.api, session: widget.session),
    ),
    (
      'records',
      'Records',
      Icons.history,
      () => RecordsTab(api: widget.api, session: widget.session),
    ),
    (
      'reports',
      'Reports',
      Icons.insights_outlined,
      () => ReportsTab(api: widget.api, session: widget.session),
    ),
    (
      // Jose, 2026-10-07: alerts to chosen people, a pop-up they dismiss.
      // Same order as the TabDefinition list in ModuleServiceProvider.
      'alerts',
      'Alerts',
      Icons.campaign_outlined,
      () => AlertsTab(api: widget.api, formats: widget.session.me!.display),
    ),
    (
      'settings',
      'Settings',
      Icons.tune_outlined,
      () => PhoneSettingsTab(api: widget.api, session: widget.session),
    ),
  ];

  /// The tabs this person may open. Jose, 2026-09-29: "we need to add the tab
  /// support like all the others". Absent `/me` answers no, as Scheduling's.
  List<(String, String, IconData, Widget Function())> get _tabs => [
    for (final tab in _declared)
      if (widget.session.me?.permissions.contains('phone.${tab.$1}.view') ??
          false)
        tab,
  ];

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;

    if (tabs.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'You do not have access to any part of Communication yet. An '
            'administrator grants these one tab at a time.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      );
    }

    // A tab that is no longer granted falls back to the first one that is.
    final selected = tabs.indexWhere((tab) => tab.$1 == _tab);
    final current = selected < 0 ? 0 : selected;
    final key = tabs[current].$1;
    _shown.add(key);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-phone-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: current,
            onSelected: (i) => setState(() => _tab = tabs[i].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (final tab in tabs)
                Offstage(
                  key: ValueKey(tab.$1),
                  offstage: tab.$1 != key,
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
