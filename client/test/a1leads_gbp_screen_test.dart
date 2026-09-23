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

    expect(find.textContaining('not connected yet'), findsOneWidget);
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
      home: Scaffold(body: A1LeadsGbpScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  bool configured = true,
  bool reachable = true,
  bool stale = false,
}) {
  return (request) async {
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

    if (request.url.path.endsWith('/a1leads-gbp')) {
      return _json({
        'data': {
          'source': {
            'configured': configured,
            'reachable': configured && reachable,
            'captured_at': '2026-09-23T15:01:07.302Z',
            'installed_at': '2026-09-23T15:33:02.864Z',
            'generation': '20260923T150107Z-23510eb0',
            'hours_old': stale ? 41.5 : 2.1,
            'stale': stale,
            'because': configured
                ? (reachable ? null : 'A1 Leads refused reviews with HTTP 403.')
                : 'No A1 Leads key is configured for this environment.',
          },
          'summary': configured && reachable ? {'total': 13169} : null,
          'reviews': configured && reachable
              ? [
                  {
                    'google_review_name': 'accounts/a/locations/l/reviews/r-1',
                    'reviewer_name': 'Dana Whitfield',
                    'rating': 5,
                    'comment': 'They were on time and tidy.',
                    'review_created_at': '2026-09-23T00:43:20.682472Z',
                  },
                ]
              : <dynamic>[],
          'health': null,
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
