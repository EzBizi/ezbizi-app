import 'package:flutter/material.dart';

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
/// ── Why there is no Disconnect here ──────────────────────────────
///
/// There was one, and Jose, 2026-10-16: "any actions like that should be on a
/// settings sub tab, not available to anyone who visits the tab". Everybody
/// reading Reviews could let the business's Google connection go. It lives in
/// Settings › Integrations, behind that tab's own grant; this line only names
/// the account and says where to change it.
class ConnectedAccount extends StatelessWidget {
  const ConnectedAccount({super.key, required this.connection});

  /// One row of `meta.connections` — `id`, `provider`, `display_name`.
  final Map<String, dynamic> connection;

  static const _labels = {
    'google_ads': 'Google Ads',
    'google_business_profile': 'Google Business Profile',
    'thumbtack': 'Thumbtack',
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final provider = '${connection['provider'] ?? ''}';
    final label = _labels[provider] ?? provider;
    final name = '${connection['display_name'] ?? ''}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: Row(
        children: [
          SocialNetworkMark(network: provider, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              // The account, not just the network. "Connected" on its own
              // is the sentence that let the wrong account sit there.
              name.isEmpty ? 'Connected to $label' : '$label · $name',
              style: theme.textTheme.bodySmall,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              'Change it in Settings › Integrations',
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
