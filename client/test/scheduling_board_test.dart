import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/schedule_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Schedule board (Jose, 2026-09-25, modelled on A1 Tools'): one day,
/// technicians × time slots, unassigned jobs beside it. Read-only.
void main() {
  testWidgets('a job sits in its technician row, under its window', (
    tester,
  ) async {
    await _pump(tester, board: _board);

    expect(find.text('8 AM – 11 AM'), findsOneWidget);
    expect(find.text('11 AM – 2 PM'), findsOneWidget);
    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(find.text('1 scheduled'), findsOneWidget);
    expect(find.text('2 unassigned'), findsOneWidget);

    // The card is to the right of its technician and under its window: same
    // row as Ivan, same column as "11 AM – 2 PM".
    final card = tester.getCenter(find.text('Honesty Haislett'));
    final tech = tester.getCenter(find.text('Ivan Sharkov'));
    final column = tester.getCenter(find.text('11 AM – 2 PM'));
    expect((card.dy - tech.dy).abs(), lessThan(40));
    expect((card.dx - column.dx).abs(), lessThan(130));
  });

  testWidgets('the unassigned list filters as you type', (tester) async {
    await _pump(tester, board: _board);

    expect(find.text('Tonya Yates'), findsOneWidget);
    expect(find.text('Sheila Content'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Customer, job #, address'),
      'norwalk',
    );
    await tester.pumpAndSettle();

    expect(find.text('Sheila Content'), findsOneWidget);
    expect(find.text('Tonya Yates'), findsNothing);
  });

  /// Jose, 2026-09-25: assigning "should be possible on both with the sync".
  testWidgets('a job opens a panel that assigns a covering technician', (
    tester,
  ) async {
    final posts = <http.Request>[];
    await _pump(tester, board: _board, posts: posts);

    await tester.tap(find.text('Sheila Content'));
    await tester.pumpAndSettle();

    expect(find.text('ON THIS JOB'), findsOneWidget);
    expect(find.text('Nobody yet.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('dispatch-assign')));
    await tester.pumpAndSettle();

    // Harry covers Michigan; Workiz refuses him on a Connecticut job, so he
    // is not offered at all.
    expect(
      find.text('Harry Jenkins'),
      findsOneWidget,
      reason: 'board row only',
    );
    await tester.tap(find.text('Ivan Sharkov').last);
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Assign'));
    await tester.pumpAndSettle();

    expect(posts, hasLength(1));
    expect(
      posts.single.url.path,
      endsWith('/scheduling/board/jobs/wj-3/assign'),
    );
    expect(jsonDecode(posts.single.body), {'member_id': 'm-ivan'});
  });

  testWidgets("a refusal shows Workiz's own sentence", (tester) async {
    const sentence =
        'Cannot assign Ivan Sharkov to job 200022773, User is not assigned '
        'to select service area.';
    await _pump(
      tester,
      board: _board,
      onPost: (_) => _json({'message': sentence}, 422),
    );

    await tester.tap(find.text('Sheila Content'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('dispatch-assign')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ivan Sharkov').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Assign'));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('not assigned to select service area'),
      findsOneWidget,
    );
  });

  testWidgets('moving sends the day and the slot', (tester) async {
    final posts = <http.Request>[];
    await _pump(tester, board: _board, posts: posts);

    await tester.tap(find.text('Sheila Content'));
    await tester.pumpAndSettle();

    // Sheila is at 8 AM, so the 8–11 slot is already chosen; pick 11–2.
    await tester.tap(find.byKey(const Key('dispatch-move-window')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('11 AM – 2 PM').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Move'));
    await tester.pumpAndSettle();

    expect(
      posts.single.url.path,
      endsWith('/scheduling/board/jobs/wj-3/reschedule'),
    );
    final body = jsonDecode(posts.single.body) as Map<String, dynamic>;
    expect(body['start_time'], '11:00');
    expect(body['end_time'], '14:00');
    expect(body['date'], matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')));
  });

  testWidgets(
    'without Scheduling edit the panel shows the job and no buttons',
    (tester) async {
      await _pump(tester, board: {..._board, 'can_dispatch': false});

      await tester.tap(find.text('Sheila Content'));
      await tester.pumpAndSettle();

      expect(find.text('ON THIS JOB'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Assign'), findsNothing);
      expect(find.widgetWithText(FilledButton, 'Move'), findsNothing);
    },
  );

  testWidgets('with no time slots the board says where to set them', (
    tester,
  ) async {
    await _pump(tester, board: {..._board, 'windows': const []});

    expect(find.textContaining('Settings › Time slots'), findsOneWidget);
  });

  testWidgets('a phone-width screen draws the board without overflowing', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, board: _board, width: 375);

    // His chip, and his name under the slot of his job.
    expect(find.text('Ivan Sharkov'), findsNWidgets(2));
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
  /// Design 207 S1: a phone shows the day's jobs in time order, each with its
  /// slot and technician; a technician chip narrows it to them.
  testWidgets('a phone shows the day as an agenda, narrowed by technician', (
    tester,
  ) async {
    await _pump(tester, board: _board, width: 390);

    expect(find.byKey(const ValueKey('schedule-phone-week')), findsOneWidget);
    final job = tester.getCenter(find.text('Honesty Haislett').first);
    final slot = tester.getCenter(find.text('11 AM – 2 PM').first);
    expect((slot.dy - job.dy).abs(), lessThan(40));
    expect(slot.dx, lessThan(job.dx));

    // Harry has nothing booked: his chip empties the agenda.
    await tester.ensureVisible(
      find.byKey(const ValueKey('schedule-phone-tech-fr-ct|102')),
    );
    await tester.tap(find.byKey(const ValueKey('schedule-phone-tech-fr-ct|102')));
    await tester.pumpAndSettle();
    expect(find.text('Honesty Haislett'), findsNothing);
    expect(find.text('Nothing booked this day.'), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const ValueKey('schedule-phone-tech-fr-ct|101')),
    );
    await tester.tap(find.byKey(const ValueKey('schedule-phone-tech-fr-ct|101')));
    await tester.pumpAndSettle();
    expect(find.text('Honesty Haislett'), findsWidgets);
  });
}

const _board = {
  'date': '2026-09-25',
  'franchises': [
    {'id': 'fr-ct', 'name': 'Connecticut'},
  ],
  'windows': [
    {'start_time': '08:00', 'end_time': '11:00'},
    {'start_time': '11:00', 'end_time': '14:00'},
  ],
  'technicians': [
    {
      'id': 'fr-ct|101',
      'member_id': 'm-ivan',
      'name': 'Ivan Sharkov',
      'franchise_id': 'fr-ct',
      'franchise_name': 'Connecticut',
      'service_areas': ['Connecticut'],
      'jobs': 1,
    },
    {
      'id': 'fr-ct|102',
      'member_id': 'm-harry',
      'name': 'Harry Jenkins',
      'franchise_id': 'fr-ct',
      'franchise_name': 'Connecticut',
      // Covers somewhere else: Workiz would refuse him on a Connecticut job.
      'service_areas': ['Michigan'],
      'jobs': 0,
    },
  ],
  'scheduled': [
    {
      'id': 'J1',
      'number': '600024908',
      'customer': 'Honesty Haislett',
      'job_type': 'Chimney Inspection',
      'status': 'Submitted',
      'time': '11:00',
      'window': 1,
      'city': 'Lancaster',
      'technician_id': 'fr-ct|101',
    },
  ],
  'unassigned': [
    {
      'id': 'J2',
      'number': '100077558',
      'customer': 'Tonya Yates',
      'job_type': 'Chimney Sweep',
      'status': 'In progress',
      'time': '11:00',
      'window': 1,
      'address': '18521 Nadol Dr',
      'city': 'Southfield',
    },
    {
      'id': 'J3',
      'job_id': 'wj-3',
      'franchise_id': 'fr-ct',
      'service_area': 'Connecticut',
      'team': [],
      'number': '200022773',
      'customer': 'Sheila Content',
      'job_type': 'Free Estimate',
      'status': 'Submitted',
      'time': '08:00',
      'window': 0,
      'address': '32 Deerwood Manor',
      'city': 'Norwalk',
    },
  ],
  'counts': {'scheduled': 1, 'unassigned': 2},
  'can_dispatch': true,
};

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic> board,
  double width = 1400,
  List<http.Request>? posts,
  http.Response Function(http.Request)? onPost,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});
      if (request.method == 'POST') {
        posts?.add(request);
        return onPost?.call(request) ??
            _json({
              'data': {'id': 'wj-3'},
            });
      }
      if (request.url.path.endsWith('/scheduling/board')) {
        return _json({'data': board});
      }
      return _json({'data': const []});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SchedulePane(api: api, session: session, visible: true),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

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
  'permissions': ['scheduling.view', 'scheduling.schedule.view'],
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
    {'id': 'fr-ct', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
