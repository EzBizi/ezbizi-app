import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import 'error_note.dart';
import 'totp_setup_dialog.dart';

/// A client switching two-factor on for themselves (plan §6).
///
/// Self-service by design: no administrator, and nobody from EzBizi needs to be
/// available. Lives in Settings because that is where somebody goes looking for
/// it, and because the organization-wide requirement lives there too.
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

  Future<void> _enable() async {
    final begin = await widget.api.post('/me/totp', {});

    if (!mounted) return;

    if (!begin.ok) {
      setState(() => _error = begin.message);
      return;
    }

    final enrolment = begin.body['enrolment'] as Map<String, dynamic>;

    await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => TotpSetupDialog(
        title: 'Turn on two-factor',
        accountLabel: widget.session.me!.email,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        intro: 'You will be asked for a code from this app every time you sign in.',
        onConfirm: (code) => widget.api.post('/me/totp/confirm', {'code': code}),
      ),
    );

    if (mounted) _load();
  }

  Future<void> _disable() async {
    final password = TextEditingController();

    final entered = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Turn off two-factor'),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Your password confirms this. A borrowed unlocked laptop should not '
                'be able to remove the thing protecting you from it.',
              ),
              const SizedBox(height: 16),
              TextField(
                controller: password,
                obscureText: true,
                autofocus: true,
                decoration: const InputDecoration(labelText: 'Password'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(password.text),
            child: const Text('Turn off'),
          ),
        ],
      ),
    );

    password.dispose();

    if (entered == null || entered.isEmpty || !mounted) return;

    final response = await widget.api.delete('/me/totp', {'password': entered});

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
    final elsewhere = state?['managed_elsewhere'] as bool? ?? false;
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
                if (elsewhere)
                  const Text('Managed by your EzBizi account')
                else if (enabled)
                  OutlinedButton(
                    onPressed: required ? null : _disable,
                    child: const Text('Turn off'),
                  )
                else
                  FilledButton(onPressed: _enable, child: const Text('Turn on')),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              elsewhere
                  // Saying so stops somebody enrolling a second authenticator and
                  // wondering why the first one is still being asked for.
                  ? 'Your sign-in is handled by your EzBizi staff account, and its '
                      'authenticator covers both. Change it there.'
                  : enabled
                      ? 'On. $codesLeft recovery ${codesLeft == 1 ? 'code' : 'codes'} left'
                          '${required ? ' · required by your organization' : ''}.'
                      : required
                          ? 'Your organization requires it. Turn it on to keep signing in.'
                          : 'A code from your phone, as well as your password. '
                              'Strongly recommended.',
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
