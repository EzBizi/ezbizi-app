import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import 'error_note.dart';
import 'side_panel.dart';
import 'enrolment_dialog.dart';

/// A client switching two-factor on for themselves (plan §6).
///
/// Self-service by design: no administrator, and nobody from EzBizi needs to be
/// available.
///
/// Lives on the ACCOUNT screen, not on Settings — an earlier version of this
/// comment said Settings, which is where the organization-wide requirement is
/// configured but not where anybody's own authenticator has ever been managed.
/// §2.1 keeps the two apart: Settings belongs to the organization and is set by
/// an administrator, this belongs to the person.
///
/// It is also the way OUT of that organization-wide requirement. When
/// `security.require_two_factor` is on and this person has not enrolled, the
/// server refuses everything except `/me` and its sub-resources, and the shell
/// puts a chip in the top bar pointing here. Anything that made this card
/// unreachable — moving it behind a screen the server refuses, or adding a
/// precondition an un-enrolled account cannot meet — would turn that
/// requirement into a lockout.
class TwoFactorCard extends StatefulWidget {
  const TwoFactorCard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TwoFactorCard> createState() => _TwoFactorCardState();
}

class _TwoFactorCardState extends State<TwoFactorCard> {
  Map<String, dynamic>? _state;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Note what this does NOT do: set `_loading` back to true.
  ///
  /// It is only ever true before the first answer arrives. Turning two-factor
  /// on, moving it to a new phone and turning it off all end here, and each one
  /// leaves the card on screen with what it last knew until the new state
  /// lands — a card that flickers to a progress bar after every one of those is
  /// the same reload complaint the list screens had.
  Future<void> _load() async {
    final response = await widget.api.get('/me/totp');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _state = response.data as Map<String, dynamic>;
        _error = null;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _enable({bool replacing = false}) async {
    Map<String, String>? proof;

    if (replacing) {
      proof = await _prove(
        'Move to a new authenticator',
        'Your password and a code from the CURRENT authenticator. The one you '
        'are using now keeps working until the new one is confirmed, so there '
        'is no moment where the account is unprotected.',
        true,
      );

      if (proof == null || !mounted) return;
    }

    await _beginEnrolment(proof ?? const {}, replacing);
  }

  Future<void> _beginEnrolment(Map<String, String> proof, bool replacing) async {
    final begin = await widget.api.post('/me/totp', proof);

    if (!mounted) return;

    if (!begin.ok) {
      setState(() => _error = begin.message);
      return;
    }

    final enrolment = begin.body['enrolment'] as Map<String, dynamic>;

    await showSidePanel<bool>(
      context,
      builder: (_) => EnrolmentDialog(
        title: replacing ? 'Move to a new authenticator' : 'Turn on two-factor',
        note: EnrolmentDialog.lockYouOutNote,
        autofocus: true,
        codesOptional: true,
        email: widget.session.me!.email,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        intro: replacing
            ? 'Scan this with the new app. Your old one keeps working until you '
                'confirm a code from the new one.'
            : 'You will be asked for a code from this app every time you sign in.',
        onConfirm: (code) => widget.api.post('/me/totp/confirm', {'code': code}),
      ),
    );

    if (mounted) _load();
  }

  /// Password, and a current code when one is already enrolled.
  ///
  /// The password alone is not enough: somebody at a borrowed unlocked laptop
  /// who also knows the password is exactly the case the second factor exists
  /// for. To change or remove it you have to hold the thing being changed.
  ///
  /// Returns null if they cancelled.
  Future<Map<String, String>?> _prove(String title, String why, bool enrolled) async {
    final password = TextEditingController();
    final code = TextEditingController();

    final ok = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: title,
        actions: [
          TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('Continue')),
        ],
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(why),
            const SizedBox(height: 16),
            TextField(
              controller: password,
              obscureText: true,
              autofocus: true,
              decoration: const InputDecoration(labelText: 'Password'),
            ),
            if (enrolled) ...[
              const SizedBox(height: 12),
              TextField(
                controller: code,
                decoration: const InputDecoration(
                  labelText: 'Authenticator code',
                  // A recovery code counts. Somebody who lost the phone is
                  // precisely who needs this, and refusing them would leave
                  // the only way back through a support request.
                  helperText: 'Six digits, or a recovery code',
                ),
              ),
            ],
          ],
        ),
      ),
    );

    final result = ok == true && password.text.isNotEmpty
        ? {
            'password': password.text,
            if (enrolled) 'code': code.text.trim(),
          }
        : null;

    password.dispose();
    code.dispose();

    return result;
  }

  Future<void> _disable(bool enrolled) async {
    final proof = await _prove(
      'Turn off two-factor',
      'Your password and a current code confirm this. A borrowed unlocked '
      'laptop should not be able to remove the thing protecting you from it.',
      enrolled,
    );

    if (proof == null || !mounted) return;

    final response = await widget.api.deleteWithBody('/me/totp', proof);

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Card(
        child: Padding(padding: EdgeInsets.all(24), child: LinearProgressIndicator()),
      );
    }

    final state = _state;
    final enabled = state?['enabled'] as bool? ?? false;
    final required = state?['required_by_organization'] as bool? ?? false;
    // Not "managed elsewhere" any more. A linked account's factor lives on the
    // staff record, and this card now changes it there — the flag survives only
    // so the card can say the change covers both sign-ins.
    final coversControlPlane = state?['covers_control_plane'] as bool? ?? false;
    final codesLeft = state?['recovery_code_count'] as int? ?? 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('Two-factor authentication',
                      style: Theme.of(context).textTheme.titleMedium),
                ),
                if (enabled) ...[
                  TextButton(
                    onPressed: () => _enable(replacing: true),
                    child: const Text('Change'),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    // Required by the organization means one person cannot opt
                    // out of a policy set above them (§6). Changing it is still
                    // allowed — that is not opting out.
                    onPressed: required ? null : () => _disable(true),
                    child: const Text('Turn off'),
                  ),
                ] else
                  FilledButton(
                      onPressed: () => _enable(), child: const Text('Turn on')),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              [
                if (enabled)
                  'On. $codesLeft recovery ${codesLeft == 1 ? 'code' : 'codes'} left'
                      '${required ? ' · required by your organization' : ''}.'
                else if (required)
                  'Your organization requires it. Turn it on to keep signing in.'
                else
                  'A code from your phone, as well as your password. '
                      'Strongly recommended.',
                if (coversControlPlane)
                  'This authenticator covers both your sign-ins.',
                if (enabled)
                  'Changing or removing it needs your password and a current code.',
              ].join(' '),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_error!),
            ],
          ],
        ),
      ),
    );
  }
}
