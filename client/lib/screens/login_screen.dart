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
  const LoginScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();

  // Its own key. The code step is a separate Form, and validating the first
  // one from the second would fail — that form is no longer mounted, so its
  // key has no state to validate.
  final _codeFormKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _busy = false;
  String? _error;

  /// Shown only after the server says this account needs it — which it only
  /// does for accounts linked to a staff record (§4.2, revised). Ordinary
  /// customers never see this field.
  bool _needsCode = false;

  /// Populated only when the server reports more than one matching account.
  List<Map<String, dynamic>>? _choices;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit({bool skipValidation = false}) async {
    if (!skipValidation && !(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/auth/login', {
      'email': _email.text.trim(),
      'password': _password.text,
      if (_code.text.trim().isNotEmpty) 'code': _code.text.trim(),
      'device_name': 'web',
    });

    if (!mounted) return;

    if (response.body['status'] == 'second_factor_required') {
      // The password was right; only the code is left. It becomes a SEPARATE
      // step rather than a third field appearing under the two the user just
      // filled in — a form that grows after you submit it reads as a rejection.
      //
      // The server's message is deliberately NOT shown. It explains that the
      // account also reaches the control plane, which is true and is nobody
      // else's business: the sign-in screen is the one page every customer
      // sees, and it should not advertise that a second system exists.
      setState(() {
        _busy = false;
        _needsCode = true;
        _error = null;
      });
      return;
    }

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

    await _adopt(
      response.body['token'] as String,
      staffToken: response.body['staff_token'] as String?,
    );
  }

  /// Shared by both paths. If /me fails after a successful login the screen is
  /// rebuilt in place with its State intact, so _busy must be released here or
  /// the card is left greyed out and spinning with nothing said.
  Future<void> _adopt(String token, {String? staffToken}) async {
    // Carried on the session so the shell can hand it to the Development
    // section's own client. It is deliberately not set on the tenant client.
    widget.session.staffToken = staffToken;

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
      if (_code.text.trim().isNotEmpty) 'code': _code.text.trim(),
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

    await _adopt(
      response.body['token'] as String,
      staffToken: response.body['staff_token'] as String?,
    );
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
                child: _choices != null
                    ? _organizationPicker(context)
                    : _needsCode
                        ? _codeForm(context)
                        : _loginForm(context),
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
            onPressed: _busy ? null : _forgot,
            child: const Text('I forgot my password'),
          ),
        ],
      ),
    );
  }

  /// Step two: the authenticator code, on its own.
  ///
  /// Nothing here names the control plane, or says why this account has a
  /// second factor. "Enter your code" is the whole instruction; anything more
  /// tells a stranger who guessed a password something useful about the
  /// account they guessed.
  Widget _codeForm(BuildContext context) {
    return Form(
      key: _codeFormKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(child: EzBiziTheme.wordmark(context, size: 32)),
          const SizedBox(height: 8),
          Center(
            child: Text('Enter your authenticator code',
                style: Theme.of(context).textTheme.bodyMedium),
          ),
          const SizedBox(height: 6),
          Center(
            child: Text(
              _email.text.trim(),
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          const SizedBox(height: 28),

          TextFormField(
            controller: _code,
            decoration: const InputDecoration(
              labelText: 'Authenticator code',
              helperText: 'Six digits, or a recovery code',
            ),
            autofocus: true,
            keyboardType: TextInputType.number,
            autofillHints: const [AutofillHints.oneTimeCode],
            enabled: !_busy,
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter the code' : null,
            onFieldSubmitted: (_) => _submitCode(),
          ),

          if (_error != null) ...[
            const SizedBox(height: 16),
            ErrorNote(_error!),
          ],

          const SizedBox(height: 24),
          FilledButton(
            onPressed: _busy ? null : _submitCode,
            child: _busy
                ? const SizedBox(
                    height: 18, width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Sign in'),
          ),
          const SizedBox(height: 4),
          TextButton(
            onPressed: _busy ? null : _back,
            child: const Text('Back'),
          ),
        ],
      ),
    );
  }

  /// Validates the code field only. The email and password are already known
  /// good — re-validating the first form here would fail, because that form is
  /// no longer mounted and its key has no state.
  Future<void> _submitCode() async {
    if (!(_codeFormKey.currentState?.validate() ?? false)) return;

    await _submit(skipValidation: true);
  }

  /// Back to the beginning, with the code cleared.
  ///
  /// The password stays: somebody who reached this step typed it correctly, and
  /// making them type it again to fix a mistyped digit is a punishment for the
  /// app's own choice to use two steps.
  void _back() {
    setState(() {
      _needsCode = false;
      _code.clear();
      _error = null;
    });
  }

  /// Ask for a reset link.
  ///
  /// The server answers identically whether or not the address is registered,
  /// so this screen must too — showing "no such account" would undo the whole
  /// point of that.
  Future<void> _forgot() async {
    final email = TextEditingController(text: _email.text.trim());

    final asked = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Reset your password'),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('We will email you a link. It works once and expires in an hour.'),
              const SizedBox(height: 16),
              TextField(
                controller: email,
                autofocus: true,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(email.text.trim()),
            child: const Text('Send link'),
          ),
        ],
      ),
    );

    email.dispose();

    if (asked == null || asked.isEmpty || !mounted) return;

    final response = await widget.api.post('/auth/forgot', {'email': asked});

    if (!mounted) return;

    // Shown whatever came back, for the same reason.
    setState(() => _error = response.message);
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

