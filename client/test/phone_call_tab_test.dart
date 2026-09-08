import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_tab.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Call desk — layout 1: three panes, stack below 600, 10DLC send disabled.
void main() {
  testWidgets('the desk is not Coming soon', (tester) async {
    await _pump(tester, numbers: const []);

    expect(find.byType(ComingSoonScreen), findsNothing);
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
      find.widgetWithText(TextField, 'Number'),
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
      find.widgetWithText(TextField, 'Number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.byTooltip('Keep this open between calls'));

    await tester.enterText(find.widgetWithText(TextField, 'Number'), '');
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

  testWidgets('at 375 one pane at a time, with Back', (tester) async {
    await _pump(tester, size: const Size(375, 800));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-teammates')), findsNothing);
    expect(find.byKey(const Key('phone-call-person')), findsNothing);
    expect(find.text('Calling as'), findsOneWidget);
    expect(find.byTooltip('Back to the team'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the team'));

    expect(find.byKey(const Key('phone-call-teammates')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.text('Maya Chen'), findsOneWidget);
    expect(find.text('Calling as'), findsNothing);

    await _tap(tester, find.text('Maya Chen'));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);

    await _tap(tester, find.byTooltip('To the customer'));

    expect(find.byKey(const Key('phone-call-person')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.text('Messages'), findsOneWidget);
    expect(find.byTooltip('Back to the pad'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the pad'));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);
  });

  testWidgets(
    'SMS send is disabled with the 10DLC sentence when the line cannot send',
    (tester) async {
      await _pump(tester, size: const Size(900, 800));

      // The message box lives in the customer column, which stays away until
      // there is somebody to show. Typing the number is what opens it.
      await tester.enterText(
        find.widgetWithText(TextField, 'Number'),
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
      expect(find.text('Messages'), findsOneWidget);
      expect(find.byType(Dialog), findsNothing);
    },
  );

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
      find.widgetWithText(TextField, 'Number'),
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

  testWidgets('pad digits load the thread as E.164', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
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
      find.widgetWithText(TextField, 'Number'),
      '305 724 2199',
    );
    await tester.pumpAndSettle();

    expect(
      find.text('This line cannot send texts until messaging is approved.'),
      findsNothing,
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
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

    expect(key.width, 56);
    expect(key.height, 46);

    // 44px is the touch guidance; a key that shrank below it would be a
    // regression even though it looks tidier.
    expect(key.height, greaterThanOrEqualTo(44));
  });

  testWidgets('the pad is the same size on a phone as on a monitor', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 800));

    expect(tester.getSize(find.byKey(const ValueKey('key:5'))).width, 56);
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
      find.widgetWithText(TextField, 'Number'),
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
      find.widgetWithText(TextField, 'Number'),
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

    await tester.enterText(find.widgetWithText(TextField, 'Number'), '724 2199');
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
      find.widgetWithText(TextField, 'Number'),
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
      find.widgetWithText(TextField, 'Number'),
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
    await tester.enterText(find.byType(TextField).first, 'Miami');
    await tester.pumpAndSettle();
    await _tap(tester, find.textContaining('Miami · +1 (305) 555-0188').last);

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
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
}

class _FakeDevice implements VoiceDevice {
  var declined = false;
  var accepted = false;
  String? connectedTo;
  String? callerId;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

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
  CallView view = CallView.dial,
  Size size = const Size(1280, 900),
  List<Map<String, Object?>>? numbers,
  VoiceDevice? device,
  List<http.Request>? seen,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final inner = _handler(numbers: numbers ?? const [_number]);
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

MockClientHandler _handler({required List<Map<String, Object?>> numbers}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({'data': _me});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': numbers});
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
        ],
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
