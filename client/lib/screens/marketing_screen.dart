import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/module_screens.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'marketing/newsletter_tab.dart';
import 'marketing/social_media_tab.dart';

/// Marketing — its own two sub-modules, and the three modules drawn inside it.
///
/// ── Why a strip above the chips ──────────────────────────────────
///
/// The sidebar says "Marketing" and the strip names the sub-module inside it.
/// Somebody who was told "the newsletter lives under Marketing" needs to see
/// the second word to know they have arrived. It is the shape Human Resources
/// and Settings already use.
///
/// ── Two kinds of chip, and the difference matters ────────────────
///
/// Newsletter and Social Media Management are PARTS of this module: they are
/// what `marketing.view` opens, they have no permission of their own, and they
/// are written here.
///
/// Leads, Reviews and Business Profile are modules in full — their own
/// permissions, their own place in the server's registry, their own triggers
/// and their own dashboard glance — which the server says are DRAWN here
/// (ModuleDefinition::$parent). So they are not listed in this file: their
/// order, their labels and whether they appear at all come off `/me`, exactly
/// as they did when each was a row in the sidebar. The only thing that changed
/// is where they are drawn.
///
/// Which is why the strip can hold as few as one chip. Somebody whose role
/// reaches `leads.view` and nothing else in Marketing gets Leads and no
/// Newsletter, because the two grants have nothing to do with each other.
class MarketingScreen extends StatefulWidget {
  const MarketingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<MarketingScreen> createState() => _MarketingScreenState();
}

class _MarketingScreenState extends State<MarketingScreen> {
  int _tab = 0;

  /// The chips, in the owner's order.
  ///
  /// A list of (name, glyph, page) rather than a switch on the index, for the
  /// reason HrScreen gives: a name can never end up above the wrong page.
  ///
  /// Built per frame rather than once, which the fixed list did not have to be.
  /// Two of the three questions it now asks can change while somebody is
  /// looking at this screen: EzBizi switches a module off mid-incident (§13),
  /// or an administrator edits the role they hold. A `late final` list would go
  /// on offering a chip whose screen has started answering 403.
  List<(String, IconData, Widget Function())> _tabsFor(Me me) => [
    // This module's own parts, behind this module's own permission.
    if (me.reaches('marketing')) ...[
      (
        'Newsletter',
        Icons.campaign_outlined,
        () => NewsletterTab(api: widget.api, session: widget.session),
      ),
      (
        'Social Media Management',
        Icons.share_outlined,
        () => SocialMediaTab(api: widget.api, session: widget.session),
      ),
    ],
    // The modules drawn inside this one. `reachableModules` is in the server's
    // registry order, which is the order the sidebar used to show them in, so
    // the strip reads the way the rail did.
    for (final module in me.reachableModules)
      if (module.parent == 'marketing')
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
      // The shell only draws a Marketing destination when this module or
      // something inside it is reachable, so arriving here with nothing is a
      // client and a server that disagree about what was granted. Said out
      // loud: an empty frame reads as the app being broken, which is the one
      // conclusion that sends somebody to the wrong person for help.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Nothing inside Marketing is available to your role.',
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    // Clamped rather than reset, because the list can shrink under a finger:
    // losing a grant while sitting on the last chip would otherwise index past
    // the end and take the page down with it.
    final selected = _tab.clamp(0, tabs.length - 1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$1, tab.$2)],
            selected: selected,
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[selected].$3()),
      ],
    );
  }
}
