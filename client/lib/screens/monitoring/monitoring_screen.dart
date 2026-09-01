import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tag.dart';
import 'desktop_monitoring_tab.dart';
import 'pc_specs_tab.dart';

/// Monitoring — a sub-tab strip (spec §"Sub-project 1") holding PC Specs and
/// Desktop Monitoring, with Camera Monitoring still declared and not built.
///
/// ── Why Camera Monitoring is a chip here rather than absent ─────────
///
/// The module design doc puts PC Specs first in the build order and sketches
/// the other two for later (Sub-projects 2 and 3). A tab that silently does
/// not exist reads as a permission somebody lost; this is the same "coming
/// soon" answer ComingSoonScreen and Marketing's Newsletter chip already give
/// at the size of a whole screen, applied here at the size of one chip inside
/// a single-permission module — `monitoring.view` gates the tab, not which
/// sub-tab inside it exists yet.
///
/// It carries no `onSelected` — there is no screen behind it to switch to,
/// so an interactive-looking control that goes nowhere would be worse than a
/// disabled one.
///
/// ── Desktop Monitoring's own gate ────────────────────────────────────
///
/// Its endpoints (`GET /monitoring/screenshots*`) are gated server-side by
/// `monitoring.screenshots.view` — a SEPARATE grant from `specs.view`, added
/// in the same slice this tab belongs to. Rather than let a caller who lacks
/// it click through to a tab that would just 403 on every fetch, the chip
/// itself falls back to the same "Soon"/no-permission look Camera Monitoring
/// always has — [DesktopMonitoringTab] carries its own copy of this check
/// too (`session.me.can(...)`, see its class doc comment), the same
/// defence-in-depth every permission-gated screen in this app uses; this is
/// only what decides whether the CHIP is worth making selectable at all.
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

enum _SubTab { pcSpecs, desktopMonitoring }

class _MonitoringScreenState extends State<MonitoringScreen> {
  _SubTab _active = _SubTab.pcSpecs;

  @override
  Widget build(BuildContext context) {
    final canViewScreenshots =
        widget.session.me?.can('monitoring.screenshots.view') ?? false;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
            child: Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                ChoiceChip(
                  label: const Text('PC Specs'),
                  selected: _active == _SubTab.pcSpecs,
                  onSelected: (_) => setState(() => _active = _SubTab.pcSpecs),
                ),
                const ChoiceChip(
                  label: _SoonLabel('Camera Monitoring'),
                  selected: false,
                  onSelected: null,
                ),
                if (canViewScreenshots)
                  ChoiceChip(
                    label: const Text('Desktop Monitoring'),
                    selected: _active == _SubTab.desktopMonitoring,
                    onSelected: (_) =>
                        setState(() => _active = _SubTab.desktopMonitoring),
                  )
                else
                  const ChoiceChip(
                    label: _SoonLabel('Desktop Monitoring'),
                    selected: false,
                    onSelected: null,
                  ),
              ],
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: _body(canViewScreenshots)),
      ],
    );
  }

  Widget _body(bool canViewScreenshots) {
    if (_active == _SubTab.desktopMonitoring && canViewScreenshots) {
      return DesktopMonitoringTab(api: widget.api, session: widget.session);
    }

    return PcSpecsTab(api: widget.api, session: widget.session);
  }
}

/// A disabled chip's label: a name that can be squeezed, and the "Soon" pill
/// that cannot.
///
/// `Wrap` (this strip's parent) hands each chip a BOUNDED max width — three
/// chips at 375px is tighter than "Desktop Monitoring" plus the pill's
/// natural width, and a bare `Text` there overflowed the chip's own RenderFlex
/// by 13 pixels. `Flexible` is what lets the name give way instead.
class _SoonLabel extends StatelessWidget {
  const _SoonLabel(this.name);

  final String name;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(child: Text(name, overflow: TextOverflow.ellipsis)),
        const Tag('Soon'),
      ],
    );
  }
}
