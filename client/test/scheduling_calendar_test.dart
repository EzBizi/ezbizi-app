import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/availability_calendar.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Availability › Calendar view (Jose, 2026-09-25, from A1 Tools' calendar):
/// a franchise's or an area's month, spots left of what each day takes.
void main() {
  testWidgets('a franchise month shows each day left of capacity', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, asked);

    await tester.tap(find.text('DMV'));
    await tester.pumpAndSettle();

    Finder inDay(String date, String text) => find.descendant(
      of: find.byKey(ValueKey('calendar-day-$date')),
      matching: find.text(text),
    );

    expect(inDay('2026-10-05', '2'), findsOneWidget);
    expect(inDay('2026-10-05', 'of 16'), findsOneWidget);
    expect(inDay('2026-10-06', 'Closed'), findsOneWidget);
    expect(inDay('2026-10-07', '0'), findsOneWidget);
    expect(inDay('2026-10-07', '3 over'), findsOneWidget);
    expect(asked.last.queryParameters['franchise_id'], 'fr-dmv');

    // October 2026 starts on a Thursday: three blank cells, then the 1st in
    // the Thursday column.
    expect(
      tester
          .getTopLeft(find.byKey(const ValueKey('calendar-day-2026-10-01')))
          .dx,
      greaterThan(tester.getTopLeft(find.text('Wed')).dx),
    );

    // The bar agrees with the number: 3 of 8 left is 5/8 used, even when 8
    // were booked (over one slot's limit, leaving another open).
    final bar = tester.widget<LinearProgressIndicator>(
      find.descendant(
        of: find.byKey(const ValueKey('calendar-day-2026-10-09')),
        matching: find.byType(LinearProgressIndicator),
      ),
    );
    expect(bar.value, closeTo(5 / 8, 0.001));
    expect(inDay('2026-10-09', '3'), findsOneWidget);

    // Every day the same size.
    final sizes = {
      for (final d in ['01', '05', '06', '07', '31'])
        tester.getSize(find.byKey(ValueKey('calendar-day-2026-10-$d'))),
    };
    expect(sizes, hasLength(1), reason: '$sizes');
  });

  testWidgets('an area and the next month are asked for by name', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, asked);

    await tester.tap(find.text('DMV'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Maryland'));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['area_id'], 'a-md');

    await tester.tap(find.byTooltip('Next month'));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['month'], '2026-11');
    expect(asked.last.queryParameters['area_id'], 'a-md');
  });

  /// "All areas" (Jose, 2026-09-28): every franchise's month added up, asked
  /// for by naming no franchise; there are no area chips under it.
  testWidgets('all areas asks for the month of every franchise', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, asked);

    await tester.tap(find.text('All areas'));
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters.containsKey('franchise_id'), isFalse);
    expect(asked.last.queryParameters['month'], '2026-10');
    expect(
      find.byKey(const ValueKey('calendar-day-2026-10-05')),
      findsOneWidget,
    );
  });

  testWidgets('a phone-width month draws without overflowing', (tester) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, <Uri>[], width: 360);
    await tester.tap(find.text('DMV'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('calendar-day-2026-10-07')),
      findsOneWidget,
    );
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

Map<String, dynamic> _day(
  String date, {
  bool closed = false,
  int capacity = 16,
  int booked = 0,
  int? remaining,
  int over = 0,
}) => {
  'date': date,
  'closed': closed,
  'capacity': closed ? 0 : capacity,
  'booked': booked,
  'remaining': closed ? 0 : (remaining ?? capacity - booked),
  'over': over,
  'limit': null,
};

Future<void> _pump(
  WidgetTester tester,
  List<Uri> asked, {
  double width = 1100,
}) async {
  tester.view.physicalSize = Size(width, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      asked.add(request.url);
      final month = request.url.queryParameters['month'] ?? '2026-10';
      final days = [
        for (var d = 1; d <= 31; d++)
          switch (d) {
            5 => _day('$month-05', booked: 14),
            6 => _day('$month-06', closed: true),
            7 => _day('$month-07', booked: 19, remaining: 0, over: 3),
            9 => _day('$month-09', capacity: 8, booked: 8, remaining: 3),
            _ => _day('$month-${d.toString().padLeft(2, '0')}'),
          },
      ];
      return http.Response(
        jsonEncode({
          'data': {
            'month': month,
            'franchise': {'id': 'fr-dmv', 'name': 'DMV'},
            'areas': [
              {'id': 'a-md', 'name': 'Maryland'},
              {'id': 'a-va', 'name': 'Virginia'},
            ],
            'area_id': request.url.queryParameters['area_id'],
            'configured': true,
            'today': '2026-10-02',
            'days': days,
          },
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: AvailabilityCalendar(
            api: api,
            month: DateTime(2026, 10),
            franchises: const [
              {'id': 'fr-dmv', 'name': 'DMV'},
              {'id': 'fr-ga', 'name': 'Georgia'},
            ],
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
