import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/password_policy.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/error_note.dart';

/// The other end of an invitation (HR plan, "Invitations").
///
/// ── Reached the same way the reset screen is ─────────────────────
///
/// `/invite?token=…`, read out of the URL by `Deployment.inviteToken()` and
/// swapped in by main.dart. There is no router in this app, and the reset flow
/// is the precedent: it was built end to end on the server, the client decided
/// what to draw from the HOST alone, and everybody who followed the link landed
/// on the sign-in screen with their token silently discarded. Following that
/// shape exactly is what stops this one repeating it.
///
/// ── Customer face only ───────────────────────────────────────────
///
/// §4.2 gives platform staff their own accounts in their own control plane,
/// created by staff. Nobody is invited into it, so main.dart offers this screen
/// on the customer face and nowhere else.
///
/// ── What the GET is allowed to say ───────────────────────────────
///
/// Anybody holding the link can read it, including somebody it was not sent to,
/// so it answers the four things needed to draw this page — the organization,
/// the role, who invited them, and whichever core fields the inviter filled in —
/// and nothing else about the organization. In particular it does not name the
/// place they are being assigned to: that is the shape of somebody's business,
/// and a token in a forwarded email should not be a way to read it.
///
/// ── Accepting IS signing in ──────────────────────────────────────
///
/// The POST answers with exactly what `/auth/login` answers with, so it goes
/// through the same [Session.adoptAuthResponse] a sign-in goes through. There
/// is no second way into the app — the same argument the signup screen makes.
class InvitationScreen extends StatefulWidget {
  const InvitationScreen({
    super.key,
    required this.api,
    required this.session,
    required this.token,
    required this.onDone,
  });

  final ApiClient api;
  final Session session;
  final String token;

  /// Hands control back to the app, which drops the token from the address bar.
  /// A spent single-use token left in the URL stays in history and gets pasted
  /// into support chats — the same reason the reset screen takes this callback.
  final VoidCallback onDone;

  @override
  State<InvitationScreen> createState() => _InvitationScreenState();
}

class _InvitationScreenState extends State<InvitationScreen> {
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _phone = TextEditingController();
  final _dateOfBirth = TextEditingController();
  final _password = TextEditingController();

  String? _gender;

  bool _loading = true;

  /// Why the invitation cannot be shown — expired, revoked, already used, or a
  /// link that was never one. All of them are the server's sentence, verbatim:
  /// "this link has already been used" and "this link has expired" need
  /// completely different things done about them.
  String? _loadError;

  /// What this invitation is for. Null until the GET lands.
  Map<String, dynamic>? _invitation;

  bool _showPassword = false;
  bool _busy = false;
  String? _error;

  /// Latched the moment the server accepts, and never released. The account
  /// exists from that point and the token is spent; offering the button again
  /// would only produce a second failure over a success.
  bool _accepted = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _firstName.dispose();
    _lastName.dispose();
    _phone.dispose();
    _dateOfBirth.dispose();
    _password.dispose();
    super.dispose();
  }

  /// Reading the invitation is also what marks it *received* — the owner's
  /// status for "the link was opened". So this is a GET with a side effect on
  /// purpose, and it happens once, here, rather than on submit: an invitation
  /// somebody opened and abandoned is exactly the row the Invitations tab needs
  /// to be able to show.
  Future<void> _load() async {
    // POST for a read, and the token in the BODY. nginx logs request lines, so
    // a token in the path was a live credential written to the access log — and
    // sent again as a Referer by this very call. The reset flow already does it
    // this way for the same reason.
    final response = await widget.api.post('/invitations/lookup', {
      'token': widget.token,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _loadError = response.reason;
        return;
      }

      final invitation = Map<String, dynamic>.from(response.data as Map);
      _invitation = invitation;

      // The two the invitation itself carries, pre-filled and NOT fixed. The
      // employer may have typed a name and got it wrong, and correcting it here
      // should be ordinary rather than a support request — the HR plan's
      // argument for `filled_by: either`. The rest of the common core is asked
      // for the first time on this page.
      _firstName.text = invitation['first_name'] as String? ?? '';
      _lastName.text = invitation['last_name'] as String? ?? '';
    });
  }

  /// What THIS organization asks of a password (§6).
  ///
  /// Resolved by the server, because only the server knows the setting — and
  /// reachable here, unlike on the reset screen, because the token has already
  /// said which organization this is. Falling back to the platform floor is for
  /// a server older than this build: it is the lowest number that can ever be
  /// correct, so the worst case is one refused password carrying the real
  /// requirement back.
  String get _passwordHint {
    final minimum = (_invitation?['password_min_length'] as num?)?.toInt();

    return minimum == null
        ? PasswordPolicy.hint
        : PasswordPolicy.hintFor(minimum);
  }

  /// How this organization writes a date, for the date-of-birth boxes (§6).
  ///
  /// Same `display` block /me will send after they join. A missing block is a
  /// server older than this build: month-first rather than ISO, because the
  /// three boxes are named and month-first is the registry default — falling
  /// back to YYYY-MM-DD here would put Year first on a form that never asked
  /// for that.
  DisplayFormats get _formats {
    final raw = _invitation?['display'];
    if (raw is! Map) {
      return const DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
    }

    return DisplayFormats.fromJson(raw);
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/invitations/accept', {
      'token': widget.token,
      'password': _password.text,
      if (_firstName.text.trim().isNotEmpty) 'first_name': _firstName.text.trim(),
      if (_lastName.text.trim().isNotEmpty) 'last_name': _lastName.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      if (_dateOfBirth.text.trim().isNotEmpty)
        'date_of_birth': _dateOfBirth.text.trim(),
      if (_gender != null) 'gender': _gender,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    // Latched before the session is adopted, because the account exists from
    // this line onwards whatever happens next.
    _accepted = true;

    // The same body a sign-in returns, through the same door. Awaited before
    // handing control back, because onDone drops this screen and the app then
    // renders whichever branch the session is in.
    final signedIn = await widget.session.adoptAuthResponse(response.body);

    if (!mounted) return;

    // The token was issued and `/me` did not answer — a blip, or a 500. The
    // account is real and the invitation is spent, so reloading this page shows
    // a dead link and nothing explains what happened. One sentence, and the
    // button below becomes the way out.
    if (!signedIn) {
      setState(() {
        _busy = false;
        _error =
            'Your account was created, but signing you in did not finish. Use '
            'the address above and the password you just chose.';
      });
      return;
    }

    widget.onDone();
  }

  bool get _canSubmit =>
      !_busy && !_accepted && _password.text.isNotEmpty && _invitation != null;

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

  Widget _form(BuildContext context) {
    final invitation = _invitation ?? const <String, dynamic>{};
    final organization = invitation['organization'] as Map<String, dynamic>?;
    final role = invitation['role'] as Map<String, dynamic>?;
    final invitedBy = invitation['invited_by'] as Map<String, dynamic>?;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        EzBiziTheme.wordmark(context, size: 26),
        const SizedBox(height: 20),
        Text(
          'Join ${organization?['name'] ?? 'this organization'}',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        Text(
          [
            if (invitedBy?['name'] != null) '${invitedBy!['name']} invited you',
            if (role?['name'] != null) 'as ${role!['name']}',
          ].join(' ').trim(),
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        if (invitation['email'] != null) ...[
          const SizedBox(height: 4),
          Text(
            // Stated, not editable. The address is what the invitation was
            // addressed to and what they will sign in with; letting it be
            // changed here would let anybody holding a forwarded link point the
            // account somewhere else entirely.
            invitation['email'] as String,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 20),
        TextField(
          controller: _password,
          obscureText: !_showPassword,
          autofocus: true,
          enabled: !_busy && !_accepted,
          decoration: InputDecoration(
            labelText: 'Choose a password',
            border: const OutlineInputBorder(),
            helperText: _passwordHint,
            suffixIcon: IconButton(
              icon: Icon(
                _showPassword ? Icons.visibility_off : Icons.visibility,
              ),
              tooltip: _showPassword ? 'Hide the password' : 'Show the password',
              onPressed: () => setState(() => _showPassword = !_showPassword),
            ),
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 20),
        Text(
          'About you. Whatever was filled in for you is here to be corrected.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _firstName,
                enabled: !_busy && !_accepted,
                decoration: const InputDecoration(
                  labelText: 'First name',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: TextField(
                controller: _lastName,
                enabled: !_busy && !_accepted,
                decoration: const InputDecoration(
                  labelText: 'Last name',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _phone,
          enabled: !_busy && !_accepted,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Phone',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 12),
        CalendarDayField(
          controller: _dateOfBirth,
          label: 'Date of birth',
          formats: _formats,
          enabled: !_busy && !_accepted,
          firstDate: DateTime(DateTime.now().year - 120),
          lastDate: DateTime.now(),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          initialValue: _gender,
          isExpanded: true,
          decoration: const InputDecoration(
            labelText: 'Gender',
            border: OutlineInputBorder(),
            helperText: 'Optional.',
          ),
          items: [
            for (final option in _genders.entries)
              DropdownMenuItem(value: option.key, child: Text(option.value)),
            // A value this build has no word for is kept and shown as it came
            // rather than dropped: the alternative is a form that silently
            // erases something somebody already answered.
            if (_gender != null && !_genders.containsKey(_gender))
              DropdownMenuItem(value: _gender, child: Text(_gender!)),
          ],
          onChanged: (_busy || _accepted)
              ? null
              : (value) => setState(() => _gender = value),
        ),
        if (_error != null) ...[const SizedBox(height: 16), ErrorNote(_error!)],
        const SizedBox(height: 20),
        FilledButton(
          // Once the account exists this stops being a submit and becomes the
          // way onwards, because a second press cannot create it again — the
          // token is spent and the address is taken.
          onPressed: _accepted
              ? (_busy ? null : widget.onDone)
              : (_canSubmit ? _submit : null),
          child: Text(
            _busy
                ? 'Setting you up…'
                : _accepted
                ? 'Go to sign in'
                : 'Accept and sign in',
          ),
        ),
      ],
    );
  }

  /// The words this form offers.
  ///
  /// Not an API contract — the endpoint takes any short string — and not tenant
  /// data either: it is product copy, and having it in one place is what stops
  /// the same question being asked with four different vocabularies as the
  /// product grows. Left out of the request entirely when nothing is chosen,
  /// because "not answered" is not the same as "prefer not to say".
  static const Map<String, String> _genders = {
    'female': 'Female',
    'male': 'Male',
    'other': 'Other',
    'prefer_not_to_say': 'Prefer not to say',
  };
}
