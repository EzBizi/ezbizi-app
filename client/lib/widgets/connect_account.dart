import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../screens/email/consent_window.dart';

/// Connect a third-party account from the module that uses it.
///
/// ── Why this is not in Marketing ─────────────────────────────
///
/// Marketing's catalogue is places a tenant PUBLISHES, and its Connect is
/// gated on `marketing.edit`. A Google Ads account is not a place anybody
/// posts; the only screen that reads it is Leads, and the person who can tell
/// whether it is the right account is the person who runs Leads. Putting the
/// button there — and gating it on that module's own permission — is the whole
/// point of this widget existing rather than a row being added to Marketing.
///
/// The OAuth dance itself is [runConsentFlow], shared with Marketing and the
/// same one Email does: open a consent window first (a popup opened after an await is a popup the
/// browser blocks), ask the server for an authorization URL, send the window
/// there, then poll until the callback lands.
class ConnectAccount extends StatefulWidget {
  const ConnectAccount({
    super.key,
    required this.api,
    required this.label,
    required this.startPath,
    required this.onConnected,
    this.openConsent = openConsentWindow,
    this.action,
  });

  /// The button's words in place of "Connect [label]": "Reconnect" on a
  /// Google login's card (design 167).
  final String? action;

  final ApiClient api;

  /// What the button calls it — "Google Ads", "Business Profile".
  final String label;

  /// The start path, passed in whole rather than built from a key.
  ///
  /// Interpolating it here (`'/social/\$routeKey/start'`) hid the path from
  /// `ClientCallsRealRoutesTest`, which reads the client for the routes it
  /// calls and checks the API registers them. A path it cannot resolve is a
  /// path nobody is checking — so the literal lives at each call site, where
  /// the scanner can see it, and this widget stays dumb.
  final String startPath;

  final Future<void> Function() onConnected;

  /// Injected so a test can hand in a window that never opens one.
  final OpenConsentWindow openConsent;

  @override
  State<ConnectAccount> createState() => _ConnectAccountState();
}

class _ConnectAccountState extends State<ConnectAccount> {
  bool _busy = false;
  String? _error;

  /// The sign-in address, offered to copy while waiting. In the app the page
  /// opens in the person's own browser, and when that silently does not
  /// happen the button would otherwise spin with no way forward (Jose,
  /// 2026-09-30, connecting Thumbtack from the app).
  String? _url;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _connect() async {
    _poll?.cancel();
    _poll = await runConsentFlow(
      api: widget.api,
      openConsent: widget.openConsent,
      startPath: widget.startPath,
      label: widget.label,
      refusedFallback: '${widget.label} refused the sign-in.',
      isMounted: () => mounted,
      onStart: () => setState(() {
        _busy = true;
        _error = null;
      }),
      onEnd: (error) => setState(() {
        _busy = false;
        _error = error;
      }),
      onUrl: (url) => setState(() => _url = url),
      onConnected: widget.onConnected,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        FilledButton.icon(
          onPressed: _busy ? null : _connect,
          icon: _busy
              ? const SizedBox(
                  width: 16, height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.link, size: 18),
          label: Text(_busy ? 'Waiting for the sign-in…' : widget.action ?? 'Connect ${widget.label}'),
        ),
        if (_busy && _url != null && !kIsWeb)
          TextButton.icon(
            key: const ValueKey('copy-sign-in-link'),
            icon: const Icon(Icons.copy, size: 16),
            label: const Text('Browser did not open? Copy the sign-in link'),
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: _url!));
              if (!context.mounted) return;
              ScaffoldMessenger.maybeOf(context)?.showSnackBar(
                const SnackBar(content: Text('Copied. Paste it into your browser.')),
              );
            },
          ),
        if (_error != null) ...[
          const SizedBox(height: 10),
          Text(
            _error!,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.error),
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }
}
