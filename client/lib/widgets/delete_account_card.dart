import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'side_panel.dart';

/// Deleting your own account, from the account screen.
///
/// ── Why the control is always visible ────────────────────────────
///
/// A sole administrator cannot delete their account — §4 will not leave an
/// organization with nobody able to run it. The temptation is to hide the
/// button for them.
///
/// Hiding it is the wrong answer twice. It is the dead end Apple's
/// Guideline 5.1.1(v) actually objects to — a person who cannot find the
/// option concludes there isn't one. And it breaks the house rule the other
/// way round: that rule says do not draw an ENABLED control for something that
/// would be refused, and this is not refused, it is conditional on a thing the
/// person can change. So the control stays, and the server's sentence explains
/// what to do first.
///
/// The sentence is the server's, never a second copy here. The client asks
/// `/me/deletion` before it draws anything and shows what comes back.
///
/// ── A panel, not a dialog ────────────────────────────────────────
///
/// House rule: no modals, ever. `showSidePanel` is what the rest of this screen
/// uses for a destructive confirm — see the sign-out-everywhere flow directly
/// above this card in profile_screen.dart.
class DeleteAccountCard extends StatefulWidget {
  const DeleteAccountCard({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<DeleteAccountCard> createState() => _DeleteAccountCardState();
}

class _DeleteAccountCardState extends State<DeleteAccountCard> {
  bool _loading = true;
  bool _mayDelete = false;
  String? _blockedBecause;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/me/deletion');
    if (!mounted) return;

    setState(() {
      _loading = false;
      _mayDelete = response.ok && response.body['data']?['may_delete'] == true;
      _blockedBecause =
          response.ok
              ? (response.body['data']?['blocked_because'] as String?)
              : null;
    });
  }

  Future<void> _confirm() async {
    final password = TextEditingController();
    final typed = TextEditingController();

    final go = await showSidePanel<bool>(
      context,
      builder: (c) => StatefulBuilder(
        builder: (c, setPanel) => SidePanel(
          title: 'Delete your account?',
          actions: [
            TextButton(
              onPressed: () => Navigator.of(c).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              style: FilledButton.styleFrom(
                backgroundColor: Theme.of(c).colorScheme.error,
                foregroundColor: Theme.of(c).colorScheme.onError,
              ),
              // Enabled only once both are supplied. The server insists on the
              // same two, so a modified client gains nothing by skipping this.
              onPressed:
                  password.text.isNotEmpty && typed.text.trim().toUpperCase() == 'DELETE'
                      ? () => Navigator.of(c).pop(true)
                      : null,
              child: const Text('Delete my account'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'This cannot be undone. Your name, email and personal details are '
                'erased, and you are signed out on every device.',
              ),
              const SizedBox(height: 12),
              // Said plainly rather than discovered afterwards. The work itself
              // belongs to the organization, not to the person, and somebody
              // deleting their account should know it does not leave with them.
              const Text(
                'The jobs, customers and records you worked on stay with the '
                'organization. What was yours is removed from them.',
              ),
              const SizedBox(height: 20),
              TextField(
                controller: password,
                obscureText: true,
                onChanged: (_) => setPanel(() {}),
                decoration: const InputDecoration(
                  labelText: 'Your password',
                  helperText: 'Being signed in is not enough for this.',
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: typed,
                onChanged: (_) => setPanel(() {}),
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  labelText: 'Type DELETE to confirm',
                ),
              ),
            ],
          ),
        ),
      ),
    );

    if (go != true || !mounted) return;

    // deleteWithBody, not delete: DELETE with a body needs its own method here
    // because delete() takes a query string. The password cannot go in a query
    // - it would land in every access log between here and the server.
    final response = await widget.api.deleteWithBody(
      '/me',
      {'password': password.text, 'confirm': 'DELETE'},
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.message)),
      );
      // A refusal may be new information — a second administrator could have
      // stepped down since the card was drawn.
      _load();
      return;
    }

    // The account is gone, so there is nothing to return to. Signing out is the
    // only coherent next screen.
    widget.session.signOut();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Delete your account', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              'Erases your name, email and personal details, and signs you out '
              'everywhere. It cannot be undone.',
              style: theme.textTheme.bodyMedium,
            ),
            if (_blockedBecause != null) ...[
              const SizedBox(height: 12),
              // The server's sentence, verbatim. Never a second copy of the
              // rule — if §4 changes, this text changes with it.
              Text(
                _blockedBecause!,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
            const SizedBox(height: 16),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton(
                onPressed: _mayDelete ? _confirm : null,
                style: OutlinedButton.styleFrom(
                  foregroundColor: theme.colorScheme.error,
                  side: BorderSide(color: theme.colorScheme.error),
                ),
                child: const Text('Delete my account'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
