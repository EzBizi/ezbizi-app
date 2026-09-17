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

  /// The same requests with their query strings, for the tests that care WHICH
  /// dates were asked for rather than which endpoint.
  late List<Uri> urls;

  Future<void> pump(
    WidgetTester tester, {
    required List<Map<String, dynamic>> accounts,
    Map<String, dynamic>? report,
    Map<String, dynamic>? rollup,
  }) async {
    tester.view.physicalSize = const Size(1400, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    calls = [];
    urls = [];

    final api = ApiClient(
      client: MockClient((request) async {
        calls.add(request.url.path);
        urls.add(request.url);

        if (request.url.path.contains('/ads/rollup')) {
          return http.Response(
            jsonEncode(rollup ?? {'data': [], 'children_total': 0, 'not_connected': 0}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }

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
      // Google generates this name; the word in it is the only thing that says
      // what kind of campaign it is. Capitalised exactly as Google writes it,
      // so a case-sensitive match would pass here and fail on real data typed
      // any other way.
      {
        'id': '333',
        'name': 'LocalServicesCampaign:SystemGenerated:00062050e55e652b',
        'status': 'ENABLED',
        'clicks': 43,
        'impressions': 251,
        'conversions': 2,
        'all_conversions': 2,
        'cost': 60.92,
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

  /// LS ADS / SEARCH ADS. Jose, 2026-09-17.
  ///
  /// Google names every Local Services campaign
  /// `LocalServicesCampaign:SystemGenerated:...`, and there is no field on the
  /// campaign saying which kind it is — the name IS the type. So the split is a
  /// string match, and the test that matters is the one that proves each chip
  /// leaves the OTHER kind out rather than merely showing its own.
  testWidgets('LS Ads shows only the Local Services campaigns', (tester) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    // The control: all three kinds are there before anything is filtered.
    expect(find.textContaining('LocalServicesCampaign'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Search'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('ads-type-local_services')));
    await tester.pumpAndSettle();

    expect(find.textContaining('LocalServicesCampaign'), findsOneWidget);
    expect(
      find.text('Chimney - Cleveland - Search'),
      findsNothing,
      reason: 'LS Ads must drop the Search campaigns, not merely include its own',
    );
    expect(find.text('Chimney - Cleveland - Display'), findsNothing);
  });

  testWidgets('Search Ads shows everything that is not Local Services', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-type-search')));
    await tester.pumpAndSettle();

    expect(find.text('Chimney - Cleveland - Search'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);
    expect(
      find.textContaining('LocalServicesCampaign'),
      findsNothing,
      reason: 'Search Ads is defined as the rest, so the generated ones must go',
    );
  });

  /// BOTH SELECTED IS ALL OF THEM, not none.
  ///
  /// The two chips partition the list, so asking for both is asking for the
  /// whole thing. A pair of booleans would have needed a fourth case here and
  /// somebody would have picked the wrong one; a set makes the right answer the
  /// only one that can happen.
  testWidgets('selecting both kinds shows every campaign', (tester) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-type-local_services')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-type-search')));
    await tester.pumpAndSettle();

    expect(find.textContaining('LocalServicesCampaign'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Search'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);
  });

  /// The kind and the state are different questions and must combine.
  ///
  /// "What are my Search ads doing while Local Services is paused" is the
  /// question a business actually asks, and it needs both filters at once. If
  /// these shared one set of chips it could not be expressed.
  testWidgets('the kind filter combines with the status filter', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-type-search')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-status-PAUSED')));
    await tester.pumpAndSettle();

    // Search AND paused: the Display one only.
    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Search'), findsNothing);
    expect(find.textContaining('LocalServicesCampaign'), findsNothing);

    // And the obligation neither filter may break.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('ads-campaigns')),
        matching: find.text('All conversions'),
      ),
      findsOneWidget,
      reason: 'hiding rows is a choice; dropping a required FIELD is a breach',
    );
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

  testWidgets('the default range is yesterday, not thirty days', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    final asked = urls.firstWhere((u) => u.path.contains('/ads/performance'));

    expect(
      asked.queryParameters['from'],
      asked.queryParameters['to'],
      reason: 'Jose asked for a one-day default; a 30-day one costs thirty times '
          'the rows to answer a question about yesterday',
    );

    final yesterday = DateTime.now().subtract(const Duration(days: 1));
    expect(
      asked.queryParameters['to'],
      '${yesterday.year.toString().padLeft(4, '0')}-'
      '${yesterday.month.toString().padLeft(2, '0')}-'
      '${yesterday.day.toString().padLeft(2, '0')}',
      reason: 'never today: Google restates the current day as late conversions '
          'are attributed, so it is the one figure guaranteed to change after '
          'somebody has read it',
    );
  });

  testWidgets('searching the sidebar narrows it and keeps the rest reachable', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());

    await tester.enterText(
      find.byKey(const ValueKey('ads-account-search')),
      'atlanta',
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('ads-account-c2')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('ads-account-c1')),
      findsNothing,
      reason: 'a search that leaves every row on screen is a decoration',
    );

    // And it filters on the customer id too, because that is the other half of
    // how somebody names an account.
    await tester.enterText(
      find.byKey(const ValueKey('ads-account-search')),
      '4051729356',
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('ads-account-c1')), findsOneWidget);
    expect(find.byKey(const ValueKey('ads-account-c2')), findsNothing);
  });

  testWidgets('searching campaigns narrows the table, not the account', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('ads-campaign-search')),
      'display',
    );
    await tester.pumpAndSettle();

    expect(find.text('Chimney - Cleveland - Display'), findsOneWidget);
    expect(find.text('Chimney - Cleveland - Search'), findsNothing);

    // The account figures above the table are the whole account and must not
    // move when the table below is filtered - a total that tracks the filter is
    // a different and wrong statement.
    expect(find.text('1234.56 USD'), findsOneWidget);
  });

  testWidgets('the table reorders on the column that was clicked', (
    tester,
  ) async {
    await pump(tester, accounts: twoAccounts(), report: report());
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    double y(String label) => tester.getTopLeft(find.text(label)).dy;

    // Cost, descending, by default: 900 above 0.
    expect(
      y('Chimney - Cleveland - Search'),
      lessThan(y('Chimney - Cleveland - Display')),
    );

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('ads-campaigns')),
        matching: find.text('Campaign'),
      ),
    );
    await tester.pumpAndSettle();

    // Alphabetical now, which is the opposite order - so a dropped sort cannot
    // satisfy this by leaving the rows as the API returned them.
    expect(
      y('Chimney - Cleveland - Display'),
      lessThan(y('Chimney - Cleveland - Search')),
    );
  });

  testWidgets('a manager rolls up instead of reporting on itself', (
    tester,
  ) async {
    await pump(
      tester,
      accounts: [
        {
          'connection_id': 'm1',
          'customer_id': '9998887776',
          'name': 'A1 Chimney - Ads Manager',
          'is_manager': true,
        },
        ...twoAccounts(),
      ],
      report: report(),
      rollup: {
        'children_total': 3,
        'not_connected': 1,
        'data': [
          {
            'connection_id': 'c1',
            'id': '4051729356',
            'name': 'A1 Chimney - Cleveland',
            'clicks': 412,
            'impressions': 10500,
            'conversions': 31.5,
            'all_conversions': 44.25,
            'cost': 1234.56,
            'currency': 'USD',
          },
          {
            'connection_id': 'c2',
            'id': '8865675621',
            'name': 'A1 Chimney - Atlanta',
            'clicks': 88,
            'impressions': 900,
            'conversions': 4,
            'all_conversions': 6,
            'cost': 65.44,
            'currency': 'USD',
          },
        ],
      },
    );

    // Before the click, the row says what the click costs - this is the one
    // fan-out left on the screen and the only thing that makes it correct is
    // that somebody chose it knowing what it was.
    expect(
      find.textContaining('reads every account beneath it'),
      findsOneWidget,
      reason: 'a manager row that looks like any other row makes the fan-out a '
          'surprise rather than a choice',
    );

    await tester.tap(find.byKey(const ValueKey('ads-account-m1')));
    await tester.pumpAndSettle();

    expect(calls.where((c) => c.contains('/ads/rollup')), hasLength(1));
    expect(
      calls.where((c) => c.contains('/ads/performance')),
      isEmpty,
      reason: 'a manager holds no spend of its own, so the per-account report on '
          'one is a screen of zeroes',
    );

    // Summed across the children.
    final figures = find.byKey(const ValueKey('ads-rollup-figures'));
    expect(
      find.descendant(of: figures, matching: find.text('1300.00 USD')),
      findsOneWidget,
    );

    for (final label in [
      'Cost',
      'Clicks',
      'Impressions',
      'Conversions',
      'All conversions',
    ]) {
      expect(
        find.descendant(of: figures, matching: find.text(label)),
        findsOneWidget,
        reason: 'R.10 applies to every account level displayed, roll-up included',
      );
    }

    // And it says what the total does NOT cover. A figure quietly covering two
    // of three accounts reads as a complete one.
    expect(
      find.textContaining('not connected'),
      findsOneWidget,
      reason: 'the reader cannot ask about an omission nothing tells them about',
    );
  });
}
