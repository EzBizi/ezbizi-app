import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'hr/contracts_tab.dart';
import 'hr/invitations_tab.dart';
import 'hr/records_tab.dart';

/// Human Resources — five tabs, three of which are built (HR plan).
///
/// The owner's words: *"this is the most integral part of the app as it allows
/// its basic function."* It is what lets an organization have employees at all —
/// roles have existed for a while and nothing has ever written `users.role_id`,
/// so an organization has been exactly one person no matter how many roles it
/// defined.
///
/// ── Why two of the tabs are drawn and do nothing ─────────────────
///
/// Because that is this codebase's answer, and it is the honest one. An
/// automation action the engine cannot run is listed, badged "Not built yet",
/// and refuses the tap (see widgets/catalogue_picker.dart); a module the
/// platform has switched off is drawn greyed in the role editor with the reason
/// beside it. The rule underneath all three: something absent invites the reader
/// to conclude the app is broken or that they have lost a permission, and both
/// guesses are worse than the truth.
///
/// So Dashboard and Onboarding appear in the owner's order and each says what
/// it is waiting for. A tab that vanishes reads as broken; a tab that explains
/// itself reads as unfinished, which is what it is.
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
  /// Records' place in [_tabs] below, and where this screen opens.
  ///
  /// Not tab zero. Landing on Dashboard would make everybody's first sight of
  /// Human Resources a placeholder, which says the module does not work rather
  /// than that one tab does not yet. So it opens on the first tab in the
  /// owner's order that actually does something, and the unbuilt ones are found
  /// by looking rather than by arriving.
  static const _records = 2;

  int _tab = _records;

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
  final Set<int> _shown = {_records};

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
      () => const NotBuiltYet(
        title: 'Dashboard',
        icon: Icons.insights_outlined,
        waitingFor:
            'It counts the things this module is about: headcount by place, '
            'onboardings in flight, invitations that have stalled, expiring '
            'certifications, who is starting and who is leaving. None of those '
            'exist to be counted until people are being taken on, so it is the '
            'last piece of Human Resources rather than the first.',
      ),
    ),
    (
      'Onboarding',
      Icons.how_to_reg_outlined,
      () => const NotBuiltYet(
        title: 'Onboarding',
        icon: Icons.how_to_reg_outlined,
        waitingFor:
            'Inviting somebody works today — that is the Invitations tab, and '
            'it is enough for office staff, a co-owner, or anybody who just '
            'needs a login. Onboarding is the richer path: the form both sides '
            'fill in, the employer starting it and the employee completing and '
            'correcting it. The Contracts tab now decides which questions that '
            'form asks; what is missing is the form itself, and somewhere to '
            'keep the answers it collects.',
      ),
    ),
    (
      'Records',
      Icons.folder_shared_outlined,
      () => RecordsTab(api: widget.api),
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

/// A tab that is part of the plan and is not built yet.
///
/// Shown, marked, and saying what it is waiting for. The badge is the same word
/// the automation builder uses for an action the engine cannot run, on purpose:
/// somebody who has met one of these knows what the other one means.
///
/// Public because it is a promise about how this codebase treats unfinished
/// work, not a detail of one screen — the next module with a tab ahead of its
/// dependencies should reach for this rather than inventing a second way of
/// saying the same thing.
class NotBuiltYet extends StatelessWidget {
  const NotBuiltYet({
    super.key,
    required this.title,
    required this.icon,
    required this.waitingFor,
  });

  final String title;
  final IconData icon;

  /// What has to exist before this can. In prose, and specific: "coming soon"
  /// tells a reader nothing they had not already worked out from the empty page.
  final String waitingFor;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 34, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(height: 16),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(title, style: theme.textTheme.titleMedium),
                  const Tag('Not built yet', warn: true),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                waitingFor,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
