import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The sidebar, built from what the server says this person can reach (§4, §13).
///
/// It used to be five destinations compiled into Dart, shown to everybody. The
/// three failures that made that untenable, one test each:
///
///   - a role that reaches one module saw five tabs, four of which answered 403
///     the moment they were tapped;
///   - the platform kill switch (§13) was built to be used mid-incident and left
///     the tab drawn with every action behind it failing;
///   - the shell tracked its selection by POSITION in that fixed list. The list
///     is now assembled per person and can change length while somebody is
///     looking at it, which under an index moves them to a different screen or
///     throws on the next frame.
void main() {
  testWidgets('the sidebar draws exactly the modules the server says are '
      'reachable', (tester) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('customers', 'Customers'),
      // Held by the organization's plan, but not by this person's role.
      _module('jobs', 'Jobs', reachable: false),
    ]);

    expect(_inSidebar('Dashboard'), findsOneWidget);
    expect(_inSidebar('Customers'), findsOneWidget);
    expect(
      _inSidebar('Jobs'),
      findsNothing,
      reason:
          'A tab this role cannot open is a tab that 403s when tapped. The '
          'sidebar renders reach, not the list of things that exist.',
    );

    // The pinned group is not module-driven and stays where it is.
    expect(_inSidebar('Organization'), findsOneWidget);
    expect(_inSidebar('Settings'), findsOneWidget);
  });

  /// §13. A module the PLATFORM has switched off must not be a destination —
  /// that switch exists to be thrown mid-incident, and leaving the tab drawn is
  /// the state it was built to prevent.
  testWidgets('a module the platform has switched off is not a destination', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('customers', 'Customers', available: false, reachable: false),
    ]);

    expect(_inSidebar('Dashboard'), findsOneWidget);
    expect(_inSidebar('Customers'), findsNothing);
  });

  /// The label is the server's word, never the key dressed up.
  testWidgets('a module is called what the server calls it', (tester) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      // The tenant renames things (§8), and the registry's own words are not
      // recoverable from the key: `hr` is "Human Resources".
      //
      // This used to be `data` / "Import & export", which made the same point
      // and then stopped compiling as a test of anything: that module was
      // removed when the tab was, so the sidebar would skip it for want of a
      // screen and this would have passed for the wrong reason. A key the
      // client CAN draw is what makes the label the thing under test.
      _module('hr', 'Human Resources'),
    ]);

    expect(_inSidebar('Human Resources'), findsOneWidget);
    expect(_inSidebar('Hr'), findsNothing);
  });

  /// A module this build has no screen for is skipped rather than drawn.
  ///
  /// That is a server newer than the client: the module exists, the person may
  /// reach it, and there is nothing here to open. A tab leading nowhere is
  /// worse than its absence.
  testWidgets('a reachable module with no screen in this build is skipped', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('invoices', 'Invoices'),
    ]);

    expect(_inSidebar('Dashboard'), findsOneWidget);
    expect(_inSidebar('Invoices'), findsNothing);
  });

  /// The failure the old `int _index` could not survive.
  ///
  /// Somebody is standing on Jobs when the platform switches Jobs off, or their
  /// role stops reaching it. Under an index into a runtime list, the selection
  /// silently becomes whatever moved into that slot — and if the module was
  /// last, the index points past the end and the next frame throws.
  testWidgets('a module disappearing under somebody does not strand them', (
    tester,
  ) async {
    _wide(tester);

    var modules = [
      _module('customers', 'Customers'),
      _module('jobs', 'Jobs'),
    ];

    final api = ApiClient(client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me(modules)});
      }

      return _json({'message': 'Nothing stubbed for this screen.'}, 500);
    }));

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await _pump(tester, api, session);

    await tester.tap(_inSidebar('Jobs'));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<NavigationRail>(find.byType(NavigationRail).first)
          .selectedIndex,
      1,
      reason: 'The tap has to have landed, or the rest of this proves nothing.',
    );

    // Jobs goes away underneath them — and it was the last one, which is the
    // case an index cannot survive at all.
    modules = [_module('customers', 'Customers')];
    await tester.runAsync(() => session.refresh());
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(_inSidebar('Jobs'), findsNothing);
    expect(_inSidebar('Customers'), findsOneWidget);

    // And landed on the one that is left, rather than on nothing.
    expect(
      tester
          .widget<NavigationRail>(find.byType(NavigationRail).first)
          .selectedIndex,
      0,
    );
  });

  /// Reach can be nothing at all. The shell has to render something, and the
  /// only screen that is theirs by definition is their own account.
  testWidgets('somebody who reaches no module lands on their own account', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('customers', 'Customers', reachable: false),
    ]);

    expect(tester.takeException(), isNull);
    expect(_inSidebar('Customers'), findsNothing);

    // The pinned group survives — it is not module-driven — so there is still a
    // way out of the screen they landed on.
    expect(_inSidebar('Settings'), findsOneWidget);
  });
}

// ── harness ────────────────────────────────────────────────────────

void _wide(WidgetTester tester) {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

/// Only the sidebar. A screen in the body may well print the same word — the
/// dashboard says "Customers" all over itself — and the question here is what
/// the navigation offers.
Finder _inSidebar(String label) => find.descendant(
  of: find.byType(NavigationSidebar),
  matching: find.text(label),
);

Future<void> _pumpShell(
  WidgetTester tester, {
  required List<Map<String, dynamic>> modules,
}) async {
  final api = ApiClient(client: MockClient((request) async {
    if (request.url.path.endsWith('/me')) return _json({'data': _me(modules)});

    // Everything else is a screen fetching its own data. These tests are about
    // which destinations exist, so the bodies are left to render their error
    // state rather than being stubbed one by one.
    return _json({'message': 'Nothing stubbed for this screen.'}, 500);
  }));

  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await _pump(tester, api, session);
}

Future<void> _pump(
  WidgetTester tester,
  ApiClient api,
  Session session,
) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      // Rebuilt on every session change, exactly as the real app does it —
      // `_EzBiziAppState` listens to the session and calls setState. The shell
      // itself does not listen, so without this a `/me` that comes back with a
      // different set of modules would never reach the screen.
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
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

/// One entry in `/me`'s `modules` block.
Map<String, dynamic> _module(
  String key,
  String label, {
  bool available = true,
  bool reachable = true,
}) => {
  'key': key,
  'label': label,
  'available': available,
  'reachable': reachable,
};

Map<String, dynamic> _me(List<Map<String, dynamic>> modules) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': modules,
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
