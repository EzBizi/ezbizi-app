import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_tab.dart';
import 'package:ezbizi_client/screens/scheduling/availability_pane.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Call desk — layout 1: three panes, stack below 600, 10DLC send disabled.
void main() {
  testWidgets('the desk is not Coming soon', (tester) async {
    await _pump(tester, numbers: const []);

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('No phone lines yet. Add one in Settings.'), findsOneWidget);
  });

  /// The Dial face is HISTORY beside the pad, not the roster.
  ///
  /// The column used to hold colleagues, which is a list of people you are
  /// mostly not trying to ring while standing at a keypad. What somebody
  /// reaches for there is a number they were just speaking to, and that was
  /// two tabs away in Records. The roster moved to its own sub-tab, where it
  /// is what you went looking for.
  testWidgets('Dial puts recent calls beside the pad, not the roster', (
    tester,
  ) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(Dialog), findsNothing);

    expect(find.byKey(const Key('phone-call-history')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(
      find.byKey(const Key('phone-call-teammates')),
      findsNothing,
      reason: 'the roster is a sub-tab now, not a column on the pad',
    );

    expect(find.text('Recent calls'), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);
    expect(find.textContaining('CT main'), findsWidgets);
    expect(find.text('Call'), findsOneWidget);

    final history = tester.getRect(find.byKey(const Key('phone-call-history')));
    final pad = tester.getRect(find.byKey(const Key('phone-call-pad')));
    expect(history.left, lessThan(pad.left));
    expect((history.top - pad.top).abs(), lessThan(8));
  });


  /// A row says who took the call, and when it happened.
  ///
  /// The list was ordered by time and printed none of it, and an inbound call
  /// rings a whole ring group so "Incoming" named nobody. Jose asked for both:
  /// a customer ringing back wants the person they spoke to, and "did they
  /// call this morning or last week" should not need the Records tab.
  testWidgets('a recent call names who answered it and when', (tester) async {
    await _pump(tester, size: const Size(900, 800));

    expect(
      find.textContaining('Adeline Ruiz'),
      findsOneWidget,
      reason: 'the row says a call was answered without saying by whom',
    );

    // Not today, so the call sits under its day rather than beside a bare
    // clock — the only reading that tells a week-old call from this morning.
    // The day is a heading now (UI queue 35), and the row keeps the clock.
    expect(find.text('Tue 8 Sep'), findsOneWidget);
  });

  /// What the caller pressed at the menu (Jose, 2026-10-05: "what client
  /// pressed on the ivr menu"), on the row it belongs to and no other.
  testWidgets('a recent call says what the caller pressed', (tester) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      calls: [
        for (final (id, from, extra) in [
          ('ca-1', '+13055550101', {'menu_key': '2'}),
          ('ca-2', '+13055550102', {'extension_dialed': '0101'}),
          ('ca-3', '+13055550103', <String, String>{}),
        ])
          {
            'id': id,
            'number_id': 'n-1',
            'direction': 'inbound',
            'from_e164': from,
            'to_e164': '+12035550142',
            'status': 'completed',
            'created_at': '2026-09-08T17:00:00+00:00',
            ...extra,
          },
      ],
    );

    expect(find.textContaining('pressed 2'), findsOneWidget);
    expect(find.textContaining('dialed ext 0101'), findsOneWidget);
  });

  /// The pin survives leaving Communication and coming back.
  ///
  /// ── What Jose reported ───────────────────────────────────────
  ///
  /// "The pin to keep the right sidebar open between calls works, but as soon
  /// as u leave the communcation tab and come back it, it's gone, and the pin
  /// is removed."
  ///
  /// It lived in this screen's State, and the State is built fresh every time
  /// Communication opens — so "keep this open between calls" meant "until you
  /// look at something else", which is the opposite of what a pin is for.
  ///
  /// A fresh CallTab with the preference already set is exactly the state of
  /// coming back to the tab, which is why the test is written this way round
  /// rather than by tapping the pin and rebuilding.
  testWidgets('a pinned customer panel is still pinned on the way back', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(1280, 900),
      preferences: const {'phone.customer_panel_pinned': true},
    );

    expect(
      find.byIcon(Icons.push_pin),
      findsOneWidget,
      reason: 'the pin came back unpinned, which is what Jose reported',
    );
    expect(find.text('Customer'), findsOneWidget);
  });

  /// And somebody who has never pinned it does not get it pinned.
  ///
  /// The control. Without it, a panel that was simply always open would pass
  /// the test above and take a column off every desk in the company.
  testWidgets('an unpinned panel stays out of the way', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    expect(find.byIcon(Icons.push_pin), findsNothing);
  });

  /// Clicking the pin writes it where the next visit will look.
  ///
  /// Asserts the PATCH, not the icon: an icon that flips and a preference that
  /// is never saved look identical until you leave the tab, which is the whole
  /// defect.
  testWidgets('pinning is saved as a preference', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(1280, 900), seen: seen);

    // The pin lives on the panel, and the panel only appears when there is
    // somebody to show — so there has to be a number typed before there is a
    // pin to click.
    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.byTooltip('Keep this open between calls'));

    final saved = seen.lastWhere(
      (r) => r.url.path.endsWith('/me/preferences'),
      orElse: () => throw StateError('nothing was saved'),
    );

    expect(
      jsonDecode(saved.body)['preferences'],
      containsPair('phone.customer_panel_pinned', true),
    );
  });

  /// The reader can drag the line between two columns.
  ///
  /// Every fixed split is wrong for two of the three things somebody does at
  /// this screen: working a list wants the history wide, taking a call wants
  /// the customer wide, dialling wants neither.
  testWidgets('the divider beside the history resizes it', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    final before = tester
        .getRect(find.byKey(const Key('phone-call-history')))
        .width;

    await tester.drag(
      find.byKey(const Key('phone-split-history')),
      const Offset(120, 0),
    );
    await tester.pumpAndSettle();

    final after = tester
        .getRect(find.byKey(const Key('phone-call-history')))
        .width;

    // 120 asked for, 20 of it eaten by the drag recogniser's slop.
    expect(
      after,
      greaterThan(before + 90),
      reason: 'dragging the divider right did not widen the history',
    );
  });

  /// And it stops at the floor rather than closing the column.
  ///
  /// A pane dragged to nothing has no edge left to grab, so it never comes
  /// back — the drag deletes a column and calls it a preference.
  testWidgets('a column cannot be dragged shut', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    await tester.drag(
      find.byKey(const Key('phone-split-history')),
      const Offset(-2000, 0),
    );
    await tester.pumpAndSettle();

    expect(
      tester.getRect(find.byKey(const Key('phone-call-history'))).width,
      greaterThan(100),
      reason: 'the history column was dragged shut and cannot be reopened',
    );
  });

  /// The customer column is not there with nobody to show.
  ///
  /// It is the widest of the three, and empty it holds a sentence explaining
  /// that it is empty — half a window given to "type a number to see who it
  /// is" while the pad and the history share the rest.
  testWidgets('the customer column stays away until there is somebody', (
    tester,
  ) async {
    await _pump(tester, size: const Size(1280, 900));

    expect(find.byKey(const Key('phone-call-person')), findsNothing);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('phone-call-person')),
      findsOneWidget,
      reason: 'a number was typed and the customer column did not appear',
    );
  });

  /// And it can be kept, because a panel that opens and closes under your
  /// hands while you work a list is worse than one that is always there.
  testWidgets('the customer column can be pinned open', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.byTooltip('Keep this open between calls'));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '',
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('phone-call-person')),
      findsOneWidget,
      reason: 'pinned, and it went away anyway',
    );
  });

  /// The history opens NARROW, and the leftover goes to the pad.
  ///
  /// It used to take everything the pad did not want, so with no customer
  /// column a 1600px desk gave 1199px to a list of phone numbers - which is
  /// what it looked like, and what got reported. The pad centres its keypad
  /// in whatever it is given, so the spare width is harmless there and would
  /// not have been harmless here.
  testWidgets('the history opens narrow, not on whatever is left', (
    tester,
  ) async {
    await _pump(tester, size: const Size(1600, 900));

    final history = tester.getRect(find.byKey(const Key('phone-call-history')));
    final pad = tester.getRect(find.byKey(const Key('phone-call-pad')));

    // 1591 after the one grabbable divider.
    expect((history.width - 1591 * 0.26).abs(), lessThan(1.5));
    expect(history.width, lessThan(pad.width));
  });

  /// The roster is the Team face of the same widget, so the softphone it
  /// holds is not torn down every time somebody looks at their colleagues.
  testWidgets('the Team face shows the roster and no keypad', (tester) async {
    await _pump(tester, view: CallView.team, size: const Size(900, 800));

    expect(find.text('Maya Chen'), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.byKey(const Key('phone-call-history')), findsNothing);
  });

  /// Do not disturb is RED, and available is not.
  ///
  /// Jose asked for it off a screenshot: the dot was drawn in
  /// `onSurfaceVariant`, which is the colour of secondary TEXT — so the one
  /// state that means "this person will not be rung" was the quietest ink on
  /// the row and read as switched off rather than as unavailable.
  ///
  /// The second half is the control. Asserting only that a red dot exists
  /// passes on a build that draws every dot red, which is a worse screen than
  /// the one being fixed.
  ///
  /// The dot became a status pill when the roster became a table (Mia,
  /// 2026-10-02); the ruling it pinned is unchanged, so the test now reads the
  /// pill's tone instead of the dot's colour.
  testWidgets('a teammate on Do not disturb gets a red pill', (tester) async {
    await _pump(tester, view: CallView.team, size: const Size(900, 800));

    Tone pillBeside(String name) {
      final row = find.ancestor(
        of: find.text(name),
        matching: find.byKey(ValueKey('teammate-${switch (name) {
          'Bruno Vega' => 'u-3',
          _ => 'u-1',
        }}')),
      );

      return tester
          .widget<StatusPill>(
            find.descendant(of: row, matching: find.byType(StatusPill)).first,
          )
          .tone;
    }

    expect(
      pillBeside('Bruno Vega'),
      Tone.bad,
      reason: 'Do not disturb is not drawn in red',
    );

    expect(
      pillBeside('Jose Silva'),
      isNot(Tone.bad),
      reason: 'every pill is red, so the colour says nothing',
    );
  });

  /// Tiles over the roster (Mia, 2026-10-02): each counts the whole desk, and
  /// tapping one leaves only the people it counted.
  testWidgets('the Team tiles count the desk and filter the roster', (
    tester,
  ) async {
    await _pump(tester, view: CallView.team, size: const Size(1000, 800));

    String countOn(String key) {
      final tile = find.byKey(Key('team-tile-$key'));
      return tester
          .widgetList<Text>(find.descendant(of: tile, matching: find.byType(Text)))
          .elementAt(1)
          .data!;
    }

    expect(countOn('all'), '3');
    expect(countOn('available'), '1', reason: 'registered reads as Available');
    expect(countOn('on_call'), '1');
    expect(countOn('dnd'), '1');
    expect(countOn('unread'), '2', reason: 'messages, not people');

    await _tap(tester, find.byKey(const Key('team-tile-dnd')));
    expect(find.text('Bruno Vega'), findsOneWidget);
    expect(find.text('Maya Chen'), findsNothing);
    expect(find.text('Jose Silva'), findsNothing);

    await _tap(tester, find.byKey(const Key('team-tile-unread')));
    expect(find.text('Maya Chen'), findsOneWidget);
    expect(find.text('2 new'), findsOneWidget);
    expect(find.text('Bruno Vega'), findsNothing);

    // Tapping the picked tile again clears it.
    await _tap(tester, find.byKey(const Key('team-tile-unread')));
    expect(find.text('Bruno Vega'), findsOneWidget);
    expect(find.text('Jose Silva'), findsOneWidget);
  });

  testWidgets('the pad stops shrinking before the other two do', (
    tester,
  ) async {
    // Below about 1050 of desk width the ratio alone would give the pad 224px,
    // and a keypad at 224px stops being tappable. A roster or a message thread
    // just gets narrower and stays usable, so the pad is the only one of the
    // three with a hard floor.
    await _pump(tester, size: const Size(900, 800));

    final pad = tester.getRect(find.byKey(const Key('phone-call-pad')));
    expect(
      pad.width,
      greaterThanOrEqualTo(280),
      reason:
          'A bare 25% of 898 is 224px. The floor is what keeps the keypad '
          'usable at the widths where the ratio does not fit.',
    );
  });

  /// ── What this test used to pin, and why it was wrong ──────────
  ///
  /// It asserted a "Back to the team" arrow on the pad and walked through it
  /// into a roster inside the Dial pane. That arrow went FORWARD into a list,
  /// on a screen whose only way out was to pick somebody — which selected them
  /// as well as leaving. Jose reported it on 2026-09-10 as getting stuck on
  /// the team screen, and he was right: Team has been its own sub-tab beside
  /// Dial since the tabs were split, so the copy inside Dial was a duplicate
  /// reachable only through a trap.
  ///
  /// The test was green throughout, because it was pinning the behaviour
  /// rather than judging it.
  testWidgets('at 375 the pad has no way into a roster it does not own', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 800));

    // Jose's 4 (UI queue 35): the calls first, the keypad a button away.
    expect(find.byKey(const Key('phone-call-history')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);

    await _tap(tester, find.byKey(const Key('phone-open-keypad')));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-person')), findsNothing);
    expect(find.text('Calling as'), findsOneWidget);

    expect(
      find.byTooltip('Back to the team'),
      findsNothing,
      reason: 'the pad still offers the arrow that strands somebody in a '
          'roster the Team tab already holds',
    );
    expect(find.byKey(const Key('phone-call-teammates')), findsNothing);

    // The CONTROL: the customer pane is still reachable and still comes back,
    // so the pad has not simply lost its navigation.
    await _tap(tester, find.byTooltip('To the customer'));

    expect(find.byKey(const Key('phone-call-person')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.byTooltip('Back to the pad'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the pad'));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);

    // And the pad goes back to the calls it was opened from.
    await _tap(tester, find.byTooltip('Back to recent calls'));
    expect(find.byKey(const Key('phone-call-history')), findsOneWidget);
  });

  testWidgets(
    'SMS send is disabled with the 10DLC sentence when the line cannot send',
    (tester) async {
      await _pump(tester, size: const Size(900, 800));

      // The message box lives in the customer column, which stays away until
      // there is somebody to show. Typing the number is what opens it.
      await tester.enterText(
        find.widgetWithText(TextField, 'Name or number'),
        '305 724 2199',
      );
      await tester.pumpAndSettle();

      expect(
        find.text('This line cannot send texts until messaging is approved.'),
        findsOneWidget,
      );

      final send = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Send'),
      );
      expect(send.onPressed, isNull);
      // The box the refusal is about sits under the timeline of calls and
      // texts, which replaced the "Messages" list (UI queue 35).
      expect(find.widgetWithText(TextField, 'Message'), findsOneWidget);
      expect(find.byType(Dialog), findsNothing);
    },
  );

  /// Paging a team, and being told who did not hear it.
  ///
  /// Both halves are one case on purpose: a page that reached nine of twelve
  /// handsets is indistinguishable from a complete one at the speaking end,
  /// and the names are the only thing that tells them apart.
  testWidgets('a team is paged, and whoever missed it is named', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);
    final seen = <http.Request>[];

    await _pump(
      tester,
      device: device,
      seen: seen,
      size: const Size(900, 800),
      groups: const [
        {'id': 'rg-1', 'connection_id': 'pc-1', 'name': 'Front desk'},

        // Another company's team. A page is a conference on ONE Twilio
        // account, so this one is not reachable from this line and offering
        // it would be a button that fails after the announcement started.
        {'id': 'rg-9', 'connection_id': 'pc-9', 'name': 'Warehouse'},
      ],
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Page'));
    expect(find.text('Warehouse'), findsNothing);

    await _tap(tester, find.widgetWithText(MenuItemButton, 'Front desk'));

    final posted = seen.singleWhere(
      (r) => r.method == 'POST' && r.url.path.endsWith('/phone/page'),
    );
    expect(jsonDecode(posted.body)['ring_group_id'], 'rg-1');

    // The speaker's microphone is at the desk, so their leg is DIALLED rather
    // than called — the same shape as picking up a parked caller.
    expect(device.connectedTo, 'page:ezbizi-page-rg-1-1789500000');

    expect(find.textContaining('Bruno Vega did not get it'), findsOneWidget);
  });

  /// The control. Without it the case above passes on a build that paints
  /// Page unconditionally, which on an account with no teams is a button
  /// whose menu is empty.
  testWidgets('no Page button on a line with no team', (tester) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.widgetWithText(OutlinedButton, 'Page'), findsNothing);
  });

  testWidgets('incoming is an in-page banner, not a dialog', (tester) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    device.ring('+12035550199');
    await tester.pump();

    expect(find.textContaining('Incoming from +1 (203) 555-0199'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Accept'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Decline'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Decline'));

    expect(device.declined, isTrue);
    expect(find.textContaining('Incoming from'), findsNothing);
  });

  testWidgets('empty pad does not request messages without a peer', (
    tester,
  ) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    expect(
      seen.where(
        (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
      ),
      isEmpty,
    );
    expect(find.text('Thursday morning still good?'), findsNothing);

    // The empty state IS the absent column now. That sentence used to sit in
    // the widest pane on the desk, explaining that the pane had nothing in it
    // — half a window given over to saying so.
    expect(find.byKey(const Key('phone-call-person')), findsNothing);
    expect(find.text('Type a number to see who it is.'), findsNothing);
  });

  testWidgets('a plus number loads the thread with that peer', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+12035550199',
    );
    await tester.pumpAndSettle();

    final gets = seen.where(
      (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
    );
    expect(gets, isNotEmpty);
    expect(gets.last.url.queryParameters['peer'], '+12035550199');
    expect(find.text('Thursday morning still good?'), findsOneWidget);
  });

  /// Every message says who said it.
  ///
  /// The panel drew each one as the same box in a column, told apart only by
  /// a tint at half opacity, and Jose reported it as not being able to see
  /// who a message was sent to. Both directions have to be on screen for this
  /// to mean anything — a test with one inbound message passes whether or not
  /// the app can tell the two apart.
  /// Nobody waiting draws nothing at all.
  ///
  /// The empty state is the one this strip is in almost always, and a
  /// permanent "Parked calls" panel with nothing in it is a box people learn
  /// to stop reading — which costs exactly the thing it exists for, being
  /// noticed on the rare day somebody IS holding.
  testWidgets('no parked callers means no strip', (tester) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.textContaining('is waiting'), findsNothing);
    expect(find.text('Pick up'), findsNothing);
  });

  /// And somebody waiting is named, with the colleague who set them down.
  ///
  /// Named rather than counted: "2 parked calls" is a fact you then have to
  /// open something to act on, and the number they are holding for is what
  /// decides whether this one is yours.
  testWidgets('a parked caller is named, with who parked them', (tester) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      parked: const [
        {
          'id': 'pk-1',
          'from_e164': '+13055550100',
          'parked_by_name': 'Bruno Vega',
          'pick_up': 'park:ezbizi-park-pk-1',
        },
      ],
    );

    expect(find.textContaining('+1 (305) 555-0100 is waiting'), findsOneWidget);
    expect(find.textContaining('parked by Bruno Vega'), findsOneWidget);
    expect(find.text('Pick up'), findsOneWidget);
  });

  /// A call ringing somewhere else names the LINE as well as the caller.
  ///
  /// "+1 305… is ringing" says a stranger is calling somebody. "+1 305… is
  /// ringing +1 203…" says whether it is any of your business — which is the
  /// decision somebody is making when they look at this row, since the whole
  /// point of pickup is calls that are NOT ringing them.
  testWidgets('a call ringing elsewhere names the line it is ringing', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      ringing: const [
        {
          'id': 'rg-1',
          'from_e164': '+13055550100',
          'to_e164': '+12035550142',
        },
      ],
    );

    expect(
      find.textContaining('+1 (305) 555-0100 is ringing +1 (203) 555-0142'),
      findsOneWidget,
    );
    expect(find.text('Pick up'), findsOneWidget);
  });

  /// Both kinds share one strip, and both are reachable in it.
  ///
  /// The control for folding them together: a bug that rendered only the
  /// first list would pass every single-kind test above.
  testWidgets('a ringing call and a parked one both appear', (tester) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      ringing: const [
        {'id': 'rg-1', 'from_e164': '+13055550100', 'to_e164': '+12035550142'},
      ],
      parked: const [
        {
          'id': 'pk-1',
          'from_e164': '+17865550111',
          'parked_by_name': 'Bruno Vega',
          'pick_up': 'park:ezbizi-park-pk-1',
        },
      ],
    );

    expect(find.textContaining('is ringing'), findsOneWidget);
    expect(find.textContaining('is waiting'), findsOneWidget);
    expect(find.text('Pick up'), findsNWidgets(2));
  });

  testWidgets('a message names who sent it', (tester) async {
    await _pump(tester, size: const Size(900, 800));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+12035550199',
    );
    await tester.pumpAndSettle();

    expect(find.text('Thursday morning still good?'), findsOneWidget);
    expect(find.text('Yes, nine works.'), findsOneWidget);

    // Ours is labelled, theirs carries their number. Looked for inside the
    // timeline: the heading and the card name the number too, formatted now
    // (UI queue 35), so a page-wide find would count them.
    final timeline = find.byKey(const Key('person-timeline'));
    expect(
      find.descendant(of: timeline, matching: find.text('You')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: timeline, matching: find.text('+1 (203) 555-0199')),
      findsOneWidget,
    );
  });

  testWidgets('pad digits load the thread as E.164', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '12035550199',
    );
    await tester.pumpAndSettle();

    final gets = seen.where(
      (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
    );
    expect(gets, isNotEmpty);
    expect(gets.last.url.queryParameters['peer'], '+12035550199');
  });

  testWidgets('Accept puts presence on_call', (tester) async {
    final device = _FakeDevice();
    final seen = <http.Request>[];
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800), seen: seen);

    seen.clear();
    device.ring('+12035550199');
    await tester.pump();

    await _tap(tester, find.widgetWithText(FilledButton, 'Accept'));

    expect(device.accepted, isTrue);
    expect(
      seen.any((r) {
        if (r.method != 'PUT' || !r.url.path.endsWith('/phone/presence')) {
          return false;
        }
        final body = jsonDecode(r.body) as Map<String, dynamic>;
        return body['state'] == 'on_call';
      }),
      isTrue,
    );
  });

  testWidgets('a colleague is called from their own row', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    // The roster is the Team face now, not a column on the pad.
    await _pump(
      tester,
      device: device,
      view: CallView.team,
      size: const Size(900, 800),
    );

    // Calling a colleague is a control ON the colleague now, not a mode on
    // the pad that did nothing until a row had already been selected.
    await _tap(tester, find.byTooltip('Call Maya Chen'));

    expect(device.connectedTo, 'client:u2');
    expect(device.callerId, '+12035550142');
  });

  testWidgets('allowed SMS enables Send when there is a peer and a body', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      numbers: [
        {..._number, 'sms_outbound_allowed': true},
      ],
    );

    // The customer column is where the message box lives, and it stays away
    // until there is somebody to show. Typing the number is what opens it.
    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    expect(
      find.text('This line cannot send texts until messaging is approved.'),
      findsNothing,
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+12035550199',
    );
    await tester.enterText(find.widgetWithText(TextField, 'Message'), 'Hello');
    await tester.pumpAndSettle();

    final send = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Send'),
    );
    expect(send.onPressed, isNotNull);
  });

  /// The keypad was a GridView that stretched to its column: each key was
  /// roughly 400 x 130 on a monitor, and twelve of them filled the desk. A dial
  /// pad is a small dense thing, and it is the least-used control here.
  testWidgets('the keypad is keypad-sized, not the whole desk', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    final key = tester.getSize(find.byKey(const ValueKey('key:5')));

    // Larger since UI queue 35 (Jose's 1): on a wide window the 56 x 46 pad
    // was a small island in empty space. Still a keypad, not the desk.
    expect(key.width, 72);
    expect(key.height, 52);

    // 44px is the touch guidance; a key that shrank below it would be a
    // regression even though it looks tidier.
    expect(key.height, greaterThanOrEqualTo(44));
  });

  testWidgets('the pad is the same size on a phone as on a monitor', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 800));

    await tester.tap(find.byKey(const Key('phone-open-keypad')));
    await tester.pumpAndSettle();

    expect(tester.getSize(find.byKey(const ValueKey('key:5'))).width, 72);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the letters are on the keys', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    expect(find.text('ABC'), findsOneWidget);
    expect(find.text('PQRS'), findsOneWidget);
  });

  /// The desktop app cannot place calls, and must say THAT rather than
  /// blaming the network.
  ///
  /// The Twilio Voice SDK is a browser script, so the Windows and macOS
  /// builds have no way to make a call at all. The desk detected this
  /// correctly — `registered` is false — but said "reload the page; if it
  /// keeps happening the network is blocking the connection", with a Try
  /// again button. That is a transient-sounding diagnosis and a retry
  /// control for a permanent platform fact, so somebody goes and looks at
  /// their firewall, finds nothing, and comes back tomorrow.
  /// The desk repeats what Twilio said, when Twilio said anything.
  ///
  /// The Windows bridge has always forwarded a reason alongside
  /// `registered: false` and the Dart side discarded it one line before the
  /// banner started guessing. Every failure therefore read as "the network is
  /// blocking the connection" — an expired token, a rejected identity and a
  /// missing audio device all sent somebody to check a firewall.
  testWidgets('the desk-down banner repeats the reason it was given', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..registered = false
      ..registrationError = 'AccessTokenExpired (20104)';

    await _pump(tester, device: device);

    expect(find.textContaining('AccessTokenExpired (20104)'), findsOneWidget);
    expect(
      find.textContaining('the network is blocking'),
      findsNothing,
      reason: 'a stated reason was replaced by a guess about the network',
    );
  });

  /// And still guesses when it was told nothing, because that is the one case
  /// the old sentence was right about.
  testWidgets('with no reason given it falls back to the network wording', (
    tester,
  ) async {
    final device = _FakeDevice()..registered = false;

    await _pump(tester, device: device);

    expect(find.textContaining('the network is blocking'), findsOneWidget);
  });

  testWidgets(
    'a build with no Voice SDK says so instead of blaming the network',
    (tester) async {
      final device = _FakeDevice()
        ..supported = false
        ..registered = false;

      await _pump(tester, device: device);

      expect(
        // It says which HALF works rather than that the app is unusable.
        // Calls can be placed from here — Twilio rings this person's own
        // phone — and only TAKING them in the app needs a browser.
        find.textContaining('ring your own phone'),
        findsOneWidget,
      );
      expect(
        find.textContaining('the network is blocking'),
        findsNothing,
        reason: 'a permanent platform limit was reported as a network fault',
      );
      expect(
        find.text('Try again'),
        findsNothing,
        reason: 'offered a retry for something that can never succeed',
      );
    },
  );

  /// And a genuine registration failure keeps the retry, because retrying is
  /// exactly what might fix it.
  testWidgets('a browser build that failed to register still offers a retry', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..supported = true
      ..registered = false;

    await _pump(tester, device: device);

    expect(find.textContaining('the network is blocking'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
  });

  /// Android's calling account is the one failure a retry cannot fix.
  ///
  /// ── Why the banner needed a second control ───────────────────────
  ///
  /// Telecom will not let the app place or accept a call until the user has
  /// switched EzBizi on under Android's Calling accounts. Pressing Try again
  /// cannot reach that screen, so on its own it is a control that can only
  /// fail — the same rule the desktop branch already follows, applied to the
  /// opposite case: offer the control that CAN succeed.
  testWidgets('a phone with no calling account is offered the way out', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..supported = true
      ..registered = false
      ..needsCallingAccount = true
      ..registrationError =
          'Android has not been given permission for this app to make and '
          'take calls. Open Calling accounts and switch EzBizi on.';

    await _pump(tester, device: device);

    expect(find.text('Open settings'), findsOneWidget);
    expect(
      find.textContaining('Calling accounts'),
      findsOneWidget,
      reason: 'the banner does not say what is wrong, only that something is',
    );

    // The BUTTON, not the flag. A control that appears and is wired to
    // nothing looks exactly like one that works, and is how a fix ships
    // having never been exercised.
    await tester.tap(find.text('Open settings'));
    await tester.pump();

    expect(device.openedCallingSettings, 1);
  });

  /// And it is not offered to anybody else.
  ///
  /// The control. Without it, a banner that always drew the button would pass
  /// the test above — and would send a Windows user, or a phone that is
  /// working, to a settings screen that has nothing to do with them.
  testWidgets('an ordinary failure is not sent to the settings screen', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..supported = true
      ..registered = false;

    await _pump(tester, device: device);

    expect(find.text('Try again'), findsOneWidget);
    expect(find.text('Open settings'), findsNothing);
  });

  /// The bug that made outbound calling fail for weeks.
  ///
  /// "305 724 2199" is how a phone number is written here. Stripping the
  /// spaces and putting a `+` in front turns it into `+3057242199`, which is
  /// not that number — the country code is missing, and +305 is not a country.
  /// Every outbound call went to it. Nothing rejected it, because it is
  /// well-formed enough to pass a length check, so it was dialled and the
  /// carrier could not route it.
  ///
  /// Three real defects were found and fixed on the way to this one and none
  /// of them was the reason. This is the assertion that would have said so on
  /// day one.
  testWidgets('a number typed the ordinary way keeps its country code', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(
      device.connectedTo,
      '+13057242199',
      reason: 'the country code was dropped, so the call went nowhere',
    );
  });

  /// A number already in E.164 says which country itself, so it is passed
  /// through rather than second-guessed.
  testWidgets('an international number is not given a country code', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+44 20 7946 0000',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(device.connectedTo, '+442079460000');
  });

  /// Too short to complete, so it is refused rather than guessed at —
  /// guessing a country code is precisely what produced +3057242199.
  testWidgets('a number too short to complete is not dialled at all', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '724 2199',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(device.connectedTo, isNull);
  });

  /// On a platform with no Voice SDK, Call still places a call.
  ///
  /// Not through the device — there is nothing to place it with — but by
  /// asking the server to ring this person's own handset and bridge them. The
  /// device must NOT be touched: calling `connect()` on the stub does nothing
  /// and returns, which is silence dressed as success.
  testWidgets('a build with no Voice SDK rings your own phone instead', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..supported = false
      ..registered = false;
    addTearDown(device.dispose);

    final seen = <http.Request>[];
    await _pump(tester, device: device, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(
      device.connectedTo,
      isNull,
      reason: 'it tried to use a device that cannot place a call',
    );

    final bridge = seen.where((r) => r.url.path.endsWith('/phone/calls/bridge'));
    expect(bridge, hasLength(1), reason: 'no bridged call was asked for');
    expect(jsonDecode(bridge.first.body)['to'], '+13057242199');
  });

  /// And a browser build still uses the device, because it can.
  testWidgets('a browser build still places the call itself', (tester) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    final seen = <http.Request>[];
    await _pump(tester, device: device, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(device.connectedTo, '+13057242199');
    expect(
      seen.where((r) => r.url.path.endsWith('/phone/calls/bridge')),
      isEmpty,
      reason: 'a browser that can place the call asked the server to instead',
    );
  });

  /// A call that fails on the device AFTER Call returned is said under Call.
  ///
  /// Jose, 2026-09-29: Call "tries to open up something then nothing
  /// happens" — the Windows page found no microphone, and nothing said so.
  testWidgets('a call that fails on the device says why under Call', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    device.onCallFailed!('No microphone found.');
    await tester.pumpAndSettle();

    expect(find.text('No microphone found.'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Call'), findsOneWidget);
  });

  /// Toula's suggestion: the caller says where they are, and the dispatcher
  /// checks availability without leaving the call — on the caller's own zip
  /// when they are a customer on file.
  testWidgets("Availability opens on the caller's zip", (tester) async {
    await _pump(
      tester,
      size: const Size(1280, 900),
      permissions: ['phone.view', 'phone.edit', 'scheduling.availability.view'],
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.byKey(const Key('dial-availability')));

    final pane = tester.widget<AvailabilityPane>(find.byType(AvailabilityPane));
    expect(pane.initialZip, '33401');
  });

  /// The control: without the Scheduling grant there is no button to press.
  testWidgets('no availability grant, no Availability button', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    expect(find.byKey(const Key('dial-availability')), findsNothing);
  });

  /// The line you are assigned to is the one you start on.
  ///
  /// The assigned line is deliberately SECOND here. The test above has it
  /// first, so it passed either way — and the bug was exactly that: the desk
  /// defaulted to `numbers.first` the moment the list loaded, which quietly
  /// defeated the "yours first" fallback further down the same file. On the
  /// real account "General Use" sorted first, so every call went out under the
  /// company's shared number unless somebody noticed the picker and changed it.
  testWidgets('the line you are assigned to is selected to begin with', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(
      tester,
      device: device,
      size: const Size(900, 800),
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12394560829',
          'label': 'General Use',
          'assigned_to_me': false,
        },
        {
          'id': 'n-2',
          'connection_id': 'cx-1',
          'e164': '+12397470324',
          'label': 'Mine',
          'assigned_to_me': true,
        },
      ],
    );

    // Reads the field rather than a dropdown's value: the picker is a search
    // box now, and what somebody sees in it IS the answer to "which line am I
    // about to appear as".
    expect(
      find.textContaining('Mine · +1 (239) 747-0324 — yours'),
      findsWidgets,
      reason: 'the desk did not open on the line assigned to this person',
    );
  });

  /// A dispatcher can choose which line they call from.
  ///
  /// "Calling as" was a LABEL: it told you which line you were about to appear
  /// as and gave you no way to change it. Somebody covering two businesses
  /// could see they were about to ring a customer as the wrong company and
  /// could do nothing about it.
  ///
  /// The line assigned to them is marked, so switching away is a thing they
  /// notice doing rather than a state they end up in.
  testWidgets('the line you call from can be changed, and yours is marked', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(
      tester,
      device: device,
      size: const Size(900, 800),
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'CT main',
          'assigned_to_me': true,
        },
        {
          'id': 'n-2',
          'connection_id': 'cx-1',
          'e164': '+13055550188',
          'label': 'Miami',
          'assigned_to_me': false,
        },
      ],
    );

    expect(find.textContaining('CT main · +1 (203) 555-0142 — yours'), findsWidgets);

    // A searchable list, not a plain dropdown: a dispatcher with a line per
    // company and per branch reads thirty of them otherwise, and the one they
    // want has a name they already know.
    await tester.enterText(find.descendant(
        of: find.byKey(const ValueKey('tour-phone-calling-as')),
        matching: find.byType(TextField),
      ), 'Miami');
    await tester.pumpAndSettle();
    await _tap(tester, find.textContaining('Miami · +1 (305) 555-0188').last);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(
      device.callerId,
      '+13055550188',
      reason: 'the call went out under the line they switched away from',
    );
  });

  /// Opening the list shows every line, without typing anything first.
  ///
  /// ── The defect ─────────────────────────────────────────────────
  ///
  /// The field holds the selected line's own description, and `optionsBuilder`
  /// is handed that text — so tapping to open searched for that exact string
  /// and matched one thing: itself. A business with thirty numbers looked like
  /// a business with one, and the picker looked like a label somebody had
  /// styled as a dropdown by mistake.
  ///
  /// The test above never saw it because it TYPES a search term first, which
  /// is the one path where the filter behaves. Jose reported it as "I should
  /// be able to choose from among all the numbers of the company".
  testWidgets('tapping the line picker offers every line, untouched', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(
      tester,
      device: device,
      size: const Size(900, 800),
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'CT main',
          'assigned_to_me': true,
        },
        {
          'id': 'n-2',
          'connection_id': 'cx-1',
          'e164': '+13055550188',
          'label': 'Miami',
          'assigned_to_me': false,
        },
        {
          'id': 'n-3',
          'connection_id': 'cx-1',
          'e164': '+19545550111',
          'label': 'Ana',
          'assigned_to_me': false,
        },
      ],
    );

    await _tap(tester, find.descendant(
        of: find.byKey(const ValueKey('tour-phone-calling-as')),
        matching: find.byType(TextField),
      ));

    // All three, including the one already selected.
    expect(find.byKey(const ValueKey('calling-as-n-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('calling-as-n-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('calling-as-n-3')), findsOneWidget);
  });

  /// A line somebody is on is shown, and refused.
  ///
  /// Hiding it would answer "why can I not see Ana's number" with silence.
  /// Offering it would put a second call on a number a colleague is mid
  /// conversation on and send the callback to them.
  testWidgets('a line already on a call says so and cannot be picked', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(
      tester,
      device: device,
      size: const Size(900, 800),
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'CT main',
          'assigned_to_me': true,
        },
        {
          'id': 'n-2',
          'connection_id': 'cx-1',
          'e164': '+19545550111',
          'label': 'Ana',
          'assigned_to_me': false,
          'on_call': true,
          'on_call_with': 'Ana Ventoso',
        },
      ],
    );

    await _tap(tester, find.descendant(
        of: find.byKey(const ValueKey('tour-phone-calling-as')),
        matching: find.byType(TextField),
      ));

    expect(find.text('on a call with Ana Ventoso'), findsOneWidget);

    final busy = tester.widget<ListTile>(
      find.byKey(const ValueKey('calling-as-n-2')),
    );
    expect(busy.enabled, isFalse);
    expect(busy.onTap, isNull, reason: 'a busy line can still be chosen');

    // The free line beside it is pickable, so "refused" is about this line and
    // not a list that has stopped working.
    final free = tester.widget<ListTile>(
      find.byKey(const ValueKey('calling-as-n-1')),
    );
    expect(free.enabled, isTrue);
    expect(free.onTap, isNotNull);
  });

  /// Opening the list asks the server again.
  ///
  /// "On a call" is true for a moment. The list is loaded once when the tab
  /// opens, so without this the busy marks are as old as the tab — and a line
  /// taken since would read as free, which is the direction that puts two
  /// calls on one number.
  testWidgets('opening the line picker refetches the lines', (tester) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);
    final seen = <http.Request>[];

    await _pump(
      tester,
      device: device,
      size: const Size(900, 800),
      seen: seen,
      numbers: const [
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'CT main',
          'assigned_to_me': true,
        },
        {
          'id': 'n-2',
          'connection_id': 'cx-1',
          'e164': '+13055550188',
          'label': 'Miami',
          'assigned_to_me': false,
        },
      ],
    );

    int fetches() => seen
        .where((r) => r.method == 'GET' && r.url.path.endsWith('/phone/numbers'))
        .length;

    final before = fetches();
    expect(before, greaterThan(0), reason: 'the tab never loaded the lines');

    await _tap(tester, find.descendant(
        of: find.byKey(const ValueKey('tour-phone-calling-as')),
        matching: find.byType(TextField),
      ));

    expect(
      fetches(),
      greaterThan(before),
      reason: 'the busy marks are as old as the tab',
    );
  });

  /// One line is not a choice, and a picker offering it is a control that
  /// cannot do anything.
  testWidgets('a single line is stated rather than offered as a choice', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    expect(find.byType(Autocomplete<Object>), findsNothing);
    expect(find.textContaining('CT main'), findsWidgets);
  });

  /// A company that requires a tag gets one, and the pad says so.
  ///
  /// The prompt already existed and could be ignored: the desk stayed fully
  /// usable behind it, so the next call went out and the last one stayed
  /// unaccounted for. A requirement nothing enforces is decorative, and the
  /// reports it feeds end up with holes nobody knows about.
  ///
  /// The rule is the COMPANY's, read off the connection, so a company that has
  /// not set up tags never finds its pad locked by a requirement it did not
  /// choose — which is why the off case is asserted too.
  testWidgets('a required tag stops the next call until it is given', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, requireCallTag: true);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Accept'));
    await _tap(tester, find.widgetWithText(FilledButton, 'Hang up'));

    expect(find.byKey(const ValueKey('tag-the-call')), findsOneWidget);
    expect(find.textContaining('Required before the next call'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 555 0199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(
      device.connectedTo,
      isNull,
      reason: 'the next call went out with the last one unaccounted for',
    );
    expect(
      find.textContaining('before starting another'),
      findsOneWidget,
      reason: 'the pad refused and did not say why',
    );
  });

  /// And a company that did not ask for it is not held up.
  testWidgets('without the rule the pad stays usable after a call', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Accept'));
    await _tap(tester, find.widgetWithText(FilledButton, 'Hang up'));

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '305 555 0199',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Call'));

    expect(
      device.connectedTo,
      '+13055550199',
      reason: 'a company that never asked for tags had its pad locked',
    );
  });

  // ── UI queue 35, Jose's 1 + 4 (2026-09-30) ───────────────────────
  //
  // "see what we can do to improve the Phone Main Screen": recent calls were
  // numbers only, the same one nine times in a row, and a customer's number
  // stayed digits although every call carried its customer_id.

  testWidgets('recent calls fall under their day, carry names, fold repeats '
      'and narrow to what was missed', (tester) async {
    final now = DateTime.now();
    String ago(Duration d) => now.subtract(d).toUtc().toIso8601String();
    Map<String, Object?> call(
      String id,
      String direction,
      String peer,
      String status,
      String at, {
      Map<String, Object?> extra = const {},
    }) => {
      'id': id,
      'direction': direction,
      'from_e164': direction == 'inbound' ? peer : '+12035550142',
      'to_e164': direction == 'inbound' ? '+12035550142' : peer,
      'status': status,
      'created_at': at,
      ...extra,
    };

    await _pump(
      tester,
      calls: [
        call(
          'back',
          'outbound',
          '+13055550142',
          'completed',
          ago(const Duration(minutes: 1)),
        ),
        for (var i = 0; i < 3; i++)
          call(
            'm$i',
            'inbound',
            '+13055550142',
            'no-answer',
            ago(Duration(minutes: 2 + i)),
          ),
        call(
          'known',
          'inbound',
          '+13055550199',
          'completed',
          ago(const Duration(minutes: 6)),
          extra: {'customer_id': 'cu-1', 'customer_name': 'Maria Lopez'},
        ),
        call(
          'old',
          'outbound',
          '+17865550177',
          'completed',
          ago(const Duration(days: 1)),
        ),
      ],
    );

    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Yesterday'), findsOneWidget);
    expect(
      find.text('Maria Lopez'),
      findsOneWidget,
      reason: 'a customer by name',
    );
    expect(find.text('Customer'), findsWidgets);
    expect(
      find.text('×3'),
      findsOneWidget,
      reason: 'three missed calls from one number are one row',
    );
    expect(find.textContaining('called back'), findsOneWidget);

    await _tap(tester, find.byKey(const ValueKey('calls-chip-missed')));

    expect(find.text('Maria Lopez'), findsNothing);
    expect(find.text('×3'), findsOneWidget);
  });

  testWidgets('a name typed into the pad suggests the customer, and picking '
      'one fills the number formatted', (tester) async {
    await _pump(
      tester,
      permissions: const ['phone.view', 'phone.edit', 'customers.view'],
      customers: const [
        {
          'id': 'cu-1',
          'name': 'Maria Lopez',
          'number': 'C-1042',
          'phone': '+13055550199',
        },
      ],
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      'Maria',
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('dial-suggestions')), findsOneWidget);
    await _tap(tester, find.text('Maria Lopez'));

    expect(
      tester
          .widget<TextField>(find.widgetWithText(TextField, 'Name or number'))
          .controller!
          .text,
      '+1 (305) 555-0199',
      reason: 'the box read +13057242199 before',
    );
    expect(find.widgetWithText(FilledButton, 'Call Maria Lopez'), findsOne);
  });

  testWidgets('the person panel names the customer and opens their record', (
    tester,
  ) async {
    await _pump(
      tester,
      permissions: const ['phone.view', 'phone.edit', 'customers.view'],
      caller: const {'name': 'Maria Lopez', 'customer_id': 'cu-1'},
      customers: const [
        {
          'id': 'cu-1',
          'name': 'Maria Lopez',
          'number': 'C-1042',
          'phone': '+13055550199',
          'jobs_count': 2,
          'address': {
            'line1': '14 Elm Ridge Rd',
            'city': 'Hartford',
            'state': 'CT',
          },
        },
      ],
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+13055550199',
    );
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('person-card'));
    expect(
      find.descendant(of: card, matching: find.text('Maria Lopez')),
      findsOneWidget,
    );
    expect(find.textContaining('C-1042'), findsOneWidget);
    expect(find.text('14 Elm Ridge Rd, Hartford, CT'), findsOneWidget);
    expect(find.text('2 jobs'), findsOneWidget);
    expect(find.byKey(const Key('person-open-customer')), findsOneWidget);
  });

  // Design 153: Communication opens the shared Book panel with the number.
  testWidgets('Book a job from a number nobody has opens with that phone', (
    tester,
  ) async {
    await _pump(
      tester,
      permissions: const ['phone.view', 'phone.edit', 'jobs.create'],
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Name or number'),
      '+13055550199',
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('person-book-job')));
    await tester.tap(find.byKey(const Key('person-book-job')));
    await tester.pumpAndSettle();

    expect(find.text('Book a job'), findsWidgets);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('book-phone')))
          .controller!
          .text,
      '+13055550199',
    );
  });

  testWidgets('the transcript box waits for a call', (tester) async {
    await _pump(tester);

    // Idle, it was a grey block under the keypad with nothing in it.
    expect(find.text('Live transcript is off on this number.'), findsNothing);
  });
}

class _FakeDevice implements VoiceDevice {
  /// Settable so a test can be a handset, where the OS rings and EzBizi must
  /// not. Defaults to false, which is the desk case.
  @override
  bool ringsItself = false;

  // Android's Telecom account. Settable so a test can put the desk into the
  // one state a retry cannot fix, rather than only the happy one.
  @override
  bool needsCallingAccount = false;

  var openedCallingSettings = 0;

  @override
  Future<void> openCallingSettings() async => openedCallingSettings++;

  @override
  void Function()? onAnswered;

  @override
  void Function(String reason)? onCallFailed;

  @override
  String? pushPlatform;

  var declined = false;
  var accepted = false;
  String? connectedTo;
  String? callerId;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  /// Registration succeeds unless a test says otherwise — the desk being
  /// usable is the precondition for almost every case in this file.
  @override
  var registered = true;

  /// Settable, so a test can assert the banner repeats the reason Twilio gave
  /// rather than the guess about the network it used to print regardless.
  @override
  String? registrationError;

  /// A browser build unless a test says otherwise. Separate from [registered]
  /// because they mean different things: this build CANNOT call, versus this
  /// build did not manage to this time.
  @override
  var supported = true;

  /// Nothing to mount. Only the Windows WebView2 device returns one.
  @override
  Object? get platformView => null;

  /// Records the order, because the order IS the bug.
  ///
  /// The Windows device must be prepared — and its widget mounted — before
  /// setup() registers, or the microphone prompt has nowhere to arrive and
  /// Twilio refuses. A fake that ignores prepare() would let that regress
  /// silently, so this one remembers whether it was called first.
  var prepareCalledBeforeSetup = false;
  var _prepared = false;

  @override
  Future<void> prepare() async {
    _prepared = true;
  }

  @override
  Future<void> setup(String token) async {
    prepareCalledBeforeSetup = _prepared;
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    connectedTo = to;
    this.callerId = callerId;
  }

  @override
  void hangUp() {}

  @override
  void acceptIncoming() => accepted = true;

  @override
  void declineIncoming() => declined = true;

  @override
  void dispose() {
    onIncoming = null;
  }

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}

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

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, dynamic>> parked = const [],
  List<Map<String, dynamic>> ringing = const [],
  List<Map<String, Object?>> groups = const [],
  CallView view = CallView.dial,
  Size size = const Size(1280, 900),
  List<Map<String, Object?>>? numbers,
  VoiceDevice? device,
  List<http.Request>? seen,
  bool requireCallTag = false,
  Map<String, Object?> preferences = const {},
  List<String>? permissions,
  List<Map<String, Object?>>? calls,
  List<Map<String, Object?>> customers = const [],
  Map<String, Object?>? caller,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final inner = _handler(
    numbers: numbers ?? const [_number],
    requireCallTag: requireCallTag,
    preferences: preferences,
    parked: parked,
    ringing: ringing,
    groups: groups,
    permissions: permissions,
    calls: calls,
    customers: customers,
    caller: caller,
  );
  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);
      return inner(request);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CallTab(
          api: api,
          session: session,
          // The desk is app-wide now, so a test hands CallTab a service
          // rather than a device. forTest() skips start() — no
          // /phone/numbers, no token, no presence, and no frame await to
          // race — and leaves the phone already up.
          service: device == null
              ? null
              : PhoneCallService.forTest(device, callerId: '+12035550142'),
          view: view,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  required List<Map<String, Object?>> numbers,
  bool requireCallTag = false,
  Map<String, Object?> preferences = const {},
  List<Map<String, dynamic>> parked = const [],
  List<Map<String, dynamic>> ringing = const [],
  List<Map<String, Object?>> groups = const [],
  List<String>? permissions,
  List<Map<String, Object?>>? calls,
  List<Map<String, Object?>> customers = const [],
  Map<String, Object?>? caller,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/phone/caller')) {
      return _json({
        'data': caller ?? {'name': 'Marta Delgado', 'postal_code': '33401'},
      });
    }

    // A name typed into the pad asks Customers; a customer on file is read
    // for the person card.
    if (path.endsWith('/customers') && request.method == 'GET') {
      return _json({'data': customers});
    }
    if (path.contains('/customers/') && request.method == 'GET') {
      final id = path.split('/').last;
      final row = customers.where((c) => c['id'] == id).firstOrNull;
      return row == null
          ? _json({'message': 'Not found'}, 404)
          : _json({'data': row});
    }

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          'permissions': ?permissions,
          'preferences': {..._me['preferences']! as Map, ...preferences},
        },
      });
    }

    // What the call could have been about, and the call itself. The desk asks
    // for both after a hang-up; without them there is no question to answer
    // and the prompt correctly stays away.
    if (path.endsWith('/phone/tags') && request.method == 'GET') {
      return _json({
        'data': [
          {'id': 'tag-1', 'name': 'Booked a job', 'is_active': true},
        ],
      });
    }

    if (path.endsWith('/phone/calls') && request.method == 'GET') {
      if (calls != null) return _json({'data': calls});
      return _json({
        'data': [
          {
            'id': 'ca-just-ended',
            'number_id': 'n-1',
            'direction': 'inbound',
            'from_e164': '+13055550100',
            'to_e164': '+12035550142',
            'status': 'completed',
            'created_at': '2026-09-08T17:00:00+00:00',
            'answered_by_name': 'Adeline Ruiz',
          },
        ],
      });
    }

    // The company's own rules live on its connection — one row per company.
    if (path.endsWith('/phone/connections') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'pc-1',
            'scope_type': 'company',
            'scope_id': 'co-1',
            'kind': 'subaccount',
            'status': 'connected',
            'require_call_tag': requireCallTag,
          },
        ],
      });
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': numbers});
    }

    if (path.endsWith('/phone/calls/parked') && request.method == 'GET') {
      return _json({'data': parked});
    }

    if (path.endsWith('/phone/calls/ringing') && request.method == 'GET') {
      return _json({'data': ringing});
    }

    if (path.endsWith('/phone/ring-groups') && request.method == 'GET') {
      return _json({'data': groups});
    }

    // The server connects the listeners and hands back the destination the
    // speaker dials — see CallsController::page().
    if (path.endsWith('/phone/page') && request.method == 'POST') {
      return _json({
        'data': {
          'devices': 2,
          'skipped': [
            {'name': 'Bruno Vega', 'state': 'on_call'},
          ],
          'speak_to': 'page:ezbizi-page-rg-1-1789500000',
        },
      });
    }

    if (path.endsWith('/phone/presence') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'user_id': 'u-2',
            'name': 'Maya Chen',
            'state': 'on_call',
            'updated_at': '2026-08-24T12:00:00+00:00',
          },
          {
            'user_id': 'u-1',
            'name': 'Jose Silva',
            'state': 'registered',
            'updated_at': '2026-08-24T12:01:00+00:00',
          },
          {
            'user_id': 'u-3',
            'name': 'Bruno Vega',
            'state': 'dnd',
            'updated_at': '2026-08-24T12:02:00+00:00',
          },
        ],
      });
    }

    if (path.endsWith('/phone/presence') && request.method == 'PUT') {
      return _json({
        'data': {
          'id': 'pr-1',
          'connection_id': 'pc-1',
          'user_id': 'u-1',
          'state': 'registered',
        },
      });
    }

    if (path.endsWith('/phone/tokens') && request.method == 'POST') {
      return _json({
        'token': 'fake-access-token',
        'identity': 'u1',
        'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
      });
    }

    if (path.endsWith('/phone/messages') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'm-1',
            'number_id': 'n-1',
            'connection_id': 'pc-1',
            'direction': 'inbound',
            'from_e164': '+12035550199',
            'to_e164': '+12035550142',
            'body': 'Thursday morning still good?',
            'customer_id': 'c-1',
            'created_at': '2026-08-23T16:02:00+00:00',
          },
          {
            'id': 'm-2',
            'number_id': 'n-1',
            'connection_id': 'pc-1',
            'direction': 'outbound',
            'from_e164': '+12035550142',
            'to_e164': '+12035550199',
            'body': 'Yes, nine works.',
            'customer_id': 'c-1',
            'created_at': '2026-08-23T16:09:00+00:00',
          },
        ],
      });
    }

    // Two messages waiting from Maya, none from anybody else: the Team
    // roster's Unread tile counts messages, the filter keeps people.
    if (path.endsWith('/staff-messages/unread')) {
      return _json({
        'data': {'u-2': 2},
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _number = {
  'id': 'n-1',
  'connection_id': 'pc-1',
  'e164': '+12035550142',
  'label': 'CT main',
  'transcribe': false,
  'sms_outbound_allowed': false,
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
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
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
