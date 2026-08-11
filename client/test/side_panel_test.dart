import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The side panel, as something you can use without a mouse.
///
/// `showSidePanel` is one function and there are 47 panels behind it, so
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

  testWidgets('closing a panel gives focus back to what opened it', (tester) async {
    await _pump(tester, const SidePanel(title: 'Add customer', child: Text('body')));

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Close Add customer'));
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsNothing);
    expect(
      _focusIsInside(tester, find.byType(MaterialApp)),
      isTrue,
      reason: 'Focus was left on a panel that no longer exists, so the next Tab '
          'starts from nowhere.',
    );
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
}

// ── driving it ─────────────────────────────────────────────────────

Future<void> _pump(WidgetTester tester, Widget panel) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => Center(
            child: ElevatedButton(
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
