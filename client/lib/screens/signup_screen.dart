import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/password_policy.dart';
import '../widgets/auth_preview.dart';
import '../widgets/auth_shell.dart';
import '../widgets/error_note.dart';

/// Create an account and the business behind it (plan §3, §13).
///
/// ── Why one form and not a wizard ────────────────────────────────
///
/// §3 forbids a company without a franchise. A resumable multi-step flow would
/// let somebody abandon halfway and leave behind exactly the state that rule
/// prohibits, so everything is collected before anything is created and the
/// server writes the whole tree — organization, administrator, first company,
/// first franchise — in one transaction or not at all.
/// company_form_panel.dart asks its two questions together for the same reason,
/// and this screen borrows its wording for them.
///
/// ── Customer face only ───────────────────────────────────────────
///
/// Reached from the sign-in screen on the customer host, and nowhere else. The
/// control plane does not offer it and StaffLoginScreen does not link to it:
/// §4.2 models platform staff as a separate control plane with its own accounts,
/// which staff create.
///
/// ── One response shape ───────────────────────────────────────────
///
/// A successful signup answers with the same body as a successful login, so it
/// is handed to the same Session method a login goes through. There is no second
/// way into the app.
class SignupScreen extends StatefulWidget {
  const SignupScreen({
    super.key,
    required this.api,
    required this.session,
    required this.onSignIn,
  });

  final ApiClient api;
  final Session session;

  /// Back to the sign-in screen, for somebody who already has an account. There
  /// is no router, so the swap happens where the reset screen's does — main.dart.
  final VoidCallback onSignIn;

  @override
  State<SignupScreen> createState() => _SignupScreenState();
}

class _SignupScreenState extends State<SignupScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _business = TextEditingController();
  final _location = TextEditingController();

  bool _showPassword = false;
  bool _busy = false;

  /// Latched the moment the server answers 201, and never released.
  ///
  /// The organization exists from that point. Nothing that goes wrong afterwards
  /// is a reason to offer the button again — a second submit would build a
  /// second organization with the same name and the same administrator email,
  /// and the first one would be the orphan.
  bool _created = false;

  /// The general failure, in the server's own words.
  String? _error;

  /// Field-level failures, keyed by the wire name so a 422 lands under the box
  /// it is about instead of as one long sentence above the form.
  Map<String, String> _fieldErrors = const {};

  /// The keys this form can place. Anything else the server rejects is still
  /// shown, just at the top — silently dropping it would leave somebody staring
  /// at a form that refuses to submit for no stated reason.
  static const _placeable = {
    'name',
    'email',
    'password',
    'business_name',
    'first_location',
  };

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _business.dispose();
    _location.dispose();
    super.dispose();
  }

  /// Every field is required by the contract, so the button waits for all five.
  ///
  /// Shape is left to the server: it owns the password minimum and the email
  /// rule, and a second copy of either here is a copy that drifts. What this
  /// prevents is the pointless round trip, not the invalid one.
  bool get _canSubmit =>
      !_busy &&
      !_created &&
      _name.text.trim().isNotEmpty &&
      _email.text.trim().isNotEmpty &&
      _password.text.isNotEmpty &&
      _business.text.trim().isNotEmpty &&
      _location.text.trim().isNotEmpty;

  Future<void> _submit() async {
    if (!_canSubmit) return;

    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });

    final response = await widget.api.post('/auth/signup', {
      'name': _name.text.trim(),
      'email': _email.text.trim(),
      'password': _password.text,
      'business_name': _business.text.trim(),
      'first_location': _location.text.trim(),
      'device_name': 'web',
    });

    if (!mounted) return;

    if (!response.ok) {
      _showFailure(response);
      return;
    }

    setState(() => _created = true);

    // The same path a login takes. Not persisted, matching the sign-in screen's
    // default: the token lives in sessionStorage and dies with the tab, which is
    // the right answer on a machine somebody else also uses. "Keep me signed in"
    // is a choice offered where signing in happens.
    final signedIn = await widget.session.adoptAuthResponse(response.body);

    // Signing in replaces this screen with the app, so there is nothing to do on
    // the way out.
    if (!mounted || signedIn) return;

    // 201 came back but /me did not — a blip, a 500, a proxy. The account is
    // real either way, and saying so is what stops somebody trying again.
    setState(() {
      _busy = false;
      _error = 'Your account was created, but it could not be opened. '
          'Sign in with the email address and password you just chose.';
    });
  }

  /// Show what the server said.
  ///
  /// Whatever came back is shown, always. The sign-in screen discards the
  /// server's message on its second-factor path for a specific reason that does
  /// not apply here, and nothing on this screen may inherit the habit: a 429
  /// arrives carrying the only number worth reading — how long until they can
  /// try again — and replacing it with wording of our own throws that away.
  void _showFailure(ApiResponse response) {
    final reported = response.body['errors'];

    final placed = <String, String>{};
    final unplaced = <String>[];

    if (reported is Map<String, dynamic>) {
      reported.forEach((field, messages) {
        // Laravel sends a list per field; the first is the rule that failed
        // first and the one worth acting on. A field with no message at all is
        // skipped rather than rendered — "[]" under a box is worse than nothing,
        // and the top-level message below still explains the refusal.
        final message = switch (messages) {
          List m when m.isNotEmpty => m.first.toString(),
          String s => s,
          _ => null,
        };

        if (message == null || message.isEmpty) return;

        if (_placeable.contains(field)) {
          placed[field] = message;
        } else {
          unplaced.add(message);
        }
      });
    }

    setState(() {
      _busy = false;
      _fieldErrors = placed;

      // The top-level message only when nothing was placed under a field. On a
      // 422 it is usually the first field error repeated, and saying the same
      // sentence twice on one screen reads as two separate problems.
      _error = unplaced.isNotEmpty
          ? unplaced.join('\n')
          : (placed.isEmpty ? response.message : null);
    });
  }

  @override
  Widget build(BuildContext context) {
    // The same identity panel as the sign-in screen, deliberately unchanged.
    // Somebody moving between the two links is moving between two screens of one
    // product, and a different pitch on each reads as a different company.
    return AuthShell(
      pill: 'Early access',
      eyebrow: 'One platform, every location',
      headline: 'Run your entire business from one platform.',
      blurb: 'Customers, scheduling, field work and billing on the same record. '
          "Most software assumes you run one site — EzBizi assumes you don't.",
      preview: const CustomerPreview(),
      footnote: 'Your data. Your organization. Nobody else can see it.',
      form: _form(context),
    );
  }

  Widget _form(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // No wordmark: the panel beside it carries the brand, and on a narrow
        // screen AuthShell puts one at the top itself.
        Text('Create your account', style: text.headlineSmall),
        const SizedBox(height: 6),
        Text(
          'Two things about you, three about the business. Everything else can '
          'wait until you are inside.',
          style: text.bodyMedium,
        ),

        // Who they are first. It is the part somebody can answer without
        // thinking, and it is the account they will sign in with tomorrow.
        const SizedBox(height: 26),
        _heading(context, 'You'),
        const SizedBox(height: 12),

        _field(
          controller: _name,
          field: 'name',
          label: 'Your name',
          icon: Icons.person_outline,
          hints: const [AutofillHints.name],
          capitalize: TextCapitalization.words,
        ),
        const SizedBox(height: 14),

        _field(
          controller: _email,
          field: 'email',
          label: 'Email address',
          icon: Icons.alternate_email,
          keyboard: TextInputType.emailAddress,
          hints: const [AutofillHints.email],
        ),
        const SizedBox(height: 14),

        _field(
          controller: _password,
          field: 'password',
          label: 'Password',
          icon: Icons.lock_outline,
          // The tenant minimum the server enforces, said before it is enforced.
          // The rule itself stays on the server — this is the sentence, not a
          // second copy of the check.
          helper: PasswordPolicy.hint,
          obscure: !_showPassword,
          hints: const [AutofillHints.newPassword],
          suffix: IconButton(
            // Shown on request, as on the sign-in screen. A password nobody can
            // read is how somebody locks themselves out of an account they are
            // creating this minute.
            icon: Icon(_showPassword
                ? Icons.visibility_off_outlined
                : Icons.visibility_outlined),
            onPressed: () => setState(() => _showPassword = !_showPassword),
          ),
        ),

        const SizedBox(height: 26),
        _heading(context, 'Your business'),
        const SizedBox(height: 12),

        _field(
          controller: _business,
          field: 'business_name',
          label: 'Business name',
          icon: Icons.business_outlined,
          // What the server does with it, in a sentence that does not need the
          // word "organization" explained first. You can add more companies
          // later — the organization screen offers it.
          helper: 'The name your customers know you by. If the business trades '
              'under more than one name, you can add the others later.',
          capitalize: TextCapitalization.words,
        ),
        const SizedBox(height: 18),

        _field(
          controller: _location,
          field: 'first_location',
          // The same words the company form uses for the same idea, so the
          // question does not change shape between signing up and adding the
          // second one a month later.
          label: 'Its first place',
          icon: Icons.place_outlined,
          helper: 'Where work happens — a state, a city, a region. A business '
              'must have at least one; you can add more later.',
          capitalize: TextCapitalization.words,
        ),

        if (_error != null) ...[
          const SizedBox(height: 18),
          ErrorNote(_error!),
        ],

        const SizedBox(height: 24),
        FilledButton(
          // Disabled while the request is in flight AND for good once it has
          // succeeded, so a double click cannot build two organizations.
          onPressed: _canSubmit ? _submit : null,
          child: _busy
              ? const SizedBox(
                  height: 18,
                  width: 18,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: Colors.white))
              : const Text('Create account'),
        ),

        const SizedBox(height: 4),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Flexible(
              child: Text('Already have an account?',
                  style: text.bodySmall, overflow: TextOverflow.ellipsis),
            ),
            TextButton(
              // Available even after the account exists. If /me failed above,
              // this is the way in.
              onPressed: _busy ? null : widget.onSignIn,
              child: const Text('Sign in'),
            ),
          ],
        ),
      ],
    );
  }

  Widget _heading(BuildContext context, String label) => Text(
        label.toUpperCase(),
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
              letterSpacing: 1.2,
              fontWeight: FontWeight.w700,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
      );

  Widget _field({
    required TextEditingController controller,
    required String field,
    required String label,
    required IconData icon,
    String? helper,
    bool obscure = false,
    Widget? suffix,
    TextInputType? keyboard,
    List<String>? hints,
    TextCapitalization capitalize = TextCapitalization.none,
  }) {
    // A TextField rather than a TextFormField with a validator: the only rules
    // that matter belong to the server, and errorText puts its answer under the
    // right box without a second rulebook here to keep in step.
    return TextField(
      controller: controller,
      obscureText: obscure,
      keyboardType: keyboard,
      autofillHints: hints,
      textCapitalization: capitalize,
      enabled: !_busy && !_created,
      decoration: InputDecoration(
        labelText: label,
        helperText: helper,
        helperMaxLines: 3,
        errorText: _fieldErrors[field],
        errorMaxLines: 3,
        prefixIcon: Icon(icon, size: 18),
        suffixIcon: suffix,
      ),
      onChanged: (_) => setState(() {
        // Cleared as they fix it. Leaving "has already been taken" under an
        // address they have since changed is the form arguing with itself.
        if (_fieldErrors.containsKey(field)) {
          _fieldErrors = {..._fieldErrors}..remove(field);
        }
      }),
      onSubmitted: (_) => _canSubmit ? _submit() : null,
    );
  }
}
