import 'dart:async';

import 'package:flutter/material.dart';

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
/// The OAuth dance itself is the same one Marketing and Email already do:
/// open a consent window first (a popup opened after an await is a popup the
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
  });

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
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _connect() async {
    // Opened BEFORE the await, deliberately. A window opened after an async gap
    // is not attributable to the click any more, and every browser blocks it.
    final window = widget.openConsent();

    if (window == null) {
      setState(() => _error =
          'EzBizi could not open the ${widget.label} sign-in window. On the web '
          'that is usually the browser blocking new windows for this site — '
          'allow them and press the button again.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(widget.startPath, const {});

    if (!mounted) {
      window.close();
      return;
    }

    if (!response.ok) {
      window.close();
      setState(() {
        _busy = false;
        // `reason` rather than `message`: a 501 here carries the driver's
        // sentence about what EzBizi has not set up yet, which is the only
        // part somebody stuck can act on.
        _error = response.reason;
      });
      return;
    }

    final data = response.data;
    final url = data is Map<String, dynamic> ? data['authorization_url'] : null;
    final flow = data is Map<String, dynamic> ? data['flow'] : null;

    if (url is! String || flow is! String) {
      window.close();
      setState(() {
        _busy = false;
        _error = '${widget.label} did not hand back a sign-in address.';
      });
      return;
    }

    window.goTo(url);
    _watch(window, flow);
  }

  void _watch(ConsentWindow window, String flow) {
    // Five minutes at two-second intervals. Somebody choosing between several
    // Google accounts, or being asked for a second factor, is slower than a
    // timeout tuned to the happy path.
    var asks = 150;
    _poll?.cancel();

    _poll = Timer.periodic(const Duration(seconds: 2), (timer) async {
      asks -= 1;

      if (asks <= 0 || !mounted) {
        timer.cancel();
        if (mounted) setState(() => _busy = false);
        return;
      }

      // Derived, the same way Marketing derives it — the scanner resolves a
      // replaceFirst against a literal it has already seen at the call site.
      final status = await widget.api
          .get(widget.startPath.replaceFirst('/start', '/status'), {'flow': flow});

      if (!mounted) {
        timer.cancel();
        return;
      }

      final data = status.data;
      final state = data is Map<String, dynamic> ? data['state'] : null;
      final message = data is Map<String, dynamic> ? data['message'] : null;

      if (state == 'connected') {
        timer.cancel();
        window.close();
        setState(() => _busy = false);
        await widget.onConnected();
        return;
      }

      if (state == 'refused') {
        timer.cancel();
        window.close();
        setState(() {
          _busy = false;
          // The server's own sentence about why, not "something went wrong".
          _error = message is String && message.isNotEmpty
              ? message
              : '${widget.label} refused the sign-in.';
        });
      }
    });
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
          label: Text(_busy ? 'Waiting for Google…' : 'Connect ${widget.label}'),
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
