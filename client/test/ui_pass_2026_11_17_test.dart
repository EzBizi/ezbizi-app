import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/financials/financial_home.dart';
import 'package:ezbizi_client/screens/jobs/estimate_board.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_dashboard.dart';
import 'package:ezbizi_client/screens/phone/call_history_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI pass 2026-11-17 (docs/reviews/ui-pass-2026-11-17.md): one test per
/// clear-cut fix, each failing on the code before it.
void main() {
  Me me() => Me.fromJson({
    'id': 'u1',
    'name': 'Jose',
    'email': 'jose@example.test',
    'organization': {'id': 'o1', 'name': 'A-1'},
  });

  // Jobs › Estimates › Board: "George And Stacy Jackson$0" — a name that
  // fills its column ran straight into the amount.
  testWidgets('an estimate card keeps a gap between the name and the amount', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 180,
              child: EstimateCard(
                row: const {
                  'id': 'e1',
                  'customer': {'name': 'George And Stacy Jackson Longname'},
                  'total_minor': 0,
                  'currency': 'USD',
                },
                me: me(),
                onTap: () {},
              ),
            ),
          ),
        ),
      ),
    );

    final name = find.text('George And Stacy Jackson Longname');
    final total = find.byKey(const ValueKey('estimate-card-total-e1'));
    expect(name, findsOneWidget);
    expect(
      tester.getTopLeft(total).dx - tester.getTopRight(name).dx,
      greaterThanOrEqualTo(8),
    );
  });

  // Communication › Phone: beside the dial pad the recent-calls column is
  // ~290 px on a computer. A tag column and the clock on one line left the
  // name ~70 px ("Rach…", "+1 (77…"). The tag now goes under the time
  // whenever the COLUMN is narrow, not only on a phone screen.
  testWidgets('recent calls keep room for the name in a narrow column', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'c-1',
                'number_id': 'n-1',
                'connection_id': 'pc-1',
                'direction': 'inbound',
                'from_e164': '+12035550199',
                'to_e164': '+12035550100',
                'status': 'completed',
                'duration_seconds': 60,
                'voicemail': false,
                'customer_name': 'Rachel',
                'customer_id': 'cu-1',
                'created_at': '2026-10-20T09:14:00+00:00',
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Align(
            alignment: Alignment.topLeft,
            child: SizedBox(
              width: 290,
              height: 600,
              child: CallHistoryPane(
                api: api,
                formats: DisplayFormats.fallback,
                onPick: (_) {},
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final tile = find.byKey(const ValueKey('call-c-1'));
    expect(tile, findsOneWidget);
    // The title slot: everything left of the trailing time and tag.
    final title = find.descendant(of: tile, matching: find.text('Rachel'));
    expect(title, findsOneWidget);
    expect(tester.getSize(title).width, greaterThanOrEqualTo(80));
    // Customer tag sits under the time, not beside it.
    final tag = find.descendant(of: tile, matching: find.text('Customer'));
    expect(tag, findsOneWidget);
    expect(tester.getTopLeft(tag).dy, greaterThan(tester.getTopLeft(title).dy));
  });

  // Marketing › Newsletter: Failed became a fourth filter tile, the width was
  // still counted for three, and it sat alone on a second row (Jose's
  // equal-tile rule). All six tiles share one row at a computer's width.
  testWidgets('the newsletter tiles share one row', (tester) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final session = Session(
      ApiClient(client: MockClient((_) async => http.Response('{}', 404))),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: NewsletterDashboard(
            stats: const NewsletterStats(sent: 1, failed: 1),
            rows: const [],
            scheduled: const [],
            viewName: 'A-1',
            session: session,
            status: null,
            onStatus: (_) {},
            onOpen: null,
            onNew: () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final keys = [
      const Key('newsletters-tile-sent'),
      const Key('newsletters-tile-failed'),
      const Key('newsletters-fact-reached'),
      const Key('newsletters-fact-last'),
    ];
    // Newsletter P1c: the tiles became one compact strip of filter chips and
    // two facts, which still share one line at a computer's width.
    final centres = {for (final k in keys) tester.getCenter(find.byKey(k)).dy.round()};
    expect(centres, hasLength(1), reason: 'every filter and fact on one line');
  });

  // Financials › Home: a card's figure is a sentence and was cut to one line
  // on every card ("This week: $0.00 refunded · 0 …"). It now has two.
  testWidgets('a financial card gives its figure two lines', (tester) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: FinancialHome(
            areas: const [
              FinancialCard(
                id: 'disputes',
                name: 'Disputes',
                figure: 'This week: \$0.00 refunded · 0 disputes opened',
              ),
            ],
            bases: const [],
            onArea: (_) {},
            onBase: (_) {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final figure = find.byKey(const ValueKey('financial-card-figure-disputes'));
    expect(figure, findsOneWidget);
    expect(tester.getSize(figure).height, greaterThanOrEqualTo(2 * 14));
    expect(tester.takeException(), isNull);
  });
}
