import 'dart:convert';

import 'package:ezbizi_client/core/address.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/screens/marketing_screen.dart';
import 'package:ezbizi_client/screens/profile_screen.dart';
import 'package:ezbizi_client/screens/settings_shell.dart';
import 'package:ezbizi_client/widgets/module_page_header.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
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
    // Organization lives inside Settings, not as a neighbour.
    expect(_inSidebar('Organization'), findsNothing);
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

  /// THE RULE, and the reason it is enforced here rather than written down.
  ///
  /// Sixteen screens rendered the shared tab row and exactly one of them
  /// printed the module's name. Asking sixteen screens to each remember a
  /// header is how you get fifteen that forget, so the shell draws it — and
  /// this is the test that fails if a future change moves that job back into
  /// the screens.
  testWidgets('a module opens under its own name, drawn by the shell', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [_module('hr', 'Human Resources')]);

    expect(
      find.descendant(
        of: find.byType(ModulePageHeader),
        matching: find.text('Human Resources'),
      ),
      findsOneWidget,
      reason:
          'The open module must say its own name at the top of the page. '
          'Fifteen of sixteen screens did not, so nothing on the page said '
          'where you were — and on a phone the sidebar is a Drawer, so '
          'nothing on screen said it at all.',
    );
  });

  /// The title is the server's word, never a literal in a screen.
  ///
  /// An organization may rename any module (§8). A name hardcoded in a screen
  /// would go stale the moment they did — silently, because it would still
  /// look like a perfectly good title.
  testWidgets('the page title is the name the server chose, not the one this '
      'build knows', (tester) async {
    _wide(tester);

    // Nothing in this build spells `hr` this way.
    await _pumpShell(tester, modules: [_module('hr', 'Our People')]);

    expect(
      find.descendant(
        of: find.byType(ModulePageHeader),
        matching: find.text('Our People'),
      ),
      findsOneWidget,
    );
    expect(
      find.text('Human Resources'),
      findsNothing,
      reason:
          'A hardcoded title would still be showing the name this build '
          'happens to know, in both the rail and the header.',
    );
  });

  /// A module says its name ONCE.
  ///
  /// The shell began drawing every module's name at the top of the page, and
  /// four screens were already drawing it themselves through `PanelHeader` —
  /// so Customers, Jobs, Fleet and Automations each printed their own name
  /// twice, twenty pixels apart. Eleven more panes repeated their own tab's
  /// label the same way.
  ///
  /// The audit that preceded the header missed all fifteen: it grepped the
  /// SCREENS for heading styles, and `PanelHeader` lives in `widgets/` and uses
  /// a style none of those screens mention. This test is the check that grep
  /// should have been — it renders the thing and counts.
  testWidgets('a module prints its own name once, not twice', (tester) async {
    _wide(tester);

    for (final module in const [
      ('customers', 'Customers'),
      ('jobs', 'Jobs'),
      ('automations', 'Automations'),
    ]) {
      await _pumpShell(tester, modules: [_module(module.$1, module.$2)]);

      // The sidebar says it too, legitimately — that is the row you clicked.
      // What must not happen is the PAGE saying it twice.
      final onPage =
          find.text(module.$2).evaluate().length -
          _inSidebar(module.$2).evaluate().length;

      expect(
        onPage,
        1,
        reason:
            '"${module.$2}" appears $onPage times on the page. The shell draws '
            'the module name; a screen that also draws it prints it twice.',
      );
    }
  });

  /// UI queue 99 (Jose, 2026-10-15): Your account gets the header every
  /// module has, so it has a walkthrough and a how-to video too. The screen
  /// no longer draws its own title, so the name is still printed once.
  testWidgets('Your account has the module header, titled once', (
    tester,
  ) async {
    _wide(tester);

    // Reaching no module at all lands on their own account.
    await _pumpShell(tester, modules: const []);

    expect(find.byType(ProfileScreen), findsOneWidget);
    expect(find.byType(ModulePageHeader), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(ModulePageHeader),
        matching: find.text('Your account'),
      ),
      findsOneWidget,
    );
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

  testWidgets('access changed elsewhere reloads the open screen; an unchanged '
      '/me does not', (tester) async {
    _wide(tester);

    var version = 'v1';
    var screenFetches = 0;
    final api = ApiClient(client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me([_module('customers', 'Customers')]),
            'access_version': version,
          },
        });
      }
      screenFetches++;

      return _json({'message': 'Nothing stubbed for this screen.'}, 500);
    }));

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await _pump(tester, api, session);
    final first = screenFetches;
    expect(first, greaterThan(0), reason: 'The screen has to fetch at all.');

    // The minute poll finds nothing new: the screen keeps its state.
    await tester.runAsync(() => session.refresh());
    await tester.pumpAndSettle();
    expect(screenFetches, first);

    // An inbox granted, a role edited: the page remounts and fetches again,
    // with nobody leaving the tab.
    version = 'v2';
    await tester.runAsync(() => session.refresh());
    await tester.pumpAndSettle();
    expect(screenFetches, greaterThan(first));
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

  /// Permanence. The selection used to live only in the widget, so a reload
  /// of Marketing always opened Dashboard. nginx already serves index.html
  /// for `/marketing`; the shell has to read that path.
  testWidgets('opening /marketing lands on Marketing, not Dashboard', (
    tester,
  ) async {
    _wide(tester);

    final address = Address(path: '/marketing');
    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('marketing', 'Marketing'),
      ],
      address: address,
    );

    expect(find.byType(MarketingScreen), findsOneWidget);
    expect(find.byType(DashboardScreen), findsNothing);
    expect(address.path, '/marketing');
  });

  testWidgets('tapping Marketing writes /marketing so a reload can find it', (
    tester,
  ) async {
    _wide(tester);

    final address = Address(path: '/');
    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('marketing', 'Marketing'),
      ],
      address: address,
    );

    expect(find.byType(DashboardScreen), findsOneWidget);
    expect(address.path, '/dashboard');

    await tester.tap(_inSidebar('Marketing'));
    await tester.pumpAndSettle();

    expect(find.byType(MarketingScreen), findsOneWidget);
    expect(address.path, '/marketing');
  });

  testWidgets('/organization still opens Settings', (tester) async {
    _wide(tester);

    final address = Address(path: '/organization');
    await _pumpShell(
      tester,
      modules: [_module('dashboard', 'Dashboard')],
      address: address,
    );

    expect(find.byType(SettingsShell), findsOneWidget);
    expect(address.path, '/settings');
    expect(find.text('Organization'), findsOneWidget);
  });

  /// An empty state's "Open Settings" (UI review, 2026-10-08). A child module
  /// opens inside its parent, as its typed URL does.
  testWidgets('a screen can send someone to another destination', (
    tester,
  ) async {
    _wide(tester);

    final address = Address(path: '/dashboard');
    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('marketing', 'Marketing'),
        _module('web_management', 'Web Management', parent: 'marketing'),
      ],
      address: address,
    );
    final open = OpenDestination.of(
      tester.element(find.byType(DashboardScreen)),
    )!;

    open('settings');
    await tester.pumpAndSettle();
    expect(find.byType(SettingsShell), findsOneWidget);
    expect(address.path, '/settings');

    open('web_management');
    await tester.pumpAndSettle();
    expect(find.byType(MarketingScreen), findsOneWidget);
    expect(address.path, '/marketing');
  });

  testWidgets('Settings and Your account have paths of their own', (
    tester,
  ) async {
    _wide(tester);

    final address = Address(path: '/settings');
    await _pumpShell(
      tester,
      modules: [_module('dashboard', 'Dashboard')],
      address: address,
    );

    expect(find.byType(SettingsShell), findsOneWidget);
    expect(address.path, '/settings');

    await tester.tap(
      find.byTooltip('Your account — Jose Silva (jose@example.com)'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Your account').last);
    await tester.pumpAndSettle();

    expect(find.byType(ProfileScreen), findsOneWidget);
    expect(address.path, '/account');
  });

  testWidgets('a path this role cannot reach falls back rather than 403ing', (
    tester,
  ) async {
    _wide(tester);

    final address = Address(path: '/jobs');
    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('jobs', 'Jobs', reachable: false),
      ],
      address: address,
    );

    expect(find.byType(DashboardScreen), findsOneWidget);
    expect(address.path, '/dashboard');
  });

  testWidgets('the back button restores the previous place', (tester) async {
    _wide(tester);

    final address = Address(path: '/dashboard');
    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('marketing', 'Marketing'),
      ],
      address: address,
    );

    await tester.tap(_inSidebar('Marketing'));
    await tester.pumpAndSettle();
    expect(find.byType(MarketingScreen), findsOneWidget);

    address.popped('/dashboard');
    await tester.pumpAndSettle();

    expect(find.byType(DashboardScreen), findsOneWidget);
    expect(find.byType(MarketingScreen), findsNothing);
  });

  // ── modules drawn inside another module ──────────────────────────

  /// The owner moved Leads, Reviews and Business Profile inside Marketing. The
  /// server says so per module (`parent` on /me), and nothing in Dart lists
  /// them — so this is the whole of what the shell does about it.
  testWidgets('a module with a parent is a chip inside it, not a sidebar row', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('marketing', 'Marketing'),
      _module('leads', 'Leads', parent: 'marketing'),
      _module('reviews', 'Reviews', parent: 'marketing'),
      _module('newsletter', 'Newsletter', parent: 'marketing'),
    ]);

    expect(_inSidebar('Marketing'), findsOneWidget);
    expect(
      _inSidebar('Leads'),
      findsNothing,
      reason: 'A row here as well as a chip is the same screen in two places.',
    );
    expect(_inSidebar('Reviews'), findsNothing);
    expect(_inSidebar('Newsletter'), findsNothing);

    await tester.tap(_inSidebar('Marketing'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ChoiceChip, 'Leads'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Reviews'), findsOneWidget);
    /*
     * Newsletter is in the fixture above BECAUSE IT IS A MODULE, and this line
     * used to assert the opposite belief.
     *
     * It read `findsOneWidget` with the reason "marketing.view is held, so its
     * own parts are there too", against a fixture that granted `marketing`,
     * `leads` and `reviews` and never mentioned Newsletter. That was true of
     * the screen as it stood and the screen was wrong: Newsletter is a module
     * with `newsletter.view`, and drawing it on Marketing's grant offered a
     * chip whose every request the server then refused (`2c46edab`).
     *
     * So the test was pinning the defect, and it went red on the fix. Its
     * fixture now grants Newsletter, which is what a role reaching this chip
     * holds in production.
     */
    expect(
      find.widgetWithText(ChoiceChip, 'Newsletter'),
      findsOneWidget,
      reason: 'Newsletter is reachable in this fixture, so its chip is drawn.',
    );
  });

  /// The other direction, which the test above cannot see.
  ///
  /// Everything there is granted, so it would pass for a screen that drew every
  /// parented module unconditionally — which is the bug `2c46edab` fixed, one
  /// step along. This is the case that distinguishes: Marketing is held and
  /// Newsletter is not.
  testWidgets('a parented module nobody holds is not a chip either', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('marketing', 'Marketing'),
      _module('leads', 'Leads', parent: 'marketing'),
    ]);

    await tester.tap(_inSidebar('Marketing'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ChoiceChip, 'Leads'), findsOneWidget);
    expect(
      find.widgetWithText(ChoiceChip, 'Newsletter'),
      findsNothing,
      reason: 'Newsletter needs its own grant; marketing.view is not it.',
    );
  });

  /// The case that decides whether moving a module can take it away from
  /// somebody. `leads.view` and `marketing.view` are independent grants, and
  /// this role holds only the first.
  testWidgets('a reachable child draws its parent, even when the parent is not '
      'reachable', (tester) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('marketing', 'Marketing', reachable: false),
      _module('leads', 'Leads', parent: 'marketing'),
    ]);

    expect(
      _inSidebar('Marketing'),
      findsOneWidget,
      reason: 'Otherwise a role holding leads.view loses Leads entirely.',
    );

    await tester.tap(_inSidebar('Marketing'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ChoiceChip, 'Leads'), findsOneWidget);
    expect(
      find.widgetWithText(ChoiceChip, 'Newsletter'),
      findsNothing,
      reason: 'Marketing is not reachable, so its own parts stay shut.',
    );
    expect(find.widgetWithText(ChoiceChip, 'Social Media Management'), findsNothing);
  });

  /// Neither the module nor anything in it — the parent is not drawn at all.
  testWidgets('an unreachable parent with no reachable child is not drawn', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(tester, modules: [
      _module('dashboard', 'Dashboard'),
      _module('marketing', 'Marketing', reachable: false),
      _module('leads', 'Leads', parent: 'marketing', reachable: false),
    ]);

    expect(_inSidebar('Marketing'), findsNothing);
  });

  /// /leads was a real URL for as long as Leads was a row. It still opens
  /// something rather than dumping somebody on the first module in their list.
  testWidgets('the old URL of a module now drawn inside another opens its '
      'parent', (tester) async {
    _wide(tester);

    await _pumpShell(
      tester,
      modules: [
        _module('dashboard', 'Dashboard'),
        _module('marketing', 'Marketing'),
        _module('leads', 'Leads', parent: 'marketing'),
      ],
      address: Address(path: '/leads'),
    );

    expect(find.byType(MarketingScreen), findsOneWidget);
    expect(find.byType(DashboardScreen), findsNothing);
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
  Address? address,
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

  await _pump(tester, api, session, address: address);
}

Future<void> _pump(
  WidgetTester tester,
  ApiClient api,
  Session session, {
  Address? address,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      // Rebuilt on every session change, exactly as the real app does it —
      // `_EzBiziAppState` listens to the session and calls setState. The shell
      // itself does not listen, so without this a `/me` that comes back with a
      // different set of modules would never reach the screen.
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(
          api: api,
          session: session,
          address: address,
        ),
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
  String? parent,
}) => {
  'key': key,
  'label': label,
  'available': available,
  'reachable': reachable,
  'parent': parent,
};

Map<String, dynamic> _me(List<Map<String, dynamic>> modules) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  // An administrator is sent every key; these two are the Settings faces.
  'permissions': <String>[
    'organizations.organization.view',
    'organizations.settings.view',
  ],
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
