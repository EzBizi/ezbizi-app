import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/appearance_card.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/password_card.dart';
import '../widgets/two_factor_card.dart';

/// Your own account (plan §2.1 category 6).
///
/// Everything here belongs to the PERSON. Settings, next door, belongs to the
/// organization and is set by an administrator. They were one screen, which
/// put somebody's dark-mode choice directly above the organization's job
/// statuses and implied that whoever could change one could change the other.
/// §2.1 says the categories must not be merged; the shape of the app should say
/// so before anybody reads a word.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _name = TextEditingController();

  bool _loading = true;
  String? _error;
  String? _nameError;
  bool _savingName = false;
  List<Map<String, dynamic>> _sessions = [];

  @override
  void initState() {
    super.initState();
    _name.text = widget.session.me?.name ?? '';
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/me/sessions');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _sessions = response.ok
          ? (response.body['sessions'] as List<dynamic>).cast<Map<String, dynamic>>()
          : [];
    });
  }

  Future<void> _saveName() async {
    setState(() {
      _savingName = true;
      _nameError = null;
    });

    final response = await widget.api.patch('/me', {'name': _name.text.trim()});

    if (!mounted) return;

    setState(() {
      _savingName = false;
      _nameError = response.ok ? null : response.message;
    });

    if (response.ok) {
      // Refreshed rather than patched locally: the app bar, the avatar initial
      // and the audit trail all read this, and a client-side copy that drifts
      // from the server is how one of them ends up showing the old name.
      await widget.session.refresh();

      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Name updated.')));
      }
    }
  }

  Future<void> _revokeOthers() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Sign out everywhere else?'),
        content: const Text(
          'Every other browser and device is signed out immediately. This one '
          'stays signed in.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(c).pop(false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.of(c).pop(true),
              child: const Text('Sign them out')),
        ],
      ),
    );

    if (confirmed != true) return;

    final response = await widget.api.delete('/me/sessions');

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(response.ok
          ? 'Signed out of ${response.body['revoked']} other session(s).'
          : response.message),
    ));

    if (response.ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text('Your account', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 4),
          Text(
            'Yours, not the organization\'s. What an administrator controls is '
            'in Settings.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),

          _identity(me),
          const SizedBox(height: 12),

          AppearanceCard(session: widget.session),
          const SizedBox(height: 12),
          TwoFactorCard(api: widget.api, session: widget.session),
          const SizedBox(height: 12),
          PasswordCard(api: widget.api, session: widget.session),
          const SizedBox(height: 12),
          _sessionsCard(),
        ],
      ),
    );
  }

  Widget _identity(Me me) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Who you are',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextField(
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'Your name',
                      isDense: true,
                      border: OutlineInputBorder(),
                      helperText: 'What everyone else sees against your work.',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
                const SizedBox(width: 8),
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: FilledButton.tonal(
                    onPressed: _savingName ||
                            _name.text.trim().isEmpty ||
                            _name.text.trim() == me.name
                        ? null
                        : _saveName,
                    child: Text(_savingName ? 'Saving…' : 'Save'),
                  ),
                ),
              ],
            ),
            if (_nameError != null) ...[
              const SizedBox(height: 8),
              ErrorNote(_nameError!),
            ],
            const SizedBox(height: 16),
            _row(context, 'Email', me.email,
                // Not editable, and the reason is worth saying rather than
                // leaving as a greyed-out field somebody keeps clicking.
                note: 'Your sign-in address and where a password reset goes. '
                    'Changing it needs a confirmation email, which is not '
                    'wired up yet — ask an administrator.'),
            if (me.roleName != null) _row(context, 'Role', me.roleName!),
            _row(context, 'Organization', me.organization.name),
            if (me.franchises.isNotEmpty)
              _row(context, 'You can act on',
                  me.franchises.map((f) => f.name).join(', '),
                  note: 'Permissions decide WHAT you may do; this decides '
                      'WHERE. Both have to allow something before it happens.'),
          ],
        ),
      ),
    );
  }

  Widget _row(BuildContext context, String label, String value, {String? note}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 130,
                child: Text(label,
                    style: Theme.of(context).textTheme.bodySmall),
              ),
              Expanded(child: Text(value)),
            ],
          ),
          if (note != null)
            Padding(
              padding: const EdgeInsets.only(left: 130, top: 2),
              child:
                  Text(note, style: Theme.of(context).textTheme.bodySmall),
            ),
        ],
      ),
    );
  }

  Widget _sessionsCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Where you are signed in',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              // The honest description. A token list is not a device list, and
              // calling it one would have people looking for a phone model.
              'One entry per sign-in. A browser you closed without signing out '
              'is still here until it expires.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final s in _sessions)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Icon(
                      s['current'] == true
                          ? Icons.radio_button_checked
                          : Icons.radio_button_unchecked,
                      size: 16,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(s['current'] == true
                          ? 'This browser'
                          : (s['name'] as String? ?? 'Session')),
                    ),
                    Text(
                      _lastUsed(s['last_used_at'] as String?),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _sessions.length < 2 ? null : _revokeOthers,
              icon: const Icon(Icons.logout),
              label: const Text('Sign out everywhere else'),
            ),
          ],
        ),
      ),
    );
  }

  String _lastUsed(String? iso) {
    if (iso == null) return 'Never used';

    final at = DateTime.tryParse(iso)?.toLocal();

    if (at == null) return '';

    final ago = DateTime.now().difference(at);

    if (ago.inMinutes < 1) return 'Just now';
    if (ago.inHours < 1) return '${ago.inMinutes} min ago';
    if (ago.inDays < 1) return '${ago.inHours} h ago';

    return '${ago.inDays} d ago';
  }
}
