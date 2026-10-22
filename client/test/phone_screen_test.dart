import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/phone_screen.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone — Call, Records and Settings, not a coming-soon tab.
void main() {
  testWidgets('a granted Communication tab is Phone Team Records Reports Settings, not Coming soon', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(_chips(tester), ['Phone', 'Users', 'Records', 'Reports', 'Settings']);
    expect(find.widgetWithText(ChoiceChip, 'Phone'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Records'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Settings'), findsOneWidget);

    expect(find.text('No phone lines yet. Add one in Settings.'), findsOneWidget);
  });

  /// Jose's UI review, 2026-10-08: the empty Dial named Settings and gave no
  /// way there.
  testWidgets('an empty Dial opens Settings from its own button', (
    tester,
  ) async {
    await _pump(tester);

    await _tap(tester, find.widgetWithText(FilledButton, 'Open Settings'));

    expect(
      tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Settings')).selected,
      isTrue,
    );
  });

  /// Jose, 2026-09-29: Communication had View and Edit only, "which means
  /// everyone can view the settings". A role now sees the tabs it holds.
  testWidgets('only the granted Communication tabs are drawn', (tester) async {
    await _pump(
      tester,
      tabs: const ['phone.phone.view', 'phone.records.view'],
    );

    expect(_chips(tester), ['Phone', 'Records']);
  });

  /// With the module and none of its tabs, a sentence rather than a blank.
  testWidgets('no Communication tab says so', (tester) async {
    await _pump(tester, tabs: const []);

    expect(_chips(tester), isEmpty);
    expect(
      find.textContaining('You do not have access to any part of Communication'),
      findsOneWidget,
    );
  });

  testWidgets('a granted Phone sidebar tab opens the real screen', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(PhoneScreen), findsOneWidget);
    expect(_chips(tester), ['Phone', 'Users', 'Records', 'Reports', 'Settings']);
  });

  testWidgets('Records is an empty list, not Coming soon', (tester) async {
    await _pump(tester);

    await _tap(tester, _tab('Records'));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('There are no calls or messages yet.'), findsOneWidget);
  });

  /// One way in, and only for an approved company. Bringing your own Twilio
  /// was removed: it is not a product EzBizi offers, and while it existed it
  /// was also the way round gate 2, since BYO spent no EzBizi money and so was
  /// allowed without approval.
  testWidgets('Settings offers activation and no credential fields', (
    tester,
  ) async {
    await _pump(tester, approvedCompanyIds: const ['co-1']);

    await _tap(tester, _tab('Settings'));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('Activate phone service'), findsWidgets);
    expect(find.text('Connect your Twilio'), findsNothing);
    expect(find.widgetWithText(TextField, 'Account SID'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
  });

  /// §4: a control that is offered must be a control that works. Before EzBizi
  /// approves the company there is nothing to activate, so nothing is drawn —
  /// the server would refuse it, and a button that refuses is a dead end.
  testWidgets('a company with no approval is not offered activation', (
    tester,
  ) async {
    await _pump(tester);

    await _tap(tester, _tab('Settings'));

    expect(find.text('Activate phone service'), findsNothing);
    expect(find.text('Ask for phone service'), findsOneWidget);
  });

  testWidgets(
    'a company view does not offer Twilio for sibling companies',
    (tester) async {
      await _pump(
        tester,
        approvedCompanyIds: const ['co-a1'],
        scope: {
          'type': 'company',
          'id': 'co-a1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney'],
        },
        companies: [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {
            'id': 'co-ever',
            'name': 'Everdoors Co',
            'parent_company_id': null,
          },
        ],
      );

      await _tap(tester, _tab('Settings'));

      // The rule this test exists for: standing in A-1 Chimney must not offer
      // to connect Everdoors.
      expect(find.text('A-1 Chimney'), findsOneWidget);
      expect(find.text('Everdoors Co'), findsNothing);
      expect(find.text('Activate phone service'), findsOneWidget);
      expect(find.text('Connect your Twilio'), findsNothing);

      // Phone service is per company now, so there is no organization card at
      // all — and nothing to say about switching to the group to edit it.
      expect(find.text('Everdoors'), findsNothing);
      expect(
        find.textContaining('Switch to the group to connect or change it'),
        findsNothing,
      );
    },
  );


  testWidgets('a connected account masks the SID and never shows the secret', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        {
          'id': 'pc-1',
          'organization_id': 'org-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'byo',
          'account_sid': 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxa7c2',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
          'status': 'connected',
          'status_reason': null,
        },
      ],
    );

    await _tap(tester, _tab('Settings'));

    expect(find.textContaining('Account SID ends …a7c2'), findsOneWidget);
    expect(find.textContaining('The key is never shown back'), findsOneWidget);
    expect(find.text('Connected'), findsOneWidget);
    expect(find.text('Your Twilio'), findsOneWidget);
    expect(find.text('SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'), findsNothing);
    expect(find.textContaining('secret'), findsNothing);
  });

  testWidgets(
    'creating a phone account shows the server sentence when EzBizi has none',
    (tester) async {
      await _pump(tester, approvedCompanyIds: const ['co-1'], createStatus: 501);

      await _tap(tester, _tab('Settings'));
      await _tap(tester, find.text('Activate phone service').first);

      expect(
        find.text(
          'EzBizi has not been given a Twilio account to create phone service with.',
        ),
        findsOneWidget,
      );
      expect(find.byType(Dialog), findsNothing);
    },
  );


  testWidgets('Settings at 375 is the list, then the form with Back', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(375, 800),
      approvedCompanyIds: const ['co-1'],
    );

    await _tap(tester, _tab('Settings'));

    expect(find.text('Connection'), findsOneWidget);
    expect(find.text('Numbers'), findsOneWidget);
    expect(find.text('IVR menus'), findsOneWidget);
    expect(find.text('Hours'), findsOneWidget);
    expect(find.text('Ring groups'), findsOneWidget);
    expect(find.text('Trust & caller identity'), findsOneWidget);

    // The rail is a lazy list and grew past one screen when it gained group
    // headings and a fourteenth section, so the last entry is no longer built
    // until it is scrolled to. Not being able to reach it WOULD be a defect,
    // which is why this scrolls rather than dropping the assertion.
    await _scrollTo(
      tester,
      find.text('Messaging'),
      within: find.byType(ListView).first,
    );
    expect(find.text('Messaging'), findsOneWidget);
    expect(find.text('Activate phone service'), findsNothing);

    // BACK UP THE LIST BEFORE TAPPING, with a NEGATIVE delta because
    // `Connection` is above. The rail is lazy, so scrolling to the bottom
    // entry unbuilds the top ones — this passed only for as long as the list
    // was short enough for both ends to be built at once, and went red the day
    // a section was added, with a "Bad state: No element" that says nothing
    // about the cause.
    await tester.scrollUntilVisible(
      find.text('Connection'),
      -240,
      scrollable: find
          .descendant(
            of: find.byType(ListView).first,
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.text('Connection'));

    // The pane's search box sits beside its title, so at 375 the heading wraps
    // taller and the account rows start below the fold of a lazy list.
    await _scrollTo(
      tester,
      find.text('Activate phone service'),
      within: find.byType(ListView).last,
    );
    expect(find.text('Activate phone service'), findsWidgets);
    expect(find.byTooltip('Back to the list'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the list'));

    expect(find.text('Activate phone service'), findsNothing);
    expect(find.text('Numbers'), findsOneWidget);
  });



  testWidgets(
    'phone.view without phone.edit still opens Settings and activation is refused',
    (tester) async {
      await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      permissions: const ['phone.view'],
    );

      await _tap(tester, _tab('Settings'));

      expect(find.text('Activate phone service'), findsWidgets);

      // Drawn and dead, with the reason attached — not hidden. Somebody who
      // cannot do it should see that it exists and why they cannot.
      final activate = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Activate phone service').first,
      );
      expect(activate.onPressed, isNull);
    },
  );

  testWidgets('Activate phone service posts subaccount kind', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      onCreate: (body) => posted = body,
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Activate phone service').first);

    expect(posted, {
      'scope_type': 'company',
      'scope_id': 'co-1',
      'kind': 'subaccount',
    });
  });

  /// The gap this closed: TwimlRenderer reads phone_numbers.ring_group_id and
  /// falls through to voicemail when it is null, and nothing could set it. A
  /// group has to be creatable AND attachable to a line, or the phone never
  /// rings on a fully configured account.
  testWidgets('a ring group is created and pointed at a line', (tester) async {
    Map<String, dynamic>? group;
    Map<String, dynamic>? patched;

    await _pump(
      tester,
      connections: const [
        {
          'id': 'conn-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'subaccount',
          'status': 'connected',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
      ],
      numbers: const [
        {
          'id': 'num-1',
          'connection_id': 'conn-1',
          'e164': '+12035550123',
          'label': 'Front desk',
          'ring_group_id': null,
        },
      ],
      members: const [
        {'id': 'u-1', 'name': 'Dana Reyes', 'role_id': 'r-1'},
      ],
      onRingGroup: (body) => group = body,
      onNumberPatch: (body) => patched = body,
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Ring groups'));

    expect(
      find.textContaining('a call goes straight to voicemail'),
      findsOneWidget,
    );

    await _tap(tester, find.text('Add a ring group'));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Name'),
      'Front desk',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.text('Dana Reyes'));
    await _tap(tester, find.text('Save'));

    expect(group?['name'], 'Front desk');
    expect(group?['member_user_ids'], ['u-1']);
    expect(group?['connection_id'], 'conn-1');

    // SAVING A GROUP TOUCHES NO LINE.
    //
    // This used to assert the opposite — that saving also PATCHed the number
    // with the new group's id — because the editor carried a list of numbers
    // with tick boxes. That list is gone: a line points at one group, and it
    // is chosen on Numbers where somebody looking at a phone number already
    // stands, or as the company default on General.
    //
    // Kept as an assertion rather than deleted, because "no request went out"
    // is the thing that would silently come back if the second save were ever
    // restored.
    expect(
      patched,
      isNull,
      reason: 'saving a ring group reached out and rewrote a phone line',
    );
  });

  /// §4 gate 2 from the customer's side. Phone is a paid extra billed to
  /// EzBizi, so the tab must explain itself and offer the one thing that moves
  /// — asking — rather than hiding or refusing at the door.
  testWidgets('asking for phone service sends the companies, volume and use', (
    tester,
  ) async {
    Map<String, dynamic>? asked;

    await _pump(
      tester,
      serviceCompanies: const [
        {'id': 'co-1', 'name': 'Connecticut', 'has_service': false},
      ],
      onServiceRequest: (body) => asked = body,
    );

    await _tap(tester, _tab('Settings'));

    expect(find.text('Activate phone service'), findsNothing);
    expect(find.textContaining('It is a paid extra'), findsOneWidget);

    await _tap(tester, find.text('Ask for phone service'));

    // .first — the form's checkbox. The connection card below also says
    // "Connecticut", and .last picks that instead, which ticks nothing.
    await _tap(tester, find.text('Connecticut').first);
    await _tap(tester, find.text('Fewer than 100 calls a month'));
    await _tap(tester, find.textContaining('text messaging as well as calls'));

    await tester.enterText(
      find.byType(TextField).last,
      'Booking sweeps and calling customers back when we miss them.',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.text('Send the request'));

    expect(asked?['company_ids'], ['co-1']);
    expect(asked?['expected_volume'], 'under_100');
    expect(asked?['wants_sms'], true);
  });

  testWidgets('a request already waiting says so and offers nothing to press', (
    tester,
  ) async {
    await _pump(
      tester,
      serviceRequest: const {
        'id': 'req-1',
        'status': 'pending',
        'requested_at': '2026-08-27T10:00:00Z',
        'companies': [
          {'company_id': 'co-1', 'name': 'Connecticut', 'approved': null},
        ],
      },
    );

    await _tap(tester, _tab('Settings'));

    expect(find.textContaining('We are looking at your request'), findsOneWidget);
    expect(find.text('Ask for phone service'), findsNothing);
    expect(find.text('Send the request'), findsNothing);
  });

  group('Settings › Numbers', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    Future<void> openNumbers(WidgetTester tester) async {
      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Numbers'));
    }

    testWidgets('lines already on the account are offered, and say when they answer elsewhere', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        importable: const [
          {'sid': 'PN1', 'e164': '+15612323929', 'label': 'EZBZ', 'answers_elsewhere': true},
          {'sid': 'PN2', 'e164': '+19737820956', 'label': 'Bar', 'answers_elsewhere': false},
        ],
      );

      await openNumbers(tester);
      // Import moved behind its own segment: it is a job you do once, and
      // it used to sit above the lines you open Numbers to look at.
      await _tap(tester, find.textContaining('Import'));

      expect(find.text('EZBZ · +1 (561) 232-3929'), findsOneWidget);
      expect(find.text('Bar · +1 (973) 782-0956'), findsOneWidget);

      // The one that still answers somewhere else says so BEFORE importing.
      expect(
        find.textContaining('currently answers somewhere else'),
        findsOneWidget,
      );
    });

    testWidgets('importing sends the picked sids and the place', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        importable: const [
          {'sid': 'PN1', 'e164': '+15612323929', 'label': 'EZBZ', 'answers_elsewhere': false},
          {'sid': 'PN2', 'e164': '+19737820956', 'label': 'Bar', 'answers_elsewhere': false},
        ],
        onImport: (body) => sent = body,
      );

      await openNumbers(tester);
      // Import moved behind its own segment: it is a job you do once, and
      // it used to sit above the lines you open Numbers to look at.
      await _tap(tester, find.textContaining('Import'));

      await tester.tap(find.byKey(const ValueKey('import:PN2')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Import 1 line'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull);
      expect(sent!['sids'], ['PN2']);
      expect(sent!['scope_type'], 'company');
      expect(sent!['scope_id'], 'co-1');
    });

    const twoLines = [
      {
        'id': 'n-1',
        'e164': '+15612323929',
        'label': 'EZBZ',
        'record': true,
        'transcribe': false,
        'after_hours': 'voicemail',
        'ring_group_id': null,
      },
      {
        'id': 'n-2',
        'e164': '+13057242199',
        'label': 'Old fax',
        'record': false,
        'transcribe': false,
        'after_hours': 'voicemail',
        'ring_group_id': null,
      },
    ];

    /// Opening a line used to REPLACE the pane.
    ///
    /// You lost the list while editing, could not see the line above the one
    /// you were changing, and came back to the top of the list rather than to
    /// where you had been. This asserts both halves: the list is still there
    /// AND the form arrived. Checking only the form would pass on the old
    /// behaviour, which is what it is here to catch.
    testWidgets('opening a line keeps the list beside it', (tester) async {
      await _pump(
        tester,
        size: const Size(1280, 900),
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: twoLines,
      );

      await openNumbers(tester);
      await _tap(tester, find.text('Edit').first);

      expect(
        find.widgetWithText(TextField, 'What this line is called'),
        findsOneWidget,
        reason: 'the form did not open',
      );

      expect(
        find.textContaining('Old fax'),
        findsWidgets,
        reason: 'the other line vanished, so the form replaced the list again',
      );
    });

    /// Below 600 there is not room for two columns, so the form pushes over
    /// the list and has to carry a way back. A split that quietly kept half a
    /// list on a phone would be worse than the stacking it replaced.
    testWidgets('on a narrow pane the form takes over and offers Back', (
      tester,
    ) async {
      await _pump(
        tester,
        size: const Size(560, 900),
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: twoLines,
      );

      await openNumbers(tester);
      await _tap(tester, find.text('Edit').first);

      expect(
        find.widgetWithText(TextField, 'What this line is called'),
        findsOneWidget,
      );
      expect(
        find.textContaining('Old fax'),
        findsNothing,
        reason: 'both columns were drawn in a width that cannot hold them',
      );
      expect(find.byTooltip('Back to the lines'), findsOneWidget);
    });

    /// Five controls exist in both Numbers and General. Jose chose to keep
    /// both editors, so they cannot be allowed to look like the only place a
    /// value lives — somebody who changes one, then finds the old value in the
    /// other, concludes the save failed.
    testWidgets('a field that also exists in General says so in place', (
      tester,
    ) async {
      await _pump(
        tester,
        size: const Size(1280, 900),
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: twoLines,
      );

      await openNumbers(tester);
      await _tap(tester, find.text('Edit').first);

      expect(find.textContaining('Also on General'), findsWidgets);
    });

    /// Leaving a section and coming back must REFETCH.
    ///
    /// Several panes edit the same line — General and Numbers share five
    /// controls, and Ring groups writes phone_numbers too — so a pane showing
    /// what it fetched an hour ago is a pane that will silently revert somebody
    /// else's save. Today that is guaranteed by the rail being a switch, which
    /// disposes the pane you leave.
    ///
    /// This exists because that is a correctness property held up by something
    /// that looks like an implementation detail. Swapping the switch for an
    /// IndexedStack reads as a straight performance win and would reintroduce
    /// the bug in silence. It would fail here instead.
    testWidgets('a pane refetches when it is opened again', (tester) async {
      final log = <String>[];

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        requestLog: log,
      );

      await openNumbers(tester);
      final first = log.where((r) => r.endsWith('/phone/numbers')).length;
      expect(first, greaterThan(0), reason: 'Numbers never loaded at all');

      // Away, then back.
      await _tap(tester, find.text('Hours'));
      await _tap(tester, find.text('Numbers'));

      expect(
        log.where((r) => r.endsWith('/phone/numbers')).length,
        greaterThan(first),
        reason: 'reopening Numbers served a cached pane, so another screen '
            'saving the same line would be reverted by this one',
      );
    });

    testWidgets('a line with no ring group says calls go to voicemail', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'EZBZ',
            'record': true,
            'transcribe': false,
            'after_hours': 'voicemail',
            'ring_group_id': null,
          },
        ],
      );

      await openNumbers(tester);

      // Loud, not a caption. All five production lines sat in exactly this
      // state for hours reading as grey secondary text, and every caller went
      // to voicemail without anyone noticing. A red pill in the "What answers
      // it" column now (tiles over a table, Mia 2026-10-02).
      expect(find.text('Nobody answers'), findsOneWidget);
      expect(find.textContaining('1 line is not reaching anybody'), findsNothing);
      expect(find.textContaining('One line is not reaching anybody'), findsOneWidget);
    });

    // Jose, 2026-10-05: "list of phones not in use, deactivated or not
    // assigned". A tile for each, from the server's last call and holder.
    testWidgets('lines not in use and not assigned have their own tiles', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'Busy line',
            'record': false,
            'transcribe': false,
            'after_hours': 'voicemail',
            'last_call_at': DateTime.now().toUtc().toIso8601String(),
            'assigned_to': 'Ana',
          },
          {
            'id': 'n-2',
            'e164': '+15612323930',
            'label': 'Quiet line',
            'record': false,
            'transcribe': false,
            'after_hours': 'voicemail',
            'last_call_at': null,
            'assigned_to': null,
          },
        ],
      );

      await openNumbers(tester);
      expect(find.textContaining('Busy line'), findsWidgets);

      await _tap(tester, find.text('NOT IN USE'));
      expect(find.textContaining('Quiet line'), findsWidgets);
      expect(find.textContaining('Busy line'), findsNothing);

      await _tap(tester, find.text('NOT ASSIGNED'));
      expect(find.textContaining('Quiet line'), findsWidgets);
      expect(find.textContaining('Busy line'), findsNothing);
    });

    // Jose, 2026-10-05: "bulk edit capabilities". Tick lines, set a field
    // once, and each line is saved with it - and nothing else touched.
    testWidgets('several lines can be changed at once', (tester) async {
      final patched = <Map<String, dynamic>>[];

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        size: const Size(1280, 1600),
        onNumberPatch: patched.add,
        numbers: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'First',
            'record': true,
            'transcribe': false,
            'after_hours': 'voicemail',
          },
          {
            'id': 'n-2',
            'e164': '+15612323930',
            'label': 'Second',
            'record': true,
            'transcribe': false,
            'after_hours': 'voicemail',
          },
        ],
      );

      await openNumbers(tester);
      await _tap(tester, find.byKey(const ValueKey('pick:n-1')));
      await _tap(tester, find.byKey(const ValueKey('pick:n-2')));

      await _tap(tester, find.byKey(const ValueKey('bulk-record')));
      await _tap(tester, find.text('Do not record').last);
      await _tap(tester, find.byKey(const ValueKey('bulk-save')));

      expect(patched, hasLength(2));
      for (final body in patched) {
        expect(body, {'record': false}, reason: 'a field nobody picked was sent');
      }
      expect(find.textContaining('Changed 2 lines'), findsOneWidget);
    });

    // Jose, 2026-10-05, of the stacked rows at phone width: "these are too
    // fat". The bulk tick had a stacked line of its own on every row.
    testWidgets('a line row stays two lines tall at phone width', (tester) async {
      await _pump(
        tester,
        size: const Size(420, 1400),
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'General',
            'record': true,
            'transcribe': false,
            'after_hours': 'voicemail',
          },
        ],
      );

      await openNumbers(tester);

      final height = tester.getSize(find.byKey(const ValueKey('line:n-1'))).height;
      expect(height, lessThan(100), reason: 'a stacked line row grew a third line');
    });

    testWidgets('editing a line saves what it rings and how it behaves', (tester) async {
      Map<String, dynamic>? patched;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: const [
          {'id': 'rg-1', 'name': 'Front desk', 'connection_id': 'cx-1'},
        ],
        numbers: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'EZBZ',
            'record': false,
            'transcribe': false,
            'after_hours': 'voicemail',
            'ring_group_id': null,
          },
        ],
        onNumberPatch: (body) => patched = body,
      );

      await openNumbers(tester);
      await tester.tap(find.text('Edit'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Nobody — go straight to voicemail'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Front desk').last);
      await tester.pumpAndSettle();

      // The form carries the carrier settings now, so Save is below the fold
      // and a ListView has not built it yet.
      await tester.dragUntilVisible(
        find.text('Save'),
        find.byType(ListView).last,
        const Offset(0, -200),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(patched, isNotNull);
      expect(patched!['ring_group_id'], 'rg-1');
      expect(patched!['after_hours'], 'voicemail');

      // Untouched carrier settings are not sent: an emergency address nobody
      // changed must not become a Twilio write on every save.
      expect(patched!.containsKey('emergency_address_sid'), isFalse);
    });

    testWidgets('it fits a 375px screen', (tester) async {
      await _pump(
        tester,
        size: const Size(375, 800),
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        importable: const [
          {'sid': 'PN1', 'e164': '+15612323929', 'label': 'EZBZ', 'answers_elsewhere': true},
        ],
      );

      await openNumbers(tester);

      expect(tester.takeException(), isNull);
      expect(find.byType(Dialog), findsNothing);
    });
  });

  /// AGENTS.md standing rule: a company view shows the view, never the reach.
  ///
  /// This shipped wrong. Standing in A-1 Chimney, the Connection screen listed
  /// "Everdoors — switched on" and "EzBizi — switched on" because the request
  /// named all three and the card rendered whatever it was sent.
  group('Settings › Connection is scoped to the current view', () {
    const threeCompanies = [
      {'id': 'co-1', 'name': 'A-1 Chimney'},
      {'id': 'co-2', 'name': 'Everdoors'},
      {'id': 'co-3', 'name': 'EzBizi'},
    ];

    const grantedAll = {
      'id': 'req-1',
      'status': 'approved',
      'requested_at': '2026-08-27T10:00:00Z',
      'decided_at': '2026-08-27T11:00:00Z',
      'decision_note': null,
      'companies': [
        {'company_id': 'co-1', 'name': 'A-1 Chimney', 'approved': true},
        {'company_id': 'co-2', 'name': 'Everdoors', 'approved': true},
        {'company_id': 'co-3', 'name': 'EzBizi', 'approved': true},
      ],
    };

    testWidgets('standing in one company, the siblings are not named', (tester) async {
      await _pump(
        tester,
        companies: threeCompanies,
        scope: const {'type': 'company', 'id': 'co-1'},
        serviceRequest: grantedAll,
        approvedCompanyIds: const ['co-1', 'co-2', 'co-3'],
      );

      await _tap(tester, find.text('Settings'));

      expect(find.textContaining('A-1 Chimney'), findsWidgets);
      expect(find.textContaining('Everdoors'), findsNothing);
      expect(find.textContaining('EzBizi —'), findsNothing);
    });

    testWidgets('standing at the organization, every company is named', (tester) async {
      await _pump(
        tester,
        companies: threeCompanies,
        scope: const {'type': 'organization', 'id': 'org-1'},
        serviceRequest: grantedAll,
        approvedCompanyIds: const ['co-1', 'co-2', 'co-3'],
      );

      await _tap(tester, find.text('Settings'));

      expect(find.textContaining('A-1 Chimney'), findsWidgets);
      expect(find.textContaining('Everdoors'), findsWidgets);
    });
  });

  group('Settings › Hours', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    testWidgets('a new schedule defaults to the working week and posts that shape', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        onHours: (body) => sent = body,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Hours'));

      await _tap(tester, find.text('Add hours'));

      await tester.enterText(find.byType(TextFormField).first, 'Office hours');
      await tester.pumpAndSettle();
      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('hours-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull);
      expect(sent!['name'], 'Office hours');

      // HoursClock reads day keys and half-open [from, to) pairs. A shape it
      // does not understand is a line that silently never answers.
      final weeks = sent!['weeks'] as Map<String, dynamic>;
      expect(weeks['mon'], [
        ['09:00', '17:00'],
      ]);
      expect(weeks['sun'], isEmpty);
      expect(weeks['sat'], isEmpty);
    });

    testWidgets('a closed day reads as Closed rather than as a blank row', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        schedules: const [
          {
            'id': 'hs-1',
            'connection_id': 'cx-1',
            'name': 'Office hours',
            'timezone': 'Etc/GMT+5',
            'weeks': {
              'mon': [
                ['09:00', '17:00'],
              ],
            },
            'exceptions': <Map<String, Object?>>[],
          },
        ],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Hours'));

      // The POSIX sign is inverted on the wire; the reader must never see that.
      expect(find.textContaining('UTC-5'), findsOneWidget);
      expect(find.textContaining('Open Mon'), findsOneWidget);

      await _tap(tester, find.text('Edit'));
      expect(find.text('Closed'), findsWidgets);
    });
  });

  group('Settings › Call flow', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'kind': 'subaccount',
      'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      'status': 'connected',
      'status_reason': null,
    };

    Future<void> openCallFlow(WidgetTester tester) async {
      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Call flow'));
    }

    /// The whole reason this screen exists. Five production lines sat with no
    /// ring group for months and every caller heard voicemail; the state WAS
    /// disclosed, in grey, as a caption on another screen. A count at the top
    /// is the difference between disclosed and told.
    testWidgets('a line that reaches nobody is counted at the top, not just described', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        callFlow: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'EZBZ',
            'record': false,
            'transcribe': false,
            'steps': [
              {'step': 'arrival', 'title': 'A call arrives on +15612323929', 'detail': 'x', 'usable': true},
              {'step': 'hours', 'title': 'Business hours: always open', 'detail': 'x', 'usable': true},
              {'step': 'menu', 'title': 'IVR menu: none', 'detail': 'x', 'usable': true},
              {
                'step': 'answer',
                'title': 'Nobody answers this line',
                'detail': 'No ring group is set, so every call goes straight to voicemail.',
                'usable': false,
              },
            ],
          },
        ],
      );

      await openCallFlow(tester);

      expect(
        find.textContaining('One line reaches nobody'),
        findsOneWidget,
        reason: 'the count is what makes a silent line impossible to scan past',
      );
      expect(find.text('Nobody answers this line'), findsOneWidget);
    });

    testWidgets('a healthy line raises no alarm', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        callFlow: const [
          {
            'id': 'n-1',
            'e164': '+15612323929',
            'label': 'EZBZ',
            'record': false,
            'transcribe': false,
            'steps': [
              {'step': 'arrival', 'title': 'A call arrives on +15612323929', 'detail': 'x', 'usable': true},
              {'step': 'answer', 'title': 'Rings Front desk', 'detail': 'x', 'usable': true},
            ],
          },
        ],
      );

      await openCallFlow(tester);

      expect(find.textContaining('reaches nobody'), findsNothing);
      expect(find.text('Rings Front desk'), findsOneWidget);
    });
  });

  group('Settings › Trust', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    testWidgets('a pending carrier review says nobody can hurry it', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        trust: const {
          'customer_profile': 'pending',
          'shaken_stir': 'twilio-approved',
          'cnam': 'noop',
          'voice_integrity': 'twilio-rejected',
        },
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Trust & caller identity'));

      // The sentence that matters on this screen: a carrier review is
      // somebody else's queue, and a customer who thinks EzBizi is sitting on
      // it will ring EzBizi.
      expect(
        find.textContaining('nobody at EzBizi can hurry it'),
        findsWidgets,
      );
      expect(find.text('Pending review'), findsWidgets);
    });
  });

  group('Settings › IVR menus', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    /// A menu that is configured and never heard.
    ///
    /// Every other field on the row reads as healthy — it has keys, it is on a
    /// line — while no caller has ever reached it. Nobody reports it either:
    /// the caller does not know they missed a menu, and the owner does not
    /// know theirs is skipped.
    testWidgets('a menu that never plays says so on its row', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        flows: const [
          {
            'id': 'fl-1',
            'connection_id': 'cx-1',
            'name': 'Never heard',
            'prompt': 'Press one for sales.',
            'options': [
              {'digit': '1', 'action': 'ring_group', 'ring_group_id': 'rg-1'},
            ],
            'number_ids': ['n-1'],
            'plays': false,
          },
        ],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));

      expect(find.textContaining('No caller ever hears this'), findsOneWidget);
    });

    /// The claim is serious, so an older server that does not send the field
    /// must not put the warning on every healthy menu.
    testWidgets('a menu says nothing when the server does not report it', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        flows: const [
          {
            'id': 'fl-1',
            'connection_id': 'cx-1',
            'name': 'Main menu',
            'prompt': 'Press one for sales.',
            'options': [
              {'digit': '1', 'action': 'ring_group', 'ring_group_id': 'rg-1'},
            ],
            'number_ids': ['n-1'],
          },
        ],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));

      expect(find.textContaining('No caller ever hears this'), findsNothing);
    });

    testWidgets('a menu posts the digit, action and group the renderer reads', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: const [
          {'id': 'rg-1', 'name': 'Front desk', 'connection_id': 'cx-1'},
        ],
        onFlow: (body) => sent = body,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      await tester.enterText(find.byType(TextFormField).at(0), 'Main menu');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'Press 1 for the office.',
      );
      await tester.pumpAndSettle();

      // Name which group the key rings. Without this the save is refused —
      // a ringing key with no group posts null and the caller reaches the
      // line's own group instead of the one the key promised.
      await _scrollTo(
        tester,
        find.byKey(const ValueKey('group:0')),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.byKey(const ValueKey('group:0')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Front desk').last);
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull);
      expect(sent!['prompt'], 'Press 1 for the office.');

      final options = sent!['options'] as List;
      expect(options, hasLength(1));
      expect((options.first as Map)['digit'], '1');
      expect((options.first as Map)['action'], 'ring_group');
      expect((options.first as Map)['ring_group_id'], 'rg-1');
    });

    /// A key can send the caller back to the top of the menu.
    ///
    /// Jose asked for it while looking at what a key could do: ring a group,
    /// take a message, forward, or say something and hang up. Every phone menu
    /// in the world also offers "to hear these options again" and this one did
    /// not — so a caller who missed which key was which had to hang up and
    /// ring back, which on a busy line is a lost job.
    ///
    /// It needs no configuration, which is exactly why it is worth a test:
    /// there is no group to pick and no number to type, so a save that
    /// silently dropped the option would look identical to one that worked.
    testWidgets('a key can repeat the menu', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: const [
          {'id': 'rg-1', 'name': 'Front desk', 'connection_id': 'cx-1'},
        ],
        onFlow: (body) => sent = body,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      await tester.enterText(find.byType(TextFormField).at(0), 'Main menu');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'Press 9 to hear this again.',
      );
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.byKey(const ValueKey('action:0')),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.byKey(const ValueKey('action:0')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Repeat the menu').last);
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull, reason: 'the save was refused');

      final options = sent!['options'] as List;
      expect(options, hasLength(1));
      expect((options.first as Map)['action'], 'repeat');
    });

    /// A key that rings one person.
    ///
    /// `TwimlRenderer` has dialled an extension from a menu key since
    /// extensions existed, and it is tested — from a `PhoneFlow` row a test
    /// wrote itself. This editor offered four actions and none of them was
    /// this one, so the only way to reach the code was to be a test. Green
    /// renderer, feature nobody could switch on.
    testWidgets('a key can ring one person by extension', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: const [
          {'id': 'rg-1', 'name': 'Front desk', 'connection_id': 'cx-1'},
        ],
        extensions: const [
          {'user_id': 'u-1', 'name': 'Bar', 'extension': '102'},

          // Everybody is on that roster, holder or not. A person with no
          // extension is not something a key can ring.
          {'user_id': 'u-2', 'name': 'Ada', 'extension': null},
        ],
        onFlow: (body) => sent = body,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      await tester.enterText(find.byType(TextFormField).at(0), 'Main menu');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'Press 2 for Bar.',
      );
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.byKey(const ValueKey('action:0')),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.byKey(const ValueKey('action:0')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ring one person').last);
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.byKey(const ValueKey('extension:0')),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.byKey(const ValueKey('extension:0')));
      await tester.pumpAndSettle();

      // Only the person who holds one is offered.
      expect(find.textContaining('Ada'), findsNothing);

      await tester.tap(find.text('102 · Bar').last);
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull, reason: 'the save was refused');

      final options = sent!['options'] as List;
      expect(options, hasLength(1));
      expect((options.first as Map)['action'], 'extension');
      expect((options.first as Map)['extension'], '102');
    });

    /// A key that rings one person with nobody named posts no digits, and the
    /// renderer sends that caller to voicemail — so the key answers, and
    /// nobody who presses it knows they were meant to reach a person.
    testWidgets('a key that rings nobody in particular is refused before it is sent', (tester) async {
      var posted = false;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        extensions: const [
          {'user_id': 'u-1', 'name': 'Bar', 'extension': '102'},
        ],
        onFlow: (_) => posted = true,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      await tester.enterText(find.byType(TextFormField).at(0), 'Main menu');
      await tester.enterText(find.byType(TextFormField).at(1), 'Press 2.');
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.byKey(const ValueKey('action:0')),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.byKey(const ValueKey('action:0')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ring one person').last);
      await tester.pumpAndSettle();

      // The extension is left unchosen on purpose.
      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(find.text('Say which extension each key rings.'), findsOneWidget);
      expect(posted, isFalse);
    });

    testWidgets('two keys doing different things is refused before it is sent', (tester) async {
      var posted = false;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: const [
          {'id': 'rg-1', 'name': 'Front desk', 'connection_id': 'cx-1'},
        ],
        onFlow: (_) => posted = true,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      await tester.enterText(find.byType(TextFormField).at(0), 'Main menu');
      await tester.enterText(find.byType(TextFormField).at(1), 'Press 1.');
      await tester.pumpAndSettle();

      // A second key, left on the same digit as the first.
      await _scrollTo(
        tester,
        find.text('Add a key'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Add a key'));
      await tester.pumpAndSettle();

      await _scrollTo(
        tester,
        find.text('Save'),
        within: find.byKey(const ValueKey('flow-form')),
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(find.text('Two keys cannot do different things.'), findsOneWidget);
      expect(posted, isFalse);
    });

    testWidgets('a key that rings a group says so when there are no groups', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('IVR menus'));
      await _tap(tester, find.text('Add a menu'));

      expect(
        find.textContaining('a key that rings nobody is worse than no key'),
        findsOneWidget,
      );
    });
  });

  group('Settings › Voicemail', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    const box = {
      'id': 'vb-1',
      'connection_id': 'cx-1',
      'name': 'Main mailbox',
      'greeting_text': 'You have reached us.',
      'spoken_greeting': 'You have reached us.',
      'max_seconds': 300,
      'transcribe': false,
      'notify_emails': <String>[],
    };

    /// The footer's PRESENCE is what says a pane is editable.
    ///
    /// It is the whole reason the skeleton has one: read-only panes have no
    /// footer, so a Save appearing on a list would be a claim that the list is
    /// something you change. This is the assertion that keeps that true, and it
    /// has to check both halves — a pane that never shows Save passes half of
    /// it while being useless.
    testWidgets('the mailbox list has no Save, and opening one produces it', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        voicemailBoxes: const [box],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Voicemail'));

      expect(
        find.widgetWithText(FilledButton, 'Save'),
        findsNothing,
        reason: 'the list offered a Save, which says the list is editable',
      );

      await _tap(tester, find.text('Edit'));

      expect(find.widgetWithText(FilledButton, 'Save'), findsOneWidget);
    });

    /// A count tells you that you have unsaved work and never tells you what.
    ///
    /// On a form with four sections that is most of the question, and the
    /// answer is above the fold in the footer rather than found by scrolling.
    testWidgets('the footer names what changed rather than only counting it', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        voicemailBoxes: const [box],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Voicemail'));
      await _tap(tester, find.text('Edit'));

      expect(find.textContaining('Everything saved'), findsOneWidget);

      await tester.enterText(
        find.widgetWithText(TextField, 'Greeting'),
        'You have reached the office. Leave a number.',
      );
      await tester.pumpAndSettle();

      expect(find.textContaining('One change not saved'), findsOneWidget);
      expect(find.textContaining('the greeting'), findsOneWidget);
    });

    /// Voicemail was one of four panes that showed a failure and offered
    /// nothing to do about it, so the only way out was to leave Settings and
    /// come back. It inherits the control from the skeleton now rather than
    /// from somebody remembering to add one.
    testWidgets('a load that fails offers a way to try it again', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        voicemailFails: true,
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Voicemail'));

      expect(find.text('Try again'), findsOneWidget);
    });
  });

  group('Settings › the rail', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    /// Thirteen sections in four groups, so somebody can stop reading once
    /// they know which group they want.
    testWidgets('sections are grouped under headings', (tester) async {
      await _pump(tester);

      await _tap(tester, find.text('Settings'));

      for (final heading in ['CONNECT', 'ANSWERING', 'IDENTITY', 'RUNNING IT']) {
        expect(find.text(heading), findsOneWidget, reason: heading);
      }
    });

    /// Grouping REORDERED the rail: Trust moved above Tags. The body used to
    /// be keyed on the index, so that move repointed every section below it —
    /// you would tap Trust and get Tags, with nothing failing anywhere.
    ///
    /// These two are the pair the move actually swapped, which is why they are
    /// the pair worth asserting rather than a section in the middle of a group
    /// that could not have shifted.
    testWidgets("each rail entry opens its own pane, not its neighbour's", (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        trust: const {
          'customer_profile': 'pending',
          'shaken_stir': 'twilio-approved',
          'cnam': 'noop',
          'voice_integrity': 'twilio-rejected',
        },
      );

      await _tap(tester, find.text('Settings'));

      await _tap(tester, find.text('Trust & caller identity'));
      expect(
        find.textContaining('Twilio Business Profile'),
        findsOneWidget,
        reason: 'Trust opened something else',
      );

      await _tap(tester, find.text('Tags'));
      expect(
        find.textContaining('Twilio Business Profile'),
        findsNothing,
        reason: 'Tags opened Trust, which is the index-repointing bug',
      );
    });
  });

  group('Call › messaging a colleague', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    const line = {
      'id': 'n-1',
      'e164': '+15612323929',
      'label': 'EZBZ',
      'record': false,
      'transcribe': false,
      'after_hours': 'voicemail',
      'ring_group_id': null,
    };

    const team = [
      {'user_id': 'u-ana', 'name': 'Ana', 'state': 'available'},
      {'user_id': 'u-bar', 'name': 'Bar', 'state': 'dnd'},
    ];

    /// The constraint the whole feature rests on.
    ///
    /// Somebody messaging a colleague from the Call tab is on a call or about
    /// to be — "can you take this", "is the van back". A thread that evicted
    /// the dial pad or the customer would take away the thing the message is
    /// about, which is why the ROSTER becomes the thread and the other two
    /// columns are untouched.
    ///
    /// Asserting the thread opened is only half. The half that matters is the
    /// pad still being there.
    testWidgets('the thread takes the Team column and nothing else', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [line],
        presence: team,
      );

      // The roster is its own sub-tab now, so the thread takes THAT and the
      // sub-tabs stay put. What must not happen is the same as before: the
      // thread taking over the whole screen, so somebody messaging a colleague
      // about a call loses the call.
      await _tap(tester, find.widgetWithText(ChoiceChip, 'Team'));
      await _tap(tester, find.byTooltip('Message Ana'));

      expect(find.byTooltip('Back to the team'), findsOneWidget);

      for (final section in ['Dial', 'Team', 'Messages', 'Voicemail']) {
        expect(
          find.widgetWithText(ChoiceChip, section),
          findsOneWidget,
          reason: 'the thread swallowed the $section tab',
        );
      }

      // And the desk is still there behind it, not torn down — the softphone
      // lives in it, and one that deregisters when you open a thread is one
      // that misses the call you were about to talk about.
      await _tap(tester, find.widgetWithText(ChoiceChip, 'Dial'));
      expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    });

    /// One total says you have messages without saying whose, and the roster
    /// is where you act on it.
    testWidgets('what is waiting is counted against the person', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [line],
        presence: team,
        unread: const {'u-ana': 2},
      );

      // Against Ana, and not against Bar, who has sent nothing.
      // The roster lives on the Team sub-tab now.
      await _tap(tester, find.widgetWithText(ChoiceChip, 'Team'));

      // The roster is a table now (Mia, 2026-10-02): the count is a "2 new"
      // pill on Ana's row, where it was a bare "2" badge.
      final ana = find.ancestor(
        of: find.text('Ana'),
        matching: find.byKey(const ValueKey('teammate-u-ana')),
      );

      expect(
        find.descendant(of: ana, matching: find.text('2 new')),
        findsOneWidget,
        reason: 'the count was not shown against the person who is waiting',
      );
      expect(find.text('2 new'), findsOneWidget, reason: 'Bar has sent nothing');
    });

    /// Sent and seen, and nothing else — no delivered, no typing indicator.
    /// Those are claims this system cannot honestly make. "Seen" is the one
    /// thing the server knows, because opening the thread records it.
    testWidgets('seen is shown on a read message and not on an unread one', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [line],
        presence: team,
        staffMessages: const [
          {'id': 'm-1', 'body': 'Is the van back?', 'mine': true,
            'read_at': '2026-08-31T10:00:00+00:00'},
        ],
      );

      await _tap(tester, find.widgetWithText(ChoiceChip, 'Team'));
      await _tap(tester, find.byTooltip('Message Ana'));

      expect(find.text('Is the van back?'), findsOneWidget);
      expect(find.text('Seen'), findsOneWidget);
      expect(
        find.text('Delivered'),
        findsNothing,
        reason: 'delivered is a claim the phone system cannot make',
      );
    });

    testWidgets('sending posts the body and who it is for', (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        numbers: const [line],
        presence: team,
        onStaffMessage: (body) => sent = body,
      );

      await _tap(tester, find.widgetWithText(ChoiceChip, 'Team'));
      await _tap(tester, find.byTooltip('Message Ana'));

      await tester.enterText(
        find.byKey(const Key('staff-message-composer')),
        'Ten minutes.',
      );
      await _tap(tester, find.byTooltip('Send'));

      expect(sent, isNotNull);
      expect(sent!['to'], 'u-ana');
      expect(sent!['body'], 'Ten minutes.');
    });
  });

  group('Team', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    const roster = [
      {'user_id': 'u-ana', 'name': 'Analorena', 'extension': '101'},
      {'user_id': 'u-bar', 'name': 'Bar', 'extension': null},
    ];

    Future<void> openExtensions(WidgetTester tester) async {
      await _tap(tester, _tab('Users'));
    }

    /// Somebody opens this asking "what is Bar's number", and the answer is
    /// often "she has not got one". A list of only the assigned cannot say
    /// that — an absent name reads as a loading problem, so everybody is
    /// listed and a blank field is the answer.
    testWidgets('a person with no extension is listed with an empty field', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        extensions: roster,
      );

      await openExtensions(tester);

      expect(find.text('Analorena'), findsOneWidget);
      expect(
        find.text('Bar'),
        findsOneWidget,
        reason: 'somebody without an extension was left out of the list',
      );

      final bar = tester.widget<TextField>(
        find.byKey(const Key('extension:u-bar')),
      );
      expect(bar.controller!.text, isEmpty);
    });

    /// The server refuses a clash by NAMING who already holds the number,
    /// because "already taken" leaves somebody hunting through the roster for
    /// the one field that matches. That sentence has to reach the screen
    /// rather than being replaced by a generic failure.
    testWidgets('a clash says who already holds the number', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        extensions: roster,
        extensionRefusal: 'Analorena already has extension 101.',
      );

      await openExtensions(tester);

      await tester.enterText(
        find.byKey(const Key('extension:u-bar')),
        '101',
      );
      await tester.pumpAndSettle();

      await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

      expect(
        find.textContaining('Analorena already has extension 101'),
        findsOneWidget,
      );
    });

    /// Clearing is a real edit, not an absence of one. The endpoint takes a
    /// present-but-null extension, so a blanked field has to post null rather
    /// than be skipped as "nothing typed".
    testWidgets('clearing a number posts null rather than being skipped', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];

      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        extensions: roster,
        onExtension: sent.add,
      );

      await openExtensions(tester);

      await tester.enterText(find.byKey(const Key('extension:u-ana')), '');
      await tester.pumpAndSettle();

      await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

      expect(sent, hasLength(1));
      expect(sent.single['user_id'], 'u-ana');
      expect(sent.single['extension'], isNull);
    });
  });

  group('Settings › Ring groups', () {
    const conn = {
      'id': 'cx-1',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_name': 'A-1 Chimney',
      'kind': 'subaccount',
      'status': 'connected',
    };

    Map<String, Object?> groupOf(List<Map<String, Object?>> members) => {
      'id': 'rg-1',
      'connection_id': 'cx-1',
      'name': 'Front desk',
      'mode': 'simultaneous',
      'timeout_seconds': 30,
      'member_user_ids': [for (final m in members) m['user_id']],
      'member_role_ids': <String>[],
      'overflow': 'voicemail',
      'members': members,
      'members_measured_at': '2026-08-31T12:00:00+00:00',
    };

    /// The defect this pane was redrawn for.
    ///
    /// It stated the rule once as prose — somebody only rings if signed in
    /// with Phone open and not on Do not disturb — and then said "4 people".
    /// A group where all four are at Do not disturb rings NOBODY and looked
    /// exactly like a working one. The rule was stated and never applied to
    /// the thing it was about.
    testWidgets('a group that can ring nobody says so instead of its headcount', (
      tester,
    ) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: [
          groupOf(const [
            {'user_id': 'u-1', 'name': 'Ana', 'reachable': false, 'state': 'dnd'},
            {'user_id': 'u-2', 'name': 'Bar', 'reachable': false, 'state': 'on_call'},
          ]),
        ],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Ring groups'));

      // "0 of 2" in the Free now column (tiles over a table, Mia 2026-10-02).
      expect(find.text('0 of 2'), findsOneWidget);
      expect(
        find.textContaining('2 people'),
        findsNothing,
        reason: 'the headcount was shown as though it were the answer',
      );
    });

    /// A count with no time against it reads as live, and there is no live
    /// channel — somebody's phone can register a second after this was read.
    testWidgets('the reachability count says how old it is', (tester) async {
      await _pump(
        tester,
        connections: const [conn],
        approvedCompanyIds: const ['co-1'],
        ringGroups: [
          groupOf(const [
            {'user_id': 'u-1', 'name': 'Ana', 'reachable': true, 'state': 'registered'},
          ]),
        ],
      );

      await _tap(tester, find.text('Settings'));
      await _tap(tester, find.text('Ring groups'));

      expect(find.text('1 of 1'), findsOneWidget);
      expect(find.textContaining('It is not live'), findsOneWidget);
    });
  });

  group('Settings › Connection', () {
    /// The inheritance rule, drawn once.
    ///
    /// It used to be six sentences across three branches — each correct, each
    /// chosen by which state you stood in — because one sentence tried to say
    /// two things: where calls go, which never changes, and whether you may
    /// change it here, which depends on your view. Splitting them is what
    /// collapses the six.
    testWidgets('a company with no account of its own says what it inherits', (
      tester,
    ) async {
      await _pump(
        tester,
        approvedCompanyIds: const ['co-a1'],
        connections: const [
          {
            'id': 'cx-org',
            'scope_type': 'organization',
            'scope_id': 'org-1',
            'scope_name': 'A-1 Group',
            'kind': 'subaccount',
            'status': 'connected',
          },
        ],
        companies: const [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
        ],
      );

      await _tap(tester, _tab('Settings'));

      expect(find.text('Uses the organization'), findsOneWidget);
      expect(
        find.textContaining("use the organization's connection above"),
        findsOneWidget,
      );
    });

    /// The sentence above must not be said when there is nothing to inherit.
    ///
    /// "uses the organization's connection" with no organization connection
    /// points at nothing, and it reads as working to somebody whose calls
    /// actually reach no account at all.
    testWidgets('a company with nothing above it says calls reach nobody', (
      tester,
    ) async {
      await _pump(
        tester,
        approvedCompanyIds: const ['co-a1'],
        companies: const [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
        ],
      );

      await _tap(tester, _tab('Settings'));

      expect(
        find.textContaining('reach nobody'),
        findsOneWidget,
        reason: 'a company with no account anywhere read as inheriting one',
      );
      // Jose's UI review, 2026-10-08: the chip said the opposite of the line.
      expect(find.text('Uses the organization'), findsNothing);
      expect(find.text('No account'), findsWidgets);
      expect(
        find.textContaining("use the organization's connection above"),
        findsNothing,
      );
    });
  });

  /// Phone holds three things, and the sub-tab IS the filter.
  ///
  /// Messages and Voicemail are the Records views with their filter fixed,
  /// rather than two more screens reading the same rows a second way. So the
  /// filter chips must NOT appear inside them: a tab labelled Messages that
  /// also offers a Calls chip is two controls for one fact, and a heading that
  /// stops being true the moment somebody presses the chip.
  testWidgets('Phone holds Dial, Messages and Voicemail', (tester) async {
    // The Records view needs a connected line before it draws anything at
    // all — without one it shows an empty state with no chips, and "no SMS
    // chip" would be true for the wrong reason. Found by sabotage: the first
    // version of this test passed with the filters deliberately left on.
    await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      connections: const [
        {
          'id': 'cx-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'scope_name': 'A-1 Chimney',
          'kind': 'subaccount',
          'status': 'connected',
        },
      ],
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'CT main',
        },
      ],

      // With no rows Records draws "there are no calls or messages yet" and
      // never reaches its filter row — so the assertion below would have been
      // true of an empty screen. Found by sabotage: leaving the filters on
      // deliberately did not fail the first two versions of this test.
      calls: const [
        {
          'id': 'c-1',
          'number_id': 'n-1',
          'direction': 'inbound',
          'from_e164': '+13055550100',
          'to_e164': '+12035550142',
          'voicemail': false,
          'created_at': '2026-09-02T10:00:00+00:00',
        },
      ],
      messages: const [
        {
          'id': 'm-1',
          'number_id': 'n-1',
          'direction': 'inbound',
          'from_e164': '+13055550100',
          'to_e164': '+12035550142',
          'body': 'On my way',
          'created_at': '2026-09-02T10:01:00+00:00',
        },
      ],
    );

    await _tap(tester, _tab('Phone'));

    for (final section in ['Dial', 'Team', 'Messages', 'Voicemail']) {
      expect(
        find.widgetWithText(ChoiceChip, section),
        findsOneWidget,
        reason: '$section is not offered under Phone',
      );
    }

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Messages'));
    await tester.pumpAndSettle();

    // The view really is rendered — otherwise the assertion below is true of
    // an empty screen rather than of a filtered one.
    expect(find.byType(RecordsTab), findsOneWidget);


    expect(
      find.widgetWithText(ChoiceChip, 'SMS'),
      findsNothing,
      reason: 'the Messages tab also offered the filter it already is',
    );
  });

  /// Voicemail is BOXES, and a box says whose it is.
  ///
  /// A flat list answers "did anybody ring" and not "did anybody ring ME",
  /// which on a shared account is the question — so it is split the way
  /// responsibility already is, and the transcript is shown rather than hidden
  /// behind a control nobody presses.
  testWidgets('Voicemail shows the boxes, whose they are, and transcripts', (
    tester,
  ) async {
    await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      connections: const [
        {
          'id': 'cx-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'scope_name': 'A-1 Chimney',
          'kind': 'subaccount',
          'status': 'connected',
        },
      ],
      voicemailBoxes: const [
        {
          'id': 'vb-1',
          'connection_id': 'cx-1',
          'name': 'Front desk',
          'owner_type': 'user',
          'owner_id': 'u-ana',
          'owner_name': 'Ana',
        },
      ],
      calls: const [
        {
          'id': 'c-9',
          'number_id': 'n-1',
          'direction': 'inbound',
          'from_e164': '+13055550100',
          'to_e164': '+12035550142',
          'voicemail': true,

          // BOTH, because a transcribed line produces both and this screen
          // must show exactly one of them. `transcript` is the live
          // transcription of the whole call — our greeting, the recording
          // notice, the menu — and it is what this screen used to print,
          // which is what Jose reported as transcripts turning up among his
          // voicemails.
          'transcript': 'Thank you for calling A-1 Chimney. Press one.',
          'voicemail_transcript': 'Can somebody call me back about the chimney',
          'created_at': '2026-09-03T10:00:00+00:00',
        },
      ],
    );

    await _tap(tester, _tab('Phone'));
    await _tap(tester, find.widgetWithText(ChoiceChip, 'Voicemail'));

    expect(find.text("Ana's"), findsOneWidget);
    expect(
      find.textContaining('call me back about the chimney'),
      findsOneWidget,
      reason: 'the transcript was not shown on the voicemail',
    );
    expect(
      find.textContaining('Press one'),
      findsNothing,
      reason: 'the voicemail shows the call transcript, menu prompt and all',
    );

    // And WHEN it was left, or a list of messages cannot be worked through.
    expect(find.textContaining('2026'), findsWidgets);
  });

  /// A box nothing routes to says so, instead of looking like a quiet week.
  ///
  /// This is the defect the whole Voicemail screen had: a box only fills once
  /// a LINE points at it, and on the real account not one line did — so two
  /// boxes sat there empty while nineteen voicemails were filed against lines
  /// with no box. An empty list looks exactly like an empty list, which is why
  /// nobody could have reported it as anything but "clicking does nothing".
  testWidgets('a voicemail box with no line explains itself', (tester) async {
    await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      connections: const [
        {
          'id': 'cx-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'scope_name': 'A-1 Chimney',
          'kind': 'subaccount',
          'status': 'connected',
        },
      ],
      voicemailBoxes: const [
        {
          'id': 'vb-1',
          'connection_id': 'cx-1',
          'name': 'A-1 Chimney Mailbox',
          'owner_type': 'company',
          'owner_id': null,
          'owner_name': null,
          'line_count': 0,
        },
      ],
      calls: const [],
    );

    await _tap(tester, _tab('Phone'));
    await _tap(tester, find.widgetWithText(ChoiceChip, 'Voicemail'));

    // Opens on everything, not on a box that cannot fill.
    expect(find.byKey(const Key('voicemail-box-all')), findsOneWidget);

    // And the name is not repeated back as a subtitle that failed to load.
    expect(find.text('The company'), findsNothing);

    await _tap(tester, find.byKey(const Key('voicemail-box-vb-1')));

    expect(
      find.textContaining('No line sends voicemail to this box'),
      findsOneWidget,
      reason: 'an unroutable box read as though nobody had rung',
    );
  });

  /// A ring group is PEOPLE. It does not hand out phone numbers.
  ///
  /// The editor used to carry a second list of numbers with tick boxes, and it
  /// asked the question backwards: a line points at one group, so ticking it
  /// here edited the line's own setting from the far end — the same setting
  /// the Numbers screen owns. Two editors, one column.
  ///
  /// It also read as "add a number to this group", which a group is not.
  /// Jose named the shape from RingCentral on 2026-09-09: a queue holds
  /// agents, and the destination is set on the number.
  ///
  /// What survives is the FACT, not the control — because a group nothing
  /// points at never rings, and a screen of correct settings no call reaches
  /// is this module's most reliable failure.
  testWidgets('a ring group holds people, and says what reaches it', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: const [
        {
          'id': 'conn-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'subaccount',
          'status': 'connected',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
      ],
      numbers: const [
        {
          'id': 'num-1',
          'connection_id': 'conn-1',
          'e164': '+12394560829',
          'label': 'General Use',
          'ring_group_id': 'rg-marketing',
        },
      ],
      members: const [
        {'id': 'u-1', 'name': 'Dana Reyes', 'role_id': 'r-1'},
      ],
      ringGroups: const [
        {
          'id': 'rg-marketing',
          'connection_id': 'conn-1',
          'name': 'Marketing',
          'mode': 'simultaneous',
          'timeout_seconds': 30,
          'overflow': 'voicemail',
          'member_ids': <String>[],
          'line_ids': ['num-1'],
        },
        {
          'id': 'rg-dispatch',
          'connection_id': 'conn-1',
          'name': 'Dispatch',
          'mode': 'simultaneous',
          'timeout_seconds': 30,
          'overflow': 'voicemail',
          'member_ids': <String>[],
          'line_ids': <String>[],
        },
      ],
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Ring groups'));

    // Edit on Dispatch — the row is a summary; the two lists live in its
    // form. Dispatch is the second row, and Marketing holds the line.
    await _tap(tester, _editOn(tester, 'Dispatch'));

    // Dispatch has no line pointing at it, and says so rather than looking
    // like a working group.
    expect(
      find.textContaining('No number rings this group yet'),
      findsOneWidget,
      reason: 'a group nothing routes to gave no sign it can never ring',
    );

    // The numbers are NOT offered as something to assign here.
    expect(
      find.textContaining('Which main numbers route INTO this group'),
      findsNothing,
      reason: 'the line-assignment list is still on the group editor',
    );
    expect(
      find.widgetWithText(CheckboxListTile, 'General Use'),
      findsNothing,
      reason: 'a phone number is still tickable from inside a ring group',
    );

    // And the group that IS reached names what reaches it.
    await _tap(tester, find.widgetWithText(TextButton, 'Cancel'));
    await _tap(tester, _editOn(tester, 'Marketing'));

    expect(
      find.textContaining('Rings when a call comes to General Use'),
      findsOneWidget,
      reason: 'a reachable group did not say what reaches it',
    );
  });

  /// Editing a second group without cancelling the first.
  ///
  /// ── The defect ─────────────────────────────────────────────────
  ///
  /// `TextFormField.initialValue` is read once, when the field's State is
  /// created. Pressing Edit on another group swaps the draft under a widget in
  /// the same position, so Flutter reuses that State and its controller — and
  /// the name box went on showing the PREVIOUS group while every other control
  /// showed the new one.
  ///
  /// Jose hit it on 2026-09-09 and reported it as leftover text, which is what
  /// it looks like from outside: Dispatch's seven people and Dispatch's "no
  /// number rings this group", under the name "Marketing". Save would have
  /// renamed Dispatch.
  ///
  /// The test above never saw it because it presses Cancel between the two,
  /// which disposes the field and hides the whole failure.
  testWidgets('editing a second group carries none of the first', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: const [
        {
          'id': 'conn-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'subaccount',
          'status': 'connected',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
      ],
      numbers: const [
        {
          'id': 'num-1',
          'connection_id': 'conn-1',
          'e164': '+12394560829',
          'label': 'General Use',
          'ring_group_id': 'rg-marketing',
        },
      ],
      members: const [
        {'id': 'u-1', 'name': 'Dana Reyes', 'role_id': 'r-1'},
      ],
      ringGroups: const [
        {
          'id': 'rg-marketing',
          'connection_id': 'conn-1',
          'name': 'Marketing',
          'mode': 'simultaneous',
          'timeout_seconds': 30,
          'overflow': 'voicemail',
          'member_ids': <String>[],
          'line_ids': ['num-1'],
        },
        {
          'id': 'rg-dispatch',
          'connection_id': 'conn-1',
          'name': 'Dispatch',
          'mode': 'simultaneous',
          'timeout_seconds': 30,
          'overflow': 'voicemail',
          'member_ids': <String>[],
          'line_ids': <String>[],
        },
      ],
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Ring groups'));

    await _tap(tester, _editOn(tester, 'Marketing'));

    // Straight to the other one. NO Cancel — that is the whole point.
    await _tap(tester, _editOn(tester, 'Dispatch'));

    expect(
      find.descendant(
        of: find.byType(TextFormField),
        matching: find.text('Dispatch'),
      ),
      findsOneWidget,
      reason:
          'the name box still holds the group edited a moment ago, so Save '
          'would rename this one to that',
    );

    expect(
      find.descendant(
        of: find.byType(TextFormField),
        matching: find.text('Marketing'),
      ),
      findsNothing,
    );

    // And the rest of the form really did move, so the assertion above is
    // about the name field and not about nothing having happened.
    expect(
      find.textContaining('No number rings this group yet'),
      findsOneWidget,
    );
  });
}

/// The Edit button on one ring group's row (a table row since tiles over a
/// table, Mia 2026-10-02; it was a card).
Finder _editOn(WidgetTester tester, String group) => find
    .descendant(
      of: find.ancestor(
        of: find.text(group),
        matching: find.byWidgetPredicate(
          (w) => w.key is ValueKey<String> &&
              (w.key! as ValueKey<String>).value.startsWith('ring-group-'),
        ),
      ),
      matching: find.widgetWithText(TextButton, 'Edit'),
    )
    .first;
Finder _tab(String label) => find.widgetWithText(ChoiceChip, label);

/// The TOP-LEVEL tabs only.
///
/// Scoped to the tab bar because Phone now draws its own Dial / Messages /
/// Voicemail chips inside itself — an unscoped search collects both levels and
/// this list silently grew to eight.
List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(
      find.descendant(
        of: find.byType(TabBarLike),
        matching: find.byType(ChoiceChip),
      ),
    )
    .map((chip) => (chip.label as Text).data!)
    .toList();

/// Scroll a lazily-built ListView until [finder] exists, then settle.
///
/// `ensureVisible` cannot help here: a ListView does not build its offscreen
/// children at all, so the widget is not merely out of view — it is not in the
/// tree, and any finder for it matches nothing.
Future<void> _scrollTo(
  WidgetTester tester,
  Finder finder, {
  required Finder within,
}) async {
  if (finder.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
    return;
  }

  await tester.scrollUntilVisible(
    finder,
    240,
    scrollable: find.descendant(of: within, matching: find.byType(Scrollable)).first,
  );
  await tester.pumpAndSettle();
}

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

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, Object?>> flows = const [],
  void Function(Map<String, dynamic> body)? onFlow,
  Map<String, Object?> trust = const {},
  void Function(Map<String, dynamic> body)? onHours,
  List<Map<String, Object?>> importable = const [],
  List<Map<String, Object?>> callFlow = const [],
  List<Map<String, Object?>> schedules = const [],
  void Function(Map<String, dynamic> body)? onImport,
  Size size = const Size(1280, 900),
  List<String> permissions = const ['phone.view', 'phone.edit'],
  List<String> tabs = _allPhoneTabs,
  List<Map<String, Object?>> connections = const [],
  Map<String, Object?>? scope,
  List<Map<String, Object?>>? companies,
  int createStatus = 201,
  String? createMessage,
  List<Map<String, Object?>> subaccounts = const [],
  List<Map<String, Object?>> numbers = const [],
  List<Map<String, Object?>> ringGroups = const [],
  List<Map<String, Object?>> members = const [],
  List<Map<String, Object?>> serviceCompanies = const [],
  List<String> approvedCompanyIds = const [],
  Map<String, Object?>? serviceRequest,
  void Function(Map<String, dynamic> body)? onServiceRequest,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onRingGroup,
  void Function(Map<String, dynamic> body)? onNumberPatch,
  void Function()? onDelete,
  List<String>? requestLog,
  List<Map<String, Object?>> calls = const [],
  List<Map<String, Object?>> messages = const [],
  List<Map<String, Object?>> extensions = const [],
  String? extensionRefusal,
  void Function(Map<String, dynamic> body)? onExtension,
  List<Map<String, Object?>> presence = const [],
  List<Map<String, Object?>> staffMessages = const [],
  Map<String, Object?> unread = const {},
  void Function(Map<String, dynamic> body)? onStaffMessage,
  List<Map<String, Object?>> voicemailBoxes = const [],
  bool voicemailFails = false,
  void Function(Map<String, dynamic> body)? onVoicemailSave,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        tabs: tabs,
        connections: connections,
        scope: scope,
        companies: companies,
        createStatus: createStatus,
        createMessage: createMessage,
        subaccounts: subaccounts,
        numbers: numbers,
        ringGroups: ringGroups,
        members: members,
        serviceCompanies: serviceCompanies,
        approvedCompanyIds: approvedCompanyIds,
        serviceRequest: serviceRequest,
        onServiceRequest: onServiceRequest,
        onCreate: onCreate,
        onRingGroup: onRingGroup,
        onNumberPatch: onNumberPatch,
        onDelete: onDelete,
        requestLog: requestLog,
        calls: calls,
        messages: messages,
        extensions: extensions,
        extensionRefusal: extensionRefusal,
        onExtension: onExtension,
        presence: presence,
        staffMessages: staffMessages,
        unread: unread,
        onStaffMessage: onStaffMessage,
        voicemailBoxes: voicemailBoxes,
        voicemailFails: voicemailFails,
        onVoicemailSave: onVoicemailSave,
        importable: importable,
    callFlow: callFlow,
        schedules: schedules,
        onImport: onImport,
        trust: trust,
        onHours: onHours,
        flows: flows,
        onFlow: onFlow,
      ),
    ),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: PhoneScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler()));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text('Phone'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<Map<String, Object?>> flows = const [],
  void Function(Map<String, dynamic> body)? onFlow,
  Map<String, Object?> trust = const {},
  void Function(Map<String, dynamic> body)? onHours,
  List<Map<String, Object?>> importable = const [],
  List<Map<String, Object?>> callFlow = const [],
  List<Map<String, Object?>> schedules = const [],
  void Function(Map<String, dynamic> body)? onImport,
  List<String> permissions = const ['phone.view', 'phone.edit'],
  List<String> tabs = _allPhoneTabs,
  List<Map<String, Object?>> connections = const [],
  Map<String, Object?>? scope,
  List<Map<String, Object?>>? companies,
  int createStatus = 201,
  String? createMessage,
  List<Map<String, Object?>> subaccounts = const [],
  List<Map<String, Object?>> numbers = const [],
  List<Map<String, Object?>> ringGroups = const [],
  List<Map<String, Object?>> members = const [],
  List<Map<String, Object?>> serviceCompanies = const [],
  List<String> approvedCompanyIds = const [],
  Map<String, Object?>? serviceRequest,
  void Function(Map<String, dynamic> body)? onServiceRequest,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onRingGroup,
  void Function(Map<String, dynamic> body)? onNumberPatch,
  void Function()? onDelete,
  List<String>? requestLog,
  List<Map<String, Object?>> calls = const [],
  List<Map<String, Object?>> messages = const [],
  List<Map<String, Object?>> extensions = const [],
  String? extensionRefusal,
  void Function(Map<String, dynamic> body)? onExtension,
  List<Map<String, Object?>> presence = const [],
  List<Map<String, Object?>> staffMessages = const [],
  Map<String, Object?> unread = const {},
  void Function(Map<String, dynamic> body)? onStaffMessage,
  List<Map<String, Object?>> voicemailBoxes = const [],
  bool voicemailFails = false,
  void Function(Map<String, dynamic> body)? onVoicemailSave,
}) {
  return (request) async {
    final path = request.url.path;
    requestLog?.add('${request.method} $path');

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          // The tab grants ride beside the module ones; see _allPhoneTabs.
          'permissions': [...permissions, ...tabs],
          'scope': ?scope,
          'companies': ?companies,
        },
      });
    }

    if (path.endsWith('/phone/service-request/companies') &&
        request.method == 'GET') {
      return _json({'data': serviceCompanies});
    }

    if (path.endsWith('/phone/service-request') && request.method == 'GET') {
      return _json({
        'data': {
          'latest': serviceRequest,
          'approved_company_ids': approvedCompanyIds,
          'may_request': serviceRequest == null,
          'cooldown_ends_at': null,
          'volumes': {'under_100': 'Fewer than 100 calls a month'},
        },
      });
    }

    if (path.endsWith('/phone/service-request') && request.method == 'POST') {
      onServiceRequest?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'status': 'pending'}}, 201);
    }

    if (path.endsWith('/phone/connections/subaccounts') &&
        request.method == 'GET') {
      return _json({'data': subaccounts});
    }

    if (path.endsWith('/phone/connections') && request.method == 'GET') {
      return _json({'data': connections});
    }

    if (path.endsWith('/phone/call-flow') && request.method == 'GET') {
      return _json({'data': callFlow});
    }

    if (path.endsWith('/phone/numbers/importable') && request.method == 'GET') {
      return _json({'data': importable});
    }

    // What Twilio says about one line, read live when the edit form opens.
    if (path.endsWith('/carrier') && request.method == 'GET') {
      return _json({
        'data': {
          'sid': 'PN1',
          'phone_number': '+15612323929',
          'friendly_name': 'EZBZ',
          'capabilities': {'voice': true, 'sms': true, 'mms': false, 'fax': false},
          'emergency_status': 'Inactive',
          'emergency_address_sid': null,
          'voice_caller_id_lookup': false,
          'address_requirements': 'none',
        },
      });
    }

    if (path.endsWith('/phone/addresses') && request.method == 'GET') {
      return _json({'data': <Map<String, Object?>>[]});
    }

    if (path.endsWith('/phone/numbers/import') && request.method == 'POST') {
      onImport?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': <Map<String, Object?>>[]}, 201);
    }

    if (path.endsWith('/phone/flows') && request.method == 'GET') {
      return _json({'data': flows});
    }

    if (path.endsWith('/phone/flows') && request.method == 'POST') {
      onFlow?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'fl-new'}}, 201);
    }

    if (path.endsWith('/phone/hours') && request.method == 'GET') {
      return _json({'data': schedules});
    }

    if (path.endsWith('/phone/hours') && request.method == 'POST') {
      onHours?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'hs-new'}}, 201);
    }

    if (path.contains('/trust') && request.method == 'GET') {
      return _json({'data': trust});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': numbers});
    }

    if (path.endsWith('/phone/ring-groups') && request.method == 'GET') {
      return _json({'data': ringGroups});
    }

    if (path.endsWith('/phone/members') && request.method == 'GET') {
      return _json({
        'data': {'people': members, 'roles': <Map<String, Object?>>[]},
      });
    }

    if (path.contains('/phone/ring-groups') && request.method == 'POST') {
      onRingGroup?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'rg-new'}}, 201);
    }

    if (path.contains('/phone/numbers/') && request.method == 'PATCH') {
      onNumberPatch?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': <String, Object?>{}});
    }

    if (path.endsWith('/phone/calls') && request.method == 'GET') {
      return _json({'data': calls});
    }

    if (path.endsWith('/phone/messages') && request.method == 'GET') {
      return _json({'data': messages});
    }

    if (path.endsWith('/phone/presence')) {
      return _json({'data': presence});
    }

    if (path.endsWith('/staff-messages/unread') && request.method == 'GET') {
      var total = 0;
      for (final value in unread.values) {
        total += int.tryParse(value.toString()) ?? 0;
      }
      return _json({'data': unread, 'total': total});
    }

    if (path.endsWith('/staff-messages') && request.method == 'GET') {
      return _json({'data': staffMessages});
    }

    if (path.endsWith('/staff-messages') && request.method == 'POST') {
      onStaffMessage?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'sm-new'}}, 201);
    }

    if (path.endsWith('/phone/tokens') && request.method == 'POST') {
      return _json({
        'token': 'fake-access-token',
        'identity': 'u1',
        'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
      });
    }

    if (path.contains('/phone/connections/') && request.method == 'DELETE') {
      onDelete?.call();
      return _json({'message': 'deleted'}, 200);
    }

    if (path.endsWith('/phone/connections') && request.method == 'POST') {
      onCreate?.call(jsonDecode(request.body) as Map<String, dynamic>);
      if (createStatus == 501) {
        return _json({
          'message':
              createMessage ??
              'EzBizi has not been given a Twilio account to create phone service with.',
        }, 501);
      }
      if (createStatus == 409) {
        return _json({
          'message':
              createMessage ??
              'A phone connection is already set up for that place. Disconnect it before connecting another.',
        }, 409);
      }
      return _json({
        'data': {
          'id': 'pc-new',
          'organization_id': 'org-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'byo',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APdddddddddddddddddddddddddddddddd',
          'status': 'connected',
          'status_reason': null,
        },
      }, createStatus);
    }

    if (path.endsWith('/phone/voicemail-boxes') && request.method == 'GET') {
      if (voicemailFails) {
        return _json({'message': 'The phone service did not answer.'}, 503);
      }
      return _json({'data': voicemailBoxes});
    }

    if (path.contains('/phone/voicemail-boxes/') && request.method == 'PATCH') {
      onVoicemailSave?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': voicemailBoxes.isEmpty ? {} : voicemailBoxes.first});
    }

    if (path.endsWith('/phone/extensions') && request.method == 'GET') {
      return _json({'data': extensions, 'connection_id': 'cx-1'});
    }

    if (path.endsWith('/phone/extensions') && request.method == 'PUT') {
      onExtension?.call(jsonDecode(request.body) as Map<String, dynamic>);
      if (extensionRefusal != null) {
        return _json({'message': extensionRefusal}, 422);
      }
      return _json({'data': <String, Object?>{}});
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// Every Communication tab (Jose, 2026-09-29). Tests about one pane get all
/// of them; the tab-gating tests pass their own.
const _allPhoneTabs = [
  'phone.phone.view',
  'phone.users.view',
  'phone.records.view',
  'phone.reports.view',
  'phone.settings.view',
];

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
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
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
