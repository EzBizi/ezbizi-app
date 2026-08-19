import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/dashboard_card.dart';
import '../widgets/tab_bar_like.dart';
import 'inspection/builder_tab.dart';
import 'inspection/reports_tab.dart';

/// Inspection — New Inspection, Generated Reports, Builder.
///
/// One destination with three faces, the same strip Human Resources and Email
/// use. The sidebar already says Inspection, so there is no heading above the
/// chips repeating it.
///
/// Chips the person cannot use are not drawn. The tab itself is `inspection.view`;
/// starting one is `create`; authoring named inspections is `edit`. Archive and
/// discard are actions on a report or a draft, not destinations.
class InspectionScreen extends StatefulWidget {
  const InspectionScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<InspectionScreen> createState() => _InspectionScreenState();
}

class _InspectionScreenState extends State<InspectionScreen> {
  late int _tab;

  @override
  void initState() {
    super.initState();
    _tab = 0;
  }

  /// Owner's order. Filtered to the actions this role actually holds.
  List<(String, IconData, Widget Function())> get _tabs {
    final can = widget.session.me!.can;
    return [
      if (can('inspection.create'))
        (
          'New Inspection',
          Icons.fact_check_outlined,
          () => const _NewInspectionTab(),
        ),
      if (can('inspection.view'))
        (
          'Generated Reports',
          Icons.article_outlined,
          () => ReportsTab(api: widget.api, session: widget.session),
        ),
      if (can('inspection.edit'))
        (
          'Builder',
          Icons.dashboard_customize_outlined,
          () => BuilderTab(api: widget.api, session: widget.session),
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    final selected = tabs.isEmpty ? 0 : _tab.clamp(0, tabs.length - 1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$1, tab.$2)],
            selected: selected,
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs.isEmpty ? const SizedBox.shrink() : tabs[selected].$3()),
      ],
    );
  }
}

/// Search is Task 8. Until then the pane says what it is waiting for: a client
/// or a job, not a missing permission.
class _NewInspectionTab extends StatelessWidget {
  const _NewInspectionTab();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.all(DashboardCard.padding),
      child: DashboardNote(
        'Search for a client by name, or a job by its number, to start.',
      ),
    );
  }
}
