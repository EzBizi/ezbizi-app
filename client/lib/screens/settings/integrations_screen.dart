import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/api_key_connect_form.dart';
import '../../widgets/social_network_mark.dart';
import 'integration_provider_screen.dart';

/// Settings › Integrations — every outside account this view is connected to,
/// in one place.
///
/// ── Why this exists when each module already has a Connect ──────
///
/// Because "what is this company connected to" was not a question anybody could
/// answer. Social accounts connect under Marketing, the mailing account under
/// Newsletter, advertising under Leads — so finding out whether Mailchimp was
/// connected meant knowing which module owned it first. That is fine when you
/// built the app and useless when you did not.
///
/// This does not replace those screens and deliberately does not duplicate
/// their panels: Connect opens the module that owns the account, because the
/// module is where the connecting is understood. What lives here is the
/// INVENTORY — what is connected, at what scope, and what is not.
///
/// ── A CARD PER PROVIDER, not a row per connection (2026-09-22) ──
///
/// It listed every connection flat under four family headings, which was
/// legible with six of them. Jose sent a screenshot of the real thing: fifty-
/// three Google Ads accounts and a column of identical "A1 Chimney · Thumbtack
/// · this company" rows, four pages of scrolling, answering nothing. His words:
/// *"i'd like to have cards instead each with their own screen for connections,
/// etc ... Remember to take into account org - company - franchise ownership"*.
///
/// So this screen now answers ONE question — what is this business connected
/// to, and is any of it broken — and [IntegrationProviderScreen] answers the
/// next one, which connections and whose. Neither page repeats the other.
///
/// ── Scope, and why the list can look short ──────────────────────
///
/// It answers for the CURRENT VIEW, the same as every other list in the app
/// (§3). Standing in A-1 Chimney you see A-1 Chimney's connections and the
/// organization's; you never see Premier Garage's. An administrator switching
/// views will see the cards change, and that is correct rather than a bug.
class IntegrationsScreen extends StatefulWidget {
  const IntegrationsScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<IntegrationsScreen> createState() => _IntegrationsScreenState();
}

class _IntegrationsScreenState extends State<IntegrationsScreen> {
  List<IntegrationConnection> _connections = const [];

  /// Every provider the software offers, so a card can be drawn for one with
  /// NOTHING connected.
  ///
  /// The catalogue rather than a list in this file: a provider added to the
  /// registry appears here with no edit, and the alternative is a second list
  /// that is right on the day somebody writes it. Same endpoint Marketing's
  /// composer reads, and it needs `marketing.view`, which is already what puts
  /// this chip in the Settings shell.
  Map<String, Map<String, dynamic>> _catalogue = const {};

  bool _loading = true;
  String? _error;

  /// Which provider's pane is open in place of the grid, by catalogue value.
  ///
  /// Jose, 2026-09-23: *"Every Single Integration Card violates the containment
  /// rule by going full screen when clicked."* This used to be a
  /// `Navigator.push`, which replaced the sidebar, the header and the top bar
  /// with a page of its own. Swapping the body is the shape
  /// `TrainingSettingsTab` and `LearnShelves` already use for the same
  /// correction, and it is why `IntegrationProviderScreen` carries no
  /// `Scaffold`.
  String? _open;

  String get _viewName =>
      widget.session.me?.scope.label ??
      widget.session.me?.organization.name ??
      'this view';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Data sources are filtered out of this endpoint by default, because
    // listing them once put twenty-two Google Ads accounts in the Social Media
    // composer as if a post could go to one. Integrations is the screen that
    // wants them, so it is the screen that asks.
    final response = await widget.api.get('/social/connections', {
      'include': 'data_sources',
    });

    final catalogue = await widget.api.get('/social/providers');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.reason;
        return;
      }

      _error = null;
      _connections = ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(IntegrationConnection.fromJson)
          .toList();

      /*
       * A FAILED CATALOGUE IS NOT AN ERROR HERE, deliberately.
       *
       * It only adds cards for providers with nothing connected. If it does
       * not answer, the screen still shows everything this business actually
       * has — which is the question it exists for — rather than refusing the
       * page over a list of things that are absent.
       */
      if (catalogue.ok) {
        _catalogue = {
          for (final entry
              in ((catalogue.body['data'] as List<dynamic>?) ?? const [])
                  .whereType<Map<String, dynamic>>())
            (entry['value'] as String? ?? ''): entry,
        }..removeWhere(
          (key, value) => key.isEmpty || (value['label'] as String? ?? '').isEmpty,
        );
      }
    });
  }

  /// Whether this person may connect this provider.
  ///
  /// The permission comes from the CATALOGUE — `SocialProvider::connectPermission()`
  /// on the wire since 2026-09-23 — rather than from a map in this file. That
  /// map is Marketing's and carries four other modules' semantics; a second
  /// copy here would drift into a Connect button that answers 403, which is
  /// exactly what §4 forbids.
  ///
  /// An unknown provider answers NO. A catalogue that did not load leaves the
  /// grid working and the connect control absent, which is the safe direction:
  /// nothing is offered that cannot be checked.
  bool _mayConnectProvider(String provider) {
    final permission = _catalogue[provider]?['permission'] as String?;

    return permission != null && _mayConnect(permission);
  }

  /// Where a provider is connected, when it is connected somewhere else.
  ///
  /// Per provider rather than per family only because the pane needs it that
  /// way now; the ANSWER is still the family's, which is why this reads the
  /// same switch rather than a second list that could disagree with the
  /// headings.
  String? _whereFor(String provider) => switch (_familyOf(provider)) {
    'mailing' => 'Marketing › Newsletter',
    'social' => 'Marketing › Social Media Management › Accounts',
    'leads' => 'Leads',
    // Reporting connects HERE — its pane carries the form instead.
    _ => null,
  };

  /// Which family a provider belongs to, for a provider with no connections.
  ///
  /// [IntegrationConnection.family] answers the same question from a row. This
  /// is the same switch and they must agree — a provider filed under one
  /// heading when empty and another when connected would move on screen the
  /// moment somebody connected it.
  String _familyOf(String provider) => IntegrationConnection(
    id: '',
    provider: provider,
    providerLabel: '',
    displayName: '',
    scopeType: '',
    scopeId: '',
    connected: false,
  ).family;

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    final byProvider = <String, List<IntegrationConnection>>{};
    for (final c in _connections) {
      byProvider.putIfAbsent(c.provider, () => []).add(c);
    }

    // Everything connected, plus everything offered. A provider with rows but
    // no catalogue entry still gets a card: the connection is the stronger
    // evidence that it exists.
    final providers = {...byProvider.keys, ..._catalogue.keys};

    // THE PANE IN PLACE OF THE GRID, never over the app. See [_open].
    final open = _open;

    if (open != null) {
      return IntegrationProviderScreen(
        api: widget.api,
        session: widget.session,
        provider: open,
        providerLabel: _label(open),
        connections: byProvider[open] ?? const [],
        connectWhere: _whereFor(open),
        connectForms: _formsFor(open),
        connectsBy: _catalogue[open]?['connects_by'] as String?,
        startPath: _catalogue[open]?['start_path'] as String?,
        mayConnect: _mayConnectProvider(open),
        canName: _familyOf(open) == 'reporting',
        onChanged: _load,
        onBack: () => setState(() => _open = null),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_error != null) ...[
          DashboardNote(_error!),
          const SizedBox(height: 12),
        ],
        _family(
          context,
          title: 'Mailing',
          icon: Icons.drafts_outlined,
          family: 'mailing',
          providers: providers,
          byProvider: byProvider,
          empty:
              'No mailing account connected in $_viewName. A newsletter is '
              'sent through the company\'s own account, so one has to be '
              'connected before anything can go out.',
          where: 'Marketing › Newsletter',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Social',
          icon: Icons.share_outlined,
          family: 'social',
          providers: providers,
          byProvider: byProvider,
          empty: 'No social account connected in $_viewName.',
          where: 'Marketing › Social Media Management › Accounts',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Advertising and listings',
          icon: Icons.campaign_outlined,
          family: 'leads',
          providers: providers,
          byProvider: byProvider,
          empty: 'No advertising account connected in $_viewName.',
          where: 'Leads',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Reporting',
          icon: Icons.assessment_outlined,
          family: 'reporting',
          providers: providers,
          byProvider: byProvider,
          empty:
              'No reporting source connected in $_viewName. Reports read '
              'these directly, so a report covering a business with none '
              'connected has nothing to show.',
          // Only the reporting sources, for now. The others are named where
          // they are connected, and this screen is not trying to become the
          // place every account is administered.
          canName: true,
        ),
      ],
    );
  }

  /// Whether this person may connect the source behind one of these forms.
  ///
  /// The two are DIFFERENT permissions and were not always: connecting an
  /// Airtable base follows Profit & Loss, the screen that reads it, while a
  /// Workiz account follows the Experimental report. Showing both to everybody
  /// was survivable while they agreed; it stopped being so on 2026-09-17, when
  /// Airtable's moved to `profit_loss.view`.
  ///
  /// Absent, not disabled, and not shown-then-refused: a form that posts and
  /// comes back with a permission error has spent somebody's time and told
  /// them the app is for other people. Absent `/me` answers no.
  bool _mayConnect(String permission) =>
      widget.session.me?.permissions.contains(permission) ?? false;

  /// Whether this person can actually SEE the Experimental report.
  ///
  /// ── Two keys, because the pane needs two ────────────────────
  ///
  /// `reports.build` is what `SocialProvider` asks for to connect a Workiz
  /// account, and it was enough to see the report until Reports' tabs landed
  /// on 2026-09-17. It is not any more: the pane needs the tab AND the
  /// capability, so a builder without `reports.experimental.view` was being
  /// offered a form for an account they cannot look at — measured, connect
  /// reached the controller while the pane answered 403.
  ///
  /// The server permission is deliberately left alone. It is an authorisation
  /// floor and `reports.build` is the right one; what was wrong is the OFFER,
  /// and offering is this screen's job.
  bool get _maySeeExperimental =>
      _mayConnect('reports.build') && _mayConnect('reports.experimental.view');

  /// The connect form for ONE reporting source.
  ///
  /// Per provider rather than per family, because the family's card grid is
  /// now a screen each: offering both forms on the Airtable screen would ask
  /// somebody looking at their bases for a Workiz token, which is the shape
  /// this redesign exists to remove one level up.
  List<Widget> Function()? _formsFor(String provider) => switch (provider) {
    'airtable' when _mayConnect('profit_loss.view') => _airtableForm,
    'workiz' when _maySeeExperimental => _workizForm,
    _ => null,
  };

  /// The connect forms for the Reporting family.
  ///
  /// Jose, 2026-09-16: "make the connection in Integrations", and for both
  /// Airtable and Workiz — "make it as well so any company can make workiz
  /// connections via api as well, same set it up on integrations".
  ///
  /// They live on the provider's own screen now rather than under the family
  /// heading, which is what "each with their own screen for connections" asked
  /// for: the form to connect a base sits beside the bases already connected.
  List<Widget> _airtableForm() => [
    Text('Airtable', style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 4),
      Text(
        'One connection is one base. Connect as many bases as this business '
        'keeps its figures in.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      const SizedBox(height: 8),
      ApiKeyConnectForm(
        api: widget.api,
        path: '/social/airtable',
        submitLabel: 'Connect base',
        fields: const [
          ('api_token', 'Personal access token', 'pat…'),
          ('base_id', 'Base id', 'app… — from the base\'s URL'),
        ],
        onConnected: _load,
      ),
  ];

  List<Widget> _workizForm() => [
    Text('Workiz', style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 4),
      Text(
        'One connection is one Workiz account. Switch to the branch it belongs '
        'to before connecting it — the accounts are separate books and a report '
        'attributes each one to its branch.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      const SizedBox(height: 8),
      ApiKeyConnectForm(
        api: widget.api,
        path: '/social/workiz',
        submitLabel: 'Connect account',
        fields: const [
          ('api_token', 'API token', 'api_… — from Workiz › Developer › v1'),
          // The second key on the same page. Without it EzBizi reads the
          // account's jobs but cannot change them.
          ('auth_secret', 'API secret', 'sec_… — beside the token; lets EzBizi change jobs'),
        ],
        onConnected: _load,
      ),
  ];

  Widget _family(
    BuildContext context, {
    required String title,
    required IconData icon,
    required String family,
    required Set<String> providers,
    required Map<String, List<IntegrationConnection>> byProvider,
    required String empty,
    String? where,
    bool canName = false,
  }) {
    final all = providers.where((p) => _familyOf(p) == family).toList();

    /*
     * WHAT YOU HAVE, NOT WHAT EXISTS — except where the card is the only door.
     *
     * The software offers seventeen providers and a business connects three.
     * Drawing a card for every one put THIRTEEN "Set up" cards on screen next
     * to two real ones (measured), which is the four-pages-of-scrolling Jose
     * photographed wearing different clothes: a screen mostly about things you
     * do not have.
     *
     * The boundary is not taste, it is whether the card DOES anything. Three
     * families connect elsewhere and say so in a sentence at the foot of this
     * block, so an unconnected card there is a second copy of that sentence
     * shaped like a control. Reporting has no `where`: its connect form lives
     * inside the provider screen, reachable only by tapping the card, so
     * hiding an unconnected Airtable would remove the only way to add one.
     */
    final mine =
        (where == null ? all : all.where((p) => (byProvider[p] ?? const []).isNotEmpty))
            .toList()
      ..sort((a, b) {
        // Connected first, then alphabetically. What a business HAS is the
        // thing it came to look at; what it could have is a catalogue.
        final byCount = (byProvider[b]?.length ?? 0).compareTo(
          byProvider[a]?.length ?? 0,
        );

        return byCount != 0 ? byCount : _label(a).compareTo(_label(b));
      });

    return DashboardCard(
      title: title,
      icon: icon,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (mine.isEmpty)
            DashboardNote(empty)
          else
            // A grid that reflows rather than a fixed column count: this pane
            // is 1400pt on a desktop window and 380pt on a phone, and a card
            // narrower than its own provider name is not a card.
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                for (final provider in mine)
                  _providerCard(
                    context,
                    provider: provider,
                    rows: byProvider[provider] ?? const [],
                    where: where,
                    canName: canName,
                  ),
              ],
            ),
          const SizedBox(height: 8),

          // Named rather than merely absent, so somebody can still discover
          // that EzBizi speaks TikTok without a card each for the fifteen it
          // speaks and they do not use.
          if (where != null && all.length > mine.length)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                'Also available: '
                '${(all.where((p) => (byProvider[p] ?? const []).isEmpty).map(_label).toList()..sort()).join(', ')}.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),

          /*
           * A SENTENCE FOR MOST FAMILIES, A FORM FOR ONE.
           *
           * This used to be a sentence for all of them, and the comment here
           * said why: "connecting is done where the account is understood, and
           * a second Connect flow here would be a second thing to keep
           * correct." Jose overrode the first half on 2026-09-16 for the
           * reporting sources — "make the connection in Integrations".
           *
           * The second half is still true, which is why the form is a shared
           * widget rather than a copy: Workiz can be connected from Reports ›
           * Experimental as well, and two forms posting to one endpoint would
           * be two sets of field names to keep in step.
           */
          if (where != null)
            Text(
              'Connect and disconnect under $where.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  String _label(String provider) =>
      (_catalogue[provider]?['label'] as String?) ??
      // A provider with rows but no catalogue entry — the connection's own
      // label is better than the raw key, which is what this fell back to
      // before and read as `google_business_profile` on screen.
      _connections
          .firstWhere(
            (c) => c.provider == provider && c.providerLabel.isNotEmpty,
            orElse: () => IntegrationConnection(
              id: '',
              provider: provider,
              providerLabel: provider,
              displayName: '',
              scopeType: '',
              scopeId: '',
              connected: false,
            ),
          )
          .providerLabel;

  /// One provider, and what this view has of it.
  Widget _providerCard(
    BuildContext context, {
    required String provider,
    required List<IntegrationConnection> rows,
    String? where,
    bool canName = false,
  }) {
    final theme = Theme.of(context);
    final broken = rows.where((r) => !r.connected).length;

    return SizedBox(
      width: 240,
      child: InkWell(
        key: ValueKey('provider:$provider'),
        borderRadius: BorderRadius.circular(12),
        // Sets state; the body swaps. NOT a push — see [_open]. Everything
        // the pane needs is read there from the same maps this card read,
        // so the two cannot show different connections for one provider.
        onTap: () => setState(() => _open = provider),
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: theme.colorScheme.outlineVariant),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  SocialNetworkMark(network: provider, size: 28),
                  const Spacer(),
                  // Flexible, because the pill is the only thing on this card
                  // that can grow. Its three labels fit English comfortably in
                  // a 240pt card -- about 66pt of slack on the longest -- and
                  // that slack is the ONLY thing preventing an overflow. A
                  // fourth status word, or the same words in another language,
                  // spends it with no warning: a RenderFlex overflow is a
                  // paint-time exception, so nothing that merely reads the
                  // text would notice.
                  Flexible(child: _pill(theme, rows: rows, broken: broken)),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                _label(provider),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 2),
              Text(
                /*
                 * WHAT IT IS CONNECTED AT, not just how many.
                 *
                 * "53 connected" is the number Jose's screenshot already made
                 * useless — fifty-three rows that each said "this company".
                 * The scope words are what an administrator is deciding about,
                 * and the detail screen behind this card names the companies
                 * and branches themselves.
                 */
                _beneath(rows),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: broken > 0
                      ? theme.colorScheme.error
                      : theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _beneath(List<IntegrationConnection> rows) {
    if (rows.isEmpty) return 'Nothing connected here yet.';

    final broken = rows.where((r) => !r.connected).length;

    final scopes = <String>{
      for (final row in rows)
        switch (row.scopeType) {
          'organization' => 'the organization',
          'company' => 'a company',
          'franchise' => 'a branch',
          _ => row.scopeType,
        },
    };

    final where = scopes.isEmpty ? '' : ' at ${scopes.join(', ')}';

    // The broken count leads when there is one, because a connection that has
    // stopped working is the whole reason somebody opens this screen.
    return broken > 0
        ? '$broken of ${rows.length} need attention$where'
        : '${rows.length} connected$where';
  }

  Widget _pill(
    ThemeData theme, {
    required List<IntegrationConnection> rows,
    required int broken,
  }) {
    final (label, colour) = switch ((rows.isEmpty, broken > 0)) {
      (true, _) => ('Set up', theme.colorScheme.onSurfaceVariant),
      (false, true) => ('Needs attention', theme.colorScheme.error),
      (false, false) => ('Connected', theme.colorScheme.primary),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: colour.withValues(alpha: 0.5)),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.labelSmall?.copyWith(color: colour),
      ),
    );
  }
}
