import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management's screen and its Dashboard (design 141, option 1 and
/// phone 4): the seven tabs, the tiles from the overview, a tile's way into
/// Jobs, the countdown bars and the phone. Every name invented.
void main() {
  List<String> tabs(WidgetTester tester) => [
    for (final l
        in tester.widget<TabBarLike>(find.byType(TabBarLike).first).labels)
      l.$1,
  ];

  String text(WidgetTester tester, String key) =>
      tester.widget<Text>(find.byKey(ValueKey(key))).data ?? '';

  testWidgets('seven tabs, opening on Dashboard', (tester) async {
    await PmServer().pump(tester);
    expect(tabs(tester), [
      'Dashboard',
      'Reports',
      'Table',
      'Jobs',
      'Assignments',
      'Logs',
      'Settings',
    ]);
    expect(find.byKey(const ValueKey('pm-dash')), findsOneWidget);
  });

  testWidgets('without financials.bases.view, no Reports or Table', (
    tester,
  ) async {
    await PmServer(
      permissions: const ['jobs.view', 'project_management.view'],
    ).pump(tester);
    expect(tabs(tester), [
      'Dashboard',
      'Jobs',
      'Assignments',
      'Logs',
      'Settings',
    ]);
  });

  testWidgets('the tiles come from the overview, in pipeline order', (
    tester,
  ) async {
    final server = PmServer();
    await server.pump(tester);

    expect(server.get('/overview').single.queryParameters, isEmpty);
    expect(text(tester, 'pm-tile-waiting_review-value'), '23');
    expect(
      text(tester, 'pm-tile-waiting_review-sub'),
      'Order parts 9 · Measurements 7 · No reason 7',
    );
    expect(text(tester, 'pm-tile-in_progress-value'), '41');
    expect(
      text(tester, 'pm-tile-in_progress-sub'),
      'Us 29 · External 12 · 2nd visit 6 · Redo 4 / new 37',
    );
    expect(
      text(tester, 'pm-tile-ready_to_schedule-sub'),
      'Oldest waiting 9 days',
    );
    expect(
      text(tester, 'pm-tile-done_waiting_payment-sub'),
      r'$48,200 total · $21,700 due',
    );
    expect(text(tester, 'pm-tile-pipeline-value'), r'$186,000');
    // The overview's is null: Financials' production cost stands in.
    expect(text(tester, 'pm-tile-avg_production_cost-value'), r'$1,180');
    expect(
      text(tester, 'pm-tile-avg_production_cost-sub'),
      'Per job, the last three months',
    );
    expect(text(tester, 'pm-tile-stopped_payments-value'), '5');
    expect(
      text(tester, 'pm-tile-stopped_payments-sub'),
      'Partly paid, nothing for 30+ days',
    );

    // Pipeline order, left to right then down.
    final order = [
      for (final key in pmTileKeys)
        tester.getTopLeft(find.byKey(ValueKey('pm-tile-$key'))),
    ];
    for (var i = 1; i < order.length; i++) {
      final before = order[i - 1];
      final now = order[i];
      expect(
        now.dy > before.dy || (now.dy == before.dy && now.dx > before.dx),
        isTrue,
        reason: 'tile $i is out of order',
      );
    }
    // Every tile the same size.
    final sizes = {
      for (final t in pmTileKeys)
        tester.getSize(find.byKey(ValueKey('pm-tile-$t'))),
    };
    expect(sizes, hasLength(1));
  });

  testWidgets('without money: no amounts, no Pipeline or cost tiles', (
    tester,
  ) async {
    await PmServer(overview: pmOverview(money: false)).pump(tester);
    expect(find.byKey(const ValueKey('pm-tile-pipeline')), findsNothing);
    expect(
      find.byKey(const ValueKey('pm-tile-avg_production_cost')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('pm-tile-done_waiting_payment-sub')),
      findsNothing,
    );
    expect(text(tester, 'pm-tile-done_waiting_payment-value'), '19');
    expect(find.textContaining(r'$'), findsNothing);
  });

  testWidgets('an unmapped stage says not set up and links to Settings', (
    tester,
  ) async {
    await PmServer().pump(tester);
    expect(
      find.byKey(const ValueKey('pm-tile-scheduled-unmapped')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('pm-tile-scheduled-value')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('pm-tile-scheduled-setup')));
    await tester.pumpAndSettle();
    expect(
      tabs(tester)[tester
          .widget<TabBarLike>(find.byType(TabBarLike).first)
          .selected],
      'Settings',
    );
  });

  testWidgets('filters and search ask the overview again', (tester) async {
    final server = PmServer();
    await server.pump(tester);

    await tester.tap(find.byKey(const ValueKey('pm-area-sa-2')));
    await tester.pumpAndSettle();
    expect(server.get('/overview').last.queryParameters, {
      'service_area_id': 'sa-2',
    });

    await tester.tap(find.byKey(const ValueKey('pm-flag-one_go')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-dispute')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-second_visit')));
    await tester.pumpAndSettle();
    expect(server.get('/overview').last.queryParameters, {
      'service_area_id': 'sa-2',
      'redo': '0',
      'dispute': '1',
      'second_visit': '1',
    });

    await tester.enterText(
      find.byKey(const ValueKey('pm-dash-search')),
      'Ortiz',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
    expect(server.get('/overview').last.queryParameters['q'], 'Ortiz');
  });

  testWidgets('a tile opens Jobs on its stage, under the same filters', (
    tester,
  ) async {
    final server = PmServer();
    await server.pump(tester);
    await tester.tap(find.byKey(const ValueKey('pm-area-sa-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-external')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('pm-tile-waiting_review')));
    await tester.pumpAndSettle();
    expect(server.get('/project-management/jobs').last.queryParameters, {
      'stage': 'waiting_review',
      'service_area_id': 'sa-1',
      'external': '1',
    });
    final chip = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('pm-stage-waiting_review')),
    );
    expect(chip.selected, isTrue);

    // Back on the Dashboard, Stopped payments adds its own filter.
    await tester.tap(find.text('Dashboard'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const ValueKey('pm-tile-stopped_payments')),
    );
    await tester.tap(find.byKey(const ValueKey('pm-tile-stopped_payments')));
    await tester.pumpAndSettle();
    expect(server.get('/project-management/jobs').last.queryParameters, {
      'stage': 'done_waiting_payment',
      'service_area_id': 'sa-1',
      'external': '1',
      'stopped': '1',
    });
  });

  testWidgets('waiting review by location, stacked, with the legend', (
    tester,
  ) async {
    await PmServer().pump(tester);
    expect(
      find.byKey(const ValueKey('pm-review-loc-Columbus')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('pm-review-loc-Cleveland')),
      findsOneWidget,
    );
    final legend = find.byKey(const ValueKey('pm-review-legend'));
    for (final l in ['Order parts', 'Measurements', 'No reason']) {
      expect(
        find.descendant(of: legend, matching: find.text(l)),
        findsOneWidget,
      );
    }
    final bar = tester.widget<PmBar>(
      find.descendant(
        of: find.byKey(const ValueKey('pm-review-loc-Columbus')),
        matching: find.byType(PmBar),
      ),
    );
    expect([for (final s in bar.segments) (s.$1 * 11).round()], [5, 4, 2]);
    expect(find.byKey(const ValueKey('pm-team-Repair NJ')), findsOneWidget);
  });

  test('the countdown tone: red at the limit, amber two days before', () {
    expect(pmCountdownTone(10, 10), Tone.bad);
    expect(pmCountdownTone(12, 10), Tone.bad);
    expect(pmCountdownTone(9, 10), Tone.caution);
    expect(pmCountdownTone(8, 10), Tone.caution);
    expect(pmCountdownTone(7, 10), Tone.info);
    expect(pmCountdownText(10, 10), '10 days · warned daily');
    expect(pmCountdownText(8, 10), '8 days · 2 left');
  });

  testWidgets('waiting to start: a bar per job, its tone and words', (
    tester,
  ) async {
    await PmServer().pump(tester);
    final late = find.byKey(const ValueKey('pm-start-bar-j-3990'));
    expect(
      find.descendant(of: late, matching: find.text('12 days · warned daily')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('pm-start-bar-j-4188')),
        matching: find.text('3 days · 7 left'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('phone at 390×844: tiles two a row, no overflow', (tester) async {
    await PmServer().pump(tester, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    final a = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-waiting_review')),
    );
    final b = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-in_progress')),
    );
    final c = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-ready_to_schedule')),
    );
    expect(a.dy, b.dy);
    expect(c.dy, greaterThan(a.dy));

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('pm-team-Repair OH')),
      300,
      scrollable: find
          .descendant(
            of: find.byKey(const ValueKey('pm-dash')),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    expect(tester.takeException(), isNull);

    // The tabs scroll; Jobs is cards.
    await tester.ensureVisible(find.text('Jobs'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-card-j-4102')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const ValueKey('pm-card-j-4102')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-field-reason')), findsOneWidget);
    expect(tester.takeException(), isNull);

    for (final tab in ['Reports', 'Table', 'Settings']) {
      await tester.ensureVisible(find.text(tab).first);
      await tester.pumpAndSettle();
      await tester.tap(find.text(tab).first);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: tab);
    }
  });
}

const pmTileKeys = [
  'waiting_review',
  'in_progress',
  'ready_to_schedule',
  'scheduled',
  'done_waiting_payment',
  'pipeline',
  'avg_production_cost',
  'stopped_payments',
];
