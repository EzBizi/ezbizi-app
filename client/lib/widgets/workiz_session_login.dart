import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import 'search_picker.dart';
import 'status_pill.dart';
import 'disconnect_panel.dart';

/// The Workiz web login: a signed-in Workiz session, handed to EzBizi through
/// the EzBizi browser extension (UI queue 120, option 1 and phone 4).
///
/// One per COMPANY, provider `workiz_web` (Workiz, 2026-10-21: "the workiz
/// belongs to a-1chimney only"). A company view works on its own company; the
/// organization view picks the company first and passes `company_id`; a
/// branch view is told to switch to the company view. Three steps: get a link
/// (good for 30 minutes), paste it into the extension in the browser that is
/// signed in to Workiz, and wait — this polls the connections every 5 seconds
/// while the link is live and turns green on its own. Under it, every Workiz
/// account the login reaches, each on the branch the server read from the
/// account's own name ("auto"), which Change overrides.
///
/// Every call tolerates a server without the routes: a 404 says "Not available
/// on this server yet" rather than an error.
class WorkizSessionLogin extends StatefulWidget {
  const WorkizSessionLogin({
    super.key,
    required this.api,
    this.branches = const [],
    this.companies = const [],
    this.companiesInView = const [],
    this.onConnected,
    this.viewType = 'organization',
    this.viewId,
    this.mayDisconnect = false,
  });

  final ApiClient api;

  /// The current view: `organization`, `company` or `franchise`. The server
  /// answers the link and the accounts in the first two only (403 from a
  /// branch); from a branch view the card shows the status read-only and
  /// [branchHint] in place of the steps and the table, and asks for neither.
  final String viewType;

  /// The scoped company or branch's id (`/me` scope id).
  final String? viewId;

  /// The hint in a branch view, and on a 403 that sends no sentence.
  static const branchHint = 'Switch to the company view to change it.';

  /// The note on a login sent before logins were per company; [WorkizWebMove]
  /// beside it moves the login to its company, session kept.
  static const legacyNote = 'Sent before logins were per company.';

  /// Under the accounts table: what the login reaches, and no more. The
  /// server keeps one login per EzBizi company (the link reuses the
  /// company's row; a move onto a company that has one answers 409).
  static const reach =
      'This login reads the Workiz accounts listed above. Another Workiz '
      'company is connected from its own EzBizi company.';

  /// The branches an account can be filed on: the current view's, limited
  /// to the login's company.
  final List<FranchiseSummary> branches;

  /// Names each of [branches] with its company ("Main · Everdoors").
  final List<CompanySummary> companies;

  /// The organization view's company picker: the companies in view.
  final List<CompanySummary> companiesInView;

  /// Whether this person holds the connect permission, which is the
  /// server's disconnect gate. Disconnect shows only with it, and only in
  /// the organization view (the server refuses it anywhere else).
  final bool mayDisconnect;

  static const disconnectQuestion =
      "Disconnect the Workiz web login? EzBizi stops reading Workiz's reports "
      'until someone sends a session again.';

  /// Called once the session arrives, so the page's list can reload.
  final VoidCallback? onConnected;

  static const disclaimer =
      "EzBizi reads Workiz through your signed-in session because Workiz's "
      "API doesn't offer these reports. Workiz's terms may not allow automated "
      'use of its website; use at your own discretion.';

  @override
  State<WorkizSessionLogin> createState() => _WorkizSessionLoginState();
}

class _WorkizSessionLoginState extends State<WorkizSessionLogin> {
  static const _life = 30 * 60;

  /// Every company-scoped `workiz_web` row, for the picker's status.
  List<Map<String, dynamic>> _rows = const [];

  /// The organization view's picked company.
  String? _picked;
  bool _unavailable = false;
  String? _failure;

  /// Why the steps and the table are not shown: a branch view, decided up
  /// front, or a 403 from the server.
  late String? _hint = _initialHint;

  String? get _initialHint =>
      widget.viewType == 'franchise' ? WorkizSessionLogin.branchHint : null;

  bool get _organizationView => widget.viewType == 'organization';

  /// The company whose login this card is: the company in view, the branch's
  /// company, or the one picked in the organization view.
  String? get _company => switch (widget.viewType) {
    'company' => widget.viewId,
    'franchise' => widget.companiesInView.firstOrNull?.id,
    _ => _picked,
  };

  /// The login of [_company], or null when it has none.
  Map<String, dynamic>? get _row => _rowOf(_company);

  Map<String, dynamic>? _rowOf(String? company) => company == null
      ? null
      : _rows.where((r) => r['scope_id'] == company).firstOrNull;

  String? _link;
  int _left = 0;
  bool _copied = false;
  bool _fetching = false;

  /// Send it again pressed on a signed-out session: the steps come back.
  bool _again = false;

  List<Map<String, dynamic>>? _accounts;

  /// Accounts whose branch picker is open (Change pressed).
  final Set<String> _changing = {};
  Timer? _ticker;
  bool _disconnecting = false;

  bool get _connected => _row?['status'] == 'connected';

  @override
  void initState() {
    super.initState();
    final pickable = _pickable;
    if (_organizationView && pickable.length == 1) _picked = pickable.first.id;
    _refresh();
  }

  /// Only companies, and only the active ones.
  List<CompanySummary> get _pickable => [
    for (final c in widget.companiesInView)
      if (c.isActive) c,
  ];

  @override
  void didUpdateWidget(WorkizSessionLogin old) {
    super.didUpdateWidget(old);
    // The form keeps its state across reloads (a global key), so a change of
    // view arrives here rather than as a fresh state.
    if (old.viewType == widget.viewType && old.viewId == widget.viewId) return;
    _hint = _initialHint;
    _pickCompany(null, refresh: true);
  }

  /// Another company picked (or the view changed): its own link, status and
  /// accounts.
  void _pickCompany(String? company, {bool refresh = false}) {
    _ticker?.cancel();
    setState(() {
      _picked = company;
      _link = null;
      _again = false;
      _accounts = null;
      _failure = null;
      _copied = false;
    });
    if (refresh) {
      _refresh();
    } else if (_row != null && _hint == null) {
      _loadAccounts();
    }
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  /// A 403 on the link or the accounts: the company view is needed.
  /// Shows the server's own sentence as a hint and stops the polling.
  bool _forbidden(ApiResponse response) {
    if (response.statusCode != 403) return false;
    _ticker?.cancel();
    final said = response.body['message'];
    setState(() {
      _fetching = false;
      _link = null;
      _again = false;
      _hint = said is String && said.isNotEmpty
          ? said
          : WorkizSessionLogin.branchHint;
    });
    return true;
  }

  static List<dynamic> _list(Object? body) => body is List
      ? body
      : (body is Map ? (body['data'] as List<dynamic>? ?? const []) : const []);

  /// Reads the `workiz_web` row; answers whether it is connected.
  Future<bool> _refresh() async {
    final response = await widget.api.get('/social/connections', {
      'include': 'data_sources',
    });
    if (!mounted || !response.ok) return false;
    // Company-scoped only: an organization-scoped row is the legacy login,
    // listed on the page with its note rather than worked on here.
    final rows = _list(response.body)
        .whereType<Map<String, dynamic>>()
        .where(
          (c) => c['provider'] == 'workiz_web' && c['scope_type'] == 'company',
        )
        .toList();
    final was = _connected;
    setState(() => _rows = rows);
    if (_row != null && _hint == null) await _loadAccounts();
    if (_connected && !was) {
      _ticker?.cancel();
      if (mounted) {
        setState(() {
          _link = null;
          _again = false;
        });
      }
      widget.onConnected?.call();
    }
    return _connected;
  }

  Future<void> _loadAccounts() async {
    final id = _row?['id'];
    if (id == null || _hint != null) return;
    final response = await widget.api.get('/social/workiz-web/$id/accounts');
    if (!mounted || _forbidden(response)) return;
    // The company changed while this was in flight.
    if (_row?['id'] != id) return;
    if (response.statusCode == 404) {
      setState(() => _unavailable = true);
      return;
    }
    if (!response.ok) return;
    setState(
      () => _accounts = _list(
        response.body,
      ).whereType<Map<String, dynamic>>().toList(),
    );
  }

  Future<void> _getLink() async {
    final company = _company;
    if (_hint != null || company == null) return;
    setState(() {
      _fetching = true;
      _failure = null;
      _copied = false;
    });
    // A company view's link is for the company in view; the organization view
    // names the company.
    final response = await widget.api.post('/social/workiz-web/link', {
      if (_organizationView) 'company_id': company,
    });
    if (!mounted || _forbidden(response)) return;
    if (response.statusCode == 404) {
      setState(() {
        _fetching = false;
        _unavailable = true;
      });
      return;
    }
    if (!response.ok) {
      setState(() {
        _fetching = false;
        _failure = response.message;
      });
      return;
    }
    final body = response.body['data'] is Map
        ? response.body['data'] as Map
        : response.body;
    final expires = DateTime.tryParse('${body['expires_at'] ?? ''}');
    setState(() {
      _fetching = false;
      _link = '${body['link'] ?? ''}';
      _left = expires == null
          ? _life
          : expires.difference(DateTime.now()).inSeconds.clamp(0, _life);
    });
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return timer.cancel();
      setState(() => _left--);
      if (_left <= 0) {
        timer.cancel();
        return;
      }
      if (_left % 5 == 0) _refresh();
    });
  }

  Future<void> _copy() async {
    try {
      await Clipboard.setData(ClipboardData(text: _link ?? ''));
    } catch (_) {
      // No clipboard on this platform; the link is still selectable.
    }
    if (mounted) setState(() => _copied = true);
  }

  Future<void> _file(Map<String, dynamic> account, String? branch) async {
    final response = await widget.api.put(
      '/social/workiz-web/${_row!['id']}/accounts',
      {'account': account['account'], 'franchise_id': branch},
    );
    if (!mounted || _forbidden(response)) return;
    if (response.statusCode == 404) {
      setState(() => _unavailable = true);
      return;
    }
    if (!response.ok) {
      setState(() => _failure = response.message);
      return;
    }
    setState(() {
      _failure = null;
      account['franchise_id'] = branch;
      // A branch picked here is an override of the one read from the name.
      account['mapped'] = branch != null;
      _changing.remove('${account['account']}');
    });
  }

  /// Asks once in a side panel, then `DELETE /social/connections/{id}` and
  /// back to the steps. A refusal shows inline.
  Future<void> _disconnect() async {
    final id = _row?['id'];
    if (id == null) return;
    setState(() {
      _disconnecting = true;
      _failure = null;
    });
    // The panel lists what stops and takes the authenticator code the
    // server requires for every disconnect.
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect',
      explanation: WorkizSessionLogin.disconnectQuestion,
      dependentsPath: '/integrations/social/$id/dependents',
      perform: (code) =>
          widget.api.deleteWithBody('/social/connections/$id', {'code': code}),
    );
    if (!mounted) return;
    if (done == null) {
      setState(() => _disconnecting = false);
      return;
    }
    _ticker?.cancel();
    setState(() {
      _disconnecting = false;
      _rows = [
        for (final r in _rows)
          if (r['id'] != id) r,
      ];
      _accounts = null;
      _link = null;
      _again = false;
    });
    await _refresh();
    widget.onConnected?.call();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 600;
        final steps = _row == null || _again || (!_connected && _link != null);
        final chosen = _company != null;

        return Column(
          key: const ValueKey('workiz-session'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_unavailable)
              Text(
                'Not available on this server yet.',
                key: const ValueKey('workiz-session-unavailable'),
                style: small,
              )
            else if (_hint != null) ...[
              // Read-only status, and the sentence in place of the steps and
              // the table: a hint, not an error.
              if (_row != null) _status(theme, small),
              Padding(
                padding: EdgeInsets.only(top: _row == null ? 0 : 8),
                child: Text(
                  _hint!,
                  key: const ValueKey('workiz-session-hint'),
                  style: small,
                ),
              ),
            ] else ...[
              if (_organizationView) _companyPicker(),
              if (_organizationView && !chosen)
                Text(
                  'Each company has its own Workiz web login.',
                  key: const ValueKey('workiz-session-choose'),
                  style: small,
                ),
            ],
            if (!_unavailable && _hint == null && chosen) ...[
              if (_row != null) _status(theme, small),
              if (_row != null && widget.mayDisconnect)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    key: const ValueKey('workiz-session-disconnect'),
                    onPressed: _disconnecting ? null : _disconnect,
                    child: Text(
                      _disconnecting ? 'Disconnecting…' : 'Disconnect',
                    ),
                  ),
                ),
              if (steps && phone)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Sending the session needs the EzBizi extension on a '
                    'computer.',
                    key: const ValueKey('workiz-session-phone'),
                    style: small,
                  ),
                )
              else if (steps)
                _steps(theme, small),
              if (_failure != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _failure!,
                    style: small?.copyWith(color: theme.colorScheme.error),
                  ),
                ),
              if (_row != null && _accounts != null) _table(theme, small),
            ],
            const SizedBox(height: 12),
            Text(
              WorkizSessionLogin.disclaimer,
              key: const ValueKey('workiz-session-disclaimer'),
              style: small,
            ),
          ],
        );
      },
    );
  }

  Widget _companyPicker() {
    String? status(String id) => switch (_rowOf(id)?['status']) {
      null => null,
      'connected' => 'Connected',
      _ => 'Signed out',
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: SearchPickerField<String>(
        key: const ValueKey('workiz-session-company'),
        label: 'For which company?',
        hint: 'Choose a company',
        value: _picked,
        options: [
          for (final c in _pickable)
            SearchOption<String>(
              value: c.id,
              label: c.name,
              detail: status(c.id),
            ),
        ],
        onChanged: (company) {
          if (company != _picked) _pickCompany(company);
        },
      ),
    );
  }

  Widget _status(ThemeData theme, TextStyle? small) {
    final reason = _row?['status_reason'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text('Workiz web login', style: theme.textTheme.titleSmall),
            const SizedBox(width: 8),
            _connected
                ? const StatusPill('Connected', tone: Tone.ok)
                : const StatusPill('Signed out', tone: Tone.bad),
          ],
        ),
        // When the session last reached EzBizi, stamped on handoff by the
        // server (Workiz, 2026-10-20). The link is anonymous on purpose, so
        // there is no "sent by".
        if (_connected &&
            DateTime.tryParse('${_row?['last_reached_at'] ?? ''}') != null) ...[
          const SizedBox(height: 4),
          Text(
            'Reads payments · last reached '
            '${formatAgo(DateTime.parse('${_row!['last_reached_at']}'))}',
            key: const ValueKey('workiz-session-reached'),
            style: small,
          ),
        ],
        if (!_connected) ...[
          if ((reason ?? '').isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              reason!,
              key: const ValueKey('workiz-session-reason'),
              style: small?.copyWith(color: theme.colorScheme.error),
            ),
          ],
          if (!_again && _link == null && _hint == null) ...[
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('workiz-session-again'),
              onPressed: () => setState(() => _again = true),
              child: const Text('Send it again'),
            ),
          ],
        ],
      ],
    );
  }

  Widget _step(
    ThemeData theme,
    int n,
    bool done,
    String title,
    List<Widget> body,
  ) {
    final scheme = theme.colorScheme;
    final tone = ToneColours.of(context, Tone.ok);

    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 11,
            backgroundColor: done ? tone.fill : scheme.primary,
            foregroundColor: done ? tone.ink : scheme.onPrimary,
            child: done
                ? const Icon(Icons.check, size: 14)
                : Text(
                    '$n',
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.titleSmall),
                ...body,
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _steps(ThemeData theme, TextStyle? small) {
    final link = _link;
    final live = link != null && _left > 0;
    final clock =
        '${_left ~/ 60}:${(_left % 60).toString().padLeft(2, '0')} left';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _step(theme, 1, live, 'Get a link', [
          const SizedBox(height: 4),
          if (link == null)
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton(
                key: const ValueKey('workiz-session-get'),
                onPressed: _fetching ? null : _getLink,
                child: Text(_fetching ? 'Getting a link…' : 'Get a link'),
              ),
            )
          else ...[
            Row(
              children: [
                Flexible(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 5,
                    ),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerHighest,
                      border: Border.all(color: EzBiziTheme.edge(context)),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: SelectableText(
                      link,
                      key: const ValueKey('workiz-session-link'),
                      maxLines: 1,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontFamily: 'monospace',
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton(
                  key: const ValueKey('workiz-session-copy'),
                  onPressed: _copy,
                  child: Text(_copied ? 'Copied' : 'Copy'),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  live
                      ? 'Good for 30 minutes · $clock'
                      : 'This link has run out.',
                  key: const ValueKey('workiz-session-clock'),
                  style: small,
                ),
                TextButton(
                  key: const ValueKey('workiz-session-new'),
                  onPressed: _fetching ? null : _getLink,
                  child: const Text('Get a new one'),
                ),
              ],
            ),
          ],
        ]),
        _step(theme, 2, false, 'Send your Workiz session', [
          Text(
            "In the browser where you're signed in to Workiz, click the "
            'EzBizi extension, paste the link, press Send.',
            style: small,
          ),
        ]),
        _step(theme, 3, false, 'Waiting for it…', [
          Text('This page turns green on its own.', style: small),
        ]),
      ],
    );
  }

  Widget _table(ThemeData theme, TextStyle? small) {
    final accounts = _accounts!;
    // Named with their company, and only the company the login belongs to:
    // every company's "Main" in one list told nobody which was which (Jose,
    // 2026-10-21), and the server refuses any other company's branch.
    final options = [
      for (final b in branchOptions(
        widget.branches,
        widget.companies,
        companyId: _company,
      ))
        SearchOption<String>(value: b.id, label: b.label),
    ];
    // The branch an account already sits on may be switched off, so its name
    // is looked up among every branch, not only the pickable ones.
    final labels = {
      for (final b in branchOptions(
        widget.branches,
        widget.companies,
        activeOnly: false,
      ))
        b.id: b.label,
    };

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text('WORKIZ ACCOUNT', style: small)),
              const SizedBox(width: 12),
              Expanded(child: Text('BRANCH', style: small)),
            ],
          ),
          if (accounts.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'No Workiz accounts reached by this login yet.',
                style: small,
              ),
            ),
          for (final account in accounts)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${account['name'] ?? account['account'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(child: _branchCell(account, options, labels, small)),
                ],
              ),
            ),
          if (accounts.any((a) => a['franchise_id'] == null))
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                "Payments from an account with no branch aren't filed "
                'anywhere.',
                style: small,
              ),
            ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              WorkizSessionLogin.reach,
              key: const ValueKey('workiz-session-reach'),
              style: small,
            ),
          ),
        ],
      ),
    );
  }

  /// The branch an account's payments are filed on. The server fills it from
  /// the account's own name ("auto"); Change opens the picker to override it.
  /// No branch at all shows the picker, highlighted.
  Widget _branchCell(
    Map<String, dynamic> account,
    List<SearchOption<String>> options,
    Map<String, String> labels,
    TextStyle? small,
  ) {
    final id = '${account['account']}';
    final branch = account['franchise_id'] as String?;

    if (branch == null || _changing.contains(id)) {
      return SearchPickerField<String>(
        key: ValueKey('workiz-session-branch:$id'),
        value: branch,
        options: options,
        hint: 'Choose a branch',
        isDense: true,
        errorText: branch == null ? '' : null,
        onChanged: (picked) {
          if (picked == branch) {
            setState(() => _changing.remove(id));
          } else {
            _file(account, picked);
          }
        },
      );
    }

    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 6,
      children: [
        Text(
          '→ ${labels[branch] ?? 'A branch outside this view'}',
          key: ValueKey('workiz-session-branch-name:$id'),
        ),
        if (account['mapped'] != true)
          Text('auto', key: ValueKey('workiz-session-auto:$id'), style: small),
        TextButton(
          key: ValueKey('workiz-session-change:$id'),
          style: TextButton.styleFrom(
            visualDensity: VisualDensity.compact,
            padding: const EdgeInsets.symmetric(horizontal: 6),
          ),
          onPressed: () => setState(() => _changing.add(id)),
          child: const Text('Change'),
        ),
      ],
    );
  }
}

/// Moves a Workiz web login sent before logins were per company (an
/// organization-scoped row) to a company, its session kept:
/// `PUT /social/workiz-web/{id}/company {company_id}`. The server answers it
/// in the organization view only; a 409 (that company already has a login)
/// shows its sentence here. Success calls [onMoved] so the page reloads.
class WorkizWebMove extends StatefulWidget {
  const WorkizWebMove({
    super.key,
    required this.api,
    required this.connectionId,
    required this.companies,
    this.onMoved,
  });

  final ApiClient api;
  final String connectionId;

  /// The companies in view; only the active ones are offered.
  final List<CompanySummary> companies;

  final Future<void> Function()? onMoved;

  @override
  State<WorkizWebMove> createState() => _WorkizWebMoveState();
}

class _WorkizWebMoveState extends State<WorkizWebMove> {
  late final List<CompanySummary> _pickable = [
    for (final c in widget.companies)
      if (c.isActive) c,
  ];
  late String? _picked = _pickable.length == 1 ? _pickable.first.id : null;
  bool _moving = false;
  String? _failure;

  Future<void> _move() async {
    final company = _picked;
    if (company == null) return;
    setState(() {
      _moving = true;
      _failure = null;
    });
    final response = await widget.api.put(
      '/social/workiz-web/${widget.connectionId}/company',
      {'company_id': company},
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _moving = false;
        _failure = response.message;
      });
      return;
    }
    setState(() => _moving = false);
    await widget.onMoved?.call();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final id = widget.connectionId;
    final name = _pickable.where((c) => c.id == _picked).firstOrNull?.name;

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 240,
                child: SearchPickerField<String>(
                  key: ValueKey('workiz-move-company:$id'),
                  value: _picked,
                  hint: 'Choose a company',
                  isDense: true,
                  options: [
                    for (final c in _pickable)
                      SearchOption<String>(value: c.id, label: c.name),
                  ],
                  onChanged: (company) => setState(() {
                    _picked = company;
                    _failure = null;
                  }),
                ),
              ),
              FilledButton(
                key: ValueKey('workiz-move:$id'),
                onPressed: name == null || _moving ? null : _move,
                child: Text(
                  _moving
                      ? 'Moving…'
                      : name == null
                      ? 'Move to a company'
                      : 'Move to $name',
                ),
              ),
            ],
          ),
          if (_failure != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                _failure!,
                key: ValueKey('workiz-move-failure:$id'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
