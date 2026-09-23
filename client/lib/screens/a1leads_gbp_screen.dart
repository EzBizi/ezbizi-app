import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/module_screens.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';

/// A1Leads GBP — Reviews and Business Profile, together and temporarily.
///
/// ── Why this exists, and when it stops existing ──────────────────
///
/// Jose, 2026-09-23: *"this is a temporary thing as we will replace his system
/// once we get our own GBP, but till then, we need to move it, which is why i
/// want it separate for now, in fact, just make it as it's own main tab."*
///
/// A1 Leads — Rodrigo's system — is still the system of record for Google
/// Business Profile, because EzBizi's own application for Business Profile API
/// access was refused on 2026-09-22 and cannot be re-filed before 26 October
/// (docs/BUSINESS-PROFILE.md §9b). Until that lands, the two screens that read
/// that world are findable together and removable together.
///
/// It is named after the system it bridges to on purpose: the name stops making
/// sense at exactly the moment the container should be deleted.
///
/// ── It holds nothing of its own ──────────────────────────────────
///
/// Every chip is a MODULE in full — its own permission, its own place in the
/// server's registry, its own triggers and glance — which the server says is
/// drawn here (`ModuleDefinition::$parent`). So neither is named in this file:
/// their order, their labels and whether they appear at all come off `/me`.
/// That is the same contract MarketingScreen has with the modules inside it,
/// and it is why moving a module between the two containers is one word on the
/// server and no change here.
///
/// The consequence worth stating: this screen can never be the reason somebody
/// cannot see Reviews. If a chip is missing, the grant is missing.
class A1LeadsGbpScreen extends StatefulWidget {
  const A1LeadsGbpScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<A1LeadsGbpScreen> createState() => _A1LeadsGbpScreenState();
}

class _A1LeadsGbpScreenState extends State<A1LeadsGbpScreen> {
  /// The chip by NAME, not by index — MarketingScreen's rule and its reasons.
  ///
  /// An index means a different chip depending on which chips are drawn, so a
  /// role edited mid-session moves the page under somebody with no interaction.
  /// Null is "whatever is first", which is also where an unknown name lands.
  String? _tab;

  /// The chips, in the server's registry order.
  ///
  /// Built per frame rather than once: EzBizi can switch a module off mid-
  /// incident (§13) and an administrator can edit the role while this is open.
  /// A `late final` list would go on offering a chip whose screen has started
  /// answering 403.
  List<(String, IconData, Widget Function())> _tabsFor(Me me) => [
    for (final module in me.reachableModules)
      if (module.parent == 'a1leads_gbp')
        if (moduleScreens[module.key] case final screen?)
          (
            module.label,
            screen.icon,
            () => screen.build(widget.api, widget.session),
          ),
  ];

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final tabs = _tabsFor(me);

    if (tabs.isEmpty) {
      /*
       * Reachable only by holding `a1leads_gbp.view` and neither child, which
       * an administrator can create from the roles editor in one tick.
       *
       * Said out loud rather than drawn as an empty frame, for the reason
       * MarketingScreen gives: an empty frame reads as the app being broken,
       * which is the one conclusion that sends somebody to the wrong person
       * for help. It also names the two things to ask for, because "ask an
       * administrator for access" without saying to what is a message that
       * cannot be acted on.
       */
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Nothing inside A1Leads GBP is available to your role. '
            'Reviews and Business Profile are granted separately.',
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    // Resolved by name every frame. A chip that is gone falls back to the first
    // one actually drawn, which cannot index past the end the way a remembered
    // position could.
    final found = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = found == -1 ? 0 : found;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('a1leads-gbp-tabs'),
            labels: [for (final tab in tabs) (tab.$1, tab.$2)],
            selected: selected,
            onSelected: (tab) => setState(() => _tab = tabs[tab].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[selected].$3()),
      ],
    );
  }
}
