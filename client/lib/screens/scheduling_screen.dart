import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'scheduling/availability_pane.dart';
import 'scheduling/capacity_settings.dart';

/// Scheduling. Availability and Settings are real; Schedule is not built yet.
///
/// design: docs/previews/scheduling-layouts.html — #4 Availability, #7 Settings
class SchedulingScreen extends StatefulWidget {
  const SchedulingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SchedulingScreen> createState() => _SchedulingScreenState();
}

class _SchedulingScreenState extends State<SchedulingScreen> {
  int _tab = 0;
  final Set<int> _shown = {0};

  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Availability',
      Icons.event_available_outlined,
      () => AvailabilityPane(
        api: widget.api,
        session: widget.session,
        visible: _tab == 0,
      ),
    ),
    (
      'Schedule',
      Icons.calendar_month_outlined,
      // ponytail: still a placeholder. Nothing books a slot yet — Job has no
      // service area — so there is no schedule to draw. It becomes real with
      // Scheduled Jobs, which is also what turns Availability's "configured
      // capacity" into "remaining".
      () => const _NotBuilt('Schedule is not built yet.'),
    ),
    (
      'Settings',
      Icons.tune,
      () => CapacitySettings(
        api: widget.api,
        session: widget.session,
        visible: _tab == 2,
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

class _NotBuilt extends StatelessWidget {
  const _NotBuilt(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Text(
        message,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}
