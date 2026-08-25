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

  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Catalogue',
      Icons.handyman_outlined,
      () => ServiceList(
        api: widget.api,
        session: widget.session,
        visible: _tab == 0,
      ),
    ),
    (
      'Settings',
      Icons.tune,
      () => ServiceSettings(
        api: widget.api,
        session: widget.session,
        visible: _tab == 1,
      ),
    ),
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < _tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? _tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
