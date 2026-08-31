import 'dart:async';

import 'package:flutter/material.dart';
import '../core/address.dart';
import '../core/api_client.dart';
import '../core/os_notifications.dart';
import 'side_panel.dart';
import 'tag.dart';

/// The bell (plan §12).
///
/// Polled, not pushed. Real-time delivery needs a websocket or a push service
/// and neither is built; a poll plus a refresh is honest about that and costs
/// one query. When push arrives this widget is what changes, not the API.
///
/// ── Why it polls on a timer rather than only when opened ─────────
///
/// It used to fetch exactly once, in [initState]. Everything after that was a
/// consequence of somebody opening the panel — so for a person who left EzBizi
/// open on the jobs list all afternoon, which is how the app is actually used,
/// the badge showed whatever had been true when they signed in. A notification
/// nobody is told about is a row in a table, and the one thing the bell is for
/// is being the telling.
///
/// Sixty seconds, matching MonitoringHeartbeat's keep-alive, so the two
/// background timers in the client tick at the same rate rather than at two
/// numbers somebody has to reconcile later.
///
/// ── Why the lifecycle observer, on top of the timer ──────────────
///
/// A timer does not run in a backgrounded app, and on the web a hidden tab has
/// its timers throttled to something like once a minute at best and frozen at
/// worst. Coming back to the app is therefore the moment the count is most
/// likely to be wrong and the moment a person is most likely to look at it, and
/// `resumed` is the only event that catches it.
class NotificationBell extends StatefulWidget {
  const NotificationBell({super.key, required this.api, this.onOpenTask});

  final ApiClient api;
  final ValueChanged<TaskAddressIntent>? onOpenTask;

  @override
  State<NotificationBell> createState() => _NotificationBellState();
}

class _NotificationBellState extends State<NotificationBell>
    with WidgetsBindingObserver {
  static const Duration _pollEvery = Duration(seconds: 60);

  int _unread = 0;

  // A failed fetch used to render as "Nothing yet.", so an outage and an empty
  // inbox were indistinguishable — and the one that needs action looked like
  // the one that does not.
  String? _error;
  bool _loading = false;
  List<Map<String, dynamic>> _items = [];

  Timer? _poll;

  /// Ids already handed to the operating system, so nothing is announced twice.
  ///
  /// Seeded on the very first fetch WITHOUT announcing anything (see
  /// [_announce]): signing in with eleven unread notifications must not fire
  /// eleven toasts about things that happened while the app was shut.
  final Set<String> _announced = <String>{};
  bool _seeded = false;

  /// Ids for the OS are ints, and this is the counter that supplies them.
  ///
  /// Not the notification's own uuid hashed down to an int: [OsNotifications]
  /// treats a repeated id as REPLACING the notification that had it, so a hash
  /// collision between two unrelated notifications would silently swallow one
  /// of them. A counter cannot collide with itself.
  int _osId = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _refresh();
    _poll = Timer.periodic(_pollEvery, (_) => unawaited(_refresh()));
  }

  @override
  void dispose() {
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_refresh());
  }

  Future<void> _refresh() async {
    if (_loading) return;

    setState(() => _loading = true);

    final response = await widget.api.get('/notifications', {'limit': '20'});

    if (!mounted) return;

    // Worked out before setState rebuilds _items out from under the comparison.
    final List<Map<String, dynamic>> arrived = response.ok
        ? _arrivals(response.data as List)
        : const [];

    setState(() {
      _loading = false;

      if (!response.ok) {
        // Recorded, not shown. A bell that pops an error banner because a
        // background poll failed is worse than one that briefly shows a stale
        // count — but the panel needs to know, or it renders a failed fetch as
        // "Nothing yet." and an outage becomes indistinguishable from an empty
        // inbox.
        _error = response.message;
        return;
      }

      _error = null;
      _items = List<Map<String, dynamic>>.from(response.data as List);
      _unread =
          (response.body['meta'] as Map<String, dynamic>?)?['unread'] as int? ??
          0;
    });

    unawaited(_announce(arrived));
  }

  /// The unread ones this widget has not already accounted for.
  ///
  /// Unread rather than merely new: a notification the person has already
  /// opened on their phone should not raise a toast on their desktop a minute
  /// later, and `read_at` is the server's record of exactly that.
  ///
  /// Every id seen is remembered whether or not it gets announced, so the first
  /// fetch after sign-in fills the set and announces nothing.
  List<Map<String, dynamic>> _arrivals(List raw) {
    final arrivals = <Map<String, dynamic>>[];

    for (final item in raw.cast<Map<String, dynamic>>()) {
      final id = item['id'] as String?;
      if (id == null) continue;

      final isNew = _announced.add(id);

      if (isNew && _seeded && item['read_at'] == null) arrivals.add(item);
    }

    _seeded = true;

    return arrivals;
  }

  /// Hand them to the operating system — see [OsNotifications].
  ///
  /// Permission is never requested here. [enable] is called when somebody opens
  /// the panel, and until they have, this quietly does nothing: a background
  /// poll is not a user gesture, and asking from one is what gets an origin
  /// permanently barred from ever asking again.
  Future<void> _announce(List<Map<String, dynamic>> arrivals) async {
    if (arrivals.isEmpty) return;

    // A rule that fires across a whole franchise can land a dozen at once, and
    // a dozen toasts is not twelve times as informative as one — it is a wall
    // of notifications somebody dismisses without reading, including the one
    // that mattered. Past three, say how many and let the bell hold the detail.
    if (arrivals.length > 3) {
      await OsNotifications.show(
        id: _osId++,
        title: '${arrivals.length} new notifications',
        body: 'Open EzBizi to see them.',
      );
      return;
    }

    for (final item in arrivals) {
      await OsNotifications.show(
        id: _osId++,
        title: item['title'] as String? ?? 'EzBizi',
        body: item['body'] as String?,
      );
    }
  }

  Future<void> _markAllRead() async {
    final response = await widget.api.post('/notifications/read-all', {});

    if (!mounted) return;

    if (!response.ok) {
      // The response was discarded and the panel closed either way, so a
      // failure looked identical to a success until the badge came back.
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    await _refresh();
  }

  TaskAddressIntent? _taskIntent(Map<String, dynamic> notification) {
    if (notification['source'] != 'task') return null;
    final meta = notification['meta'];
    final route = meta is Map ? meta['route'] : null;
    return route is String ? Address.taskIntent(route) : null;
  }

  Future<void> _open() async {
    // The user gesture the permission prompt has to come from. Its answer is
    // deliberately not checked: a refusal is a perfectly good outcome that
    // leaves the panel below working exactly as it always has, and nagging
    // somebody who has said no is how an app trains people to say no faster.
    unawaited(OsNotifications.enable());

    await _refresh();

    if (!mounted) return;

    await showSidePanel<void>(
      context,
      // StatefulBuilder, because a panel's page is built ONCE by the route and
      // cached: calling setState on this widget repaints the bell behind it and
      // leaves the open list showing exactly what it showed before. Marking one
      // read did nothing visible until the panel was closed and reopened.
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: 'Notifications',
          width: 460,
          // "Mark all read" sat beside the title and moves to the footer with
          // Close, because the panel has one place for the things you press.
          actions: [
            if (_unread > 0)
              TextButton(
                onPressed: () async {
                  await _markAllRead();
                  if (context.mounted) repaint(() {});
                },
                child: const Text('Mark all read'),
              ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close'),
            ),
          ],
          child: _items.isEmpty
              ? Padding(
                  padding: const EdgeInsets.symmetric(vertical: 48),
                  child: Center(child: Text(_error ?? 'Nothing yet.')),
                )
              : ListView.separated(
                  // The panel scrolls the body already, so the list lays itself
                  // out at full height inside it. Two nested scrollers would
                  // fight over the same drag.
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  padding: EdgeInsets.zero,
                  itemCount: _items.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final n = _items[i];
                    final unread = n['read_at'] == null;

                    return ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        unread ? Icons.circle : Icons.circle_outlined,
                        size: 12,
                        color: unread
                            ? Theme.of(context).colorScheme.primary
                            : null,
                      ),
                      title: Text(n['title'] as String),
                      subtitle: n['body'] != null
                          ? Text(n['body'] as String)
                          : null,
                      trailing: (n['source'] == 'automation')
                          ? const Tag('Rule')
                          : null,
                      onTap: () async {
                        if (unread) {
                          final r = await widget.api.post(
                            '/notifications/${n['id']}/read',
                            {},
                          );

                          if (!context.mounted) return;

                          if (!r.ok) {
                            // Access can disappear after the list was fetched.
                            // Re-read the governed feed before repainting so a
                            // filtered notification leaves neither a stale
                            // title nor a stale unread count behind.
                            await _refresh();
                            if (!context.mounted) return;
                            repaint(() {});
                            ScaffoldMessenger.of(
                              context,
                            ).showSnackBar(SnackBar(content: Text(r.message)));
                            return;
                          }
                        }

                        // Do not navigate from the row snapshot. Refreshing
                        // confirms that this person still receives the item;
                        // its canonical route is then converted into an opaque
                        // typed intent before it leaves this widget.
                        await _refresh();
                        if (!context.mounted) return;
                        final current = _items
                            .cast<Map<String, dynamic>?>()
                            .firstWhere(
                              (item) => item?['id'] == n['id'],
                              orElse: () => null,
                            );
                        final intent = current == null
                            ? null
                            : _taskIntent(current);
                        if (intent != null && widget.onOpenTask != null) {
                          if (context.mounted) {
                            Navigator.of(context).pop();
                            widget.onOpenTask!(intent);
                          }
                          return;
                        }

                        // repaint, not setState: setState rebuilds the
                        // bell behind the panel, and the panel's own
                        // page is cached by the route.
                        if (context.mounted) repaint(() {});
                      },
                    );
                  },
                ),
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
