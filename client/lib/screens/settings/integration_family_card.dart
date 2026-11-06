import 'dart:async';

import 'package:flutter/material.dart';

import '../../widgets/social_network_mark.dart';

/// One product a sign-in brings, as a line inside its family card.
class FamilyProduct {
  const FamilyProduct({
    required this.provider,
    required this.label,
    required this.detail,
    required this.connected,
    required this.status,
    this.keeps = false,
  });

  final String provider;
  final String label;

  /// What it is connected at, or what it is for when it is not.
  final String detail;
  final bool connected;

  /// The status pill, drawn by the screen so every card shares one.
  final Widget status;

  /// Has a sign-in of its own (WhatsApp), so the family's Disconnect leaves
  /// it: dropping a WhatsApp row drops the number's routing (UI queue 183).
  final bool keeps;
}

/// One card per sign-in (UI queue 182, option 1 / phone 4). Jose, 2026-11-05:
/// "1 facebook connections grabs social and ada and whatever scopes we have
/// etc. Same for disconnecting. And this goes for everything".
///
/// Generic on purpose: the server marks a provider `signed_in_with` its head,
/// and any family it marks draws here with no client change.
///
/// Disconnect is an inline two-step in red: the first tap lists what goes and
/// lapses after 5 s; Confirm hands over to [onDisconnect], which asks for the
/// authenticator code the server insists on.
class IntegrationFamilyCard extends StatefulWidget {
  const IntegrationFamilyCard({
    super.key,
    required this.head,
    required this.label,
    required this.subtitle,
    required this.products,
    required this.onOpen,
    this.onDisconnect,
    this.onManage,
    this.onAddAccess,
    this.mayAddAccess = false,
    this.phone = false,
  });

  final String head;
  final String label;
  final String subtitle;
  final List<FamilyProduct> products;

  /// Opens the head's pane: its sign-in is how a product is added.
  final VoidCallback onOpen;

  /// Null hides Disconnect: nothing connected, or not this person's to remove.
  final VoidCallback? onDisconnect;

  /// "Choose what to connect" (UI queue 183); null when the family lists no
  /// products to choose between.
  final VoidCallback? onManage;

  /// Add access for one product line; null falls back to [onOpen].
  final void Function(String provider)? onAddAccess;
  final bool mayAddAccess;
  final bool phone;

  @override
  State<IntegrationFamilyCard> createState() => _IntegrationFamilyCardState();
}

class _IntegrationFamilyCardState extends State<IntegrationFamilyCard> {
  bool _confirming = false;
  Timer? _lapse;

  @override
  void dispose() {
    _lapse?.cancel();
    super.dispose();
  }

  void _ask() {
    _lapse?.cancel();
    _lapse = Timer(const Duration(seconds: 5), () {
      if (mounted) setState(() => _confirming = false);
    });
    setState(() => _confirming = true);
  }

  void _cancel() {
    _lapse?.cancel();
    setState(() => _confirming = false);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bad = theme.colorScheme.error;
    final connected = widget.products.where((p) => p.connected).toList();
    final goes = connected.where((p) => !p.keeps).toList();
    final stays = connected.where((p) => p.keeps).toList();
    final anyConnected = connected.isNotEmpty;
    final manage = widget.onManage == null
        ? null
        : FilledButton.tonal(
            key: ValueKey('family-manage:${widget.head}'),
            onPressed: widget.onManage,
            child: Text(anyConnected ? 'Manage' : 'Connect'),
          );

    final disconnect = widget.onDisconnect == null
        ? null
        : OutlinedButton(
            key: ValueKey('family-disconnect:${widget.head}'),
            style: OutlinedButton.styleFrom(
              foregroundColor: bad,
              side: BorderSide(color: bad.withValues(alpha: 0.6)),
            ),
            onPressed: _confirming ? null : _ask,
            child: Text(
              widget.phone ? 'Disconnect ${widget.label}' : 'Disconnect',
            ),
          );

    return Container(
      key: ValueKey('family:${widget.head}'),
      width: widget.phone ? double.infinity : 480,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SocialNetworkMark(network: widget.head, size: 28),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.label, style: theme.textTheme.titleSmall),
                    Text(
                      widget.subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              if (manage != null && !widget.phone) ...[
                manage,
                const SizedBox(width: 8),
              ],
              if (disconnect != null && !widget.phone) disconnect,
            ],
          ),
          const SizedBox(height: 8),
          for (final p in widget.products)
            InkWell(
              key: ValueKey('family-product:${p.provider}'),
              onTap: widget.onOpen,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(p.label, style: theme.textTheme.bodyMedium),
                          Text(
                            p.detail,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    if (!p.connected && widget.mayAddAccess)
                      TextButton(
                        key: ValueKey('add-access:${p.provider}'),
                        onPressed: widget.onAddAccess == null
                            ? widget.onOpen
                            : () => widget.onAddAccess!(p.provider),
                        child: const Text('Add access'),
                      )
                    else
                      Flexible(child: p.status),
                  ],
                ),
              ),
            ),
          if (widget.phone && !_confirming) ...[
            if (manage != null) ...[const SizedBox(height: 4), manage],
            if (disconnect != null) ...[const SizedBox(height: 4), disconnect],
          ],
          if (_confirming) ...[
            const SizedBox(height: 8),
            Container(
              key: ValueKey('family-confirm:${widget.head}'),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: bad.withValues(alpha: 0.5)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'This removes:',
                    style: theme.textTheme.labelLarge?.copyWith(color: bad),
                  ),
                  for (final p in goes) Text('• ${p.label}: ${p.detail}'),
                  if (stays.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text('Stays connected:', style: theme.textTheme.labelLarge),
                    for (final p in stays)
                      Text(
                        '• ${p.label}: remove it on its own line',
                        key: ValueKey('family-stays:${p.provider}'),
                      ),
                  ],
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      FilledButton(
                        key: ValueKey('family-confirm-go:${widget.head}'),
                        style: FilledButton.styleFrom(
                          backgroundColor: bad,
                          foregroundColor: theme.colorScheme.onError,
                        ),
                        onPressed: () {
                          _cancel();
                          widget.onDisconnect?.call();
                        },
                        child: Text('Disconnect ${widget.label}'),
                      ),
                      OutlinedButton(
                        key: ValueKey('family-confirm-cancel:${widget.head}'),
                        onPressed: _cancel,
                        child: const Text('Cancel'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}
