import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customer Experience — the container, and only the container.
///
/// ── What this file is about ──────────────────────────────────────
///
/// Not Reviews and not Business Profile: each has its own screen and its own
/// tests. This is about the one thing the container decides, which is WHICH
/// CHIPS APPEAR — and it decides that entirely from `/me`, never from a list in
/// its own source.
///
/// That is the property worth pinning. A container that hard-coded its two
/// chips would look identical on screen today and would keep drawing Reviews
/// for a role that had lost it, or miss a third module moved in later. So the
/// assertions below vary `/me` and never the widget.
///
/// The real `/me` is held to the real registry by `CustomerExperienceModuleTest` on the
/// server. A fixture here would otherwise only prove it agrees with itself.
void main() {
  reviewLinkTests();
  deepLinkTests();
  testWidgets('both children are drawn, in the order the server sent them', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'GBP Listings'), findsOneWidget);
    // Jose, 2026-10-20: "Reviews - GBP Listings - GBP Performance - Review
    // Links", Business Profile as two chips.
    final labels = [
      for (final chip in tester.widgetList<ChoiceChip>(find.byType(ChoiceChip)))
        if (chip.label case final Text t) t.data,
    ].whereType<String>().toList();
    expect(
      labels,
      containsAllInOrder([
        'Reviews',
        'GBP Listings',
        'GBP Performance',
        'Review links',
      ]),
    );
  });

  /*
   * ONE GRANT, ONE CHIP — asserted in both directions on purpose.
   *
   * A test that only checked "without reviews.view there is no Reviews chip"
   * would pass for a container that had stopped drawing Reviews entirely. So
   * each case names the chip that must be ABSENT and the chip that must still
   * be THERE.
   *
   * Varying `reachable` rather than `permissions`: the loop reads
   * `me.reachableModules`, which is the flag the server computed from the
   * grant. A version of this that varied the permission list moved nothing on
   * screen and passed either way.
   */
  testWidgets('a role with only Reviews gets Reviews and nothing else', (
    tester,
  ) async {
    await _pump(tester, _handler(reachable: const ['reviews']));

    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'GBP Listings'), findsNothing);
  });

  testWidgets('a role with only Business Profile gets that one', (
    tester,
  ) async {
    await _pump(tester, _handler(reachable: const ['business_profile']));

    expect(find.widgetWithText(ChoiceChip, 'GBP Listings'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsNothing);
  });

  /// The state an administrator can create with one tick.
  ///
  /// `customer_experience.view` and neither child is a real grant, and the screen is
  /// reachable in it. An empty frame there reads as the app being broken, which
  /// is the one conclusion that sends somebody to the wrong person for help —
  /// so it says so, and names the two things to ask for.
  testWidgets('holding the container and neither child says so, and says what '
      'to ask for', (tester) async {
    await _pump(tester, _handler(reachable: const <String>[]));

    expect(
      find.textContaining('Nothing inside Customer Experience is available'),
      findsOneWidget,
    );
    expect(
      find.textContaining('granted separately'),
      findsOneWidget,
      reason:
          'an empty state that does not name what to ask for cannot be acted on',
    );
    expect(find.byType(ChoiceChip), findsNothing);
  });

  /// A module the server puts somewhere else is not drawn here.
  ///
  /// The negative control for the loop itself: with a Leads module present and
  /// parented to `marketing`, a container that drew every reachable module —
  /// or every module with a screen — would show a third chip. Leads is the
  /// right specimen because it used to be drawn inside a container and is not
  /// any more.
  testWidgets('a module parented elsewhere is left where it belongs', (
    tester,
  ) async {
    await _pump(tester, _handler(withLeads: true));

    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsOneWidget);
    expect(
      find.widgetWithText(ChoiceChip, 'Leads'),
      findsNothing,
      reason:
          'the strip is the children of customer_experience, not everything reachable',
    );
  });

  /// Selecting by NAME rather than index.
  ///
  /// Tapping the second chip must open the second chip's pane whatever the
  /// first one is. With Reviews absent, "index 1" is not Business Profile any
  /// more, and a screen remembering a position rather than a name lands on the
  /// wrong pane — or past the end of the list.
  testWidgets('the remembered chip survives the list changing under it', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await tester.tap(find.widgetWithText(ChoiceChip, 'GBP Listings'));
    await tester.pumpAndSettle();

    final chip = tester.widget<ChoiceChip>(
      find.widgetWithText(ChoiceChip, 'GBP Listings'),
    );

    expect(chip.selected, isTrue);
  });
}

/// Review links (Jose, 2026-10-15): a chip of the container's own, on
/// Reviews' grant, drawing the funnel and the table from one endpoint.
/// A tapped notification about a review (Notifications 6650e3cf): the shell
/// lands on Customer Experience, which brings Reviews forward, and Reviews
/// opens that review.
void deepLinkTests() {
  testWidgets('a review link brings Reviews forward and opens the review', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await tester.tap(find.widgetWithText(ChoiceChip, 'GBP Listings'));
    await tester.pumpAndSettle();
    asked.clear();

    DeepLinks.open(
      const DeepLink(module: 'reviews', type: 'review', id: 'rv-42'),
    );
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Reviews'))
          .selected,
      isTrue,
    );
    expect(asked, contains(endsWith('/reviews/rv-42')));
    expect(
      DeepLinks.pending.value,
      isNull,
      reason: 'taken, so it does not fire twice',
    );
  });
}

void reviewLinkTests() {
  testWidgets('review links ride on the Reviews grant', (tester) async {
    await _pump(tester, _handler(reachable: const ['business_profile']));
    expect(find.widgetWithText(ChoiceChip, 'Review links'), findsNothing);

    await _pump(tester, _handler(reachable: const ['reviews']));
    expect(find.widgetWithText(ChoiceChip, 'Review links'), findsOneWidget);
  });

  testWidgets('the funnel shows each step and its share of the one before', (
    tester,
  ) async {
    await _pump(tester, _handler(reachable: const ['reviews']));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Review links'));
    await tester.pumpAndSettle();

    expect(find.text('20'), findsWidgets);
    expect(find.text('10 · 50%'), findsWidgets, reason: 'opened of sent');
    expect(find.text('8 · 80%'), findsOneWidget, reason: 'green of opened');
    expect(find.text('6 · 75%'), findsOneWidget, reason: 'google of green');
    // Jose, 2026-11-09: the fifth step, matched reviews.
    expect(find.text('3 · 50%'), findsOneWidget, reason: 'reviewed of google');
    expect(
      find.textContaining(
        'exact 1 · likely 1 · confirmed 1 · without a click 1 · 4.7 ★',
      ),
      findsOneWidget,
    );
    expect(
      find.text('+4 possible matches, confirm in Reviews'),
      findsOneWidget,
    );
    expect(find.textContaining('counted since'), findsOneWidget);

    // Siblings are one size (Jose: "you always make that mistake").
    final sizes = {
      for (final k in [
        'sent',
        'opened',
        'happy',
        'sad',
        'google',
        'submitted',
        'reviewed',
      ])
        tester.getSize(find.byKey(Key('review-links-tile-$k'))),
    };
    expect(sizes.length, 1);
  });

  testWidgets('on a phone: two columns of tiles and a list, nothing overflows', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    try {
      await _pump(tester, _handler(reachable: const ['reviews']));
      tester.view.physicalSize = const Size(390, 844);
      await tester.pumpAndSettle();
      // The chip row scrolls on a phone; Auto-replies pushed this one past the edge.
      await tester.ensureVisible(
        find.widgetWithText(ChoiceChip, 'Review links'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Review links'));
      await tester.pumpAndSettle();

      // Stacked cards on a phone, never a sideways DataTable (P1).
      expect(find.byType(DataTable), findsNothing);
      // Seven tiles push the list below the fold on a phone (2026-11-09).
      await tester.scrollUntilVisible(
        find.text('North Branch'),
        200,
        scrollable: find
            .ancestor(
              of: find.byKey(const Key('review-links-reviewed-detail')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      expect(find.text('North Branch'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('review-links-tile-sent')),
        -200,
        scrollable: find
            .ancestor(
              of: find.byKey(const Key('review-links-reviewed-detail')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      final sent = tester.getTopLeft(
        find.byKey(const Key('review-links-tile-sent')),
      );
      final opened = tester.getTopLeft(
        find.byKey(const Key('review-links-tile-opened')),
      );
      expect(sent.dy, opened.dy, reason: 'two tiles per row');
    } finally {
      // Restored before any failure, so the real one is reported (2026-11-09).
      FlutterError.onError = previous;
    }
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });

  testWidgets('review links open on today', (tester) async {
    await _pump(tester, _handler(reachable: const ['reviews']));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Review links'));
    await tester.pumpAndSettle();

    final today = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('report-dates-Today')),
    );
    expect(today.selected, isTrue);
  });

  testWidgets('the table switches between branch and person', (tester) async {
    await _pump(tester, _handler(reachable: const ['reviews']));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Review links'));
    await tester.pumpAndSettle();

    expect(find.text('North Branch'), findsOneWidget);
    expect(find.text('Total'), findsOneWidget);
    await tester.tap(find.text('Person'));
    await tester.pumpAndSettle();
    final table = find.byKey(const Key('review-links-table'));
    expect(
      find.descendant(of: table, matching: find.text('Rita Tech')),
      findsOneWidget,
    );
    expect(find.text('North Branch'), findsNothing);
  });

  // Jose 2026-11-23 (design 204): "which technician got the most reviews".
  testWidgets('the leaderboard ranks people by reviews written', (
    tester,
  ) async {
    await _pump(tester, _handler(reachable: const ['reviews']));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Review links'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('review-links-leaderboard')), findsOneWidget);
    // Omar wrote 3 reviews to Rita's 2: first, though Rita sent more texts.
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('review-links-rank-0')))
          .data,
      'Omar Lead',
    );
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('review-links-rank-1')))
          .data,
      'Rita Tech',
    );
    // Nothing earned, not ranked.
    expect(find.byKey(const ValueKey('review-links-rank-2')), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CustomerExperienceScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The server. `reachable` null means both children are reachable.
final asked = <String>[];

MockClientHandler _handler({List<String>? reachable, bool withLeads = false}) {
  bool reaches(String key) => reachable == null || reachable.contains(key);

  return (request) async {
    asked.add(request.url.path);
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Jose Silva',
          'email': 'jose@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Administrator'},
          'permissions': const ['customer_experience.view'],
          'modules': [
            {
              'key': 'customer_experience',
              'label': 'Customer Experience',
              'available': true,
              'reachable': true,
            },
            {
              'key': 'reviews',
              'label': 'Reviews',
              'parent': 'customer_experience',
              'available': true,
              'reachable': reaches('reviews'),
            },
            {
              'key': 'business_profile',
              'label': 'Business Profile',
              'parent': 'customer_experience',
              'available': true,
              'reachable': reaches('business_profile'),
            },
            if (withLeads)
              {
                'key': 'leads',
                'label': 'Leads',
                'parent': 'marketing',
                'available': true,
                'reachable': true,
              },
          ],
          'preferences': <String, dynamic>{},
          // Required by Me.fromJson, and only the shape matters here: this
          // screen reads `modules` and nothing else off /me.
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

    if (request.url.path.endsWith('/customer-experience/review-links')) {
      return _json({
        'data': {
          'totals': {
            'sent': 20,
            'opened': 10,
            'happy': 8,
            'sad': 2,
            'submitted': 1,
            'google': 6,
            'reviewed': 3,
          },
          'reviewed': {
            'by': {
              'exact': 1,
              'likely': 1,
              'confirmed': 1,
              'possible': 4,
              'without_click': 1,
            },
            'stars': 4.67,
          },
          'by_branch': [
            {
              'id': 'f-1',
              'name': 'North Branch',
              'sent': 20,
              'opened': 10,
              'happy': 8,
              'sad': 2,
              'submitted': 1,
              'google': 6,
            },
          ],
          'by_person': [
            {
              'id': 'u-9',
              'name': 'Rita Tech',
              'sent': 20,
              'opened': 10,
              'happy': 8,
              'sad': 2,
              'submitted': 1,
              'google': 6,
              'reviewed': 2,
              'qr': 3,
            },
            {
              'id': 'u-10',
              'name': 'Omar Lead',
              'sent': 5,
              'opened': 4,
              'happy': 9,
              'sad': 0,
              'submitted': 0,
              'google': 4,
              'reviewed': 3,
              'qr': 0,
            },
            {
              'id': 'u-11',
              'name': 'Quiet Person',
              'sent': 3,
              'opened': 0,
              'happy': 0,
              'sad': 0,
              'submitted': 0,
              'google': 0,
              'reviewed': 0,
              'qr': 0,
            },
          ],
          'tracked_since': '2026-10-15T14:00:00Z',
        },
      });
    }

    // Reviews and Business Profile fetch on open. They have their own suites;
    // here they only have to render without throwing, so an empty page is the
    // right answer rather than a fixture that would go stale beside theirs.
    return _json({
      'data': <dynamic>[],
      'meta': {'total': 0, 'connections': <dynamic>[]},
    });
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
