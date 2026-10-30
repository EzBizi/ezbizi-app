import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../core/api_client.dart';
import 'enrolment_dialog.dart' show authenticatorField;
import 'error_note.dart';
import 'recovery_codes.dart';
import 'side_panel.dart';

/// Setting up an authenticator, from the app (plan §4.2, §6).
///
/// Used by both audiences — a staff member bootstrapping their own account, and
/// a client switching two-factor on in Settings. One panel, because it is the
/// same moment: here is a QR, prove it works, keep these codes.
///
/// The QR is drawn from the otpauth:// URI the server issues. The server does
/// not render an image: the URI is the single source of truth, and generating
/// the picture twice is how the two quietly disagree.
class TotpSetupDialog extends StatefulWidget {
  const TotpSetupDialog({
    super.key,
    required this.accountLabel,
    required this.secret,
    required this.uri,
    required this.onConfirm,
    this.title = 'Set up your authenticator',
    this.intro,
  });

  final String accountLabel;
  final String secret;
  final String uri;
  final String title;
  final String? intro;

  /// Proves the code. Kept out here because staff bootstrap and a tenant user's
  /// own setup are different endpoints.
  final Future<ApiResponse> Function(String code) onConfirm;

  @override
  State<TotpSetupDialog> createState() => _TotpSetupDialogState();
}

class _TotpSetupDialogState extends State<TotpSetupDialog> {
  final _code = TextEditingController();

  bool _busy = false;
  String? _error;
  List<String>? _recoveryCodes;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _confirm() async {
    if (_code.text.trim().isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.onConfirm(_code.text.trim());

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
        _code.clear();
      });
      return;
    }

    setState(() {
      _busy = false;
      _recoveryCodes =
          (response.body['recovery_codes'] as List? ?? []).map((c) => c as String).toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    final done = _recoveryCodes != null;

    // The dialog this replaces refused scrim dismissal, so a stray click could
    // not destroy the recovery codes. A panel is escapable by design
    // (side_panel.dart), which leaves 'Copy all' standing in for that guard.
    return SidePanel(
      title: done ? 'Recovery codes' : widget.title,
      // Wider than the default so the QR and the secret beside its label both
      // fit without wrapping.
      width: 480,
      actions: done
          ? [
              FilledButton(
                onPressed: () => Navigator.of(context).pop(true),
                child: const Text('Done'),
              ),
            ]
          : [
              TextButton(
                onPressed: _busy ? null : () => Navigator.of(context).pop(false),
                child: const Text('Later'),
              ),
              FilledButton(onPressed: _busy ? null : _confirm, child: const Text('Confirm')),
            ],
      child: done
          ? RecoveryCodesView(codes: _recoveryCodes!)
          : _setup(context),
    );
  }

  Widget _setup(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.intro != null) ...[
          Text(widget.intro!, style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 16),
        ],

        // Always on white. A QR has to be dark-on-light to scan, and in dark
        // mode a transparent background inverts it — most phone cameras then
        // silently refuse to read it.
        Center(
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(10),
            ),
            child: QrImageView(
              data: widget.uri,
              version: QrVersions.auto,
              size: 200,
              backgroundColor: Colors.white,
            ),
          ),
        ),

        const SizedBox(height: 18),
        Text('Or enter it by hand', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        authenticatorField(context, 'Account name', widget.accountLabel),
        authenticatorField(context, 'Key', widget.secret, copyable: true),
        authenticatorField(context, 'Type of key', 'Time based'),

        const SizedBox(height: 18),
        Text(
          'Then type the six digits it shows. Nothing takes effect until a code '
          'verifies — an unproven secret would lock you out.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),

        TextField(
          controller: _code,
          decoration: const InputDecoration(labelText: 'Code from the app'),
          enabled: !_busy,
          autofocus: true,
          onSubmitted: (_) => _confirm(),
        ),

        if (_error != null) ...[
          const SizedBox(height: 14),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
