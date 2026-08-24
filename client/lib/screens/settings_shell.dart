import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/ui_strings.dart';
import '../widgets/tab_bar_like.dart';
import 'organization_screen.dart';
import 'roles_screen.dart';
import 'settings_screen.dart';

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
  /// opening it still shows timezone, paper, address. Organization is chip
  /// 0, this is chip 1 — not a hijack of the first click.
  static const _settings = 1;

  int _tab = _settings;

  /// Which faces have been shown at least once — hr_screen's idiom. Opening
  /// Settings must not fetch the company tree, and a tree already opened must
  /// not rebuild when somebody glances at Roles.
  final Set<int> _shown = {_settings};

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final roles = me.module('roles');

    final tabs = <(String, IconData, Widget Function())>[
      (
        'Organization',
        Icons.hub_outlined,
        () => OrganizationScreen(api: widget.api, session: widget.session),
      ),
      (
        UiStrings.t('shell.settings'),
        Icons.tune_outlined,
        () => SettingsScreen(api: widget.api, session: widget.session),
      ),
      if (roles != null && roles.reachable)
        (
          roles.label,
          Icons.badge_outlined,
          () => RolesScreen(api: widget.api, session: widget.session),
        ),
    ];

    final index = _tab.clamp(0, tabs.length - 1);

    return Column(
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$1, tab.$2)],
            selected: index,
            onSelected: (i) => setState(() {
              _tab = i;
              _shown.add(i);
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
                  child: _shown.contains(i)
                      ? tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
