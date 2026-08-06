import 'package:flutter/material.dart';
import '../core/api_client.dart';
import 'tag.dart';

/// The bell (plan §12).
///
/// Polled, not pushed. Real-time delivery needs a websocket or a push service
/// and neither is built; a poll on open plus a refresh is honest about that and
/// costs one query. When push arrives this widget is what changes, not the API.
class NotificationBell extends StatefulWidget {
  const NotificationBell({super.key, required this.api});

  final ApiClient api;

  @override
  State<NotificationBell> createState() => _NotificationBellState();
}

class _NotificationBellState extends State<NotificationBell> {
  int _unread = 0;
  bool _loading = false;
  List<Map<String, dynamic>> _items = [];

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    if (_loading) return;

    setState(() => _loading = true);

    final response = await widget.api.get('/notifications', {'limit': '20'});

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        // Silent. A bell that shows an error banner because a background poll
        // failed is worse than a bell that briefly shows a stale count.
        return;
      }

      _items = List<Map<String, dynamic>>.from(response.data as List);
      _unread = (response.body['meta'] as Map<String, dynamic>?)?['unread'] as int? ?? 0;
    });
  }

  Future<void> _open() async {
    await _refresh();

    if (!mounted) return;

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Row(
          children: [
            const Expanded(child: Text('Notifications')),
            if (_unread > 0)
              TextButton(
                onPressed: () async {
                  await widget.api.post('/notifications/read-all', {});
                  if (context.mounted) Navigator.of(context).pop();
                  await _refresh();
                },
                child: const Text('Mark all read'),
              ),
          ],
        ),
        content: SizedBox(
          width: 460,
          height: 400,
          child: _items.isEmpty
              ? const Center(child: Text('Nothing yet.'))
              : ListView.separated(
                  itemCount: _items.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final n = _items[i];
                    final unread = n['read_at'] == null;

                    return ListTile(
                      dense: true,
                      leading: Icon(
                        unread ? Icons.circle : Icons.circle_outlined,
                        size: 12,
                        color: unread ? Theme.of(context).colorScheme.primary : null,
                      ),
                      title: Text(n['title'] as String),
                      subtitle: n['body'] != null ? Text(n['body'] as String) : null,
                      trailing: (n['source'] == 'workflow') ? const Tag('Rule') : null,
                      onTap: unread
                          ? () async {
                              await widget.api.post('/notifications/${n['id']}/read', {});
                              await _refresh();
                              if (context.mounted) setState(() {});
                            }
                          : null,
                    );
                  },
                ),
        ),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );

    await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: _unread == 0 ? 'Notifications' : '$_unread unread',
      onPressed: _open,
      icon: Badge(
        isLabelVisible: _unread > 0,
        label: Text('$_unread'),
        child: const Icon(Icons.notifications_none),
      ),
    );
  }
}
