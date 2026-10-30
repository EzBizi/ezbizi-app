import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/project_management_reports.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management › Reports and › Table, from Financials' repair week
/// (design 141; origin/session/repair-report d0fa5eb18). Every name invented.
void main() {
  Future<PmServer> open(
    WidgetTester tester,
    String tab, {
    List<String>? permissions,
  }) async {
    final server = permissions == null
        ? PmServer()
        : PmServer(permissions: permissions);
    await server.pump(tester, size: const Size(1400, 1600));
    // One tab since design 144: Repair team, Cards or Table.
    await tester.tap(find.text('Repair team'));
    await tester.pumpAndSettle();
    if (tab == 'Table') {
      await tester.tap(find.byKey(const ValueKey('pm-repair-table')));
      await tester.pumpAndSettle();
    }
    return server;
  }

  testWidgets('Repair team switches between Cards and Table', (tester) async {
    final server = await open(tester, 'Reports');
    expect(find.byKey(const ValueKey('pm-repair-mode-cards')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-repair-mode-table')), findsNothing);
    expect(find.byKey(const ValueKey('pm-reports')), findsOneWidget);
    expect(server.get('/financials/repair-report/table'), isEmpty);

    await tester.tap(find.byKey(const ValueKey('pm-repair-table')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-repair-mode-table')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-table')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-reports')), findsNothing);
    expect(server.get('/financials/repair-report/table'), hasLength(1));
    // The switch is in the frame's filter bar, after the search.
    final bar = find.byKey(const ValueKey('pm-frame-filters'));
    expect(
      find.descendant(
        of: bar,
        matching: find.byKey(const ValueKey('tour-pm-report-mode')),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('pm-repair-cards')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-reports')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  List<List<Object?>> writes(PmServer s) => [
    for (final w in s.writes())
      [w.method, w.uri.path.split('/v1').last, w.body],
  ];

  test('weeks start on Monday', () {
    expect(pmWeekStart(DateTime(2026, 10, 22)), DateTime(2026, 10, 19));
    expect(pmWeekStart(DateTime(2026, 10, 19)), DateTime(2026, 10, 19));
    expect(pmWeekStart(DateTime(2026, 10, 25)), DateTime(2026, 10, 19));
  });

  testWidgets('Reports: last week for the branch in view, the cards, the '
      'draft badge, cost from gross red above the alert', (tester) async {
    final server = await open(tester, 'Reports');

    // Thursday 2026-10-22: the week just finished starts Monday the 12th.
    expect(server.get('/financials/repair-report').last.queryParameters, {
      'franchise_id': 'fr-1',
      'week_start': '2026-10-12',
    });
    expect(server.get('/financials/production-cost').last.queryParameters, {
      'from': '2026-08-01',
      'to': '2026-10-22',
      'franchise_id': 'fr-1',
    });
    expect(
      find.byKey(const ValueKey('pm-report-card-Sam Ortiz')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('pm-report-card-Pat Lin')),
      findsOneWidget,
    );
    expect(find.text('All repair teams combined (2)'), findsOneWidget);
    expect(find.text('Unapproved draft'), findsOneWidget);

    final bad = ToneColours.of(
      tester.element(find.byKey(const ValueKey('pm-reports'))),
      Tone.bad,
    ).ink;
    final high = tester.widget<Text>(
      find.byKey(const ValueKey('pm-report-card-Sam Ortiz-gross')),
    );
    expect((high.data, high.style?.color), ('33.4%', bad));
    final fine = tester.widget<Text>(
      find.byKey(const ValueKey('pm-report-card-Pat Lin-gross')),
    );
    expect(fine.data, '12.5%');
    expect(fine.style?.color, isNot(bad));

    // External share is null and not in missing[]: a dash, not amber.
    final card = find.byKey(const ValueKey('pm-report-card-Sam Ortiz'));
    expect(
      find.descendant(of: card, matching: find.text('missing')),
      findsNothing,
    );

    // The production cost by month, place and team.
    expect(
      find.byKey(const ValueKey('pm-production-2026-10-Sam Ortiz')),
      findsOneWidget,
    );
    expect(find.text(r'$1,240.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-week-back')));
    await tester.pumpAndSettle();
    expect(
      server
          .get('/financials/repair-report')
          .last
          .queryParameters['week_start'],
      '2026-10-05',
    );
  });

  testWidgets('Approve, with financials.approve, posts and shows who', (
    tester,
  ) async {
    final server = await open(tester, 'Reports');
    await tester.tap(find.byKey(const ValueKey('pm-report-approve')));
    await tester.pumpAndSettle();
    expect(writes(server), [
      [
        'POST',
        '/financials/repair-report/approve',
        {'franchise_id': 'fr-1', 'week_start': '2026-10-12'},
      ],
    ]);
    expect(find.text('Approved'), findsOneWidget);
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('pm-report-approved-by')))
          .data,
      startsWith('Approved by Rhea Tollan · '),
    );
    expect(find.byKey(const ValueKey('pm-report-approve')), findsNothing);
  });

  testWidgets('no Approve without financials.approve', (tester) async {
    await open(
      tester,
      'Reports',
      permissions: const [
        'jobs.view',
        'project_management.view',
        'financials.bases.view',
      ],
    );
    expect(find.byKey(const ValueKey('pm-report-approve')), findsNothing);
    expect(find.byKey(const ValueKey('pm-report-copy')), findsOneWidget);
  });

  testWidgets("Copy for WhatsApp copies the server's text", (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String;
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    await open(tester, 'Reports');
    await tester.tap(find.byKey(const ValueKey('pm-report-copy')));
    await tester.pumpAndSettle();
    expect(copied, pmRepairWeek()['text']);
  });

  testWidgets('Table: missing[] amber and counted, gaps only, search', (
    tester,
  ) async {
    final server = await open(tester, 'Table');
    expect(server.get('/financials/repair-report/table').last.queryParameters, {
      'franchise_id': 'fr-1',
      'from': '2026-08-31',
      'to': '2026-10-25',
    });
    expect(find.text('2 fields missing'), findsOneWidget);
    expect(
      find.byKey(
        const ValueKey('pm-table-missing-2026-10-12-Pat Lin-paycheck'),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(
        const ValueKey('pm-table-missing-2026-10-12-Pat Lin-operation'),
      ),
      findsOneWidget,
    );
    // External share is null for both, and not missing.
    expect(
      find.byKey(
        const ValueKey('pm-table-cell-2026-10-12-Sam Ortiz-external_share'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('pm-table-gaps')));
    await tester.pumpAndSettle();
    expect(find.text('2026-10-12 · Sam Ortiz'), findsNothing);
    expect(find.text('2026-10-12 · Pat Lin'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-table-gaps')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('pm-table-search')),
      'ortiz',
    );
    await tester.pumpAndSettle();
    expect(find.text('2026-10-12 · Sam Ortiz'), findsOneWidget);
    expect(find.text('2026-10-12 · Pat Lin'), findsNothing);
  });

  testWidgets('an approved week warns before a typed value reopens it; the '
      'PATCH is dollars', (tester) async {
    final server = await open(tester, 'Table');
    await tester.tap(
      find.byKey(
        const ValueKey('pm-table-missing-2026-10-12-Pat Lin-paycheck'),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('pm-table-reopen-warning')),
      findsOneWidget,
    );
    expect(writes(server), isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('pm-table-edit')),
      '1600.50',
    );
    await tester.tap(find.byKey(const ValueKey('pm-table-edit-save')));
    await tester.pumpAndSettle();
    expect(writes(server), [
      [
        'PATCH',
        '/financials/repair-report/value',
        {
          'franchise_id': 'fr-1',
          'week_start': '2026-10-12',
          'team': 'Pat Lin',
          'field': 'paycheck',
          'value': 1600.5,
        },
      ],
    ]);
    // The week came back as a draft and replaced its rows.
    expect(find.byKey(const ValueKey('pm-table-editor')), findsNothing);
    expect(find.text('No fields missing'), findsOneWidget);

    // Empty clears it.
    await tester.tap(
      find.byKey(const ValueKey('pm-table-cell-2026-10-12-Sam Ortiz-hours')),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-table-reopen-warning')), findsNothing);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('pm-table-edit')))
          .controller!
          .text,
      '38.5',
    );
    await tester.enterText(find.byKey(const ValueKey('pm-table-edit')), '');
    await tester.tap(find.byKey(const ValueKey('pm-table-edit-save')));
    await tester.pumpAndSettle();
    expect((writes(server).last.last as Map)['value'], isNull);
  });

  testWidgets('no cell opens an editor without financials.edit', (
    tester,
  ) async {
    await open(
      tester,
      'Table',
      permissions: const [
        'jobs.view',
        'project_management.view',
        'financials.bases.view',
      ],
    );
    await tester.tap(
      find.byKey(
        const ValueKey('pm-table-missing-2026-10-12-Pat Lin-paycheck'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-table-editor')), findsNothing);
  });
}
