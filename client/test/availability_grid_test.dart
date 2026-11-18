import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/availability_grid.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jobs › Book's grid (design 197): only a window with room can be picked.
/// Invented area and numbers.
void main() {
  Map<String, dynamic> w(
    String s,
    String e,
    int cap,
    int taken, {
    bool late = false,
  }) => {
    'start_time': s,
    'end_time': e,
    'capacity': cap,
    'taken': taken,
    'remaining': late ? 0 : (cap - taken).clamp(0, cap),
    'late': late,
  };

  final days = {
    'area_id': 'a-1',
    'area_name': 'Brightside East',
    'configured': true,
    'days': [
      {
        'date': '2030-01-02', // a Wednesday
        'closed': null,
        'windows': [w('08:00', '11:00', 4, 4), w('11:00', '14:00', 4, 3)],
      },
      {
        'date': '2030-01-04', // a Friday: the afternoon is after the last start
        'closed': null,
        'windows': [
          w('08:00', '11:00', 4, 1),
          w('14:00', '17:00', 4, 0, late: true),
        ],
      },
      {'date': '2030-01-06', 'closed': 'weekly', 'windows': <dynamic>[]},
    ],
  };

  Future<List<PickedWindow>> pump(WidgetTester tester, double width) async {
    final picks = <PickedWindow>[];
    final api = ApiClient(
      client: MockClient((request) async {
        expect(request.url.path, endsWith('/scheduling/availability/days'));
        expect(request.url.queryParameters['zip'], '33316');
        return http.Response(
          jsonEncode({'data': days}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SizedBox(
            width: width,
            child: StatefulBuilder(
              builder: (context, setState) => AvailabilityGrid(
                api: api,
                zip: '33316',
                picked: picks.lastOrNull,
                onPick: (p) => setState(() => picks.add(p)),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return picks;
  }

  testWidgets('a full or blocked window cannot be picked; an open one can', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final picks = await pump(tester, 900);

    expect(find.byKey(const ValueKey('availability-grid')), findsOneWidget);
    final full = find.byKey(const ValueKey('grid-2030-01-02-08:00'));
    expect(
      find.descendant(of: full, matching: find.text('Full')),
      findsOneWidget,
    );
    await tester.tap(full);
    await tester.tap(find.byKey(const ValueKey('grid-2030-01-04-14:00')));
    await tester.pump();
    expect(picks, isEmpty, reason: 'Full and Blocked refuse a tap.');
    expect(find.text('Blocked'), findsOneWidget);
    expect(
      find.text('Closed'),
      findsNWidgets(3),
      reason: 'Sunday is closed in every row.',
    );

    final last = find.byKey(const ValueKey('grid-2030-01-02-11:00'));
    expect(
      find.descendant(of: last, matching: find.text('1 left')),
      findsOneWidget,
    );
    await tester.tap(last);
    await tester.pump();
    expect(picks.single, (date: '2030-01-02', start: '11:00', end: '14:00'));
    expect(find.text('Picked'), findsOneWidget);
  });

  testWidgets('on a phone the days are a strip and the windows rows', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(380, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final picks = await pump(tester, 360);

    expect(find.byKey(const ValueKey('availability-strip')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('grid-day-2030-01-04')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('grid-2030-01-04-14:00')));
    await tester.pump();
    expect(picks, isEmpty);
    await tester.tap(find.byKey(const ValueKey('grid-2030-01-04-08:00')));
    await tester.pump();
    expect(picks.single.date, '2030-01-04');
  });
}
