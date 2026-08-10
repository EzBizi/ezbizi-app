import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/password_policy.dart';
import 'error_note.dart';

/// Changing your password while signed in (plan §4).
///
/// Sits under "Your account" beside two-factor and appearance, because it is the
/// same kind of thing: yours, not the organization's.
class PasswordCard extends StatefulWidget {
  const PasswordCard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PasswordCard> createState() => _PasswordCardState();
}

class _PasswordCardState extends State<PasswordCard> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();

  bool _open = false;
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_next.text != _confirm.text) {
      setState(() => _error = 'The two new passwords do not match.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _done = null;
    });

    final response = await widget.api.post('/me/password', {
      'current_password': _current.text,
      'password': _next.text,
      'password_confirmation': _confirm.text,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;

      if (!response.ok) {
        _error = _firstError(response);
        return;
      }

      _done = response.message;
      _open = false;
      _current.clear();
      _next.clear();
      _confirm.clear();
    });
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    final linked = widget.session.me?.development != null;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('Password', style: Theme.of(context).textTheme.titleMedium),
                ),
                TextButton(
                  onPressed: _busy ? null : () => setState(() => _open = !_open),
                  child: Text(_open ? 'Cancel' : 'Change'),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              linked
                  // Worth saying: somebody with a linked account would otherwise
                  // reasonably expect this to change only their CRM password.
                  ? 'One password covers the app and the control plane. Changing it '
                      'signs out every other session, including this tab\'s Development '
                      'section.'
                  // The organization's own minimum, resolved by the server (§6
                  // `security.password_min_length`). This card is behind a
                  // session, so it can state the number that will actually be
                  // enforced instead of the compiled floor — an administrator
                  // who raised it to 16 should not have their staff told 12 and
                  // then refused.
                  : '${PasswordPolicy.hintFor(widget.session.me?.passwordMinLength ?? PasswordPolicy.minLength)}'
                      ' Changing it signs out your other devices.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),

            if (_done != null) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const Icon(Icons.check, size: 18),
                  const SizedBox(width: 8),
                  Expanded(child: Text(_done!)),
                ],
              ),
            ],

            if (_open) ...[
              const SizedBox(height: 16),
              TextField(
                controller: _current,
                obscureText: true,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'Current password'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _next,
                obscureText: true,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'New password'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _confirm,
                obscureText: true,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'New password again'),
                onSubmitted: (_) => _submit(),
              ),

              if (_error != null) ...[
                const SizedBox(height: 14),
                ErrorNote(_error!),
              ],

              const SizedBox(height: 16),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: const Text('Change password'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
