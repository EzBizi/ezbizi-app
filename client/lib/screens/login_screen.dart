import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/error_note.dart';

/// Sign in (plan §4).
///
/// Handles the three-outcome flow the server exposes: authenticated, failed, or
/// "choose an organization" when one address has accounts in several. The
/// picker only ever appears AFTER the password verified — the server enforces
/// that, and this screen simply never has the data before then.
class LoginScreen extends StatefulWidget {
  const LoginScreen({
    super.key,
    required this.api,
    required this.session,
    required this.onStaffSignIn,
  });

  final ApiClient api;
  final Session session;

  /// Switches the card to the control-plane form (§4.2).
  ///
  /// Visible rather than hidden behind a secret path: obscurity is not what
  /// protects this — mandatory TOTP is. A hidden URL only means the person who
  /// needs it has to remember it.
  final VoidCallback onStaffSignIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _busy = false;
  String? _error;

  /// Populated only when the server reports more than one matching account.
  List<Map<String, dynamic>>? _choices;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/auth/login', {
      'email': _email.text.trim(),
      'password': _password.text,
      'device_name': 'web',
    });

    if (!mounted) return;

    if (response.body['status'] == 'choose_organization') {
      setState(() {
        _busy = false;
        _choices = List<Map<String, dynamic>>.from(response.body['choices'] as List);
      });
      return;
    }

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    await _adopt(response.body['token'] as String);
  }

  /// Shared by both paths. If /me fails after a successful login the screen is
  /// rebuilt in place with its State intact, so _busy must be released here or
  /// the card is left greyed out and spinning with nothing said.
  Future<void> _adopt(String token) async {
    final signedIn = await widget.session.adopt(token);

    if (!mounted || signedIn) return;

    setState(() {
      _busy = false;
      _error = 'Signed in, but your account could not be loaded. Try again.';
      _choices = null;
    });
  }

  Future<void> _choose(Map<String, dynamic> choice) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/auth/choose', {
      'user_id': choice['user_id'],
      'email': _email.text.trim(),
      'password': _password.text,
      'device_name': 'web',
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
        _choices = null;
      });
      return;
    }

    await _adopt(response.body['token'] as String);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: _choices == null ? _loginForm(context) : _organizationPicker(context),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _loginForm(BuildContext context) {
    return Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(child: EzBiziTheme.wordmark(context, size: 32)),
          const SizedBox(height: 8),
          Center(
            child: Text('Sign in to your account',
                style: Theme.of(context).textTheme.bodyMedium),
          ),
          const SizedBox(height: 28),

          TextFormField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.username],
            enabled: !_busy,
            validator: (v) =>
                (v == null || !v.contains('@')) ? 'Enter your email address' : null,
            onFieldSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 14),

          TextFormField(
            controller: _password,
            decoration: const InputDecoration(labelText: 'Password'),
            obscureText: true,
            autofillHints: const [AutofillHints.password],
            enabled: !_busy,
            validator: (v) => (v == null || v.isEmpty) ? 'Enter your password' : null,
            onFieldSubmitted: (_) => _submit(),
          ),

          if (_error != null) ...[
            const SizedBox(height: 16),
            ErrorNote(_error!),
          ],

          const SizedBox(height: 24),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    height: 18, width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Sign in'),
          ),
          const SizedBox(height: 4),
          TextButton(
            onPressed: _busy ? null : widget.onStaffSignIn,
            child: const Text('EzBizi staff'),
          ),
        ],
      ),
    );
  }

  /// Shown only when the password matched accounts in several organizations.
  Widget _organizationPicker(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(child: EzBiziTheme.wordmark(context, size: 28)),
        const SizedBox(height: 20),
        Text('Choose an organization',
            style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
        const SizedBox(height: 6),
        Text(
          'This address is used by more than one organization.',
          style: Theme.of(context).textTheme.bodyMedium,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 20),

        ..._choices!.map((choice) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: OutlinedButton(
                onPressed: _busy ? null : () => _choose(choice),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 18),
                  alignment: Alignment.centerLeft,
                ),
                child: Text(choice['organization_name'] as String),
              ),
            )),

        if (_error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_error!),
        ],

        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _choices = null),
          child: const Text('Back'),
        ),
      ],
    );
  }
}

