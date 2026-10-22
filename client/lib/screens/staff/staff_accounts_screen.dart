import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';
import '../../widgets/enrolment_dialog.dart';
import '../../widgets/error_note.dart';
import '../../widgets/recovery_codes.dart';
import '../../widgets/side_panel.dart';

/// EzBizi staff accounts (plan §4.2). Lead Developer only.
///
/// A new account arrives inert: it has a pending secret and cannot sign in until
/// a code proves the authenticator works. The person creating it shows the QR,
/// the new person scans and reads back six digits, and it is confirmed here —
/// so no unauthenticated enrolment endpoint has to exist.
class StaffAccountsScreen extends StatefulWidget {
  const StaffAccountsScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<StaffAccountsScreen> createState() => _StaffAccountsScreenState();
}

class _StaffAccountsScreenState extends State<StaffAccountsScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  List<Map<String, dynamic>> _accounts = [];
  String _query = '';

  /// The accounts whose visible text contains the search, case-insensitively.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _accounts;
    return _accounts.where((account) {
      final text = [
        account['name'],
        account['email'],
        account['tier'] == 'lead_developer' ? 'Lead Developer' : 'Developer',
        if (account['is_active'] == false) 'Disabled',
        if (account['audit_exempt'] == true) 'Not audited',
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. Enabling
      // an account, changing a tier and confirming an authenticator all refetch
      // through here, and the list they came from stays up meanwhile. There is
      // always at least one account — the one signed in — and a failure leaves
      // whatever was there alone, so empty means no answer has arrived yet.
      _loading = _accounts.isEmpty;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/accounts');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _accounts = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Staff',
          note:
              'Accounts that work across every organization. Two factors are '
              'mandatory — an account without a confirmed authenticator cannot '
              'sign in at all.',
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // As tall as the buttons beside it (Jose, 2026-10-20).
              SizedBox(
                width: 240,
                height: 40,
                child: TextField(
                  key: const Key('staff-accounts-search'),
                  textAlignVertical: TextAlignVertical.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    contentPadding: EdgeInsets.symmetric(horizontal: 12),
                    prefixIconConstraints: BoxConstraints(minWidth: 36),
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find a staff account',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              FilledButton.icon(
                onPressed: _create,
                icon: const Icon(Icons.person_add_outlined, size: 18),
                label: const Text('Add staff'),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _accounts.isNotEmpty && _shown.isEmpty
                ? Padding(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    child: Text(
                      'No staff accounts match "${_query.trim()}".',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _shown.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _shown[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> account) {
    final active = account['is_active'] as bool? ?? false;
    final exempt = account['audit_exempt'] as bool? ?? false;
    final confirmed = account['totp_confirmed'] as bool? ?? false;
    final pending = account['totp_pending'] as bool? ?? false;
    final isMe = account['id'] == widget.staff.me!.id;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              account['name'] as String,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                          ),
                          if (isMe) const Tag('You'),
                          if (!active) const Tag('Disabled', warn: true),
                          // §4.2's exemption, on the screen rather than only in
                          // the database. One account is outside the business
                          // audit trail; which one has to be visible to whoever
                          // is looking at this list, or nobody ever reviews it.
                          if (exempt) const Tag('Not audited', warn: true),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${account['email']} · '
                        '${account['tier'] == 'lead_developer' ? 'Lead Developer' : 'Developer'}',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),
                PopupMenuButton<String>(
                  // One of these per row, all the same icon: the framework
                  // default reads "Show menu" on every one of them.
                  tooltip: 'What to do about ${account['name'] ?? account['email']}',
                  itemBuilder: (_) => _menuFor(account),
                  onSelected: (action) => _act(action, account),
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (!confirmed)
              ErrorNote(
                pending
                    ? 'Waiting on the authenticator. This account cannot sign in '
                          'until a code confirms it.'
                    : 'No authenticator enrolled. This account cannot sign in.',
              )
            else
              Text(
                'Authenticator confirmed · ${account['recovery_code_count']} recovery '
                'codes left · last signed in '
                // Platform format, stated rather than defaulted: a staff
                // account belongs to no organization (§4.2), so no
                // organization's `date_format` governs when it last signed in.
                '${formatStamp(account['last_login_at'], 'never', formats: DisplayFormats.platform)}',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
          ],
        ),
      ),
    );
  }

  /// What this row can actually be told to do.
  ///
  /// Built as a list rather than as `if`s inside a literal, for one reason that
  /// a literal cannot express: a divider only belongs between two things. The
  /// literal had an unconditional `const PopupMenuDivider()` above the exemption
  /// item, so on your own row — where the two items above it are hidden — the
  /// menu opened with a rule across the top and nothing over it.
  List<PopupMenuEntry<String>> _menuFor(Map<String, dynamic> account) {
    final active = account['is_active'] as bool? ?? false;
    final exempt = account['audit_exempt'] as bool? ?? false;
    final pending = account['totp_pending'] as bool? ?? false;
    final isLead = account['tier'] == 'lead_developer';
    final isMe = account['id'] == widget.staff.me!.id;

    final items = <PopupMenuEntry<String>>[];

    void divide() {
      if (items.isNotEmpty) items.add(const PopupMenuDivider());
    }

    if (pending) {
      items.add(
        const PopupMenuItem(
          value: 'confirm',
          child: Text('Confirm authenticator'),
        ),
      );
    }

    // Never on your own row. This endpoint kills the live secret AND every
    // token in one request — on yourself that strands the account. "Move
    // authenticator" in the account menu is the self path, and it keeps the
    // current one working.
    if (!isMe) {
      items.add(
        const PopupMenuItem(
          value: 'reset',
          child: Text('Re-enrol authenticator'),
        ),
      );

      divide();

      items.add(
        PopupMenuItem(
          value: 'active',
          child: Text(active ? 'Disable account' : 'Enable account'),
        ),
      );
      items.add(
        PopupMenuItem(
          value: 'tier',
          child: Text(isLead ? 'Make Developer' : 'Make Lead Developer'),
        ),
      );
    }

    // Offered on your OWN row too, unlike the two above. Those are guarded
    // because locking yourself out of the control plane leaves the console on
    // the box as the only way back; nothing here can lock anybody out, and the
    // owner granting it to their own account is the case it exists for.
    //
    // Not offered on a Developer row, though, which it used to be: only a Lead
    // Developer may hold the exemption (§4.2), so on a Developer the item was a
    // control that existed only to be refused. Revoking one stays available
    // whatever the tier — an account that somehow holds it must always be able
    // to give it back.
    if (isLead || exempt) {
      divide();

      items.add(
        PopupMenuItem(
          value: 'exempt',
          child: Text(
            exempt
                ? 'Put back in the audit trail'
                : 'Exempt from the audit trail',
          ),
        ),
      );
    }

    return items;
  }

  Future<void> _act(String action, Map<String, dynamic> account) async {
    switch (action) {
      case 'confirm':
        await _confirmExisting(account);
      case 'reset':
        await _resetTotp(account);
      case 'active':
        await _patch(account, {
          'is_active': !(account['is_active'] as bool? ?? false),
        });
      case 'tier':
        await _patch(account, {
          'tier': account['tier'] == 'lead_developer'
              ? 'developer'
              : 'lead_developer',
        });
      case 'exempt':
        await _patch(account, {
          'audit_exempt': !(account['audit_exempt'] as bool? ?? false),
        });
    }
  }

  Future<void> _patch(
    Map<String, dynamic> account,
    Map<String, dynamic> body,
  ) async {
    final response = await widget.staff.api.patch(
      '/staff/accounts/${account['id']}',
      body,
    );

    if (!mounted) return;

    if (!response.ok) {
      // The server's refusals explain themselves ("this is the last active Lead
      // Developer"), so they are shown verbatim rather than reworded here.
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  // ── enrolment ────────────────────────────────────────────────

  Future<void> _create() async {
    final created = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => _NewStaffPanel(staff: widget.staff),
    );

    if (created == null || !mounted) return;

    final enrolment = created['enrolment'] as Map<String, dynamic>;
    final account = created['data'] as Map<String, dynamic>;

    await _showEnrolment(account, enrolment);
    if (mounted) _load();
  }

  Future<void> _resetTotp(Map<String, dynamic> account) async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Re-enrol ${account['name']}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Re-enrol'),
          ),
        ],
        child: const Text(
          'Their current authenticator stops working immediately and every '
          'session is ended. Use this when a phone is lost or suspect.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.staff.api.post(
      '/staff/accounts/${account['id']}/totp',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    await _showEnrolment(
      account,
      response.body['enrolment'] as Map<String, dynamic>,
    );
    if (mounted) _load();
  }

  /// Confirm a secret that was issued earlier — the panel was closed, or the
  /// person went away to find their phone.
  Future<void> _confirmExisting(Map<String, dynamic> account) async {
    final code = TextEditingController();

    final entered = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: 'Confirm ${account['name']}\'s authenticator',
        subtitle: 'Ask them to read out the six digits their app is showing.',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(code.text.trim()),
            child: const Text('Confirm'),
          ),
        ],
        child: TextField(
          controller: code,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Code'),
        ),
      ),
    );

    if (entered == null || entered.isEmpty || !mounted) return;

    final response = await widget.staff.api.post(
      '/staff/accounts/${account['id']}/totp/confirm',
      {'code': entered},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    // The codes are minted by this call and are never retrievable again. A
    // snackbar here would silently destroy them. The dialog this replaced also
    // refused to be dismissed; a panel always can be, so closing it early now
    // costs a re-enrolment — the panel's own words are what stop that.
    await showSidePanel<void>(
      context,
      builder: (_) => RecoveryCodesDialog(
        codes: (response.body['recovery_codes'] as List? ?? [])
            .map((c) => c as String)
            .toList(),
      ),
    );

    if (mounted) _load();
  }

  Future<void> _showEnrolment(
    Map<String, dynamic> account,
    Map<String, dynamic> enrolment,
  ) {
    return showSidePanel<bool>(
      context,
      builder: (_) => EnrolmentDialog(
        title: 'Set up ${account['name']}\'s authenticator',
        email: account['email'] as String,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        onConfirm: (code) => widget.staff.api.post(
          '/staff/accounts/${account['id']}/totp/confirm',
          {'code': code},
        ),
      ),
    );
  }
}

/// Collecting the details for a new account.
class _NewStaffPanel extends StatefulWidget {
  const _NewStaffPanel({required this.staff});

  final StaffSession staff;

  @override
  State<_NewStaffPanel> createState() => _NewStaffPanelState();
}

class _NewStaffPanelState extends State<_NewStaffPanel> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  String _tier = 'developer';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.staff.api.post('/staff/accounts', {
      'name': _name.text.trim(),
      'email': _email.text.trim(),
      'password': _password.text,
      'tier': _tier,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    Navigator.of(context).pop(response.body);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Add staff',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: const Text('Create'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
            keyboardType: TextInputType.emailAddress,
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _password,
            decoration: const InputDecoration(
              labelText: 'Password',
              // Longer than the tenant minimum, and the reason is worth
              // saying rather than leaving as an arbitrary-looking rule.
              helperText:
                  'At least 16 characters — this account reaches every customer',
            ),
            obscureText: true,
            enabled: !_busy,
          ),
          const SizedBox(height: 18),
          Text('Tier', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'developer', label: Text('Developer')),
              ButtonSegment(
                value: 'lead_developer',
                label: Text('Lead Developer'),
              ),
            ],
            selected: {_tier},
            onSelectionChanged: _busy
                ? null
                : (s) => setState(() => _tier = s.first),
          ),
          const SizedBox(height: 8),
          Text(
            _tier == 'lead_developer'
                ? 'Reaches every organization, may enter a client without their '
                      'approval, and may manage staff accounts. Client access is '
                      'always recorded. It is NOT exempt from the audit trail — '
                      'that is a separate setting held by one account, and this '
                      'form does not grant it.'
                : 'Platform work only. Reaching client records needs the client\'s '
                      'approval on each session.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
