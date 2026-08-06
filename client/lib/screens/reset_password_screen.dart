import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/theme.dart';
import '../widgets/error_note.dart';

/// The other end of "I forgot my password" (plan §4).
///
/// This did not exist. The reset email links to `/reset?token=…`, the client
/// read only the HOST to decide which face to show, and the path was discarded
/// — so everybody who clicked the link landed on the sign-in screen with their
/// token silently thrown away. The whole flow was built and shipped with no
/// ending, and nothing would have failed loudly enough to say so.
///
/// Stateless about identity on purpose: it takes a token from the URL and never
/// asks who you are. The server already knows, and asking would let somebody
/// with a token confirm which address it belongs to.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({
    super.key,
    required this.api,
    required this.token,
    required this.onDone,
  });

  final ApiClient api;
  final String token;

  /// Hands control back to the app, which drops the token from the address bar
  /// before showing the sign-in screen. A spent token left in the URL stays in
  /// history and gets pasted into support chats.
  final VoidCallback onDone;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _password = TextEditingController();
  final _confirm = TextEditingController();

  bool _busy = false;
  String? _error;
  bool _done = false;
  bool _needsSecondFactor = false;

  @override
  void dispose() {
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/auth/reset', {
      'token': widget.token,
      'password': _password.text,
      'password_confirmation': _confirm.text,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;

      if (!response.ok) {
        _error = _firstError(response) ?? response.message;
        return;
      }

      _done = true;
      _needsSecondFactor = response.body['needs_second_factor'] == true;
    });
  }

  /// The field-level message, which says what is actually wrong.
  ///
  /// The top-level one is generic; "The password must be at least 12
  /// characters" is under `errors.password` and is the only sentence worth
  /// showing somebody who is stuck.
  String? _firstError(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;

    if (errors == null || errors.isEmpty) return null;

    final first = errors.values.first;

    return first is List && first.isNotEmpty ? first.first as String : null;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: _done ? _success(context) : _form(context),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _form(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        EzBiziTheme.wordmark(context, size: 26),
        const SizedBox(height: 20),
        Text('Choose a new password',
            style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          'At least 12 characters. Every other session is signed out when you '
          'save it — the usual reason for a reset is that somebody else may '
          'know the old one.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _password,
          obscureText: true,
          autofocus: true,
          decoration: const InputDecoration(
            labelText: 'New password',
            border: OutlineInputBorder(),
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _confirm,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'Type it again',
            border: OutlineInputBorder(),
          ),
          onSubmitted: (_) => _canSubmit ? _submit() : null,
          onChanged: (_) => setState(() {}),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_error!),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _canSubmit ? _submit : null,
          child: Text(_busy ? 'Saving…' : 'Save the new password'),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: widget.onDone,
          child: const Text('Back to sign in'),
        ),
      ],
    );
  }

  bool get _canSubmit =>
      !_busy && _password.text.isNotEmpty && _confirm.text.isNotEmpty;

  Widget _success(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.check_circle_outline,
            size: 36, color: Theme.of(context).colorScheme.primary),
        const SizedBox(height: 12),
        Text('Password changed', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          _needsSecondFactor
              // Said plainly, because the most common assumption after a reset
              // is that it replaced everything. It did not — proving control of
              // a mailbox says nothing about the authenticator.
              ? 'You will still need your authenticator code to sign in. A reset '
                  'proves you can read your email; it does not replace your '
                  'second factor.'
              : 'Every other session has been signed out. Sign in with the new '
                  'password.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: widget.onDone,
          child: const Text('Go to sign in'),
        ),
      ],
    );
  }
}
