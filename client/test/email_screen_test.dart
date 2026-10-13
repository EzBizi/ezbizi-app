import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/compose_panel.dart';
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

      expect(find.text('ALL INBOXES'), findsWidgets);
      expect(find.text('info@a-1chimney.com'), findsWidgets);
      expect(find.text('bookings@a-1chimney.com'), findsWidgets);

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

  testWidgets('all inboxes shows mail from every inbox on one list', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    expect(find.text('All inboxes'), findsOneWidget);
    expect(find.text('Chimney sweep quote'), findsOneWidget);
    expect(find.text('Booking for Friday'), findsOneWidget);

    await _tap(tester, find.text('Booking for Friday').first);

    expect(find.text('THE INBOX YOU ARE IN'), findsOneWidget);
    expect(
      find.text('bookings@a-1chimney.com'),
      findsWidgets,
      reason:
          'A combined list that opens a mail without naming its inbox is '
          'how a reply goes out as the wrong address.',
    );
    expect(find.textContaining('Reply as bookings@a-1chimney.com'), findsOneWidget);
  });

  testWidgets('ticking a sender mark selects the row for bulk actions', (
    tester,
  ) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    expect(find.byType(SenderAvatar), findsWidgets);

    await tester.tap(find.byType(SenderAvatar).first);
    await tester.pumpAndSettle();

    expect(find.text('1 selected'), findsOneWidget);
    expect(find.byTooltip('Delete'), findsOneWidget);
    expect(find.byTooltip('Mark as read'), findsOneWidget);
  });

  testWidgets('the list has a search bar and Gmail labels', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    expect(find.text('Search mail'), findsOneWidget);
    expect(find.text('Unread'), findsOneWidget);
    expect(find.text('LABELS'), findsOneWidget);
    expect(find.text('Starred'), findsOneWidget);
    expect(find.text('Sent'), findsOneWidget);
  });

  testWidgets('at 1400px opening a message replaces the list', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    expect(find.byType(MailboxRail), findsOneWidget);
    expect(find.byType(MessageListPane), findsOneWidget);
    expect(find.text('Search mail'), findsOneWidget);

    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(
      find.byType(MessageListPane),
      findsNothing,
      reason: 'Gmail does not keep the list beside the mail. The list has to '
          'go so the message can use the space.',
    );
    expect(find.byType(MessageBody), findsOneWidget);
    expect(find.byType(MailboxRail), findsOneWidget);
    expect(find.byTooltip('Back to the messages'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the messages'));
    expect(find.byType(MessageListPane), findsOneWidget);
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
      findsOneWidget,
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

  // ── starting a conversation ──────────────────────────────────────

  /// The same failure as the reply path, on the path that has no message to
  /// take its address from: sending from the wrong inbox, unrecoverably.
  testWidgets('a new message names the inbox chosen and posts to THAT inbox',
      (tester) async {
    final posted = <String, Map<String, dynamic>>{};

    await _pump(tester, _handler(onSend: (path, body) => posted[path] = body));

    // The RAIL entry specifically. `find.text(...).first` can land on a row
    // label in the combined list, which leaves the screen in All inboxes and
    // makes this test pass for the wrong reason.
    await _tap(
      tester,
      find.descendant(
        of: find.byType(MailboxRail),
        matching: find.text('bookings@a-1chimney.com'),
      ),
    );
    await _tap(tester, find.text('New message'));

    expect(find.byType(ComposePanel), findsOneWidget);
    expect(find.text('Sending as bookings@a-1chimney.com'), findsOneWidget);
    expect(
      find.text('Sending as info@a-1chimney.com'),
      findsNothing,
      reason: 'The composer is naming the inbox the screen opened on rather '
          'than the one the reader moved to.',
    );

    // The reader's own identity is nowhere in it — `/me` says Jose Silva.
    expect(find.textContaining('Jose Silva'), findsNothing);

    await tester.enterText(
      find.widgetWithText(TextField, 'To'),
      'bob@example.com, jan@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Subject'),
      'Your annual sweep',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Message'),
      'Morning both.',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.textContaining('Send as'));

    expect(
      posted.keys.single,
      '/api/v1/email/mailboxes/mb-2/messages',
      reason: 'The message was posted to a different inbox from the one the '
          'screen said it would go out as.',
    );

    // BOTH recipients. A comma-separated field that sent to the first address
    // only would look identical on this screen and deliver to one person.
    expect(posted.values.single['to'], ['bob@example.com', 'jan@example.com']);
    expect(posted.values.single['subject'], 'Your annual sweep');
    expect(posted.values.single['body'], 'Morning both.');

    expect(find.text('Sent as bookings@a-1chimney.com.'), findsOneWidget);
  });

  /// The combined list has no sending identity, so it offers no way to send.
  ///
  /// This is pinned because the first version of the button got it wrong. In
  /// All inboxes `_selected` is null and the list column falls back to
  /// `_mailboxes.first` purely so it has something to draw — sending on that
  /// fallback would have gone out as info@ while the reader was looking at
  /// everything at once, having chosen nothing. It is the same wrong-inbox
  /// failure as the reply path's, arriving through a screen state rather than
  /// through a mis-click, and it cannot be undone once the message has left.
  testWidgets('All inboxes offers no New message button, because it has no '
      'inbox to send as', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));

    // The control: this IS the combined list, and it is working.
    expect(find.text('All inboxes'), findsOneWidget);
    expect(find.text('Chimney sweep quote'), findsOneWidget);
    expect(find.text('Booking for Friday'), findsOneWidget);

    expect(
      find.text('New message'),
      findsNothing,
      reason: 'The combined list is offering to send, and the only address it '
          'could pick is one the reader never chose.',
    );

    // Standing in one inbox, it appears.
    await _tap(
      tester,
      find.descendant(
        of: find.byType(MailboxRail),
        matching: find.text('bookings@a-1chimney.com'),
      ),
    );

    expect(find.text('New message'), findsOneWidget);
  });

  /// With nothing attached the message travels as JSON, not as a form.
  ///
  /// Pinned because the panel has two send shapes and the empty case is the one
  /// that would silently switch to multipart without anybody noticing — it
  /// works either way, right up until a field name differs.
  testWidgets('a message with no files is sent as JSON', (tester) async {
    String? contentType;

    await _pump(tester, _handler(onSendRequest: (r) => contentType = r.headers['content-type']));

    await _tap(
      tester,
      find.descendant(
        of: find.byType(MailboxRail),
        matching: find.text('info@a-1chimney.com'),
      ).first,
    );
    await _tap(tester, find.text('New message'));

    await tester.enterText(find.widgetWithText(TextField, 'To'), 'bob@example.com');
    await tester.enterText(find.widgetWithText(TextField, 'Message'), 'Morning.');
    await tester.pumpAndSettle();

    await _tap(tester, find.textContaining('Send as'));

    expect(contentType, contains('application/json'));
  });

  /// A file reaches the wire in the SAME request as the message.
  ///
  /// Not uploaded first and referenced: there is no half-sent state to recover
  /// from, because until this one request succeeds nothing has been sent and
  /// nothing has been stored.
  testWidgets('an attached file is sent with the message as multipart',
      (tester) async {
    http.Request? sent;

    await _pump(tester, _handler(onSendRequest: (r) => sent = r));

    await _tap(
      tester,
      find.descendant(
        of: find.byType(MailboxRail),
        matching: find.text('info@a-1chimney.com'),
      ).first,
    );
    await _tap(tester, find.text('New message'));

    expect(find.text('Attach a file'), findsOneWidget);

    // Drive the panel's own state: the OS file picker cannot be opened from a
    // widget test, and stubbing it would test the stub. What is being asserted
    // is what the panel DOES with a picked file, which starts here.
    final state = tester.state<ComposePanelState>(find.byType(ComposePanel));
    state.debugAddFile('quote.pdf', 'PDF-BYTES'.codeUnits);
    await tester.pumpAndSettle();

    expect(find.text('quote.pdf'), findsOneWidget);
    expect(find.textContaining('1 of 5 files'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'To'), 'bob@example.com');
    await tester.enterText(find.widgetWithText(TextField, 'Message'), 'Attached.');
    await tester.pumpAndSettle();

    await _tap(tester, find.textContaining('Send as'));

    // MockClient finalises a MultipartRequest into a plain Request before the
    // handler ever sees it, so this asserts the WIRE BYTES rather than the
    // request type. That is the stronger check anyway: it is exactly what the
    // server will parse, field names and all.
    expect(sent!.headers['content-type'], contains('multipart/form-data'));

    final body = sent!.body;

    expect(body, contains('name="attachments[]"; filename="quote.pdf"'));
    expect(body, contains('PDF-BYTES'));
    expect(body, contains('name="to[0]"'));
    expect(body, contains('bob@example.com'));
    expect(body, contains('name="body"'));
    expect(body, contains('Attached.'));
  });

  /// A folder list is what the SERVER said, not a table this client keeps.
  ///
  /// Outlook has no Starred — Graph offers a flag filter over the inbox, which
  /// is a different query rather than a folder — and it does have Archive,
  /// which Gmail does not. The screen does not know that and must not: it
  /// draws what came back from `/folders`, which is the only arrangement that
  /// can also be right for an IMAP server nobody has seen.
  testWidgets('an Outlook inbox offers Archive and not Starred', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          {
            'id': 'mb-1',
            'address': 'info@a-1chimney.com',
            'display_name': 'Info',
            'provider': 'microsoft_oauth',
            'unread': 0,
            'can_reply': true,
            'shared_read': true,
          },
        ],
        // What MicrosoftMailboxReader::availableFolders answers.
        folders: const ['INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM', 'ARCHIVE'],
      ),
      size: const Size(1400, 900),
    );

    expect(find.text('Archive'), findsOneWidget);
    expect(
      find.text('Starred'),
      findsNothing,
      reason: 'Outlook is offered a folder Graph cannot address, so picking it '
          'returns a refusal instead of mail.',
    );

    // The control: the folder list is drawn at all, so this is "Starred is
    // absent" rather than "no folders were rendered".
    expect(find.text('Sent'), findsOneWidget);
    expect(find.text('LABELS'), findsOneWidget);
  });

  /// IMAP opens only the Inbox, so it is offered no folder list at all.
  ///
  /// The server refuses anything else — folder names differ per server and
  /// discovering them is real work — so drawing the list would be offering
  /// six refusals.
  testWidgets('an IMAP inbox is offered no folder list', (tester) async {
    await _pump(
      tester,
      _handler(mailboxes: [
        {
          'id': 'mb-1',
          'address': 'info@a-1chimney.com',
          'display_name': 'Info',
          'provider': 'imap',
          'unread': 0,
          'can_reply': true,
          'shared_read': true,
        },
      ]),
      size: const Size(1400, 900),
    );

    expect(find.text('LABELS'), findsNothing);
    expect(find.text('Sent'), findsNothing);
    expect(find.text('Starred'), findsNothing);

    // The control: the mailbox itself is on screen.
    expect(find.text('info@a-1chimney.com'), findsWidgets);
  });

  /// An IMAP mailbox draws the folders THAT SERVER has — no more, no fewer.
  ///
  /// The guess for IMAP is the Inbox alone, because nothing else is knowable
  /// without asking. Once asked, the answer replaces the guess: this server
  /// has Sent and Trash and no Archive, so Archive is not offered. Offering it
  /// and refusing on the click is the broken promise this module keeps
  /// declining to make.
  testWidgets('an IMAP inbox draws the folders its own server reported',
      (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          {
            'id': 'mb-1',
            'address': 'info@a-1chimney.com',
            'display_name': 'Info',
            'provider': 'imap',
            'unread': 0,
            'can_reply': true,
            'shared_read': true,
          },
        ],
        folders: const ['INBOX', 'SENT', 'TRASH'],
      ),
      size: const Size(1400, 900),
    );

    // Before it is asked, the guess is the Inbox alone — so nothing here can
    // be coming from a provider table.
    await _tap(
      tester,
      find.descendant(
        of: find.byType(MailboxRail),
        matching: find.text('info@a-1chimney.com'),
      ).first,
    );

    expect(find.text('Sent'), findsOneWidget);
    expect(find.text('Trash'), findsOneWidget);

    expect(
      find.text('Archive'),
      findsNothing,
      reason: 'A folder this server did not report is on screen, so picking it '
          'would come back refused.',
    );
    expect(
      find.text('Starred'),
      findsNothing,
      reason: 'Starred is a Gmail idea and this is an IMAP mailbox.',
    );
  });

  /// "Handled" is a different button from "Reply", and a different fact.
  ///
  /// Opening a message already marked it SEEN — that is what "Seen by" reads,
  /// and it is a claim about the person looking. This says the customer has
  /// been dealt with, which is the inbox's fact and the one a colleague needs
  /// in order to know whether to act.
  testWidgets('marking a message handled posts to the dealt endpoint',
      (tester) async {
    final posted = <String>[];

    await _pump(tester, _handler(onDealt: posted.add));

    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.text('Mark handled'), findsOneWidget);

    await _tap(tester, find.text('Mark handled'));

    expect(
      posted.single,
      '/api/v1/email/mailboxes/mb-1/messages/m-1/dealt',
      reason: 'The handled button reached a different endpoint from the one '
          'that records it.',
    );

    // And the control flips, so somebody can see it took.
    expect(find.text('Handled'), findsOneWidget);
    expect(find.text('Mark handled'), findsNothing);
  });

  /// Gate four of four: if the answer would be 403, the button is not drawn.
  testWidgets('an inbox somebody may read and not answer offers no New '
      'message button', (tester) async {
    await _pump(tester, _handler(canReply: false, replyRefusal: 'Not yours.'));

    expect(
      find.text('New message'),
      findsNothing,
      reason: 'A button the server would refuse is drawn, which is the shape '
          'the house rule forbids.',
    );

    // The control: the inbox itself is on screen, so this is "the button is
    // absent" rather than "the screen failed to load".
    expect(find.text('Chimney sweep quote'), findsWidgets);
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
  testWidgets('the open message shows the sender icon', (tester) async {
    await _pump(tester, _handler(), size: const Size(1400, 900));
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.byType(MessageListPane), findsNothing);
    expect(
      find.byType(SenderAvatar),
      findsOneWidget,
      reason: 'The list has icons and the open mail did not.',
    );
  });

  testWidgets('an attachment is named and can be downloaded', (tester) async {
    String? fileUrl;

    await _pump(
      tester,
      _handler(onFile: (url) => fileUrl = url),
      size: const Size(1400, 900),
    );
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(find.text('ATTACHMENTS'), findsOneWidget);
    expect(find.textContaining('flue.jpg'), findsOneWidget);

    await _tap(tester, find.textContaining('flue.jpg'));

    expect(
      fileUrl,
      contains('/email/mailboxes/mb-1/messages/m-1/file'),
    );
    expect(fileUrl, contains('src=sealed-att'));
    expect(fileUrl, contains('name=flue.jpg'));
  });

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
    expect(find.text('Booking for Friday'), findsOneWidget);
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
    expect(find.text('Ana Reis'), findsOneWidget);
    expect(find.text('Info'), findsWidgets);
  });
  /// A tab nobody granted is not drawn, and the OTHER one still opens.
  ///
  /// Jose, 2026-09-17: "i should be able to grant access by sub tab as well".
  /// The second half is the one that would fail silently: tabs used to be
  /// chosen by INDEX, so hiding Inbox would leave Settings at index 0 and a
  /// role holding only Settings would be shown the mail. The screen keys on the
  /// tab's name now, and this is what says so.
  testWidgets('a role with only Settings sees Settings and not the mail',
      (tester) async {
    _permissionsOverride = ['email.view', 'email.settings.view'];
    addTearDown(() => _permissionsOverride = null);

    await _pump(tester, _handler());

    expect(find.text('Inbox'), findsNothing);
    expect(find.text('Settings'), findsWidgets);
  });

  /// The mirror, and the control: with only Inbox the mail is there and the
  /// connections screen is not. Without this pair, a filter that hid
  /// EVERYTHING would pass the test above.
  testWidgets('a role with only Inbox sees the mail and not Settings',
      (tester) async {
    _permissionsOverride = ['email.view', 'email.inbox.view'];
    addTearDown(() => _permissionsOverride = null);

    await _pump(tester, _handler());

    expect(find.text('Settings'), findsNothing);
    expect(find.text('Inbox'), findsWidgets);
  });

  /// Neither tab: a sentence, not an empty strip over an empty pane.
  testWidgets('a role with neither tab is told, not shown a blank screen',
      (tester) async {
    _permissionsOverride = ['email.view'];
    addTearDown(() => _permissionsOverride = null);

    await _pump(tester, _handler());

    expect(find.textContaining('has been given to your role'), findsOneWidget);
  });

  /// Opening sends the row's thread back, so the server fetches the
  /// conversation once rather than the message and then the conversation
  /// containing it again (~165 ms an open, measured 2026-10-06).
  testWidgets('opening a message sends its conversation along', (tester) async {
    final opened = <Uri>[];

    await _pump(tester, _handler(onOpen: opened.add));
    await _tap(tester, find.text('Chimney sweep quote').first);

    expect(opened, isNotEmpty);
    expect(opened.last.queryParameters['thread'], 'thread-of-m-1');
  });

  // Jose, 2026-10-13: Eve was given 10 inboxes and saw all 30 in the list --
  // the others she may manage, not open. The Inbox tab lists only what opens.
  testWidgets('an inbox this person may manage but not open is not listed', (tester) async {
    final rows = _mailboxes(sharedRead: true, canReply: true);
    rows[1] = {...rows[1], 'may_read': false, 'may_manage': true};

    await _pump(tester, _handler(mailboxes: rows), size: const Size(1400, 900));

    expect(find.text('info@a-1chimney.com'), findsWidgets);
    expect(find.text('bookings@a-1chimney.com'), findsNothing);
  });

  // Bar via Jose, 2026-10-12: "a new email notification should open that
  // email". The bell leaves a link and switches to Email; Email opens it.
  testWidgets('a notification tapped before Email opens lands on that email', (tester) async {
    final opened = <Uri>[];
    addTearDown(() => DeepLinks.pending.value = null);

    DeepLinks.open(const DeepLink(
      module: 'email', type: 'email_message', id: 'm-9', mailbox: 'Bookings@a-1chimney.com',
    ));
    await _pump(tester, _handler(onOpen: opened.add));

    expect(opened.map((u) => u.path), contains(endsWith('/mailboxes/mb-2/messages/m-9')));
    expect(DeepLinks.pending.value, isNull, reason: 'The link was not consumed.');
  });

  testWidgets('a notification tapped while Email is open opens that email', (tester) async {
    final opened = <Uri>[];
    addTearDown(() => DeepLinks.pending.value = null);

    await _pump(tester, _handler(onOpen: opened.add));
    expect(opened, isEmpty);

    DeepLinks.open(const DeepLink(
      module: 'email', type: 'email_message', id: 'm-9', mailbox: 'info@a-1chimney.com',
    ));
    await tester.pumpAndSettle();

    expect(opened.map((u) => u.path), contains(endsWith('/mailboxes/mb-1/messages/m-9')));
  });

  testWidgets('a link for another module is left for that module', (tester) async {
    addTearDown(() => DeepLinks.pending.value = null);
    const call = DeepLink(module: 'phone', type: 'call', id: 'c-1');

    DeepLinks.open(call);
    await _pump(tester, _handler());

    expect(DeepLinks.pending.value, same(call));
  });

  /// A conversation the server really sent, opened on the screen.
  ///
  /// Jose, 2026-09-17: a lot of emails show blank on desktop, and "content
  /// loads for a second and then vanishes". Every other test in this file
  /// opens a message I wrote, and all of them pass -- so what they establish
  /// is that the screen agrees with my idea of a message.
  ///
  /// This one opens the largest CONVERSATION in the two production inboxes as
  /// the server published it: 73 parts, each with its own blocks, which is the
  /// branch `_conversation` takes and no other test in this file reaches.
  /// Words are replaced by x's of the same length and URLs reduced to their
  /// host -- the shape is real and none of anybody's mail is in the repository.
  testWidgets('a real 73-part conversation draws', (tester) async {
    final thread = jsonDecode(
      File('test/fixtures/real_thread_message.json').readAsStringSync(),
    ) as Map<String, dynamic>;

    expect(
      (thread['thread'] as List).length,
      greaterThan(1),
      reason: 'the fixture stopped being a conversation',
    );

    await _pump(tester, _handler(detail: thread));
    await _tap(tester, find.text('Chimney sweep quote').first);

    // The report is that it appears and then goes, so the frames AFTER the
    // first are the ones that matter.
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.textContaining('x'), findsWidgets);
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
  void Function(String path, Map<String, dynamic> body)? onSend,
  void Function(http.Request request)? onSendRequest,
  void Function(String path)? onDealt,
  void Function(String url)? onFile,
  List<String>? folders,

  /// Answer every message-detail request with THIS, instead of the detail
  /// built below. For the one test that opens a message the server really
  /// sent -- see `test/fixtures/real_thread_message.json`.
  Map<String, Object?>? detail,

  /// Every message-detail request, as the screen made it.
  void Function(Uri url)? onOpen,
}) {
  return (request) async {
    final path = request.url.path;
    final parts = path.split('/').where((part) => part.isNotEmpty).toList();

    if (path.endsWith('/file')) {
      onFile?.call(request.url.toString());

      return http.Response(
        'file-bytes',
        200,
        headers: {'content-type': 'application/octet-stream'},
      );
    }

    // What folders this mailbox actually has, asked when it is opened.
    if (path.endsWith('/folders')) {
      return _json({'data': folders ?? const ['INBOX']});
    }

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

    if (path.endsWith('/bulk')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      return _json({
        'data': {
          'ids': body['ids'],
          'action': body['action'],
        },
      });
    }

    if (path.endsWith('/dealt')) {
      onDealt?.call(path);

      return _json({
        'data': {
          'message_id': parts[parts.length - 2],
          'dealt': request.method == 'POST'
              ? {'at': '2026-09-09T10:00:00Z', 'by': 'Jose Silva'}
              : null,
          'at_source': null,
        },
      });
    }

    if (path.endsWith('/seen')) {
      return _json({
        'data': {'message_id': parts.last, 'seen_by': <Object>[], 'shared': true},
      });
    }

    if (request.method == 'DELETE' && parts.contains('messages')) {
      return _json({'data': {'id': parts.last}});
    }

    // .../mailboxes/{mailbox}/messages — POST is a NEW message, not the list.
    // Checked before the GET branch below, which matches the same path.
    if (path.endsWith('/messages') && request.method == 'POST') {
      onSendRequest?.call(request);

      // Only JSON bodies decode; a multipart send is inspected via
      // onSendRequest instead.
      if ((request.headers['content-type'] ?? '').contains('application/json')) {
        onSend?.call(path, jsonDecode(request.body) as Map<String, dynamic>);
      }

      return _json({'data': {'sent_at': '2026-08-12T10:00:00Z'}});
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
      onOpen?.call(request.url);

      return _json({
        'data': detail ?? _detail(parts[parts.length - 3], parts.last),
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };

}

/// `/me` answered first, whatever else the handler does.
/// Permissions the next `_withMe` should answer with. Null means [_me] as is.
List<String>? _permissionsOverride;

MockClientHandler _withMe(MockClientHandler inner) {
  return (request) async {
    if (request.url.path.endsWith('/me')) {
      final me = _permissionsOverride == null
          ? _me
          : {..._me, 'permissions': _permissionsOverride};

      return _json({'data': me});
    }

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
    'provider': 'gmail_workspace',
    'unread': 2,
    'shared_read': sharedRead,
    'can_reply': canReply,
    'reply_refusal': replyRefusal,
  },
  {
    'id': 'mb-2',
    'address': 'bookings@a-1chimney.com',
    'label': 'Bookings',
    'provider': 'gmail_workspace',
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
      'thread_id': 'thread-of-m-1',
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
            {'name': 'flue.jpg', 'size': 184320, 'token': 'sealed-att'},
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
      {'text': 'Confirm here', 'href': 'https://not-your-bank.example.com/x'},
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
  // Both tabs: this fixture stands for somebody who could open all of Email
  // before the tabs were split, which is what the migration grants every
  // existing role. A test for the SPLIT grants one tab, not this.
  'permissions': ['email.view', 'email.inbox.view', 'email.settings.view'],
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
