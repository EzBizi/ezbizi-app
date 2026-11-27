import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../core/staff_session.dart';
import '../../core/api_client.dart';
import '../../widgets/auth_preview.dart';
import '../../widgets/auth_shell.dart';
import '../../widgets/enrolment_dialog.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// Control-plane sign-in (plan §4.2).
///
/// Three fields, one request. The server takes password and code together
/// deliberately — a two-step flow needs an intermediate token that, on its own,
/// proves the password, and that token becomes the thing to steal.
class StaffLoginScreen extends StatefulWidget {
  const StaffLoginScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<StaffLoginScreen> createState() => _StaffLoginScreenState();
}

class _StaffLoginScreenState extends State<StaffLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.staff.api.post('/staff/auth/login', {
      'email': _email.text.trim(),
      'password': _password.text,
      'code': _code.text.trim(),
      'device_name': 'web',
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
        _code.clear();
      });
      return;
    }

    // adopt() returns whether /staff/me then answered. If it did not — a proxy
    // hiccup, a 500, a dropped connection — the token is discarded and this
    // screen is rebuilt in place with its State intact, so leaving _busy set
    // would strand the card with greyed-out fields and a spinner and no
    // explanation.
    final signedIn = await widget.staff.adopt(response.body['token'] as String);

    if (!mounted || signedIn) return;

    setState(() {
      _busy = false;
      _error = 'Signed in, but the session could not be loaded. Try again.';
      _code.clear();
    });
  }

  /// Bind an authenticator to an account that has never had one.
  ///
  /// Uses the email and password already typed above — the server refuses this
  /// entirely for an account that is already enrolled, so it cannot be used to
  /// take over a working one.
  Future<void> _setUpAuthenticator() async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(
        () => _error =
            'Enter your email and password first, then set up the code.',
      );
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final begin = await widget.staff.api.post('/staff/auth/enrolment', {
      'email': _email.text.trim(),
      'password': _password.text,
    });

    if (!mounted) return;

    setState(() => _busy = false);

    if (!begin.ok) {
      setState(() => _error = begin.message);
      return;
    }

    final enrolment = begin.body['enrolment'] as Map<String, dynamic>;
    final token = begin.body['token'] as String;

    // Its own client, carrying the fifteen-minute single-ability token. It must
    // not be set on the session's client — that one holds a real staff token
    // after sign-in, and this is deliberately not one.
    final enrolApi = ApiClient()..setToken(token);

    final done = await showSidePanel<bool>(
      context,
      builder: (_) => EnrolmentDialog(
        title: 'Set up your authenticator',
        note: EnrolmentDialog.lockYouOutNote,
        autofocus: true,
        codesOptional: true,
        email:
            (begin.body['account'] as Map<String, dynamic>)['email'] as String,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        intro: 'Your account needs an authenticator before it can sign in.',
        onConfirm: (code) =>
            enrolApi.post('/staff/auth/enrolment/confirm', {'code': code}),
      ),
    );

    if (done == true && mounted) {
      setState(() => _error = 'Set up. Enter a code from the app and sign in.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthShell(
      pill: 'Control plane',
      eyebrow: 'EzBizi platform',
      // Same construction the site uses throughout — "One account. Every
      // location.", "Every module, one record" — so the control plane reads as
      // the same product rather than an internal tool somebody bolted on.
      headline: 'One console. Every organization.',
      blurb:
          'Modules, access, subscriptions and health — for the whole '
          'platform at once.',
      preview: const StaffPreview(),
      // Said plainly on the way in, because it is true and because somebody who
      // reached this screen by accident should understand what it is.
      footnote: 'Every action here is audited, including this sign-in.',
      form: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // The panel beside this already says what this is.
            Text(
              'Staff sign-in',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 6),
            Text(
              'Your account, your password, and a code.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 24),

            TextFormField(
              controller: _email,
              decoration: const InputDecoration(
                labelText: 'Staff email',
                prefixIcon: Icon(Icons.alternate_email, size: 18),
              ),
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.username],
              enabled: !_busy,
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'Enter your email address'
                  : null,
            ),
            const SizedBox(height: 14),

            TextFormField(
              controller: _password,
              decoration: const InputDecoration(labelText: 'Password'),
              obscureText: true,
              autofillHints: const [AutofillHints.password],
              enabled: !_busy,
              validator: (v) =>
                  (v == null || v.isEmpty) ? 'Enter your password' : null,
            ),
            const SizedBox(height: 14),

            TextFormField(
              controller: _code,
              decoration: const InputDecoration(
                labelText: 'Authenticator code',
                helperText: 'Six digits, or a recovery code',
              ),
              keyboardType: TextInputType.text,
              autofillHints: const [AutofillHints.oneTimeCode],
              // Six digits or a recovery code (XXXXX-XXXXX); the
              // server decides which, so the field accepts both.
              inputFormatters: [LengthLimitingTextInputFormatter(20)],
              enabled: !_busy,
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Enter the code' : null,
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
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Text('Sign in'),
            ),
            const SizedBox(height: 4),
            // The bootstrap path. A staff account cannot sign in until
            // it has an authenticator, so it cannot enrol from inside —
            // this is how somebody sets one up without a console and
            // without anybody else being present.
            TextButton(
              onPressed: _busy ? null : _setUpAuthenticator,
              child: const Text('Set up my authenticator'),
            ),
            const SizedBox(height: 4),
            Center(
              child: Text(
                'Customers sign in at app.ezbizi.com.',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
