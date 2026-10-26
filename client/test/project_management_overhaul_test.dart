import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/pm_frame.dart';
import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:ezbizi_client/screens/jobs/project_management_rules.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Design 144, option 1: one stage order and one colour per stage on every
/// tab, the Rules tab read live, and Settings and Rules linked both ways.
/// Every name invented.
void main() {
  Future<void> go(WidgetTester tester, String tab) async {
    await tester.ensureVisible(find.text(tab).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text(tab).first);
    await tester.pumpAndSettle();
  }

  String selected(WidgetTester tester) {
    final bar = tester.widget<TabBarLike>(find.byType(TabBarLike).first);
    return bar.labels[bar.selected].$1;
  }

  /// Each stage's dot on the tab in front: where it is and what it paints.
  Map<String, (Offset, Color)> dots(WidgetTester tester) => {
    for (final e in find.byType(PmStageDot).evaluate())
      (e.widget as PmStageDot).stage: (
        tester.getTopLeft(find.byWidget(e.widget)),
        ((tester
                    .widget<Container>(
                      find.descendant(
                        of: find.byWidget(e.widget),
                        matching: find.byType(Container),
                      ),
                    )
                    .decoration
                as BoxDecoration)
            .color)!,
      ),
  };

  /// Reading order: line by line, then left to right.
  List<String> order(Map<String, (Offset, Color)> d) =>
      (d.keys.toList()..sort((a, b) {
        final (pa, _) = d[a]!;
        final (pb, _) = d[b]!;
        final dy = (pa.dy - pb.dy);
        if (dy.abs() > 8) return dy.sign.toInt();
        return (pa.dx - pb.dx).sign.toInt();
      }));

  testWidgets('one stage order and one colour per stage on Overview, Jobs '
      'and Settings', (tester) async {
    final server = PmServer();
    server.settings['jobs.pm_stages'] = {
      ...server.settings['jobs.pm_stages']!,
      'value': [
        for (final k in pmStageKeys)
          {
            'key': k,
            'statuses': ['in_progress'],
            'scheduled': null,
            'due': null,
          },
      ],
    };
    await server.pump(tester, size: const Size(1440, 1400));

    // The way a job moves, spelled out: not read back from pmStageKeys, so
    // putting the old order back fails here.
    expect(pmStageKeys, [
      'waiting_review',
      'ready_to_schedule',
      'scheduled',
      'in_progress',
      'done_waiting_payment',
    ]);
    final seen = <String, Map<String, (Offset, Color)>>{};
    for (final tab in ['Overview', 'Jobs', 'Settings']) {
      await go(tester, tab);
      final d = dots(tester);
      expect(d.keys.toSet(), pmStageKeys.toSet(), reason: tab);
      // Settings' stage table reads top to bottom, the flows left to right.
      expect(order(d), pmStageKeys, reason: tab);
      seen[tab] = d;
    }
    for (final k in pmStageKeys) {
      final colours = {for (final d in seen.values) d[k]!.$2};
      expect(colours, hasLength(1), reason: '$k changes colour between tabs');
    }

    // Five different colours, none of them the "needs attention" red, and
    // In progress plainly apart from Scheduled (blue and sky were not).
    final colours = {for (final k in pmStageKeys) seen['Jobs']![k]!.$2};
    expect(colours, hasLength(pmStageKeys.length));
    final context = tester.element(find.byType(PmStageDot).first);
    final red = ToneColours.of(context, Tone.bad).ink;
    double distance(Color a, Color b) =>
        ((a.r - b.r).abs() + (a.g - b.g).abs() + (a.b - b.b).abs()) * 255;
    for (final c in colours) {
      expect(distance(c, red), greaterThan(60));
    }
    expect(
      distance(
        seen['Jobs']!['in_progress']!.$2,
        seen['Jobs']!['scheduled']!.$2,
      ),
      greaterThan(150),
    );
    // Review reasons wear no stage's colour.
    for (var i = 0; i < 4; i++) {
      expect(colours.contains(pmReasonColour(context, i)), isFalse);
    }
  });

  testWidgets('Rules renders the live rules: warnings first, OFF, each '
      'block with Change', (tester) async {
    final server = PmServer();
    await server.pump(tester, size: const Size(1440, 1400));
    await go(tester, 'Rules');
    expect(
      server.get('/jobs/project-management/rules'),
      hasLength(2),
      reason: 'read on open, and again when Rules is opened',
    );

    // The warning sits above every block.
    final warning = find.byKey(const ValueKey('pm-rules-warning-0'));
    expect(
      find.descendant(
        of: warning,
        matching: find.textContaining('"Parts ordered" is in scope'),
      ),
      findsOneWidget,
    );
    expect(
      tester.getTopLeft(warning).dy,
      lessThan(
        tester
            .getTopLeft(find.byKey(const ValueKey('pm-rules-title-scope')))
            .dy,
      ),
    );
    // Which jobs count, with its count and where it is set.
    expect(find.textContaining('97 jobs count now'), findsOneWidget);
    expect(
      find.textContaining('Set at Brightway Chimney'),
      findsNWidgets(2),
      reason: 'the scope and the stages',
    );
    // A line per stage with its count, in flow order.
    expect(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('pm-rules-stage-in_progress-count')),
          )
          .data,
      '41',
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('pm-rules-stage-scheduled')),
        matching: find.textContaining('Not set up'),
      ),
      findsOneWidget,
    );
    // Each rule as When / Only if / Then, on or off, and its week.
    final off = tester.widget<StatusPill>(
      find.byKey(const ValueKey('pm-rules-onoff-a-1')),
    );
    expect((off.label, off.tone), ('OFF', Tone.neutral));
    expect(
      tester
          .widget<StatusPill>(find.byKey(const ValueKey('pm-rules-onoff-a-2')))
          .label,
      'ON',
    );
    final rule = find.byKey(const ValueKey('pm-rules-automation-a-1'));
    for (final t in [
      'When',
      'a payment is received on a job',
      'Only if',
      'status is Needs review.',
      'Then',
      'Last skip',
    ]) {
      expect(
        find.descendant(of: rule, matching: find.text(t)),
        findsOneWidget,
        reason: t,
      );
    }
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('pm-rules-week-a-1'))).data,
      'This week: 0 runs, 12 skipped',
    );
    // Daily checks and alerts, each value with where it is set.
    expect(find.text('Every day at 8:20 AM Eastern'), findsOneWidget);
    expect(find.text('Paid but not started after'), findsOneWidget);
    expect(find.text('10 days'), findsOneWidget);
    expect(find.text('office@example.com'), findsOneWidget);
    // Every number explained.
    expect(find.text('Payments stopped'), findsWidgets);
    expect(find.byKey(const ValueKey('pm-rules-number-0')), findsOneWidget);
    // Read only: nothing here writes.
    expect(server.writes(), isEmpty);
    expect(find.byType(Switch), findsNothing);

    // Change › opens the right Settings card.
    for (final (section, card) in [
      ('scope', 'tour-pm-settings-scope'),
      ('stages', 'tour-pm-settings-stages'),
      ('alerts', 'tour-pm-settings-warn'),
    ]) {
      await go(tester, 'Rules');
      final change = find.byKey(ValueKey('pm-rules-change-$section'));
      await tester.ensureVisible(change);
      await tester.pumpAndSettle();
      await tester.tap(change);
      await tester.pumpAndSettle();
      expect(selected(tester), 'Settings', reason: section);
      final top = tester.getTopLeft(find.byKey(ValueKey(card))).dy;
      expect(top, inInclusiveRange(0, 1400), reason: '$section: $card shown');
    }
    expect(tester.takeException(), isNull);
  });

  testWidgets('Rules with two branch groups: a part per group, labelled '
      '"Company · Branch"', (tester) async {
    final server = PmServer()..rules = pmRules(two: true);
    await server.pump(tester, size: const Size(1440, 1400));
    await go(tester, 'Rules');
    expect(
      find.byKey(
        const ValueKey('pm-rules-group-Brightway Chimney · Northgate'),
      ),
      findsWidgets,
    );
    expect(
      find.byKey(
        const ValueKey('pm-rules-group-Brightway Chimney · Southside'),
      ),
      findsWidgets,
    );
    // Its warning names its group.
    expect(
      find.textContaining('Brightway Chimney · Northgate: "Parts ordered"'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('pm-rules-warning-1')), findsNothing);
    // The Overview's ⓘ says each group's rule.
    await go(tester, 'Overview');
    final tip = tester
        .widget<PmInfo>(find.byKey(const ValueKey('pm-info-waiting_review')))
        .message;
    expect(
      tip,
      contains('Brightway Chimney · Northgate: Status Needs review.'),
    );
    expect(
      tip,
      contains('Brightway Chimney · Southside: Status Needs review.'),
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('Rules searches its lines', (tester) async {
    await PmServer().pump(tester, size: const Size(1440, 1400));
    await go(tester, 'Rules');
    expect(
      find.byKey(const ValueKey('pm-rules-automation-a-2')),
      findsOneWidget,
    );
    await tester.enterText(
      find.byKey(const ValueKey('pm-rules-search')),
      'deposit',
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('pm-rules-automation-a-1')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('pm-rules-automation-a-2')), findsNothing);
  });

  testWidgets('each Settings card links to its line on Rules', (tester) async {
    await PmServer().pump(tester, size: const Size(1440, 900));
    for (final (card, title) in [
      ('stages', 'pm-rules-title-stages'),
      ('alerts', 'pm-rules-title-alerts'),
      ('watched', 'pm-rules-title-daily'),
    ]) {
      await go(tester, 'Settings');
      final link = find.byKey(ValueKey('pm-set-rules-$card'));
      await tester.ensureVisible(link);
      await tester.pumpAndSettle();
      await tester.tap(link);
      await tester.pumpAndSettle();
      expect(selected(tester), 'Rules', reason: card);
      final top = tester.getTopLeft(find.byKey(ValueKey(title))).dy;
      expect(top, inInclusiveRange(0, 900), reason: '$card: $title shown');
    }
  });

  testWidgets('Settings searches its cards', (tester) async {
    await PmServer().pump(tester, size: const Size(1440, 1400));
    await go(tester, 'Settings');
    await tester.enterText(
      find.byKey(const ValueKey('pm-set-search')),
      'alert',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('tour-pm-settings-warn')), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-pm-settings-stages')), findsNothing);
  });

  testWidgets('without the rules endpoint the tabs still draw', (tester) async {
    final server = PmServer()..rules = null;
    await server.pump(tester);
    expect(
      tester
          .widget<PmInfo>(find.byKey(const ValueKey('pm-info-waiting_review')))
          .message,
      contains('Waiting review statuses in Settings'),
    );
    await go(tester, 'Rules');
    expect(find.byKey(const ValueKey('pm-rules-error')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the countdown bar paints: a filled segment as tall as its '
      'track', (tester) async {
    await PmServer().pump(tester);
    await go(tester, 'Jobs');
    final bar = find.descendant(
      of: find.byKey(const ValueKey('pm-after-j-4102')),
      matching: find.byType(PmBar),
    );
    final boxes = find.descendant(of: bar, matching: find.byType(ColoredBox));
    expect(boxes.evaluate().length, greaterThan(1));
    final track = tester.getSize(boxes.first);
    final seg = tester.getSize(boxes.at(1));
    expect(seg.height, greaterThan(0));
    expect(seg.height, track.height);
    // 8 of 10 days: four fifths of the track.
    expect(seg.width / track.width, closeTo(0.8, 0.02));
    // In progress is past starting: no countdown there.
    await tester.tap(find.byKey(const ValueKey('pm-stage-in_progress')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-after-j-4102')), findsNothing);
  });

  test('a stage sentence for one group is that sentence', () {
    final rules = PmRules.parse(pmRules());
    expect(rules.stageSentence('in_progress', null), 'Status In progress.');
    expect(rules.stageSentence('scheduled', null), isNull);
    expect(rules.scopeSetAt, 'company: Brightway Chimney');
    expect(pmSetAtLabel('company: Brightway Chimney'), 'Brightway Chimney');
    expect(pmSetAtLabel('default'), 'the default');
  });
}
