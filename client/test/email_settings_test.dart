import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/email/email_screen.dart';
import 'package:ezbizi_client/screens/email/mailbox_settings.dart';
import 'package:ezbizi_client/screens/email/settings_tab.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Email › Settings — the connections behind the mail.
///
/// ── What is actually being defended here ─────────────────────────
///
/// **A broken inbox has to say what broke.** The owner's words: *"it should
/// definitely display the message of revoked or expired."* Consent withdrawn
/// and a password changed are the same silence on screen and completely
/// different remedies, and a shared inbox that quietly stops delivering is a
/// customer who never got an answer. The sentence is the server's; this end
/// prints it, and the test asserts the sentence rather than a state name.
///
/// **When it last worked is half the answer.** "It broke just now" and "it has
/// been dead since Tuesday" are the same red dot. The line is asserted, and so
/// is its absence on a server that cannot yet answer the question — printing
/// "never" for a field nobody sent would be this screen inventing an outage.
///
/// **The credential goes one way.** There is no path on the server that hands a
/// stored mailbox password back — `Mailbox::mayReveal` answers false for
/// everybody — so the field is an empty box for a NEW value. The test feeds a
/// credential into the row anyway, as hostile input, and asserts it reaches no
/// pixel and no controller.
///
/// **A control that cannot finish is worse than none.** The Google sign-in flow
/// does not exist in this application. A "Connect Google" button would be
/// pressed, and typed into, before anybody found that out.
///
/// **Disconnecting takes an inbox away from everybody.** Not from the person
/// pressing it: from every role that was granted it, and reconnecting does not
/// bring the grants back. That is said in the sentence, before the request is
/// sent, and the test asserts nothing is sent until it has been read.
void main() {
  // ── the two sub-tabs ───────────────────────────────────────────

  testWidgets('Email has an Inbox tab and a Settings tab, and switching '
      'between them shows the other one', (tester) async {
    await _pump(tester, _handler());

    // The Inbox tab is what the module opens on, and it is what was there
    // before there were two of them.
    expect(find.text('THE INBOX YOU ARE IN'), findsOneWidget);
    expect(find.byType(MailboxSettingsTab), findsNothing);

    await _openSettings(tester);

    expect(find.byType(MailboxSettingsTab), findsOneWidget);
    expect(find.text('Connected inboxes'), findsOneWidget);
    expect(
      find.text('THE INBOX YOU ARE IN'),
      findsNothing,
      reason:
          'The inbox is still being drawn under the Settings tab, so both '
          'pages are on screen at once.',
    );

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Inbox'));

    expect(find.text('THE INBOX YOU ARE IN'), findsOneWidget);
  });

  // ── what a row says ────────────────────────────────────────────

  testWidgets('a broken inbox says what went wrong and when it last worked', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'broken',
            statusReason:
                'Google says the permission to read this address was taken '
                'away. Somebody removed EzBizi from that account’s connected '
                'apps, and connecting it again is what restores it.',
            canBeOpened: false,
            lastReachedAt: _agoIso(const Duration(days: 3)),
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Not working'), findsOneWidget);
    expect(
      find.textContaining('the permission to read this address was taken away'),
      findsOneWidget,
      reason:
          'The server said why this inbox stopped working and the screen did '
          'not print it. A state word on its own does not tell a withdrawn '
          'consent from an expired password.',
    );
    expect(
      find.textContaining('Last reached 3 days ago'),
      findsOneWidget,
      reason:
          '"It broke just now" and "it has been dead since Tuesday" are the '
          'same row without this.',
    );
  });

  testWidgets('a working inbox says so, and says how long ago it answered', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            lastReachedAt: _agoIso(const Duration(minutes: 20, seconds: 5)),
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Connected'), findsOneWidget);
    expect(find.textContaining('Last reached 20 minutes ago'), findsOneWidget);
  });

  testWidgets('an inbox nobody has connected yet says that, in words', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            unavailableReason:
                'The mail server details and the password for this address '
                'have not been entered yet, so EzBizi cannot open it.',
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Waiting to be connected'), findsOneWidget);
    expect(
      find.textContaining('have not been entered yet'),
      findsOneWidget,
    );
    // The act on offer is the one that finishes the setup, not one that
    // implies something was working before.
    expect(find.widgetWithText(OutlinedButton, 'Connect'), findsOneWidget);
  });

  /// The parallel half of this work adds `last_reached_at` server-side. Until
  /// it lands the key is simply absent, and absent is not "never" — one is a
  /// server that cannot answer, the other is an address that has never once
  /// replied, and only the second is worth alarming somebody about.
  testWidgets('a server that does not send when it was last reached gets no '
      'line, rather than "never"', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            lastReachedKnown: false,
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.textContaining('Last reached'), findsNothing);
    expect(find.textContaining('has not answered EzBizi yet'), findsNothing);

    // The control. Everything else about the row still drew, so the assertions
    // above are about the missing field and not about a card that failed to
    // render at all.
    expect(find.text('info@a-1chimney.com'), findsWidgets);
    expect(find.text('Connected'), findsOneWidget);
  });

  testWidgets('a server that says the address has never answered prints that', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com', lastReachedAt: null),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.textContaining('has not answered EzBizi yet'), findsOneWidget);
  });

  /// Two inboxes called info@ under two different companies is the case this
  /// app has already met. The address alone names either of them.
  testWidgets('each row says which part of the business owns it', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            scopeType: 'company',
            scopeId: 'co-1',
          ),
          _row(
            id: 'mb-2',
            address: 'info@a-1chimney.com',
            scopeType: 'franchise',
            scopeId: 'fr-1',
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Company · Everdoors'), findsOneWidget);
    expect(
      find.text('Franchise · Everdoors › Connecticut'),
      findsOneWidget,
      reason:
          'A place is named by its whole path, because four companies in this '
          'business each operate a Connecticut.',
    );
  });

  // ── connecting ─────────────────────────────────────────────────

  testWidgets('the password box starts empty, is obscured, and no stored '
      'credential reaches the screen', (tester) async {
    await _pump(
      tester,
      _handler(imapUsable: true, 
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            // Hostile input. The server has no path that sends this and must
            // never gain one; if it ever did, nothing on this screen may draw
            // it.
            extra: const {'credentials': 'hunter2-must-never-appear'},
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    final field = tester.widget<TextField>(find.byType(TextField));

    expect(
      field.obscureText,
      isTrue,
      reason: 'A shared mailbox password is being typed in an open-plan office.',
    );
    expect(
      field.controller?.text,
      isEmpty,
      reason:
          'The box is for a NEW credential. Anything prefilled into it came '
          'from somewhere, and there is nowhere it could have come from that '
          'this application is allowed to read.',
    );
    expect(find.textContaining('hunter2'), findsNothing);
    expect(
      find.textContaining('never shown again'),
      findsOneWidget,
      reason:
          'Somebody typing a password nobody can ever read back needs telling '
          'that before they wonder where to check it.',
    );
  });

  testWidgets('the password is posted to the connect endpoint and the list '
      'is asked again', (tester) async {
    final requests = <String>[];
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(imapUsable: true, 
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
          ),
        ],
        onRequest: (method, path, body) {
          requests.add('$method $path');

          if (body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await tester.enterText(find.byType(TextField), 'the-mailbox-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(
      requests,
      contains('POST /api/v1/email/mailboxes/mb-1/connect'),
      reason: 'Sent: $requests',
    );
    expect(bodies.last, {'credential': 'the-mailbox-password'});
    expect(
      requests.where((r) => r == 'GET /api/v1/email/mailboxes').length,
      greaterThan(1),
      reason:
          'The list still shows the state from before the credential landed.',
    );
  });

  /// Fact D: the browser flow that turns somebody's consent into a token is
  /// not built. A button that opens it would be pressed.
  testWidgets('an address connected by signing in says what is missing '
      'instead of offering a button that cannot finish', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            provider: 'gmail_oauth',
            providerLabel: 'Google (recommended)',
            status: 'unconfigured',
            canBeOpened: false,
            unavailableReason:
                'EzBizi has no Google connection set up on this installation '
                'yet, so no Google mailbox can be opened. Nothing is wrong '
                'with this address — the missing piece is at EzBizi’s end.',
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(find.text('Not built yet'), findsOneWidget);
    expect(
      find.textContaining('sign-in step is not built yet'),
      findsOneWidget,
    );
    // Inside the panel specifically. The card behind it carries the same
    // sentence as its state — which is right, and is why this is scoped rather
    // than counted.
    expect(
      find.descendant(
        of: find.byType(SidePanel),
        matching: find.textContaining(
          'no Google connection set up on this installation',
        ),
      ),
      findsOneWidget,
      reason:
          'The server knows something this screen does not — whether this '
          'installation is registered with that service at all.',
    );
    expect(
      find.byType(TextField),
      findsNothing,
      reason: 'There is nothing anybody can usefully type here.',
    );
    expect(
      find.widgetWithText(FilledButton, 'Connect it'),
      findsNothing,
      reason:
          'A control that cannot complete is worse than none: it gets pressed '
          'first and understood afterwards.',
    );
  });

  testWidgets('the same panel for an IMAP address does offer the field — the '
      'control for the case above', (tester) async {
    await _pump(
      tester,
      _handler(imapUsable: true, 
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(find.byType(TextField), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Connect it'), findsOneWidget);
    expect(find.text('Not built yet'), findsNothing);
  });

  // ── disconnecting ──────────────────────────────────────────────

  testWidgets('disconnecting says what it takes away, and takes nothing away '
      'until it has been confirmed', (tester) async {
    final requests = <String>[];

    await _pump(
      tester,
      _handler(
        mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
        onRequest: (method, path, _) => requests.add('$method $path'),
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(TextButton, 'Disconnect'));

    expect(
      find.textContaining('will lose it'),
      findsOneWidget,
      reason:
          'Disconnecting removes the inbox for every role that was granted it, '
          'not for the person pressing the button.',
    );
    expect(find.textContaining('would have to be given it again'), findsOneWidget);
    expect(
      find.textContaining('Nothing happens to the mail itself'),
      findsOneWidget,
      reason:
          '"Disconnect" reads to a nervous administrator like it might delete '
          'their email.',
    );
    expect(
      requests.any((r) => r.startsWith('DELETE')),
      isFalse,
      reason: 'The inbox was disconnected by the act of asking about it.',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Disconnect it'));

    expect(requests, contains('DELETE /api/v1/email/mailboxes/mb-1'));
    expect(
      find.textContaining('no longer connected to EzBizi'),
      findsOneWidget,
      reason:
          'The server’s own sentence about what has just happened, kept on '
          'screen rather than flashed past in a bar.',
    );
  });

  // ── adding ─────────────────────────────────────────────────────

  testWidgets('adding an address posts it against the part of the business '
      'this person is working in', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        onRequest: (method, path, body) {
          if (method == 'POST' && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);

    expect(
      find.textContaining('No shared inbox is connected here yet'),
      findsOneWidget,
      reason: 'An empty list has to say what to do next.',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Address'),
      'bookings@a-1chimney.com',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'What to call it'),
      'Bookings',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Add the address'));

    expect(bodies, hasLength(1));
    expect(bodies.single['address'], 'bookings@a-1chimney.com');
    expect(bodies.single['display_name'], 'Bookings');
    expect(bodies.single['scope_type'], 'organization');
    expect(bodies.single['scope_id'], 'org-1');
  });

  // ── gating ─────────────────────────────────────────────────────

  testWidgets('a role that may read the mail but not manage it gets no live '
      'manage controls, and a sentence saying why', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com', mayManage: false),
        ],
      ),
      // The tab permission and nothing else — the ordinary case for anybody
      // who answers mail without looking after the connections.
      permissions: const ['email.view'],
    );

    await _openSettings(tester);

    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Add an inbox'))
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Reconnect'),
          )
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<TextButton>(find.widgetWithText(TextButton, 'Disconnect'))
          .onPressed,
      isNull,
    );

    // Still on screen, and saying which of the two things is missing — a
    // permission that lives on a role, so the person to ask is whoever edits
    // roles. The same words the server's own 403 uses.
    expect(
      find.byTooltip(
        'You do not have permission to add a shared inbox. That is part of a '
        'role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
    expect(
      find.byTooltip(
        'You do not have permission to disconnect this inbox. That is part of '
        'a role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a role that may manage gets the same controls, live — the '
      'control for the refusal above', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
      ),
    );

    await _openSettings(tester);

    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Add an inbox'))
          .onPressed,
      isNotNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Reconnect'),
          )
          .onPressed,
      isNotNull,
    );
    expect(
      tester
          .widget<TextButton>(find.widgetWithText(TextButton, 'Disconnect'))
          .onPressed,
      isNotNull,
    );
  });

  // ── R7 ─────────────────────────────────────────────────────────

  /// A status list is exactly the shape that breaks on a phone: six facts per
  /// row, and the one somebody came for — the state — is the one that ends up
  /// off the right edge. 375 is a phone, 700 a tablet upright or a window
  /// dragged narrow, 1400 a laptop.
  for (final width in [375.0, 700.0, 1400.0]) {
    testWidgets('at ${width.toInt()}px the state, the owner and the sentence '
        'are all on screen and nothing overflows', (tester) async {
      await _pump(
        tester,
        _handler(
          mailboxes: [
            _row(
              id: 'mb-1',
              address: 'info@a-1chimney.com',
              scopeType: 'franchise',
              scopeId: 'fr-1',
              status: 'broken',
              statusReason:
                  'The password for this address was refused. It has probably '
                  'been changed where the mailbox lives; entering the new one '
                  'here reconnects it.',
              canBeOpened: false,
              lastReachedAt: _agoIso(const Duration(hours: 5)),
            ),
          ],
        ),
        size: Size(width, 900),
      );

      await _openSettings(tester);

      expect(tester.takeException(), isNull);
      expect(find.text('info@a-1chimney.com'), findsWidgets);
      expect(find.text('Not working'), findsOneWidget);
      expect(
        find.text('Franchise · Everdoors › Connecticut'),
        findsOneWidget,
      );
      expect(find.textContaining('has probably been changed'), findsOneWidget);
      expect(find.textContaining('Last reached 5 hours ago'), findsOneWidget);
    });

    testWidgets('at ${width.toInt()}px the connect panel fits and still asks '
        'for one thing', (tester) async {
      await _pump(
        tester,
        _handler(imapUsable: true, 
          mailboxes: [
            _row(
              id: 'mb-1',
              address: 'info@a-1chimney.com',
              status: 'unconfigured',
              canBeOpened: false,
            ),
          ],
        ),
        size: Size(width, 900),
      );

      await _openSettings(tester);
      await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

      expect(tester.takeException(), isNull);
      expect(find.byType(TextField), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Connect it'), findsOneWidget);
    });
  }

  // ── the words, without a widget tree ───────────────────────────

  group('lastReachedSentence', () {
    final formats = DisplayFormats.fallback;
    final now = DateTime.utc(2026, 8, 13, 14, 0);

    MailboxConnection at(Object? iso, {bool known = true}) =>
        MailboxConnection.fromJson({
          'id': 'mb-1',
          'address': 'info@example.com',
          if (known) 'last_reached_at': iso,
        });

    test('an absent field draws no line at all', () {
      expect(lastReachedSentence(at(null, known: false), formats: formats), isNull);
    });

    test('a null field says the address has never answered', () {
      expect(
        lastReachedSentence(at(null), formats: formats),
        'This address has not answered EzBizi yet.',
      );
    });

    test('it carries the relative phrase AND the exact stamp', () {
      final sentence = lastReachedSentence(
        at('2026-08-13T13:40:00Z'),
        formats: formats,
        now: now,
      );

      expect(sentence, startsWith('Last reached 20 minutes ago · '));
      expect(
        sentence,
        contains('2026-08-13'),
        reason:
            'A clock that is out makes the relative half quietly false, and '
            'the stamp beside it is what lets a reader catch that.',
      );
    });

    test('one of anything is singular', () {
      expect(
        lastReachedSentence(
          at('2026-08-13T13:59:10Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached just now'),
      );
      expect(
        lastReachedSentence(
          at('2026-08-13T13:59:00Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached 1 minute ago'),
      );
      expect(
        lastReachedSentence(
          at('2026-08-12T14:00:00Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached 1 day ago'),
      );
    });
  });

  group('stateOf', () {
    MailboxConnection row(Map<String, Object?> json) =>
        MailboxConnection.fromJson({
          'id': 'mb-1',
          'address': 'info@example.com',
          'can_be_opened': true,
          ...json,
        });

    test('broken wins over an unusable reader, because it is the fact to act '
        'on first', () {
      final state = stateOf(
        row({
          'status': 'broken',
          'status_reason': 'Consent was withdrawn.',
          'can_be_opened': false,
          'unavailable_reason': 'Nobody has signed in yet.',
        }),
      );

      expect(state.word, 'Not working');
      expect(state.sentence, 'Consent was withdrawn.');
      expect(state.trouble, isTrue);
    });

    test('a mailbox marked broken with no reason still says something useful', () {
      final state = stateOf(row({'status': 'broken'}));

      expect(state.sentence, contains('was not told why'));
    });

    test('connected and openable needs no sentence', () {
      final state = stateOf(row({'status': 'connected'}));

      expect(state.word, 'Connected');
      expect(state.sentence, isNull);
      expect(state.trouble, isFalse);
    });

    test('connected but unopenable is trouble, in the server’s words', () {
      final state = stateOf(
        row({
          'status': 'connected',
          'can_be_opened': false,
          'unavailable_reason': 'EzBizi has no Google connection set up here.',
        }),
      );

      expect(state.word, 'Connected, but cannot be opened');
      expect(state.sentence, 'EzBizi has no Google connection set up here.');
      expect(state.trouble, isTrue);
    });

    test('a row with no status at all is treated as unfinished, never as '
        'working', () {
      expect(stateOf(row(const {})).word, 'Waiting to be connected');
    });
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1500, 1000),
  List<String> permissions = const ['email.view', 'email.create', 'email.edit'],
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_withMe(handler, permissions)));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: EmailScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _openSettings(WidgetTester tester) =>
    _tap(tester, find.widgetWithText(ChoiceChip, 'Settings'));

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first, warnIfMissed: false);
  await tester.pumpAndSettle();
}

// ── the server, as this tab reads it ───────────────────────────────
//
// `last_reached_at` is the field being added server-side in parallel; every
// other key below is what MailboxesController::row already answers with.

typedef _Watcher =
    void Function(String method, String path, Map<String, dynamic>? body);

/// [imapUsable] pretends this installation CAN speak IMAP.
///
/// It cannot — PHP 8.4 dropped the extension — and the default here says so,
/// because that is what the real server publishes and it is what stops a live
/// password form being drawn for a connection that could never be attempted.
///
/// The tests that exercise the password panel pass true. They are about the
/// PANEL's behaviour — write-only, posted to the connect endpoint, laid out at
/// three widths — and that code becomes reachable the day the extension is
/// installed or a hosted provider lands. Deleting them because today's box
/// cannot reach the panel would leave that behaviour unpinned for the day it
/// can.
MockClientHandler _handler({
  List<Map<String, Object?>>? mailboxes,
  _Watcher? onRequest,
  bool imapUsable = false,
}) {
  final rows = mailboxes ?? [_row(id: 'mb-1', address: 'info@a-1chimney.com')];

  return (request) async {
    final path = request.url.path;
    final body = request.body.isEmpty
        ? null
        : jsonDecode(request.body) as Map<String, dynamic>;

    onRequest?.call(request.method, path, body);

    if (request.method == 'DELETE') {
      return _json({
        'message':
            'info@a-1chimney.com is no longer connected to EzBizi. Nothing has '
            'happened to the mail itself — the address still receives '
            'everything it did, and it can be read wherever it was read '
            'before.',
      });
    }

    if (path.endsWith('/connect')) {
      return _json({'data': rows.first});
    }

    if (path.endsWith('/email/mailboxes')) {
      if (request.method == 'POST') {
        return _json({'data': rows.isEmpty ? _row(id: 'mb-9', address: 'x@y.z') : rows.first}, 201);
      }

      return _json({
        'data': rows,
        'meta': {
          'providers': [
            // Declaration order, as MailboxProvider::all() returns it: the one
            // to steer people to first, and the one that stores a password
            // second.
            // `usable` is what the real server publishes, and BOTH are false
            // on this installation today: the Google sign-in flow is not built,
            // and PHP 8.4 dropped the imap extension the IMAP reader needs.
            //
            // This fixture said neither, which mattered: with `usable` absent
            // the client treats a provider as usable (an older server cannot
            // answer), so IMAP drew a live password form for a connection that
            // could never be attempted — the defect the flag exists to stop.
            {
              'value': 'gmail_oauth',
              'label': 'Google (recommended)',
              'needs_host': false,
              'caution': null,
              'usable': false,
              'unusable_because':
                  'EzBizi has no Google connection set up on this installation '
                  'yet, so no Google mailbox can be opened and signing in would '
                  'not complete.',
            },
            {
              'value': 'imap',
              'label': 'IMAP',
              'needs_host': true,
              'caution':
                  'Connecting by IMAP means storing the mailbox password here, '
                  'and for most mail services that is the account password.',
              'usable': imapUsable,
              'unusable_because': imapUsable
                  ? null
                  : 'EzBizi cannot speak to IMAP mail servers on this '
                        'installation yet - the PHP extension it needs is not '
                        'present. Entering a password here would not connect '
                        'anything.',
            },
          ],
          'imap_ports': [143, 993],
          'smtp_ports': [587, 465],
        },
      });
    }

    // The Inbox tab, which this file does not test but does render.
    if (path.endsWith('/messages')) {
      return _json({
        'data': const [],
        'meta': {'page': 1, 'last_page': 1},
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

MockClientHandler _withMe(MockClientHandler inner, List<String> permissions) {
  return (request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    return inner(request);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// One row of `GET /email/mailboxes`, in the shape the controller answers with.
///
/// [lastReachedKnown] false leaves the key OUT, which is what a server without
/// that column sends and is a different fact from a null.
Map<String, Object?> _row({
  required String id,
  required String address,
  String displayName = 'Info',
  String provider = 'imap',
  String providerLabel = 'IMAP',
  String scopeType = 'organization',
  String scopeId = 'org-1',
  String status = 'connected',
  String? statusReason,
  bool canBeOpened = true,
  String? unavailableReason,
  String? lastReachedAt,
  bool lastReachedKnown = true,
  bool mayManage = true,
  Map<String, Object?> extra = const {},
}) => {
  'id': id,
  'address': address,
  'display_name': displayName,
  'provider': provider,
  'provider_label': providerLabel,
  'scope_type': scopeType,
  'scope_id': scopeId,
  'status': status,
  'status_reason': statusReason,
  'can_be_opened': canBeOpened,
  'unavailable_reason': unavailableReason,
  if (lastReachedKnown) 'last_reached_at': lastReachedAt,
  'mail_server': const {'imap_host': 'imap.example.com', 'imap_port': 993},
  'may_read': true,
  'may_read_reason': null,
  'may_manage': mayManage,
  'read_state_shared': true,
  'read_state_note': null,
  ...extra,
};

/// A stamp that will still read as [ago] by the time the widget builds.
String _agoIso(Duration ago) =>
    DateTime.now().toUtc().subtract(ago).toIso8601String();

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['email.view', 'email.create', 'email.edit'],
  'modules': [
    {'key': 'email', 'label': 'Email', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
