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
/// ── The switch below is a SECOND switch, and that is a debt ────────
///
/// `PhoneSettingsTab._sectionBody()` has the first one. Two switches over the
/// same ids can drift, which is the failure this file exists to avoid, so it
/// is worth being plain about why this one is here anyway and what makes it
/// survivable:
///
///   * the duplication is a list of *references to public widgets*, not a
///     reimplementation — the panes themselves stay single;
///   * a pane id this file has never heard of **falls through to a sentence
///     and a door**, never to wrong content. It degrades to "open it in
///     Settings", which is true of every pane whether or not it is listed.
///
/// The fix is one factory both callers use, and that lives in Phone's file.
/// Raised with them rather than edited in: inserting into that tab's own lists
/// broke thirteen tests earlier this week, and it is their module.
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import 'blocked_numbers_pane.dart';
import 'call_flow_chart.dart';
import 'flows_pane.dart';
import 'general_pane.dart';
import 'hours_pane.dart';
import 'numbers_pane.dart';
import 'porting_pane.dart';
import 'ring_groups_pane.dart';
import 'tags_pane.dart';
import 'trust_pane.dart';
import 'voicemail_pane.dart';

/// What a pane id is called on screen.
///
/// The words are the settings rail's own. A drawer that renamed them would
/// leave somebody hunting for a section that does not exist under that name.
const Map<String, String> phonePaneNames = {
  'general': 'General',
  'call-flow': 'Call flow',
  'numbers': 'Numbers',
  'porting': 'Porting',
  'flows': 'IVR menus',
  'hours': 'Opening hours',
  'ring-groups': 'Ring groups',
  'voicemail': 'Voicemail',
  'blocked': 'Blocked numbers',
  'trust': 'Trust',
  'tags': 'Call tags',
};

/// The real pane for an id, or null when this build does not know the id.
///
/// Null rather than a guess: a pane rendered for the wrong id is worse than a
/// door to Settings, because it looks like it worked.
Widget? phonePaneFor(String id, {required ApiClient api, required Session session}) {
  switch (id) {
    case 'general':
      return GeneralPane(api: api, session: session);
    case 'numbers':
      return NumbersPane(api: api, session: session);
    case 'porting':
      return PortingPane(api: api, session: session);
    case 'flows':
      return FlowsPane(api: api, session: session);
    case 'hours':
      return HoursPane(api: api, session: session);
    case 'ring-groups':
      return RingGroupsPane(api: api, session: session);
    case 'voicemail':
      return VoicemailPane(api: api, session: session);
    case 'blocked':
      return BlockedNumbersPane(api: api, session: session);
    case 'trust':
      return TrustPane(api: api, session: session);
    case 'tags':
      return TagsPane(api: api, session: session);
    default:
      return null;
  }
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
  });

  final FlowNode node;
  final ApiClient api;
  final Session session;
  final VoidCallback onClose;
  final double width;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final edit = node.edit;
    final pane = edit == null
        ? null
        : phonePaneFor(edit.pane, api: api, session: session);

    return SizedBox(
      width: width,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border(left: BorderSide(color: scheme.outlineVariant)),
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
                  'Settings › Phone › ${phonePaneNames[edit.pane] ?? edit.pane}',
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
      padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        children: [
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
            : 'This one is changed in Settings › Phone › '
                  '${phonePaneNames[edit.pane] ?? edit.pane}.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// The phone answer: a whole screen with a way back.
///
/// Mobile 5 rather than the inline expansion I first recommended, and the
/// reason is the desktop decision rather than a change of taste. Once the
/// drawer hosts the REAL settings panes, those panes need the width they
/// already have in Settings — expanding one inside a 380px list row would mean
/// redrawing every pane to fit a place none of them were built for, which is
/// the cost the hosting decision was supposed to avoid.
class CallFlowEditScreen extends StatelessWidget {
  const CallFlowEditScreen({
    super.key,
    required this.node,
    required this.api,
    required this.session,
  });

  final FlowNode node;
  final ApiClient api;
  final Session session;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(node.title),
        leading: BackButton(onPressed: () => Navigator.of(context).pop()),
      ),
      body: CallFlowEditDrawer(
        node: node,
        api: api,
        session: session,
        onClose: () => Navigator.of(context).pop(),
        width: double.infinity,
      ),
    );
  }
}
