import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/side_panel.dart';
import '../scheduling/overbooking_pane.dart';
import 'work_hours_report_panel.dart';

/// Scheduling's morning "Overbooking warning", opened from the Reports
/// library (queue 33's shape: beside the list on a desk, in place of it on a
/// phone). What it covers, the rules that send it, and the seven days the
/// email reports — read from the same endpoint and rules as Scheduling ›
/// Overbooking, so the two cannot disagree.
class OverbookingReportPanel extends StatelessWidget {
  const OverbookingReportPanel({
    super.key,
    required this.api,
    required this.session,
    required this.report,
    required this.inPlace,
    required this.onClose,
  });

  final ApiClient api;
  final Session session;

  /// The row as `GET /scheduling/reports` serves it: name, description,
  /// covers, sent_automatically, sent_by.
  final Map<String, dynamic> report;

  /// On a phone the panel takes the list's place, and its back arrow closes it.
  final bool inPlace;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final about = '${report['description'] ?? ''}';

    return SidePanel(
      title: '${report['name'] ?? 'Overbooking warning'}',
      subtitle: 'Scheduling · the morning email',
      docked: true,
      onClose: onClose,
      onBack: inPlace ? onClose : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (about.isNotEmpty) ...[
            Text(
              about,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 16),
          ],
          reportPanelSection(
            context,
            'Covers',
            Text('${report['covers'] ?? 'Every service area'}'),
          ),
          reportPanelSection(
            context,
            'Sent by',
            reportSenders(context, report, session.me!.display),
          ),
          reportPanelSection(
            context,
            'The next 7 days',
            OverbookingPane(api: api, visible: true, embedded: true),
          ),
        ],
      ),
    );
  }
}
