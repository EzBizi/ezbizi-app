import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/automations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Automations screen, after the builder came out of the side panel.
///
/// ── What the owner was looking at ────────────────────────────────
///
/// A drawer over the list, containing a name box and one dropdown holding
/// twenty raw event keys — `customer.owner_reassigned`, `job.status_changed` —
/// with nothing anywhere to say what any of them meant. Their words: "we barely
/// started and we already have a lot of actions, so they need to be separated
/// by category".
///
/// So the assertions here are about what a person can actually read and reach:
/// that the two jobs are two tabs, that an event shows its name and its
/// explanation rather than its key, that an action the engine cannot run says
/// so and refuses to be picked, and that the payload the form has always sent
/// is still the payload it sends. That last one matters most — a redesign that
/// quietly changed what a rule means would be far worse than the dropdown.
void main() {
  testWidgets('the list and the builder are two tabs, and switching works', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Your automations'), findsOneWidget);
    expect(find.text('Build'), findsOneWidget);

    // The list is what you land on.
    expect(find.text('Chase completions'), findsOneWidget);
    expect(find.text('New automation'), findsOneWidget);

    await _tap(tester, find.text('Build'));

    // The list is gone, and so is the header button that belongs to it — the
    // builder has its own footer.
    expect(find.text('Chase completions'), findsNothing);
    expect(find.text('New automation'), findsNothing);
    expect(find.text('Name it'), findsOneWidget);
    expect(find.text('When this happens'), findsOneWidget);
    expect(find.text('Then do this'), findsOneWidget);

    await _tap(tester, find.text('Your automations'));

    expect(find.text('Chase completions'), findsOneWidget);
  });

  /// Leads is a module of its own that the app draws inside Marketing, and the
  /// builder groups its events where somebody just navigated to them — rather
  /// than under a heading for a tab that no longer exists on its own.
  testWidgets('a module drawn inside another is grouped under it', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithNesting));
    await _tap(tester, find.text('Build'));

    expect(find.text('Marketing › Leads'), findsOneWidget);
    expect(
      find.text('Leads'),
      findsNothing,
      reason: 'A bare heading says it is a tab of its own, and it is not.',
    );

    // A top-level module is untouched — the path is where it is drawn, not
    // decoration applied to every row.
    expect(find.text('Customers'), findsOneWidget);
  });

  /// The same person may hold `leads.view` and not `marketing.view`, and the
  /// catalogue only carries what they can reach. Naming a tab they cannot open
  /// would be worse than not naming one at all.
  testWidgets(
    'a child whose parent is out of reach is grouped by its own name',
    (tester) async {
      await _pump(tester, _handler(catalogue: _catalogueWithOrphanedChild));
      await _tap(tester, find.text('Build'));

      expect(find.text('Leads'), findsOneWidget);
      expect(find.text('Marketing › Leads'), findsNothing);
    },
  );

  testWidgets('events are grouped by category and named, never shown as keys', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    // The categories, as a list beside the events.
    expect(find.text('Customers'), findsOneWidget);
    expect(find.text('Jobs'), findsOneWidget);

    // The first category's events, with their explanations.
    expect(find.text('Owner reassigned'), findsOneWidget);
    expect(
      find.text('Accountability for a customer moved to another franchise.'),
      findsOneWidget,
    );

    expect(
      find.text('customer.owner_reassigned'),
      findsNothing,
      reason: 'The raw event key was shown instead of its name.',
    );

    // The other category is one click away, not twenty rows down a dropdown.
    expect(find.text('Status changed'), findsNothing);
    await _tap(tester, find.text('Jobs'));
    expect(find.text('Status changed'), findsOneWidget);

    await _tap(tester, find.text('Status changed'));
    expect(find.text('Chosen: Jobs › Status changed'), findsOneWidget);
  });

  testWidgets('an action the engine cannot run is shown, marked and refused', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _tap(tester, find.text('Created'));
    await _tap(tester, find.text('Add a step'));

    // Everything the engine runs, plus what the modules mean to offer.
    expect(find.text('Send a notification'), findsOneWidget);

    // Scoped to the step's own card: the trigger picker one section up has a
    // category list of its own, and it is the first 'Jobs' in the tree.
    await _tap(tester, _inStep(find.text('Jobs')));

    expect(find.text('Change the status'), findsOneWidget);
    expect(
      find.text('Not built yet'),
      findsOneWidget,
      reason: 'An unimplemented action was listed with nothing to mark it.',
    );

    // Tapping it must do nothing at all — the API would refuse the save, and
    // finding that out after filling the form in is the failure the mark exists
    // to prevent.
    await tester.tap(find.text('Change the status'), warnIfMissed: false);
    await tester.pumpAndSettle();

    expect(find.text('Choose what this step does'), findsOneWidget);
    expect(find.text('One of the steps has no action chosen.'), findsOneWidget);
    expect(_saveButton(tester).onPressed, isNull);
  });

  /// ── The field, and the record it belongs to ──────────────────────
  ///
  /// It was a free-text box, and the server took whatever was in it:
  /// `organization_id` on a job trigger saved with a 201 and was then refused
  /// by the engine on every run, forever. The options are the SUBJECT's — the
  /// record the trigger is about — because that is what `update_field` writes
  /// to, whichever module's action was chosen from the list.
  testWidgets(
    'the field offers the subject’s fields, by name, and sends keys',
    (tester) async {
      Map<String, dynamic>? sent;

      await _pump(tester, _handler(onCreate: (body) => sent = body));
      await _tap(tester, find.text('Build'));

      await _type(tester, 'Name', 'Close it');
      await _tap(tester, find.text('Jobs'));
      await _tap(tester, find.text('Status changed'));

      await _tap(tester, find.text('Add a step'));
      await _tap(tester, _inStep(find.text('Update a field')));

      // The button inside the form field, which is where the options live.
      final field = tester.widget<DropdownButton<String>>(
        find.byType(DropdownButton<String>),
      );

      // The job's fields and only the job's — `email` is a customer's, and the
      // engine would have refused it every time.
      expect(field.items!.map((i) => i.value), ['status', 'scheduled_for']);

      // And read as words. "scheduled_for" is a wire name, and the person
      // choosing runs a chimney business.
      expect(field.items!.map((i) => (i.child as Text).data), [
        'Status',
        'Date and time',
      ]);

      await _tap(tester, find.byType(DropdownButtonFormField<String>));
      await _tap(tester, find.text('Date and time').last);
      await _type(tester, 'Set to', '2026-09-01');

      await _tap(tester, find.text('Create automation'));

      // Names on screen, keys on the wire.
      expect(sent, {
        'name': 'Close it',
        'description': null,
        'trigger_key': 'job.status_changed',
        'conditions': null,
        'actions': [
          {
            'type': 'update_field',
            'field': 'scheduled_for',
            'value': '2026-09-01',
          },
        ],
      });
    },
  );

  testWidgets('with nothing to react to chosen yet, the step says so', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    // An empty dropdown is a dead end that explains nothing.
    expect(find.byType(DropdownButtonFormField<String>), findsNothing);
    expect(
      find.textContaining('Choose what this reacts to first'),
      findsOneWidget,
    );
  });

  testWidgets('a record no rule may change refuses the step, and says why', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _tap(tester, find.text('Companies'));
    await _tap(tester, find.text('Opened'));
    await _tap(tester, find.text('Add a step'));

    expect(find.text('Nothing to set here'), findsOneWidget);
    expect(
      find.textContaining('a rule may not change a company'),
      findsOneWidget,
    );

    await tester.tap(
      _inStep(find.text('Update a field')).first,
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(find.text('Choose what this step does'), findsOneWidget);
  });

  /// The other half of the same defect.
  ///
  /// "Customers › Update a field" was offered under a job trigger, enabled,
  /// with a description promising to set a field on the customer — and
  /// `ActionRunner::updateField` writes to the EVENT's subject, so it wrote to
  /// the job. Same mechanism as "Not built yet", because it is the same kind of
  /// answer: shown, marked, and not selectable.
  testWidgets('an action about another record is shown, marked and refused', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Customers')));

    expect(find.text('Update a field on the customer'), findsOneWidget);
    expect(find.text('Not for this trigger'), findsOneWidget);
    expect(
      find.textContaining('it cannot act on a customer'),
      findsOneWidget,
      reason: 'A disabled row with no reason is just a bug.',
    );

    await tester.tap(
      find.text('Update a field on the customer'),
      warnIfMissed: false,
    );
    await tester.pumpAndSettle();

    expect(find.text('Choose what this step does'), findsOneWidget);
    expect(_saveButton(tester).onPressed, isNull);
  });

  testWidgets('changing the trigger clears a field the new record lacks', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _tap(tester, find.text('Created'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    await _tap(tester, find.byType(DropdownButtonFormField<String>));
    await _tap(tester, find.text('Email').last);

    expect(_saveButton(tester).onPressed, isNotNull);

    // Now it is a job rule, and a job has no email.
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));

    // Cleared — leaving it would save a rule the server refuses — and said out
    // loud, because somebody chose it. Named the way they saw it, too: "Email",
    // not the wire key they never typed.
    expect(find.textContaining('the field “Email” on step 1'), findsOneWidget);
    expect(find.textContaining('This rule is now about a job'), findsOneWidget);

    expect(_saveButton(tester).onPressed, isNull);
    expect(find.text('Which field should the step set?'), findsOneWidget);
  });

  testWidgets(
    'changing the trigger takes back an action about the old record',
    (tester) async {
      await _pump(tester, _handler());
      await _tap(tester, find.text('Build'));

      await _type(tester, 'Name', 'Anything');
      await _tap(tester, find.text('Created'));
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, _inStep(find.text('Customers')));
      await _tap(tester, find.text('Update a field on the customer'));

      expect(
        find.text('Customers › Update a field on the customer'),
        findsOneWidget,
      );

      await _tap(tester, find.text('Jobs'));
      await _tap(tester, find.text('Status changed'));

      // The whole choice went, not just the field: the card's own header said
      // Customers while the engine would have written to the job.
      expect(
        find.textContaining(
          'step 1, “Customers › Update a field on the customer”',
        ),
        findsOneWidget,
      );
      expect(find.text('Choose what this step does'), findsOneWidget);
    },
  );

  /// "No fields" and "I cannot tell" are different answers.
  ///
  /// A server that does not publish subjects — one older than this build, or a
  /// module that declares no record — must not dead-end the form. Refusing to
  /// offer a list is honest; refusing to let anything be typed would strand an
  /// edit over a rule that has been running for months.
  testWidgets('a server that publishes no subject degrades to a text box', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        catalogue: _catalogueWithoutSubjects(),
        onCreate: (body) => sent = body,
      ),
    );
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Close it');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    expect(find.byType(DropdownButtonFormField<String>), findsNothing);
    expect(find.textContaining('cannot offer the list'), findsOneWidget);

    await _type(tester, 'Field', 'status');
    await _type(tester, 'Set to', 'completed');

    expect(_saveButton(tester).onPressed, isNotNull);
    await _tap(tester, find.text('Create automation'));

    expect(sent!['actions'], [
      {'type': 'update_field', 'field': 'status', 'value': 'completed'},
    ]);
  });

  /// The finding, in one screen: `Me.can` had ONE call site in ~28,000 lines.
  ///
  /// So the client downloaded the permission set on every /me and consulted it
  /// nowhere. A view-only role was offered New, the switch on every row and
  /// Delete, and learnt what it could not do by pressing each one and reading
  /// "This action is unauthorized."
  group('a view-only role', () {
    testWidgets('is refused the controls it cannot use, and told why', (
      tester,
    ) async {
      await _pump(tester, _handler(permissions: const ['automations.view']));

      // Still on screen. Hiding it is not compliance — somebody looking for
      // "New automation" and not finding it concludes the app is broken.
      final create = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'New automation'),
      );
      expect(create.onPressed, isNull);

      expect(
        find.byTooltip(
          'You do not have permission to build an automation. That is part of a '
          'role rather than of an account, so somebody who can edit roles can '
          'add it to yours.',
        ),
        findsOneWidget,
        reason:
            'A dead button with no explanation is indistinguishable from a bug.',
      );

      // The switch is `automations.edit`, the same authority as opening the
      // builder — see AutomationPolicy.
      final live = tester.widget<Switch>(find.byType(Switch));
      expect(live.onChanged, isNull);
    });

    testWidgets('sees the reason inside the row menu, not a grey line', (
      tester,
    ) async {
      await _pump(tester, _handler(permissions: const ['automations.view']));

      await _tap(tester, find.byType(PopupMenuButton<String>));

      // Every entry still listed. Run history is `automations.view` and stays
      // usable; the rest say which grant is missing. Rehearsing is `update`
      // rather than `view` — see AutomationsController::test — so it is refused
      // with the same sentence as opening the builder, which is why that one
      // appears twice.
      expect(find.text('Open and edit'), findsOneWidget);
      expect(find.text('Run history'), findsOneWidget);
      expect(find.text('Test against a real event'), findsOneWidget);
      expect(
        find.textContaining('permission to change an automation'),
        findsNWidgets(2),
      );
      expect(
        find.textContaining('permission to delete an automation'),
        findsOneWidget,
      );
    });

    testWidgets('cannot reach the builder by opening the tab directly', (
      tester,
    ) async {
      await _pump(tester, _handler(permissions: const ['automations.view']));

      await _tap(tester, find.text('Build'));

      // Gating the button alone would leave a whole form to fill in and a
      // refusal at the save, which is the worst moment to find out.
      expect(find.text('Name it'), findsNothing);
      expect(
        find.textContaining('permission to build an automation'),
        findsOneWidget,
      );
    });
  });

  testWidgets('the condition editor still sends the payload it always did', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(tester, _handler(onCreate: (body) => sent = body));

    await _tap(tester, find.text('Build'));

    await _tap(tester, find.text('Created'));

    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'status');
    await _type(tester, 'Value', 'completed');

    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'It is done');

    // Typing the name LAST used to leave the button dead, because nothing
    // recomputed whether the form was complete once it had been drawn.
    expect(_saveButton(tester).onPressed, isNull);
    await _type(tester, 'Name', 'Chase completions');
    expect(_saveButton(tester).onPressed, isNotNull);

    await _tap(tester, find.text('Create automation'));

    expect(sent, {
      'name': 'Chase completions',
      'description': null,
      'trigger_key': 'customer.created',
      'conditions': {
        'all': [
          {'field': 'status', 'op': 'eq', 'value': 'completed'},
        ],
      },
      'actions': [
        {'type': 'notify', 'message': 'It is done'},
      ],
    });
  });

  testWidgets('a condition with no field cannot be saved', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _tap(tester, find.text('Created'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'Something');

    expect(_saveButton(tester).onPressed, isNotNull);

    // An empty clause saved happily and produced a rule that failed every
    // single run, because the evaluator refuses a condition with no field.
    await _tap(tester, find.text('Add a condition'));

    expect(
      find.text(
        'One of the conditions has no field, so the rule could never run.',
      ),
      findsOneWidget,
    );
    expect(_saveButton(tester).onPressed, isNull);
  });

  testWidgets('the field box offers what the event carries, and keeps what it '
      'does not', (tester) async {
    // The customer's rule: an email whose payload carries `from_address` and
    // `subject`, and a condition somebody wrote as `Status` before there was a
    // list to read.
    await _pump(
      tester,
      _handler(triggerFields: const ['from_address', 'received_at', 'subject']),
    );
    await _tap(tester, find.text('Build'));
    // The fields belong to the EVENT, so there is nothing to offer until one is
    // chosen — which is also why the box explains itself rather than sitting
    // empty before that.
    await _tap(tester, find.text('Created'));
    await _tap(tester, find.text('Add a condition'));

    // A chooser, not a text box. The free box is what let `Status` be typed at
    // all, and the rule then skipped every run saying only "the value at the
    // time was nothing".
    expect(find.byType(DropdownMenu<String>), findsOneWidget);

    await _tap(tester, find.widgetWithText(DropdownMenu<String>, 'Field'));

    for (final field in ['from_address', 'received_at', 'subject']) {
      expect(find.text(field), findsWidgets, reason: '$field should be offered');
    }

    await _tap(tester, find.text('subject').last);

    expect(
      tester
          .widget<DropdownMenu<String>>(find.byType(DropdownMenu<String>))
          .controller
          ?.text,
      'subject',
    );
  });

  testWidgets('a field the list does not offer is kept, not dropped', (
    tester,
  ) async {
    // The list is what recent events CARRIED, so a real field can be missing
    // because the office was quiet. Silently emptying the box would turn a
    // correct rule into one with no field the next time somebody saved it.
    await _pump(tester, _handler(triggerFields: const ['subject']));
    await _tap(tester, find.text('Build'));
    await _tap(tester, find.text('Created'));
    await _tap(tester, find.text('Add a condition'));

    await tester.enterText(
      find.descendant(
        of: find.byType(DropdownMenu<String>),
        matching: find.byType(TextField),
      ),
      'from_address',
    );
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<DropdownMenu<String>>(find.byType(DropdownMenu<String>))
          .controller
          ?.text,
      'from_address',
    );
    expect(find.text('Not seen on this event lately'), findsOneWidget);
  });

  testWidgets('deleting a condition takes its text with it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'first');

    await _tap(tester, find.text('Add a condition'));
    await tester.enterText(
      find.widgetWithText(TextField, 'Field').at(1),
      'second',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.byTooltip('Remove this condition').first);

    // The rows used to be redrawn from a list of plain strings, so the field
    // state stayed where it sat: deleting the first left the second wearing the
    // first one's text while the payload carried the second one's.
    final remaining = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Field'),
    );
    expect(remaining.controller?.text, 'second');
  });

  testWidgets('a run says which clause failed, and why a failure failed', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Run history'));

    // The server has always sent the trace; the screen printed a fixed
    // sentence and threw the trace away.
    expect(find.text('Conditions did not match.'), findsNothing);
    expect(find.text('status is “completed”'), findsOneWidget);
    expect(find.text('the value at the time was “scheduled”'), findsOneWidget);

    expect(
      find.text('The webhook returned 500.'),
      findsOneWidget,
      reason: 'A failed run showed no reason, though the server sent one.',
    );

    expect(
      find.textContaining('job.status_changed'),
      findsNothing,
      reason: 'The run log named the event by its key rather than its name.',
    );
  });

  testWidgets('a saved rule can be read back, and its trigger cannot move', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(tester, _handler(onUpdate: (body) => sent = body));

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Open and edit'));

    // Until this existed there was no way to see what a saved rule did at all.
    expect(find.textContaining('Editing “Chase completions”'), findsOneWidget);
    expect(find.text('Jobs › Status changed'), findsOneWidget);

    // "is one of" could not be expressed by the old form and so could not be
    // read back by it either: one text box against a stored list.
    final values = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Values'),
    );
    expect(values.controller?.text, 'completed, invoiced');

    expect(find.text('Version 1'), findsOneWidget);
    expect(find.text('In use'), findsOneWidget);

    await _type(tester, 'Message', 'Everyone should know.');
    await _tap(tester, find.text('Save changes'));

    expect(sent, {
      'name': 'Chase completions',
      'description': 'Tell the office when a job finishes.',
      // Sent back as the list it was stored as, not as one comma-joined
      // string that would match nothing.
      'conditions': {
        'all': [
          {
            'field': 'status',
            'op': 'in',
            'value': ['completed', 'invoiced'],
          },
        ],
      },
      'actions': [
        {'type': 'notify', 'message': 'Everyone should know.'},
      ],
    });

    expect(
      sent!.containsKey('trigger_key'),
      isFalse,
      reason:
          'The API refuses a changed trigger, so the form must not send one.',
    );
  });

  testWidgets('a rule holding a masked secret is not saved back over it', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(detail: _detailWithSecret, onUpdate: (body) => sent = body),
    );

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Open and edit'));

    expect(find.textContaining('signing secret'), findsOneWidget);

    // The name is still editable, and saving it must not carry the version's
    // contents with it — the API hands secrets back masked, so re-sending them
    // would write eight bullets over a live secret.
    await _type(tester, 'Name', 'Chase completions, renamed');
    await _tap(tester, find.text('Save changes'));

    expect(sent, {
      'name': 'Chase completions, renamed',
      'description': 'Tell the office when a job finishes.',
    });
  });

  testWidgets('an older version can be put back', (tester) async {
    String? activated;

    await _pump(tester, _handler(onActivate: (path) => activated = path));

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Open and edit'));
    await _tap(tester, find.text('Put this one back'));

    expect(activated, '/api/v1/automations/a-1/activate/v-1');
  });

  // The builder is the widest thing in the app, and a two-pane chooser is
  // exactly the sort of layout that is fine on the machine it was written on
  // and overflows on a laptop. 700px is below the width the picker needs for
  // its category column, so it exercises the chip row it falls back to.
  for (final width in [700.0, 1024.0, 1600.0]) {
    testWidgets('the builder lays out at ${width.toInt()}px', (tester) async {
      await _pump(tester, _handler(), size: Size(width, 1000));

      await _tap(tester, find.text('Build'));
      await _tap(tester, find.text('Add a condition'));
      await _tap(tester, find.text('Add a step'));

      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('a refused delete says so instead of looking like a success', (
    tester,
  ) async {
    await _pump(tester, _handler(deleteStatus: 403));

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Delete'));

    // The confirmation panel's own button, the menu having closed.
    await _tap(tester, find.widgetWithText(FilledButton, 'Delete'));

    expect(
      find.text('Only an administrator may delete an automation.'),
      findsOneWidget,
    );
    expect(
      find.text('Chase completions'),
      findsOneWidget,
      reason: 'The row vanished as though the delete had worked.',
    );
  });

  /// English picks an article by SOUND, not spelling.
  ///
  /// `_article` was a vowel test, and "User" is a real subject with real
  /// triggers, so the picker read "This rule reacts to something about an user,
  /// and a rule may not change an user" — twice in one sentence, on the screen
  /// whose whole purpose was to stop the product sounding like a database.
  testWidgets('a subject beginning with a vowel sound still reads correctly', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithUserSubject()));
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Greet them');
    await _tap(tester, find.text('People'));
    await _tap(tester, find.text('Invited'));
    await _tap(tester, find.text('Add a step'));

    expect(
      find.textContaining('an user'),
      findsNothing,
      reason: 'A vowel test says "an user". English does not.',
    );
    expect(find.textContaining('a user'), findsWidgets);
  });

  /// A saved rule that can never run says so, in the loud register.
  ///
  /// The picker marks an unraised trigger quietly, because there it is
  /// information helping a choice. Once the rule is SAVED the fault has
  /// happened: it is switched on, sits among the live ones, and nothing will
  /// ever set it off. That is what this register is for, and this is the
  /// surface where nobody is looking because the work felt finished.
  ///
  /// Two rules in the list on purpose — one on a raised trigger, one not — so
  /// the note has to tell them apart rather than appear on every row.
  /// ── A rule that runs on a clock ─────────────────────────────────
  ///
  /// The schedule sits inside "When this happens" because for this trigger the
  /// schedule IS what happens. The assertions are the two ways it could be
  /// wrong: offering the editor for triggers that are not the clock, and
  /// letting a weekly rule save with no day — which the server refuses, and
  /// which would otherwise be found only after a round trip.
  testWidgets('the clock trigger asks when, and refuses a week with no day', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(catalogue: _catalogueWithTheClock(), onCreate: (b) => sent = b),
    );
    await _tap(tester, find.text('Build'));

    // Not offered for an ordinary trigger.
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    expect(
      find.text('How often'),
      findsNothing,
      reason: 'Only the clock trigger has a schedule.',
    );

    await _type(tester, 'Name', 'Monday report');
    await _tap(tester, find.text('Automations'));
    await _tap(tester, find.text('A time you chose comes round'));

    expect(find.text('How often'), findsOneWidget);

    // The zone is asked for FIRST and is deliberately not defaulted: it is the
    // choice that decides whether the rule survives a clock change, and
    // defaulting it to the organization's own setting would hand everybody the
    // fixed offset that does not.
    expect(
      find.text('Choose the timezone the time is written in.'),
      findsOneWidget,
    );
    expect(_saveButton(tester).onPressed, isNull);

    // The MENU, not its label: a DropdownMenu's label is a Text inside the
    // decoration and is not a hit target, so tapping it lands on the field
    // behind and opens nothing.
    await _tap(tester, find.byType(DropdownMenu<String>));
    await _tap(tester, find.text('Eastern — New York').last);

    // Now weekly, with no day ticked — the second way this cannot come round.
    await _tap(tester, find.text('Days of the week'));
    expect(
      find.text('Pick at least one day, or this rule never comes round.'),
      findsOneWidget,
    );
    expect(_saveButton(tester).onPressed, isNull);

    await _tap(tester, find.text('Mon'));

    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Send a notification')));
    await _type(tester, 'Message', 'The weekly report is ready.');

    await _tap(tester, find.text('Create automation'));

    expect(sent, isNotNull);
    expect(sent!['trigger_key'], 'automation.schedule_reached');

    final schedule = sent!['schedule'] as Map<String, dynamic>;
    expect(schedule['kind'], 'weekly');
    expect(schedule['days'], ['monday']);
    expect(schedule['timezone'], 'America/New_York');
    expect(schedule['time'], '09:00');

    // A weekly rule must not carry a day-of-month: it would be stored, read
    // back into this form, and shown to the next editor as though it meant
    // something.
    expect(schedule.containsKey('day_of_month'), isFalse);
  });

  testWidgets('a saved rule on a trigger nothing raises says it will not run', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        catalogue: _catalogueWithAnUnraisedTrigger(),
        list: const [
          {
            'id': 'a-1',
            'name': 'Tell the office when stock arrives',
            'description': null,
            'trigger_key': 'item.received',
            'is_active': true,
            'is_live': true,
            'disabled_reason': null,
            'runs_count': 4,
            'version': 1,
          },
          {
            'id': 'a-2',
            'name': 'Reorder when we run low',
            'description': null,
            'trigger_key': 'item.low',
            'is_active': true,
            'is_live': true,
            'disabled_reason': null,
            'runs_count': 0,
            'version': 1,
          },
        ],
      ),
    );

    expect(
      find.textContaining('Nothing sets this off yet'),
      findsOneWidget,
      reason:
          'The rule on item.low can never run and has to say so. One, not two '
          '— the rule on item.received beside it is fine.',
    );
  });

  /// A trigger nothing emits looks exactly like one that fires.
  ///
  /// The description already ends with "Nothing raises this yet, so a rule
  /// built on it will not run", and that sentence says the consequence a badge
  /// cannot. But it is the tail of a paragraph: the row is indistinguishable
  /// from the twenty around it until you read to the end of one, and the rule
  /// you build on it saves, sits enabled, and never runs. No error, ever.
  ///
  /// Raised by the UI/UX session, 2026-09-08, against the precedent of five
  /// production phone lines that went to voicemail for months because the
  /// state was disclosed in grey secondary text.
  ///
  /// The row stays SELECTABLE. Refusing the tap would answer nobody's question
  /// about why an obvious event is missing; the point is to mark it, not to
  /// hide it.
  testWidgets('a trigger nothing raises yet is marked, and still choosable', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithAnUnraisedTrigger()));
    await _tap(tester, find.text('Build'));
    await _tap(tester, find.text('Inventory'));

    expect(
      find.text('Not raised yet'),
      findsOneWidget,
      reason:
          'The unraised trigger needs a marker that is not the tail of a '
          'paragraph. One, not two — the raised row beside it must not carry it.',
    );

    // And it is still a real choice: tapping it selects the trigger rather
    // than doing nothing.
    await _tap(tester, find.text('Stock is low'));

    expect(
      find.text('Stock is low'),
      findsWidgets,
      reason: 'A marked trigger is still selectable; the badge explains, it '
          'does not forbid.',
    );
  });

  /// A declared action the builder cannot render is a dead menu entry.
  ///
  /// "Send a message" is the first Phone action that is NOT about the record
  /// the event was about, so it is the first with parameters of its own — and
  /// the parameter form was gated to task actions. Choosing it would have
  /// drawn nothing, saved with no line and no destination, and failed on every
  /// run with the rule looking perfectly configured.
  ///
  /// The line is a PICKER, not a text field. Its value is an id, and a text
  /// field there asks somebody to type a uuid, which is a way of asking them
  /// to fail.
  testWidgets('Send a message offers the company lines rather than a blank box', (
    tester,
  ) async {
    Map<String, dynamic>? sent;
    await _pump(
      tester,
      _handler(catalogue: _catalogueWithSms(), onCreate: (body) => sent = body),
    );

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Text me when mail lands');
    await _tap(tester, find.text('Email'));
    await _tap(tester, find.text('An email arrived'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Phone')));
    await _tap(tester, _inStep(find.text('Send a message')));

    // The line is chosen from what the company holds.
    await _tap(tester, find.byType(DropdownButtonFormField<String>).last);
    await tester.pumpAndSettle();
    await _tap(tester, find.text('Marketing · +1 (239) 456-0829').last);

    await _type(tester, 'Send to', '+13057242199');
    await _type(tester, 'Message', 'An email just arrived.');
    await _tap(tester, find.text('Create automation'));

    expect(sent?['actions'], [
      {
        'type': 'sms.send',
        'number_id': 'num-1',
        'to': '+13057242199',
        'body': 'An email just arrived.',
      },
    ]);
  });

  /// A yes/no parameter is a switch, and OFF is an answer.
  ///
  /// Two failures in one shape, and the second is the one that would have
  /// shipped. Without a `boolean` kind it draws as a TEXT BOX somebody types
  /// "true" into. And because off is stored as the empty string, a "required"
  /// check that tests for emptiness reads a deliberate no as a blank and
  /// refuses to save — against a switch sitting there plainly answered.
  ///
  /// So this saves with the switch UNTOUCHED. Break it by deleting the boolean
  /// skip in _missingParameter and it goes red on "Mark it urgent is required."
  testWidgets('a yes/no step parameter is a switch, and leaving it off still saves', (
    tester,
  ) async {
    Map<String, dynamic>? sent;
    await _pump(
      tester,
      _handler(
        catalogue: _catalogueWithABooleanParameter(),
        onCreate: (body) => sent = body,
      ),
    );

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Text me when mail lands');
    await _tap(tester, find.text('Email'));
    await _tap(tester, find.text('An email arrived'));
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Phone')));
    await _tap(tester, _inStep(find.text('Send a message')));

    await _tap(tester, find.byType(DropdownButtonFormField<String>).last);
    await tester.pumpAndSettle();
    await _tap(tester, find.text('Marketing · +1 (239) 456-0829').last);
    await _type(tester, 'Send to', '+13057242199');
    await _type(tester, 'Message', 'An email just arrived.');

    // A switch, not a box to type "true" into.
    expect(
      find.widgetWithText(SwitchListTile, 'Mark it urgent'),
      findsOneWidget,
      reason: 'a boolean parameter drawn as a TextField asks for a typed "true"',
    );

    // Deliberately NOT touched. Off is the answer being given.
    await _tap(tester, find.text('Create automation'));

    expect(
      sent,
      isNotNull,
      reason: 'a required boolean left off is answered, not missing — it must save',
    );

    // A REAL boolean on the wire, not the text the switch stored it in. The
    // server must never be left casting a string: PHP turns '' into false and
    // the string 'false' into TRUE.
    expect((sent?['actions'] as List).first, containsPair('urgent', false));
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  // Tall by default so a form of four steps fits without every tap having to
  // scroll first. The layout tests pass real window sizes instead.
  Size size = const Size(1600, 2200),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AutomationsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// Tap something that may be below the fold of one of the several scrollables
/// on this screen.
Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

/// Type into the text box carrying a given label, and let the form react.
///
/// The pump is not optional: `enterText` updates the controller and returns
/// without a frame, so anything asserted straight afterwards would be reading
/// the tree as it was before the keystroke.
Future<void> _type(WidgetTester tester, String label, String text) async {
  await tester.enterText(find.widgetWithText(TextField, label).first, text);
  await tester.pumpAndSettle();
}

/// Scoped to an action step's card — the pickers above it hold the same
/// category names.
Finder _inStep(Finder finder) =>
    find.descendant(of: find.byType(Card), matching: finder);

FilledButton _saveButton(WidgetTester tester) => tester.widget<FilledButton>(
  find.widgetWithText(FilledButton, 'Create automation'),
);

/// A module carrying one trigger nothing emits yet, beside one that fires.
///
/// Both in the same module on purpose: the badge has to distinguish them from
/// each other, which is the whole complaint. A fixture with only the unraised
/// one would pass while marking every row.
Map<String, Object?> _catalogueWithAnUnraisedTrigger() => {
  ..._catalogue,
  'modules': [
    ...(_catalogue['modules'] as List),
    {
      'key': 'inventory',
      'label': 'Inventory',
      'subject': null,
      'triggers': [
        {
          'key': 'item.received',
          'label': 'Stock received',
          'description': 'Stock arrived at a location.',
          'is_raised': true,
        },
        {
          'key': 'item.low',
          'label': 'Stock is low',
          'description':
              'On-hand is at or below the reorder point. Nothing raises this '
              'yet, so a rule built on it will not run.',
          'is_raised': false,
        },
      ],
      'declared_actions': [],
    },
  ],
};

/// The catalogue with a User subject that nothing may write to.
///
/// "User" is the one subject label in the real registry that breaks a naive
/// article rule: it begins with a vowel and takes "a". The empty writable set is
/// what routes the form into the sentence that names the subject twice.
Map<String, Object?> _catalogueWithUserSubject() => {
  ..._catalogue,
  'modules': [
    ...(_catalogue['modules'] as List),
    {
      'key': 'users',
      'label': 'People',
      'subject': {'key': 'user', 'label': 'User', 'writable_fields': []},
      'triggers': [
        {
          'key': 'user.invited',
          'label': 'Invited',
          'description': 'Somebody was invited to the organization.',
        },
      ],
      'declared_actions': [],
    },
  ],
};

/// The same catalogue with every `subject` stripped — a server older than this
/// build, during the minutes of a deploy, or a module that declares no record.
Map<String, Object?> _catalogueWithoutSubjects() => {
  ..._catalogue,
  'modules': [
    for (final module in _catalogue['modules'] as List)
      {
        for (final entry in (module as Map).entries)
          if (entry.key != 'subject') entry.key: entry.value,
      },
  ],
};

/// Leads drawn inside Marketing, which is the shape the server sends.
final _catalogueWithNesting = {
  ..._catalogue,
  'modules': [
    ...(_catalogue['modules'] as List),
    {
      'key': 'marketing',
      'label': 'Marketing',
      'parent': null,
      'subject': null,
      'triggers': [
        {
          'key': 'newsletter.sent',
          'label': 'Newsletter sent',
          'description': 'A newsletter went out.',
        },
      ],
      'declared_actions': [],
    },
    _leadsModule('marketing'),
  ],
};

/// The same, for somebody whose role does not reach Marketing itself — so it is
/// not in the catalogue at all and there is nothing to name the path after.
final _catalogueWithOrphanedChild = {
  ..._catalogue,
  'modules': [...(_catalogue['modules'] as List), _leadsModule('marketing')],
};

Map<String, Object?> _leadsModule(String? parent) => {
  'key': 'leads',
  'label': 'Leads',
  'parent': parent,
  'subject': {'key': 'lead', 'label': 'Lead', 'writable_fields': []},
  'triggers': [
    {
      'key': 'lead.received',
      'label': 'Lead received',
      'description': 'An enquiry arrived from a connected advertising account.',
    },
  ],
  'declared_actions': [],
};

/// A catalogue carrying the Phone module's "Send a message", which is the
/// first action with parameters that is NOT bound to the record the event was
/// about — the trigger here is an email arriving.
Map<String, Object?> _catalogueWithSms() => {
  ..._catalogue,
  'modules': [
    ...(_catalogue['modules'] as List),
    {
      'key': 'email',
      'label': 'Email',
      'parent': null,
      'subject': null,
      'triggers': [
        {
          'key': 'email.received',
          'label': 'An email arrived',
          'description': 'A new message reached one of the shared inboxes.',
        },
      ],
      'declared_actions': [],
    },
    {
      'key': 'phone',
      'label': 'Phone',
      'parent': null,
      'subject': null,
      'triggers': [],
      'declared_actions': [
        {
          'key': 'phone.sms.send',
          'label': 'Send a message',
          'description': 'Sends a text from one of the company phone lines.',
          'type': 'sms.send',
          'implemented': true,
        },
      ],
    },
  ],
  'action_types': [
    ...(_catalogue['action_types'] as List),
    {
      'key': 'sms.send',
      'label': 'Send a message',
      'module_only': true,
      'parameters': [
        {
          'key': 'number_id',
          'label': 'Send from',
          'kind': 'phone_number',
          'required': true,
          'nullable': false,
        },
        {
          'key': 'to',
          'label': 'Send to',
          'kind': 'text',
          'required': true,
          'nullable': false,
        },
        {
          'key': 'body',
          'label': 'Message',
          'kind': 'text',
          'required': true,
          'nullable': false,
        },
      ],
    },
  ],
};

/// The same, plus a REQUIRED boolean — the shape that breaks two ways at once.
///
/// Drawn as a text box unless the builder knows the kind, and refused at save
/// when switched off unless "required" understands that off is an answer.
Map<String, Object?> _catalogueWithABooleanParameter() {
  final catalogue = Map<String, Object?>.from(_catalogueWithSms());
  final types = List<Object?>.from(catalogue['action_types'] as List);

  final sms = Map<String, Object?>.from(
    types.firstWhere(
          (t) => (t as Map)['key'] == 'sms.send',
        )
        as Map,
  );

  sms['parameters'] = [
    ...(sms['parameters'] as List),
    {
      'key': 'urgent',
      'label': 'Mark it urgent',
      'kind': 'boolean',
      'required': true,
      'nullable': false,
    },
  ];

  types[types.indexWhere((t) => (t as Map)['key'] == 'sms.send')] = sms;
  catalogue['action_types'] = types;

  return catalogue;
}

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onUpdate,
  void Function(String path)? onActivate,
  Map<String, dynamic> detail = _detail,
  Object catalogue = _catalogue,
  List<Map<String, Object?>> list = _list,
  // Null leaves the endpoint unanswered, which is what every test written
  // before the field picker expects: the form falls back to a text box.
  List<String>? triggerFields,
  int deleteStatus = 200,
  // What the signed-in role holds. The screen consults this now, so a test that
  // does not say produces an administrator — which is what every one of these
  // tests was written against.
  List<String> permissions = const [
    'automations.view',
    'automations.create',
    'automations.edit',
    'automations.delete',
  ],
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/automations/catalogue')) {
      return _json({'data': catalogue});
    }

    if (path.endsWith('/automations/trigger-fields')) {
      if (triggerFields == null) return _json({'message': 'no'}, 404);

      return _json({
        'data': {
          'trigger': request.url.queryParameters['trigger'],
          'fields': triggerFields,
          'sampled': triggerFields.isEmpty ? 0 : 1,
        },
      });
    }

    if (path.endsWith('/runs')) return _json({'data': _runs});

    if (path.contains('/activate/')) {
      onActivate?.call(path);
      return _json({'data': detail});
    }

    if (path.endsWith('/automations/a-1')) {
      if (request.method == 'PATCH') {
        onUpdate?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': detail});
      }

      if (request.method == 'DELETE') {
        return _json(
          deleteStatus == 200
              ? {'message': 'Automation deleted.'}
              : {'message': 'Only an administrator may delete an automation.'},
          deleteStatus,
        );
      }

      return _json({'data': detail});
    }

    if (path.endsWith('/automations')) {
      if (request.method == 'POST') {
        onCreate?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': <String, dynamic>{}}, 201);
      }

      return _json({'data': list});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'num-1',
            'e164': '+12394560829',
            'label': 'Marketing',
          },
        ],
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

// ── the server, as this screen sees it ─────────────────────────────

/// What `GET /automations` lists — a summary, with no conditions or actions in
/// it. Reading those back is what the detail endpoint is for.
const _list = [
  {
    'id': 'a-1',
    'name': 'Chase completions',
    'description': null,
    'trigger_key': 'job.status_changed',
    'is_active': true,
    'is_live': true,
    'disabled_reason': null,
    'runs_count': 2,
    'version': 2,
  },
];

const _detail = {
  'id': 'a-1',
  'name': 'Chase completions',
  'description': 'Tell the office when a job finishes.',
  'trigger_key': 'job.status_changed',
  'is_active': true,
  'is_live': true,
  'disabled_reason': null,
  'runs_count': 2,
  'version': 2,
  'conditions': {
    'all': [
      {
        'field': 'status',
        'op': 'in',
        'value': ['completed', 'invoiced'],
      },
    ],
  },
  'actions': [
    {'type': 'notify', 'message': 'A job finished.'},
  ],
  'versions': [
    {
      'id': 'v-1',
      'version': 1,
      'is_active': false,
      'created_at': '2026-08-01T09:00:00+00:00',
    },
    {
      'id': 'v-2',
      'version': 2,
      'is_active': true,
      'created_at': '2026-08-05T09:00:00+00:00',
    },
  ],
};

/// The same rule, but its webhook signs its calls. `present()` masks the
/// secret on the way out, so what arrives here is bullets, not the secret.
const _detailWithSecret = {
  'id': 'a-1',
  'name': 'Chase completions',
  'description': 'Tell the office when a job finishes.',
  'trigger_key': 'job.status_changed',
  'is_active': true,
  'is_live': true,
  'disabled_reason': null,
  'runs_count': 2,
  'version': 1,
  'conditions': null,
  'actions': [
    {
      'type': 'webhook',
      'url': 'https://hooks.example.test/jobs',
      'secret': '••••••••',
    },
  ],
  'versions': [
    {
      'id': 'v-1',
      'version': 1,
      'is_active': true,
      'created_at': '2026-08-01T09:00:00+00:00',
    },
  ],
};

/// Shaped exactly like the catalogue contract: triggers and declared actions
/// are objects carrying a label and a sentence, not bare keys, and each module
/// says which RECORD its events are about and what a rule may set on it.
///
/// Three modules, on purpose. Two with different writable sets, so "the picker
/// offers the wrong subject's fields" is a failure this can see; and one with
/// an empty set, because "you can react to a company and cannot change one" is
/// an answer the form has to be able to give.
const _catalogue = {
  'modules': [
    {
      'key': 'customers',
      'label': 'Customers',
      'subject': {
        'key': 'customer',
        'label': 'Customer',
        'writable_fields': [
          {'key': 'name', 'label': 'Name'},
          {'key': 'email', 'label': 'Email'},
          {'key': 'is_active', 'label': 'Active'},
        ],
      },
      'triggers': [
        {
          'key': 'customer.created',
          'label': 'Created',
          'description': 'Somebody added a customer.',
        },
        {
          'key': 'customer.owner_reassigned',
          'label': 'Owner reassigned',
          'description':
              'Accountability for a customer moved to another franchise.',
        },
      ],
      'declared_actions': [
        {
          'key': 'customer.update_field',
          'label': 'Update a field on the customer',
          'description': 'Set one field to a fixed value.',
          'implemented': true,
        },
        {
          'key': 'customer.reassign_owner',
          'label': 'Reassign the owner',
          'description': 'Move accountability to another franchise.',
          'implemented': false,
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
          {'key': 'status', 'label': 'Status'},
          {'key': 'scheduled_for', 'label': 'Date and time'},
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
          'key': 'job.change_status',
          'label': 'Change the status',
          'description': 'Move the job to another status.',
          'implemented': false,
        },
      ],
    },
    {
      'key': 'companies',
      'label': 'Companies',
      // Reactable, unchangeable. The empty list is the point.
      'subject': {'key': 'company', 'label': 'Company', 'writable_fields': []},
      'triggers': [
        {
          'key': 'company.created',
          'label': 'Opened',
          'description': 'A new company was added to the organization.',
        },
      ],
      'declared_actions': [],
    },
  ],
  'operators': [
    {'key': 'eq', 'label': 'is'},
    {'key': 'in', 'label': 'is one of'},
    {'key': 'is_empty', 'label': 'is empty'},
  ],
  'action_types': [
    {'key': 'update_field', 'label': 'Update a field'},
    {'key': 'webhook', 'label': 'Call a webhook'},
    {'key': 'notify', 'label': 'Send a notification'},
  ],
  'timezones': [
    {'id': 'America/New_York', 'label': 'Eastern — New York'},
    {'id': 'Etc/GMT+5', 'label': 'UTC-5'},
  ],
  'schedule_kinds': [
    {'key': 'daily', 'label': 'Every day'},
    {'key': 'weekly', 'label': 'Days of the week'},
    {'key': 'monthly', 'label': 'Once a month'},
  ],
  'limits': {'max_depth': 5, 'action_budget': 50},
};

/// The catalogue plus the clock trigger, on a module the picker will show.
///
/// A separate fixture rather than adding it to `_catalogue`, so every test
/// written before schedules keeps the vocabulary it was written against — a
/// trigger appearing in a picker it did not have changes what "the first row"
/// means in half a dozen assertions.
Map<String, dynamic> _catalogueWithTheClock() {
  final modules = [
    ...(_catalogue['modules'] as List),
    {
      'key': 'automations',
      'label': 'Automations',
      'triggers': [
        {
          'key': 'automation.schedule_reached',
          'label': 'A time you chose comes round',
          'description': 'Runs on a clock rather than on something happening.',
        },
      ],
      'declared_actions': [],
    },
  ];

  return {...Map<String, dynamic>.from(_catalogue), 'modules': modules};
}

const _runs = [
  {
    'id': 'r-1',
    'status': 'skipped',
    'is_test': false,
    'event': {'key': 'job.status_changed', 'occurred_at': null},
    'condition_trace': {
      'type': 'all',
      'matched': false,
      'children': [
        {
          'type': 'condition',
          'field': 'status',
          'op': 'eq',
          'expected': 'completed',
          'actual': 'scheduled',
          'matched': false,
        },
      ],
    },
    'action_results': null,
    'error': null,
    'started_at': '2026-08-09T10:00:00+00:00',
  },
  {
    'id': 'r-2',
    'status': 'failed',
    'is_test': false,
    'event': {'key': 'job.status_changed', 'occurred_at': null},
    'condition_trace': null,
    'action_results': [
      {'type': 'webhook', 'ok': false, 'detail': 'It answered 500.'},
    ],
    'error': 'The webhook returned 500.',
    'started_at': '2026-08-09T11:00:00+00:00',
  },
];

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'r-1', 'name': 'Administrator'},
  'theme': 'light',
  'navigation': {'style': 'list', 'collapsed': false, 'width': 220},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'has_logo': false,
    'is_read_only': false,
  },
  'companies': [
    {
      'id': 'co-1',
      'name': 'A-1 Chimney',
      'parent_company_id': null,
      'has_logo': false,
    },
  ],
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'franchises': [
    {
      'id': 'fr-1',
      'name': 'Connecticut',
      'company_id': 'co-1',
      'is_active': true,
    },
  ],
};
