import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/dashboard_panels.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/screens/hr/dashboard_tab.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/dashboard_grid.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// One card standard, and an order that belongs to the person looking at it.
///
/// ── What was actually wrong ──────────────────────────────────────
///
/// Two things, and they were the same thing. The dashboards laid their panels
/// out in a `Wrap` of content-height children, so a panel with one line in it
/// sat as a sliver beside a panel with six and the next row started at a
/// different place on each side of the screen. And the HR tab had its OWN card
/// implementation, which is why the two screens could not be corrected
/// together: they did not share the thing that was wrong.
///
/// So the assertions below are about the two properties that make a grid a
/// grid — every card in a row is the same height, and there is exactly one card
/// widget behind both screens — and about the order somebody drags their cards
/// into surviving a reload, a keyboard, and a widget they can no longer see.
void main() {
  group('one card standard', () {
    testWidgets('cards in a row are the same height, however little they say', (
      tester,
    ) async {
      await _pumpDashboard(tester, _dashboard());

      final heights = _cardHeights(tester);

      expect(heights, hasLength(4), reason: 'Four panels were sent.');

      // The two on the first row match, and the two on the second row match.
      // "Jobs by status" has one line under its header and "Upcoming" has four,
      // which is exactly the pair that used to leave a hole.
      expect(heights[0], heights[1]);
      expect(heights[2], heights[3]);

      // And the rows really are different heights — otherwise this test would
      // pass against a grid that forced every card in the app to one size,
      // which is a different bug with the same assertion.
      expect(heights[0], isNot(heights[2]));
    });

    testWidgets('a card with almost nothing in it is still a card', (
      tester,
    ) async {
      await _pumpDashboard(tester, _dashboard(only: {'jobs.by_status'}));

      expect(_cardHeights(tester).single, DashboardCard.minimumHeight);
    });

    /// The duplication was half the bug. If the HR tab keeps its own card, the
    /// two screens drift again the first time either is corrected.
    testWidgets('both dashboards draw the same card widget', (tester) async {
      await _pumpDashboard(tester, _dashboard());
      expect(find.byType(DashboardCard), findsNWidgets(4));

      await _pumpHr(tester, _hr());

      // Four from the server and the one this tab draws itself.
      expect(find.byType(DashboardCard), findsNWidgets(5));
    });

    testWidgets('the border is the theme’s edge, not a colour of its own', (
      tester,
    ) async {
      await _pumpDashboard(tester, _dashboard(only: {'jobs.by_status'}));

      final context = tester.element(find.byType(DashboardCard).first);
      final material = tester.widget<Material>(
        find.descendant(
          of: find.byType(DashboardCard).first,
          matching: find.byType(Material),
        ).first,
      );

      expect(
        (material.shape as RoundedRectangleBorder).side.color,
        EzBiziTheme.edge(context),
      );
    });

    /// R7. A phone is one column, and nothing may assume a wide window.
    testWidgets('a narrow window is one column', (tester) async {
      await _pumpDashboard(tester, _dashboard(), size: const Size(600, 3000));

      final lefts = tester
          .renderObjectList<RenderBox>(find.byType(DashboardCard))
          .map((box) => box.localToGlobal(Offset.zero).dx)
          .toSet();

      expect(lefts, hasLength(1), reason: 'Two columns on a 600px window.');
    });

    /// A phone. Three controls, an icon and a long title share one header line,
    /// and the desktop-shaped assumption is that they always fit.
    testWidgets('a phone-width card lays out without overflowing', (
      tester,
    ) async {
      await _pumpDashboard(tester, _dashboard(), size: const Size(375, 4000));
      expect(tester.takeException(), isNull);

      await _pumpHr(tester, _hr(), size: const Size(375, 4000));
      expect(tester.takeException(), isNull);

      // And the controls are still there to be used, not dropped because the
      // window was small — which would leave a phone with no way to rearrange
      // anything at all.
      expect(find.byTooltip('Move “Headcount by place” later'), findsOneWidget);
    });
  });

  group('an order that is the user’s own', () {
    testWidgets('dragging a panel later is saved into the layout, options and '
        'all', (tester) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpDashboard(tester, _dashboard(record: sent));

      await tester.tap(find.byTooltip('Move “Jobs by status” later'));
      await tester.pumpAndSettle();

      expect(sent, hasLength(1));

      // The whole list goes back, in its new order...
      expect(
        [for (final w in sent.single['widgets'] as List) w['key']],
        ['jobs.upcoming', 'jobs.by_status', 'jobs.revenue', 'customers.added'],
      );

      // ...carrying what each panel was configured with. Saving an ORDER that
      // quietly reset every panel's options would be a very expensive drag.
      expect(
        (sent.single['widgets'] as List)[2]['options'],
        {'statuses': ['completed'], 'days': 30},
      );
    });

    /// The screen must not wait for the network to show the move. It also must
    /// not show a move the server rejected.
    testWidgets('the card moves on the spot, and moves back if the save fails', (
      tester,
    ) async {
      // The save is held open, so what the screen does BEFORE the answer lands
      // is observable. Without this the mock replies inside the same pump and
      // the optimistic move and its undo are indistinguishable.
      final answered = Completer<void>();

      await _pumpDashboard(tester, _dashboard(saveStatus: 500, held: answered));

      expect(_cardTitles(tester).first, 'Jobs by status');

      await tester.tap(find.byTooltip('Move “Jobs by status” later'));
      await tester.pump();

      expect(
        _cardTitles(tester).first,
        'Upcoming jobs',
        reason: 'The card waited for a round trip before moving.',
      );

      answered.complete();
      await tester.pumpAndSettle();

      // Put back where the server still has it, and said out loud. A card left
      // sitting somewhere the server does not agree with is worse than a card
      // that did not move.
      expect(_cardTitles(tester).first, 'Jobs by status');
      expect(find.text('Nothing doing.'), findsOneWidget);
    });

    /// Turning a panel on is not a reason to lose an arrangement. Rebuilding
    /// the layout from the catalogue, as the panel picker used to, threw away
    /// the order every time somebody ticked a box.
    testWidgets('choosing panels keeps the order they are already in', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];

      // The catalogue lists them in the registry's order; this user's dashboard
      // is in a different one. Saving must follow the user, not the registry.
      await _pumpDashboard(tester, _dashboard(record: sent, catalogueReversed: true));

      await tester.tap(find.byTooltip('Choose panels'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(
        [for (final w in sent.single['widgets'] as List) w['key']],
        ['jobs.by_status', 'jobs.upcoming', 'jobs.revenue', 'customers.added'],
      );
    });

    testWidgets('a saved order comes back the next time the tab is opened', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpHr(tester, _hr(record: sent));

      expect(_cardTitles(tester).first, 'Headcount by place');

      await tester.tap(find.byTooltip('Move “Headcount by place” later'));
      await tester.pumpAndSettle();

      // It is stored per surface, in the dashboard's own preference, because
      // this tab's panels are fixed and there is no chosen list to keep an
      // order inside.
      expect(sent.single['surface'], 'hr');

      final order = [
        for (final w in sent.single['widgets'] as List) w['key'] as String,
      ];

      expect(order.first, 'hr.onboardings_in_flight');
      expect(order[1], 'hr.headcount');

      // Reopened by somebody whose preferences now carry that order — which is
      // how it arrives, since /me brings a user's preferences with it.
      await _pumpHr(tester, _hr(savedOrder: order));

      expect(_cardTitles(tester).first, 'Onboardings in flight');
      expect(_cardTitles(tester)[1], 'Headcount by place');
    });

    /// The order outlives the thing it names, in both directions.
    testWidgets('a saved order naming a panel that is gone still draws the '
        'ones that are not', (tester) async {
      await _pumpHr(
        tester,
        _hr(
          // 'hr.payroll' was never a panel; the headcount panel is withheld by
          // the server this time, the way a narrowed role would withhold it.
          savedOrder: const [
            'hr.payroll',
            'hr.headcount',
            'hr.invitations_stalled',
          ],
          withhold: {'hr.headcount'},
        ),
      );

      // No error, and no missing card: the two names it could not place were
      // passed over, and every panel the order says nothing about is still
      // drawn — appended rather than dropped.
      expect(tester.takeException(), isNull);
      expect(_cardTitles(tester).first, 'Invitations that have stalled');
      expect(find.text('Expiring certifications'), findsOneWidget);
      expect(find.byType(DashboardCard), findsNWidgets(4));
    });
  });

  /// The grip is a 48-point target sitting inside a page that scrolls, and
  /// those two facts were in each other's way.
  ///
  /// MEASURED on a 375x700 window with `PointerDeviceKind.touch`, before this
  /// was fixed: a swipe starting on the grip moved the page 0.0, while the same
  /// swipe starting on the card body moved it 300.0. `Draggable` recognises on
  /// the first pixel of movement, so it won the gesture arena against the
  /// ListView every time — and the swipe did not rearrange anything either,
  /// because it ended over the card it started on. Every card carried a band
  /// that silently refused to scroll.
  ///
  /// Both halves are asserted here on purpose. Fixing the scroll by taking the
  /// drag away, or fixing the drag by taking the scroll away, are the two ways
  /// to make one of these tests pass while the page is still wrong.
  group('a grip a finger can find, on a page a finger can scroll (R7)', () {
    testWidgets('a finger swiping from the grip scrolls the page', (
      tester,
    ) async {
      await _pumpHr(tester, _hr(), size: const Size(375, 700));

      // Controls. A page with nothing to scroll would pass the assertion below
      // by standing still, and so would a test that swiped a page already at
      // the bottom.
      expect(_scrollPixels(tester), 0);
      expect(
        _scrollExtent(tester),
        greaterThan(300),
        reason: 'This window already fits the whole tab; nothing to scroll.',
      );

      await tester.drag(
        _gripOf('Headcount by place'),
        const Offset(0, -300),
        kind: PointerDeviceKind.touch,
      );
      await tester.pumpAndSettle();

      expect(
        _scrollPixels(tester),
        greaterThan(0),
        reason: 'The grip swallowed the swipe.',
      );

      // And a swipe is not a sideways way to rearrange anything: it finished
      // over a different card, and no card moved.
      expect(_cardTitles(tester).first, 'Headcount by place');
    });

    testWidgets('a finger held on the grip picks the card up', (tester) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpHr(tester, _hr(record: sent), size: const Size(375, 700));

      expect(_cardTitles(tester).first, 'Headcount by place');

      final felt = _recordHaptics(tester);

      final finger = await tester.startGesture(
        tester.getCenter(_gripOf('Onboardings in flight')),
        kind: PointerDeviceKind.touch,
      );

      // Control: nothing has been said to the hand yet, so the assertion after
      // the hold is about the pick-up and not about something the screen did
      // while it was loading.
      expect(felt, isEmpty);

      // Held still, which is where a swipe and a pick-up part company.
      await tester.pump(kLongPressTimeout + const Duration(milliseconds: 20));

      // The finger holding the card is also covering it, so "it is up now"
      // cannot only be something to look at.
      expect(felt, ['HapticFeedbackType.selectionClick']);

      // The hold produced the card, not a caption about it. A Tooltip's own
      // touch trigger is also a long press, and it must not be answering this
      // one.
      expect(
        find.text(
          'Drag “Onboardings in flight” onto the card whose place it should '
          'take',
        ),
        findsNothing,
      );

      await finger.moveTo(tester.getCenter(find.byType(DashboardCard).first));
      await tester.pump();
      await finger.up();
      await tester.pumpAndSettle();

      expect(_cardTitles(tester).first, 'Onboardings in flight');
      expect(_cardTitles(tester)[1], 'Headcount by place');

      // The card was carried UP the page. Had the hold leaked to the ListView
      // instead, this is the number that would have moved and the order that
      // would not have.
      expect(_scrollPixels(tester), 0);

      expect(sent, hasLength(1));
      expect(
        [for (final w in sent.single['widgets'] as List) w['key']],
        [
          'hr.onboardings_in_flight',
          'hr.headcount',
          'hr.invitations_stalled',
          'hr.starters_and_leavers',
          'hr.certifications',
        ],
      );
    });

    /// "A control a finger misses is a control a phone does not have." The box
    /// is 48 points; this is the assertion that all 48 of them drag, rather
    /// than the 18-point glyph drawn in the middle of a decorative margin.
    testWidgets('the corner of the grip is grip, not decoration', (
      tester,
    ) async {
      await _pumpHr(tester, _hr(), size: const Size(375, 700));

      final box = tester.getRect(_gripOf('Onboardings in flight'));

      expect(
        box.size,
        const Size(kMinInteractiveDimension, kMinInteractiveDimension),
      );

      final finger = await tester.startGesture(
        box.topLeft + const Offset(2, 2),
        kind: PointerDeviceKind.touch,
      );

      await tester.pump(kLongPressTimeout + const Duration(milliseconds: 20));
      await finger.moveTo(tester.getCenter(find.byType(DashboardCard).first));
      await tester.pump();
      await finger.up();
      await tester.pumpAndSettle();

      expect(_cardTitles(tester).first, 'Onboardings in flight');
    });

    /// A finger's long press had to stop being the tooltip's trigger, because
    /// it is now the drag's. That must not cost the grip its sentence: it is
    /// the only thing that says what the grip does, and it is the whole reason
    /// this control is not icon-only to somebody who cannot see it.
    testWidgets('the grip still says what it is, hovered and read aloud', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();

      await _pumpHr(tester, _hr(), size: const Size(375, 700));

      const said =
          'Drag “Headcount by place” onto the card whose place it should take';

      expect(tester.getSemantics(_gripOf('Headcount by place')).tooltip, said);

      // Control: it is not on screen yet, so the assertion after the hover is
      // about the hover and not about a caption that was always drawn.
      expect(find.text(said), findsNothing);

      final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
      addTearDown(mouse.removePointer);

      await mouse.addPointer(location: Offset.zero);
      await mouse.moveTo(tester.getCenter(_gripOf('Headcount by place')));
      await tester.pumpAndSettle();

      expect(find.text(said), findsOneWidget);

      semantics.dispose();
    });

    /// The mouse path, which is the one the owner asked for by name, and which
    /// the touch fix must not cost. `tester.drag` presses, moves and releases
    /// without a frame in between, so a mouse that had to be held down first
    /// would not move this card at all.
    testWidgets('a mouse drags a card onto another without holding it', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpDashboard(tester, _dashboard(record: sent));

      final grip = _gripOf('Customers added');

      await tester.drag(
        grip,
        tester.getCenter(find.byType(DashboardCard).first) -
            tester.getCenter(grip),
        kind: PointerDeviceKind.mouse,
      );
      await tester.pumpAndSettle();

      expect(_cardTitles(tester).first, 'Customers added');
      expect(
        [for (final w in sent.single['widgets'] as List) w['key']],
        ['customers.added', 'jobs.by_status', 'jobs.upcoming', 'jobs.revenue'],
      );
    });

    /// "Immediate" is not a figure of speech, and this is the assertion that
    /// keeps it literal. A mouse does not drag-scroll — Flutter's own scroll
    /// behaviour leaves mice out of `dragDevices` — so nothing contests the
    /// press and the card is in hand on the press itself, with no travel and
    /// no wait.
    ///
    /// It is also what catches a held drag that has quietly been handed mice
    /// as well as fingers: two recognizers would then be contesting one press,
    /// and the card would sit there until one of them won.
    testWidgets('a mouse has the card the moment it presses', (tester) async {
      await _pumpHr(tester, _hr(), size: const Size(375, 700));

      // Control: one of this title, drawn in the card's own header. The
      // assertion below is about a SECOND one appearing.
      expect(find.text('Onboardings in flight'), findsOneWidget);

      final mouse = await tester.startGesture(
        tester.getCenter(_gripOf('Onboardings in flight')),
        kind: PointerDeviceKind.mouse,
      );

      // One frame. Not a moved pointer, not an elapsed delay — a frame.
      await tester.pump();

      expect(
        find.text('Onboardings in flight'),
        findsNWidgets(2),
        reason: 'The preview is not following the pointer: nothing was picked '
            'up by the press alone.',
      );

      await mouse.up();
      await tester.pumpAndSettle();
    });

    /// The stylus, which is where `triggerMode: manual` stops being tidiness.
    ///
    /// The grip's tooltip and its delayed drag both wait 500ms, and the comment
    /// on `triggerMode` used to say the drag wins that race by tree order — and
    /// that "no test can tell the two apart, which is the point". Both halves
    /// were wrong, and a held pen is what shows it.
    ///
    /// Tooltip's trigger recognizer excludes a MOUSE and includes a STYLUS. The
    /// delayed drag listener is registered for touch only. So on a stylus there
    /// is no race at all: without `manual` the tooltip's long press wins
    /// outright, a caption appears, and the card is never picked up. That line
    /// is load-bearing on this one input, and it was nearly removed as debt.
    testWidgets('a held stylus picks the card up and shows no caption', (
      tester,
    ) async {
      await _pumpHr(tester, _hr(), size: const Size(375, 700));

      // Control, as in the mouse test above: one of this title to begin with,
      // drawn in the card's own header.
      expect(find.text('Onboardings in flight'), findsOneWidget);

      final pen = await tester.startGesture(
        tester.getCenter(_gripOf('Onboardings in flight')),
        kind: PointerDeviceKind.stylus,
      );

      // One frame first: a pen must NOT have the card yet, or it is back in
      // the immediate set and the dead band comes with it.
      await tester.pump();
      expect(find.text('Onboardings in flight'), findsOneWidget);

      await tester.pump(kLongPressTimeout + const Duration(milliseconds: 20));

      expect(
        find.textContaining('Drag “'),
        findsNothing,
        reason:
            'A caption came up under the held pen instead of the card. The '
            'tooltip won the press, which is what happens the moment '
            'triggerMode stops being manual — and on a stylus nothing else '
            'is contesting it.',
      );

      expect(
        find.text('Onboardings in flight'),
        findsNWidgets(2),
        reason:
            'The pen held the grip and nothing was picked up, so a stylus '
            'cannot rearrange the dashboard at all.',
      );

      await pen.up();
      await tester.pumpAndSettle();
    });
  });

  group('reachable without a mouse (R7)', () {
    /// Focus a move button the way Tab would, then press Enter. If this only
    /// worked from a tap, a keyboard user could not rearrange their dashboard
    /// at all and a phone build would be worse.
    testWidgets('pressing Enter on a focused move button moves the card', (
      tester,
    ) async {
      await _pumpDashboard(tester, _dashboard());

      expect(_cardTitles(tester).first, 'Jobs by status');

      final button = tester.widget<IconButton>(
        find.ancestor(
          of: find.byTooltip('Move “Jobs by status” later'),
          matching: find.byType(IconButton),
        ).first,
      );

      button.focusNode!.requestFocus();
      await tester.pump();

      expect(button.focusNode!.hasFocus, isTrue, reason: 'Tab could not land here.');

      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pumpAndSettle();

      expect(_cardTitles(tester).first, 'Upcoming jobs');
      expect(_cardTitles(tester)[1], 'Jobs by status');
    });

    /// Walking a card from the bottom of the page to the top means pressing the
    /// same button several times. Focus dropped on the first press sends Tab
    /// back to the top of the document and the walk cannot be finished.
    testWidgets('focus follows the card it just moved', (tester) async {
      await _pumpDashboard(tester, _dashboard());

      await tester.tap(find.byTooltip('Move “Customers added” earlier'));
      await tester.pumpAndSettle();

      expect(_cardTitles(tester)[2], 'Customers added');

      final focused = tester
          .widgetList<IconButton>(find.byType(IconButton))
          .where((button) => button.focusNode?.hasFocus == true)
          .toList();

      expect(focused, hasLength(1), reason: 'Focus was dropped by the move.');
      expect(focused.single.tooltip, 'Move “Customers added” earlier');
    });

    testWidgets('a dashboard nobody may rearrange draws no controls at all', (
      tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: const Scaffold(
            body: DashboardGrid(
              panels: [
                DashboardPanel(
                  key: 'one',
                  title: 'One',
                  icon: Icons.circle,
                  body: Text('Something'),
                ),
              ],
            ),
          ),
        ),
      );

      // Not a disabled control, which invites somebody to work out why it does
      // nothing.
      expect(find.byType(IconButton), findsNothing);
    });
  });

  group('the order, read defensively', () {
    test('a panel the order says nothing about is appended, not dropped', () {
      expect(
        panelsInSavedOrder(const ['a', 'b', 'c'], const ['c']),
        ['c', 'a', 'b'],
      );
    });

    test('a name nothing draws any more is passed over', () {
      expect(
        panelsInSavedOrder(const ['a', 'b'], const ['gone', 'b', 'a']),
        ['b', 'a'],
      );
    });

    test('a name stored twice draws one card, not two', () {
      expect(
        panelsInSavedOrder(const ['a', 'b'], const ['b', 'b', 'a']),
        ['b', 'a'],
      );
    });

    test('never having chosen an order is the order it was built in', () {
      expect(panelsInSavedOrder(const ['a', 'b'], const []), ['a', 'b']);
    });

    /// This is JSON out of a preferences blob an older version of the app wrote.
    /// A dashboard that threw because of what is in it would be a screen nobody
    /// could open and nobody could fix.
    test('a stored order of the wrong shape is ignored rather than thrown on', () {
      expect(savedPanelOrder(const {}, 'hr'), isEmpty);
      expect(savedPanelOrder(const {'dashboard.order': 'hr'}, 'hr'), isEmpty);
      expect(savedPanelOrder(const {'dashboard.order': {'hr': 7}}, 'hr'), isEmpty);
      expect(
        savedPanelOrder(const {
          'dashboard.order': {
            'hr': ['hr.headcount', 42, null],
          },
        }, 'hr'),
        ['hr.headcount'],
      );
    });
  });
}

// ── reading the screen ─────────────────────────────────────────────

List<double> _cardHeights(WidgetTester tester) => tester
    .renderObjectList<RenderBox>(find.byType(DashboardCard))
    .map((box) => box.size.height)
    .toList();

/// The card titles, in the order they are drawn.
List<String> _cardTitles(WidgetTester tester) => [
  for (final card in tester.widgetList<DashboardCard>(find.byType(DashboardCard)))
    card.title,
];

/// The grip in the card called [title].
///
/// Found by the sentence it says rather than by widget type, because that
/// sentence is what a screen reader reads off it and what a pointer hovering
/// sees — so a grip this finder cannot reach is a grip a person cannot either.
Finder _gripOf(String title) =>
    find.byTooltip('Drag “$title” onto the card whose place it should take');

/// How far down the dashboard's own scroll view has been moved.
ScrollPosition _scroll(WidgetTester tester) => tester
    .state<ScrollableState>(
      find
          .ancestor(
            of: find.byType(DashboardGrid),
            matching: find.byType(Scrollable),
          )
          .first,
    )
    .position;

double _scrollPixels(WidgetTester tester) => _scroll(tester).pixels;

/// Collects the haptics the app asks the device for, in order.
List<String> _recordHaptics(WidgetTester tester) {
  final felt = <String>[];

  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async {
      if (call.method == 'HapticFeedback.vibrate') {
        felt.add(call.arguments as String);
      }

      return null;
    },
  );

  addTearDown(
    () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    ),
  );

  return felt;
}

double _scrollExtent(WidgetTester tester) => _scroll(tester).maxScrollExtent;

// ── driving the screens ────────────────────────────────────────────

Future<void> _pumpDashboard(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 3000),
}) async {
  await _pump(
    tester,
    handler,
    size,
    (api, session) => DashboardScreen(api: api, session: session),
  );
}

Future<void> _pumpHr(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 3000),
}) async {
  await _pump(
    tester,
    handler,
    size,
    (api, session) => DashboardTab(api: api, session: session),
  );
}

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler,
  Size size,
  Widget Function(ApiClient, Session) screen,
) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      // A fresh key every time, so pumping a second screen of the same type is
      // somebody OPENING it again rather than the same State carrying on. A
      // test that reopens a tab to check what it read back from preferences
      // otherwise passes on the state it left behind — which is exactly the
      // reading that would hide the preference never being read at all.
      home: Scaffold(body: KeyedSubtree(key: UniqueKey(), child: screen(api, session))),
    ),
  );
  await tester.pumpAndSettle();
}

// ── the servers ────────────────────────────────────────────────────

/// A main dashboard of four panels: two short, two long, in that pairing, so
/// that a row of unequal cards is what the layout is asked to cope with.
MockClientHandler _dashboard({
  Set<String>? only,
  List<Map<String, dynamic>>? record,
  int saveStatus = 200,
  Completer<void>? held,
  bool catalogueReversed = false,
}) {
  final panels = [
    {
      'key': 'jobs.by_status',
      'label': 'Jobs by status',
      'options': <String, dynamic>{},
      'data': {
        'rows': [
          {'status': 'scheduled', 'total': 2},
        ],
      },
    },
    {
      'key': 'jobs.upcoming',
      'label': 'Upcoming jobs',
      'options': <String, dynamic>{},
      'data': {
        'rows': [
          for (var i = 0; i < 4; i++)
            {
              'number': 'J-$i',
              'title': 'Sweep $i',
              'scheduled_for': '2026-09-0${i + 1}T09:00:00+00:00',
              'customer': 'Somebody',
            },
        ],
      },
    },
    {
      'key': 'jobs.revenue',
      'label': 'Revenue',
      'options': {
        'statuses': ['completed'],
        'days': 30,
      },
      'data': {
        'rows': [
          {'currency': 'USD', 'amount_minor': 12345, 'jobs': 3},
        ],
      },
    },
    {
      'key': 'customers.added',
      'label': 'Customers added',
      'options': <String, dynamic>{},
      'data': {'total': 4},
    },
  ].where((panel) => only == null || only.contains(panel['key'])).toList();

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      record?.add(jsonDecode(request.body) as Map<String, dynamic>);

      if (held != null) await held.future;

      if (saveStatus != 200) return _json({'message': 'Nothing doing.'}, saveStatus);

      return _json({'widgets': jsonDecode(request.body)['widgets']});
    }

    if (path.endsWith('/dashboard')) return _json({'panels': panels});

    if (path.endsWith('/dashboard/catalogue')) {
      return _json({
        'widgets': [
          // The registry's order, which is not this user's. A save that
          // followed THIS list would reverse their dashboard.
          for (final panel in catalogueReversed ? panels.reversed : panels)
            {
              'key': panel['key'],
              'label': panel['label'],
              'options': <String, dynamic>{},
            },
        ],
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// The HR tab's four widgets, with a saved order optionally already in this
/// user's preferences — which is where /me carries it from.
MockClientHandler _hr({
  List<String>? savedOrder,
  Set<String> withhold = const {},
  List<Map<String, dynamic>>? record,
}) {
  final panels = [
    {
      'key': 'hr.headcount',
      'label': 'Headcount by place',
      'data': {
        'rows': [
          {'title': 'Everdoors', 'total': 1},
        ],
        'total': 1,
        'places': 1,
      },
    },
    {
      'key': 'hr.onboardings_in_flight',
      'label': 'Onboardings in flight',
      'data': {'rows': <Map<String, Object?>>[], 'total': 0, 'oldest_started_at': null},
    },
    {
      'key': 'hr.invitations_stalled',
      'label': 'Invitations that have stalled',
      'data': {'rows': <Map<String, Object?>>[], 'total': 0, 'sent_total': 0},
    },
    {
      'key': 'hr.starters_and_leavers',
      'label': 'Starting and leaving',
      'data': {
        'rows': [
          {'title': 'Joined', 'total': 1},
        ],
        'total': 1,
        'days': 30,
        'period_applies_to': ['Joined'],
        'people': <Map<String, Object?>>[],
        'more_people': 0,
      },
    },
  ].where((panel) => !withhold.contains(panel['key'])).toList();

  final me = {
    ..._me,
    if (savedOrder != null)
      'preferences': {
        'dashboard.order': {'hr': savedOrder},
      },
  };

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      record?.add(jsonDecode(request.body) as Map<String, dynamic>);

      return _json(jsonDecode(request.body));
    }

    if (path.endsWith('/hr/dashboard')) return _json({'panels': panels});

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view', 'jobs.view', 'customers.view'],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
