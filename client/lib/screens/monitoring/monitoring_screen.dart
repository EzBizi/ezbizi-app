import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import 'camera_monitoring_tab.dart';
import 'desktop_monitoring_tab.dart';
import 'general_tab.dart';
import 'ownership_tab.dart';
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

enum _SubTab { general, map, pcSpecs, desktopMonitoring, cameraMonitoring }

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

  @override
  Widget build(BuildContext context) {
    // Each chip is its own grant now (TabDefinition, 2026-09-17). Absent
    // `/me` answers NO rather than yes, for every one of them.
    //
    // `monitoring.general.view` is the one key this module MINTED — the
    // floor-plan map rode on plain `monitoring.view` until today, so every
    // role that held that keeps the map by backfill
    // (2026_09_17_213000). The other three name grants this module already
    // had, which is why none of them is backfilled: they were never part of
    // `monitoring.view` to restore.
    //
    // PC Specs is NOT filtered here, and deliberately. Its action is the
    // module's own `view`, so anybody who can reach this screen can open it —
    // which is also what keeps this strip from ever being empty, and `_body`
    // from ever falling through to a pane nobody may see.
    final canViewGeneral =
        widget.session.me?.can('monitoring.general.view') ?? false;
    final canViewMap = widget.session.me?.can('monitoring.map.view') ?? false;
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
                  const _LockedChip('General'),
                if (canViewMap)
                  ChoiceChip(
                    label: const Text('Map'),
                    selected: _active == _SubTab.map,
                    onSelected: (_) => setState(() => _active = _SubTab.map),
                  )
                else
                  const _LockedChip('Map'),
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
                  const _LockedChip('Camera Monitoring'),
                if (canViewScreenshots)
                  ChoiceChip(
                    label: const Text('Desktop Monitoring'),
                    selected: _active == _SubTab.desktopMonitoring,
                    onSelected: (_) =>
                        setState(() => _active = _SubTab.desktopMonitoring),
                  )
                else
                  const _LockedChip('Desktop Monitoring'),
              ],
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: _body(
            canViewGeneral,
            canViewMap,
            canViewScreenshots,
            canViewCameras,
          ),
        ),
      ],
    );
  }

  Widget _body(
    bool canViewGeneral,
    bool canViewMap,
    bool canViewScreenshots,
    bool canViewCameras,
  ) {
    if (_active == _SubTab.general && canViewGeneral) {
      return OwnershipTab(api: widget.api, session: widget.session);
    }

    if (_active == _SubTab.map && canViewMap) {
      // The Map's person summary offers a jump to Desktop Monitoring.
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

/// A tab this person is not granted.
///
/// ── Why it no longer says "Soon" ─────────────────────────────────
///
/// It used to carry a `Soon` pill, borrowed from ComingSoonScreen, and that
/// was a false sentence even before tabs became grantable: Camera Monitoring
/// and Desktop Monitoring both shipped long ago, and the only reason this chip
/// is locked is that the reader's role does not hold the grant. Telling
/// somebody a built feature is coming soon sends them to wait for something
/// that already exists instead of to the person who can tick the box.
///
/// The chip STAYS rather than being filtered out, which is the opposite of
/// what most of the sweep did. Monitoring's tabs are few and named after
/// things people ask for by name — "can I see the cameras" — so showing the
/// name and refusing it beats a strip that silently varies by role, where
/// somebody comparing screens with a colleague cannot tell a missing
/// permission from a missing feature.
///
/// `Wrap` (this strip's parent) hands each chip a BOUNDED max width, and three
/// chips at 375px is tighter than "Desktop Monitoring" — a bare `Text` here
/// overflowed the chip's own RenderFlex by 13 pixels, so the name must be able
/// to give way.
class _LockedChip extends StatelessWidget {
  const _LockedChip(this.name);

  final String name;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: 'Your role does not include this tab.',
      child: ChoiceChip(
        label: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(child: Text(name, overflow: TextOverflow.ellipsis)),
            const SizedBox(width: 6),
            Icon(
              Icons.lock_outline,
              size: 14,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ],
        ),
        selected: false,
        onSelected: null,
      ),
    );
  }
}
