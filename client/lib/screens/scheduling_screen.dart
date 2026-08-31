import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';

/// Scheduling. Three tabs standing, nothing behind any of them yet.
///
/// ponytail: placeholders live inline here rather than in
/// `screens/scheduling/`. The moment a tab gets real content it moves to its
/// own file the way Services did — one empty Center is not worth three imports.
class SchedulingScreen extends StatefulWidget {
  const SchedulingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SchedulingScreen> createState() => _SchedulingScreenState();
}

class _SchedulingScreenState extends State<SchedulingScreen> {
  int _tab = 0;

  static const List<(String, IconData, String)> _tabs = [
    (
      'Availability',
      Icons.event_available_outlined,
      'Availability is not built yet.',
    ),
    ('Schedule', Icons.calendar_month_outlined, 'Schedule is not built yet.'),
    ('Settings', Icons.tune, 'Scheduling settings are not built yet.'),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Center(
            child: Text(
              _tabs[_tab].$3,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
