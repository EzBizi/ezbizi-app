/// Changing a box from the chart (Jose, 2026-09-16).
///
/// His words: *"if i were to click on the ring group at the top as u can see
/// in the image, it should open a right sidebar with the list of members, so i
/// can assign - unassign, so basically, every settings needs to be **synced**
/// and manageable from here as well"*.
///
/// ── Why this hosts the real pane rather than drawing its own ───────
///
/// **Because he said synced.** He named a property, not a layout. A drawer
/// with its own members list is nicer to use and is exactly the thing that
/// goes out of sync: the day somebody adds a field to ring groups in Settings,
/// a bespoke drawer goes on showing yesterday's fields and **nothing fails** —
/// no test, no error, just two editors that have quietly stopped agreeing.
///
/// So the drawer opens the same `RingGroupsPane` the settings tab opens. There
/// is one ring-group editor in this app and this is a second door onto it.
///
/// ── There is ONE list of panes, and it is not here ────────────────
///
/// This file used to carry a second switch over pane ids, with
/// `PhoneSettingsTab._sectionBody()` carrying the first. UI/UX wrote that up
/// as a debt rather than leaving it silent, and they were right to: two
/// switches over the same ids drift, and the day a pane is added to Settings
/// the drawer goes on not knowing about it with nothing failing.
///
/// It is now `phoneSettingsPane()` in `settings_tab.dart`, called by the rail
/// and by this drawer. A pane that needs more than an api and a session comes
/// back null and this falls through to a sentence and a door — "open it in
/// Settings", which is true of every pane whether or not it is listed.
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import 'call_flow_chart.dart';
import 'settings_tab.dart';

/// Where to go and find this pane again, said in the rail's own words.
///
/// The words come from `phoneSettingsPaneLabel`, which reads the settings
/// rail's own sections. This file used to keep a map of them, and it had
/// drifted exactly as a second copy does: four ids missing, and four more
/// spelled differently from the words on screen -- "Trust" for a section
/// called "Trust & caller identity". A trail that renames a section leaves
/// somebody hunting for one that does not exist.
///
/// Unknown id, honest breadcrumb: the trail STOPS rather than inventing a
/// section. It used to fall back to the raw id, and once Phone's factory
/// started serving the rail as well, `connection` and `cost` began reaching
/// this drawer -- which would have read "Settings > Phone > cost" for a
/// section called Cost. A shorter true trail beats a longer invented one.
String phonePaneTrail(String id) {
  final name = phoneSettingsPaneLabel(id);

  return name == null ? 'Settings › Phone' : 'Settings › Phone › $name';
}

/// The drawer itself: a header saying which box you opened, a line saying
/// where you are, and the pane.
class CallFlowEditDrawer extends StatelessWidget {
  const CallFlowEditDrawer({
    super.key,
    required this.node,
    required this.api,
    required this.session,
    required this.onClose,
    this.width = 380,
    this.asPage = false,
  });

  final FlowNode node;
  final ApiClient api;
  final Session session;
  final VoidCallback onClose;
  final double width;

  /// Drawn in the chart's place on a narrow pane rather than beside it: no
  /// rule down its left, and a back arrow where the drawer has its close
  /// button, because on a phone this IS the screen until somebody goes back.
  final bool asPage;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final edit = node.edit;
    final pane = edit == null
        ? null
        : phoneSettingsPane(edit.pane, api: api, session: session);

    return SizedBox(
      width: width,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surface,
          border: asPage
              ? null
              : Border(left: BorderSide(color: scheme.outlineVariant)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(context),
            if (edit != null)
              Container(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(color: scheme.outlineVariant),
                  ),
                ),
                // Somebody changing a ring group from a chart should still
                // know they changed a ring group, and where to find it again.
                child: Text(
                  phonePaneTrail(edit.pane),
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),
            Expanded(child: pane ?? _noPane(context)),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      padding: EdgeInsets.fromLTRB(asPage ? 4 : 16, 12, 8, 12),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
          if (asPage)
            IconButton(
              onPressed: onClose,
              icon: const Icon(Icons.arrow_back),
              tooltip: 'Back to the call flow',
            ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(node.title, style: theme.textTheme.titleSmall),
                if (node.detail.isNotEmpty)
                  Text(
                    node.detail,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
          if (!asPage)
            IconButton(
              onPressed: onClose,
              icon: const Icon(Icons.close),
              iconSize: 18,
              tooltip: 'Close',
            ),
        ],
      ),
    );
  }

  /// Nothing to edit, or nothing this build knows how to open.
  ///
  /// Two different sentences, because they are two different facts and the
  /// second one is a gap in this file rather than in the flow.
  Widget _noPane(BuildContext context) {
    final theme = Theme.of(context);
    final edit = node.edit;

    return Padding(
      padding: const EdgeInsets.all(20),
      child: Text(
        edit == null
            ? 'This step is part of how the call arrives, so there is nothing '
                  'to change on it here.'
            : 'This one is changed in ${phonePaneTrail(edit.pane)}.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}
