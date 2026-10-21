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
/// A module in full — its own permission, its own place in the server's
/// registry, its own triggers and its own dashboard glance — which the server
/// says is DRAWN here (ModuleDefinition::$parent). Such modules are not listed
/// in this file: their order, their labels and whether they appear at all come
/// off `/me`, exactly as they did when each was a row in the sidebar. The only
/// thing that changes is where they are drawn.
///
/// THE THREE THAT USED TO BE HERE HAVE ALL LEFT, and the loop stays because
/// that is the point of it. Leads moved out on 2026-09-03; Reviews and Business
/// Profile moved into Customer Experience on 2026-09-25. Nothing in this file named
/// them, so nothing in this file changed on either day — which is the property
/// worth keeping rather than replacing the loop with two hard-coded chips.
///
/// Which is why the strip can hold as few as one chip, and why the list is
/// rebuilt per frame rather than fixed. Somebody whose role reaches
/// `newsletter.view` and nothing else in Marketing gets Newsletter and no
/// Social Media Management, because the two grants have nothing to do with
/// each other.
///
/// The example used to be `leads.view`, which stopped being true on
/// 2026-09-03 when Leads left — the sentence was right and its illustration
/// named a module that is not drawn here any more. Kept at Marketing's own
/// request when Reviews and Business Profile left too, because it is the fact
/// that stops somebody "simplifying" the per-frame rebuild back into a fixed
/// list.
class MarketingScreen extends StatefulWidget {
  const MarketingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<MarketingScreen> createState() => _MarketingScreenState();
}

class _MarketingScreenState extends State<MarketingScreen> {
  /// The chip by NAME, not by index.
  ///
  /// Settings found this in three modules while rolling out per-tab
  /// permissions, and the symptom is a blank or wrong pane rather than a
  /// refusal, so nothing errors. An index means a different chip depending on
  /// which chips are drawn: somebody on Social Media at index 0 who is granted
  /// `newsletter.view` mid-session finds index 0 is now Newsletter, and the
  /// page changes under them with no interaction.
  ///
  /// Null is "whatever is first", which is also where an unknown name lands —
  /// the chip this was on has gone, so the fallback has to be a chip that
  /// exists rather than the position it used to occupy.
  String? _tab;

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
    // First, so Marketing opens on it (Jose, 2026-10-21: "make social media
    // management the default tab in marketing instead of newsletter").
    // Social Media Management is Marketing's own pane and the only one, which
    // is why this module declares no tabs of its own: a `marketing.social.view`
    // would be a checkbox that is never ticked apart from `marketing.view`.
    if (me.reaches('marketing'))
      (
        'Social Media Management',
        Icons.share_outlined,
        () => SocialMediaTab(api: widget.api, session: widget.session),
      ),
    /*
     * Newsletter is its own MODULE with its own grant, so it asks for that
     * grant and not Marketing's.
     *
     * It used to sit inside `reaches('marketing')` with Social Media, which
     * offered the chip to anybody holding `marketing.view` and then let
     * NewslettersController refuse every request behind it with "You do not
     * have permission to open Newsletter." A tab that is drawn and then says no
     * is worse than an absent one: the person can see the thing they are being
     * refused, so they read it as broken rather than as not theirs.
     *
     * The reverse was true too and is the half that loses work — a role granted
     * `newsletter.view` and nothing else in Marketing got NO chip at all, since
     * the loop below only draws modules that have a `moduleScreens` entry and
     * Newsletter has none. It is drawn here by name.
     */
    if (me.reaches('newsletter'))
      (
        'Newsletter',
        Icons.campaign_outlined,
        () => NewsletterTab(api: widget.api, session: widget.session),
      ),
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

    // Resolved by name every frame. A chip that is gone falls back to the
    // first one that is actually drawn, which cannot index past the end the
    // way a remembered position could.
    final found = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = found == -1 ? 0 : found;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-marketing-tabs'),
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
