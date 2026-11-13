import 'dart:async';

import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/client_platform.dart';
import '../core/deep_links.dart';
import '../core/os_notifications.dart';
import '../core/push_registration.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import 'alert_popup.dart';
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
  const NotificationBell({
    super.key,
    required this.api,
    this.onOpenModule,
    this.iconOf,
    this.formats,
    this.session,
  });

  final ApiClient api;

  /// Where this person's own "alert me" switch is read and saved.
  ///
  /// Jose, 2026-10-05: "an option turn notifications on or off (individually
  /// for each user, something they can inside the notification button
  /// sidebar)". Read live from the session each time it matters, never copied
  /// into this widget, so the panel's switch and the pop-ups below cannot
  /// disagree. Null — a test or a shell with no session — hides the switch and
  /// leaves alerts on.
  final Session? session;

  /// Where to send somebody who taps a notification.
  ///
  /// A MODULE KEY, because that is the unit the shell can select — no module
  /// today accepts a record to open within it, so this goes as far as the shell
  /// goes. The server derives the key from the owning module's own `emits`
  /// declaration and sends null when a notification is about nothing openable.
  ///
  /// Optional so the bell keeps working in a test or any shell that has nowhere
  /// to send anybody. Null here means the old behaviour: tapping marks read.
  ///
  /// `recordId` is the record the notification is about (its subject), so a
  /// destination that can open one does: Jose, 2026-09-28, a lead
  /// notification "always [opens] the newest lead, instead of the one it was
  /// spawned for".
  final void Function(String moduleKey, String? filter, String? recordId)? onOpenModule;

  /// The glyph the sidebar draws for a module, so a notification wears the
  /// icon of what it is about (Jose, 2026-09-30).
  final IconData? Function(String moduleKey)? iconOf;

  /// How this organization writes a clock and a date, for when each
  /// notification happened. Null until /me has arrived.
  final DisplayFormats? formats;

  @override
  State<NotificationBell> createState() => _NotificationBellState();
}

class _NotificationBellState extends State<NotificationBell>
    with WidgetsBindingObserver {
  static const Duration _pollEvery = Duration(seconds: 60);

  int _unread = 0;

  /// The inbox's tab and switch (monday port, 2026-11-09). Kept on the bell,
  /// not the panel, so reopening it shows what was last chosen.
  String _show = 'all';
  bool _unreadOnly = false;

  // A failed fetch used to render as "Nothing yet.", so an outage and an empty
  // inbox were indistinguishable — and the one that needs action looked like
  // the one that does not.
  String? _error;
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

    /*
     * ASK FOR PERMISSION AT SIGN-IN, EVERYWHERE EXCEPT A BROWSER.
     *
     * This used to be asked in ONE place: [_open], when somebody tapped the
     * bell to open the panel. The consequence went unnoticed for two weeks —
     * a person who never opened that panel was never asked, so `_permitted`
     * stayed false and every OS notification was a silent no-op. The feature
     * shipped on five platforms and was switched off for anybody who had not
     * happened to click the right icon, which is most people: you open a
     * notification list to read what you were told, and the whole point of
     * this is to tell you without being opened.
     *
     * The user-gesture requirement that put it there is a WEB rule, and only
     * a web rule. A browser can permanently blacklist an origin that asks on
     * page load, so there the ask stays behind the tap in [_open]. Windows,
     * macOS, Android and iOS have no such penalty and ask at first launch by
     * convention — and this widget is built inside the signed-in shell, so
     * "when this mounts" already means "just after somebody signed in", which
     * is the moment an app is expected to ask.
     */
    // Before enable(): enable() is what hands the plugin its click callback,
    // and a click can arrive the moment it does — on Windows, a toast clicked
    // while EzBizi was not running is exactly what launched it.
    OsNotifications.onClick = _openFromToast;
    unawaited(OsNotifications.listenForPushTaps());

    if (!ClientPlatform.isWeb) unawaited(OsNotifications.enable());

    // Same moment, for the other half of notifications: enable() above asks
    // the OS to SHOW one, and this tells the server which handset to WAKE.
    // Android only; it returns immediately elsewhere. Hooked here because the
    // comment above already establishes that this mount means "just signed
    // in", which is when a device token is worth sending.
    if (!ClientPlatform.isWeb) unawaited(PushRegistration.start(widget.api));

    _refresh();
    _poll = Timer.periodic(_pollEvery, (_) => unawaited(_refresh()));
  }

  @override
  void dispose() {
    // Only our own hook: a newer bell (a re-sign-in) may already have set its.
    if (OsNotifications.onClick == _openFromToast) OsNotifications.onClick = null;
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_refresh());
  }

  /// A second caller during a fetch waits for THAT fetch rather than returning
  /// at once — a cold-start push tap arrives while the first fetch is still in
  /// flight, and must see its rows (Android, 2026-10-12: it opened the panel).
  Future<void>? _inFlight;

  Future<void> _refresh() =>
      _inFlight ??= _fetch().whenComplete(() => _inFlight = null);

  Future<void> _fetch() async {
    final response = await widget.api.get('/notifications', {'limit': '50'});

    if (!mounted) return;

    // Worked out before setState rebuilds _items out from under the comparison.
    final List<Map<String, dynamic>> arrived = response.ok
        ? _arrivals(response.data as List)
        : const [];

    setState(() {
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
    if (response.ok) {
      _raiseAlerts((response.body['meta'] as Map<String, dynamic>?)?['alerts']);
    }
  }

  /// Alerts from Communication › Alerts (UI queue 65): in the middle of the
  /// screen until dismissed, one after another.
  ///
  /// Jose, 2026-10-07: "This sort of alert notifications should be a pop up on
  /// the screen, that they can dismiss", and of the drawn layouts, "2". Read
  /// from meta.alerts, every unread one, so an alert older than this page of
  /// notifications, sent while its person was signed out, still meets them.
  late final _popups = AlertPopups(widget.api, onDismissed: _refresh);

  void _raiseAlerts(Object? open) {
    if (!mounted || open is! List) return;
    _popups.show(
      context,
      [
        for (final alert in open)
          if (alert is Map) Map<String, dynamic>.from(alert),
      ],
      formats: widget.session?.me?.display ?? DisplayFormats.fallback,
    );
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
  /// Permission is never requested HERE, on any platform: a background poll is
  /// not a user gesture, and asking from one is what gets a browser origin
  /// permanently barred from ever asking again. It is asked in [initState] off
  /// the web, and from the tap in [_open] on it.
  ///
  /// So on the web this stays a no-op until somebody has opened the panel once.
  /// That is a real limit rather than an oversight, and it is the browsers'
  /// rule, not ours.
  Future<void> _announce(List<Map<String, dynamic>> arrivals) async {
    if (arrivals.isEmpty) return;

    // Muted by this person in the panel: they still collect in the bell, they
    // just do not pop up. The server stops their pushes and emails the same way.
    if (widget.session?.me?.notificationAlerts == false) return;

    /*
     * NOT WHEN THIS DEVICE IS ALREADY BEING PUSHED TO.
     *
     * Both paths became correct at the same time and nobody had seen them
     * together. The Android module measured two records in the shade for one
     * notification — `tag=null` from this poll, `tag=FCM-Notification:` from
     * the server's push — so every notification appeared twice for anybody
     * with EzBizi OPEN, which is most of a working day. A closed app got one,
     * because nothing is polling.
     *
     * The push is the better of the two to keep: it arrives whether the app is
     * open or shut, and it is the one that carries the notification channel a
     * person can mute.
     *
     * ── Why the flag is "the server accepted our token" ──────────────
     *
     * Not "we have a token" and not "this platform supports push". A token the
     * server never received is a device the server cannot reach, and
     * suppressing on the strength of one would trade a visible duplicate for
     * silence — which is the failure this module has spent a week removing.
     *
     * If the flag is ever wrong, it is wrong in the safe direction: false
     * means both fire and somebody sees two, which is visible and harmless.
     * The unsafe reading would have been "do not push to an active session",
     * where a stale signal shows nothing at all.
     *
     * Web, Windows, macOS and Linux have no push registration, so this is
     * always false there and the local notification stays their only one.
     */
    if (PushRegistration.isRegistered) return;

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

    // The notification's own id rides the toast, so a click can do exactly
    // what clicking its row in the panel does — see [_openFromToast]. The
    // summary above carries none: its answer is the panel itself.
    for (final item in arrivals) {
      await OsNotifications.show(
        id: _osId++,
        // Header / what happened / content, like the push.
        title: item['header'] as String? ?? item['title'] as String? ?? 'EzBizi',
        body: item['header'] == null
            ? item['body'] as String?
            : [item['title'], item['body']].whereType<String>().join('\n'),
        payload: item['id']?.toString(),
      );
    }
  }

  /// A toast was clicked. Do what clicking that notification in the panel does.
  ///
  /// Jose, 2026-09-29: "clicking on notification on desktop does nothing, it
  /// should lead to whatever it's warning about". OsNotifications has already
  /// brought the window forward by the time this runs.
  ///
  /// With no id — the "N new notifications" summary — the destination is the
  /// panel, because that is where the N are. With an id, mark it read and go
  /// where its row would go; when there is nowhere specific to go ("your trial
  /// ends tomorrow"), the panel again, rather than a click that visibly does
  /// nothing a second time.
  Future<void> _openFromToast(String? payload) async {
    if (!mounted) return;

    if (payload == null || payload.isEmpty) {
      await _open();
      return;
    }

    // Unread-ness from the list as fetched, so a toast clicked twice does not
    // post the read twice. A failed read is not a reason to go nowhere: the
    // person clicked to see something, and the panel below still shows it.
    // After the fetch, not before: on a cold start _items is still empty.
    await _refresh();
    if (!mounted) return;

    final clicked = _items
        .where((item) => item['id']?.toString() == payload)
        .firstOrNull;
    if (clicked != null && clicked['read_at'] == null) {
      await widget.api.post('/notifications/$payload/read', {});
      if (!mounted) return;
      await _refresh();
      if (!mounted) return;
    }

    final destination = _destinationOf(payload);
    if (destination != null && widget.onOpenModule != null) {
      _go(destination);
      return;
    }

    await _open();
  }

  /// Where a notification leads, read from the REFRESHED list, or null.
  ///
  /// Shared by the row tap and the toast click so the two cannot drift: the
  /// toast click exists to be the row tap, reached from outside the app.
  /// Read after a refresh deliberately — the refresh re-reads the governed
  /// feed, and a notification that has since stopped being visible must not
  /// still navigate on a stale copy.
  ({String module, String? filter, String? record, DeepLink? link})? _destinationOf(
    String id,
  ) {
    final current = _items
        .where((item) => item['id']?.toString() == id)
        .firstOrNull;
    final meta = current?['meta'] as Map<String, dynamic>?;
    final module = meta?['module'] as String?;
    if (module == null) return null;

    // The exact record (Bar, 2026-10-12: "a new email notification should
    // open that email"). From the server's meta.record, which names the
    // MESSAGE for an email and the CALL for a missed call — neither of which
    // is the notification's subject.
    final record = meta?['record'] as Map<String, dynamic>?;
    final type = record?['type'] as String?;
    final recordId = record?['id'] as String?;

    return (
      module: module,
      filter: meta?['filter'] as String?,
      record: (current?['subject'] as Map<String, dynamic>?)?['id'] as String?,
      link: type == null || recordId == null
          ? null
          : DeepLink(
              module: module,
              type: type,
              id: recordId,
              mailbox: record?['mailbox'] as String?,
              date: record?['date'] as String?,
            ),
    );
  }

  /// Go where a notification points: hand its record to the owning screen,
  /// then switch to that module. The row tap, the desktop toast and a phone
  /// push all arrive here.
  void _go(
    ({String module, String? filter, String? record, DeepLink? link}) destination,
  ) {
    if (destination.link case final link?) DeepLinks.open(link);
    widget.onOpenModule!(destination.module, destination.filter, destination.record);
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

  /// The rows the panel draws: the tab and switch applied, with a heading
  /// string before each of Today / Yesterday / Last 7 days / Older.
  List<Object> _inboxRows() {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    String bucket(Object? stamp) {
      final at = DateTime.tryParse('$stamp')?.toLocal();
      if (at == null) return 'Older';
      final ago = today.difference(DateTime(at.year, at.month, at.day)).inDays;
      if (ago <= 0) return 'Today';
      if (ago == 1) return 'Yesterday';
      if (ago < 7) return 'Last 7 days';
      return 'Older';
    }

    final rows = <Object>[];
    String? last;
    for (final n in _items) {
      if (_unreadOnly && n['read_at'] != null) continue;
      if (_show != 'all' && n['kind'] != _show) continue;
      final b = bucket(n['created_at']);
      if (b != last) {
        rows.add(last = b);
      }
      rows.add(n);
    }
    return rows;
  }

  /// Whether the open panel shows its settings instead of the list.
  bool _settingsOpen = false;

  Future<void> _open() async {
    // The user gesture the permission prompt has to come from. Its answer is
    // deliberately not checked: a refusal is a perfectly good outcome that
    // leaves the panel below working exactly as it always has, and nagging
    // somebody who has said no is how an app trains people to say no faster.
    unawaited(OsNotifications.enable());

    await _refresh();

    if (!mounted) return;

    _settingsOpen = false;
    await showSidePanel<void>(
      context,
      // StatefulBuilder, because a panel's page is built ONCE by the route and
      // cached: calling setState on this widget repaints the bell behind it and
      // leaves the open list showing exactly what it showed before. Marking one
      // read did nothing visible until the panel was closed and reopened.
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => _settingsOpen && widget.session != null
            ? SidePanel(
                // Jose, 2026-11-13: the panel shows only notifications; the
                // gear swaps it for the settings, with a back arrow.
                title: 'Notification settings',
                width: 460,
                onBack: () => repaint(() => _settingsOpen = false),
                actions: [
                  FilledButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Close'),
                  ),
                ],
                child: NotificationSettings(api: widget.api, session: widget.session!),
              )
            : SidePanel(
          title: 'Notifications',
          width: 460,
          headerActions: [
            if (widget.session != null)
              IconButton(
                key: const Key('notification-settings'),
                tooltip: 'Notification settings',
                icon: const Icon(Icons.settings_outlined),
                onPressed: () => repaint(() => _settingsOpen = true),
              ),
          ],
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
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    SegmentedButton<String>(
                      key: const Key('notifications-show'),
                      segments: const [
                        ButtonSegment(value: 'all', label: Text('All')),
                        ButtonSegment(
                          value: 'mentioned',
                          label: Text('Mentioned'),
                        ),
                        ButtonSegment(
                          value: 'assigned',
                          label: Text('Assigned to me'),
                        ),
                      ],
                      selected: {_show},
                      onSelectionChanged: (v) {
                        _show = v.first;
                        repaint(() {});
                      },
                    ),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Text('Unread only'),
                        Switch(
                          key: const Key('notifications-unread-only'),
                          value: _unreadOnly,
                          onChanged: (on) {
                            _unreadOnly = on;
                            repaint(() {});
                          },
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (_inboxRows().isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 48),
                  child: Center(
                    child: Text(
                      _error ??
                          (_items.isEmpty ? 'Nothing yet.' : 'Nothing here.'),
                    ),
                  ),
                )
              else
                ListView.separated(
                  // The panel scrolls the body already, so the list lays itself
                  // out at full height inside it. Two nested scrollers would
                  // fight over the same drag.
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  padding: EdgeInsets.zero,
                  itemCount: _inboxRows().length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final row = _inboxRows()[i];
                    if (row is String) {
                      return Padding(
                        padding: const EdgeInsets.only(top: 12, bottom: 4),
                        child: Text(
                          row,
                          style: Theme.of(context).textTheme.labelMedium
                              ?.copyWith(
                                color: Theme.of(
                                  context,
                                ).colorScheme.onSurfaceVariant,
                              ),
                        ),
                      );
                    }
                    final n = row as Map<String, dynamic>;
                    final unread = n['read_at'] == null;
                    final scheme = Theme.of(context).colorScheme;
                    final module =
                        (n['meta'] as Map<String, dynamic>?)?['module']
                            as String?;
                    final icon = module == null
                        ? null
                        : widget.iconOf?.call(module);
                    final when = formatListStamp(
                      n['created_at'],
                      '',
                      formats: widget.formats ?? DisplayFormats.fallback,
                    );

                    return ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      // The module it is about, with a dot while unread: every
                      // row was the same circle, and "A new lead..." twenty
                      // times over said nothing about when (Jose, 2026-09-30).
                      leading: Badge(
                        key: ValueKey('notification-icon-${n['id']}'),
                        isLabelVisible: unread,
                        smallSize: 8,
                        backgroundColor: scheme.primary,
                        child: Icon(
                          icon ?? Icons.info_outline,
                          size: 20,
                          // A rule's "Mark as urgent (red)".
                          color: n['urgent'] == true
                              ? scheme.error
                              : unread
                                  ? scheme.primary
                                  : scheme.onSurfaceVariant,
                        ),
                      ),
                      // Header / what happened / content — the same three
                      // lines as the push (Jose, 2026-10-22).
                      title: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (n['header'] case final String header)
                            Text(
                              header,
                              key: ValueKey('notification-header-${n['id']}'),
                              style: Theme.of(context).textTheme.labelSmall
                                  ?.copyWith(color: scheme.onSurfaceVariant),
                            ),
                          Text(
                            n['title'] as String,
                            style: unread
                                ? const TextStyle(fontWeight: FontWeight.w600)
                                : null,
                          ),
                        ],
                      ),
                      subtitle: n['body'] != null
                          ? Text(n['body'] as String)
                          : null,
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          if (when.isNotEmpty)
                            Text(
                              when,
                              key: ValueKey('notification-when-${n['id']}'),
                              style: Theme.of(context).textTheme.bodySmall
                                  ?.copyWith(color: scheme.onSurfaceVariant),
                            ),
                          if (n['source'] == 'automation') ...[
                            const SizedBox(height: 4),
                            const Tag('Rule'),
                          ],
                        ],
                      ),
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

                        await _refresh();
                        if (!context.mounted) return;

                        /*
                         * GO TO WHAT IT IS ABOUT, if there is anywhere to go.
                         *
                         * Read from the REFRESHED list rather than from the row
                         * this closure captured: the refresh above re-reads the
                         * governed feed, and a notification that has since
                         * stopped being visible must not still navigate on its
                         * stale copy.
                         *
                         * Until today this did nothing but mark read, which for
                         * "New Live Chat Lead" meant being told about a lead and
                         * given no way to open it.
                         */
                        // With the filter (Jose asked for the Leads tab AND
                        // its chat filter) and the record, so Leads opens
                        // THAT lead rather than the newest.
                        final destination = _destinationOf(n['id'].toString());

                        if (destination != null && widget.onOpenModule != null) {
                          Navigator.of(context).pop();
                          _go(destination);
                          return;
                        }

                        // repaint, not setState: setState rebuilds the
                        // bell behind the panel, and the panel's own
                        // page is cached by the route.
                        repaint(() {});
                      },
                    );
                  },
                ),
            ],
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

/// "Notify me about": which companies this person hears from.
///
/// Bar, 2026-10-22 (via Jose): assigned to the whole group, he only wants
/// Everdoors — "everything under everdoors". Ticking a company covers its sub
/// companies and branches; none ticked means all, which is everybody's
/// default. Hidden for a person who cannot list companies, or when there is
/// only one, since there is nothing to choose between.
class CompanyFocus extends StatefulWidget {
  const CompanyFocus({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<CompanyFocus> createState() => _CompanyFocusState();
}

class _CompanyFocusState extends State<CompanyFocus> {
  /// (id, name, depth) — a company then its sub companies, indented.
  List<(String, String, int)> _companies = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/companies');
    if (!mounted || !response.ok) return;
    final rows = <(String, String, int)>[];
    // A switched-off company, and every Sub Company under it, is not offered
    // (Jose, 2026-11-10); Settings › Organization still lists them.
    for (final c in (response.body['companies'] as List? ?? const [])) {
      if (c['is_active'] == false) continue;
      rows.add((c['id'] as String, c['name'] as String, 0));
      for (final s in (c['sub_companies'] as List? ?? const [])) {
        if (s['is_active'] == false) continue;
        rows.add((s['id'] as String, s['name'] as String, 1));
      }
    }
    setState(() => _companies = rows);
  }

  Future<void> _toggle(String id, bool on) async {
    final chosen = widget.session.me?.notificationCompanies ?? const <String>[];
    final next = on ? [...chosen, id] : chosen.where((c) => c != id).toList();
    final saving = widget.session.setPreference('notifications.companies', next);
    setState(() {});
    await saving;
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (_companies.length < 2) return const SizedBox.shrink();
    final chosen = widget.session.me?.notificationCompanies ?? const <String>[];

    return ExpansionTile(
      key: const ValueKey('notification-company-focus'),
      tilePadding: EdgeInsets.zero,
      dense: true,
      title: const Text('Notify me about'),
      subtitle: Text(
        chosen.isEmpty
            ? 'All companies'
            : _companies.where((c) => chosen.contains(c.$1)).map((c) => c.$2).join(', '),
      ),
      children: [
        for (final (id, name, depth) in _companies)
          CheckboxListTile(
            dense: true,
            contentPadding: EdgeInsets.only(left: 16.0 * depth),
            controlAffinity: ListTileControlAffinity.leading,
            value: chosen.contains(id),
            title: Text(name),
            onChanged: (on) => _toggle(id, on ?? false),
          ),
      ],
    );
  }
}

/// The bell panel's settings page: Alert me, which companies, which modules.
///
/// Jose, 2026-11-13: "the settings should be with an icon ... we should also
/// add the ability to choose notifications from specific modules". A module
/// switched off still collects in the bell; it just stops popping up, pushing
/// and emailing (NotificationDispatcher reads notifications.muted_modules).
class NotificationSettings extends StatefulWidget {
  const NotificationSettings({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NotificationSettings> createState() => _NotificationSettingsState();
}

class _NotificationSettingsState extends State<NotificationSettings> {
  Future<void> _save(String key, Object value) async {
    final saving = widget.session.setPreference(key, value);
    setState(() {});
    await saving;
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final muted = me?.notificationMutedModules ?? const <String>[];
    final modules = me?.reachableModules ?? const [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          value: me?.notificationAlerts ?? true,
          title: const Text('Alert me'),
          subtitle: const Text('Pop-ups, phone and email. Notifications still collect in the bell.'),
          onChanged: (on) => _save('notifications.alerts', on),
        ),
        CompanyFocus(api: widget.api, session: widget.session),
        const Divider(height: 1),
        Padding(
          padding: const EdgeInsets.only(top: 12, bottom: 4),
          child: Text('Alert me about', style: Theme.of(context).textTheme.titleSmall),
        ),
        Text(
          'Off still collects in the bell, without pop-ups, phone or email.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        for (final m in modules)
          SwitchListTile(
            key: ValueKey('notification-module-${m.key}'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            value: !muted.contains(m.key),
            title: Text(m.label),
            onChanged: (on) => _save(
              'notifications.muted_modules',
              on ? muted.where((k) => k != m.key).toList() : [...muted, m.key],
            ),
          ),
      ],
    );
  }
}
