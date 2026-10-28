import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/automations_screen.dart';
import 'package:ezbizi_client/widgets/scope_choice_field.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
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
  // ── UI queue 73: a rule reads as a sentence and opens beside the list ──

  testWidgets('a rule says what it does and how its last run went', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        list: [
          {
            ..._list.first,
            'action_keys': ['webhook'],
            'last_run_at': '2026-10-09T12:00:00Z',
            'last_run_status': 'failed',
            'runs_this_week': 3,
          },
        ],
      ),
    );

    expect(find.textContaining('→ Call a webhook'), findsOneWidget);
    expect(find.textContaining('failed'), findsOneWidget);
    expect(find.text('3 this week'), findsOneWidget);
    // The switch is the status: no Live or Off chip beside it.
    expect(find.text('Live'), findsNothing);
    expect(find.text('Off'), findsNothing);
  });

  testWidgets('Off and the search narrow the list', (tester) async {
    await _pump(
      tester,
      _handler(
        list: [
          ..._list,
          {
            ..._list.first,
            'id': 'a-2',
            'name': 'Welcome new customers',
            'is_active': false,
          },
        ],
      ),
    );

    await _tap(tester, find.byKey(const ValueKey('automations-show-off')));
    expect(find.text('Chase completions'), findsNothing);
    expect(find.text('Welcome new customers'), findsOneWidget);

    await _tap(tester, find.byKey(const ValueKey('automations-show-all')));
    await tester.enterText(
      find.byKey(const Key('automations-search')),
      'chase',
    );
    await tester.pumpAndSettle();
    expect(find.text('Chase completions'), findsOneWidget);
    expect(find.text('Welcome new customers'), findsNothing);
  });

  testWidgets('a rule opens beside the list with its runs and Run now apart', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, find.byKey(const ValueKey('automation-a-1')));

    expect(find.text('Recent runs'), findsOneWidget);
    expect(find.byKey(const Key('automation-open-edit')), findsOneWidget);
    expect(find.byKey(const Key('automation-open-try')), findsOneWidget);
    // Really sends, so it is not one of the buttons beside Edit.
    expect(find.byKey(const Key('automation-open-run-now')), findsOneWidget);
  });

  /// Notifications, 2026-10-12: tapping a notification about a rule opens
  /// that rule, not the Automations home.
  testWidgets('a notification about a rule opens that rule', (tester) async {
    DeepLinks.open(
      const DeepLink(module: 'automations', type: 'automation', id: 'a-1'),
    );
    await _pump(tester, _handler());

    expect(find.text('Recent runs'), findsOneWidget);
    expect(
      DeepLinks.pending.value,
      isNull,
      reason: 'taken once, never reopened',
    );
  });

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
    // One step at a time now, with all five on the timeline (UI queue 113).
    expect(find.text('Name it'), findsOneWidget);
    for (final step in ['name', 'when', 'only-if', 'then', 'review']) {
      expect(find.byKey(ValueKey('automation-step-$step')), findsOneWidget);
    }
    await _goTo(tester, 'when');
    expect(find.text('When this happens'), findsOneWidget);
    await _goTo(tester, 'then');
    expect(find.text('Then do this'), findsOneWidget);

    await _tap(tester, find.text('Your automations'));

    expect(find.text('Chase completions'), findsOneWidget);
  });

  /// Leads is a module of its own that the app draws inside Marketing, and the
  /// builder groups its events where somebody just navigated to them — rather
  /// than under a heading for a tab that no longer exists on its own.
  /// Every fixture in this file sets `disabled_reason: null`, so until this test
  /// nothing had ever RENDERED the auto-disabled branch — the one a customer only
  /// sees on the day their automation breaks. It carries a formatStamp call that
  /// would throw on a screen with no display formats, and nothing would have
  /// caught that but the customer.
  testWidgets('a disabled automation says when it stopped, not just that it did', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        list: const [
          {
            'id': 'a-1',
            'name': 'Chase completions',
            'description': null,
            'trigger_key': 'job.status_changed',
            'is_active': true,
            'is_live': false,
            'disabled_at': '2026-09-14T15:40:00+00:00',
            'disabled_reason':
                'Disabled after 5 consecutive failures. Open Run history to see what failed.',
            'runs_count': 7,
            'version': 2,
          },
        ],
      ),
    );

    expect(find.text('Auto-disabled'), findsOneWidget);

    // The WHEN is the half that was missing: the field was on the wire and
    // rendered nowhere. Asserting the year rather than a full stamp, because the
    // organization chooses the date format and this test must not pin it.
    expect(find.textContaining('2026'), findsWidgets);
    expect(find.textContaining('Open Run history'), findsOneWidget);

    // The fallback means disabled_at did not parse — a green test that showed
    // this would be asserting the failure path.
    expect(find.textContaining('At an unknown time'), findsNothing);
  });

  // Jose, 2026-10-28: a rule a tab depends on is marked, and switching it
  // off names what stops before it goes.
  testWidgets('a core rule says what stops and asks before switching off', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        list: const [
          {
            'id': 'a-1',
            'name': 'Membership renewal',
            'description': null,
            'trigger_key': 'job.status_changed',
            'is_active': true,
            'is_live': true,
            'disabled_at': null,
            'disabled_reason': null,
            'core_reason': 'memberships stop renewing.',
            'runs_count': 3,
            'version': 1,
          },
        ],
      ),
    );

    expect(find.text('Core'), findsOneWidget);
    expect(find.textContaining('memberships stop renewing'), findsOneWidget);
    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();
    expect(find.text('Switch off a core rule?'), findsOneWidget);
    expect(
      find.text('While this rule is off: memberships stop renewing.'),
      findsOneWidget,
    );
  });

  // On a phone one line cut every rule to "Flag a customer with no w…".
  testWidgets('on a phone a rule name reads in full over two lines', (
    tester,
  ) async {
    // Short, because the test font draws every letter 16px wide: at 411 the
    // name has 207px, so this is two lines here and one cut line before.
    const name = 'Flag idle customers';
    await _pump(
      tester,
      _handler(
        list: const [
          {
            'id': 'a-1',
            'name': name,
            'description': null,
            'trigger_key': 'job.status_changed',
            'is_active': false,
            'is_live': false,
            'disabled_at': null,
            'disabled_reason': null,
            'runs_count': 0,
            'version': 1,
          },
        ],
      ),
      size: const Size(411, 900),
    );

    expect(
      tester.renderObject<RenderParagraph>(find.text(name)).didExceedMaxLines,
      isFalse,
    );
  });

  /// A MODULE DRAWN INSIDE ANOTHER IS FILED UNDER IT — ONE CATEGORY, NOT TWO.
  ///
  /// This test used to pin the opposite: "Marketing › Leads" as a category of
  /// its own. Jose overruled it on 2026-09-24 — *"The Marketing Tab is divided
  /// into tabs, when all of it should be together"* — because Marketing arrived
  /// as five categories and a marketing event meant opening each in turn.
  ///
  /// THE ROW HAS TO NAME THE CHILD once the heading stops doing it, or a folded
  /// trigger is indistinguishable from the parent's own. That half is the one
  /// that would quietly regress: merging the categories and dropping the prefix
  /// passes every assertion about headings.
  testWidgets('a module drawn inside another is filed under its parent', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithNesting));
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');

    // One heading for the whole of Marketing, and no separate one for Leads.
    expect(find.text('Marketing'), findsOneWidget);
    expect(find.text('Marketing › Leads'), findsNothing);

    await _tap(tester, find.text('Marketing'));

    // The parent's own trigger, unprefixed, beside the child's, named.
    expect(find.text('Newsletter sent'), findsOneWidget);
    expect(find.text('Leads · Lead received'), findsOneWidget);

    // A top-level module with no parent is untouched.
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
      await _goTo(tester, 'when');

      expect(find.text('Leads'), findsOneWidget);
      expect(find.text('Marketing › Leads'), findsNothing);
    },
  );

  testWidgets('events are grouped by category and named, never shown as keys', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');

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
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-picked-trigger')),
        matching: find.text('Jobs › Status changed'),
      ),
      findsOneWidget,
    );
  });

  /// AN ACTION THE ENGINE CANNOT RUN IS NOT LISTED AT ALL.
  ///
  /// This used to assert the opposite: shown, marked "Not built yet", and
  /// refused on tap — so somebody could see what was coming. Jose overruled it
  /// on 2026-09-24: *"i don't want to see any not built yet anymore"*. Five of
  /// the seven were then built, eleven more turned out to have been built and
  /// hidden, and the last two wait on real decisions — moving a person between
  /// places, which HR has deliberately not built for people either, and adding
  /// somebody to a mailing list, which needs their consent first.
  ///
  /// THE CONTROL IS THE RUNNABLE ONE BESIDE IT. Asserting only that "Change
  /// the status" is absent would pass against a picker that listed nothing.
  testWidgets('an action the engine cannot run is not listed', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));

    expect(find.text('Send a notification'), findsOneWidget);
    expect(find.text('Not built yet'), findsNothing);
    expect(
      find.text('Change the status'),
      findsNothing,
      reason: 'an action the engine cannot run was still offered',
    );
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
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Jobs'));
      await _tap(tester, find.text('Status changed'));

      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, _inStep(find.text('Update a field')));

      final field = tester.widget<SearchPickerField<String>>(
        find.byType(SearchPickerField<String>),
      );

      // The job's fields and only the job's — `email` is a customer's, and the
      // engine would have refused it every time.
      expect(field.options.map((o) => o.value), ['status', 'scheduled_for']);

      // And read as words. "scheduled_for" is a wire name, and the person
      // choosing runs a chimney business.
      expect(field.options.map((o) => o.label), ['Status', 'Date and time']);

      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Field'),
      );
      await _tap(tester, find.text('Date and time').last);
      await _type(tester, 'Set to', '2026-09-01');

      await _save(tester);

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
    await _goTo(tester, 'then');

    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    // An empty dropdown is a dead end that explains nothing.
    expect(find.byType(SearchPickerField<String>), findsNothing);
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

    await _goTo(tester, 'when');
    await _tap(tester, find.text('Companies'));
    await _tap(tester, find.text('Opened'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));

    // UI queue 133: a step that cannot apply is not listed (was: listed,
    // greyed, "Nothing to set here").
    expect(_inStep(find.text('Update a field')), findsNothing);
    expect(_inStep(find.text('Send a notification')), findsOneWidget);
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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));

    // UI queue 133: hidden rather than shown greyed.
    expect(find.text('Update a field on the customer'), findsNothing);
    expect(find.text('Not for this trigger'), findsNothing);
    expect(_inStep(find.text('Update a field')), findsOneWidget);

    expect(find.text('Choose what this step does'), findsOneWidget);
    expect((await _saveButton(tester)).onPressed, isNull);
  });

  testWidgets('changing the trigger clears a field the new record lacks', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    await _tap(tester, find.widgetWithText(SearchPickerField<String>, 'Field'));
    await _tap(tester, find.text('Email').last);

    expect((await _saveButton(tester)).onPressed, isNotNull);

    // Now it is a job rule, and a job has no email.
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    await _goTo(tester, 'then');

    // Cleared — leaving it would save a rule the server refuses — and said out
    // loud, because somebody chose it. Named the way they saw it, too: "Email",
    // not the wire key they never typed.
    expect(find.textContaining('the field “Email” on step 1'), findsOneWidget);
    expect(find.textContaining('This rule is now about a job'), findsOneWidget);

    expect(find.text('Which field should the step set?'), findsOneWidget);
    expect((await _saveButton(tester)).onPressed, isNull);
  });

  testWidgets(
    'changing the trigger takes back an action about the old record',
    (tester) async {
      await _pump(tester, _handler());
      await _tap(tester, find.text('Build'));

      await _type(tester, 'Name', 'Anything');
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Created'));
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));

      // UI queue 133: listed once. The generic "Update a field" acts on the
      // customer here, so the Customers module's copy is not listed beside it.
      expect(_inStep(find.text('Update a field')), findsOneWidget);
      expect(find.text('Update a field on the customer'), findsNothing);

      await _goTo(tester, 'when');
      await _tap(tester, find.text('Jobs'));
      await _tap(tester, find.text('Status changed'));
      await _goTo(tester, 'then');

      expect(
        _inStep(find.text('Update a field on the customer')),
        findsNothing,
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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));

    expect(find.byType(SearchPickerField<String>), findsNothing);
    expect(find.textContaining('cannot offer the list'), findsOneWidget);

    await _type(tester, 'Field', 'status');
    await _type(tester, 'Set to', 'completed');

    expect((await _saveButton(tester)).onPressed, isNotNull);
    await _save(tester);

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
      await _pump(
        tester,
        _handler(
          permissions: const [
            'automations.view',
            'automations.rules.view',
            'automations.build.view',
          ],
        ),
      );

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
      await _pump(
        tester,
        _handler(
          permissions: const [
            'automations.view',
            'automations.rules.view',
            'automations.build.view',
          ],
        ),
      );

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
      await _pump(
        tester,
        _handler(
          permissions: const [
            'automations.view',
            'automations.rules.view',
            'automations.build.view',
          ],
        ),
      );

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

    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'status');
    await _type(tester, 'Value', 'completed');

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'It is done');

    // Typing the name LAST used to leave the button dead, because nothing
    // recomputed whether the form was complete once it had been drawn.
    expect((await _saveButton(tester)).onPressed, isNull);
    await _goTo(tester, 'name');
    await _type(tester, 'Name', 'Chase completions');
    expect((await _saveButton(tester)).onPressed, isNotNull);

    await _save(tester);

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
        // No email switch: a notification never emails (Jose, 2026-09-28).
        {'type': 'notify', 'message': 'It is done', 'role_ids': []},
      ],
    });
  });

  /// A field with a fixed set is chosen, not typed.
  ///
  /// The field picker fixed half of "people cannot guess them around" and left
  /// the other half: knowing the field is `source` tells nobody that the value
  /// they must type is `website_chat`. Asserting the WIRE value rather than the
  /// label, because the label is for the reader and the engine compares the
  /// value - a dropdown that showed the right words and sent the wrong string
  /// would look completely correct on screen.
  testWidgets('a value with a fixed set is a picker, and sends the wire value', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        onCreate: (body) => sent = body,
        triggerFields: const ['source', 'contact_name'],
        triggerFieldValues: const {
          'source': [
            {'value': 'local_services', 'label': 'Google Local Services'},
            {'value': 'website_chat', 'label': 'Website live chat'},
          ],
        },
      ),
    );

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Chat replies');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));
    await _pickField(tester, 'source');

    // The options do not exist until the picker is open, which is also the
    // point: before this change the same spot was a TextField and nothing
    // listed the values at all.
    // The LAST picker in the condition row is the value: the field picker sits
    // before it, and both are the same widget type now.
    await _tap(tester, find.byType(SearchPickerField<String>).last);
    await _tap(tester, find.text('Website live chat').last);

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'A chat came in');
    await _save(tester);

    expect(((sent!['conditions'] as Map)['all'] as List).single, {
      'field': 'source',
      'op': 'eq',
      'value': 'website_chat',
    });
  });

  // Jose, 2026-10-20: inboxes for an email auto-reply are ticked, not typed.
  testWidgets('"is one of" over a fixed set is ticked, and sends the values', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        onCreate: (body) => sent = body,
        triggerFields: const ['address'],
        triggerFieldValues: const {
          'address': [
            {'value': 'office@brightside.example', 'label': 'Office'},
            {'value': 'jobs@brightside.example', 'label': 'Jobs'},
            {'value': 'billing@brightside.example', 'label': 'Billing'},
          ],
        },
      ),
    );

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Closed-office reply');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));
    await _pickField(tester, 'address');
    tester
        .widget<SearchPickerField<String>>(
          find.widgetWithText(SearchPickerField<String>, 'Is'),
        )
        .onChanged!('in');
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilterChip, 'Office'));
    await _tap(tester, find.widgetWithText(FilterChip, 'Billing'));

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'Replied');
    await _save(tester);

    expect(((sent!['conditions'] as Map)['all'] as List).single, {
      'field': 'address',
      'op': 'in',
      'value': ['office@brightside.example', 'billing@brightside.example'],
    });
  });

  /// A line the carrier will refuse is not offered.
  ///
  /// Twilio approves outbound SMS per number. Before this the picker listed
  /// every line the company held, so a rule could be written against a
  /// voice-only number, save cleanly, and fail at send time — arriving in the
  /// run log as a carrier error long after, and reading like a Twilio problem
  /// rather than a choice this screen offered.
  ///
  /// THE SECOND EXPECT IS THE ONE THAT MATTERS: asserting the approved line is
  /// present would pass just as well against no filter at all.
  testWidgets('the Send from picker lists only lines approved for texting', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithSms()));

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Email'));
    await _tap(tester, find.text('An email arrived'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Send a message')));

    await _tap(tester, find.byType(SearchPickerField<String>).last);
    await tester.pumpAndSettle();

    expect(find.textContaining('Marketing'), findsWidgets);
    expect(find.textContaining('Voice only'), findsNothing);
  });

  /// A WhatsApp step is a template with its blanks filled, never a message box.
  ///
  /// Meta accepts free text only inside the 24-hour window after the customer
  /// wrote in, and an automation is by definition a message the business starts.
  /// A message box would have worked whenever somebody tried it right after a
  /// reply and failed silently for every real use.
  ///
  /// THE BLANK COUNT COMES FROM THE SERVER. This template declares 2 while its
  /// preview shows {{1}} and {{3}} — a real Meta shape, numbered positionally
  /// with a gap — so a builder that counted placeholders would draw the wrong
  /// number of boxes. Two boxes is the assertion that it did not count.
  testWidgets('a WhatsApp step fills the template blanks the server declared', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        onCreate: (body) => sent = body,
        triggerFields: const ['phone', 'contact_name'],
        // GROUPED BY NUMBER, as Meta's endpoint returns it: a template is
        // approved against the WABA behind a number, and ours sit on different
        // ones. The flat shape hid one number's templates entirely.
        whatsAppTemplates: const [
          {
            'from': '+18778536971',
            'label': 'EzBizi',
            'templates': [
              {
                'name': 'appointment_reminder',
                'parameters': 2,
                'preview': 'Hi {{1}}, your visit is {{3}}.',
              },
            ],
          },
        ],
      ),
    );

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Remind them');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a WhatsApp message'));

    // Found by its LABEL, not by position. This form now holds three dropdowns
    // - the step type, the template, and the recipient kind - and an index into
    // that is a test that breaks every time the form gains a control, silently
    // testing the wrong one.
    await _tap(
      tester,
      find.ancestor(
        of: find.text('Template'),
        matching: find.byType(SearchPickerField<String>),
      ),
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.textContaining('appointment_reminder').last);

    expect(find.text('Blank 1'), findsOneWidget);
    expect(find.text('Blank 2'), findsOneWidget);
    expect(find.text('Blank 3'), findsNothing);

    // A blank left empty is refused HERE. Meta refuses it too — at send time,
    // in the run log, on a rule that looked finished.
    await _type(tester, 'Blank 1', '{{ contact_name }}');
    expect((await _saveButton(tester)).onPressed, isNull);

    await _goTo(tester, 'then');
    await _type(tester, 'Blank 2', 'tomorrow');

    // The recipient asks WHAT KIND first now. Default is a person; this rule
    // wants the number the event carried.
    await _tap(
      tester,
      find.ancestor(
        of: find.text('Send to'),
        matching: find.byType(SearchPickerField<String>),
      ),
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.text('A number from the event').last);

    await _tap(tester, find.byType(DropdownMenu<String>).last);

    // THE MENU ENTRY, not any text saying "phone". The variable catalogue under
    // the message boxes puts the field's own name on a chip, so `find.text`
    // matches two different controls and `.last` silently picks the wrong one.
    await _tap(tester, find.widgetWithText(MenuItemButton, 'phone').last);

    await _save(tester);

    expect((sent!['actions'] as List).single, {
      'type': 'whatsapp.send',
      // The sending NUMBER rides with it now: a template is approved per WABA
      // and our two numbers sit on different ones, so the runner cannot pick.
      'from': '+18778536971',
      'template': 'appointment_reminder',
      'to_field': 'phone',
      'parameters': ['{{ contact_name }}', 'tomorrow'],
    });
  });

  /// A notification can be narrowed to roles, and untouched narrows nothing.
  ///
  /// The second half is what protects every rule saved before this existed: an
  /// untouched picker must send NO role ids, not an empty-string one, and not
  /// change who is told. Jose's live rule notifies twelve people today because
  /// a website-chat lead carries no franchise — narrowing is the point, and
  /// accidentally narrowing a rule nobody edited would be the opposite.
  ///
  /// IDs travel, not names. A role's name is editable, so a rule storing names
  /// would re-point itself the day somebody renamed one — silently, and wider.
  testWidgets('a notification step can be narrowed to roles, and stores ids', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(tester, _handler(onCreate: (body) => sent = body));

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Tell the office');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'A chat came in');

    // Untouched first: the step is complete and narrows nothing.
    expect((await _saveButton(tester)).onPressed, isNotNull);

    await _goTo(tester, 'then');
    await _tap(tester, find.widgetWithText(FilterChip, 'Office'));
    await _save(tester);

    expect((sent!['actions'] as List).single, {
      'type': 'notify',
      'message': 'A chat came in',
      'role_ids': ['role-office'],
    });
  });

  /// "Send to" asks WHAT KIND first, then one question.
  ///
  /// It was one menu holding every person plus every field of the event.
  /// Thirteen people and ten fields already filled the screen and a hundred
  /// employees would make it unusable — Jose said so while it was still small
  /// enough to work.
  ///
  /// THE ASSERTION THAT MATTERS IS THE ABSENCE: choosing "an address I type"
  /// must not leave the people list on screen. A test that only checked the
  /// typed box appeared would pass against a control that showed everything at
  /// once, which is the thing being fixed.
  testWidgets('the email recipient asks for a kind before offering a list', (
    tester,
  ) async {
    await _pump(tester, _handler(triggerFields: const ['from_address']));

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send an email'));

    // Lands on "somebody who works here", searchable rather than a flat list.
    expect(find.text('Search people'), findsOneWidget);
    expect(find.text('Email address'), findsNothing);

    await _tap(tester, find.byType(SearchPickerField<String>).last);
    await tester.pumpAndSettle();
    await _tap(tester, find.text('An address I type').last);

    expect(find.text('Email address'), findsOneWidget);
    expect(
      find.text('Search people'),
      findsNothing,
      reason:
          'the people list must be GONE, not merely joined by a box - '
          'showing both is the overflow this replaced',
    );
  });

  /// "The customer it is about" saves the one value the server resolves.
  ///
  /// Jose, 2026-09-24: text the customer when a job is Done. The server reads
  /// `customer:` through the job's customer at run time; any other string is a
  /// field name or a literal, so the saved value is the whole contract.
  testWidgets('an email step can go to the customer it is about', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(tester, _handler(onCreate: (body) => sent = body));

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'Tell the customer');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send an email'));

    await _tap(tester, find.byType(SearchPickerField<String>).last);
    await tester.pumpAndSettle();
    await _tap(tester, find.text('The customer it is about').last);
    expect(find.text('Search people'), findsNothing);

    await _type(tester, 'Subject', 'Done');
    await _type(tester, 'Message', 'All done.');
    await _save(tester);

    expect(
      (sent!['actions'] as List).single,
      containsPair('to_field', 'customer:'),
    );
  });

  /// A box that takes {{ }} NAMES THE FIELDS, and one that does not says nothing.
  ///
  /// "Use {{ title }} to include a field from the record" sat under two boxes
  /// and told nobody which words work here — and an unknown name resolves to an
  /// empty string with no error, so a guess costs a message with a hole in it
  /// that nobody can explain afterwards.
  ///
  /// THE ABSENCE IS THE HALF THAT MATTERS. "Send to" is a recipient, not an
  /// interpolated box, and update_field's value is written as typed. Offering
  /// variables under every text field would promise something the runner does
  /// not do in most of the places it was offered, which is why the server
  /// declares the flag per parameter instead of the client guessing from kind.
  testWidgets('a box that takes variables names the fields of this trigger', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(triggerFields: const ['from_address', 'subject']),
    );

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send an email'));

    expect(
      find.textContaining('from_address, subject'),
      findsWidgets,
      reason: 'the boxes that substitute {{ }} have to say which names exist',
    );
  });

  /// TAP A FIELD, SEE WHAT A CUSTOMER WOULD READ.
  ///
  /// Jose asked for both halves in one sentence: *"I should have a preview and
  /// catalog of variables, so for example if i put {{title}} i should be able
  /// to see what that will turn out as"*. A name typed from memory that does
  /// not exist resolves to an empty string with no error, so the catalogue
  /// inserts the real name; and a message that reads fine in the builder can
  /// still arrive with a hole in it, so the preview shows the substitution.
  ///
  /// THE PREVIEW USES THE SAME RULE THE RUNNER USES — this asserts the sample,
  /// not the braces, because a preview that echoed `{{from_address}}` back
  /// would look like it worked in exactly the case that fails.
  testWidgets('a variable can be inserted and previewed with a real value', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        triggerFields: const ['from_address'],
        fieldSamples: const {'from_address': 'notify@ringcentral.com'},
      ),
    );

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send an email'));

    // The catalogue carries the example beside the name, so the value is
    // visible before anything is typed.
    final chip = find.widgetWithText(ActionChip, '+ from_address');
    expect(chip, findsWidgets);

    await tester.enterText(
      find.widgetWithText(TextField, 'Subject').first,
      'Call from ',
    );
    await tester.pumpAndSettle();

    await _tap(tester, chip.first);

    expect(
      find.textContaining(
        'Preview with the last event: “Call from notify@ringcentral.com”',
      ),
      findsOneWidget,
      reason: 'the preview has to show the VALUE, not the braces',
    );
  });

  /// AN UNTOUCHED PICKER SENDS NOTHING, and that is the whole fix.
  ///
  /// Jose, 2026-09-17: *"i made an automation for a-1chimney, but it's applying
  /// for everything"*. Nothing ever set a scope, so every rule was stored
  /// organization-wide. The server now scopes a new rule to the view it was
  /// written in — which only works while the builder stays SILENT about scope
  /// unless somebody chooses.
  ///
  /// So the assertion is an absence. Sending `organization` by default would
  /// look identical on screen and would put every rule back to applying across
  /// the whole business, this time explicitly and with a control agreeing.
  testWidgets('a new rule says nothing about where it applies until asked', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(catalogue: _catalogueWithPlaces(), onCreate: (b) => sent = b),
    );

    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Only here');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));
    await _tap(tester, find.widgetWithText(SearchPickerField<String>, 'Field'));
    await _tap(tester, find.text('Date and time').last);
    await _type(tester, 'Set to', '2026-09-01');

    await _save(tester);

    expect(sent, isNotNull);
    expect(
      sent!.containsKey('scope_type'),
      isFalse,
      reason: 'an untouched picker must leave the server free to use the view',
    );
  });

  /// And choosing one sends the PAIR the server stores.
  ///
  /// The catalogue hands out `franchise:f-1` as one string precisely so the
  /// builder cannot mispair a type with another place's id; this is the split
  /// back into the two columns, which is the only place that can go wrong.
  testWidgets('choosing where it applies sends the type and the id', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(catalogue: _catalogueWithPlaces(), onCreate: (b) => sent = b),
    );

    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Only here');
    await _tap(
      tester,
      find.widgetWithText(ScopeChoiceField, 'Where it applies'),
    );
    await _tap(tester, find.text('Connecticut').last);

    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));
    await _tap(tester, find.widgetWithText(SearchPickerField<String>, 'Field'));
    await _tap(tester, find.text('Date and time').last);
    await _type(tester, 'Set to', '2026-09-01');

    await _save(tester);

    expect(sent!['scope_type'], 'franchise');
    expect(sent!['scope_id'], 'f-1');
  });

  /// A TAB SOMEBODY MAY NOT OPEN IS NOT DRAWN, and the one they may still is.
  ///
  /// Jose, 2026-09-17: *"i should be able to grant access by sub tab as well"*.
  /// Build hands somebody the catalogue — a map of every trigger and action
  /// this installation can perform — so it is a separate grant from reading the
  /// rules that already exist.
  ///
  /// THE POSITIVE HALF IS THE ONE THAT MATTERS. A screen that drew nothing at
  /// all would pass an assertion that only checked Build was gone, and a rule
  /// reader losing their rules is the failure nobody reports as a permission
  /// change — they report that their automations have disappeared.
  testWidgets('without the build grant the tab is gone and the list remains', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        permissions: const ['automations.view', 'automations.rules.view'],
      ),
    );

    expect(find.text('Build'), findsNothing);
    expect(find.text('Chase completions'), findsOneWidget);
  });

  /// THE CATALOGUE ANSWERS THE QUESTION A PICKER CANNOT.
  ///
  /// Jose, 2026-09-17: *"i set website_chat manually cause i knew it, but i
  /// dont know the rest ... give me a catalog as well to see them all"*. A
  /// picker answers "what goes in THIS box", which requires already knowing
  /// which field to hang it off; this answers "what is there at all".
  ///
  /// Asserted on a field with a closed set AND one without, because the two
  /// say different things and a catalogue that only listed the closed ones
  /// would leave somebody believing the others do not exist.
  testWidgets('the catalogue lists every field and the values it allows', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        triggerFields: const ['source', 'contact_name'],
        triggerFieldValues: const {
          'source': [
            {'value': 'website_chat', 'label': 'Website live chat'},
            {'value': 'thumbtack', 'label': 'Thumbtack'},
          ],
        },
        fieldSamples: const {'contact_name': 'Marta Ruiz'},
      ),
    );

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    final table = find.byKey(const ValueKey('automation-carries'));

    // The closed set, in plain words.
    expect(
      find.descendant(
        of: table,
        matching: find.text('Website live chat, Thumbtack'),
      ),
      findsOneWidget,
    );

    // And the open field, named, with a real example rather than nothing.
    expect(
      find.descendant(of: table, matching: find.text('Marta Ruiz')),
      findsOneWidget,
    );
  });

  /// A TEMPLATE FILLS THE FORM AND SAVES NOTHING.
  ///
  /// Jose, 2026-09-17: *"i want to have Templates as well ... so people can
  /// easily select them and apply them."*
  ///
  /// The half worth asserting is the one that is easy to get wrong: applying a
  /// template must not create anything. A version that saved directly would
  /// look identical here — a rule appears — and would have switched something
  /// on that nobody read first.
  testWidgets('a template fills the builder in and creates nothing by itself', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(catalogue: _catalogueWithTemplates(), onCreate: (b) => sent = b),
    );

    await _tap(tester, find.text('Build'));
    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Start from a template'),
    );
    await _tap(
      tester,
      find.text('Tell the office about website chats only').last,
    );

    // The name, the trigger, the condition and the step all arrive together.
    expect(
      find.widgetWithText(
        TextField,
        'Tell the office about website chats only',
      ),
      findsOneWidget,
    );
    await _goTo(tester, 'then');
    expect(find.text('Somebody is chatting on the website.'), findsOneWidget);

    // AND NOTHING WAS SAVED. The person still has to press the button.
    expect(sent, isNull);
  });

  /// TEMPLATES ARE SEARCHABLE, by what they are called AND where they run.
  ///
  /// Jose, 2026-09-24: *"I want more templates ... also add a search bar for
  /// them"*. The target template's name and description share no word with the
  /// query; only its "Runs on" area matches. So this passes only if search
  /// reads the area, and the eight others staying hidden shows it filters.
  testWidgets('templates can be searched by the area they run on', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        catalogue: {
          ...Map<String, dynamic>.from(_catalogue),
          'templates': [
            for (var i = 0; i < 8; i++)
              {
                'key': 'filler-$i',
                'name': 'Filler number $i',
                'description': 'Nothing to see.',
                'trigger_key': 'customer.created',
                'conditions': null,
                'actions': [
                  {'type': 'notify', 'message': 'Hi.'},
                ],
              },
            {
              'key': 'handoff',
              'name': 'Heads-up for the new branch',
              'description': 'Tells the branch that now looks after them.',
              'trigger_key': 'customer.owner_reassigned',
              'conditions': null,
              'actions': [
                {'type': 'notify', 'message': 'Hi.'},
              ],
            },
          ],
        },
      ),
    );

    await _tap(tester, find.text('Build'));
    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Start from a template'),
    );

    await tester.enterText(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.byType(TextField),
      ),
      'owner reassigned',
    );
    await tester.pumpAndSettle();

    expect(find.text('Heads-up for the new branch'), findsOneWidget);
    expect(find.text('Filler number 0'), findsNothing);
  });

  /// REPLACING WORK ASKS IN PLACE, not in a dialog, and Keep mine keeps it.
  ///
  /// The house has no modal dialogs (theme_conventions_test). Both answers are
  /// exercised: Keep mine must leave the typed name alone, which is the half
  /// a confirm that always applied would fail.
  testWidgets('a template over typed work asks inline before replacing it', (
    tester,
  ) async {
    await _pump(tester, _handler(catalogue: _catalogueWithTemplates()));

    await _tap(tester, find.text('Build'));
    await _type(tester, 'Name', 'My own rule');

    Future<void> choose() async {
      await _tap(
        tester,
        find.widgetWithText(SearchPickerField<String>, 'Start from a template'),
      );
      await _tap(
        tester,
        find.text('Tell the office about website chats only').last,
      );
    }

    await choose();
    expect(find.byType(AlertDialog), findsNothing);
    await _tap(tester, find.text('Keep mine'));
    expect(find.widgetWithText(TextField, 'My own rule'), findsOneWidget);

    await choose();
    await _tap(tester, find.text('Use the template'));
    expect(
      find.widgetWithText(
        TextField,
        'Tell the office about website chats only',
      ),
      findsOneWidget,
    );
    expect(find.text('Use the template'), findsNothing);
  });

  /// CARDS SIT SIDE BY SIDE, and still fit when there is no room for two.
  ///
  /// Jose, 2026-09-22: *"i would also like to see the current automations in
  /// card format too, so i can see more at the same time."* A full-width row
  /// spent about a fifth of a wide screen on the rule and the rest on nothing.
  ///
  /// TWO ASSERTIONS, BECAUSE THEY FAIL DIFFERENTLY. The first is the feature —
  /// same vertical position means genuinely side by side, which a taller list
  /// of full-width rows would not produce. The second is the cost of it: at
  /// card width a `Tag` sizes to its own text and takes no part in a Row's
  /// negotiation, so a long place name pushed the switch off the edge. An
  /// overflow throws in a widget test, so pumping a long name and a long scope
  /// label at a narrow surface is the whole check.
  testWidgets('rules are laid out as cards across the width', (tester) async {
    final many = [
      for (var i = 0; i < 4; i++)
        {
          ..._list.first,
          'id': 'a-$i',
          'name': 'A rule with a fairly long name number $i',
          'scope': {
            'type': 'franchise',
            'id': 'f-1',
            'label': 'Connecticut and the surrounding branches',
          },
        },
    ];

    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);

    await _pump(tester, _handler(list: many));

    final first = tester.getTopLeft(find.byType(Card).at(0));
    final second = tester.getTopLeft(find.byType(Card).at(1));

    expect(
      second.dy,
      first.dy,
      reason:
          'two rules at the same height is what "more at the same time" means',
    );
    expect(second.dx, greaterThan(first.dx));

    // And narrow, where there is room for one: no overflow, which a widget
    // test reports as a failure on its own.
    tester.view.physicalSize = const Size(520, 900);
    await tester.pumpAndSettle();

    expect(find.byType(Card), findsWidgets);
  });

  /// CARDS IN ONE ROW ARE ONE SIZE, whatever their names.
  ///
  /// A rule's name wraps to two lines now (b32c4e3d, for phones). In a Wrap
  /// each card sized to itself, so one long name beside a short one made two
  /// cards of different heights — Jose's "you always make that mistake". The
  /// short/long pair is what can tell the difference.
  testWidgets('rule cards side by side are the same size', (tester) async {
    final pair = [
      {..._list.first, 'id': 'a-1', 'name': 'Short'},
      {
        ..._list.first,
        'id': 'a-2',
        'name':
            'A rule whose name is long enough to need a second line at card width',
      },
    ];

    tester.view.physicalSize = const Size(1000, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);

    await _pump(tester, _handler(list: pair));

    expect(
      tester.getTopLeft(find.byType(Card).at(1)).dy,
      tester.getTopLeft(find.byType(Card).at(0)).dy,
      reason: 'the two must be side by side for this to test anything',
    );
    expect({
      for (var i = 0; i < 2; i++) tester.getSize(find.byType(Card).at(i)),
    }, hasLength(1));
  });

  /// A BUILT ACTION IS OFFERED, AND DRAWS ITS OWN FORM.
  ///
  /// Two defects that looked like one from the screen. Jose, 2026-09-24:
  /// *"there is a lot of not built yet actions for the 4th step, i don't want
  /// to see any not built yet anymore"* — and eleven of them were built. With
  /// no `action_types` entry under their execution type the builder refused
  /// them; and a built one that WAS offered but was not one of four types named
  /// by hand drew a bare Message box, while the save posted parameters nobody
  /// had been shown.
  ///
  /// So both halves: the parameterless one is choosable and says there is
  /// nothing to fill in, and the one with a parameter draws that parameter —
  /// asserted as the ABSENCE of the Message box, since a Message box beside the
  /// right picker would pass a check for the picker alone.
  testWidgets('built actions are offered and draw their own forms', (
    tester,
  ) async {
    // Assigning training needs a person (UI queue 133: a step that cannot
    // apply is not listed), so this rule reacts to a person.
    final built = _catalogueWithBuiltActions();
    await _pump(
      tester,
      _handler(
        catalogue: {
          ...built,
          'modules': [
            ...(built['modules'] as List),
            {
              'key': 'users',
              'label': 'People',
              'subject': {
                'key': 'user',
                'label': 'User',
                'writable_fields': [],
              },
              'triggers': [
                {'key': 'user.activated', 'label': 'Activated'},
              ],
              'declared_actions': [],
            },
          ],
        },
      ),
    );

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('People'));
    await _tap(tester, find.text('Activated'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));

    expect(
      find.text('Not built yet'),
      findsNothing,
      reason: 'both of these are built',
    );

    await _tap(tester, _inStep(find.text('Assign training')));

    expect(
      find.widgetWithText(SearchPickerField<String>, 'What to assign'),
      findsOneWidget,
    );
    expect(
      find.widgetWithText(TextField, 'Message'),
      findsNothing,
      reason: 'a built action must draw its own form, not a Message box',
    );
  });

  /// THE STEP SAYS WHO THE REPORT COVERS (queue 33, Jose's 1).
  ///
  /// Jose, 2026-09-29, on the weekly work hours rule: *"i should also be able
  /// to select which role gets reported"*. The step picks a report and the
  /// report decides who is in it, so the step says who, and offers Work
  /// Hours' own editor to change it, for a role that may manage Work Hours.
  testWidgets('a work hours report step says who the report covers', (
    tester,
  ) async {
    final hours = _handler(
      catalogue: _catalogueWithHoursReport(),
      permissions: const [
        'automations.view',
        'automations.rules.view',
        'automations.build.view',
        'automations.create',
        'automations.edit',
        'work_hours.manage',
      ],
    );
    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/hr/time-clock/reports')) {
        return _json({
          'data': [
            {
              'id': 'w-1',
              'name': 'Weekly Time Report',
              'role_ids': ['r-1'],
              'include_user_ids': ['u-7'],
              'exclude_user_ids': <String>[],
              'recipients': <String>[],
            },
          ],
          'roles': [
            {'id': 'r-1', 'name': 'Remote Dispatcher'},
          ],
          'people': [
            {'id': 'u-7', 'name': 'Mia Torres', 'email': 'mia@example.com'},
          ],
        });
      }
      return hours(request);
    });

    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Email'));
    await _tap(tester, find.text('An email arrived'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Send the work hours report')));

    expect(
      find.byKey(const ValueKey('step-hours-covers')),
      findsNothing,
      reason: 'no report chosen yet',
    );

    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Which report'),
    );
    await _tap(tester, find.text('Weekly Time Report').last);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('step-hours-covers')),
        matching: find.textContaining(
          'Remote Dispatcher, + Mia Torres',
          findRichText: true,
        ),
      ),
      findsOneWidget,
    );

    await _tap(tester, find.byKey(const ValueKey('step-hours-edit')));
    expect(
      find.text('Edit report'),
      findsOneWidget,
      reason: "Work Hours' own editor",
    );
  });

  /// "Set to" is a list when the field has one, and a box when it does not.
  ///
  /// The values have been served since the condition dropdowns landed and this
  /// half of the screen never read them — so a rule could set a status to
  /// "Booked" and skip for ever, because the stored value is `booked`. The
  /// SECOND assertion is the one that matters: a field with an open set must
  /// keep its text box, or this trades one wrong control for another.
  testWidgets(
    'setting a field offers its real values, and only when it has them',
    (tester) async {
      await _pump(
        tester,
        _handler(
          // `is_active` is writable on the fixture's Customer subject AND has a
          // closed set, which is the pairing this control is about.
          triggerFields: const ['is_active', 'name'],
          triggerFieldValues: const {
            'is_active': [
              {'value': '1', 'label': 'Active'},
              {'value': '0', 'label': 'Inactive'},
            ],
          },
        ),
      );

      await _tap(tester, find.text('Build'));
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Created'));
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Update a field'));

      // The Field control is a PICKER of writable fields, not a text box.
      await _tap(
        tester,
        find.ancestor(
          of: find.text('Field'),
          matching: find.byType(SearchPickerField<String>),
        ),
      );
      await tester.pumpAndSettle();
      await _tap(tester, find.text('Active').last);

      expect(find.text('Set to'), findsOneWidget);

      await _tap(
        tester,
        find.ancestor(
          of: find.text('Set to'),
          matching: find.byType(SearchPickerField<String>),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.text('Inactive'),
        findsWidgets,
        reason: 'the field offers its closed set, in plain words',
      );
    },
  );

  testWidgets('a condition with no field cannot be saved', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Anything');
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _type(tester, 'Message', 'Something');

    expect((await _saveButton(tester)).onPressed, isNotNull);

    // An empty clause saved happily and produced a rule that failed every
    // single run, because the evaluator refuses a condition with no field.
    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));

    expect(
      find.text(
        'One of the conditions has no field, so the rule could never run.',
      ),
      findsOneWidget,
    );
    expect((await _saveButton(tester)).onPressed, isNull);
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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));

    // A chooser, not a text box. The free box is what let `Status` be typed at
    // all, and the rule then skipped every run saying only "the value at the
    // time was nothing".
    final picker = find.widgetWithText(SearchPickerField<String>, 'Field');
    expect(picker, findsOneWidget);

    await _tap(tester, picker);

    for (final field in ['from_address', 'received_at', 'subject']) {
      expect(
        find.text(field),
        findsWidgets,
        reason: '$field should be offered',
      );
    }

    await _tap(tester, find.text('subject').last);

    expect(
      tester
          .widget<SearchPickerField<String>>(
            find.widgetWithText(SearchPickerField<String>, 'Field'),
          )
          .value,
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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));

    tester
        .widget<SearchPickerField<String>>(
          find.widgetWithText(SearchPickerField<String>, 'Field'),
        )
        .onChanged!('from_address');
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<SearchPickerField<String>>(
            find.widgetWithText(SearchPickerField<String>, 'Field'),
          )
          .value,
      'from_address',
    );
    expect(find.text('Not seen on this event lately'), findsOneWidget);
  });

  testWidgets('deleting a condition takes its text with it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'only-if');

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

  /// A SCHEDULED RULE CAN BE EDITED AND SAVED, and keeps its schedule.
  ///
  /// Jose, 2026-09-28, on the weekly hours rule: "can't save for some reason".
  /// The detail carried no schedule, so the form opened with no timezone,
  /// refused to save, and hid the control that could fix it. Saving is the
  /// half that proves it, and the schedule sent back unchanged is the half
  /// that proves the form did not quietly reset it to its defaults.
  testWidgets('a scheduled rule can be edited and keeps its schedule', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        catalogue: _catalogueWithTheClock(),
        onUpdate: (body) => sent = body,
        detail: {
          ..._detail,
          'trigger_key': 'automation.schedule_reached',
          'conditions': null,
          'schedule': {
            'kind': 'weekly',
            'days': ['sunday'],
            'time': '20:00',
            'timezone': 'America/New_York',
          },
        },
      ),
    );

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Open and edit'));

    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'Save changes'),
          )
          .onPressed,
      isNotNull,
    );
    await _tap(tester, find.text('Save changes'));

    expect(sent!['schedule'], {
      'kind': 'weekly',
      'time': '20:00',
      'timezone': 'America/New_York',
      'days': ['sunday'],
    });
  });

  /// Jose, 2026-10-09: opened rules showed the WhatsApp step with no number
  /// or template, and would not save. The form keeps that step in its own
  /// state, which nothing filled from the saved rule.
  testWidgets('a saved WhatsApp step opens filled in and saves unchanged', (
    tester,
  ) async {
    Map<String, dynamic>? sent;
    const step = {
      'type': 'whatsapp.send',
      'from': '+18778536971',
      'template': 'appointment_reminder',
      'to_field': 'phone',
      'parameters': ['{{ contact_name }}', 'tomorrow'],
      'language': 'en',
    };

    await _pump(
      tester,
      _handler(
        onUpdate: (body) => sent = body,
        detail: {
          ..._detail,
          'actions': [step],
        },
        whatsAppTemplates: const [
          {
            'from': '+18778536971',
            'label': 'EzBizi',
            'templates': [
              {
                'name': 'appointment_reminder',
                'parameters': 2,
                'preview': 'Hi {{1}}, your visit is {{2}}.',
              },
            ],
          },
        ],
      ),
    );

    await _tap(tester, find.byType(PopupMenuButton<String>));
    await _tap(tester, find.text('Open and edit'));

    await _goTo(tester, 'then');
    expect(find.text('appointment_reminder'), findsWidgets);
    expect(
      tester
          .widget<TextField>(find.widgetWithText(TextField, 'Blank 2'))
          .controller
          ?.text,
      'tomorrow',
    );

    await _save(tester);

    expect((sent!['actions'] as List).single, step);
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
    // It opens on Review, whose sentence names the trigger.
    expect(find.text('Jobs › Status changed'), findsOneWidget);
    expect(find.text('Version 1'), findsOneWidget);
    expect(find.text('In use'), findsOneWidget);

    // And the When step shows it read-only, with the reason it cannot move.
    await _goTo(tester, 'when');
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-when-locked')),
        matching: find.text('Jobs › Status changed'),
      ),
      findsOneWidget,
    );
    expect(
      find.textContaining('This stays as it is once a rule is saved'),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('tour-automations-trigger')),
      findsNothing,
      reason: 'a saved rule offers no trigger picker',
    );

    await _goTo(tester, 'only-if');

    // "is one of" could not be expressed by the old form and so could not be
    // read back by it either: one text box against a stored list.
    final values = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Values'),
    );
    expect(values.controller?.text, 'completed, invoiced');

    await _goTo(tester, 'then');
    await _type(tester, 'Message', 'Everyone should know.');
    await _save(tester);

    expect(sent, {
      'name': 'Chase completions',
      'description': 'Tell the office when a job finishes.',
      // AN EDIT ALWAYS RESTATES WHERE IT APPLIES, including "everywhere". There
      // is no view to fall back on when saving an existing rule, so silence
      // would let a save re-scope a rule to wherever the person happened to be
      // standing — which is the bug this whole change is about, arriving
      // through the edit path instead of the create one.
      'scope_type': null,
      'scope_id': null,
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
        {'type': 'notify', 'message': 'Everyone should know.', 'role_ids': []},
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

    await _goTo(tester, 'then');
    expect(find.textContaining('signing secret'), findsOneWidget);

    // The name is still editable, and saving it must not carry the version's
    // contents with it — the API hands secrets back masked, so re-sending them
    // would write eight bullets over a live secret.
    await _goTo(tester, 'name');
    await _type(tester, 'Name', 'Chase completions, renamed');
    await _save(tester);

    expect(sent, {
      'name': 'Chase completions, renamed',
      'description': 'Tell the office when a job finishes.',
      'scope_type': null,
      'scope_id': null,
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
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Created'));
      await _goTo(tester, 'only-if');
      await _tap(tester, find.text('Add a condition'));
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, find.text('Send a notification'));
      await _goTo(tester, 'review');

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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Update a field')));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('People'));
    await _tap(tester, find.text('Invited'));
    await _goTo(tester, 'then');

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
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Jobs'));
    await _tap(tester, find.text('Status changed'));
    expect(
      find.text('How often'),
      findsNothing,
      reason: 'Only the clock trigger has a schedule.',
    );

    await _goTo(tester, 'name');
    await _type(tester, 'Name', 'Monday report');
    await _goTo(tester, 'when');
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
    expect((await _saveButton(tester)).onPressed, isNull);

    await _goTo(tester, 'when');
    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Timezone'),
    );
    await _tap(tester, find.text('Eastern — New York').last);

    // Now weekly, with no day ticked — the second way this cannot come round.
    await _tap(tester, find.text('Days of the week'));
    expect(
      find.text('Pick at least one day, or this rule never comes round.'),
      findsOneWidget,
    );
    expect((await _saveButton(tester)).onPressed, isNull);

    await _goTo(tester, 'when');
    await _tap(tester, find.text('Mon'));

    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, _inStep(find.text('Send a notification')));
    await _type(tester, 'Message', 'The weekly report is ready.');

    await _save(tester);

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
    await _goTo(tester, 'when');
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
      reason:
          'A marked trigger is still selectable; the badge explains, it '
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
  testWidgets(
    'Send a message offers the company lines rather than a blank box',
    (tester) async {
      Map<String, dynamic>? sent;
      await _pump(
        tester,
        _handler(
          catalogue: _catalogueWithSms(),
          onCreate: (body) => sent = body,
        ),
      );

      await _tap(tester, find.text('Build'));
      await _type(tester, 'Name', 'Text me when mail lands');
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Email'));
      await _tap(tester, find.text('An email arrived'));
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, _inStep(find.text('Send a message')));

      // The line is chosen from what the company holds.
      await _tap(tester, find.byType(SearchPickerField<String>).last);
      await tester.pumpAndSettle();
      await _tap(tester, find.text('Marketing · +1 (239) 456-0829').last);

      await _type(tester, 'Send to', '+13057242199');
      await _type(tester, 'Message', 'An email just arrived.');
      await _save(tester);

      expect(sent?['actions'], [
        {
          'type': 'sms.send',
          'number_id': 'num-1',
          'to': '+13057242199',
          'body': 'An email just arrived.',
        },
      ]);
    },
  );

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
  testWidgets(
    'a yes/no step parameter is a switch, and leaving it off still saves',
    (tester) async {
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
      await _goTo(tester, 'when');
      await _tap(tester, find.text('Email'));
      await _tap(tester, find.text('An email arrived'));
      await _goTo(tester, 'then');
      await _tap(tester, find.text('Add a step'));
      await _tap(tester, _inStep(find.text('Send a message')));

      await _tap(tester, find.byType(SearchPickerField<String>).last);
      await tester.pumpAndSettle();
      await _tap(tester, find.text('Marketing · +1 (239) 456-0829').last);
      await _type(tester, 'Send to', '+13057242199');
      await _type(tester, 'Message', 'An email just arrived.');

      // A switch, not a box to type "true" into.
      expect(
        find.widgetWithText(SwitchListTile, 'Mark it urgent'),
        findsOneWidget,
        reason:
            'a boolean parameter drawn as a TextField asks for a typed "true"',
      );

      // Deliberately NOT touched. Off is the answer being given.
      await _save(tester);

      expect(
        sent,
        isNotNull,
        reason:
            'a required boolean left off is answered, not missing — it must save',
      );

      // A REAL boolean on the wire, not the text the switch stored it in. The
      // server must never be left casting a string: PHP turns '' into false and
      // the string 'false' into TRUE.
      expect((sent?['actions'] as List).first, containsPair('urgent', false));
    },
  );

  // ── UI queue 113: five steps with a timeline (Jose's 1 and 4) ──────────

  testWidgets(
    'the timeline jumps between the steps, and Back and Next walk them',
    (tester) async {
      await _pump(tester, _handler());
      await _tap(tester, find.text('Build'));

      // A new rule starts at its name.
      expect(find.text('Name it'), findsOneWidget);
      expect(find.text('Step 1 of 5'), findsOneWidget);

      await _goTo(tester, 'then');
      expect(find.text('Then do this'), findsOneWidget);
      expect(find.text('Name it'), findsNothing, reason: 'one step at a time');
      expect(find.text('Step 4 of 5'), findsOneWidget);

      await _goTo(tester, 'only-if');
      expect(find.text('Only if'), findsWidgets);
      expect(
        find.byKey(const ValueKey('tour-automations-add-condition')),
        findsOneWidget,
      );

      await _tap(tester, find.byKey(const ValueKey('automation-builder-back')));
      expect(find.text('When this happens'), findsOneWidget);

      await _tap(tester, find.text('Created'));
      await _tap(tester, find.byKey(const ValueKey('automation-builder-next')));
      expect(find.text('Step 3 of 5'), findsOneWidget);

      // With no conditions the bar offers to skip them, and skipping lands on
      // Then and reads as "none" on the timeline.
      await _tap(tester, find.byKey(const ValueKey('automation-builder-skip')));
      expect(find.text('Then do this'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-step-only-if')),
          matching: find.text('none'),
        ),
        findsOneWidget,
      );

      // The When step's summary is the trigger it reacts to.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('automation-step-when')),
          matching: find.text('Created'),
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets('Next refuses without a trigger, and says why', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    await _type(tester, 'Name', 'Tell the office');
    expect(_next(tester).onPressed, isNotNull);

    await _tap(tester, find.byKey(const ValueKey('automation-builder-next')));
    expect(find.text('When this happens'), findsOneWidget);

    // The same sentence Save would refuse with.
    expect(_next(tester).onPressed, isNull);
    expect(find.text('Choose what it should react to.'), findsOneWidget);

    await _tap(tester, find.text('Created'));
    expect(_next(tester).onPressed, isNotNull);
    expect(find.text('Choose what it should react to.'), findsNothing);

    // And Then refuses until it has a step.
    await _goTo(tester, 'then');
    expect(_next(tester).onPressed, isNull);
    expect(find.text('Add at least one thing for it to do.'), findsOneWidget);
  });

  testWidgets('a name left empty holds Next on the first step', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));

    expect(_next(tester).onPressed, isNull);
    expect(find.text('Give it a name first.'), findsOneWidget);
  });

  testWidgets(
    'editing opens on Review, as one sentence whose parts jump back',
    (tester) async {
      await _pump(tester, _handler());

      await _tap(tester, find.byType(PopupMenuButton<String>));
      await _tap(tester, find.text('Open and edit'));

      expect(find.text('Step 5 of 5'), findsOneWidget);
      expect(find.byKey(const ValueKey('automation-sentence')), findsOneWidget);

      Finder part(String step) =>
          find.byKey(ValueKey('automation-sentence-$step'));

      String said(String step) => tester
          .widget<Text>(
            find.descendant(of: part(step), matching: find.byType(Text)),
          )
          .data!;

      expect(said('when'), 'Jobs › Status changed');
      expect(
        said('only-if'),
        'only if the status is one of completed, invoiced',
      );
      expect(
        said('then'),
        'sends a notification to everybody who can see it: “A job finished.”',
      );
      expect(said('name'), 'across the whole organization');

      // The rehearsal of the saved rule sits here now, and so does History.
      expect(find.byKey(const Key('automation-builder-try')), findsOneWidget);
      expect(find.text('Put this one back'), findsOneWidget);
      expect(find.text('Save changes'), findsOneWidget);

      // Every complete step is ticked on the timeline.
      for (final step in ['name', 'when', 'only-if', 'then']) {
        expect(
          find.descendant(
            of: find.byKey(ValueKey('automation-step-$step')),
            matching: find.byIcon(Icons.check),
          ),
          findsOneWidget,
          reason: '$step is complete on a saved rule',
        );
      }

      await _tap(tester, part('only-if'));
      expect(find.text('Step 3 of 5'), findsOneWidget);
      expect(
        tester
            .widget<TextField>(find.widgetWithText(TextField, 'Values'))
            .controller
            ?.text,
        'completed, invoiced',
      );
    },
  );

  testWidgets('trigger cards carry the 30-day count when the server answers', (
    tester,
  ) async {
    String? asked;

    await _pump(
      tester,
      _handler(
        triggerStats: const {
          'customer.created': {'last_30_days': 23},
          'customer.owner_reassigned': {'last_30_days': 1},
        },
        onTriggerStats: (query) => asked = query,
      ),
    );
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');

    expect(asked, contains('customer.created'));
    expect(asked, contains('job.status_changed'));

    expect(find.text('23 in 30 days'), findsOneWidget);
    expect(find.text('1 in 30 days'), findsOneWidget);

    // A trigger the server left out says nothing, rather than zero.
    await _tap(tester, find.text('Jobs'));
    expect(find.textContaining('in 30 days'), findsNothing);

    // Picked, the trigger's own panel repeats it beside what it is.
    await _tap(tester, find.text('Customers'));
    await _tap(tester, find.text('Created'));
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-picked-trigger')),
        matching: find.text('23 times in the last 30 days'),
      ),
      findsOneWidget,
    );
    expect(find.text('Somebody added a customer.'), findsWidgets);
  });

  testWidgets('without the counts endpoint the cards say nothing', (
    tester,
  ) async {
    // triggerStats and wouldHaveRun left null: both answer 404.
    await _pump(tester, _handler());
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    expect(find.textContaining('in the last 30 days'), findsNothing);
    expect(
      find.byKey(const ValueKey('automation-trigger-count')),
      findsNothing,
    );

    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'status');
    await _type(tester, 'Value', 'completed');
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('automation-would-have-run')),
      findsNothing,
    );
    expect(find.textContaining('would have run'), findsNothing);
  });

  testWidgets('Only if says how many recent events would have run', (
    tester,
  ) async {
    Map<String, dynamic>? asked;

    await _pump(
      tester,
      _handler(
        wouldHaveRun: const {
          'total': 23,
          'matched': 9,
          'examples': [
            {'occurred_at': '2026-10-19T13:00:00Z', 'matched': true},
            {'occurred_at': '2026-10-18T13:00:00Z', 'matched': false},
          ],
        },
        onWouldHaveRun: (body) => asked = body,
      ),
    );
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    // Nothing to ask about while there are no conditions.
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(asked, isNull);

    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'status');
    await _type(tester, 'Value', 'completed');
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    // The conditions exactly as the save would send them.
    expect(asked, {
      'trigger': 'customer.created',
      'conditions': {
        'all': [
          {'field': 'status', 'op': 'eq', 'value': 'completed'},
        ],
      },
    });

    final card = find.byKey(const ValueKey('automation-would-have-run'));
    expect(
      find.descendant(
        of: card,
        matching: find.text(
          'Tested on the last 23 events (30 days): it would have run 9 times.',
        ),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.byIcon(Icons.check)),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.byIcon(Icons.close)),
      findsOneWidget,
    );

    // Changing a condition takes the old count away until the new one comes.
    await _type(tester, 'Value', 'invoiced');
    expect(card, findsNothing);
  });

  testWidgets('a rehearsal over the newest 500 is not called a month', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        wouldHaveRun: const {'total': 500, 'matched': 120, 'examples': []},
      ),
    );
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));
    await _type(tester, 'Field', 'status');
    await _type(tester, 'Value', 'completed');
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Tested on the newest 500 events: it would have run 120 times.',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('last 30 days'), findsNothing);
  });

  testWidgets('on a phone the timeline is dots and the steps stack', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(375, 812));
    await _tap(tester, find.text('Build'));

    // Only the step on screen is named.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-step-name')),
        matching: find.text('Name'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-step-when')),
        matching: find.text('When'),
      ),
      findsNothing,
    );
    expect(find.text('Next ›'), findsOneWidget);

    await _goTo(tester, 'when');
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('automation-step-when')),
        matching: find.text('When'),
      ),
      findsOneWidget,
    );
    await _tap(tester, find.text('Created'));

    await _goTo(tester, 'only-if');
    await _tap(tester, find.text('Add a condition'));

    // Then's steps are a list; the one being written fills the step, with a
    // way back, and no details column beside it (UI queue 133, Jose's 4).
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send a notification'));
    await _tap(tester, find.byKey(const ValueKey('automation-then-back')));
    expect(
      find.byKey(const ValueKey('automation-then-step-0')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('automation-then-details')), findsNothing);

    await _goTo(tester, 'review');
    expect(find.byKey(const ValueKey('automation-sentence')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('beside the step, a detail drops in where the cursor is', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(triggerFields: const ['contact_name', 'phone']),
    );
    await _tap(tester, find.text('Build'));
    await _goTo(tester, 'when');
    await _tap(tester, find.text('Created'));
    await _goTo(tester, 'then');
    await _tap(tester, find.text('Add a step'));
    await _tap(tester, find.text('Send an email'));

    final details = find.byKey(const ValueKey('automation-then-details'));
    expect(details, findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Message').first,
      'Hello ',
    );
    await tester.pumpAndSettle();

    await _tap(
      tester,
      find.descendant(
        of: details,
        matching: find.widgetWithText(ActionChip, '+ contact_name'),
      ),
    );

    expect(
      tester
          .widget<TextField>(find.widgetWithText(TextField, 'Message').first)
          .controller
          ?.text,
      'Hello {{contact_name}}',
    );
    // Into the box the cursor was in, not the first one.
    expect(
      tester
          .widget<TextField>(find.widgetWithText(TextField, 'Subject').first)
          .controller
          ?.text,
      '',
    );
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

/// Choose a condition's detail from its picker (UI queue 131).
Future<void> _pickField(WidgetTester tester, String field) async {
  await _tap(
    tester,
    find.widgetWithText(SearchPickerField<String>, 'Field').last,
  );
  await _tap(tester, find.text(field).last);
}

/// Jump to one of the builder's five steps by its place on the timeline.
Future<void> _goTo(WidgetTester tester, String step) =>
    _tap(tester, find.byKey(ValueKey('automation-step-$step')));

FilledButton _next(WidgetTester tester) => tester.widget<FilledButton>(
  find.byKey(const ValueKey('automation-builder-next')),
);

/// Save lives on Review, so reading it means going there.
Future<FilledButton> _saveButton(WidgetTester tester) async {
  await _goTo(tester, 'review');
  return tester.widget<FilledButton>(
    find.byKey(const ValueKey('tour-automations-save')),
  );
}

Future<void> _save(WidgetTester tester) async {
  await _goTo(tester, 'review');
  await _tap(tester, find.byKey(const ValueKey('tour-automations-save')));
}

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
/// A module action with NO parameters and one with a TRAINING picker — both
/// built, both greyed out as "Not built yet" until 2026-09-24, because neither
/// execution type had an entry in `action_types`.
Map<String, Object?> _catalogueWithBuiltActions() => {
  ...Map<String, dynamic>.from(_catalogueWithSms()),
  'trainings': const [
    {'id': 'guide:g-1', 'label': 'Chimney safety basics'},
    {'id': 'test:t-1', 'label': 'Chimney safety basics (test)'},
  ],
  'modules': [
    ...(_catalogueWithSms()['modules'] as List),
    {
      'key': 'training',
      'label': 'Training',
      'parent': null,
      'subject': null,
      'triggers': [],
      'declared_actions': [
        {
          'key': 'training.assign',
          'label': 'Assign training',
          'description': 'Give somebody a guide or a test.',
          'type': 'training.assign',
          'implemented': true,
        },
        {
          'key': 'customer.deactivate',
          'label': 'Switch off the customer',
          'description': 'Marks the customer inactive.',
          'type': 'deactivate',
          'implemented': true,
        },
      ],
    },
  ],
  'action_types': [
    ...(_catalogueWithSms()['action_types'] as List),
    {
      'key': 'deactivate',
      'label': 'Switch it off',
      'module_only': true,
      'parameters': [],
    },
    {
      'key': 'training.assign',
      'label': 'Assign training',
      'module_only': true,
      'parameters': [
        {
          'key': 'training',
          'label': 'What to assign',
          'kind': 'training',
          'required': true,
          'nullable': false,
        },
      ],
    },
  ],
};

/// The built actions, plus Work Hours' "Send the work hours report" and one
/// saved report to choose (queue 33).
Map<String, Object?> _catalogueWithHoursReport() => {
  ...Map<String, dynamic>.from(_catalogueWithBuiltActions()),
  'work_hours_reports': const [
    {'id': 'w-1', 'label': 'Weekly Time Report'},
  ],
  'modules': [
    ...(_catalogueWithBuiltActions()['modules'] as List),
    {
      'key': 'work_hours',
      'label': 'Work Hours',
      'parent': null,
      'subject': null,
      'triggers': [],
      'declared_actions': [
        {
          'key': 'work_hours.send_report',
          'label': 'Send the work hours report',
          'description': 'Emails the hours each person worked in the week.',
          'type': 'send_report',
          'implemented': true,
        },
      ],
    },
  ],
  'action_types': [
    ...(_catalogueWithBuiltActions()['action_types'] as List),
    {
      'key': 'send_report',
      'label': 'Send the work hours report',
      'module_only': true,
      'parameters': [
        {
          'key': 'report_id',
          'label': 'Which report',
          'kind': 'work_hours_report',
          'required': true,
          'nullable': false,
        },
      ],
    },
  ],
};

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
    types.firstWhere((t) => (t as Map)['key'] == 'sms.send') as Map,
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
  Map<String, List<Map<String, String>>>? triggerFieldValues,
  // One real example per field, as the server sends for the preview. A field
  // with no entry is one whose record this person does not reach.
  Map<String, String> fieldSamples = const {},
  List<Map<String, Object?>>? whatsAppTemplates,
  int deleteStatus = 200,
  // UI queue 113's two counts. Null answers 404, as a server without them.
  Map<String, Object?>? triggerStats,
  void Function(String query)? onTriggerStats,
  Map<String, Object?>? wouldHaveRun,
  void Function(Map<String, dynamic> body)? onWouldHaveRun,
  // What the signed-in role holds. The screen consults this now, so a test that
  // does not say produces an administrator — which is what every one of these
  // tests was written against.
  List<String> permissions = const [
    'automations.view',
    // The two TABS, since 2026-09-17. Absent, the screen draws no tab bar and
    // no panes — which is correct behaviour and would red every test here for a
    // reason that has nothing to do with what each one is about.
    'automations.rules.view',
    'automations.build.view',
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
          'values': triggerFieldValues ?? const {},
          'samples': fieldSamples,
          'sampled': triggerFields.isEmpty ? 0 : 1,
        },
      });
    }

    if (path.endsWith('/automations/trigger-stats')) {
      if (triggerStats == null) return _json({'message': 'no'}, 404);
      onTriggerStats?.call(request.url.queryParameters['triggers'] ?? '');
      return _json({'data': triggerStats});
    }

    if (path.endsWith('/automations/would-have-run')) {
      if (wouldHaveRun == null) return _json({'message': 'no'}, 404);
      onWouldHaveRun?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': wouldHaveRun});
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

    if (path.endsWith('/automations/whatsapp-templates')) {
      return _json({
        'data': whatsAppTemplates ?? const [],
        if (whatsAppTemplates == null || (whatsAppTemplates as List).isEmpty)
          'because': 'No WhatsApp template has been approved yet.',
      });
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'num-1',
            'e164': '+12394560829',
            'label': 'Marketing',
            // Mirrors NumbersController: the real endpoint has always sent this
            // and the stub did not, which is why the picker could offer a line
            // the carrier would refuse.
            'sms_outbound_allowed': true,
          },
          {
            'id': 'num-2',
            'e164': '+15005550006',
            'label': 'Voice only',
            'sms_outbound_allowed': false,
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
  'people': [
    {'id': 'user:u-1', 'label': 'Dana Office — dana@alpha.test'},
  ],
  'roles': [
    {'id': 'role-office', 'label': 'Office'},
    {'id': 'role-tech', 'label': 'Technicians'},
  ],
  'operators': [
    {'key': 'eq', 'label': 'is'},
    {'key': 'in', 'label': 'is one of'},
    {'key': 'is_empty', 'label': 'is empty'},
  ],
  'action_types': [
    {'key': 'update_field', 'label': 'Update a field'},
    {'key': 'webhook', 'label': 'Call a webhook'},
    // Mirrors AutomationsController::actionTypes(): notify DECLARES its message
    // now rather than the builder drawing a bespoke box for it. Kept in step
    // because the form is built from this, so a fixture still saying
    // "no parameters" draws no field and the rule cannot be written at all.
    // Generic like notify: no parameters declared, because the form's shape
    // depends on which template is picked.
    {'key': 'whatsapp.send', 'label': 'Send a WhatsApp message'},
    {
      'key': 'email.send',
      'label': 'Send an email',
      'parameters': [
        {
          'key': 'to_field',
          'label': 'Send to',
          'kind': 'recipient',
          'required': true,
          'nullable': false,
        },
        {
          'key': 'subject',
          'label': 'Subject',
          'kind': 'text',
          'required': true,
          'nullable': false,
          'variables': true,
        },
        {
          'key': 'body',
          'label': 'Message',
          'kind': 'rich_text',
          'required': true,
          'nullable': false,
          'variables': true,
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
          'nullable': false,
        },
        {
          'key': 'role_ids',
          'label': 'Only tell these roles',
          'kind': 'roles',
          'required': false,
          'nullable': false,
        },
      ],
    },
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
Map<String, dynamic> _catalogueWithTemplates() => {
  ...Map<String, dynamic>.from(_catalogue),
  'templates': const [
    {
      'key': 'website-chat-only',
      'name': 'Tell the office about website chats only',
      'description': 'Narrowed to the live chat on your own website.',
      'trigger_key': 'customer.created',
      'conditions': {
        'all': [
          {'field': 'source', 'op': 'eq', 'value': 'website_chat'},
        ],
      },
      'actions': [
        {'type': 'notify', 'message': 'Somebody is chatting on the website.'},
      ],
    },
  ],
};

Map<String, dynamic> _catalogueWithPlaces() => {
  ...Map<String, dynamic>.from(_catalogue),
  'places': const [
    {'id': 'organization', 'label': 'Everywhere in this business'},
    {'id': 'company:c-1', 'label': 'A-1 Chimney and everything under it'},
    {'id': 'franchise:f-1', 'label': 'Connecticut'},
  ],
};

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
