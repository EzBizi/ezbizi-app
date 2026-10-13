import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'hr/contracts_tab.dart';
import 'hr/dashboard_tab.dart';
import 'hr/invitations_tab.dart';
import 'hr/onboarding_tab.dart';
import 'hr/records_tab.dart';

/// Human Resources — five tabs (HR plan), plus Clock in–out when the
/// organization has turned Clock in on.
///
/// The owner's words: *"this is the most integral part of the app as it allows
/// its basic function."* It is what lets an organization have employees at all.
/// Roles existed for a while before it, and the only thing that ever wrote
/// `users.role_id` was ProvisionOrganization putting the founding administrator
/// in one — so an organization was exactly one person no matter how many roles
/// it defined. This module is what closed that: an invitation carries a role,
/// InvitationAcceptanceController::createAccount writes it onto the account it
/// creates, and the Records tab can change it afterwards.
///
/// ── All five are built, and one panel inside one of them is not ──
///
/// Dashboard was the last placeholder and is now real. The convention it used
/// has not gone away, it has moved down a level: the HR plan names five figures
/// for that tab and "expiring certifications" cannot be computed, because a
/// contract field has no way to say that a date IS an expiry. So the PANEL is
/// drawn, badged "Not built yet", and says what it is waiting for — the same
/// answer an automation action the engine cannot run gets in
/// widgets/catalogue_picker.dart, and a module the platform has switched off
/// gets in the role editor.
///
/// The rule underneath all three: something absent invites the reader to
/// conclude the app is broken or that they have lost a permission, and both
/// guesses are worse than the truth.
///
/// ── The tab strip, not a second sidebar ──────────────────────────
///
/// Chips above the page, the shape settings_shell.dart and automations_screen
/// already use. This is one destination with a handful of faces, not an
/// application inside the application, and the sidebar has already said
/// "Human Resources"
/// — repeating it in a header above the strip would be the product's name
/// printed twice in two inches.
class HrScreen extends StatefulWidget {
  const HrScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<HrScreen> createState() => _HrScreenState();
}

class _HrScreenState extends State<HrScreen> {
  /// Dashboard's place in [_declaredTabs] below, and where this screen opens.
  ///
  /// The rule has always been "the first tab in the owner's order that actually
  /// does something", and the landing tab has moved twice under it: Records
  /// while Onboarding was a placeholder, Onboarding while Dashboard was one, and
  /// now Dashboard, which is where the rule pointed all along. Leaving it on
  /// Onboarding would have made the sentence above false — which is the reason
  /// this constant is written as the rule rather than as a number somebody has
  /// to remember to revisit.
  ///
  /// It is also the right landing tab on its own merits: it is the only one that
  /// says how the module is doing rather than asking the reader to go and find
  /// out, and its empty state names the next thing to do.
  static const _dashboard = 'dashboard';

  /// The tab being looked at, BY KEY rather than by position.
  ///
  /// It was an index until 2026-09-17, and the filter added that day is what
  /// made an index wrong: the list's length now depends on what the reader may
  /// open, so slot 1 is Onboarding for one person and Records for another. Two
  /// other modules found the same defect in their own screens within the hour
  /// — Scheduling's visibility check was `_tab == 2`, which told the only
  /// visible pane it was off-screen, and Automations moved to keys for the same
  /// reason. A screen that decides which pane is live by position is one grant
  /// away from opening the wrong one.
  ///
  /// The docblock under `_shown` used to say "a tab's position never moves".
  /// That was true when it was written and my own change falsified it.
  String _tab = _dashboard;

  /// Which tabs have been shown at least once.
  ///
  /// Two things are true at once and this is what satisfies both. A tab nobody
  /// has opened must not fetch — opening Human Resources would otherwise fire
  /// four requests for pages the reader may never look at. And a tab they HAVE
  /// opened must not fetch again every time they glance at another one, which
  /// is the "it reloads for every change" complaint AsyncBody's docblock is
  /// about.
  ///
  /// Keyed, for the reason `_tab` is: a set of POSITIONS would mark a
  /// different pane as already-loaded for two people holding different grants.
  final Set<String> _shown = {_dashboard};

  /// Owner's order: Dashboard · Onboarding · Records · Invitations ·
  /// Contracts · Clock in–out. The sixth chip is absent unless the
  /// organization has turned Clock in on (and this person holds `hr.view`).
  ///
  /// Every tab this screen has, with the permission key that opens it.
  ///
  /// ── Why the key is in the list rather than checked at each site ──
  ///
  /// Jose, 2026-09-17: "i want them to see their work hours, but not the
  /// onboarding information". Each of these is now granted separately, as
  /// `hr.<key>.view` — declared in ModuleServiceProvider, drawn in the roles
  /// editor under a Tabs heading, and enforced by the controllers behind them.
  ///
  /// THE KEYS HERE AND THE TABS DECLARED IN THE REGISTRY ARE THE SAME SEVEN,
  /// and nothing mechanical can check that from this side: the registry is PHP
  /// and this is Dart. What keeps them together is that a key with no
  /// declaration grants nothing — so the tab silently disappears for everyone
  /// rather than appearing for everyone, which is the safe direction of that
  /// mistake and the one somebody notices.
  ///
  /// The labels are UiStrings/literals unchanged from before, and the registry
  /// copies them rather than the reverse: an administrator ticking "Work Hours"
  /// must find a tab with that word on it.
  List<(String, String, IconData, Widget Function())> get _declaredTabs => [
    (
      'dashboard',
      'Dashboard',
      Icons.insights_outlined,
      () => DashboardTab(api: widget.api, session: widget.session),
    ),
    // Work Hours and Paid time off left for their own module on 2026-09-24
    // (work_hours_screen.dart).
    // Order is Mia's, 2026-10-02: Records, Contracts, Invitations,
    // Onboarding.
    (
      'records',
      'Records',
      Icons.folder_shared_outlined,
      () => RecordsTab(api: widget.api, session: widget.session),
    ),
    (
      'contracts',
      'Contracts',
      Icons.description_outlined,
      () => ContractsTab(api: widget.api, session: widget.session),
    ),
    (
      'invitations',
      'Invitations',
      Icons.mail_outline,
      () => InvitationsTab(api: widget.api, session: widget.session),
    ),
    (
      'onboarding',
      'Onboarding',
      Icons.how_to_reg_outlined,
      () => OnboardingTab(
        api: widget.api,
        session: widget.session,
        link: _onboardingLink,
      ),
    ),
  ];

  /// The tabs actually drawn: declared, minus the ones this role cannot open.
  ///
  /// One list, read by the bar and by the stack, so the two cannot disagree
  /// about what exists.
  List<(String, String, IconData, Widget Function())> get _shownTabs => [
    for (final tab in _declaredTabs)
      if (_mayOpen(tab.$1)) tab,
  ];

  /// The tab actually on screen: `_tab` if they may open it, else the first
  /// one they may.
  ///
  /// `_tab` starts at `dashboard` because that is the right landing pane for
  /// almost everybody — and a role granted Records and Contracts and nothing
  /// else holds no such tab. THE FIRST VERSION OF THIS FIX FELL BACK IN THE
  /// BAR ONLY: the bar highlighted the first tab while the stack compared
  /// every pane against a key nobody had, so all of them stayed offstage and
  /// the screen rendered blank. A widget test with two grants and neither of
  /// them Dashboard caught it; the twenty-six tests that grant all seven
  /// passed against it.
  ///
  /// So the fallback belongs HERE, where both readers get it, rather than in
  /// the index conversion where only one did.
  String get _activeTab =>
      _shownTabs.any((tab) => tab.$1 == _tab) || _shownTabs.isEmpty
      ? _tab
      : _shownTabs.first.$1;

  /// Where the active tab sits in what is drawn, for the bar alone.
  int get _selectedIndex {
    final at = _shownTabs.indexWhere((tab) => tab.$1 == _activeTab);

    return at < 0 ? 0 : at;
  }

  /// May this person open one named tab?
  ///
  /// Absent `/me` answers NO rather than yes. The screen is reached only with a
  /// session, so a null here is a load that has not finished or a state nobody
  /// designed — and the safe reading of "I do not know yet" is to draw nothing,
  /// not to draw everything.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('hr.$tab.view') ?? false;

  void _select(String tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  /// The onboarding a tapped notification named (Notifications, 2026-10-12:
  /// "an onboarding notification opens that onboarding"). Handed to the tab
  /// as the link itself, so tapping the same one twice opens it twice.
  DeepLink? _onboardingLink;

  @override
  void initState() {
    super.initState();
    _takeLink();
    DeepLinks.pending.addListener(_linkArrived);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_linkArrived);
    super.dispose();
  }

  void _takeLink() {
    final link = DeepLinks.take('hr');
    if (link == null || link.type != 'onboarding') return;
    _onboardingLink = link;
    _tab = 'onboarding';
    _shown.add('onboarding');
  }

  void _linkArrived() => setState(_takeLink);

  /// `hr.view` and not one tab inside it — a blank module, said out loud.
  ///
  /// ── This state is reachable by a role somebody makes tomorrow ────
  ///
  /// Measured on 2026-09-17 rather than assumed: `hr.view` is offered in the
  /// roles editor as its own tick, beside the seven tabs and independent of
  /// them, and a user holding exactly `hr.view` comes back from
  /// `PermissionCatalogue::modulesFor` as `reachable: true`. So Human Resources
  /// is in their navigation and every tab inside it is filtered out — a pane
  /// with nothing in it and no error anywhere.
  ///
  /// The migration that introduced tabs granted all seven to every role that
  /// already held `hr.view`, so nobody lost a tab that morning. It cannot cover
  /// a role created after it ran, and an administrator who ticks Human
  /// Resources without ticking a tab has made exactly this.
  ///
  /// So the screen says which of the two it is. A blank pane reads as broken
  /// software and sends somebody hunting through a product that is working as
  /// configured; this names the cause and the place it is changed, and — the
  /// part that matters for the person reading it — says plainly that they
  /// cannot fix it themselves.
  Widget _noTabGranted(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'No part of Human Resources has been opened to you yet.',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                'The tabs inside this module are granted one at a time — '
                'Records, Onboarding and the rest — and none of them is '
                'on your role.',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
              Text(
                'Somebody who can edit roles adds them under Settings › Roles & '
                'permissions. This is not something you can turn on for '
                'yourself, and nothing here is broken.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_shownTabs.isEmpty) return _noTabGranted(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-hr-tabs'),
            labels: [for (final tab in _shownTabs) (tab.$2, tab.$3)],
            // TabBarLike speaks positions, so the conversion happens HERE and
            // nowhere else: one place that knows both, rather than an index
            // leaking back into the state it was just taken out of.
            selected: _selectedIndex,
            onSelected: (i) => _select(_shownTabs[i].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Offstage rather than IndexedStack, for the reason automations_screen
          // gives: IndexedStack keeps its hidden children merely invisible, so
          // they stay in the semantics tree and a screen reader would read out
          // four other tabs while somebody is looking at one. Offstage takes
          // them out of layout, painting and semantics and leaves their State
          // exactly where it was.
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (final tab in _shownTabs)
                Offstage(
                  offstage: tab.$1 != _activeTab,
                  child: _shown.contains(tab.$1) || tab.$1 == _activeTab
                      ? tab.$4()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
