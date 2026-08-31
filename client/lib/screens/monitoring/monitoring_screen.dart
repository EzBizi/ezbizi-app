import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tag.dart';
import 'pc_specs_tab.dart';

/// Monitoring — a sub-tab strip (spec §"Sub-project 1") holding PC Specs,
/// with Camera Monitoring and Desktop Monitoring declared and not built.
///
/// ── Why the other two are chips here rather than absent ────────────
///
/// The module design doc puts PC Specs first in the build order and sketches
/// the other two for later (Sub-projects 2 and 3). A tab that silently does
/// not exist reads as a permission somebody lost; this is the same "coming
/// soon" answer ComingSoonScreen and Marketing's Newsletter chip already give
/// at the size of a whole screen, applied here at the size of one chip inside
/// a single-permission module — `monitoring.view` gates the tab, not which
/// sub-tab inside it exists yet.
///
/// They carry no `onSelected` — there is no screen behind them to switch to,
/// so an interactive-looking control that goes nowhere would be worse than a
/// disabled one.
class MonitoringScreen extends StatelessWidget {
  const MonitoringScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  Widget build(BuildContext context) {
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
                  selected: true,
                  onSelected: (_) {},
                ),
                const ChoiceChip(
                  label: _SoonLabel('Camera Monitoring'),
                  selected: false,
                  onSelected: null,
                ),
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
        Expanded(child: PcSpecsTab(api: api, session: session)),
      ],
    );
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
