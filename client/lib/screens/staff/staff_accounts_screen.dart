import 'package:flutter/material.dart';
import '../../core/api_client.dart';
import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';
import '../../widgets/enrolment_dialog.dart';
import '../../widgets/error_note.dart';

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
  String? _error;
  List<Map<String, dynamic>> _accounts = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/accounts');

    if (!mounted) return;

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
          note: 'Accounts that work across every organization. Two factors are '
              'mandatory — an account without a confirmed authenticator cannot '
              'sign in at all.',
          action: FilledButton.icon(
            onPressed: _create,
            icon: const Icon(Icons.person_add_outlined, size: 18),
            label: const Text('Add staff'),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              itemCount: _accounts.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) => _row(context, _accounts[i]),
            ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> account) {
    final active = account['is_active'] as bool? ?? false;
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
                            child: Text(account['name'] as String,
                                overflow: TextOverflow.ellipsis,
                                style: Theme.of(context).textTheme.titleMedium),
                          ),
                          if (isMe) const Tag('You'),
                          if (!active) const Tag('Disabled', warn: true),
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
                  itemBuilder: (_) => [
                    if (pending)
                      const PopupMenuItem(value: 'confirm', child: Text('Confirm authenticator')),
                    const PopupMenuItem(value: 'reset', child: Text('Re-enrol authenticator')),
                    if (!isMe) const PopupMenuDivider(),
                    if (!isMe)
                      PopupMenuItem(
                        value: 'active',
                        child: Text(active ? 'Disable account' : 'Enable account'),
                      ),
                    if (!isMe)
                      PopupMenuItem(
                        value: 'tier',
                        child: Text(account['tier'] == 'lead_developer'
                            ? 'Make Developer'
                            : 'Make Lead Developer'),
                      ),
                  ],
                  onSelected: (action) => _act(action, account),
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (!confirmed)
              ErrorNote(pending
                  ? 'Waiting on the authenticator. This account cannot sign in '
                      'until a code confirms it.'
                  : 'No authenticator enrolled. This account cannot sign in.')
            else
              Text(
                'Authenticator confirmed · ${account['recovery_code_count']} recovery '
                'codes left · last signed in ${_stamp(account['last_login_at'])}',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _act(String action, Map<String, dynamic> account) async {
    switch (action) {
      case 'confirm':
        await _confirmExisting(account);
      case 'reset':
        await _resetTotp(account);
      case 'active':
        await _patch(account, {'is_active': !(account['is_active'] as bool? ?? false)});
      case 'tier':
        await _patch(account, {
          'tier': account['tier'] == 'lead_developer' ? 'developer' : 'lead_developer',
        });
    }
  }

  Future<void> _patch(Map<String, dynamic> account, Map<String, dynamic> body) async {
    final response =
        await widget.staff.api.patch('/staff/accounts/${account['id']}', body);

    if (!mounted) return;

    if (!response.ok) {
      // The server's refusals explain themselves ("this is the last active Lead
      // Developer"), so they are shown verbatim rather than reworded here.
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  // ── enrolment ────────────────────────────────────────────────

  Future<void> _create() async {
    final created = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _NewStaffDialog(staff: widget.staff),
    );

    if (created == null || !mounted) return;

    final enrolment = created['enrolment'] as Map<String, dynamic>;
    final account = created['data'] as Map<String, dynamic>;

    await _showEnrolment(account, enrolment);
    if (mounted) _load();
  }

  Future<void> _resetTotp(Map<String, dynamic> account) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Re-enrol ${account['name']}?'),
        content: const Text(
          'Their current authenticator stops working immediately and every '
          'session is ended. Use this when a phone is lost or suspect.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('Re-enrol')),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final response =
        await widget.staff.api.post('/staff/accounts/${account['id']}/totp', {});

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    await _showEnrolment(account, response.body['enrolment'] as Map<String, dynamic>);
    if (mounted) _load();
  }

  /// Confirm a secret that was issued earlier — the dialog was closed, or the
  /// person went away to find their phone.
  Future<void> _confirmExisting(Map<String, dynamic> account) async {
    final code = TextEditingController();

    final entered = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Confirm ${account['name']}\'s authenticator'),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('Ask them to read out the six digits their app is showing.'),
              const SizedBox(height: 16),
              TextField(
                controller: code,
                autofocus: true,
                decoration: const InputDecoration(labelText: 'Code'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(code.text.trim()),
            child: const Text('Confirm'),
          ),
        ],
      ),
    );

    if (entered == null || entered.isEmpty || !mounted) return;

    final response = await widget.staff.api
        .post('/staff/accounts/${account['id']}/totp/confirm', {'code': entered});

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(response.ok
          ? 'Confirmed. Hand over the recovery codes from the setup dialog.'
          : response.message),
    ));

    if (response.ok) _load();
  }

  Future<void> _showEnrolment(
      Map<String, dynamic> account, Map<String, dynamic> enrolment) {
    return showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => EnrolmentDialog(
        title: 'Set up ${account['name']}\'s authenticator',
        email: account['email'] as String,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        onConfirm: (code) => widget.staff.api
            .post('/staff/accounts/${account['id']}/totp/confirm', {'code': code}),
      ),
    );
  }

  String _stamp(dynamic iso) {
    if (iso is! String) return 'never';

    final at = DateTime.tryParse(iso)?.toLocal();
    if (at == null) return 'never';

    String two(int n) => n.toString().padLeft(2, '0');

    return '${at.year}-${two(at.month)}-${two(at.day)} ${two(at.hour)}:${two(at.minute)}';
  }
}

/// Collecting the details for a new account.
class _NewStaffDialog extends StatefulWidget {
  const _NewStaffDialog({required this.staff});

  final StaffSession staff;

  @override
  State<_NewStaffDialog> createState() => _NewStaffDialogState();
}

class _NewStaffDialogState extends State<_NewStaffDialog> {
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
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(response.body);
  }

  /// Laravel returns field errors under `errors`; the top-level message is the
  /// generic "the given data was invalid", which tells the reader nothing.
  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Add staff'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
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
                  helperText: 'At least 16 characters — this account reaches every customer',
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
                  ButtonSegment(value: 'lead_developer', label: Text('Lead Developer')),
                ],
                selected: {_tier},
                onSelectionChanged: _busy ? null : (s) => setState(() => _tier = s.first),
              ),
              const SizedBox(height: 8),
              Text(
                _tier == 'lead_developer'
                    ? 'Reaches every organization, may enter a client without their '
                        'approval, and is exempt from the ordinary audit trail. '
                        'Client access is still always recorded.'
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
        ),
      ),
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
    );
  }
}

