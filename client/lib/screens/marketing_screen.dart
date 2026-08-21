import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/dashboard_card.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'marketing/social_media_tab.dart';

/// Marketing — Newsletter (not built) and Social Media Management (started).
///
/// ── Why a strip above the chips ──────────────────────────────────
///
/// The sidebar says "Marketing" and the strip names the sub-module inside it.
/// Somebody who was told "the newsletter lives under Marketing" needs to see
/// the second word to know they have arrived. It is the shape Human Resources
/// and Settings already use, so another sub-module is a line in [_tabs].
class MarketingScreen extends StatefulWidget {
  const MarketingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<MarketingScreen> createState() => _MarketingScreenState();
}

class _MarketingScreenState extends State<MarketingScreen> {
  int _tab = 0;

  /// The sub-modules, in the owner's order.
  ///
  /// A list of (name, glyph, page) rather than a switch on the index, for the
  /// reason HrScreen gives: a name can never end up above the wrong page.
  late final List<(String, IconData, Widget Function())> _tabs = [
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
  ];

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
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: _tabs[_tab].$3()),
      ],
    );
  }
}

/// Newsletter, declared and not built — and saying both out loud.
///
/// ── What "placeholder" means here ────────────────────────────────
///
/// Not an empty screen. This app already has an answer for a feature that is
/// planned and absent, and it is used in three places: an automation action the
/// engine cannot run is listed and badged, a module the platform has switched
/// off is drawn greyed with the reason beside it, and the HR dashboard's
/// "Expiring certifications" panel carries a "Not built yet" badge and a
/// sentence naming what has to exist before it can be computed. This is the
/// same answer at the size of a tab.
///
/// The rule underneath all of them: something simply missing invites the reader
/// to conclude the app is broken or that they have lost a permission, and both
/// guesses are worse than the truth.
///
/// ── The three things it says, and where each comes from ──────────
///
/// What it will be, and what it is waiting for, are written here — the same
/// place the certifications panel writes its own, because they are facts about
/// the product rather than about the tenant, and a round trip to fetch a
/// paragraph is a round trip to be wrong about.
///
/// The automation surface is NOT written here. Marketing declared its triggers
/// and actions at the moment it declared itself, and those declarations, with
/// their wording, live on the server; a copy in Dart would be a second
/// dictionary to forget to update. So it is fetched, and each event nothing
/// raises yet is marked — which is the whole point of showing it. Somebody who
/// can see that "Newsletter sent" already exists in the automation builder, and
/// that it does not fire, will not go and build a rule that waits forever.
class NewsletterTab extends StatefulWidget {
  const NewsletterTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NewsletterTab> createState() => _NewsletterTabState();
}

class _NewsletterTabState extends State<NewsletterTab> {
  bool _loading = true;
  String? _error;

  List<Map<String, dynamic>> _triggers = [];
  List<Map<String, dynamic>> _actions = [];

  /// Long lines are hard to read, and this screen is mostly prose. The cards
  /// stop growing here and sit against the left edge rather than centring, so
  /// the page keeps the same margin as every other tab.
  ///
  /// It has to be reached through an [Align] and not a bare [ConstrainedBox].
  /// A ListView hands its children a TIGHT cross-axis width, and
  /// `BoxConstraints.enforce` clamps an additional constraint into the parent's
  /// range — so a max of 760 inside a 1352-point list resolves to 1352 and the
  /// cap silently does nothing. Align loosens the constraint first, which is
  /// what gives the child room to be narrower than its parent.
  static const double _readable = 760;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // A spinner only when there is nothing to keep — see AsyncBody.
    setState(() {
      _loading = _triggers.isEmpty && _actions.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/marketing/newsletter');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;

      if (response.ok) {
        final data = response.body['data'] as Map<String, dynamic>;

        _triggers = (data['triggers'] as List<dynamic>)
            .cast<Map<String, dynamic>>();
        _actions = (data['actions'] as List<dynamic>)
            .cast<Map<String, dynamic>>();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: _readable),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Wrap, not Row: at 375 points the badge drops under the
                  // heading instead of squeezing it to two characters a line.
                  Wrap(
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text('Newsletter', style: theme.textTheme.headlineSmall),
                      const Tag('Not built yet', warn: true),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Newsletter is how a company writes to its own customers: '
                    'a mailing composed once, sent to a list of people who '
                    'asked for it, and sent from the company’s own address '
                    'rather than the platform’s — so what lands in a '
                    'customer’s inbox comes from the business they deal with.',
                    style: theme.textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 20),
                  const _BeforeItCanRun(),
                  const SizedBox(height: 16),
                  _AlreadyDeclared(triggers: _triggers, actions: _actions),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// What has to exist before Newsletter can send anything.
///
/// Two items, and each is a thing this build genuinely does not have rather
/// than a hedge. Naming them is the difference between "not built yet" and a
/// sentence somebody can act on: an owner reading this can tell that the wait
/// is on a mailing list and an editor, not on a decision nobody has taken.
///
/// What is deliberately NOT listed is the sending identity — the answer to
/// "which address does this leave from". It is a tenant setting with its own
/// cascade, described in the paragraph above rather than here, because it is
/// not Newsletter's to build: this module reads that answer, it never decides
/// it, and listing somebody else's foundation as this module's blocker would
/// be wrong the moment it lands.
class _BeforeItCanRun extends StatelessWidget {
  const _BeforeItCanRun();

  @override
  Widget build(BuildContext context) {
    return const DashboardCard(
      title: 'Before it can run',
      icon: Icons.pending_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Need(
            'Somebody to send to',
            'Nothing yet records who has agreed to receive marketing mail. '
                'The app already knows how to hold an agreement — the words a '
                'person was shown, the moment they agreed, and who they are — '
                'and a mailing list has to be built on that rather than on a '
                'bare list of addresses, or there is no way to show that '
                'anybody asked for it.',
          ),
          SizedBox(height: 12),
          _Need(
            'Something written to send',
            'There is no way to write a mailing, no record of what went out, '
                'and no way to read back last month’s. A message that cannot '
                'be looked at again after it has reached ten thousand people '
                'is not one worth sending.',
          ),
        ],
      ),
    );
  }
}

/// One missing piece: what it is, and why it is missing.
class _Need extends StatelessWidget {
  const _Need(this.title, this.detail);

  final String title;
  final String detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          // Sits on the first line of the title rather than the middle of the
          // block, which is where a centred icon lands once the sentence wraps
          // to four lines on a phone.
          padding: const EdgeInsets.only(top: 3, right: 8),
          child: Icon(
            Icons.radio_button_unchecked,
            size: 14,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: theme.textTheme.titleSmall),
              const SizedBox(height: 2),
              DashboardNote(detail),
            ],
          ),
        ),
      ],
    );
  }
}

/// The automation surface Marketing has already declared.
///
/// ── Why this is on screen at all ─────────────────────────────────
///
/// The standing rule is that every module works with the automations system,
/// and it was set after Human Resources shipped without one. A module that
/// declares its events on the day it is written and never shows them has kept
/// the letter of that rule where nobody can check it. This is where somebody
/// can check it.
///
/// ── Marked, not merely listed ────────────────────────────────────
///
/// Every event here is one nothing raises yet, and each says so on its own row
/// rather than the card saying it once at the top. A blanket sentence would go
/// quietly false the day the first one starts firing; a mark driven by the
/// server's own flag cannot.
///
/// The actions carry no mark of their own, and that is not an oversight. An
/// action only ever runs under a trigger, so an action beneath events that do
/// not fire cannot run either — the flag above already answers it, and a second
/// badge saying the same thing twice is how two answers start disagreeing.
class _AlreadyDeclared extends StatelessWidget {
  const _AlreadyDeclared({required this.triggers, required this.actions});

  final List<Map<String, dynamic>> triggers;
  final List<Map<String, dynamic>> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DashboardCard(
      title: 'Already in the automation builder',
      icon: Icons.bolt_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (triggers.isEmpty && actions.isEmpty)
            const DashboardNote(
              'Nothing has been declared yet, so there is nothing here for a '
              'rule to be built on.',
            )
          else ...[
            const DashboardNote(
              'These are already offered when you write a rule, so you can see '
              'what Newsletter will be able to tell the rest of the business.',
            ),
            const SizedBox(height: 12),
            for (final trigger in triggers) ...[
              _Declared(
                label: trigger['label'] as String,
                description: trigger['description'] as String,
                // The server's flag, not a guess from the wording. A client
                // reading English to find out whether something works is a
                // client that stops working when the sentence is reworded.
                mark: (trigger['raised'] as bool)
                    ? null
                    : const Tag('Does not fire yet', warn: true),
              ),
              const SizedBox(height: 10),
            ],
            if (actions.isNotEmpty) ...[
              const SizedBox(height: 2),
              Text(
                'And what a rule will be able to do here',
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 8),
              for (final action in actions) ...[
                _Declared(
                  label: action['label'] as String,
                  description: action['description'] as String,
                ),
                const SizedBox(height: 10),
              ],
            ],
          ],
        ],
      ),
    );
  }
}

/// One declared trigger or action: what it is called, what it means, and
/// whatever has to be said about whether it works.
class _Declared extends StatelessWidget {
  const _Declared({required this.label, required this.description, this.mark});

  final String label;
  final String description;
  final Widget? mark;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Wrap for the same reason the heading uses one: at 375 points a badge
        // beside a label has nowhere to go, and a Row would overflow rather
        // than move it to the next line.
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(label, style: theme.textTheme.titleSmall),
            ?mark,
          ],
        ),
        const SizedBox(height: 2),
        DashboardNote(description),
      ],
    );
  }
}
