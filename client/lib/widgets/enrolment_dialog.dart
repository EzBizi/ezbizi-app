import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../core/api_client.dart';
import 'error_note.dart';
import 'recovery_codes.dart';
import 'side_panel.dart';

/// Handing someone their second factor (plan §4.2).
///
/// Shown in three situations — a new account, a lost phone, and moving your own
/// authenticator — because they are the same moment from different directions.
/// One panel means one set of instructions to keep correct.
///
/// The QR is drawn from the otpauth:// URI the server issues. The server does
/// not render an image: the URI is the single source of truth, and generating
/// the picture twice is how the two quietly disagree.
class EnrolmentDialog extends StatefulWidget {
  const EnrolmentDialog({
    super.key,
    required this.email,
    required this.secret,
    required this.uri,
    required this.onConfirm,
    this.title = 'Set up the authenticator',
  });

  final String email;
  final String secret;
  final String uri;
  final String title;

  /// Verifies the six digits. Kept out here because confirming your own
  /// authenticator and confirming someone else's are different endpoints.
  final Future<ApiResponse> Function(String code) onConfirm;

  @override
  State<EnrolmentDialog> createState() => _EnrolmentDialogState();
}

class _EnrolmentDialogState extends State<EnrolmentDialog> {
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
          (response.body['recovery_codes'] as List?)?.map((c) => c as String).toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    // The dialog this replaces refused scrim dismissal, so a stray click could
    // not destroy the recovery codes. A panel is escapable by design
    // (side_panel.dart), which leaves 'Copy all' standing in for that guard.
    return SidePanel(
      title: _recoveryCodes == null ? widget.title : 'Recovery codes',
      // Wider than the default so the QR and the secret beside its label both
      // fit without wrapping.
      width: 480,
      actions: _recoveryCodes == null
          ? [
              TextButton(
                onPressed: _busy ? null : () => Navigator.of(context).pop(false),
                child: const Text('Later'),
              ),
              FilledButton(
                onPressed: _busy ? null : _confirm,
                child: const Text('Confirm'),
              ),
            ]
          : [
              FilledButton(
                onPressed: () => Navigator.of(context).pop(true),
                child: const Text('Done'),
              ),
            ],
      child: _recoveryCodes == null
          ? _setup(context)
          : RecoveryCodesView(codes: _recoveryCodes!),
    );
  }

  Widget _setup(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
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
        authenticatorField(context, 'Account name', widget.email),
        authenticatorField(context, 'Key', widget.secret, copyable: true),
        authenticatorField(context, 'Type of key', 'Time based'),

        const SizedBox(height: 18),
        Text(
          'Then type the six digits it shows. Nothing is saved until a code '
          'verifies — an unproven secret would lock the account out.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),

        TextField(
          controller: _code,
          decoration: const InputDecoration(labelText: 'Code from the app'),
          enabled: !_busy,
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

/// One line of "Or enter it by hand": a label, the value, and a copy button
/// when [copyable]. The authenticator panels share it.
Widget authenticatorField(BuildContext context, String label, String value, {bool copyable = false}) {
  return Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 110,
          child: Text(label, style: Theme.of(context).textTheme.bodyMedium),
        ),
        Expanded(
          child: SelectableText(
            value,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
          ),
        ),
        if (copyable)
          IconButton(
            tooltip: 'Copy',
            iconSize: 18,
            visualDensity: VisualDensity.compact,
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: value));
              if (context.mounted) {
                ScaffoldMessenger.of(context)
                    .showSnackBar(const SnackBar(content: Text('Key copied')));
              }
            },
            icon: const Icon(Icons.copy_outlined),
          ),
      ],
    ),
  );
}
