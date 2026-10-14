import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/availability_suggestion.dart';
import 'package:ezbizi_client/screens/scheduling/rescheduling_pane.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Re-Scheduling (UI queue 86, desktop 1 and phone 4): booked
/// jobs that would sit better beside other work, biggest saving first, with
/// Move as a second, confirmed step. Built against Scheduling's
/// `GET /scheduling/reschedules`. Every name here is invented.
void main() {
  Map<String, dynamic> row({
    required String id,
    required String number,
    required String area,
    required String areaName,
    required String customer,
    required String date,
    required String time,
    required Object? nearestNow,
    required String toDate,
    required num nearestThen,
    int nearby = 3,
  }) => {
    'area_id': area,
    'area_name': areaName,
    'job': {
      'kind': 'job',
      'job_id': 'ez-$id',
      'workiz_job_id': id,
      'lead_id': null,
      'number': number,
      'status': 'Scheduled',
      'time': time,
      'end_time': '10:00',
      'service': 'Chimney sweep',
      'technicians': ['Sam Ortiz'],
      'notes': null,
      'customer': {
        'name': customer,
        'phone': '555-0142',
        'email': null,
        'address': '1180 Larkspur Ln',
      },
      'date': date,
      'latitude': 41.9,
      'longitude': -87.6,
    },
    'current_nearest_miles': nearestNow,
    'suggested': {
      'date': toDate,
      'window': {'start_time': '10:00', 'end_time': '12:00', 'remaining': 2},
      'window_index': 1,
      'day': 'Thursday',
      'nearby': nearby,
      'nearest_miles': nearestThen,
      'technicians': ['Sam Ortiz'],
      'jobs': [
        {
          'latitude': 41.91,
          'longitude': -87.6,
          'miles': nearestThen,
          'nearby': true,
          'customer': 'Leo Brandt',
          'time': '08:00',
        },
      ],
    },
  };

  final rows = [
    row(
      id: 'job-1',
      number: '4127',
      area: 'a-mg',
      areaName: 'Maple Grove',
      customer: 'Ana Ruiz',
      date: '2026-10-14',
      time: '08:00',
      nearestNow: null,
      toDate: '2026-10-16',
      nearestThen: 0.8,
    ),
    row(
      id: 'job-2',
      number: '4133',
      area: 'a-cf',
      areaName: 'Cedar Falls',
      customer: 'Ben Okafor',
      date: '2026-10-15',
      time: '08:00',
      nearestNow: 14.2,
      toDate: '2026-10-22',
      nearestThen: 1.1,
      nearby: 2,
    ),
  ];

  late List<http.Request> posts;
  late int reads;

  Future<void> pump(
    WidgetTester tester, {
    List<Map<String, dynamic>>? data,
    List<String> permissions = const [
      'scheduling.view',
      'scheduling.rescheduling.view',
      'scheduling.schedule.view',
      'scheduling.edit',
    ],
    double width = 1280,
  }) async {
    tester.view.physicalSize = Size(width, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    posts = [];
    reads = 0;

    http.Response json(Object body, [int code = 200]) => http.Response(
      jsonEncode(body),
      code,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Dispatcher',
              'email': 'dispatch@example.com',
              'role': {'name': 'Manager'},
              'has_avatar': false,
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northwind',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'scope': {'type': 'organization', 'id': 'org-1'},
              'permissions': permissions,
            },
          });
        }
        if (path.endsWith('/scheduling/reschedules')) {
          reads++;
          return json({'data': data ?? rows});
        }
        if (request.method == 'POST') {
          posts.add(request);
          return json({
            'data': {'ok': true},
          });
        }
        return json({'data': null});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReschedulingPane(api: api, session: session, visible: true),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('each move reads from → to and what it saves, the first open', (
    tester,
  ) async {
    await pump(tester);

    expect(reads, 1);
    expect(find.text('#4127 · Chimney sweep'), findsNWidgets(2));
    expect(find.text('Ana Ruiz · Maple Grove'), findsOneWidget);
    expect(find.text('was alone'), findsOneWidget);
    expect(find.text('alone → 0.8 mi'), findsOneWidget);
    expect(find.text('14.2 mi → 1.1 mi'), findsOneWidget);
    expect(find.text('13.1 mi'), findsOneWidget);
    expect(find.text('beside 2 jobs'), findsOneWidget);
    // The first row is open beside the list, on its suggested day's map.
    expect(
      find.byKey(const ValueKey('rescheduling-detail-job-1')),
      findsOneWidget,
    );
    expect(find.byType(RouteMap), findsOneWidget);
    expect(find.text('Fri Oct 16 · 10 AM–12 PM'), findsWidgets);
    expect(find.text('no other job that day'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('rescheduling-row-job-2')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('rescheduling-detail-job-2')),
      findsOneWidget,
    );
    expect(find.text('nearest other job 14.2 mi'), findsOneWidget);
  });

  testWidgets('Move asks first, then sends the window, then reads again', (
    tester,
  ) async {
    await pump(tester);
    expect(
      find.text('Make sure to confirm with the client before rescheduling.'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('rescheduling-move')));
    await tester.pumpAndSettle();
    expect(posts, isEmpty, reason: 'Move… only asks.');
    expect(
      find.textContaining("Move Ana Ruiz's appointment to Fri Oct 16"),
      findsOneWidget,
    );
    expect(
      find.textContaining('Do you wish to proceed with the change?'),
      findsOneWidget,
    );
    expect(
      find.text('Make sure to confirm with the client before rescheduling.'),
      findsOneWidget,
      reason: 'The warning stays while it asks.',
    );

    await tester.tap(find.byKey(const Key('rescheduling-confirm')));
    await tester.pumpAndSettle();

    expect(posts, hasLength(1));
    expect(
      posts.single.url.path,
      endsWith('/scheduling/board/jobs/job-1/reschedule'),
    );
    expect(jsonDecode(posts.single.body), {
      'date': '2026-10-16',
      'start_time': '10:00',
      'end_time': '12:00',
    });
    expect(find.byKey(const Key('rescheduling-moved')), findsOneWidget);
    expect(reads, 2);
  });

  testWidgets('without the right to dispatch there is no Move', (tester) async {
    await pump(
      tester,
      permissions: const ['scheduling.view', 'scheduling.rescheduling.view'],
    );

    expect(find.byKey(const Key('rescheduling-move')), findsNothing);
    expect(
      find.textContaining('Moving a job needs the Schedule'),
      findsOneWidget,
    );
  });

  testWidgets('the area picker narrows the list', (tester) async {
    await pump(tester);

    await tester.tap(find.byType(SearchPickerField<String?>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cedar Falls · 1').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('rescheduling-row-job-1')), findsNothing);
    expect(
      find.byKey(const ValueKey('rescheduling-row-job-2')),
      findsOneWidget,
    );
  });

  testWidgets('on a phone a card opens the job in the tab, and back returns', (
    tester,
  ) async {
    await pump(tester, width: 390);

    expect(find.byType(RouteMap), findsNothing);
    await tester.tap(find.byKey(const ValueKey('rescheduling-row-job-2')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('rescheduling-detail-job-2')),
      findsOneWidget,
    );
    expect(find.byType(Scaffold), findsOneWidget);

    await tester.tap(find.byKey(const Key('rescheduling-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('rescheduling-list')), findsOneWidget);
  });

  testWidgets('nothing to move says so', (tester) async {
    await pump(tester, data: const []);

    expect(find.byKey(const Key('rescheduling-empty')), findsOneWidget);
  });

  /// Jose, 2026-10-14: repairs and redos "should be included, but tag them
  /// appropriately … perhaps we can have the rescheduling clickable, showing
  /// the type of jobs set for that day and how the rescheduled one would fit".
  testWidgets('a repair or a redo is tagged, and the opened row shows its '
      'new day window by window with this job where it would go', (
    tester,
  ) async {
    final first = rows[0];
    final tagged = [
      {
        ...first,
        'job': {...first['job'] as Map, 'service': 'Chimney Repair'},
        'suggested': {
          ...first['suggested'] as Map,
          'day': {
            'date': '2026-10-16',
            'windows': [
              {
                'start_time': '08:00',
                'end_time': '10:00',
                'capacity': 3,
                'taken': 3,
                'remaining': 0,
              },
              {
                'start_time': '10:00',
                'end_time': '12:00',
                'capacity': 3,
                'taken': 1,
                'remaining': 2,
              },
            ],
          },
          'jobs': [
            {
              'latitude': 41.91,
              'longitude': -87.6,
              'miles': 0.8,
              'nearby': true,
              'customer': 'Leo Brandt',
              'time': '08:00',
              'window': 0,
              'number': '4102',
              'service': 'Redo',
            },
            {
              'latitude': 41.92,
              'longitude': -87.6,
              'miles': 1.4,
              'nearby': true,
              'customer': 'Mia Holt',
              'time': '10:00',
              'window': 1,
              'number': '4111',
              'service': 'Inspection',
            },
          ],
        },
      },
      rows[1],
    ];
    await pump(tester, data: tagged);

    expect(find.widgetWithText(StatusPill, 'Chimney Repair'), findsWidgets);
    expect(find.widgetWithText(StatusPill, 'Redo'), findsOneWidget);
    expect(find.textContaining('3 of 3 taken'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('rescheduling-window-0')),
        matching: find.textContaining('#4102 · Redo'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('rescheduling-window-1')),
        matching: find.text('#4127 would go here'),
      ),
      findsOneWidget,
    );
  });
}
