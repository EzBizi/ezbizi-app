import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/tab_bar_like.dart';
import 'gmail_inbox.dart';
import 'settings_tab.dart';
import 'workspace_tab.dart';

/// Email — a window onto the addresses an organization shares.
///
/// ── What this module is, in the owner's words ───────────────────
///
/// *"The difference is the capacity to let people answer emails they don't
/// regularly have access to — like company wide emails that receive customer
/// emails or forms."*
///
/// So: `info@`, `bookings@`, `careers@`. Not somebody's personal mailbox. No
/// mail is stored against a customer or a job; since design 137 a row only
/// NAMES the customer or lead the sender is, with a link to their record. Which of an organization's inboxes a role reaches is granted per
/// inbox, and the list endpoint answers with what this person may open in
/// the company they are looking at, so there is no filtering in this file
/// to get wrong.
///
/// ── Read live, and hold nothing ─────────────────────────────────
///
/// *"we don't want to store thousands and thousands of emails on our
/// database."* Headers arrive when the list is drawn and a body arrives when a
/// message is opened. Nothing here syncs, nothing mirrors a folder, and the
/// state below is a screen's worth of what is on screen — it goes when the tab
/// does.
///
/// ── Two faces: Inbox, and Settings ──────────────────────────────
///
/// *"we should have 2 sub tabs on the email module, one on the inbox itself
/// and one for it's settings."* The strip is TabBarLike, which is what HrScreen
/// draws its five with and what settings_shell and automations_screen use —
/// one destination with two faces rather than a second application inside the
/// application.
///
/// Inbox is gmail_inbox.dart (design 137, laid out like Gmail).
/// Settings is where a connection is made, replaced and taken away, and where
/// an inbox that has stopped working says why — see settings_tab.dart, which is
/// also the only place in this client that reaches the connect endpoints.
///
/// The Settings tab is not built until somebody opens it, and then it keeps its
/// State: opening Email should not cost a second request for a page most
/// readers never look at, and glancing back at the mail should not throw the
/// list of connections away. Same arrangement, and the same reasons, as HR's.
///
/// ── Why there is no "Email" heading above this ──────────────────
///
/// The sidebar already says it, in the organization's own word for the module,
/// and HrScreen makes the same call for the same reason: the product's name
/// printed twice in two inches. Which inbox a mail is in is said on the mail
/// itself — see GmailInbox.
class EmailScreen extends StatefulWidget {
  const EmailScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<EmailScreen> createState() => _EmailScreenState();
}


class _EmailScreenState extends State<EmailScreen> {
  /// Inbox's place in the strip below, and where this module opens.
  ///
  /// The mail is what somebody came for; the connections behind it are what
  /// they come for once, or on the day one breaks.
  static const _inboxTab = 'inbox';

  /// The tab being shown, BY NAME rather than by index.
  ///
  /// Indices were fine while everybody saw both. Once a tab can be hidden the
  /// index of the other one moves -- a role with only Settings would select
  /// index 0 and be shown the mail, which is the permission failing open in the
  /// one direction nobody would notice. A name cannot drift.
  String _tab = _inboxTab;

  /// Which sub-tabs have been shown at least once — HrScreen's set, for the
  /// two reasons its own note gives: a tab nobody has opened must not fetch,
  /// and a tab they have opened must not fetch again every time they glance at
  /// the other one.
  final Set<String> _shown = {_inboxTab};

  /// Every tab this screen has, with the permission key that opens it.
  ///
  /// Jose, 2026-09-17: "i should be able to grant access by sub tab as well".
  /// Each is granted separately as `email.<key>.view`, declared in
  /// `ModuleServiceProvider` and drawn in the roles editor under a Tabs
  /// heading. THE KEYS HERE AND THE DECLARATION THERE ARE THE SAME TWO, and
  /// nothing mechanical can check that across PHP and Dart — but a key with no
  /// declaration grants nobody, so the tab vanishes for everyone rather than
  /// appearing for everyone. That is the safe direction of that mistake and the
  /// one somebody reports.
  ///
  /// Labels are the registry's, copied from here rather than the reverse.
  static const _declaredTabs = <(String, String, IconData)>[
    (_inboxTab, 'Inbox', Icons.inbox_outlined),
    ('settings', 'Settings', Icons.settings_outlined),
    // UI queue 134: each connected Workspace's people and their signatures.
    ('workspace', 'Google Workspace', Icons.badge_outlined),
  ];

  /// Absent `/me` answers NO, not yes — HrScreen's rule. A null here is a load
  /// that has not finished, and "I do not know yet" must draw nothing.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('email.$tab.view') ?? false;

  List<(String, String, IconData)> get _visibleTabs =>
      [for (final tab in _declaredTabs) if (_mayOpen(tab.$1)) tab];

  @override
  Widget build(BuildContext context) {
    final tabs = _visibleTabs;

    // Neither tab granted. Said rather than drawn as an empty strip above an
    // empty pane, which reads as a screen that failed rather than as a
    // permission somebody has to ask for.
    if (tabs.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No part of Email has been given to your role yet. Somebody who '
            'manages roles can grant the Inbox or Settings tab.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    // The selected tab may have just been taken away -- a role edited while
    // somebody is looking at the screen. Fall back to the first tab they DO
    // hold rather than indexing into a list that no longer contains it.
    final current = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = current == -1 ? 0 : current;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-email-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: (index) => setState(() {
              _tab = tabs[index].$1;
              _shown.add(_tab);
            }),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Offstage rather than IndexedStack, for the reason HrScreen and
          // automations_screen both give: IndexedStack leaves its hidden child
          // in the semantics tree, so a screen reader would read out the
          // settings page while somebody is looking at their mail. Offstage
          // takes it out of layout, painting and semantics and leaves its State
          // exactly where it was.
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (_mayOpen(_inboxTab))
                Offstage(offstage: _tab != _inboxTab, child: _inbox()),
              if (_mayOpen('settings'))
                Offstage(
                  offstage: _tab != 'settings',
                  child: _shown.contains('settings')
                    ? MailboxSettingsTab(
                        api: widget.api,
                        session: widget.session,
                      )
                    : const SizedBox.shrink(),
              ),
              if (_mayOpen('workspace'))
                Offstage(
                  offstage: _tab != 'workspace',
                  child: _shown.contains('workspace')
                    ? WorkspaceTab(api: widget.api, session: widget.session)
                    : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// The mail itself, laid out like Gmail (design 137).
  Widget _inbox() => GmailInbox(api: widget.api, session: widget.session);
}
