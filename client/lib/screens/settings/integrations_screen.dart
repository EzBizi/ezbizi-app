import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../widgets/list_search.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/sending_server_panel.dart';
import '../../widgets/workiz_connect_form.dart';
import '../../widgets/workiz_tags_section.dart';
import '../../widgets/airtable_token_connect.dart';
import '../../widgets/api_key_connect_form.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/telegram_linked_staff.dart';
import '../email/mailbox_panels.dart';
import '../../widgets/disconnect_panel.dart';
import '../../core/theme.dart';
import '../email/consent_window.dart';
import '../../widgets/open_destination.dart';
import 'choose_products_panel.dart';
import 'integration_family_card.dart';
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
    this.openConsent = openConsentWindow,
  });

  final ApiClient api;
  final Session session;

  /// Injected so a test can hand in a sign-in window that opens nothing.
  final OpenConsentWindow openConsent;

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

  /// Google Workspace domains in this view (Jose, 2026-10-15: "On
  /// Integrations we are also missing Google Workspace connections").
  ///
  /// Not a SocialProvider row: a domain is bound by GoogleWorkspaceAuthController
  /// and read from `GET /email/workspace`, which already answers for the
  /// current view (a sibling company's domain is never in it). This screen
  /// lists what that endpoint lists and connects and disconnects through the
  /// same endpoints Email › Settings uses — no second flow.
  List<IntegrationConnection> _workspace = const [];

  /// This view's phone numbers, as connections (UI queue 110), or null when
  /// this person cannot read them.
  List<IntegrationConnection>? _numbers;

  /// The sending server in effect here, or null when this person cannot
  /// read it. An empty map is "none set up".
  Map<String, dynamic>? _sending;

  /// The server saved at this scope (`connected_here`), with its last check.
  Map<String, dynamic>? _sendingHere;

  /// Why the server would not answer for the sending server here, when it
  /// would not. The card is still drawn, carrying this instead of Connect:
  /// a card that vanishes on a refusal is how Triplex Group (2026-10-22) had
  /// no way to connect its own mail server and no word on why.
  String? _sendingRefusal;

  /// The server's `can_connect`: false once this view has a domain.
  bool _workspaceCanConnect = false;

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

  /// Products a head's sign-in brings, by head: the catalogue's
  /// `signed_in_with` (UI queue 182). They draw inside the head's card.
  Map<String, List<String>> get _folded {
    final out = <String, List<String>>{};
    for (final e in _catalogue.entries) {
      final head = e.value['signed_in_with'] as String?;
      if (head != null && head.isNotEmpty) {
        out.putIfAbsent(head, () => []).add(e.key);
      }
    }
    return out;
  }

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

    final catalogue = await widget.api.get('/social/providers', {
      'include': 'data_sources',
    });

    // Only for somebody the endpoint answers; anybody else would be told
    // "not connected" about a domain they simply cannot read.
    final workspace = _mayManageWorkspace
        ? await widget.api.get('/email/workspace')
        : null;

    // EzBizi's own services, each only when this person can read it.
    final numbers = widget.session.me?.module('phone')?.reachable == true
        ? await widget.api.get('/phone/numbers')
        : null;
    // AT this scope, the same pair the panel and `/settings` send. The
    // controller REQUIRES both: without them every answer was a 422, `_sending`
    // stayed null, and the card was never offered (Jose, 2026-10-22).
    final me = widget.session.me;
    final sending = await widget.api.get('/mail/sending-server', {
      'scope_type': me?.scope.type ?? 'organization',
      'scope_id': me?.scope.id ?? me?.organization.id ?? '',
    });

    if (!mounted) return;

    setState(() {
      _loading = false;

      _numbers = numbers != null && numbers.ok
          ? [
              for (final n
                  in ((numbers.body['data'] as List<dynamic>?) ?? const [])
                      .whereType<Map<String, dynamic>>())
                IntegrationConnection(
                  id: 'phone:${n['id']}',
                  provider: 'phone_numbers',
                  providerLabel: 'Phone numbers',
                  displayName: formatPhoneNumber(n['e164'] as String?),
                  label: n['label'] as String?,
                  scopeType: n['scope_type'] as String? ?? '',
                  scopeId: n['scope_id'] as String? ?? '',
                  // NumbersController's own health: does a call reach anybody.
                  connected: _phoneProblems(n).isEmpty,
                  statusReason: _phoneProblems(n).isEmpty
                      ? null
                      : '${_phoneProblems(n).length} '
                            '${_phoneProblems(n).length == 1 ? 'problem' : 'problems'}: '
                            '${_phoneProblems(n).join(' ')}',
                ),
            ]
          : null;
      final server = sending.ok
          ? sending.body['data'] as Map<String, dynamic>?
          : null;
      _sending = server == null
          ? null
          : (server['in_effect'] as Map<String, dynamic>?) ?? const {};
      _sendingHere = server?['connected_here'] as Map<String, dynamic>?;
      // A 4xx is the server answering "not here", with its reason; a 5xx or
      // no answer at all says nothing about this scope, so it offers nothing.
      _sendingRefusal =
          !sending.ok && sending.statusCode >= 400 && sending.statusCode < 500
          ? sending.reason
          : null;

      final data = workspace != null && workspace.ok
          ? workspace.body['data'] as Map<String, dynamic>?
          : null;
      _workspaceCanConnect = data?['can_connect'] == true;
      _workspace = ((data?['connections'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(IntegrationConnection.fromWorkspaceJson)
          .toList();

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
        _catalogue =
            {
              for (final entry
                  in ((catalogue.body['data'] as List<dynamic>?) ?? const [])
                      .whereType<Map<String, dynamic>>())
                (entry['value'] as String? ?? ''): entry,
            }..removeWhere(
              (key, value) =>
                  key.isEmpty || (value['label'] as String? ?? '').isEmpty,
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
    'email' => 'Email › Settings',
    'phone' => 'Communication › Phone › Settings',
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

    // The Workiz web login (`workiz_web`, UI queue 120) is drawn on Workiz's
    // own page and counted on its tile: one Workiz, however it connects, so
    // the grid never offers a second Workiz tile to choose between.
    final byProvider = <String, List<IntegrationConnection>>{};
    for (final c in [..._connections, ..._workspace, ...?_numbers]) {
      final key = c.provider == 'workiz_web' ? 'workiz' : c.provider;
      byProvider.putIfAbsent(key, () => []).add(c);
    }
    // The server saved at this scope, as its last check found it: verified
    // is connected, failed is broken with the reason, never on our say-so.
    final here = _sendingHere ?? _sending;
    final host = here?['host'] as String?;
    if (here != null && host != null && host.isNotEmpty) {
      final state = here['state'] as String?;
      final failed = state == 'failed';
      byProvider['sending_server'] = [
        IntegrationConnection(
          id: 'sending_server',
          provider: 'sending_server',
          providerLabel: 'Sending server',
          displayName: host,
          label: here['from_address'] as String?,
          scopeType: '',
          scopeId: '',
          connected: !failed,
          statusReason: failed
              ? (here['state_reason'] as String? ??
                    'The mail server refused the last send.')
              : null,
          detail: state == 'verified' || failed
              ? null
              : 'Not checked yet: send a test from its panel.',
          lastCheckedAt: DateTime.tryParse(
            here['last_checked_at'] as String? ?? '',
          ),
        ),
      ];
    }

    // Everything connected, plus everything offered. A provider with rows but
    // no catalogue entry still gets a card: the connection is the stronger
    // evidence that it exists.
    final providers = {
      ...byProvider.keys,
      ..._catalogue.keys.where((p) => p != 'workiz_web'),
      if (_mayManageWorkspace) 'google_workspace',
      if (_numbers != null) 'phone_numbers',
      if (_sending != null || _sendingRefusal != null) 'sending_server',
    }..removeAll(_folded.values.expand((v) => v));
    // A head with nothing of its own but a connected product still draws.
    providers.addAll(_folded.keys.where(_catalogue.containsKey));

    // THE PANE IN PLACE OF THE GRID, never over the app. See [_open].
    final open = _open;

    // The sending server is set up by its own panel, drawn here in place.
    if (open == 'sending_server') {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('integration-back'),
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Back to integrations',
                onPressed: () {
                  setState(() => _open = null);
                  _load();
                },
              ),
              const SizedBox(width: 4),
              const SocialNetworkMark(network: 'sending_server', size: 22),
              const SizedBox(width: 10),
              Text(
                'Sending server',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(left: 52, top: 2, bottom: 12),
            child: Text(
              _purpose['sending_server']!,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          SendingServerPanel(api: widget.api, session: widget.session),
        ],
      );
    }

    if (open != null) {
      return IntegrationProviderScreen(
        api: widget.api,
        session: widget.session,
        provider: open,
        providerLabel: _label(open),
        connections: byProvider[open] ?? const [],
        connectWhere: _whereFor(open),
        connectModule: switch (_familyOf(open)) {
          'mailing' => 'newsletter',
          'social' => 'marketing',
          'leads' => 'leads',
          'email' => 'email',
          'phone' => 'phone',
          _ => null,
        },
        connectForms: open == 'phone_numbers'
            ? () => [
                const DashboardNote(
                  'Numbers are bought, set up and released in Communication '
                  '› Phone › Settings.',
                ),
              ]
            : _formsFor(open),
        connectsBy: _catalogue[open]?['connects_by'] as String?,
        startPath: _catalogue[open]?['start_path'] as String?,
        mayConnect: _mayConnectProvider(open),
        canName: _familyOf(open) == 'reporting',
        // Every provider since UI queue 110: the server's disconnect gate is
        // the provider's connect permission, which is what this asks.
        onDisconnect: open == 'google_workspace'
            ? (_mayManageWorkspace ? _disconnectWorkspace : null)
            : (_mayConnectProvider(open) ? _disconnectConnection : null),
        purpose: _purpose[open],
        onEdit: open == 'workiz' && _maySeeExperimental ? _editWorkiz : null,
        mayMoveWorkizWeb: _mayConnectProvider('workiz_web'),
        footer: open == 'workiz' && _mayConnectProvider('workiz')
            ? WorkizTagsSection(api: widget.api)
            : null,
        onChanged: _load,
        onBack: () => setState(() => _open = null),
      );
    }

    // The phone layout follows the room this tab has, not the window's
    // (UI queue 110).
    return LayoutBuilder(
      builder: (context, box) {
        _phone = box.maxWidth < 600;
        return _grid(context, providers, byProvider);
      },
    );
  }

  bool _phone = false;

  String _query = '';

  Widget _grid(
    BuildContext context,
    Set<String> providers,
    Map<String, List<IntegrationConnection>> byProvider,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The same header as every Settings tab (UI queue 110).
        const PanelHeader(
          note:
              'The outside services EzBizi works with. Open one to connect, '
              'edit or disconnect it.',
        ),
        // Every list has search: about twenty services in six cards (phone
        // audit 2026-11-04, Section 4 #12).
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
          child: Align(
            alignment: AlignmentDirectional.centerStart,
            child: ListSearch(
              key: const ValueKey('integrations-search'),
              hint: 'Find a service',
              width: _phone ? double.infinity : 280,
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
        ),
        Expanded(
          child: _list(context, {
            for (final p in providers)
              if (_query.trim().isEmpty ||
                  [p, ...?_folded[p]].any(
                    (q) => _label(
                      q,
                    ).toLowerCase().contains(_query.trim().toLowerCase()),
                  ))
                p,
          }, byProvider),
        ),
      ],
    );
  }

  /// The families, as category chips (UI queue 183) — the headings this
  /// screen used to draw as one card each.
  static const _categories = [
    ('phone', 'Phone'),
    ('messaging', 'Messaging'),
    ('mailing', 'Mailing'),
    ('email', 'Email'),
    ('social', 'Social'),
    ('leads', 'Advertising and listings'),
    ('reporting', 'Reporting'),
  ];

  String? _category;

  /// Where a provider stands, for the three sections (UI queue 183): a row
  /// that is not working needs attention; anything else connected is
  /// connected; the rest is available.
  String _standing(
    String provider,
    Map<String, List<IntegrationConnection>> byProvider,
  ) {
    final rows = [
      for (final p in [provider, ...?_folded[provider]]) ...?byProvider[p],
    ];
    if (rows.any((r) => !r.connected)) return 'attention';
    if (rows.isNotEmpty) return 'connected';
    if (_products(provider).any((p) => p['connected'] == true)) {
      return 'connected';
    }
    return 'available';
  }

  Widget _list(
    BuildContext context,
    Set<String> providers,
    Map<String, List<IntegrationConnection>> byProvider,
  ) {
    final theme = Theme.of(context);
    final present = {for (final p in providers) _familyOf(p)};
    final shown =
        providers
            .where((p) => _category == null || _familyOf(p) == _category)
            .toList()
          ..sort((a, b) => _label(a).compareTo(_label(b)));
    final by = <String, List<String>>{};
    for (final p in shown) {
      by.putIfAbsent(_standing(p, byProvider), () => []).add(p);
    }

    Widget section(String key, String title, Color? colour) {
      final mine = by[key] ?? const <String>[];
      if (mine.isEmpty) return const SizedBox.shrink();
      return Padding(
        key: ValueKey('integrations-section:$key'),
        padding: const EdgeInsets.only(bottom: 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '$title · ${mine.length}',
              style: theme.textTheme.labelLarge?.copyWith(color: colour),
            ),
            const SizedBox(height: 8),
            if (_phone)
              for (final provider in mine)
                _isFamily(provider)
                    ? Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: _familyCard(provider, byProvider),
                      )
                    : _providerRow(
                        context,
                        provider: provider,
                        rows: byProvider[provider] ?? const [],
                      )
            else
              Wrap(
                spacing: 12,
                runSpacing: 12,
                children: [
                  for (final provider in mine)
                    _isFamily(provider)
                        ? _familyCard(provider, byProvider)
                        : _providerCard(
                            context,
                            provider: provider,
                            rows: byProvider[provider] ?? const [],
                            canName: _familyOf(provider) == 'reporting',
                          ),
                ],
              ),
          ],
        ),
      );
    }

    return ListView(
      key: const ValueKey('tour-integrations-list'),
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      children: [
        if (_error != null) ...[
          DashboardNote(_error!),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: const ValueKey('integrations-retry'),
              onPressed: () {
                setState(() => _loading = true);
                _load();
              },
              child: const Text('Try again'),
            ),
          ),
          const SizedBox(height: 12),
        ],
        Padding(
          padding: const EdgeInsets.only(bottom: 16),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(
                key: const ValueKey('integrations-category:all'),
                label: const Text('All'),
                selected: _category == null,
                onSelected: (_) => setState(() => _category = null),
              ),
              for (final (key, title) in _categories)
                if (present.contains(key))
                  ChoiceChip(
                    key: ValueKey('integrations-category:$key'),
                    label: Text(title),
                    selected: _category == key,
                    onSelected: (on) =>
                        setState(() => _category = on ? key : null),
                  ),
            ],
          ),
        ),
        for (final line in _problems)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              line,
              key: const ValueKey('integrations-problem'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: StateColour.of(context).caution,
              ),
            ),
          ),
        section(
          'attention',
          'Needs attention',
          StateColour.of(context).caution,
        ),
        section('connected', 'Connected', null),
        section('available', 'Available', null),
        if (shown.isEmpty) DashboardNote('No service matches in $_viewName.'),
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
  /// What Email › Settings needs to show and change a Workspace domain: the
  /// route's `email.settings.view` and the controller's `email.edit`.
  bool get _mayManageWorkspace =>
      _mayConnect('email.settings.view') && _mayConnect('email.edit');

  /// Connect, through Email's own panel and endpoints.
  List<Widget> _workspaceForm() => [
    if (_workspaceCanConnect)
      Align(
        alignment: Alignment.centerLeft,
        child: FilledButton(
          key: const ValueKey('connect:google_workspace'),
          onPressed: () async {
            final connected = await showSidePanel<bool>(
              context,
              builder: (_) => ConnectWorkspacePanel(api: widget.api),
            );
            if (connected == true) await _load();
          },
          child: const Text('Connect Workspace'),
        ),
      )
    else
      DashboardNote(
        '$_viewName already has a Google Workspace connected. Disconnect it '
        'above to connect a different domain.',
      ),
  ];

  /// Disconnect, through the same `DELETE /email/workspace` Email uses.
  /// Answers the server's refusal, or null when it went.
  Future<ApiResponse> _disconnectWorkspace(
    IntegrationConnection row,
    String code,
  ) async {
    final response = await widget.api.deleteWithBody('/email/workspace', {
      'scope_type': row.scopeType,
      'scope_id': row.scopeId,
      'code': code,
    });
    if (response.ok) await _load();
    return response;
  }

  /// Disconnect one account through Marketing's own endpoint, the one every
  /// module's Disconnect uses. Answers the refusal, or what is left to remove
  /// on the provider's side, or null.
  Future<ApiResponse> _disconnectConnection(
    IntegrationConnection row,
    String code,
  ) async {
    final response = await widget.api.deleteWithBody(
      '/social/connections/${row.id}',
      {'code': code},
    );
    if (response.ok) await _load();
    return response;
  }

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
    'ringcentral' when _maySeeExperimental => _ringCentralForm,
    'google_workspace' when _mayManageWorkspace => _workspaceForm,
    'telegram' when _mayConnectProvider('telegram') => _telegramForm,
    _ => null,
  };

  /// The organization's own Telegram bot for the staff Tech Assistant (Jose,
  /// 2026-10-26: Telegram worked but nothing in the app set it up). One bot
  /// per organization: with one connected, the form gives way to its staff.
  List<Widget> _telegramForm() {
    final theme = Theme.of(context);
    final connected = _connections.any((c) => c.provider == 'telegram');
    final orgView =
        (widget.session.me?.scope.type ?? 'organization') == 'organization';

    return [
      if (connected) ...[
        TelegramLinkedStaff(api: widget.api),
        const SizedBox(height: 16),
        Text(
          'To use a different bot, disconnect this one above first.',
          style: theme.textTheme.bodySmall,
        ),
      ] else if (!orgView)
        const DashboardNote(
          'A Telegram bot serves the whole organization. Switch to the '
          'organization view to connect it.',
        )
      else ...[
        Text('Telegram bot', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'In Telegram, message @BotFather, send /newbot and pick a name. '
          'Paste the token it gives you here. Staff then open the bot and '
          'share their phone number to link.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        ApiKeyConnectForm(
          api: widget.api,
          path: '/social/telegram',
          submitLabel: 'Connect bot',
          fields: const [
            ('bot_token', 'Bot token', 'From @BotFather, like 123456:ABC-DEF…'),
          ],
          onConnected: _load,
        ),
      ],
    ];
  }

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
      'Paste one access token, then tick the bases EzBizi should use. A new '
      'token can replace the old one for every base at once.',
      style: Theme.of(context).textTheme.bodySmall,
    ),
    const SizedBox(height: 8),
    AirtableTokenConnect(
      api: widget.api,
      hasConnections: _connections.any((c) => c.provider == 'airtable'),
      onChanged: _load,
    ),
  ];

  final _workizFormKey = GlobalKey();

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
    // v1, v2 or a signed-in session, each with its own boxes (UI queue 109,
    // 120). A global key, because reloading the page can add a group above
    // the form, and the session's link and countdown must survive that.
    WorkizConnectForm(
      key: _workizFormKey,
      api: widget.api,
      branches: widget.session.me?.franchisesInView ?? const [],
      companies: widget.session.me?.companies ?? const [],
      companiesInView: widget.session.me?.companiesInView ?? const [],
      // One login per company: the company in view, or the one picked in the
      // organization view; a branch view is told to switch.
      viewType: widget.session.me?.scope.type ?? 'organization',
      viewId: widget.session.me?.scope.id,
      // The server's disconnect gate is the provider's connect permission.
      mayDisconnect: _mayConnectProvider('workiz_web'),
      onDone: _load,
    ),
  ];

  /// New credentials, or another method, for one Workiz account (UI queue
  /// 109). The saved ones are never shown back.
  Future<void> _editWorkiz(IntegrationConnection row) async {
    // The web login has no credentials to replace; it is sent again from
    // the form's own Signed-in session.
    if (row.provider == 'workiz_web') return;
    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Edit ${row.title}',
        child: WorkizConnectForm(
          api: widget.api,
          connectionId: row.id,
          method: row.method,
          label: row.label,
          onDone: () => Navigator.of(context).pop(),
        ),
      ),
    );
    await _load();
  }

  /// Jose, 2026-10-15: "Shouldn't ring central also show here on
  /// Integrations?" The organization's own RingCentral app, JWT flow — the
  /// same three values the call report read from the server's env.
  List<Widget> _ringCentralForm() => [
    Text('RingCentral', style: Theme.of(context).textTheme.titleSmall),
    const SizedBox(height: 4),
    Text(
      'In the RingCentral Developer Console, create an app that can read the '
      'call log, then paste its three keys here.',
      style: Theme.of(context).textTheme.bodySmall,
    ),
    const SizedBox(height: 8),
    ApiKeyConnectForm(
      api: widget.api,
      path: '/social/ringcentral',
      submitLabel: 'Connect account',
      fields: const [
        ('client_id', 'App ID', 'Your app › Credentials (Client ID)'),
        ('client_secret', 'App secret', 'Beside the App ID (Client secret)'),
        ('jwt', 'Sign-in key', 'Credentials › JWT, made for this app'),
      ],
      onConnected: _load,
    ),
  ];

  /// The products a head's sign-in can bring, when it lists two or more
  /// (UI queue 183): the catalogue's `products[]`. One product is just the
  /// provider, with nothing to choose between.
  List<Map<String, dynamic>> _products(String head) {
    final list = ((_catalogue[head]?['products'] as List<dynamic>?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    return list.length > 1 ? list : const [];
  }

  bool _isFamily(String provider) =>
      _folded.containsKey(provider) || _products(provider).isNotEmpty;

  /// A product with a sign-in of its own (WhatsApp's Embedded Signup): the
  /// family's Disconnect leaves it (Meta API, 2da5e93ec).
  bool _ownSignIn(String head, Map<String, dynamic> product) {
    final path = product['start_path'] as String?;
    return path != null &&
        path.isNotEmpty &&
        path != _catalogue[head]?['start_path'];
  }

  /// What the server said it could not take back, after a disconnect: shown
  /// as a caution line rather than lost in a snackbar.
  List<String> _problems = const [];

  void _noteProblems(ApiResponse? response) {
    final data = response?.data;
    final problems = data is Map<String, dynamic> ? data['problems'] : null;
    setState(() {
      _problems = problems is List
          ? problems.whereType<String>().toList()
          : const [];
    });
  }

  /// Set up in another module, never at a sign-in (Gmail → Email's
  /// Workspace setup, Leads + Google 6262b4928): no scopes of its own.
  bool _setUpElsewhere(String head, Map<String, dynamic> product) =>
      _ownSignIn(head, product) &&
      ((product['scopes'] as List<dynamic>?) ?? const []).isEmpty;

  /// Lands on the module a product is set up in: `/email/workspace` → Email.
  void _openElsewhere(Map<String, dynamic> product) {
    final module = ((product['start_path'] as String?) ?? '')
        .split('/')
        .firstWhere((s) => s.isNotEmpty, orElse: () => '');
    if (module.isNotEmpty) OpenDestination.of(context)?.call(module);
  }

  /// The login a family's rows came from. Google's DELETE needs it beside the
  /// code (6262b4928); a family whose rows carry none sends none.
  Map<String, dynamic>? _accountEmail(String head) {
    final providers = {
      head,
      ...?_folded[head],
      for (final p in _products(head)) p['provider'] as String? ?? '',
    };
    for (final c in [..._connections, ..._workspace]) {
      final email = c.accountEmail;
      if (providers.contains(c.provider) && email != null && email.isNotEmpty) {
        return {'account_email': email};
      }
    }
    return null;
  }

  /// One sign-in and everything it brings (UI queue 182, reshaped by 183).
  Widget _familyCard(
    String head,
    Map<String, List<IntegrationConnection>> byProvider,
  ) {
    final theme = Theme.of(context);
    final all = [head, ...?_folded[head]];
    final rows = [for (final p in all) ...?byProvider[p]];
    final may = _mayConnectProvider(head);
    final products = _products(head);
    final usable = may && _catalogue[head]?['usable'] != false;

    Widget pill(String provider) => _pill(
      theme,
      rows: byProvider[provider] ?? const [],
      broken: (byProvider[provider] ?? const [])
          .where((r) => !r.connected)
          .length,
    );

    return IntegrationFamilyCard(
      head: head,
      label: _label(head),
      subtitle: rows.isEmpty ? (_purpose[head] ?? '') : _lastChecked(rows),
      phone: _phone,
      mayAddAccess: usable,
      onOpen: () => setState(() => _open = head),
      onManage: products.isNotEmpty && usable ? () => _choose(head) : null,
      onAddAccess: products.isNotEmpty
          ? (key) {
              final product = products.firstWhere(
                (p) => p['key'] == key,
                orElse: () => const {},
              );
              _setUpElsewhere(head, product)
                  ? _openElsewhere(product)
                  : _choose(head, preTick: {key});
            }
          : null,
      onDisconnect: rows.isNotEmpty && may
          ? () => _disconnectFamily(head)
          : null,
      products: products.isNotEmpty
          ? [
              for (final p in products)
                FamilyProduct(
                  provider: p['key'] as String? ?? '',
                  label: p['label'] as String? ?? '',
                  connected: p['connected'] == true,
                  keeps: _ownSignIn(head, p),
                  detail: p['connected'] != true
                      ? 'Not granted at sign-in'
                      : (byProvider[p['provider']] ?? const []).isEmpty
                      ? 'Connected'
                      : _beneath(byProvider[p['provider']]!),
                  status: pill(p['provider'] as String? ?? ''),
                ),
            ]
          : [
              for (final p in all)
                FamilyProduct(
                  provider: p,
                  label: _label(p),
                  connected: (byProvider[p] ?? const []).isNotEmpty,
                  detail: (byProvider[p] ?? const []).isEmpty
                      ? 'Not granted at sign-in'
                      : _beneath(byProvider[p]!),
                  status: pill(p),
                ),
            ],
    );
  }

  /// "Choose what to connect" (UI queue 183): ticks sign in for just those;
  /// unticking removes one product with `DELETE …/connections?product=`.
  Future<void> _choose(String head, {Set<String> preTick = const {}}) async {
    await showSidePanel<void>(
      context,
      builder: (_) => ChooseProductsPanel(
        api: widget.api,
        label: _label(head),
        startPath: _catalogue[head]?['start_path'] as String? ?? '',
        products: _products(head),
        preTick: preTick,
        openConsent: widget.openConsent,
        onElsewhere: (product) {
          Navigator.of(context).pop();
          _openElsewhere(product);
        },
        onChanged: _load,
        onRemove: (key) => confirmDisconnect(
          context,
          api: widget.api,
          title:
              'Remove ${_products(head).firstWhere((p) => p['key'] == key, orElse: () => const {})['label'] ?? key}?',
          explanation:
              'EzBizi stops using it, and asks ${_label(head)} to take the '
              'access back.',
          perform: (code) => widget.api.deleteWithBody(
            '/social/providers/$head/connections?product=$key',
            {'code': code, ...?_accountEmail(head)},
          ),
        ),
      ),
    );
    if (mounted) await _load();
  }

  /// One code, every row the sign-in brought: the server's
  /// `DELETE social/providers/{head}/connections` takes the whole family —
  /// except a product with a sign-in of its own, which stays.
  Future<void> _disconnectFamily(String head) async {
    final products = _products(head);
    final goes = products.isNotEmpty
        ? [
            for (final p in products)
              if (p['connected'] == true && !_ownSignIn(head, p))
                p['label'] as String? ?? '',
          ]
        : [head, ...?_folded[head]].map(_label).toList();
    final stays = [
      for (final p in products)
        if (p['connected'] == true && _ownSignIn(head, p))
          p['label'] as String? ?? '',
    ];
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect ${_label(head)}?',
      explanation:
          'EzBizi stops using everything this sign-in brought: '
          '${goes.join(', ')}. To use them again, sign in again.'
          '${stays.isEmpty ? '' : ' ${stays.join(', ')} stays connected.'}',
      perform: (code) => widget.api.deleteWithBody(
        '/social/providers/$head/connections',
        {'code': code, ...?_accountEmail(head)},
      ),
    );
    if (done != null && mounted) {
      _noteProblems(done);
      await _load();
    }
  }

  String _label(String provider) =>
      (switch (provider) {
        'google_workspace' => 'Google Workspace',
        'phone_numbers' => 'Phone numbers',
        'sending_server' => 'Sending server',
        _ => null,
      }) ??
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

  /// One line on what each provider is for, on a card with nothing
  /// connected yet. A provider this build does not know says nothing.
  static const _purpose = {
    'mailchimp': 'Sends your newsletters from your own account.',
    'facebook_page': 'Post to your Facebook page.',
    'instagram_professional': 'Post to your Instagram account.',
    'threads': 'Post to Threads.',
    'linkedin': 'Post to your LinkedIn page.',
    'tiktok': 'Post videos to TikTok.',
    'pinterest': 'Pin to your Pinterest boards.',
    'youtube': 'Post videos to YouTube.',
    'x': 'Post to X.',
    'bluesky': 'Post to Bluesky.',
    'google_business_profile': 'Your listing, its reviews and its messages.',
    'google_ads': 'Leads and spend from your Google Ads.',
    'facebook_ads': 'Leads and spend from your Facebook ads.',
    'thumbtack': 'Leads and messages from Thumbtack.',
    'workiz': 'Jobs and calls from Workiz, for reports.',
    'airtable': 'A base read by Profit & Loss.',
    'ringcentral': 'Calls from RingCentral, for reports.',
    'whatsapp': 'Your own WhatsApp number, to message customers.',
    'telegram': 'Your staff Tech Assistant bot on Telegram.',
    'google_workspace': 'Shared inboxes from your Google Workspace domain.',
    'phone_numbers': 'Calls and texts on your EzBizi numbers.',
    'sending_server':
        'Mail EzBizi sends for you goes out through your own mail server.',
  };

  /// What a card with nothing connected offers: Connect where connecting can
  /// work, otherwise why not, so the button never leads nowhere.
  ///
  /// Connect opens the provider's own page, which already knows every way
  /// in: the sign-in, the reporting forms, or the module that connects it.
  Widget _offer(BuildContext context, String provider) {
    final theme = Theme.of(context);
    final entry = _catalogue[provider];
    final why = entry?['unusable_because'] as String?;
    final note = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w600,
    );

    // Refused here: say why, where Connect would be.
    final refusal = _sendingRefusal;
    if (provider == 'sending_server' && refusal != null) {
      return Text(
        refusal,
        key: const ValueKey('refused:sending_server'),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: note,
      );
    }

    if (entry?['usable'] == false) {
      return Text(
        why == null || why.isEmpty ? 'Not available on EzBizi yet.' : why,
        key: ValueKey('unavailable:$provider'),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: note,
      );
    }
    // Reporting is gated by its own forms (see [_formsFor]); the rest by the
    // catalogue's permission.
    // Reporting and Email are gated by their own forms (see [_formsFor]).
    final may = switch (_familyOf(provider)) {
      // Each opens a page that says how, whoever looks.
      'phone' => true,
      _ when provider == 'sending_server' => true,
      'reporting' || 'email' => _formsFor(provider) != null,
      _ => _mayConnectProvider(provider),
    };
    if (!may) {
      return Text(
        'Ask an administrator to connect it.',
        key: ValueKey('ask:$provider'),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: note,
      );
    }
    return FilledButton.tonal(
      key: ValueKey('connect-card:$provider'),
      onPressed: () => setState(() => _open = provider),
      child: const Text('Connect'),
    );
  }

  /// One provider, and what this view has of it.
  Widget _providerCard(
    BuildContext context, {
    required String provider,
    required List<IntegrationConnection> rows,
    bool canName = false,
  }) {
    final theme = Theme.of(context);
    final broken = rows.where((r) => !r.connected).length;
    final none = rows.isEmpty;

    // One height for every card in the grid, whatever it holds.
    return SizedBox(
      width: 240,
      height: 168,
      child: InkWell(
        key: ValueKey('provider:$provider'),
        borderRadius: BorderRadius.circular(12),
        // Sets state; the body swaps. NOT a push — see [_open]. Everything
        // the pane needs is read there from the same maps this card read,
        // so the two cannot show different connections for one provider.
        onTap: none && _catalogue[provider]?['usable'] == false
            ? null
            : () => setState(() => _open = provider),
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            // Lighter when nothing is connected, so a glance tells what this
            // business has from what it could have.
            color: none ? theme.colorScheme.surfaceContainerLow : null,
            border: Border.all(
              color: none
                  ? theme.colorScheme.outlineVariant.withValues(alpha: 0.6)
                  : theme.colorScheme.outlineVariant,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                // spaceBetween, not a Spacer: a Spacer took half the row and
                // cut the pill to "Not conne…".
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  SocialNetworkMark(network: provider, size: 28),
                  const SizedBox(width: 8),
                  // Flexible, because the pill is the only thing on this card
                  // that can grow; a fourth status word, or another language,
                  // would otherwise overflow at paint time with no warning.
                  Flexible(
                    child: _pill(theme, rows: rows, broken: broken),
                  ),
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
                 * WHAT IT IS CONNECTED AT, not just how many: "53 connected"
                 * was the number Jose's screenshot made useless. The detail
                 * screen behind this card names the companies and branches.
                 */
                none ? (_purpose[provider] ?? '') : _beneathFor(provider, rows),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: broken > 0
                      ? theme.colorScheme.error
                      : theme.colorScheme.onSurfaceVariant,
                ),
              ),
              if (!none) ...[
                const SizedBox(height: 2),
                Text(
                  _lastChecked(rows),
                  key: ValueKey('last-checked:$provider'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
              if (none) ...[const Spacer(), _offer(context, provider)],
            ],
          ),
        ),
      ),
    );
  }

  /// The same, as one line of a phone's list.
  Widget _providerRow(
    BuildContext context, {
    required String provider,
    required List<IntegrationConnection> rows,
  }) {
    final theme = Theme.of(context);
    final broken = rows.where((r) => !r.connected).length;
    final none = rows.isEmpty;

    return InkWell(
      key: ValueKey('provider:$provider'),
      onTap: none && _catalogue[provider]?['usable'] == false
          ? null
          : () => setState(() => _open = provider),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            SocialNetworkMark(network: provider, size: 26),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _label(provider),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    // What it is for, as the card says (UI queue 110).
                    none
                        ? (_purpose[provider] ?? 'Not connected')
                        : _beneathFor(provider, rows),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: broken > 0
                          ? theme.colorScheme.error
                          : theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  if (!none)
                    Text(
                      _lastChecked(rows),
                      key: ValueKey('last-checked:$provider'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            if (none)
              Flexible(child: _offer(context, provider))
            else
              _pill(theme, rows: rows, broken: broken),
          ],
        ),
      ),
    );
  }

  /// The line under a connected card. The sending server says what it sends
  /// as and through which host — one server per scope, so a count is noise.
  String _beneathFor(String provider, List<IntegrationConnection> rows) {
    if (provider == 'sending_server' && rows.isNotEmpty) {
      final row = rows.first;
      final from = row.label;

      // A broken server never reads "Connected" (Jose, 2026-11-03).
      if (!row.connected) {
        return 'Needs reconnecting · ${row.statusReason ?? row.displayName}';
      }

      return from == null || from.isEmpty
          ? 'Connected · ${row.displayName}'
          : 'Connected · $from\n${row.displayName}';
    }

    return _beneath(rows);
  }

  /// "Last checked …" from the daily token check: the latest verdict among
  /// these rows, or that none has been checked yet (Jose, 2026-11-03).
  static String _lastChecked(List<IntegrationConnection> rows) {
    final checked = rows.map((r) => r.lastCheckedAt).whereType<DateTime>();
    if (checked.isEmpty) return 'Not checked yet';
    final at = checked.reduce((a, b) => a.isAfter(b) ? a : b).toLocal();
    final d = DateTime.now().difference(at);
    final when = d.inMinutes < 1
        ? 'just now'
        : d.inHours < 1
        ? '${d.inMinutes} min ago'
        : d.inDays < 1
        ? '${d.inHours} h ago'
        : '${at.year}-${_two(at.month)}-${_two(at.day)}';
    return 'Last checked $when';
  }

  static String _two(int n) => n.toString().padLeft(2, '0');

  String _beneath(List<IntegrationConnection> rows) {
    if (rows.isEmpty) return 'Nothing connected here yet.';

    final broken = rows.where((r) => !r.connected).length;

    final scopes = <String>{
      for (final row in rows.where((r) => r.scopeType.isNotEmpty))
        switch (row.scopeType) {
          'organization' => 'the organization',
          'company' => 'a company',
          'franchise' => 'a branch',
          _ => 'another place',
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
      (true, _) => ('Not connected', theme.colorScheme.onSurfaceVariant),
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

/// Phone's own health for one line (NumbersController `health`): the plain
/// sentences of what is wrong; empty when it is ok. A row without `health`
/// (an older server) is read as ok rather than invented broken.
List<String> _phoneProblems(Map<String, dynamic> n) {
  final health = n['health'] as Map<String, dynamic>?;
  if (health == null || health['ok'] == true) return const [];
  final problems = ((health['problems'] as List<dynamic>?) ?? const [])
      .whereType<String>()
      .toList();
  return problems.isEmpty ? const ['This line is not working.'] : problems;
}
