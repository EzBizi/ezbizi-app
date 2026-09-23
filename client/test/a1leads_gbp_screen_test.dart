import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/a1leads_gbp_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A1Leads GBP — the window onto Rodrigo's system, and only that.
///
/// ── The two readings this screen must never allow ────────────────
///
/// The data is a daily snapshot of another system, so both of the plausible
/// wrong impressions are expensive:
///
///   - a stale snapshot drawn as current. Every request still answers, the
///     list still looks right, and it is a week old.
///   - an unreachable source drawn as an empty list, which says A-1 Chimney
///     has no reviews. It has over thirteen thousand.
///
/// So each case below varies only the server's `source` block and asserts which
/// sentence the screen chooses.
///
/// ── And the read-only half ───────────────────────────────────────
///
/// The A1 Tools tab this is ported from can reply on Google, match a reviewer
/// to a customer and report spam. All three are writes, and this consumer is
/// admitted to five GET routes against a service running `MIGRATION_READ_ONLY`.
/// A button here could only ever produce a 503, so there are tests below that
/// no such control exists.
void main() {
  testWidgets('a healthy source shows the reviews and when they were captured', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.textContaining('Snapshot captured'), findsOneWidget);
    expect(find.text('Dana Whitfield'), findsOneWidget);
  });

  /// The case the whole design exists for.
  testWidgets('a stale snapshot says so, in words, and is not drawn as current', (
    tester,
  ) async {
    await _pump(tester, _handler(stale: true));

    expect(find.textContaining('has not refreshed since'), findsOneWidget);
    expect(
      find.textContaining('historical rather than current'),
      findsOneWidget,
      reason: 'a stale list that looks current is the expensive reading',
    );

    // And the rows are still there: stale is a warning about the data, not a
    // reason to hide it.
    expect(find.text('Dana Whitfield'), findsOneWidget);
  });

  /// The opposite failure, and the worse one.
  testWidgets('an unreachable source is a sentence, never an empty list', (
    tester,
  ) async {
    await _pump(tester, _handler(reachable: false));

    expect(find.textContaining('A1 Leads is not answering'), findsOneWidget);
    expect(find.textContaining('nothing is broken'), findsOneWidget);
    expect(
      find.byType(ListView),
      findsNothing,
      reason: 'an empty list here would say the business has no reviews',
    );
  });

  testWidgets('an unconfigured source names that as the reason', (tester) async {
    await _pump(tester, _handler(configured: false));

    expect(find.textContaining('not connected here'), findsOneWidget);
  });

  /// It points at where OUR data lives.
  ///
  /// Written down because the mistake this screen is a correction of was
  /// exactly this confusion: nobody reading this tab should conclude that
  /// EzBizi's own Reviews and Business Profile moved or went away.
  testWidgets('it says our own modules are still in Marketing', (tester) async {
    await _pump(tester, _handler(reachable: false));

    expect(find.textContaining('are in Marketing'), findsOneWidget);
  });

  /// And it draws no chips of its own.
  ///
  /// The negative control for the shape: an earlier version of this file
  /// asserted two ChoiceChips here, because the module wrongly contained
  /// EzBizi's own two. Chips coming back would mean that mistake had returned.
  testWidgets('it holds no chips, because it holds no modules', (tester) async {
    await _pump(tester, _handler());

    expect(find.byType(ChoiceChip), findsNothing);
  });

  /// THE DEFAULT IS A REQUEST, not a label on a dropdown.
  ///
  /// The tab opens on the negative reviews the way the A1 Tools one does. A
  /// version that drew "Negative (1–3★)" in the field and asked the server for
  /// everything would look identical and be wrong in the direction nobody
  /// checks: thirteen thousand five-star reviews burying the four that matter.
  testWidgets('it opens by asking for the negative reviews', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, _handler(seen: seen));

    final first = seen.firstWhere((u) => u.path.endsWith('/a1leads-gbp'));

    expect(first.queryParameters['rating'], 'negative');
    expect(first.queryParameters['sort'], 'newest');
  });

  /// Paging asks the LIST route, and appends rather than replaces.
  ///
  /// The route matters as much as the rows: `/a1leads-gbp` also fetches the
  /// summary, the health block and the location list, none of which can have
  /// changed between one page and the next. Spending them on a scroll is four
  /// upstream reads against a service we were asked to keep under 120 a minute.
  testWidgets('load more asks the list route and adds to what is shown', (
    tester,
  ) async {
    final seen = <Uri>[];
    await _pump(tester, _handler(total: 2, pageSize: 1, seen: seen));

    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.textContaining('Load more'), findsOneWidget);

    await tester.tap(find.textContaining('Load more'));
    await tester.pumpAndSettle();

    final paged = seen.lastWhere((u) => u.path.endsWith('/a1leads-gbp/reviews'));

    expect(paged.queryParameters['offset'], '1');
    expect(paged.queryParameters['rating'], 'negative', reason: 'the filters travel with the page');

    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.text('Marcus Reed'), findsOneWidget, reason: 'appended, not replaced');
  });

  /// A-1's own triage is shown, and an uncomputed one is not guessed at.
  ///
  /// `match_tier` has three states and only two of them are an answer. Drawing
  /// a null tier as "Unverified" would put customers nobody has checked yet
  /// into the possible-spam queue, which is the reading somebody acts on.
  testWidgets('the match line shows a match, a non-match, and nothing for neither', (
    tester,
  ) async {
    await _pump(tester, _handler(total: 3));

    expect(find.textContaining('Matched: Dana M. Whitfield'), findsOneWidget);
    expect(find.textContaining('Unverified'), findsOneWidget);

    // The third row is drawn - so the absence below is about its tier and not
    // about the row failing to render - and carries neither line.
    expect(find.text('Kevin Ha'), findsOneWidget);
    expect(
      find.textContaining('Matched'),
      findsOneWidget,
      reason: 'an uncomputed tier is a third state, not a non-match',
    );
  });

  /// The reply Google already holds is shown; the button to write one is not.
  testWidgets('an existing reply is shown and no reply can be written here', (
    tester,
  ) async {
    await _pump(tester, _handler(total: 2));

    expect(find.text('Replied on Google'), findsOneWidget);
    expect(find.textContaining('Glad we could help'), findsOneWidget);

    // The unanswered one is flagged, because that is a fact about the listing.
    expect(find.text('No reply'), findsOneWidget);

    // But there is nowhere to answer it. This is the read-only boundary drawn
    // as an absence, so it is asserted beside the presence above rather than
    // alone - a screen that failed to render at all would pass it otherwise.
    expect(find.textContaining('Reply on Google'), findsNothing);
    expect(find.textContaining('Report spam'), findsNothing);
    expect(find.textContaining('Match manually'), findsNothing);
  });

  /// IT FITS ON A PHONE, and an overflow is not a test failure by itself.
  ///
  /// A RenderFlex that runs past its box paints yellow stripes in a debug build
  /// and passes every finder above, so the six-dropdown filter bar and the long
  /// review rows could be unusable at 375px with the suite green. The only way
  /// to see it is to catch what Flutter reports rather than what it renders.
  testWidgets('it lays out at phone width without overflowing', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      complaints.add(details.exceptionAsString());
      previous?.call(details);
    };
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, _handler(total: 3), size: const Size(375, 812));

    expect(
      complaints.where((c) => c.contains('overflowed')),
      isEmpty,
      reason: 'the filter bar and the review rows have to fit a phone',
    );

    // And the table at the narrowest width it is ever offered at, which is the
    // one where its columns are tightest.
    await _pump(tester, _handler(total: 3), size: const Size(900, 900));
    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    expect(
      complaints.where((c) => c.contains('overflowed')),
      isEmpty,
      reason: 'nine columns at 900px is the tightest the table is ever drawn',
    );

    // And the control: the rows really were drawn, so the absence above is
    // about the layout rather than about an empty screen.
    expect(find.text('Dana Whitfield'), findsOneWidget);
  });

  /// The location filter names cities, with the state that tells two apart.
  ///
  /// Every one of A-1's listings is titled with the bare brand, so a dropdown
  /// of titles would be "A1 Chimney" twenty-nine times. The state is there for
  /// the same reason: there is a Rochester in more than one of them.
  testWidgets('the location filter offers the cities the source could name', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('All locations'), findsOneWidget);

    await tester.tap(find.text('All locations'));
    await tester.pumpAndSettle();

    expect(find.text('Boston, MA'), findsOneWidget);
    expect(find.text('Morton Grove, IL'), findsOneWidget);
  });

  /// THE TABLE, and the columns that are honestly not in it.
  ///
  /// Jose asked for A1 Tools' bad-review board columns. Contact, Status and
  /// Notes all live on `GET /api/v1/reviews/workflow/cards`, which answers 403
  /// for this consumer — measured, not assumed. Three empty headers would read
  /// as "this customer has no phone number" rather than "we were not granted
  /// this", so they are absent AND said, and the sentence is asserted here
  /// because an unexplained absence is the thing this tab must never produce.
  testWidgets('the table shows the columns it can fill and names the ones it cannot', (
    tester,
  ) async {
    await _pump(tester, _handler(total: 3));

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    for (final column in ['CUSTOMER', 'REVIEW', 'DATE', 'RATING', 'REPLY']) {
      expect(find.text(column), findsOneWidget, reason: '$column is a column we can fill');
    }

    // The rows really are there, in the table rather than the board.
    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.textContaining('Matched: Dana M. Whitfield'), findsOneWidget);

    // And the absent three are explained rather than left blank.
    expect(find.textContaining('Contact, Status and Notes are not here yet'), findsOneWidget);
    expect(find.textContaining('cannot write to it'), findsOneWidget);
  });

  /// The board is the default, because a review is prose.
  testWidgets('it opens on the board, not the table', (tester) async {
    await _pump(tester, _handler(total: 3));

    expect(find.text('CUSTOMER'), findsNothing);
    expect(find.text('Board'), findsOneWidget, reason: 'the toggle is offered');
  });

  /// A table narrower than the board it replaces is not offered at all.
  testWidgets('the table toggle is absent at phone width', (tester) async {
    await _pump(tester, _handler(total: 3), size: const Size(375, 812));

    expect(find.text('Table'), findsNothing);
    expect(find.text('Board'), findsNothing);

    // The control: the screen rendered, so the absence is about the width.
    expect(find.text('Dana Whitfield'), findsOneWidget);
  });

  /// Nothing matching the filters is a different sentence from nothing at all.
  testWidgets('an empty page blames the filters, not the business', (tester) async {
    await _pump(tester, _handler(total: 0));

    expect(find.textContaining('Nothing matches these filters'), findsOneWidget);
    expect(find.textContaining('has no reviews'), findsNothing);
  });
}

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: A1LeadsGbpScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// The three reviews, in the order the server would send them.
///
/// Field names are A1 Leads', taken from the reader A1 Tools has been pointing
/// at this endpoint for months rather than from a specification.
List<Map<String, Object?>> _reviews(int total, int offset, int pageSize) {
  final all = <Map<String, Object?>>[
    {
      'id': 1,
      'google_review_name': 'accounts/a/locations/l/reviews/r-1',
      'reviewer_name': 'Dana Whitfield',
      'rating': 2,
      'comment': 'They were late twice.',
      'review_created_at': '2026-09-23T00:43:20.682472Z',
      'location_address': '204 Ark Rd, Mt Laurel Township, NJ, 08054',
      'location_title': 'A1 Chimney',
      'match_tier': 'high',
      'match_customer_name': 'Dana M. Whitfield',
      'workiz_tenant': 'NJ',
      'reply': null,
    },
    {
      'id': 2,
      'google_review_name': 'accounts/a/locations/l/reviews/r-2',
      'reviewer_name': 'Marcus Reed',
      'rating': 3,
      'comment': 'Fine in the end.',
      'review_created_at': '2026-09-22T11:02:00.000000Z',
      'location_address': '8145 Austin Ave, Morton Grove, IL, 60053',
      'match_tier': 'none',
      'reply': 'Glad we could help, Marcus.',
      'reply_at': '2026-09-22T15:00:00.000000Z',
    },
    {
      'id': 3,
      'google_review_name': 'accounts/a/locations/l/reviews/r-3',
      'reviewer_name': 'Kevin Ha',
      'rating': 1,
      'comment': 'Very disorganized.',
      'review_created_at': '2026-09-21T08:00:00.000000Z',
      // No `match_tier` at all: not computed, which is a third state.
      'reply': null,
    },
  ];

  return all
      .take(total)
      .skip(offset)
      .take(pageSize)
      .toList();
}

MockClientHandler _handler({
  bool configured = true,
  bool reachable = true,
  bool stale = false,
  int total = 1,
  int pageSize = 20,
  List<Uri>? seen,
}) {
  return (request) async {
    seen?.add(request.url);

    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Jose Silva',
          'email': 'jose@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Administrator'},
          'permissions': const ['a1leads_gbp.view'],
          'modules': [
            {
              'key': 'a1leads_gbp',
              'label': 'A1Leads GBP',
              'available': true,
              'reachable': true,
            },
          ],
          'preferences': <String, dynamic>{},
          'organization': {
            'id': 'org-1',
            'name': 'Everdoors',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': 'org-1',
            'label': 'Everdoors',
            'path': ['Everdoors'],
          },
          'companies': <Map<String, Object?>>[],
          'franchises': <Map<String, Object?>>[],
        },
      });
    }

    final usable = configured && reachable;
    final source = {
      'configured': configured,
      'reachable': usable,
      'captured_at': '2026-09-23T15:01:07.302Z',
      'installed_at': '2026-09-23T15:33:02.864Z',
      'generation': '20260923T150107Z-23510eb0',
      'hours_old': stale ? 41.5 : 2.1,
      'stale': stale,
      'because': configured
          ? (reachable ? null : 'A1 Leads refused reviews with HTTP 403.')
          : 'No A1 Leads key is configured for this environment.',
    };

    // The list route, which is what a filter change and a page ask for.
    if (request.url.path.endsWith('/a1leads-gbp/reviews')) {
      final offset = int.tryParse(request.url.queryParameters['offset'] ?? '0') ?? 0;

      return _json({
        'data': {
          'source': source,
          'reviews': usable ? _reviews(total, offset, pageSize) : <dynamic>[],
          'meta': {'total': total},
        },
      });
    }

    if (request.url.path.endsWith('/a1leads-gbp')) {
      return _json({
        'data': {
          'source': source,
          'summary': usable ? {'total': 13169} : null,
          'reviews': usable ? _reviews(total, 0, pageSize) : <dynamic>[],
          'meta': {'total': total},
          'health': null,
          'cities': usable
              ? [
                  {'city': 'Boston', 'state': 'MA'},
                  {'city': 'Morton Grove', 'state': 'IL'},
                ]
              : <dynamic>[],
        },
      });
    }

    return _json({'data': <dynamic>[]});
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
