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

    await tester.tap(find.text('Edit'));
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

    await tester.tap(find.text('Edit'));
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
      roles: [_role('r-2', 'Manager', permissions: ['customers.view'])],
      onPatch: (body) => sent = body,
    );

    await tester.tap(find.text('Edit'));
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
    expect(find.text('Administrator'), findsOneWidget);
    expect(find.text('Built in'), findsOneWidget);

    await tester.tap(find.text('View'));
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
    expect(
      tester.widget<TextField>(find.byType(TextField)).enabled,
      isFalse,
    );
    expect(
      find.widgetWithText(FilledButton, 'Save'),
      findsNothing,
      reason: 'A save button that cannot save is a control that lies.',
    );

    expect(patched, isFalse);
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
          reason: 'Jobs is paused for your organization while we fix an '
              'issue with scheduling.',
        ),
      ],
    );

    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    expect(find.text('Jobs'), findsOneWidget);
    expect(
      find.textContaining('paused for your organization'),
      findsOneWidget,
    );
    expect(tester.widget<Checkbox>(_tabCheckbox('jobs')).onChanged, isNull);

    // The one beside it is still live, so "greyed" is about that module rather
    // than about the whole panel being read-only.
    expect(
      tester.widget<Checkbox>(_tabCheckbox('customers')).onChanged,
      isNotNull,
    );
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

    await tester.tap(find.text('Edit'));
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

    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    // A module this build has never heard of, with actions it has never heard
    // of, rendered from the answer alone.
    expect(find.text('Work orders'), findsOneWidget);
    expect(find.text('0 of 2'), findsOneWidget);

    await tester.tap(find.text('Work orders'));
    await tester.pumpAndSettle();

    expect(find.text('See them'), findsOneWidget);
    expect(find.text('Approve one'), findsOneWidget);
  });

  /// Settings carries the editor, and only for somebody who can reach it.
  group('the roles tab in Settings', () {
    testWidgets('appears when /me says the module is reachable', (
      tester,
    ) async {
      _wide(tester);

      await _pumpSettingsShell(tester, rolesReachable: true);

      // Named by the server, not by this end.
      expect(find.text('Roles & permissions'), findsOneWidget);
    });

    testWidgets('is absent when it is not', (tester) async {
      _wide(tester);

      await _pumpSettingsShell(tester, rolesReachable: false);

      expect(find.text('Roles & permissions'), findsNothing);
      // And with one tab left there is no chip row at all — a lone chip reads
      // as a control that has lost its siblings.
      expect(find.text('Settings'), findsNothing);
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
/// them.
bool? _tabState(String moduleKey) => (find
        .descendant(
          of: find.byKey(ValueKey('module:$moduleKey')),
          matching: find.byType(Checkbox),
        )
        .evaluate()
        .first
        .widget
    as Checkbox).value;

Future<void> _pumpRoles(
  WidgetTester tester, {
  required List<Map<String, dynamic>> roles,
  List<Map<String, dynamic>>? catalogue,
  void Function(Map<String, dynamic> body)? onPatch,
}) async {
  final api = ApiClient(client: MockClient((request) async {
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
  }));

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
      home: Scaffold(body: RolesScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpSettingsShell(
  WidgetTester tester, {
  required bool rolesReachable,
}) async {
  final api = ApiClient(client: MockClient((request) async {
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
  }));

  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SettingsShell(api: api, session: session)),
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
  bool editable = true,
  int users = 1,
}) => {
  'id': id,
  'name': name,
  'is_administrator': !editable,
  'user_count': users,
  'permissions': permissions,
  'may_edit': editable,
  'may_delete': editable,
};

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
}) => {
  'key': key,
  'label': label,
  'available': available,
  'unavailable_reason': reason,
  'actions': [
    for (final (action, actionLabel) in actions)
      {
        'key': '$key.$action',
        'action': action,
        'label': actionLabel,
        'grantable': !ungrantable.contains(action),
      },
  ],
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
