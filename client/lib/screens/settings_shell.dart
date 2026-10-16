import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/ui_strings.dart';
import '../widgets/api_activity.dart';
import '../widgets/tab_bar_like.dart';
import 'organization_screen.dart';
import 'roles_screen.dart';
import 'settings/integrations_screen.dart';
import 'settings/settings_hub.dart';

/// The Settings destination: the shape of the business, how the work behaves,
/// and who may do it.
///
/// Organization used to be a second pinned row beside this one. Both were
/// "configure the business, not do the work", and hunting between them was
/// the reason they merged. The destination is named Settings; Organization
/// is a face of it, not a neighbour.
///
/// ── Why roles live here rather than in the sidebar ───────────────
///
/// The sidebar's business destinations are places you go to do the work. Roles
/// are how the work is arranged — the same kind of thing as the settings beside
/// them, and configured by the same person on the same day. A tab of its own
/// beside Customers and Jobs would say it is one more thing to work on.
///
/// A second row of chips rather than a nested rail, matching the Development
/// section: this is one destination with three faces, not an application within
/// the application.
///
/// The Roles chip appears only when `/me` says this person can reach the
/// `roles` module — both gates, the platform's and their role's. Its LABEL is
/// the server's word for that module, so an editor called something else on the
/// server is called that here too.
class SettingsShell extends StatefulWidget {
  const SettingsShell({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SettingsShell> createState() => _SettingsShellState();
}

class _SettingsShellState extends State<SettingsShell> {
  /// The cascade is the landing face: the destination is named Settings, so
  /// opening it still shows timezone, paper, address.
  ///
  /// By KEY, not position: since 2026-09-29 each tab is its own grant, so the
  /// list is filtered and an index would land on whichever face slid into it.
  String _tab = 'settings';

  /// Which faces have been shown at least once — hr_screen's idiom. Opening
  /// Settings must not fetch the company tree, and a tree already opened must
  /// not rebuild when somebody glances at Roles.
  final Set<String> _shown = {'settings'};

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final roles = me.module('roles');

    // Each face is its own grant (`organizations.{tab}.view`), declared on the
    // `organizations` module so the roles editor can offer it.
    final tabs = <(String, String, IconData, Widget Function())>[
      if (me.can('organizations.organization.view'))
        (
          'organization',
          'Organization',
          Icons.hub_outlined,
          () => OrganizationScreen(api: widget.api, session: widget.session),
        ),
      if (me.can('organizations.settings.view'))
        (
          'settings',
          UiStrings.t('shell.settings'),
          Icons.tune_outlined,
          // The hub of every module's settings too (UI queue 99).
          () => SettingsHub(api: widget.api, session: widget.session),
        ),
      /*
       * Integrations sits between Settings and Roles: it is a thing you look
       * at while configuring, and it is not a permission surface. The tab
       * grant is an AND on top of marketing.view, which is the module that
       * owns most of what it lists: without it there is nothing to read here.
       */
      if (me.can('organizations.integrations.view') && me.can('marketing.view'))
        (
          'integrations',
          'Integrations',
          Icons.link_outlined,
          () => IntegrationsScreen(api: widget.api, session: widget.session),
        ),
      if (roles != null && roles.reachable)
        (
          'roles',
          roles.label,
          Icons.badge_outlined,
          () => RolesScreen(api: widget.api, session: widget.session),
        ),
      // Everything anybody did with an API key, for administrators (UI queue
      // 66). After Roles, where the kinds of key are decided; a key's owner
      // reads their own under Your account.
      if (me.can('organizations.api_activity.view'))
        (
          'api_activity',
          'API activity',
          Icons.manage_search_outlined,
          () => SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ApiActivity(
              api: widget.api,
              formats: me.display,
              everyone: true,
            ),
          ),
        ),
    ];

    if (tabs.isEmpty) {
      return const Center(
        child: Text('Your role does not open any part of Settings.'),
      );
    }

    // A face this role cannot open falls back to the first it can.
    final active = tabs.any((t) => t.$1 == _tab) ? _tab : tabs.first.$1;
    final index = tabs.indexWhere((t) => t.$1 == active);

    return Column(
      children: [
        Material(
          key: const ValueKey('tour-settings-tabs'),
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: index,
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
              for (var i = 0; i < tabs.length; i++)
                Offstage(
                  offstage: i != index,
                  child: _shown.contains(tabs[i].$1) || i == index
                      ? tabs[i].$4()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
