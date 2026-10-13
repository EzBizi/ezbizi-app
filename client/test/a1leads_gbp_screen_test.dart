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
  testWidgets('it says our own modules are in Customer Experience', (tester) async {
    await _pump(tester, _handler(reachable: false));

    expect(find.textContaining('are in Customer Experience'), findsOneWidget);
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
    await _board(tester);

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
    await _board(tester);

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

  /// THE TABLE: Status and Notes from A-1's board, and Contact honestly empty.
  ///
  /// Rodrigo opened the board feed on 24 September. Measured after install,
  /// Status was filled on 387 of 387 cards and Notes on 354 - and Contact on
  /// NONE, because A1 Leads sends EzBizi only a saved customer match and never
  /// the name-guessing A1 Tools does. The column is drawn - Jose asked for it,
  /// 2026-09-28 - and with no customer matched it holds dashes, with the
  /// reason beneath the table so the dashes do not read as "no phone".
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

    // Contact is empty, and the reason is on screen.
    expect(find.text('CONTACT'), findsOneWidget);
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

  /// Seeing the tab without the Reply permission says which permission.
  testWidgets('a viewer without the reply permission is told so', (tester) async {
    await _pump(tester, _handler(replyBecause: 'no_permission'));

    expect(find.textContaining('needs the A1Leads GBP Reply permission'), findsOneWidget);
    expect(find.textContaining('not switched on'), findsNothing);
  });

  /// The customer and job A1 Leads matched, on the card - Rodrigo's cutover
  /// checklist, row 5. Board: who, the job, a Workiz link, and the updates
  /// folded until opened. Table: the job line under the name.
  testWidgets('a matched customer and their job are shown, updates folded', (tester) async {
    await _pump(tester, _handler(total: 3, withCustomer: true));

    expect(find.textContaining('Job #100077846 · 2026-09-01 · Tech: Amit · Assigned to Adeline'),
        findsOneWidget, reason: 'the table carries the job line');

    await _board(tester);
    expect(find.textContaining('(555) 555-0101'), findsOneWidget);
    expect(find.text('Open in Workiz'), findsOneWidget);
    expect(find.text('1 update'), findsOneWidget);
    expect(find.textContaining('Called, left voicemail.'), findsNothing, reason: 'folded until opened');

    await tester.tap(find.text('1 update'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Called, left voicemail.'), findsOneWidget);

    // The control: a card with none of it draws none of it.
    expect(find.byKey(const ValueKey('gbp-customer-accounts/a/locations/l/reviews/r-2')), findsNothing);
  });

  /// DRAFT WITH AI fills the editor and posts nothing. Rodrigo: "Never send a
  /// draft just because it was generated" - it goes out only through the same
  /// Post and confirm as a typed reply.
  testWidgets('draft with AI fills the editor and posts nothing', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(replyAvailable: true, replyGranted: true, opsAvailable: true, requests: requests));
    await _board(tester);
    await tester.tap(find.text('Reply on Google'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('gbp-reply-draft')));
    await tester.pumpAndSettle();

    expect(find.text('Hi Dana, thank you for telling us about the delay.'), findsOneWidget);
    final draft = requests.singleWhere((r) => r.url.path.endsWith('/draft'));
    expect(draft.url.path, endsWith('/a1leads-gbp/reviews/1/draft'), reason: "A1 Leads' review id");
    expect(requests.where((r) => r.url.path.endsWith('/a1leads-gbp/reply') && r.method == 'POST'), isEmpty);
  });

  /// UI queue 77: the draft says it is AI's until somebody changes it.
  testWidgets('a draft with AI is marked until the words change', (tester) async {
    await _pump(tester, _handler(replyAvailable: true, replyGranted: true, opsAvailable: true));
    await _board(tester);
    await tester.tap(find.text('Reply on Google'));
    await tester.pumpAndSettle();

    final mark = find.byKey(const ValueKey('gbp-reply-ai-draft'));
    expect(mark, findsNothing);

    await tester.tap(find.byKey(const ValueKey('gbp-reply-draft')));
    await tester.pumpAndSettle();
    expect(mark, findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Public reply'),
      'Hi Dana, thank you. We are sorry about the delay.',
    );
    await tester.pumpAndSettle();
    expect(mark, findsNothing);
  });

  /// The card's status is set from the board, one change per request.
  testWidgets('a card status is set from the review', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(opsAvailable: true, manageGranted: true, requests: requests));
    await _board(tester);

    await tester.tap(find.text('Status').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Done').last);
    await tester.pumpAndSettle();

    final mark = requests.singleWhere((r) => r.url.path.endsWith('/a1leads-gbp/cards/mark'));
    expect(jsonDecode(mark.body), {'ref': 'accounts/a/locations/l/reviews/r-1', 'status': 'Done'});
  });

  /// A report is queued only once somebody confirms a policy reason.
  testWidgets('a report needs a reason and a confirmation', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(opsAvailable: true, manageGranted: true, requests: requests));
    await _board(tester);

    await tester.tap(find.text('Report to Google').first);
    await tester.pumpAndSettle();
    final confirm = find.byKey(const ValueKey('gbp-report-confirm'));
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull, reason: 'not before confirming');

    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await tester.tap(confirm);
    await tester.pumpAndSettle();

    final report = requests.singleWhere((r) => r.url.path.endsWith('/a1leads-gbp/report'));
    expect(jsonDecode(report.body), {'reviewIds': [1], 'reason': 'fake_or_deceptive', 'confirmed': true});
  });

  /// A suggested Workiz job is picked; nobody types a job number.
  testWidgets('a customer match is picked from the suggestions', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(opsAvailable: true, manageGranted: true, requests: requests));
    await _board(tester);

    await tester.tap(find.text('Customer match').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dana M. Whitfield').last);
    await tester.pumpAndSettle();

    final match = requests.singleWhere((r) => r.url.path.endsWith('/match'));
    expect(match.url.path, endsWith('/a1leads-gbp/reviews/1/match'));
    expect(jsonDecode(match.body), {'tenant': 'NJ', 'jobId': '7001'});
  });

  /// No actions without the manage permission, or before the routes are connected.
  testWidgets('the card actions need the manage permission and the connection', (tester) async {
    await _pump(tester, _handler(opsAvailable: true));
    await _board(tester);
    expect(find.text('Report to Google'), findsNothing, reason: 'no manage permission');
    expect(find.text('Dana Whitfield'), findsOneWidget, reason: 'the control: the board rendered');
  });

  testWidgets('the card actions wait for the review-ops connection', (tester) async {
    await _pump(tester, _handler(manageGranted: true));
    await _board(tester);
    expect(find.text('Report to Google'), findsNothing);
    expect(find.text('Dana Whitfield'), findsOneWidget);
  });

  /// Read from the live copy, the line says so - not "snapshot captured".
  testWidgets('the live copy is labelled live', (tester) async {
    await _pump(tester, _handler(live: true));
    expect(find.textContaining('Live from A1 Leads'), findsOneWidget);
    expect(find.textContaining('Snapshot captured'), findsNothing);
  });

  /// Clicking a review opens the right-hand panel - Jose, 2026-09-28, as in
  /// A1 Tools - with the review whole and our reply beneath it.
  testWidgets('clicking a review opens the panel with its history', (tester) async {
    await _pump(tester, _handler(total: 2));
    expect(find.byKey(const ValueKey('gbp-panel')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('gbp-row-accounts/a/locations/l/reviews/r-2')));
    await tester.pumpAndSettle();

    final panel = find.byKey(const ValueKey('gbp-panel'));
    expect(panel, findsOneWidget);
    expect(find.descendant(of: panel, matching: find.text('Our reply')), findsOneWidget);
    expect(find.descendant(of: panel, matching: find.text('Glad we could help, Marcus.')), findsOneWidget);
    expect(find.descendant(of: panel, matching: find.text('REVIEW DETAILS')), findsOneWidget);

    await tester.tap(find.descendant(of: panel, matching: find.byTooltip('Close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gbp-panel')), findsNothing);
  });

  /// The table's status pill changes the card's status, as A1 Tools' does,
  /// through the app's search picker rather than a popup menu.
  testWidgets('the table status pill changes the status', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, _handler(opsAvailable: true, manageGranted: true, requests: requests));

    await tester.tap(find.byKey(const ValueKey('gbp-status-pill-accounts/a/locations/l/reviews/r-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Done').last);
    await tester.pumpAndSettle();

    final mark = requests.singleWhere((r) => r.url.path.endsWith('/a1leads-gbp/cards/mark'));
    expect(jsonDecode(mark.body), {'ref': 'accounts/a/locations/l/reviews/r-1', 'status': 'Done'});
    expect(find.byKey(const ValueKey('gbp-panel')), findsNothing, reason: 'the pill changes status; it does not open the panel');
  });

  testWidgets('without the manage permission the status pill is only a label', (tester) async {
    await _pump(tester, _handler(opsAvailable: true));
    expect(find.byKey(const ValueKey('gbp-status-pill-accounts/a/locations/l/reviews/r-1')), findsNothing);
    expect(find.text('Working on it'), findsOneWidget, reason: 'the control: the status is still shown');
  });

  /// The panel fits a phone: it takes the whole width there.
  testWidgets('the panel fits at phone width', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) => complaints.add(details.exceptionAsString());
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, _handler(total: 2, withCustomer: true, opsAvailable: true, manageGranted: true, replyAvailable: true, replyGranted: true),
        size: const Size(375, 900));
    // The name, not the card's centre: the centre of a card with actions on
    // it is a button.
    await tester.tap(find.descendant(
      of: find.byKey(const ValueKey('gbp-card-accounts/a/locations/l/reviews/r-1')),
      matching: find.text('Dana Whitfield'),
    ));
    await tester.pumpAndSettle();
    FlutterError.onError = previous;

    expect(find.byKey(const ValueKey('gbp-panel')), findsOneWidget);
    expect(complaints, isEmpty);
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

  /// The TABLE is the default - Jose, 2026-09-24: "table view should be the
  /// default". Asserted with the board's own marker absent, so a screen that
  /// drew both would not pass.
  testWidgets('it opens on the table, not the board', (tester) async {
    await _pump(tester, _handler(total: 2));

    expect(find.text('CUSTOMER'), findsOneWidget);
    expect(find.text('Replied'), findsOneWidget, reason: "the table's reply state");
    expect(find.text('Replied on Google'), findsNothing, reason: "the board's is not drawn");
    expect(find.text('Board'), findsOneWidget, reason: 'the toggle is still offered');
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
    await _board(tester);

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

  /// The table's Reply opens the editor UNDER THAT ROW and stays in the
  /// table. It used to jump to the board; Jose, 2026-09-24: "that is not good
  /// UI".
  testWidgets('reply from the table opens the editor in the table', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) => complaints.add(details.exceptionAsString());
    addTearDown(() => FlutterError.onError = previous);

    // The narrowest width the table is offered at.
    await _pump(tester, _handler(total: 3, replyAvailable: true, replyGranted: true),
        size: const Size(900, 1400));
    expect(find.text('CUSTOMER'), findsOneWidget, reason: 'the table is open');

    await tester.tap(find.widgetWithText(TextButton, 'Reply').first);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('gbp-reply-editor')), findsOneWidget);
    expect(find.text('Live Google review'), findsOneWidget);
    expect(find.text('CUSTOMER'), findsOneWidget, reason: 'still the table, not the board');
    expect(find.text('Reply on Google'), findsNothing, reason: "the board's button is not drawn");
    expect(complaints, isEmpty, reason: 'the editor fits under the row');
  });

  /// HALF A DESKTOP SCREEN - Jose, 2026-09-28: the table did not fit at about
  /// 960 wide. The window is 960 but the page is not: the shell's sidebar
  /// (220 by default) takes its share, and the table is chosen from the
  /// WINDOW's width. So the page is pumped beside a sidebar, with every column
  /// filled - contact, the tappable status pill, the Reply button.
  ///
  /// Measured in a browser with real fonts first: no RenderFlex overflowed.
  /// What did not fit was the columns - every one a share of 700px, so the
  /// status pill read "Awaitin…" and the date ran into the rating. So beside
  /// "no overflow error" this asserts the columns keep their widths, which
  /// the squeezed table fails whatever font draws it.
  testWidgets('the table fits half a desktop screen beside the sidebar', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) => complaints.add(details.exceptionAsString());
    addTearDown(() => FlutterError.onError = previous);

    await _pump(
      tester,
      _handler(
        total: 3,
        withPhone: true,
        withCustomer: true,
        opsAvailable: true,
        manageGranted: true,
        replyAvailable: true,
        replyGranted: true,
      ),
      size: const Size(960, 900),
      sidebar: 220,
    );
    FlutterError.onError = previous;

    // The control: the table really was drawn, with its rows.
    expect(find.text('CUSTOMER'), findsOneWidget);
    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(complaints.where((c) => c.contains('overflowed')), isEmpty);

    // A column's width, read from where its heading and the next one start.
    double column(String heading, String next) =>
        tester.getTopLeft(find.text(next)).dx - tester.getTopLeft(find.text(heading)).dx;

    expect(column('STATUS', 'NOTES'), greaterThanOrEqualTo(140),
        reason: 'room for "Awaiting report" and its arrow, not "Awaitin…"');
    expect(column('DATE', 'RATING'), greaterThanOrEqualTo(88),
        reason: 'a date that does not run into the rating');
    expect(column('CONTACT', 'DATE'), greaterThanOrEqualTo(140), reason: 'a whole phone number');
    // And the squeeze is not merely moved onto the prose columns.
    expect(column('CUSTOMER', 'REVIEW'), greaterThanOrEqualTo(150));
    expect(column('REVIEW', 'CONTACT'), greaterThanOrEqualTo(280));
  });

  /// SORTING - a header sorts the loaded page by it, and a second click
  /// reverses it. The default is the server's newest first.
  testWidgets('a column header sorts the table, and again reverses it', (tester) async {
    await _pump(tester, _handler(total: 3, withCustomer: true));

    // The three reviewers, top to bottom as drawn.
    double top(String name) => tester.getTopLeft(find.text(name)).dy;
    List<String> order() =>
        ['Dana Whitfield', 'Marcus Reed', 'Kevin Ha']..sort((a, b) => top(a).compareTo(top(b)));

    // Newest first, as the server sent it.
    expect(order(), ['Dana Whitfield', 'Marcus Reed', 'Kevin Ha']);

    await tester.tap(find.byKey(const ValueKey('gbp-sort-rating')));
    await tester.pumpAndSettle();
    expect(order(), ['Marcus Reed', 'Dana Whitfield', 'Kevin Ha'], reason: 'highest first');

    await tester.tap(find.byKey(const ValueKey('gbp-sort-rating')));
    await tester.pumpAndSettle();
    expect(order(), ['Kevin Ha', 'Dana Whitfield', 'Marcus Reed'], reason: 'reversed');

    await tester.tap(find.byKey(const ValueKey('gbp-sort-name')));
    await tester.pumpAndSettle();
    expect(order(), ['Dana Whitfield', 'Kevin Ha', 'Marcus Reed'], reason: 'A to Z');

    await tester.tap(find.byKey(const ValueKey('gbp-sort-date')));
    await tester.pumpAndSettle();
    expect(order(), ['Dana Whitfield', 'Marcus Reed', 'Kevin Ha'], reason: 'newest first');

    await tester.tap(find.byKey(const ValueKey('gbp-sort-date')));
    await tester.pumpAndSettle();
    expect(order(), ['Kevin Ha', 'Marcus Reed', 'Dana Whitfield'], reason: 'oldest first');

    // Unreplied first: the ones that still need doing.
    await tester.tap(find.byKey(const ValueKey('gbp-sort-reply')));
    await tester.pumpAndSettle();
    expect(order().last, 'Marcus Reed', reason: 'the only replied review goes last');

    // Cards with a status before the ones with none, whichever way round.
    await tester.tap(find.byKey(const ValueKey('gbp-sort-status')));
    await tester.pumpAndSettle();
    expect(order().first, 'Dana Whitfield');
    await tester.tap(find.byKey(const ValueKey('gbp-sort-status')));
    await tester.pumpAndSettle();
    expect(order().first, 'Dana Whitfield', reason: 'no status is last in both directions');

    // By place: Morton Grove, IL before Mt Laurel Township, NJ; no place last.
    await tester.tap(find.byKey(const ValueKey('gbp-sort-location')));
    await tester.pumpAndSettle();
    expect(order(), ['Marcus Reed', 'Dana Whitfield', 'Kevin Ha']);
  });

  /// CONTACT, as A1 Tools shows it: the matched customer's phone when the
  /// card's own contact is empty - which it is on every card A1 Leads sends.
  testWidgets("the contact column shows the matched customer's phone", (tester) async {
    await _pump(tester, _handler(total: 3, withCustomer: true));

    expect(find.text('CONTACT'), findsOneWidget);
    expect(find.text('(555) 555-0101'), findsOneWidget, reason: 'from the matched customer');
    expect(find.textContaining('does not send customer phone or email'), findsNothing);
  });


  /// Rodrigo, 2 October: a matched customer shows whether or not the review
  /// has a card, with how it was matched and whether a team or a person did
  /// the job.
  testWidgets('a matched customer with no card is shown in full', (tester) async {
    await _pump(tester, _handler(total: 3, liveCustomer: true));

    expect(find.text('Matched: Christopher Beard'), findsOneWidget);
    expect(find.text('Workiz OH · Job #600024076 · 2026-07-02 08:00:00 · Team: ms chimney'), findsOneWidget,
        reason: 'a team is not written as a technician');
    expect(find.text('(513) 390-5722'), findsOneWidget);
    expect(find.text('christopher@example.com'), findsOneWidget);

    await _board(tester);
    expect(find.textContaining('matched by hand'), findsOneWidget);
    expect(find.text('Open in Workiz'), findsOneWidget);
  });

  /// Rodrigo, 6 October: "make the contacts in the contact column in a1leads
  /// gbp format friendlier '(123) 456-6789'".
  test('a US phone in any shape A1 Leads sends reads (513) 390-5722', () {
    for (final shape in ['5133905722', '15133905722', '+15133905722']) {
      expect(gbpContactText(shape), '(513) 390-5722', reason: shape);
    }
    // Not a US number, or not a number: shown as it came.
    for (final other in ['+442071838750', '+2035550142', '0123456789', '555-0101', 'christopher@example.com']) {
      expect(gbpContactText(other), other, reason: other);
    }
  });

  /// Nothing matching the filters is a different sentence from nothing at all.
  testWidgets('an empty page blames the filters, not the business', (tester) async {
    await _pump(tester, _handler(total: 0));

    expect(find.textContaining('Nothing matches these filters'), findsOneWidget);
    expect(find.textContaining('has no reviews'), findsNothing);
  });
}

/// Switch to the board, where a review is read whole. The table opens first.
Future<void> _board(WidgetTester tester) async {
  await tester.tap(find.text('Board'));
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
  double sidebar = 0,
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
      // `sidebar` stands in for the shell's navigation beside the page.
      home: Scaffold(
        body: sidebar == 0
            ? A1LeadsGbpScreen(api: api, session: session)
            : Row(children: [
                SizedBox(width: sidebar),
                Expanded(child: A1LeadsGbpScreen(api: api, session: session)),
              ]),
      ),
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
  bool withCustomer = false,
  bool liveCustomer = false,
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

  if (withCustomer) {
    (all[0]['board'] as Map)
      ..['customer'] = {
        'name': 'Dana M. Whitfield', 'phone': '+15555550101', 'address': '12 Elm St, Warren, MI',
        'technician': 'Amit', 'job_number': '100077846', 'job_date': '2026-09-01',
        'workiz_url': 'https://app.workiz.com/job/ABC123',
      }
      ..['assignee'] = 'Adeline'
      ..['updates'] = [
        {'author': 'Tiara', 'body': 'Called, left voicemail.', 'at': '2026-09-22T14:00:00Z'},
      ];
  }

  // The live copy's shape: the matched customer on the review itself, with no
  // card at all (measured 5 October, seq 13179).
  if (liveCustomer) {
    all[2]['customer'] = {
      'name': 'Christopher Beard', 'basis': 'operator_selected', 'manual': true,
      'workiz_account': 'OH', 'client_id': '6290', 'job_number': '600024076',
      'job_date': '2026-07-02 08:00:00', 'technician': 'ms chimney', 'assignee_kind': 'team',
      'phones': ['5133905722'], 'email': 'christopher@example.com', 'address': '920 Winsray Ct, Cincinnati, Ohio',
      'workiz_url': 'https://app.workiz.com/root/client/6290',
    };
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
  bool withCustomer = false,
  bool liveCustomer = false,
  bool opsAvailable = false,
  bool manageGranted = false,
  bool live = false,
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
            // Replying is its own permission (Jose, 2026-09-25).
            if (replyGranted) 'a1leads_gbp.reply',
            if (manageGranted) 'a1leads_gbp.manage',
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
      'live': live,
      'because': configured
          ? (reachable ? null : 'A1 Leads refused reviews with HTTP 403.')
          : 'No A1 Leads key is configured for this environment.',
    };

    // The live preflight: the review as Google has it NOW.
    if (request.url.path.endsWith('/draft') && request.method == 'POST') {
      return _json({'data': {'text': 'Hi Dana, thank you for telling us about the delay.'}});
    }
    if (request.url.path.endsWith('/a1leads-gbp/cards/mark') ||
        request.url.path.endsWith('/a1leads-gbp/report') ||
        request.url.path.endsWith('/match')) {
      return _json({'data': {'ok': true}});
    }
    if (request.url.path.endsWith('/a1leads-gbp/report/status')) {
      return _json({'data': {'reasons': ['fake_or_deceptive', 'conflict_of_interest']}});
    }
    if (request.url.path.endsWith('/suggestions')) {
      // The live shape, measured 2026-09-25: the jobs sit under `data.jobs`.
      return _json({
        'data': {
          'tenant': 'NJ',
          'tenants': ['NJ', 'TX'],
          'localOnly': true,
          'currentMatch': <String, dynamic>{},
          'jobs': [
            {'tenant': 'NJ', 'jobId': '7001', 'jobNumber': 100077846, 'name': 'Dana M. Whitfield', 'date': '2026-09-01', 'team': ['Amit']},
          ],
        },
      });
    }
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
          'reviews': usable ? _reviews(total, offset, pageSize, withPhone: withPhone, boardDown: boardDown, withCustomer: withCustomer, liveCustomer: liveCustomer) : <dynamic>[],
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
          'ops_available': opsAvailable,
          'summary': usable ? {'total': 13169} : null,
          'reviews': usable ? _reviews(total, 0, pageSize, withPhone: withPhone, boardDown: boardDown, withCustomer: withCustomer, liveCustomer: liveCustomer) : <dynamic>[],
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
