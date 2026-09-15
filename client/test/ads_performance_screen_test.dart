import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_performance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The required fields have to be ON SCREEN, not merely in the response.
///
/// Google's Reporting Minimum Functionality is about a reporting INTERFACE:
/// R.10 and R.20 require clicks, cost, impressions, conversions and all
/// conversions to be shown for each level displayed. An endpoint that returns
/// all five while the widget draws three satisfies nothing, and the API test
/// next door cannot see the difference — it never builds a widget.
///
/// So this is the half of the compliance claim that only a widget test covers.
void main() {
  Future<void> pump(WidgetTester tester, Map<String, dynamic> body) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.contains('/ads/performance')) {
          return http.Response(
            jsonEncode(body),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('{}', 200,
            headers: {'content-type': 'application/json'});
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: AdsPerformanceScreen(api: api, session: Session(api))),
      ),
    );
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> payload() => {
        'data': [
          {
            'connection_id': 'c1',
            'account': {
              'id': '4051729356',
              'name': 'A1 Chimney - Cleveland',
              'clicks': 412,
              'impressions': 10500,
              'conversions': 31.5,
              'all_conversions': 44.25,
              'cost': 1234.56,
              'currency': 'USD',
            },
            'campaigns': [
              {
                'id': '111',
                'name': 'Chimney - Cleveland - Search',
                'status': 'ENABLED',
                'clicks': 300,
                'impressions': 8000,
                'conversions': 20,
                'all_conversions': 28,
                // A whole figure crosses JSON as an int, not a double. This is
                // the value that would crash an `as double` cast, so it is
                // deliberately round.
                'cost': 900,
                'currency': 'USD',
              },
            ],
            'currency': 'USD',
            'from': '2026-08-16',
            'to': '2026-09-14',
          }
        ],
        'refused': <Map<String, dynamic>>[],
        'from': '2026-08-16',
        'to': '2026-09-14',
      };

  testWidgets('the account level draws all five required fields', (tester) async {
    await pump(tester, payload());

    for (final label in [
      'Cost',
      'Clicks',
      'Impressions',
      'Conversions',
      'All conversions',
    ]) {
      // Scoped to the account card. `find.text(label)` alone passes on the
      // campaign table's column header of the same name, so deleting a figure
      // from the account level would leave this green - which it did, until a
      // sabotage caught it.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('ads-account-figures')),
          matching: find.text(label),
        ),
        findsOneWidget,
        reason: 'R.10 requires $label at the Account level, and requires it on screen',
      );
    }

    expect(find.text('1234.56 USD'), findsOneWidget);
    expect(find.text('412'), findsWidgets);
    expect(find.text('10500'), findsWidgets);
  });

  testWidgets('the campaign level draws all five, plus status', (tester) async {
    await pump(tester, payload());

    final table = find.byKey(const ValueKey('ads-campaigns'));
    expect(table, findsOneWidget);
    expect(find.text('Chimney - Cleveland - Search'), findsOneWidget);

    // The five, as column headers INSIDE the table. This test was named "draws
    // all five" while asserting none of them, and a sabotage that deleted the
    // All conversions column left it green - a label is a claim, and the output
    // is the finding.
    for (final column in [
      'Cost',
      'Clicks',
      'Impressions',
      'Conversions',
      'All conversions',
    ]) {
      expect(
        find.descendant(of: table, matching: find.text(column)),
        findsOneWidget,
        reason: 'R.20 requires $column at the Campaign level, on screen',
      );
    }

    // Required by R.20 because paused campaigns are shown.
    expect(find.text('ENABLED'), findsOneWidget);

    // A round number rendered as money. If the widget cast this to double it
    // would have thrown before reaching here, and the screen would be an
    // exception rather than a wrong figure.
    expect(find.text('900.00 USD'), findsOneWidget);
  });

  testWidgets('a refused account is named rather than dropped', (tester) async {
    final body = payload();
    body['data'] = <Map<String, dynamic>>[];
    body['refused'] = [
      {
        'connection_id': 'c9',
        'account': '993-282-9592',
        'because': "The customer account can't be accessed because it is not yet "
            'enabled or has been deactivated.',
      }
    ];

    await pump(tester, body);

    expect(find.byKey(const ValueKey('ads-refused')), findsOneWidget);
    expect(find.textContaining('993-282-9592'), findsOneWidget);
    expect(
      find.textContaining('deactivated'),
      findsOneWidget,
      reason: 'leaving it out entirely would read as "this account spent nothing", '
          'which is a different and wrong statement',
    );

    // And the empty state must NOT also appear - there is something to say.
    expect(find.byKey(const ValueKey('ads-empty')), findsNothing);
  });

  testWidgets('with nothing connected it names the way in', (tester) async {
    await pump(tester, {
      'data': <Map<String, dynamic>>[],
      'refused': <Map<String, dynamic>>[],
    });

    final empty = find.byKey(const ValueKey('ads-empty'));
    expect(empty, findsOneWidget);
    expect(
      find.textContaining('Connected sources'),
      findsOneWidget,
      reason: 'an empty state that does not name the way in leaves somebody stuck',
    );
  });
}
