import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'hr/contracts_tab.dart';
import 'hr/dashboard_tab.dart';
import 'hr/invitations_tab.dart';
import 'hr/onboarding_tab.dart';
import 'hr/records_tab.dart';

/// Human Resources — five tabs (HR plan).
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
/// already use. This is one destination with five faces, not an application
/// inside the application, and the sidebar has already said "Human Resources"
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
  /// Dashboard's place in [_tabs] below, and where this screen opens.
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
  static const _dashboard = 0;

  int _tab = _dashboard;

  /// Which tabs have been shown at least once.
  ///
  /// Two things are true at once and this is what satisfies both. A tab nobody
  /// has opened must not fetch — opening Human Resources would otherwise fire
  /// four requests for pages the reader may never look at. And a tab they HAVE
  /// opened must not fetch again every time they glance at another one, which
  /// is the "it reloads for every change" complaint AsyncBody's docblock is
  /// about.
  ///
  /// The stack below always has five children, so a tab's position never moves
  /// and its State survives every switch after the first; only the CONTENT of a
  /// slot arrives late.
  final Set<int> _shown = {_dashboard};

  /// The five, in the owner's order: Dashboard · Onboarding · Records ·
  /// Invitations · Contracts.
  ///
  /// One list rather than a list of labels beside a switch on the index, so a
  /// name can never end up above the wrong page. The pages are built on demand
  /// — see [_shown] — which is why the third element is a function and not a
  /// widget.
  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Dashboard',
      Icons.insights_outlined,
      () => DashboardTab(api: widget.api, session: widget.session),
    ),
    (
      'Onboarding',
      Icons.how_to_reg_outlined,
      () => OnboardingTab(api: widget.api, session: widget.session),
    ),
    (
      'Records',
      Icons.folder_shared_outlined,
      () => RecordsTab(api: widget.api, session: widget.session),
    ),
    (
      'Invitations',
      Icons.mail_outline,
      () => InvitationsTab(api: widget.api, session: widget.session),
    ),
    (
      'Contracts',
      Icons.description_outlined,
      () => ContractsTab(api: widget.api, session: widget.session),
    ),
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: _select,
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
              for (var i = 0; i < _tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? _tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
