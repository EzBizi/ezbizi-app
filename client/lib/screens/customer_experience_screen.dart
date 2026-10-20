import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/module_screens.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'business_profile_screen.dart';
import 'customer_experience/review_links_tab.dart';

/// Customer Experience — Reviews and Business Profile, moved out of Marketing
/// on 2026-09-25 (Jose).
///
/// One chip of its own, Review links (2026-10-15, on Reviews' grant). Every
/// other chip is a module the server says is drawn
/// here (`ModuleDefinition::$parent`), so their order, labels and whether they
/// appear at all come off `/me` — MarketingScreen's contract. If a chip is
/// missing, the grant is missing; this screen cannot be the reason.
/// The chips a child module is drawn as, where it is more than one: Business
/// Profile is GBP Listings and GBP Performance (Jose, 2026-10-20). Both on the
/// module's own grant; the registry test reads this to know they are the
/// module, not tabs it lacks.
const customerExperienceChildChips = {
  'business_profile': ['GBP Listings', 'GBP Performance'],
};

class CustomerExperienceScreen extends StatefulWidget {
  const CustomerExperienceScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<CustomerExperienceScreen> createState() =>
      _CustomerExperienceScreenState();
}

class _CustomerExperienceScreenState extends State<CustomerExperienceScreen> {
  /// The chip by NAME, not by index — MarketingScreen's rule and its reasons.
  String? _tab;

  @override
  void initState() {
    super.initState();
    _followLink();
    DeepLinks.pending.addListener(_followLink);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_followLink);
    super.dispose();
  }

  /// A notification about a module drawn here (a review, say) brings its chip
  /// forward; the module's own screen then takes the link and opens the record.
  void _followLink() {
    final key = DeepLinks.pending.value?.module;
    if (key == null) return;
    for (final module in widget.session.me?.reachableModules ?? const []) {
      if (module.key == key && module.parent == 'customer_experience') {
        final label = _labelOf(module.key, module.label);
        if (_tab != label) setState(() => _tab = label);
        return;
      }
    }
  }

  /// Built per frame: a module switched off or a role edited mid-session must
  /// take its chip with it rather than leave one that answers 403.
  /// Business Profile is two chips, Listings and Performance (Jose,
  /// 2026-10-20: "Reviews - GBP Listings - GBP Performance - Review Links").
  static String _labelOf(String key, String label) =>
      customerExperienceChildChips[key]?.first ?? label;

  List<(String, IconData, Widget Function())> _tabsFor(Me me) => [
    for (final module in me.reachableModules)
      if (module.parent == 'customer_experience')
        if (moduleScreens[module.key] case final screen?) ...[
          (
            _labelOf(module.key, module.label),
            screen.icon,
            () => screen.build(widget.api, widget.session),
          ),
          if (module.key == 'business_profile')
            (
              customerExperienceChildChips['business_profile']![1],
              Icons.insights_outlined,
              () => BusinessProfileScreen(
                api: widget.api,
                session: widget.session,
                performance: true,
              ),
            ),
        ],
    // What customers did with their review links (Jose, 2026-10-15). Reviews'
    // own activity, so Reviews' grant; the server asks the same.
    if (me.reaches('reviews'))
      (
        'Review links',
        Icons.ads_click_outlined,
        () => ReviewLinksTab(api: widget.api, session: widget.session),
      ),
  ];

  @override
  Widget build(BuildContext context) {
    final tabs = _tabsFor(widget.session.me!);

    if (tabs.isEmpty) {
      // Reachable only by holding `customer_experience.view` and neither child.
      // Said out loud, and naming what to ask for: an empty frame reads as broken.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Nothing inside Customer Experience is available to your role. '
            'Reviews and Business Profile are granted separately.',
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final found = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = found == -1 ? 0 : found;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('customer-experience-tabs'),
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
