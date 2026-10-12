import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/overbooking_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Overbooking (Jose, 2026-09-29): which days are overbooked, and
/// which areas are fully booked in the next 7 days.
void main() {
  testWidgets('overbooked days are listed by date, franchise rows named', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, _data, asked);

    expect(find.text('MON, OCT 5'), findsOneWidget);
    expect(find.text('TUE, OCT 6'), findsOneWidget);
    // Dallas is over on both days.
    expect(find.text('Texas › Dallas'), findsNWidgets(2));
    expect(find.text('4 booked of 3'), findsOneWidget);
    expect(find.text('1 over'), findsNWidgets(3));
    expect(find.text('Texas (whole franchise)'), findsOneWidget);
    expect(find.text('1 booked on a day this area is closed'), findsOneWidget);

    expect(find.text('Ohio › Cleveland'), findsOneWidget);
    expect(find.textContaining('Next opening Mon, Oct 12 ('), findsOneWidget);
    // The threshold is the server's (Shoval, 2026-10-05: 10 days and above).
    expect(find.text('Fully booked for 10 days or more'), findsOneWidget);

    // The two counters are one size.
    final sizes = {
      tester.getSize(find.byKey(const ValueKey('overbooking-stat-overbooked'))),
      tester.getSize(find.byKey(const ValueKey('overbooking-stat-full'))),
    };
    expect(sizes, hasLength(1));

    await tester.tap(find.text('7 days'));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['days'], '7');
  });

  /// Jose, 2026-09-29: "pick a date and it shows me the overbooking for that
  /// specific date so that way i can fix it".
  testWidgets(
    'a picked date asks for that one day, and clearing it goes back',
    (tester) async {
      final asked = <Uri>[];
      await _pump(tester, _data, asked);

      await tester.tap(find.byKey(const Key('overbooking-pick-date')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();

      final now = DateTime.now();
      final today =
          '${now.year}-${now.month.toString().padLeft(2, '0')}-'
          '${now.day.toString().padLeft(2, '0')}';
      expect(asked.last.queryParameters, {'date': today});

      await tester.tap(find.byTooltip('Clear the date'));
      await tester.pumpAndSettle();
      expect(asked.last.queryParameters, {'days': '30'});
    },
  );

  /// Bar, via Jose, 2026-10-12: "make everything clickable to show its full
  /// information". A row opens to the jobs it counted, with the client and
  /// the appointment, in place, and closes again.
  testWidgets('an overbooked row opens to its jobs, client and appointment', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, _data, asked, jobs: _jobs);

    await tester.tap(find.text('Texas › Dallas').first);
    await tester.pumpAndSettle();

    expect(asked.last.path, endsWith('/scheduling/overbooking/jobs'));
    expect(asked.last.queryParameters, {
      'date': '2026-10-05',
      'area_id': 'a-1',
    });
    expect(
      find.text('8:00 AM – 11:00 AM  ·  Chimney Inspection  ·  #600012'),
      findsOneWidget,
    );
    expect(find.text('John Lynch  ·  Submitted'), findsOneWidget);
    expect(find.text('2145550100'), findsOneWidget);
    expect(find.text('1430 Red Oaks Dr, Dallas 75201'), findsOneWidget);
    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(find.text('Gate code 4411'), findsOneWidget);
    // An online booking with nobody on it yet says so.
    expect(find.text('No technician yet'), findsOneWidget);

    // The franchise row asks for the franchise.
    await tester.tap(find.text('Texas (whole franchise)'));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters, {
      'date': '2026-10-05',
      'franchise_id': 'f-tx',
    });

    // Tapped again, a row closes.
    await tester.tap(find.text('Texas › Dallas').first);
    await tester.pumpAndSettle();
    expect(
      find.text('Gate code 4411'),
      findsOneWidget,
      reason: 'Only the franchise row is still open.',
    );
  });

  testWidgets('an opened row draws at phone width without overflowing', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, _data, <Uri>[], width: 360, jobs: _jobs);
    await tester.tap(find.text('Texas › Dallas').first);
    await tester.pumpAndSettle();

    expect(find.text('Gate code 4411'), findsOneWidget);
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });

  testWidgets('a quiet week says so rather than showing nothing', (
    tester,
  ) async {
    await _pump(tester, {
      'from': '2026-09-30',
      'days': 30,
      'overbooked': const [],
      'full_from': '2026-10-01',
      'full_days': 7,
      'fully_booked': const [],
    }, <Uri>[]);

    expect(find.textContaining('No overbooked days'), findsOneWidget);
    expect(find.textContaining('still has a spot'), findsOneWidget);
  });

  testWidgets('a phone-width list draws without overflowing', (tester) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, _data, <Uri>[], width: 360);

    expect(find.text('Texas › Dallas'), findsWidgets);
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

const _data = {
  'from': '2026-09-30',
  'days': 30,
  'overbooked': [
    {
      'level': 'area',
      'date': '2026-10-05',
      'area_id': 'a-1',
      'franchise_id': 'f-tx', //
      'name': 'Dallas', 'franchise_name': 'Texas',
      'capacity': 3, 'booked': 4, 'over': 1, 'closed': false,
    },
    {
      'level': 'franchise',
      'date': '2026-10-05',
      'area_id': null,
      'franchise_id': 'f-tx', //
      'name': 'Texas', 'franchise_name': 'Texas',
      'capacity': 4, 'booked': 5, 'over': 1, 'closed': false,
    },
    {
      'level': 'area', 'date': '2026-10-06', 'area_id': 'a-1', //
      'name': 'Dallas', 'franchise_name': 'Texas',
      'capacity': 0, 'booked': 1, 'over': 1, 'closed': true,
    },
  ],
  'full_from': '2026-10-01',
  'full_days': 10,
  'fully_booked': [
    {
      'area_id': 'a-9', 'name': 'Cleveland', 'franchise_name': 'Ohio', //
      'working_days': 5, 'next_opening': '2026-10-12',
    },
  ],
};

const _jobs = [
  {
    'kind': 'job',
    'job_id': 'j-1',
    'number': '600012',
    'status': 'Submitted', //
    'time': '08:00', 'end_time': '11:00', 'service': 'Chimney Inspection',
    'technicians': ['Ivan Sharkov'], 'notes': 'Gate code 4411',
    'customer': {
      'name': 'John Lynch',
      'phone': '2145550100',
      'email': 'john@example.com', //
      'address': '1430 Red Oaks Dr, Dallas 75201',
    },
  },
  {
    'kind': 'booking',
    'lead_id': 'l-1',
    'number': null,
    'status': 'Online booking', //
    'time': '11:00', 'end_time': '14:00', 'service': null,
    'technicians': <String>[], 'notes': null,
    'customer': {
      'name': 'Ann Reyes',
      'phone': '2145550199',
      'email': null,
      'address': null,
    },
  },
];

Future<void> _pump(
  WidgetTester tester,
  Map<String, dynamic> data,
  List<Uri> asked, {
  double width = 1100,
  List<Map<String, dynamic>> jobs = const [],
}) async {
  tester.view.physicalSize = Size(width, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      asked.add(request.url);
      return http.Response(
        jsonEncode({
          'data': request.url.path.endsWith('/overbooking/jobs') ? jobs : data,
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: OverbookingPane(api: api, visible: true)),
    ),
  );
  await tester.pumpAndSettle();
}
