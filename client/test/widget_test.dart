import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/signup_screen.dart';
import 'package:ezbizi_client/screens/staff/modules_screen.dart';
import 'package:ezbizi_client/screens/staff/staff_login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('an unauthenticated app shows the sign-in screen', (
    tester,
  ) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // The gate that matters: no session, no application shell.
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Welcome back'), findsOneWidget);
  });

  testWidgets('the customer host offers no way into the control plane', (
    tester,
  ) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // Staff live at dev.ezbizi.com now. nginx refuses /api/v1/staff here too,
    // so a link would only lead somewhere that answers 404.
    expect(find.text('EzBizi staff'), findsNothing);
    expect(find.text('Staff sign-in'), findsNothing);
    expect(find.text('Authenticator code'), findsNothing);
  });

  testWidgets('the control-plane form asks for a second factor', (
    tester,
  ) async {
    // Pumped directly: the host picks this screen in the real app, and a widget
    // test cannot set Uri.base. Which hostname selects it is covered in
    // deployment_test.dart.
    await tester.pumpWidget(
      MaterialApp(home: StaffLoginScreen(staff: StaffSession(ApiClient()))),
    );
    await tester.pump();

    // TOTP is mandatory for staff, so the field is not optional and not a second
    // screen — the server takes both factors in one request.
    expect(find.text('Staff sign-in'), findsOneWidget);
    expect(find.text('Staff email'), findsOneWidget);
    expect(find.text('Authenticator code'), findsOneWidget);
  });

  // ── signing up ────────────────────────────────────────────────────
  //
  // Until this screen existed there was no way to create an organization at
  // all: every caller of ProvisionOrganization was a test. These cover the four
  // things that would make it useless or dangerous — unreachable, reachable
  // from the control plane, submittable half-filled, or silent about why the
  // server refused.

  testWidgets('the sign-in screen leads to signup, and back again', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // The only door into it. There is no router and no marketing page inside
    // the app, so if this affordance goes the screen is unreachable.
    await tester.tap(find.text('Create an account'));
    await tester.pump();

    expect(find.text('Create your account'), findsOneWidget);

    // The person, then the business — and the business asked for in the same
    // words company_form_panel.dart uses for the same idea.
    expect(find.text('Your name'), findsOneWidget);
    expect(find.text('Business name'), findsOneWidget);
    expect(find.text('Its first place'), findsOneWidget);

    // Two-way: somebody who already has an account is one tap from where they
    // meant to be.
    await tester.tap(find.widgetWithText(TextButton, 'Sign in'));
    await tester.pump();

    expect(find.text('Welcome back'), findsOneWidget);
  });

  testWidgets('the control plane offers no way to create an account', (
    tester,
  ) async {
    // Pumped directly, as the other control-plane tests are: the host picks
    // this screen in the real app and a widget test cannot set Uri.base.
    await tester.pumpWidget(
      MaterialApp(home: StaffLoginScreen(staff: StaffSession(ApiClient()))),
    );
    await tester.pump();

    // §4.2: staff accounts are created by staff. Nobody self-registers here,
    // and the signup endpoint is a customer one regardless.
    expect(find.text('Create an account'), findsNothing);
    expect(find.text('Create your account'), findsNothing);
  });

  testWidgets('signup will not submit until every field is filled', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pumpSignup(tester, (_) async => http.Response('{}', 201));

    // The contract has no optional field but device_name, so a partial form can
    // only ever produce a 422 round trip.
    expect(_submitEnabled(tester), isFalse);

    await tester.enterText(
      find.widgetWithText(TextField, 'Your name'),
      'Jose Silva',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Email address'),
      'jose@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Password'),
      'a-long-passphrase',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Business name'),
      'A-1 Chimney',
    );
    await tester.pump();

    // Four of five. §3 forbids a company without a franchise, which is exactly
    // what this last field becomes.
    expect(_submitEnabled(tester), isFalse);

    await tester.enterText(
      find.widgetWithText(TextField, 'Its first place'),
      'Connecticut',
    );
    await tester.pump();

    expect(_submitEnabled(tester), isTrue);
  });

  testWidgets('a rejected signup shows what the server said', (tester) async {
    tester.view.physicalSize = const Size(1440, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // A 422 in Laravel's ordinary shape.
    await _pumpSignup(
      tester,
      (_) async => http.Response(
        jsonEncode({
          'message': 'The given data was invalid.',
          'errors': {
            'email': ['That email address is already in use.'],
          },
        }),
        422,
        headers: {'content-type': 'application/json'},
      ),
    );

    await _fillSignup(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Create account'));
    await tester.pumpAndSettle();

    // Under the field it is about, not as a sentence floating above the form.
    expect(find.text('That email address is already in use.'), findsOneWidget);
  });

  testWidgets('a throttled signup shows the server message verbatim', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // The shape login already uses for 429. The sentence carries the only
    // number worth reading, so substituting wording of our own — which the
    // sign-in screen does on one path — would throw it away.
    await _pumpSignup(
      tester,
      (_) async => http.Response(
        jsonEncode({
          'message': 'Too many attempts. Try again in 41 seconds.',
          'code': 'throttled',
          'retry_after': 41,
        }),
        429,
        headers: {'content-type': 'application/json'},
      ),
    );

    await _fillSignup(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Create account'));
    await tester.pumpAndSettle();

    expect(
      find.text('Too many attempts. Try again in 41 seconds.'),
      findsOneWidget,
    );

    // And it is still submittable — a throttle is a "not yet", not a "never".
    expect(_submitEnabled(tester), isTrue);
  });

  testWidgets('a signup that succeeded cannot be submitted twice', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var signups = 0;

    // 201 with a token, then /me fails — the case that matters, because the
    // organization exists and the screen stays on screen to say so. A second
    // submit here would build a second organization behind the first.
    await _pumpSignup(tester, (request) async {
      if (request.url.path.endsWith('/auth/signup')) {
        signups++;
        return http.Response(
          jsonEncode({
            'status': 'authenticated',
            'token': 'tok',
            'staff_token': null,
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{"message":"Server error."}',
        500,
        headers: {'content-type': 'application/json'},
      );
    });

    await _fillSignup(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Create account'));
    await tester.pumpAndSettle();

    expect(signups, 1);
    expect(_submitEnabled(tester), isFalse);
    expect(find.textContaining('Your account was created'), findsOneWidget);
  });

  testWidgets('the wordmark is legible in dark mode', (tester) async {
    // "Bizi" was once fixed to the brand's ink and vanished on a dark
    // background. It follows the theme now, and this is what says so.
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // No longer inside a Card: the sign-in form sits directly on the surface
    // beside the identity panel. Found by type instead.
    final wordmark = tester.widget<RichText>(find.byType(RichText).first);

    final spans = (wordmark.text as TextSpan).children!;
    final bizi =
        spans.firstWhere((s) => (s as TextSpan).text == 'Bizi') as TextSpan;

    expect(bizi.style!.color, Colors.white);
  });

  test('the API base URL is a build flag, never a hardcoded host', () {
    // A1 Tools compiled its host into the binary, which is why moving that app
    // to a new domain is a migration rather than a config change. The default
    // here is same-origin, so a web build cannot drift from where it was served.
    expect(
      ApiClient.baseUrl,
      isEmpty,
      reason:
          'Default must be same-origin; set API_BASE at build time to override.',
    );
  });

  // ── the sign-in screens at every size somebody actually uses ──
  //
  // Added after shipping a row that overflowed by 110px: a fixed-width label
  // beside a button, in a panel that could be narrower than the two of them.
  // `flutter analyze` cannot see that, and neither can reading it — the only
  // thing that catches an overflow is laying the widget out at the size where
  // it happens.
  //
  // Flutter reports overflow as a test failure, so pumping at each size and
  // settling IS the assertion.
  for (final size in const [
    Size(360, 740), // phone
    Size(768, 1024), // tablet, still below the two-panel threshold
    Size(1024, 640), // small laptop — short, which is the hard one
    Size(1440, 900), // ordinary laptop
    Size(1920, 1080), // the window these screens are actually reviewed in
    Size(2560, 1440), // the wide monitor this is usually seen on
  ]) {
    testWidgets(
      'the customer sign-in lays out at ${size.width}x${size.height}',
      (tester) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        await tester.pumpWidget(const EzBiziApp());
        await tester.pump();

        expect(find.text('Welcome back'), findsOneWidget);
      },
    );

    testWidgets('the control plane lays out at ${size.width}x${size.height}', (
      tester,
    ) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(home: StaffLoginScreen(staff: StaffSession(ApiClient()))),
      );
      await tester.pump();

      expect(find.text('Sign in'), findsWidgets);
    });

    // Signup gets the same treatment, and needs it more: five fields, two
    // section headings and three blocks of helper text make it the tallest of
    // the three public screens by some way.
    //
    // Unlike sign-in it is NOT asserted to fit without scrolling. It cannot —
    // the form is taller than a 720px window once the padding is paid — and
    // AuthShell's form panel scrolls by design. What this catches is the other
    // kind of failure: a row that overflows sideways, which is what shipped on
    // the sign-in screen and which nothing but laying it out will find.
    testWidgets('signup lays out at ${size.width}x${size.height}', (
      tester,
    ) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpSignup(tester, (_) async => http.Response('{}', 201));

      expect(find.text('Create your account'), findsOneWidget);
    });
  }

  // A sign-in page must never ask anyone to scroll to see it.
  //
  // The first attempt at "make it fit" wrapped the panel in a scroll view,
  // which hands its child UNBOUNDED height — the exact opposite of what Spacer
  // and Flexible need to shrink. So nothing shrank, the column grew past the
  // window, and it scrolled. Asserting on the absence of a scrollable is the
  // only way to keep that fix from quietly coming back.
  for (final size in const [
    Size(1280, 720),
    Size(1440, 900),
    Size(1920, 1080),
    Size(2560, 1440),
  ]) {
    testWidgets(
      'the sign-in page does not scroll at ${size.width}x${size.height}',
      (tester) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        await tester.pumpWidget(const EzBiziApp());
        await tester.pump();

        for (final scrollable in tester.widgetList<Scrollable>(
          find.byType(Scrollable),
        )) {
          final position = (scrollable.controller?.hasClients ?? false)
              ? scrollable.controller!.position
              : null;

          if (position == null) continue;

          expect(
            position.maxScrollExtent,
            0.0,
            reason:
                'Something on the sign-in page scrolls at '
                '${size.width}x${size.height}.',
          );
        }
      },
    );
  }

  // ── a refresh must not blank a screen that has something on it ──
  //
  // The owner's complaint, in their words: "whenever you change a value ... the
  // whole page reloads". It was not a Settings bug. Fifteen screens set
  // `_loading = true` at the top of every fetch, so every save, toggle and
  // delete tore the page down to a CircularProgressIndicator and rebuilt it.
  //
  // Modules stands in for all of them: one list, a refresh that goes through
  // the same `_load()` every write does, and no session state to fake. What is
  // asserted is the distinction the fix turns on — a first load blanks, because
  // there is genuinely nothing to show, and every load after it does not.
  testWidgets('a refresh replaces content in place, a first load blanks', (
    tester,
  ) async {
    var loads = 0;

    // Held open so the assertion lands DURING the request rather than after it.
    // Completing immediately would make a spinner and no spinner
    // indistinguishable, which is the whole thing being tested.
    var gate = Completer<void>();

    final api = ApiClient(
      client: MockClient((request) async {
        loads++;
        await gate.future;

        return http.Response(
          jsonEncode({
            'data': [
              {
                'key': 'jobs',
                'label': 'Jobs',
                'state': 'enabled',
                'permissions': <String>[],
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: ModulesScreen(staff: StaffSession(api))),
      ),
    );
    await tester.pump();

    // First load: nothing to preserve, so the spinner is the honest thing to
    // draw. If this ever stops holding, the isEmpty target is pointed at
    // something that is never empty and the screen shows a false empty state.
    expect(loads, 1);
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.text('Jobs'), findsNothing);

    gate.complete();
    await tester.pumpAndSettle();

    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Jobs'), findsOneWidget);

    // Now the refresh, through the same _load() every write on this screen
    // calls when the server accepts it.
    gate = Completer<void>();
    await tester.tap(find.byTooltip('Refresh'));
    await tester.pump();

    expect(loads, 2, reason: 'The refresh did not actually re-request.');
    expect(
      find.byType(CircularProgressIndicator),
      findsNothing,
      reason: 'A refresh blanked a screen that already had a row on it.',
    );
    expect(
      find.text('Jobs'),
      findsOneWidget,
      reason: 'The row on screen should stay put until the new one lands.',
    );

    gate.complete();
    await tester.pumpAndSettle();

    expect(find.text('Jobs'), findsOneWidget);
  });

  // The alignment test that used to live here has been deleted, not fixed.
  //
  // It asserted that the hand-rolled footer items landed in the same column as
  // the real destinations. They are now real destinations themselves — a second
  // NavigationRail — so there is nothing left to drift, and a test that can no
  // longer fail is a test that only costs time to maintain.

  // The rule between the modules and the pinned group must actually be drawn.
  //
  // It was not, for two rounds of "fixes". Divider has no width of its own —
  // it fills what it is given — and the sidebar was a non-flex child of a Row,
  // which hands out `maxWidth: infinity`. Under that, the line collapses to
  // ZERO WIDTH while still occupying its 17px of vertical space: a gap where a
  // rule should be. Making it full-bleed and then darkening it changed nothing,
  // because the problem was never how it looked.
  //
  // Asserting on the painted width is the only check that distinguishes "faint"
  // from "not there", which is exactly the distinction that was missed.
  testWidgets('a short window scrolls the destinations and keeps the tail', (
    tester,
  ) async {
    // The defect this exists for: NavigationRail lays its destinations out in a
    // Column and cannot scroll. In a bare Expanded, a sidebar shorter than its
    // contents OVERFLOWED -- the destinations drew over Settings, and the ones
    // underneath were unreachable rather than hidden, because there was nothing
    // to scroll with. Reported from a 900px screen.
    //
    // 320px of height against fifteen destinations is well past the threshold.
    tester.view.physicalSize = const Size(1200, 320);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Row(
            children: [
              NavigationSidebar(
                expanded: true,
                width: 220,
                destinations: [
                  for (final name in const [
                    'Dashboard', 'Human Resources', 'Customers',
                    'Jobs', 'Scheduling', 'Inspection', 'Marketing',
                    'Web Management', 'Phone', 'Fleet Management', 'Inventory',
                    'Services', 'Email', 'Automations', 'Monitoring',
                  ])
                    Destination(name, Icons.circle_outlined, const SizedBox()),
                ],
                pinned: [
                  Destination(
                    'Settings',
                    Icons.settings_outlined,
                    const SizedBox(),
                  ),
                ],
                staffOnly: const [],
                selectedIndex: 0,
                pinnedIndex: null,
                staffIndex: null,
                onSelected: (_) {},
                onPinnedSelected: (_) {},
                onStaffSelected: (_) {},
              ),
              const Expanded(child: SizedBox()),
            ],
          ),
        ),
      ),
    );
    await tester.pump();

    // 1. It does not overflow. A RenderFlex overflow is an exception in a test,
    //    so this is what actually fails if the scroll view is removed.
    expect(
      tester.takeException(),
      isNull,
      reason:
          'The sidebar overflowed at 320px tall. The destinations need to '
          'scroll -- a NavigationRail has no scrolling of its own.',
    );

    // 2. The destinations are genuinely scrollable rather than merely clipped.
    //    Clipping would hide them with no way to reach them, which is the
    //    defect wearing a tidier appearance.
    final scroller = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.byType(Scrollable),
    );
    expect(scroller, findsWidgets);

    // 3. And the tail did NOT scroll away with them. Going to Settings is often
    //    what somebody does because they could not find a module in the list.
    expect(
      find.text('Settings'),
      findsOneWidget,
      reason: 'Settings must stay pinned when the destinations scroll.',
    );

    // 4. "More" has its own row: drawn over the list it covered a module
    //    name (desktop review 2026-11-13, Top 6).
    await tester.pump();
    final more = find.byKey(const Key('sidebar-more-below'));
    expect(more, findsOneWidget);
    final moreBox = tester.getRect(more);
    final list = tester.getRect(scroller.first);
    expect(
      moreBox.top,
      greaterThanOrEqualTo(list.bottom - 0.5),
      reason: 'More sits below the scrolling list, not on top of a row.',
    );
  });

  /// The fifteen modules, in shell order.
  List<Destination> modules() => [
    for (final name in const [
      'Dashboard', 'Human Resources', 'Customers',
      'Jobs', 'Scheduling', 'Inspection', 'Marketing',
      'Web Management', 'Phone', 'Fleet Management', 'Inventory',
      'Services', 'Email', 'Automations', 'Monitoring',
    ])
      Destination(name, Icons.circle_outlined, const SizedBox()),
  ];

  Widget sidebar({
    required bool expanded,
    required ValueChanged<int> onSelected,
  }) => MaterialApp(
    theme: EzBiziTheme.light(),
    home: Scaffold(
      body: Row(
        children: [
          NavigationSidebar(
            expanded: expanded,
            width: 220,
            destinations: modules(),
            pinned: [
              Destination('Settings', Icons.settings_outlined, const SizedBox()),
            ],
            staffOnly: const [],
            selectedIndex: 0,
            pinnedIndex: null,
            staffIndex: null,
            onSelected: onSelected,
            onPinnedSelected: (_) {},
            onStaffSelected: (_) {},
          ),
          const Expanded(child: SizedBox()),
        ],
      ),
    ),
  );

  testWidgets('a filtered row opens the module it names, not the one at its '
      'position', (tester) async {
    // THE defect this feature can have, and it is silent.
    //
    // NavigationRail addresses destinations by POSITION. Hand it a filtered
    // list and it reports the index within that list -- so the third visible
    // row reports 1, which in the full list is Human Resources. Every row would
    // open a plausible, wrong module, with no error anywhere. Nothing about the
    // screen would look broken; you would just keep ending up somewhere else.
    final opened = <int>[];
    await tester.pumpWidget(sidebar(expanded: true, onSelected: opened.add));

    // "man" keeps three of fifteen: Human Resources (1), Web Management (7),
    // Fleet Management (9).
    await tester.enterText(find.byType(TextField), 'man');
    await tester.pumpAndSettle();

    expect(find.text('Scheduling'), findsNothing, reason: 'the filter is on');
    expect(find.text('3 of 15 modules'), findsOneWidget);

    await tester.tap(find.text('Fleet Management'));
    await tester.pumpAndSettle();

    expect(
      opened,
      [9],
      reason:
          'Fleet Management is destination 9. Reporting 2 means the rail was '
          'handed a filtered list without mapping its indices back, and every '
          'row below the first removed one opens the wrong module.',
    );
  });

  testWidgets('the collapsed rail has no search, and a filter that matches '
      'nothing explains itself', (tester) async {
    await tester.pumpWidget(sidebar(expanded: false, onSelected: (_) {}));
    expect(
      find.byType(TextField),
      findsNothing,
      reason:
          'At 80px there are no labels to search. An icon here would be a '
          'control that only works by first undoing the state you chose.',
    );

    await tester.pumpWidget(sidebar(expanded: true, onSelected: (_) {}));
    await tester.enterText(find.byType(TextField), 'invoic');
    await tester.pumpAndSettle();

    // An empty sidebar with no explanation is indistinguishable from a broken
    // one, so it has to name what was typed and offer the way back.
    expect(find.textContaining('invoic'), findsWidgets);
    expect(find.textContaining('filtered, not empty'), findsOneWidget);
    expect(
      find.text('Settings'),
      findsOneWidget,
      reason: 'the tail never filters -- it is where you go when the list did '
          'not have what you wanted',
    );
  });

  testWidgets('the sidebar rule is actually drawn, not zero-width', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          // The real arrangement: a non-flex sidebar beside an Expanded body, so
          // the sidebar receives unbounded width exactly as it does in the shell.
          body: Row(
            children: [
              NavigationSidebar(
                expanded: true,
                width: 220,
                destinations: [
                  Destination(
                    'Dashboard',
                    Icons.dashboard_outlined,
                    const SizedBox(),
                  ),
                  Destination(
                    'Customers',
                    Icons.people_outline,
                    const SizedBox(),
                  ),
                ],
                pinned: [
                  Destination(
                    'Settings',
                    Icons.settings_outlined,
                    const SizedBox(),
                  ),
                ],
                staffOnly: const [],
                selectedIndex: 0,
                pinnedIndex: null,
                staffIndex: null,
                onSelected: (_) {},
                onPinnedSelected: (_) {},
                onStaffSelected: (_) {},
              ),
              const Expanded(child: SizedBox()),
            ],
          ),
        ),
      ),
    );
    await tester.pump();

    final divider = find.byType(Divider);
    expect(divider, findsOneWidget);

    // The Divider box itself always has width; the LINE inside it is the thing
    // that vanished. Measure the painted container, not its parent.
    final line = find.descendant(
      of: divider,
      matching: find.byWidgetPredicate(
        (w) => w is Container && w.decoration != null,
      ),
    );

    expect(line, findsOneWidget);
    expect(
      tester.getSize(line).width,
      greaterThan(50),
      reason:
          'The rule is drawn but has (almost) no width — the sidebar is '
          'being laid out with unbounded width again.',
    );
  });

  /// Development is staff-only (§4.2), so it sits under its own rule rather than
  /// in the group with Settings — that configures the business and every
  /// administrator has it.
  ///
  /// Both directions, because the interesting failure is the second one: a rule
  /// left behind when the group beneath it is empty is a divider with nothing
  /// under it, which reads as a rendering fault.
  testWidgets('the staff group brings its own rule, and takes it away again', (
    tester,
  ) async {
    Future<void> pumpWith(List<Destination> staffOnly) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: Row(
              children: [
                NavigationSidebar(
                  expanded: true,
                  width: 220,
                  destinations: [
                    Destination(
                      'Dashboard',
                      Icons.dashboard_outlined,
                      const SizedBox(),
                    ),
                  ],
                  pinned: [
                    Destination(
                      'Settings',
                      Icons.settings_outlined,
                      const SizedBox(),
                    ),
                  ],
                  staffOnly: staffOnly,
                  selectedIndex: 0,
                  pinnedIndex: null,
                  staffIndex: null,
                  onSelected: (_) {},
                  onPinnedSelected: (_) {},
                  onStaffSelected: (_) {},
                ),
                const Expanded(child: SizedBox()),
              ],
            ),
          ),
        ),
      );
      await tester.pump();
    }

    await pumpWith([
      Destination('Development', Icons.terminal_outlined, const SizedBox()),
    ]);

    expect(find.text('Development'), findsOneWidget);
    expect(
      find.byType(Divider),
      findsNWidgets(2),
      reason:
          'Development needs a rule of its own — grouped with Settings it '
          'reads as one more place to configure the business.',
    );

    // Not staff: the group and its rule both go.
    await pumpWith(const []);

    expect(find.text('Development'), findsNothing);
    expect(
      find.byType(Divider),
      findsOneWidget,
      reason: 'A rule with nothing under it looks like a bug.',
    );
  });
}

/// Put the signup screen on screen with a canned server behind it.
///
/// Pumped directly rather than tapped through from the sign-in screen, because
/// these tests are about what the screen does with an answer — reaching it is
/// covered once, above.
Future<void> _pumpSignup(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(client: MockClient(handler));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: SignupScreen(api: api, session: Session(api), onSignIn: () {}),
    ),
  );
  await tester.pump();
}

/// One valid answer per field, so a test can get to the submit.
Future<void> _fillSignup(WidgetTester tester) async {
  await tester.enterText(
    find.widgetWithText(TextField, 'Your name'),
    'Jose Silva',
  );
  await tester.enterText(
    find.widgetWithText(TextField, 'Email address'),
    'jose@example.com',
  );
  await tester.enterText(
    find.widgetWithText(TextField, 'Password'),
    'a-long-passphrase',
  );
  await tester.enterText(
    find.widgetWithText(TextField, 'Business name'),
    'A-1 Chimney',
  );
  await tester.enterText(
    find.widgetWithText(TextField, 'Its first place'),
    'Connecticut',
  );
  await tester.pump();
}

/// Whether the button would do anything if it were pressed.
///
/// A disabled FilledButton is one whose onPressed is null — asking the widget
/// rather than looking at its colour is the difference between "greyed out" and
/// "cannot be pressed", and only the second one prevents a second organization.
bool _submitEnabled(WidgetTester tester) =>
    tester
        .widget<FilledButton>(
          find.widgetWithText(FilledButton, 'Create account'),
        )
        .onPressed !=
    null;
