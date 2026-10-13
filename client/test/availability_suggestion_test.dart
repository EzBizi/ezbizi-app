import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/availability_pane.dart';
import 'package:ezbizi_client/screens/scheduling/availability_suggestion.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 83 (Jose, 2026-10-13): a zip search shows Earliest beside
/// Suggested, the window nearest booked work, with the route that explains
/// it. Built against Scheduling's `GET /scheduling/availability/suggestion`.
void main() {
  Map<String, dynamic> suggestion({
    bool same = false,
    bool none = false,
    bool origin = true,
  }) => {
    'area_id': 'a-lv',
    'area_name': 'Lakeview',
    'radius_miles': 5,
    'days': 14,
    'origin': origin
        ? {'zip': '60614', 'latitude': 41.92, 'longitude': -87.65}
        : null,
    'earliest': {
      'date': '2026-10-14',
      'window': {'start_time': '08:00', 'end_time': '11:00', 'remaining': 2},
      'nearby': same ? 2 : 0,
      'nearest_miles': same ? 0.8 : 23,
      'technicians': <dynamic>[],
      'jobs': [
        {
          'zip': '60004',
          'city': 'Arlington Heights',
          'latitude': 42.08,
          'longitude': -87.98,
          'miles': 23,
          'time': '09:00',
          'window': 0,
          'technicians': <dynamic>[],
          'nearby': false,
        },
      ],
    },
    'suggested': none
        ? null
        : {
            'date': '2026-10-16',
            'window': {
              'start_time': '12:00',
              'end_time': '15:00',
              'remaining': 1,
            },
            'nearby': 3,
            'nearest_miles': 1.2,
            'technicians': ['Omar Feld'],
            'same_as_earliest': same,
            'jobs': [
              for (final (i, miles) in [1.2, 2.8, 11.0].indexed)
                {
                  'zip': '6061$i',
                  'city': null,
                  'latitude': 41.92 + miles / 100,
                  'longitude': -87.65,
                  'miles': miles,
                  'time': '09:00',
                  'window': null,
                  'technicians': <dynamic>[],
                  'nearby': miles <= 5,
                  if (i == 0) ...{
                    'customer': 'Rita Vance',
                    'service': 'Chimney sweep',
                    'number': '4102',
                    'status': 'Online booking',
                    'address': '12 Elm St',
                  },
                },
            ],
          },
  };

  Future<void> pump(
    WidgetTester tester, {
    Map<String, dynamic>? data,
    int status = 200,
    double width = 1280,
  }) async {
    tester.view.physicalSize = Size(width, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

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
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': [
                'scheduling.view',
                'scheduling.availability.view',
              ],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northside',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/scheduling/availability/suggestion')) {
          return status == 200
              ? json({'data': data})
              : json({'message': 'Not found.'}, status);
        }
        if (path.endsWith('/scheduling/availability')) {
          return json({
            'data': {
              'basis': 'remaining',
              'areas': [
                {
                  'area_id': 'a-lv',
                  'area_name': 'Lakeview',
                  'franchise_name': 'Chicago',
                  'configured': true,
                  'first': {
                    'date': '2026-10-14',
                    'no_time': 0,
                    'windows': [
                      {
                        'start_time': '08:00',
                        'end_time': '11:00',
                        'capacity': 3,
                        'taken': 1,
                        'remaining': 2,
                      },
                    ],
                  },
                  'after': <dynamic>[],
                  'closed': <dynamic>[],
                  'none_because': null,
                },
              ],
            },
          });
        }
        return json({'data': const <dynamic>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SingleChildScrollView(
            child: SizedBox(
              height: 1500,
              child: AvailabilityPane(
                api: api,
                session: session,
                visible: true,
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '60614',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();
  }

  testWidgets('Earliest beside Suggested, each saying why, with the route', (
    tester,
  ) async {
    await pump(tester, data: suggestion());

    expect(find.byKey(const Key('availability-earliest')), findsOneWidget);
    expect(find.byKey(const Key('availability-suggested')), findsOneWidget);
    expect(
      find.text('Nearest booked job that day: 23 mi away.'),
      findsOneWidget,
    );
    expect(
      find.text('3 jobs within 5 mi that day, the nearest 1.2 mi away.'),
      findsOneWidget,
    );
    expect(find.text('Omar Feld'), findsOneWidget);
    expect(find.byType(RouteMap), findsOneWidget);
    expect(find.byKey(const Key('availability-route-replay')), findsOneWidget);
    // Once drawn, every job that day has landed: three dots, the zip's own,
    // and the three in the legend.
    expect(
      find.descendant(
        of: find.byType(RouteMap),
        matching: find.byWidgetPredicate(
          (w) => w.runtimeType.toString() == '_Dot',
        ),
      ),
      findsNWidgets(7),
    );
    // Side by side on a wide screen.
    expect(
      tester.getTopLeft(find.byKey(const Key('availability-earliest'))).dy,
      tester.getTopLeft(find.byKey(const Key('availability-suggested'))).dy,
    );
    // "First available" gave way to the two cards.
    expect(find.text('First available'), findsNothing);
  });

  testWidgets('a dot shows its job on hover, a click keeps it, + zooms in', (
    tester,
  ) async {
    await pump(tester, data: suggestion());
    final dot = find.byKey(const Key('route-dot-0'));
    final card = find.byKey(const Key('route-dot-card'));
    expect(dot, findsOneWidget);
    expect(card, findsNothing);

    final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
    await mouse.addPointer(location: Offset.zero);
    addTearDown(mouse.removePointer);
    await mouse.moveTo(tester.getCenter(dot));
    await tester.pump();
    expect(card, findsOneWidget);
    expect(find.text('Rita Vance'), findsOneWidget);
    expect(find.text('Chimney sweep · #4102'), findsOneWidget);
    expect(find.text('9 AM'), findsOneWidget);
    expect(find.text('12 Elm St · 1.2 mi away'), findsOneWidget);
    expect(find.text('Online booking'), findsOneWidget);

    // Hover alone goes when the pointer does; a click keeps it.
    await mouse.moveTo(Offset.zero);
    await tester.pump();
    expect(card, findsNothing);
    await tester.tap(dot);
    await tester.pump();
    expect(card, findsOneWidget);

    // A job with no details still says what it is.
    await tester.tap(find.byKey(const Key('route-dot-1')));
    await tester.pump();
    expect(find.text('Booked job'), findsOneWidget);
    expect(find.text('Rita Vance'), findsNothing);

    final apart =
        (tester.getCenter(find.byKey(const Key('route-dot-0'))) -
                tester.getCenter(find.byKey(const Key('route-dot-1'))))
            .distance;
    await tester.tap(find.byKey(const Key('route-zoom-in')));
    await tester.pumpAndSettle();
    final zoomed =
        (tester.getCenter(find.byKey(const Key('route-dot-0'))) -
                tester.getCenter(find.byKey(const Key('route-dot-1'))))
            .distance;
    expect(zoomed, greaterThan(apart * 1.8));
    await tester.tap(find.byKey(const Key('route-zoom-fit')));
    await tester.pumpAndSettle();
    expect(
      (tester.getCenter(find.byKey(const Key('route-dot-0'))) -
              tester.getCenter(find.byKey(const Key('route-dot-1'))))
          .distance,
      moreOrLessEquals(apart, epsilon: 1),
    );
  });

  testWidgets('when the earliest day is the best fit, one card says so', (
    tester,
  ) async {
    await pump(tester, data: suggestion(same: true));

    expect(find.byKey(const Key('availability-suggested')), findsNothing);
    expect(find.text('EARLIEST · AND THE BEST FIT'), findsOneWidget);
    expect(
      find.byKey(const Key('availability-same-as-earliest')),
      findsOneWidget,
    );
  });

  testWidgets('with nothing in the radius there is no suggestion, and why', (
    tester,
  ) async {
    await pump(tester, data: suggestion(none: true));

    expect(find.byKey(const Key('availability-suggested')), findsNothing);
    expect(find.byKey(const Key('availability-no-suggestion')), findsOneWidget);
    expect(find.textContaining('within 5 mi of 60614'), findsOneWidget);
  });

  testWidgets('a zip with no job ever booked gets no map', (tester) async {
    await pump(tester, data: suggestion(origin: false));

    expect(find.byKey(const Key('availability-suggested')), findsOneWidget);
    expect(find.byType(RouteMap), findsNothing);
  });

  testWidgets('an endpoint that is not there leaves the answer as it was', (
    tester,
  ) async {
    await pump(tester, status: 404);

    expect(find.byKey(const Key('availability-earliest')), findsNothing);
    expect(find.text('First available'), findsOneWidget);
  });

  testWidgets('on a phone the cards stack, Suggested second', (tester) async {
    await pump(tester, data: suggestion(), width: 375);

    final earliest = tester.getTopLeft(
      find.byKey(const Key('availability-earliest')),
    );
    final suggested = tester.getTopLeft(
      find.byKey(const Key('availability-suggested')),
    );
    expect(suggested.dy, greaterThan(earliest.dy));
    expect(tester.takeException(), isNull);
  });
}
