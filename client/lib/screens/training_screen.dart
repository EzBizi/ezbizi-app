import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/coming_soon.dart';
import '../widgets/tab_bar_like.dart';
import 'training/learn_shelves.dart';

/// Training (Task 12): the tab shell and Learn — layout 1 of
/// `docs/previews/training-layouts.html`.
///
/// Three tabs, the same shape `InventoryScreen` already uses: a
/// `TabBarLike`, a hairline `Divider`, and an `Offstage` stack over the
/// bodies so a tab is built only once it has actually been visited. Learn is
/// the only one this task builds — Dashboard (task 14) and Settings (task
/// 15) are `ComingSoonScreen`s, the same "granted and not built" answer
/// Newsletter and HR's own not-built panels already give, so a person who
/// taps either tonight sees a sentence about what it will be rather than a
/// blank page that reads as broken.
class TrainingScreen extends StatefulWidget {
  const TrainingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrainingScreen> createState() => _TrainingScreenState();
}

class _TrainingScreenState extends State<TrainingScreen> {
  static const _learn = 0;

  int _tab = _learn;

  final Set<int> _shown = {_learn};

  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Learn',
      Icons.menu_book_outlined,
      () => LearnShelves(api: widget.api, session: widget.session),
    ),
    (
      'Dashboard',
      Icons.query_stats_outlined,
      () => const ComingSoonScreen(
        title: 'Dashboard',
        icon: Icons.query_stats_outlined,
        what:
            'Who has done what, and who has not — one row per person, one '
            'column per guide or test, and who is overdue or due for '
            'retaking at a glance. Coming next for Training.',
      ),
    ),
    (
      'Settings',
      Icons.tune_outlined,
      () => const ComingSoonScreen(
        title: 'Settings',
        icon: Icons.tune_outlined,
        what:
            'Where a guide or a test gets written, screen by screen or '
            'question by question, and published when it is ready. Coming '
            'next for Training.',
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
