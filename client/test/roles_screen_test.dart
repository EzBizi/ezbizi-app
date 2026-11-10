import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/roles_screen.dart';
import 'package:ezbizi_client/screens/settings_shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The role editor (plan §4).
///
/// Two things are being pinned down here, and they are different in kind.
///
/// The first is the owner's one-switch design. A role must never be able to
/// reach "sees the tab but may view nothing", nor "may edit but the tab is
/// hidden". The server will refuse both; the UI has to make them unreachable as
/// well, because a control that can be put into a state the server rejects is a
/// control that teaches people the app is broken.
///
/// The second is that none of this is written down in Dart. The modules, their
/// names, their actions and which role is the untouchable one all arrive from
/// the server — so the fixtures below are the only place those words appear,
/// and a module invented in a fixture renders exactly like a real one.
void main() {
  /// The tab IS the module's view permission, and everything else in the module
  /// hangs off it. Untick it and the actions inside go with it.
  // Jose, 2026-10-01, on Eve's Suggestion: which roles a role may offer when
  // it invites, chosen on the role. Sent only when changed.
  testWidgets('a role can be given the roles it may invite as', (tester) async {
    _wide(tester);

    final sent = <Map<String, dynamic>>[];

    await _pumpRoles(
      tester,
      roles: [
        _role('r-2', 'Lead Manager', permissions: ['customers.view']),
        _role('r-3', 'Technician', permissions: ['jobs.view']),
      ],
      onPatch: sent.add,
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();
    expect(
      sent.last.containsKey('invitable_roles'),
      isFalse,
      reason: 'An unchanged setting was sent back.',
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Only roles chosen here'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const ValueKey('role-invitable-r-3')),
    );
    await tester.tap(find.byKey(const ValueKey('role-invitable-r-3')));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(sent.last['invitable_roles'], ['r-3']);
  });

  testWidgets('unticking a tab clears the actions inside it', (tester) async {
    _wide(tester);

    Map<String, dynamic>? sent;

    await _pumpRoles(
      tester,
      roles: [
        _role(
          'r-2',
          'Manager',
          permissions: ['customers.view', 'customers.create', 'jobs.view'],
        ),
      ],
      onPatch: (body) => sent = body,
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    expect(_tabState('customers'), isNull, reason: 'Two of four — a dash.');

    await tester.tap(_tabCheckbox('customers'));
    await tester.pumpAndSettle();

    expect(_tabState('customers'), isFalse);

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(
      sent!['permissions'],
      ['jobs.view'],
      reason:
          'Create survived the tab being switched off — the role could edit '
          'customers it had no way to open.',
    );
  });

  /// The other half of the same invariant, in the other direction.
  testWidgets('granting an action grants the tab it lives on', (tester) async {
    _wide(tester);

    Map<String, dynamic>? sent;

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      onPatch: (body) => sent = body,
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    // Open the detail and tick Edit on its own. Nothing else is granted, so
    // without the rule this leaves a role that may edit a customer and has no
    // Customers tab to do it from.
    await tester.tap(find.text('Customers'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('action:customers.edit')));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(sent!['permissions'], ['customers.edit', 'customers.view']);
  });

  /// The payload is the keys, and only the keys.
  testWidgets('what is saved is exactly the permissions the role holds', (
    tester,
  ) async {
    _wide(tester);

    Map<String, dynamic>? sent;

    await _pumpRoles(
      tester,
      roles: [
        _role('r-2', 'Manager', permissions: ['customers.view']),
      ],
      onPatch: (body) => sent = body,
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    // Switching a tab on grants view and nothing else. Handing out Delete to
    // somebody who ticked a box to make a tab appear is not a default.
    await tester.tap(_tabCheckbox('jobs'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(sent!['permissions'], ['customers.view', 'jobs.view']);
    expect(sent!['name'], 'Manager');
  });

  /// The owner's words: "it could lock itself out of its own powers, too
  /// dangerous."
  ///
  /// Which role that is comes from the server. The client never matches on the
  /// NAME — an organization is free to create a role called "Administrators",
  /// and matching the word is the same class of bug as the server-side
  /// short-circuit this whole change removes.
  testWidgets('the built-in administrator opens, and cannot be changed', (
    tester,
  ) async {
    _wide(tester);

    var patched = false;

    await _pumpRoles(
      tester,
      roles: [
        _role(
          'r-1',
          'Administrator',
          permissions: ['customers.view', 'customers.create'],
          editable: false,
          users: 2,
        ),
      ],
      onPatch: (_) => patched = true,
    );

    // Not hidden. Somebody looking for it and not finding it assumes the app is
    // broken, and it is the role most people come to this screen to check.
    // Listed under "Built in" since UI queue 111 (option 1).
    expect(find.byKey(const ValueKey('role-row:r-1')), findsOneWidget);
    expect(find.text('Built in'), findsOneWidget);

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    expect(
      find.textContaining('cannot be renamed'),
      findsOneWidget,
      reason: 'Read-only without a reason reads as a bug rather than a rule.',
    );

    expect(
      tester.widget<Checkbox>(_tabCheckbox('customers')).onChanged,
      isNull,
    );
    // Rename and Delete are drawn, greyed — never hidden.
    expect(
      tester
          .widget<OutlinedButton>(find.byKey(const ValueKey('role-rename')))
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(find.byKey(const ValueKey('role-delete')))
          .onPressed,
      isNull,
    );
    expect(
      find.widgetWithText(FilledButton, 'Save'),
      findsNothing,
      reason: 'A save button that cannot save is a control that lies.',
    );

    expect(patched, isFalse);
  });

  testWidgets('the built-in administrator is listed first, under Built in', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [
        _role('r-2', 'Accounting', permissions: const []),
        _role(
          'r-1',
          'Administrator',
          permissions: const ['customers.view'],
          editable: false,
          users: 2,
        ),
        _role('r-3', 'Dispatcher', permissions: const ['jobs.view']),
      ],
    );

    // Two groups since UI queue 111 (option 1): "Built in", then "Yours".
    final builtIn = tester.getTopLeft(find.text('Built in'));
    final yours = tester.getTopLeft(find.text('Yours'));
    final admin = tester.getTopLeft(find.byKey(const ValueKey('role-row:r-1')));
    final accounting = tester.getTopLeft(
      find.byKey(const ValueKey('role-row:r-2')),
    );
    final dispatcher = tester.getTopLeft(
      find.byKey(const ValueKey('role-row:r-3')),
    );

    expect(builtIn.dy, lessThan(admin.dy));
    expect(admin.dy, lessThan(yours.dy));
    expect(yours.dy, lessThan(accounting.dy));
    expect(accounting.dy, lessThan(dispatcher.dy));

    // And it is the one opened first, by what the server said rather than by
    // where it came in the answer.
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('role-title'))).data,
      'Administrator',
    );
  });

  /// §13. A module the platform has switched off is SHOWN — greyed, dead, and
  /// saying why. Removing it leaves somebody hunting for a tab that used to be
  /// there, and the kill switch gets thrown mid-incident, when that guess costs
  /// the most.
  testWidgets('a module the platform switched off is greyed, not hidden', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule('customers', 'Customers'),
        _catalogueModule(
          'jobs',
          'Jobs',
          available: false,
          reason:
              'Jobs is paused for your organization while we fix an '
              'issue with scheduling.',
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    expect(find.text('Jobs'), findsOneWidget);
    expect(find.textContaining('paused for your organization'), findsOneWidget);
    expect(tester.widget<Checkbox>(_tabCheckbox('jobs')).onChanged, isNull);

    // The one beside it is still live, so "greyed" is about that module rather
    // than about the whole panel being read-only.
    expect(
      tester.widget<Checkbox>(_tabCheckbox('customers')).onChanged,
      isNotNull,
    );
  });

  testWidgets('grants follow the sidebar, with Organization not a fake tab', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [
        _role('r-2', 'Dispatcher', permissions: const ['dashboard.view']),
      ],
      catalogue: [
        _catalogueModule('dashboard', 'Dashboard'),
        _catalogueModule('customers', 'Customers'),
        _catalogueModule(
          'companies',
          'Companies',
          groupKey: 'organization',
          groupLabel: 'Organization',
          groupHint:
              'The Organization tab in Settings. '
              'There is no Companies or Franchises tab.',
        ),
        _catalogueModule(
          'franchises',
          'Franchises',
          groupKey: 'organization',
          groupLabel: 'Organization',
          groupHint:
              'The Organization tab in Settings. '
              'There is no Companies or Franchises tab.',
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    expect(find.text('Organization'), findsWidgets);
    expect(
      find.textContaining('no Companies or Franchises tab'),
      findsOneWidget,
    );

    final titles = tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(const ValueKey('roles-grid')),
            matching: find.byType(Text),
          ),
        )
        .map((t) => t.data)
        .whereType<String>()
        .toList();

    expect(titles.indexOf('Dashboard'), lessThan(titles.indexOf('Customers')));
    expect(titles.indexOf('Customers'), lessThan(titles.indexOf('Companies')));
  });

  /// §4: nobody grants what they do not hold — but a role can hold what the
  /// person editing it does not.
  ///
  /// The server preserves those keys on a save and answers 422 for the whole
  /// request if one of them is named in it, so the ticked box has to be shown,
  /// has to be dead, and has to stay out of the payload. All three, or the
  /// editor either lies about the role or cannot save it at all.
  testWidgets('a permission the editor does not hold is shown, dead, and left '
      'out of the payload', (tester) async {
    _wide(tester);

    Map<String, dynamic>? sent;

    await _pumpRoles(
      tester,
      roles: [
        _role(
          'r-2',
          'Supervisor',
          permissions: ['customers.view', 'customers.delete'],
        ),
      ],
      catalogue: [
        _catalogueModule('customers', 'Customers', ungrantable: ['delete']),
      ],
      onPatch: (body) => sent = body,
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customers'));
    await tester.pumpAndSettle();

    final delete = tester.widget<CheckboxListTile>(
      find.byKey(const ValueKey('action:customers.delete')),
    );

    expect(delete.value, isTrue, reason: 'The role does hold it.');
    expect(delete.onChanged, isNull, reason: 'And this person cannot move it.');

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(
      sent!['permissions'],
      ['customers.view'],
      reason:
          'Naming a key the caller does not hold is a 422 for the whole save, '
          'and leaving it out is not a removal — the server keeps it.',
    );
  });

  /// Everything in the editor comes off the wire, including the words.
  testWidgets('the tree is whatever the server declares', (tester) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule(
          'work_orders',
          'Work orders',
          actions: const [('view', 'See them'), ('approve', 'Approve one')],
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();

    // A module this build has never heard of, with actions it has never heard
    // of, rendered from the answer alone.
    expect(find.text('Work orders'), findsOneWidget);
    expect(_tabState('work_orders'), isFalse);
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('can-do-text:work_orders')))
          .data,
      '—',
    );

    await tester.tap(find.text('Work orders'));
    await tester.pumpAndSettle();

    expect(find.text('See them'), findsOneWidget);
    expect(find.text('Approve one'), findsOneWidget);
  });

  /// ── The organization's own inboxes, nested under the module ────
  ///
  /// The owner: "the organization can grant access to the email module to a
  /// role, but not necessary to all the emails connected to the module."
  ///
  /// Everything about these rows is tenant data — the addresses exist because
  /// somebody connected them — so they arrive on the same answer as the modules
  /// and this end knows nothing about them beyond where to nest them.
  group('modules drawn inside another module', () {
    /// Leads, Reviews and Business Profile are tabs inside Marketing, and the
    /// editor lays the grants out the same way — so an administrator reads the
    /// tree in the shape of the app they are granting it for.
    testWidgets('a child is listed under its parent, not as a tab of its own', (
      tester,
    ) async {
      _wide(tester);

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule('customers', 'Customers'),
          _catalogueModule('marketing', 'Marketing'),
          _catalogueModule('leads', 'Leads', parent: 'marketing'),
        ],
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      // Present and grantable — nesting hides nothing.
      expect(find.byKey(const ValueKey('module:leads')), findsOneWidget);

      // Indented, which is the only thing on this screen that says where it is
      // drawn. Measured rather than asserted on a widget type, because the
      // indent is the whole statement.
      final parent = tester
          .getTopLeft(find.byKey(const ValueKey('module-name:marketing')))
          .dx;
      final child = tester
          .getTopLeft(find.byKey(const ValueKey('module-name:leads')))
          .dx;

      expect(child, greaterThan(parent));

      // And it is under Marketing rather than somewhere else in the list.
      final marketingY = tester
          .getTopLeft(find.byKey(const ValueKey('module:marketing')))
          .dy;
      final leadsY = tester
          .getTopLeft(find.byKey(const ValueKey('module:leads')))
          .dy;

      expect(leadsY, greaterThan(marketingY));
    });

    /// The rule the nesting must not quietly break (§4): a switch grants its
    /// own module and nothing else.
    testWidgets('ticking the parent does not grant the child', (tester) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule('marketing', 'Marketing'),
          _catalogueModule('leads', 'Leads', parent: 'marketing'),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      await tester.tap(_tabCheckbox('marketing'));
      await tester.pumpAndSettle();

      expect(_tabState('leads'), isFalse);

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(
        (sent!['permissions'] as List).where((k) => '$k'.startsWith('leads.')),
        isEmpty,
        reason: 'Drawing Leads inside Marketing is a layout fact, not a grant.',
      );
    });
  });

  group('inboxes nested under their module', () {
    testWidgets('the tree carries this organization\'s own addresses', (
      tester,
    ) async {
      _wide(tester);

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule('customers', 'Customers'),
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View'), ('send', 'Send')],
            inboxes: [_inbox('mb-1', 'info@a-1chimney.com')],
          ),
        ],
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Email'));
      await tester.pumpAndSettle();

      expect(find.text('info@a-1chimney.com'), findsOneWidget);

      // And nothing under a module the server sent no inboxes for. The absence
      // of the key is the statement "this module has nothing to do with mail",
      // and it must not draw an empty Inboxes heading under Customers.
      await tester.tap(find.text('Customers'));
      await tester.pumpAndSettle();

      expect(find.text('Inboxes'), findsOneWidget);
    });

    // Jose 11-10: "No inboxes granted" read as "Lucas cannot read office@nj".
    testWidgets('a role with no inboxes says how many of its people were given '
        'some by name', (tester) async {
      _wide(tester);
      await _pumpRoles(
        tester,
        roles: [
          _role(
            'r-2',
            'Local Manager',
            permissions: const ['email.view'],
            users: 14,
            ownInboxes: 9,
          ),
        ],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View')],
            inboxes: [_inbox('mb-1', 'office@nj.a-1chimney.com')],
          ),
        ],
      );
      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      expect(
        find.textContaining(
          '9 of its 14 people have inboxes given to them by name',
        ),
        findsOneWidget,
      );
      expect(
        find.textContaining('The tab on its own opens none'),
        findsNothing,
      );
    });

    /// The control that carries the whole point: the tab is one grant and the
    /// inbox is another.
    testWidgets('ticking the tab grants no inbox, and says so', (tester) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View')],
            inboxes: [
              _inbox('mb-1', 'info@a-1chimney.com'),
              _inbox('mb-2', 'billing@everdoors.com'),
            ],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      await tester.tap(_tabCheckbox('email'));
      await tester.pumpAndSettle();

      expect(_tabState('email'), isTrue, reason: 'The one action is granted.');

      // Visible without opening anything. Somebody who ticked Email expecting
      // it to include the mail can see that it did not.
      expect(
        find.textContaining('No inboxes granted, of 2'),
        findsOneWidget,
        reason:
            'A tab that quietly granted nothing, with nothing saying so, is how '
            'somebody concludes the mail is covered and stops checking.',
      );

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent!['permissions'], ['email.view']);
      expect(
        sent!['inboxes'],
        isEmpty,
        reason: 'Switching the tab on must not hand out anybody’s mail.',
      );
    });

    /// And the other direction, which IS implied: an inbox behind a tab the role
    /// cannot open opens nothing, and the server refuses to store it.
    testWidgets('ticking an inbox grants the tab it lives on', (tester) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View'), ('send', 'Send')],
            inboxes: [
              _inbox('mb-1', 'info@a-1chimney.com'),
              _inbox('mb-2', 'billing@everdoors.com'),
            ],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Email'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('inbox:mb-2')));
      await tester.pumpAndSettle();

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent!['permissions'], ['email.view']);
      expect(sent!['inboxes'], ['mb-2']);

      // The control assertion. Without it this passes just as well against an
      // editor that grants every inbox the moment one is ticked.
      expect(sent!['inboxes'], isNot(contains('mb-1')));
    });

    /// Revoking has to be as reachable as granting. It is the same box.
    testWidgets('unticking a granted inbox takes it back', (tester) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [
          _role(
            'r-2',
            'Manager',
            permissions: ['email.view'],
            inboxes: ['mb-1', 'mb-2'],
          ),
        ],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View')],
            inboxes: [
              _inbox('mb-1', 'info@a-1chimney.com'),
              _inbox('mb-2', 'billing@everdoors.com'),
            ],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      expect(find.textContaining('2 of 2 inboxes granted'), findsOneWidget);

      await tester.tap(find.text('Email'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('inbox:mb-1')));
      await tester.pumpAndSettle();

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent!['inboxes'], ['mb-2']);
      // The tab survives losing an inbox — they are separate grants in both
      // directions.
      expect(sent!['permissions'], ['email.view']);
    });

    /// An organization that has connected nothing sees a sentence, never a
    /// nested list with nothing in it.
    testWidgets('a module with no inboxes yet explains itself', (tester) async {
      _wide(tester);

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View')],
            inboxes: const [],
          ),
        ],
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      expect(find.text('No inboxes connected yet.'), findsOneWidget);

      await tester.tap(find.text('Email'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('appear here as soon as somebody does'),
        findsOneWidget,
        reason:
            'An empty list under a heading is indistinguishable from a screen '
            'that failed to load.',
      );

      // The heading is there and there is nothing under it to tick, which is
      // the control: the sentence is not being drawn beside real rows.
      expect(find.byType(CheckboxListTile), findsOneWidget);
    });

    /// §4 again, one level down: a role can hold an inbox the person editing it
    /// cannot open. Shown, ticked, dead — and out of the payload, because
    /// naming it is a 422 for the whole save and omitting it is not a removal.
    testWidgets(
      'an inbox the editor cannot open is shown, dead, and preserved',
      (tester) async {
        _wide(tester);

        Map<String, dynamic>? sent;

        await _pumpRoles(
          tester,
          roles: [
            _role(
              'r-2',
              'Supervisor',
              permissions: ['email.view'],
              inboxes: ['mb-1', 'mb-2'],
            ),
          ],
          catalogue: [
            _catalogueModule(
              'email',
              'Email',
              actions: const [('view', 'View')],
              inboxes: [
                _inbox('mb-1', 'info@a-1chimney.com'),
                _inbox('mb-2', 'payroll@everdoors.com', grantable: false),
              ],
            ),
          ],
          onPatch: (body) => sent = body,
        );

        await tester.tap(_firstRole);
        await tester.pumpAndSettle();
        await tester.tap(find.text('Email'));
        await tester.pumpAndSettle();

        final payroll = tester.widget<CheckboxListTile>(
          find.byKey(const ValueKey('inbox:mb-2')),
        );

        expect(payroll.value, isTrue, reason: 'The role does hold it.');
        expect(
          payroll.onChanged,
          isNull,
          reason: 'This person cannot move it.',
        );

        await tester.tap(find.widgetWithText(FilledButton, 'Save'));
        await tester.pumpAndSettle();

        expect(sent!['inboxes'], ['mb-1']);
      },
    );

    /// Switching the tab off takes the mail with it — the state the server
    /// refuses is one the editor never produces.
    testWidgets('unticking the tab clears the inboxes under it', (
      tester,
    ) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [
          _role(
            'r-2',
            'Manager',
            permissions: ['email.view'],
            inboxes: ['mb-1'],
          ),
        ],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View')],
            inboxes: [_inbox('mb-1', 'info@a-1chimney.com')],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();

      await tester.tap(_tabCheckbox('email'));
      await tester.pumpAndSettle();

      expect(_tabState('email'), isFalse);

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent!['permissions'], isEmpty);
      expect(
        sent!['inboxes'],
        isEmpty,
        reason:
            'A grant behind a tab the role cannot open opens nothing, and the '
            'server refuses to store it.',
      );
    });

    /// A catalogue with no inboxes anywhere must not make a statement about
    /// them. Absent means "leave these alone"; an empty list means "this role
    /// should hold none", and this editor never drew a control to say that.
    testWidgets('a catalogue with no inbox module sends no inbox field', (
      tester,
    ) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [_catalogueModule('customers', 'Customers')],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(_tabCheckbox('customers'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent!.containsKey('permissions'), isTrue);
      expect(
        sent!.containsKey('inboxes'),
        isFalse,
        reason:
            'Sending an empty list here would revoke every inbox grant on a '
            'role, from a screen that showed nobody a single inbox.',
      );
    });

    testWidgets(
      'named inspections nest under Inspection, not under Customers',
      (tester) async {
        _wide(tester);

        Map<String, dynamic>? sent;

        await _pumpRoles(
          tester,
          roles: [_role('r-2', 'Manager', permissions: const [])],
          catalogue: [
            _catalogueModule('customers', 'Customers'),
            _catalogueModule(
              'inspection',
              'Inspection',
              actions: const [('view', 'View'), ('create', 'Create')],
              inspections: [
                {'id': 'def-1', 'name': 'Level 1', 'grantable': true},
              ],
            ),
          ],
          onPatch: (body) => sent = body,
        );

        await tester.tap(_firstRole);
        await tester.pumpAndSettle();
        await tester.tap(find.text('Inspection'));
        await tester.pumpAndSettle();

        expect(find.text('Named inspections'), findsOneWidget);
        expect(find.text('Level 1'), findsOneWidget);

        await tester.tap(_tabCheckbox('inspection'));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('inbox:def-1')));
        await tester.pumpAndSettle();
        await tester.tap(find.widgetWithText(FilledButton, 'Save'));
        await tester.pumpAndSettle();

        expect(sent!['inspections'], ['def-1']);
        expect(sent!.containsKey('inboxes'), isFalse);
      },
    );

    /// Phone lines are NOT a per-role grant, and the editor must not offer
    /// them — even if a server still sends the key.
    ///
    /// This asserted the opposite until 3 Sep: that lines nested under Phone
    /// and saved as `phone_numbers`. That stopped being true on 2 Sep when
    /// access became module permission ∩ scope ∩ reach, and the picker carried
    /// on being drawn with every box ticked and every change ignored.
    ///
    /// The catalogue below still sends `phone_numbers` on purpose. A deployed
    /// client meets whatever the server has, and during a rollout that server
    /// may be the older one; the client must not resurrect the section on being
    /// handed the key. `grantKinds` no longer lists it, so it cannot.
    testWidgets('phone lines are not offered as a grant, even if sent', (
      tester,
    ) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [_role('r-3', 'Dispatcher', permissions: const [])],
        catalogue: [
          _catalogueModule('customers', 'Customers'),
          _catalogueModule(
            'phone',
            'Phone',
            actions: const [('view', 'View'), ('edit', 'Edit')],
            phoneNumbers: [
              {
                'id': 'num-1',
                'e164': '+12035550123',
                'label': 'Front desk',
                'grantable': true,
              },
            ],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Phone'));
      await tester.pumpAndSettle();

      // Not the heading, not the instruction, not the line itself.
      expect(find.text('Phone lines'), findsNothing);
      expect(find.text('Front desk'), findsNothing);
      expect(find.text('+12035550123'), findsNothing);

      await tester.tap(_tabCheckbox('phone'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      // And nothing is sent for it. Sending `phone_numbers: []` from a screen
      // that drew no picker would revoke rows the editor never showed.
      expect(sent!.containsKey('phone_numbers'), isFalse);
      expect(sent!.containsKey('inboxes'), isFalse);
    });

    /// R7: the tree is already dense and this adds a level to it.
    testWidgets('it fits a 375px screen', (tester) async {
      tester.view.physicalSize = const Size(375, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpRoles(
        tester,
        roles: [
          _role(
            'r-2',
            'Manager',
            permissions: ['email.view'],
            inboxes: ['mb-1'],
          ),
        ],
        catalogue: [
          _catalogueModule(
            'email',
            'Email',
            actions: const [('view', 'View'), ('send', 'Send')],
            inboxes: [
              _inbox(
                'mb-1',
                'scheduling@a-1chimneyandfireplace.com',
                name: 'Scheduling',
              ),
              _inbox('mb-2', 'billing@everdoors.com'),
            ],
          ),
        ],
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Email'));
      await tester.pumpAndSettle();

      // The named one shows both facts: "Scheduling" is not enough to know
      // which mail a role would be reading.
      expect(find.text('Scheduling'), findsOneWidget);
      expect(
        find.text('scheduling@a-1chimneyandfireplace.com'),
        findsOneWidget,
      );

      expect(
        tester.takeException(),
        isNull,
        reason: 'A long address must wrap rather than overflow at 375px.',
      );
    });
  });

  /// Settings carries the editor, and only for somebody who can reach it.
  group('the roles tab in Settings', () {
    testWidgets('appears when /me says the module is reachable', (
      tester,
    ) async {
      _wide(tester);

      await _pumpSettingsShell(tester, rolesReachable: true);

      expect(find.text('Organization'), findsOneWidget);
      // Preferences since UI queue 110 (not Settings under Settings).
      expect(find.text('Preferences'), findsOneWidget);
      // Named by the server, not by this end.
      expect(find.text('Roles & permissions'), findsOneWidget);
    });

    testWidgets('is absent when it is not', (tester) async {
      _wide(tester);

      await _pumpSettingsShell(tester, rolesReachable: false);

      expect(find.text('Roles & permissions'), findsNothing);
      // Organization and Preferences are always both there, so the chip row stays.
      expect(find.text('Organization'), findsOneWidget);
      // Preferences since UI queue 110 (not Settings under Settings).
      expect(find.text('Preferences'), findsOneWidget);
    });
  });

  /// UI/UX permission-requires layouts 2 + 6, chosen 2026-09-09.
  ///
  /// Exactly ONE action in the product declares a requirement, so the case
  /// that matters most is the one where nothing is declared at all.
  testWidgets('an action with no requirement says nothing about one', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [_catalogueModule('customers', 'Customers')],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customers'));
    await tester.pumpAndSettle();

    // The control: the rows are actually drawn, so "no requirement text"
    // is a statement about them rather than about an empty screen.
    expect(find.byKey(const ValueKey('action:customers.view')), findsOneWidget);
    expect(find.textContaining('needs'), findsNothing);
    expect(find.textContaining('Needs'), findsNothing);
  });

  testWidgets('a tab inside another tab sits under it, as the app draws it', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule(
          'marketing',
          'Marketing',
          actions: const [
            ('view', 'Social Media Management'),
            ('compose.view', 'Compose'),
            ('ez_calls.view', 'EZ Calls'),
            ('edit', 'Edit'),
          ],
          tabs: const ['view', 'compose.view', 'ez_calls.view'],
          within: const {
            'compose.view': 'Social Media Management',
            'ez_calls.view': 'Calls',
          },
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Marketing'));
    await tester.pumpAndSettle();

    // Under a tab that is a tick of its own: indented, no extra heading.
    expect(
      find.byKey(const ValueKey('tab-nested:marketing.compose.view')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('tab-group:marketing:Social Media Management')),
      findsNothing,
    );
    // Under a group with no tick (Leads › Calls): its name as a heading.
    expect(
      find.byKey(const ValueKey('tab-group:marketing:Calls')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('tab-nested:marketing.view')),
      findsNothing,
    );
  });

  testWidgets('a tick the module describes says what it lets someone do', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule(
          'tasks',
          'Task Board',
          actions: const [('view', 'View'), ('view_all', 'See every board')],
          descriptions: const {
            'view_all': "Everyone's boards in their picker, grouped by person.",
          },
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Task Board'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('action:tasks.view_all')),
        matching: find.text(
          "Everyone's boards in their picker, grouped by person.",
        ),
      ),
      findsOneWidget,
    );
    // An action the module does not describe has no sentence under it.
    expect(
      find.byKey(const ValueKey('action-description:tasks.view')),
      findsNothing,
    );
  });

  testWidgets('on a desktop the requirement rides the label, before any tick', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule(
          'training',
          'Training',
          actions: const [
            ('view', 'View'),
            ('monitor', 'Monitor training'),
            ('assign', 'Assign a guide'),
          ],
          requires: const {
            'assign': ['training.monitor'],
          },
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Training'));
    await tester.pumpAndSettle();

    // Legible with NOTHING ticked -- the point of layout 2 over a warning.
    expect(find.textContaining('needs Monitor training'), findsOneWidget);
    // Named in the words on the other row, not as a permission key.
    expect(find.textContaining('training.monitor'), findsNothing);
    // Not yet a complaint: nothing is ticked, so nothing is wrong.
    expect(find.textContaining('not ticked'), findsNothing);

    // And it fits on ONE line. A wrapped suffix costs a whole line (measured:
    // 48 -> 56 at the label's own size); bodySmall costs 4pt of line height
    // and no wrap. Guard the wrap, which is the failure a longer label would
    // reintroduce, not the 4pt, which nobody can see on one row in a hundred.
    final plain = tester
        .getSize(find.byKey(const ValueKey('action:training.view')))
        .height;
    final withSuffix = tester
        .getSize(find.byKey(const ValueKey('action:training.assign')))
        .height;
    expect(
      withSuffix,
      lessThan(plain + 8),
      reason:
          'The requirement suffix wrapped onto a second line at this '
          'width. Shorten it or move it under the label.',
    );
  });

  testWidgets('ticking it without its requirement says so, and ticks nothing', (
    tester,
  ) async {
    _wide(tester);

    await _pumpRoles(
      tester,
      roles: [_role('r-2', 'Manager', permissions: const [])],
      catalogue: [
        _catalogueModule(
          'training',
          'Training',
          actions: const [
            ('view', 'View'),
            ('monitor', 'Monitor training'),
            ('assign', 'Assign a guide'),
          ],
          requires: const {
            'assign': ['training.monitor'],
          },
        ),
      ],
    );

    await tester.tap(_firstRole);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Training'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('action:training.assign')));
    await tester.pumpAndSettle();

    expect(find.textContaining('not ticked'), findsOneWidget);

    // The screen NEVER ticks the other box. The server refuses rather than
    // implying, because monitor is the larger disclosure -- a screen that
    // helpfully ticked it would hand out more than the administrator did.
    expect(
      tester
          .widget<CheckboxListTile>(
            find.byKey(const ValueKey('action:training.monitor')),
          )
          .value,
      isFalse,
    );
  });

  testWidgets(
    'on a phone the requirement is under the label, not behind a tap',
    (tester) async {
      tester.view.physicalSize = const Size(400, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Manager', permissions: const [])],
        catalogue: [
          _catalogueModule(
            'training',
            'Training',
            actions: const [
              ('view', 'View'),
              ('monitor', 'Monitor training'),
              ('assign', 'Assign a guide'),
            ],
            requires: const {
              'assign': ['training.monitor'],
            },
          ),
        ],
      );

      await tester.tap(_firstRole);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Training'));
      await tester.pumpAndSettle();

      // Stated at rest, with nothing ticked and nothing tapped.
      expect(find.text('Needs Monitor training'), findsOneWidget);
      // And NOT also as a suffix: two slots measured separately would say it
      // twice, which is what the single LayoutBuilder exists to prevent.
      expect(find.textContaining('·  needs'), findsNothing);
    },
  );

  /// UI queue 111, option 1: the role as one grid, a row per module.
  group('the roles grid', () {
    List<Map<String, dynamic>> catalogue() => [
      _catalogueModule('customers', 'Customers'),
      _catalogueModule('jobs', 'Jobs'),
      _catalogueModule(
        'hr',
        'People',
        actions: const [
          ('view', 'View'),
          ('create', 'Invite'),
          ('edit', 'Edit'),
          ('records', 'Records'),
          ('hours', 'Work hours'),
        ],
        tabs: const ['records', 'hours'],
      ),
    ];

    testWidgets(
      'each row counts what the role holds, with an all/some/none box',
      (tester) async {
        _wide(tester);

        await _pumpRoles(
          tester,
          roles: [
            _role(
              'r-2',
              'Dispatcher',
              permissions: [
                'customers.view',
                'customers.edit',
                'hr.view',
                'hr.create',
                'hr.records',
              ],
              users: 5,
              people: [
                {'id': 'u-7', 'name': 'Dana Reyes'},
                {'id': 'u-8', 'name': 'Pat Lin'},
              ],
            ),
          ],
          catalogue: catalogue(),
        );

        String text(String key) =>
            tester.widget<Text>(find.byKey(ValueKey(key))).data!;

        expect(text('role-counts'), '5 people · 2 modules');

        // Some: view and edit of four.
        expect(_tabState('customers'), isNull);
        expect(text('can-do-text:customers'), '2 of 4 · view, edit');
        expect(text('tabs:customers'), '—', reason: 'Customers has no tabs.');

        // None: not opened, so nothing to count.
        expect(_tabState('jobs'), isFalse);
        expect(text('can-do-text:jobs'), '—');

        // Tabs are counted apart from what can be done.
        expect(text('tabs:hr'), '1 of 2');
        expect(text('can-do-text:hr'), '2 of 3 · view, invite');
        expect(text('invite:hr'), 'Roles within their own');
        expect(text('invite:customers'), '—');

        // Who has it: the names sent, and the rest as a number.
        expect(find.text('DR'), findsOneWidget);
        expect(find.text('Dana Reyes'), findsOneWidget);
        expect(find.text('Pat Lin'), findsOneWidget);
        expect(text('role-people-more'), '+3');
      },
    );

    testWidgets(
      'all of it is a full box, and a missing people list is a count',
      (tester) async {
        _wide(tester);

        await _pumpRoles(
          tester,
          roles: [
            _role(
              'r-2',
              'Dispatcher',
              permissions: [
                'jobs.view',
                'jobs.create',
                'jobs.edit',
                'jobs.delete',
              ],
              users: 4,
            ),
          ],
          catalogue: catalogue(),
        );

        expect(_tabState('jobs'), isTrue);
        expect(
          tester
              .widget<Text>(find.byKey(const ValueKey('can-do-text:jobs')))
              .data,
          'all 4',
        );
        expect(find.text('4 people hold this role.'), findsOneWidget);
        expect(find.byKey(const ValueKey('role-people-more')), findsNothing);
      },
    );

    testWidgets('a row opens in place, and its save is the same request', (
      tester,
    ) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [
          _role('r-2', 'Dispatcher', permissions: ['customers.view']),
        ],
        onPatch: (body) => sent = body,
      );

      expect(find.byKey(const ValueKey('action:customers.edit')), findsNothing);

      await tester.tap(find.text('Customers'));
      await tester.pumpAndSettle();

      // Inside the module's own row, not in a panel somewhere else.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('module:customers')),
          matching: find.byKey(const ValueKey('action:customers.edit')),
        ),
        findsOneWidget,
      );
      expect(find.byType(ExpansionTile), findsNothing);

      await tester.tap(find.byKey(const ValueKey('action:customers.edit')));
      await tester.pumpAndSettle();

      expect(find.text('Unsaved changes.'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      expect(sent, {
        'name': 'Dispatcher',
        'permissions': ['customers.edit', 'customers.view'],
      });
    });

    testWidgets('Rename asks in a side panel and saves the new name', (
      tester,
    ) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [
          _role('r-2', 'Dispatcher', permissions: ['customers.view']),
        ],
        onPatch: (body) => sent = body,
      );

      await tester.tap(find.byKey(const ValueKey('role-rename')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField).last,
        'Brightside dispatch',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Save').last);
      await tester.pumpAndSettle();

      expect(sent!['name'], 'Brightside dispatch');
      expect(sent!['permissions'], ['customers.view']);
    });

    testWidgets('Compare with adds the other role, read-only', (tester) async {
      _wide(tester);

      Map<String, dynamic>? sent;

      await _pumpRoles(
        tester,
        roles: [
          _role('r-2', 'Dispatcher', permissions: ['customers.view']),
          _role(
            'r-3',
            'Technician',
            permissions: [
              'jobs.view',
              'jobs.create',
              'jobs.edit',
              'jobs.delete',
              'customers.view',
            ],
          ),
        ],
        onPatch: (body) => sent = body,
      );

      expect(find.byKey(const ValueKey('compare-heading')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('role-compare')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Technician').last);
      await tester.pumpAndSettle();

      expect(
        tester.widget<Text>(find.byKey(const ValueKey('compare-heading'))).data,
        'Technician',
      );
      expect(_boxState(const ValueKey('compare:jobs')), isTrue);
      expect(_boxState(const ValueKey('compare:customers')), isNull);

      // The page is still Dispatcher's: its own boxes did not move.
      expect(_tabState('jobs'), isFalse);
      expect(_tabState('customers'), isNull);

      // Read-only: the only checkbox on the row is this role's own Open.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('module:jobs')),
          matching: find.byType(Checkbox),
        ),
        findsOneWidget,
      );

      // And a save says nothing about the other role.
      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();
      expect(sent!['name'], 'Dispatcher');
      expect(sent!['permissions'], ['customers.view']);
    });

    testWidgets('on a narrow tab the list opens a role page, with a way back', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(600, 1200);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpRoles(
        tester,
        roles: [
          _role('r-2', 'Dispatcher', permissions: const []),
          _role('r-3', 'Technician', permissions: ['jobs.view', 'jobs.edit']),
        ],
      );

      // The list alone.
      expect(find.byKey(const ValueKey('roles-grid')), findsNothing);
      expect(find.byKey(const ValueKey('role-row:r-3')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('role-row:r-3')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('role-row:r-3')), findsNothing);
      expect(
        tester.widget<Text>(find.byKey(const ValueKey('role-title'))).data,
        'Technician',
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('module-summary:jobs')))
            .data,
        '2 of 4 ›',
      );
      expect(
        tester
            .widget<Text>(
              find.byKey(const ValueKey('module-summary:customers')),
            )
            .data,
        'off ›',
      );

      // A row opens in place.
      await tester.tap(find.text('Jobs'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('action:jobs.edit')), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.tap(find.byKey(const ValueKey('role-back')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('role-row:r-2')), findsOneWidget);
      expect(find.byKey(const ValueKey('roles-grid')), findsNothing);
    });

    testWidgets('Find a permission narrows the rows and opens the match', (
      tester,
    ) async {
      _wide(tester);

      await _pumpRoles(
        tester,
        roles: [_role('r-2', 'Dispatcher', permissions: const [])],
        catalogue: catalogue(),
      );

      await tester.enterText(
        find.byKey(const ValueKey('role-search')),
        'work hours',
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('module:customers')), findsNothing);
      expect(find.byKey(const ValueKey('module:hr')), findsOneWidget);
      expect(find.byKey(const ValueKey('action:hr.hours')), findsOneWidget);
      // Only the row that matched, not the module's whole list.
      expect(find.byKey(const ValueKey('action:hr.edit')), findsNothing);
    });
  });
}

// ── harness ────────────────────────────────────────────────────────

void _wide(WidgetTester tester) {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

/// The module row's own checkbox, as opposed to the action checkboxes revealed
/// underneath it. The header is built before the children, so it comes first.
Finder _tabCheckbox(String moduleKey) => find
    .descendant(
      of: find.byKey(ValueKey('module:$moduleKey')),
      matching: find.byType(Checkbox),
    )
    .first;

/// false = hidden · null = visible with some actions withheld · true = all of
/// them. Read off the "What they can do" box in the module's grid row.
bool? _tabState(String moduleKey) => _boxState(ValueKey('can-do:$moduleKey'));

bool? _boxState(Key key) {
  final icon = (find.byKey(key).evaluate().single.widget as Icon).icon;
  if (icon == Icons.check_box) return true;
  if (icon == Icons.indeterminate_check_box) return null;
  expect(icon, Icons.check_box_outline_blank);
  return false;
}

/// The first role in the list. On a desktop it is already open beside the
/// list; on a phone the list is all there is until one is tapped.
final Finder _firstRole = find
    .byWidgetPredicate(
      (w) =>
          w.key is ValueKey<String> &&
          (w.key as ValueKey<String>).value.startsWith('role-row:'),
    )
    .first;

Future<void> _pumpRoles(
  WidgetTester tester, {
  required List<Map<String, dynamic>> roles,
  List<Map<String, dynamic>>? catalogue,
  void Function(Map<String, dynamic> body)? onPatch,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.url.path.endsWith('/roles/catalogue')) {
        // `modules` at the top level, with no `data` around it — the shape
        // RolesController@catalogue actually answers with.
        return _json({
          'modules':
              catalogue ??
              [
                _catalogueModule('customers', 'Customers'),
                _catalogueModule('jobs', 'Jobs'),
              ],
        });
      }

      if (request.method == 'PATCH') {
        onPatch?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': roles.first});
      }

      return _json({'data': roles});
    }),
  );

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
        body: RolesScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpSettingsShell(
  WidgetTester tester, {
  required bool rolesReachable,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'modules': [
              {
                'key': 'roles',
                'label': 'Roles & permissions',
                'available': true,
                'reachable': rolesReachable,
              },
            ],
          },
        });
      }

      return _json({'data': const <dynamic>[]});
    }),
  );

  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SettingsShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// One row of RolesController@index. `editable` is deliberately NOT sent — the
/// server says `is_administrator`, and the client works the rest out from that
/// and from the policy's own two answers.
Map<String, dynamic> _role(
  String id,
  String name, {
  required List<String> permissions,
  List<String> inboxes = const [],
  bool editable = true,
  int users = 1,
  int ownInboxes = 0,
  List<Map<String, String>>? people,
}) => {
  'people_with_own_inboxes': ownInboxes,
  'id': id,
  'name': name,
  'is_administrator': !editable,
  'user_count': users,
  'permissions': permissions,
  // Mailbox ids, not addresses. What the row carries is what a save sends
  // back; the words beside them come from the catalogue.
  'inboxes': inboxes,
  'may_edit': editable,
  'may_delete': editable,
  'people': ?people,
};

/// One of the organization's shared inboxes, as `/roles/catalogue` sends it
/// nested under the module that owns it.
Map<String, dynamic> _inbox(
  String id,
  String address, {
  String? name,
  bool grantable = true,
}) => {'id': id, 'address': address, 'label': name, 'grantable': grantable};

/// One module in the grantable tree. The default action set is the CRUD one the
/// server's registry declares, spelled out here rather than assumed anywhere in
/// the client.
Map<String, dynamic> _catalogueModule(
  String key,
  String label, {
  bool available = true,
  String? reason,
  List<String> ungrantable = const [],
  List<(String, String)> actions = const [
    ('view', 'View'),
    ('create', 'Create'),
    ('edit', 'Edit'),
    ('delete', 'Delete'),
  ],

  /// Null — the default — means the key is ABSENT from the answer, which is how
  /// the server says a module has nothing to do with mail. An empty list is a
  /// different statement: it takes inboxes and this organization has connected
  /// none.
  List<Map<String, dynamic>>? inboxes,
  List<Map<String, dynamic>>? inspections,
  List<Map<String, dynamic>>? phoneNumbers,
  String groupKey = 'tab',
  String groupLabel = '',
  String? groupHint,
  String? parent,
  Map<String, List<String>> requires = const {},
  Map<String, String> descriptions = const {},
  List<String> tabs = const [],
  Map<String, String> within = const {},
}) => {
  'key': key,
  'label': label,
  'available': available,
  'unavailable_reason': reason,
  'parent': parent,
  'group': {
    'key': groupKey,
    'label': groupLabel.isEmpty ? label : groupLabel,
    'hint': groupHint,
  },
  'actions': [
    for (final (action, actionLabel) in actions)
      {
        'key': '$key.$action',
        'action': action,
        'label': actionLabel,
        'grantable': !ungrantable.contains(action),
        // The server sends this on EVERY action; almost always empty.
        'requires': requires[action] ?? const <String>[],
        'description': ?descriptions[action],
        if (tabs.contains(action)) 'tab': true,
        'within': ?within[action],
      },
  ],
  'inboxes': ?inboxes,
  'inspections': ?inspections,
  'phone_numbers': ?phoneNumbers,
};

/// An administrator, holding the whole catalogue as a LIST rather than a
/// wildcard — the client must never be able to tell which role is the
/// administrator one from what it is sent.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': [
    'roles.view',
    'roles.create',
    'roles.edit',
    'roles.delete',
    'customers.view',
    'customers.create',
    'customers.edit',
    'customers.delete',
    'jobs.view',
    'jobs.create',
    'jobs.edit',
    'jobs.delete',
    'organizations.organization.view',
    'organizations.settings.view',
  ],
  'modules': [
    {
      'key': 'roles',
      'label': 'Roles & permissions',
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
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
