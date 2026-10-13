import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tab_bar_like.dart';
import 'camera_monitoring_tab.dart';
import 'desktop_monitoring_tab.dart';
import 'files_tab.dart';
import 'general_tab.dart';
import 'ownership_tab.dart';
import 'pc_specs_tab.dart';

/// Monitoring — a sub-tab strip holding General, Map, PC Specs, Camera
/// Monitoring and Desktop Monitoring.
///
/// ── The strip is TabBarLike, and an ungranted tab is not drawn ─────────
///
/// It was a `Wrap` of `ChoiceChip`s with ticks, and a padlocked chip for every
/// tab the reader's role did not hold. On a phone that wrapped to two rows and
/// looked like no other module's strip. Jose, 2026-09-28, through the UI/UX
/// Android audit: "app needs to be cohesive." So it is the shared
/// [TabBarLike] — icons, one scrolling row, selected = fill — and an
/// ungranted tab is left out, as every other module leaves it out. The
/// padlock was Monitoring's own answer to a question the rest of the app
/// answers the other way, and two answers is the thing being fixed.
///
/// Each pane still checks its own grant (`session.me.can(...)`, see their
/// class docs), so leaving a tab off the strip is presentation, not the gate.
class MonitoringScreen extends StatefulWidget {
  const MonitoringScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MonitoringScreen> createState() => _MonitoringScreenState();
}

enum _SubTab { general, map, pcSpecs, files, cameraMonitoring, desktopMonitoring }

class _MonitoringScreenState extends State<MonitoringScreen> {
  /// Opens on General — the ownership chart — rather than PC Specs.
  ///
  /// Jose, 2026-09-22: "When opening the monitoring tab it opens on PC Specs,
  /// it should open on General." The chart is the whole office in one table,
  /// which is the question the module gets asked first; PC Specs answers a
  /// narrower one about machines.
  ///
  /// `_body` still falls through to PC Specs, so a viewer without the General
  /// tab lands somewhere real rather than on a blank pane.
  _SubTab _active = _SubTab.general;

  /// The tabs this reader is granted, in strip order.
  ///
  /// Each is its own grant (TabDefinition, 2026-09-17), and an absent `/me`
  /// answers NO for every one. PC Specs is NOT filtered: its action is the
  /// module's own `view`, so anybody who reached this screen may open it —
  /// which is what keeps the strip from ever being empty.
  List<(_SubTab, String, IconData)> get _shownTabs {
    final me = widget.session.me;
    bool can(String key) => me?.can(key) ?? false;

    return [
      if (can('monitoring.general.view'))
        (_SubTab.general, 'General', Icons.account_tree_outlined),
      if (can('monitoring.map.view'))
        (_SubTab.map, 'Map', Icons.map_outlined),
      (_SubTab.pcSpecs, 'PC Specs', Icons.computer_outlined),
      if (can('monitoring.files.view'))
        (_SubTab.files, 'Files', Icons.folder_open_outlined),
      if (can('monitoring.cameras.view'))
        (_SubTab.cameraMonitoring, 'Camera Monitoring', Icons.videocam_outlined),
      if (can('monitoring.screenshots.view'))
        (
          _SubTab.desktopMonitoring,
          'Desktop Monitoring',
          Icons.screenshot_monitor_outlined,
        ),
    ];
  }

  /// The tab actually shown: the chosen one if it is on the strip, else the
  /// first that is. hr_screen's pattern, which TabBarLike's own comment says
  /// to copy — one getter feeds both the bar and the body, so the two cannot
  /// disagree when a grant filters the list.
  _SubTab get _activeTab {
    final shown = _shownTabs;

    return shown.any((tab) => tab.$1 == _active) ? _active : shown.first.$1;
  }

  @override
  Widget build(BuildContext context) {
    final shown = _shownTabs;
    final active = _activeTab;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-monitoring-tabs'),
            labels: [for (final tab in shown) (tab.$2, tab.$3)],
            selected: shown.indexWhere((tab) => tab.$1 == active),
            onSelected: (i) => setState(() => _active = shown[i].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: _body(active)),
      ],
    );
  }

  Widget _body(_SubTab active) {
    switch (active) {
      case _SubTab.general:
        return OwnershipTab(api: widget.api, session: widget.session);
      case _SubTab.map:
        // The Map's person summary offers a jump to Desktop Monitoring. The
        // sub-tab is not a route, so the switch is handed down as a callback —
        // ONLY when Desktop Monitoring is on the strip. A jump to a tab you
        // cannot open is a button that can only ever fail; null makes the
        // summary show none.
        final canOpenDesktop = _shownTabs.any(
          (tab) => tab.$1 == _SubTab.desktopMonitoring,
        );
        return GeneralTab(
          api: widget.api,
          session: widget.session,
          onOpenDesktopMonitoring: canOpenDesktop
              ? () => setState(() => _active = _SubTab.desktopMonitoring)
              : null,
        );
      case _SubTab.desktopMonitoring:
        return DesktopMonitoringTab(api: widget.api, session: widget.session);
      case _SubTab.cameraMonitoring:
        return CameraMonitoringTab(api: widget.api, session: widget.session);
      case _SubTab.files:
        return FilesTab(api: widget.api, session: widget.session);
      case _SubTab.pcSpecs:
        return PcSpecsTab(api: widget.api, session: widget.session);
    }
  }
}
