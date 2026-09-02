import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'social_network_mark.dart';

/// Which account a module is connected to, and how to disconnect it.
///
/// ── Why this exists next to ConnectAccount ───────────────────────
///
/// ConnectAccount only ever appears when nothing is connected. The moment one
/// is, it vanishes and the tab says nothing at all about what it is reading —
/// so a connection made by mistake looks exactly like a connection made
/// correctly, and the only symptom is a list that stays empty.
///
/// That is not hypothetical. Jose connected EzBizi's own manager account to
/// A-1 Chimney, and the tab's answer was "No enquiries yet" — true, and no help
/// at all. Naming the account is what prevents that; the disconnect is what
/// fixes it once it has happened.
///
/// ── Why the disconnect lives here and not only in Marketing ──────
///
/// There was one, in Social Media Management, and it worked. But the Google
/// connections are deliberately kept out of Marketing's catalogue — nobody
/// posts as a Google Ads account — so the tab that owns the connection sent
/// people to a module they were not working in to undo something they had just
/// done in this one.
///
/// ── No dialog ────────────────────────────────────────────────────
///
/// The house rule: nothing this application draws covers the thing it is
/// about, and there is not one AlertDialog in it. So the confirm is the button
/// changing its mind in place — one tap arms it, the next does it, and Keep
/// backs out. Disconnecting deletes the stored grant on the server, which is
/// what the compliance page tells Google happens, so it is worth two taps.
class ConnectedAccount extends StatefulWidget {
  const ConnectedAccount({
    super.key,
    required this.api,
    required this.connection,
    required this.onDisconnected,
  });

  final ApiClient api;

  /// One row of `meta.connections` — `id`, `provider`, `display_name`.
  final Map<String, dynamic> connection;

  final Future<void> Function() onDisconnected;

  @override
  State<ConnectedAccount> createState() => _ConnectedAccountState();
}

class _ConnectedAccountState extends State<ConnectedAccount> {
  bool _armed = false;
  bool _busy = false;
  String? _error;

  static const _labels = {
    'google_ads': 'Google Ads',
    'google_business_profile': 'Google Business Profile',
    'thumbtack': 'Thumbtack',
  };

  String get _provider => '${widget.connection['provider'] ?? ''}';

  String get _label => _labels[_provider] ?? _provider;

  String get _name => '${widget.connection['display_name'] ?? ''}';

  Future<void> _disconnect() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response =
        await widget.api.delete('/social/connections/${widget.connection['id']}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _armed = false;
        // The server's own sentence. A refusal here is nearly always a
        // permission one, and it names the permission.
        _error = response.reason;
      });

      return;
    }

    setState(() {
      _busy = false;
      _armed = false;
    });

    await widget.onDisconnected();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SocialNetworkMark(network: _provider, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  // The account, not just the network. "Connected" on its own
                  // is the sentence that let the wrong account sit there.
                  _name.isEmpty ? 'Connected to $_label' : '$_label · $_name',
                  style: theme.textTheme.bodySmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (_busy)
                const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              else if (_armed) ...[
                TextButton(
                  onPressed: () => setState(() => _armed = false),
                  child: const Text('Keep'),
                ),
                TextButton(
                  onPressed: _disconnect,
                  child: const Text('Disconnect'),
                ),
              ] else
                TextButton(
                  onPressed: () => setState(() => _armed = true),
                  child: const Text('Disconnect'),
                ),
            ],
          ),
          if (_armed && !_busy)
            Text(
              'This removes the stored permission. Nothing already in EzBizi is '
              'deleted, and you can connect an account again afterwards.',
              style: theme.textTheme.bodySmall?.copyWith(color: theme.hintColor),
            ),
          if (_error != null)
            Text(
              _error!,
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
            ),
        ],
      ),
    );
  }
}
