import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'inspection/builder_tab.dart';
import 'inspection/new_tab.dart';
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

  /// Owner's order, filtered by TWO rules that are not the same rule.
  ///
  /// `inspection.<tab>.view` says whether this person has the tab at all —
  /// Jose, 2026-09-17: "i should be able to grant access by sub tab as well".
  /// The capability beside it (`create`, `view`, `edit`) says whether the
  /// endpoints behind that tab will do anything, and is unchanged.
  ///
  /// Both, deliberately. Dropping the capability check would hand somebody a
  /// Builder whose every save is refused by the server, which is a worse
  /// experience than not having the tab. Dropping the tab check would ignore
  /// what was actually asked for.
  ///
  /// The migration grants every existing role the tabs it could already open,
  /// so nobody loses one they had this morning.
  List<(String, IconData, Widget Function())> get _tabs {
    final can = widget.session.me!.can;
    return [
      if (_mayOpen('new') && can('inspection.create'))
        (
          'New Inspection',
          Icons.fact_check_outlined,
          () => NewInspectionTab(api: widget.api, session: widget.session),
        ),
      if (_mayOpen('reports') && can('inspection.view'))
        (
          'Generated Reports',
          Icons.article_outlined,
          () => ReportsTab(api: widget.api, session: widget.session),
        ),
      if (_mayOpen('builder') && can('inspection.edit'))
        (
          'Builder',
          Icons.dashboard_customize_outlined,
          () => BuilderTab(api: widget.api, session: widget.session),
        ),
    ];
  }

  /// Absent `/me` answers NO.
  ///
  /// A null here is a load that has not finished, or a state nobody designed.
  /// The safe reading of "I do not know yet" is to draw nothing — drawing every
  /// tab and letting the server refuse would flash a pane somebody may not have
  /// and is how a permission becomes a decoration.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('inspection.$tab.view') ?? false;

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
            key: const ValueKey('tour-inspection-tabs'),
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
