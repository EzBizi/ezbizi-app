import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'training/dashboard_list.dart';
import 'training/learn_shelves.dart';
import 'training/settings_tab.dart';

/// Training: the tab shell (task 12), Learn (task 12), Dashboard (task 14)
/// and Settings (task 15) — layouts 1, 6 and 8 of
/// `docs/previews/training-layouts.html`.
///
/// Three tabs, the same shape `InventoryScreen` already uses: a
/// `TabBarLike`, a hairline `Divider`, and an `Offstage` stack over the
/// bodies so a tab is built only once it has actually been visited.
///
/// Settings' own top level ([TrainingSettingsTab]) lives in its own file,
/// `training/settings_tab.dart` — this shell file draws only the tab strip
/// and switches between the three tab bodies.
///
/// ── Dashboard is gated on `training.monitor`, Settings on `training.manage` ──
///
/// `DashboardController` refuses every one of its routes to anybody without
/// `training.monitor` (never `.manage`, never `.view` — its own docblock),
/// and `TrainingGuidePolicy`/`TrainingTestPolicy` refuse every one of
/// theirs — read and write alike — to anybody without `training.manage`
/// (their own docblocks, "one permission, one controller"). A control that
/// is offered must work, so the chip itself follows the same rule HR's
/// shell already uses for Clock in–out (`me.permissions.contains
/// ('hr.view')`): [_canMonitor] and [_canManage] decide whether a tab is
/// even IN [_tabs] at all, rather than drawing it and letting the first
/// request 403. A viewer who lacks the permission never sees the chip.
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

  /// `training.monitor` — the fourth of the module's four permissions
  /// (`DashboardController`'s own docblock), never `.manage` and never
  /// `.view`. Read off `session.me`, which every other screen in this
  /// module already assumes is populated by the time it builds (see
  /// `LearnShelves`'s own `session.me!`).
  bool get _canMonitor => widget.session.me?.can('training.monitor') ?? false;

  /// `training.manage` — the permission `TrainingGuidePolicy` and
  /// `TrainingTestPolicy` check on every verb, read included (their own
  /// docblocks). Settings is the authoring surface, not the Learn tab a
  /// learner reads from, so this is deliberately not `training.view`.
  bool get _canManage => widget.session.me?.can('training.manage') ?? false;

  /// Learn · Dashboard (only with `training.monitor`) · Settings (only with
  /// `training.manage`). One list rather than a fixed-length one with a
  /// slot conditionally swapped for a placeholder — the same shape HR's own
  /// `_tabs` uses for Clock in–out — so a viewer without a permission never
  /// has that chip at all, rather than one that opens onto a 403.
  List<(String, IconData, Widget Function())> get _tabs => [
    (
      'Learn',
      Icons.menu_book_outlined,
      () => LearnShelves(api: widget.api, session: widget.session),
    ),
    if (_canMonitor)
      (
        'Dashboard',
        Icons.query_stats_outlined,
        () => TrainingDashboard(api: widget.api, session: widget.session),
      ),
    if (_canManage)
      (
        'Settings',
        Icons.tune_outlined,
        () => TrainingSettingsTab(api: widget.api, session: widget.session),
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
            key: const ValueKey('tour-training-tabs'),
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
