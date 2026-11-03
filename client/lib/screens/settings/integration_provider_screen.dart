import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/open_destination.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/connect_account.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/disconnect_panel.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/workiz_session_login.dart';

/// One provider's connections, grouped by who owns them (Jose, 2026-09-22).
///
/// ── IN THE BODY, NEVER A ROUTE (Jose, 2026-09-23) ───────────────
///
/// *"Every Single Integration Card violates the containment rule by going full
/// screen when clicked."* It did: the grid pushed a `MaterialPageRoute` and
/// this class returned a `Scaffold` with an `AppBar`, so opening a provider
/// replaced the sidebar, the header and the top bar with a page carrying its
/// own back arrow.
///
/// The fix is the one `TrainingSettingsTab` already documents from the same
/// correction — *"it should all be within the main UI"*: the grid holds which
/// provider is open in its own state and SWAPS its body, so this widget is
/// rendered in place. It needs no `Scaffold` of its own: rendered in-body, the
/// app shell's `Material` further up the tree already reaches everything here,
/// and a route push is what used to force the artificial wrap.
///
/// So there is no `Navigator` anywhere in this file. Back is [onBack], and a
/// rename leaves the pane open rather than popping it.
///
/// ── Why this screen exists ──────────────────────────────────────
///
/// Integrations used to list every connection as a flat row under four family
/// headings. That was legible with six connections and unreadable with the
/// real thing: A1-Group holds fifty-three Google Ads accounts and a column of
/// identical Thumbtack rows, so the screen scrolled for pages and answered
/// nothing. His words: *"i'd like to have cards instead each with their own
/// screen for connections"*.
///
/// So the grid answers "what is this business connected to" and this screen
/// answers "which ones, and whose".
///
/// ── Grouped by SCOPE, which is the half the flat list destroyed ─
///
/// Every one of those fifty-three rows said "this company" — true, and useless,
/// because it never said WHICH. §3's cascade is the thing an administrator is
/// actually looking at when they open this: an account connected at the
/// organization serves everybody, one connected at a company serves that
/// company's branches, and one connected at a branch serves that branch alone.
///
/// The names come from `/me`'s `companiesInView` and `franchisesInView` rather
/// than from a new server field, and that is deliberate rather than
/// convenient: those two lists are the CURRENT VIEW (AGENTS.md — file on the
/// view, not on reach), so a connection belonging to a company this person is
/// not standing in cannot be named here. It falls back to the scope word rather
/// than inventing a name, which is the honest answer for a row that is in the
/// list because the server said it is in view.
class IntegrationProviderScreen extends StatefulWidget {
  const IntegrationProviderScreen({
    super.key,
    required this.api,
    required this.session,
    required this.provider,
    required this.providerLabel,
    required this.connections,
    this.connectWhere,
    this.connectModule,
    this.connectForms,
    this.canName = false,
    this.onChanged,
    this.connectsBy,
    this.startPath,
    this.mayConnect = false,
    this.onDisconnect,
    this.purpose,
    this.onEdit,
    this.mayMoveWorkizWeb = false,
    required this.onBack,
  });

  /// Whether a legacy (organization-scoped) Workiz web login offers Move to
  /// a company: the `workiz_web` connect permission. The server answers the
  /// move in the organization view only; elsewhere the row says so.
  final bool mayMoveWorkizWeb;

  final ApiClient api;
  final Session session;

  /// Catalogue `value` — `google_ads`, `thumbtack`, `airtable`…
  final String provider;
  final String providerLabel;

  /// This provider's connections, already filtered by the grid.
  final List<IntegrationConnection> connections;

  /// Where the account is connected, when it is connected somewhere else.
  ///
  /// The module that owns an account is where connecting it is understood, so
  /// this screen points rather than duplicating the panel. Null for the
  /// reporting sources, which connect here — see [connectForms].
  final String? connectWhere;

  /// The module [connectWhere] names, so the sentence can carry a button that
  /// opens it rather than a path written as text (UI review, 2026-10-08).
  final String? connectModule;

  /// The connect form, for the providers whose only home is Integrations.
  final List<Widget> Function()? connectForms;

  /// Whether a connection here can be given a name a person recognises.
  final bool canName;

  /// Reload the grid after a rename or a connect, so the card's count and this
  /// list cannot disagree about what exists.
  final Future<void> Function()? onChanged;

  /// How this provider is connected, from the catalogue — `sign_in`,
  /// `api_key`, `password`.
  final String? connectsBy;

  /// Where the OAuth flow starts, from the catalogue.
  final String? startPath;

  /// Whether this person may connect it at all.
  ///
  /// Absent rather than disabled when they may not: §4's rule is that a control
  /// which is offered must work, and a Connect button that answers 403 has told
  /// somebody the app is for other people. The sentence beneath names who can
  /// instead, which is the remedy they can act on.
  final bool mayConnect;

  /// Disconnect one row. Every provider since UI queue 110, for somebody who
  /// may connect it (the server's disconnect gate is the connect permission).
  /// Asked once more before it runs. Answers the refusal to show, or null.
  final Future<ApiResponse> Function(IntegrationConnection row, String code)?
  onDisconnect;

  /// What this service is for, in one line under its name (UI queue 110).
  final String? purpose;

  /// Replace a connection's credentials or how it connects (Workiz, UI queue
  /// 109). Null where a provider has nothing to edit here.
  final Future<void> Function(IntegrationConnection row)? onEdit;

  /// Close this pane and put the grid back.
  ///
  /// A callback rather than `Navigator.pop`, because this is NOT a route —
  /// see the class docblock. The grid swaps its own body; nothing was pushed
  /// and there is nothing to pop.
  final VoidCallback onBack;

  @override
  State<IntegrationProviderScreen> createState() =>
      _IntegrationProviderScreenState();
}

class _IntegrationProviderScreenState extends State<IntegrationProviderScreen> {
  String? _error;

  /// The organization, then each company, then each branch — in that order and
  /// only where something is connected.
  ///
  /// Empty groups are dropped rather than drawn empty. A company with no
  /// connection of this provider is not a fact worth a heading; the grid
  /// already said how many there are in total, and a page of "nothing here"
  /// rows is the shape this redesign exists to remove.
  List<(String, List<IntegrationConnection>)> get _groups {
    final me = widget.session.me;
    final rows = widget.connections;

    final organization = rows
        .where((c) => c.scopeType == 'organization')
        .toList();

    final groups = <(String, List<IntegrationConnection>)>[
      if (organization.isNotEmpty)
        (me?.organization.name ?? 'The organization', organization),
    ];

    for (final company in me?.companiesInView ?? const []) {
      final mine = rows
          .where((c) => c.scopeType == 'company' && c.scopeId == company.id)
          .toList();

      if (mine.isNotEmpty) groups.add((company.name, mine));
    }

    for (final franchise in me?.franchisesInView ?? const []) {
      final mine = rows
          .where((c) => c.scopeType == 'franchise' && c.scopeId == franchise.id)
          .toList();

      if (mine.isNotEmpty) groups.add((franchise.name, mine));
    }

    /*
     * ANYTHING THE TWO LISTS COULD NOT NAME, rather than dropped.
     *
     * The server decided this row is in view; `/me` decides what this person's
     * switcher can name. Those agree today and a row that fell between them
     * would VANISH from a screen whose whole purpose is inventory — the one
     * failure worse than an unnamed heading. So it is drawn under the scope
     * word instead, and the sum of the groups is always the count the card
     * showed.
     */
    final named = {for (final group in groups) ...group.$2.map((c) => c.id)};
    final rest = rows.where((c) => !named.contains(c.id)).toList();

    if (rest.isNotEmpty) {
      groups.add(('Other places in this view', rest));
    }

    return groups;
  }

  Future<void> _rename(IntegrationConnection row) async {
    final controller = TextEditingController(text: row.label ?? '');

    // A side panel, as every other edit in Settings (UI queue 99).
    final name = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: 'Name this ${widget.providerLabel} connection',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text),
            child: const Text('Save'),
          ),
        ],
        child: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'Name',
            // The id, so somebody naming several in a row can tell them apart
            // while the dialog is covering the list.
            helperText: row.displayName,
          ),
          onSubmitted: (value) => Navigator.of(context).pop(value),
        ),
      ),
    );

    controller.dispose();

    // Null is Cancel. An EMPTY string is not — it is "take the name off",
    // which the endpoint accepts and stores as null.
    if (name == null || !mounted) return;

    final response = await widget.api.patch(
      '/social/connections/${row.id}/label',
      {'label': name.trim()},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    setState(() => _error = null);

    // Reload the grid so its count matches; the pane STAYS OPEN. It used to
    // pop, which was the route closing itself — without a route there is
    // nothing to close, and somebody naming three bases in a row should not be
    // thrown back to the grid after each one.
    await widget.onChanged?.call();
  }

  /// Asks once more, in a side panel, then disconnects (UI queue 110). The
  /// panel lists what stops working and asks for an authenticator code —
  /// the server refuses a disconnect without one.
  Future<void> _disconnect(IntegrationConnection row) async {
    final dependents = row.provider == 'google_workspace'
        ? '/integrations/workspace/${row.scopeType}:${row.scopeId}/dependents'
        : '/integrations/social/${row.id}/dependents';
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect ${row.title}?',
      explanation:
          'EzBizi stops using this ${widget.providerLabel} connection. To use '
          'it again, it has to be connected again.',
      dependentsPath: dependents,
      perform: (code) => widget.onDisconnect!(row, code),
    );
    if (done == null || !mounted) return;
    final problems =
        ((done.body['data'] as Map?)?['problems'] as List<dynamic>?) ??
        const [];
    setState(() => _error = problems.isEmpty ? null : problems.join(' '));
  }

  /// A Workiz web login is one per company, so its row names the company
  /// ("Workiz web login · Harbor Home Services").
  String _titleOf(IntegrationConnection row) {
    if (row.provider != 'workiz_web' || row.scopeType != 'company') {
      return row.title;
    }
    final company = widget.session.me?.companies
        .where((c) => c.id == row.scopeId)
        .firstOrNull;

    return company == null ? row.title : '${row.title} · ${company.name}';
  }

  /// An organization-scoped Workiz web login was sent before logins were per
  /// company (Workiz, 2026-10-21); it keeps Disconnect.
  static bool _legacyWorkizWeb(IntegrationConnection row) =>
      row.provider == 'workiz_web' && row.scopeType == 'organization';

  /// A row's detail line; a legacy Workiz web login adds Move to a company
  /// under it (PUT, session kept), or in another view, where to do it.
  Widget _subtitle(IntegrationConnection row, Widget detail) {
    if (!_legacyWorkizWeb(row) || !widget.mayMoveWorkizWeb) return detail;
    final me = widget.session.me;
    final organization = me?.scope.type == 'organization';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        detail,
        if (organization)
          WorkizWebMove(
            api: widget.api,
            connectionId: row.id,
            companies: me?.companiesInView ?? const [],
            onMoved: widget.onChanged,
          )
        else
          Text(
            'Switch to the organization view to move it to a company.',
            key: ValueKey('workiz-move-hint:${row.id}'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
      ],
    );
  }

  static String _ago(DateTime at) {
    final d = DateTime.now().difference(at.toLocal());
    if (d.inMinutes < 1) return 'just now';
    if (d.inHours < 1) return '${d.inMinutes} min ago';
    if (d.inDays < 1) return '${d.inHours} h ago';
    return '${d.inDays} d ago';
  }

  /// "Open Marketing" beside a sentence naming it, or null when there is
  /// nowhere to open (outside the shell, or a provider that connects here).
  Widget? _openModule(BuildContext context) {
    final open = OpenDestination.of(context);
    final module = widget.connectModule;
    if (open == null || module == null) return null;

    return TextButton(
      key: ValueKey('open:$module'),
      onPressed: () => open(module),
      child: Text('Open ${widget.connectWhere!.split(' › ').first}'),
    );
  }

  /// One connection, the same under a place heading or a login's card.
  Widget _row(
    ThemeData theme,
    IntegrationConnection row,
    bool narrow, {
    String? place,
  }) => ListTile(
    key: ValueKey('connection:${row.id}'),
    contentPadding: EdgeInsets.zero,
    dense: true,
    leading: SocialNetworkMark(network: row.provider, size: 24),
    title: Text(_titleOf(row), maxLines: 1, overflow: TextOverflow.ellipsis),
    // `appadTMQltMGmGrse` tells nobody anything, so a named
    // connection leads with its name and still shows the id
    // underneath — that is what identifies it to the provider
    // when something is wrong.
    subtitle: _subtitle(
      row,
      Text(
        [
          // Under a login's card, where it is (design 167).
          ?place,
          if (_legacyWorkizWeb(row)) WorkizSessionLogin.legacyNote,
          if (row.beneath != null) row.beneath!,
          if ((row.detail ?? '').isNotEmpty) row.detail!,
          // When it last synced (UI queue 109).
          if (row.lastSyncedAt != null) 'synced ${_ago(row.lastSyncedAt!)}',
          if (!row.connected) row.statusReason ?? 'Not working',
        ].join(' · '),
        key: ValueKey('connection-detail:${row.id}'),
        maxLines: _legacyWorkizWeb(row) ? 4 : 2,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.bodySmall?.copyWith(
          color: row.connected
              ? theme.colorScheme.onSurfaceVariant
              : theme.colorScheme.error,
        ),
      ),
    ),
    trailing:
        widget.canName || widget.onDisconnect != null || widget.onEdit != null
        ? Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              // The Workiz web login is sent again, not
              // edited (UI queue 120).
              if (widget.onEdit != null && row.provider != 'workiz_web')
                TextButton(
                  key: ValueKey('edit:${row.id}'),
                  onPressed: () => widget.onEdit!(row),
                  child: const Text('Edit'),
                ),
              if (widget.canName)
                IconButton(
                  tooltip: 'Name this connection',
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  onPressed: () => _rename(row),
                ),
              if (widget.onDisconnect != null && narrow)
                IconButton(
                  key: ValueKey('disconnect:${row.id}'),
                  tooltip: 'Disconnect',
                  color: theme.colorScheme.error,
                  icon: const Icon(Icons.link_off, size: 20),
                  onPressed: () => _disconnect(row),
                )
              else if (widget.onDisconnect != null)
                TextButton(
                  key: ValueKey('disconnect:${row.id}'),
                  style: TextButton.styleFrom(
                    foregroundColor: theme.colorScheme.error,
                  ),
                  onPressed: () => _disconnect(row),
                  child: const Text('Disconnect'),
                ),
            ],
          )
        : null,
  );

  /// The Google providers, grouped by the login they were signed in with
  /// rather than by place (design 167, Jose 2026-11-02): one login can hold
  /// dozens of rows, and the login is what reconnecting needs.
  static const _byLogin = {'google_business_profile', 'google_ads', 'youtube'};

  /// One card per login, the unknown login last.
  List<Widget> _logins(ThemeData theme, bool narrow) {
    final byEmail = <String?, List<IntegrationConnection>>{};
    for (final row in widget.connections) {
      byEmail.putIfAbsent(row.accountEmail, () => []).add(row);
    }
    final emails = byEmail.keys.whereType<String>().toList()..sort();
    final places = {
      for (final (heading, rows) in _groups)
        for (final row in rows) row.id: heading,
    };

    return [
      for (final email in [...emails, if (byEmail.containsKey(null)) null])
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _LoginCard(
            key: ValueKey('login:${email ?? 'unknown'}'),
            email: email,
            rows: byEmail[email]!,
            row: (r) => _row(theme, r, narrow, place: places[r.id]),
            reconnect:
                widget.mayConnect &&
                    widget.connectsBy == 'sign_in' &&
                    widget.startPath != null
                ? ConnectAccount(
                    key: ValueKey('reconnect:${email ?? 'unknown'}'),
                    api: widget.api,
                    label: widget.providerLabel,
                    action: 'Reconnect',
                    startPath: widget.startPath!,
                    onConnected: () async => widget.onChanged?.call(),
                  )
                : null,
            onDisconnectAll: widget.onDisconnect == null
                ? null
                : () => _disconnectLogin(email, byEmail[email]!.length),
          ),
        ),
    ];
  }

  /// Every connection of this provider made by one login, with one code.
  /// The server revokes the shared grant once, on the last row.
  Future<void> _disconnectLogin(String? email, int count) async {
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect all $count?',
      subtitle: email ?? 'Unknown login',
      explanation:
          'EzBizi stops using every ${widget.providerLabel} connection made by '
          '${email ?? 'this unknown login'}. To use them again, sign in again.',
      perform: (code) => widget.api.deleteWithBody(
        '/social/providers/${widget.provider}/connections',
        {'code': code, 'account_email': email},
      ),
    );
    if (done == null || !mounted) return;
    final problems =
        ((done.body['data'] as Map?)?['problems'] as List<dynamic>?) ??
        const [];
    setState(() => _error = problems.isEmpty ? null : problems.join(' '));
    await widget.onChanged?.call();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final groups = _groups;

    // Narrow, Disconnect is an icon so the row keeps its name (UI queue 110).
    return LayoutBuilder(
      builder: (context, box) =>
          _body(context, theme, groups, box.maxWidth < 480),
    );
  }

  Widget _body(
    BuildContext context,
    ThemeData theme,
    List<(String, List<IntegrationConnection>)> groups,
    bool narrow,
  ) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        /*
           * THE HEADER IS IN THE BODY, because this pane is in the body.
           *
           * An `AppBar` belongs to a `Scaffold` and a `Scaffold` belongs to a
           * route; drawing either here is what put this pane over the whole
           * window. The back control is a row like any other.
           */
        Row(
          children: [
            IconButton(
              key: const ValueKey('integration-back'),
              icon: const Icon(Icons.arrow_back),
              tooltip: 'Back to integrations',
              onPressed: widget.onBack,
            ),
            const SizedBox(width: 4),
            SocialNetworkMark(network: widget.provider, size: 22),
            const SizedBox(width: 10),
            Flexible(
              child: Text(
                widget.providerLabel,
                style: theme.textTheme.titleMedium,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        if ((widget.purpose ?? '').isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(left: 52, top: 2),
            child: Text(
              widget.purpose!,
              key: const ValueKey('integration-purpose'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        const SizedBox(height: 12),
        if (_error != null) ...[
          DashboardNote(_error!),
          const SizedBox(height: 12),
        ],

        if (groups.isEmpty)
          DashboardCard(
            title: widget.providerLabel,
            icon: Icons.link_off_outlined,
            // Only that: the Connect card below says how, once (UI queue 110).
            child: const DashboardNote('Nothing connected here yet.'),
          ),

        // By login once any login is known; with none, a single "Unknown
        // login" card would only hide the places (design 167).
        if (_byLogin.contains(widget.provider) &&
            widget.connections.any((c) => c.accountEmail != null))
          ..._logins(theme, narrow)
        else
          for (final (heading, rows) in groups) ...[
            DashboardCard(
              title: heading,
              icon: Icons.account_tree_outlined,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [for (final row in rows) _row(theme, row, narrow)],
              ),
            ),
            const SizedBox(height: 12),
          ],

        /*
           * CONNECTING HAPPENS HERE (Jose, 2026-09-23).
           *
           * "The point of the Integration tab by itself is to be able to
           * connect stuff from here, not just list it." This used to be a
           * sentence pointing at the module that owns the account for three of
           * the four families, which made the screen an inventory somebody had
           * to leave in order to act on.
           *
           * The pointer survives as a FOOTNOTE rather than as the only route:
           * the module's own screen is still where connecting is explained,
           * and somebody who wants the explanation should be able to find it.
           */
        DashboardCard(
          title: groups.isEmpty ? 'Connect' : 'Connect another',
          icon: Icons.add_link,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              /*
                 * THE FORM IS ITS OWN GATE, and is asked FIRST.
                 *
                 * `_formsFor` in the grid already decides who may see each
                 * reporting form, and it encodes something the catalogue
                 * cannot: Workiz needs `reports.build` AND
                 * `reports.experimental.view`, because the pane it connects for
                 * is behind the tab. Asking `mayConnect` as well would be a
                 * second gate over one control, and the two disagree by
                 * construction — measured, it hid a form the first gate had
                 * already allowed.
                 */
              if (widget.connectForms != null)
                ...widget.connectForms!()
              else if (!widget.mayConnect)
                DashboardNote(
                  'Somebody who can manage ${widget.connectWhere ?? 'this'} '
                  'needs to connect a ${widget.providerLabel} account.',
                )
              else if (widget.connectsBy == 'sign_in' &&
                  widget.startPath != null)
                ConnectAccount(
                  key: ValueKey('connect:${widget.provider}'),
                  api: widget.api,
                  label: widget.providerLabel,
                  startPath: widget.startPath!,
                  onConnected: () async => widget.onChanged?.call(),
                )
              else
                /*
                   * NO CONTROL, AND THE REASON RATHER THAN A DEAD BUTTON.
                   *
                   * Mailchimp connects by api_key and has no endpoint to post
                   * one to — measured, `routes/api.php` has `social/airtable`,
                   * `social/workiz`, `social/bluesky/connect` and the OAuth
                   * `social/{network}/start`, and nothing else. Drawing a form
                   * here would be a control with nowhere to send what somebody
                   * typed.
                   */
                DashboardNote(
                  widget.connectWhere == null
                      ? 'This one cannot be connected from here yet.'
                      : 'Connect a ${widget.providerLabel} account in '
                            '${widget.connectWhere}.',
                ),
              if (!widget.mayConnect ||
                  widget.connectsBy != 'sign_in' ||
                  widget.startPath == null)
                ?_openModule(context),
            ],
          ),
        ),
      ],
    );
  }
}

/// One outside account this view is connected to.
///
/// Public, and shared by the grid and this screen deliberately: the card's
/// count and the list behind it are two readings of one set, and two models
/// would be two chances for them to disagree about what "connected" means.
class IntegrationConnection {
  const IntegrationConnection({
    required this.id,
    required this.provider,
    required this.providerLabel,
    required this.displayName,
    required this.scopeType,
    required this.scopeId,
    required this.connected,
    this.label,
    this.statusReason,
    this.detail,
    this.method,
    this.lastSyncedAt,
    this.accountEmail,
  });

  /// The Google login it was signed in with; null when not known (design 167).
  final String? accountEmail;

  final String id;
  final String provider;
  final String providerLabel;
  final String displayName;

  /// What this business calls it. Null until somebody names it.
  ///
  /// Kept beside `displayName` rather than replacing it: an Airtable base's
  /// display name is its BASE ID, so a named connection still has to be able
  /// to show which base it is.
  final String? label;
  final String scopeType;

  /// Which organization, company or franchise owns it.
  ///
  /// The server has always sent this; nothing read it until the flat list was
  /// replaced, because a row saying "this company" had nowhere to put a name.
  final String scopeId;

  final bool connected;
  final String? statusReason;

  /// One provider-specific line, such as which Workiz API the key speaks.
  final String? detail;

  /// Workiz: which API it speaks and when it last synced (UI queue 109).
  final String? method;
  final DateTime? lastSyncedAt;

  /// Which screen owns this account. The grid draws a heading per family,
  /// because "where do I go to fix this" is the question underneath.
  String get family => switch (provider) {
    'mailchimp' => 'mailing',
    'google_ads' ||
    'google_business_profile' ||
    'thumbtack' ||
    'facebook_ads' => 'leads',
    'airtable' || 'workiz' || 'workiz_web' || 'ringcentral' => 'reporting',
    'google_workspace' || 'sending_server' => 'email',
    'phone_numbers' => 'phone',
    'whatsapp' || 'telegram' => 'messaging',
    _ => 'social',
  };

  String get scopeWord => switch (scopeType) {
    'organization' => 'the whole organization',
    'company' => 'this company',
    'franchise' => 'this branch',
    _ => scopeType,
  };

  /// What to put in front of a person, and what to put under it.
  String get title => (label ?? '').isNotEmpty ? label! : displayName;
  String? get beneath => (label ?? '').isNotEmpty ? displayName : null;

  /// One row of `GET /email/workspace` — a domain, not a SocialConnection.
  /// The domain leads; the administrator who proved it sits beneath.
  factory IntegrationConnection.fromWorkspaceJson(Map<String, dynamic> json) {
    final health = json['health'] as Map<String, dynamic>?;
    final healthy =
        (json['status'] as String? ?? '') == 'connected' &&
        health?['status'] != 'needs_attention';
    final admin = json['admin_email'] as String? ?? '';

    return IntegrationConnection(
      id:
          'google_workspace:${json['scope_type'] ?? ''}:'
          '${json['scope_id'] ?? ''}',
      provider: 'google_workspace',
      providerLabel: 'Google Workspace',
      label: json['domain'] as String? ?? '',
      displayName: admin.isEmpty ? 'Google Workspace' : 'Admin $admin',
      scopeType: json['scope_type'] as String? ?? '',
      scopeId: json['scope_id'] as String? ?? '',
      connected: healthy,
      statusReason:
          health?['summary'] as String? ??
          json['status_reason'] as String? ??
          'Finish the setup in Email › Settings',
    );
  }

  factory IntegrationConnection.fromJson(Map<String, dynamic> json) =>
      IntegrationConnection(
        id: json['id'] as String? ?? '',
        label: json['label'] as String?,
        provider: json['provider'] as String? ?? '',
        providerLabel: json['provider_label'] as String? ?? '',
        displayName: json['display_name'] as String? ?? '',
        scopeType: json['scope_type'] as String? ?? '',
        scopeId: json['scope_id'] as String? ?? '',
        connected: (json['status'] as String? ?? '') == 'connected',
        statusReason: json['status_reason'] as String?,
        detail: json['detail'] as String?,
        method: (json['workiz'] as Map?)?['method'] as String?,
        lastSyncedAt: DateTime.tryParse(
          '${(json['workiz'] as Map?)?['last_synced_at'] ?? ''}',
        ),
        accountEmail: json['account_email'] as String?,
      );
}

/// One Google login's connections (design 167): the login, how many, how many
/// work and how many need reconnecting, then its rows behind a search, with
/// Reconnect and Disconnect all. Starts open only when something is broken.
class _LoginCard extends StatefulWidget {
  const _LoginCard({
    super.key,
    required this.email,
    required this.rows,
    required this.row,
    this.reconnect,
    this.onDisconnectAll,
  });

  /// Null is the rows whose login is not known.
  final String? email;
  final List<IntegrationConnection> rows;
  final Widget Function(IntegrationConnection) row;
  final Widget? reconnect;
  final VoidCallback? onDisconnectAll;

  @override
  State<_LoginCard> createState() => _LoginCardState();
}

class _LoginCardState extends State<_LoginCard> {
  late bool _open = widget.rows.any((r) => !r.connected);
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ok = widget.rows.where((r) => r.connected).length;
    final bad = widget.rows.length - ok;
    final q = _search.text.trim().toLowerCase();
    final shown = q.isEmpty
        ? widget.rows
        : widget.rows
              .where(
                (r) => '${r.title} ${r.displayName}'.toLowerCase().contains(q),
              )
              .toList();
    final key = widget.email ?? 'unknown';

    return DashboardCard(
      title: widget.email ?? 'Unknown login · reconnect to see',
      icon: Icons.account_circle_outlined,
      controls: IconButton(
        key: ValueKey('login-toggle:$key'),
        tooltip: _open ? 'Collapse' : 'Expand',
        icon: Icon(_open ? Icons.expand_less : Icons.expand_more),
        onPressed: () => setState(() => _open = !_open),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${widget.rows.length} '
                '${widget.rows.length == 1 ? 'connection' : 'connections'}',
                style: theme.textTheme.bodySmall,
              ),
              if (ok > 0) StatusPill('$ok OK', tone: Tone.ok),
              if (bad > 0) StatusPill('$bad need reconnect', tone: Tone.bad),
            ],
          ),
          if (_open) ...[
            const SizedBox(height: 8),
            ListSearch(
              key: ValueKey('login-search:$key'),
              controller: _search,
              hint: 'Search these connections',
              onChanged: (_) => setState(() {}),
            ),
            for (final r in shown) widget.row(r),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                ?widget.reconnect,
                if (widget.onDisconnectAll != null)
                  TextButton(
                    key: ValueKey('disconnect-login:$key'),
                    style: TextButton.styleFrom(
                      foregroundColor: theme.colorScheme.error,
                    ),
                    onPressed: widget.onDisconnectAll,
                    child: const Text('Disconnect all'),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
