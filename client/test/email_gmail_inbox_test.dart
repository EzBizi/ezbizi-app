import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as local_store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/customer_detail_panel.dart';
import 'package:ezbizi_client/screens/email/email_screen.dart';
import 'package:ezbizi_client/screens/email/gmail_inbox.dart';
import 'package:ezbizi_client/screens/email/inbox_rows.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Email › Inbox laid out like Gmail (design 137, options 1 and 4).
///
/// The list is `GET email/inbox`: one row per mail however many inboxes it
/// reached, and every action on a row is sent once per inbox in its
/// `inboxes[]`. Every name and address here is invented.
void main() {
  setUp(() {
    local_store.debugStoreDirOverride = Directory.systemTemp.createTempSync(
      'email_gmail_inbox_test',
    );
  });
  tearDown(() => local_store.debugStoreDirOverride = null);

  testWidgets('a mail that reached two inboxes is one row tagged with both', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    expect(find.textContaining('Re: A few questions'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('email-tags-m:sam')),
        matching: find.text('admin@ · info@'),
      ),
      findsOneWidget,
    );
    // The count of the conversation sits beside the sender.
    expect(find.textContaining('Sam Ortiz 3'), findsOneWidget);
    // Entities in a snippet are decoded.
    expect(find.textContaining("<b>it's</b> fine"), findsOneWidget);
    expect(find.textContaining('&#39;'), findsNothing);
  });

  testWidgets('a folder filter is a folder that opens ?filter=id', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('email-folder-filter:8')), findsNothing);
    await _tap(tester, find.byKey(const ValueKey('email-folder-filter:7')));

    final last = server.lists.last.queryParameters;
    expect(last['filter'], '7');
    expect(last.containsKey('folder'), isFalse);
  });

  testWidgets('starring a merged row sends one item per inbox', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.byKey(const ValueKey('email-star-m:sam')));

    final post = server.actions.single;
    expect(post['action'], 'star');
    expect(post['items'], [
      {'mailbox_id': 'mb-admin', 'message_id': 'g-admin-1'},
      {'mailbox_id': 'mb-info', 'message_id': 'g-info-1'},
    ]);
    expect(
      tester
          .widget<IconButton>(find.byKey(const ValueKey('email-star-m:sam')))
          .tooltip,
      'Unstar',
    );
  });

  testWidgets('archiving from the hover actions sends one item per inbox and '
      'takes the row off the Inbox', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
    await mouse.addPointer(location: Offset.zero);
    addTearDown(mouse.removePointer);
    await mouse.moveTo(
      tester.getCenter(find.byKey(const ValueKey('email-row-m:sam'))),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('email-quick-read-m:sam')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('email-quick-snooze-m:sam')),
      findsOneWidget,
    );

    await _tap(tester, find.byKey(const ValueKey('email-quick-archive-m:sam')));

    final post = server.actions.single;
    expect(post['action'], 'archive');
    expect((post['items'] as List).length, 2);
    expect(find.textContaining('Re: A few questions'), findsNothing);
    // The control: the other row is still there.
    expect(find.textContaining('You received money'), findsOneWidget);
  });

  testWidgets('a bulk action shows the sentence of an item that failed', (
    tester,
  ) async {
    const refusal =
        "office@brightsideair.example is an IMAP inbox; it can't be marked "
        'read at the source.';
    final server = _Server(failMessage: 'g-air-1', failSentence: refusal);
    await _pump(tester, server);

    await _tap(tester, find.byKey(const ValueKey('email-select-all')));
    await _tap(tester, find.byTooltip('Mark as read'));

    final post = server.actions.single;
    expect(post['action'], 'read');
    expect(
      (post['items'] as List).length,
      6,
      reason: 'two of the four rows reached two inboxes',
    );
    expect(find.textContaining(refusal), findsOneWidget);
  });

  testWidgets('category tabs ask for their category, with counts', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    // All is the default tab: the whole inbox, no category (Jose, 2026-11-03).
    expect(server.lists.first.queryParameters.containsKey('category'), isFalse);
    expect(server.lists.first.queryParameters['folder'], 'inbox');
    expect(
      find.byKey(const ValueKey('email-category-count-people')),
      findsOneWidget,
    );

    await _tap(tester, find.byKey(const ValueKey('email-category-people')));

    expect(server.lists.last.queryParameters['category'], 'people');
  });

  testWidgets('folders ask for their folder, and only Inbox has categories', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    for (final folder in ['starred', 'snoozed', 'sent', 'drafts', 'all']) {
      await _tap(tester, find.byKey(ValueKey('email-folder-$folder')));
      final query = server.lists.last.queryParameters;
      expect(query['folder'], folder);
      expect(query.containsKey('category'), isFalse, reason: folder);
      expect(find.byKey(const ValueKey('tour-email-categories')), findsNothing);
    }

    await _tap(tester, find.byKey(const ValueKey('email-folder-inbox')));
    expect(server.lists.last.queryParameters['folder'], 'inbox');
    expect(find.byKey(const ValueKey('tour-email-categories')), findsOneWidget);
  });

  testWidgets('inboxes are grouped by company, and picking one filters the '
      'list to it', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    expect(server.lists.first.queryParameters.containsKey('mailbox'), isFalse);

    // Folded closed, with the company's unread total showing.
    final home = find.byKey(const ValueKey('email-group-co-home'));
    expect(
      find.descendant(of: home, matching: find.text('Brightside Home')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: home, matching: find.text('21')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('email-inbox-mb-admin')), findsNothing);
    // Not connected is last.
    expect(
      tester
          .getTopLeft(find.byKey(const ValueKey('email-group-not-connected')))
          .dy,
      greaterThan(
        tester.getTopLeft(find.byKey(const ValueKey('email-group-co-air'))).dy,
      ),
    );

    await _tap(tester, home);
    await _tap(tester, find.byKey(const ValueKey('email-inbox-mb-admin')));

    expect(server.lists.last.queryParameters['mailbox'], 'mb-admin');
    // One inbox in view: no tags needed on the rows.
    expect(find.byKey(const ValueKey('email-tags-m:sam')), findsNothing);
  });

  testWidgets('the not-connected notice shows only on that inbox', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    // All inboxes: the server's error for careers@ is not drawn, nor the
    // notice — the "Not connected" group says it (Jose's screenshot bug).
    expect(find.byKey(const ValueKey('email-not-connected')), findsNothing);
    expect(find.textContaining(_careersSentence), findsNothing);
    // The control: a connected inbox's failure IS said, quietly.
    expect(find.text(_airError), findsOneWidget);

    await _tap(tester, find.byKey(const ValueKey('email-group-not-connected')));
    await _tap(tester, find.byKey(const ValueKey('email-inbox-mb-careers')));

    expect(find.byKey(const ValueKey('email-not-connected')), findsOneWidget);
    expect(find.text(_careersSentence), findsOneWidget);
  });

  testWidgets('snooze asks when, and sends until', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.byKey(const ValueKey('email-tick-m:zelle')));
    await _tap(tester, find.byTooltip('Snooze'));

    expect(find.text('Later today'), findsOneWidget);
    expect(find.text('Next week'), findsOneWidget);
    expect(find.text('Pick a date…'), findsOneWidget);

    final before = DateTime.now();
    await _tap(tester, find.byKey(const ValueKey('email-snooze-tomorrow')));

    final post = server.actions.single;
    expect(post['action'], 'snooze');
    expect(post['items'], [
      {'mailbox_id': 'mb-info', 'message_id': 'g-zelle'},
    ]);
    final until = DateTime.parse(post['until'] as String).toLocal();
    expect(until, snoozeTomorrow(before));
    expect(until.hour, 8);
    expect(find.textContaining('You received money'), findsNothing);
  });

  testWidgets('the sender\'s customer record is a chip that opens it', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.textContaining('Quote for the cap').first);

    final chip = find.byKey(const ValueKey('email-customer-chip'));
    expect(
      find.descendant(of: chip, matching: find.text('Customer · Dana Reyes')),
      findsOneWidget,
    );

    await _tap(tester, chip);

    expect(find.byType(CustomerDetailPanel), findsOneWidget);
    expect(
      tester.widget<CustomerDetailPanel>(find.byType(CustomerDetailPanel)).id,
      'cust-dana',
    );
  });

  testWidgets('a lead sender opens the lead', (tester) async {
    final server = _Server();
    final opened = <String>[];
    await _pump(tester, server, openLead: opened.add);

    await _tap(tester, find.textContaining('Thank you!').first);
    await _tap(tester, find.byKey(const ValueKey('email-customer-chip')));

    expect(opened, ['lead-morgan']);
  });

  testWidgets('a merged mail offers its inboxes as From, and replies from the '
      'one picked', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.textContaining('Re: A few questions').first);
    expect(
      server.opened.last.path,
      endsWith('/mailboxes/mb-admin/messages/g-admin-1'),
    );
    expect(find.byKey(const ValueKey('email-from-picker')), findsOneWidget);

    await _tap(
      tester,
      find.widgetWithText(ChoiceChip, 'info@brightside.example'),
    );

    expect(
      server.opened.last.path,
      endsWith('/mailboxes/mb-info/messages/g-info-1'),
    );
    expect(
      find.textContaining('Reply as info@brightside.example'),
      findsOneWidget,
    );
    // Reply all and Forward reach the server (2026-10-22 server pin).
    expect(find.byKey(const ValueKey('email-reply-all')), findsOneWidget);
    expect(find.byKey(const ValueKey('email-forward')), findsOneWidget);
  });

  testWidgets('reaching the bottom of the list loads the next page under it '
      '(Jose, 11-04: it stopped at Nov 1)', (tester) async {
    final server = _Server(cursor: 'CUR-2');
    await _pump(tester, server);

    // The fixture is shorter than the screen, so the bottom is in view at once.
    await tester.pumpAndSettle();

    expect(server.lists.last.queryParameters['cursor'], 'CUR-2');
    // The last page has no cursor: the spinner goes and nothing more is asked.
    expect(find.byKey(const ValueKey('email-more')), findsNothing);
    expect(server.lists.length, 2);
  });

  testWidgets('the reading pane setting is remembered', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.byKey(const ValueKey('email-pane-setting')));
    await _tap(tester, find.byKey(const ValueKey('email-pane-below')));

    expect(local_store.readItem('email_reading_pane_u-1'), 'below');

    await _tap(tester, find.textContaining('You received money').first);
    final list = tester.getRect(find.byKey(const ValueKey('tour-email-list')));
    final reader = tester.getRect(find.byKey(const ValueKey('email-reader')));
    expect(reader.top, greaterThanOrEqualTo(list.bottom));
  });

  testWidgets('at 390×844 it is the Gmail app: nothing overflows, swipe '
      'archives every copy', (tester) async {
    final server = _Server();
    await _pump(tester, server, size: const Size(390, 844));

    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('email-compose-fab')), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-email-inboxes')), findsNothing);
    expect(find.text('Sam Ortiz 3'), findsOneWidget);

    await tester.drag(
      find.byKey(const ValueKey('email-swipe-m:sam')),
      const Offset(-360, 0),
    );
    await tester.pumpAndSettle();

    final post = server.actions.single;
    expect(post['action'], 'archive');
    expect((post['items'] as List).length, 2);
    expect(find.text('Sam Ortiz 3'), findsNothing);
    expect(tester.takeException(), isNull);

    // A long press ticks a row and brings the bulk actions.
    await tester.longPress(find.text('Riverbank'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('tour-email-toolbar')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await _tap(tester, find.byKey(const ValueKey('email-select-all')));
    await _tap(tester, find.byKey(const ValueKey('email-select-all')));
    expect(find.byKey(const ValueKey('tour-email-toolbar')), findsNothing);

    // ☰ opens the rail in place; the reader opens full screen with back.
    await _tap(tester, find.byTooltip('Folders and inboxes'));
    expect(find.byKey(const ValueKey('tour-email-inboxes')), findsOneWidget);
    await _tap(tester, find.byKey(const ValueKey('email-folder-inbox')));
    expect(find.byKey(const ValueKey('tour-email-inboxes')), findsNothing);

    await _tap(tester, find.text('Riverbank'));
    expect(find.byKey(const ValueKey('email-reader')), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-email-list')), findsNothing);
    expect(tester.takeException(), isNull);
    await _tap(tester, find.byTooltip('Back to the messages'));
    expect(find.byKey(const ValueKey('tour-email-list')), findsOneWidget);
  });

  testWidgets(
    'at 390 an opened mail fills the screen: full width, icon '
    'actions with tooltips, the mail below a capped header, back to the list',
    (tester) async {
      final server = _Server();
      await _pump(tester, server, size: const Size(390, 844));

      await _tap(tester, find.text('Riverbank'));
      final reader = tester.getRect(find.byKey(const ValueKey('email-reader')));
      expect(reader.width, 390);
      expect(find.byKey(const ValueKey('tour-email-list')), findsNothing);

      final actions = find.byKey(const ValueKey('email-icon-actions'));
      expect(actions, findsOneWidget);
      expect(
        find.descendant(of: actions, matching: find.byType(IconButton)),
        findsWidgets,
      );
      expect(find.byTooltip('Mark handled'), findsOneWidget);
      expect(find.textContaining('Reply as '), findsNothing);
      // The header never takes more than a third of the reader.
      final header = tester.getRect(
        find.ancestor(of: actions, matching: find.byType(ConstrainedBox)).first,
      );
      expect(header.height, lessThanOrEqualTo(reader.height * 0.31));
      expect(tester.takeException(), isNull);

      await _tap(tester, find.byTooltip('Back to the messages'));
      expect(find.byKey(const ValueKey('tour-email-list')), findsOneWidget);
      expect(find.text('Riverbank'), findsOneWidget);
    },
  );

  for (final width in [471.0, 700.0]) {
    testWidgets('at ${width.toInt()} an opened mail takes the whole pane, the '
        'actions are one icon row, and the mail starts right under it', (
      tester,
    ) async {
      final server = _Server();
      await _pump(tester, server, size: Size(width, 900));

      await _tap(tester, find.text('Riverbank'));
      expect(find.byKey(const ValueKey('tour-email-list')), findsNothing);
      final reader = tester.getRect(find.byKey(const ValueKey('email-reader')));
      final actions = find.byKey(const ValueKey('email-icon-actions'));
      final row = tester.getRect(actions);
      final buttons = find.descendant(
        of: actions,
        matching: find.byType(IconButton),
      );
      for (final b in buttons.evaluate()) {
        expect(tester.getRect(find.byWidget(b.widget)).top, row.top);
      }
      expect(row.height, lessThanOrEqualTo(56));
      final header = tester.getRect(
        find.ancestor(of: actions, matching: find.byType(ConstrainedBox)).first,
      );
      expect(header.height, lessThanOrEqualTo(reader.height * 0.31));
      expect(tester.takeException(), isNull);

      await _tap(tester, find.byTooltip('Back to the messages'));
      expect(find.byKey(const ValueKey('tour-email-list')), findsOneWidget);
    });
  }

  testWidgets('an inbox not counted yet shows a dash, never 0, and a company '
      'sums only what is known', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    // Brightside Home: 9 + 12 known, billing@ not counted yet.
    final home = find.byKey(const ValueKey('email-group-co-home'));
    expect(
      find.descendant(of: home, matching: find.text('21')),
      findsOneWidget,
    );

    // Riverton Garage: nothing counted yet.
    final garage = find.byKey(const ValueKey('email-group-co-garage'));
    expect(
      find.descendant(of: garage, matching: find.text('—')),
      findsOneWidget,
    );
    expect(find.descendant(of: garage, matching: find.text('0')), findsNothing);

    // Inbox rows carry no count (Jose, 11-04); the company total stays.
    await _tap(tester, home);
    final info = find.byKey(const ValueKey('email-inbox-mb-info'));
    expect(find.descendant(of: info, matching: find.text('12')), findsNothing);
    final billing = find.byKey(const ValueKey('email-inbox-mb-billing'));
    expect(find.descendant(of: billing, matching: find.text('—')), findsNothing);
  });

  testWidgets('a handled mail has a tick on its row, and the reader says who '
      'handled it', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('email-handled-m:dana')), findsOneWidget);
    // The control: an unhandled row has none.
    expect(find.byKey(const ValueKey('email-handled-m:sam')), findsNothing);

    await _tap(tester, find.textContaining('Quote for the cap').first);
    await _tap(
      tester,
      find.widgetWithText(ChoiceChip, 'info@brightside.example'),
    );

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('email-handled-toggle')),
        matching: find.textContaining('Handled by Dana Reyes'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('Reply all sends all, and Cc sends its addresses', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.textContaining('You received money').first);
    await _tap(tester, find.byKey(const ValueKey('email-reply-all')));

    expect(find.text('Reply all'), findsWidgets);
    await tester.enterText(
      find.byKey(const ValueKey('email-reply-cc')),
      'pat@example.com, lee@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Your reply'),
      'Thanks.',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.textContaining('Send as'));

    final body = server
        .replies['/api/v1/email/mailboxes/mb-info/messages/g-zelle/reply']!;
    expect(body['all'], isTrue);
    expect(body['cc'], ['pat@example.com', 'lee@example.com']);
    expect(body['body'], 'Thanks.');
    expect(find.text('Sent as info@brightside.example.'), findsOneWidget);
  });

  testWidgets('a plain reply sends no copies, and a refused copy shows the '
      "server's sentence", (tester) async {
    const refusal =
        'Reply all and copies work on Gmail inboxes. Reply to the sender instead.';
    final server = _Server(replyRefusal: refusal);
    await _pump(tester, server);

    await _tap(tester, find.textContaining('You received money').first);
    await _tap(tester, find.textContaining('Reply as'));
    await tester.enterText(
      find.byKey(const ValueKey('email-reply-cc')),
      'pat@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Your reply'),
      'Thanks.',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.textContaining('Send as'));

    final body = server.replies.values.single;
    expect(body.containsKey('all'), isFalse);
    expect(body['cc'], ['pat@example.com']);
    expect(find.text(refusal), findsOneWidget, reason: 'the 422 sentence');
  });

  testWidgets('Forward sends To and the note from the open inbox, and says '
      'attachments are not forwarded', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await _tap(tester, find.textContaining('You received money').first);
    await _tap(tester, find.byKey(const ValueKey('email-forward')));

    expect(find.text('Attachments are not forwarded yet.'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.byKey(const ValueKey('email-forward-send')),
          )
          .onPressed,
      isNull,
      reason: 'nothing to send to yet',
    );

    await tester.enterText(
      find.byKey(const ValueKey('email-forward-to')),
      'books@example.com, pat@example.com',
    );
    await tester.enterText(
      find.byKey(const ValueKey('email-forward-body')),
      'For the books.',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.byKey(const ValueKey('email-forward-send')));

    final body = server
        .forwards['/api/v1/email/mailboxes/mb-info/messages/g-zelle/forward']!;
    expect(body['to'], ['books@example.com', 'pat@example.com']);
    expect(body['body'], 'For the books.');
    expect(
      find.text('Forwarded, from info@brightside.example.'),
      findsOneWidget,
    );
  });

  test('entities in a snippet become characters', () {
    expect(
      decodeEntities('a &lt;b&gt; &amp; it&#39;s &quot;x&quot; &#x2014;'),
      'a <b> & it\'s "x" —',
    );
    expect(decodeEntities('no entities'), 'no entities');
  });
}

const _careersSentence =
    'careers@brightside.example is not connected yet. Connect it in Settings.';
const _airError =
    'office@brightsideair.example did not answer. Try again in a minute.';

class _Server {
  _Server({
    this.failMessage,
    this.failSentence,
    this.cursor,
    this.replyRefusal,
  });

  /// When set, every reply is refused 422 with this sentence.
  final String? replyRefusal;
  final replies = <String, Map<String, dynamic>>{};
  final forwards = <String, Map<String, dynamic>>{};

  final String? failMessage;
  final String? failSentence;
  final String? cursor;

  final lists = <Uri>[];
  final opened = <Uri>[];
  final actions = <Map<String, dynamic>>[];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/email/mailboxes')) return _json({'data': _mailboxes});

    if (path.endsWith('/email/filters')) {
      return _json({
        'data': {
          'filters': [
            {
              'id': 7,
              'name': 'Vendors',
              'from_contains': '@vendor.example',
              'action': 'folder',
              'shared': false,
              'may_delete': true,
            },
            {
              'id': 8,
              'name': 'Hidden junk',
              'subject_contains': 'Win',
              'action': 'hide',
              'shared': false,
              'may_delete': true,
            },
          ],
          'may_share': false,
          'actions': ['folder', 'notifications', 'hide'],
        },
      });
    }

    if (path.endsWith('/email/inbox')) {
      lists.add(request.url);
      return _json({
        'data': _rows,
        'meta': {
          'cursor': request.url.queryParameters['cursor'] == null
              ? cursor
              : null,
          'errors': [
            {
              'mailbox_id': 'mb-careers',
              'address': 'careers@brightside.example',
              'message': _careersSentence,
            },
            {
              'mailbox_id': 'mb-air',
              'address': 'office@brightsideair.example',
              'message': _airError,
            },
          ],
          'counts': {
            'categories': {'primary': 3, 'people': 2, 'notifications': 0},
            'unread_loaded': 2,
            'snoozed': 1,
          },
        },
      });
    }

    if (path.endsWith('/email/inbox/actions')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      actions.add(body);
      return _json({
        'data': {
          'results': [
            for (final item in body['items'] as List)
              {
                'mailbox_id': item['mailbox_id'],
                'message_id': item['message_id'],
                'ok': item['message_id'] != failMessage,
                'message': item['message_id'] == failMessage
                    ? failSentence
                    : null,
              },
          ],
        },
      });
    }

    final parts = path.split('/').where((p) => p.isNotEmpty).toList();

    if (path.endsWith('/reply')) {
      replies[path] = jsonDecode(request.body) as Map<String, dynamic>;
      if (replyRefusal != null) return _json({'message': replyRefusal}, 422);
      return _json({
        'data': {
          'sent_at': '2026-10-22T10:00:00Z',
          'from': _mailboxes.firstWhere(
            (m) => m['id'] == parts[parts.length - 4],
          )['address'],
        },
      });
    }

    if (path.endsWith('/forward')) {
      forwards[path] = jsonDecode(request.body) as Map<String, dynamic>;
      return _json({'message': 'Forwarded, from info@brightside.example.'});
    }

    if (parts.length > 3 && parts[parts.length - 2] == 'messages') {
      opened.add(request.url);
      final mailbox = parts[parts.length - 3];
      final address = _mailboxes.firstWhere(
        (m) => m['id'] == mailbox,
      )['address'];
      return _json({
        'data': {
          'id': parts.last,
          'subject': 'Opened',
          'from': {'name': 'Somebody', 'address': 'somebody@example.com'},
          'to': [address],
          'received_at': '2026-10-21T14:00:00Z',
          'seen_by': <Object>[],
          'blocks': [
            {
              'kind': 'paragraph',
              'spans': [
                {'text': 'Hello there.'},
              ],
            },
          ],
          'attachments': <Object>[],
          'dealt': parts.last == 'g-info-dana'
              ? {'at': '2026-10-21T13:12:00Z', 'by': 'Dana Reyes'}
              : null,
          'reply': {
            'as': {'address': address, 'name': ''},
            'to': ['somebody@example.com'],
            'subject': 'Re: Opened',
          },
        },
      });
    }

    return _json({'message': 'Not here.'}, 404);
  }
}

Future<void> _pump(
  WidgetTester tester,
  _Server server, {
  Size size = const Size(1500, 1000),
  void Function(String leadId)? openLead,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(server.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: OpenDestination(
        open: (_) {},
        openLead: openLead,
        child: Scaffold(
          body: EmailScreen(api: api, session: session),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder.first);
  await tester.pumpAndSettle();
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _mailbox(
  String id,
  String address,
  String company, {
  int? unread,
  String status = 'connected',
  String? reason,
}) => {
  'id': id,
  'address': address,
  'label': '',
  'provider': 'gmail_workspace',
  'scope_type': 'company',
  'scope_id': company,
  'status': status,
  'status_reason': reason,
  'can_be_opened': true,
  'unread': unread,
  'may_read': true,
  'shared_read': true,
  'can_reply': true,
};

final _mailboxes = [
  _mailbox('mb-admin', 'admin@brightside.example', 'co-home', unread: 9),
  _mailbox('mb-info', 'info@brightside.example', 'co-home', unread: 12),
  _mailbox('mb-air', 'office@brightsideair.example', 'co-air', unread: 4),
  _mailbox('mb-billing', 'billing@brightside.example', 'co-home'),
  _mailbox('mb-garage', 'hello@rivertongarage.example', 'co-garage'),
  _mailbox(
    'mb-careers',
    'careers@brightside.example',
    'co-home',
    status: 'unconfigured',
    reason: _careersSentence,
  ),
];

Map<String, Object?> _copy(String mailbox, String address, String id) => {
  'mailbox_id': mailbox,
  'address': address,
  'message_id': id,
  'thread_id': 't-$id',
};

final _rows = [
  {
    'key': 'm:sam',
    'subject': 'Re: A few questions',
    'snippet': 'I sent an invite &lt;b&gt;it&#39;s&lt;/b&gt; fine',
    'from': {'name': 'Sam Ortiz', 'address': 'sam@example.com'},
    'received_at': '2026-10-21T14:19:00Z',
    'unread': true,
    'starred': false,
    'has_attachments': false,
    'message_count': 3,
    'category': 'primary',
    'inboxes': [
      _copy('mb-admin', 'admin@brightside.example', 'g-admin-1'),
      _copy('mb-info', 'info@brightside.example', 'g-info-1'),
    ],
    'customer': null,
  },
  {
    'key': 'm:zelle',
    'subject': 'You received money with Zelle',
    'snippet': r'$500.00 from Pat Lin',
    'from': {'name': 'Riverbank', 'address': 'alerts@riverbank.example'},
    'received_at': '2026-10-21T14:24:00Z',
    'unread': true,
    'starred': false,
    'has_attachments': false,
    'message_count': 1,
    'category': 'primary',
    'inboxes': [_copy('mb-info', 'info@brightside.example', 'g-zelle')],
    'customer': null,
  },
  {
    'key': 'm:dana',
    'subject': 'Quote for the cap replacement',
    'snippet': 'Attached is the quote we talked about',
    'from': {'name': 'Dana Reyes', 'address': 'dana@example.com'},
    'received_at': '2026-10-20T13:02:00Z',
    'unread': false,
    'starred': false,
    'has_attachments': true,
    'message_count': 1,
    'category': 'people',
    'inboxes': [
      _copy('mb-air', 'office@brightsideair.example', 'g-air-1'),
      {
        ..._copy('mb-info', 'info@brightside.example', 'g-info-dana'),
        'dealt': {'at': '2026-10-21T13:12:00Z', 'by': 'Dana Reyes'},
      },
    ],
    'dealt': {'at': '2026-10-21T13:12:00Z', 'by': 'Dana Reyes'},
    'customer': {'type': 'customer', 'id': 'cust-dana', 'name': 'Dana Reyes'},
  },
  {
    'key': 'm:morgan',
    'subject': 'Thank you!',
    'snippet': "The crew was great, we'll leave a review",
    'from': {'name': 'Morgan Kay', 'address': 'morgan@example.com'},
    'received_at': '2026-10-19T13:02:00Z',
    'unread': false,
    'starred': false,
    'has_attachments': false,
    'message_count': 1,
    'category': 'people',
    'inboxes': [_copy('mb-info', 'info@brightside.example', 'g-morgan')],
    'customer': {'type': 'lead', 'id': 'lead-morgan', 'name': 'Morgan Kay'},
  },
];

const _me = {
  'id': 'u-1',
  'name': 'Robin Vale',
  'email': 'robin@brightside.example',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['email.view', 'email.inbox.view', 'email.settings.view'],
  'modules': [
    {'key': 'email', 'label': 'Email', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Group',
    'path': ['Brightside Group'],
  },
  'companies': [
    {'id': 'co-home', 'name': 'Brightside Home', 'parent_company_id': null},
    {'id': 'co-air', 'name': 'Brightside Air', 'parent_company_id': null},
    {'id': 'co-garage', 'name': 'Riverton Garage', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
