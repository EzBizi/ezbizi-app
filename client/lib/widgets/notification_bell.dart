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

  // A failed fetch used to render as "Nothing yet.", so an outage and an empty
  // inbox were indistinguishable — and the one that needs action looked like
  // the one that does not.
  String? _error;
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
        // Recorded, not shown. A bell that pops an error banner because a
        // background poll failed is worse than one that briefly shows a stale
        // count — but the dialog needs to know, or it renders a failed fetch as
        // "Nothing yet." and an outage becomes indistinguishable from an empty
        // inbox.
        _error = response.message;
        return;
      }

      _error = null;
      _items = List<Map<String, dynamic>>.from(response.data as List);
      _unread = (response.body['meta'] as Map<String, dynamic>?)?['unread'] as int? ?? 0;
    });
  }

  Future<void> _markAllRead() async {
    final response = await widget.api.post('/notifications/read-all', {});

    if (!mounted) return;

    if (!response.ok) {
      // The response was discarded and the dialog closed either way, so a
      // failure looked identical to a success until the badge came back.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.message)),
      );
      return;
    }

    await _refresh();
  }

  Future<void> _open() async {
    await _refresh();

    if (!mounted) return;

    await showDialog<void>(
      context: context,
      // StatefulBuilder, because a dialog's page is built ONCE by the route and
      // cached: calling setState on this widget repaints the bell behind it and
      // leaves the open list showing exactly what it showed before. Marking one
      // read did nothing visible until the dialog was closed and reopened.
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => AlertDialog(
        title: Row(
          children: [
            const Expanded(child: Text('Notifications')),
            if (_unread > 0)
              TextButton(
                onPressed: () async {
                  await _markAllRead();
                  if (context.mounted) repaint(() {});
                },
                child: const Text('Mark all read'),
              ),
          ],
        ),
        content: SizedBox(
          width: 460,
          height: 400,
          child: _items.isEmpty
              ? Center(
                  child: Text(_error ?? 'Nothing yet.'),
                )
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
                              final r = await widget.api
                                  .post('/notifications/${n['id']}/read', {});

                              if (!context.mounted) return;

                              if (!r.ok) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(content: Text(r.message)),
                                );
                                return;
                              }

                              await _refresh();

                              // repaint, not setState: setState rebuilds the
                              // bell behind the dialog, and the dialog's own
                              // page is cached by the route.
                              if (context.mounted) repaint(() {});
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
