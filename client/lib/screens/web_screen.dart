import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'web/seo_tab.dart';
import 'web/widgets_tab.dart';

/// Web Management — the company's websites, and what they publish.
///
/// ── Two tabs, and they are two grants ────────────────────────────
///
/// SEO is what the website SAYS — the phone number, the licence, the copy on
/// each page. Widgets is what a visitor can START on it, and every conversation
/// begun there becomes a lead. Since 2026-09-17 those are separately grantable:
/// `web_management.seo.view` and `web_management.widgets.view`, declared in
/// `ModuleServiceProvider` with these same two labels.
///
/// The strip is still a fixed list rather than one built from `/me` the way
/// Marketing builds its child modules — there are no child modules here. What
/// `/me` decides is which of the two survive into `_shownTabs`.
class WebScreen extends StatefulWidget {
  const WebScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WebScreen> createState() => _WebScreenState();
}

class _WebScreenState extends State<WebScreen> {
  /// The tab currently chosen, by KEY rather than by index.
  ///
  /// An index would mean a different pane once the list is filtered: a role
  /// without SEO would find "0" pointing at Widgets, and a saved 1 pointing at
  /// nothing. The key survives whatever the filter removes.
  String _tab = 'seo';

  /// Every tab this screen has, in the order the bar draws them.
  ///
  /// The keys are the ones declared in `ModuleServiceProvider`, and the labels
  /// are what the roles editor shows beside the tick box. They have to be the
  /// same words in both places or an administrator grants "SEO" and then hunts
  /// for a tab by that name.
  List<(String, String, IconData, Widget Function())> get _declaredTabs => [
    (
      'seo',
      'SEO',
      Icons.travel_explore_outlined,
      () => SeoTab(api: widget.api, session: widget.session),
    ),
    (
      'widgets',
      'Widgets',
      Icons.chat_bubble_outline,
      () => WidgetsTab(api: widget.api, session: widget.session),
    ),
  ];

  /// The tabs actually drawn: declared, minus the ones this role cannot open.
  ///
  /// One list, read by the bar and by the body, so the two cannot disagree
  /// about what exists.
  List<(String, String, IconData, Widget Function())> get _shownTabs => [
    for (final tab in _declaredTabs)
      if (_mayOpen(tab.$1)) tab,
  ];

  /// May this person open one named tab?
  ///
  /// Absent `/me` answers NO rather than yes. This screen is reached only with
  /// a session, so a null here is a load that has not finished or a state
  /// nobody designed, and the safe reading of "I do not know yet" is to draw
  /// nothing rather than everything.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('web_management.$tab.view') ??
      false;

  @override
  Widget build(BuildContext context) {
    final tabs = _shownTabs;

    /*
     * A role holding the module but neither tab. The sidebar row is drawn from
     * `web_management.view`, so this is reachable, and an empty Column would be
     * a blank rectangle with nothing to read. Say which permission is missing
     * instead — "nothing here" is the one message a person cannot act on.
     */
    if (tabs.isEmpty) {
      final theme = Theme.of(context);

      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'No part of Web Management has been opened to you yet.',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                'The tabs inside this module are granted one at a time — SEO, '
                'which is what your websites publish, and Widgets, which is the '
                'chat and contact form on them — and neither is on your role.',
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
      );
    }

    // Falls back to the FIRST visible tab rather than to zero-as-SEO: a role
    // that cannot open SEO would otherwise start on a pane that is not in its
    // list, and the bar would highlight nothing while the body drew nothing.
    final at = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = at < 0 ? 0 : at;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-web_management-tabs'),
            level: TabLevel.secondary,
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: (index) => setState(() => _tab = tabs[index].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[selected].$4()),
      ],
    );
  }
}
