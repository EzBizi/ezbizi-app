import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import 'call_tab.dart';
import 'records_tab.dart';

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
  const PhoneTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PhoneTab> createState() => _PhoneTabState();
}

class _PhoneTabState extends State<PhoneTab> {
  int _section = 0;

  static const _sections = <(String, IconData)>[
    ('Dial', Icons.dialpad_outlined),
    ('Messages', Icons.sms_outlined),
    ('Voicemail', Icons.voicemail_outlined),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Wrap(
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
                  onSelected: (_) => setState(() => _section = i),
                ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),

        // Built one at a time rather than kept alive in a stack, so leaving
        // Messages and coming back refetches. Several screens write these rows
        // — a call ends, a text is sent from the desk — and a list showing what
        // it fetched when the tab first opened is a list that is quietly wrong.
        Expanded(
          child: switch (_section) {
            1 => RecordsTab(
              api: widget.api,
              session: widget.session,
              initialFilter: RecordsFilter.sms,
              showFilters: false,
            ),
            2 => RecordsTab(
              api: widget.api,
              session: widget.session,
              initialFilter: RecordsFilter.voicemail,
              showFilters: false,
            ),
            _ => CallTab(api: widget.api, session: widget.session),
          },
        ),
      ],
    );
  }
}
