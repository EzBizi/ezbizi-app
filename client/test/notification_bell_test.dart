import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/notification_bell.dart';
import 'package:flutter/material.dart';
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

  testWidgets('tapping a notification opens the module it is about', (
    tester,
  ) async {
    // Until 2026-09-16 this did nothing but mark it read. Being told "New Live
    // Chat Lead" and given no way to open the lead is the whole complaint.
    final opened = <String>[];
    String? filter;

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
      onOpenModule: (m, f) {
        opened.add(m);
        filter = f;
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
  });

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
      onOpenModule: (m, f) => opened.add(m),
    );

    await tester.tap(find.byIcon(Icons.notifications_none));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Your trial ends tomorrow'));
    await tester.pumpAndSettle();

    expect(opened, isEmpty);
    // And the panel is still open, because nothing popped it.
    expect(find.text('Your trial ends tomorrow'), findsOneWidget);
  });
}

/// One notification in the list, and a spy for where a tap sends you.
Future<void> _pumpWithItem(
  WidgetTester tester, {
  required Map<String, dynamic> item,
  required void Function(String, String?) onOpenModule,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST') return _json({'data': {}});

      return _json({
        'data': [item],
        'meta': {'unread': item['read_at'] == null ? 1 : 0},
      });
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(
          actions: [NotificationBell(api: api, onOpenModule: onOpenModule)],
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
      home: Scaffold(appBar: AppBar(actions: [NotificationBell(api: api)])),
    ),
  );

  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
