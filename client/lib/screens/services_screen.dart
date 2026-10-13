import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'services/service_list.dart';
import 'services/service_settings.dart';

/// Services (spec). Catalogue and Settings, not a coming-soon tab.
class ServicesScreen extends StatefulWidget {
  const ServicesScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  static const double splitAbove = 600;

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  int _tab = 0;
  final Set<int> _shown = {0};

  /// Every chip this screen can draw, each carrying the tab key its permission
  /// is named for. The builder is handed its own visibility rather than
  /// comparing against a hard-coded index: once a chip can be filtered out,
  /// position 1 is no longer always Settings.
  late final List<(String, String, IconData, Widget Function(bool))> _all = [
    (
      'catalogue',
      'Catalogue',
      Icons.handyman_outlined,
      (visible) =>
          ServiceList(api: widget.api, session: widget.session, visible: visible),
    ),
    (
      'settings',
      'Settings',
      Icons.tune,
      (visible) => ServiceSettings(
        api: widget.api,
        session: widget.session,
        visible: visible,
      ),
    ),
  ];

  /// An absent `/me` answers NO. A chip drawn while the answer is still
  /// arriving is a chip that appears, briefly, to somebody who may not have it.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('services.$tab.view') ?? false;

  List<(String, String, IconData, Widget Function(bool))> get _tabs => [
    for (final tab in _all)
      if (_mayOpen(tab.$1)) tab,
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;

    // Reachable: the module gate is `services.view`, and a role can hold that
    // with neither chip granted. Say so rather than drawing an empty bar.
    if (tabs.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('No Services tabs have been granted to your role.'),
        ),
      );
    }

    final selected = _tab < tabs.length ? _tab : 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-services-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < tabs.length; i++)
                Offstage(
                  offstage: i != selected,
                  child: _shown.contains(i)
                      ? tabs[i].$4(i == selected)
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
