import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme.dart';
import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/disconnect_panel.dart';
import '../../widgets/async_body.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../core/branch_options.dart' show branchLabelOf;
import '../../widgets/list_table.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tag.dart';
import 'consent_window.dart';
import 'inbox_people.dart';
import 'mailbox_panels.dart';
import 'mailbox_settings.dart';

/// Email › Settings — where an inbox is set up, and where you find out why one
/// stopped working.
///
/// ── The owner's ask, and the two halves of it ───────────────────
///
/// *"it should definitely display the message of revoked or expired, perhaps
/// an interface within the email module that shows the status of each connected
/// email box."*
///
/// The visible half is the STATUS of each connection: which address, which part
/// of the business owns it, which service it lives on, whether it works, and
/// when it last did. The larger half is that nothing in this client called the
/// connect endpoints at all — they existed, they were tested, and no screen
/// reached them. So this tab is also the only place an address is added, handed
/// its key, and taken away again.
///
/// ── State is a word and a sentence, never a colour ──────────────
///
/// A dot tells somebody who can see it that something is wrong and nothing
/// about what to do; it tells somebody who cannot see it nothing at all. Every
/// row carries the word — "Connected", "Waiting to be connected", "Not working"
/// — and, under it, the server's own sentence. That sentence is what tells a
/// withdrawn consent apart from an expired password, which is the fact the
/// owner asked for and the one this screen exists to print.
///
/// ── Why a stack of cards and not a table ────────────────────────
///
/// R7. A status table with six columns is exactly the shape that dies on a
/// phone: the columns become unreadable slivers, or the row scrolls sideways
/// and the state — the one column somebody came for — is off the edge. A card
/// per inbox reads the same at 375, 700 and 1400; only the two facts inside it
/// go from side by side to stacked.
class MailboxSettingsTab extends StatefulWidget {
  const MailboxSettingsTab({
    super.key,
    required this.api,
    required this.session,
    this.openConsent = openConsentWindow,
  });

  final ApiClient api;
  final Session session;

  /// How a window is opened at the mail service, handed down to the connect
  /// panel.
  ///
  /// A seam rather than a hack: the real implementation is chosen by
  /// consent_window.dart's conditional import, and the reason it is a parameter
  /// at all is that the sign-in road cannot otherwise be exercised anywhere
  /// except a browser — `flutter test` runs on the VM, where the stub correctly
  /// answers "no window here". Without this the one flow in this tab that a
  /// person cannot recover from unaided would be the one flow nothing asserts.
  final OpenConsentWindow openConsent;

  @override
  State<MailboxSettingsTab> createState() => _MailboxSettingsTabState();
}

class _MailboxSettingsTabState extends State<MailboxSettingsTab> {
  bool _loading = true;
  String? _error;
  List<MailboxConnection> _mailboxes = const [];
  List<ProviderOption> _providers = const [];
  List<int> _imapPorts = const [];
  List<int> _smtpPorts = const [];
  WorkspaceSettings? _workspace;
  bool _workspaceReachable = false;

  /// The "New email" automation, as the server read it: which addresses it
  /// covers, whether this screen may edit it, and why not. Null when it could
  /// not be read -- the switch is then not drawn rather than drawn wrong.
  Map<String, dynamic>? _alerts;

  /// The server's last word about something that just happened — what
  /// disconnecting took away, in its own sentence.
  ///
  /// Kept on screen rather than flashed in a snack bar. The disconnect message
  /// is a paragraph about access four people have just lost, and a bar that
  /// slides away after three seconds is where a paragraph goes to be unread.
  String? _notice;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      // A spinner only when there is nothing to keep — AsyncBody's rule. A
      // refetch after connecting keeps the list on screen and swaps it.
      _loading = _mailboxes.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/email/mailboxes');
    final workspace = await widget.api.get('/email/workspace');
    final alerts = await widget.api.get('/email/mailboxes/new-mail-alerts');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;

        return;
      }

      _mailboxes = MailboxConnection.listFrom(response.data);
      _alerts = alerts.ok && alerts.data is Map<String, dynamic>
          ? alerts.data as Map<String, dynamic>
          : null;

      final meta = response.body['meta'];

      _providers = ProviderOption.listFrom(meta);
      _imapPorts = _ports(meta, 'imap_ports');
      _smtpPorts = _ports(meta, 'smtp_ports');

      _workspaceReachable = workspace.ok;
      _workspace = workspace.ok && workspace.data is Map<String, dynamic>
          ? WorkspaceSettings.fromJson(workspace.data as Map<String, dynamic>)
          : null;
    });
  }

  static List<int> _ports(Object? meta, String key) => [
    for (final port in ((meta is Map<String, dynamic> ? meta[key] : null)
            as List? ??
        const []))
      if (port is int) port,
  ];

  // ── what this person may do ──────────────────────────────────

  Me get _me => widget.session.me!;

  /// Why adding is refused, or null when it is not.
  ///
  /// `email.create` is what MailboxesController::store asks for, and
  /// [Me.refusal] writes the same sentence Refusal::permission does — the same
  /// words in the tooltip as in the 403 behind it, rather than two phrasings of
  /// one fact that read as two different problems.
  String? get _addRefusal => _me.refusal('email.create', 'add a shared inbox');

  /// Why managing THIS inbox is refused, or null when it is not.
  ///
  /// The server's own `may_manage` is the answer, and this only supplies the
  /// words. `email.edit` is the permission behind connecting, changing and
  /// disconnecting alike — MailboxAccess::MANAGE — so a row the server will not
  /// let this person manage is one whose role does not hold it.
  ///
  /// The last branch is unreachable through this API and is written all the
  /// same. A listed mailbox is one this person either reads or manages, and
  /// both sets are already filtered by whether their assignment covers the
  /// level it hangs off — so a row that arrives with `may_manage` false has
  /// failed on the permission and nothing else. If that ever stops being true,
  /// this says so instead of inventing a cause.
  String? _manageRefusal(MailboxConnection mailbox, String act) {
    if (mailbox.mayManage) return null;

    return _me.refusal('email.edit', act) ??
        'EzBizi will not let you $act and did not say which of the two reasons '
            'applies — the permissions on your role, or the part of the '
            'business you are assigned to. Whoever looks after roles here can '
            'tell you.';
  }

  // ── the three acts ───────────────────────────────────────────

  Future<void> _add() async {
    final added = await showSidePanel<bool>(
      context,
      builder: (_) => AddMailboxPanel(
        api: widget.api,
        session: widget.session,
        providers: _providers,
        imapPorts: _imapPorts,
        smtpPorts: _smtpPorts,
      ),
    );

    if (added != true || !mounted) return;

    setState(() => _notice = null);

    await _load();
  }

  Future<void> _connect(MailboxConnection mailbox) async {
    final connected = await showSidePanel<bool>(
      context,
      builder: (_) => ConnectMailboxPanel(
        api: widget.api,
        mailbox: mailbox,
        provider: _providerFor(mailbox),
        // The same lists the add form offers, because this panel now asks the
        // same question. Connecting is where RemoteHostGuard actually runs, so
        // it is where a refused host has to be correctable — and a port list
        // that differed between the two forms would be a refusal from the far
        // end rather than anything visible on either screen.
        imapPorts: _imapPorts,
        smtpPorts: _smtpPorts,
        openConsent: widget.openConsent,
      ),
    );

    if (connected != true || !mounted) return;

    setState(() => _notice = null);

    await _load();
  }

  /// Turn "also mark it read in the mail service" on or off for one inbox.
  ///
  /// Per mailbox, because shared-versus-personal is a property of the mailbox
  /// and not of the company: one organization holds `info@`, which three
  /// people share, and `jose@`, which one person reads.
  Future<void> _setMarkReadAtSource(MailboxConnection mailbox, bool on) async {
    final response = await widget.api.patch(
      '/email/mailboxes/${mailbox.id}',
      {'mark_read_at_source': on},
    );

    if (!mounted) return;

    if (!response.ok) {
      // The server's sentence — it is the one that knows WHY a provider
      // cannot do this, and the answer differs between Outlook and IMAP.
      setState(() => _notice = response.reason);
      return;
    }

    await _load();
  }

  /// Switches the new-email notification for one inbox. The automation rule is
  /// the one record: this edits it, and Automations shows the same edit.
  Future<void> _setNewMailAlert(MailboxConnection mailbox, bool on) async {
    final response = await widget.api.put(
      '/email/mailboxes/${mailbox.id}/new-mail-alert',
      {'on': on},
    );

    if (!mounted) return;

    if (!response.ok) {
      // The rule's own sentence: reshaped in Automations, two of them, or no
      // new-lead notification to copy who is told from.
      setState(() => _notice = response.reason);
      return;
    }

    setState(() => _alerts = response.data as Map<String, dynamic>);
  }

  Future<void> _disconnect(MailboxConnection mailbox) async {
    final message = await showSidePanel<String>(
      context,
      builder: (_) => DisconnectMailboxPanel(
        api: widget.api,
        mailbox: mailbox,
        scope: scopeLabel(_me, mailbox),
      ),
    );

    if (message == null || !mounted) return;

    setState(() => _notice = message);

    await _load();
  }

  Future<void> _connectWorkspace() async {
    final connected = await showSidePanel<bool>(
      context,
      builder: (_) => ConnectWorkspacePanel(
        api: widget.api,
        openConsent: widget.openConsent,
      ),
    );

    if (connected != true || !mounted) return;

    setState(() => _notice = null);
    await _load();
  }

  Future<void> _disconnectWorkspace(WorkspaceLink link) async {
    // The panel lists what stops and takes the authenticator code the
    // server requires for every disconnect.
    final response = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect Google Workspace?',
      explanation:
          'EzBizi stops opening the shared inboxes picked from this '
          'directory until the Workspace is connected again.',
      dependentsPath:
          '/integrations/workspace/${link.scopeType}:${link.scopeId}/dependents',
      perform: (code) => widget.api.deleteWithBody('/email/workspace', {
        'scope_type': link.scopeType,
        'scope_id': link.scopeId,
        'code': code,
      }),
    );

    if (!mounted || response == null) return;

    setState(() => _notice = response.message);
    await _load();
  }

  /// How this address is set up, as the server described the service. Null when
  /// the server named a provider this build's `meta` block does not describe —
  /// a newer server than this bundle, which the connect panel says out loud
  /// rather than offering a form it cannot fill in.
  ProviderOption? _providerFor(MailboxConnection mailbox) => _providers
      .where((option) => option.value == mailbox.provider)
      .firstOrNull;

  // ── the screen (design 151, options 1 and 4) ─────────────────

  /// Below this the inbox pane replaces the list instead of sitting beside it.
  static const double _paneBeside = 1000;

  @override
  Widget build(BuildContext context) {
    final refusal = _addRefusal;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          note:
              'An inbox belongs to the company you are looking at when you add '
              'it; each role is then given the ones it reads. Open an inbox to '
              'hand it its password, choose who reads it, or disconnect it.',
          action: Refused(
            reason: refusal,
            child: FilledButton.icon(
              key: const ValueKey('tour-email-add-inbox'),
              onPressed: refusal == null ? _add : null,
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add an inbox'),
            ),
          ),
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: _Notice(_notice!, onDismiss: () => setState(() => _notice = null)),
          ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: LayoutBuilder(
              builder: (context, box) {
                final beside = box.maxWidth >= _paneBeside;
                final selected = _selectedMailbox(beside);

                if (selected == null) return _scrollBody();
                if (!beside) return _inboxPane(selected, beside: false);

                return Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _scrollBody()),
                    SizedBox(width: 440, child: _inboxPane(selected, beside: true)),
                  ],
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  // ── selection, search, filter ────────────────────────────────

  String? _selectedId;

  /// True once somebody closed the pane, so a lone inbox is not reopened.
  bool _closedPane = false;
  final _search = TextEditingController();
  String? _companyFilter;
  final _scroll = ScrollController();
  final Map<String, bool> _expanded = {};
  final Map<String, GlobalKey> _rowKeys = {};

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  /// The open inbox. With exactly one inbox and room beside the list, it is
  /// open from the start — there is nothing to choose between.
  MailboxConnection? _selectedMailbox(bool beside) {
    final id =
        _selectedId ??
        (beside && !_closedPane && _mailboxes.length == 1
            ? _mailboxes.single.id
            : null);

    return _mailboxes.where((m) => m.id == id).firstOrNull;
  }

  void _select(MailboxConnection mailbox) => setState(() {
    _selectedId = mailbox.id;
    _closedPane = false;
  });

  void _closePane() => setState(() {
    _selectedId = null;
    _closedPane = true;
  });

  /// Which company a row is filtered under: a branch files under its company.
  String _companyKey(MailboxConnection mailbox) {
    switch (mailbox.scopeType) {
      case 'franchise':
        final franchise = _me.franchises
            .where((f) => f.id == mailbox.scopeId)
            .firstOrNull;

        return 'company:${franchise?.companyId ?? mailbox.scopeId}';
      case 'company':
        return 'company:${mailbox.scopeId}';
      default:
        return '${mailbox.scopeType}:${mailbox.scopeId}';
    }
  }

  String _companyKeyLabel(String key) {
    final id = key.substring(key.indexOf(':') + 1);
    if (key.startsWith('company:')) {
      return _me.companies.where((c) => c.id == id).firstOrNull?.name ??
          'A company';
    }
    if (key.startsWith('organization:')) {
      return id == _me.organization.id
          ? _me.organization.name
          : 'This organization';
    }
    return 'Elsewhere';
  }

  /// "Company · Branch" for a branch, the company or organization otherwise.
  String _companyLabel(MailboxConnection mailbox) =>
      mailbox.scopeType == 'franchise'
      ? branchLabelOf(_me, mailbox.scopeId, scopeLabel(_me, mailbox))
      : scopeLabel(_me, mailbox);

  List<MailboxConnection> get _visibleMailboxes {
    final q = _search.text.trim().toLowerCase();

    return [
      for (final m in _mailboxes)
        if ((_companyFilter == null || _companyKey(m) == _companyFilter) &&
            (q.isEmpty ||
                [
                  m.address,
                  m.displayName,
                  _companyLabel(m),
                  m.providerLabel,
                ].any((v) => v.toLowerCase().contains(q))))
          m,
    ];
  }

  // ── what needs attention ─────────────────────────────────────

  static bool _workspaceTrouble(WorkspaceLink link) =>
      link.pending || (link.health?.needsAttention ?? false);

  static bool _inboxTrouble(MailboxConnection m) =>
      m.status != statusConnected || !m.canBeOpened;

  bool _isExpanded(WorkspaceLink link) =>
      _expanded[link.domain] ?? _workspaceTrouble(link);

  GlobalKey _rowKey(String id) => _rowKeys.putIfAbsent(id, GlobalKey.new);

  /// The summary's ›: the first Workspace with something missing is opened and
  /// scrolled to; failing that, the first inbox in trouble is opened.
  Future<void> _openFirstProblem(List<WorkspaceLink> links) async {
    final link = links.where(_workspaceTrouble).firstOrNull;

    if (link != null) {
      setState(() => _expanded[link.domain] = true);
      await WidgetsBinding.instance.endOfFrame;
      final context = _rowKey('ws-${link.domain}').currentContext;
      if (context != null && context.mounted) {
        await Scrollable.ensureVisible(
          context,
          duration: const Duration(milliseconds: 250),
        );
      }
      return;
    }

    final inbox = _mailboxes.where(_inboxTrouble).firstOrNull;
    if (inbox != null) _select(inbox);
  }

  int _inboxesOn(WorkspaceLink link) => _mailboxes
      .where(
        (m) =>
            m.provider == 'gmail_workspace' &&
            m.address.toLowerCase().endsWith('@${link.domain.toLowerCase()}'),
      )
      .length;

  Widget _scrollBody() {
    final theme = Theme.of(context);
    final links = _workspace?.connections ?? const <WorkspaceLink>[];
    final offerConnect = _workspaceReachable && (_workspace?.canConnect ?? false);
    final showWorkspace = _workspaceReachable && (links.isNotEmpty || offerConnect);
    final attention =
        links.where(_workspaceTrouble).length +
        _mailboxes.where(_inboxTrouble).length;
    final connectRefusal = _me.refusal('email.edit', 'connect Google Workspace');

    return ListView(
      key: const Key('email-settings-list'),
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(24, 4, 24, 28),
      children: [
        _SummaryLine(
          workspaces: links.length,
          inboxes: _mailboxes.length,
          attention: attention,
          onAttention: attention == 0 ? null : () => _openFirstProblem(links),
        ),
        const SizedBox(height: 14),
        if (showWorkspace) ...[
          Text('Google Workspace', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          for (final link in links)
            Padding(
              key: _rowKey('ws-${link.domain}'),
              padding: const EdgeInsets.only(bottom: 8),
              child: _WorkspaceRow(
                link: link,
                viewLabel: _workspace?.viewLabel ?? '',
                inboxes: _inboxesOn(link),
                expanded: _isExpanded(link),
                onToggle: () => setState(
                  () => _expanded[link.domain] = !_isExpanded(link),
                ),
                onShowMissing: () => setState(() => _expanded[link.domain] = true),
                connectRefusal: connectRefusal,
                onDisconnect: () => _disconnectWorkspace(link),
              ),
            ),
          if (offerConnect)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _WorkspaceCard(
                link: null,
                viewLabel: _workspace?.viewLabel ?? '',
                connectRefusal: connectRefusal,
                onConnect: _connectWorkspace,
                onDisconnect: () {},
              ),
            ),
          const SizedBox(height: 14),
        ],
        Text('Connected inboxes', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        if (_mailboxes.isEmpty)
          _nothingConnected()
        else ...[
          _inboxFilters(),
          const SizedBox(height: 10),
          _inboxTable(),
        ],
      ],
    );
  }

  Widget _inboxFilters() {
    final keys = <String>{for (final m in _mailboxes) _companyKey(m)}.toList()
      ..sort((a, b) => _companyKeyLabel(a).compareTo(_companyKeyLabel(b)));
    final picked = _companyFilter;

    return Wrap(
      spacing: 10,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 280,
          child: TextField(
            key: const ValueKey('email-inbox-search'),
            controller: _search,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search, size: 18),
              hintText: 'Search inboxes',
            ),
          ),
        ),
        if (keys.length > 1)
          MenuAnchor(
            menuChildren: [
              for (final key in <String?>[null, ...keys])
                MenuItemButton(
                  key: ValueKey('email-inbox-company-${key ?? 'all'}'),
                  leadingIcon: Icon(
                    picked == key
                        ? Icons.radio_button_checked
                        : Icons.radio_button_unchecked,
                    size: 18,
                  ),
                  onPressed: () => setState(() => _companyFilter = key),
                  child: Text(key == null ? 'All companies' : _companyKeyLabel(key)),
                ),
            ],
            builder: (context, controller, _) => OutlinedButton.icon(
              key: const ValueKey('email-inbox-company'),
              onPressed: () =>
                  controller.isOpen ? controller.close() : controller.open(),
              icon: const Icon(Icons.filter_list, size: 18),
              label: Text(picked == null ? 'All companies' : _companyKeyLabel(picked)),
            ),
          ),
      ],
    );
  }

  /// The inbox list. Roles are not a column: the list endpoint does not send
  /// them (they are on the inbox's own pane, under who can open it).
  Widget _inboxTable() {
    final rows = _visibleMailboxes;
    final theme = Theme.of(context);

    if (rows.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 20),
        child: Text(
          'No inbox matches.',
          key: const ValueKey('email-inbox-none'),
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Address', flex: 4),
        ListColumn('Company', flex: 3),
        ListColumn('Kind', flex: 2),
        ListColumn('Status', flex: 3),
        ListColumn('Last checked', flex: 2),
      ],
      rowKey: (i) => ValueKey('email-inbox-row-${rows[i].id}'),
      onTap: (i) => _select(rows[i]),
      rows: [
        for (final m in rows)
          [
            Text(
              m.address,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
                color: m.id == _selectedId ? theme.colorScheme.primary : null,
              ),
            ),
            Text(
              _companyLabel(m),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
            Text(
              m.providerLabel.isNotEmpty
                  ? m.providerLabel
                  : _providerFor(m)?.label ?? '—',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 260),
              child: _inboxChip(m),
            ),
            Text(
              _lastChecked(m),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ],
      ],
    );
  }

  /// Working / Needs password / Stopped · reason.
  Widget _inboxChip(MailboxConnection m) {
    if (m.status == statusBroken || (m.status == statusConnected && !m.canBeOpened)) {
      final why = m.statusReason ?? m.unavailableReason;
      final short = why == null
          ? (m.canBeOpened ? 'not answering' : 'cannot be opened')
          : _firstClause(why);

      return Tooltip(
        message: why ?? stateOf(m).word,
        child: StatusPill('Stopped · $short', tone: Tone.bad),
      );
    }
    if (m.status == statusUnconfigured) {
      final password = _providerFor(m)?.connectsBy == 'password';

      return StatusPill(
        password ? 'Needs password' : 'Not connected yet',
        tone: Tone.caution,
      );
    }

    return const StatusPill('Working', tone: Tone.ok);
  }

  static String _firstClause(String sentence) {
    final cut = sentence.indexOf(RegExp(r'[.;]'));
    final head = (cut > 0 ? sentence.substring(0, cut) : sentence).trim();

    return head.length > 48 ? '${head.substring(0, 47)}…' : head;
  }

  String _lastChecked(MailboxConnection m) {
    if (!m.lastReachedKnown) return '—';
    final iso = m.lastReachedAt;
    if (iso == null) return 'Never';
    final at = DateTime.tryParse(iso)?.toLocal();
    if (at == null) return '—';
    final ago = DateTime.now().difference(at);
    if (ago.inMinutes < 1) return 'Just now';
    if (ago.inMinutes < 60) return '${ago.inMinutes} min ago';
    if (ago.inHours < 24) return '${ago.inHours} h ago';

    return '${ago.inDays} d ago';
  }

  /// One inbox's settings — the card it always had — beside the list, or in
  /// its place where there is no room.
  Widget _inboxPane(MailboxConnection mailbox, {required bool beside}) {
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('email-inbox-pane'),
      decoration: beside
          ? BoxDecoration(
              border: Border(left: BorderSide(color: EzBiziTheme.edge(context))),
            )
          : null,
      child: ListView(
        key: ValueKey('email-inbox-pane-${mailbox.id}'),
        padding: EdgeInsets.fromLTRB(beside ? 16 : 24, 4, 24, 28),
        children: [
          Row(
            children: [
              if (!beside)
                TextButton.icon(
                  key: const ValueKey('email-inbox-pane-back'),
                  onPressed: _closePane,
                  icon: const Icon(Icons.arrow_back, size: 18),
                  label: const Text('All inboxes'),
                )
              else
                Expanded(
                  child: Text(
                    'Inbox settings',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
              if (!beside) const Spacer(),
              if (beside)
                IconButton(
                  key: const ValueKey('email-inbox-pane-close'),
                  tooltip: 'Close',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: _closePane,
                ),
            ],
          ),
          const SizedBox(height: 6),
          _MailboxCard(
            mailbox: mailbox,
            me: _me,
            provider: _providerFor(mailbox),
            connectRefusal: _manageRefusal(mailbox, 'connect this inbox'),
            disconnectRefusal: _manageRefusal(mailbox, 'disconnect this inbox'),
            onConnect: () => _connect(mailbox),
            onDisconnect: () => _disconnect(mailbox),
            onMarkReadAtSource: (on) => _setMarkReadAtSource(mailbox, on),
            alerts: mailbox.mayManage ? _alerts : null,
            onNewMailAlert: (on) => _setNewMailAlert(mailbox, on),
            // Only for somebody who may manage this inbox: choosing who reads
            // it is managing it, and the server refuses anybody else.
            people: mailbox.mayManage
                ? InboxPeople(
                    key: ValueKey('people-${mailbox.id}'),
                    api: widget.api,
                    mailboxId: mailbox.id,
                  )
                : null,
          ),
        ],
      ),
    );
  }

  /// No inbox at all — a setup step rather than an empty list.
  Widget _nothingConnected() => Padding(
    key: const ValueKey('tour-email-settings-empty'),
    padding: const EdgeInsets.symmetric(vertical: 32),
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 460),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.mark_email_unread_outlined,
            size: 34,
            color: Theme.of(context).colorScheme.outline,
          ),
          const SizedBox(height: 12),
          Text(
            'No shared inbox is connected here yet.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            _addRefusal ??
                'Add the address people already write to — info@, bookings@ '
                    '— and then give the roles that answer it access under '
                    'Roles & permissions.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ],
      ),
    ),
  );

}

/// One Workspace grant, or the empty card that offers to connect this view.
class _WorkspaceCard extends StatelessWidget {
  const _WorkspaceCard({
    required this.link,
    required this.viewLabel,
    required this.connectRefusal,
    required this.onConnect,
    required this.onDisconnect,
  });

  final WorkspaceLink? link;
  final String viewLabel;
  final String? connectRefusal;
  final VoidCallback onConnect;
  final VoidCallback onDisconnect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final linked = link != null;
    final pending = link?.pending ?? false;
    final belongs = link?.scopeLabel.isNotEmpty == true
        ? link!.scopeLabel
        : viewLabel;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Google Workspace', style: theme.textTheme.titleSmall),
            if (belongs.isNotEmpty) ...[
              const SizedBox(height: 2),
              Text(belongs, style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 6),
            Text(
              linked
                  ? (pending
                      ? '${link!.domain} is linked. Finish the steps below in '
                          'this company\'s Google Admin — not in EzBizi — then '
                          'come back here.'
                      : '${link!.domain} is connected. Shared inboxes are '
                          'picked from the directory when you add one.')
                  : 'Connect the Google Workspace for $belongs, then pick '
                      'shared addresses like info@ from the directory.',
              style: theme.textTheme.bodyMedium,
            ),
            if (link?.health != null) ...[
              const SizedBox(height: 16),
              _WorkspaceHealth(health: link!.health!),
            ],
            if ((pending || (link?.health?.needsAttention ?? false)) &&
                link!.setupSteps.isNotEmpty) ...[
              const SizedBox(height: 16),
              _DelegationGuide(steps: link!.setupSteps),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (linked &&
                    (pending || (link?.health?.needsAttention ?? false)) &&
                    (link!.marketplaceListingUrl ?? '').isNotEmpty)
                  FilledButton(
                    onPressed: () => launchUrl(
                      Uri.parse(link!.marketplaceListingUrl!),
                      mode: LaunchMode.externalApplication,
                      webOnlyWindowName: '_blank',
                    ),
                    child: const Text('Install EzBizi in Google'),
                  ),
                linked
                    ? Refused(
                        reason: connectRefusal,
                        child: OutlinedButton(
                          onPressed: connectRefusal == null
                              ? onDisconnect
                              : null,
                          child: const Text('Disconnect Workspace'),
                        ),
                      )
                    : Refused(
                        reason: connectRefusal,
                        child: FilledButton(
                          onPressed: connectRefusal == null ? onConnect : null,
                          child: const Text('Connect Workspace'),
                        ),
                      ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// "2 Workspaces · 5 inboxes · 1 needs attention ›" — the ›, and the count
/// before it, open the first problem.
class _SummaryLine extends StatelessWidget {
  const _SummaryLine({
    required this.workspaces,
    required this.inboxes,
    required this.attention,
    required this.onAttention,
  });

  final int workspaces;
  final int inboxes;
  final int attention;
  final VoidCallback? onAttention;

  static String _n(int n, String one, String many) => '$n ${n == 1 ? one : many}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final caution = StateColour.of(context).caution;
    final head =
        '${_n(workspaces, 'Workspace', 'Workspaces')} · '
        '${_n(inboxes, 'inbox', 'inboxes')} · ';

    return Wrap(
      key: const ValueKey('email-settings-summary'),
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(head, style: theme.textTheme.bodyMedium),
        if (attention == 0)
          Text(
            'nothing needs attention',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: StateColour.of(context).on,
            ),
          )
        else
          InkWell(
            key: const ValueKey('email-settings-attention'),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            onTap: onAttention,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
              child: Text(
                '$attention ${attention == 1 ? 'needs' : 'need'} attention ›',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: caution,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// One Workspace as a compact row: the G mark, domain, company, inbox count
/// and one health chip; it opens to the permissions with their meanings, the
/// Google Admin steps, and stays shut unless something is missing.
class _WorkspaceRow extends StatelessWidget {
  const _WorkspaceRow({
    required this.link,
    required this.viewLabel,
    required this.inboxes,
    required this.expanded,
    required this.onToggle,
    required this.onShowMissing,
    required this.connectRefusal,
    required this.onDisconnect,
  });

  final WorkspaceLink link;
  final String viewLabel;
  final int inboxes;
  final bool expanded;
  final VoidCallback onToggle;
  final VoidCallback onShowMissing;
  final String? connectRefusal;
  final VoidCallback onDisconnect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final health = link.health;
    final belongs = link.scopeLabel.isNotEmpty ? link.scopeLabel : viewLabel;
    final missing = health?.scopes.where((s) => !s.ok).length ?? 0;
    final trouble = link.pending || (health?.needsAttention ?? false);

    final Widget chip;
    if (link.pending) {
      chip = _chip(
        context,
        'Waiting for Google Admin · Update permissions',
        Tone.caution,
        onShowMissing,
      );
    } else if (missing > 0 || trouble) {
      chip = _chip(
        context,
        '${missing > 0 ? '$missing missing' : 'Needs attention'} · Update permissions',
        Tone.caution,
        onShowMissing,
      );
    } else if (health != null) {
      chip = StatusPill('All ${health.scopes.length} permissions', tone: Tone.ok);
    } else {
      chip = const StatusPill('Connected', tone: Tone.ok);
    }

    final menu = MenuAnchor(
      menuChildren: [
        Refused(
          reason: connectRefusal,
          child: MenuItemButton(
            key: ValueKey('workspace-disconnect-${link.domain}'),
            leadingIcon: const Icon(Icons.link_off, size: 18),
            onPressed: connectRefusal == null ? onDisconnect : null,
            child: const Text('Disconnect Workspace'),
          ),
        ),
      ],
      builder: (context, controller, _) => IconButton(
        key: ValueKey('workspace-menu-${link.domain}'),
        tooltip: 'More for ${link.domain}',
        icon: const Icon(Icons.more_horiz, size: 20),
        onPressed: () => controller.isOpen ? controller.close() : controller.open(),
      ),
    );

    final heading = LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < 560;
        final name = Row(
          children: [
            const _GMark(),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    link.domain,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  Row(
                    children: [
                      if (belongs.isNotEmpty)
                        Flexible(
                          child: Text(
                            belongs,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                      Text(
                        '${belongs.isNotEmpty ? ' · ' : ''}'
                        '$inboxes ${inboxes == 1 ? 'inbox' : 'inboxes'}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ],
              ),
            ),
            if (narrow) menu,
          ],
        );
        final tail = <Widget>[
          Flexible(child: chip),
          Icon(expanded ? Icons.expand_less : Icons.expand_more, size: 20),
        ];

        if (narrow) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              name,
              const SizedBox(height: 6),
              Row(children: [const SizedBox(width: 38), ...tail]),
            ],
          );
        }

        return Row(
          children: [
            Expanded(child: name),
            const SizedBox(width: 12),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 320),
              child: Row(mainAxisSize: MainAxisSize.min, children: tail),
            ),
            menu,
          ],
        );
      },
    );

    return Card(
      key: ValueKey('workspace-row-${link.domain}'),
      margin: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            onTap: onToggle,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 8, 6, 8),
              child: heading,
            ),
          ),
          if (expanded)
            Padding(
              key: ValueKey('workspace-detail-${link.domain}'),
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Divider(height: 1, color: EzBiziTheme.edge(context)),
                  const SizedBox(height: 12),
                  if (link.pending)
                    Text(
                      '${link.domain} is linked. Finish the steps below in '
                      'this company\'s Google Admin — not in EzBizi — then '
                      'come back here.',
                      style: theme.textTheme.bodyMedium,
                    ),
                  if (health != null) _WorkspaceHealth(health: health),
                  if (trouble && link.setupSteps.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    _DelegationGuide(steps: link.setupSteps),
                  ],
                  if (trouble && (link.marketplaceListingUrl ?? '').isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: FilledButton(
                        onPressed: () => launchUrl(
                          Uri.parse(link.marketplaceListingUrl!),
                          mode: LaunchMode.externalApplication,
                          webOnlyWindowName: '_blank',
                        ),
                        child: const Text('Install EzBizi in Google'),
                      ),
                    ),
                  ],
                ],
              ),
            ),
        ],
      ),
    );
  }

  static Widget _chip(
    BuildContext context,
    String label,
    Tone tone,
    VoidCallback onTap,
  ) => InkWell(
    key: const ValueKey('workspace-health-chip'),
    borderRadius: BorderRadius.circular(999),
    onTap: onTap,
    child: StatusPill(label, tone: tone),
  );
}

/// Google's G, drawn from theme colours rather than a brand asset.
class _GMark extends StatelessWidget {
  const _GMark();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: 28,
      height: 28,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: scheme.primaryContainer,
      ),
      child: Text(
        'G',
        style: TextStyle(
          fontWeight: FontWeight.w800,
          fontSize: 14,
          color: scheme.onPrimaryContainer,
        ),
      ),
    );
  }
}

class _WorkspaceHealth extends StatelessWidget {
  const _WorkspaceHealth({required this.health});

  final WorkspaceHealth health;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // Were `const Color(0xFF188038)` / `0xFFE37400` — already named for the
     // two roles, just written out by hand. Three modules had their own
     // near-identical pair, which is why the theme now has one.
    final okColor = StateColour.of(context).on;
    final warnColor = StateColour.of(context).caution;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Health', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          health.summary,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: health.ok ? okColor : (health.needsAttention ? warnColor : null),
          ),
        ),
        const SizedBox(height: 8),
        LayoutBuilder(
          builder: (context, box) {
            // Four across on a wide row, two on a tablet, one on a phone.
            final across = box.maxWidth >= 720
                ? 4
                : box.maxWidth >= 380
                ? 2
                : 1;
            final width = (box.maxWidth - 12 * (across - 1)) / across;

            return Wrap(
              spacing: 12,
              runSpacing: 10,
              children: [
                for (final scope in health.scopes)
                  SizedBox(
                    width: width,
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(
                          scope.ok ? Icons.check_circle : Icons.error_outline,
                          size: 18,
                          color: scope.ok ? okColor : warnColor,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                scope.label,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              Text(
                                scope.detail,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.bodySmall,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _DelegationGuide extends StatelessWidget {
  const _DelegationGuide({required this.steps});

  final List<WorkspaceSetupStep> steps;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('To-do in Google Admin', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        for (var i = 0; i < steps.length; i++) ...[
          if (i > 0) const SizedBox(height: 12),
          _GuideStep(number: i + 1, step: steps[i]),
        ],
      ],
    );
  }
}

class _GuideStep extends StatelessWidget {
  const _GuideStep({required this.number, required this.step});

  final int number;
  final WorkspaceSetupStep step;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 24,
          child: Text('$number.', style: theme.textTheme.titleSmall),
        ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(step.title, style: theme.textTheme.titleSmall),
              const SizedBox(height: 2),
              Text(step.detail, style: theme.textTheme.bodySmall),
              if (step.url != null) ...[
                const SizedBox(height: 6),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: () => launchUrl(
                      Uri.parse(step.url!),
                      mode: LaunchMode.externalApplication,
                      webOnlyWindowName: '_blank',
                    ),
                    child: Text(
                      step.url!.contains('workspace.google.com/marketplace')
                          ? 'Install in Google'
                          : 'Open Google Admin',
                    ),
                  ),
                ),
              ],
              if (step.copy != null && step.copy!.isNotEmpty) ...[
                const SizedBox(height: 6),
                SelectableText(
                  step.copy!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontFamily: 'monospace',
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// One connection, everything about it on one card.
class _MailboxCard extends StatelessWidget {
  const _MailboxCard({
    required this.mailbox,
    required this.me,
    required this.provider,
    required this.connectRefusal,
    required this.disconnectRefusal,
    required this.onConnect,
    required this.onDisconnect,
    this.onMarkReadAtSource,
    this.alerts,
    this.onNewMailAlert,
    this.people,
  });

  final MailboxConnection mailbox;
  final Me me;
  final ProviderOption? provider;
  final String? connectRefusal;
  final String? disconnectRefusal;
  final VoidCallback onConnect;
  final VoidCallback onDisconnect;

  /// Null on the cards that are not a real mailbox row (the Workspace ones).
  final void Function(bool on)? onMarkReadAtSource;

  /// The new-email notification's state (GET email/mailboxes/new-mail-alerts);
  /// null hides the switch -- not this viewer's inbox to manage, or unreadable.
  final Map<String, dynamic>? alerts;
  final void Function(bool on)? onNewMailAlert;

  /// Who can open this inbox, person by person; null when this viewer may not
  /// choose. See inbox_people.dart.
  final Widget? people;

  /// Why the switch cannot be flipped from here, or null when it can.
  String? get _alertBlocker {
    if (alerts!['editable'] != true) {
      return '${alerts!['reason'] ?? 'This was changed in Automations.'}';
    }
    if (alerts!['may_change'] != true) {
      return 'Changing it takes permission to change automations.';
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = stateOf(mailbox);
    final reached = lastReachedSentence(mailbox, formats: me.display);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Wrap rather than Row: at 375 the state word drops under the
            // address instead of squeezing it into two characters, which is
            // what a Row does to the one thing on the card nobody can afford
            // to misread.
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  mailbox.address,
                  style: theme.textTheme.titleMedium,
                ),
                Tag(state.word, warn: state.trouble),
              ],
            ),
            if (mailbox.named) ...[
              const SizedBox(height: 2),
              Text(mailbox.displayName, style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 32,
              runSpacing: 12,
              children: [
                _Fact(
                  label: 'BELONGS TO',
                  // The level and the name together. "Everdoors" alone does not
                  // say whether that is the whole organization or one company
                  // inside it, and two inboxes called info@ at two levels is
                  // the case this app has already met.
                  value:
                      '${scopeLevelLabel(me, mailbox.scopeType)} · '
                      '${scopeLabel(me, mailbox)}',
                ),
                _Fact(
                  label: 'MAIL SERVICE',
                  // The server's label where it sent one. Where it did not, the
                  // wire value is not shown — it is a name for a database
                  // column, not for a person — and the gap is stated instead.
                  value: mailbox.providerLabel.isNotEmpty
                      ? mailbox.providerLabel
                      : provider?.label ??
                            'A service this version of the app cannot name',
                ),
              ],
            ),
            if (state.sentence != null) ...[
              const SizedBox(height: 12),
              _StateSentence(sentence: state.sentence!, trouble: state.trouble),
            ],
            // Drawn only where the server said this connection can do it, and
            // only for somebody who may change the inbox. A switch the server
            // would refuse is the shape the house rule forbids — and here the
            // refusal would arrive AFTER a message had already been marked
            // read for everybody, which is not a refusal anyone can act on.
            if (mailbox.canMarkReadAtSource && mailbox.mayManage && onMarkReadAtSource != null) ...[
              const SizedBox(height: 6),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: mailbox.markReadAtSource,
                onChanged: onMarkReadAtSource,
                title: const Text('Keep read and unread in step with the mail service'),
                // The whole decision, in the words of the thing that goes
                // wrong. On by default since 2026-10-06 (Jose: "Sync email
                // read/unread with gmail"), so the cost is said while it is ON:
                // on a shared inbox one person reading marks it read for
                // everyone looking at the mail service directly.
                subtitle: Text(
                  mailbox.markReadAtSource
                      ? 'Reading a message here, or marking it unread, does the same in the '
                            'mail service, for everyone. On a shared inbox, one person reading '
                            'it marks it read for the others too.'
                      : 'Read and unread change only in EzBizi, so anyone looking at the mail '
                            'service directly still sees the old state.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
            // Jose, 2026-10-06: the "New email" automation, switched from
            // here as well as from Automations -- the same rule, so the two
            // cannot disagree. Drawn disabled, with the reason, when this
            // screen may not change it, so the state is still visible.
            if (alerts != null && onNewMailAlert != null) ...[
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: (alerts!['addresses'] as List? ?? const [])
                    .contains(mailbox.address.toLowerCase()),
                onChanged: _alertBlocker == null ? onNewMailAlert : null,
                title: const Text('Notify about new emails'),
                subtitle: Text(
                  _alertBlocker ??
                      'Sender, subject and the start of the message, for mail a person '
                          'wrote. Newsletters and other bulk mail are skipped. The same '
                          'people as new leads are told; change it in Automations too.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
            if (people != null) ...[
              const SizedBox(height: 14),
              people!,
            ],
            if (reached != null) ...[
              const SizedBox(height: 10),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.schedule,
                    size: 15,
                    color: theme.textTheme.bodySmall?.color,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(reached, style: theme.textTheme.bodySmall),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 12),
            // Wrap again, for the same reason as the heading. At 375 the list
            // takes 48 for its own padding and the card 32 for its own, which
            // leaves 295 for a "Reconnect" and a "Disconnect" sitting side by
            // side. That fits, and stops fitting the moment either label grows
            // or somebody's text is scaled up — which a Row would answer with
            // an overflow stripe rather than a second line.
            Wrap(
              spacing: 10,
              runSpacing: 8,
              children: [
                Refused(
                  reason: connectRefusal,
                  child: OutlinedButton(
                    onPressed: connectRefusal == null ? onConnect : null,
                    child: Text(_connectLabel),
                  ),
                ),
                Refused(
                  reason: disconnectRefusal,
                  child: TextButton(
                    onPressed: disconnectRefusal == null ? onDisconnect : null,
                    child: const Text('Disconnect'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// "Connect" for an address nobody has finished setting up, "Reconnect" for
  /// one that has a key already — including a working one, where replacing a
  /// changed password is a perfectly ordinary thing to want.
  String get _connectLabel =>
      mailbox.status == statusUnconfigured ? 'Connect' : 'Reconnect';
}

/// A labelled fact on a card — stacked at any width, so nothing has to be a
/// column that collapses.
class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ConstrainedBox(
      // Bounded so a long company path wraps inside the card instead of
      // pushing the Wrap wider than the row it sits in.
      constraints: const BoxConstraints(maxWidth: 320),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label, style: theme.textTheme.labelSmall),
          const SizedBox(height: 2),
          Text(value, style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }
}

/// The state's sentence — what happened, and what to do about it.
///
/// It sits in a block of its own rather than as another line of body text so it
/// is not skimmed past, and it carries an icon with a label of its own: the
/// colour behind it says "trouble" to somebody who can see it and the icon's
/// label says the same thing to a screen reader.
class _StateSentence extends StatelessWidget {
  const _StateSentence({required this.sentence, required this.trouble});

  final String sentence;
  final bool trouble;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: (trouble ? scheme.errorContainer : scheme.surfaceContainerHighest)
            .withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            label: trouble ? 'Needs attention' : 'For information',
            child: Icon(
              trouble ? Icons.error_outline : Icons.info_outline,
              size: 17,
              color: trouble ? scheme.error : theme.textTheme.bodySmall?.color,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              sentence,
              style: theme.textTheme.bodySmall?.copyWith(
                color: trouble ? scheme.error : null,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// What the server said just happened, until the reader has had it.
class _Notice extends StatelessWidget {
  const _Notice(this.message, {required this.onDismiss});

  final String message;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(message, style: theme.textTheme.bodySmall)),
          IconButton(
            icon: const Icon(Icons.close, size: 18),
            tooltip: 'Dismiss this message',
            onPressed: onDismiss,
          ),
        ],
      ),
    );
  }
}
