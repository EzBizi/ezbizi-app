import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/pm_frame.dart';
import 'package:ezbizi_client/screens/jobs/project_management_dashboard.dart';
import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management's screen and its Overview (design 144, option 1 and
/// phone 4): the seven tabs in one frame, the flow, Needs attention, every
/// number's ⓘ and its way into Jobs, and the phone. Every name invented.
void main() {
  List<String> tabs(WidgetTester tester) => [
    for (final l
        in tester.widget<TabBarLike>(find.byType(TabBarLike).first).labels)
      l.$1,
  ];

  String text(WidgetTester tester, String key) =>
      tester.widget<Text>(find.byKey(ValueKey(key))).data ?? '';

  Future<void> go(WidgetTester tester, String tab) async {
    await tester.ensureVisible(find.text(tab).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text(tab).first);
    await tester.pumpAndSettle();
  }

  const all = [
    'Overview',
    'Jobs',
    'Assignments',
    'Repair team',
    'Rules',
    'Logs',
    'Settings',
  ];
  const keys = {
    'Overview': 'dashboard',
    'Jobs': 'pending',
    'Assignments': 'assignments',
    'Repair team': 'reports',
    'Rules': 'rules',
    'Logs': 'logs',
    'Settings': 'settings',
  };

  testWidgets('seven tabs, opening on Overview', (tester) async {
    await PmServer().pump(tester);
    expect(tabs(tester), all);
    expect(find.byKey(const ValueKey('pm-dash')), findsOneWidget);
  });

  testWidgets('without financials.bases.view, no Repair team', (tester) async {
    await PmServer(
      permissions: const ['jobs.view', 'project_management.view'],
    ).pump(tester);
    expect(tabs(tester), [
      for (final t in all)
        if (t != 'Repair team') t,
    ]);
  });

  testWidgets('every tab draws the one frame: purpose, scope, search first', (
    tester,
  ) async {
    final server = PmServer()..cards = {'data': <Object>[], 'boards': []};
    await server.pump(tester);
    double? purposeTop;
    for (final tab in all) {
      await go(tester, tab);
      final frame = find.byKey(ValueKey('pm-frame-${keys[tab]}'));
      expect(frame, findsOneWidget, reason: tab);
      expect(
        find.descendant(of: frame, matching: find.byType(PmFrame)),
        findsNothing,
        reason: '$tab nests a second frame',
      );
      final purpose = find.descendant(
        of: frame,
        matching: find.byKey(const ValueKey('pm-frame-purpose')),
      );
      expect(purpose, findsOneWidget, reason: tab);
      expect(
        find.descendant(
          of: frame,
          matching: find.byKey(const ValueKey('pm-scope-line')),
        ),
        findsOneWidget,
        reason: tab,
      );
      // Search is the filter bar's first field.
      final bar = find.descendant(
        of: frame,
        matching: find.byKey(const ValueKey('pm-frame-filters')),
      );
      final fields = find.descendant(of: bar, matching: find.byType(TextField));
      expect(fields, findsWidgets, reason: tab);
      final first = tester.getTopLeft(fields.first);
      for (final e
          in find
              .descendant(of: bar, matching: find.byType(InputDecorator))
              .evaluate()) {
        final at = tester.getTopLeft(find.byWidget(e.widget));
        expect(
          at.dy > first.dy - 1 && (at.dy > first.dy + 1 || at.dx >= first.dx),
          isTrue,
          reason: '$tab: a field sits before the search',
        );
      }
      // The same spacing everywhere: the purpose starts at one height.
      final top = tester.getTopLeft(purpose).dy;
      purposeTop ??= top;
      expect(top, purposeTop, reason: tab);
      // Every tab links to Rules; Rules does not link to itself.
      expect(
        find.byKey(ValueKey('pm-frame-how-${keys[tab]}')),
        tab == 'Rules' ? findsNothing : findsOneWidget,
        reason: tab,
      );
    }
  });

  testWidgets('How this is measured opens Rules at the tab\'s section', (
    tester,
  ) async {
    await PmServer().pump(tester, size: const Size(1440, 700));
    await tester.tap(find.byKey(const ValueKey('pm-frame-how-dashboard')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-rules')), findsOneWidget);
    // Scrolled to "How each number is measured".
    final numbers = tester.getTopLeft(
      find.byKey(const ValueKey('pm-rules-title-numbers')),
    );
    expect(numbers.dy, lessThan(700));
    expect(numbers.dy, greaterThan(0));
    // The scope bar's Change opens Settings at the scope card.
    await go(tester, 'Jobs');
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('pm-frame-pending')),
        matching: find.byKey(const ValueKey('pm-scope-change')),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      tabs(tester)[tester
          .widget<TabBarLike>(find.byType(TabBarLike).first)
          .selected],
      'Settings',
    );
    expect(
      find.byKey(const ValueKey('tour-pm-settings-scope')),
      findsOneWidget,
    );
  });

  testWidgets('the scope names every status and where it is set', (
    tester,
  ) async {
    final server = PmServer();
    server.settings['jobs.pm_scope_statuses'] = {
      'key': 'jobs.pm_scope_statuses',
      'value': ['in_progress', 'parts_ordered', 'done', 'review'],
      'may_edit': true,
    };
    await server.pump(tester);
    expect(
      text(tester, 'pm-scope-text'),
      'Counting jobs in In progress, Parts ordered, Done, Needs review · '
      'Brightway · set at Brightway Chimney',
    );
  });

  testWidgets('the flow: five stages in order, one size, › between', (
    tester,
  ) async {
    final server = PmServer();
    await server.pump(tester, size: const Size(1440, 1000));
    expect(server.get('/overview').single.queryParameters, isEmpty);

    expect(text(tester, 'pm-tile-waiting_review-value'), '23');
    expect(
      text(tester, 'pm-tile-waiting_review-sub'),
      '7 with no reason picked',
    );
    expect(text(tester, 'pm-tile-in_progress-value'), '41');
    expect(
      text(tester, 'pm-tile-in_progress-sub'),
      'Our own technicians 29 · external company 12',
    );
    expect(
      text(tester, 'pm-tile-ready_to_schedule-sub'),
      'Oldest 9 days in this status',
    );
    expect(text(tester, 'pm-tile-done_waiting_payment-sub'), r'$21,700 due');
    // The rule line is the rules endpoint's own sentence.
    expect(text(tester, 'pm-tile-waiting_review-rule'), 'Status Needs review.');

    final xs = [
      for (final k in pmStageKeys)
        tester.getTopLeft(find.byKey(ValueKey('pm-tile-$k'))).dx,
    ];
    expect([...xs]..sort(), xs);
    final ys = {
      for (final k in pmStageKeys)
        tester.getTopLeft(find.byKey(ValueKey('pm-tile-$k'))).dy,
    };
    expect(ys, hasLength(1));
    final sizes = {
      for (final k in pmStageKeys)
        tester.getSize(find.byKey(ValueKey('pm-tile-$k'))),
    };
    expect(sizes, hasLength(1));
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('tour-pm-dash-tiles')),
        matching: find.byIcon(Icons.chevron_right),
      ),
      findsNWidgets(pmStageKeys.length - 1),
    );
    // Needs attention's three cards, one size.
    final cards = {
      for (final k in pmAttentionCards)
        tester.getSize(find.byKey(ValueKey('pm-attention-$k'))),
    };
    expect(cards, hasLength(1));
    expect(text(tester, 'pm-attention-not_started-value'), '1');
    expect(text(tester, 'pm-attention-stopped-value'), '5');
    expect(text(tester, 'pm-attention-no_reason-value'), '7');
    // No average production cost, no waiting-to-start list (design 144).
    expect(
      find.byKey(const ValueKey('pm-tile-avg_production_cost')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('tour-pm-dash-start')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('every Overview number has an ⓘ with its rule', (tester) async {
    await PmServer().pump(tester, size: const Size(1440, 1200));
    String tip(String key) =>
        tester.widget<PmInfo>(find.byKey(ValueKey(key))).message;
    for (final k in pmStageKeys) {
      expect(tip('pm-info-$k'), startsWith('${pmStageName(k)} = '));
    }
    expect(
      tip('pm-info-waiting_review'),
      contains('Status Needs review.'),
      reason: "the rules endpoint's sentence",
    );
    expect(tip('pm-info-waiting_review'), contains('still owe money'));
    expect(tip('pm-info-pipeline'), contains('Workiz total prices'));
    expect(tip('pm-info-attention-not_started'), contains('first payment'));
    expect(tip('pm-info-attention-not_started'), contains('8:20 AM Eastern'));
    expect(tip('pm-info-attention-stopped'), contains('more than 30 days'));
    expect(tip('pm-info-attention-no_reason'), contains('no review reason'));
    // The boxes too.
    expect(
      find.byWidgetPredicate(
        (w) => w is PmInfo && '${w.key}'.contains('pm-info-box-'),
      ),
      findsNWidgets(2),
    );
    // A Tooltip that shows on tap, so a phone has it too.
    final t = tester.widget<Tooltip>(
      find.descendant(
        of: find.byKey(const ValueKey('pm-info-in_progress')),
        matching: find.byType(Tooltip),
      ),
    );
    expect(t.triggerMode, TooltipTriggerMode.tap);
  });

  testWidgets('without money: no amounts', (tester) async {
    await PmServer(overview: pmOverview(money: false)).pump(tester);
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
    // Needs a second visit means something only In progress: not offered.
    expect(find.byKey(const ValueKey('pm-flag-second_visit')), findsNothing);
    expect(server.get('/overview').last.queryParameters, {
      'service_area_id': 'sa-2',
      'redo': '0',
      'dispute': '1',
    });

    await tester.enterText(
      find.byKey(const ValueKey('pm-dash-search')),
      'Ortiz',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
    expect(server.get('/overview').last.queryParameters['q'], 'Ortiz');
  });

  group('every number opens Jobs on exactly what it counted', () {
    /// The stage list answers with as many jobs as the query asks for, by
    /// the overview's own counts, so the page's total is the drill's count.
    Map<String, dynamic> counted(Uri uri) {
      final o = pmOverview();
      final q = uri.queryParametersAll;
      int c(String k) => (o[k] as Map)['count'] as int;
      final total = q['review_reason[]']?.single == 'none'
          ? 7
          : q['stopped']?.single == '1'
          ? o['stopped_payments'] as int
          : q['waiting_to_start']?.single == '1'
          ? 1
          : q.containsKey('stages[]')
          ? [for (final s in q['stages[]']!) c(s)].fold(0, (a, b) => a + b)
          : c(uri.queryParameters['stage']!);
      return {
        ...pmJobsPage(uri),
        'meta': {
          ...(pmJobsPage(uri)['meta'] as Map),
          'last_page': 1,
          'total': total,
        },
      };
    }

    Future<PmServer> open(WidgetTester tester) async {
      final server = PmServer(jobs: counted);
      await server.pump(tester, size: const Size(1440, 1000));
      await tester.tap(find.byKey(const ValueKey('pm-area-sa-1')));
      await tester.pumpAndSettle();
      return server;
    }

    String page(WidgetTester tester) =>
        tester.widget<Text>(find.byKey(const ValueKey('pm-jobs-page'))).data!;

    String drill(WidgetTester tester) =>
        (tester
                    .widget<InputChip>(
                      find.byKey(const ValueKey('pm-jobs-drill')),
                    )
                    .label
                as Text)
            .data!;

    testWidgets('a stage tile: its stage, under the same filters', (
      tester,
    ) async {
      final server = await open(tester);
      await tester.tap(find.byKey(const ValueKey('pm-tile-waiting_review')));
      await tester.pumpAndSettle();
      expect(server.get('/project-management/jobs').last.queryParameters, {
        'stage': 'waiting_review',
        'service_area_id': 'sa-1',
      });
      expect(
        tester
            .widget<ChoiceChip>(
              find.byKey(const ValueKey('pm-stage-waiting_review')),
            )
            .selected,
        isTrue,
      );
      expect(page(tester), startsWith('Page 1 of 1 · 23 jobs'));
      expect(find.byKey(const ValueKey('pm-jobs-drill')), findsNothing);
    });

    testWidgets('payments stopped: every stage, stopped=1', (tester) async {
      final server = await open(tester);
      await tester.tap(find.byKey(const ValueKey('pm-attention-stopped')));
      await tester.pumpAndSettle();
      final uri = server.get('/project-management/jobs').last;
      expect(uri.queryParameters, {'service_area_id': 'sa-1', 'stopped': '1'});
      expect(uri.queryParametersAll.containsKey('stages[]'), isFalse);
      expect(page(tester), startsWith('Page 1 of 1 · 5 jobs'));
      expect(drill(tester), 'Payments stopped, every stage');
      // No stage is picked while drilled; the chip takes the drill off.
      for (final k in pmStageKeys) {
        expect(
          tester
              .widget<ChoiceChip>(find.byKey(ValueKey('pm-stage-$k')))
              .selected,
          isFalse,
        );
      }
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('pm-jobs-drill')),
          matching: find.byTooltip('Remove'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('pm-jobs-drill')), findsNothing);
      expect(server.get('/project-management/jobs').last.queryParameters, {
        'stage': 'waiting_review',
        'service_area_id': 'sa-1',
      });
    });

    testWidgets('the pipeline: its stages as stages[]', (tester) async {
      final server = await open(tester);
      // 23 + 14 + 41: the pipeline line's own count.
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('pm-tile-pipeline-value')))
            .textSpan!
            .toPlainText(),
        startsWith('Pipeline: 78 open jobs'),
      );
      await tester.tap(find.byKey(const ValueKey('pm-tile-pipeline')));
      await tester.pumpAndSettle();
      final uri = server.get('/project-management/jobs').last;
      // Scheduled is not mapped in the fixture, so three of the four.
      expect(uri.queryParametersAll['stages[]'], [
        'waiting_review',
        'ready_to_schedule',
        'in_progress',
      ]);
      expect(uri.queryParameters.containsKey('stage'), isFalse);
      expect(page(tester), startsWith('Page 1 of 1 · 78 jobs'));
      expect(drill(tester), 'Pipeline: Waiting review to In progress');
    });

    testWidgets('paid, not started: waiting_to_start and over_limit', (
      tester,
    ) async {
      final server = await open(tester);
      await tester.tap(find.byKey(const ValueKey('pm-attention-not_started')));
      await tester.pumpAndSettle();
      final uri = server.get('/project-management/jobs').last;
      expect(uri.queryParameters, {
        'service_area_id': 'sa-1',
        'waiting_to_start': '1',
        'over_limit': '1',
      });
      expect(page(tester), startsWith('Page 1 of 1 · 1 job'));
      expect(drill(tester), 'Paid 10 or more days ago, not started');
      // The countdown applies here, from the first payment.
      final bar = tester.widget<PmCountdownBar>(
        find.byKey(const ValueKey('pm-after-j-4102')),
      );
      expect((bar.days, bar.limit), (8, 10));
    });

    testWidgets('no reason: Waiting review, the rows with none', (
      tester,
    ) async {
      final server = await open(tester);
      await tester.tap(find.byKey(const ValueKey('pm-attention-no_reason')));
      await tester.pumpAndSettle();
      final uri = server.get('/project-management/jobs').last;
      expect(uri.queryParameters, {
        'stage': 'waiting_review',
        'service_area_id': 'sa-1',
        'review_reason[]': 'none',
      });
      expect(uri.queryParametersAll['review_reason[]'], ['none']);
      expect(drill(tester), 'No reason picked');
      // The server filters now: the page is its count, no client filter.
      expect(page(tester), startsWith('Page 1 of 1 · 7 jobs'));
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('pm-jobs-drill')),
          matching: find.byTooltip('Remove'),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        server
            .get('/project-management/jobs')
            .last
            .queryParametersAll
            .containsKey('review_reason[]'),
        isFalse,
      );
    });
  });

  testWidgets('waiting review by service area, stacked, with the legend', (
    tester,
  ) async {
    await PmServer().pump(tester, size: const Size(1440, 1200));
    expect(
      find.byKey(const ValueKey('pm-review-loc-Columbus')),
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

  testWidgets('phone at 390×844: the flow stacks, filters behind Filters', (
    tester,
  ) async {
    await PmServer().pump(tester, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    // Each stage under the one before, the first on the first screen.
    final ys = [
      for (final k in pmStageKeys)
        tester.getTopLeft(find.byKey(ValueKey('pm-tile-$k'))).dy,
    ];
    expect([...ys]..sort(), ys);
    expect(ys.toSet(), hasLength(pmStageKeys.length));
    expect(
      tester
          .getBottomLeft(find.byKey(const ValueKey('pm-tile-waiting_review')))
          .dy,
      lessThan(844),
    );
    // The flags wait behind Filters.
    expect(find.byKey(const ValueKey('pm-flag-redo')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('pm-frame-filters-button')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-flag-redo')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  for (final width in [1440.0, 1024.0, 390.0]) {
    testWidgets('no overflow on any tab at $width wide', (tester) async {
      final server = PmServer()..cards = {'data': <Object>[], 'boards': []};
      await server.pump(tester, size: Size(width, width < 600 ? 844 : 900));
      expect(tester.takeException(), isNull);
      for (final tab in all) {
        await go(tester, tab);
        expect(tester.takeException(), isNull, reason: tab);
        if (tab == 'Repair team') {
          if (width < 600) {
            await tester.tap(
              find.byKey(const ValueKey('pm-frame-filters-button')),
            );
            await tester.pumpAndSettle();
          }
          await tester.tap(find.byKey(const ValueKey('pm-repair-table')));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull, reason: 'Repair team, Table');
        }
        if (tab == 'Jobs' && width < 600) {
          await tester.tap(find.byKey(const ValueKey('pm-card-j-4102')));
          await tester.pumpAndSettle();
          expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
          expect(tester.takeException(), isNull, reason: 'the timeline');
          await tester.tap(find.byKey(const ValueKey('pm-timeline-close')));
          await tester.pumpAndSettle();
        }
      }
    });
  }
}
