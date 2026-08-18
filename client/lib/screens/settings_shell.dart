import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/ui_strings.dart';
import '../widgets/tab_bar_like.dart';
import 'roles_screen.dart';
import 'settings_screen.dart';

/// The Settings destination: the organization's settings, and its roles.
///
/// ── Why roles live here rather than in the sidebar ───────────────
///
/// The sidebar's business destinations are places you go to do the work. Roles
/// are how the work is arranged — the same kind of thing as the settings beside
/// them, and configured by the same person on the same day. A tab of its own
/// beside Customers and Jobs would say it is one more thing to work on.
///
/// A second row of chips rather than a nested rail, matching the Development
/// section: this is one destination with two faces, not an application within
/// the application.
///
/// The tab appears only when `/me` says this person can reach the `roles`
/// module — both gates, the platform's and their role's. Its LABEL is the
/// server's word for that module, so an editor called something else on the
/// server is called that here too.
class SettingsShell extends StatefulWidget {
  const SettingsShell({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SettingsShell> createState() => _SettingsShellState();
}

class _SettingsShellState extends State<SettingsShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final roles = me.module('roles');

    final tabs = <(String, IconData, Widget)>[
      (
        UiStrings.t('shell.settings'),
        Icons.tune_outlined,
        SettingsScreen(api: widget.api, session: widget.session),
      ),
      if (roles != null && roles.reachable)
        (
          roles.label,
          Icons.badge_outlined,
          RolesScreen(api: widget.api, session: widget.session),
        ),
    ];

    // Clamped every build, for the reason the sidebar's selection is held by
    // name: this list changes length at runtime — a role permission taken away
    // mid-session removes the second tab — and an index left pointing past the
    // end throws on the next frame.
    final index = _index.clamp(0, tabs.length - 1);

    // One tab is not a choice, and a lone chip above a screen reads as a
    // control that has lost its siblings.
    if (tabs.length == 1) return tabs.first.$3;

    return Column(
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: tabs.map((t) => (t.$1, t.$2)).toList(),
            selected: index,
            onSelected: (i) => setState(() => _index = i),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[index].$3),
      ],
    );
  }
}
