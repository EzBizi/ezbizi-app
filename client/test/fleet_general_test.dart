import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/fleet/fleet_general.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 149 (options 1 and 4): Fleet's General dashboard.
void main() {
  String day(int offset) {
    final now = DateTime.now();
    final d = DateTime(now.year, now.month, now.day + offset);
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-'
        '${d.day.toString().padLeft(2, '0')}';
  }

  Map<String, dynamic> health({int active = 20, int shop = 3, int out = 1}) => {
    'empty': false,
    'vehicles': active + shop + out,
    'active': active,
    'in_shop': shop,
    'out_of_service': out,
    'owned': 22,
    'rented': 2,
    'unassigned': 21,
    'links': {
      'vehicles': '',
      'active': 'status=active',
      'in_shop': 'status=in_shop',
      'out_of_service': 'status=out_of_service',
      'owned': 'ownership=owned',
      'rented': 'ownership=rented',
      'unassigned': 'unassigned=1',
    },
  };

  Map<String, dynamic> spending(String period, int total) => {
    'period': period,
    'currency': 'USD',
    'total_minor': total,
    'categories': total == 0
        ? []
        : [
            {'key': 'fuel', 'label': 'Fuel', 'amount_minor': total * 3 ~/ 4},
            {
              'key': 'maintenance',
              'label': 'Maintenance',
              'amount_minor': total ~/ 4,
            },
          ],
    'buckets': <String>[],
    'points': <int>[],
  };

  final warnings = [
    {
      'key': 'service_overdue',
      'count': 2,
      'sentence': '2 vehicles are past their service date or mileage.',
      'link': 'due_soon=1',
    },
    {
      'key': 'no_insurance',
      'count': 1,
      'sentence': '1 vehicle has no insurance on file.',
    },
    {
      'key': 'active_unassigned',
      'count': 4,
      'sentence': '4 vehicles are in use with nobody holding the keys.',
      'link': 'unassigned=1',
    },
  ];

  final vehicles = [
    {
      'id': 'v-1',
      'label': 'Ford Transit',
      'registration_expires_on': day(12),
      'inspection_expires_on': day(90),
      'next_service_on': day(-3),
    },
    {
      'id': 'v-2',
      'label': 'Ram ProMaster',
      'registration_expires_on': null,
      'inspection_expires_on': day(5),
      'next_service_on': null,
    },
  ];

  Future<List<String>> pump(
    WidgetTester tester, {
    Map<String, dynamic>? healthBody,
    List<Map<String, dynamic>>? warningRows,
    Map<String, int> totals = const {'month': 400000, 'week': 0},
    Size size = const Size(1280, 1000),
    List<Uri>? asked,
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final opened = <String>[];

    final api = ApiClient(
      client: MockClient((request) async {
        asked?.add(request.url);
        final path = request.url.path;
        if (path.endsWith('/vehicles/summary')) {
          final period = request.url.queryParameters['period'] ?? 'month';
          return _json({
            'health': healthBody ?? health(),
            'warnings': warningRows ?? warnings,
            'spending': spending(period, totals[period] ?? 0),
          });
        }
        if (path.endsWith('/vehicles')) {
          return _json({'data': vehicles, 'meta': {}});
        }
        return _json({'message': 'no'}, 500);
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: FleetGeneral(
            api: api,
            visible: true,
            onOpen: opened.add,
            canAdd: true,
            onAdd: () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets('status bar counts, and every non-zero segment is painted', (
    tester,
  ) async {
    await pump(tester);

    expect(find.text('24 vehicles'), findsOneWidget);
    expect(find.text('Active 20'), findsOneWidget);
    expect(find.text('In shop 3'), findsOneWidget);
    expect(find.text('Out of service 1'), findsOneWidget);
    expect(find.text('22 owned'), findsOneWidget);
    expect(find.text('2 rented'), findsOneWidget);
    expect(find.text('Nobody has the keys'), findsNothing);

    final widths = <String, double>{};
    for (final key in ['active', 'in_shop', 'out_of_service']) {
      final size = tester.getSize(find.byKey(Key('fleet-status-segment-$key')));
      expect(size.height, greaterThan(0), reason: key);
      expect(size.width, greaterThan(0), reason: key);
      widths[key] = size.width;
    }
    expect(widths['active']!, greaterThan(widths['in_shop']!));
    expect(widths['in_shop']!, greaterThan(widths['out_of_service']!));
  });

  testWidgets('a zero status draws no segment', (tester) async {
    await pump(tester, healthBody: health(shop: 0));

    expect(find.byKey(const Key('fleet-status-segment-in_shop')), findsNothing);
    expect(
      tester
          .getSize(find.byKey(const Key('fleet-status-segment-active')))
          .height,
      greaterThan(0),
    );
  });

  testWidgets('attention rows only when they apply, each fix navigates', (
    tester,
  ) async {
    final opened = await pump(tester);

    expect(find.byKey(const Key('fleet-attention-service_overdue')), findsOne);
    expect(find.byKey(const Key('fleet-attention-no_insurance')), findsOne);
    expect(
      find.byKey(const Key('fleet-attention-active_unassigned')),
      findsOne,
    );
    expect(
      find.byKey(const Key('fleet-attention-out_of_service')),
      findsNothing,
    );
    expect(find.text('Nothing needs attention.'), findsNothing);

    await tester.tap(find.byKey(const Key('fleet-fix-service_overdue')));
    await tester.tap(find.byKey(const Key('fleet-fix-active_unassigned')));
    await tester.tap(find.byKey(const Key('fleet-fix-no_insurance')));
    expect(find.text('Book service'), findsOneWidget);
    expect(find.text('Assign keys'), findsOneWidget);
    expect(find.text('Add insurance'), findsOneWidget);
    expect(opened, ['due_soon=1', 'unassigned=1', '']);
  });

  testWidgets('no warnings is one sentence', (tester) async {
    await pump(tester, warningRows: const []);

    expect(find.text('Nothing needs attention.'), findsOneWidget);
    expect(find.byType(OutlinedButton), findsNothing);
  });

  testWidgets('coming up is in date order with overdue in red', (tester) async {
    await pump(tester);

    String row(int i) => tester
        .widget<Text>(
          find.descendant(
            of: find.byKey(Key('fleet-coming-$i')),
            matching: find.byWidgetPredicate(
              (w) => w is Text && (w.data ?? '').contains(' · '),
            ),
          ),
        )
        .data!;

    expect(row(0), 'Service · Ford Transit');
    expect(row(1), 'Inspection · Ram ProMaster');
    expect(row(2), 'Registration · Ford Transit');
    // 90 days out is not "coming up".
    expect(find.byKey(const Key('fleet-coming-3')), findsNothing);

    final red = ToneColours.of(
      tester.element(find.byKey(const Key('fleet-coming-0'))),
      Tone.bad,
    ).ink;
    final overdue = tester.widget<Text>(
      find.byKey(const Key('fleet-coming-overdue-0')),
    );
    expect(overdue.style?.color, red);
    expect(find.byKey(const Key('fleet-coming-overdue-1')), findsNothing);
  });

  testWidgets('spending switches period and has an empty state', (
    tester,
  ) async {
    final asked = <Uri>[];
    final opened = await pump(tester, asked: asked);

    expect(find.text('\$4,000.00'), findsOneWidget);
    expect(find.text('Fuel'), findsOneWidget);
    expect(find.text('\$3,000.00'), findsOneWidget);

    await tester.tap(find.text('Week'));
    await tester.pumpAndSettle();

    final summaries = asked.where((u) => u.path.endsWith('/summary')).toList();
    expect(summaries.last.queryParameters['period'], 'week');
    expect(find.text('No costs were recorded in this period.'), findsOne);
    expect(find.byKey(const Key('fleet-general-spending-total')), findsNothing);

    await tester.tap(find.byKey(const Key('fleet-general-record-cost')));
    expect(opened, ['']);
  });

  testWidgets('cards in a row are one height on a desktop', (tester) async {
    await pump(tester);

    double height(String key) => tester.getSize(find.byKey(Key(key))).height;
    expect(
      height('fleet-general-status'),
      height('tour-fleet_management-attention'),
    );
    expect(height('fleet-general-coming-up'), height('fleet-general-spending'));
  });

  testWidgets('a phone at 390x844 is one column with no overflow', (
    tester,
  ) async {
    await pump(tester, size: const Size(390, 844));

    expect(tester.takeException(), isNull);
    final status = tester.getRect(
      find.byKey(const Key('fleet-general-status')),
    );
    final attention = tester.getRect(
      find.byKey(const Key('tour-fleet_management-attention')),
    );
    expect(attention.top, greaterThan(status.bottom));
    expect(status.width, attention.width);

    await tester.scrollUntilVisible(
      find.byKey(const Key('fleet-general-spending')),
      300,
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
