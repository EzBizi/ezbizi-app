import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'dashboard_card.dart';

/// The staff linked to this organization's Telegram bot, each with Unlink
/// (Jose, 2026-10-26). The same as /unlink in Telegram: they can share their
/// phone with the bot again to come back, so it asks nothing first.
class TelegramLinkedStaff extends StatefulWidget {
  const TelegramLinkedStaff({super.key, required this.api});

  final ApiClient api;

  @override
  State<TelegramLinkedStaff> createState() => _TelegramLinkedStaffState();
}

class _TelegramLinkedStaffState extends State<TelegramLinkedStaff> {
  List<Map<String, dynamic>>? _links;
  String? _error;
  final Set<String> _busy = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/social/telegram/links');
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.reason;
      _links = response.ok
          ? ((response.body['data'] as List<dynamic>?) ?? const [])
                .whereType<Map<String, dynamic>>()
                .toList()
          : const [];
    });
  }

  Future<void> _unlink(String id) async {
    setState(() => _busy.add(id));
    final response = await widget.api.delete('/social/telegram/links/$id');
    if (!mounted) return;
    setState(() {
      _busy.remove(id);
      _error = response.ok ? null : response.reason;
    });
    if (response.ok) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final links = _links;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Linked staff', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        if (_error != null) DashboardNote(_error!),
        if (links == null)
          const Padding(
            padding: EdgeInsets.all(8),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (links.isEmpty)
          Text(
            'Nobody has linked yet. Staff open the bot in Telegram and share '
            'their phone number; it must match the phone on their profile.',
            key: const ValueKey('telegram-links-empty'),
            style: theme.textTheme.bodySmall,
          )
        else
          for (final link in links)
            ListTile(
              key: ValueKey('telegram-link:${link['id']}'),
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: const Icon(Icons.person_outline, size: 20),
              title: Text(
                link['name'] as String? ?? '',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              subtitle: (link['email'] as String?)?.isNotEmpty == true
                  ? Text(
                      link['email'] as String,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    )
                  : null,
              trailing: TextButton(
                key: ValueKey('telegram-unlink:${link['id']}'),
                style: TextButton.styleFrom(
                  foregroundColor: theme.colorScheme.error,
                ),
                onPressed: _busy.contains(link['id'])
                    ? null
                    : () => _unlink(link['id'] as String),
                child: const Text('Unlink'),
              ),
            ),
      ],
    );
  }
}
