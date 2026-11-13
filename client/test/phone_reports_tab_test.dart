import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/reports_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Communication › Reports.
void main() {
  // A by-day report has no labels, and PHP sends an empty array as `[]`. Read
  // as a map, that threw after the answer arrived: the spinner never stopped
  // and the day rows never drew. It was the report the tab opens on.
  testWidgets('a report whose labels come back as [] still draws its rows', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/reports/run')) {
          return _json({
            'data': {
              'totals': {'calls': 3, 'missed': 1},
              'rows': [
                {'bucket': '2026-09-27', 'calls': 3, 'missed': 1},
              ],
              'labels': <Object>[],
            },
          });
        }
        if (path.endsWith('/phone/reports')) {
          return _json({'data': <Object>[], 'meta': <String, Object>{}});
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportsTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('2026-09-27'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });

  // Jose, 2026-10-05: "what client pressed on the ivr menu". The key is a
  // sentence, and a call where nothing was pressed says so.
  testWidgets('by key pressed names each key and the calls with none', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/reports/run')) {
          final key = request.url.queryParameters['group_by'] == 'key';
          return _json({
            'data': {
              'totals': {'calls': 3, 'missed': 1},
              'rows': key
                  ? [
                      {'bucket': '1', 'calls': 2, 'missed': 0},
                      {'bucket': '', 'calls': 1, 'missed': 1},
                    ]
                  : [
                      {'bucket': '2026-09-27', 'calls': 3, 'missed': 1},
                    ],
              'labels': <Object>[],
            },
          });
        }
        if (path.endsWith('/phone/reports')) {
          return _json({'data': <Object>[], 'meta': <String, Object>{}});
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportsTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('report-grouping')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('By key pressed in the menu').last);
    await tester.pumpAndSettle();

    expect(find.text('Pressed 1'), findsOneWidget);
    expect(find.text('No key pressed'), findsOneWidget);
  });

  // Tiles over a table (Mia, 2026-10-02). Incoming narrows by the Direction
  // filter the run endpoint has; the tiles keep counting the whole period,
  // so Outgoing still says 1 while Incoming is picked. Missed has no filter
  // behind it and must not pretend to.
  testWidgets('the Incoming tile counts the whole and narrows by direction', (
    tester,
  ) async {
    final runs = <Uri>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/reports/run')) {
          runs.add(request.url);
          final inbound = request.url.query.contains('direction=inbound');
          return _json({
            'data': {
              'totals': inbound
                  ? {'calls': 2, 'inbound': 2, 'outbound': 0, 'missed': 1}
                  : {
                      'calls': 3,
                      'inbound': 2,
                      'outbound': 1,
                      'missed': 1,
                      'missed_rate': 33.3,
                      'average_seconds': 90,
                      'total_seconds': 270,
                    },
              'rows': [
                {'bucket': 'inbound', 'calls': 2, 'missed': 1},
                if (!inbound) {'bucket': 'outbound', 'calls': 1, 'missed': 0},
              ],
              'labels': <Object>[],
            },
          });
        }
        if (path.endsWith('/phone/reports')) {
          return _json({
            'data': [
              {
                'id': 'r-1',
                'name': 'Missed by line',
                'period': 'last_7',
                'group_by': 'line',
                'direction': 'inbound',
                'number_ids': <String>[],
                'tag_ids': <String>[],
              },
            ],
            'meta': <String, Object>{},
          });
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportsTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    Finder inTile(String key, String text) => find.descendant(
      of: find.byKey(Key('reports-tile-$key')),
      matching: find.text(text),
    );

    expect(inTile('all', '3'), findsOneWidget);
    expect(inTile('inbound', '2'), findsOneWidget);
    expect(inTile('outbound', '1'), findsOneWidget);
    expect(inTile('missed', '33.3% of calls'), findsOneWidget);
    expect(inTile('average', '1m'), findsOneWidget);
    expect(find.byKey(const ValueKey('report-chart')), findsOneWidget);
    expect(find.byKey(const ValueKey('report-row:outbound')), findsOneWidget);
    expect(find.text('Last 7 days · By line · Incoming'), findsOneWidget);

    // Missed filters nothing: tapping it asks the server nothing new.
    final before = runs.length;
    await tester.tap(find.byKey(const Key('reports-tile-missed')));
    await tester.pumpAndSettle();
    expect(runs.length, before);

    await tester.tap(find.byKey(const Key('reports-tile-inbound')));
    await tester.pumpAndSettle();

    expect(runs.any((u) => u.query.contains('direction=inbound')), isTrue);
    expect(find.byKey(const ValueKey('report-row:outbound')), findsNothing);
    expect(inTile('outbound', '1'), findsOneWidget);
    expect(inTile('all', '3'), findsOneWidget);

    // Tapping it again clears the filter.
    await tester.tap(find.byKey(const Key('reports-tile-inbound')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('report-row:outbound')), findsOneWidget);
  });

  // UI/UX audit 2026-11-13, §10.1: Jose's Reports said "No calls in this
  // period" while Records listed 317. The server had sent him 15 calls for
  // the last 30 days (576 bytes in the access log); the screen drew the
  // empty totals before that answer was read, and empty read as zero. The
  // answer below is the one he was sent. A failed count is not zero either.
  testWidgets('no "No calls" before the count answers, or when it fails', (
    tester,
  ) async {
    final answer = Completer<http.Response>();
    var fail = false;
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/reports/run')) {
          if (fail) return http.Response('{"message":"Down."}', 500);
          return answer.future;
        }
        if (path.endsWith('/phone/reports')) {
          return _json({'data': <Object>[], 'meta': <String, Object>{}});
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    Future<void> open() async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: ReportsTab(key: UniqueKey(), api: api, session: session),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
    }

    await open();
    expect(find.textContaining('No calls in this period'), findsNothing);
    expect(find.text('Counting the calls…'), findsOneWidget);

    answer.complete(
      _json({
        'data': {
          'totals': {
            'calls': 15,
            'inbound': 4,
            'outbound': 11,
            'missed': 0,
            'voicemails': 0,
            'untagged': 15,
            'total_seconds': 591,
            'average_seconds': 39,
            'missed_rate': 0,
          },
          'rows': [
            {'bucket': '2026-10-16', 'calls': 11, 'missed': 0, 'seconds': 548},
          ],
          'labels': <String, Object>{},
        },
      }),
    );
    await tester.pumpAndSettle();
    expect(find.text('15'), findsWidgets);
    expect(find.text('Counting the calls…'), findsNothing);
    expect(find.textContaining('No calls in this period'), findsNothing);

    fail = true;
    await open();
    await tester.pumpAndSettle();
    expect(find.text('Down.'), findsOneWidget);
    expect(find.textContaining('No calls in this period'), findsNothing);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
