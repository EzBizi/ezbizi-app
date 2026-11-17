import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/os_notifications.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/notification_bell.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The bell keeping itself current.
///
/// It used to fetch once, in initState, and then only when somebody opened the
/// panel — so the badge showed whatever had been true at sign-in for anybody
/// who left the app open, which is how it is normally used. Both tests here
/// fail if that single fetch comes back.
///
/// What is NOT asserted, and deliberately: whether an operating-system banner
/// appeared. That is [OsNotifications], it needs a permission grant nobody can
/// give in a widget test, and on this harness it is a no-op by construction —
/// so an assertion about it would pass whatever the code did, which is worse
/// than no assertion at all. What these do cover is that the poll drives the
/// count, which is the part a person actually sees.
void main() {
  testWidgets('the badge follows the server without the panel being opened', (
    tester,
  ) async {
    var unread = 0;

    await _pump(tester, unread: () => unread);

    expect(find.text('1'), findsNothing);

    // Something happens on the server. Nobody touches the app.
    unread = 1;

    // Just past the poll interval.
    await tester.pump(const Duration(seconds: 61));
    await tester.pumpAndSettle();

    expect(
      find.text('1'),
      findsOneWidget,
      reason: 'the timer, not a tap, is what fetched this',
    );
  });

  testWidgets('coming back to the app refetches immediately', (tester) async {
    var unread = 0;

    await _pump(tester, unread: () => unread);

    unread = 4;

    // A backgrounded app's timers are throttled or frozen — on the web a hidden
    // tab may not tick at all — so returning to the app has to fetch on its own
    // rather than wait for the next tick that may be up to a minute away.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(find.text('4'), findsOneWidget);
  });

  /// Jose, 2026-09-30: twenty rows of "A new lead from Local Services has
  /// arrived." with nothing to say when, each behind the same circle.
  testWidgets('an urgent notification draws its icon in the error colour', (
    tester,
  ) async {
    final at = DateTime.now().toUtc().toIso8601String();
    Map<String, dynamic> row(String id, bool urgent) => {
      'id': id,
      'source': 'automation',
      'title': 'Row $id',
      'body': null,
      'urgent': urgent,
      'subject': {'type': null, 'id': null},
      'meta': {'module': 'leads', 'filter': null},
      'read_at': null,
      'created_at': at,
    };

    await _pumpWithItem(
      tester,
      item: row('u1', true),
      also: [row('n1', false)],
      onOpenModule: (_, _, _) {},
      iconOf: (key) => Icons.call_received_outlined,
    );
    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    Color? colourOf(String id) => tester
        .widget<Icon>(
          find.descendant(
            of: find.byKey(ValueKey('notification-icon-$id')),
            matching: find.byType(Icon),
          ),
        )
        .color;
    final scheme = Theme.of(
      tester.element(find.byKey(const ValueKey('notification-icon-u1'))),
    ).colorScheme;
    expect(colourOf('u1'), scheme.error);
    expect(colourOf('n1'), scheme.primary);
  });

  testWidgets('the inbox groups by day and filters to assigned and unread', (
    tester,
  ) async {
    final now = DateTime.now().toUtc();
    Map<String, dynamic> row(
      String id,
      DateTime at, {
      String? kind,
      bool read = false,
    }) => {
      'id': id,
      'source': 'automation',
      'title': 'Row $id',
      'body': null,
      'urgent': false,
      'kind': kind,
      'subject': {'type': null, 'id': null},
      'meta': {'module': null, 'filter': null},
      'read_at': read ? now.toIso8601String() : null,
      'created_at': at.toIso8601String(),
    };

    await _pumpWithItem(
      tester,
      item: row('a', now, kind: 'assigned'),
      also: [
        row('b', now.subtract(const Duration(days: 3)), read: true),
        row('c', now.subtract(const Duration(days: 30))),
      ],
      onOpenModule: (_, _, _) {},
    );
    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Last 7 days'), findsOneWidget);
    expect(find.text('Older'), findsOneWidget);

    await tester.tap(find.text('Assigned to me'));
    await tester.pumpAndSettle();
    expect(find.text('Row a'), findsOneWidget);
    expect(find.text('Row c'), findsNothing);

    // The test font is wide, so the tab strip scrolls in the narrow panel and
    // "All" sits off its left edge once "Assigned to me" is chosen.
    await tester.ensureVisible(find.text('All'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notifications-unread-only')));
    await tester.pumpAndSettle();
    expect(find.text('Row b'), findsNothing);
    expect(find.text('Row c'), findsOneWidget);

    // Unread rows are tinted, read ones are not (Jose, 2026-11-16).
    ListTile tile(String id) =>
        tester.widget(find.byKey(ValueKey('notification-row-$id')));
    expect(tile('c').tileColor, isNotNull);
    await tester.tap(find.byKey(const Key('notifications-unread-only')));
    await tester.pumpAndSettle();
    expect(tile('b').tileColor, isNull);

    // The search narrows the list.
    await tester.enterText(
      find.byKey(const Key('notifications-search')).first,
      'row c',
    );
    await tester.pumpAndSettle();
    expect(find.text('Row c'), findsOneWidget);
    expect(find.text('Row a'), findsNothing);
  });

  testWidgets('a notification wears the icon of its module and says when', (
    tester,
  ) async {
    final at = DateTime.now().subtract(const Duration(minutes: 1));

    await _pumpWithItem(
      tester,
      item: {
        'id': 'n1',
        'source': 'automation',
        'title': 'A new lead from Local Services has arrived.',
        'body': null,
        'subject': {'type': 'Lead', 'id': 'lead-1'},
        'meta': {'module': 'leads', 'filter': null},
        'read_at': null,
        'created_at': at.toUtc().toIso8601String(),
      },
      onOpenModule: (_, _, _) {},
      iconOf: (key) => key == 'leads' ? Icons.call_received_outlined : null,
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('notification-icon-n1')),
        matching: find.byIcon(Icons.call_received_outlined),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('notification-when-n1')),
      findsOneWidget,
      reason: 'when it happened, on the row',
    );
  });

  testWidgets('tapping a notification opens the module it is about', (
    tester,
  ) async {
    // Until 2026-09-16 this did nothing but mark it read. Being told "New Live
    // Chat Lead" and given no way to open the lead is the whole complaint.
    final opened = <String>[];
    String? filter;
    String? record;

    await _pumpWithItem(
      tester,
      item: {
        'id': 'n1',
        'source': 'automation',
        'title': 'New Live Chat Lead',
        'body': null,
        'subject': {'type': 'Lead', 'id': 'lead-1'},
        'meta': {'module': 'leads', 'filter': 'website_chat'},
        'read_at': null,
        'created_at': '2026-09-16T12:00:00+00:00',
      },
      onOpenModule: (m, f, r) {
        opened.add(m);
        filter = f;
        record = r;
      },
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New Live Chat Lead'));
    await tester.pumpAndSettle();

    expect(opened, ['leads']);
    // The chat filter travels with the module. Without it the tap lands on a
    // list of every source, which is where the person already was.
    expect(filter, 'website_chat');
    // And the record it is about, so Leads opens THAT lead, not the newest.
    expect(record, 'lead-1');
  });

  testWidgets(
    'clicking its desktop toast does exactly what tapping its row does',
    (tester) async {
      // Jose, 2026-09-29: "clicking on notification on desktop does nothing, it
      // should lead to whatever it's warning about". The plugin delivered the
      // click; nothing was listening. The toast carries the notification's id,
      // and the click must mark it read and go where the row goes — module,
      // filter AND record — without the panel ever being opened.
      final opened = <String>[];
      final posted = <String>[];
      String? filter;
      String? record;

      await _pumpWithItem(
        tester,
        item: {
          'id': 'n1',
          'source': 'automation',
          'title': 'New Live Chat Lead',
          'body': null,
          'subject': {'type': 'Lead', 'id': 'lead-1'},
          'meta': {'module': 'leads', 'filter': 'website_chat'},
          'read_at': null,
          'created_at': '2026-09-16T12:00:00+00:00',
        },
        onOpenModule: (m, f, r) {
          opened.add(m);
          filter = f;
          record = r;
        },
        posted: posted,
      );

      // What the OS delivers on a click, with the payload the toast was shown with.
      OsNotifications.onClick?.call('n1');
      await tester.pumpAndSettle();

      expect(opened, ['leads']);
      expect(filter, 'website_chat');
      expect(record, 'lead-1');
      expect(posted, contains(endsWith('/notifications/n1/read')));
    },
  );

  testWidgets(
    'clicking the "N new notifications" toast opens the panel that holds them',
    (tester) async {
      // The summary toast carries no id: its answer is the list. Asserted by the
      // row's title appearing, which only the open panel draws — and by nothing
      // having navigated, so this cannot pass by accident through the path above.
      final opened = <String>[];

      await _pumpWithItem(
        tester,
        item: {
          'id': 'n3',
          'source': 'automation',
          'title': 'New Live Chat Lead',
          'body': null,
          'subject': {'type': 'Lead', 'id': 'lead-1'},
          'meta': {'module': 'leads', 'filter': 'website_chat'},
          'read_at': null,
          'created_at': '2026-09-16T12:00:00+00:00',
        },
        onOpenModule: (m, f, r) => opened.add(m),
      );

      expect(
        find.text('New Live Chat Lead'),
        findsNothing,
        reason: 'Precondition: the panel is closed.',
      );

      OsNotifications.onClick?.call(null);
      await tester.pumpAndSettle();

      expect(find.text('New Live Chat Lead'), findsOneWidget);
      expect(opened, isEmpty);
    },
  );

  testWidgets('a notification about nothing openable navigates nowhere', (
    tester,
  ) async {
    // The control. `meta.module` is null for a notification with no subject —
    // "your trial ends tomorrow" has nowhere more specific to go than where
    // you already are. Without this, navigating on EVERY tap would pass the
    // test above just as well, and every such notification would throw you out
    // of whatever you were looking at.
    final opened = <String>[];

    await _pumpWithItem(
      tester,
      item: {
        'id': 'n2',
        'source': 'system',
        'title': 'Your trial ends tomorrow',
        'body': null,
        'subject': {'type': null, 'id': null},
        'meta': {'module': null, 'filter': null},
        'read_at': null,
        'created_at': '2026-09-16T12:00:00+00:00',
      },
      onOpenModule: (m, f, r) => opened.add(m),
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Your trial ends tomorrow'));
    await tester.pumpAndSettle();

    expect(opened, isEmpty);
    // And the panel is still open, because nothing popped it.
    expect(find.text('Your trial ends tomorrow'), findsOneWidget);
  });

  // Bar, 2026-10-12: "a new email notification should open that email". The
  // subject is the MAILBOX; meta.record names the message, and that is what
  // must reach the Email screen — at desktop and at phone width.
  for (final width in [1280.0, 390.0]) {
    testWidgets(
      'a new-email notification hands Email that message (${width.toInt()}px)',
      (tester) async {
        await tester.binding.setSurfaceSize(Size(width, 800));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        addTearDown(() => DeepLinks.pending.value = null);
        final opened = <String>[];

        await _pumpWithItem(
          tester,
          item: _email,
          onOpenModule: (m, f, r) => opened.add(m),
        );

        await tester.tap(find.byIcon(Icons.notifications_none));
        await tester.pumpAndSettle();
        await tester.tap(find.text('New email'));
        await tester.pumpAndSettle();

        expect(opened, ['email']);
        final link = DeepLinks.take('email');
        expect(link?.type, 'email_message');
        expect(link?.id, 'msg-123');
        expect(link?.mailbox, 'info@a1.test');
        expect(
          DeepLinks.take('email'),
          isNull,
          reason: 'taken once, not reopened on rebuild',
        );
      },
    );
  }

  testWidgets('tapping a phone push opens what it is about, like its row', (
    tester,
  ) async {
    // The runner forwards the push's notification_id on this channel.
    addTearDown(() => DeepLinks.pending.value = null);
    final opened = <String>[];
    await _pumpWithItem(
      tester,
      item: _email,
      onOpenModule: (m, f, r) => opened.add(m),
    );

    await tester.binding.defaultBinaryMessenger.handlePlatformMessage(
      'com.ezbizi.app/notification_tap',
      const StandardMethodCodec().encodeMethodCall(
        const MethodCall('tapped', 'n9'),
      ),
      (_) {},
    );
    await tester.pumpAndSettle();

    expect(opened, ['email']);
    expect(DeepLinks.take('email')?.id, 'msg-123');
  });

  testWidgets(
    'a cold-start push tap, before the first fetch lands, still opens its record',
    (tester) async {
      // Android, 2026-10-12: from a stopped app the tap opened the PANEL — the
      // lookup ran against the still-empty list while the first fetch was out.
      addTearDown(() => DeepLinks.pending.value = null);
      final opened = <String>[];
      final api = ApiClient(
        client: MockClient((request) async {
          await Future<void>.delayed(const Duration(milliseconds: 50));
          if (request.method == 'POST') return _json({'data': {}});
          return _json({
            'data': [_email],
            'meta': {'unread': 1},
          });
        }),
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            appBar: AppBar(
              actions: [
                NotificationBell(
                  api: api,
                  onOpenModule: (m, f, r) => opened.add(m),
                ),
              ],
            ),
          ),
        ),
      );
      OsNotifications.onClick?.call('n9'); // first fetch still in flight
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 60));
      }

      expect(opened, ['email']);
      expect(DeepLinks.take('email')?.id, 'msg-123');
    },
  );

  testWidgets('a row leads with whose it is, like the push', (tester) async {
    // Jose, 2026-10-22: "EzBizi - Company - Branch", then what happened,
    // then the content — in the panel as on the phone.
    await _pumpWithItem(
      tester,
      item: {..._email, 'header': 'EzBizi - A-1 Chimney - Georgia'},
      onOpenModule: (_, _, _) {},
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    expect(find.text('EzBizi - A-1 Chimney - Georgia'), findsOneWidget);
    expect(find.text('New email'), findsOneWidget);
  });

  testWidgets('a task mention opens its board at the task and the update', (
    tester,
  ) async {
    // Jose, 2026-11-17: "clicking on the notification should lead you to the
    // board". The Task Board takes 'tasks', finds the board, opens the task.
    addTearDown(() => DeepLinks.pending.value = null);
    final opened = <String>[];
    await _pumpWithItem(
      tester,
      item: {
        'id': 'n-task',
        'source': 'system',
        'title': 'Ana mentioned you',
        'body': null,
        'subject': {'type': 'TaskUpdate', 'id': 'upd-1'},
        'meta': {
          'module': 'tasks',
          'filter': null,
          'record': {'type': 'task', 'id': 'task-1', 'update': 'upd-1'},
        },
        'read_at': null,
        'created_at': '2026-11-17T12:00:00+00:00',
      },
      onOpenModule: (m, f, r) => opened.add(m),
    );

    OsNotifications.onClick?.call('n-task'); // a push or toast tap
    await tester.pumpAndSettle();

    expect(opened, ['tasks']);
    final link = DeepLinks.take('tasks');
    expect(link?.id, 'task-1');
    expect(link?.update, 'upd-1');
  });

  testWidgets('a link for another module waits for that module', (
    tester,
  ) async {
    addTearDown(() => DeepLinks.pending.value = null);
    DeepLinks.open(
      const DeepLink(module: 'email', type: 'email_message', id: 'm'),
    );

    expect(DeepLinks.take('leads'), isNull);
    expect(DeepLinks.take('email')?.id, 'm');
  });

  // Jose, 2026-10-05: "an option turn notifications on or off (individually
  // for each user, something they can inside the notification button sidebar)".
  testWidgets(
    "the panel holds each person's own Alert me switch, and it saves",
    (tester) async {
      final patches = <Object?>[];
      await _pumpWithSession(
        tester,
        preferences: {},
        items: () => [],
        patches: patches,
      );

      await tester.tap(find.byIcon(Icons.notifications_none));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('notification-settings')));
      await tester.pumpAndSettle();

      final alertMe = find.widgetWithText(SwitchListTile, 'Alert me');
      expect(
        tester.widget<SwitchListTile>(alertMe).value,
        isTrue,
        reason: 'Never touched means on, or every existing user goes silent.',
      );

      await tester.tap(alertMe);
      await tester.pumpAndSettle();

      expect(patches, [
        {
          'preferences': {'notifications.alerts': false},
        },
      ]);
      expect(tester.widget<SwitchListTile>(alertMe).value, isFalse);
    },
  );

  testWidgets('a person can choose to hear only from one company', (
    tester,
  ) async {
    // Bar, 2026-10-22: "only the Everdoors notifications".
    final patches = <Object?>[];
    await _pumpWithSession(
      tester,
      preferences: {},
      items: () => [],
      patches: patches,
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-settings')));
    await tester.pumpAndSettle();
    expect(
      find.text('All companies'),
      findsOneWidget,
      reason: 'nobody has chosen: all',
    );

    await tester.tap(find.text('Notify me about'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(CheckboxListTile, 'Everdoors'));
    await tester.pumpAndSettle();

    expect(patches, [
      {
        'preferences': {
          'notifications.companies': ['c-ever'],
        },
      },
    ]);
  });

  // Jose, 2026-11-13: the panel shows only notifications; the gear opens the
  // settings, which include an on/off per module.
  testWidgets(
    'the panel holds only notifications; the gear opens per-module settings',
    (tester) async {
      final patches = <Object?>[];
      await _pumpWithSession(
        tester,
        preferences: {},
        items: () => [],
        patches: patches,
      );

      await tester.tap(find.byIcon(Icons.notifications_none));
      await tester.pumpAndSettle();
      expect(
        find.text('Alert me'),
        findsNothing,
        reason: 'settings are behind the gear',
      );

      await tester.tap(find.byKey(const Key('notification-settings')));
      await tester.pumpAndSettle();
      expect(find.text('Notification settings'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('notification-module-leads')));
      await tester.pumpAndSettle();

      expect(patches, [
        {
          'preferences': {
            'notifications.muted_modules': ['leads'],
          },
        },
      ]);
    },
  );

  testWidgets('each task notification kind can be switched off', (tester) async {
    // Jose, 2026-11-17: task board coverage with a per-type toggle.
    final patches = <Object?>[];
    await _pumpWithSession(
      tester,
      preferences: {},
      items: () => [],
      patches: patches,
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-settings')));
    await tester.pumpAndSettle();

    final mentioned = find.byKey(
      const ValueKey('notification-kind-task.mentioned'),
    );
    await tester.ensureVisible(mentioned);
    await tester.tap(mentioned);
    await tester.pumpAndSettle();

    expect(patches, [
      {
        'preferences': {
          'notifications.muted_kinds': ['task.mentioned'],
        },
      },
    ]);
  });

  testWidgets('with alerts on, a new notification pops up (the control)', (
    tester,
  ) async {
    expect(await _popUpsAfterAnArrival(tester, alerts: true), [
      'New Live Chat Lead',
    ]);
  });

  testWidgets('with alerts off, the same notification does not pop up', (
    tester,
  ) async {
    // Same arrival, same bell, one preference different. Without the control
    // above, this would pass just as well if pop-ups had stopped working.
    expect(await _popUpsAfterAnArrival(tester, alerts: false), isEmpty);
  });

  // UI queue 65, Jose: "2". Every unread alert, from meta.alerts, pops up in
  // the middle of the screen until it is dismissed.
  testWidgets('an alert older than the page still pops up, until dismissed', (
    tester,
  ) async {
    final open = [
      {
        'id': 'a1',
        'title': 'Storm coming in: finish outdoor jobs by 3 PM.',
        'body': 'From Dana Reyes',
        'sender': 'Dana Reyes',
        'created_at': '2026-10-07T12:00:00+00:00',
      },
    ];
    final posted = <String>[];
    await _pumpAlerts(tester, open: open, posted: posted);

    // On screen without anybody opening the bell, though the page of
    // notifications itself holds none of it.
    expect(find.byKey(const Key('alert-popup')), findsOneWidget);
    expect(
      find.text('Storm coming in: finish outdoor jobs by 3 PM.'),
      findsOneWidget,
    );
    expect(find.text('Alert from Dana Reyes'), findsOneWidget);

    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();

    expect(posted, ['/api/v1/notifications/a1/read']);
    expect(find.byKey(const Key('alert-popup')), findsNothing);
  });

  testWidgets('no open alert, no pop-up (the control)', (tester) async {
    await _pumpAlerts(tester, open: [], posted: []);

    expect(find.byKey(const Key('alert-popup')), findsNothing);
  });
}

/// A bell with a signed-in session, whose list answers [items] on each fetch.
Future<void> _pumpWithSession(
  WidgetTester tester, {
  required Map<String, dynamic> preferences,
  required List<Map<String, dynamic>> Function() items,
  List<Object?>? patches,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Jose Silva',
            'email': 'jose@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Administrator'},
            'permissions': <String>[],
            'modules': [
              {
                'key': 'leads',
                'label': 'Leads',
                'available': true,
                'reachable': true,
              },
              {
                'key': 'tasks',
                'label': 'Task Board',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': preferences,
            'organization': {
              'id': 'org-1',
              'name': 'A1-Group',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'companies': <Map<String, Object?>>[],
            'franchises': <Map<String, Object?>>[],
          },
        });
      }
      if (path.endsWith('/companies')) {
        return _json({
          'companies': [
            {'id': 'c-ever', 'name': 'Everdoors', 'sub_companies': <Object>[]},
            {'id': 'c-a1', 'name': 'A-1 Chimney', 'sub_companies': <Object>[]},
          ],
        });
      }
      if (request.method == 'PATCH') {
        patches?.add(jsonDecode(request.body));
        return _json({'data': {}});
      }
      if (request.method == 'POST') return _json({'data': {}});

      final data = items();
      return _json({
        'data': data,
        'meta': {'unread': data.where((i) => i['read_at'] == null).length},
      });
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(
          actions: [NotificationBell(api: api, session: session)],
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// What the bell asks the OS to show when one new notification arrives.
Future<List<String>> _popUpsAfterAnArrival(
  WidgetTester tester, {
  required bool alerts,
}) async {
  final shown = <String>[];
  OsNotifications.shownForTest = shown;
  addTearDown(() => OsNotifications.shownForTest = null);

  // Empty on the first fetch, which seeds the bell without announcing; the
  // notification arrives on the next one.
  var items = <Map<String, dynamic>>[];
  await _pumpWithSession(
    tester,
    preferences: {'notifications.alerts': alerts},
    items: () => items,
  );

  items = [
    {
      'id': 'n9',
      'source': 'automation',
      'title': 'New Live Chat Lead',
      'body': null,
      'subject': {'type': 'Lead', 'id': 'lead-1'},
      'meta': {'module': 'leads', 'filter': 'website_chat'},
      'read_at': null,
      'created_at': '2026-10-05T12:00:00+00:00',
    },
  ];
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  await tester.pumpAndSettle();

  return shown;
}

/// One notification in the list, and a spy for where a tap sends you.
/// A bell whose server lists [open] under meta.alerts, and forgets one once it
/// is marked read.
Future<void> _pumpAlerts(
  WidgetTester tester, {
  required List<Map<String, dynamic>> open,
  required List<String> posted,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST') {
        posted.add(request.url.path);
        open.removeWhere(
          (alert) => request.url.path.endsWith('/${alert['id']}/read'),
        );
        return _json({'data': {}});
      }

      return _json({
        'data': <Map<String, dynamic>>[],
        'meta': {'unread': open.length, 'alerts': open},
      });
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(
          actions: [NotificationBell(api: api, onOpenModule: (_, _, _) {})],
        ),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

const _email = {
  'id': 'n9',
  'source': 'automation',
  'title': 'New email',
  'body': null,
  'subject': {'type': 'Mailbox', 'id': 'mbx-1'},
  'meta': {
    'module': 'email',
    'filter': null,
    'record': {
      'type': 'email_message',
      'id': 'msg-123',
      'mailbox': 'info@a1.test',
    },
  },
  'read_at': null,
  'created_at': '2026-10-12T12:00:00+00:00',
};

Future<void> _pumpWithItem(
  WidgetTester tester, {
  required Map<String, dynamic> item,
  required void Function(String, String?, String?) onOpenModule,
  List<String>? posted,
  List<Map<String, dynamic>>? also,
  IconData? Function(String)? iconOf,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST') {
        posted?.add(request.url.path);
        return _json({'data': {}});
      }

      return _json({
        'data': [item, ...?also],
        'meta': {'unread': item['read_at'] == null ? 1 : 0},
      });
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(
          actions: [
            NotificationBell(
              api: api,
              onOpenModule: onOpenModule,
              iconOf: iconOf,
            ),
          ],
        ),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  required int Function() unread,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.contains('/notifications')) {
        return _json({
          'data': <Map<String, dynamic>>[],
          'meta': {'unread': unread()},
        });
      }

      return _json({'data': <Map<String, dynamic>>[]});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(actions: [NotificationBell(api: api)]),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
