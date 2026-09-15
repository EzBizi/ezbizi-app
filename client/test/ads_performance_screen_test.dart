import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_performance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Google Ads report: what it costs to OPEN, and what it must show.
///
/// Two separate obligations live here and they pull in opposite directions.
///
/// Google's Reporting Minimum Functionality requires clicks, cost, impressions,
/// conversions and all conversions to be ON SCREEN for each level displayed —
/// an endpoint returning all five while the widget draws three satisfies
/// nothing, and the API test next door cannot see the difference because it
/// never builds a widget.
///
/// And opening the screen must cost Google nothing. It used to load all 53
/// connected accounts at once: 106 operations of a 15,000/day ceiling and 47
/// seconds of spinner, spent before anybody asked for a figure.
void main() {
  /// Every request the screen makes, in order, so a test can assert on what
  /// was NOT asked for as well as what was.
  late List<String> calls;

  Future<void> pump(
    WidgetTester tester, {
    required List<Map<String, dynamic>> accounts,
    Map<String, dynamic>? report,
  }) async {
    tester.view.physicalSize = const Size(1400, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    calls = [];

    final api = ApiClient(
      client: MockClient((request) async {
        calls.add(request.url.path);

        if (request.url.path.contains('/ads/accounts')) {
          return http.Response(
            jsonEncode({'data': accounts}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path.contains('/ads/performance')) {
          return http.Response(
            jsonEncode({
              'data': report == null ? [] : [report],
              'refused': report?['_refused'] ?? [],
            }),
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
        home: Scaffold(
          body: AdsPerformanceScreen(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  List<Map<String, dynamic>> twoAccounts() => [
    {
      'connection_id': 'c1',
      'customer_id': '4051729356',
      'name': 'A1 Chimney - Cleveland',
    },
    {
      'connection_id': 'c2',
      'customer_id': '8865675621',
      'name': 'A1 Chimney - Atlanta',
    },
  ];

  Map<String, dynamic> report() => {
    'connection_id': 'c1',
    'currency': 'USD',
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
    'series': [
      {
        'label': 'Cost',
        'key': 'cost',
        'points': [
          {'at': '2026-09-01', 'value': 1},
          {'at': '2026-09-02', 'value': 2.5},
        ],
      },
      {'label': 'Clicks', 'key': 'clicks', 'points': []},
      {'label': 'Conversions', 'key': 'conversions', 'points': []},
    ],
    'campaigns': [
      {
        'id': '111',
        'name': 'Chimney - Cleveland - Search',
        'status': 'ENABLED',
        'clicks': 300,
        'impressions': 8000,
        'conversions': 20,
        'all_conversions': 28,
        // A round figure: this is the value that would crash `as double`.
        'cost': 900,
      },
      {
        'id': '222',
        'name': 'Chimney - Cleveland - Display',
        'status': 'PAUSED',
        'clicks': 0,
        'impressions': 0,
        'conversions': 0,
        'all_conversions': 0,
        'cost': 0,
      },
    ],
  };

  testWidgets('opening the screen asks Google for nothing', (tester) async {
    await pump(tester, accounts: twoAccounts(), report: report());

    // The account list is there, and NO report has been fetched. This is the
    // whole point of the redesign: the expensive call waits for a choice.
    expect(find.byKey(const ValueKey('ads-accounts')), findsOneWidget);
    expect(find.text('A1 Chimney - Cleveland'), findsOneWidget);
    expect(find.byKey(const ValueKey('ads-pick-one')), findsOneWidget);

    expect(
      calls.where((c) => c.contains('/ads/performance')),
      isEmpty,
      reason: 'a screen that reports on open spends the quota of whoever opens it',
    );
  });

  testWidgets('picking an account loads that one, and only then', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());

    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    expect(calls.where((c) => c.contains('/ads/performance')), hasLength(1));
    expect(find.byKey(const ValueKey('ads-pick-one')), findsNothing);
    expect(find.text('Account 4051729356'), findsOneWidget);
  });

  testWidgets('the account level draws all five required fields', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    final card = find.byKey(const ValueKey('ads-account-figures'));

    for (final label in [
      'Cost',
      'Clicks',
      'Impressions',
      'Conversions',
      'All conversions',
    ]) {
      // Scoped INSIDE the account card. `find.text(label)` alone is satisfied
      // by the campaign table's column header of the same name, so a deleted
      // figure would leave this green — which it did, until a sabotage caught it.
      expect(
        find.descendant(of: card, matching: find.text(label)),
        findsOneWidget,
        reason: 'R.10 requires $label at the Account level, on screen',
      );
    }

    expect(find.text('1234.56 USD'), findsOneWidget);
  });

  testWidgets('the campaign level draws all five, plus status', (tester) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    final table = find.byKey(const ValueKey('ads-campaigns'));
    expect(table, findsOneWidget);

    for (final column in [
      'Cost',
      'Clicks',
      'Impressions',
      'Conversions',
      'All conversions',
      'Status',
    ]) {
      expect(
        find.descendant(of: table, matching: find.text(column)),
        findsOneWidget,
        reason: 'R.20 requires $column at the Campaign level, on screen',
      );
    }

    // A round number rendered as money. Had the widget cast it to double it
    // would have thrown before reaching here.
    expect(find.text('900.00 USD'), findsOneWidget);
  });

  testWidgets('the status filter hides rows and never the column', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    expect(find.text('Chimney - Cleveland - Search'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('ads-status-PAUSED')));
    await tester.pumpAndSettle();

    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);
    expect(
      find.text('Chimney - Cleveland - Search'),
      findsNothing,
      reason: 'filtering to Paused must actually drop the active campaign',
    );

    // The obligation the filter must not break: hiding ROWS is a user's choice,
    // dropping a required FIELD is a policy breach.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('ads-campaigns')),
        matching: find.text('All conversions'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refused account is named rather than dropped', (tester) async {
    await pump(
      tester,
      accounts: twoAccounts(),
      report: {
        ...report(),
        '_refused': [
          {
            'connection_id': 'c1',
            'account': '993-282-9592',
            'because': "The customer account can't be accessed because it is "
                'not yet enabled or has been deactivated.',
          },
        ],
      },
    );
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('ads-refused')), findsOneWidget);
    expect(
      find.textContaining('deactivated'),
      findsOneWidget,
      reason: 'dropping it would read as "this account spent nothing", which is '
          'a different and wrong statement',
    );
  });

  testWidgets('with nothing connected it names the way in', (tester) async {
    await pump(tester, accounts: const []);

    expect(find.byKey(const ValueKey('ads-empty')), findsOneWidget);
    expect(
      find.textContaining('Connected sources'),
      findsOneWidget,
      reason: 'an empty state that does not name the way in leaves somebody stuck',
    );
  });
}
