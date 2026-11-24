import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// The side panel, as something you can use without a mouse.
///
/// `showSidePanel` is one function and every panel in the app is behind it, so
/// everything asserted here is asserted once for all of them. That is the whole
/// argument for fixing it there rather than panel by panel.
///
/// Two different users are being served and they are easy to confuse:
///
///   - somebody on a keyboard, who needs focus to MOVE into the panel when it
///     opens and come back to where they were when it closes. Without that,
///     Tab starts from wherever it was and walks the page behind.
///   - somebody on a screen reader, who needs the panel announced as a thing
///     that has opened, by its own name, with the page behind excluded.
///
/// The second is `scopesRoute` + `namesRoute`, which is what Flutter's own
/// Drawer and AlertDialog do. The first is focus, and it does not come for free.
void main() {
  testWidgets('opening a panel moves focus into it', (tester) async {
    await _pump(tester, const SidePanel(title: 'Add customer', child: Text('body')));

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    expect(
      _focusIsInside(tester, find.byType(SidePanel)),
      isTrue,
      reason: 'Focus stayed on the page behind, so Tab walks the page, not the '
          'panel — and a screen reader is left where it was.',
    );
  });

  testWidgets('a field that asks for focus still gets it', (tester) async {
    await _pump(
      tester,
      const SidePanel(
        title: 'Add customer',
        child: TextField(autofocus: true, decoration: InputDecoration(labelText: 'Name')),
      ),
    );

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    // The panel taking focus for itself must not out-race the form. Most panels
    // are forms, and landing on the first box is the whole point of opening one.
    expect(
      _focusIsInside(tester, find.byType(TextField)),
      isTrue,
      reason: 'The panel stole focus from the field that asked for it.',
    );
  });

  /// The half this file used to only claim to test.
  ///
  /// It asserted `_focusIsInside(MaterialApp)`, which is true of any focus node
  /// anywhere in the app — including the empty route scope focus lands on when
  /// nothing was restored. And it opened the panel with a tap, which in Flutter
  /// does NOT give a button keyboard focus, so the opener never held focus and
  /// there was nothing for the close to give back. The test could not have
  /// failed, and it was named after a behaviour it never looked at.
  ///
  /// The behaviour is real and belongs to the framework rather than to
  /// SidePanel: the route beneath keeps its own FocusScopeNode, that scope
  /// remembers its focusedChild, and popping the panel hands focus back to it.
  /// Asserted here anyway, because "the framework does it" is exactly the kind
  /// of thing that stops being true when the panel is later wrapped in
  /// something with a scope of its own.
  testWidgets('closing a panel gives focus back to what opened it', (tester) async {
    final opener = FocusNode(debugLabel: 'Opener');
    addTearDown(opener.dispose);

    await _pump(
      tester,
      const SidePanel(title: 'Add customer', child: Text('body')),
      openerFocus: opener,
    );

    // What a keyboard user has done by the time they press Enter, and what a
    // tap alone does not do.
    opener.requestFocus();
    await tester.pumpAndSettle();
    expect(opener.hasFocus, isTrue, reason: 'The premise of the test.');

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    expect(
      opener.hasFocus,
      isFalse,
      reason: 'Focus should have moved into the panel, not stayed behind it.',
    );

    await tester.tap(find.byTooltip('Close Add customer'));
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsNothing);
    expect(
      opener.hasFocus,
      isTrue,
      reason: 'Focus was left on a panel that no longer exists, so the next Tab '
          'starts from nowhere instead of from the control you pressed.',
    );
  });

  testWidgets('dismissing a panel without the close button restores it too', (
    tester,
  ) async {
    // Escape and the scrim both pop the route rather than pressing the button,
    // and they are how most panels actually get closed.
    final opener = FocusNode(debugLabel: 'Opener');
    addTearDown(opener.dispose);

    await _pump(
      tester,
      const SidePanel(title: 'Add customer', child: Text('body')),
      openerFocus: opener,
    );

    opener.requestFocus();
    await tester.pumpAndSettle();

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsNothing);
    expect(opener.hasFocus, isTrue);
  });

  testWidgets('the panel is announced by its own name', (tester) async {
    final handle = tester.ensureSemantics();

    await _pump(tester, const SidePanel(title: 'Add customer', child: Text('body')));

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    final data = tester.getSemantics(find.byType(SidePanel)).getSemanticsData();

    expect(
      data.flagsCollection.namesRoute,
      isTrue,
      reason: 'Nothing tells a screen reader that a panel opened, or what it is.',
    );
    expect(
      data.label,
      'Add customer',
      reason: 'Announced, but not by the name of the thing that opened.',
    );
    expect(
      data.flagsCollection.scopesRoute,
      isTrue,
      reason: 'Without scopesRoute the page behind is still read as part of the '
          'same screen.',
    );

    handle.dispose();
  });

  testWidgets('the close button says what it closes', (tester) async {
    await _pump(tester, const SidePanel(title: 'Add customer', child: Text('body')));

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    // An icon-only control is the one a screen reader cannot guess, and "Close"
    // on its own does not say close what when a panel sits over a page.
    expect(find.byTooltip('Close Add customer'), findsOneWidget);
  });

  /// A phone's status bar is not empty space the panel may draw under.
  ///
  /// `showGeneralDialog` covers the whole screen, notch and home indicator
  /// included, and nothing in the panel inset for them. On a desktop there is
  /// no status bar so it never showed; on an iPhone the header drew UNDER the
  /// clock, which put the close button somewhere that cannot be tapped. The
  /// panel has no other way out on a phone — Escape needs a keyboard and the
  /// scrim is off the side — so the app had to be force-quit.
  // Mobile review 11-24: a panel is a dialog route, so nothing resized it for
  // the keyboard, and Book a job's buttons sat under it on a phone.
  testWidgets('the footer stays above the keyboard', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await _pump(
      tester,
      SidePanel(
        title: 'Book a job',
        actions: [
          FilledButton(onPressed: () {}, child: const Text('Book job')),
        ],
        child: const TextField(),
      ),
      viewPadding: const EdgeInsets.only(top: 47, bottom: 34),
    );
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    tester.view.viewInsets = const FakeViewPadding(bottom: 336);
    await tester.pumpAndSettle();

    expect(
      tester.getRect(find.text('Book job')).bottom,
      lessThanOrEqualTo(844 - 336),
      reason: 'The buttons of the panel are under the keyboard.',
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('the close button clears the status bar on a notched phone', (
    tester,
  ) async {
    // An iPhone with a notch: 47 logical pixels of status bar, 34 of home
    // indicator.
    await _pump(
      tester,
      const SidePanel(title: "What's been built", child: Text('body')),
      viewPadding: const EdgeInsets.only(top: 47, bottom: 34),
    );

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    final close = find.byTooltip("Close What's been built");
    expect(close, findsOneWidget);

    expect(
      tester.getRect(close).top,
      greaterThanOrEqualTo(47),
      reason:
          'The close button is under the status bar, where the clock and the '
          'battery are. It cannot be tapped, and it is the only way out of a '
          'panel on a phone.',
    );

    // The surface itself must still reach the top edge. Insetting the Material
    // rather than its contents would leave a floating rounded card with a gap
    // above it — the modal look this panel exists not to be.
    expect(
      tester
          .getRect(
            find
                .descendant(
                  of: find.byType(SidePanel),
                  matching: find.byType(Material),
                )
                .first,
          )
          .top,
      0,
      reason: 'The panel surface should still bleed to the screen edge; only '
          'what you touch moves down.',
    );
  });
}

// ── driving it ─────────────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  Widget panel, {
  FocusNode? openerFocus,
  EdgeInsets viewPadding = EdgeInsets.zero,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(padding: viewPadding, viewPadding: viewPadding),
        child: child!,
      ),
      home: Scaffold(
        body: Builder(
          builder: (context) => Center(
            child: ElevatedButton(
              focusNode: openerFocus,
              onPressed: () => showSidePanel<void>(context, builder: (_) => panel),
              child: const Text('Open'),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// Whether the thing holding keyboard focus is inside [of].
bool _focusIsInside(WidgetTester tester, Finder of) {
  final focused = FocusManager.instance.primaryFocus;

  if (focused == null) return false;

  final context = focused.context;

  if (context == null) return false;

  var found = false;

  context.visitAncestorElements((element) {
    if (of.evaluate().contains(element)) {
      found = true;
      return false;
    }
    return true;
  });

  return found;
}
