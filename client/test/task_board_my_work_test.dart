import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_my_work.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Wednesday 2026-11-25: this week ends Sunday the 29th.
  final today = DateTime(2026, 11, 25);

  test("Monday's My Work buckets", () {
    expect(myWorkBucket('2026-11-20', today), 'Past dates');
    expect(myWorkBucket('2026-11-25', today), 'Today');
    expect(myWorkBucket('2026-11-29', today), 'This week');
    expect(myWorkBucket('2026-11-30', today), 'Next week');
    expect(myWorkBucket('2026-12-06', today), 'Next week');
    expect(myWorkBucket('2026-12-07', today), 'Later');
    expect(myWorkBucket(null, today), 'No date');
  });

  testWidgets('gap review P1: My Work shows the buckets, a calendar by day, '
      'and opens a task', (tester) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': [
              {
                'id': 't1',
                'title': 'Order the liner',
                'due_on': '2026-11-20',
                'board_name': 'Ohio PM',
              },
              {
                'id': 't2',
                'title': 'Call back',
                'due_on': '2026-11-25',
                'board_name': 'DMV',
              },
              {
                'id': 't3',
                'title': 'Someday',
                'due_on': null,
                'board_name': 'My board',
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );
    String? opened;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MyWorkPanel(
            api: api,
            formats: DisplayFormats.fallback,
            today: today,
            onOpenTask: (id) => opened = id,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Past dates · 1'), findsOneWidget);
    expect(find.text('Today · 1'), findsOneWidget);
    expect(find.text('No date · 1'), findsOneWidget);
    // Row 70: Monday's count tiles; "This week" counts today too.
    expect(find.byKey(const Key('my-work-tiles')), findsOneWidget);
    expect(find.byKey(const ValueKey('my-work-bucket-This week')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('my-work-tile-Past dates')));
    await tester.pumpAndSettle();
    expect(find.text('Past dates · 1'), findsOneWidget);
    expect(find.text('Today · 1'), findsNothing, reason: 'the tile narrows to its bucket');
    await tester.tap(find.byKey(const ValueKey('my-work-tile-Past dates')));
    await tester.pumpAndSettle();
    expect(find.text('Today · 1'), findsOneWidget);

    await tester.tap(find.byKey(const Key('my-work-calendar')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('my-work-day-2026-11-25')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('my-work-t2')));
    expect(opened, 't2');
  });
}
