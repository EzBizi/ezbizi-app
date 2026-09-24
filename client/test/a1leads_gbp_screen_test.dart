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

  /// The location filter offers each listing, labelled by place, and filters
  /// on its exact id.
  ///
  /// Every one of A-1's listings is titled with the bare brand, so a dropdown
  /// of titles would be "A1 Chimney" forty-six times. The label is the place;
  /// the value sent is the listing's GBP id.
  testWidgets('the location filter offers each listing and sends its id', (
    tester,
  ) async {
    final seen = <Uri>[];
    await _pump(tester, _handler(seen: seen));

    expect(find.text('All locations'), findsOneWidget);

    await tester.tap(find.text('All locations'));
    await tester.pumpAndSettle();

    expect(find.text('Boston, MA'), findsOneWidget);
    expect(find.text('Morton Grove, IL'), findsOneWidget);

    await tester.tap(find.text('Boston, MA'));
    await tester.pumpAndSettle();

    final asked = seen.lastWhere((u) => u.path.endsWith('/a1leads-gbp/reviews'));
    expect(asked.queryParameters['location'], 'locations/222', reason: 'the id, not the label');
    expect(asked.queryParameters.containsKey('city'), isFalse);
  });

  /// THE TABLE: Status and Notes from A-1's board, and Contact honestly absent.
  ///
  /// Rodrigo opened the board feed on 24 September. Measured after install,
  /// Status was filled on 387 of 387 cards and Notes on 354 - and Contact on
  /// NONE, because A1 Leads sends EzBizi only a saved customer match and never
  /// the name-guessing A1 Tools does. An empty Contact column would read as
  /// "these customers have no phone", so it is not drawn and the reason is.
  testWidgets('the table shows status and notes, and says why contact is absent', (
    tester,
  ) async {
    await _pump(tester, _handler(total: 3));

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    for (final column in ['CUSTOMER', 'REVIEW', 'DATE', 'RATING', 'STATUS', 'NOTES']) {
      expect(find.text(column), findsOneWidget, reason: '$column is a column we fill');
    }

    // The card joined to Dana's review, verbatim in A1 Leads' own words.
    expect(find.text('Working on it'), findsOneWidget);
    expect(find.text('Outreach made, waiting on the customer.'), findsOneWidget);

    // A review with no card says so with a dash, not a blank.
    expect(find.text('—'), findsWidgets);

    // Contact is absent, and the reason is on screen.
    expect(find.text('CONTACT'), findsNothing);
    expect(find.textContaining('does not send customer phone or email'), findsOneWidget);
    expect(find.textContaining('needs A-1 Chimney as the company in view'), findsOneWidget);
  });

  /// Replying switched off is NOT blamed on the company in view - the
  /// sentence production showed everyone, A-1 Chimney in view or not.
  testWidgets('replying switched off says so, not "change company"', (tester) async {
    await _pump(tester, _handler(replyBecause: 'not_switched_on'));
    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    expect(find.textContaining('not switched on yet'), findsOneWidget);
    expect(find.textContaining('company in view'), findsNothing);
  });

  /// A card that DOES carry a phone brings the Contact column back.
  ///
  /// The discriminating half of the test above: without it, a table that had
  /// simply lost the column would pass.
  testWidgets('a card with a phone number draws the contact column', (tester) async {
    await _pump(tester, _handler(total: 3, withPhone: true));

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    expect(find.text('CONTACT'), findsOneWidget);
    expect(find.text('(404) 555-0100'), findsOneWidget);
    expect(find.textContaining('does not send customer phone or email'), findsNothing);
  });

  /// A board that could not be read says so, and the reviews stay.
  testWidgets('an unreadable board is a sentence, not missing columns', (tester) async {
    await _pump(tester, _handler(total: 3, boardDown: true));

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();

    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.textContaining('Status and Notes could not be read'), findsOneWidget);
    expect(find.textContaining('HTTP 403'), findsOneWidget);
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

  /// REPLY: live check, draft, confirm, post - and the exact revision sent.
  ///
  /// Rodrigo's handoff, 24 September. The discriminating moments: nothing is
  /// posted when "Post public reply" is pressed, only when the consequence has
  /// been read and "Post it" answered; the request names the exact review and
  /// the LIVE revision from the preflight, not the snapshot; and it carries no
  /// actor - the server derives who is signed in.
  testWidgets('a reply is checked live, confirmed, and posted against its revision', (
    tester,
  ) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(replyAvailable: true, replyGranted: true, requests: requests));

    await tester.tap(find.text('Reply on Google'));
    await tester.pumpAndSettle();

    expect(find.text('Live Google review'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'We would like to look into this.');
    await tester.tap(find.byKey(const ValueKey('gbp-reply-post')));
    await tester.pumpAndSettle();

    expect(
      requests.where((r) => r.url.path.endsWith('/a1leads-gbp/reply') && r.method == 'POST'),
      isEmpty,
      reason: 'the first press arms the confirmation and sends nothing',
    );
    expect(find.textContaining("under A-1 Chimney's name"), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('gbp-reply-confirm')));
    await tester.pumpAndSettle();

    final preflight = requests.singleWhere((r) => r.url.path.endsWith('/a1leads-gbp/reply/preflight'));
    expect(preflight.url.queryParameters['googleReviewName'], 'accounts/a/locations/l/reviews/r-1');

    final send = requests.singleWhere((r) => r.url.path.endsWith('/a1leads-gbp/reply') && r.method == 'POST');
    final body = jsonDecode(send.body) as Map<String, dynamic>;
    expect(body['googleReviewName'], 'accounts/a/locations/l/reviews/r-1');
    expect(body['expectedReviewUpdatedAt'], '2026-09-24T12:00:00Z', reason: 'the live revision');
    expect(body.containsKey('actorId'), isFalse, reason: 'the server derives the signed-in user');

    expect(find.textContaining('Public reply posted on Google'), findsOneWidget);
    expect(find.text('Replied on Google'), findsOneWidget, reason: 'drawn as answered at once');
  });

  /// The editor and its confirmation fit a phone.
  testWidgets('the reply editor and its confirmation fit a phone', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (d) {
      complaints.add(d.exceptionAsString());
      previous?.call(d);
    };
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, _handler(replyAvailable: true, replyGranted: true), size: const Size(375, 1400));

    await tester.ensureVisible(find.text('Reply on Google'));
    await tester.tap(find.text('Reply on Google'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, 'We would like to look into this.');
    await tester.ensureVisible(find.byKey(const ValueKey('gbp-reply-post')));
    await tester.tap(find.byKey(const ValueKey('gbp-reply-post')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('gbp-reply-confirm')), findsOneWidget, reason: 'the control');
    expect(complaints.where((c) => c.contains('overflowed')), isEmpty);
  });

  /// Holding the grant is not enough: the SERVER must say reply is available.
  ///
  /// It is the only side that knows whether the live connection is configured
  /// and whether A-1 Chimney is the company in view. A button drawn on the
  /// grant alone would, on every other company, be a control that only refuses.
  testWidgets('the grant alone draws no reply button when the server says no', (tester) async {
    await _pump(tester, _handler(total: 2, replyGranted: true));

    // The control: the unanswered review is there.
    expect(find.text('No reply'), findsOneWidget);
    expect(find.text('Reply on Google'), findsNothing);
  });

  /// The table's Reply opens the editor on the board, where the review can be read.
  testWidgets('reply from the table opens the editor on the board', (tester) async {
    await _pump(tester, _handler(total: 3, replyAvailable: true, replyGranted: true));

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton, 'Reply').first);
    await tester.pumpAndSettle();

    expect(find.text('CUSTOMER'), findsNothing, reason: 'back on the board');
    expect(find.byKey(const ValueKey('gbp-reply-editor')), findsOneWidget);
    expect(find.text('Live Google review'), findsOneWidget);
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
List<Map<String, Object?>> _reviews(
  int total,
  int offset,
  int pageSize, {
  bool withPhone = false,
  bool boardDown = false,
}) {
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
      'board': {
        'status': 'Working on it',
        'note': 'Outreach made, waiting on the customer.',
        // Typed, because a literal of three nulls infers Map<String, Null> and
        // the phone-number variant below could not be written into it.
        'contact': <String, Object?>{'name': null, 'phone': null, 'email': null},
      },
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

  if (withPhone) {
    ((all[0]['board'] as Map)['contact'] as Map)['phone'] = '(404) 555-0100';
  }

  if (boardDown) {
    for (final r in all) {
      r['board'] = null;
    }
  }

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
  bool withPhone = false,
  bool boardDown = false,
  bool replyAvailable = false,
  String? replyBecause = 'company_not_in_view',
  bool replyGranted = false,
  List<Uri>? seen,
  List<http.Request>? requests,
}) {
  return (request) async {
    seen?.add(request.url);
    requests?.add(request);

    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Jose Silva',
          'email': 'jose@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Administrator'},
          'permissions': [
            'a1leads_gbp.view',
            // Replying needs what reading needs (Jose, 2026-09-24); the
            // flag now only varies what the SERVER says is available.
            if (replyGranted) 'a1leads_gbp.view',
          ],
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

    // The live preflight: the review as Google has it NOW.
    if (request.url.path.endsWith('/a1leads-gbp/reply/preflight')) {
      return _json({
        'data': {
          'googleReviewName': 'accounts/a/locations/l/reviews/r-1',
          'reviewerName': 'Dana Whitfield',
          'rating': 2,
          'comment': 'They were late twice.',
          'reviewUpdatedAt': '2026-09-24T12:00:00Z',
          'replyStatus': 'none',
          'replyText': null,
          'canReply': true,
        },
      });
    }

    if (request.url.path.endsWith('/a1leads-gbp/reply') && request.method == 'POST') {
      return _json({
        'data': {
          'ok': true,
          'text': 'We would like to look into this.',
          'sentAt': '2026-09-24T12:01:00Z',
          'reply_status': 'sent',
          'receiptPersisted': true,
        },
      });
    }

    // The list route, which is what a filter change and a page ask for.
    if (request.url.path.endsWith('/a1leads-gbp/reviews')) {
      final offset = int.tryParse(request.url.queryParameters['offset'] ?? '0') ?? 0;

      return _json({
        'data': {
          'source': source,
          'reviews': usable ? _reviews(total, offset, pageSize, withPhone: withPhone, boardDown: boardDown) : <dynamic>[],
          'meta': {'total': total},
          'board': boardDown
              ? {'available': false, 'because': 'A1 Leads refused cards with HTTP 403.'}
              : {'available': true, 'because': null},
        },
      });
    }

    if (request.url.path.endsWith('/a1leads-gbp')) {
      return _json({
        'data': {
          'source': source,
          'reply_available': replyAvailable,
          'reply_unavailable_because': replyAvailable ? null : replyBecause,
          'summary': usable ? {'total': 13169} : null,
          'reviews': usable ? _reviews(total, 0, pageSize, withPhone: withPhone, boardDown: boardDown) : <dynamic>[],
          'meta': {'total': total},
          'health': null,
          'locations': usable
              ? [
                  {'name': 'locations/222', 'label': 'Boston, MA'},
                  {'name': 'locations/111', 'label': 'Morton Grove, IL'},
                ]
              : <dynamic>[],
          'board': boardDown
              ? {'available': false, 'because': 'A1 Leads refused cards with HTTP 403.'}
              : {'available': true, 'because': null},
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
