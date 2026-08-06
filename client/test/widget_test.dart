import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/staff/staff_login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void _noop() {}

void main() {
  testWidgets('an unauthenticated app shows the sign-in screen', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // The gate that matters: no session, no application shell.
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Welcome back'), findsOneWidget);
  });

  testWidgets('the customer host offers no way into the control plane', (tester) async {
    await tester.pumpWidget(const EzBiziApp());
    await tester.pump();

    // Staff live at dev.ezbizi.com now. nginx refuses /api/v1/staff here too,
    // so a link would only lead somewhere that answers 404.
    expect(find.text('EzBizi staff'), findsNothing);
    expect(find.text('Staff sign-in'), findsNothing);
    expect(find.text('Authenticator code'), findsNothing);
  });

  testWidgets('the control-plane form asks for a second factor', (tester) async {
    // Pumped directly: the host picks this screen in the real app, and a widget
    // test cannot set Uri.base. Which hostname selects it is covered in
    // deployment_test.dart.
    await tester.pumpWidget(MaterialApp(
      home: StaffLoginScreen(staff: StaffSession(ApiClient())),
    ));
    await tester.pump();

    // TOTP is mandatory for staff, so the field is not optional and not a second
    // screen — the server takes both factors in one request.
    expect(find.text('Staff sign-in'), findsOneWidget);
    expect(find.text('Staff email'), findsOneWidget);
    expect(find.text('Authenticator code'), findsOneWidget);
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
    final bizi = spans.firstWhere((s) => (s as TextSpan).text == 'Bizi') as TextSpan;

    expect(bizi.style!.color, Colors.white);
  });

  test('the API base URL is a build flag, never a hardcoded host', () {
    // A1 Tools compiled its host into the binary, which is why moving that app
    // to a new domain is a migration rather than a config change. The default
    // here is same-origin, so a web build cannot drift from where it was served.
    expect(ApiClient.baseUrl, isEmpty,
        reason: 'Default must be same-origin; set API_BASE at build time to override.');
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
    Size(360, 740),    // phone
    Size(768, 1024),   // tablet, still below the two-panel threshold
    Size(1024, 640),   // small laptop — short, which is the hard one
    Size(1440, 900),   // ordinary laptop
    Size(1920, 1080),  // the window these screens are actually reviewed in
    Size(2560, 1440),  // the wide monitor this is usually seen on
  ]) {
    testWidgets('the customer sign-in lays out at ${size.width}x${size.height}',
        (tester) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(const EzBiziApp());
      await tester.pump();

      expect(find.text('Welcome back'), findsOneWidget);
    });

    testWidgets('the control plane lays out at ${size.width}x${size.height}',
        (tester) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(MaterialApp(
        home: StaffLoginScreen(staff: StaffSession(ApiClient())),
      ));
      await tester.pump();

      expect(find.text('Sign in'), findsWidgets);
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
    testWidgets('the sign-in page does not scroll at ${size.width}x${size.height}',
        (tester) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(const EzBiziApp());
      await tester.pump();

      for (final scrollable in tester.widgetList<Scrollable>(find.byType(Scrollable))) {
        final position = (scrollable.controller?.hasClients ?? false)
            ? scrollable.controller!.position
            : null;

        if (position == null) continue;

        expect(
          position.maxScrollExtent,
          0.0,
          reason: 'Something on the sign-in page scrolls at '
              '${size.width}x${size.height}.',
        );
      }
    });
  }

  // The pinned footer items must line up with the destinations above them.
  //
  // They did not: NavigationRail centres each destination icon in an 80px box
  // and starts the label after it, while the footer used a 16px inset — so
  // Settings and Development sat 20px to the left of everything else and read
  // as a separate list that happened to be underneath.
  //
  // Asserting on the x-position rather than on the widget tree, because the two
  // are built by completely different code and the only thing that matters is
  // that they land in the same column.
  testWidgets('the pinned footer icons align with the rail destinations',
      (tester) async {
    tester.view.physicalSize = const Size(1440, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Row(children: [
          NavigationRail(
            selectedIndex: 0,
            extended: true,
            minWidth: kRailWidth,
            minExtendedWidth: 220,
            destinations: const [
              NavigationRailDestination(
                  icon: Icon(Icons.dashboard_outlined), label: Text('Dashboard')),
              NavigationRailDestination(
                  icon: Icon(Icons.people_outline), label: Text('Customers')),
            ],
            trailing: const SizedBox(
              width: 220,
              child: RailFooterItem(
                extended: true,
                icon: Icons.settings_outlined,
                label: 'Settings',
                selected: false,
                onTap: _noop,
              ),
            ),
          ),
          const Expanded(child: SizedBox()),
        ]),
      ),
    ));
    await tester.pump();

    final destinationIcon =
        tester.getCenter(find.byIcon(Icons.dashboard_outlined)).dx;
    final footerIcon =
        tester.getCenter(find.byIcon(Icons.settings_outlined)).dx;

    expect(
      footerIcon,
      moreOrLessEquals(destinationIcon, epsilon: 1.0),
      reason: 'The footer icon is at $footerIcon, the destinations are at '
          '$destinationIcon — they are in different columns.',
    );
  });
}
