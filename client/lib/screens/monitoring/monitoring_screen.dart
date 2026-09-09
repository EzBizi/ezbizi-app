import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tag.dart';
import 'camera_monitoring_tab.dart';
import 'desktop_monitoring_tab.dart';
import 'general_tab.dart';
import 'pc_specs_tab.dart';

/// Monitoring — a sub-tab strip (spec §"Sub-project 1") holding PC Specs,
/// Desktop Monitoring, and Camera Monitoring.
///
/// ── Why a locked chip rather than an absent one ──────────────────────
///
/// A tab that silently does not exist reads as a permission somebody lost;
/// this is the same "coming soon" answer ComingSoonScreen and Marketing's
/// Newsletter chip already give at the size of a whole screen, applied here
/// at the size of one chip inside a single-permission module —
/// `monitoring.view` gates the tab, not which sub-tab inside it a given
/// viewer may open.
///
/// A locked chip carries no `onSelected` — there is no screen behind it this
/// viewer may switch to, so an interactive-looking control that goes nowhere
/// would be worse than a disabled one.
///
/// ── Desktop Monitoring's and Camera Monitoring's own gates ───────────
///
/// Their endpoints (`GET /monitoring/screenshots*`, `GET /monitoring/cameras`)
/// are gated server-side by `monitoring.screenshots.view` and
/// `monitoring.cameras.view` respectively — SEPARATE grants from `specs.view`,
/// each added in the slice its tab belongs to. Rather than let a caller who
/// lacks one click through to a tab that would just 403 on every fetch, the
/// chip itself falls back to the locked "Soon" look — [DesktopMonitoringTab]
/// and [CameraMonitoringTab] each carry their own copy of this same check too
/// (`session.me.can(...)`, see their class doc comments), the same
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

enum _SubTab { general, pcSpecs, desktopMonitoring, cameraMonitoring }

class _MonitoringScreenState extends State<MonitoringScreen> {
  _SubTab _active = _SubTab.pcSpecs;

  @override
  Widget build(BuildContext context) {
    // The General tab (floor-plan map) rides on the module's own
    // `monitoring.view` — the same grant that gates reaching this screen at
    // all — so every viewer here can open it. It is gated anyway, as the same
    // defence-in-depth the other chips use; the locked fallback is only ever
    // reached when this screen is built for somebody the module was not meant
    // to show, and GeneralTab carries its own copy of the check too.
    final canViewGeneral = widget.session.me?.can('monitoring.view') ?? false;
    final canViewScreenshots =
        widget.session.me?.can('monitoring.screenshots.view') ?? false;
    final canViewCameras =
        widget.session.me?.can('monitoring.cameras.view') ?? false;

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
                if (canViewGeneral)
                  ChoiceChip(
                    label: const Text('General'),
                    selected: _active == _SubTab.general,
                    onSelected: (_) =>
                        setState(() => _active = _SubTab.general),
                  )
                else
                  const ChoiceChip(
                    label: _SoonLabel('General'),
                    selected: false,
                    onSelected: null,
                  ),
                ChoiceChip(
                  label: const Text('PC Specs'),
                  selected: _active == _SubTab.pcSpecs,
                  onSelected: (_) => setState(() => _active = _SubTab.pcSpecs),
                ),
                if (canViewCameras)
                  ChoiceChip(
                    label: const Text('Camera Monitoring'),
                    selected: _active == _SubTab.cameraMonitoring,
                    onSelected: (_) =>
                        setState(() => _active = _SubTab.cameraMonitoring),
                  )
                else
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
        Expanded(
          child: _body(canViewGeneral, canViewScreenshots, canViewCameras),
        ),
      ],
    );
  }

  Widget _body(
    bool canViewGeneral,
    bool canViewScreenshots,
    bool canViewCameras,
  ) {
    if (_active == _SubTab.general && canViewGeneral) {
      // The General map's person summary offers a jump to Desktop Monitoring.
      // The sub-tab is not a route, so nothing below this screen can switch
      // it — the switch is handed down as a callback. It is handed down ONLY
      // with `monitoring.screenshots.view`: without it the Desktop Monitoring
      // chip is locked, and a jump to a tab you cannot open is a button that
      // can only ever fail. Null is what makes the summary show none.
      return GeneralTab(
        api: widget.api,
        session: widget.session,
        onOpenDesktopMonitoring: canViewScreenshots
            ? () => setState(() => _active = _SubTab.desktopMonitoring)
            : null,
      );
    }

    if (_active == _SubTab.desktopMonitoring && canViewScreenshots) {
      return DesktopMonitoringTab(api: widget.api, session: widget.session);
    }

    if (_active == _SubTab.cameraMonitoring && canViewCameras) {
      return CameraMonitoringTab(api: widget.api, session: widget.session);
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
