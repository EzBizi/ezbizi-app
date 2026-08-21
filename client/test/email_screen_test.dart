import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/email_screen.dart';
import 'package:ezbizi_client/screens/email/mailbox_rail.dart';
import 'package:ezbizi_client/screens/email/message_body.dart';
import 'package:ezbizi_client/screens/email/message_list.dart';
import 'package:ezbizi_client/screens/email/reply_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Email tab — pick an inbox, read a message, reply.
///
/// ── What is actually being defended here ─────────────────────────
///
/// This module is an access-controlled window onto addresses that are not the
/// reader's own: *"the capacity to let people answer emails they don't
/// regularly have access to — like company wide emails that receive customer
/// emails or forms."* Everything below follows from that one sentence.
///
/// **Which inbox am I in.** Somebody who can open `info@`, `bookings@` and
/// `careers@` answering a customer from the wrong one is the failure this
/// screen exists to prevent, and it is the only one on the screen that cannot
/// be undone — the reply has already left. So the inbox is asserted at three
/// widths, on the message as well as on the list, and the reply is asserted to
/// name the inbox it is going out as AND to be posted to that inbox's own path.
///
/// **"Seen by" is a setting, not a feature.** Ownership of a thread was
/// deferred, so this line is the whole of what stops two people answering the
/// same customer — and whether it is drawn at all is the organization's choice
/// between shared and private read state. The two tests below run the SAME
/// fixture, `seen_by` and all, and differ only in that setting: a screen that
/// ignores it passes neither.
///
/// **An unreachable inbox is not an empty one.** "No mail" is the most
/// damaging thing a mail window can say wrongly, because nobody investigates
/// it. The refusal is the server's own sentence.
///
/// **A remote image is not fetched.** It is a tracking pixel and it is an SSRF
/// vector, and the answer is a sentence where the picture was — not a silent
/// gap, and not a button offering to go and get it.
void main() {
  /// R7, and the requirement with the sharpest edge: at no width may somebody
  /// be looking at mail without being told whose mail it is.
  ///
  /// 375 is a phone, 700 is a tablet upright or a browser window dragged
  /// narrow, 1400 is a laptop. The first two draw one pane, the third draws
  /// three, and the sentence is on all of them — including after a message has
  /// been opened, which on a phone REPLACES the list the inbox was named above.
  for (final width in [375.0, 700.0, 1400.0]) {
    testWidgets('the inbox you are in is named at ${width.toInt()}px, on the '
        'list and on the message', (tester) async {
      await _pump(tester, _handler(), size: Size(width, 900));

      expect(find.text('THE INBOX YOU ARE IN'), findsOneWidget);
      expect(find.text('info@a-1chimney.com'), findsWidgets);

      await _tap(tester, find.text('Chimney sweep quote').first);

      expect(
        find.text('THE INBOX YOU ARE IN'),
        findsOneWidget,
        reason:
            'The message is open and nothing on screen says which inbox it '
            'arrived in. That is how a reply goes out as the wrong address.',
      );
      expect(find.text('info@a-1chimney.com'), findsWidgets);
    });
  }

  /// R7. List-plus-detail is the shape that breaks worst on a phone, and this
  /// screen is list-plus-list-plus-detail. One pane at a time, and the panes
  /// that are not on screen are not built at all — an off-screen column still
  /// lays out, still overflows, and is still read aloud by a screen reader.
  testWidgets('at 375px it is one pane at a time, and nothing overflows', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(375, 900));

    expect(tester.takeException(), isNull);
    expect(find.byType(MessageListPane), findsOneWidget);
    expect(
      find.byType(MailboxRail),
      findsNothing,
      reason: 'The inbox rail is a desktop column. At 375px it is a pane of '
          'its own, reached from the bar, not a strip beside the list.',
    );

    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(tester.takeException(), isNull);
    expect(find.byType(MessageListPane), findsNothing);
    expect(find.byType(MessageBody), findsOneWidget);

    // Not merely "no exception": the one pane on screen has the whole width.
    // A message laid out in a 200px column beside an invisible list is the
    // failure that produces the overflow, and it shows up here first.
    expect(tester.getRect(find.byType(MessageBody)).width, greaterThan(300));

    // Back to the list, and back again to the inboxes.
    await _tap(tester, find.byTooltip('Back to the messages'));
    expect(find.byType(MessageListPane), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the inboxes'));
    expect(find.byType(MailboxRail), findsOneWidget);
    expect(find.text('bookings@a-1chimney.com'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('at 1400px the inboxes, the list and the message are side by '
      'side', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    await _tap(tester, find.text('Chimney sweep quote').first);

    final rail = tester.getRect(find.byType(MailboxRail));
    final list = tester.getRect(find.byType(MessageListPane));
    final message = tester.getRect(find.byType(MessageBody));

    expect(rail.right, lessThanOrEqualTo(list.left + 1));
    expect(list.right, lessThanOrEqualTo(message.left + 1));
    expect(tester.takeException(), isNull);
  });

  // ── "Seen by", and the setting that decides whether it exists ────

  /// Decision 6, and the only thing standing between a customer and two
  /// different answers to the same email.
  testWidgets('a message somebody else has opened says who, on the list and '
      'on the message', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('Seen by Marta Vieira'), findsOneWidget);

    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(
      find.text('Seen by Marta Vieira'),
      findsNWidgets(2),
      reason: 'The row said somebody was on this and the open message did '
          'not, which is where the second reply gets written.',
    );
  });

  /// Decision 8: *"perhaps we can let the organization choose in settings if
  /// it's shared or not shared view."*
  ///
  /// The SAME fixture as the test above — `seen_by` is still on every row, and
  /// Marta has still read it. Only the organization's setting differs. A screen
  /// that draws the indicator from the data rather than from the setting passes
  /// the test above and fails this one.
  testWidgets('with private read state nobody is named, and the mail is still '
      'there', (tester) async {
    await _pump(tester, _handler(sharedRead: false));

    // The CONTROL. Without it this assertion would pass on a screen that
    // failed to load anything at all.
    expect(find.text('Chimney sweep quote'), findsWidgets);

    expect(
      find.text('Seen by Marta Vieira'),
      findsNothing,
      reason: 'The organization chose private read state and the screen is '
          'still reporting who opened what.',
    );

    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.byType(MessageBody), findsOneWidget);
    expect(find.textContaining('Seen by'), findsNothing);
  });

  /// "Seen by" answers "is somebody ELSE on this". The reader's own read is in
  /// the payload — the server records every one — and naming them back to
  /// themselves on every message would train people to ignore the one line
  /// that matters.
  testWidgets('the reader is never the person it says has seen it', (
    tester,
  ) async {
    await _pump(tester, _handler());

    // Invoice 4471 has been read by exactly one person: the reader.
    expect(find.text('Seen by Jose Silva'), findsNothing);

    // The CONTROL, on the row beside it: the indicator does appear when it is
    // somebody else, so the assertion above is about the filter and not about
    // the line being broken.
    expect(find.text('Seen by Marta Vieira'), findsOneWidget);
  });

  // ── the reply, and the address it goes out as ────────────────────

  /// Decision 7: *"info replies as info"*. The person typing is not the
  /// sender, and the screen says so in full before anything leaves.
  testWidgets('the reply says it goes out as the inbox, not as the person '
      'typing', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Chimney sweep quote').first);
    await _tap(tester, find.textContaining('Reply as'));

    expect(find.byType(ReplyPanel), findsOneWidget);
    expect(find.text('Replying as info@a-1chimney.com'), findsOneWidget);
    expect(
      find.textContaining('goes out as the inbox, not as you'),
      findsOneWidget,
    );

    // And the reader's own identity is nowhere in it. `/me` says Jose Silva,
    // and a composer that puts the author's name on a shared inbox's reply is
    // the whole decision undone.
    expect(find.textContaining('Jose Silva'), findsNothing);
  });

  /// The failure this screen is arranged against, driven end to end: somebody
  /// with two inboxes, standing in the second one.
  testWidgets('with a second inbox chosen, the reply names and posts to THAT '
      'inbox', (tester) async {
    final posted = <String, Map<String, dynamic>>{};

    await _pump(tester, _handler(onReply: (path, body) => posted[path] = body));

    await _tap(tester, find.text('bookings@a-1chimney.com').first);
    await _tap(tester, find.text('Booking for Friday').first);
    await _tap(tester, find.textContaining('Reply as'));

    expect(find.text('Replying as bookings@a-1chimney.com'), findsOneWidget);
    expect(
      find.text('Replying as info@a-1chimney.com'),
      findsNothing,
      reason: 'The composer is naming the inbox the screen opened on rather '
          'than the one the reader moved to.',
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Your reply'),
      'Friday at ten is fine.',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.textContaining('Send as'));

    expect(
      posted.keys.single,
      '/api/v1/email/mailboxes/mb-2/messages/m-3/reply',
      reason: 'The reply was posted to a different inbox from the one the '
          'screen said it would go out as.',
    );
    expect(posted.values.single['body'], 'Friday at ten is fine.');

    // And the confirmation names the address it actually went out as, rather
    // than congratulating somebody who still does not know.
    expect(find.text('Sent as bookings@a-1chimney.com.'), findsOneWidget);
  });

  /// R8: a refusal is a sentence saying what to do. The server writes it,
  /// because only the server holds both halves of the answer — the role's
  /// permission and this organization's grant on this inbox.
  testWidgets('an inbox somebody may read and not answer says so, in the '
      'server’s own words', (tester) async {
    const refusal = 'You can read info@a-1chimney.com but not send from it. '
        'Ask whoever looks after this inbox to add replying to your role.';

    await _pump(tester, _handler(canReply: false, replyRefusal: refusal));
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(
      tester
          .widget<FilledButton>(
            find.ancestor(
              of: find.textContaining('Reply as'),
              matching: find.byType(FilledButton),
            ),
          )
          .onPressed,
      isNull,
    );

    // The control stays on screen with the reason attached, rather than
    // disappearing and leaving somebody to conclude the app is broken.
    expect(find.byTooltip(refusal), findsOneWidget);
  });

  // ── the two states that must never read as "nothing here" ────────

  /// The most damaging thing a mail window can say wrongly. An expired Google
  /// token and an IMAP host that stopped answering both arrive here, and both
  /// used to look exactly like an inbox with nothing in it.
  testWidgets('an inbox that cannot be reached shows the server’s sentence, '
      'never an empty list', (tester) async {
    const sentence =
        'Google would not let us open info@a-1chimney.com. The connection was '
        'removed from that Google account, so it has to be connected again.';

    await _pump(tester, _handler(listStatus: 502, listMessage: sentence));

    expect(find.text(sentence), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);

    expect(
      find.textContaining('Nothing in'),
      findsNothing,
      reason: 'A broken inbox drawn as an empty one is a customer whose email '
          'nobody ever answers, because nobody investigates "no mail".',
    );

    // The inbox is still named, so the sentence is about a KNOWN address.
    expect(find.text('info@a-1chimney.com'), findsWidgets);
  });

  testWidgets('no inboxes at all is a setup step with words on it', (
    tester,
  ) async {
    await _pump(tester, _handler(mailboxes: const []));

    expect(find.textContaining('No inbox belongs to this part of the business'), findsOneWidget);
    expect(find.textContaining('each role is then given'), findsOneWidget);
  });

  testWidgets('the open message sits on a white page', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(
      find.byWidgetPredicate(
        (widget) => widget is ColoredBox && widget.color == Colors.white,
      ),
      findsWidgets,
      reason:
          'The reading pane was the app grey, so the mail looked like a '
          'card on a tint instead of a white page.',
    );
  });

  testWidgets('five messages in one conversation are one row with the count', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    expect(find.textContaining('WhatsApp Business Team'), findsOneWidget);
    expect(find.text('5'), findsOneWidget);
    expect(
      find.text('Your WhatsApp message template state has been changed'),
      findsOneWidget,
    );
  });

  // ── the body: blocks, and the picture ────────────────────────────

  testWidgets('a picture in the message is drawn, and nothing offers a '
      'separate fetch', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.byType(Image), findsOneWidget);
    expect(find.textContaining('look at'), findsWidgets);

    expect(
      find.descendant(
        of: find.byType(MessageBody),
        matching: find.bySubtype<ButtonStyleButton>(),
      ),
      findsNothing,
      reason: 'A control in the body would be offering to load the image, '
          'and the picture is already being fetched through our origin.',
    );
  });

  /// The link is drawn with its address beside it and does not open anything.
  /// "Click here" over an address nobody can see is how phishing arrives, and
  /// this module is a window onto mail from strangers.
  testWidgets('a link shows where it goes', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(
      find.textContaining('not-your-bank.example.com'),
      findsOneWidget,
      reason: 'The words were drawn and the address behind them was not, '
          'which is the shape of the attack this module invites.',
    );
  });

  /// Switching inbox with a message open. The message belonged to the inbox
  /// being left, and leaving it on screen under a new inbox's name is the exact
  /// confusion the whole screen is arranged against.
  testWidgets('changing inbox takes the open message with it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.byType(MessageBody), findsOneWidget);

    await _tap(tester, find.text('bookings@a-1chimney.com').first);

    expect(find.byType(MessageBody), findsNothing);
    expect(find.text('Pick a message to read it.'), findsOneWidget);
    expect(
      find.text('Chimney sweep quote'),
      findsNothing,
      reason: 'A message from the last inbox is still on screen under this '
          'one’s name.',
    );
  });

  testWidgets('the live API envelope is mail, not an empty inbox', (
    tester,
  ) async {
    // Production answers `{ data: { messages, next_cursor } }` with
    // from_name / snippet / sent_at, not the list-plus-page the first
    // screen assumed. Reading that as "not a list" drew "Nothing in
    // development@ezbizi.com right now" over 201 real messages.
    await _pump(tester, (request) async {
      final path = request.url.path;

      if (path.endsWith('/email/mailboxes')) {
        return _json({
          'data': [
            {
              'id': 'mb-1',
              'address': 'info@a-1chimney.com',
              'display_name': 'Info',
              'may_read': true,
              'read_state_shared': true,
            },
          ],
        });
      }

      if (path.endsWith('/messages')) {
        return _json({
          'data': {
            'messages': [
              {
                'id': 'm-1',
                'from_name': 'Ana Reis',
                'from_address': 'ana@example.com',
                'subject': 'Chimney sweep quote',
                'snippet': 'Could somebody look at the flue this week?',
                'sent_at': '2026-08-11T09:14:00Z',
                'unread_at_provider': true,
                'has_attachments': true,
                'seen_by': ['Front desk'],
              },
            ],
            'next_cursor': null,
            'approximate_total': 1,
          },
          'meta': {'cache_seconds': 60},
        });
      }

      if (path.contains('/messages/')) {
        return _json({
          'data': _detail('mb-1', 'm-1'),
        });
      }

      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    });

    expect(find.text('Nothing in info@a-1chimney.com right now.'), findsNothing);
    expect(find.text('Chimney sweep quote'), findsOneWidget);
    expect(find.text('Ana Reis <ana@example.com>'), findsOneWidget);
    expect(find.text('Info'), findsWidgets);
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1500, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_withMe(handler)));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: EmailScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
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

// ── the server, as this screen assumes it ──────────────────────────
//
// The endpoints are being written in parallel with this screen, so the shape
// below is the stated assumption rather than a recording of a live API. It is
// written out in full in lib/screens/email/mail_data.dart.

MockClientHandler _handler({
  bool sharedRead = true,
  bool canReply = true,
  String? replyRefusal,
  int listStatus = 200,
  String listMessage = 'Unavailable.',
  List<Map<String, Object?>>? mailboxes,
  void Function(String path, Map<String, dynamic> body)? onReply,
}) {
  return (request) async {
    final path = request.url.path;
    final parts = path.split('/').where((part) => part.isNotEmpty).toList();

    if (path.endsWith('/email/mailboxes')) {
      return _json({
        'data': mailboxes ??
            _mailboxes(
              sharedRead: sharedRead,
              canReply: canReply,
              replyRefusal: replyRefusal,
            ),
      });
    }

    // .../mailboxes/{mailbox}/messages/{message}/reply
    if (path.endsWith('/reply')) {
      onReply?.call(path, jsonDecode(request.body) as Map<String, dynamic>);

      return _json({
        'data': {
          'sent_at': '2026-08-12T10:00:00Z',
          // The address it actually went out as, reported back — the screen
          // states this rather than repeating what it intended.
          'from': _addressOf(parts[parts.length - 4]),
        },
      });
    }

    // .../mailboxes/{mailbox}/messages
    if (path.endsWith('/messages')) {
      if (listStatus != 200) return _json({'message': listMessage}, listStatus);

      return _json({
        'data': _messages[parts[parts.length - 2]] ?? const [],
        'meta': {'page': 1, 'last_page': 1},
      });
    }

    // .../mailboxes/{mailbox}/messages/{message}
    if (parts.length > 2 && parts[parts.length - 2] == 'messages') {
      return _json({
        'data': _detail(parts[parts.length - 3], parts.last),
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// `/me` answered first, whatever else the handler does.
MockClientHandler _withMe(MockClientHandler inner) {
  return (request) async {
    if (request.url.path.endsWith('/me')) return _json({'data': _me});

    return inner(request);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

String _addressOf(String mailboxId) =>
    mailboxId == 'mb-2' ? 'bookings@a-1chimney.com' : 'info@a-1chimney.com';

List<Map<String, Object?>> _mailboxes({
  required bool sharedRead,
  required bool canReply,
  String? replyRefusal,
}) => [
  {
    'id': 'mb-1',
    'address': 'info@a-1chimney.com',
    'label': 'Info',
    'unread': 2,
    'shared_read': sharedRead,
    'can_reply': canReply,
    'reply_refusal': replyRefusal,
  },
  {
    'id': 'mb-2',
    'address': 'bookings@a-1chimney.com',
    'label': 'Bookings',
    'unread': 0,
    'shared_read': sharedRead,
    'can_reply': canReply,
    'reply_refusal': replyRefusal,
  },
];

/// Headers only, which is all a list ever fetches.
///
/// `seen_by` is on every row in EVERY test, including the private-read one:
/// the setting decides whether it is DRAWN, and a fixture that dropped the
/// data would let a screen that ignores the setting pass.
const Map<String, List<Map<String, Object?>>> _messages = {
  'mb-1': [
    {
      'id': 'm-1',
      'from': {'name': 'Ana Reis', 'address': 'ana@example.com'},
      'subject': 'Chimney sweep quote',
      'preview': 'Could somebody look at the flue this week?',
      'received_at': '2026-08-11T09:14:00Z',
      'unread': true,
      'has_attachments': true,
      // Marta first, and the reader second. Both are real reads and only one
      // of them is anybody else's business.
      'seen_by': [
        {'id': 'u-2', 'name': 'Marta Vieira'},
        {'id': 'u-1', 'name': 'Jose Silva'},
      ],
    },
    {
      'id': 'm-2',
      'from': {'name': 'Pedro Nunes', 'address': 'pedro@example.com'},
      'subject': 'Invoice 4471',
      'preview': 'Attached is the invoice for last month.',
      'received_at': '2026-08-10T16:02:00Z',
      'unread': false,
      'has_attachments': false,
      'seen_by': [
        {'id': 'u-1', 'name': 'Jose Silva'},
      ],
    },
    {
      'id': 'm-wa',
      'from': {
        'name': 'WhatsApp Business Team',
        'address': 'notification@facebookmail.com',
      },
      'subject': 'Your WhatsApp message template state has been changed',
      'preview': 'Please see your template current state information.',
      'received_at': '2026-08-21T10:31:00Z',
      'unread': true,
      'has_attachments': false,
      'message_count': 5,
      'seen_by': <Map<String, Object?>>[],
    },
  ],
  'mb-2': [
    {
      'id': 'm-3',
      'from': {'name': 'Sofia Lopes', 'address': 'sofia@example.com'},
      'subject': 'Booking for Friday',
      'preview': 'Is ten in the morning still free?',
      'received_at': '2026-08-12T08:30:00Z',
      'unread': true,
      'has_attachments': false,
      'seen_by': <Map<String, Object?>>[],
    },
  ],
};

/// One message, opened: blocks rather than markup, and the address a reply
/// goes out as.
Map<String, Object?> _detail(String mailboxId, String messageId) {
  final address = _addressOf(mailboxId);

  final header = (_messages[mailboxId] ?? const [])
      .firstWhere((row) => row['id'] == messageId, orElse: () => const {});

  return {
    'id': messageId,
    'subject': header['subject'] ?? '',
    'from': header['from'] ?? const {},
    'to': [address],
    'received_at': header['received_at'],
    'seen_by': header['seen_by'] ?? const [],
    'blocks': messageId == 'm-1' ? _blocks : _plainBlocks,
    'attachments': messageId == 'm-1'
        ? [
            {'name': 'flue.jpg', 'size': 184320},
          ]
        : const [],
    'reply': {
      'as': {'address': address, 'name': mailboxId == 'mb-2' ? 'Bookings' : 'Info'},
      'to': [(header['from'] as Map?)?['address'] ?? ''],
      'subject': 'Re: ${header['subject'] ?? ''}',
    },
  };
}

/// The body as the server sanitised it. No markup reaches this client, so
/// there is no HTML in this fixture to be drawn by accident.
const List<Map<String, Object?>> _blocks = [
  {
    'kind': 'paragraph',
    'spans': [
      {'text': 'Could somebody look at '},
      {'text': 'the flue', 'strong': true},
      {'text': ' this week?'},
    ],
  },
  {
    'kind': 'image',
    'alt': 'Everdoors banner',
    // 1×1 png. A data URI is already the bytes — the client draws it
    // without a second request, which is what this test can exercise.
    'src':
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  },
  {
    'kind': 'paragraph',
    'spans': [
      {'text': 'Confirm here', 'link': 'https://not-your-bank.example.com/x'},
    ],
  },
  {'kind': 'rule'},
  {
    'kind': 'quote',
    'spans': [
      {'text': 'On Monday you wrote: we can come Tuesday.'},
    ],
  },
];

const List<Map<String, Object?>> _plainBlocks = [
  {
    'kind': 'paragraph',
    'spans': [
      {'text': 'Is ten in the morning still free?'},
    ],
  },
];

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['email.view'],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
