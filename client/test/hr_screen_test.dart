import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr_screen.dart';
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
/// The first is that a person who invites somebody is told the invitation was
/// not sent. Nothing on this platform can send mail, so the link IS the
/// delivery — and a screen that shows a link without saying so leaves somebody
/// waiting for an acceptance to a message that was never going to arrive. That
/// sentence is tested like a feature because it is one.
///
/// The second is that the places somebody may be assigned come from the server.
/// §4 bounds an inviter to their own reach, the server already computes it, and
/// `/me` happens to carry a franchise list that would make a plausible-looking
/// substitute. The fixture below makes the two lists deliberately disagree, so
/// a Dart implementation of an access-control rule cannot pass this file.
///
/// The third is that the three unbuilt tabs say what they are waiting for. A
/// tab that vanishes reads as broken and a blank one reads as broken; this
/// codebase's answer is to draw it and mark it, the same as an automation
/// action the engine cannot run.
void main() {
  testWidgets('the five tabs are drawn, in the owner’s order, and switch', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(_chips(tester), [
      'Dashboard',
      'Onboarding',
      'Records',
      'Invitations',
      'Contracts',
    ]);

    // It opens on Records, not on tab zero: the first tab in that order that
    // does something. Landing on a placeholder says the module is broken.
    expect(find.text('Not built yet'), findsNothing);
    expect(find.text('Jose Silva'), findsOneWidget);

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Jose Silva'), findsNothing);
    expect(find.text('bea@example.com'), findsOneWidget);

    await _tap(tester, _tab('Records'));

    expect(find.text('Jose Silva'), findsOneWidget);
  });

  testWidgets('an unbuilt tab is drawn, marked, and says what it waits for', (
    tester,
  ) async {
    await _pump(tester, _handler());

    for (final tab in ['Dashboard', 'Onboarding', 'Contracts']) {
      await _tap(tester, _tab(tab));

      expect(
        find.text('Not built yet'),
        findsOneWidget,
        reason: '$tab was drawn with nothing to say it is unfinished.',
      );
    }

    // And each one says something specific. "Coming soon" tells a reader
    // nothing they had not already worked out from the empty page.
    await _tap(tester, _tab('Dashboard'));
    expect(find.textContaining('headcount by place'), findsOneWidget);

    await _tap(tester, _tab('Onboarding'));
    expect(find.textContaining('contract designer'), findsOneWidget);

    await _tap(tester, _tab('Contracts'));
    expect(find.textContaining('outside every backup'), findsOneWidget);
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
      reason: 'An invitation carries a role and a place; an address is not one.',
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

    // The load-bearing sentence.
    expect(
      find.textContaining('cannot send mail yet'),
      findsOneWidget,
      reason:
          'Without this somebody invites a person and waits for an acceptance '
          'to a message that was never sent.',
    );
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
    expect(scopes.items!.map((i) => i.value), [
      'organization:org-1',
      'company:co-1',
      'franchise:fr-9',
      'franchise:fr-4',
    ]);

    expect(scopes.items!.map((i) => (i.child as Text).data), [
      'Everdoors',
      'Serenisima',
      'Serenisima › Connecticut',
      // §4: an assignment to a deactivated scope grants nothing, so it is drawn
      // and refused rather than offered as a silent mistake.
      'Serenisima › Rhode Island — deactivated',
    ]);
    expect(
      scopes.items!.where((i) => !i.enabled).map((i) => i.value),
      ['franchise:fr-4'],
    );

    // `/me` knows about Texas and does not know about Connecticut. Neither
    // fact may show through: the question is what this person may ASSIGN, and
    // that is not the same question as what they can see.
    expect(
      scopes.items!.map((i) => (i.child as Text).data).join(' '),
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

    expect(roles.items!.map((i) => (i.child as Text).data), [
      'Administrator — not yours to give',
      'Technician',
    ]);
    expect(roles.items!.where((i) => !i.enabled).map((i) => i.value), [
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
    expect(find.textContaining('previous link has stopped working'), findsOneWidget);
    expect(find.textContaining('cannot send mail yet'), findsOneWidget);
  });

  testWidgets('revoking asks first, and a refusal is not a silent success', (
    tester,
  ) async {
    await _pump(tester, _handler(revokeStatus: 403));

    await _tap(tester, _tab('Invitations'));
    await _tap(tester, find.text('Revoke'));

    expect(find.textContaining('The link stops working'), findsOneWidget);

    await _tap(tester, find.widgetWithText(FilledButton, 'Revoke'));

    expect(find.text('Only an administrator may revoke an invitation.'),
        findsOneWidget);
    expect(
      find.text('bea@example.com'),
      findsOneWidget,
      reason: 'The row vanished as though the revoke had worked.',
    );
  });

  /// An accepted invitation is a person now. Resending would mint a token for
  /// an account that already exists.
  testWidgets('a completed invitation offers neither resend nor revoke', (
    tester,
  ) async {
    await _pump(tester, _handler(invitations: _completedOnly));

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Completed'), findsOneWidget);
    expect(find.text('Resend'), findsNothing);
    expect(find.text('Revoke'), findsNothing);
  });

  testWidgets('somebody with no role is marked as such on the row', (
    tester,
  ) async {
    await _pump(tester, _handler());

    // The state this whole module exists to close: roles were definable and no
    // endpoint wrote users.role_id, so an organization stayed one person.
    expect(find.text('No role'), findsOneWidget);
    expect(find.text('Administrator'), findsOneWidget);

    // Both places somebody is assigned — §4 allows several, and showing only
    // the first would hide half of their reach.
    expect(
      find.text('bea@example.com · Connecticut · Texas'),
      findsOneWidget,
    );
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

    await _tap(tester, find.byType(PopupMenuButton<String>).last);
    await _tap(tester, find.text('Change their role'));
    await _tap(tester, find.text('Technician'));

    expect(path, '/api/v1/hr/people/u-2');
    expect(patched, {'role_id': 'role-2'});
  });

  testWidgets('deactivating asks first, and says the record survives it', (
    tester,
  ) async {
    Map<String, dynamic>? patched;

    await _pump(tester, _handler(onPatchPerson: (_, body) => patched = body));

    await _tap(tester, find.byType(PopupMenuButton<String>).last);
    await _tap(tester, find.text('Deactivate'));

    // The owner's rule, stated where the decision is made: every employee is a
    // user account and the record survives deactivation.
    expect(find.textContaining('The record stays exactly where it is'),
        findsOneWidget);
    expect(patched, isNull);

    await _tap(tester, find.widgetWithText(FilledButton, 'Deactivate'));

    expect(patched, {'is_active': false});
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

    expect(find.textContaining('roles are read-only here'), findsOneWidget);

    await _tap(tester, find.byType(PopupMenuButton<String>).last);

    expect(
      tester
          .widget<PopupMenuItem<String>>(
            find.widgetWithText(PopupMenuItem<String>, 'Change their role'),
          )
          .enabled,
      isFalse,
    );

    // And the rest of the tab still works. Not being able to hand out a role
    // does not make the list of people unreadable, or switching somebody off
    // something they may no longer do.
    expect(find.text('Deactivate'), findsOneWidget);
  });

  /// "Abandoned" is one word for two different things — nobody came, and we
  /// changed our mind — and the two want opposite responses.
  testWidgets('a withdrawn invitation is not confused with one that ran out', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, _tab('Invitations'));

    expect(find.text('Abandoned'), findsOneWidget);
    expect(find.textContaining('withdrawn'), findsOneWidget);
    expect(find.textContaining('expired'), findsNothing);
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
      home: Scaffold(body: HrScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// The tab strip's own chip. Every tab label is also a heading on the page
/// behind it, so an unscoped `find.text` matches two things once it is open.
Finder _tab(String label) => find.widgetWithText(ChoiceChip, label);

List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(find.byType(ChoiceChip))
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
  await _tap(
    tester,
    find.widgetWithText(DropdownButtonFormField<String>, label),
  );
  await _tap(tester, find.text(option).last);
}

/// The button inside a labelled form field, which is where the options live —
/// `DropdownButtonFormField` is a FormField and does not expose them.
DropdownButton<String> _dropdown(WidgetTester tester, String label) =>
    tester.widget<DropdownButton<String>>(
      find.descendant(
        of: find.widgetWithText(DropdownButtonFormField<String>, label),
        matching: find.byType(DropdownButton<String>),
      ),
    );

FilledButton _inviteButton(WidgetTester tester) => tester.widget<FilledButton>(
  find.widgetWithText(FilledButton, 'Create the invitation'),
);

/// The header's own button, which is dead whenever there is no complete
/// invitation this person could send.
FilledButton _inviteSomebody(WidgetTester tester) => tester.widget<FilledButton>(
  find.widgetWithText(FilledButton, 'Invite somebody'),
);

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onInvite,
  void Function(String path)? onResend,
  void Function(String path, Map<String, dynamic> body)? onPatchPerson,
  List<Map<String, Object?>> invitations = _invitations,
  Map<String, Object?> scopeOptions = _scopeOptions,
  int scopeOptionsStatus = 200,
  int revokeStatus = 200,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/hr/people/scope-options')) {
      return scopeOptionsStatus == 200
          ? _json({'data': scopeOptions})
          : _json(
              {'message': 'You may not invite people.'},
              scopeOptionsStatus,
            );
    }

    if (path.endsWith('/hr/people')) {
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

    if (path.contains('/hr/people/') && request.method == 'PATCH') {
      onPatchPerson?.call(
        path,
        jsonDecode(request.body) as Map<String, dynamic>,
      );
      return _json({'data': _people.first});
    }

    if (path.endsWith('/resend')) {
      onResend?.call(path);
      return _json({'data': _resent});
    }

    if (path.endsWith('/hr/invitations')) {
      if (request.method == 'POST') {
        onInvite?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': _created}, 201);
      }

      return _json({
        'data': invitations,
        'meta': {
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
    {'id': 'fr-9', 'name': 'Connecticut', 'company_id': 'co-1', 'is_active': true},
    // Switched off. §4: an assignment to it would grant nothing at all.
    {'id': 'fr-4', 'name': 'Rhode Island', 'company_id': 'co-1', 'is_active': false},
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
  // Abandoned is one word for two different things, and the row has to say
  // which — this one was withdrawn rather than left to run out.
  {
    'id': 'i-9',
    'email': 'kit@example.com',
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
    'revoked_at': '2026-08-03T09:00:00+00:00',
  },
];

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

/// The create response — the one moment the raw token exists.
const _created = {
  'id': 'i-3',
  'email': 'nadia@example.com',
  'status': 'sent',
  'expires_at': '2026-08-18T09:00:00+00:00',
  'accept_url': 'https://app.ezbizi.com/invite?token=raw-token-1',
};

/// A resend answers the same shape with a NEW token; the old one is dead.
const _resent = {
  'id': 'i-1',
  'email': 'bea@example.com',
  'status': 'sent',
  'expires_at': '2026-08-25T09:00:00+00:00',
  'accept_url': 'https://app.ezbizi.com/invite?token=raw-token-2',
};

const _people = [
  {
    'id': 'u-1',
    'name': 'Jose Silva',
    'first_name': 'Jose',
    'last_name': 'Silva',
    'email': 'jose@example.com',
    'is_active': true,
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
  'permissions': ['hr.view'],
  'modules': [
    {'key': 'hr', 'label': 'Human Resources', 'available': true, 'reachable': true},
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
