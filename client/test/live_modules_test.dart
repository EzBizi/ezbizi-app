import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/live_changes.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:ezbizi_client/screens/inventory/item_list.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:ezbizi_client/screens/scheduling/schedule_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every module follows the change feed (Jose, 2026-11-16: "The tab needs to
/// be live basically. This should go tbh for every module in the app"): a
/// write made elsewhere shows on the open screen at the next tick, with no
/// reload, and a scrolled list stays where it was.
void main() {
  testWidgets('Customers: a changed customer shows, scroll kept', (
    tester,
  ) async {
    final rows = [
      for (var i = 0; i < 40; i++)
        {
          'id': 'c$i',
          'name': 'Customer $i',
          'number': 'C-$i',
          'email': null,
          'phone': null,
          'city': 'Dallas',
          'state': 'TX',
          'is_active': true,
          'jobs_count': 0,
          'owning_franchise': {
            'id': 'fr-1',
            'name': 'Texas',
            'company': {'id': 'co-1', 'name': 'A-1'},
          },
        },
    ];
    final s = await _pump(
      tester,
      permissions: ['customers.view'],
      handler: (r) => r.url.path.endsWith('/customers')
          ? _json({
              'data': rows,
              'meta': {'total': 40, 'last_page': 1, 'current_page': 1},
            })
          : null,
      home: (api, session) => CustomersScreen(api: api, session: session),
    );
    await _scrolled(tester, s, () {
      rows[7] = {...rows[7], 'name': 'Customer 7 renamed by Workiz'};
      return {'type': 'customers', 'id': 'c7'};
    }, 'Customer 7 renamed by Workiz');
  });

  testWidgets('Leads: a synced lead shows without the list dimming', (
    tester,
  ) async {
    final rows = [for (var i = 0; i < 30; i++) _lead('l$i', 'Lead person $i')];
    final s = await _pump(
      tester,
      size: const Size(1200, 900),
      permissions: [
        'leads.view',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
      handler: (r) {
        final one = RegExp(r'/leads/([^/]+)$').firstMatch(r.url.path);
        if (one != null) {
          return _json({
            'data': {
              ...rows.firstWhere((l) => l['id'] == one.group(1)),
              'conversations': const <dynamic>[],
              'customer_matches': const <dynamic>[],
            },
          });
        }
        return r.url.path.endsWith('/leads')
            ? _json({
                'data': rows,
                'meta': {
                  'total': rows.length,
                  'per_page': 50,
                  'current_page': 1,
                  'last_page': 1,
                  'connections': [
                    {
                      'provider': 'google_ads',
                      'display_name': 'A1',
                      'status': 'connected',
                    },
                  ],
                },
              })
            : null;
      },
      home: (api, session) => LeadsScreen(api: api, session: session),
    );
    expect(find.text('Lead person 0'), findsWidgets);
    final reads = s.reads['/leads'] ?? 0;
    rows[0] = {...rows[0], 'contact_name': 'Webhook Wanda'};
    s.feed = [
      {'type': 'leads', 'id': 'l0', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pump();
    expect(
      find.byType(LinearProgressIndicator),
      findsNothing,
      reason: 'a live re-read never dims the list',
    );
    await tester.pumpAndSettle();
    expect(s.reads['/leads'], reads + 1);
    expect(find.text('Webhook Wanda'), findsWidgets);
  });

  testWidgets('Project Management: a job saved elsewhere re-reads the table', (
    tester,
  ) async {
    var customer = 'Person 3';
    final s = await _pump(
      tester,
      size: const Size(1440, 900),
      permissions: ['project_board.view', 'jobs.view'],
      handler: (r) {
        if (r.url.path.endsWith('/project-board/options')) {
          return _json({'data': <String, dynamic>{}});
        }
        if (!r.url.path.endsWith('/project-board/jobs')) return null;
        return _json({
          'data': [
            for (var i = 0; i < 8; i++)
              {
                'id': 'j$i',
                'number': 'J-$i',
                'customer': i == 3 ? customer : 'Person $i',
                'status': 'Pending',
                'ready_missing': <String>[],
                'tags': <String>[],
              },
          ],
          'meta': {
            'total': 8,
            'last_page': 1,
            'counts': {'unscheduled': 8},
            'places': <dynamic>[],
            'columns': [
              for (final k in ['number', 'customer', 'status'])
                {'key': k, 'label': k, 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'can_edit': false,
            'sortable': <String>[],
          },
        });
      },
      home: (api, session) => ProjectBoardScreen(api: api, session: session),
    );
    expect(find.text('Person 3'), findsOneWidget);
    customer = 'Moved by Workiz';
    s.feed = [
      {'type': 'jobs', 'id': 'j3', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.text('Moved by Workiz'), findsOneWidget);
  });

  testWidgets('Scheduling: the open day shows a job booked elsewhere', (
    tester,
  ) async {
    var who = 'Tonya Yates';
    final s = await _pump(
      tester,
      size: const Size(1400, 1000),
      permissions: ['scheduling.view', 'scheduling.schedule.view'],
      handler: (r) => r.url.path.endsWith('/scheduling/board')
          ? _json({'data': _board(who)})
          : null,
      home: (api, session) =>
          SchedulePane(api: api, session: session, visible: true),
    );
    expect(find.textContaining('Tonya Yates'), findsWidgets);
    who = 'Booked by the website';
    s.feed = [
      {'type': 'jobs', 'id': 'J2', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.textContaining('Booked by the website'), findsWidgets);
  });

  testWidgets('Inventory: an item changed elsewhere shows on Items', (
    tester,
  ) async {
    var name = '6 mil liner';
    final s = await _pump(
      tester,
      permissions: ['inventory.view'],
      handler: (r) => r.url.path.endsWith('/inventory/items')
          ? _json({
              'data': [
                {
                  'id': 'item-1',
                  'number': 'I-00001',
                  'sku': 'LINER-6',
                  'name': name,
                  'unit': 'each',
                  'status': 'active',
                  'reorder_at': '5.000',
                  'on_hand': '4.000',
                  'company': {'id': 'co-1', 'name': 'Everdoors'},
                  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
                },
              ],
              'meta': {'total': 1, 'last_page': 1},
            })
          : null,
      home: (api, session) =>
          ItemList(api: api, session: session, visible: true),
    );
    expect(find.text('6 mil liner'), findsOneWidget);
    name = '8 mil liner';
    s.feed = [
      {'type': 'inventory', 'id': 'item-1', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.text('8 mil liner'), findsOneWidget);
  });

  testWidgets('a change of another type reads nothing again', (tester) async {
    final s = await _pump(
      tester,
      permissions: ['inventory.view'],
      handler: (r) => r.url.path.endsWith('/inventory/items')
          ? _json({
              'data': <dynamic>[],
              'meta': {'total': 0, 'last_page': 1},
            })
          : null,
      home: (api, session) =>
          ItemList(api: api, session: session, visible: true),
    );
    final reads = s.reads['/inventory/items'];
    s.feed = [
      {'type': 'leads', 'id': 'l1', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(s.reads['/inventory/items'], reads);
  });
}

class _Server {
  List<Map<String, dynamic>> feed = const [];
  int cursor = 1;
  final Map<String, int> reads = {};
  late ApiClient api;
}

/// Scrolls the list, makes one change, ticks the feed, and checks the new
/// [text] shows with the list where it stood.
Future<void> _scrolled(
  WidgetTester tester,
  _Server s,
  Map<String, Object> Function() change,
  String text,
) async {
  final scroll = tester.state<ScrollableState>(find.byType(Scrollable).last);
  scroll.position.jumpTo(300);
  await tester.pump();
  final before = scroll.position.pixels;
  expect(before, greaterThan(0));
  s.feed = [
    {...change(), 'deleted': false},
  ];
  LiveChanges.of(s.api).poll();
  await tester.pumpAndSettle();
  expect(find.text(text), findsOneWidget);
  expect(
    tester.state<ScrollableState>(find.byType(Scrollable).last).position.pixels,
    before,
  );
}

Future<_Server> _pump(
  WidgetTester tester, {
  required List<String> permissions,
  required http.Response? Function(http.Request request) handler,
  required Widget Function(ApiClient api, Session session) home,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final s = _Server();
  s.api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      s.reads[path] = (s.reads[path] ?? 0) + 1;
      if (path == '/me') {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path == '/changes') {
        final since = request.url.queryParameters['since'];
        final out = since == null ? const [] : s.feed;
        s.feed = const [];
        if (out.isNotEmpty) s.cursor++;
        return _json({
          'data': {'cursor': s.cursor, 'reload': false, 'changes': out},
        });
      }
      return handler(request) ?? _json({'data': <dynamic>[]});
    }),
  );
  final session = Session(s.api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: home(s.api, session)),
    ),
  );
  await tester.pumpAndSettle();
  return s;
}

Map<String, dynamic> _lead(String id, String name) => {
  'id': id,
  'is_unread': false,
  'can_reply': false,
  'starred': false,
  'note': null,
  'note_by': null,
  'note_updated_at': null,
  'contact_name': name,
  'phone': '+1 (555) 123-4567',
  'category': 'xcat:service_area_business_home_inspector',
  'service': 'fireplace_chimney',
  'status': 'new',
  'received_at': '2026-09-07T09:00:00Z',
  'last_contact_at': null,
  'last_contact_channel': null,
  'last_contact_from': null,
  'last_contact_preview': null,
  'franchise': {'id': 'f-1', 'name': 'Connecticut'},
  'company': {'id': 'c-1', 'name': 'A-1 Chimney'},
};

Map<String, dynamic> _board(String unassigned) => {
  'date': '2026-09-25',
  'franchises': [
    {'id': 'fr-ct', 'name': 'Connecticut'},
  ],
  'windows': [
    {'start_time': '08:00', 'end_time': '11:00'},
  ],
  'technicians': <dynamic>[],
  'scheduled': <dynamic>[],
  'unassigned': [
    {
      'id': 'J2',
      'number': '100077558',
      'customer': unassigned,
      'job_type': 'Chimney Sweep',
      'status': 'Submitted',
      'time': '08:00',
      'window': 0,
      'address': '18521 Nadol Dr',
      'city': 'Southfield',
    },
  ],
  'counts': {'scheduled': 0, 'unassigned': 1},
  'can_dispatch': false,
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {'key': 'leads', 'label': 'Leads', 'available': true, 'reachable': true},
  ],
};
