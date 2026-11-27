import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/password_policy.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/status_pill.dart';

/// The other end of an invite link (Jose, 2026-11-23: "an invite link in which
/// they can create their accounts on their own").
///
/// `/join?link=…`, read by `Deployment.joinLink()` and swapped in by main.dart
/// exactly as [InvitationScreen] is. The link names the role and place; the
/// person types who they are. Joining signs them in through the same
/// [Session.adoptAuthResponse] a sign-in uses, and the server sends a
/// confirmation email because nobody has proved the address yet.
class JoinLinkScreen extends StatefulWidget {
  const JoinLinkScreen({
    super.key,
    required this.api,
    required this.session,
    required this.link,
    required this.onDone,
  });

  final ApiClient api;
  final Session session;
  final String link;
  final VoidCallback onDone;

  @override
  State<JoinLinkScreen> createState() => _JoinLinkScreenState();
}

class _JoinLinkScreenState extends State<JoinLinkScreen> {
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();

  bool _loading = true;
  String? _loadError;
  Map<String, dynamic>? _about;
  bool _showPassword = false;
  bool _busy = false;
  bool _joined = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    for (final c in [_firstName, _lastName, _email, _password]) {
      c.addListener(() => setState(() {}));
    }
    _load();
  }

  @override
  void dispose() {
    for (final c in [_firstName, _lastName, _email, _phone, _password]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    // POST, the token in the body: nginx logs request lines.
    final response = await widget.api.post('/invite-links/lookup', {
      'link': widget.link,
    });
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _loadError = response.reason;
        return;
      }
      _about = Map<String, dynamic>.from(response.data as Map);
      if (_about!['open'] != true) {
        _loadError =
            _about!['closed_because'] as String? ??
            'This invite link can no longer be used.';
      }
    });
  }

  String get _passwordHint {
    final minimum = (_about?['password_min_length'] as num?)?.toInt();
    return minimum == null
        ? PasswordPolicy.hint
        : PasswordPolicy.hintFor(minimum);
  }

  bool get _canSubmit =>
      !_busy &&
      !_joined &&
      _firstName.text.trim().isNotEmpty &&
      _lastName.text.trim().isNotEmpty &&
      _email.text.trim().isNotEmpty &&
      _password.text.isNotEmpty;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/invite-links/join', {
      'link': widget.link,
      'first_name': _firstName.text.trim(),
      'last_name': _lastName.text.trim(),
      'email': _email.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': toE164(_phone.text),
      'password': _password.text,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }
    _joined = true;
    final signedIn = await widget.session.adoptAuthResponse(response.body);
    if (!mounted) return;
    if (!signedIn) {
      setState(() {
        _busy = false;
        _error =
            'Your account was created, but signing you in did not finish. '
            'Sign in with your email and the password you just chose.';
      });
      return;
    }
    widget.onDone();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: AsyncBody(
                  loading: _loading,
                  error: _loadError,
                  onRetry: () {
                    setState(() {
                      _loading = true;
                      _loadError = null;
                    });
                    _load();
                  },
                  child: _form(context),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    Key? key,
    TextInputType? keyboard,
    bool obscure = false,
    String? helper,
    Widget? suffix,
  }) => TextField(
    key: key,
    controller: controller,
    enabled: !_busy && !_joined,
    keyboardType: keyboard,
    obscureText: obscure,
    inputFormatters: keyboard == TextInputType.phone
        ? const [PhoneNumberInputFormatter()]
        : null,
    decoration: InputDecoration(
      labelText: label,
      border: const OutlineInputBorder(),
      helperText: helper,
      suffixIcon: suffix,
    ),
  );

  Widget _form(BuildContext context) {
    final about = _about ?? const <String, dynamic>{};
    final theme = Theme.of(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        EzBiziTheme.wordmark(context, size: 26),
        const SizedBox(height: 20),
        Text(
          'Join ${about['organization'] ?? 'this organization'}',
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        // Who they will be and where, as the link set it.
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (about['role'] != null)
              StatusPill('${about['role']}', tone: Tone.ok),
            if (about['place'] != null) StatusPill('${about['place']}'),
          ],
        ),
        const SizedBox(height: 20),
        Row(
          children: [
            Expanded(
              child: _field(
                _firstName,
                'First name',
                key: const ValueKey('join-first-name'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _field(
                _lastName,
                'Last name',
                key: const ValueKey('join-last-name'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        _field(
          _email,
          'Email',
          key: const ValueKey('join-email'),
          keyboard: TextInputType.emailAddress,
          helper: 'We will send a link to confirm it.',
        ),
        const SizedBox(height: 12),
        _field(
          _phone,
          'Phone',
          key: const ValueKey('join-phone'),
          keyboard: TextInputType.phone,
        ),
        const SizedBox(height: 12),
        _field(
          _password,
          'Choose a password',
          key: const ValueKey('join-password'),
          obscure: !_showPassword,
          helper: _passwordHint,
          suffix: IconButton(
            icon: Icon(_showPassword ? Icons.visibility_off : Icons.visibility),
            tooltip: _showPassword ? 'Hide the password' : 'Show the password',
            onPressed: () => setState(() => _showPassword = !_showPassword),
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 16), ErrorNote(_error!)],
        const SizedBox(height: 20),
        FilledButton(
          key: const ValueKey('join-submit'),
          onPressed: _joined
              ? (_busy ? null : widget.onDone)
              : (_canSubmit ? _submit : null),
          child: Text(
            _busy
                ? 'Setting you up…'
                : _joined
                ? 'Go to sign in'
                : 'Create my account',
          ),
        ),
      ],
    );
  }
}
