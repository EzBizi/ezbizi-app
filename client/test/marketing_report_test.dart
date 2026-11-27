import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/marketing_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Print (Merav, 2026-11-04): disabled, not hidden, with no rows; with rows
  // it hands over a PDF.
  testWidgets('Print is disabled with no rows and prints a PDF with them', (
    tester,
  ) async {
    var rows = <Map<String, Object?>>[];
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': {'rows': rows},
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    final printed = <List<int>>[];
    printPdf = (pdf, _) async => printed.add(pdf);
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    Future<void> open() async {
      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: MarketingReport(api: api, now: () => DateTime(2026, 10, 14)),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    await open();
    final button = find.byKey(const Key('marketing-print'));
    expect(tester.widget<ButtonStyleButton>(button).onPressed, isNull);

    rows = [
      {'branch': 'Connecticut', 'source': 'LS Ads', 'cost_minor': 3000000},
    ];
    await open();
    await tester.tap(button);
    await tester.runAsync(() => Future.delayed(const Duration(seconds: 2)));
    await tester.pump();
    expect(printed, hasLength(1));
    expect(ascii.decode(printed.single.take(4).toList()), '%PDF');
  });

  testWidgets('asks for last week and shows cost, jobs and profit per source', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url.queryParameters);
        return http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {
                  'branch': 'Connecticut',
                  'source': 'LS Ads',
                  'clicks': 40,
                  'conversions': 5,
                  'cost_minor': 3000000,
                  'leads': 10,
                  'leads_to_jobs': 20.0,
                  'lead_jobs': 2,
                  'jobs_created': 2,
                  'cost_per_job_minor': 1500000,
                  'done_jobs': 1,
                  'gross_minor': 100000,
                  'profit_minor': 50000,
                  'net_profit_minor': -2950000,
                },
                {
                  'branch': 'Connecticut',
                  'source': 'GMB /SEO',
                  'cost_minor': null,
                  'jobs_created': 1,
                  'done_jobs': 1,
                  'gross_minor': 50000,
                  'profit_minor': null,
                  'net_profit_minor': null,
                  'profit_missing':
                      'the CT table has no week 12 Oct - 18 Oct yet',
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MarketingReport(
            api: api,
            now: () => DateTime(2026, 10, 14), // a Wednesday
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked.single, {'from': '2026-10-05', 'to': '2026-10-11'});
    // One table (UI queue 87): money as every tab writes it, a Total row
    // under the rows, and the figures above.
    final table = find.byKey(const Key('marketing-table'));
    Finder inTable(String text) =>
        find.descendant(of: table, matching: find.text(text));
    expect(inTable(r'$30,000.00'), findsNWidgets(2), reason: 'row and Total');
    expect(inTable(r'$15,000.00'), findsOneWidget);
    // Leads → jobs on the Total uses the row's formula (review item 15).
    expect(inTable('20.0%'), findsNWidgets(2), reason: 'row and Total');
    expect(inTable('−\$29,500.00'), findsNWidgets(2));
    // Total: $30,000 over 3 jobs, the one known profit.
    expect(inTable('Total'), findsOneWidget);
    expect(inTable(r'$10,000.00'), findsOneWidget);
    // Profit on the first row, gross on the second, and the Total's profit.
    expect(inTable(r'$500.00'), findsNWidgets(3));
    // The figures read the Total.
    expect(find.byKey(const Key('marketing-fact-cost')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('marketing-fact-net')),
        matching: find.text('−\$29,500.00'),
      ),
      findsOneWidget,
    );
    // No ad cost and no P&L: dashes, never $0.
    expect(find.text(r'$0.00'), findsNothing);
    expect(find.text('No P&L'), findsNWidgets(2));

    // The arrow steps a week back; the panel picks any dates.
    await tester.tap(find.byKey(const Key('date-range-previous')));
    await tester.pumpAndSettle();
    expect(asked.last, {'from': '2026-09-28', 'to': '2026-10-04'});

    await tester.tap(find.byKey(const Key('date-range-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('date-preset-last_month')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('date-range-apply')));
    await tester.pumpAndSettle();
    expect(asked.last, {'from': '2026-09-01', 'to': '2026-09-30'});
  });

  // Jose, 2026-11-16: Ace's one account names no branch; its cost is shared
  // out by jobs, so the cell is marked as an estimate and says how.
  testWidgets('a shared-out cost is marked as an estimate and explained', (
    tester,
  ) async {
    const why =
        'Includes 23% of Ace Home Care LLC (no branch in its name): this '
        "branch's 11 of the 47 Ace Fireplace Services jobs created.";
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {
                  'branch': 'Connecticut',
                  'source': 'Ace Fireplace Services',
                  'clicks': 293,
                  'conversions': 20.1,
                  'cost_minor': 317059,
                  'cost_note': why,
                  'jobs_created': 11,
                  'done_jobs': 5,
                  'gross_minor': 1225631,
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MarketingReport(api: api, now: () => DateTime(2026, 11, 16)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final cell = find.descendant(
      of: find.byKey(const Key('marketing-table')),
      matching: find.text(r'≈ $3,170.59'),
    );
    expect(cell, findsOneWidget);
    expect(
      tester
          .widget<Tooltip>(
            find.ancestor(of: cell, matching: find.byType(Tooltip)).first,
          )
          .message,
      why,
    );
  });
}
