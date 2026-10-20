import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile/performance_view.dart';
import 'package:ezbizi_client/screens/business_profile_screen.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Business Profile › Performance (UI queue 114). Every name and number is
/// invented.
void main() {
  testWidgets('tiles carry the totals and the change from the period before', (
    tester,
  ) async {
    final fake = _Fake();
    await _pumpScreen(tester, fake);

    // Its own chip since 2026-10-20: the screen opens on it directly.

    expect(find.byKey(const ValueKey('gbp-performance')), findsOneWidget);
    expect(find.text('412'), findsWidgets);
    expect(find.text('938'), findsWidgets);
    expect(find.text('48.2k'), findsOneWidget);
    expect(find.text('Views · 71% Maps'), findsOneWidget);

    // 412 against 368 is +12%; 276 against 294 is -6%; 31 against 0 is +31.
    expect(_changeOf(tester, 'calls'), '▲ 12%');
    expect(_changeOf(tester, 'directions'), '▼ 6%');
    expect(_changeOf(tester, 'messages'), '▲ 31');

    // The second request is the same length, ending the day before.
    final yesterday = _yesterday();
    final from = DateTime(yesterday.year, yesterday.month, yesterday.day - 27);
    final before = DateTime(from.year, from.month, from.day - 1);
    final current = fake.performance.firstWhere(
      (q) => q['to'] == dayKey(yesterday),
    );
    final previous = fake.performance.firstWhere(
      (q) => q['to'] == dayKey(before),
    );
    expect(current['from'], dayKey(from));
    expect(
      previous['from'],
      dayKey(DateTime(before.year, before.month, before.day - 27)),
    );

    // Up and down are drawn in different colours, both off the theme.
    final up = tester.widget<Text>(
      find.byKey(const ValueKey('gbp-performance-change-calls')),
    );
    final down = tester.widget<Text>(
      find.byKey(const ValueKey('gbp-performance-change-directions')),
    );
    expect(up.style?.color, isNot(down.style?.color));
  });

  testWidgets('the freshness line names the last day Google filled in', (
    tester,
  ) async {
    await _pumpView(tester, _Fake());

    expect(
      find.text(
        "Google's numbers run to Sat 17 Oct; the last days fill in later.",
      ),
      findsOneWidget,
    );
  });

  testWidgets('clicking a tile puts it on the daily chart', (tester) async {
    await _pumpView(tester, _Fake());

    expect(
      find.byKey(const ValueKey('gbp-performance-chart-calls')),
      findsOneWidget,
    );
    expect(find.text('Calls by day'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('gbp-performance-tile-views')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('gbp-performance-chart-calls')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('gbp-performance-chart-views')),
      findsOneWidget,
    );
    expect(find.text('Views by day'), findsOneWidget);
  });

  testWidgets('a listing row opens its searches beside the table, by months', (
    tester,
  ) async {
    final fake = _Fake();
    await _pumpView(tester, fake);

    expect(find.byKey(const ValueKey('gbp-performance-table')), findsOneWidget);
    expect(find.text('Brightside · Springfield'), findsOneWidget);
    expect(find.text('Brightside · Riverton'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('gbp-performance-row-loc-1')));
    await tester.pumpAndSettle();

    expect(fake.terms.single.path, contains('/locations/loc-1/search-terms'));
    expect(fake.terms.single.queryParameters['months'], '3');
    expect(find.text('Searches · Brightside · Springfield'), findsOneWidget);
    expect(find.text('chimney sweep near me'), findsOneWidget);
    expect(find.text('2.1k'), findsOneWidget);
    // Beside the table, not instead of it.
    expect(find.byKey(const ValueKey('gbp-performance-table')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('gbp-performance-months')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Last 12 months').last);
    await tester.pumpAndSettle();

    expect(fake.terms, hasLength(2));
    expect(fake.terms.last.queryParameters['months'], '12');
  });

  testWidgets(
    "when Google does not answer, its sentence shows with Try again",
    (tester) async {
      final fake = _Fake()..termsFail = true;
      await _pumpView(tester, fake);

      await tester.tap(find.byKey(const ValueKey('gbp-performance-row-loc-2')));
      await tester.pumpAndSettle();

      expect(
        find.text(
          'Google did not return the search terms for this listing just now. '
          'Try again later.',
        ),
        findsOneWidget,
      );

      fake.termsFail = false;
      await tester.tap(
        find.byKey(const ValueKey('gbp-performance-searches-retry')),
      );
      await tester.pumpAndSettle();

      expect(fake.terms, hasLength(2));
      expect(find.text('chimney sweep near me'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('gbp-performance-searches-error')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'narrow: tiles two to a row, listings as rows, searches in place',
    (tester) async {
      await _pumpView(tester, _Fake(), size: const Size(420, 900));

      final calls = tester.getRect(
        find.byKey(const ValueKey('gbp-performance-tile-calls')),
      );
      final website = tester.getRect(
        find.byKey(const ValueKey('gbp-performance-tile-website')),
      );
      final directions = tester.getRect(
        find.byKey(const ValueKey('gbp-performance-tile-directions')),
      );
      expect(website.top, calls.top);
      expect(directions.top, greaterThan(calls.bottom - 1));
      expect(calls.width, lessThan(420 / 2));

      expect(find.byKey(const ValueKey('gbp-performance-table')), findsNothing);
      expect(find.text('188 calls · 21.4k views'), findsOneWidget);

      await tester.tap(find.text('Brightside · Springfield'));
      await tester.pumpAndSettle();

      expect(find.text('Searches · Brightside · Springfield'), findsOneWidget);
      expect(find.text('Brightside · Riverton'), findsNothing);

      await tester.tap(
        find.byKey(const ValueKey('gbp-performance-searches-back')),
      );
      await tester.pumpAndSettle();

      expect(find.text('Brightside · Riverton'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('no listings, and no numbers, each say so', (tester) async {
    await _pumpView(tester, _Fake()..noListings = true);
    expect(find.text('No listings yet'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('gbp-performance-tile-calls')),
      findsNothing,
    );

    await _pumpView(tester, _Fake()..noData = true);
    expect(find.text('No numbers for these dates'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('gbp-performance-tile-calls')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('gbp-performance-fresh')), findsNothing);
    // The listings stay, so their searches can still be opened.
    expect(find.byKey(const ValueKey('gbp-performance-table')), findsOneWidget);
  });
}

DateTime _yesterday() {
  final now = DateTime.now();
  return DateTime(now.year, now.month, now.day - 1);
}

String? _changeOf(WidgetTester tester, String tile) => tester
    .widget<Text>(find.byKey(ValueKey('gbp-performance-change-$tile')))
    .data;

Map<String, int> _metrics({
  int calls = 0,
  int website = 0,
  int directions = 0,
  int messages = 0,
  int desktopSearch = 0,
  int mobileSearch = 0,
  int desktopMaps = 0,
  int mobileMaps = 0,
}) => {
  'CALL_CLICKS': calls,
  'WEBSITE_CLICKS': website,
  'BUSINESS_DIRECTION_REQUESTS': directions,
  'BUSINESS_CONVERSATIONS': messages,
  'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH': desktopSearch,
  'BUSINESS_IMPRESSIONS_MOBILE_SEARCH': mobileSearch,
  'BUSINESS_IMPRESSIONS_DESKTOP_MAPS': desktopMaps,
  'BUSINESS_IMPRESSIONS_MOBILE_MAPS': mobileMaps,
};

class _Fake {
  bool termsFail = false;
  bool noListings = false;
  bool noData = false;

  final performance = <Map<String, String>>[];
  final terms = <Uri>[];

  http.Response _json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json; charset=utf-8'},
  );

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/business-profile/performance')) {
      final q = request.url.queryParameters;
      performance.add(q);
      final current = q['to'] == dayKey(_yesterday());
      return _json({'data': _performance(q, current)});
    }
    if (path.contains('/search-terms')) {
      terms.add(request.url);
      if (termsFail) {
        return _json({
          'message':
              'Google did not return the search terms for this listing just '
              'now. Try again later.',
        }, 502);
      }
      return _json({
        'data': [
          {'term': 'chimney sweep near me', 'impressions': 2100},
          {'term': 'chimney repair', 'impressions': 1400},
          {'term': 'brightside home', 'impressions': 790},
        ],
      });
    }
    if (path.contains('/business-profile/locations')) {
      return _json({
        'data': const [],
        'meta': {'total': 0, 'not_live': 0, 'connections': const []},
      });
    }

    return _json(const {});
  }

  Map<String, dynamic> _performance(Map<String, String> q, bool current) {
    if (noListings) {
      return {
        'from': q['from'],
        'to': q['to'],
        'last_date': null,
        'totals': _metrics(),
        'daily': const [],
        'by_location': const [],
      };
    }

    final springfield = current
        ? _metrics(
            calls: 188,
            website: 402,
            directions: 131,
            messages: 14,
            desktopSearch: 3000,
            mobileSearch: 4000,
            desktopMaps: 4400,
            mobileMaps: 10000,
          )
        : _metrics(calls: 170, website: 400, directions: 140);
    final riverton = current
        ? _metrics(
            calls: 224,
            website: 536,
            directions: 145,
            messages: 17,
            desktopSearch: 3000,
            mobileSearch: 4000,
            desktopMaps: 5600,
            mobileMaps: 14200,
          )
        : _metrics(calls: 198, website: 500, directions: 154);

    final totals = current
        ? _metrics(
            calls: 412,
            website: 938,
            directions: 276,
            messages: 31,
            desktopSearch: 6000,
            mobileSearch: 8000,
            desktopMaps: 10000,
            mobileMaps: 24200,
          )
        : _metrics(calls: 368, website: 900, directions: 294);

    final empty = noData;
    final from = DateTime.parse(q['from']!);

    return {
      'from': q['from'],
      'to': q['to'],
      'last_date': empty ? null : '2026-10-17',
      'totals': empty ? _metrics() : totals,
      'daily': empty
          ? const []
          : [
              for (var i = 0; i < 3; i++)
                {
                  'date': dayKey(DateTime(from.year, from.month, from.day + i)),
                  ..._metrics(calls: 10 + i, website: 20, desktopMaps: 30),
                },
            ],
      'by_location': [
        {
          'id': 'loc-1',
          'name': 'Brightside · Springfield',
          ...(empty ? _metrics() : springfield),
        },
        {
          'id': 'loc-2',
          'name': 'Brightside · Riverton',
          ...(empty ? _metrics() : riverton),
        },
      ],
    };
  }
}

Future<void> _pumpScreen(WidgetTester tester, _Fake fake) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(fake.handle));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BusinessProfileScreen(
          api: api,
          session: Session(api),
          performance: true,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpView(
  WidgetTester tester,
  _Fake fake, {
  Size size = const Size(1200, 1400),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(fake.handle));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        // A new key each pump, so a second pump in one test starts afresh.
        body: BusinessProfilePerformance(
          key: UniqueKey(),
          api: api,
          session: Session(api),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
