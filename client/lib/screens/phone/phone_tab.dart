import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/tab_help.dart';
import 'call_tab.dart';
import 'records_tab.dart';
import 'voicemails_pane.dart';

/// Communication › Phone — the three things you do with a phone line.
///
/// ── Why Messages and Voicemail are Records, not new screens ──────
///
/// Both already existed as filters on Records, reading the same rows this
/// would. Building them again would put one fact behind two readers, which is
/// the drift this module has spent a week removing: they agree on the day they
/// are written and diverge the first time either is touched.
///
/// So a sub-tab here is the same widget with its filter fixed, and the filter
/// chips are hidden — a tab labelled Messages that also offers a Calls chip is
/// two controls for one thing, and a heading that stops being true the moment
/// somebody presses the chip.
class PhoneTab extends StatefulWidget {
  const PhoneTab({
    super.key,
    required this.api,
    required this.session,
    this.onOpenSettings,
  });

  final ApiClient api;
  final Session session;

  /// Opens Communication › Settings, for Dial's empty state.
  final VoidCallback? onOpenSettings;

  @override
  State<PhoneTab> createState() => _PhoneTabState();
}

class _PhoneTabState extends State<PhoneTab> {
  int _section = 0;

  /// Counts sub-tab changes, so a Records view refetches when it is opened.
  ///
  /// It is kept alive in the stack for the softphone's sake, which means it
  /// would otherwise keep showing whatever it fetched the first time — the
  /// staleness the switch used to avoid by rebuilding.
  int _visits = 0;

  /// ORDER IS LOAD-BEARING: `_section` is an INDEX into this list.
  ///
  /// Not a key, an index — so inserting an entry in the middle silently
  /// renumbers every pane after it, and the chips keep drawing correctly while
  /// pointing at the wrong screens. Nothing fails; it just stops being the
  /// thing you clicked.
  ///
  /// UI/UX hit exactly this in the Settings pane list on 2026-09-15, slotting
  /// a new entry beside a related one and turning thirteen tests red — and
  /// that was the lucky outcome, because tests happened to name the panes.
  /// Here the IndexedStack below maps indexes by hand (`_section <= 1 ? 0 :
  /// _section - 1`), so an insert breaks that arithmetic too.
  ///
  /// APPEND. If a new section genuinely belongs in the middle, move the
  /// IndexedStack to named sections first.
  static const _sections = <(String, IconData)>[
    ('Dial', Icons.dialpad_outlined),
    ('Team', Icons.groups_outlined),
    ('Messages', Icons.sms_outlined),
    ('Voicemail', Icons.voicemail_outlined),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The chips are this tab's strip, so (?) reads the open one from here
        // and runs phone/Phone/Dial, /Team, /Messages or /Voicemail.
        OpenTabMark(_sections[_section].$1, child: const SizedBox.shrink()),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Wrap(
            key: const ValueKey('tour-phone-sections'),
            spacing: 8,
            runSpacing: 8,
            children: [
              for (var i = 0; i < _sections.length; i++)
                ChoiceChip(
                  // Or the selected chip draws a tick ON TOP of the icon it
                  // already has, which is the house gate that caught this.
                  showCheckmark: false,
                  avatar: Icon(
                    _sections[i].$2,
                    size: 16,
                    color: i == _section
                        ? theme.colorScheme.onSecondaryContainer
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                  label: Text(_sections[i].$1),
                  selected: i == _section,
                  onSelected: (_) => setState(() {
                    _section = i;
                    _visits++;
                  }),
                ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),

        // ── Why a stack, and why the desk is ONE child of it ─────
        //
        // The softphone lives inside CallTab. Rebuilding the sub-tabs would
        // tear it down and register it again every time somebody glanced at
        // Messages — and a softphone that deregisters when you look away is a
        // softphone that misses calls.
        //
        // Dial and Team are therefore the SAME CallTab, told which face to
        // show, sitting at one index. Records is built fresh per section
        // because it holds no connection and its rows go stale: a call ends,
        // a text is sent, and a list showing what it fetched an hour ago is
        // quietly wrong.
        Expanded(
          child: IndexedStack(
            index: _section <= 1 ? 0 : _section - 1,
            children: [
              CallTab(
                api: widget.api,
                session: widget.session,
                view: _section == 1 ? CallView.team : CallView.dial,
                onOpenSettings: widget.onOpenSettings,
              ),
              RecordsTab(
                api: widget.api,
                session: widget.session,
                initialFilter: RecordsFilter.sms,
                showFilters: false,
                reloadToken: _section == 2 ? _visits : 0,
              ),
              // Voicemail is boxes, not a filtered call list: a flat list
              // answers "did anybody ring" and not "did anybody ring ME",
              // which on a shared account is the question.
              VoicemailsPane(
                api: widget.api,
                session: widget.session,
                reloadToken: _section == 3 ? _visits : 0,
              ),
            ],
          ),
        ),
      ],
    );
  }
}
