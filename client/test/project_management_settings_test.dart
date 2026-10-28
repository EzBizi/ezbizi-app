import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management › Settings for the place being viewed (design 141;
/// per place since 875bb4070). Every name invented.
void main() {
  Future<PmServer> settings(
    WidgetTester tester, {
    Map<String, dynamic>? scope,
  }) async {
    final server = scope == null ? PmServer() : PmServer(scope: scope);
    await server.pump(tester, size: _tall);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    return server;
  }

  // Lists, not records: a record compares its maps by identity.
  List<List<Object?>> writes(PmServer s) => [
    for (final w in s.writes())
      [
        w.method,
        w.uri.path.split('/v1').last,
        w.uri.queryParameters,
        w.body is Map ? (w.body as Map)['value'] : w.body,
      ],
  ];

  const org = {'scope_type': 'organization', 'scope_id': 'org-1'};

  testWidgets('stage mapping writes jobs.pm_stages as JSON', (tester) async {
    final server = await settings(tester);
    expect(server.get('/settings').last.queryParameters, org);
    // In the frame's scope bar since design 144.
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('pm-scope-text'))).data,
      'Set for Brightway · its branches use these unless they set their own',
    );

    // Add Parts ordered to In progress, then say it must have money due.
    await tester.tap(
      find.byKey(const ValueKey('pm-set-stage-in_progress-add')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Parts ordered').last);
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('pm-set-stage-in_progress-due-true')),
    );
    await tester.pumpAndSettle();
    // Done, waiting payment from nothing.
    await tester.ensureVisible(
      find.byKey(const ValueKey('pm-set-stage-done_waiting_payment-add')),
    );
    await tester.tap(
      find.byKey(const ValueKey('pm-set-stage-done_waiting_payment-add')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Done').last);
    await tester.pumpAndSettle();

    expect(writes(server), [
      [
        'PATCH',
        '/settings/jobs.pm_stages',
        org,
        [
          {
            'key': 'in_progress',
            'statuses': ['in_progress', 'parts_ordered'],
            'scheduled': null,
            'due': null,
          },
        ],
      ],
      [
        'PATCH',
        '/settings/jobs.pm_stages',
        org,
        [
          {
            'key': 'in_progress',
            'statuses': ['in_progress', 'parts_ordered'],
            'scheduled': null,
            'due': true,
          },
        ],
      ],
      [
        'PATCH',
        '/settings/jobs.pm_stages',
        org,
        [
          {
            'key': 'in_progress',
            'statuses': ['in_progress', 'parts_ordered'],
            'scheduled': null,
            'due': true,
          },
          {
            'key': 'done_waiting_payment',
            'statuses': ['done'],
            'scheduled': null,
            'due': null,
          },
        ],
      ],
    ]);
  });

  testWidgets('a stage takes a second rule: + Or, its own statuses and money', (
    tester,
  ) async {
    final server = await settings(tester);
    Future<void> tap(String key) async {
      await tester.ensureVisible(find.byKey(ValueKey(key)));
      await tester.tap(find.byKey(ValueKey(key)));
      await tester.pumpAndSettle();
    }

    Object? stages() => writes(server).last.last;
    const done = {
      'key': 'done_waiting_payment',
      'statuses': ['done'],
      'scheduled': null,
      'due': null,
    };

    // No "+ Or" until the stage has a rule of its own.
    expect(
      find.byKey(const ValueKey('pm-set-stage-done_waiting_payment-or')),
      findsNothing,
    );
    await tap('pm-set-stage-done_waiting_payment-add');
    await tester.tap(find.text('Done').last);
    await tester.pumpAndSettle();
    await tap('pm-set-stage-done_waiting_payment-or');
    await tap('pm-set-stage-done_waiting_payment-1-add');
    await tester.tap(find.text('Parts ordered').last);
    await tester.pumpAndSettle();
    await tap('pm-set-stage-done_waiting_payment-1-due-true');

    expect((stages() as List).skip(1).toList(), [
      done,
      {
        'key': 'done_waiting_payment',
        'statuses': ['parts_ordered'],
        'scheduled': null,
        'due': true,
      },
    ]);

    await tap('pm-set-stage-done_waiting_payment-1-remove');
    expect((stages() as List).skip(1).toList(), [done]);
    expect(
      find.byKey(const ValueKey('pm-set-stage-done_waiting_payment-1-or')),
      findsNothing,
    );
  });

  testWidgets('reasons, words, the warning sentence, stopped and cost', (
    tester,
  ) async {
    final server = await settings(tester);

    await tester.enterText(
      find.byKey(const ValueKey('pm-set-jobs.review_reasons-add')),
      'Looking for a contractor',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    tester
        .widget<InputChip>(
          find.byKey(const ValueKey('pm-set-jobs.review_reasons-order_parts')),
        )
        .onDeleted!();
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('pm-set-jobs.pm_redo_words-add')),
      'Callback',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('pm-set-jobs.pm_dispute_words-add')),
      'chargeback',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    Future<void> type(String key, String text) async {
      final field = find.descendant(
        of: find.byKey(ValueKey('pm-set-$key')),
        matching: find.byType(TextField),
      );
      await tester.ensureVisible(field);
      await tester.enterText(field, text);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
    }

    await type('jobs.pm_warn_days', '12');
    await type('jobs.pm_warning_email', 'not-an-email');
    expect(find.text('That is not an email address.'), findsOneWidget);
    await type('jobs.pm_warning_email', 'info@brightway.example');
    await type('jobs.pm_stopped_days', '45');
    await type('jobs.pm_cost_alert_percent', '30');

    expect(writes(server), [
      [
        'PATCH',
        '/settings/jobs.review_reasons',
        org,
        [
          {'value': 'order_parts', 'label': 'Order parts'},
          {'value': 'measurements', 'label': 'Measurements'},
          {
            'value': 'looking_for_a_contractor',
            'label': 'Looking for a contractor',
          },
        ],
      ],
      [
        'PATCH',
        '/settings/jobs.review_reasons',
        org,
        [
          {'value': 'measurements', 'label': 'Measurements'},
          {
            'value': 'looking_for_a_contractor',
            'label': 'Looking for a contractor',
          },
        ],
      ],
      [
        'PATCH',
        '/settings/jobs.pm_redo_words',
        org,
        ['Redo', 'Callback'],
      ],
      [
        'PATCH',
        '/settings/jobs.pm_dispute_words',
        org,
        ['Dispute', 'chargeback'],
      ],
      ['PATCH', '/settings/jobs.pm_warn_days', org, 12],
      [
        'PATCH',
        '/settings/jobs.pm_warning_email',
        org,
        'info@brightway.example',
      ],
      ['PATCH', '/settings/jobs.pm_stopped_days', org, 45],
      ['PATCH', '/settings/jobs.pm_cost_alert_percent', org, 30],
    ]);
  });

  testWidgets('the last watched status cannot be removed', (tester) async {
    await settings(tester);
    final chip = tester.widget<InputChip>(
      find.byKey(
        const ValueKey('pm-settings-jobs.estimate_aging_statuses-review'),
      ),
    );
    expect(chip.onDeleted, isNull);
    expect(
      find.byKey(
        const ValueKey('pm-settings-jobs.estimate_aging_statuses-keep-one'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a company view writes at the company; inherited says from '
      'where; set here goes back to the parent', (tester) async {
    final server = PmServer(
      scope: const {
        'type': 'company',
        'id': 'co-1',
        'label': 'Brightway Chimney',
        'path': ['Brightway', 'Brightway Chimney'],
      },
    );
    // Everything inherited from the organization but the warning days, set
    // at the company.
    for (final row in server.settings.values) {
      row['source'] = {
        'type': 'organization',
        'id': 'org-1',
        'name': 'Brightway',
        'inherited': true,
      };
    }
    server.settings['jobs.pm_stopped_days']!['source'] = {
      'type': 'organization',
      'id': 'org-1',
      'name': 'Brightway',
      'inherited': true,
    };
    server.settings['jobs.pm_warn_days']!['source'] = {
      'type': 'company',
      'id': 'co-1',
      'name': 'Brightway Chimney',
      'inherited': false,
    };
    await server.pump(tester, size: _tall);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    const company = {'scope_type': 'company', 'scope_id': 'co-1'};
    expect(server.get('/settings').last.queryParameters, company);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('pm-scope-text'))).data,
      startsWith('Set for Brightway Chimney · '),
    );
    expect(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('pm-set-from-jobs.pm_stopped_days')),
          )
          .data,
      'From Brightway',
    );

    final back = find.byKey(const ValueKey('pm-set-inherit-jobs.pm_warn_days'));
    await tester.ensureVisible(back);
    expect(find.text('Use Brightway’s again'), findsWidgets);
    await tester.tap(back);
    await tester.pumpAndSettle();

    final field = find.descendant(
      of: find.byKey(const ValueKey('pm-set-jobs.pm_cost_alert_percent')),
      matching: find.byType(TextField),
    );
    await tester.ensureVisible(field);
    await tester.enterText(field, '20');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(writes(server), [
      ['DELETE', '/settings/jobs.pm_warn_days', company, null],
      ['PATCH', '/settings/jobs.pm_cost_alert_percent', company, 20],
    ]);
  });
}

/// Every Settings row built at once.
const _tall = Size(1400, 2600);
