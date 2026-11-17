// Leads › Manage: the Connections page, with Templates and Dashboard beside it.
//
// UI queue 34, docs/previews/leads-connections-layouts.html, Jose's 1 + 4
// (2026-09-30: "Sounds good, go ahead with ur recommendations"), after "i feel
// we could also use a revamp on the manage of leads". It replaces one narrow
// side panel that stacked every provider in one scroll, with three text
// buttons on every account row and certification problems as loose red lines.
//
// Manage's tabs (Jose, 2026-09-30: "inside manage and we can make a
// connection sub tab and templates sub tab", then a Dashboard "sub tab for
// leads"): Connections, described below; Templates, ReplyTemplatesManager;
// Dashboard, LeadsDashboard (UI queue 38 drew those two); Stats, LeadsStats;
// and Office hours, LeadsOfficeHours (UI queue 138).
//
// The page takes the place of the leads, with ← back (containment): the
// providers down the left with how many need attention, the chosen provider's
// accounts as a table narrowed by chips, and an account opened beside the list
// with its name, where its leads go, its certification and Disconnect. A row
// carries no buttons. On a phone the same three are steps, each with ← back.
//
// ── Kept from the list it replaces ───────────────────────────────
//
// One connect made twenty-two Google Ads accounts in the same second and the
// screen showed one; one Thumbtack connect made twelve businesses all called
// "A1 Chimney". So every account is listed; a name worn twice shows the
// provider's id until somebody names it; the accounts that never sent a lead
// fold away, and search reaches through the fold; a placement the reader
// cannot see is stated and cannot be changed; and Disconnect all is one
// question and one call.

import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'connect_account.dart';
import 'error_note.dart';
import 'grouped_rows.dart';
import 'leads_dashboard.dart';
import 'leads_hidden.dart';
import 'leads_office_hours.dart';
import 'leads_recovery.dart';
import 'leads_sources.dart';
import 'leads_stats.dart';
import 'reply_templates_manager.dart';
import 'search_picker.dart';
import 'side_panel.dart';
import 'social_network_mark.dart';
import 'status_pill.dart';
import 'tab_bar_like.dart';
import 'disconnect_panel.dart';

/// How a placement reads, and whether it can be changed.
///
/// The server names a franchise only when the reader can reach it, so an
/// account placed outside their reach arrives with a real id and a null name.
/// That is an answer rather than missing data: the page says a placement EXISTS
/// without naming it, because naming a franchise somebody cannot otherwise
/// reach leaks the shape of the organization one row at a time.
({String text, bool editable}) placementOf(Map<String, dynamic> connection) {
  final id = connection['leads_franchise_id'];
  final name = connection['leads_franchise_name'];

  if (id == null) return (text: 'Company level', editable: true);
  if (name == null) {
    // Not movable on purpose. Offering to move something whose current value
    // you cannot see is offering to guess.
    return (text: 'Placed somewhere you cannot see', editable: false);
  }
  return (text: '$name', editable: true);
}

/// What the row calls itself: the name when it has one, the number when not.
///
/// Both stay on the row when named — the name is how you find it, the number is
/// how you check it against Google Ads. Jose, on twenty-two numbered rows:
/// *"no one will remember what the numbers mean."*
String accountName(Map<String, dynamic> connection) {
  final label = '${connection['label'] ?? ''}'.trim();
  return label.isEmpty ? '${connection['display_name'] ?? ''}' : label;
}

bool isNamed(Map<String, dynamic> connection) =>
    '${connection['label'] ?? ''}'.trim().isNotEmpty;

int leadsOf(Map<String, dynamic> connection) =>
    (connection['leads_count'] as num?)?.toInt() ?? 0;

/// How many of these sit on a franchise rather than at company level.
int placedCount(List<Map<String, dynamic>> connections) =>
    connections.where((c) => c['leads_franchise_id'] != null).length;

/// A connection the provider stopped honouring: nothing is fetched from it
/// until it is connected again.
bool isBroken(Map<String, dynamic> connection) =>
    connection['status'] == 'broken' || connection['status'] == 'revoked';

/// Connected, but no fetch from it has worked lately: a Google row can stay
/// `connected` while every sync fails (Leads, 2026-09-30).
bool isNotFetching(Map<String, dynamic> connection) =>
    connection['sync_failing'] == true;

/// Broken, not fetching, or Google says its Local Services certification
/// needs attention.
bool needsAttention(Map<String, dynamic> connection) =>
    isBroken(connection) ||
    isNotFetching(connection) ||
    (connection['certification'] as Map?)?['state'] == 'attention';

/// The account's state as one pill. Broken first, because nothing arrives;
/// then Google's daily certification check. An account never checked makes no
/// certification claim either way.
(String, Tone) accountState(Map<String, dynamic> connection) {
  if (isBroken(connection)) return ('Reconnect', Tone.bad);
  if (isNotFetching(connection)) return ('Not fetching', Tone.bad);
  final cert = connection['certification'] as Map?;
  if (cert == null) return ('Connected', Tone.neutral);
  return cert['state'] == 'attention'
      ? ('Needs attention', Tone.caution)
      : ('Certified', Tone.ok);
}

const _providers = <({String key, String name})>[
  (key: 'google_ads', name: 'Google Ads'),
  (key: 'thumbtack', name: 'Thumbtack'),
  (key: 'web', name: 'Website'),
];

/// The sources `GET /leads/sources` adds after Website (design 156), in
/// the list's order. Shown only when the server sends them: it leaves out a
/// source the person cannot view.
const _sourceProviders = <({String key, String name})>[
  (key: 'whatsapp', name: 'WhatsApp'),
  (key: 'facebook', name: 'Facebook / Messenger'),
  (key: 'instagram', name: 'Instagram'),
  (key: 'google_business_profile', name: 'Google Business Profile'),
];

/// The page: providers, their accounts, and one account beside them.
class LeadsConnections extends StatefulWidget {
  const LeadsConnections({
    super.key,
    required this.api,
    required this.session,
    required this.connections,
    required this.canEdit,
    required this.onChanged,
    required this.onBack,
  });

  final ApiClient api;
  final Session session;

  /// Every connection the leads list read (`meta.connections`).
  final List<Map<String, dynamic>> connections;

  /// `leads.edit`: naming, placing, connecting and disconnecting. Without it
  /// this is the same page with no controls, never a button that 403s.
  final bool canEdit;

  /// Reload the screen behind this. Placement is retroactive, so the leads
  /// change the moment an account moves.
  final Future<void> Function() onChanged;

  /// Back to the leads.
  final VoidCallback onBack;

  @override
  State<LeadsConnections> createState() => _LeadsConnectionsState();
}

class _LeadsConnectionsState extends State<LeadsConnections> {
  /// The provider shown. A desk always shows one; a phone starts on the list
  /// of them, so null there is a step of its own.
  String? _provider;

  /// all, attention or quiet: the chips.
  String _filter = 'all';

  final _search = TextEditingController();
  String _query = '';

  /// The accounts that never sent a lead, unfolded. Searching opens the fold
  /// as well, because a fold that hides a match is the defect it exists to
  /// avoid.
  bool _showQuiet = false;

  /// The account open beside the list, or in place of it on a phone.
  String? _openId;

  /// Disconnect all: asked once, then done in one call. One Thumbtack consent
  /// is twelve rows sharing one grant, and twelve confirmations for one
  /// account is the wrong number (Jose, 2026-09-09).
  bool _confirmAll = false;
  bool _lettingGo = false;
  String? _allError;

  /// Manage's tab: connections or templates.
  String _tab = 'connections';

  /// Templates is built the first time it is opened and kept after, so what
  /// somebody typed there survives a look at the connections.
  bool _templatesOpened = false;

  /// The dashboard, like Templates, is built on first open and kept after.
  bool _dashboardOpened = false;
  bool _statsOpened = false;
  bool _officeHoursOpened = false;

  /// A phone's office hours editor takes the page, as a template does.
  bool _officeHoursDeep = false;

  /// A phone's template steps past the first take the page, header and tabs
  /// included, the way the connections' steps do.
  bool _templatesDeep = false;

  /// `GET /leads/sources`, or null until it answers or when it does not
  /// (the endpoint is new): then Website keeps its explanation and the other
  /// sources stay out of the list.
  List<LeadSource>? _sources;

  /// The site open beside the table, or in place of it on a phone.
  String? _openSiteId;

  @override
  void initState() {
    super.initState();
    // The source panes' search boxes share this controller and set no
    // onChanged of their own.
    _search.addListener(() {
      final q = _search.text.trim().toLowerCase();
      if (q != _query) setState(() => _query = q);
    });
    _loadSources();
  }

  Future<void> _loadSources() async {
    final response = await widget.api.get('/leads/sources');
    if (!mounted || !response.ok) return;
    setState(() => _sources = parseLeadSources(response.body));
  }

  /// The server's source for a provider row; `web` is its `website`.
  LeadSource? _source(String provider) {
    final key = provider == 'web' ? 'website' : provider;
    for (final s in _sources ?? const <LeadSource>[]) {
      if (s.key == key) return s;
    }
    return null;
  }

  /// Google Ads and Thumbtack always; Website unless the server left it out;
  /// the rest when the server sent them.
  List<({String key, String name})> get _shownProviders => [
    for (final p in _providers)
      if (p.key != 'web' || _sources == null || _source('web') != null) p,
    for (final p in _sourceProviders)
      if (_source(p.key) != null) p,
  ];

  SourceItem? _openSite() {
    for (final i in _source('web')?.items ?? const <SourceItem>[]) {
      if (i.id == _openSiteId) return i;
    }
    return null;
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> _accountsOf(String provider) => [
    for (final c in widget.connections)
      if (c['provider'] == provider) c,
  ];

  /// The first provider with anything connected, so a desk opens on accounts.
  String get _firstProvider => _providers
      .firstWhere(
        (p) => _accountsOf(p.key).isNotEmpty,
        orElse: () => _providers.first,
      )
      .key;

  Map<String, dynamic>? _open(String provider) {
    for (final c in _accountsOf(provider)) {
      if ('${c['id']}' == _openId) return c;
    }
    return null;
  }

  void _choose(String provider) => setState(() {
    _provider = provider;
    _openId = null;
    _openSiteId = null;
    _filter = 'all';
    _search.clear();
    _query = '';
    _showQuiet = false;
    _confirmAll = false;
    _allError = null;
  });

  Future<void> _disconnectAll(String provider) async {
    setState(() {
      _lettingGo = true;
      _allError = null;
    });

    final response = await widget.api.delete(
      '/social/providers/$provider/connections',
    );
    if (!mounted) return;

    setState(() {
      _lettingGo = false;
      _confirmAll = false;
      _allError = response.ok ? null : response.reason;
      if (response.ok) _openId = null;
    });

    if (response.ok) await widget.onChanged();
  }

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) =>
        constraints.maxWidth >= 900 ? _desk(context) : _phone(context),
  );

  Widget _desk(BuildContext context) {
    final provider = _provider ?? _firstProvider;
    final open = _open(provider);
    final connections = _tab == 'connections';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(
          context,
          title: 'Manage',
          subtitle: connections
              ? 'Where enquiries come from, and where each account\'s leads go.'
              : null,
          backTooltip: 'Back to the leads',
          onBack: widget.onBack,
          action:
              connections &&
                  widget.canEdit &&
                  provider != 'web' &&
                  _accountsOf(provider).isNotEmpty
              ? _connect(provider)
              : null,
        ),
        _tabStrip(context),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(
                offstage: !connections,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      width: 240,
                      child: _providerList(context, selected: provider),
                    ),
                    const VerticalDivider(width: 1),
                    if (provider == 'web' && _openSite() != null) ...[
                      Expanded(child: _pane(context, provider, table: true)),
                      const VerticalDivider(width: 1),
                      SizedBox(
                        width: 400,
                        child: _sitePanel(_openSite()!, inPlace: false),
                      ),
                    ] else if (open == null)
                      Expanded(child: _pane(context, provider, table: true))
                    else ...[
                      SizedBox(
                        width: 360,
                        child: _pane(context, provider, table: false),
                      ),
                      const VerticalDivider(width: 1),
                      Expanded(child: _panel(open, provider, inPlace: false)),
                    ],
                  ],
                ),
              ),
              Offstage(offstage: _tab != 'templates', child: _templates()),
              Offstage(offstage: _tab != 'dashboard', child: _dashboard()),
              Offstage(offstage: _tab != 'stats', child: _stats()),
              Offstage(offstage: _tab != 'office_hours', child: _officeHours()),
              Offstage(offstage: _tab != 'hidden', child: _hidden()),
              Offstage(offstage: _tab != 'recovery', child: _recovery()),
            ],
          ),
        ),
      ],
    );
  }

  /// A phone: the tabs on the first step only. The steps after it are the
  /// connections' own, each with its own ← back.
  Widget _phone(BuildContext context) {
    final connections = _tab == 'connections';

    return Stack(
      fit: StackFit.expand,
      children: [
        Offstage(offstage: !connections, child: _phoneConnections(context)),
        Offstage(
          offstage: _tab != 'templates',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (!_templatesDeep) ...[
                _header(
                  context,
                  title: 'Manage',
                  backTooltip: 'Back to the leads',
                  onBack: widget.onBack,
                ),
                _tabStrip(context),
                const Divider(height: 1),
              ],
              // Keyed, so hiding the header above keeps the templates' State.
              Expanded(
                key: const ValueKey('templates-body'),
                child: _templates(),
              ),
            ],
          ),
        ),
        Offstage(
          offstage: _tab != 'dashboard',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(
                context,
                title: 'Manage',
                backTooltip: 'Back to the leads',
                onBack: widget.onBack,
              ),
              _tabStrip(context),
              const Divider(height: 1),
              Expanded(child: _dashboard()),
            ],
          ),
        ),
        Offstage(
          offstage: _tab != 'stats',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(
                context,
                title: 'Manage',
                backTooltip: 'Back to the leads',
                onBack: widget.onBack,
              ),
              _tabStrip(context),
              const Divider(height: 1),
              Expanded(child: _stats()),
            ],
          ),
        ),
        Offstage(
          offstage: _tab != 'office_hours',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (!_officeHoursDeep) ...[
                _header(
                  context,
                  title: 'Manage',
                  backTooltip: 'Back to the leads',
                  onBack: widget.onBack,
                ),
                _tabStrip(context),
                const Divider(height: 1),
              ],
              // Keyed, so hiding the header above keeps the editor's State.
              Expanded(
                key: const ValueKey('office-hours-body'),
                child: _officeHours(),
              ),
            ],
          ),
        ),
        Offstage(
          offstage: _tab != 'hidden',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(
                context,
                title: 'Manage',
                backTooltip: 'Back to the leads',
                onBack: widget.onBack,
              ),
              _tabStrip(context),
              const Divider(height: 1),
              Expanded(child: _hidden()),
            ],
          ),
        ),
        Offstage(
          offstage: _tab != 'recovery',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(
                context,
                title: 'Manage',
                backTooltip: 'Back to the leads',
                onBack: widget.onBack,
              ),
              _tabStrip(context),
              const Divider(height: 1),
              Expanded(child: _recovery()),
            ],
          ),
        ),
      ],
    );
  }

  Widget _phoneConnections(BuildContext context) {
    final provider = _provider;

    if (provider == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _header(
            context,
            title: 'Manage',
            backTooltip: 'Back to the leads',
            onBack: widget.onBack,
          ),
          _tabStrip(context),
          const Divider(height: 1),
          Expanded(child: _providerList(context)),
        ],
      );
    }

    final open = _open(provider);
    if (open != null) return _panel(open, provider, inPlace: true);
    final site = provider == 'web' ? _openSite() : null;
    if (site != null) return _sitePanel(site, inPlace: true);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(
          context,
          title: _shownProviders
              .firstWhere(
                (p) => p.key == provider,
                orElse: () => _providers.first,
              )
              .name,
          backTooltip: 'Back to the connections',
          onBack: () => setState(() => _provider = null),
        ),
        const Divider(height: 1),
        Expanded(child: _pane(context, provider, table: false, phone: true)),
      ],
    );
  }

  Widget _header(
    BuildContext context, {
    required String title,
    String? subtitle,
    required String backTooltip,
    required VoidCallback onBack,
    Widget? action,
  }) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 16, 8),
      child: Row(
        children: [
          IconButton(
            key: const ValueKey('connections-back'),
            tooltip: backTooltip,
            icon: const Icon(Icons.arrow_back),
            onPressed: onBack,
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleLarge,
                ),
                if (subtitle != null)
                  Text(
                    subtitle,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
          ?action,
        ],
      ),
    );
  }

  List<(String, String, IconData)> get _manageTabs => [
    ..._allManageTabs,
    // Design 160, B1: only with the tick.
    if (widget.session.me?.can(leadsHiddenPermission) == true)
      ('hidden', 'Hidden', Icons.visibility_off_outlined),
    // Jose, 2026-11-16: the automatic win-back texts.
    if (widget.session.me?.can(leadsRecoveryPermission) == true)
      ('recovery', 'Recovery', Icons.replay_outlined),
  ];

  static const _allManageTabs = [
    ('connections', 'Connections', Icons.link),
    ('templates', 'Templates', Icons.bolt_outlined),
    ('dashboard', 'Dashboard', Icons.insights_outlined),
    // A1 takeover's Stats (UI queue 107).
    ('stats', 'Stats', Icons.bar_chart),
    // When each company and branch answers leads (UI queue 138).
    ('office_hours', 'Office hours', Icons.schedule_outlined),
  ];

  Widget _tabStrip(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    child: TabBarLike(
      key: const ValueKey('manage-tabs'),
      labels: [for (final t in _manageTabs) (t.$2, t.$3)],
      selected: _manageTabs.indexWhere((t) => t.$1 == _tab),
      onSelected: (i) => setState(() {
        _tab = _manageTabs[i].$1;
        if (_tab == 'templates') _templatesOpened = true;
        if (_tab == 'dashboard') _dashboardOpened = true;
        if (_tab == 'stats') _statsOpened = true;
        if (_tab == 'office_hours') _officeHoursOpened = true;
        if (_tab == 'hidden') _hiddenOpened = true;
        if (_tab == 'recovery') _recoveryOpened = true;
      }),
    ),
  );

  /// The replies beside Send, one list per company (04be9c2f; laid out as
  /// UI queue 38's 1 and 4).
  Widget _templates() => _templatesOpened
      ? ReplyTemplatesManager(
          api: widget.api,
          onDeeper: (deep) => setState(() => _templatesDeep = deep),
        )
      : const SizedBox.shrink();

  /// Replies, response times and leads, per person and per day (UI queue
  /// 38's 7 and 10).
  Widget _stats() => _statsOpened
      ? LeadsStats(api: widget.api, session: widget.session)
      : const SizedBox.shrink();

  /// Office hours and time zone per place (UI queue 138's 1 and 4).
  Widget _officeHours() => _officeHoursOpened
      ? LeadsOfficeHours(
          api: widget.api,
          onDeeper: (deep) => setState(() => _officeHoursDeep = deep),
        )
      : const SizedBox.shrink();

  bool _hiddenOpened = false;
  bool _recoveryOpened = false;

  Widget _recovery() => _recoveryOpened
      ? LeadsRecovery(api: widget.api, session: widget.session)
      : const SizedBox.shrink();

  Widget _hidden() => _hiddenOpened
      ? LeadsHidden(api: widget.api, session: widget.session)
      : const SizedBox.shrink();

  Widget _dashboard() => _dashboardOpened
      ? LeadsDashboard(api: widget.api, session: widget.session)
      : const SizedBox.shrink();

  /// The provider's own sign-in. The start paths are literals here, at the
  /// call site, as ConnectAccount asks.
  Widget _connect(String provider) => provider == 'thumbtack'
      ? ConnectAccount(
          api: widget.api,
          label: 'Thumbtack',
          startPath: '/social/thumbtack/start',
          onConnected: widget.onChanged,
        )
      : ConnectAccount(
          api: widget.api,
          label: 'Google Ads',
          startPath: '/social/google-ads/start',
          onConnected: widget.onChanged,
        );

  // ── the providers ────────────────────────────────────────────────

  Widget _providerList(BuildContext context, {String? selected}) {
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('connections-providers'),
      padding: const EdgeInsets.all(8),
      children: [
        for (final p in _shownProviders)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: ListTile(
              key: ValueKey('provider-${p.key}'),
              selected: p.key == selected,
              selectedTileColor: theme.colorScheme.primaryContainer,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
              // No site has a brand mark; a globe says "your website".
              leading: switch (p.key) {
                'web' => const Icon(Icons.language),
                'facebook' => const SocialNetworkMark(
                  network: 'facebook_page',
                  size: 24,
                ),
                'instagram' => const SocialNetworkMark(
                  network: 'instagram_professional',
                  size: 24,
                ),
                _ => SocialNetworkMark(network: p.key, size: 24),
              },
              title: Text(p.name),
              subtitle: Text(_summary(p.key)),
              trailing: _attentionMark(p.key, chevron: selected == null),
              onTap: () => _choose(p.key),
            ),
          ),
      ],
    );
  }

  /// "22 accounts · 13 sending leads": the count first, because the count is
  /// what tells twenty-two lookalike accounts from one.
  String _summary(String provider) {
    final source = _source(provider);
    if (source != null) {
      final n = source.count;
      final noun = switch (provider) {
        'web' => n == 1 ? 'site' : 'sites',
        'whatsapp' => n == 1 ? 'number' : 'numbers',
        'google_business_profile' => n == 1 ? 'listing' : 'listings',
        _ => n == 1 ? 'page' : 'pages',
      };
      return n == 0 ? 'Not connected' : '$n $noun';
    }
    if (provider == 'web') {
      return 'Chat and forms, through the WordPress plugin';
    }

    final all = _accountsOf(provider);
    if (all.isEmpty) return 'Not connected';

    final n = all.length;
    final noun = provider == 'thumbtack'
        ? (n == 1 ? 'business' : 'businesses')
        : (n == 1 ? 'account' : 'accounts');
    final sending = all.where((c) => leadsOf(c) > 0).length;
    return '$n $noun · $sending sending leads';
  }

  Widget? _attentionMark(String provider, {required bool chevron}) {
    final source = _source(provider);
    final n = _accountsOf(provider).where(needsAttention).length;
    final mark = source != null
        ? (source.needsAttention
              ? Tooltip(
                  key: ValueKey('attention-$provider'),
                  message: 'Needs attention',
                  child: const ToneDot(Tone.caution),
                )
              : null)
        : n == 0
        ? null
        : Tooltip(
            message: '$n ${n == 1 ? 'needs' : 'need'} attention',
            child: StatusPill('$n', tone: Tone.caution),
          );
    if (!chevron) return mark;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [?mark, const Icon(Icons.chevron_right)],
    );
  }

  // ── one provider's accounts ──────────────────────────────────────

  Widget _pane(
    BuildContext context,
    String provider, {
    required bool table,
    bool phone = false,
  }) {
    final source = _source(provider);
    if (provider == 'web') {
      return source == null
          ? _website(context)
          : LeadsWebsitePane(
              source: source,
              session: widget.session,
              search: _search,
              query: _query,
              table: table,
              openId: _openSiteId,
              onOpen: (i) => setState(() => _openSiteId = i.id),
            );
    }
    if (source != null) {
      return LeadsSourceItemsPane(
        source: source,
        search: _search,
        query: _query,
      );
    }

    final all = _accountsOf(provider);
    if (all.isEmpty) return _notConnected(context, provider);

    final theme = Theme.of(context);
    final twins = _twins(all);

    final attention = all.where(needsAttention).length;
    final quietCount = all.where((c) => leadsOf(c) == 0).length;
    // A chip that disappears while chosen falls back to All, rather than
    // leaving an empty list under a filter nobody can see.
    final filter = switch (_filter) {
      'attention' when attention > 0 => 'attention',
      'quiet' when quietCount > 0 => 'quiet',
      _ => 'all',
    };

    final shown = [
      for (final c in all)
        if (_matches(c) &&
            switch (filter) {
              'attention' => needsAttention(c),
              'quiet' => leadsOf(c) == 0,
              _ => true,
            })
          c,
    ];
    final producing = shown.where((c) => leadsOf(c) > 0).toList()
      ..sort((a, b) => leadsOf(b).compareTo(leadsOf(a)));
    final quiet = shown.where((c) => leadsOf(c) == 0).toList()
      ..sort(
        (a, b) => accountName(
          a,
        ).toLowerCase().compareTo(accountName(b).toLowerCase()),
      );

    final folded =
        filter == 'all' && _query.isEmpty && !_showQuiet && quiet.isNotEmpty;
    final groups = filter == 'all'
        ? [
            if (producing.isNotEmpty)
              RowGroup(
                heading: quiet.isEmpty ? '' : 'Sending leads',
                rows: producing,
              ),
            if (!folded && quiet.isNotEmpty)
              RowGroup(heading: 'Never sent a lead', rows: quiet),
          ]
        : [
            RowGroup(heading: '', rows: [...producing, ...quiet]),
          ];
    final footer = folded ? _fold(context, quiet.length) : null;

    final Widget body;
    if (shown.isEmpty) {
      body = Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No account matches “${_search.text.trim()}”. The list is filtered, '
          'not empty — clear the box to see all ${all.length} again.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    } else if (table) {
      body = GroupedTable<Map<String, dynamic>>(
        columns: _columns(context, twins),
        groups: groups,
        idOf: (c) => '${c['id']}',
        onOpen: (c) => setState(() => _openId = '${c['id']}'),
        footer: footer,
      );
    } else {
      body = GroupedList<Map<String, dynamic>>(
        groups: groups,
        idOf: (c) => '${c['id']}',
        tile: (context, c) => _tile(c, twins),
        onOpen: (c) => setState(() => _openId = '${c['id']}'),
        selectedId: _openId,
        footer: footer,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _filters(
          context,
          filter: filter,
          total: all.length,
          attention: attention,
          quiet: quietCount,
        ),
        Expanded(child: body),
        _foot(context, provider, all, phone: phone),
      ],
    );
  }

  bool _matches(Map<String, dynamic> c) {
    if (_query.isEmpty) return true;
    return [
      '${c['label'] ?? ''}',
      '${c['display_name'] ?? ''}',
      '${c['remote_id'] ?? ''}',
      placementOf(c).text,
    ].join(' ').toLowerCase().contains(_query);
  }

  /// display_names that more than one account wears.
  Set<String> _twins(List<Map<String, dynamic>> accounts) {
    final seen = <String, int>{};
    for (final c in accounts) {
      final name = '${c['display_name'] ?? ''}';
      seen[name] = (seen[name] ?? 0) + 1;
    }
    return {
      for (final e in seen.entries)
        if (e.value > 1) e.key,
    };
  }

  /// The line under the name: the number when named, and the provider's id
  /// for a twin nobody has named yet — the only thing that tells twins apart.
  String? _second(Map<String, dynamic> c, Set<String> twins) {
    if (isNamed(c)) return '${c['display_name'] ?? ''}';
    final id = '${c['remote_id'] ?? ''}';
    return twins.contains('${c['display_name'] ?? ''}') && id.isNotEmpty
        ? id
        : null;
  }

  Widget _filters(
    BuildContext context, {
    required String filter,
    required int total,
    required int attention,
    required int quiet,
  }) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    Widget chip(String value, String label, int count) => ChoiceChip(
      key: ValueKey('accounts-chip-$value'),
      showCheckmark: false,
      selected: filter == value,
      label: Text.rich(
        TextSpan(
          text: label,
          children: [
            TextSpan(
              text: '  $count',
              style: TextStyle(color: muted, fontWeight: FontWeight.w500),
            ),
          ],
        ),
      ),
      onSelected: (_) => setState(() {
        _filter = value;
        _showQuiet = false;
      }),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              chip('all', 'All', total),
              if (attention > 0)
                chip('attention', 'Needs attention', attention),
              if (quiet > 0) chip('quiet', 'Never sent a lead', quiet),
            ],
          ),
          // Only where there are enough to lose one in.
          if (total > 6) ...[
            const SizedBox(height: 10),
            ListSearch(
              key: const ValueKey('accounts-search'),
              controller: _search,
              hint: 'Find an account',
              onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
            ),
          ],
        ],
      ),
    );
  }

  List<TableColumn<Map<String, dynamic>>> _columns(
    BuildContext context,
    Set<String> twins,
  ) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return [
      TableColumn(
        'Account',
        flex: 3,
        cell: (_, c) {
          final second = _second(c, twins);
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                accountName(c),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              if (second != null)
                Text(
                  second,
                  key: isNamed(c) ? null : ValueKey('twin-${c['id']}'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
            ],
          );
        },
      ),
      TableColumn(
        'Leads go to',
        flex: 2,
        cell: (_, c) {
          final placement = placementOf(c);
          return Text(
            placement.text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: c['leads_franchise_id'] == null ? muted : null,
              fontStyle: placement.editable
                  ? FontStyle.normal
                  : FontStyle.italic,
            ),
          );
        },
      ),
      TableColumn(
        'Leads',
        width: 72,
        numeric: true,
        cell: (_, c) => Text('${leadsOf(c)}'),
      ),
      TableColumn(
        'Status',
        width: 164,
        cell: (_, c) {
          final (label, tone) = accountState(c);
          return StatusPill(label, tone: tone);
        },
      ),
    ];
  }

  Widget _tile(Map<String, dynamic> c, Set<String> twins) {
    final (label, tone) = accountState(c);
    final leads = leadsOf(c);

    return TwoLineTile(
      title: accountName(c),
      subtitle: [
        ?_second(c, twins),
        placementOf(c).text,
        leads == 0 ? 'no leads' : '$leads ${leads == 1 ? 'lead' : 'leads'}',
      ].join(' · '),
      bottomRight: StatusPill(label, tone: tone),
    );
  }

  /// The accounts that never sent a lead, folded, saying how many and that
  /// search still reaches them.
  Widget _fold(BuildContext context, int n) {
    final theme = Theme.of(context);

    return InkWell(
      key: const ValueKey('accounts-fold'),
      onTap: () => setState(() => _showQuiet = true),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$n that ${n == 1 ? 'has' : 'have'} never sent a lead',
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    'Search reaches in here',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            const Icon(Icons.expand_more, size: 20),
          ],
        ),
      ),
    );
  }

  /// How they are placed, and letting them all go, under the list. A phone
  /// connects here too, having no room in its title bar.
  Widget _foot(
    BuildContext context,
    String provider,
    List<Map<String, dynamic>> all, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final n = all.length;
    final placed = placedCount(all);
    final label =
        widget.session.me?.organization.franchiseLabel.toLowerCase() ??
        'franchise';

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_confirmAll) ...[
            Text(
              'Disconnect all $n accounts? Their leads are hidden from Leads '
              'until you connect again; nothing is deleted.',
              style: theme.textTheme.bodySmall,
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  key: const ValueKey('accounts-keep-all'),
                  onPressed: _lettingGo
                      ? null
                      : () => setState(() => _confirmAll = false),
                  child: const Text('Keep'),
                ),
                TextButton(
                  key: const ValueKey('accounts-disconnect-all-confirm'),
                  style: TextButton.styleFrom(
                    foregroundColor: theme.colorScheme.error,
                  ),
                  onPressed: _lettingGo ? null : () => _disconnectAll(provider),
                  child: Text(_lettingGo ? 'Disconnecting…' : 'Disconnect all'),
                ),
              ],
            ),
          ] else
            Row(
              children: [
                Expanded(
                  child: Text(
                    placed == 0
                        ? 'All at company level'
                        : '${n - placed} at company level · $placed on '
                              '${placed == 1 ? 'a $label' : '${label}s'}',
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ),
                // One account has its own Disconnect; all of one is not "all".
                if (widget.canEdit && n > 1)
                  TextButton(
                    key: const ValueKey('accounts-disconnect-all'),
                    style: TextButton.styleFrom(
                      foregroundColor: theme.colorScheme.error,
                    ),
                    onPressed: () => setState(() => _confirmAll = true),
                    child: Text('Disconnect all $n'),
                  ),
              ],
            ),
          if (_allError != null) ErrorNote(_allError!),
          if (phone && widget.canEdit) ...[
            const SizedBox(height: 8),
            _connect(provider),
          ],
        ],
      ),
    );
  }

  Widget _notConnected(BuildContext context, String provider) {
    final theme = Theme.of(context);
    final name = provider == 'thumbtack' ? 'Thumbtack' : 'Google Ads';

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('No $name account connected', style: theme.textTheme.titleSmall),
        if (provider == 'thumbtack') ...[
          const SizedBox(height: 4),
          Text(
            'Connecting brings Thumbtack enquiries into Leads, and keeps your '
            'team\'s phone numbers in step with Thumbtack so a technician '
            'calling a customer shows as the business.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
        const SizedBox(height: 12),
        // A control that is offered must be a control that works: somebody
        // holding only `leads.view` is told who can do it instead.
        Align(
          alignment: Alignment.centerLeft,
          child: widget.canEdit
              ? _connect(provider)
              : Text(
                  'Somebody who can manage Leads needs to connect the account.',
                  style: theme.textTheme.bodySmall,
                ),
        ),
      ],
    );
  }

  Widget _website(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Website', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Chat and form enquiries arrive through the EzBizi WordPress plugin '
          'on your site, into the leads.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }

  Widget _sitePanel(SourceItem site, {required bool inPlace}) =>
      LeadsWebsitePanel(
        key: ValueKey('site-panel-${site.id}'),
        api: widget.api,
        session: widget.session,
        site: site,
        inPlace: inPlace,
        onChanged: _loadSources,
        onClose: () => setState(() => _openSiteId = null),
      );

  Widget _panel(
    Map<String, dynamic> c,
    String provider, {
    required bool inPlace,
  }) => LeadsAccountPanel(
    key: ValueKey('account-panel-${c['id']}'),
    api: widget.api,
    session: widget.session,
    connection: c,
    canEdit: widget.canEdit,
    inPlace: inPlace,
    onChanged: widget.onChanged,
    onClose: () => setState(() => _openId = null),
  );
}

/// One account: its name, where its leads go, what Google holds against it,
/// and letting it go. Docked beside the list on a desk, in place of it on a
/// phone.
class LeadsAccountPanel extends StatefulWidget {
  const LeadsAccountPanel({
    super.key,
    required this.api,
    required this.session,
    required this.connection,
    required this.canEdit,
    required this.onChanged,
    required this.onClose,
    this.inPlace = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> connection;
  final bool canEdit;
  final Future<void> Function() onChanged;
  final VoidCallback onClose;

  /// On a phone: ← back instead of ✕.
  final bool inPlace;

  @override
  State<LeadsAccountPanel> createState() => _LeadsAccountPanelState();
}

class _LeadsAccountPanelState extends State<LeadsAccountPanel> {
  late final _name = TextEditingController(
    text: '${widget.connection['label'] ?? ''}',
  );
  bool _busy = false;
  bool _confirming = false;
  String? _error;

  /// What the last change actually did.
  String? _said;

  /// Campaign id to status, as the last pause or resume here left it: the
  /// connections list learns it only at the next hourly watch (:20).
  final _campaignNow = <String, String>{};

  /// The campaign whose pause is being confirmed. Pausing stops the account's
  /// leads, so it is asked once, in the panel.
  String? _pausing;
  String? _campaignSaid;
  String? _campaignError;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  String get _id => '${widget.connection['id']}';

  Future<void> _saveName() async {
    final typed = _name.text.trim();
    setState(() {
      _busy = true;
      _error = null;
      _said = null;
    });

    final response = await widget.api.patch(
      '/social/connections/$_id/label',
      // Blank clears rather than storing an empty string, so "unnamed" has one
      // representation and nothing downstream has to decide what '' means.
      {'label': typed.isEmpty ? null : typed},
    );
    if (!mounted) return;

    setState(() {
      _busy = false;
      _error = response.ok ? null : response.message;
    });
    if (response.ok) await widget.onChanged();
  }

  Future<void> _place(String? franchiseId) async {
    setState(() {
      _busy = true;
      _error = null;
      _said = null;
    });

    final response = await widget.api.patch(
      '/social/connections/$_id/leads-franchise',
      {'leads_franchise_id': franchiseId},
    );
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    // Three answers, and they mean different things.
    //
    //   a number — the leads that moved. Placement is retroactive, so this is
    //              the whole confirmation somebody looks for.
    //   0        — it took, and there was nothing to carry. Saying nothing
    //              here would read as a failure.
    //   null     — the same place re-submitted; nothing happened. Saying
    //              "0 moved" would report a non-event as an outcome.
    final moved = (response.body['data']?['leads_moved'] as num?)?.toInt();
    final where = franchiseId == null
        ? 'company level'
        : _franchiseName(franchiseId);

    setState(() {
      _busy = false;
      _said = moved == null
          ? null
          : moved == 0
          ? 'Moved to $where. No leads to move yet.'
          : '$moved ${moved == 1 ? 'lead' : 'leads'} moved to $where.';
    });
    await widget.onChanged();
  }

  Future<void> _disconnect() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    // The panel lists what stops and takes the authenticator code the
    // server requires for every disconnect.
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect this account?',
      explanation:
          'EzBizi stops reading leads from this account. To use it again, it '
          'has to be connected again.',
      dependentsPath: '/integrations/social/$_id/dependents',
      perform: (code) =>
          widget.api.deleteWithBody('/social/connections/$_id', {'code': code}),
    );
    if (!mounted) return;

    setState(() {
      _busy = false;
      _confirming = false;
    });
    if (done != null) await widget.onChanged();
  }

  /// Pause or resume one campaign, through the Ads report's own action (Jose,
  /// 2026-09-30: "we would only be adding it here as well on manage").
  Future<void> _setCampaign(String id, String status) async {
    setState(() {
      _busy = true;
      _pausing = null;
      _campaignSaid = null;
      _campaignError = null;
    });

    final response = await widget.api.post('/ads/campaigns/status', {
      'connection_id': _id,
      'campaign_ids': [id],
      'status': status,
    });
    if (!mounted) return;

    final result = (response.body['data'] as List? ?? const [])
        .cast<Map>()
        .where((r) => '${r['campaign_id']}' == id)
        .firstOrNull;
    setState(() {
      _busy = false;
      if (response.ok && result?['ok'] == true) {
        _campaignNow[id] = status;
        _campaignSaid = status == 'PAUSED'
            ? 'Paused. No new leads come from this account until it is '
                  'resumed.'
            : 'Running again.';
      } else {
        _campaignError = response.ok
            ? '${result?['because'] ?? 'Google did not take the change.'}'
            : response.reason;
      }
    });
  }

  String _franchiseName(String id) {
    for (final f
        in widget.session.me?.franchises ?? const <FranchiseSummary>[]) {
      if (f.id == id) return f.name;
    }
    return 'that place';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final c = widget.connection;
    final placement = placementOf(c);
    final leads = leadsOf(c);
    final cert = (c['certification'] as Map?)?.cast<String, dynamic>();
    final reason = switch ('${c['status_reason'] ?? ''}'.trim()) {
      '' => null,
      final said =>
        said.endsWith('.') ? said.substring(0, said.length - 1) : said,
    };
    // The number when the account is named, and the provider's id when it
    // says something the number does not: Google's id is the number again
    // without its dashes, Thumbtack's tells twelve "A1 Chimney"s apart.
    String digits(Object? s) => '${s ?? ''}'.replaceAll(RegExp(r'\D'), '');
    final display = '${c['display_name'] ?? ''}';
    final remote = '${c['remote_id'] ?? ''}';
    final ids = [
      if (display.isNotEmpty && display != accountName(c)) display,
      if (remote.isNotEmpty && digits(remote) != digits(display)) remote,
    ];

    Widget label(String text) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(
        text,
        style: theme.textTheme.labelMedium?.copyWith(color: muted),
      ),
    );

    return SidePanel(
      title: accountName(c),
      subtitle: ids.isEmpty ? null : ids.join(' · '),
      docked: true,
      onBack: widget.inPlace ? widget.onClose : null,
      onClose: widget.inPlace ? null : widget.onClose,
      actions: [
        if (widget.canEdit && _confirming) ...[
          TextButton(
            key: const ValueKey('account-keep'),
            onPressed: _busy ? null : () => setState(() => _confirming = false),
            child: const Text('Keep'),
          ),
          FilledButton(
            key: const ValueKey('account-disconnect-confirm'),
            style: FilledButton.styleFrom(
              backgroundColor: theme.colorScheme.error,
              foregroundColor: theme.colorScheme.onError,
            ),
            onPressed: _busy ? null : _disconnect,
            child: Text(_busy ? 'Disconnecting…' : 'Disconnect'),
          ),
        ] else if (widget.canEdit)
          OutlinedButton(
            key: const ValueKey('account-disconnect'),
            style: OutlinedButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
            ),
            onPressed: _busy ? null : () => setState(() => _confirming = true),
            child: const Text('Disconnect'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            leads == 0
                ? 'No leads yet'
                : '$leads ${leads == 1 ? 'lead' : 'leads'} · going to '
                      '${placement.text}',
            key: const ValueKey('account-leads'),
            style: theme.textTheme.titleSmall,
          ),

          // Reconnecting runs the sign-in over the same row, so its leads
          // stay attached; disconnecting first would hide them (Leads,
          // 2026-09-30).
          if (isBroken(c) || isNotFetching(c)) ...[
            const SizedBox(height: 14),
            _note(
              context,
              Tone.bad,
              '${isBroken(c) ? _stopped : _stale}'
              '${reason == null ? '.' : ': $reason.'} Connect the account '
              'again to fix it; no need to disconnect first.',
            ),
          ],

          const SizedBox(height: 20),
          label('Name'),
          if (widget.canEdit)
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextField(
                    key: const ValueKey('account-name'),
                    controller: _name,
                    enabled: !_busy,
                    onSubmitted: (_) => _saveName(),
                    decoration: const InputDecoration(
                      hintText: 'What this account is, in your words',
                      helperText: 'Empty goes back to the number.',
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextButton(
                    key: const ValueKey('account-name-save'),
                    onPressed: _busy ? null : _saveName,
                    child: const Text('Save'),
                  ),
                ),
              ],
            )
          else
            Text(accountName(c)),

          const SizedBox(height: 18),
          label('Leads go to'),
          if (widget.canEdit && placement.editable) ...[
            SearchPickerField<String?>(
              key: const ValueKey('account-place'),
              value: c['leads_franchise_id'] as String?,
              options: [
                const SearchOption(value: null, label: 'Company level'),
                for (final b in branchOptions(
                  widget.session.me?.switchableFranchises ?? const [],
                  widget.session.me?.companies ?? const [],
                ))
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: _busy ? null : _place,
            ),
            const SizedBox(height: 6),
            Text(
              leads == 0
                  ? 'Its leads will go wherever it is placed.'
                  : 'Moving it moves its $leads '
                        '${leads == 1 ? 'lead' : 'leads'} with it.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ] else
            Text(
              placement.text,
              style: TextStyle(
                fontStyle: placement.editable
                    ? FontStyle.normal
                    : FontStyle.italic,
              ),
            ),
          if (_said != null) ...[
            const SizedBox(height: 8),
            Text(
              _said!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.primary,
              ),
            ),
          ],

          if (cert != null) ...[
            const SizedBox(height: 18),
            label('Certification'),
            _certification(context, cert),
          ],

          // The account's Local Services campaign, as the hourly watch read
          // it, with the Ads report's pause and resume.
          if (c['provider'] == 'google_ads' && c.containsKey('campaigns')) ...[
            const SizedBox(height: 18),
            label('Campaign'),
            if (c['campaigns'] is! List)
              Text(
                'Not read from Google yet. The hourly check fills this in.',
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              )
            else
              for (final camp in (c['campaigns'] as List).cast<Map>())
                _campaign(context, camp, mayPause: c['may_pause'] == true),
            if (_campaignSaid != null) ...[
              const SizedBox(height: 8),
              Text(
                _campaignSaid!,
                key: const ValueKey('campaign-said'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                ),
              ),
            ],
            if (_campaignError != null) ...[
              const SizedBox(height: 8),
              ErrorNote(_campaignError!),
            ],
          ],

          if (_confirming) ...[
            const SizedBox(height: 18),
            Text(
              'This removes the stored permission. Its leads are hidden from '
              'Leads until you connect it again; nothing is deleted.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],

          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  static const _stopped =
      'This connection stopped working, so nothing is fetched from it';
  static const _stale = 'Nothing has been fetched from this account lately';

  /// What Google holds: the verdict, each problem on its own line so the fix
  /// is named rather than guessed at, and where it comes from.
  Widget _certification(BuildContext context, Map<String, dynamic> cert) {
    final theme = Theme.of(context);
    final attention = cert['state'] == 'attention';
    final problems = [
      for (final p in (cert['problems'] as List? ?? const [])) '$p',
    ];
    final colours = ToneColours.of(context, attention ? Tone.caution : Tone.ok);

    return Container(
      key: const ValueKey('lsa-certification'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            attention ? 'Certification needs attention' : 'Certified',
            style: theme.textTheme.titleSmall?.copyWith(color: colours.ink),
          ),
          for (final p in problems)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                '• $p',
                style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
              ),
            ),
          const SizedBox(height: 6),
          Text(
            cert['failed'] != null
                ? 'The last check could not reach Google: ${cert['failed']}'
                : 'Licence, insurance and background check, as Google holds '
                      'them. Checked daily.',
            style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
          ),
        ],
      ),
    );
  }

  Widget _campaign(BuildContext context, Map camp, {required bool mayPause}) {
    final theme = Theme.of(context);
    final id = '${camp['id']}';
    final status = _campaignNow[id] ?? '${camp['status']}';
    final running = status == 'ENABLED';
    // Google's own word when it says more than running or paused, and only
    // while it is not older than a change made here.
    final primary = '${camp['primary_status'] ?? ''}';
    final more =
        _campaignNow.containsKey(id) ||
            primary.isEmpty ||
            primary == 'ELIGIBLE' ||
            primary == 'PAUSED'
        ? null
        : primary[0] + primary.substring(1).toLowerCase().replaceAll('_', ' ');

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              StatusPill(
                running
                    ? 'Running'
                    : status == 'PAUSED'
                    ? 'Paused'
                    : status[0] + status.substring(1).toLowerCase(),
                key: ValueKey('campaign-state-$id'),
                tone: running
                    ? Tone.ok
                    : status == 'PAUSED'
                    ? Tone.caution
                    : Tone.neutral,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  '${camp['name'] ?? 'Campaign'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (mayPause && _pausing != id)
                running
                    ? OutlinedButton(
                        key: ValueKey('campaign-pause-$id'),
                        onPressed: _busy
                            ? null
                            : () => setState(() => _pausing = id),
                        child: const Text('Pause'),
                      )
                    : FilledButton.tonal(
                        key: ValueKey('campaign-resume-$id'),
                        onPressed: _busy
                            ? null
                            : () => _setCampaign(id, 'ENABLED'),
                        child: const Text('Resume'),
                      ),
            ],
          ),
          if (more != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Google: $more',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          if (_pausing == id) ...[
            const SizedBox(height: 8),
            _note(
              context,
              Tone.caution,
              "Pausing stops this account's Local Services ads: no new leads "
              'come from it until it is resumed.',
            ),
            const SizedBox(height: 8),
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              children: [
                TextButton(
                  key: const ValueKey('campaign-keep'),
                  onPressed: () => setState(() => _pausing = null),
                  child: const Text('Keep running'),
                ),
                FilledButton(
                  key: const ValueKey('campaign-pause-confirm'),
                  style: FilledButton.styleFrom(
                    backgroundColor: theme.colorScheme.error,
                    foregroundColor: theme.colorScheme.onError,
                  ),
                  onPressed: _busy ? null : () => _setCampaign(id, 'PAUSED'),
                  child: const Text('Pause'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _note(BuildContext context, Tone tone, String text) {
    final colours = ToneColours.of(context, tone);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Text(
        text,
        style: Theme.of(
          context,
        ).textTheme.bodySmall?.copyWith(color: colours.ink),
      ),
    );
  }
}
