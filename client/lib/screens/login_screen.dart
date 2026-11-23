import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/ui_strings.dart';
import '../widgets/auth_preview.dart';
import '../widgets/auth_shell.dart';
import '../widgets/error_note.dart';
import '../widgets/side_panel.dart';
import '../widgets/get_the_apps.dart';

/// Sign in (plan §4).
///
/// Handles the three-outcome flow the server exposes: authenticated, failed, or
/// "choose an organization" when one address has accounts in several. The
/// picker only ever appears AFTER the password verified — the server enforces
/// that, and this screen simply never has the data before then.
class LoginScreen extends StatefulWidget {
  /// Jose 2026-11-23, "for now": the Create an account line confused staff
  /// arriving from HR invite links. Set true to bring it back. Signup itself,
  /// /invite and /join still work. Not const so a test can open the door.
  static bool showCreateAccount = false;

  const LoginScreen({
    super.key,
    required this.api,
    required this.session,
    required this.onCreateAccount,
  });

  final ApiClient api;
  final Session session;

  /// Opens the signup screen. Required rather than optional because this is the
  /// only way to reach it — there is no router, so an omitted callback would
  /// leave a screen that exists and cannot be opened.
  final VoidCallback onCreateAccount;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();

  // Its own key. The code step is a separate Form, and validating the first
  // one from the second would fail — that form is no longer mounted, so its
  // key has no state to validate.
  final _codeFormKey = GlobalKey<FormState>();

  /// The organization picked from the list, waiting on a code.
  Map<String, dynamic>? _pendingChoice;

  bool _showPassword = false;

  /// Whether the session should survive closing the browser.
  ///
  /// On by default. Jose, 2026-10-06: *"make the keep me logged in toggle when
  /// logging in activated by default instead of turned off."* Ticked, the token
  /// is kept in localStorage and the server mints a remembered session (no idle
  /// sign-out, thirty days at most — SessionLifetime). On a shared or public
  /// machine the box is still there to untick, and then the token lives in
  /// sessionStorage and dies with the tab.
  bool _stayed = true;
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
  void initState() {
    super.initState();
    _loadCopy();
  }

  Future<void> _loadCopy() async {
    final response = await widget.api.get('/ui-strings');
    if (!mounted || !response.ok) return;
    UiStrings.adopt(response.data as Map<String, dynamic>);
    setState(() {});
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit({bool skipValidation = false}) async {
    if (!skipValidation && !(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/auth/login', {
      'email': _email.text.trim(),
      'password': _password.text,
      if (_code.text.trim().isNotEmpty) 'code': _code.text.trim(),
      'device_name': 'web',
      'remember': _stayed,
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
        _choices = List<Map<String, dynamic>>.from(
          response.body['choices'] as List,
        );
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

    // Tells the browser the sign-in worked, so it offers to save it.
    TextInput.finishAutofillContext();
    await _adopt(response.body);
  }

  /// Shared by both paths. If /me fails after a successful login the screen is
  /// rebuilt in place with its State intact, so _busy must be released here or
  /// the card is left greyed out and spinning with nothing said.
  Future<void> _adopt(Map<String, dynamic> body) async {
    // The signup screen goes through the same Session method — its response body
    // is this one, by design — so the staff token cannot be handled two ways.
    final signedIn = await widget.session.adoptAuthResponse(
      body,
      persist: _stayed,
    );

    if (!mounted || signedIn) return;

    setState(() {
      _busy = false;
      _error = 'Signed in, but your account could not be loaded. Try again.';
      _choices = null;
    });
  }

  Future<void> _choose(Map<String, dynamic> choice) async {
    // This account is protected and no code has been given yet — ask for it
    // before spending a request that can only be refused. Reachable now because
    // the server offers the picker instead of silently signing somebody into
    // whichever of their accounts happened to need nothing.
    if (choice['needs_code'] == true && _code.text.trim().isEmpty) {
      setState(() {
        _pendingChoice = choice;
        _needsCode = true;
        _error = null;
      });
      return;
    }

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
      'remember': _stayed,
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

    await _adopt(response.body);
  }

  @override
  Widget build(BuildContext context) {
    return AuthShell(
      pill: 'Early access',
      // Lifted from ezbizi.com rather than invented here. Somebody arriving at
      // this screen has usually just read that page, and a sign-in that makes a
      // different promise in a different voice reads as a different company.
      //
      // The second sentence is the site's own differentiator, and it is the one
      // line on the page that says why this rather than any of the others.
      eyebrow: 'One platform, every location',
      headline: 'Run your entire business from one platform.',
      blurb:
          'Customers, scheduling, field work and billing on the same record. '
          "Most software assumes you run one site — EzBizi assumes you don't.",
      preview: const CustomerPreview(),
      footnote: 'Your data. Your organization. Nobody else can see it.',
      form: _choices != null
          ? _organizationPicker(context)
          : _needsCode
          ? _codeForm(context)
          : _loginForm(context),
    );
  }

  Widget _loginForm(BuildContext context) {
    // AutofillGroup is what lets the browser and password managers offer and
    // save the email + password together (Jose, 11-11: no autofill on web).
    return Form(
      key: _formKey,
      child: AutofillGroup(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // No wordmark here: the panel beside it already carries the brand,
            // and on a narrow screen AuthShell puts one at the top. Two on one
            // screen is a logo competing with itself.
            Text(
              'Welcome back',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 6),
            Text(
              // Two different people arrive at this screen and they are owed
              // different sentences. Somebody who pressed Sign out meant to be
              // here. Somebody whose session lapsed overnight did not, and "why
              // am I looking at a login screen" is their first thought — so the
              // subtitle answers it rather than making them wonder whether
              // something is broken.
              widget.session.expired
                  ? 'Your session ended, so you have been signed out. Sign in '
                        'again to pick up where you were.'
                  : UiStrings.t('shell.sign_in_prompt'),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 28),

            TextFormField(
              controller: _email,
              decoration: const InputDecoration(
                labelText: 'Email address',
                prefixIcon: Icon(Icons.alternate_email, size: 18),
              ),
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.username],
              enabled: !_busy,
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'Enter your email address'
                  : null,
              onFieldSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: 14),

            TextFormField(
              controller: _password,
              decoration: InputDecoration(
                labelText: 'Password',
                prefixIcon: const Icon(Icons.lock_outline, size: 18),
                suffixIcon: IconButton(
                  // Shown on request. A password field nobody can read is how
                  // people end up locked out by a keyboard layout they cannot see.
                  icon: Icon(
                    _showPassword
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                  ),
                  tooltip: _showPassword
                      ? 'Hide the password'
                      : 'Show the password',
                  onPressed: () =>
                      setState(() => _showPassword = !_showPassword),
                ),
              ),
              obscureText: !_showPassword,
              autofillHints: const [AutofillHints.password],
              enabled: !_busy,
              validator: (v) =>
                  (v == null || v.isEmpty) ? 'Enter your password' : null,
              onFieldSubmitted: (_) => _submit(),
            ),

            const SizedBox(height: 4),
            Row(
              children: [
                // A Checkbox carries a 48px tap target of its own, which pushed
                // the label out of line with the button opposite and wrapped it
                // onto two lines.
                Transform.translate(
                  offset: const Offset(-4, 0),
                  child: Checkbox(
                    value: _stayed,
                    visualDensity: VisualDensity.compact,
                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    onChanged: _busy
                        ? null
                        : (v) => setState(() => _stayed = v ?? false),
                  ),
                ),
                const SizedBox(width: 6),
                // Expanded, not Spacer + fixed Text. `softWrap: false` on a rigid
                // Text meant the row could not shrink at all and overflowed by
                // 110px the moment the panel was narrower than the label plus the
                // button. This fills the gap AND gives way when it has to.
                const Expanded(
                  child: Text(
                    'Keep me signed in',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: _busy ? null : _forgot,
                  child: const Text('Forgot password?'),
                ),
              ],
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
                  : Text(UiStrings.t('shell.sign_in')),
            ),

            // The only door into signup, and it is here because this is where
            // somebody without an account arrives — there is no marketing page in
            // the app to put it on. Hidden when the server has closed the front
            // door (no mailbox to verify an address). Invitations still work.
            if (LoginScreen.showCreateAccount && UiStrings.signupOpen) ...[
              const SizedBox(height: 4),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Flexible(
                    child: Text(
                      'New to EzBizi?',
                      style: Theme.of(context).textTheme.bodySmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  TextButton(
                    onPressed: _busy ? null : widget.onCreateAccount,
                    child: Text(UiStrings.t('shell.create_account')),
                  ),
                ],
              ),
            ],
            // On the web only: the apps for this device (UI queue 106).
            if (kIsWeb) ...[const SizedBox(height: 20), const GetTheAppRow()],
          ],
        ),
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
            child: Text(
              'Enter your authenticator code',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
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
            validator: (v) =>
                (v == null || v.trim().isEmpty) ? 'Enter the code' : null,
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
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : Text(UiStrings.t('shell.sign_in')),
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

    final pending = _pendingChoice;

    if (pending != null) {
      setState(() => _pendingChoice = null);
      await _choose(pending);
      return;
    }

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
      _pendingChoice = null;
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

    final asked = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: 'Reset your password',
        subtitle:
            'We will email you a link. It works once and expires in an hour.',
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
        child: TextField(
          controller: email,
          autofocus: true,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: 'Email'),
        ),
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
        Text(
          'Choose an organization',
          style: Theme.of(context).textTheme.titleMedium,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 6),
        Text(
          'This address is used by more than one organization.',
          style: Theme.of(context).textTheme.bodyMedium,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 20),

        ..._choices!.map(
          (choice) => Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: OutlinedButton(
              onPressed: _busy ? null : () => _choose(choice),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 18),
                alignment: Alignment.centerLeft,
              ),
              child: Row(
                children: [
                  Expanded(child: Text(choice['organization_name'] as String)),
                  // Marked, so choosing one and immediately being asked for a
                  // code is expected rather than a surprise.
                  if (choice['needs_code'] == true)
                    const Icon(Icons.lock_outline, size: 16),
                ],
              ),
            ),
          ),
        ),

        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],

        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _choices = null),
          child: const Text('Back'),
        ),
      ],
    );
  }
}
