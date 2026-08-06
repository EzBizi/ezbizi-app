import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../core/staff_session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';

/// Control-plane sign-in (plan §4.2).
///
/// Three fields, one request. The server takes password and code together
/// deliberately — a two-step flow needs an intermediate token that, on its own,
/// proves the password, and that token becomes the thing to steal.
class StaffLoginScreen extends StatefulWidget {
  const StaffLoginScreen({super.key, required this.staff, required this.onExit});

  final StaffSession staff;

  /// Back to the tenant login. The control plane is not a separate site, just a
  /// separate identity.
  final VoidCallback onExit;

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

    await widget.staff.adopt(response.body['token'] as String);
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
                child: Form(
                  key: _formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Center(child: EzBiziTheme.wordmark(context, size: 30)),
                      const SizedBox(height: 6),
                      Center(
                        child: Text(
                          'CONTROL PLANE',
                          style: Theme.of(context).textTheme.labelSmall,
                        ),
                      ),
                      const SizedBox(height: 24),

                      TextFormField(
                        controller: _email,
                        decoration: const InputDecoration(labelText: 'Staff email'),
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.username],
                        enabled: !_busy,
                        validator: (v) =>
                            (v == null || !v.contains('@')) ? 'Enter your email address' : null,
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
                                    strokeWidth: 2, color: Colors.white))
                            : const Text('Sign in'),
                      ),
                      const SizedBox(height: 4),
                      TextButton(
                        onPressed: _busy ? null : widget.onExit,
                        child: const Text('Back to customer sign-in'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
