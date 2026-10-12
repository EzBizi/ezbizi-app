// Field: GPS tracking of the roles an organization chooses, for safety and
// compliance, and suggested routes for their jobs.
// docs/superpowers/specs/2026-09-25-field-module-design.md
//
// Shaped like Work Hours: one tab per grant, each drawn only once opened.

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'field/dashboard_tab.dart';
import 'field/map_tab.dart';
import 'field/route_tab.dart';
import 'settings_screen.dart';

class FieldScreen extends StatefulWidget {
  const FieldScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<FieldScreen> createState() => _FieldScreenState();
}

class _FieldScreenState extends State<FieldScreen> {
  String _tab = 'dashboard';
  final Set<String> _shown = {'dashboard'};

  /// A tapped notification about an alert opens it on the Dashboard.
  String? _alertId;

  @override
  void initState() {
    super.initState();
    _open();
    DeepLinks.pending.addListener(_takeLink);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_takeLink);
    super.dispose();
  }

  void _takeLink() => setState(_open);

  void _open() {
    final link = DeepLinks.take('field');
    if (link?.type != 'field_alert' || !_may('field.dashboard.view')) return;
    _alertId = link!.id;
    _tab = 'dashboard';
    _shown.add(_tab);
  }

  bool _may(String key) =>
      widget.session.me?.permissions.contains(key) ?? false;

  List<(String, String, IconData, Widget Function())> get _tabs => [
    if (_may('field.dashboard.view'))
      (
        'dashboard',
        'Dashboard',
        Icons.space_dashboard_outlined,
        () => FieldDashboardTab(
          api: widget.api,
          canAcknowledge: _may('field.manage'),
          focusAlertId: _alertId,
        ),
      ),
    if (_may('field.map.view'))
      (
        'map',
        'Map',
        Icons.map_outlined,
        () => FieldMapTab(api: widget.api, canSeeJobs: _may('jobs.view')),
      ),
    if (_may('field.route.view'))
      (
        'route',
        'Route',
        Icons.alt_route,
        () => FieldRouteTab(api: widget.api, meId: widget.session.me?.id),
      ),
    if (_may('field.settings.view'))
      (
        'settings',
        'Settings',
        Icons.tune,
        () => SettingsScreen(
          api: widget.api,
          session: widget.session,
          onlyGroup: 'field',
        ),
      ),
  ];

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
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            'No part of Field has been opened to you yet. Somebody who can '
            'edit roles adds Dashboard, Map, Route or Settings under '
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
