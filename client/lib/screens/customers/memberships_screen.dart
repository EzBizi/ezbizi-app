import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tab_bar_like.dart';
import '../customers_screen.dart';
import 'memberships_dashboard.dart';
import 'memberships_members.dart';
import 'memberships_settings.dart';

/// Customers with its Memberships pane beside it (Jose, 2026-10-05).
///
/// Drawn around [CustomersScreen] rather than inside it, the way Jobs draws
/// Estimates, so the customers list itself is untouched. Without
/// `memberships.view` this is the customers list alone, no strip.
class CustomersWithMemberships extends StatefulWidget {
  const CustomersWithMemberships({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<CustomersWithMemberships> createState() =>
      _CustomersWithMembershipsState();
}

class _CustomersWithMembershipsState extends State<CustomersWithMemberships> {
  int _tab = 0;
  bool _membershipsOpened = false;

  @override
  Widget build(BuildContext context) {
    final customers = CustomersScreen(api: widget.api, session: widget.session);
    if (!(widget.session.me?.can('memberships.view') ?? false)) {
      return customers;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-customers-tabs'),
            labels: const [
              ('Customers', Icons.people_outline),
              ('Memberships', Icons.card_membership_outlined),
            ],
            selected: _tab,
            onSelected: (tab) => setState(() {
              _tab = tab;
              _membershipsOpened |= tab == 1;
            }),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Offstage, not IndexedStack: a docked customer's unsaved edits
          // survive a look at memberships (see jobs_screen).
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(offstage: _tab != 0, child: customers),
              Offstage(
                offstage: _tab != 1,
                child: _membershipsOpened
                    ? MembershipsScreen(api: widget.api, session: widget.session)
                    : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// The membership program: Dashboard, Members, Settings, each granted alone.
class MembershipsScreen extends StatefulWidget {
  const MembershipsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsScreen> createState() => _MembershipsScreenState();
}

class _MembershipsScreenState extends State<MembershipsScreen> {
  String _tab = 'dashboard';

  bool _may(String key) =>
      widget.session.me?.permissions.contains(key) ?? false;

  List<(String, String, IconData)> get _tabs => [
    if (_may('memberships.dashboard.view'))
      ('dashboard', 'Dashboard', Icons.space_dashboard_outlined),
    if (_may('memberships.members.view'))
      ('members', 'Members', Icons.groups_outlined),
    if (_may('memberships.settings.view'))
      ('settings', 'Settings', Icons.tune),
  ];

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    final theme = Theme.of(context);

    if (tabs.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            'No part of Memberships has been opened to you yet. Somebody who '
            'can edit roles adds Dashboard, Members or Settings under '
            'Settings › Roles & permissions.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final index = tabs.indexWhere((t) => t.$1 == _tab);
    final active = tabs[index < 0 ? 0 : index];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            level: TabLevel.secondary,
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: index < 0 ? 0 : index,
            onSelected: (i) => setState(() => _tab = tabs[i].$1),
          ),
        ),
        const Divider(height: 1),
        // UI queue 52 (Jose: my recommendations, 1 and 4), keyed by tab so a
        // switch is a fresh read rather than a stale list.
        Expanded(
          child: KeyedSubtree(
            key: ValueKey(active.$1),
            child: switch (active.$1) {
              'dashboard' => MembershipsDashboard(
                api: widget.api,
                session: widget.session,
              ),
              'members' => MembershipsMembers(
                api: widget.api,
                session: widget.session,
              ),
              _ => MembershipsSettings(
                api: widget.api,
                session: widget.session,
              ),
            },
          ),
        ),
      ],
    );
  }
}
