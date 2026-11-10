import 'dart:io';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/onboarding_form.dart';
import 'package:ezbizi_client/screens/hr/records_tab.dart';
import 'package:ezbizi_client/screens/hr/training_card.dart';
import 'package:ezbizi_client/screens/hr_screen.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Human Resources — the module that lets an organization have employees.
///
/// ── What is actually being defended here ─────────────────────────
///
/// Three things, and they fail in different ways.
///
/// The first is that a person who invites somebody is told what became of the
/// message. Mail sends for real since SES production access (2026-09-14), so
/// the strip says which of three things happened — sent, refused, or never
/// attempted because this installation delivers nowhere — and the link is
/// there for the two where nothing arrived. A screen that showed a link
/// without saying so leaves somebody waiting for an acceptance to a message
/// that was never going to come. That sentence is tested like a feature
/// because it is one.
///
/// (This paragraph used to say "nothing on this platform can send mail". True
/// when written, false the hour SES was approved, and nothing failed when it
/// turned — which is why it is worth suspecting every sentence that says what
/// the platform cannot do.)
///
/// The second is that the places somebody may be assigned come from the server.
/// §4 bounds an inviter to their own reach, the server already computes it, and
/// `/me` happens to carry a franchise list that would make a plausible-looking
/// substitute. The fixture below makes the two lists deliberately disagree, so
/// a Dart implementation of an access-control rule cannot pass this file.
///
/// The third is that whatever is still waiting on something says what it is
/// waiting for. A tab that vanishes reads as broken and a blank one reads as
/// broken; this codebase's answer is to draw it and mark it, the same as an
/// automation action the engine cannot run.
///
/// No tab is left — all six are built, and hr_contracts_test.dart,
/// hr_onboarding_test.dart and hr_dashboard_test.dart defend what they do. The
/// Dashboard chip is one glance; certifications is a sentence on that card.
void main() {
  // Five since 2026-09-24: Paid time off (and Work Hours) left for the
  // Work Hours module — see work hours' own screen test.
  testWidgets('the five tabs are drawn, in the owner’s order, and switch', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(_chips(tester), [
      'Dashboard',
      'Records',
      'Contracts',
      'Invitations',
      'Onboarding',
    ]);

    // It opens on tab zero now, and the rule that put it there has not changed:
    // "the first tab in the owner's order that does something". That was Records
    // while Onboarding was a placeholder, then Onboarding while Dashboard was
    // one, and now Dashboard — which is where the rule pointed all along.
    // Anchored on the range chips rather than on a heading. "At a glance" was
    // the heading here and it is gone: it sat directly under a highlighted
    // "Dashboard" chip, which is the same fact twice, and the module's name is
    // in the shell header now. A test should not pin a heading whose whole
    // purpose was being redundant.
    expect(find.text('Quarter'), findsOneWidget);

    await _tap(tester, _tab('Onboarding'));

    expect(find.text('Start an onboarding'), findsOneWidget);

    await _tap(tester, _tab('Records'));

    expect(find.text('Jose Silva'), findsOneWidget);

    await _tap(tester, _tab('Invitations'));

    // Jose is named on Invitations too now, as who sent them (the Invited by
    // column), so the Records list is what must have gone.
    expect(find.byType(RecordsTab), findsNothing);
    expect(find.text('bea@example.com'), findsOneWidget);

    await _tap(tester, _tab('Records'));

    expect(find.text('Jose Silva'), findsOneWidget);
  });

  testWidgets('a tapped onboarding notification opens that onboarding', (
    tester,
  ) async {
    DeepLinks.open(
      const DeepLink(module: 'hr', type: 'onboarding', id: 'onb-1'),
    );
    await _pump(tester, _handler());

    expect(
      tester.widget<OnboardingForm>(find.byType(OnboardingForm)).onboardingId,
      'onb-1',
    );
    expect(DeepLinks.pending.value, isNull);

    // And while the screen is already open.
    DeepLinks.open(
      const DeepLink(module: 'hr', type: 'onboarding', id: 'onb-2'),
    );
    await tester.pump();

    expect(
      tester.widget<OnboardingForm>(find.byType(OnboardingForm)).onboardingId,
      'onb-2',
    );
  });

  /// The module granted, and not one tab inside it.
  ///
  /// Not a hypothetical and not a stale fixture. Measured 2026-09-17 against
  /// the server: `hr.view` is offered in the roles editor as its own tick,
  /// beside the seven tabs and independent of them, and a user holding exactly
  /// `hr.view` comes back from PermissionCatalogue::modulesFor as
  /// `reachable: true`. Human Resources is in their navigation and every tab
  /// inside it is filtered out.
  ///
  /// The migration that introduced per-tab grants gave all seven to every role
  /// that already held `hr.view`, so nobody lost a tab that morning — but it
  /// cannot reach a role created after it ran, and ticking the module without
  /// ticking a tab makes this.
  ///
  /// Before this, the answer was a blank pane: no chips, no content, nothing
  /// logged, and a person deciding the product is broken when it is doing what
  /// somebody configured.
  testWidgets(
    'hr.view with no tab granted says so instead of drawing nothing',
    (tester) async {
      await _pump(
        tester,
        _handler(
          me: {
            ..._me,
            'permissions': const ['hr.view'],
          },
        ),
      );

      expect(_chips(tester), isEmpty);
      expect(
        find.text('No part of Human Resources has been opened to you yet.'),
        findsOneWidget,
      );

      // Names the place, and says it is not theirs to change — the two things a
      // person in this state cannot work out from an empty screen.
      expect(
        find.textContaining('Settings › Roles & permissions'),
        findsOneWidget,
      );
      expect(find.textContaining('nothing here is broken'), findsOneWidget);
    },
  );

  /// And one tab is enough to be an ordinary screen again.
  ///
  /// The control for the test above: without it, a bug that drew the empty
  /// state whenever anything was missing would pass it. This is also the case
  /// Jose asked for — "i want them to see their work hours, but not the
  /// onboarding information".
  testWidgets('one tab granted draws that tab and nothing else', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        me: {
          ..._me,
          'permissions': const ['hr.view', 'hr.records.view'],
        },
      ),
    );

    expect(_chips(tester), ['Records']);
    expect(
      find.text('No part of Human Resources has been opened to you yet.'),
      findsNothing,
    );
    expect(find.text('Jose Silva'), findsOneWidget);
  });

  /// Certifications is a sentence on the one HR card, not a fifth panel.
  testWidgets(
    'a role without Dashboard lands on a real tab and that pane loads',
    (tester) async {
      /*
       * The defect the tab filter introduced, and the one nothing here could
       * have caught: `_tab` was an INDEX, initialised to the constant 0 meaning
       * Dashboard, and the list's length now depends on what the reader may
       * open. Automations and Scheduling each found the same shape in their own
       * screens within the hour — Scheduling's `_tab == 2` told the only
       * visible pane it was off-screen, which renders as an empty screen rather
       * than as a refusal.
       *
       * Every other test in this file grants all seven tabs, so all seven pass
       * against the broken version. This one holds TWO, neither of them
       * Dashboard, which is the only shape that can tell the difference.
       */
      await _pump(
        tester,
        _handler(
          me: {
            ..._me,
            'permissions': ['hr.view', 'hr.records.view', 'hr.contracts.view'],
          },
        ),
      );

      expect(find.text('Dashboard'), findsNothing);
      expect(find.text('Records'), findsOneWidget);
      expect(find.text('Contracts'), findsOneWidget);

      // Not merely "a tab is highlighted": the pane behind it has to have
      // FETCHED. A stack that believes it is showing slot 0 while the bar
      // believes something else renders nothing at all, and an empty pane is
      // exactly what a reader would report as "the screen is blank".
      await tester.pumpAndSettle();
      expect(find.byType(RecordsTab), findsOneWidget);
    },
  );

  testWidgets('the certifications sentence is on the one HR card', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Human Resources'), findsOneWidget);
    expect(find.text('Expiring certifications'), findsNothing);
    expect(find.text('Not built yet'), findsNothing);

    expect(find.textContaining('marked as an expiry'), findsOneWidget);
  });

  /// Onboarding stopped being a placeholder. Only that it opens and reaches the
  /// server; everything the tab and the form DO is defended in
  /// client/test/hr_onboarding_test.dart.
  testWidgets('the Onboarding tab is the real thing, not a placeholder', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Onboarding'));

    expect(find.text('Not built yet'), findsNothing);
    expect(find.text('Start an onboarding'), findsOneWidget);
    expect(find.textContaining('pins the contract version'), findsOneWidget);
  });

  /// Contracts stopped being a placeholder. The tab is what it always said it
  /// would be — the template designer — so the test that used to assert it was
  /// unfinished now asserts that it is not.
  ///
  /// Only that it opens and reaches the server; everything the screen DOES is
  /// defended in client/test/hr_contracts_test.dart, against a catalogue
  /// written so a client with its own copy of the vocabulary cannot pass.
  testWidgets('the Contracts tab is the designer, not a placeholder', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Contracts'));

    expect(find.text('Not built yet'), findsNothing);
    expect(find.text('New contract'), findsOneWidget);
    expect(find.textContaining('what onboarding asks'), findsOneWidget);
  });

  testWidgets('the invite form will not submit until it is complete', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));

    expect(_inviteButton(tester).onPressed, isNull);

    await _type(tester, 'Email', 'nadia@example.com');
    expect(
      _inviteButton(tester).onPressed,
      isNull,
      reason:
          'An invitation carries a role and a place; an address is not one.',
    );

    await _choose(tester, 'Role', 'Technician');
    expect(_inviteButton(tester).onPressed, isNull);

    await _choose(
      tester,
      'Where they are assigned',
      'Serenisima › Connecticut',
    );
    expect(_inviteButton(tester).onPressed, isNotNull);

    // The names are the one part that may be left out — whatever the employer
    // does not fill in, the person completes when they accept.
    expect(find.widgetWithText(TextField, 'First name'), findsOneWidget);
  });

  testWidgets('creating an invitation shows the link and says nothing was '
      'emailed', (tester) async {
    Map<String, dynamic>? sent;

    await _pump(tester, _handler(onInvite: (body) => sent = body));

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));

    await _type(tester, 'Email', 'nadia@example.com');
    await _type(tester, 'First name', 'Nadia');
    await _choose(tester, 'Role', 'Technician');
    await _choose(
      tester,
      'Where they are assigned',
      'Serenisima › Connecticut',
    );

    await _tap(tester, find.text('Create the invitation'));

    // The scope is one dropdown value on screen and two fields on the wire —
    // §4's XOR: one target level, never two.
    expect(sent, {
      'email': 'nadia@example.com',
      'first_name': 'Nadia',
      'last_name': '',
      'role_id': 'role-2',
      'scope_type': 'franchise',
      'scope_id': 'fr-9',
    });

    expect(
      find.text('https://app.ezbizi.com/invite?token=raw-token-1'),
      findsOneWidget,
      reason: 'This response is the only one that ever carries the raw token.',
    );
    expect(find.text('Copy link'), findsOneWidget);

    // The load-bearing sentence, and it names WHICH failure: this box sends
    // nothing at all, which is a different remedy from a refused message.
    expect(
      find.text('Not sent — this installation is not sending mail at all.'),
      findsOneWidget,
      reason:
          'Without this somebody invites a person and waits for an acceptance '
          'to a message that was never sent.',
    );

    // Offered even here, and in every other state — see _strip.
    expect(find.text('Re-invite at a different address'), findsOneWidget);
    expect(find.text('The current link stops working.'), findsOneWidget);
  });

  // Jose, 2026-10-01: "on record i should be able to filter by role too".
  // Asked of the server, because the list arrives a page at a time.
  testWidgets('the records list filters by role on the server', (tester) async {
    final queries = <Map<String, String>>[];

    await _pump(tester, _handler(onPeopleQuery: queries.add));
    await _tap(tester, _tab('Records'));
    expect(queries.last.containsKey('role_id'), isFalse);

    await _choose(tester, 'Filter by role', 'Technician');
    expect(queries.last['role_id'], 'role-2');

    await _choose(tester, 'Filter by role', 'All roles');
    expect(queries.last.containsKey('role_id'), isFalse);
  });

  // Jose, 2026-10-01: "see if you can improve records as well, by adding
  // division by company too aside from role". On the server, like the role.
  testWidgets('the records list filters by company or franchise on the '
      'server', (tester) async {
    final queries = <Map<String, String>>[];

    await _pump(tester, _handler(onPeopleQuery: queries.add));
    await _tap(tester, _tab('Records'));
    expect(queries.last.containsKey('company_id'), isFalse);

    await _choose(tester, 'Filter by company', 'Serenisima › Texas');
    expect(queries.last['franchise_id'], 'fr-3');
    expect(queries.last.containsKey('company_id'), isFalse);

    await _choose(tester, 'Filter by company', 'Serenisima');
    expect(queries.last['company_id'], 'co-1');
    expect(queries.last.containsKey('franchise_id'), isFalse);

    await _choose(tester, 'Filter by company', 'All companies');
    expect(queries.last.containsKey('company_id'), isFalse);
    expect(queries.last.containsKey('franchise_id'), isFalse);
  });

  // Jose, 2026-11-03: Eve fills the HR gaps by hand, so Records can show who
  // has no start date or no contract. One at a time, combined with the role.
  testWidgets('the records list filters by a missing start date or contract', (
    tester,
  ) async {
    final queries = <Map<String, String>>[];

    await _pump(tester, _handler(onPeopleQuery: queries.add));
    await _tap(tester, _tab('Records'));
    expect(queries.last.containsKey('missing'), isFalse);

    await _choose(tester, 'Filter by role', 'Technician');
    await _tap(tester, find.widgetWithText(FilterChip, 'No start date'));
    expect(queries.last['missing'], 'start_date');
    expect(queries.last['role_id'], 'role-2');

    await _tap(tester, find.widgetWithText(FilterChip, 'No contract'));
    expect(queries.last['missing'], 'contract');

    await _tap(tester, find.widgetWithText(FilterChip, 'No contract'));
    expect(queries.last.containsKey('missing'), isFalse);
  });

  testWidgets('Active and Inactive ask the server, not the loaded page', (
    tester,
  ) async {
    final queries = <Map<String, String>>[];

    await _pump(tester, _handler(onPeopleQuery: queries.add));
    await _tap(tester, _tab('Records'));
    expect(queries.last.containsKey('status'), isFalse);

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Inactive'));
    expect(queries.last['status'], 'inactive');

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Active'));
    expect(queries.last['status'], 'active');

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Everyone'));
    expect(queries.last.containsKey('status'), isFalse);
  });

  // Jose, 2026-09-30: the invite can start the paperwork, "but toggable, some
  // people dont need contracts". Off sends no contract; on needs one chosen.
  testWidgets('an invitation can start the paperwork with a contract', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        onInvite: (body) => sent = body,
        contracts: const [
          {'id': 'k-1', 'name': 'Call Center Employee (hourly)'},
        ],
      ),
    );

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));
    await _type(tester, 'Email', 'tommy@example.com');
    await _choose(tester, 'Role', 'Technician');
    await _choose(
      tester,
      'Where they are assigned',
      'Serenisima › Connecticut',
    );

    await _tap(tester, find.byKey(const ValueKey('invite-with-contract')));
    final create = find.widgetWithText(FilledButton, 'Create the invitation');
    expect(
      tester.widget<FilledButton>(create).onPressed,
      isNull,
      reason: 'Switched on, the invite waited for no contract.',
    );

    await _choose(tester, 'Contract', 'Call Center Employee (hourly)');
    await _tap(tester, create);

    expect(sent?['contract_id'], 'k-1');
  });

  /// §4, and the reason the endpoint exists at all.
  ///
  /// An inviter may only assign at or below their own reach. The server works
  /// that out; `/me` carries a franchise list that looks like it would do, and
  /// using it would be a second implementation of an access-control rule in
  /// Dart. Here the two disagree on purpose.
  testWidgets('the places somebody may be assigned come from the server', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));

    final scopes = _dropdown(tester, 'Where they are assigned');

    // The organization first — it is the widest thing on offer — then
    // everything else by its whole path, so a company is followed by the
    // franchises under it rather than by an alphabetical run of Connecticuts.
    // §4: an assignment to a deactivated scope grants nothing, and a
    // switched-off place does not show up at all (Jose, 2026-11-10), so
    // Rhode Island (fr-4) is not drawn.
    expect(scopes.options.map((o) => o.value), [
      'organization:org-1',
      'company:co-1',
      'franchise:fr-9',
    ]);

    expect(scopes.options.map((o) => o.label), [
      'Everdoors',
      'Serenisima',
      'Serenisima › Connecticut',
    ]);

    // `/me` knows about Texas and does not know about Connecticut. Neither
    // fact may show through: the question is what this person may ASSIGN, and
    // that is not the same question as what they can see.
    expect(
      scopes.options.map((o) => o.label).join(' '),
      isNot(contains('Texas')),
      reason: 'The list was assembled from /me instead of from the server.',
    );
  });

  /// §4: nobody hands out a permission they do not hold. The server answers it
  /// per role, and a role that is simply missing from the list leaves somebody
  /// unable to tell "we have no such role" from "that one is not mine to give".
  testWidgets('a role this person may not hand out is shown and refused', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));

    final roles = _dropdown(tester, 'Role');

    expect(roles.options.map((o) => o.label), [
      'Administrator — not yours to give',
      'Technician',
    ]);
    expect(roles.options.where((o) => !o.enabled).map((o) => o.value), [
      'role-1',
    ]);
  });

  testWidgets('resending mints a new link, and says the old one is dead', (
    tester,
  ) async {
    String? resent;

    await _pump(tester, _handler(onResend: (path) => resent = path));

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Resend'));

    expect(resent, '/api/v1/hr/invitations/i-1/resend');

    expect(
      find.text('https://app.ezbizi.com/invite?token=raw-token-2'),
      findsOneWidget,
    );
    expect(
      find.textContaining('previous link has stopped working'),
      findsOneWidget,
    );

    // The other failing state, and it must not be worded as the first: this
    // one was attempted and refused, so the box is not the thing to go and fix.
    expect(
      find.text('Not sent — the message was refused when we tried to send it.'),
      findsOneWidget,
    );
  });

  /// A state this build cannot read must not borrow the reassuring sentence.
  ///
  /// `null` and an unrecognised string are different facts: the first is a
  /// server that said nothing, the second is a server NEWER than this build
  /// that said something. Collapsing them meant a future state — a bounce, a
  /// suppression, anything added after this release — rendering as "may or may
  /// not have been sent" in calm grey, and somebody would stop chasing on the
  /// strength of it. Found by UI/UX reading the switch rather than my
  /// description of it.
  testWidgets('a send state this build does not know is not read as maybe', (
    tester,
  ) async {
    await _pump(tester, _handler(createdState: 'something_from_the_future'));

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Invite somebody'));

    await _type(tester, 'Email', 'nadia@example.com');
    await _type(tester, 'First name', 'Nadia');
    await _choose(tester, 'Role', 'Technician');
    await _choose(
      tester,
      'Where they are assigned',
      'Serenisima › Connecticut',
    );
    await _tap(tester, find.text('Create the invitation'));

    expect(find.textContaining('cannot read'), findsOneWidget);
    expect(find.text('May or may not have been sent.'), findsNothing);
  });

  /// Re-inviting sends the corrected address on the RESEND, one call.
  ///
  /// The call shape is the assertion, not the wording, because the whole
  /// design rests on it: the address rides the reissue so the old token dies
  /// with it. A client that PATCHed the address instead would leave the
  /// already-sent link alive for whoever received the typo.
  testWidgets('re-inviting at a different address rides the resend', (
    tester,
  ) async {
    final calls = <(String, Map<String, dynamic>)>[];

    await _pump(
      tester,
      _handler(onResendBody: (path, body) => calls.add((path, body))),
    );

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Resend'));
    await _tap(tester, find.text('Re-invite at a different address'));

    await _type(tester, 'Email address', 'bea@example.com');
    await _tap(tester, find.widgetWithText(FilledButton, 'Re-invite'));

    expect(calls, hasLength(2), reason: 'The resend and the re-invite.');
    expect(calls.last.$1, endsWith('/resend'));
    expect(
      calls.last.$2,
      {'email': 'bea@example.com'},
      reason: 'A PATCH of the address would leave the sent link alive.',
    );
  });

  testWidgets('revoking asks first, and a refusal is not a silent success', (
    tester,
  ) async {
    await _pump(tester, _handler(revokeStatus: 403));

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Revoke'));

    expect(find.textContaining('The link stops working'), findsOneWidget);

    await _tap(tester, find.widgetWithText(FilledButton, 'Revoke'));

    expect(
      find.text('Only an administrator may revoke an invitation.'),
      findsOneWidget,
    );
    expect(
      find.text('bea@example.com'),
      findsOneWidget,
      reason: 'The row vanished as though the revoke had worked.',
    );
  });

  /// An accepted invitation is a person now. Resending would mint a token for
  /// an account that already exists; revoking still works (Jose, 2026-10-05:
  /// "she should be able to still revoke it. regardless of anything").
  testWidgets('a completed invitation offers revoke but not resend', (
    tester,
  ) async {
    await _pump(tester, _handler(invitations: _completedOnly));

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Completed'), findsOneWidget);
    expect(find.text('Resend'), findsNothing);
    expect(find.text('Revoke'), findsOneWidget);
  });

  testWidgets('somebody with no role is marked as such on the row', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, _tab('Records'));

    // The state this whole module exists to close: roles were definable and no
    // endpoint wrote users.role_id, so an organization stayed one person.
    expect(find.text('No role'), findsOneWidget);
    expect(find.text('Administrator'), findsOneWidget);

    // Both places somebody is assigned — §4 allows several, and showing only
    // the first would hide half of their reach.
    expect(find.text('bea@example.com · Connecticut · Texas'), findsOneWidget);
  });

  testWidgets('opening a record shows their Training card, asked about them', (
    tester,
  ) async {
    String? askedAbout;

    await _pump(tester, _handler(onTraining: (user) => askedAbout = user));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Training'));

    // Their record, on its Training section: Training's card about the PERSON
    // — the counts strip at this width, the lapsed row, and the line saying
    // whose it is to act on.
    expect(find.byKey(TrainingCard.countsKey), findsOneWidget);
    expect(find.text('Ladder safety'), findsOneWidget);
    expect(
      find.textContaining('is ours to re-assign', findRichText: true),
      findsOneWidget,
    );
    expect(askedAbout, 'u-2');
  });

  testWidgets('changing a role sends the role and nothing else', (
    tester,
  ) async {
    Map<String, dynamic>? patched;
    String? path;

    await _pump(
      tester,
      _handler(
        onPatchPerson: (p, body) {
          path = p;
          patched = body;
        },
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Employment'));
    await _choose(tester, 'Role', 'Technician');

    expect(
      path,
      isNull,
      reason: 'Choosing is not saving — the section has a Save.',
    );

    await _tap(tester, find.text('Save employment'));

    expect(path, '/api/v1/hr/people/u-2');
    expect(patched, {'role_id': 'role-2'});
  });

  // Design 188: several places, each added or removed on its own.
  testWidgets('Works at adds a place and removes one after a second tap', (
    tester,
  ) async {
    final sent = <(String, String, String)>[];

    await _pump(
      tester,
      _handler(
        onPlaces: (method, path, body) => sent.add((method, path, body)),
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Employment'));

    expect(find.byKey(const ValueKey('hr-place:a-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('hr-place:a-3')), findsOneWidget);

    await _tap(tester, find.byKey(const ValueKey('hr-add-place')));
    await _tap(tester, find.text('Everdoors').last);
    expect(sent.single.$1, 'POST');
    expect(sent.single.$2, endsWith('/hr/people/u-2/places'));
    expect(jsonDecode(sent.single.$3), {
      'scope_type': 'organization',
      'scope_id': 'org-1',
    });

    sent.clear();
    await _tap(tester, find.byKey(const ValueKey('hr-place-x:a-3')));
    expect(sent, isEmpty, reason: 'The first tap only asks.');
    await _tap(tester, find.byKey(const ValueKey('hr-place-remove:a-3')));
    expect(sent.single.$1, 'DELETE');
    expect(sent.single.$2, endsWith('/hr/people/u-2/places/a-3'));
  });

  // Layout A (Mia, 2026-10-02): the tiles are the server's counts, and one
  // asks the server for its slice rather than filtering a page.
  testWidgets('an Invitations tile shows its count and asks for its slice', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(tester, _handler(onInvitationsQuery: asked.add));

    await _tap(tester, _tab('Invitations'));
    expect(
      find.descendant(
        of: find.byKey(const Key('invitations-tile-abandoned')),
        matching: find.text('2'),
      ),
      findsOneWidget,
    );

    await _tap(tester, find.byKey(const Key('invitations-tile-abandoned')));
    expect(asked.last['status'], 'abandoned');
  });

  testWidgets('deactivating asks first, and says the record survives it', (
    tester,
  ) async {
    Map<String, dynamic>? patched;

    await _pump(tester, _handler(onPatchPerson: (_, body) => patched = body));

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Employment'));
    await _tap(
      tester,
      find.descendant(
        of: find.byType(SegmentedButton<bool>),
        matching: find.text('Inactive'),
      ),
    );
    await _tap(tester, find.text('Save employment'));

    // The owner's rule, stated where the decision is made: every employee is a
    // user account and the record survives deactivation.
    expect(
      find.textContaining('The record stays exactly where it is'),
      findsOneWidget,
    );
    expect(patched, isNull);

    await _tap(tester, find.widgetWithText(FilledButton, 'Make inactive'));

    expect(patched, {'is_active': false});
  });

  /// The lost-phone path, from the person who is not locked out.
  ///
  /// Built after Rodrigo lost his authenticator and the only way back in was
  /// a shell on the production box. It is its own key — hr.edit does not
  /// carry it — and it asks first, because it signs the colleague out
  /// everywhere.
  // Jose, 2026-10-20: "different than deactivation, we are missing full
  // deletion".
  testWidgets('deleting permanently lists what goes and waits for the name', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler(
        onDeletePerson: (body) => sent = body,
        me: {
          ..._me,
          'permissions': [
            'hr.view',
            'hr.edit',
            'hr.delete',
            'hr.dashboard.view',
            'hr.onboarding.view',
            'hr.records.view',
            'hr.invitations.view',
            'hr.contracts.view',
          ],
        },
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Access'));
    await _tap(tester, find.byKey(const Key('delete-permanently')));

    expect(find.text('• Personal access tokens: 1'), findsOneWidget);
    expect(find.text('• Invitations'), findsOneWidget);
    expect(find.text('• Onboarding answers'), findsOneWidget);

    final confirm = find.byKey(const Key('delete-permanently-confirm'));
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull);

    await tester.enterText(
      find.byKey(const Key('delete-permanently-name')),
      'Bea Okafor',
    );
    await tester.pump();
    await _tap(tester, confirm);

    expect(sent, {'confirm': 'Bea Okafor'});
  });

  testWidgets('without hr.delete the button says why and does nothing', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        me: {
          ..._me,
          'permissions': [
            'hr.view',
            'hr.edit',
            'hr.dashboard.view',
            'hr.records.view',
          ],
        },
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Access'));

    final button = find.byKey(const Key('delete-permanently'));
    expect(button, findsOneWidget);
    expect(tester.widget<ButtonStyleButton>(button).onPressed, isNull);
  });

  // Jose, 2026-10-21: days already used, so a balance starts right.
  testWidgets('time off shows the balance and the days recorded as used', (
    tester,
  ) async {
    final writes = <(String, String, Map<String, dynamic>?)>[];
    await _pump(
      tester,
      _handler(
        onDetail: (method, path, body) => writes.add((method, path, body)),
        pto: {
          'allowance': 2,
          'allowance_period': 'quarter',
          'eligible': true,
          'eligible_from': null,
          'total': 6,
          'used': 2.5,
          'left': 3.5,
          'resets_on': null,
          'expired': 4,
        },
        ptoUsed: [
          {'id': 'p-1', 'days': 1.5, 'used_on': null, 'note': 'Before'},
        ],
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Time off'));

    expect(find.text('2 days a quarter'), findsOneWidget);
    expect(
      find.text('3.5 days left of 6 days, 2.5 days used · 4 days expired.'),
      findsOneWidget,
    );
    expect(find.text('1.5 days used'), findsOneWidget);
    expect(find.text('before EzBizi · Before'), findsOneWidget);
  });

  testWidgets('resetting an authenticator asks first, then posts', (
    tester,
  ) async {
    String? posted;

    await _pump(
      tester,
      _handler(
        onPostPerson: (path) => posted = path,
        me: {
          ..._me,
          'permissions': [
            'hr.view',
            'hr.edit',
            'hr.security.reset',
            'hr.dashboard.view',
            'hr.hours.view',
            'hr.onboarding.view',
            'hr.records.view',
            'hr.invitations.view',
            'hr.pto.view',
            'hr.contracts.view',
          ],
        },
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Access'));
    await _tap(tester, find.text('Reset their authenticator'));

    // Says what it does before it does it — signed out everywhere, and that
    // nobody, including the administrator, is shown the new secret.
    expect(find.textContaining('signed out on every device'), findsOneWidget);
    expect(find.textContaining('you are never shown it'), findsOneWidget);
    expect(posted, isNull, reason: 'it reset before anybody confirmed');

    await _tap(tester, find.widgetWithText(FilledButton, 'Reset it'));

    expect(posted, '/api/v1/hr/people/u-2/totp/reset');
  });

  testWidgets('a password reset link is posted for, then shown to hand over', (
    tester,
  ) async {
    String? posted;

    await _pump(
      tester,
      _handler(
        onPostPerson: (path) => posted = path,
        me: {
          ..._me,
          'permissions': [
            'hr.view',
            'hr.edit',
            'hr.security.reset',
            'hr.dashboard.view',
            'hr.hours.view',
            'hr.onboarding.view',
            'hr.records.view',
            'hr.invitations.view',
            'hr.pto.view',
            'hr.contracts.view',
          ],
        },
      ),
    );

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Access'));
    await _tap(tester, find.text('Password reset link'));

    expect(posted, '/api/v1/hr/people/u-2/reset-link');
    // The link is on screen to copy, not emailed — somebody locked out of
    // their mailbox is the case this exists for.
    expect(
      find.text('https://app.example.test/reset?token=abc123'),
      findsOneWidget,
    );
    expect(find.text('Copy and close'), findsOneWidget);
  });

  /// hr.edit alone is what every other test in this file holds, and it must
  /// not reach these. Shown and refused rather than hidden, with the reason on
  /// the item, the same as every other refused control on this list.
  testWidgets('without hr.security.reset both recovery items are refused', (
    tester,
  ) async {
    String? posted;

    await _pump(tester, _handler(onPostPerson: (path) => posted = path));

    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Access'));

    for (final label in ['Reset their authenticator', 'Password reset link']) {
      final button = tester.widget<ButtonStyleButton>(
        find
            .ancestor(
              of: find.text(label),
              matching: find.byWidgetPredicate((w) => w is ButtonStyleButton),
            )
            .first,
      );
      expect(
        button.onPressed,
        isNull,
        reason: '"$label" was offered without the key',
      );
    }
    expect(
      find.textContaining('reset somebody’s sign-in security'),
      findsNWidgets(2),
    );

    await _tap(tester, find.text('Reset their authenticator'));
    expect(posted, isNull, reason: 'a refused item still posted');
  });

  testWidgets('with no role to hand out, inviting is refused and says why', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(scopeOptions: {..._scopeOptions, 'roles': const []}),
    );

    await _tap(tester, _tab('Invitations'));

    expect(_inviteSomebody(tester).onPressed, isNull);
    expect(find.textContaining('no role you may hand out'), findsOneWidget);
  });

  /// Somebody whose role opens this tab and does not add to it. A 403 from
  /// scope-options is the ordinary answer for them, not a failure, and dressing
  /// it up as one would send them looking for a fault that is not there.
  testWidgets('a reader who may not invite is told that, not shown an error', (
    tester,
  ) async {
    await _pump(tester, _handler(scopeOptionsStatus: 403));

    await _tap(tester, _tab('Invitations'));

    expect(_inviteSomebody(tester).onPressed, isNull);
    expect(
      find.text('You can see who has been invited, but not invite anybody.'),
      findsOneWidget,
    );

    // And the list is still there. Not being allowed to add to it does not make
    // it unreadable.
    expect(find.text('bea@example.com'), findsOneWidget);
  });

  /// The endpoint that says which roles are this person's to hand out is the
  /// invite form's, so somebody who may edit people and not invite them has no
  /// list to choose from. The action goes quiet and says why — a dead menu item
  /// with no reason is indistinguishable from a bug.
  testWidgets('without the roles, Records says so rather than going quiet', (
    tester,
  ) async {
    await _pump(tester, _handler(scopeOptionsStatus: 403));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Employment'));

    // Said beside the control it is about, rather than once at the top of a
    // list where nobody connects it to a greyed field.
    expect(find.textContaining('roles are read-only here'), findsOneWidget);
    expect(_dropdown(tester, 'Role').onChanged, isNull);

    // And the rest of the record still works. Not being able to hand out a
    // role does not stop switching somebody off, or anything else here.
    expect(
      tester
          .widget<SegmentedButton<bool>>(find.byType(SegmentedButton<bool>))
          .onSelectionChanged,
      isNotNull,
    );
  });

  // ── Records: everybody on the left, one whole record on the right ──
  //
  // Jose, 2026-09-24, desktop 2 and phone 5 from
  // docs/previews/hr-records-person-layouts.html.

  /// Nothing opens until somebody is chosen — and nothing is READ either.
  ///
  /// Opening a record is an audited read of somebody's personal details, so
  /// opening the first person automatically would log a read nobody made.
  testWidgets('no record is opened, or asked for, until somebody is chosen', (
    tester,
  ) async {
    final asked = <String>[];

    await _pump(tester, _handler(onRecord: asked.add));
    await _tap(tester, _tab('Records'));

    expect(
      find.text('Choose somebody on the left to see their whole record.'),
      findsOneWidget,
    );
    expect(asked, isEmpty, reason: 'A record was fetched that nobody opened.');

    // The control: choosing somebody DOES ask, exactly once and for them.
    await _openPerson(tester, 'Bea Okafor');

    expect(asked, ['u-2']);
    expect(find.text('41 Elm St, Stamford CT'), findsOneWidget);
  });

  /// Only what changed is sent — a phone number cannot carry their name, or
  /// anything else, along with it.
  testWidgets('saving personal details sends only the field that changed', (
    tester,
  ) async {
    Map<String, dynamic>? patched;

    await _pump(tester, _handler(onPatchPerson: (_, body) => patched = body));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    // Save is off until something differs from what was saved.
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'Save personal details'),
          )
          .onPressed,
      isNull,
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Phone'),
      '(203) 555-0199',
    );
    await tester.pump();
    await _tap(tester, find.text('Save personal details'));

    // Sent as E.164, as Your account sends it: the box shows the pretty form,
    // the API and Twilio key on this one (Jose, 2026-09-30).
    expect(patched, {'phone': '+12035550199'});
  });

  /// Jose, 2026-09-30: the phone "lacking formatting", and the home address
  /// as the lookup and its parts, as on Your account. Formatting a stored
  /// number is not a change to it; a record from before the parts keeps its
  /// one line as the street.
  testWidgets('the phone reads formatted and the address comes in parts', (
    tester,
  ) async {
    Map<String, dynamic>? patched;

    await _pump(tester, _handler(onPatchPerson: (_, body) => patched = body));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    String box(String label) => tester
        .widget<TextField>(find.widgetWithText(TextField, label))
        .controller!
        .text;
    expect(box('Phone'), '+1 (203) 555-0148');
    expect(box('Street'), '41 Elm St, Stamford CT');
    expect(find.widgetWithText(TextField, 'Country'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'Save personal details'),
          )
          .onPressed,
      isNull,
      reason: 'nothing was changed, only shown',
    );

    await tester.enterText(find.widgetWithText(TextField, 'City'), 'Stamford');
    await tester.pump();
    await _tap(tester, find.text('Save personal details'));

    // The address goes whole: one address, and the one line contracts print
    // is composed from all of it.
    expect(patched, {
      'address_line1': '41 Elm St, Stamford CT',
      'address_line2': null,
      'city': 'Stamford',
      'state': null,
      'postal_code': null,
      'country': null,
    });
  });

  /// Email is shown and not editable, and says why where it is drawn.
  testWidgets('email is read only on the record, with the reason beside it', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    expect(find.widgetWithText(TextField, 'Email'), findsNothing);
    expect(find.textContaining('They sign in with this'), findsOneWidget);
  });

  /// Jose, 2026-09-29: "i also want all the missing field to light up like we
  /// do on marketing SEO with the counters on the list as well".
  testWidgets('an empty box lights up, and the list counts them', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, _tab('Records'));

    // The server's count beside Bea; nothing at all beside a zero.
    expect(find.byTooltip('3 fields not filled in'), findsOneWidget);
    expect(find.byTooltip('0 fields not filled in'), findsNothing);

    await _openPerson(tester, 'Bea Okafor');

    TextField box(String label) =>
        tester.widget<TextField>(find.widgetWithText(TextField, label));

    // Gender is a picker now (design 186), saying so while empty.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('hr-gender')),
        matching: find.text('Not filled in'),
      ),
      findsOneWidget,
    );
    // The control: a box with something in it is not amber.
    expect(box('Phone').decoration?.hintText, isNull);
    // And an empty middle name is not amber either. Most people have none,
    // and nothing can record that, so it would stay lit for ever.
    expect(box('Middle name').decoration?.hintText, isNull);

    // The date of birth is the house date field, three boxes rather than one,
    // and says the same: amber boxes and "Not filled in" under them.
    final born = find.byKey(const ValueKey('hr-date-of-birth'));
    expect(
      find.descendant(of: born, matching: find.text('Not filled in')),
      findsOneWidget,
    );
    expect(
      tester
          .widget<TextField>(
            find.descendant(of: born, matching: find.byType(TextField)).first,
          )
          .decoration
          ?.filled,
      isTrue,
    );

    // Where the three are: two under Personal, the start date under
    // Employment, so the number on the list can be found.
    expect(
      find.descendant(of: _section('Personal'), matching: find.text('2')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: _section('Employment'), matching: find.text('1')),
      findsOneWidget,
    );

    // Picking takes it out of amber there and then, from the three answers
    // Jose named (2026-11-06).
    await tester.ensureVisible(find.byKey(const ValueKey('hr-gender')));
    await tester.tap(find.byKey(const ValueKey('hr-gender')));
    await tester.pumpAndSettle();
    expect(find.text('Prefers not to answer'), findsWidgets);
    await tester.tap(find.text('Female').last);
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('hr-gender')),
        matching: find.text('Not filled in'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('hr-gender')),
        matching: find.text('Female'),
      ),
      findsOneWidget,
    );
  });

  // Jose, 2026-09-30: "it should highlight all the missing stuff", not only
  // Personal. No emergency contact, no pay rate and no schedule count; no
  // equipment and no notes do not, since most people have none of either.
  testWidgets('an empty contact, pay and schedule light up their tabs', (
    tester,
  ) async {
    await _pump(tester, _handler(payRates: const []));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    for (final lit in ['Emergency contacts', 'Pay', 'Work']) {
      expect(
        find.descendant(of: _section(lit), matching: find.text('1')),
        findsOneWidget,
        reason: '$lit carries no count',
      );
    }
    for (final quiet in ['Equipment', 'Notes']) {
      expect(
        find.descendant(of: _section(quiet), matching: find.text('1')),
        findsNothing,
        reason: 'no $quiet is not a gap',
      );
    }

    for (final section in ['Emergency contacts', 'Pay', 'Work']) {
      await _tap(tester, _section(section));
      expect(find.text('Not filled in'), findsOneWidget, reason: section);
    }
  });

  /// Jose, 2026-09-29: "I want to be able to resize the sidebar here on human
  /// resources".
  testWidgets('the list resizes by its edge, within limits', (tester) async {
    final kept = <Map<String, dynamic>>[];
    await _pump(tester, _handler(onPreference: kept.add));
    await _tap(tester, _tab('Records'));

    final edge = find.byKey(const ValueKey('hr-list-edge'));
    double list() =>
        tester.getTopLeft(edge).dx -
        tester.getTopLeft(find.byType(RecordsTab)).dx;

    expect(list(), 300);

    await tester.drag(edge, const Offset(150, 0));
    await tester.pumpAndSettle();
    expect(list(), greaterThan(400));
    // Kept where it was left, so leaving the tab does not undo it.
    expect(kept.last, {'hr.records.list_width': list().round()});

    await tester.drag(edge, const Offset(-2000, 0));
    await tester.pumpAndSettle();
    expect(list(), 240, reason: 'It narrows; it cannot be dragged away.');

    await tester.drag(edge, const Offset(4000, 0));
    await tester.pumpAndSettle();
    expect(
      list(),
      tester.getSize(find.byType(RecordsTab)).width - 420,
      reason: 'Nor can it push the record out.',
    );

    // A double-click puts it back, and keeps that too.
    await tester.tap(edge);
    await tester.pump(const Duration(milliseconds: 50));
    await tester.tap(edge);
    await tester.pumpAndSettle();
    expect(list(), 300);
    expect(kept.last, {'hr.records.list_width': 300});
  });

  testWidgets('the list opens at the width it was left at', (tester) async {
    await _pump(
      tester,
      _handler(
        me: {
          ..._me,
          'preferences': {'hr.records.list_width': 520},
        },
      ),
    );
    await _tap(tester, _tab('Records'));

    final edge = find.byKey(const ValueKey('hr-list-edge'));
    expect(
      tester.getTopLeft(edge).dx -
          tester.getTopLeft(find.byType(RecordsTab)).dx,
      520,
    );
  });

  /// What onboarding collected still opens full page, and comes back to the
  /// person rather than to an empty list.
  testWidgets('an onboarding opens full page and returns to the person', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Onboarding'));
    await _tap(tester, find.byIcon(Icons.chevron_right).first);

    expect(find.text('Back to Bea Okafor'), findsOneWidget);

    await _tap(tester, find.text('Back to Bea Okafor'));

    expect(
      find.text('41 Elm St, Stamford CT'),
      findsNothing,
      reason: 'It came back to the Onboarding section, not Personal.',
    );
    expect(find.byIcon(Icons.chevron_right), findsWidgets);
  });

  /// A phone has no room for a sidebar: the list, then the record over it,
  /// with a way back.
  testWidgets('on a phone the record replaces the list and has a way back', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(390, 900));
    await _tap(tester, _tab('Records'));

    expect(find.text('Bea Okafor'), findsOneWidget);
    expect(
      find.text('Choose somebody on the left to see their whole record.'),
      findsNothing,
      reason: 'There is no "left" on a phone.',
    );

    await _openPerson(tester, 'Bea Okafor');

    expect(
      find.text('Jose Silva'),
      findsNothing,
      reason: 'The list is still drawn beside the record.',
    );
    expect(find.byTooltip('All people'), findsOneWidget);

    await _tap(tester, find.byTooltip('All people'));

    expect(find.text('Jose Silva'), findsOneWidget);
  });

  // The person and the section strip stood over the form, and with the HR
  // chrome above them took 270 of a phone's 800 pixels.
  testWidgets("on a phone the record's header scrolls away with its form", (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(390, 700));
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    expect(find.byTooltip('All people'), findsOneWidget);

    await tester.drag(
      find.byType(SingleChildScrollView).last,
      const Offset(0, -300),
    );
    await tester.pumpAndSettle();

    // A finder skips what a viewport has scrolled out of view.
    expect(find.byTooltip('All people'), findsNothing);
  });

  // ── the four lists on a record (2026-09-29) ─────────────────────

  /// Pay is not a section for somebody the server sent no pay: null is "you may
  /// not see it", and a tab that could only refuse is not drawn.
  testWidgets('pay is not a section without hr.pay.view', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');

    expect(_section('Pay'), findsNothing);
    // The control: the other three new sections ARE drawn for this reader.
    expect(_section('Emergency contacts'), findsOneWidget);
    expect(_section('Equipment'), findsOneWidget);
    expect(_section('Notes'), findsOneWidget);
  });

  /// Seeing pay is not changing it: `hr.pay.edit` is its own key, and the
  /// section says so instead of offering a button the server would refuse.
  testWidgets('pay shows, and says why it cannot be changed', (tester) async {
    await _pump(
      tester,
      _handler(
        payRates: const [
          {
            'id': 'p-1',
            'basis': 'hourly',
            'amount': '27.50',
            'period': 'hour',
            'effective_on': '2026-06-01',
            'note': null,
          },
        ],
      ),
    );
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Pay'));

    expect(find.text('27.50 an hour'), findsOneWidget);
    expect(find.text('Add pay rate'), findsNothing);
    expect(find.textContaining('change pay rates'), findsOneWidget);
  });

  testWidgets('adding an emergency contact sends what the form asked', (
    tester,
  ) async {
    final writes = <(String, String, Map<String, dynamic>?)>[];

    await _pump(
      tester,
      _handler(
        onDetail: (method, path, body) => writes.add((method, path, body)),
      ),
    );
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Emergency contacts'));
    await _tap(tester, find.text('Add emergency contact'));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name'),
      'Ana Okafor',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Phone'),
      '(203) 555-0101',
    );
    await tester.pump();
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(writes, hasLength(1));
    expect(writes.single.$1, 'POST');
    expect(
      writes.single.$2,
      '/api/v1/hr/people/u-2/details/emergency-contacts',
    );
    expect(writes.single.$3, {
      'name': 'Ana Okafor',
      'relationship': null,
      'phone': '(203) 555-0101',
    });
  });

  /// Equipment is handed back rather than deleted: the row stays as the record
  /// of what was returned, so the button is "Mark returned", with today's date.
  testWidgets('equipment still held can be marked returned', (tester) async {
    final writes = <(String, String, Map<String, dynamic>?)>[];

    await _pump(
      tester,
      _handler(
        equipment: const [
          {
            'id': 'e-1',
            'name': 'Ladder',
            'category': 'tool',
            'quantity': 1,
            'identifier': null,
            'issued_on': '2026-05-01',
            'returned_on': null,
            'note': null,
          },
        ],
        onDetail: (method, path, body) => writes.add((method, path, body)),
      ),
    );
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Equipment'));

    expect(find.textContaining('still held'), findsOneWidget);

    await _tap(tester, find.text('Mark returned'));

    final now = DateTime.now();
    final today =
        '${now.year.toString().padLeft(4, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.day.toString().padLeft(2, '0')}';

    expect(writes.single.$1, 'PATCH');
    expect(writes.single.$2, '/api/v1/hr/people/u-2/details/equipment/e-1');
    expect(writes.single.$3, {'returned_on': today});
  });

  testWidgets('a note says who wrote it', (tester) async {
    await _pump(
      tester,
      _handler(
        notes: const [
          {
            'id': 'n-1',
            'body': 'Prefers the Stamford jobs.',
            'author': 'Jose Silva',
            'created_at': '2026-09-20T10:00:00Z',
          },
        ],
      ),
    );
    await _tap(tester, _tab('Records'));
    await _openPerson(tester, 'Bea Okafor');
    await _tap(tester, _section('Notes'));

    expect(find.text('Prefers the Stamford jobs.'), findsOneWidget);
    expect(find.textContaining('Jose Silva ·'), findsOneWidget);
    expect(
      find.textContaining('The person does not see these.'),
      findsOneWidget,
    );
  });

  /// "Abandoned" used to be one word for two different things — nobody came,
  /// and we changed our mind — and the two want opposite responses. They are two
  /// words now, and this is what proves the screen uses both.
  testWidgets('a withdrawn invitation is not confused with one that ran out', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Revoked'), findsOneWidget);
    expect(find.textContaining('withdrawn'), findsOneWidget);

    expect(find.text('Abandoned'), findsOneWidget);
    expect(find.textContaining('expired'), findsOneWidget);
  });

  /// Withdrawing is a decision, not a gap in the process.
  ///
  /// The server refuses to resend a revoked invitation with a 422 — "send a new
  /// one if you have changed your mind" — so the button could only ever have
  /// answered an error.
  testWidgets('a revoked invitation offers neither resend nor revoke', (
    tester,
  ) async {
    await _pump(tester, _handler(invitations: _revokedOnly));

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Revoked'), findsOneWidget);
    expect(find.text('Resend'), findsNothing);
    expect(find.text('Revoke'), findsNothing);
  });

  /// The other half of the same change, and the easy mistake to make with it.
  ///
  /// An expired invitation is the row Resend exists for: chasing one that
  /// lapsed is the single most useful thing the button does, and the server
  /// allows it deliberately. Hiding it here alongside the revoked one would
  /// have cost the feature its main use.
  testWidgets('an expired invitation can still be chased', (tester) async {
    await _pump(tester, _handler(invitations: _expiredOnly));

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Abandoned'), findsOneWidget);
    expect(find.text('Resend'), findsOneWidget);
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
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
        body: HrScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The tab strip's own chip. Every tab label is also a heading on the page
/// behind it, so an unscoped `find.text` matches two things once it is open.
Finder _tab(String label) => find.widgetWithText(ChoiceChip, label);

Future<void> _openPerson(WidgetTester tester, String name) =>
    _tap(tester, find.text(name).first);

/// A section of the open record, found in the record's OWN strip by key —
/// "Onboarding" is also a module chip, and a positional finder would re-aim
/// silently if another strip were ever drawn below this one.
Finder _section(String label) => find.descendant(
  of: find.byKey(RecordsTab.sectionsKey),
  matching: find.widgetWithText(ChoiceChip, label),
);

List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(
      find.descendant(
        of: find.byType(TabBarLike),
        matching: find.byType(ChoiceChip),
      ),
    )
    .map((chip) => (chip.label as Text).data!)
    .toList();

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

/// Type into the box carrying a label, and let the form react — `enterText`
/// returns without a frame, so anything asserted straight after would be
/// reading the tree as it was before the keystroke.
Future<void> _type(WidgetTester tester, String label, String text) async {
  await tester.enterText(find.widgetWithText(TextField, label).first, text);
  await tester.pumpAndSettle();
}

/// Open a dropdown by its label and take the named option.
Future<void> _choose(WidgetTester tester, String label, String option) async {
  await _tap(tester, find.widgetWithText(SearchPickerField<String>, label));
  await _tap(tester, find.text(option).last);
}

/// The picker behind one labelled field. It carries its own options, so this
/// no longer reaches through a FormField for the button underneath it.
SearchPickerField<String> _dropdown(WidgetTester tester, String label) =>
    tester.widget<SearchPickerField<String>>(
      find.widgetWithText(SearchPickerField<String>, label),
    );

FilledButton _inviteButton(WidgetTester tester) => tester.widget<FilledButton>(
  find.widgetWithText(FilledButton, 'Create the invitation'),
);

/// The header's own button, which is dead whenever there is no complete
/// invitation this person could send.
FilledButton _inviteSomebody(WidgetTester tester) => tester
    .widget<FilledButton>(find.widgetWithText(FilledButton, 'Invite somebody'));

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onInvite,
  void Function(String path)? onResend,
  // The BODY as well as the path: re-inviting at a corrected address is the
  // same call carrying an email, and asserting the path alone cannot tell the
  // two apart.
  void Function(String path, Map<String, dynamic> body)? onResendBody,
  void Function(String path, Map<String, dynamic> body)? onPatchPerson,
  void Function(String path)? onPostPerson,
  void Function(String method, String path, String body)? onPlaces,
  void Function(String? user)? onTraining,
  void Function(String id)? onRecord,
  // The four lists on a record (2026-09-29). `payRates` null is what the server
  // sends a reader without `hr.pay.view`.
  List<Map<String, Object?>>? payRates,
  List<Map<String, Object?>> equipment = const [],
  List<Map<String, Object?>> notes = const [],
  void Function(String method, String path, Map<String, dynamic>? body)?
  onDetail,
  // The signed-in person, so a test can hold or lack a key. Defaults to the
  // administrator every other test in this file drives.
  Map<String, Object?> me = _me,
  void Function(Map<String, dynamic> preferences)? onPreference,
  // A `sent_state` this build has never heard of, for the case where the
  // server is newer than the client reading it.
  String? createdState,
  List<Map<String, Object?>> invitations = _invitations,
  List<Map<String, Object?>> contracts = const [],
  void Function(Map<String, String> query)? onPeopleQuery,
  void Function(Map<String, String> query)? onInvitationsQuery,
  Map<String, Object?> scopeOptions = _scopeOptions,
  int scopeOptionsStatus = 200,
  int revokeStatus = 200,
  void Function(Map<String, dynamic> body)? onDeletePerson,
  Map<String, Object?>? pto,
  List<Map<String, Object?>> ptoUsed = const [],
}) {
  return (request) async {
    final path = request.url.path;

    // Permanent deletion (2026-10-20): the dry run, then the act.
    // The production payload's shape (0.0.0461 crashed on it): columns_erased
    // is a map when there are any, PHP's empty list when not.
    if (path.endsWith('/deletion')) {
      return _json(
        jsonDecode(
              File('test/fixtures/hr_deletion_preview.json').readAsStringSync(),
            )
            as Map<String, dynamic>,
      );
    }
    if (request.method == 'DELETE' &&
        RegExp(r'/hr/people/[^/]+$').hasMatch(path)) {
      onDeletePerson?.call(
        (jsonDecode(request.body) as Map).cast<String, dynamic>(),
      );
      return _json({
        'data': {'deleted': true},
      });
    }

    if (path.endsWith('/me/preferences')) {
      onPreference?.call(
        ((jsonDecode(request.body) as Map)['preferences'] as Map)
            .cast<String, dynamic>(),
      );
      return _json({'data': <String, dynamic>{}});
    }

    if (path.endsWith('/me')) return _json({'data': me});

    // The tab this screen now opens on, so every test in this file pays for it.
    // Enough for it to draw; what the chip DOES is defended in
    // client/test/hr_dashboard_test.dart.
    if (path.endsWith('/hr/dashboard')) {
      return _json({
        'period': 'month',
        'period_starts_on': '2026-08-01T00:00:00-04:00',
        'period_ends_on': '2026-08-24T16:00:00-04:00',
        'glance': _dashboardGlance,
      });
    }

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      return _json({'ok': true});
    }

    // Enough for the Contracts tab to open and say it has nothing yet. What
    // that tab actually does is defended in hr_contracts_test.dart, against a
    // catalogue written to catch a client reading its own vocabulary — a second
    // copy of that fixture here would be a second thing to keep in step.
    if (path.endsWith('/hr/contracts/catalogue')) {
      return _json({
        'data': {
          'types': [
            {'key': 'text', 'label': 'Text'},
          ],
          'filled_by': [
            {'key': 'employer', 'label': 'The employer'},
            {'key': 'employee', 'label': 'The employee'},
            {'key': 'either', 'label': 'Either'},
          ],
          'core': <Map<String, Object?>>[],
        },
      });
    }

    if (path.endsWith('/hr/contracts')) {
      return _json({'data': contracts});
    }

    // Enough for the Onboarding tab to open and say it has nobody in flight.
    // What that tab actually does is defended in hr_onboarding_test.dart,
    // against a form written to catch a client deciding for itself who may
    // fill in what — a second copy of that fixture here would be a second
    // thing to keep in step.
    // Bea has one record, so opening it goes straight to the page. The form
    // itself is defended in hr_onboarding_test.dart; this one is the least a
    // record can be, there so the Training card beneath it has a page to be on.
    if (path.endsWith('/hr/onboardings/o-2')) {
      return _json({'data': _beaRecord});
    }

    if (path.endsWith('/training/records')) {
      onTraining?.call(request.url.queryParameters['user']);
      return _json({
        'user': {'id': 'u-2', 'name': 'Bea Okafor'},
        'data': [
          {
            'type': 'test',
            'id': 't-1',
            'title': 'Ladder safety',
            'assigned': true,
            'score': 88,
            'passed': true,
            'expires_on': '2026-03-12',
            'due_for_retaking': true,
          },
        ],
      });
    }

    if (path.endsWith('/hr/onboardings')) {
      return _json({
        'data': request.url.queryParameters['user_id'] == 'u-2'
            ? [_beaRecord]
            : <Map<String, Object?>>[],
        'meta': {'total': 0, 'per_page': 50, 'current_page': 1, 'last_page': 1},
      });
    }

    if (path.endsWith('/hr/people/scope-options')) {
      return scopeOptionsStatus == 200
          ? _json({'data': scopeOptions})
          : _json({
              'message': 'You may not invite people.',
            }, scopeOptionsStatus);
    }

    // One person's record — HrPeopleController::show, the list row plus what
    // the list deliberately leaves out.
    if (path.contains('/places')) {
      onPlaces?.call(request.method, path, request.body);
      return _json({'data': _people[1]});
    }
    if (request.method == 'GET' &&
        RegExp(r'/hr/people/u-\d+$').hasMatch(path)) {
      final id = path.split('/').last;
      onRecord?.call(id);
      return _json({
        'data': {
          ..._people.firstWhere((person) => person['id'] == id),
          'middle_name': null,
          'phone': '(203) 555-0148',
          'address': '41 Elm St, Stamford CT',
          'gender': null,
          'date_of_birth': null,
          'started_on': null,
          'pto': pto,
          'pto_used': ptoUsed,
          'emergency_contacts': <Map<String, Object?>>[],
          'notes': notes,
          'equipment': equipment,
          'pay_rates': payRates,
        },
      });
    }

    if (path.contains('/details/')) {
      onDetail?.call(
        request.method,
        path,
        request.body.isEmpty
            ? null
            : jsonDecode(request.body) as Map<String, dynamic>,
      );
      return _json({
        'data': <String, Object?>{'id': 'd-1'},
      }, 201);
    }

    if (path.endsWith('/hr/people')) {
      onPeopleQuery?.call(request.url.queryParameters);
      return _json({
        'data': _people,
        'meta': {
          'total': _people.length,
          'per_page': 50,
          'current_page': 1,
          'last_page': 1,
        },
      });
    }

    if (path.contains('/hr/people/') && request.method == 'POST') {
      onPostPerson?.call(path);
      if (path.endsWith('/totp/reset')) {
        return _json({
          'data': {
            'had_factor': true,
            'note':
                'Their authenticator is removed and they have been signed out everywhere.',
          },
        });
      }
      if (path.endsWith('/reset-link')) {
        return _json({
          'data': {
            'url': 'https://app.example.test/reset?token=abc123',
            'expires_at': '2026-09-03T21:00:00-04:00',
            'note': 'Single use, valid one hour.',
          },
        });
      }
    }

    if (path.contains('/hr/people/') && request.method == 'PATCH') {
      onPatchPerson?.call(
        path,
        jsonDecode(request.body) as Map<String, dynamic>,
      );
      return _json({'data': _people.first});
    }

    if (path.endsWith('/resend')) {
      onResend?.call(path);
      onResendBody?.call(
        path,
        request.body.isEmpty
            ? <String, dynamic>{}
            : jsonDecode(request.body) as Map<String, dynamic>,
      );
      return _json({'data': _resent});
    }

    if (path.endsWith('/hr/invitations')) {
      if (request.method == 'POST') {
        onInvite?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({
          'data': createdState == null
              ? _created
              : {..._created, 'sent_state': createdState},
        }, 201);
      }

      onInvitationsQuery?.call(request.url.queryParameters);
      return _json({
        'data': invitations,
        'meta': {
          'tiles': const {
            'sent': 1,
            'opened': 0,
            'completed': 0,
            'abandoned': 2,
            'revoked': 0,
          },
          'total': invitations.length,
          'per_page': 50,
          'current_page': 1,
          'last_page': 1,
        },
      });
    }

    if (path.contains('/hr/invitations/') && request.method == 'DELETE') {
      return _json(
        revokeStatus == 200
            ? {'message': 'Invitation revoked.'}
            : {'message': 'Only an administrator may revoke an invitation.'},
        revokeStatus,
      );
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

/// `GET /hr/people/scope-options` — what THIS caller may hand out, both halves
/// of it, exactly as HrPeopleController answers.
///
/// Deliberately not the same set of places as `/me`'s franchises. Connecticut
/// is assignable and absent from `/me`; Texas is on `/me` and not assignable.
/// Either one showing through is the bug the scope test is written against.
const _scopeOptions = {
  'organization': {'id': 'org-1', 'name': 'Everdoors'},
  'companies': [
    {
      'id': 'co-1',
      'name': 'Serenisima',
      'parent_company_id': null,
      'is_active': true,
    },
  ],
  'franchises': [
    {
      'id': 'fr-9',
      'name': 'Connecticut',
      'company_id': 'co-1',
      'is_active': true,
    },
    // Switched off. §4: an assignment to it would grant nothing at all.
    {
      'id': 'fr-4',
      'name': 'Rhode Island',
      'company_id': 'co-1',
      'is_active': false,
    },
  ],
  'roles': [
    {
      'id': 'role-1',
      'name': 'Administrator',
      'is_administrator': true,
      // §4's escalation bound, as the server answered it.
      'assignable': false,
    },
    {
      'id': 'role-2',
      'name': 'Technician',
      'is_administrator': false,
      'assignable': true,
    },
  ],
};

const _invitations = [
  {
    'id': 'i-1',
    'email': 'bea@example.com',
    'first_name': 'Bea',
    'last_name': 'Okafor',
    'status': 'received',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'franchise', 'id': 'fr-9', 'name': 'Connecticut'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'sent_at': '2026-08-11T09:00:00+00:00',
    'expires_at': '2026-08-18T09:00:00+00:00',
    'opened_at': '2026-08-11T10:00:00+00:00',
    'accepted_at': null,
    'revoked_at': null,
  },
  // The two dead ends, as the server now reports them: `revoked` is a status of
  // its own since 2026-08-11, so this pair is what tells the screen apart.
  //
  // This one was withdrawn. It used to arrive as `abandoned` with `revoked_at`
  // beside it, and this fixture said so — which made it a fixture for a server
  // that no longer exists.
  {
    'id': 'i-9',
    'email': 'kit@example.com',
    'first_name': null,
    'last_name': null,
    'status': 'revoked',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'company', 'id': 'co-1', 'name': 'Serenisima'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'sent_at': '2026-08-02T09:00:00+00:00',
    'expires_at': '2026-08-09T09:00:00+00:00',
    'opened_at': null,
    'accepted_at': null,
    'revoked_at': '2026-08-03T09:00:00+00:00',
  },
  // And this one nobody came to — the same shape, with the clock as the only
  // difference. Without it the test below could not prove the two are told
  // apart, only that one of them renders.
  {
    'id': 'i-10',
    'email': 'lee@example.com',
    'first_name': null,
    'last_name': null,
    'status': 'abandoned',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'company', 'id': 'co-1', 'name': 'Serenisima'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'sent_at': '2026-08-02T09:00:00+00:00',
    'expires_at': '2026-08-09T09:00:00+00:00',
    'opened_at': null,
    'accepted_at': null,
    'revoked_at': null,
  },
];

/// The two dead ends on their own, so a test can assert what a row does NOT
/// offer without another row supplying the same button.
///
/// `final` rather than `const`: indexing a const list is not itself a constant
/// expression in Dart. Sliced from the list above rather than copied, so a
/// change to either row cannot leave these two describing an older server.
final _revokedOnly = [_invitations[1]];
final _expiredOnly = [_invitations[2]];

const _completedOnly = [
  {
    'id': 'i-2',
    'email': 'sam@example.com',
    'first_name': 'Sam',
    'last_name': 'Reyes',
    'status': 'completed',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'franchise', 'id': 'fr-9', 'name': 'Connecticut'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'sent_at': '2026-08-02T09:00:00+00:00',
    'expires_at': '2026-08-09T09:00:00+00:00',
    'opened_at': '2026-08-02T10:00:00+00:00',
    'accepted_at': '2026-08-02T11:00:00+00:00',
    'revoked_at': null,
  },
];

/// `GET /hr/dashboard` — just enough for the tab to draw.
///
/// The one-person organization, which is what most people open this on. What
/// the chip says in that state is defended in hr_dashboard_test.dart.
const _dashboardGlance = {
  'key': 'hr',
  'label': 'Human Resources',
  'presentation': 'bar',
  'allowed_presentations': ['bar', 'pie', 'text'],
  'snapshot': true,
  'coming_soon': false,
  'options': <String, dynamic>{},
  'data': {
    'empty': false,
    'slices': [
      {'label': 'Active', 'total': 0},
      {'label': 'Inactive', 'total': 0},
    ],
    'sentences': [
      '1 person on the books.',
      '0 onboardings in flight.',
      '0 invitations have stalled.',
      '0 people joined this month.',
      '0 people left this month.',
      'None of the contract questions are marked as an expiry, so there is nothing to list yet. Mark a date field as an expiry on a contract template.',
    ],
  },
};

/// The create response — the one moment the raw token exists.
const _created = {
  'id': 'i-3',
  'email': 'nadia@example.com',
  'status': 'sent',
  'expires_at': '2026-08-18T09:00:00+00:00',
  'accept_url': 'https://app.ezbizi.com/invite?token=raw-token-1',
  // The server answers what became of the send, as a STATE rather than a
  // yes/no: the strip has a different sentence and a different remedy for "we
  // never tried" and "we tried and were refused". `emailed` rides alongside
  // for a client older than the state, and the two must agree.
  'emailed': false,
  'sent_state': 'not_sent_no_transport',
};

/// A resend answers the same shape with a NEW token; the old one is dead.
const _resent = {
  'id': 'i-1',
  'email': 'bea@example.com',
  'status': 'sent',
  'expires_at': '2026-08-25T09:00:00+00:00',
  'accept_url': 'https://app.ezbizi.com/invite?token=raw-token-2',
  'emailed': false,
  'sent_state': 'not_sent_refused',
};

const _beaRecord = {
  'id': 'o-2',
  'user_id': 'u-2',
  'status': 'completed',
  'person': {'id': 'u-2', 'name': 'Bea Okafor'},
  'contract': {'id': 'c-1', 'name': 'Technician', 'version': 1},
  'started_at': '2026-02-01T09:00:00+00:00',
  'completed_at': '2026-02-03T09:00:00+00:00',
  'fields': <Object?>[],
  'signatures': <Object?>[],
};

const _people = [
  {
    'id': 'u-1',
    'name': 'Jose Silva',
    'first_name': 'Jose',
    'last_name': 'Silva',
    'email': 'jose@example.com',
    'is_active': true,
    'missing': 0,
    'role': {'id': 'role-1', 'name': 'Administrator'},
    'assignments': [
      {
        'id': 'a-1',
        'type': 'organization',
        'id_of_scope': 'org-1',
        'name': 'Everdoors',
      },
    ],
    'joined_at': '2026-01-04T09:00:00+00:00',
  },
  {
    'id': 'u-2',
    'name': 'Bea Okafor',
    'first_name': 'Bea',
    'last_name': 'Okafor',
    'email': 'bea@example.com',
    'is_active': true,
    // HrPeopleController::missing: her record below is missing gender, date
    // of birth and a start date. Her middle name is empty too, and is not a gap.
    'missing': 3,
    // The state this whole module exists to close: the row exists, the roles
    // exist, and nothing has ever joined the two.
    'role': {'id': null, 'name': null},
    'assignments': [
      {
        'id': 'a-2',
        'type': 'franchise',
        'id_of_scope': 'fr-9',
        'name': 'Connecticut',
      },
      {
        'id': 'a-3',
        'type': 'franchise',
        'id_of_scope': 'fr-3',
        'name': 'Texas',
      },
    ],
    'joined_at': '2026-06-04T09:00:00+00:00',
  },
];

/// `/me` deliberately holds a DIFFERENT set of places from `_scopeOptions` —
/// see the test that depends on it.
const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  // What the Records tab actually exercises. It held `hr.view` alone while the
  // screen checked nothing, so tests drove Deactivate and Change their role
  // through a role that could do neither.
  'permissions': [
    'hr.view',
    'hr.create',
    'hr.edit',
    'hr.dashboard.view',
    'hr.hours.view',
    'hr.onboarding.view',
    'hr.records.view',
    'hr.invitations.view',
    'hr.pto.view',
    'hr.contracts.view',
  ],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Serenisima', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-3', 'name': 'Texas', 'company_id': 'co-1'},
  ],
};
