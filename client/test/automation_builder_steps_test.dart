import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/automation_catalogue.dart';
import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/automation_builder.dart';
import 'package:ezbizi_client/screens/automation_steps/step_fit.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Automations builder's four steps as Jose approved them on 2026-10-22:
/// When (UI queue 130), Only if (131), Review (132) and Then (133), each with
/// its desktop option 1 and its phone option 4. Every name here is invented.
void main() {
  // ── 130 · When ───────────────────────────────────────────────────

  group('130 When', () {
    testWidgets('the rail has the sidebar icons, Most used first, and no '
        'empty module', (tester) async {
      final server = _Server();
      await _pump(tester, server);
      await _goTo(tester, 'when');

      final rail = find.byKey(const ValueKey('automation-when-rail'));
      String railText(String key) => tester
          .widget<Text>(
            find
                .descendant(
                  of: find.byKey(ValueKey('automation-when-category-$key')),
                  matching: find.byType(Text),
                )
                .first,
          )
          .data!;

      expect(railText('_most'), 'Most used');
      expect(
        tester
            .getTopLeft(
              find.byKey(const ValueKey('automation-when-category-_most')),
            )
            .dy,
        lessThan(
          tester
              .getTopLeft(
                find.byKey(const ValueKey('automation-when-category-hr')),
              )
              .dy,
        ),
      );
      // The sidebar's own icon for the module, read from the same registry.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-when-category-jobs')),
          matching: find.byIcon(moduleScreens['jobs']!.icon),
        ),
        findsOneWidget,
      );
      // Leads declares no triggers, so it has no entry.
      expect(
        find.descendant(of: rail, matching: find.text('Leads')),
        findsNothing,
      );

      // Most used: the busiest first, with the card footer.
      final busiest = find.byKey(
        const ValueKey('automation-when-card-job.status_changed'),
      );
      expect(busiest, findsOneWidget);
      expect(
        find.descendant(of: busiest, matching: find.text('118 in 30 days')),
        findsOneWidget,
      );

      // A module's cards: count, rules, and never-raised last and marked.
      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-category-hr')),
      );
      final sent = find.byKey(
        const ValueKey('automation-when-card-invitation.sent'),
      );
      expect(
        find.descendant(of: sent, matching: find.text('72 in 30 days')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: sent, matching: find.text('2 rules')),
        findsOneWidget,
      );
      final expired = find.byKey(
        const ValueKey('automation-when-card-invitation.expired'),
      );
      expect(
        find.descendant(of: expired, matching: find.text('Not raised yet')),
        findsOneWidget,
      );
      expect(
        tester.getTopLeft(expired).dy,
        greaterThan(
          tester
              .getTopLeft(
                find.byKey(
                  const ValueKey('automation-when-card-invitation.accepted'),
                ),
              )
              .dy,
        ),
        reason: 'never raised sorts last',
      );
      expect(
        tester
            .widget<Opacity>(
              find.ancestor(of: expired, matching: find.byType(Opacity)).first,
            )
            .opacity,
        lessThan(1),
      );
    });

    testWidgets('the details panel is drawn at once and fills in without '
        'moving', (tester) async {
      Map<String, dynamic>? opened;
      final server = _Server();
      await _pump(tester, server, onOpenRule: (rule) => opened = rule);
      await _goTo(tester, 'when');

      // Before anything is chosen: every section, with placeholders.
      for (final key in ['uses', 'last', 'rules', 'paired']) {
        expect(find.byKey(ValueKey('automation-when-$key')), findsOneWidget);
      }
      final before = tester.getTopLeft(
        find.byKey(const ValueKey('automation-picked-trigger')),
      );

      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-category-hr')),
      );
      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-card-invitation.accepted')),
      );

      final panel = find.byKey(const ValueKey('automation-picked-trigger'));
      expect(tester.getTopLeft(panel), before, reason: 'no layout jump');
      expect(
        find.descendant(
          of: panel,
          matching: find.text('The person made their account.'),
        ),
        findsOneWidget,
      );
      // Plain words, and the technical id left out.
      for (final label in ['Email', 'Role', 'Where']) {
        expect(
          find.descendant(
            of: panel,
            matching: find.widgetWithText(Chip, label),
          ),
          findsOneWidget,
        );
      }
      expect(
        find.descendant(of: panel, matching: find.text('role_id')),
        findsNothing,
      );
      expect(
        find.descendant(
          of: panel,
          matching: find.textContaining('dana@brightside.example'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: panel, matching: find.text('Rules using it · 1')),
        findsOneWidget,
      );

      await _tap(tester, find.text('Welcome new technicians ›'));
      expect(opened?['id'], 'r-1');

      // Often paired with: one tap adds that Then step.
      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-pair-email.send')),
      );
      await _goTo(tester, 'then');
      expect(find.text('1 · Send an email'), findsWidgets);
    });

    testWidgets('on a phone the modules are icon chips and ⓘ opens the '
        'details in place', (tester) async {
      final server = _Server();
      await _pump(tester, server, size: const Size(390, 844));
      await _goTo(tester, 'when');

      expect(
        find.byKey(const ValueKey('automation-when-chips')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('automation-when-rail')), findsNothing);

      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-info-job.status_changed')),
      );
      expect(
        find.byKey(const ValueKey('automation-when-info-page')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('automation-picked-trigger')),
        findsOneWidget,
      );
      expect(find.text('Jobs › Status changed'), findsOneWidget);

      await _tap(
        tester,
        find.byKey(const ValueKey('automation-when-info-back')),
      );
      expect(
        find.byKey(const ValueKey('automation-when-info-page')),
        findsNothing,
      );
      expect(tester.takeException(), isNull);
    });
  });

  // ── 131 · Only if ────────────────────────────────────────────────

  group('131 Only if', () {
    testWidgets('a condition reads as a sentence, the detail menu is in plain '
        'words with an example, and ids wait under technical details', (
      tester,
    ) async {
      final server = _Server();
      await _pump(tester, server);
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'only-if');

      await _tap(tester, find.text('Add a condition'));
      await _tap(tester, find.text('Add a condition'));
      expect(find.text('Only if'), findsWidgets);
      expect(find.text('and'), findsOneWidget);

      var field = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Field').first,
      );
      expect(field.options.map((o) => o.label), [
        'Accepted on',
        'Email',
        'Open',
        'Role',
        'Where',
      ]);
      expect(
        field.options.firstWhere((o) => o.value == 'email').detail,
        'e.g. dana@brightside.example',
      );
      expect(
        field.options.firstWhere((o) => o.value == 'role').detail,
        'e.g. Technician',
        reason: 'a closed set shows the value in words',
      );

      await _tap(
        tester,
        find.byKey(const ValueKey('automation-show-technical')),
      );
      field = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Field').first,
      );
      expect(field.options.map((o) => o.value), contains('role_id'));
    });

    testWidgets('the comparisons fit the detail, each says what it means, and '
        '"changed" only where something changes', (tester) async {
      final server = _Server();
      await _pump(tester, server);
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'only-if');
      await _tap(tester, find.text('Add a condition'));
      await _pickField(tester, 'Role');

      var ops = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Is'),
      );
      expect(ops.options.map((o) => o.value), ['eq', 'neq', 'in', 'is_empty']);
      expect(ops.options.first.detail, 'Role is Technician');
      expect(ops.options.map((o) => o.value), isNot(contains('changed')));

      await _pickField(tester, 'Email');
      ops = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Is'),
      );
      expect(ops.options.map((o) => o.value), contains('contains'));
      expect(ops.options.map((o) => o.value), isNot(contains('gt')));

      // A change event offers the change comparisons.
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'only-if');
      await _pickField(tester, 'Status');
      ops = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Is'),
      );
      expect(ops.options.map((o) => o.value), contains('changed_to'));
      expect(
        ops.options.firstWhere((o) => o.value == 'changed_to').detail,
        'Status changed to Scheduled',
      );
    });

    test('operator filtering is by type, and keeps a stored operator', () {
      final all = [
        for (final key in ['eq', 'gt', 'contains', 'changed', 'is_empty'])
          CatalogueChoice(key: key, label: key),
      ];
      expect(
        operatorsFor(
          all,
          type: 'number',
          triggerKey: 'job.created',
        ).map((o) => o.key),
        ['eq', 'gt', 'is_empty'],
      );
      expect(
        operatorsFor(
          all,
          type: 'boolean',
          triggerKey: 'job.updated',
        ).map((o) => o.key),
        ['eq', 'changed', 'is_empty'],
      );
      expect(
        operatorsFor(
          all,
          type: 'enum',
          triggerKey: 'job.created',
          current: 'gt',
        ).map((o) => o.key),
        ['eq', 'gt', 'is_empty'],
      );
      expect(
        operatorsFor(
          all,
          type: null,
          triggerKey: 'job.created',
        ).map((o) => o.key),
        ['eq', 'gt', 'contains', 'is_empty'],
      );
    });

    testWidgets('the value is a list, a switch or a calendar by type, and the '
        'table adds a condition with Use', (tester) async {
      Map<String, dynamic>? asked;
      final server = _Server(onWouldHaveRun: (body) => asked = body);
      await _pump(tester, server);
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'only-if');

      // Use, from the table: a condition on Open, a yes/no, as a switch.
      final table = find.byKey(const ValueKey('automation-carries'));
      expect(
        find.descendant(
          of: table,
          matching: find.text('What this event carries · 5 (+1 technical)'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: table, matching: find.text('Springfield')),
        findsOneWidget,
      );
      await _tap(
        tester,
        find.byKey(const ValueKey('automation-use-office_open')),
      );
      expect(find.byType(Switch), findsOneWidget);

      await _tap(
        tester,
        find.byKey(const ValueKey('automation-use-accepted_at')),
      );
      expect(find.byType(CalendarDayField), findsOneWidget);
      await tester.tap(find.byTooltip('Remove this condition').at(1));
      await tester.pumpAndSettle();
      expect(find.byType(CalendarDayField), findsNothing);

      await _tap(tester, find.byKey(const ValueKey('automation-use-role')));
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Value'),
      );
      await _tap(tester, find.text('Technician').last);

      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();
      expect(((asked!['conditions'] as Map)['all'] as List).first, {
        'field': 'office_open',
        'op': 'eq',
        'value': 'true',
      });
      expect(
        find.text(
          'Tested on the last 20 events (30 days): it would have run 6 times.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('on a phone the sentence stacks and nothing overflows', (
      tester,
    ) async {
      final server = _Server();
      await _pump(tester, server, size: const Size(390, 844));
      await _chooseTrigger(tester, 'hr', 'invitation.accepted', narrow: true);
      await _goTo(tester, 'only-if');
      await _tap(tester, find.text('Add a condition'));
      await _pickField(tester, 'Role');
      await _tap(tester, find.text('Add a condition'));

      final first = tester.getTopLeft(
        find.widgetWithText(SearchPickerField<String>, 'Field').first,
      );
      final op = tester.getTopLeft(
        find.widgetWithText(SearchPickerField<String>, 'Is').first,
      );
      expect(op.dy, greaterThan(first.dy), reason: 'stacked, not side by side');
      expect(tester.takeException(), isNull);
    });
  });

  // ── 132 · Review ─────────────────────────────────────────────────

  group('132 Review', () {
    testWidgets('the sentence names what is missing in red and the place '
        'once', (tester) async {
      final server = _Server();
      await _pump(tester, server);
      await _type(tester, 'Name', 'Welcome new technicians');
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _goTo(tester, 'review');

      final missing = tester.widget<Text>(
        find.descendant(
          of: find.byKey(const ValueKey('automation-sentence-then')),
          matching: find.byType(Text),
        ),
      );
      expect(missing.data, 'choose what step 1 does');
      expect(
        missing.style?.color,
        Theme.of(
          tester.element(find.byType(AutomationBuilder)),
        ).colorScheme.error,
      );
      expect(
        tester
            .widget<Text>(
              find.descendant(
                of: find.byKey(const ValueKey('automation-sentence-name')),
                matching: find.byType(Text),
              ),
            )
            .data,
        'in Springfield · Brightside, where you are now',
      );
      expect(find.textContaining('Where it applies'), findsNothing);

      // A part goes back to its step.
      await _tap(
        tester,
        find.byKey(const ValueKey('automation-sentence-then')),
      );
      expect(find.text('Then do this'), findsOneWidget);
    });

    testWidgets('a ✕ on the checklist greys Turn on and says why; readiness '
        'names a step that cannot send', (tester) async {
      final server = _Server(smsReady: false);
      await _pump(tester, server);
      await _type(tester, 'Name', 'Text the office');
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Send a notification'));
      await _type(tester, 'Message', 'A job moved');
      await _goTo(tester, 'review');

      FilledButton turnOn() => tester.widget<FilledButton>(
        find.byKey(const ValueKey('tour-automations-turn-on')),
      );
      expect(turnOn().onPressed, isNotNull);
      expect(
        find.byKey(const ValueKey('automation-check-2-ok')),
        findsOneWidget,
      );

      // A second step with nothing chosen: ✕, Fix, and Turn on greyed.
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _goTo(tester, 'review');
      expect(
        find.byKey(const ValueKey('automation-check-3-bad')),
        findsOneWidget,
      );
      expect(turnOn().onPressed, isNull);
      expect(
        tester
            .widget<FilledButton>(
              find.byKey(const ValueKey('tour-automations-save')),
            )
            .onPressed,
        isNull,
        reason: 'a step with nothing chosen cannot be saved at all',
      );
      expect(
        find.textContaining('Turn on is greyed out until'),
        findsNothing,
        reason: 'while Save itself is blocked, its reason is the one shown',
      );

      // The readiness ✕: a text step where no number can text.
      await _tap(tester, find.byKey(const ValueKey('automation-check-fix-3')));
      expect(find.text('Then do this'), findsOneWidget);
      final sms = find.byKey(
        const ValueKey('automation-step-option-phone.sms.send'),
      );
      expect(
        find.descendant(
          of: sms,
          matching: find.text('No number here can send texts yet.'),
        ),
        findsOneWidget,
        reason: 'not ready: dimmed, with the reason',
      );
      await _tap(tester, find.byTooltip('Remove this step').first);
      await _goTo(tester, 'review');
      expect(turnOn().onPressed, isNotNull);
    });

    testWidgets('Try it sends the draft to dry-run and shows the trace and '
        'each step', (tester) async {
      Map<String, dynamic>? asked;
      final server = _Server(onDryRun: (body) => asked = body);
      await _pump(tester, server);
      await _type(tester, 'Name', 'Welcome');
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'only-if');
      await _tap(tester, find.text('Add a condition'));
      await _pickField(tester, 'Role');
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Value'),
      );
      await _tap(tester, find.text('Technician').last);
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Send a notification'));
      await _type(tester, 'Message', 'Say hello');
      await _goTo(tester, 'review');

      expect(find.text('Nothing is sent or changed.'), findsOneWidget);
      await _tap(tester, find.byKey(const Key('automation-builder-try')));

      expect(asked, {
        'trigger_key': 'invitation.accepted',
        'conditions': {
          'all': [
            {'field': 'role', 'op': 'eq', 'value': 'tech'},
          ],
        },
        'actions': [
          {'type': 'notify', 'message': 'Say hello', 'role_ids': []},
        ],
      });
      final card = find.byKey(const ValueKey('automation-try'));
      expect(
        find.descendant(
          of: card,
          matching: find.text(
            'Only if: role is Technician (it was Technician)',
          ),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-try-step-0')),
          matching: find.text(
            'Would · Send a notification: Would tell 3 people: “Say hello”',
          ),
        ),
        findsOneWidget,
      );
    });

    testWidgets('Turn on saves the new rule and switches it on', (
      tester,
    ) async {
      Map<String, dynamic>? created;
      Map<String, dynamic>? patched;
      final server = _Server(
        onCreate: (body) => created = body,
        onPatch: (body) => patched = body,
      );
      var saved = false;
      await _pump(tester, server, onSaved: () => saved = true);
      await _type(tester, 'Name', 'Tell the office');
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Send a notification'));
      await _type(tester, 'Message', 'Moved');
      await _goTo(tester, 'review');
      await _tap(
        tester,
        find.byKey(const ValueKey('tour-automations-turn-on')),
      );

      expect(created?['trigger_key'], 'job.status_changed');
      expect(patched, {'is_active': true});
      expect(saved, isTrue);
    });

    testWidgets('on a phone Review fits, Try it and Turn on included', (
      tester,
    ) async {
      final server = _Server();
      await _pump(tester, server, size: const Size(390, 844));
      await _type(tester, 'Name', 'Welcome');
      await _chooseTrigger(tester, 'hr', 'invitation.accepted', narrow: true);
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _goTo(tester, 'review');

      expect(
        find.byKey(const ValueKey('automation-checklist')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('automation-builder-try')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('tour-automations-turn-on')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    });
  });

  // ── 133 · Then ───────────────────────────────────────────────────

  group('133 Then', () {
    testWidgets('only the steps that fit, once each, in four groups', (
      tester,
    ) async {
      final server = _Server();
      await _pump(tester, server);
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));

      for (final group in ['_record', '_message', '_team', '_elsewhere']) {
        expect(
          find.byKey(ValueKey('automation-step-group-$group')),
          findsOneWidget,
        );
      }
      expect(find.text('FOR THIS JOB'), findsOneWidget);
      // Once, though the generic list and Jobs both declare it.
      expect(find.text('Update a field'), findsOneWidget);
      expect(find.text('Change the status'), findsOneWidget);
      // Steps about other records are not listed.
      expect(find.text('Send the invitation again'), findsNothing);
      expect(find.text('Mark a voicemail dealt with'), findsNothing);

      // A trigger about no record: its own steps, and no Update a field.
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'then');
      expect(find.text('FOR THIS EVENT'), findsOneWidget);
      expect(find.text('Send the invitation again'), findsOneWidget);
      expect(find.text('Update a field'), findsNothing);
      expect(find.text('Change the status'), findsNothing);
    });

    test('a step fits by the server list where there is one, else by the '
        'audit', () {
      final catalogue = AutomationCatalogue.fromJson(_catalogue);
      bool fits(String type, String trigger) => stepFits(
        catalogue,
        type: type,
        triggerKey: trigger,
        definition: catalogue.actionType(type),
      );

      expect(fits('voicemail.mark_dealt', 'job.status_changed'), isFalse);
      expect(fits('update_field', 'invitation.accepted'), isFalse);
      expect(fits('update_field', 'job.status_changed'), isTrue);
      expect(fits('notify', 'invitation.accepted'), isTrue);
      expect(fits('resend', 'invitation.accepted'), isTrue);
      expect(fits('resend', 'job.status_changed'), isFalse);
      expect(parameterFits('to_assignee', 'job'), isFalse);
      expect(parameterFits('to_job_technicians', 'job'), isTrue);
    });

    testWidgets('Update a field: the detail by name, then a value control by '
        'kind, and a green sentence', (tester) async {
      Map<String, dynamic>? created;
      final server = _Server(onCreate: (body) => created = body);
      await _pump(tester, server);
      await _type(tester, 'Name', 'Done means pending');
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Update a field'));

      expect(find.text("Set the job's"), findsOneWidget);
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Field'),
      );
      await _tap(tester, find.text('Status').last);
      final value = tester.widget<SearchPickerField<String>>(
        find.widgetWithText(SearchPickerField<String>, 'Set to'),
      );
      expect(value.options.map((o) => o.label), [
        'Scheduled',
        'Done, pending approval',
      ]);
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Set to'),
      );
      await _tap(tester, find.text('Done, pending approval').last);

      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-step-says-0')),
          matching: find.text(
            "Sets the job's status to “Done, pending approval”.",
          ),
        ),
        findsOneWidget,
      );

      // A money field is typed in dollars and sent in cents.
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Field'),
      );
      await _tap(tester, find.text('Amount').last);
      await tester.enterText(
        find.widgetWithText(TextField, 'Set to'),
        '199.99',
      );
      await tester.pumpAndSettle();
      expect(find.text("Sets the job's amount to “\$199.99”."), findsOneWidget);

      await _goTo(tester, 'review');
      await _tap(tester, find.byKey(const ValueKey('tour-automations-save')));
      expect(created?['actions'], [
        {'type': 'update_field', 'field': 'amount_minor', 'value': '19999'},
      ]);
    });

    testWidgets('Change the status picks from the record’s statuses', (
      tester,
    ) async {
      Map<String, dynamic>? created;
      final server = _Server(onCreate: (body) => created = body);
      await _pump(tester, server);
      await _type(tester, 'Name', 'Move it');
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Change the status'));
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Move it to'),
      );
      await _tap(tester, find.text('Scheduled').last);
      expect(find.text('Moves the job to “Scheduled”.'), findsOneWidget);

      await _goTo(tester, 'review');
      await _tap(tester, find.byKey(const ValueKey('tour-automations-save')));
      expect(created?['actions'], [
        {'type': 'change_status', 'value': 'scheduled'},
      ]);
    });

    testWidgets('a refusal about one step is shown on that step', (
      tester,
    ) async {
      final server = _Server(refuseValue: true);
      await _pump(tester, server);
      await _type(tester, 'Name', 'Move it');
      await _chooseTrigger(tester, 'jobs', 'job.status_changed');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Change the status'));
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Move it to'),
      );
      await _tap(tester, find.text('Scheduled').last);
      await _goTo(tester, 'review');
      await _tap(tester, find.byKey(const ValueKey('tour-automations-save')));

      expect(
        find.byKey(const ValueKey('automation-check-2-bad')),
        findsOneWidget,
      );
      await _goTo(tester, 'then');
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-step-error-0')),
          matching: find.text('"scheduled" is not one of the choices: Done.'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('placeholders are plain-named chips and the preview fills them '
        'from the last event', (tester) async {
      final server = _Server();
      await _pump(tester, server);
      await _chooseTrigger(tester, 'hr', 'invitation.accepted');
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Send a notification'));

      await tester.enterText(find.widgetWithText(TextField, 'Message'), 'Hi ');
      await tester.pumpAndSettle();
      await _tap(tester, find.widgetWithText(ActionChip, '+ Email').first);

      expect(
        tester
            .widget<TextField>(find.widgetWithText(TextField, 'Message'))
            .controller
            ?.text,
        'Hi {{email}}',
      );
      expect(
        find.text('Preview with the last event: “Hi dana@brightside.example”'),
        findsOneWidget,
      );
      expect(
        find.text(
          'Sends a notification to everybody who can see it: '
          '“Hi dana@brightside.example”.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('on a phone the steps are a list, one opens with a way back, '
        'and nothing overflows', (tester) async {
      final server = _Server();
      await _pump(tester, server, size: const Size(390, 844));
      await _chooseTrigger(tester, 'jobs', 'job.status_changed', narrow: true);
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Update a field'));
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Field'),
      );
      await _tap(tester, find.text('Status').last);
      expect(tester.takeException(), isNull);

      await _tap(tester, find.byKey(const ValueKey('automation-then-back')));
      expect(
        find.byKey(const ValueKey('automation-then-step-0')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    });
  });
}

// ── driving the builder ────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  _Server server, {
  Size size = const Size(1440, 2400),
  void Function(Map<String, dynamic>)? onOpenRule,
  VoidCallback? onSaved,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AutomationBuilder(
          api: ApiClient(client: MockClient(server.handle)),
          catalogue: AutomationCatalogue.fromJson(_catalogue),
          formats: DisplayFormats.fallback,
          onSaved: onSaved ?? () {},
          onCancel: () {},
          rules: const [
            {
              'id': 'r-1',
              'name': 'Welcome new technicians',
              'trigger_key': 'invitation.accepted',
            },
          ],
          onOpenRule: onOpenRule,
          viewPlace: 'franchise:f-1',
          viewLabel: 'Springfield · Brightside',
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder.first);
  await tester.pumpAndSettle();
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

Future<void> _type(WidgetTester tester, String label, String text) async {
  await tester.enterText(find.widgetWithText(TextField, label).first, text);
  await tester.pumpAndSettle();
}

Future<void> _goTo(WidgetTester tester, String step) =>
    _tap(tester, find.byKey(ValueKey('automation-step-$step')));

Future<void> _chooseTrigger(
  WidgetTester tester,
  String module,
  String trigger, {
  bool narrow = false,
}) async {
  await _goTo(tester, 'when');
  if (narrow) {
    await _tap(
      tester,
      find.descendant(
        of: find.byKey(const ValueKey('automation-when-chips')),
        matching: find.text(module == 'hr' ? 'Human Resources' : 'Jobs'),
      ),
    );
  } else {
    await _tap(
      tester,
      find.byKey(ValueKey('automation-when-category-$module')),
    );
  }
  await _tap(tester, find.byKey(ValueKey('automation-when-card-$trigger')));
}

/// Choose the last condition's detail by its plain name.
Future<void> _pickField(WidgetTester tester, String label) async {
  await _tap(
    tester,
    find.widgetWithText(SearchPickerField<String>, 'Field').last,
  );
  await _tap(tester, find.text(label).last);
}

// ── the server, as the builder sees it ─────────────────────────────

class _Server {
  _Server({
    this.onWouldHaveRun,
    this.onDryRun,
    this.onCreate,
    this.onPatch,
    this.smsReady = true,
    this.refuseValue = false,
  });

  /// Answers a save with the 422 a stale status gets (actions.0.value).
  final bool refuseValue;

  final void Function(Map<String, dynamic>)? onWouldHaveRun;
  final void Function(Map<String, dynamic>)? onDryRun;
  final void Function(Map<String, dynamic>)? onCreate;
  final void Function(Map<String, dynamic>)? onPatch;
  final bool smsReady;

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    Map<String, dynamic> body() =>
        jsonDecode(request.body) as Map<String, dynamic>;

    if (path.endsWith('/automations/trigger-stats')) {
      return _json({
        'data': {
          'invitation.sent': {
            'last_30_days': 72,
            'rules': 2,
            'top_actions': [],
          },
          'invitation.accepted': {
            'last_30_days': 40,
            'rules': 1,
            'top_actions': ['email.send', 'voicemail.mark_dealt'],
          },
          'job.status_changed': {
            'last_30_days': 118,
            'rules': 0,
            'top_actions': [],
          },
        },
      });
    }

    if (path.endsWith('/automations/trigger-fields')) {
      final trigger = request.url.queryParameters['trigger'];
      return _json({
        'data': trigger == 'job.status_changed'
            ? _jobFields
            : _invitationFields,
      });
    }

    if (path.endsWith('/automations/readiness')) {
      return _json({
        'data': {
          'sms.send': {
            'ready': smsReady,
            'why_not': smsReady ? null : 'No number here can send texts yet.',
          },
          'notify': {'ready': true, 'why_not': null},
        },
      });
    }

    if (path.endsWith('/automations/would-have-run')) {
      onWouldHaveRun?.call(body());
      return _json({
        'data': {'total': 20, 'matched': 6, 'examples': []},
      });
    }

    if (path.endsWith('/automations/dry-run')) {
      onDryRun?.call(body());
      return _json({
        'data': {
          'event_id': '0b6c8f2e-0000-4000-8000-000000000001',
          'occurred_at': '2026-10-22T13:12:00Z',
          'matched': true,
          'condition_trace': {
            'type': 'all',
            'matched': true,
            'children': [
              {
                'type': 'condition',
                'field': 'role',
                'op': 'eq',
                'expected': 'tech',
                'actual': 'tech',
                'matched': true,
              },
            ],
          },
          'would_do': [
            {
              'type': 'notify',
              'ok': true,
              'skipped': false,
              'detail': 'Would tell 3 people: “Say hello”',
            },
          ],
        },
      });
    }

    if (path.endsWith('/automations') && request.method == 'POST') {
      if (refuseValue) {
        return _json({
          'message': 'The given data was invalid.',
          'errors': {
            'actions.0.value': ['"scheduled" is not one of the choices: Done.'],
          },
        }, 422);
      }
      onCreate?.call(body());
      return _json({
        'data': {'id': 'new-1'},
      }, 201);
    }

    if (path.endsWith('/automations/new-1') && request.method == 'PATCH') {
      onPatch?.call(body());
      return _json({
        'data': {'id': 'new-1', 'is_active': true},
      });
    }

    // Phone lines, WhatsApp templates, Work Hours: nothing to offer here.
    return _json({'data': []});
  }
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _invitationFields = {
  'trigger': 'invitation.accepted',
  'fields': ['accepted_at', 'email', 'office_open', 'role', 'role_id', 'scope'],
  'labels': {
    'accepted_at': 'Accepted on',
    'email': 'Email',
    'office_open': 'Open',
    'role': 'Role',
    'role_id': 'Role',
    'scope': 'Where',
  },
  'types': {
    'accepted_at': 'date',
    'email': 'text',
    'office_open': 'boolean',
    'role': 'enum',
    'role_id': 'id',
    'scope': 'text',
  },
  'values': {
    'role': [
      {'value': 'tech', 'label': 'Technician'},
      {'value': 'office', 'label': 'Office'},
    ],
  },
  'samples': {
    'email': 'dana@brightside.example',
    'role': 'tech',
    'scope': 'Springfield',
  },
  'message_fields': [],
  'sampled': 12,
};

const _jobFields = {
  'trigger': 'job.status_changed',
  'fields': ['amount_minor', 'status'],
  'labels': {'amount_minor': 'Amount', 'status': 'Status'},
  'types': {'amount_minor': 'number', 'status': 'enum'},
  'values': {
    'status': [
      {'value': 'scheduled', 'label': 'Scheduled'},
      {'value': 'done_pending', 'label': 'Done, pending approval'},
    ],
  },
  'samples': {'status': 'scheduled'},
  'message_fields': [],
  'sampled': 30,
};

const _statusOptions = [
  {'value': 'scheduled', 'label': 'Scheduled'},
  {'value': 'done_pending', 'label': 'Done, pending approval'},
];

const _catalogue = <String, dynamic>{
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'subject': null,
      'triggers': [
        {
          'key': 'invitation.sent',
          'label': 'Invitation sent',
          'description': 'Somebody was invited to join.',
        },
        {
          'key': 'invitation.accepted',
          'label': 'Invitation accepted',
          'description': 'The person made their account.',
        },
        {
          'key': 'invitation.expired',
          'label': 'Invitation ran out',
          'description': 'Nobody accepted within a week.',
          'is_raised': false,
        },
      ],
      'declared_actions': [
        {
          'key': 'invitation.resend',
          'label': 'Send the invitation again',
          'type': 'resend',
          'implemented': true,
        },
      ],
    },
    {
      'key': 'jobs',
      'label': 'Jobs',
      'subject': {
        'key': 'job',
        'label': 'Job',
        'writable_fields': [
          {
            'key': 'status',
            'label': 'Status',
            'kind': 'enum',
            'options': _statusOptions,
          },
          {
            'key': 'amount_minor',
            'label': 'Amount',
            'kind': 'money',
            'options': null,
          },
        ],
      },
      'triggers': [
        {
          'key': 'job.status_changed',
          'label': 'Status changed',
          'description': 'A job moved from one status to another.',
        },
      ],
      'declared_actions': [
        {
          'key': 'job.update_field',
          'label': 'Update a field',
          'type': 'update_field',
          'implemented': true,
        },
        {
          'key': 'job.change_status',
          'label': 'Change the status',
          'type': 'change_status',
          'implemented': true,
        },
      ],
    },
    {
      'key': 'leads',
      'label': 'Leads',
      'subject': {'key': 'lead', 'label': 'Lead', 'writable_fields': []},
      'triggers': [],
      'declared_actions': [],
    },
    {
      'key': 'phone',
      'label': 'Communication',
      'subject': null,
      'triggers': [],
      'declared_actions': [
        {
          'key': 'phone.sms.send',
          'label': 'Send a text',
          'type': 'sms.send',
          'implemented': true,
          'subject_bound': false,
        },
        {
          'key': 'phone.voicemail.mark_dealt',
          'label': 'Mark a voicemail dealt with',
          'type': 'voicemail.mark_dealt',
          'implemented': true,
        },
      ],
    },
  ],
  'operators': [
    {'key': 'eq', 'label': 'is'},
    {'key': 'neq', 'label': 'is not'},
    {'key': 'in', 'label': 'is one of'},
    {'key': 'gt', 'label': 'is greater than'},
    {'key': 'contains', 'label': 'contains'},
    {'key': 'is_empty', 'label': 'is empty'},
    {'key': 'changed', 'label': 'changed'},
    {'key': 'changed_to', 'label': 'changed to'},
  ],
  'action_types': [
    {'key': 'update_field', 'label': 'Update a field'},
    {'key': 'webhook', 'label': 'Call a webhook'},
    {
      'key': 'change_status',
      'label': 'Change the status',
      'parameters': [
        {
          'key': 'value',
          'label': 'Move it to',
          'kind': 'status',
          'required': true,
        },
      ],
    },
    {
      'key': 'notify',
      'label': 'Send a notification',
      'parameters': [
        {
          'key': 'message',
          'label': 'Message',
          'kind': 'rich_text',
          'required': true,
          'variables': true,
        },
        {'key': 'role_ids', 'label': 'Only tell these roles', 'kind': 'roles'},
      ],
    },
    {
      'key': 'email.send',
      'label': 'Send an email',
      'parameters': [
        {
          'key': 'to_field',
          'label': 'Send to',
          'kind': 'recipient',
          'required': true,
        },
        {
          'key': 'subject',
          'label': 'Subject',
          'kind': 'text',
          'required': true,
          'variables': true,
        },
        {
          'key': 'body',
          'label': 'Message',
          'kind': 'rich_text',
          'required': true,
          'variables': true,
        },
      ],
    },
    {
      'key': 'sms.send',
      'label': 'Send a text',
      'module_only': true,
      'parameters': [
        {
          'key': 'body',
          'label': 'Message',
          'kind': 'rich_text',
          'required': true,
        },
      ],
    },
    {
      'key': 'voicemail.mark_dealt',
      'label': 'Mark a voicemail dealt with',
      'subjects': ['PhoneCall'],
    },
    {
      'key': 'resend',
      'label': 'Send the invitation again',
      'module_only': true,
      'parameters': [],
    },
  ],
  'roles': [],
  'people': [],
  'timezones': [],
  'schedule_kinds': [],
};
