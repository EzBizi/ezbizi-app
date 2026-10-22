import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management › Settings made orderly (design 142, options 1 and 4),
/// and the Dashboard's waiting-to-start "See all". Every name invented.
void main() {
  Future<PmServer> settings(
    WidgetTester tester, {
    PmServer? server,
    Size size = const Size(1440, 2600),
  }) async {
    final s = server ?? PmServer();
    await s.pump(tester, size: size);
    // On a phone the tab strip scrolls sideways.
    await tester.ensureVisible(find.text('Settings'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    return s;
  }

  Color dot(WidgetTester tester, String key) =>
      (tester
                  .widget<Container>(
                    find.descendant(
                      of: find.byKey(ValueKey(key)),
                      matching: find.byType(Container),
                    ),
                  )
                  .decoration!
              as BoxDecoration)
          .color!;

  testWidgets('the stage table lines every row up: one x per column', (
    tester,
  ) async {
    await settings(tester);
    double x(String key) => tester.getTopLeft(find.byKey(ValueKey(key))).dx;

    final scheduled = {
      for (final k in pmStageKeys) x('pm-set-stage-$k-scheduled'),
    };
    final due = {for (final k in pmStageKeys) x('pm-set-stage-$k-due')};
    final dots = {for (final k in pmStageKeys) x('pm-set-stage-$k-dot')};
    expect(scheduled, hasLength(1));
    expect(due, hasLength(1));
    expect(dots, hasLength(1));
    expect(due.single, greaterThan(scheduled.single));
    expect(find.byKey(const ValueKey('pm-set-stage-head')), findsOneWidget);
  });

  testWidgets('a Scheduled segment writes the same jobs.pm_stages shape', (
    tester,
  ) async {
    final server = await settings(tester);
    await tester.tap(
      find.byKey(const ValueKey('pm-set-stage-in_progress-scheduled-true')),
    );
    await tester.pumpAndSettle();
    final w = server.writes().single;
    expect(w.method, 'PATCH');
    expect(w.uri.path, endsWith('/settings/jobs.pm_stages'));
    final rules = ((w.body as Map)['value'] as List).cast<Map>();
    final inProgress = rules.firstWhere((r) => r['key'] == 'in_progress');
    expect(inProgress.keys.toList(), ['key', 'statuses', 'scheduled', 'due']);
    expect(inProgress['scheduled'], true);
  });

  testWidgets('stage colours are the Jobs tab chips’ colours', (tester) async {
    final server = await settings(tester);
    final inSettings = {
      for (final k in pmStageKeys) k: dot(tester, 'pm-set-stage-$k-dot'),
    };
    expect(inSettings.values.toSet(), hasLength(pmStageKeys.length));

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    expect(server.get('/jobs/project-management/jobs'), isNotEmpty);
    for (final k in pmStageKeys) {
      expect(dot(tester, 'pm-stage-$k-dot'), inSettings[k], reason: k);
    }
  });

  testWidgets('each card says once whether it is its own or from the parent', (
    tester,
  ) async {
    final server = PmServer(
      scope: const {
        'type': 'company',
        'id': 'co-1',
        'label': 'Brightway Chimney',
        'path': ['Brightway', 'Brightway Chimney'],
      },
    );
    for (final row in server.settings.values) {
      row['source'] = {
        'type': 'organization',
        'id': 'org-1',
        'name': 'Brightway',
        'inherited': true,
      };
    }
    server.settings['jobs.pm_stages']!['source'] = {
      'type': 'company',
      'id': 'co-1',
      'name': 'Brightway Chimney',
      'inherited': false,
    };
    await settings(tester, server: server);

    final stages = find.byKey(const ValueKey('tour-pm-settings-stages'));
    expect(
      find.descendant(of: stages, matching: find.text('Own settings ·')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: stages, matching: find.text('Use Brightway’s again')),
      findsOneWidget,
    );
    // Inherited cards: "From Brightway" once in the header, no link.
    for (final (card, key) in const [
      ('tour-pm-settings-reasons', 'jobs.review_reasons'),
      ('tour-pm-settings-words', 'jobs.pm_redo_words'),
      ('tour-pm-settings-warn', 'jobs.pm_warn_days'),
      ('tour-pm-settings-watched', 'jobs.estimate_aging_statuses'),
    ]) {
      final c = find.byKey(ValueKey(card));
      expect(
        find.descendant(of: c, matching: find.text('From Brightway')),
        findsOneWidget,
        reason: card,
      );
      expect(
        find.descendant(
          of: find.byKey(ValueKey('pm-set-head-$key')),
          matching: find.byKey(ValueKey('pm-set-from-$key')),
        ),
        findsOneWidget,
        reason: card,
      );
      expect(
        find.descendant(of: c, matching: find.text('Use Brightway’s again')),
        findsNothing,
        reason: card,
      );
    }
    expect(find.text('Use Brightway’s again'), findsOneWidget);
  });

  testWidgets('See all opens Jobs on paid, not started, past the limit; the '
      'chip comes off', (tester) async {
    final server = PmServer();
    await server.pump(tester, size: const Size(1440, 1600));
    final see = find.byKey(const ValueKey('pm-start-see-all'));
    await tester.ensureVisible(see);
    await tester.tap(see);
    await tester.pumpAndSettle();

    final query = server.get('/jobs/project-management/jobs').last;
    expect(query.queryParameters['waiting_to_start'], '1');
    expect(query.queryParameters['over_limit'], '1');
    expect(query.queryParameters.containsKey('stage'), isFalse);

    final chip = find.byKey(const ValueKey('pm-jobs-waiting-to-start'));
    expect(chip, findsOneWidget);
    expect(find.text('Paid, not started'), findsOneWidget);
    tester.widget<InputChip>(chip).onDeleted!();
    await tester.pumpAndSettle();
    final after = server.get('/jobs/project-management/jobs').last;
    expect(after.queryParameters.containsKey('waiting_to_start'), isFalse);
    expect(after.queryParameters['stage'], 'in_progress');
    expect(chip, findsNothing);
  });

  testWidgets('Which jobs: Any status when empty; Add and the switch write '
      'the scope keys', (tester) async {
    final server = PmServer();
    Map<String, dynamic> row(String key, Object? value) => {
      'key': key,
      'value': value,
      'may_edit': true,
      'source': {
        'type': 'organization',
        'id': 'org-1',
        'name': 'Brightway',
        'inherited': false,
      },
    };
    server.settings['jobs.pm_scope_statuses'] = row(
      'jobs.pm_scope_statuses',
      <String>[],
    );
    server.settings['jobs.pm_partly_paid_only'] = row(
      'jobs.pm_partly_paid_only',
      false,
    );
    await settings(tester, server: server);

    final card = find.byKey(const ValueKey('tour-pm-settings-scope'));
    expect(
      find.descendant(
        of: card,
        matching: find.text('Which jobs Project Management includes'),
      ),
      findsOneWidget,
    );
    expect(find.text('Any status'), findsOneWidget);
    // Above the stages.
    expect(
      tester.getTopLeft(card).dy,
      lessThan(
        tester
            .getTopLeft(find.byKey(const ValueKey('tour-pm-settings-stages')))
            .dy,
      ),
    );

    await tester.tap(
      find.byKey(const ValueKey('pm-set-jobs.pm_scope_statuses-add')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Parts ordered').last);
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('pm-set-jobs.pm_partly_paid_only')),
    );
    await tester.pumpAndSettle();

    final w = server.writes();
    expect(w, hasLength(2));
    expect(w[0].method, 'PATCH');
    expect(w[0].uri.path, endsWith('/settings/jobs.pm_scope_statuses'));
    expect((w[0].body as Map)['value'], hasLength(1));
    expect(w[1].uri.path, endsWith('/settings/jobs.pm_partly_paid_only'));
    expect((w[1].body as Map)['value'], true);
  });

  for (final size in const [Size(1440, 2600), Size(390, 5200)]) {
    testWidgets('no overflow at ${size.width.toInt()}', (tester) async {
      await settings(tester, size: size);
      expect(find.byKey(const ValueKey('pm-settings')), findsOneWidget);
      expect(tester.takeException(), isNull);
      if (size.width < 600) {
        // Phone 4: each stage a card, its rules under the statuses.
        expect(find.byKey(const ValueKey('pm-set-stage-head')), findsNothing);
        final chips = tester.getTopLeft(
          find.byKey(const ValueKey('pm-set-stage-in_progress-scheduled')),
        );
        final due = tester.getTopLeft(
          find.byKey(const ValueKey('pm-set-stage-in_progress-due')),
        );
        expect(due.dy, greaterThan(chips.dy));
      }
    });
  }
}
